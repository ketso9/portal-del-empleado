<?php

defined('ABSPATH') || exit;

class EP_Downloads
{
    const TYPE_PUBLIC = 'public';
    const TYPE_PRIVATE = 'private';
    const POST_TYPE = 'ep_document';

    public function __construct()
    {
        add_action('init', array($this, 'register_documents_cpt'));
        add_action('admin_init', array($this, 'register_settings'));

        // AJAX Hooks
        add_action('wp_ajax_ep_upload_document', array($this, 'ajax_handle_upload'));
        add_action('wp_ajax_ep_delete_document', array($this, 'ajax_handle_delete'));
        add_action('wp_ajax_ep_secure_download', array($this, 'ajax_secure_download'));
        add_action('wp_ajax_ep_update_review_status', array($this, 'ajax_update_review_status'));
        add_action('wp_ajax_ep_send_to_signature', array($this, 'ajax_send_to_signature'));
        add_action('wp_ajax_ep_create_category', array($this, 'ajax_create_category'));
        add_action('wp_ajax_ep_update_document_category', array($this, 'ajax_update_document_category'));
        add_action('wp_ajax_ep_delete_category', array($this, 'ajax_delete_category'));
        add_action('wp_ajax_ep_get_document_feedback', array($this, 'ajax_get_document_feedback'));
        add_action('wp_ajax_ep_get_document_preview_url', array($this, 'ajax_get_document_preview_url'));
        add_action('wp_ajax_ep_get_live_folder_contents', array($this, 'ajax_get_live_folder_contents'));
        add_action('wp_ajax_ep_get_onedrive_download_url', array($this, 'ajax_get_onedrive_download_url'));
        add_action('wp_ajax_ep_backup_to_onedrive', array($this, 'ajax_backup_to_onedrive'));
        add_action('wp_ajax_ep_backup_all_user_pending', array($this, 'ajax_backup_all_user_pending'));
        add_action('wp_ajax_ep_send_document_to_user', array($this, 'ajax_send_document_to_user'));
        add_action('wp_ajax_ep_share_document', array($this, 'ajax_share_document'));
        add_action('wp_ajax_ep_force_sync_onedrive', array($this, 'ajax_force_sync_onedrive'));
        add_action('wp_ajax_ep_link_to_my_onedrive', array($this, 'ajax_link_to_my_onedrive'));

        add_action('init', array($this, 'register_document_taxonomy'));

        // Security: Block direct access to ep_document posts
        add_action('template_redirect', array($this, 'block_direct_access'));

        // Cron Auto Sync
        add_filter('cron_schedules', array($this, 'add_six_hours_schedule'));
        add_action('ep_onedrive_auto_sync_event', array($this, 'run_auto_sync'));

        // Descomentar esto instalaría el evento en la primera vez que se carga el código
        if (!wp_next_scheduled('ep_onedrive_auto_sync_event')) {
            wp_schedule_event(time(), 'six_hours', 'ep_onedrive_auto_sync_event');
        }

        // --- Integración con IA Bot ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_documents', array($this, 'responder_intent_bot'), 10, 5);
    }

