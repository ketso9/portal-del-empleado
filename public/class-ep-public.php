<?php

defined('ABSPATH') || exit;

#[AllowDynamicProperties]
class EP_Public
{

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Dark Mode Persistence
        add_action('wp_ajax_ep_toggle_dark_mode', array($this, 'ajax_toggle_dark_mode'));

        // App Order Customization
        add_action('wp_ajax_ep_save_app_order', array($this, 'ajax_save_app_order'));
        add_action('wp_ajax_ep_reset_app_order', array($this, 'ajax_reset_app_order'));

        // M365 Real-time Data
        add_action('wp_ajax_ep_get_m365_presence', array($this, 'ajax_get_m365_presence'));
        add_action('wp_ajax_ep_get_m365_events', array($this, 'ajax_get_m365_events'));
        add_action('wp_ajax_ep_get_m365_tasks', array($this, 'ajax_get_m365_tasks'));
        add_action('wp_ajax_ep_get_m365_emails', array($this, 'ajax_get_m365_emails'));
        add_action('wp_ajax_ep_get_onedrive_preview', array($this, 'ajax_get_onedrive_preview'));

        // Hide Admin Bar for non-admins
        add_action('after_setup_theme', array($this, 'hide_admin_bar_for_non_admins'));

        // Logout Redirect
        add_filter('logout_redirect', array($this, 'portal_logout_redirect'), 10, 3);
        add_action('wp_logout', array($this, 'portal_logout_redirect_action'));

        // Custom 404 handling
        add_filter('template_include', array($this, 'handle_custom_404'), 20);

        // Teams Mode Body Class
        add_filter('body_class', array($this, 'add_teams_body_class'));

