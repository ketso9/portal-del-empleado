<?php

/**
 * EP_App_Contratos - Implementa EP_App_Interface para el gestor de apps del portal.
 */
class EP_App_Contratos implements EP_App_Interface
{
    public function get_id()
    {
        return 'contratos';
    }

    public function get_name()
    {
        return 'Registro de Contratos Menores';
    }

    public function get_icon()
    {
        return 'fa-solid fa-file-contract';
    }

    public function get_menu_label()
    {
        return 'Contratos';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=contratos'">
            <div class="app-icon-container" style="background: linear-gradient(135deg, #9e1c2e 0%, #c0392b 100%);">
                <i class="fa-solid fa-file-contract"></i>
            </div>
            <h3>Contratos</h3>
            <p>Registro de contratos menores</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_CONTRATOS_PATH . 'partials/contratos-app.php';
    }

    public function handle_ajax()
    {
        // Handled by EP_Contratos class directly via wp_ajax_ hooks
    }
}

/**
 * EP_Contratos - Lógica principal del módulo.
 */
class EP_Contratos
{
    public function __construct()
    {
        add_action('wp_ajax_ep_contratos_list',   [$this, 'ajax_list']);
        add_action('wp_ajax_ep_contratos_create', [$this, 'ajax_create']);
        add_action('wp_ajax_ep_contratos_edit',   [$this, 'ajax_edit']);
        add_action('wp_ajax_ep_contratos_upload', [$this, 'ajax_upload_signed']);
        add_action('wp_ajax_ep_contratos_delete', [$this, 'ajax_delete']);

        // Background Notifications
        add_action('ep_contratos_notify_bg', [$this, 'process_bg_notifications'], 10, 3);

        // Encolar CSS siempre (el portal es una SPA — wp_enqueue_scripts no discrimina por vista)
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets()
    {
        // El handle del CSS principal del portal es 'employee-portal' (definido en EP_Public::enqueue_styles)
        wp_enqueue_style(
            'ep-contratos-css',
            EP_CONTRATOS_URL . 'css/ep-contratos.css',
            ['employee-portal'],
            '1.0.6'
        );
    }

    /**
     * Instala la tabla de contratos en la base de datos.
     * Seguro para llamar múltiples veces (dbDelta gestiona la diferencia).
     */
    public static function install_table()
    {
        global $wpdb;
        $table     = $wpdb->prefix . 'ep_contratos';
        $collate   = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero        VARCHAR(10)  NOT NULL COMMENT 'Ej: 001/25',
            anio          SMALLINT     NOT NULL COMMENT 'Año de generación',
            fecha         DATE         NOT NULL,
            siglas        VARCHAR(20)  NOT NULL DEFAULT '',
            objeto        TEXT         NOT NULL,
            codigo_curso  VARCHAR(512) NOT NULL DEFAULT '',
            duracion      VARCHAR(255) NOT NULL DEFAULT '',
            importe       VARCHAR(150) NOT NULL DEFAULT '',
            identidad     TEXT         NOT NULL DEFAULT '',
            locked        TINYINT(1)  NOT NULL DEFAULT 0,
            contrato_url  VARCHAR(512) NOT NULL DEFAULT '',
            autor_id      BIGINT(20)  UNSIGNED NOT NULL,
            created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY anio (anio),
            KEY locked (locked)
        ) {$collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Genera el siguiente número correlativo del año actual.
     * El contador se reinicia cada 1 de enero.
     */
    public static function get_next_number(): string
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'ep_contratos';
        $year   = (int) date('Y');
        $suffix = substr((string) $year, -2); // "25" para 2025

        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM {$table} WHERE anio = %d",
            $year
        ));

        // Buscar el último número correlativo del año
        $last_num = 0;
        if ($max > 0) {
            $last_numero = $wpdb->get_var($wpdb->prepare(
                "SELECT numero FROM {$table} WHERE anio = %d ORDER BY id DESC LIMIT 1",
                $year
            ));
            if ($last_numero) {
                $parts    = explode('/', $last_numero);
                $last_num = (int) $parts[0];
            }
        }