    public function registrar_intent_bot($intents)
    {
        $intents['DOCUMENTS'] = "El usuario quiere ver sus propios documentos, archivos personales, nóminas o ficheros almacenados. Ej: 'mis documentos', 'ver mis nóminas', 'qué archivos tengo'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $params = $intent_data['params'] ?? [];
        $question = $params['question'] ?? '';
        $search_term = $params['search_term'] ?? '';

        // Si hay una pregunta específica sobre el contenido
        if (!empty($question)) {
            return $this->handle_document_query($question, $search_term, $user_id, $bot_instance);
        }

        // Búsqueda de documentos para listado (públicos y privados del usuario)
        $args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => 5,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_ep_document_target_user',
                    'value' => $user_id,
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_document_type',
                    'value' => self::TYPE_PUBLIC,
                    'compare' => '='
                )
            )
        );

        if (!empty($search_term)) {
            $args['s'] = $search_term;
        }

        $docs = get_posts($args);

        if (empty($docs)) {
            return $bot_instance->tarjeta_simple('📂 Documentos', "No he encontrado documentos que coincidan con tu búsqueda.", home_url('/?view=downloads'));
        }

        $facts = [];
        foreach ($docs as $doc) {
            $date = get_the_date('d/m/Y', $doc);
            $type = get_post_meta($doc->ID, '_ep_document_type', true);
            $prefix = ($type === self::TYPE_PUBLIC) ? '🌍' : '🔒';
            $facts[] = ['title' => "$prefix $date", 'value' => mb_substr($doc->post_title, 0, 50)];
        }

        $titulo = !empty($search_term) ? "📂 Resultados para '$search_term'" : "📂 Últimos Documentos";

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => $titulo, 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'FactSet', 'facts' => $facts]
        ], [['type' => 'Action.OpenUrl', 'title' => 'Ver todos en el Portal', 'url' => home_url('/?view=downloads')]]);
    }

    /**
     * Maneja la consulta profunda a la IA sobre un documento público
     */
    private function handle_document_query($question, $search_term, $user_id, $bot_instance)
    {
        // 1. Buscar el documento (Priorizamos PÚBLICOS por seguridad según instrucción)
        $args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_key' => '_ep_document_type',
            'meta_value' => self::TYPE_PUBLIC,
            's' => $search_term,
            'fields' => 'ids'
        );

        $doc_ids = get_posts($args);

        if (empty($doc_ids)) {
            return $bot_instance->tarjeta_simple('📂 Analizador de Documentos', "No he podido encontrar ningún documento público con el nombre '$search_term' para analizar.", '');
        }

        $post_id = $doc_ids[0];
        $file_path = '';

        // Intento 1: Meta key personalizada de este plugin
        $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
        if ($attachment_id) {
            $file_path = get_attached_file($attachment_id);
        }

        // Intento 2: Si el post_id es directamente un attachment
        if (!$file_path || !file_exists($file_path)) {
            $file_path = get_attached_file($post_id);
        }

        // Intento 3: Buscar por meta de WordPress estándar
        if (!$file_path || !file_exists($file_path)) {
            $meta_file = get_post_meta($post_id, '_wp_attached_file', true);
            if ($meta_file) {
                $upload_dir = wp_upload_dir();
                $file_path = $upload_dir['basedir'] . '/' . $meta_file;
            }
        }

        $content = '';
        $ext = '';

        $onedrive_id = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        $content = '';
        $ext = '';

        // Priorizamos OneDrive si el documento tiene ID (cumpliendo con la petición del usuario de usar la sincronización perfecta)
        if ($onedrive_id && class_exists('EP_Auth_O365')) {
            $auth = EP_Auth_O365::get_instance();
            $sync_owner = EP_Auth_O365::get_sync_principal_id();
            $download_url = $auth->get_item_download_url($sync_owner, $onedrive_id);
            
            if (!is_wp_error($download_url)) {
                $response = wp_remote_get($download_url, ['timeout' => 30]);
                if (!is_wp_error($response)) {
                    $content = wp_remote_retrieve_body($response);
                    $filename = get_post_field('post_title', $post_id);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                }
            }
        }

        // Si no se pudo obtener de OneDrive o no tiene ID, probamos local
        if (empty($content)) {
            if ($file_path && file_exists($file_path)) {
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                $content = $this->decrypt_file_content($file_path);
            }
        }

        if (empty($content)) {
            return $bot_instance->tarjeta_simple('📂 Analizador de Documentos', "No se ha podido recuperar el contenido del documento ni de OneDrive ni del servidor local.", '');
        }

        // 2. Validar formato soportado por Gemini (PDF, TXT, CSV)
        $mime_map = [
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv'
        ];

        if (!isset($mime_map[$ext]) || !$content) {
            return $bot_instance->tarjeta_simple('📂 Analizador de Documentos', "Lo siento, actualmente solo puedo analizar el contenido de archivos PDF, TXT o CSV. El formato '$ext' no es compatible para análisis profundo.", home_url('/?view=downloads'));
        }

        // 3. Consultar a la IA
        $ai = EP_AI_Service::get_instance();
        $user_data = get_userdata($user_id);
        $context = [
            'display_name' => $user_data ? $user_data->display_name : 'Usuario'
        ];

        $answer = $ai->query_document($content, $mime_map[$ext], $question, basename($file_path), $context);

        if (is_wp_error($answer)) {
            return $bot_instance->tarjeta_simple('📂 Analizador de Documentos', "Hubo un problema al consultar a la IA: " . $answer->get_error_message(), '');
        }

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "📄 Análisis de: " . basename($file_path), 'weight' => 'Bolder', 'color' => 'Accent'],
            ['type' => 'TextBlock', 'text' => $answer, 'wrap' => true],
            ['type' => 'TextBlock', 'text' => "Preguntado: \"$question\"", 'isSubtle' => true, 'size' => 'Small', 'spacing' => 'Medium']
        ], [
            ['type' => 'Action.OpenUrl', 'title' => '🌐 Ver en Portal', 'url' => home_url('/?view=downloads')]
        ]);
    }


    public function block_direct_access()
    {
        if (is_singular(self::POST_TYPE)) {
            wp_redirect(home_url());
            exit;
        }
    }

    private function get_encryption_key()
    {
        $salt = defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'default_salt_for_encryption_ep_portal';
        return hash('sha256', $salt, true);
    }

    private function encrypt_file($file_path)
    {
        if (!file_exists($file_path))
            return false;
        $data = file_get_contents($file_path);

        $key = $this->get_encryption_key();
        $iv_len = openssl_cipher_iv_length('aes-256-ctr');
        $iv = openssl_random_pseudo_bytes($iv_len);

        $encrypted = openssl_encrypt($data, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false)
            return false;

        return file_put_contents($file_path, 'ENC:' . $iv . $encrypted);
    }

    private function decrypt_file_content($file_path)
    {
        if (!file_exists($file_path))
            return false;
        $content = file_get_contents($file_path);

        if (mb_substr($content, 0, 4, '8bit') !== 'ENC:') {
            return $content;
        }

        $content = mb_substr($content, 4, null, '8bit');
        $key = $this->get_encryption_key();
        $iv_len = openssl_cipher_iv_length('aes-256-ctr');
        $iv = mb_substr($content, 0, $iv_len, '8bit');
        $encrypted = mb_substr($content, $iv_len, null, '8bit');

        return openssl_decrypt($encrypted, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
    }

    public function ajax_handle_upload()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        global $ep_app_manager;

        $type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : self::TYPE_PUBLIC;
        $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
        $folder_id = isset($_POST['category_id']) ? sanitize_text_field($_POST['category_id']) : '';

        $has_write = ($ep_app_manager->get_user_permission('downloads') === 'write');
        
        if (!$has_write) {
            // Trabajadores solo pueden subir a su propia unidad (PRIVATE y target = ellos mismos o 0)
            if ($type !== self::TYPE_PRIVATE || ($target_user_id !== 0 && $target_user_id !== get_current_user_id())) {
                wp_send_json_error('No tienes permisos para subir documentos en esta sección.');
            }
        }

        // Auto-asignación para subidas personales si no se especifica destinatario
        if ($type === self::TYPE_PRIVATE && $target_user_id === 0) {
            $target_user_id = get_current_user_id();
        }

        if (!isset($_FILES['ep_document_file'])) {
            wp_send_json_error('No se ha subido ningún archivo.');
        }

        $file = $_FILES['ep_document_file'];
        // Extended format support: Office, CSV, OpenDocument
        $allowed_types_str = get_option('ep_downloads_allowed_types', 'jpg,png,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,odt,ods,odp');
        $allowed_types = array_map('trim', explode(',', $allowed_types_str));
        $max_size_mb = get_option('ep_downloads_max_size', 3);
        $max_size_bytes = $max_size_mb * 1024 * 1024;

        // Validation
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_types)) {
            wp_send_json_error('Tipo de archivo no permitido. Tipos permitidos: ' . $allowed_types_str);
        }

        if ($file['size'] > $max_size_bytes) {
            wp_send_json_error('El archivo excede el tamaño máximo permitido de ' . $max_size_mb . 'MB.');
        }

        if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            wp_send_json_error('Error al subir el archivo temporal.');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Subir a WP localmente de forma temporal
        $attachment_id = media_handle_upload('ep_document_file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error('Error al procesar el archivo: ' . $attachment_id->get_error_message());
        }

        $file_path = get_attached_file($attachment_id);
        $filename = sanitize_file_name($file['name']);

        $post_id = wp_insert_post(array(
            'post_title' => $filename,
            'post_status' => 'publish',
            'post_type' => self::POST_TYPE,
            'post_author' => get_current_user_id()
        ));

        if (!$post_id) {
            wp_delete_attachment($attachment_id, true);
            wp_send_json_error('Error al crear el registro del documento.');
        }

        // Guardar metadatos locales (NO guardamos attachment_id porque borraremos local)
        update_post_meta($post_id, '_ep_document_type', $type);
        update_post_meta($post_id, '_ep_document_target_user', $target_user_id);
        update_post_meta($post_id, '_ep_document_size', filesize($file_path));

        // Asignar carpeta a través de la taxonomía del post de WP
        if (!empty($folder_id) && $folder_id !== '0') {
            wp_set_object_terms($post_id, intval($folder_id), 'ep_document_category');
        }

        $is_global = ($type === self::TYPE_PUBLIC);
        // owner_id: para privados, quién es el dueño real del documento
        // Si es una subida propia (trabajador), es el usuario actual
        // Si el admin asigna a otro usuario, es target_user_id
        if ($is_global) {
            $owner_id = 0;
        } elseif ($target_user_id > 0) {
            $owner_id = $target_user_id;
        } else {
            $owner_id = get_current_user_id();
        }

        // ---- Subir a OneDrive inmediatamente y borrar local ----
        if (class_exists('EP_Auth_O365')) {
            $auth = EP_Auth_O365::get_instance();
            
            // Todas las carpetas maestras viven en el OneDrive del admin.
            // Usamos SIEMPRE el token del admin para subir.
            // Para privados, upload_to_onedrive creará la subcarpeta [email_usuario] automáticamente.
            $admin_id = EP_Auth_O365::get_sync_principal_id();
            $target_od_user = $admin_id;
            
            // Determinar subcarpeta OneDrive (categoría)
            $folder_type = 'documentos';
            if (!empty($folder_id) && $folder_id !== '0') {
                $term = get_term($folder_id, 'ep_document_category');
                if (!is_wp_error($term) && $term) {
                    $folder_type = $term->name;
                }
            }

            // Pasar owner_id para que los privados vayan a la subcarpeta correcta del usuario
            $result = $auth->upload_to_onedrive($target_od_user, $file_path, $filename, $folder_type, $type, $owner_id);
            
            if (!is_wp_error($result) && isset($result['id'])) {
                update_post_meta($post_id, '_ep_onedrive_item_id', sanitize_text_field($result['id']));
                update_post_meta($post_id, '_ep_onedrive_synced', '1');
                update_post_meta($post_id, '_ep_onedrive_last_sync', current_time('mysql'));
                // Guardar el propietario real para que la vista lo filtre correctamente
                update_post_meta($post_id, '_ep_document_target_user', $owner_id);
            } else {
                $error_detail = is_wp_error($result) ? $result->get_error_message() : wp_json_encode($result);
                ep_error_log('EP_Downloads upload_to_onedrive falló. admin=' . $target_od_user . ' owner=' . $owner_id . ' type=' . $type . ' error=' . $error_detail);
                wp_delete_post($post_id, true);
                wp_delete_attachment($attachment_id, true);
                wp_send_json_error('No se pudo subir a OneDrive: ' . $error_detail);
            }
        }

        // Borrar el archivo local y el attachment de WP porque SOLO guardamos en OneDrive
        wp_delete_attachment($attachment_id, true);

        // Enviar notificación si es personal
        if (!$is_global && $owner_id > 0) {
            $current_user = wp_get_current_user();
            $author_name = $current_user->display_name;
            if ($owner_id !== get_current_user_id()) {
                if (class_exists('EP_Notifications')) {
                    EP_Notifications::add_notification(
                        $owner_id,
                        array(
                            'title' => 'Nuevo documento subido',
                            'message' => $author_name . ' ha subido un nuevo documento: ' . $filename,
                            'type' => 'download'
                        )
                    );
                }
            }
        }

        // Objeto de respuesta mockeado para frontend
        $response_item = array(
            'id' => $post_id,
            'name' => $filename,
            'type' => 'file',
            'wp_id' => $post_id,
        );

        wp_send_json_success(array(
            'message' => 'Archivo subido y cifrado correctamente en el servidor seguro.',
            'item' => $response_item
        ));
    }

    public function ajax_secure_download()
    {
        if (!isset($_GET['id'])) {
            wp_die('ID de documento no proporcionado.');
        }

        $post_id = intval($_GET['id']);

        // Verificar Nonce (Seguridad extra contra hotlinking)
        if (!isset($_GET['security']) || !wp_verify_nonce($_GET['security'], 'ep_download_' . $post_id)) {
            wp_die('Enlace de descarga expirado o inválido.');
        }

        // Blindaje extra: Verificar tipo de post para no dar acceso a otras cosas de WP
        if (get_post_type($post_id) !== self::POST_TYPE) {
            wp_die('ID de documento no reconocido o formato inválido.');
        }

        // 1. Verificar si el usuario está logueado
        if (!is_user_logged_in()) {
            wp_die('Acceso denegado. Debes iniciar sesión.');
        }

        $current_user_id = get_current_user_id();
        $doc_type = get_post_meta($post_id, '_ep_document_type', true);
        $doc_target = intval(get_post_meta($post_id, '_ep_document_target_user', true));
        $uploader = intval(get_post_field('post_author', $post_id));

        // 2. Lógica de Permisos de Descarga
        $can_download = false;

        // Caso A: RRHH / Administradores (Permiso 'write' en downloads)
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('downloads') === 'write') {
            $can_download = true;
        }
        // Caso B: Es el destinatario del archivo privado
        elseif ($doc_type === self::TYPE_PRIVATE && $doc_target === $current_user_id) {
            $can_download = true;
        }
        // Caso C: Es un archivo público
        elseif ($doc_type !== self::TYPE_PRIVATE && $doc_target <= 0) {
            $can_download = true;
        }
        // Caso D: Es el propio autor que lo subió
        elseif ($uploader === $current_user_id) {
            $can_download = true;
        }

        if (!$can_download) {
            wp_die('No tienes permisos para descargar este archivo privado.');
        }

        // 3. Servir el archivo
        $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
        $file_path = $attachment_id ? get_attached_file($attachment_id) : false;

        if ($file_path && file_exists($file_path)) {
            $filename = basename($file_path);
            $filetype = wp_check_filetype($file_path);

            // Check if encrypted
            $is_encrypted = (mb_substr(@file_get_contents($file_path, false, null, 0, 4), 0, 4, '8bit') === 'ENC:');

            if ($is_encrypted) {
                $content = $this->decrypt_file_content($file_path);
                if ($content === false) {
                    wp_die('Error al descifrar el archivo.');
                }
                $filesize = strlen($content);
            } else {
                $filesize = filesize($file_path);
            }

            header('Content-Description: File Transfer');
            header('Content-Type: ' . ($filetype['type'] ?: 'application/octet-stream'));
            if (isset($_GET['preview']) && $_GET['preview'] == '1') {
                header('Content-Disposition: inline; filename="' . $filename . '"');
            } else {
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . $filesize);

            // Limpiar buffers
            if (ob_get_level())
                ob_end_clean();

            if ($is_encrypted && isset($content)) {
                echo $content;
            } else {
                readfile($file_path);
            }
            exit;
        }

        // 4. Fallback a OneDrive (Backup) si el archivo local no se encuentra
        $onedrive_id = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        if ($onedrive_id && class_exists('EP_Auth_O365')) {
            $target_user_id = get_post_meta($post_id, '_ep_document_target_user', true) ?: $uploader;
            $download_url = EP_Auth_O365::get_instance()->get_item_download_url((int) $target_user_id, $onedrive_id);

            if (!is_wp_error($download_url)) {
                wp_redirect($download_url);
                exit;
            }
        }

        wp_die('El archivo no existe físicamente en el servidor ni se pudo encontrar en el backup de OneDrive.');
    }

    public function ajax_handle_delete()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        if (!isset($_POST['document_id'])) {
            wp_send_json_error('ID de documento no proporcionado.');
        }

        $post_id = intval($_POST['document_id']);
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'personal';

        if (!$this->can_delete_document($post_id)) {
            wp_send_json_error('No tienes permisos para borrar este documento.');
        }

        if ($post_id <= 0 || get_post_type($post_id) !== self::POST_TYPE) {
            wp_send_json_error('Documento inválido.');
        }

        // Si existe un OneDrive ID asociado (backup), intentar borrarlo en background
        $onedrive_id = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        if ($onedrive_id && class_exists('EP_Auth_O365')) {
            $doc_type = get_post_meta($post_id, '_ep_document_type', true);

            // CORRECCIÓN: Para archivos privados, el dueño de la carpeta de OneDrive es el destinatario (_ep_document_target_user)
            if ($doc_type === self::TYPE_PRIVATE) {
                $sync_owner = (int) get_post_meta($post_id, '_ep_document_target_user', true);
                if ($sync_owner <= 0) {
                    $sync_owner = (int) get_post_field('post_author', $post_id);
                }
            } else {
                $sync_owner = EP_Auth_O365::get_sync_principal_id();
            }

            if ($sync_owner > 0) {
                EP_Auth_O365::get_instance()->delete_onedrive_item($sync_owner, $onedrive_id);
            }
        }

        // Borrar el attachment local
        $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        // Borrar el post
        wp_delete_post($post_id, true);

        wp_send_json_success('Documento borrado correctamente.');
    }

    public function register_document_taxonomy()
    {
        $labels = array(
            'name' => _x('Categorías de Documentos', 'Taxonomy General Name', 'employee-portal'),
            'singular_name' => _x('Categoría de Documento', 'Taxonomy Singular Name', 'employee-portal'),
            'menu_name' => __('Categorías', 'employee-portal'),
        );
        $args = array(
            'labels' => $labels,
            'hierarchical' => true,
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
        );
        register_taxonomy('ep_document_category', array(self::POST_TYPE), $args);
    }

    public function ajax_update_review_status()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $post_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : ''; // 'ok' o 'feedback'
        $feedback = isset($_POST['feedback']) ? sanitize_textarea_field($_POST['feedback']) : '';

        if (!$post_id) {
            wp_send_json_error('ID de documento no válido.');
        }

        // Seguridad: Solo el destinatario o RRHH pueden actualizar
        $current_user_id = get_current_user_id();
        $target_user = intval(get_post_meta($post_id, '_ep_document_target_user', true));
        global $ep_app_manager;
        $is_hr = ($ep_app_manager->get_user_permission('downloads') === 'write');

        if ($target_user !== $current_user_id && !$is_hr) {
            wp_send_json_error('No tienes permiso para revisar este documento.');
        }

        update_post_meta($post_id, '_ep_document_review_status', $status);
        if ($feedback) {
            update_post_meta($post_id, '_ep_document_feedback', $feedback); // Usar clave corta para consistencia
        }
        update_post_meta($post_id, '_ep_document_review_date', current_time('mysql'));

        // Notificar al autor (si no es el mismo que revisa)
        $author_id = get_post_field('post_author', $post_id);
        if ($author_id != $current_user_id) {
            if (class_exists('EP_Notifications')) {
                $reviewer_name = wp_get_current_user()->display_name;
                $doc_title = get_the_title($post_id);
                EP_Notifications::add_notification($author_id, [
                    'type' => ($status === 'ok' ? 'success' : 'warning'),
                    'title' => 'Revisión de documento',
                    'message' => "$reviewer_name ha revisado \"$doc_title\" con resultado: " . strtoupper($status),
                    'link' => '?view=downloads'
                ]);
            }
        }

        wp_send_json_success('Estado de revisión actualizado.');
    }

    public function ajax_send_to_signature()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        global $ep_app_manager;
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error('ID de documento no válido.');
        }

        // Validación de permisos: Permitir si es admin/RRHH O si es el dueño/receptor de un documento privado
        $can_write_global = ($ep_app_manager->get_user_permission('downloads') === 'write');
        $is_authorized = $can_write_global;

        if (!$is_authorized) {
            $doc_type = get_post_meta($post_id, '_ep_document_type', true);
            $owner_id = get_post_field('post_author', $post_id);
            $target_id = get_post_meta($post_id, '_ep_document_target_user', true);
            $current_user_id = get_current_user_id();

            if ($doc_type === self::TYPE_PRIVATE && ($owner_id == $current_user_id || $target_id == $current_user_id)) {
                $is_authorized = true;
            }
        }

        if (!$is_authorized) {
            wp_send_json_error('No tienes permisos para enviar este documento a firma.');
        }

        $signer_id = isset($_POST['signer_id']) ? intval($_POST['signer_id']) : 0;

        if (!$signer_id) {
            wp_send_json_error('Debes seleccionar un firmante.');
        }

        $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
        $onedrive_id   = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        $title         = get_the_title($post_id);

        $temp_file = false;

        if ($attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                wp_send_json_error('El archivo local no existe en el servidor.');
            }
            // Desencriptar a temp
            $temp_file = $this->decrypt_to_temp($file_path, basename($file_path));
        } elseif ($onedrive_id && class_exists('EP_Auth_O365')) {
            $target_user_id = get_post_meta($post_id, '_ep_document_target_user', true) ?: get_post_field('post_author', $post_id);
            
            // Si es public, target user es el admin/sync principal
            $doc_type = get_post_meta($post_id, '_ep_document_type', true);
            if ($doc_type === self::TYPE_PUBLIC) {
                 $target_user_id = EP_Auth_O365::get_sync_principal_id();
            }

            $auth = EP_Auth_O365::get_instance();
            $download_url = $auth->get_item_download_url((int) $target_user_id, $onedrive_id);
            
            if (is_wp_error($download_url) || empty($download_url)) {
                wp_send_json_error('Error al obtener el enlace de descarga de OneDrive.');
            }
            
            // Descargar temporalmente el archivo
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            $temp_download = download_url($download_url);
            
            if (is_wp_error($temp_download)) {
                wp_send_json_error('Error al descargar el archivo desde OneDrive: ' . $temp_download->get_error_message());
            }
            
            // Asegurar que tenga la extensión correcta (ej. .pdf) para la app de firmas
            $ext = strtolower(get_post_meta($post_id, '_ep_document_file_ext', true) ?: 'pdf');
            $new_temp_file = $temp_download . '.' . $ext;
            rename($temp_download, $new_temp_file);
            $temp_file = $new_temp_file;
        } else {
             wp_send_json_error('No se encontró el archivo local ni en OneDrive.');
        }

        if (!$temp_file || !file_exists($temp_file)) {
            wp_send_json_error('Error al preparar el archivo para la firma.');
        }

        // 2. Enviar a App de Firma
        $signature_class = '';
        if (class_exists('EP_App_Signature_V4')) {
            $signature_class = 'EP_App_Signature_V4';
        } elseif (class_exists('EP_App_Signature')) {
            $signature_class = 'EP_App_Signature';
        }

        if ($signature_class) {
            $signer_user = get_userdata($signer_id);
            $signer_name = $signer_user ? $signer_user->display_name : 'Usuario Desconocido';

            $result = $signature_class::add_signature_request(
                $signer_id,
                $temp_file,
                $title,
                get_current_user_id()
            );

            // Limpieza del archivo temporal
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }

            if (is_wp_error($result)) {
                wp_send_json_error('Error al enviar al buzón: ' . $result->get_error_message());
            }

            update_post_meta($post_id, '_ep_document_signature_request_id', $result);
            wp_send_json_success('Documento enviado correctamente al buzón de firma de ' . $signer_name);
        } else {
            wp_send_json_error('La aplicación de firma no está activa.');
        }
    }

    /**
     * AJAX: Crear nueva categoría/carpeta en OneDrive
     */
    public function ajax_create_category()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';

        if (empty($name)) {
            wp_send_json_error('El nombre no puede estar vacío.');
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'personal';
        $owner_id = ($type === 'global') ? 0 : get_current_user_id();
        $parent_folder_id = isset($_POST['parent_folder_id']) ? intval($_POST['parent_folder_id']) : 0;

        // Permisos de validación: sólo los de RRHH (o admin) pueden crear carpetas globales
        if ($owner_id === 0 && !current_user_can('manage_options') && !EP_App_Manager::user_has_app_permission('downloads', 'write')) {
            wp_send_json_error('No tienes permisos para crear carpetas públicas.');
        }

        $term_args = array();
        if ($parent_folder_id > 0) {
            $term_args['parent'] = $parent_folder_id;
        }

        $term = wp_insert_term($name, 'ep_document_category', $term_args);

        if (is_wp_error($term)) {
            wp_send_json_error('Error al crear la carpeta: ' . $term->get_error_message());
        }

        $term_id = $term['term_id'];
        update_term_meta($term_id, '_ep_category_owner', $owner_id);

        // Also create the empty folder in OneDrive if MS 365 is active
        if (class_exists('EP_Auth_O365')) {
            $auth = EP_Auth_O365::get_instance();
            
            // CORRECCIÓN: Si la carpeta es global, el dueño en OneDrive DEBE SER el Sync Principal.
            if ($owner_id === 0) {
                $od_user = EP_Auth_O365::get_sync_principal_id();
                $doc_type = 'public';
            } else {
                $od_user = $owner_id;
                $doc_type = 'private';
            }

            if ($od_user > 0) {
                $od_folder = $auth->create_folder_in_onedrive($od_user, $name, $doc_type);

                if (!is_wp_error($od_folder) && isset($od_folder['id'])) {
                    update_term_meta($term_id, '_ep_onedrive_item_id', $od_folder['id']);
                } else if (is_wp_error($od_folder)) {
                    ep_error_log("EP_Downloads: Error creating folder in OneDrive: " . $od_folder->get_error_message());
                }
            }
        }

        wp_send_json_success(array(
            'id' => (string) $term_id,
            'name' => $name,
            'type' => 'folder'
        ));
    }


    /**
     * AJAX: Borrar categoría/carpeta
     */
    public function ajax_delete_category()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
        if (!$folder_id) {
            $folder_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        }

        if ($folder_id <= 0) {
            wp_send_json_error('ID de carpeta inválido.');
        }

        $term = get_term($folder_id, 'ep_document_category');
        if (is_wp_error($term) || !$term) {
            wp_send_json_error('Carpeta no encontrada.');
        }

        $owner_id = (int) get_term_meta($folder_id, '_ep_category_owner', true);

        // Lógica de permisos de borrado
        $can_delete = false;

        if ($owner_id === 0) {
            if (current_user_can('manage_options') || EP_App_Manager::user_has_app_permission('downloads', 'write')) {
                $can_delete = true;
            }
        } else {
            if (current_user_can('manage_options') || EP_App_Manager::user_has_app_permission('downloads', 'write') || get_current_user_id() === $owner_id) {
                $can_delete = true;
            }
        }

        if (!$can_delete) {
            wp_send_json_error('No tienes permiso para borrar esta carpeta.');
        }

        // --- Sincronizar borrado con OneDrive ---
        $onedrive_id = get_term_meta($folder_id, '_ep_onedrive_item_id', true);
        if ($onedrive_id && class_exists('EP_Auth_O365')) {
            // CORRECCIÓN: El dueño de la carpeta en OneDrive se determina igual que para archivos.
            if ($owner_id === 0) {
                $sync_owner = EP_Auth_O365::get_sync_principal_id();
            } else {
                $sync_owner = $owner_id;
            }

            if ($sync_owner > 0) {
                $result_od = EP_Auth_O365::get_instance()->delete_onedrive_item($sync_owner, $onedrive_id);
                if (is_wp_error($result_od)) {
                    ep_error_log("EP_Downloads: Error deleting folder in OneDrive: " . $result_od->get_error_message());
                    // No detenemos el borrado local, pero lo registramos.
                }
            }
        }

        $result = wp_delete_term($folder_id, 'ep_document_category');

        if (is_wp_error($result)) {
            wp_send_json_error('Error al borrar la carpeta localmente: ' . $result->get_error_message());
        }

        wp_send_json_success('Carpeta borrada correctamente.');
    }

    /**
     * AJAX: Actualizar categoría de un documento (Drag & Drop en Live API View)
     */
    public function ajax_update_document_category()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('downloads') !== 'write') {
            wp_send_json_error('No tienes permisos para organizar documentos.');
        }

        $item_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        $new_folder_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

        if ($item_id <= 0) {
            wp_send_json_error('ID de documento no válido.');
        }

        if (get_post_type($item_id) !== self::POST_TYPE) {
            wp_send_json_error('Documento no encontrado.');
        }

        if ($new_folder_id > 0) {
            $term = get_term($new_folder_id, 'ep_document_category');
            if (is_wp_error($term) || !$term) {
                wp_send_json_error('Carpeta destino no encontrada.');
            }
        }

        $term_ids = ($new_folder_id > 0) ? array($new_folder_id) : array();
        $result = wp_set_object_terms($item_id, $term_ids, 'ep_document_category', false);

        if (is_wp_error($result)) {
            wp_send_json_error('Error al mover el documento: ' . $result->get_error_message());
        }

        // --- Sincronizar movimiento con OneDrive si está respaldado ---
        $onedrive_id = get_post_meta($item_id, '_ep_onedrive_item_id', true);
        if ($onedrive_id && class_exists('EP_Auth_O365')) {
            $auth = EP_Auth_O365::get_instance();

            $folder_type = 'documentos';
            if ($new_folder_id > 0 && isset($term) && !is_wp_error($term)) {
                $folder_type = $term->name;
            }

            $doc_type = get_post_meta($item_id, '_ep_document_type', true);
            $target_user_id = (int) get_post_meta($item_id, '_ep_document_target_user', true);
            $owner_id = ($doc_type === 'public') ? 0 : $target_user_id;

            $od_user = ($owner_id > 0) ? $owner_id : get_current_user_id();

            $move_result = $auth->move_item_to_category($od_user, $onedrive_id, $folder_type, $doc_type);

            if (is_wp_error($move_result)) {
                ep_error_log("EP_Downloads: Error moving item in OneDrive: " . $move_result->get_error_message());
            }
        }

        wp_send_json_success('Documento agrupado correctamente en la carpeta local.');
    }

    public function ajax_get_live_folder_contents()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'personal';
        $user_id = get_current_user_id();

        $is_global = ($type === self::TYPE_PUBLIC || $type === 'global');
        $owner_id = $is_global ? 0 : $user_id;

        $contents = array();

        // 1. Obtener carpetas (terms)
        $term_args = array(
            'taxonomy' => 'ep_document_category',
            'hide_empty' => false,
            'parent' => $folder_id,
            'meta_query' => array(
                array(
                    'key' => '_ep_category_owner',
                    'value' => $owner_id,
                    'compare' => '='
                )
            )
        );
        $terms = get_terms($term_args);

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $contents[] = array(
                    'id' => (string) $term->term_id,
                    'name' => $term->name,
                    'type' => 'folder',
                    'folder' => array('childCount' => $term->count)
                );
            }
        }

        // 2. Obtener archivos (posts)
        $post_args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_document_type',
                    'value' => $is_global ? self::TYPE_PUBLIC : self::TYPE_PRIVATE,
                    'compare' => '='
                )
            )
        );

        if ($folder_id > 0) {
            $post_args['tax_query'] = array(
                array(
                    'taxonomy' => 'ep_document_category',
                    'field' => 'term_id',
                    'terms' => $folder_id,
                    'include_children' => false
                )
            );
        } else {
            // Buscar aquellos que NO están en ninguna categoría ep_document_category
            $post_args['tax_query'] = array(
                array(
                    'taxonomy' => 'ep_document_category',
                    'operator' => 'NOT EXISTS'
                )
            );
        }

        $posts = array();

        if ($is_global) {
            // PÚBLICO: solo documentos marcados como public, sin filtrar por usuario
            $posts = get_posts($post_args);
        } else {
            // PRIVADO: EXCLUSIVAMENTE los documentos donde el propietario real es este usuario.
            $post_args['meta_query'][] = array(
                'key'     => '_ep_document_target_user',
                'value'   => $user_id,
                'compare' => '=',
                'type'    => 'NUMERIC'
            );
            $posts = get_posts($post_args);
        }

        if (!empty($posts)) {
            foreach ($posts as $post) {
                $status = get_post_meta($post->ID, '_ep_document_review_status', true) ?: 'pending';
                $onedrive_id = get_post_meta($post->ID, '_ep_onedrive_item_id', true);

                $download_url = admin_url('admin-ajax.php?action=ep_secure_download&id=' . $post->ID . '&security=' . wp_create_nonce('ep_download_' . $post->ID));

                $attachment_id = get_post_meta($post->ID, '_ep_document_attachment_id', true);
                $file_path = $attachment_id ? get_attached_file($attachment_id) : '';
                $filesize = (int) get_post_meta($post->ID, '_ep_document_size', true);
                if (!$filesize && $file_path && file_exists($file_path)) {
                    $filesize = filesize($file_path);
                }

                $owner_name = '';
                $is_shared_with_me = false;

                $target_u = get_post_meta($post->ID, '_ep_document_target_user', true);
                if ($target_u) {
                    $u_data = get_userdata($target_u);
                    if ($u_data) $owner_name = $u_data->display_name;
                }

                $shared_with   = get_post_meta($post->ID, '_ep_shared_with', true);
                $shared_with   = is_array($shared_with) ? $shared_with : [];
                $shared_names  = [];
                foreach ($shared_with as $shared_uid) {
                    $su = get_userdata($shared_uid);
                    if ($su) $shared_names[] = $su->display_name;
                }
                $is_shared_with_me = in_array($user_id, $shared_with) || in_array((string)$user_id, $shared_with);

                $contents[] = array(
                    'id'                  => (string) $post->ID,
                    'wp_id'               => $post->ID,
                    'onedrive_id'         => $onedrive_id,
                    'name'                => get_the_title($post->ID),
                    'createdDateTime'     => $post->post_date,
                    'lastModified'        => $post->post_modified,
                    'type'                => 'file',
                    'review_status'       => $status,
                    'requires_signature'  => get_post_meta($post->ID, '_ep_document_requires_signature', true) == '1',
                    'is_signed'           => get_post_meta($post->ID, '_ep_document_is_signed', true) == '1',
                    'feedback'            => get_post_meta($post->ID, '_ep_document_feedback', true),
                    'oki_status'          => ($status === 'ok'),
                    'downloadUrl'         => $download_url,
                    'size'                => $filesize,
                    'owner_name'          => $owner_name,
                    'shared_with'         => $shared_names,
                    'is_shared'           => !empty($shared_with),
                    'is_shared_with_me'   => $is_shared_with_me,
                    'file'                => array()
                );
            }
        }

        // Para la vista PRIVADA: añadir también documentos compartidos con el usuario actual
        if (!$is_global) {
            $shared_args = array(
                'post_type'      => self::POST_TYPE,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_ep_shared_with',
                        'value'   => sprintf('s:%d:"%s";', strlen((string)$user_id), $user_id),
                        'compare' => 'LIKE'
                    ),
                    array(
                        'key'     => '_ep_shared_with',
                        'value'   => 'i:' . $user_id . ';',
                        'compare' => 'LIKE'
                    ),
                    array(
                        'key'     => '_ep_shared_with',
                        'value'   => '"' . $user_id . '"',
                        'compare' => 'LIKE'
                    )
                )
            );
            $shared_posts = get_posts($shared_args);

            // Evitar duplicados con los ya listados
            $already_ids = array_column($contents, 'wp_id');

            foreach ($shared_posts as $post) {
                if (in_array($post->ID, $already_ids)) continue;

                $status      = get_post_meta($post->ID, '_ep_document_review_status', true) ?: 'pending';
                $onedrive_id = get_post_meta($post->ID, '_ep_onedrive_item_id', true);
                $download_url = admin_url('admin-ajax.php?action=ep_secure_download&id=' . $post->ID . '&security=' . wp_create_nonce('ep_download_' . $post->ID));
                $attachment_id = get_post_meta($post->ID, '_ep_document_attachment_id', true);
                $file_path = $attachment_id ? get_attached_file($attachment_id) : '';
                $filesize  = (int) get_post_meta($post->ID, '_ep_document_size', true);
                if (!$filesize && $file_path && file_exists($file_path)) {
                    $filesize = filesize($file_path);
                }
                $target_u = get_post_meta($post->ID, '_ep_document_target_user', true) ?: $post->post_author;
                $owner_user   = get_userdata($target_u);
                $owner_name   = $owner_user ? $owner_user->display_name : '';
                $shared_with  = get_post_meta($post->ID, '_ep_shared_with', true);
                $shared_with  = is_array($shared_with) ? $shared_with : [];
                $shared_names = [];
                foreach ($shared_with as $uid) {
                    $su = get_userdata($uid);
                    if ($su) $shared_names[] = $su->display_name;
                }

                $contents[] = array(
                    'id'                 => (string) $post->ID,
                    'wp_id'              => $post->ID,
                    'onedrive_id'        => $onedrive_id,
                    'name'               => get_the_title($post->ID),
                    'createdDateTime'    => $post->post_date,
                    'lastModified'       => $post->post_modified,
                    'type'               => 'file',
                    'review_status'      => $status,
                    'requires_signature' => get_post_meta($post->ID, '_ep_document_requires_signature', true) == '1',
                    'is_signed'          => get_post_meta($post->ID, '_ep_document_is_signed', true) == '1',
                    'feedback'           => get_post_meta($post->ID, '_ep_document_feedback', true),
                    'oki_status'         => ($status === 'ok'),
                    'downloadUrl'        => $download_url,
                    'size'               => $filesize,
                    'owner_name'         => $owner_name,
                    'shared_with'        => $shared_names,
                    'is_shared'          => true,
                    'is_shared_with_me'  => true,
                    'file'               => array()
                );
            }
        }

        wp_send_json_success(array(
            'items' => $contents,
            'folder_id' => $folder_id,
            'cached' => false
        ));
    }

    public function ajax_get_document_feedback()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $post_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        if ($post_id > 0) {
            $feedback = get_post_meta($post_id, '_ep_document_feedback', true);
            wp_send_json_success($feedback);
        }
        wp_send_json_error('No se pudo obtener el feedback.');
    }

    public function ajax_backup_to_onedrive()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        if (!isset($_POST['document_id'])) {
            wp_send_json_error('ID de documento no proporcionado.');
        }

        $post_id = intval($_POST['document_id']);
        $current_user_id = get_current_user_id();

        // 1. Verificaciones y Permisos
        $doc_type = get_post_meta($post_id, '_ep_document_type', true);
        $doc_target = (int) get_post_meta($post_id, '_ep_document_target_user', true);
        $uploader = (int) get_post_field('post_author', $post_id);

        // Solo permitir respaldo temporalmente a los dueños, recipientes o admins
        global $ep_app_manager;
        $can_backup = false;
        if ($ep_app_manager->get_user_permission('downloads') === 'write') {
            $can_backup = true;
        } elseif ($doc_type === self::TYPE_PRIVATE && $doc_target === $current_user_id) {
            $can_backup = true;
        } elseif ($uploader === $current_user_id) {
            $can_backup = true;
        }

        if (!$can_backup) {
            wp_send_json_error('No tienes permisos para respaldar este archivo.');
        }

        // 2. Comprobar si ya está respaldado
        $existing_onedrive_id = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        if ($existing_onedrive_id) {
            wp_send_json_error('El archivo ya está respaldado en OneDrive.');
        }

        // 3. Preparar archivo (Desencriptar temporalmente si es necesario)
        $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
        $file_path = $attachment_id ? get_attached_file($attachment_id) : false;

        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error('Archivo físico no encontrado en el servidor.');
        }

        $filename = basename($file_path);
        $is_encrypted = (mb_substr(@file_get_contents($file_path, false, null, 0, 4), 0, 4, '8bit') === 'ENC:');
        $upload_path = $file_path;
        $temp_path = '';

        if ($is_encrypted) {
            $temp_path = $this->decrypt_to_temp($file_path, $filename);
            if (!$temp_path || !file_exists($temp_path)) {
                wp_send_json_error('No se pudo desencriptar el archivo temporalmente para la subida.');
            }
            $upload_path = $temp_path;
        }

        // 4. Subir a OneDrive
        if (!class_exists('EP_Auth_O365')) {
            if ($temp_path)
                @unlink($temp_path);
            wp_send_json_error('El módulo de Microsoft 365 no está activo.');
        }

        $auth = EP_Auth_O365::get_instance();

        // El backup de usuarios es personal (a la cuenta de ese usuario)
        // Para public files se debió restringir lógicamente en la UI, pero si llegan aquííí
        // los subimos a su cuenta personal de igual modo.
        $target_od_user = ($doc_target > 0) ? $doc_target : $current_user_id;

        $folder_type = 'documentos';
        $terms = wp_get_post_terms($post_id, 'ep_document_category');
        if (!empty($terms) && !is_wp_error($terms)) {
            $folder_type = $terms[0]->name;
        }

        $result = $auth->upload_to_onedrive($target_od_user, $upload_path, $filename, $folder_type, $doc_type);

        // Limpiamos temp path
        if ($temp_path) {
            @unlink($temp_path);
        }

        if (is_wp_error($result)) {
            wp_send_json_error('Error al subir a OneDrive: ' . $result->get_error_message());
        }

        $item_id = is_array($result) ? ($result['id'] ?? null) : ($result->id ?? null);

        if ($item_id) {
            update_post_meta($post_id, '_ep_onedrive_item_id', sanitize_text_field($item_id));
            update_post_meta($post_id, '_ep_onedrive_synced', '1');
            update_post_meta($post_id, '_ep_onedrive_last_sync', current_time('mysql'));

            // Invalida cache
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ep_onedrive_folder_%'");

            wp_send_json_success(array(
                'message' => 'Archivo respaldado con éxito en OneDrive',
                'onedrive_id' => $item_id
            ));
        }

        wp_send_json_error('Respuesta inesperada al subir el archivo a OneDrive.');
    }

    private function decrypt_to_temp($file_path, $filename)
    {
        $content = $this->decrypt_file_content($file_path);
        $temp_dir = get_temp_dir();
        $temp_path = $temp_dir . 'fds_tmp_' . bin2hex(random_bytes(4)) . '_' . sanitize_file_name($filename);
        file_put_contents($temp_path, $content);
        return $temp_path;
    }

    public function register_documents_cpt()
    {
        $labels = array(
            'name' => _x('Recursos', 'Post Type General Name', 'employee-portal'),
            'singular_name' => _x('Recurso', 'Post Type Singular Name', 'employee-portal'),
            'menu_name' => __('Recursos y Gestión', 'employee-portal'),
        );
        $args = array(
            'label' => __('Recurso', 'employee-portal'),
            'labels' => $labels,
            'supports' => array('title', 'author'),
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 7,
            'menu_icon' => 'dashicons-portfolio',
            'capability_type' => 'post',
        );
        register_post_type(self::POST_TYPE, $args);
    }

    public function register_settings()
    {
        register_setting('ep_portal_settings', 'ep_downloads_allowed_types');
        register_setting('ep_portal_settings', 'ep_downloads_max_size');
    }

    public function handle_document_upload()
    {
        if (isset($_POST['ep_upload_document']) && isset($_FILES['ep_document_file'])) {
            if (!wp_verify_nonce($_POST['ep_document_nonce'], 'ep_new_document')) {
                return;
            }

            global $ep_app_manager;
            if ($ep_app_manager->get_user_permission('downloads') !== 'write') {
                wp_die('No tienes permisos para subir documentos.');
            }

            $file = $_FILES['ep_document_file'];
            $allowed_types_str = get_option('ep_downloads_allowed_types', 'jpg,png,pdf,doc,docx,xlsx');
            $allowed_types = array_map('trim', explode(',', $allowed_types_str));
            $max_size_mb = get_option('ep_downloads_max_size', 3);
            $max_size_bytes = $max_size_mb * 1024 * 1024;

            // Validation
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($file_ext, $allowed_types)) {
                wp_die('Tipo de archivo no permitido. Tipos permitidos: ' . $allowed_types_str);
            }

            if ($file['size'] > $max_size_bytes) {
                wp_die('El archivo excede el tamaño máximo permitido de ' . $max_size_mb . 'MB.');
            }

            // Upload
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            $attachment_id = media_handle_upload('ep_document_file', 0);

            if (is_wp_error($attachment_id)) {
                wp_die('Error al subir el archivo: ' . $attachment_id->get_error_message());
            }

            // Create Post
            $post_id = wp_insert_post(array(
                'post_title' => $file['name'],
                'post_status' => 'publish',
                'post_type' => self::POST_TYPE,
                'post_author' => get_current_user_id(),
            ));

            if ($post_id) {
                update_post_meta($post_id, '_ep_document_attachment_id', $attachment_id);
                update_post_meta($post_id, '_ep_document_type', self::TYPE_PRIVATE); // En fallback manual lo forzamos a privado para que se sincronice si falló el JS

                // En la nueva arquitectura Live API, preferimos subidas AJAX directas a OneDrive.
                // Si llegamos aquííí (fallback manual), el doc se queda solo local a menos que se implemente subida manual a OneDrive.
                // Por ahora, simplemente no llamamos al sync legado.

                // Send Notification
                $this->send_notification($post_id, $file['name']);

                wp_redirect(add_query_arg('document_uploaded', 'true', (string) ($_SERVER['REQUEST_URI'] ?? '')));
                exit;
            }
        }
    }

    public function handle_document_delete()
    {
        if (isset($_GET['delete_document']) && isset($_GET['_wpnonce'])) {
            $post_id = intval($_GET['delete_document']);
            if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_document_' . $post_id)) {
                return;
            }

            if (!$this->can_delete_document($post_id)) {
                wp_die('No tienes permisos para borrar este documento.');
            }

            // Delete attachment
            $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
            if ($attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }

            // Delete post
            wp_delete_post($post_id, true);

            wp_redirect(remove_query_arg(array('delete_document', '_wpnonce'), (string) ($_SERVER['REQUEST_URI'] ?? '')));
            exit;
        }
    }

    public function send_notification($post_id, $filename)
    {
        $type = get_post_meta($post_id, '_ep_document_type', true);
        $target_user_id = get_post_meta($post_id, '_ep_document_target_user', true);
        $uploader = wp_get_current_user();
        $subject = 'Nuevo documento disponible en el Portal del Empleado';

        if ($type === self::TYPE_PRIVATE && $target_user_id) {
            $user = get_userdata($target_user_id);
            if ($user) {
                $message = "Hola {$user->display_name},\n\nTienes un nuevo documento privado disponible: {$filename}.\n\nPuedes verlo en tu sección de Gestión Personal del Portal del Empleado.\n\nSaludos.";
                wp_mail($user->user_email, $subject, $message);
            }
        } else {
            $users = get_users();
            $message = "Hola,\n\nEl usuario {$uploader->display_name} ha subido un nuevo documento público: {$filename}.\n\nPuedes verlo en la sección de Recursos de la empresa del Portal del Empleado.\n\nSaludos.";
            foreach ($users as $user) {
                wp_mail($user->user_email, $subject, $message);
            }
        }
    }

    public static function get_documents($type = self::TYPE_PUBLIC, $user_id = 0)
    {
        // 1. Obtener TODOS los posibles documentos (pocos registros, filtrado PHP es más fiable aquííí)
        $all_docs = get_posts(array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true
        ));

        if (empty($all_docs))
            return array();

        $filtered = array();
        $user_id = (int) $user_id;

        foreach ($all_docs as $doc) {
            $doc_type = get_post_meta($doc->ID, '_ep_document_type', true);
            $doc_target = (int) get_post_meta($doc->ID, '_ep_document_target_user', true);

            if ($type === self::TYPE_PUBLIC) {
                // CONDICIÃ“N PÃšBLICA:
                // - NO debe tener destinatario ($doc_target <= 0)
                // - Y NO debe estar marcado como privado ($doc_type !== private)
                // Los documentos legacy (sin meta) pasarán correctamente aquííí.
                if ($doc_target <= 0 && $doc_type !== self::TYPE_PRIVATE) {
                    $filtered[] = $doc;
                }
            } else {
                // CONDICIÃ“N PRIVADA:
                // - El destinatario DEBE ser el usuario actual
                // - Y el usuario debe ser válido (> 0)
                if ($user_id > 0 && $doc_target === $user_id) {
                    $filtered[] = $doc;
                }
            }
        }

        return $filtered;
    }

    public static function add_system_document($user_id, $file_path, $filename, $source_tag = '', $sync_onedrive = true)
    {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'El archivo temporal no existe en el servidor: ' . $file_path);
        }

        $existing = get_posts(array(
            'post_type' => self::POST_TYPE,
            'meta_query' => array(
                array('key' => '_ep_document_type', 'value' => self::TYPE_PRIVATE),
                array('key' => '_ep_document_target_user', 'value' => $user_id),
                array('key' => '_ep_document_source_tag', 'value' => $source_tag)
            ),
            'posts_per_page' => 1
        ));

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $file_path
        );

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if (!empty($existing)) {
            $post_id = $existing[0]->ID;
            $old_attachment = get_post_meta($post_id, '_ep_document_attachment_id', true);
            if ($old_attachment)
                wp_delete_attachment($old_attachment, true);

            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $filename,
                'post_date' => current_time('mysql'),
            ));
        } else {
            $post_id = wp_insert_post(array(
                'post_title' => $filename,
                'post_status' => 'publish',
                'post_type' => self::POST_TYPE,
                'post_author' => get_current_user_id() ?: 1,
            ));
        }

        if ($post_id) {
            update_post_meta($post_id, '_ep_document_attachment_id', $attachment_id);
            update_post_meta($post_id, '_ep_document_type', self::TYPE_PRIVATE);
            update_post_meta($post_id, '_ep_document_target_user', $user_id);
            update_post_meta($post_id, '_ep_document_is_signed', '0');

            if ($source_tag) {
                update_post_meta($post_id, '_ep_document_source_tag', $source_tag);
            }

            // Subir sistemáticamente a OneDrive (Live API) si el módulo está activo y se ha solicitado sincronización
            if ($sync_onedrive && class_exists('EP_Auth_O365')) {
                try {
                    $auth = EP_Auth_O365::get_instance();
                    $structure = $auth->ensure_onedrive_structure($user_id);
                    if (!is_wp_error($structure)) {
                        $personal_folder_id = $structure['personal'] ?? '';
                        if ($personal_folder_id) {
                            // IMPORTANTE: Tras media_handle_sideload, el archivo original en $file_path ha sido movido/borrado.
                            // Obtenemos la ruta real del archivo guardado en WordPress.
                            $real_file_path = get_attached_file($attachment_id);
                            
                            if ($real_file_path && file_exists($real_file_path)) {
                                $auth->upload_to_onedrive_folder($user_id, $personal_folder_id, $real_file_path, $filename);
                                // Invalida cache
                                global $wpdb;
                                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ep_onedrive_folder_%'");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    ep_error_log("EP_Downloads: Error sincronizando con OneDrive (Continuando proceso local): " . $e->getMessage());
                } catch (\Error $er) {
                    ep_error_log("EP_Downloads: Error crítico sincronizando con OneDrive (Continuando proceso local): " . $er->getMessage());
                }
            }

            return $post_id;
        }

        return new WP_Error('post_creation_failed', 'No se pudo crear el registro del documento en la base de datos.');
    }

    public static function get_attachment_url($post_id)
    {
        $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
        return wp_get_attachment_url($attachment_id);
    }

    public static function mark_as_signed($post_id)
    {
        return update_post_meta($post_id, '_ep_document_is_signed', '1');
    }

    public static function can_delete_document($post_id)
    {
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('downloads') === 'write') {
            return true;
        }

        $doc_type = get_post_meta($post_id, '_ep_document_type', true);
        if ($doc_type === self::TYPE_PRIVATE) {
            $user_id = get_current_user_id();
            $target_user = (int) get_post_meta($post_id, '_ep_document_target_user', true);
            $author = (int) get_post_field('post_author', $post_id);
            
            if ($user_id > 0 && ($target_user === $user_id || $author === $user_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legacy Sync methods removed in favor of Live API View
     */

    public function get_document_preview_url($post_id)
    {
        $file_path = get_attached_file($post_id);
        $is_synced = get_post_meta($post_id, '_ep_onedrive_synced', true);
        $onedrive_id = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        $uploader = get_post_field('post_author', $post_id);

        $file_url = '';
        $ext = '';

        if ($file_path && file_exists($file_path)) {
            $file_type = wp_check_filetype($file_path);
            $ext = strtolower($file_type['ext'] ?: '');
            $file_url = wp_get_attachment_url($post_id);
        } elseif ($is_synced && $onedrive_id && class_exists('EP_Auth_O365')) {
            $ext = strtolower(get_post_meta($post_id, '_ep_document_file_ext', true) ?: '');
            $target_user_id = get_post_meta($post_id, '_ep_document_target_user', true) ?: $uploader;
            $auth = EP_Auth_O365::get_instance();

            // Para Office/CSV, necesitamos una URL directa para los visores externos
            if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'csv'])) {
                $file_url = $auth->get_item_download_url((int) $target_user_id, $onedrive_id);
                if (is_wp_error($file_url)) {
                    $file_url = '';
                }
            }

            // Si no obtuvimos URL directa o es otro formato, probamos la vista previa nativa
            if (empty($file_url)) {
                $preview_url = $auth->get_item_preview_url((int) $target_user_id, $onedrive_id);
                if (!is_wp_error($preview_url)) {
                    return $preview_url;
                }
            }

            // Fallback final a la URL de descarga segura
            if (empty($file_url)) {
                $file_url = admin_url('admin-ajax.php?action=ep_secure_download&id=' . $post_id . '&security=' . wp_create_nonce('ep_download_' . $post_id));
            }
        }

        if (empty($file_url)) {
            return '';
        }

        // Office Online Viewer handles most Office formats + ODT/ODS/ODP
        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'])) {
            return 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($file_url);
        }

        // Google Docs Viewer as fallback for CSV or others if needed
        if ($ext === 'csv') {
            return 'https://docs.google.com/gview?url=' . urlencode($file_url) . '&embedded=true';
        }

        // Default to file URL (PDF/Images)
        return $file_url;
    }

    public function ajax_get_onedrive_download_url()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $item_id = sanitize_text_field($_POST['item_id'] ?? '');
        $user_id = get_current_user_id();
        $type = sanitize_text_field($_POST['type'] ?? 'private');

        if (empty($item_id)) {
            wp_send_json_error('ID de item no válido.');
        }

        $auth = EP_Auth_O365::get_instance();
        $sync_owner = ($type === 'public' || $type === 'global') ? EP_Auth_O365::get_sync_principal_id() : $user_id;

        ep_error_log("EP_Downloads: Generando descarga. Item=$item_id, Owner=$sync_owner, Type=$type");

        $url = $auth->get_item_download_url($sync_owner, $item_id);

        if (is_wp_error($url)) {
            ep_error_log("EP_Downloads: Error en descarga: " . $url->get_error_message());
            wp_send_json_error($url->get_error_message());
        }

        wp_send_json_success(array('url' => $url));
    }

    public function ajax_get_document_preview_url()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $id = isset($_POST['document_id']) ? sanitize_text_field($_POST['document_id']) : '';

        if (empty($id)) {
            wp_send_json_error('ID de documento no válido.');
        }

        ep_error_log("EP_Downloads: Solicitada previsualización para ID=$id");

        $post_id = 0;

        // Si es un ID numérico, asumimos que es el post_id de WP
        if (is_numeric($id)) {
            $post_id = intval($id);
        } else {
            // Si es un string (OneDrive ID), buscamos el post vinculado
            global $wpdb;
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ep_onedrive_item_id' AND meta_value = %s",
                $id
            ));
        }

        if (!$post_id) {
            // Si no hay post de WP, intentamos obtener la URL de OneDrive directamente
            $auth = EP_Auth_O365::get_instance();
            
            // Primero intentamos con el usuario actual (por si es privado)
            $url = $auth->get_item_preview_url(get_current_user_id(), $id);

            // Si falla con el usuario actual, intentamos con el principal de sincronización (para recursos públicos)
            if (is_wp_error($url)) {
                $url = $auth->get_item_preview_url(EP_Auth_O365::get_sync_principal_id(), $id);
            }

            if (!is_wp_error($url) && !empty($url)) {
                ep_error_log("EP_Downloads: Vista previa generada directamente de OneDrive: $url");
                wp_send_json_success(array('url' => $url));
            }

            ep_error_log("EP_Downloads: Error generando vista previa sin post_id.");
            wp_send_json_error('No se pudo encontrar el documento o generar la vista previa.');
        }

        $onedrive_id = get_post_meta($post_id, '_ep_onedrive_item_id', true);
        if ($onedrive_id) {
            $auth = EP_Auth_O365::get_instance();
            $doc_type = get_post_meta($post_id, '_ep_document_type', true);
            
            // Si es público, usamos el Sync Principal (Admin) que es el dueño real en OneDrive
            if ($doc_type === self::TYPE_PUBLIC) {
                $target_user_id = EP_Auth_O365::get_sync_principal_id();
            } else {
                $target_user_id = get_post_meta($post_id, '_ep_document_target_user', true) ?: get_current_user_id();
            }

            ep_error_log("EP_Downloads: Generando vista previa OneDrive. Item=$onedrive_id, TargetUser=$target_user_id, Type=$doc_type");
            $url = $auth->get_item_preview_url((int) $target_user_id, $onedrive_id);
            
            if (!is_wp_error($url) && !empty($url)) {
                ep_error_log("EP_Downloads: Vista previa OneDrive generada: $url");
                wp_send_json_success(array('url' => $url));
            } else {
                $err = is_wp_error($url) ? $url->get_error_message() : 'URL vacía';
                ep_error_log("EP_Downloads: Error vista previa OneDrive: $err");
            }
        }

        // Fallback: Generate local preview URL
        $url = admin_url('admin-ajax.php?action=ep_secure_download&id=' . $post_id . '&security=' . wp_create_nonce('ep_download_' . $post_id) . '&preview=1');
        ep_error_log("EP_Downloads: Fallback a vista previa local: $url");
        wp_send_json_success(array('url' => $url));
    }

    public function ajax_backup_all_user_pending()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        if (!class_exists('EP_Auth_O365')) {
            wp_send_json_error('Integración M365 no activa.');
        }

        $target_user = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
        if ($target_user <= 0) {
            $target_user = get_current_user_id();
        }

        // Only admins/HR can backup for others
        if ($target_user !== get_current_user_id() && !current_user_can('manage_options') && empty(array_intersect(['administrator', 'hr'], wp_get_current_user()->roles))) {
            wp_send_json_error('No tienes permisos suficientes.');
        }

        $args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_document_type',
                    'value' => self::TYPE_PRIVATE,
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_onedrive_item_id',
                    'compare' => 'NOT EXISTS'
                )
            )
        );

        $posts = get_posts($args);

        // Ensure accurate filtering by filtering out ones that don't match author if target user meta doesn't exist
        $filtered_posts = array();
        foreach ($posts as $post) {
            $doc_target = (int) get_post_meta($post->ID, '_ep_document_target_user', true);
            if ($doc_target <= 0) {
                $doc_target = (int) get_post_field('post_author', $post->ID);
            }
            if ($doc_target === $target_user) {
                $filtered_posts[] = $post;
            }
        }

        if (empty($filtered_posts)) {
            wp_send_json_success(array('count' => 0, 'message' => 'No hay archivos nuevos pendientes de respaldo en esta carpeta personal (ni en subcarpetas).'));
        }

        $auth = EP_Auth_O365::get_instance();
        $success_count = 0;

        foreach ($filtered_posts as $post) {
            $post_id = $post->ID;
            $terms = wp_get_post_terms($post_id, 'ep_document_category');
            $folder_type = 'documentos';
            if (!is_wp_error($terms) && !empty($terms)) {
                $folder_type = $terms[0]->name;
            }

            $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
            $file_path = $attachment_id ? get_attached_file($attachment_id) : false;

            if (!$file_path || !file_exists($file_path))
                continue;

            $filename = basename($file_path);

            $is_encrypted = (mb_substr(@file_get_contents($file_path, false, null, 0, 4), 0, 4, '8bit') === 'ENC:');
            $upload_path = $file_path;
            $temp_path = '';

            if ($is_encrypted) {
                $temp_path = $this->decrypt_to_temp($file_path, $filename);
                if (!$temp_path || !file_exists($temp_path))
                    continue;
                $upload_path = $temp_path;
            }

            $result = $auth->upload_to_onedrive($target_user, $upload_path, $filename, $folder_type, 'private');

            if ($temp_path) {
                @unlink($temp_path);
            }

            if (!is_wp_error($result) && !empty($result['id'])) {
                $item_id = $result['id'];
                update_post_meta($post_id, '_ep_onedrive_item_id', sanitize_text_field($item_id));
                $success_count++;
            }
        }

        wp_send_json_success(array('count' => $success_count, 'total' => count($filtered_posts), 'message' => "Respaldo completado. Se respaldaron $success_count de " . count($filtered_posts) . " archivos (incluyendo todas las subcarpetas)."));
    }

    public function add_six_hours_schedule($schedules)
    {
        if (!isset($schedules['six_hours'])) {
            $schedules['six_hours'] = array(
                'interval' => 21600, // 6 hours
                'display' => __('Cada 6 Horas', 'employee-portal')
            );
        }
        return $schedules;
    }

    /**
     * AJAX: Forzar sincronización manual con OneDrive.
     * Wrapper de run_auto_sync() para uso desde el frontend.
     */
    public function ajax_force_sync_onedrive()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error('No autorizado.');
        }

        try {
            $result = $this->run_auto_sync();
            wp_send_json_success(array(
                'message' => 'Sincronización completada correctamente.',
                'stats' => array(
                    'timestamp' => current_time('mysql'),
                    'user_id' => get_current_user_id(),
                    'synced' => isset($result['synced']) ? $result['synced'] : 0,
                    'skipped' => isset($result['skipped']) ? $result['skipped'] : 0,
                    'errors' => isset($result['errors']) ? $result['errors'] : [],
                    'auth_available' => class_exists('EP_Auth_O365'),
                )
            ));
        } catch (\Throwable $e) {
            error_log('EP_Downloads sync error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array(
                'message' => 'Error durante la sincronización: ' . $e->getMessage()
            ));
        }
    }

    public function run_auto_sync()
    {
        $stats = array('synced' => 0, 'skipped' => 0, 'errors' => array());

        if (!class_exists('EP_Auth_O365')) {
            $stats['errors'][] = 'EP_Auth_O365 no disponible.';
            return $stats;
        }

        // --- FASE 1: PUSH (Locales a OneDrive) ---
        $args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_ep_onedrive_item_id',
                    'compare' => 'NOT EXISTS'
                )
            )
        );

        $posts = get_posts($args);
        $auth = EP_Auth_O365::get_instance();

        if (!empty($posts)) {
            foreach ($posts as $post) {
                try {
                    $post_id = $post->ID;
                    $doc_type = get_post_meta($post_id, '_ep_document_type', true) ?: self::TYPE_PUBLIC;
                    $doc_target = (int) get_post_meta($post_id, '_ep_document_target_user', true);

                    if ($doc_type === self::TYPE_PUBLIC) {
                        $doc_target = EP_Auth_O365::get_sync_principal_id();
                    } else {
                        if ($doc_target <= 0) {
                            $doc_target = (int) get_post_field('post_author', $post_id);
                        }
                    }

                    if ($doc_target <= 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    $terms = wp_get_post_terms($post_id, 'ep_document_category');
                    $folder_type = 'documentos';
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $folder_type = $terms[0]->name;
                    }

                    $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
                    $file_path = $attachment_id ? get_attached_file($attachment_id) : false;

                    if (!$file_path || !file_exists($file_path)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $filename = basename($file_path);

                    $is_encrypted = (mb_substr(@file_get_contents($file_path, false, null, 0, 4), 0, 4, '8bit') === 'ENC:');
                    $upload_path = $file_path;
                    $temp_path = '';

                    if ($is_encrypted) {
                        $temp_path = $this->decrypt_to_temp($file_path, $filename);
                        if (!$temp_path || !file_exists($temp_path)) {
                            $stats['skipped']++;
                            continue;
                        }
                        $upload_path = $temp_path;
                    }

                    $result = $auth->upload_to_onedrive($doc_target, $upload_path, $filename, $folder_type, $doc_type);

                    if ($temp_path) {
                        @unlink($temp_path);
                    }

                    if (!is_wp_error($result) && !empty($result['id'])) {
                        $item_id = $result['id'];
                        update_post_meta($post_id, '_ep_onedrive_item_id', sanitize_text_field($item_id));
                        $stats['synced']++;
                    } else {
                        $err_msg = is_wp_error($result) ? $result->get_error_message() : 'Sin ID de respuesta';
                        $stats['errors'][] = "Post #{$post_id} ({$filename}): {$err_msg}";
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Post #{$post_id}: " . $e->getMessage();
                    error_log('EP_Downloads sync item error: ' . $e->getMessage());
                }
            }
        }

        // --- FASE 2: PULL (De OneDrive a Web) ---
        $admin_id = EP_Auth_O365::get_sync_principal_id();
        if ($admin_id) {
            // Asegurar que la estructura esté compartida con la organización
            $folder_ids = get_user_meta($admin_id, 'ep_onedrive_folder_ids', true);
            if (!empty($folder_ids['publico'])) {
                $auth = EP_Auth_O365::get_instance();
                // SEGURIDAD: Invitamos al grupo solo a la carpeta PUBLICO, no a la raíz.
                $auth->invite_collaboration($admin_id, $folder_ids['publico'], ['personalcamara@camara', 'personalcamara@camaracaceres.es'], 'write');
            }
            $this->pull_from_onedrive($admin_id, self::TYPE_PUBLIC, 0, $stats);
        }

        $current_user_id = get_current_user_id();
        if ($current_user_id > 0) {
            $this->pull_from_onedrive($current_user_id, self::TYPE_PRIVATE, 0, $stats);
        }

        return $stats;
    }

    /**
     * AJAX: Link Shared Portal folder to current user's OneDrive as a shortcut.
     */
    public function ajax_link_to_my_onedrive()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error('No autorizado.');
        }

        $current_user_id = get_current_user_id();
        $admin_id = EP_Auth_O365::get_sync_principal_id();

        if ($current_user_id === $admin_id) {
            // Un admin no se vincula a sí mismo (ya es el dueño)
            wp_send_json_success(array('message' => 'Eres el propietario de la carpeta, no necesitas vincularla.'));
        }

        $folder_ids = get_user_meta($admin_id, 'ep_onedrive_folder_ids', true);
        if (empty($folder_ids['publico']) || empty($folder_ids['personal'])) {
            wp_send_json_error('Estructura de carpetas maestra incompleta.');
        }

        $auth = EP_Auth_O365::get_instance();
        $graph = EP_Graph_Service::get_instance();

        // 1. Obtener el driveId de la cuenta maestra de forma eficiente
        $remote_drive_id = get_user_meta($admin_id, 'ep_onedrive_drive_id', true);
        if (!$remote_drive_id) {
            $drive_res = $graph->get_my_drive($admin_id);
            if (is_wp_error($drive_res)) wp_send_json_error("Error de unidad maestra: " . $drive_res->get_error_message());
            if (empty($drive_res['id'])) wp_send_json_error("No se pudo identificar la unidad de OneDrive maestra.");
            
            $remote_drive_id = $drive_res['id'];
            update_user_meta($admin_id, 'ep_onedrive_drive_id', $remote_drive_id);
        }

        // 2. Borrar carpeta anterior "AA - Portal del Empleado" para forzar reestructuracion
        $old_folder = $graph->get_child_by_name($current_user_id, 'root', 'AA - Portal del Empleado');
        if (!is_wp_error($old_folder) && !empty($old_folder['id'])) {
            $graph->delete_onedrive_item($current_user_id, $old_folder['id']);
        }

        // 3. Gestionar Carpeta Personal del Usuario (PRIVACIDAD TOTAL)
        // Cada usuario tiene su propia carpeta 'AA Portal Privado' en SU unidad.
        // NOTA: Necesitamos el token del usuario actual para operar en SU OneDrive.
        $current_user_token = EP_Auth_O365::get_valid_token($current_user_id);
        if (is_wp_error($current_user_token)) {
            wp_send_json_error('No se pudo obtener tu token de OneDrive: ' . $current_user_token->get_error_message());
        }
        $private_folder_id = $graph->ensure_folder($current_user_token, 'me/drive/root', 'AA Portal Privado');
        if (!is_wp_error($private_folder_id)) {
             $user_folder_ids = get_user_meta($current_user_id, 'ep_onedrive_folder_ids', true) ?: [];
             $user_folder_ids['personal'] = $private_folder_id;
             update_user_meta($current_user_id, 'ep_onedrive_folder_ids', $user_folder_ids);
        }

        // 4. Crear/Actualizar Acceso Directo a la parte PÚBLICA directamente en la raíz ('root')
        // A. Recursos Compartidos (Público) - Atajo a la unidad del Admin
        $shortcut_public = $auth->add_onedrive_shortcut(
            $current_user_id, 
            $folder_ids['publico'], 
            $remote_drive_id, 
            'AA Portal Publico',
            'root'
        );

        // B. Carpeta Privada - Ya es una carpeta real creada arriba
        $shortcut_private = $private_folder_id;


        // Limpieza de nombres en los shortcuts (para quitar el sufijo " - Nombre" que Microsoft añade)
        if (!is_wp_error($shortcut_public) && is_string($shortcut_public)) {
            $result_rename = $auth->rename_drive_item($current_user_id, $shortcut_public, 'AA Portal Publico');
            // En caso de que el shortcut ya existiera con el nombre sucio
            if (is_wp_error($result_rename) && $result_rename->get_error_code() === 'graph_error') {
                // Posiblemente ya renombrado o conflicto, ignoramos
            }
        }
        if (!is_wp_error($shortcut_private) && is_string($shortcut_private)) {
            $auth->rename_drive_item($current_user_id, $shortcut_private, 'AA Portal Privado');
        }

        wp_send_json_success(array('message' => '¡Portal vinculado correctamente! Busca las carpetas "AA Portal Publico" y "AA Portal Privado" en la raíz de tu OneDrive personal. Ahora tienes tus archivos personales y los de la empresa en un solo lugar seguro y organizado.'));
    }

    private function pull_from_onedrive($user_id, $root_type, $local_parent_id, &$stats)
    {
        $auth = EP_Auth_O365::get_instance();

        $folder_ids = get_user_meta($user_id, 'ep_onedrive_folder_ids', true);
        if (!$folder_ids || empty($folder_ids['publico'])) {
            $folder_ids = $auth->ensure_onedrive_structure($user_id);
            if (is_wp_error($folder_ids))
                return;
        }

        $root_key = ($root_type === self::TYPE_PRIVATE) ? 'personal' : 'publico';
        $onedrive_folder_id = $folder_ids[$root_key] ?? '';

        if (!$onedrive_folder_id)
            return;

        $doc_type = ($root_type === self::TYPE_PRIVATE) ? self::TYPE_PRIVATE : self::TYPE_PUBLIC;
        $all_remote_item_ids = [];
        $all_remote_folder_names = [];

        $this->sync_onedrive_folder_recursive($user_id, $onedrive_folder_id, $local_parent_id, $doc_type, $stats, $all_remote_item_ids, $all_remote_folder_names);
        $this->cleanup_global_deleted_items($local_parent_id, $all_remote_item_ids, $all_remote_folder_names, $user_id, $doc_type, $stats);
    }

    private function sync_onedrive_folder_recursive($user_id, $onedrive_folder_id, $local_parent_id, $doc_type, &$stats, &$all_remote_item_ids, &$all_remote_folder_names)
    {
        $auth = EP_Auth_O365::get_instance();
        $contents = $auth->get_live_onedrive_contents($user_id, $onedrive_folder_id);

        if (is_wp_error($contents)) {
            $stats['errors'][] = 'Error syncing folder ' . $onedrive_folder_id . ': ' . $contents->get_error_message();
            return;
        }

        foreach ($contents as $item) {
            $all_remote_item_ids[] = $item['id'];

            if ($item['type'] === 'folder') {
                $all_remote_folder_names[] = strtolower($item['name']);
                $term = $this->get_or_create_local_category($item['name'], $local_parent_id, $user_id, $doc_type, $item['id']);
                if ($term && !is_wp_error($term)) {
                    $this->sync_onedrive_folder_recursive($user_id, $item['id'], $term['term_id'], $doc_type, $stats, $all_remote_item_ids, $all_remote_folder_names);
                }
            } else {
                $this->get_or_create_local_document($item, $local_parent_id, $user_id, $doc_type, $stats);
            }
        }
    }

    private function get_or_create_local_category($name, $parent_id, $user_id, $doc_type, $onedrive_id = '')
    {
        $owner_id = ($doc_type === self::TYPE_PUBLIC) ? 0 : $user_id;

        $term_args = array(
            'taxonomy' => 'ep_document_category',
            'hide_empty' => false,
            'parent' => $parent_id,
            'name' => $name,
            'meta_query' => array(
                array(
                    'key' => '_ep_category_owner',
                    'value' => $owner_id,
                    'compare' => '='
                )
            )
        );
        $terms = get_terms($term_args);

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $t) {
                if (strtolower($t->name) === strtolower($name)) {
                    if ($onedrive_id) {
                        update_term_meta($t->term_id, '_ep_onedrive_item_id', $onedrive_id);
                    }
                    return array('term_id' => $t->term_id);
                }
            }
        }

        $args = array();
        if ($parent_id > 0)
            $args['parent'] = $parent_id;
        $term = wp_insert_term($name, 'ep_document_category', $args);
        if (!is_wp_error($term)) {
            $term_id = $term['term_id'];
            update_term_meta($term_id, '_ep_category_owner', $owner_id);
            update_term_meta($term_id, '_ep_display_name', $name);
            if ($onedrive_id) {
                update_term_meta($term_id, '_ep_onedrive_item_id', $onedrive_id);
            }
        }
        return $term;
    }

    private function get_or_create_local_document($item, $parent_id, $user_id, $doc_type, &$stats)
    {
        $onedrive_id = $item['id'];

        $args = array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_ep_onedrive_item_id',
                    'value' => $onedrive_id,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        );
        $posts = get_posts($args);

        if (!empty($posts)) {
            $post = $posts[0];
            $current_terms = wp_get_post_terms($post->ID, 'ep_document_category', array('fields' => 'ids'));
            $current_parent = empty($current_terms) ? 0 : $current_terms[0];

            if ($current_parent != $parent_id) {
                $term_ids = ($parent_id > 0) ? array($parent_id) : array();
                wp_set_object_terms($post->ID, $term_ids, 'ep_document_category', false);
            }

            if (isset($item['size']) && $item['size'] > 0) {
                update_post_meta($post->ID, '_ep_document_size', $item['size']);
            }
            if ($post->post_title !== $item['name']) {
                wp_update_post(array('ID' => $post->ID, 'post_title' => $item['name']));
                $stats['synced']++;
            } else {
                $stats['skipped']++;
            }

            return $post->ID;
        }

        $post_id = wp_insert_post(array(
            'post_title' => $item['name'],
            'post_status' => 'publish',
            'post_type' => self::POST_TYPE,
            'post_author' => ($doc_type === self::TYPE_PUBLIC) ? 1 : $user_id
        ));

        if ($post_id) {
            update_post_meta($post_id, '_ep_document_type', $doc_type);
            update_post_meta($post_id, '_ep_document_target_user', ($doc_type === self::TYPE_PUBLIC) ? 0 : $user_id);
            update_post_meta($post_id, '_ep_onedrive_item_id', $onedrive_id);
            update_post_meta($post_id, '_ep_onedrive_synced', '1');
            update_post_meta($post_id, '_ep_onedrive_last_sync', current_time('mysql'));
            if (isset($item['size'])) {
                update_post_meta($post_id, '_ep_document_size', $item['size']);
            }

            if ($parent_id > 0) {
                wp_set_object_terms($post_id, array($parent_id), 'ep_document_category', false);
            }
            $stats['synced']++;
            return $post_id;
        }

        return false;
    }

    private function cleanup_global_deleted_items($root_local_parent_id, $all_remote_item_ids, $all_remote_folder_names, $user_id, $doc_type, &$stats)
    {
        $owner_id = ($doc_type === self::TYPE_PUBLIC) ? 0 : $user_id;

        $post_args = array(
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_document_type',
                    'value' => $doc_type,
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_onedrive_item_id',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_ep_document_target_user',
                    'value' => $owner_id,
                    'compare' => '='
                )
            )
        );

        // Ya no iteramos solo los del parent actual, sino TODOS los de este owner_id y tipo.
        $local_posts = get_posts($post_args);
        foreach ($local_posts as $post) {
            $local_od_id = get_post_meta($post->ID, '_ep_onedrive_item_id', true);
            if ($local_od_id && !in_array($local_od_id, $all_remote_item_ids)) {
                $attachment_id = get_post_meta($post->ID, '_ep_document_attachment_id', true);
                if ($attachment_id) {
                    wp_delete_attachment($attachment_id, true);
                }
                wp_delete_post($post->ID, true);
                $stats['skipped']++;
            }
        }

        $term_args = array(
            'taxonomy' => 'ep_document_category',
            'hide_empty' => false,
            // Eliminamos la restricción de parent para buscar en todo el árbol de este usuario
            'meta_query' => array(
                array(
                    'key' => '_ep_category_owner',
                    'value' => $owner_id,
                    'compare' => '='
                )
            )
        );

        $local_terms = get_terms($term_args);
        if (!is_wp_error($local_terms)) {
            foreach ($local_terms as $term) {
                $local_od_id = get_term_meta($term->term_id, '_ep_onedrive_item_id', true);

                $should_delete = false;
                if ($local_od_id) {
                    // Si tiene ID, comprobamos si sigue existiendo en remoto
                    if (!in_array($local_od_id, $all_remote_item_ids)) {
                        $should_delete = true;
                    }
                } else {
                    // Si no tiene ID (carpetas antiguas), seguimos usando el nombre temporalmente
                    if (!in_array(strtolower($term->name), $all_remote_folder_names)) {
                        $should_delete = true;
                    }
                }

                if ($should_delete) {
                    wp_delete_term($term->term_id, 'ep_document_category');
                }
            }
        }
    }

    /**
     * AJAX: Envía un documento a un usuario específico
     */
    public function ajax_send_document_to_user()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $post_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;

        if ($post_id <= 0 || get_post_type($post_id) !== self::POST_TYPE) {
            wp_send_json_error('Documento no válido.');
        }

        if ($target_user_id <= 0) {
            wp_send_json_error('Debes seleccionar un destinatario válido.');
        }

        // 1. Actualizar el destinatario en los metadatos
        update_post_meta($post_id, '_ep_document_target_user', $target_user_id);
        update_post_meta($post_id, '_ep_document_type', self::TYPE_PRIVATE); // Asegurar que sea privado

        // Reset de estado de revisión al enviar de nuevo
        update_post_meta($post_id, '_ep_document_review_status', 'pending');
        delete_post_meta($post_id, '_ep_document_feedback');

        // 2. Notificar al destinatario
        if (class_exists('EP_Notifications')) {
            $sender_name = wp_get_current_user()->display_name;
            $doc_title = get_the_title($post_id);
            EP_Notifications::add_notification($target_user_id, [
                'type' => 'info',
                'title' => 'Documento recibido',
                'message' => "$sender_name te ha enviado un documento: $doc_title",
                'link' => '?view=downloads'
            ]);
        }

        wp_send_json_success('El documento ha sido enviado correctamente al usuario.');
    }

    /**
     * AJAX: Compartir un documento con otro usuario (sin moverlo)
     * El documento permanece en el dueño original. El destinatario
     * puede verlo y dar feedback. Se registra en _ep_shared_with[].
     */
    public function ajax_share_document()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $post_id        = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
        $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
        $current_user   = get_current_user_id();

        if ($post_id <= 0 || get_post_type($post_id) !== self::POST_TYPE) {
            wp_send_json_error('Documento no válido.');
        }

        if ($target_user_id <= 0) {
            wp_send_json_error('Debes seleccionar un destinatario válido.');
        }

        if ($target_user_id === $current_user) {
            wp_send_json_error('No puedes compartir un documento contigo mismo.');
        }

        // Verificar que el usuario actual es el propietario o tiene permiso de escritura
        global $ep_app_manager;
        $can_write = ($ep_app_manager->get_user_permission('downloads') === 'write');
        $owner_id  = (int) get_post_field('post_author', $post_id);

        if (!$can_write && $owner_id !== $current_user) {
            wp_send_json_error('Solo el propietario del documento puede compartirlo.');
        }

        // Añadir al array de compartidos (sin duplicar)
        $shared_with = get_post_meta($post_id, '_ep_shared_with', true);
        if (!is_array($shared_with)) {
            $shared_with = [];
        }

        if (!in_array($target_user_id, $shared_with)) {
            $shared_with[] = $target_user_id;
            update_post_meta($post_id, '_ep_shared_with', $shared_with);
        }

        // Notificar al destinatario
        $target_user = get_userdata($target_user_id);
        $target_name = $target_user ? $target_user->display_name : 'Usuario';
        $sender_name = wp_get_current_user()->display_name;
        $doc_title   = get_the_title($post_id);

        if (class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($target_user_id, [
                'type'    => 'info',
                'title'   => 'Documento compartido',
                'message' => "$sender_name ha compartido contigo el documento: \"$doc_title\"",
                'link'    => '?view=downloads'
            ]);
        }

        wp_send_json_success("El documento \"$doc_title\" ha sido compartido con $target_name.");
    }
}