        // Permit iframing from Teams
        add_action('send_headers', array($this, 'allow_teams_iframing'));
    }

    /**
     * Hide Admin Bar for non-administrators
     */
    public function hide_admin_bar_for_non_admins()
    {
        if (!current_user_can('administrator')) {
            show_admin_bar(false);
        }
    }

    /**
     * AJAX: Toggle Dark Mode preference
     */
    public function ajax_toggle_dark_mode()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }

        $dark_mode = isset($_POST['dark_mode']) ? sanitize_text_field($_POST['dark_mode']) : 'off';
        update_user_meta($user_id, 'ep_dark_mode', $dark_mode);

        wp_send_json_success();
    }

    /**
     * AJAX: Save custom user app order
     */
    public function ajax_save_app_order()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }

        $order = isset($_POST['order']) && is_array($_POST['order']) ? array_map('sanitize_key', $_POST['order']) : array();

        if (empty($order)) {
            wp_send_json_error('Lista de orden vacía');
        }

        update_user_meta($user_id, 'ep_user_app_order', $order);
        wp_send_json_success(array('message' => 'Orden guardado correctamente'));
    }

    /**
     * AJAX: Reset user app order to default (A-Z)
     */
    public function ajax_reset_app_order()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }

        delete_user_meta($user_id, 'ep_user_app_order');
        wp_send_json_success(array('message' => 'Orden restablecido a A-Z'));
    }

    /**
     * AJAX: Get M365 Presence for all employees
     */
    public function ajax_get_m365_presence()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id)
            wp_send_json_error('No autorizado');

        // Get all users who have an O365 ID (ms_user_id)
        $users = get_users(array(
            'meta_key' => 'ep_o365_user_id',
            'meta_compare' => 'EXISTS',
            'fields' => array('ID', 'display_name', 'user_email')
        ));

        if (empty($users)) {
            error_log("EP Debug: No users found with ep_o365_user_id meta.");
            wp_send_json_success(array());
        }

        $ms_ids = array();
        $user_map = array();
        foreach ($users as $user) {
            $ms_id = get_user_meta($user->ID, 'ep_o365_user_id', true);
            if ($ms_id) {
                $ms_ids[] = $ms_id;
                $user_map[$ms_id] = array(
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'photo' => get_user_meta($user->ID, 'ep_user_photo_url', true) ?: get_avatar_url($user->ID)
                );
            }
        }

        error_log("EP Debug: Querying presence for IDS: " . implode(', ', $ms_ids));

        $auth = EP_Auth_O365::get_instance();
        $presence_data = $auth->get_users_presence($user_id, $ms_ids);

        if (is_wp_error($presence_data)) {
            error_log("EP Debug Presence Error: " . $presence_data->get_error_message());
            wp_send_json_error($presence_data->get_error_message());
        }

        $result = array();
        foreach ($presence_data as $presence) {
            $ms_id = $presence['id'];
            if (isset($user_map[$ms_id])) {
                $target_wp_id = $user_map[$ms_id]['id'];
                // No mostrar al propio usuario en la lista de compañeros
                if ($target_wp_id == $user_id)
                    continue;

                $availability = $presence['availability'] ?? 'Offline';
                $activity     = $presence['activity'] ?? '';

                $oof_data = EP_Auth_O365::get_user_oof_data($target_wp_id);
                $is_oof   = $oof_data['is_oof'] || $availability === 'OutOfOffice' || $activity === 'OutOfOffice';

                if ($is_oof) {
                    $availability = 'OutOfOffice';
                }

                $result[] = array_merge($user_map[$ms_id], array(
                    'availability' => $availability,
                    'activity'     => $activity,
                    'is_oof'       => $is_oof,
                    'oof_message'  => $oof_data['message'] ?? ''
                ));
            } else {
                error_log("EP Debug: MS ID $ms_id not found in local user map.");
            }
        }

        error_log("EP Debug: Returning " . count($result) . " companions.");
        wp_send_json_success($result);
    }

    /**
     * AJAX: Get next M365 Event
     */
    public function ajax_get_m365_events()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id)
            wp_send_json_error('No autorizado');

        $auth = EP_Auth_O365::get_instance();
        $event = $auth->get_next_event($user_id);

        if (is_wp_error($event)) {
            wp_send_json_error($event->get_error_message());
        }

        wp_send_json_success($event);
    }

    /**
     * AJAX: Get Unified Tasks (M365 + Portal Internal)
     */
    public function ajax_get_m365_tasks()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id)
            wp_send_json_error('No autorizado');

        $unified_tasks = array();

        // 1. Get Microsoft To Do Tasks
        $auth = EP_Auth_O365::get_instance();
        $ms_tasks = $auth->get_my_tasks($user_id);

        if (!is_wp_error($ms_tasks) && is_array($ms_tasks)) {
            foreach ($ms_tasks as $task) {
                $unified_tasks[] = array(
                    'id' => 'ms_' . $task['id'],
                    'source' => 'microsoft',
                    'title' => $task['title'],
                    'type' => 'todo',
                    'link' => 'https://to-do.office.com/tasks/id/' . $task['id']
                );
            }
        }

        // 2. Firmas Pendientes (fds_documentos) - CORREGIDO
        global $wpdb;
        $signatures_table = $wpdb->prefix . 'fds_documentos';
        if ($wpdb->get_var("SHOW TABLES LIKE '$signatures_table'") == $signatures_table) {
            $pending_sigs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, nombre_archivo_original FROM $signatures_table WHERE usuario_id = %d AND estado = 'pendiente' LIMIT 5",
                $user_id
            ));

            ep_error_log("EP_Tasks DEBUG: Firmas encontradas: " . count($pending_sigs));

            foreach ($pending_sigs as $sig) {
                $unified_tasks[] = array(
                    'id' => 'sig_' . $sig->id,
                    'source' => 'portal',
                    'title' => 'Firma pendiente: ' . $sig->nombre_archivo_original,
                    'type' => 'signature',
                    'link' => '?view=signature'
                );
            }
        }

        // 3. Tickets (Novedades o Pendientes)
        if (class_exists('EP_Tickets')) {
            // Tickets que soy autor
            $my_tickets = EP_Tickets::get_user_tickets($user_id);
            // Tickets que puedo gestionar (si soy manager)
            $manage_tickets = EP_Tickets::get_manageable_tickets_for_user($user_id);

            $all_tickets = array_merge($my_tickets, $manage_tickets);
            // Deduplicate if any
            $unique_tickets = array();
            foreach ($all_tickets as $t)
                $unique_tickets[$t->ID] = $t;

            $count = 0;
            foreach ($unique_tickets as $ticket) {
                $status = get_post_meta($ticket->ID, '_ep_ticket_status', true);
                if ($status !== 'closed' && $count < 5) {
                    ep_error_log("EP_Tasks DEBUG: Agregando ticket " . $ticket->ID . " con estado: " . $status);
                    $prefix = ($ticket->post_author == $user_id) ? 'Mi Ticket' : 'Ticket Soporte';
                    $unified_tasks[] = array(
                        'id' => 'tk_' . $ticket->ID,
                        'source' => 'portal',
                        'title' => $prefix . ': ' . $ticket->post_title,
                        'type' => 'ticket',
                        'link' => '?view=tickets'
                    );
                    $count++;
                }
            }
        }

        // 4. Inventario (Alertas)
        //
        // Antes se consultaba la tabla legada {prefix}imjc_inventory_items pidiendo
        // return_date / warranty_date / is_itinerant, columnas que esa tabla nunca
        // ha tenido: cada carga del dashboard dejaba tres "Unknown column" en el
        // error_log y ninguna alerta llegaba nunca. El inventario del portal guarda
        // estos datos como post meta de 'ep_inventory_item'.
        if (class_exists('EP_Inventory')) {
            $today = current_time('Y-m-d');

            // Devoluciones próximas o pasadas (equipos itinerantes en préstamo).
            // El prestatario se guarda en _ep_item_loaned_to, no en _ep_item_assigned_to.
            $near_returns = get_posts(array(
                'post_type'      => 'ep_inventory_item',
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array('key' => '_ep_item_loaned_to', 'value' => $user_id),
                    array('key' => '_ep_item_itinerant_status', 'value' => 'loaned'),
                    array('key' => '_ep_item_estimated_return', 'value' => '', 'compare' => '!='),
                    array(
                        'key'     => '_ep_item_estimated_return',
                        'value'   => date('Y-m-d', strtotime($today . ' +2 days')),
                        'compare' => '<=',
                        'type'    => 'DATE'
                    ),
                )
            ));

            foreach ($near_returns as $item_id) {
                $unified_tasks[] = array(
                    'id' => 'inv_ret_' . $item_id,
                    'source' => 'portal',
                    'title' => '📦 Devolver equipo: ' . get_the_title($item_id),
                    'type' => 'inventory',
                    'link' => '?view=inventory'
                );
            }

            // Garantías próximas a caducar (< 30 días) del material asignado
            $near_warranty = get_posts(array(
                'post_type'      => 'ep_inventory_item',
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array('key' => '_ep_item_assigned_to', 'value' => $user_id),
                    array('key' => '_ep_item_warranty_date', 'value' => '', 'compare' => '!='),
                    array(
                        'key'     => '_ep_item_warranty_date',
                        'value'   => array($today, date('Y-m-d', strtotime($today . ' +30 days'))),
                        'compare' => 'BETWEEN',
                        'type'    => 'DATE'
                    ),
                )
            ));

            foreach ($near_warranty as $item_id) {
                $unified_tasks[] = array(
                    'id' => 'inv_war_' . $item_id,
                    'source' => 'portal',
                    'title' => '🛡️ Garantía por caducar: ' . get_the_title($item_id),
                    'type' => 'inventory',
                    'link' => '?view=inventory'
                );
            }
        }

        // 5. Descargas (Feedback pendiente o OK)
        if (class_exists('EP_Downloads')) {
            $feedback_docs = get_posts(array(
                'post_type' => 'ep_document',
                'author' => $user_id,
                'meta_query' => array(
                    array(
                        'key' => '_ep_document_review_status',
                        'value' => array('feedback', 'ok'),
                        'compare' => 'IN'
                    )
                )
            ));

            foreach ($feedback_docs as $doc) {
                $status = get_post_meta($doc->ID, '_ep_document_review_status', true);
                $title = ($status === 'ok') ? '✅ Doc revisado (OK): ' : '💬 Feedback necesario: ';
                $unified_tasks[] = array(
                    'id' => 'dl_fb_' . $doc->ID,
                    'source' => 'portal',
                    'title' => $title . $doc->post_title,
                    'type' => 'download',
                    'link' => '?view=downloads'
                );
            }
        }

        ep_error_log("EP_Tasks DEBUG: Total tareas unificadas: " . count($unified_tasks));
        wp_send_json_success($unified_tasks);
    }

    /**
     * AJAX: Get M365 Emails (Outlook)
     */
    public function ajax_get_m365_emails()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id)
            wp_send_json_error('No autorizado');

        $auth = EP_Auth_O365::get_instance();
        $emails = $auth->get_recent_emails($user_id);

        if (is_wp_error($emails)) {
            wp_send_json_error($emails->get_error_message());
        }

        wp_send_json_success($emails);
    }

    /**
     * AJAX: Get OneDrive Preview URL for a document
     */
    public function ajax_get_onedrive_preview()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $user_id = get_current_user_id();
        if (!$user_id)
            wp_send_json_error('No autorizado');

        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        ep_error_log("EP_Public: Solicitando previsualización OneDrive para DOC_ID: " . $doc_id . " por USER_ID: " . $user_id);

        if (!$doc_id) {
            ep_error_log("EP_Public Error: doc_id es 0. POST data: " . json_encode($_POST));
            wp_send_json_error('ID de documento no válido');
        }

        // Check if user has access
        $doc_type = get_post_meta($doc_id, '_ep_document_type', true);
        $target_user = (int) get_post_meta($doc_id, '_ep_document_target_user', true);
        $doc_author = (int) get_post_field('post_author', $doc_id);

        // Permitir si es público (sin destinatario específico o tipo public)
        $is_public = ($doc_type === 'public' || ($doc_type !== 'private' && $target_user <= 0));

        if (!$is_public && $target_user !== $user_id && $doc_author !== $user_id && !current_user_can('administrator')) {
            ep_error_log("EP_Public Error: Permiso denegado para DOC_ID $doc_id. Tipo: $doc_type, Target: $target_user, Author: $doc_author");
            wp_send_json_error('No tienes permiso para ver este documento');
        }

        $remote_id = get_post_meta($doc_id, '_ep_onedrive_item_id', true);
        if (!$remote_id) {
            wp_send_json_error('El documento no está sincronizado con OneDrive');
        }

        $auth = EP_Auth_O365::get_instance();
        $preview_url = $auth->get_item_preview_url($user_id, $remote_id);

        if (is_wp_error($preview_url)) {
            wp_send_json_error($preview_url->get_error_message());
        }

        wp_send_json_success($preview_url);
    }

    /**
     * Versión de un recurso a partir de su fecha de modificación. Antes se usaba
     * time(), lo que obligaba a todos los navegadores a volver a descargar el CSS
     * y el JS en cada carga de página.
     */
    private function asset_version($relative_path)
    {
        $file = EMPLOYEE_PORTAL_PATH . $relative_path;
        return file_exists($file) ? filemtime($file) : EMPLOYEE_PORTAL_VERSION;
    }

    public function enqueue_styles()
    {
        wp_enqueue_style($this->plugin_name, EMPLOYEE_PORTAL_URL . 'public/css/employee-portal.css', array(), $this->asset_version('public/css/employee-portal.css'), 'all');
        wp_enqueue_style('ep-tickets-extra', EMPLOYEE_PORTAL_URL . 'public/css/tickets-extra.css', array(), $this->asset_version('public/css/tickets-extra.css'), 'all');
        wp_enqueue_style('ep-header-widgets', EMPLOYEE_PORTAL_URL . 'public/css/header-widgets.css', array(), $this->asset_version('public/css/header-widgets.css'), 'all');
        wp_enqueue_style('ep-dashboard-widgets', EMPLOYEE_PORTAL_URL . 'public/css/dashboard-widgets.css', array(), $this->asset_version('public/css/dashboard-widgets.css'), 'all');
        // FontAwesome for icons
        wp_enqueue_style('ep-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0');
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script($this->plugin_name, EMPLOYEE_PORTAL_URL . 'public/js/employee-portal.js', array('jquery'), $this->asset_version('public/js/employee-portal.js'), false);

        wp_localize_script($this->plugin_name, 'ep_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ep_ajax_nonce'),
            'user_id' => get_current_user_id(),
            'is_dark_mode' => get_user_meta(get_current_user_id(), 'ep_dark_mode', true),
            'is_teams' => isset($_GET['teams']) && $_GET['teams'] === 'true'
        ));
    }

    public function load_portal_template($template)
    {
        global $post;

        if (is_singular() && isset($post->post_content) && has_shortcode($post->post_content, 'employee_portal')) {
            $new_template = plugin_dir_path(__FILE__) . 'templates/portal-template.php';
            if (file_exists($new_template)) {
                return $new_template;
            }
        }

        return $template;
    }

    public function render_dashboard($atts)
    {
        // Allow public access to verification action even if not logged in
        $is_verification = (isset($_GET['view']) && $_GET['view'] === 'signature' && isset($_GET['sub_action']) && $_GET['sub_action'] === 'verify' && isset($_GET['csv']));

        if (!is_user_logged_in() && !$is_verification) {
            return do_shortcode('[ep_login_button]');
        }

        // Maintenance Mode Check
        $maintenance_mode = get_option('ep_maintenance_mode', 0);
        $is_admin = current_user_can('administrator');
        if ($maintenance_mode && !$is_admin) {
            ob_start();
            include plugin_dir_path(__FILE__) . 'partials/maintenance-view.php';
            $content = ob_get_clean();

            ob_start();
            include plugin_dir_path(__FILE__) . 'partials/layout.php';
            return ob_get_clean();
        }

        global $ep_app_manager;
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'dashboard';

        // Check permissions for specific views (skip if it's a verification request)
        if ($view !== 'dashboard' && $view !== 'profile' && !$is_verification) {
            $app_id = $view;
            $permission = $ep_app_manager->get_user_permission($app_id);

            if ($permission === 'none') {
                $view = 'dashboard';
            }
        }

        // Capture content
        ob_start();

        if ($view === 'dashboard') {
            include plugin_dir_path(__FILE__) . 'partials/dashboard.php';
        } elseif ($view === 'profile') {
            include plugin_dir_path(__FILE__) . 'partials/profile-app.php';
        } elseif ($view === 'notifications') {
            include plugin_dir_path(__FILE__) . 'partials/notifications-view.php';
        } else {
            // Dynamic App Rendering
            $app = $ep_app_manager->get_app($view);
            if ($app) {
                $app->render_full_view();
            } else {
                // Fallback to dashboard if app not found
                include plugin_dir_path(__FILE__) . 'partials/dashboard.php';
            }
        }

        $content = ob_get_clean();

        // Render Layout
        ob_start();
        include plugin_dir_path(__FILE__) . 'partials/layout.php';
        return ob_get_clean();
    }

    /**
     * Redirect to home (portal login) after logout
     */
    public function portal_logout_redirect($redirect_to, $requested_redirect_to, $user)
    {
        return home_url('/');
    }

    /**
     * Force redirect to home after logout action
     */
    public function portal_logout_redirect_action()
    {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    /**
     * Handle custom 404 for the portal
     */
    public function handle_custom_404($template)
    {
        if (is_404()) {

            // DIAGNÓSTICO TEMPORAL: registrar por qué saltan los 404 en el admin
            $req_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown';
            ep_error_log("EP_DEBUG 404 disparado: Usuario ID: " . get_current_user_id() . " - RUTA: " . $req_url);

            $custom_404 = plugin_dir_path(__FILE__) . 'partials/404-portal.php';
            if (file_exists($custom_404)) {
                return $custom_404;
            }
        }
        return $template;
    }

    /**
     * Add 'ep-teams-mode' class to body if viewing from Teams
     */
    public function add_teams_body_class($classes)
    {
        if (isset($_GET['teams']) && $_GET['teams'] === 'true') {
            $classes[] = 'ep-teams-mode';
        }
        return $classes;
    }

    /**
     * Permit iframing from Teams
     */
    public function allow_teams_iframing()
    {
        if (isset($_GET['teams']) && $_GET['teams'] === 'true') {
            // Remove X-Frame-Options to allow Teams to embed the page
            header_remove('X-Frame-Options');
            // Set Content-Security-Policy for frame-ancestors
            header("Content-Security-Policy: frame-ancestors 'self' teams.microsoft.com *.teams.microsoft.com *.microsoft.com *.microsoftonline.com portal.camaracaceres.com");
        }
    }
}
