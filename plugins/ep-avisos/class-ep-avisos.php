<?php

class EP_App_Avisos implements EP_App_Interface
{
    public function get_id()
    {
        return 'avisos';
    }

    public function get_name()
    {
        return 'Avisos Generales';
    }

    public function get_icon()
    {
        return 'fa-solid fa-bullhorn';
    }

    public function get_menu_label()
    {
        return 'Avisos';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=avisos'">
            <div class="app-icon-container color-orange">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <h3>Avisos</h3>
            <p>Comunicaciones internas</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_AVISOS_PATH . 'partials/avisos-app.php';
    }

    public function handle_ajax()
    {
        // Handled via separate hooks in EP_Avisos class for consistency with other apps
    }
}

class EP_Avisos
{
    public function __construct()
    {
        add_action('init', array($this, 'register_avisos_cpt'));
        add_action('wp_ajax_ep_create_aviso', array($this, 'ajax_create_aviso'));
        add_action('wp_ajax_ep_get_avisos', array($this, 'ajax_get_avisos')); // Fixed action name from get_avisos to ep_get_avisos to match JS
        add_action('wp_ajax_ep_avisos_mark_read', array($this, 'ajax_mark_read'));
        add_action('wp_ajax_ep_avisos_get_reads', array($this, 'ajax_get_reads'));

        // Background Notifications hook
        add_action('ep_avisos_send_notifications_bg', array($this, 'process_bg_notifications'));

        // Security: Block direct access to ep_aviso posts
        add_action('template_redirect', array($this, 'block_direct_access'));

        // Cron tasks
        add_action('ep_daily_cleanup', array($this, 'daily_cleanup_tasks'));
        if (!wp_next_scheduled('ep_daily_cleanup')) {
            $six_am = strtotime('06:00:00');
            if ($six_am < time()) {
                $six_am = strtotime('tomorrow 06:00:00');
            }
            wp_schedule_event($six_am, 'daily', 'ep_daily_cleanup');
        }

        // --- Integración con IA Bot ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_context', array($this, 'inyectar_contexto_bot'), 10, 2);
        add_filter('ep_bot_handle_intent_avisos', array($this, 'responder_intent_bot'), 10, 5);
    }

    public function registrar_intent_bot($intents)
    {
        $intents['AVISOS'] = "El usuario quiere ver los comunicados internos o avisos. Ej: 'hay algún aviso', 'qué comunicados hay', 'ver noticias'.";
        return $intents;
    }