        $next = $last_num + 1;
        return sprintf('%03d', $next) . '/' . $suffix;
    }

    /**
     * Comprueba si el usuario actual tiene permiso de escritura
     * (puede subir contratos firmados).
     */
    public static function current_user_can_write(): bool
    {
        if (!is_user_logged_in()) return false;
        if (current_user_can('administrator')) return true;

        global $ep_app_manager;
        if (!isset($ep_app_manager)) return false;

        return $ep_app_manager->get_user_permission('contratos') === 'write';
    }

    /**
     * Comprueba si el usuario actual puede leer (ver/crear contratos).
     * Por defecto, todos los autenticados pueden.
     * Un admin puede bloquearlo asignando explícitamente permiso 'none'.
     */
    public static function current_user_can_read(): bool
    {
        if (!is_user_logged_in()) return false;
        if (current_user_can('administrator')) return true;

        global $ep_app_manager;
        if (!isset($ep_app_manager)) return true;

        $perm = $ep_app_manager->get_user_permission('contratos');
        // Si se deniega explícitamente (none/blocked), bloquear
        if ($perm === 'none' || $perm === 'blocked') return false;

        return true;
    }

    // =========================================================
    // AJAX Handlers
    // =========================================================

    /**
     * Lista contratos paginados.
     */
    public function ajax_list()
    {
        check_ajax_referer('ep_contratos_nonce', 'nonce');

        if (!self::current_user_can_read()) {
            wp_send_json_error(['message' => 'Sin permisos.'], 403);
        }

        global $wpdb;
        $table    = $wpdb->prefix . 'ep_contratos';
        $page     = max(1, (int) ($_POST['page'] ?? 1));
        $per_page = 10;
        $offset   = ($page - 1) * $per_page;

        // Obtener todos los años disponibles
        $years_db = $wpdb->get_col("SELECT DISTINCT anio FROM {$table} ORDER BY anio DESC");
        // Asegurar que al menos esté el año actual
        $current_year = date('Y');
        $available_years = empty($years_db) ? [$current_year] : $years_db;
        if (!in_array($current_year, $available_years)) {
            array_unshift($available_years, $current_year);
            rsort($available_years);
        }

        // Filtro por año
        $filter_year = isset($_POST['anio']) && $_POST['anio'] !== 'todos' ? (int) $_POST['anio'] : (int) $current_year;
        
        if (isset($_POST['anio']) && $_POST['anio'] === 'todos') {
            $where = "1=1";
            $query_args = [];
        } else {
            $where = "anio = %d";
            $query_args = [$filter_year];
        }

        if (!empty($query_args)) {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$query_args));
            
            $query_args[] = $per_page;
            $query_args[] = $offset;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                ...$query_args
            ), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ), ARRAY_A);
        }

        $current_uid = get_current_user_id();
        $can_write   = self::current_user_can_write();
        $is_admin    = current_user_can('administrator');

        // Añadir datos procesados para el frontend
        foreach ($rows as &$row) {
            $row['can_edit']   = (int) $row['locked'] === 0 && ($row['autor_id'] == $current_uid || $is_admin);
            $row['can_upload'] = (int) $row['locked'] === 0 && $can_write;
            $row['can_delete'] = $is_admin && (int) $row['locked'] === 0;
            $row['fecha_fmt']  = date('d/m/Y', strtotime($row['fecha']));
        }
        unset($row);

        wp_send_json_success([
            'contratos'      => $rows,
            'total'          => $total,
            'pages'          => (int) ceil($total / $per_page),
            'current'        => $page,
            'can_create'     => self::current_user_can_read(),
            'can_write'      => $can_write,
            'available_years'=> $available_years,
            'filter_year'    => $filter_year
        ]);
    }

    /**
     * Crea un nuevo contrato.
     */
    public function ajax_create()
    {
        check_ajax_referer('ep_contratos_nonce', 'nonce');

        if (!self::current_user_can_read()) {
            wp_send_json_error(['message' => 'Sin permisos.'], 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ep_contratos';
        $year  = (int) date('Y');

        $numero = self::get_next_number();

        // Validar fecha
        $fecha_raw = sanitize_text_field($_POST['fecha'] ?? '');
        $fecha_dt  = $fecha_raw ? date('Y-m-d', strtotime($fecha_raw)) : date('Y-m-d');

        $data = [
            'numero'       => $numero,
            'anio'         => $year,
            'fecha'        => $fecha_dt,
            'siglas'       => sanitize_text_field($_POST['siglas'] ?? ''),
            'objeto'       => sanitize_textarea_field($_POST['objeto'] ?? ''),
            'codigo_curso' => sanitize_textarea_field($_POST['codigo_curso'] ?? ''),
            'duracion'     => sanitize_text_field($_POST['duracion'] ?? ''),
            'importe'      => sanitize_text_field($_POST['importe'] ?? ''),
            'identidad'    => sanitize_textarea_field($_POST['identidad'] ?? ''),
            'locked'       => 0,
            'autor_id'     => get_current_user_id(),
        ];

        $formats = ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d'];

        $result = $wpdb->insert($table, $data, $formats);

        if ($result === false) {
            wp_send_json_error(['message' => 'Error al guardar el contrato: ' . $wpdb->last_error]);
        }

        $new_id = $wpdb->insert_id;

        // Programar notificación a gestores de que hay un nuevo contrato para procesar
        wp_schedule_single_event(time(), 'ep_contratos_notify_bg', [$new_id, 'new_contract', get_current_user_id()]);

        $row    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $new_id), ARRAY_A);
        $row['fecha_fmt']  = date('d/m/Y', strtotime($row['fecha']));
        $row['can_edit']   = true;
        $row['can_upload'] = self::current_user_can_write();
        $row['can_delete'] = current_user_can('administrator');

        wp_send_json_success(['contrato' => $row, 'message' => "Contrato {$numero} creado correctamente."]);
    }

    /**
     * Edita un contrato existente (solo si no está bloqueado).
     */
    public function ajax_edit()
    {
        check_ajax_referer('ep_contratos_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'No autenticado.'], 401);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ep_contratos';
        $id    = (int) ($_POST['id'] ?? 0);

        if (!$id) {
            wp_send_json_error(['message' => 'ID inválido.']);
        }

        $contrato = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        if (!$contrato) {
            wp_send_json_error(['message' => 'Contrato no encontrado.']);
        }

        if ((int) $contrato['locked'] === 1) {
            wp_send_json_error(['message' => 'Este contrato está bloqueado y no puede editarse.']);
        }

        $current_uid = get_current_user_id();
        $is_admin    = current_user_can('administrator');

        if ($contrato['autor_id'] != $current_uid && !$is_admin) {
            wp_send_json_error(['message' => 'No tienes permisos para editar este contrato.'], 403);
        }

        $fecha_raw = sanitize_text_field($_POST['fecha'] ?? '');
        $fecha_dt  = $fecha_raw ? date('Y-m-d', strtotime($fecha_raw)) : $contrato['fecha'];

        $data = [
            'fecha'        => $fecha_dt,
            'siglas'       => sanitize_text_field($_POST['siglas'] ?? ''),
            'objeto'       => sanitize_textarea_field($_POST['objeto'] ?? ''),
            'codigo_curso' => sanitize_textarea_field($_POST['codigo_curso'] ?? ''),
            'duracion'     => sanitize_text_field($_POST['duracion'] ?? ''),
            'importe'      => sanitize_text_field($_POST['importe'] ?? ''),
            'identidad'    => sanitize_textarea_field($_POST['identidad'] ?? ''),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];
        $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        $row['fecha_fmt']  = date('d/m/Y', strtotime($row['fecha']));
        $row['can_edit']   = true;
        $row['can_upload'] = self::current_user_can_write();
        $row['can_delete'] = $is_admin;

        wp_send_json_success(['contrato' => $row, 'message' => 'Contrato actualizado correctamente.']);
    }

    /**
     * Sube el contrato firmado (PDF) y bloquea la tarjeta.
     * Solo usuarios con permiso write pueden hacerlo.
     */
    public function ajax_upload_signed()
    {
        check_ajax_referer('ep_contratos_nonce', 'nonce');

        if (!self::current_user_can_write()) {
            wp_send_json_error(['message' => 'Sin permisos para subir contratos firmados.'], 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ep_contratos';
        $id    = (int) ($_POST['id'] ?? 0);

        if (!$id) {
            wp_send_json_error(['message' => 'ID inválido.']);
        }

        $contrato = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        if (!$contrato) {
            wp_send_json_error(['message' => 'Contrato no encontrado.']);
        }

        if ((int) $contrato['locked'] === 1) {
            wp_send_json_error(['message' => 'Este contrato ya tiene un documento firmado adjunto.']);
        }

        if (empty($_FILES['contrato_firmado']['name'])) {
            wp_send_json_error(['message' => 'No se ha proporcionado ningún archivo.']);
        }

        // Validate file type (PDF only)
        $file_info = $_FILES['contrato_firmado'];
        $allowed   = ['application/pdf', 'application/x-pdf'];
        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file_info['tmp_name']);

        if (!in_array($mime_type, $allowed)) {
            wp_send_json_error(['message' => 'Solo se permiten archivos PDF.']);
        }

        $ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            wp_send_json_error(['message' => 'La extensión del archivo debe ser .pdf']);
        }

        // Upload the file using WordPress media library
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('contrato_firmado', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => 'Error al subir el archivo: ' . $attachment_id->get_error_message()]);
        }

        $file_url = wp_get_attachment_url($attachment_id);

        // Lock the contract and save the URL
        $wpdb->update(
            $table,
            [
                'locked'       => 1,
                'contrato_url' => $file_url,
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );

        // Notificar que el contrato ha sido firmado y bloqueado
        wp_schedule_single_event(time(), 'ep_contratos_notify_bg', [$id, 'contract_signed', get_current_user_id()]);

        wp_send_json_success([
            'message'      => '¡Contrato firmado adjuntado correctamente! La tarjeta ha quedado bloqueada.',
            'contrato_url' => $file_url,
        ]);
    }

    /**
     * Elimina un contrato (solo admin, solo si no está bloqueado).
     */
    public function ajax_delete()
    {
        check_ajax_referer('ep_contratos_nonce', 'nonce');

        if (!current_user_can('administrator')) {
            wp_send_json_error(['message' => 'Solo los administradores pueden eliminar contratos.'], 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ep_contratos';
        $id    = (int) ($_POST['id'] ?? 0);

        if (!$id) {
            wp_send_json_error(['message' => 'ID inválido.']);
        }

        $contrato = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        if (!$contrato) {
            wp_send_json_error(['message' => 'Contrato no encontrado.']);
        }

        if ((int) $contrato['locked'] === 1) {
            wp_send_json_error(['message' => 'No se puede eliminar un contrato bloqueado (tiene contrato firmado adjunto).']);
        }

        $wpdb->delete($table, ['id' => $id], ['%d']);

        wp_send_json_success(['message' => 'Contrato eliminado.']);
    }

    /**
     * Obtiene todos los contratos del año actual para estadísticas.
     */
    public static function get_stats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ep_contratos';
        $year  = (int) date('Y');

        $total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE anio = %d", $year));
        $locked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE anio = %d AND locked = 1", $year));

        return [
            'total_anio'  => $total,
            'total_firma' => $locked,
            'pendientes'  => $total - $locked,
        ];
    }

    /**
     * Procesa las notificaciones de contratos en segundo plano.
     */
    public function process_bg_notifications($id, $event_type, $trigger_user_id)
    {
        @set_time_limit(0);
        global $wpdb;
        $table = $wpdb->prefix . 'ep_contratos';
        $contrato = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        
        if (!$contrato) return;

        if (!class_exists('EP_Notifications')) {
            require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-notifications.php';
        }

        $trigger_user = get_userdata($trigger_user_id);
        $trigger_name = $trigger_user ? $trigger_user->display_name : 'Un usuario';

        if ($event_type === 'new_contract') {
            // Notificar a los que tienen permiso de escritura (gestores de contratos)
            $args = array(
                'role__in' => array('administrator', 'ep_hr', 'ep_direction', 'ep_super_admin'),
                'fields'   => 'ID'
            );
            $managers = get_users($args);
            
            foreach ($managers as $uid) {
                // Evitar auto-notificarse si el manager es el mismo que creó el registro
                if ($uid == $trigger_user_id) continue;

                EP_Notifications::add_notification($uid, [
                    'type'    => 'info',
                    'title'   => 'Nuevo Contrato Registrado',
                    'message' => "{$trigger_name} ha registrado el contrato {$contrato->numero}. Pendiente de adjuntar PDF firmado.",
                    'link'    => '?view=contratos'
                ]);
            }
        } 
        elseif ($event_type === 'contract_signed') {
            // Notificar al autor original y a los gestores
            $recipients = [$contrato->autor_id];
            
            $args = array(
                'role__in' => array('administrator', 'ep_hr', 'ep_direction', 'ep_super_admin'),
                'fields'   => 'ID'
            );
            $managers = get_users($args);
            $recipients = array_unique(array_merge($recipients, $managers));

            foreach ($recipients as $uid) {
                // Evitar auto-notificarse si el que subió el PDF es el mismo que recibe la notificación
                if ($uid == $trigger_user_id) continue;

                EP_Notifications::add_notification($uid, [
                    'type'    => 'success',
                    'title'   => 'Contrato Finalizado',
                    'message' => "Se ha subido el documento firmado para el contrato {$contrato->numero}. La tarjeta ha quedado bloqueada.",
                    'link'    => '?view=contratos'
                ]);
            }
        }
    }
}

// Bootstrap
new EP_Contratos();