    public function inyectar_contexto_bot($context, $user_context)
    {
        $avisos_activos = self::get_active_avisos(8);
        if (!empty($avisos_activos)) {
            $contexto_avisos = "COMUNICADOS/AVISOS INTERNOS ACTIVOS DEL PORTAL:\n";
            foreach ($avisos_activos as $av) {
                $txt = mb_substr(wp_strip_all_tags($av['content']), 0, 250);
                $contexto_avisos .= "  · [{$av['date']}] {$av['title']}: $txt\n";
            }
            $context[] = $contexto_avisos;
        }
        return $context;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $avisos_activos = self::get_active_avisos(5);

        if (empty($avisos_activos)) {
            return $bot_instance->tarjeta_simple("📢 Avisos Generales", "No hay avisos ni comunicados recientes en este momento.", home_url('/?view=avisos'));
        }

        $facts = [];
        foreach ($avisos_activos as $av) {
            $date = $av['date'];
            $facts[] = ['title' => "📢 $date", 'value' => $av['title']];
        }

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => '📢 Últimos Avisos y Comunicados', 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'FactSet', 'facts' => $facts]
        ], [['type' => 'Action.OpenUrl', 'title' => 'Ver Tablón de Anuncios', 'url' => home_url('/?view=avisos')]]);
    }

    public function block_direct_access()
    {
        if (is_singular('ep_aviso')) {
            wp_redirect(home_url());
            exit;
        }
    }

    public function daily_cleanup_tasks()
    {
        $this->expire_avisos();
        $this->purge_old_avisos();
    }

    private function expire_avisos()
    {
        $today = date('Y-m-d');
        $args = array(
            'post_type' => 'ep_aviso',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_ep_aviso_expiry_date',
                    'value' => $today,
                    'compare' => '<',
                    'type' => 'DATE'
                )
            )
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                wp_update_post(array(
                    'ID' => get_the_ID(),
                    'post_status' => 'draft'
                ));
            }
            wp_reset_postdata();
        }
    }

    private function purge_old_avisos()
    {
        // Purge draft/expired avisos older than 6 months
        $six_months_ago = date('Y-m-d', strtotime('-6 months'));

        $args = array(
            'post_type' => 'ep_aviso',
            'post_status' => array('draft', 'trash'),
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_ep_aviso_expiry_date',
                    'value' => $six_months_ago,
                    'compare' => '<',
                    'type' => 'DATE'
                )
            )
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Delete attachments first
                $attachments = get_post_meta($post_id, '_ep_aviso_attachments', true) ?: array();
                foreach ($attachments as $att_id) {
                    wp_delete_attachment($att_id, true);
                }

                // Delete post permanently
                wp_delete_post($post_id, true);
            }
            wp_reset_postdata();
        }
    }

    public function register_avisos_cpt()
    {
        $labels = array(
            'name' => _x('Avisos', 'Post Type General Name', 'employee-portal'),
            'singular_name' => _x('Aviso', 'Post Type Singular Name', 'employee-portal'),
            'menu_name' => __('Avisos', 'employee-portal'),
        );
        $args = array(
            'label' => __('Aviso', 'employee-portal'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'author'),
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 7,
            'menu_icon' => 'dashicons-megaphone',
            'capability_type' => 'post',
        );
        register_post_type('ep_aviso', $args);
    }

    public static function can_manage_avisos($user_id = null)
    {
        if (!$user_id)
            $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        if (!$user)
            return false;

        $allowed_roles = array('ep_hr', 'ep_direction', 'ep_maintenance', 'ep_communication', 'administrator');
        foreach ($user->roles as $role) {
            if (in_array($role, $allowed_roles))
                return true;
        }
        return false;
    }

    public function ajax_create_aviso()
    {
        check_ajax_referer('ep_avisos_nonce', 'nonce');

        if (!self::can_manage_avisos()) {
            wp_send_json_error('No tienes permiso para gestionar avisos.');
        }

        $aviso_id    = !empty($_POST['aviso_id']) ? intval($_POST['aviso_id']) : 0;
        $title       = sanitize_text_field($_POST['title']);
        $content     = wp_kses_post($_POST['content']);
        $expiry_date = sanitize_text_field($_POST['expiry_date']);

        if (empty($title) || empty($content) || empty($expiry_date)) {
            wp_send_json_error('Todos los campos son obligatorios, incluyendo la fecha de caducidad.');
        }

        if ($aviso_id > 0) {
            // EDITING AN EXISTING NOTICE
            $existing_post = get_post($aviso_id);
            if (!$existing_post || $existing_post->post_type !== 'ep_aviso') {
                wp_send_json_error('El aviso especificado no existe.');
            }

            // Security check: Only the author (or admin) can edit
            if (intval($existing_post->post_author) !== get_current_user_id() && !current_user_can('administrator')) {
                wp_send_json_error('Solo la persona que publicó este aviso puede editarlo.');
            }

            $updated = wp_update_post(array(
                'ID'           => $aviso_id,
                'post_title'   => $title,
                'post_content' => $content,
            ));

            if (is_wp_error($updated)) {
                wp_send_json_error('Error al actualizar el aviso.');
            }

            $post_id = $aviso_id;
            $is_edit = true;
        } else {
            // CREATING A NEW NOTICE
            $post_id = wp_insert_post(array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'ep_aviso',
                'post_author'  => get_current_user_id(),
            ));

            if (is_wp_error($post_id)) {
                wp_send_json_error('Error al crear el aviso.');
            }
            $is_edit = false;
        }

        $target_department = sanitize_text_field($_POST['target_department'] ?? '');
        $target_users_raw  = $_POST['target_users'] ?? array();
        $target_users      = array();

        if (is_array($target_users_raw)) {
            foreach ($target_users_raw as $uid) {
                $uid_int = intval($uid);
                if ($uid_int > 0) $target_users[] = $uid_int;
            }
        } elseif (!empty($target_users_raw) && is_string($target_users_raw)) {
            $exploded = explode(',', $target_users_raw);
            foreach ($exploded as $uid) {
                $uid_int = intval(trim($uid));
                if ($uid_int > 0) $target_users[] = $uid_int;
            }
        }

        update_post_meta($post_id, '_ep_aviso_expiry_date', $expiry_date);
        update_post_meta($post_id, '_ep_aviso_target_department', $target_department);
        update_post_meta($post_id, '_ep_aviso_target_users', $target_users);

        // Handle File Uploads (max 3)
        if (!empty($_FILES['files'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            $files = $_FILES['files'];
            $count = min(count($files['name']), 3);
            $attachment_ids = get_post_meta($post_id, '_ep_aviso_attachments', true) ?: array();

            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $_FILES['single_file'] = array(
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                    );
                    $attachment_id = media_handle_upload('single_file', $post_id);
                    if (!is_wp_error($attachment_id)) {
                        $attachment_ids[] = $attachment_id;
                    }
                }
            }
            $attachment_ids = array_slice($attachment_ids, 0, 3);
            update_post_meta($post_id, '_ep_aviso_attachments', $attachment_ids);
        }

        if (!$is_edit) {
            // Programar notificaciones solo para avisos nuevos
            wp_schedule_single_event(time(), 'ep_avisos_send_notifications_bg', array($post_id));

            if (function_exists('spawn_cron')) {
                spawn_cron();
            }

            wp_send_json_success('Aviso creado correctamente.');
        } else {
            wp_send_json_success('Aviso actualizado correctamente.');
        }
    }

    public function process_bg_notifications($post_id)
    {
        // Aumentar el límite de tiempo ya que enviar >40 mensajes de Teams por Graph API puede tardar
        @set_time_limit(300); // 5 minutos máximo

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'ep_aviso') {
            return;
        }

        if (!class_exists('EP_Notifications')) {
            require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-notifications.php';
        }

        $title = '📢 Nuevo Aviso: ' . wp_strip_all_tags($post->post_title);
        $message = wp_trim_words(wp_strip_all_tags($post->post_content), 20);

        ep_error_log("EP_Avisos: Iniciando envío masivo para aviso ID $post_id...");

        $target_department = get_post_meta($post_id, '_ep_aviso_target_department', true);
        $target_users      = get_post_meta($post_id, '_ep_aviso_target_users', true);

        if (is_array($target_users) && !empty($target_users)) {
            $users = $target_users;
        } elseif (!empty($target_department)) {
            $users = get_users(array(
                'meta_key' => 'ep_department',
                'meta_value' => $target_department,
                'fields' => 'ID'
            ));
        } else {
            $users = get_users(array('fields' => 'ID'));
        }

        $success_count = 0;
        $fail_count = 0;

        foreach ($users as $user_id) {
            try {
                $result = EP_Notifications::add_notification($user_id, array(
                    'type' => 'info',
                    'title' => $title,
                    'message' => $message,
                    'link' => '?view=avisos'
                ));

                if ($result) {
                    $success_count++;
                } else {
                    $fail_count++;
                }
            } catch (\Exception $e) {
                $fail_count++;
                ep_error_log("EP_Avisos: Error enviando a usuario $user_id: " . $e->getMessage());
            } catch (\Throwable $t) {
                $fail_count++;
                ep_error_log("EP_Avisos: Error fatal enviando a usuario $user_id: " . $t->getMessage());
            }
        }

        ep_error_log("EP_Avisos: Envío masivo completado. Éxito: $success_count, Fallo: $fail_count.");
    }

    public function ajax_get_avisos()
    {
        check_ajax_referer('ep_avisos_nonce', 'security');
        
        if (function_exists('ep_stats_log')) {
            ep_stats_log('avisos', 'board_viewed', get_current_user_id());
        }

        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'active';
        $is_manager = self::can_manage_avisos();

        $args = array(
            'post_type' => 'ep_aviso',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if ($type === 'active') {
            $today = date('Y-m-d');
            $args['meta_query'] = array(
                array(
                    'key' => '_ep_aviso_expiry_date',
                    'value' => $today,
                    'compare' => '>=',
                    'type' => 'DATE'
                )
            );
        } elseif ($type === 'history' && !$is_manager) {
            wp_send_json_error('No tienes permiso para ver el historial.');
        }

        $query = new WP_Query($args);
        $avisos = array();

        if ($query->have_posts()) {
            $current_user_id = get_current_user_id();
            $user_dept = get_user_meta($current_user_id, 'ep_department', true);

            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Visibility check for non-managers
                if (!$is_manager) {
                    $t_dept  = get_post_meta($post_id, '_ep_aviso_target_department', true);
                    $t_users = get_post_meta($post_id, '_ep_aviso_target_users', true);

                    if (is_array($t_users) && !empty($t_users)) {
                        if (!in_array($current_user_id, $t_users)) {
                            continue;
                        }
                    } elseif (!empty($t_dept)) {
                        if (strtolower(trim($user_dept)) !== strtolower(trim($t_dept))) {
                            continue;
                        }
                    }
                }
                $attachments_ids = get_post_meta($post_id, '_ep_aviso_attachments', true) ?: array();
                $attachments = array();
                foreach ($attachments_ids as $aid) {
                    $attachments[] = array(
                        'url' => wp_get_attachment_url($aid),
                        'name' => basename(get_attached_file($aid))
                    );
                }

                $meta_key = 'ep_aviso_read_' . $post_id;
                $readers = get_users(array(
                    'meta_key' => $meta_key,
                    'meta_compare' => 'EXISTS',
                    'fields' => 'ids'
                ));
                $read_count = is_array($readers) ? count($readers) : 0;

                $post_author_id = intval(get_post_field('post_author', $post_id));
                $is_author = ($current_user_id === $post_author_id);

                $avisos[] = array(
                    'id'                => $post_id,
                    'title'             => get_the_title(),
                    'content'           => get_the_content(),
                    'excerpt'           => wp_trim_words(get_the_content(), 20),
                    'date'              => get_the_date(),
                    'expiry_date'       => get_post_meta($post_id, '_ep_aviso_expiry_date', true),
                    'target_department' => get_post_meta($post_id, '_ep_aviso_target_department', true),
                    'target_users'      => get_post_meta($post_id, '_ep_aviso_target_users', true) ?: array(),
                    'author'            => get_the_author(),
                    'author_id'         => $post_author_id,
                    'can_edit'          => $is_author,
                    'attachments'       => $attachments,
                    'read_count'        => $read_count
                );
            }
            wp_reset_postdata();
        }

        wp_send_json_success($avisos);
    }

    public function ajax_mark_read()
    {
        $aviso_id = isset($_POST['aviso_id']) ? sanitize_text_field($_POST['aviso_id']) : '';
        $user_id = get_current_user_id();

        if (!empty($aviso_id) && $user_id) {
            update_user_meta($user_id, 'ep_aviso_read_' . $aviso_id, current_time('mysql'));
            if (function_exists('ep_stats_log')) {
                ep_stats_log('avisos', 'aviso_read', $user_id, array('aviso_id' => $aviso_id));
            }
            wp_send_json_success('Lectura de aviso registrada');
        }
        wp_send_json_error('Parámetros inválidos');
    }

    public function ajax_get_reads()
    {
        $aviso_id = isset($_POST['aviso_id']) ? sanitize_text_field($_POST['aviso_id']) : '';

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $user_dept = (string) ($user->ep_department ?? '');
        $can_view_reads = current_user_can('administrator')
            || in_array('ep_hr', $user_roles)
            || in_array('ep_direction', $user_roles)
            || strpos($user_dept, 'Direcci') !== false
            || strpos($user_dept, 'RRHH') !== false
            || strpos($user_dept, 'Recursos Humanos') !== false
            || self::can_manage_avisos();

        if (!$can_view_reads) {
            wp_send_json_error('No tienes permisos para consultar las lecturas. Esta función está reservada para Dirección, RRHH y Administradores.');
        }

        $meta_key = 'ep_aviso_read_' . $aviso_id;
        $readers = get_users(array(
            'meta_key' => $meta_key,
            'meta_compare' => 'EXISTS'
        ));

        $data = array();
        foreach ($readers as $r) {
            $read_time = get_user_meta($r->ID, $meta_key, true);
            $dept = get_user_meta($r->ID, 'ep_department', true) ?: 'General';
            $data[] = array(
                'name' => $r->display_name,
                'email' => $r->user_email,
                'dept' => $dept,
                'read_at' => $read_time ? date('d/m/Y H:i:s', strtotime($read_time)) : 'N/A'
            );
        }

        wp_send_json_success($data);
    }
    public static function get_active_avisos($limit = -1)
    {
        $today = date('Y-m-d');
        $args = array(
            'post_type' => 'ep_aviso',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                array(
                    'key' => '_ep_aviso_expiry_date',
                    'value' => $today,
                    'compare' => '>=',
                    'type' => 'DATE'
                )
            )
        );

        $query = new WP_Query($args);
        $avisos = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $attachments_ids = get_post_meta($post_id, '_ep_aviso_attachments', true) ?: array();
                $attachments = array();
                foreach ($attachments_ids as $aid) {
                    $attachments[] = array(
                        'url' => wp_get_attachment_url($aid),
                        'name' => basename(get_attached_file($aid))
                    );
                }

                $avisos[] = array(
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'content' => get_the_content(),
                    'excerpt' => wp_trim_words(get_the_content(), 20),
                    'date' => get_the_date(),
                    'expiry_date' => get_post_meta($post_id, '_ep_aviso_expiry_date', true),
                    'author' => get_the_author(),
                    'attachments' => $attachments
                );
            }
            wp_reset_postdata();
        }
        return $avisos;
    }
}

new EP_Avisos();

