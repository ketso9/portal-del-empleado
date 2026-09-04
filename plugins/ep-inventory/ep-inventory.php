<?php

/**
 * Module Name: Inventory Mini App
 * Description: Gestión de inventario de hardware y software y asignación a usuarios.
 * Package: pro_plus
 */

defined('ABSPATH') || exit;

// Define CONSTANTS
define('EP_INVENTORY_PATH', plugin_dir_path(__FILE__));
define('EP_INVENTORY_URL', plugin_dir_url(__FILE__));

class EP_Inventory
{
    public function __construct()
    {
        // Load dependencies
        require_once EP_INVENTORY_PATH . 'class-ep-inventory-cpt.php';
        require_once EP_INVENTORY_PATH . 'class-ep-inventory-pdf.php';

        // Load TCPDF if not available
        if (!class_exists('TCPDF')) {
            $tcpdf_path = EMPLOYEE_PORTAL_PATH . 'plugins/ep-signature/libs/tcpdf/tcpdf.php';
            if (file_exists($tcpdf_path)) {
                require_once $tcpdf_path;
            }
        }

        // Initialize CPT
        new EP_Inventory_CPT();

        // Initialize Shortcodes
        add_shortcode('ep_inventory_dashboard', array($this, 'render_shortcode'));

        // Register Search Filters
        $this->register_search_filters();

        // AJAX Hooks
        add_action('wp_ajax_ep_inventory_save_item', array($this, 'ajax_save_item'));
        add_action('wp_ajax_ep_inventory_get_item', array($this, 'ajax_get_item'));
        add_action('wp_ajax_ep_inventory_delete_item', array($this, 'ajax_delete_item'));
        add_action('wp_ajax_ep_inventory_clone_item', array($this, 'ajax_clone_item'));
        add_action('wp_ajax_ep_inventory_generate_labels', array($this, 'ajax_generate_labels'));
        add_action('wp_ajax_ep_inventory_request_material', array($this, 'ajax_request_material'));
        add_action('wp_ajax_ep_update_request_status', array($this, 'ajax_update_request_status'));
        add_action('wp_ajax_ep_inventory_unassign_user', array($this, 'ajax_unassign_user'));
        add_action('wp_ajax_ep_inventory_bulk_assign', array($this, 'ajax_bulk_assign'));
        add_action('wp_ajax_ep_inventory_save_inline', array($this, 'ajax_save_inline')); // Inline chip editor

        // Admin Post for PDF Download
        add_action('admin_post_ep_inventory_download_commitment', array($this, 'handle_download_commitment'));
        add_action('admin_post_ep_inventory_download_itinerant_loan', array($this, 'handle_download_itinerant_loan'));
        add_action('admin_post_ep_inventory_download_request_commitment', array($this, 'handle_download_request_commitment'));
        add_action('wp_ajax_ep_inventory_upload_signed_commitment', array($this, 'ajax_upload_signed_commitment'));
        add_action('wp_ajax_ep_inventory_upload_signed_loan', array($this, 'ajax_upload_signed_loan'));
        add_action('wp_ajax_ep_inventory_upload_signed_request', array($this, 'ajax_upload_signed_request_doc'));
        add_action('admin_post_ep_inventory_download_labels', array($this, 'handle_download_labels'));
        add_action('admin_post_ep_inventory_export', array($this, 'handle_export'));

        // Schedule daily warranty check
        add_action('ep_inventory_daily_warranty_check', array($this, 'check_warranty_expirations'));
        // El hook estaba registrado pero nadie programaba el evento, asi que el aviso
        // de garantia no se ha enviado nunca.
        add_action('init', array($this, 'maybe_schedule_warranty_check'));

        // Itinerant Actions
        add_action('wp_ajax_ep_inventory_itinerant_action', array($this, 'ajax_itinerant_action'));

        // Roles
        add_action('init', array($this, 'register_roles'));

        // Enqueue Assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Register App Class
        add_action('ep_register_apps', function ($manager) {
            require_once EP_INVENTORY_PATH . 'class-ep-app-inventory.php';
            $manager->register_app(new EP_App_Inventory());
        });

        // User Deletion Hook
        add_action('delete_user', array($this, 'handle_user_deletion'));

        // New AJAX for User-centric view
        add_action('wp_ajax_ep_inventory_get_available_items', array($this, 'ajax_get_available_items'));
        add_action('wp_ajax_ep_inventory_assign_item_to_user', array($this, 'ajax_assign_item_to_user'));

        // --- Integración con IA Bot ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_inventory', array($this, 'responder_intent_bot'), 10, 5);
    }

    /**
     * Helper para obtener todo el material de un usuario (Asignado + Itinerante en préstamo)
     */
    public static function get_user_assets($user_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'posts';
        $meta_table = $wpdb->prefix . 'postmeta';

        // Buscamos items donde el usuario sea responsable O prestatario
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title 
             FROM $table p
             INNER JOIN $meta_table m ON p.ID = m.post_id
             WHERE p.post_type = 'ep_inventory_item' 
             AND p.post_status = 'publish'
             AND (
                (m.meta_key = '_ep_item_assigned_to' AND m.meta_value = %d)
                OR 
                (m.meta_key = '_ep_item_loaned_to' AND m.meta_value = %d)
             )",
            $user_id, $user_id
        ));

        $assets = [];
        foreach ($results as $item) {
            $serial = get_post_meta($item->ID, '_ep_item_serial', true);
            $is_itinerant = get_post_meta($item->ID, '_ep_item_is_itinerant', true) === '1';
            $it_status = get_post_meta($item->ID, '_ep_item_itinerant_status', true) ?: 'available';
            $loaned_to = get_post_meta($item->ID, '_ep_item_loaned_to', true);
            
            $name = $item->post_title;
            
            // Si el usuario es prestatario, añadir nota
            if ($is_itinerant && $it_status === 'loaned' && $loaned_to == $user_id) {
                $name .= " (En préstamo temporal)";
            }

            $assets[] = (object)[
                'name' => $name,
                'serial_number' => $serial ?: 'S/N',
                'id' => $item->ID
            ];
        }

        return $assets;
    }

    public function register_roles()
    {
        // Add capability to administrator
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('ep_manage_inventory');
            $admin->add_cap('ep_manage_itinerant_inventory');
        }

        // Register Itinerant Manager role (Backwards Compatibility)
        if (!get_role('ep_itinerant_manager')) {
            add_role('ep_itinerant_manager', 'Gestor de Inventario Itinerante', array(
                'read' => true,
                'ep_manage_itinerant_inventory' => true
            ));
        }
    }

    public function enqueue_assets()
    {
        // Only enqueue if necessary (check logic later)
        wp_enqueue_style('ep-inventory-css', EP_INVENTORY_URL . 'assets/css/ep-inventory.css', array(), '1.0.0');
        wp_enqueue_script('ep-inventory-js', EP_INVENTORY_URL . 'assets/js/ep-inventory.js', array('jquery'), (file_exists(EP_INVENTORY_PATH . 'assets/js/ep-inventory.js') ? filemtime(EP_INVENTORY_PATH . 'assets/js/ep-inventory.js') : '1.0'), true);

        wp_localize_script('ep-inventory-js', 'ep_inventory_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ep_inventory_nonce')
        ));
    }


    /**
     * Shortcode [ep_inventory_dashboard]. Estaba registrado en el constructor pero el
     * metodo no existia: cualquier pagina que lo usara reventaba con un error fatal.
     */
    public function render_shortcode($atts = array())
    {
        if (!is_user_logged_in()) {
            return '<p>Debes iniciar sesión para ver el inventario.</p>';
        }

        global $ep_app_manager;
        if (!$ep_app_manager || $ep_app_manager->get_user_permission('inventory') === 'none') {
            return '<p>No tienes acceso al inventario.</p>';
        }

        require_once EP_INVENTORY_PATH . 'class-ep-app-inventory.php';
        $app = new EP_App_Inventory();

        ob_start();
        $app->render_full_view();
        return ob_get_clean();
    }

    public function ajax_save_item()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $title = sanitize_text_field($_POST['title']);
        $type = sanitize_text_field($_POST['type']);
        $serial = sanitize_text_field($_POST['serial']);
        $provider = sanitize_text_field($_POST['provider']);
        $purchase_date = sanitize_text_field($_POST['purchase_date']);
        $warranty_date = sanitize_text_field($_POST['warranty_date']);
        $assigned_to = intval($_POST['assigned_to']);

        $post_data = array(
            'post_title' => $title,
            'post_type' => 'ep_inventory_item',
            'post_status' => 'publish'
        );

        if ($id > 0) {
            // Mismo motivo que en el borrado: sin validar el tipo, un ID cualquiera
            // convierte otro contenido del portal en item de inventario.
            $existing = get_post($id);
            if (!$existing || $existing->post_type !== 'ep_inventory_item') {
                wp_send_json_error('Item no encontrado.');
            }

            $post_data['ID'] = $id;
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id->get_error_message());
        }

        update_post_meta($post_id, '_ep_item_type', $type);
        update_post_meta($post_id, '_ep_item_serial', $serial);
        update_post_meta($post_id, '_ep_item_provider', $provider);
        update_post_meta($post_id, '_ep_item_purchase_date', $purchase_date);
        $old_warranty = get_post_meta($post_id, '_ep_item_warranty_date', true);
        update_post_meta($post_id, '_ep_item_warranty_date', $warranty_date);
        if ($old_warranty !== $warranty_date) {
            delete_post_meta($post_id, '_ep_item_warranty_notified'); // Nueva fecha, nuevo aviso
        }
        $old_assigned_to = get_post_meta($post_id, '_ep_item_assigned_to', true);
        update_post_meta($post_id, '_ep_item_assigned_to', $assigned_to);

        // Notify user if assignment changed
        if ($assigned_to > 0 && $assigned_to != $old_assigned_to && class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($assigned_to, [
                'type' => 'info',
                'title' => 'Material Asignado',
                'message' => 'Se te ha asignado un nuevo material: "' . $title . '".',
                'link' => '?view=profile'
            ]);
        }

        // Itinerant Fields
        $is_itinerant = isset($_POST['is_itinerant']) ? '1' : '0';
        $itinerant_status = isset($_POST['itinerant_status']) ? sanitize_text_field($_POST['itinerant_status']) : 'available';

        // Sync: if assigned_to is 0 and status was 'loaned', force 'available'
        if ($is_itinerant === '1' && $assigned_to === 0 && $itinerant_status === 'loaned') {
            $itinerant_status = 'available';
        }

        update_post_meta($post_id, '_ep_item_is_itinerant', $is_itinerant);
        update_post_meta($post_id, '_ep_item_itinerant_status', $itinerant_status);

        // Sync Commitment PDF if assigned
        if ($assigned_to > 0) {
            $pdf = new EP_Inventory_PDF();
            $pdf->sync_commitment_to_portal($assigned_to);
        }
        if ($old_assigned_to > 0 && $old_assigned_to != $assigned_to) {
            $pdf = new EP_Inventory_PDF();
            $pdf->sync_commitment_to_portal($old_assigned_to);
        }

        wp_send_json_success('Item guardado correctamente.');
    }

    public function ajax_get_item()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write' && !current_user_can('ep_manage_inventory')) {
            if (function_exists('ep_stats_log')) {
                ep_stats_log('inventory', 'security_alert', get_current_user_id(), [
                    'action' => 'ajax_get_item',
                    'detail' => 'Unauthorized access attempt to inventory item ID: ' . (isset($_POST['id']) ? intval($_POST['id']) : 'unknown'),
                    'severity' => 'medium'
                ]);
            }
            wp_send_json_error('Permisos insuficientes.');
        }

        $id = intval($_POST['id']);
        $post = get_post($id);

        if (!$post || $post->post_type !== 'ep_inventory_item') {
            wp_send_json_error('Item no encontrado.');
        }

        $data = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => get_post_meta($post->ID, '_ep_item_type', true),
            'serial' => get_post_meta($post->ID, '_ep_item_serial', true),
            'provider' => get_post_meta($post->ID, '_ep_item_provider', true),
            'purchase_date' => get_post_meta($post->ID, '_ep_item_purchase_date', true),
            'warranty_date' => get_post_meta($post->ID, '_ep_item_warranty_date', true),
            'assigned_to' => get_post_meta($post->ID, '_ep_item_assigned_to', true),
            'is_itinerant' => get_post_meta($post->ID, '_ep_item_is_itinerant', true),
            'itinerant_status' => get_post_meta($post->ID, '_ep_item_itinerant_status', true) ?: 'available'
        );

        wp_send_json_success($data);
    }

    public function ajax_delete_item()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id = intval($_POST['id']);

        // Sin esta comprobacion, el permiso de inventario borra CUALQUIER contenido
        // del portal por ID (paginas, documentos firmados, gastos...) y ademas de
        // forma permanente, sin pasar por la papelera.
        $post = get_post($id);
        if (!$post || $post->post_type !== 'ep_inventory_item') {
            wp_send_json_error('Item no encontrado.');
        }

        wp_delete_post($id, true);
        wp_send_json_success('Item eliminado.');
    }

    /**
     * Guarda campos inline (ubicación / notas) directamente desde la tabla.
     */
    public function ajax_save_inline()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        $perm = $ep_app_manager->get_user_permission('inventory');
        if ($perm !== 'write' && $perm !== 'manage_itinerant') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id    = intval($_POST['id'] ?? 0);
        $field = sanitize_key($_POST['field'] ?? '');
        $value = sanitize_text_field($_POST['value'] ?? '');

        if (!$id || !in_array($field, ['location', 'notes'], true)) {
            wp_send_json_error('Parámetros inválidos.');
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'ep_inventory_item') {
            wp_send_json_error('Item no encontrado.');
        }

        $meta_key = '_ep_item_' . $field; // _ep_item_location | _ep_item_notes
        update_post_meta($id, $meta_key, $value);

        // Si el item esta prestado, la ubicacion del chip manda tambien sobre la del
        // prestamo: es la que leen el justificante PDF y la exportacion CSV.
        if ($field === 'location' && get_post_meta($id, '_ep_item_itinerant_status', true) === 'loaned') {
            update_post_meta($id, '_ep_item_loan_location', $value);
        }

        wp_send_json_success(['field' => $field, 'value' => $value]);
    }

    public function ajax_generate_labels()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $ids = isset($_POST['ids']) ? $_POST['ids'] : array();

        // Ensure IDs is an array of integers
        if (!is_array($ids)) {
            $ids = array($ids);
        }
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids); // Remove 0s

        if (empty($ids)) {
            wp_send_json_error('No has seleccionado ningún item válido.');
        }

        // Token for transient
        $token = 'labels_' . md5(uniqid(rand(), true));
        set_transient($token, $ids, 60 * 5); // 5 mins

        $url = admin_url('admin-post.php?action=ep_inventory_download_labels&token=' . $token);

        wp_send_json_success(array('url' => $url));
    }

    public function handle_download_labels()
    {
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_die('Permisos insuficientes.');
        }

        $ids = array();

        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        if ($token) {
            $ids = get_transient($token);
        }

        if (empty($ids) && isset($_GET['item_id'])) {
            $ids = array(intval($_GET['item_id']));
        }

        if (empty($ids) && isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $ids = get_posts(array(
                'post_type' => 'ep_inventory_item',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => array(
                    array('key' => '_ep_item_assigned_to', 'value' => $user_id, 'compare' => '=')
                )
            ));
        }

        if (empty($ids)) {
            wp_die('No se han especificado items válidos o el enlace ha caducado.');
        }

        $pdf = new EP_Inventory_PDF();
        $result = $pdf->generate_labels($ids);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }
    }

    public function handle_download_commitment()
    {
        if (!is_user_logged_in()) {
            wp_die('Debes iniciar sesión.');
        }

        global $ep_app_manager;
        $can_write = $ep_app_manager->get_user_permission('inventory') === 'write';

        // Check if specific user requested and has permission
        $target_user_id = get_current_user_id();
        if (isset($_REQUEST['user_id'])) {
            if ($can_write) {
                $target_user_id = intval($_REQUEST['user_id']);
            } else {
                wp_die('Permisos insuficientes para ver este documento.');
            }
        }

        // Verify nonce (allow if admin? or strict?)
        // Let's keep it strict but maybe allow a specific admin nonce if needed?
        // reusing the same nonce action for now.
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ep_inventory_download_commitment')) {
            wp_die('Seguridad fallida.');
        }

        // PRIORIDAD: Si existe un documento firmado en el sistema de descargas, servir ese.
        if (class_exists('EP_Downloads')) {
            $signed_doc = get_posts(array(
                'post_type' => 'ep_document',
                'meta_query' => array(
                    array('key' => '_ep_document_target_user', 'value' => $target_user_id),
                    array('key' => '_ep_document_source_tag', 'value' => 'inventory_commitment'),
                    array('key' => '_ep_document_type', 'value' => 'private'),
                    array('key' => '_ep_document_is_signed', 'value' => '1')
                ),
                'posts_per_page' => 1,
                'orderby' => 'ID',
                'order' => 'DESC'
            ));

            if (!empty($signed_doc)) {
                $post_id = $signed_doc[0]->ID;
                $attachment_id = get_post_meta($post_id, '_ep_document_attachment_id', true);
                $file_path = get_attached_file($attachment_id);

                if ($file_path && file_exists($file_path)) {
                    $filename = basename($file_path);
                    $filetype = wp_check_filetype($file_path);

                    header('Content-Description: File Transfer');
                    header('Content-Type: ' . ($filetype['type'] ?: 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($file_path));

                    if (ob_get_level())
                        ob_end_clean();
                    readfile($file_path);
                    exit;
                }
            }
        }

        $pdf = new EP_Inventory_PDF();
        $result = $pdf->generate_commitment($target_user_id);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }
    }

    public function handle_download_itinerant_loan()
    {
        if (!is_user_logged_in()) {
            wp_die('Debes iniciar sesión.');
        }

        $ids_raw = $_REQUEST['item_id'] ?? '';
        if (is_array($ids_raw)) {
            wp_die('ID de material no válido.'); // explode() sobre un array es fatal en PHP 8
        }
        $ids_raw = (string) $ids_raw;
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        if (empty($ids)) {
            wp_die('ID de material no especificado.');
        }

        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ep_inventory_download_loan_' . $ids_raw)) {
            wp_die('Seguridad fallida.');
        }

        global $ep_app_manager;
        $perm = $ep_app_manager->get_user_permission('inventory');

        if ($perm !== 'write' && $perm !== 'manage_itinerant' && !current_user_can('administrator')) {
            wp_die('Permisos insuficientes.');
        }

        $pdf = new EP_Inventory_PDF();
        if (count($ids) > 1) {
            $result = $pdf->generate_bulk_itinerant_loan($ids);
        } else {
            $result = $pdf->generate_itinerant_loan($ids[0]);
        }

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }
    }

    public function handle_download_request_commitment()
    {
        if (!is_user_logged_in()) {
            wp_die('Debes iniciar sesión.');
        }

        if (!isset($_REQUEST['request_id'])) {
            wp_die('ID de solicitud no especificado.');
        }

        $request_id = intval($_REQUEST['request_id']);

        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ep_inventory_download_request_' . $request_id)) {
            wp_die('Seguridad fallida.');
        }

        // Permissions: Admin or the user who made the request
        $viewer_id = get_current_user_id();
        $request_user_id = get_post_meta($request_id, '_ep_request_user_id', true);

        global $ep_app_manager;
        if ($viewer_id != $request_user_id && $ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_die('Permisos insuficientes.');
        }

        $pdf = new EP_Inventory_PDF();
        $result = $pdf->generate_request_commitment($request_id);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }
    }

    public function ajax_upload_signed_loan()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write' && !current_user_can('ep_manage_itinerant_inventory')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        if (!$item_id || empty($_FILES['signed_doc'])) {
            wp_send_json_error('Datos incompletos.');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = media_handle_upload('signed_doc', $item_id);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error($attachment_id->get_error_message());
        }

        // Guardamos el ID del adjunto como meta del item
        update_post_meta($item_id, '_ep_item_signed_loan_doc_id', $attachment_id);

        wp_send_json_success(array(
            'url' => wp_get_attachment_url($attachment_id),
            'message' => 'Documento de préstamo subido correctamente.'
        ));
    }

    public function ajax_upload_signed_commitment()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (!$user_id || empty($_FILES['signed_doc'])) {
            wp_send_json_error('Datos incompletos.');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $file_array = array(
            'name' => $_FILES['signed_doc']['name'],
            'type' => $_FILES['signed_doc']['type'],
            'tmp_name' => $_FILES['signed_doc']['tmp_name'],
            'error' => $_FILES['signed_doc']['error'],
            'size' => $_FILES['signed_doc']['size'],
        );

        if (class_exists('EP_Downloads')) {
            try {
                // Sincronización a OneDrive desactivada (quinto parámetro: false)
                $result = EP_Downloads::add_system_document($user_id, $file_array['tmp_name'], 'Compromiso_Cesion_Firmado_' . date('Y-m-d') . '.pdf', 'inventory_commitment', false);

                if (is_wp_error($result)) {
                    wp_send_json_error($result->get_error_message());
                } elseif ($result) {
                    EP_Downloads::mark_as_signed($result);
                    wp_send_json_success('Documento subido y registrado localmente.');
                } else {
                    wp_send_json_error('Error desconocido al guardar el documento.');
                }
            } catch (\Exception $e) {
                wp_send_json_error('Excepción: ' . $e->getMessage());
            }
        } else {
            wp_send_json_error('El sistema de descargas no está activo.');
        }
    }

    public function ajax_upload_signed_request_doc()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $request_id = intval($_POST['request_id']);
        if (!$request_id || empty($_FILES['signed_doc'])) {
            wp_send_json_error('Datos incompletos.');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = media_handle_upload('signed_doc', $request_id);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error($attachment_id->get_error_message());
        }

        update_post_meta($request_id, '_ep_request_signed_doc_id', $attachment_id);

        $doc_url = wp_get_attachment_url($attachment_id);

        wp_send_json_success(array(
            'url' => $doc_url,
            'message' => 'Documento subido correctamente.'
        ));
    }

    public function ajax_request_material()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        $user = wp_get_current_user();
        $is_itinerant = isset($_POST['is_itinerant']) && $_POST['is_itinerant'] === 'true';

        if ($is_itinerant) {
            $item_ids_raw = sanitize_text_field($_POST['item_ids']);
            $item_ids = array_map('intval', explode(',', $item_ids_raw));
            $item_ids = array_filter($item_ids); // Remove 0s

            if (empty($item_ids)) {
                wp_send_json_error('No se han seleccionado equipos válidos.');
            }

            $start_date = sanitize_text_field($_POST['start_date']);
            $end_date = sanitize_text_field($_POST['end_date']);
            $reason = sanitize_textarea_field($_POST['reason']);

            // Overlap Validation
            foreach ($item_ids as $item_id) {
                $item_id = intval($item_id);

                // 1. Check current loan status overlap
                $it_status = get_post_meta($item_id, '_ep_item_itinerant_status', true);
                if ($it_status === 'loaned') {
                    $item_start = get_post_meta($item_id, '_ep_item_loan_date', true);
                    $item_end = get_post_meta($item_id, '_ep_item_estimated_return', true);

                    if ($item_start && $item_end) {
                        // Check if [start, end] overlaps [item_start, item_end]
                        if ($start_date <= $item_end && $end_date >= $item_start) {
                            wp_send_json_error('El equipo "' . get_the_title($item_id) . '" ya está en préstamo del ' . date('d/m/Y', strtotime($item_start)) . ' al ' . date('d/m/Y', strtotime($item_end)) . '.');
                        }
                    }
                }

                // 2. Check other pending/accepted requests overlap
                $args = array(
                    'post_type' => 'ep_material_request',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_ep_request_status',
                            'value' => array('pending', 'accepted'),
                            'compare' => 'IN'
                        ),
                        array(
                            'key' => '_ep_request_is_itinerant',
                            'value' => '1',
                            'compare' => '='
                        )
                    )
                );
                $existing_requests = get_posts($args);
                foreach ($existing_requests as $ex_req) {
                    $ex_item_ids = explode(',', get_post_meta($ex_req->ID, '_ep_request_item_ids', true));
                    if (in_array($item_id, $ex_item_ids)) {
                        $ex_start = get_post_meta($ex_req->ID, '_ep_request_start_date', true);
                        $ex_end = get_post_meta($ex_req->ID, '_ep_request_end_date', true);

                        if ($start_date <= $ex_end && $end_date >= $ex_start) {
                            // Refining: if material is available and not assigned, allow overlap if the old request is in the past/current
                            $current_assigned = get_post_meta($item_id, '_ep_item_assigned_to', true);
                            $it_status_now = get_post_meta($item_id, '_ep_item_itinerant_status', true);

                            if ($it_status_now === 'available' && !$current_assigned && $ex_start <= date('Y-m-d')) {
                                // Material was returned early or is free, ignore the old current block
                                continue;
                            }

                            $ex_user_id = get_post_meta($ex_req->ID, '_ep_request_user_id', true);
                            $ex_user = get_userdata($ex_user_id);
                            $u_name = $ex_user ? $ex_user->display_name : 'otro usuario';

                            wp_send_json_error('El equipo "' . get_the_title($item_id) . '" ya tiene una reserva de ' . $u_name . ' del ' . date('d/m/Y', strtotime($ex_start)) . ' al ' . date('d/m/Y', strtotime($ex_end)) . '.');
                        }
                    }
                }
            }

            $item_titles = [];
            foreach ($item_ids as $id) {
                $item_titles[] = get_the_title($id);
            }

            $details = "SOLICITUD DE MATERIAL ITINERANTE\n";
            $details .= "Equipos: " . implode(', ', $item_titles) . "\n";
            $details .= "Desde: " . date('d/m/Y', strtotime($start_date)) . "\n";
            $details .= "Hasta: " . date('d/m/Y', strtotime($end_date)) . "\n";
            $details .= "Motivo: " . $reason;
        } else {
            $details = sanitize_textarea_field($_POST['details']);
        }

        // Email to admin
        $to = get_option('admin_email');
        $subject = 'Nueva Solicitud de Material: ' . $user->display_name;
        $message = "El usuario {$user->display_name} ha solicitado el siguiente material:\n\n";
        $message .= $details;
        $message .= "\n\nPuede gestionar esta solicitud contactando con el usuario o asignando material en el inventario.";

        // 1. Send Email
        wp_mail($to, $subject, $message);

        // 2. Save Request to DB
        $post_data = array(
            'post_title' => 'Solicitud de ' . $user->display_name . ' - ' . date('d/m/Y'),
            'post_content' => $details,
            'post_status' => 'publish',
            'post_type' => 'ep_material_request',
            'post_author' => $user->ID
        );
        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            update_post_meta($post_id, '_ep_request_user_id', $user->ID);
            update_post_meta($post_id, '_ep_request_status', 'pending');

            if ($is_itinerant) {
                update_post_meta($post_id, '_ep_request_is_itinerant', '1');
                update_post_meta($post_id, '_ep_request_item_ids', implode(',', $item_ids)); // Saneado, no el POST crudo
                update_post_meta($post_id, '_ep_request_start_date', $start_date);
                update_post_meta($post_id, '_ep_request_end_date', $end_date);
            }

            // Log stats
            if (function_exists('ep_stats_log')) {
                ep_stats_log('inventory', 'material_request_created', $user->ID, [
                    'request_id' => $post_id,
                    'details' => $details,
                    'is_itinerant' => $is_itinerant
                ]);
            }
        }

        wp_send_json_success('Solicitud enviada correctamente.');
    }


    // Actually, sticking to the standard "Filter by Search" using meta_query OR logic in the View file is much easier and safer than raw SQL injection here.
    // I will NOT add these filters here, but instead modify admin-inventory.php to build a proper meta_query OR loop.
    // Wait, WP_Query doesn't support "Search Title OR Meta" natively easily without a filter.
    // So I DO need a filter. Let's register it in __construct and implement it properly.

    public function register_search_filters()
    {
        add_filter('posts_join', array($this, 'search_join'), 10, 2);
        add_filter('posts_where', array($this, 'search_where'), 10, 2);
        add_filter('posts_distinct', array($this, 'search_distinct'), 10, 2);
    }

    public function search_join($join, $query)
    {
        global $wpdb;
        if ($query->get('ep_custom_search')) {
            $join .= " LEFT JOIN {$wpdb->postmeta} ep_meta ON {$wpdb->posts}.ID = ep_meta.post_id ";
        }
        return $join;
    }

    public function search_where($where, $query)
    {
        global $wpdb;
        $search_term = $query->get('ep_custom_search');
        if (!empty($search_term)) {
            $like_term = '%' . $wpdb->esc_like($search_term) . '%';

            // 1. Search Users by Display Name to get IDs
            $user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s",
                $like_term
            ));

            $meta_or_clause = $wpdb->prepare("(ep_meta.meta_value LIKE %s)", $like_term);

            if (!empty($user_ids)) {
                $ids_string = implode(',', array_map('intval', $user_ids));
                $meta_or_clause .= " OR (ep_meta.meta_key = '_ep_item_assigned_to' AND ep_meta.meta_value IN ($ids_string))";
                $meta_or_clause .= " OR (ep_meta.meta_key = '_ep_item_loaned_to' AND ep_meta.meta_value IN ($ids_string))";
            }

            // Append safely to existing WHERE clause
            // Note: We avoid passing $meta_or_clause back into prepare() to prevent % issues
            $where .= " AND ({$wpdb->posts}.post_title LIKE " . $wpdb->prepare('%s', $like_term) . " OR $meta_or_clause) ";
        }
        return $where;
    }

    public function search_distinct($distinct, $query)
    {
        if ($query->get('ep_custom_search')) {
            return "DISTINCT";
        }
        return $distinct;
    }

    public function ajax_update_request_status()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

        if (!$request_id || !$status) {
            wp_send_json_error('Datos inválidos.');
        }

        update_post_meta($request_id, '_ep_request_status', $status);

        // If accepted and itinerant, sync item status
        if ($status === 'accepted') {
            $user_id = get_post_meta($request_id, '_ep_request_user_id', true);
            $is_itinerant = get_post_meta($request_id, '_ep_request_is_itinerant', true);
            if ($is_itinerant) {
                $item_ids_str = get_post_meta($request_id, '_ep_request_item_ids', true);
                if ($item_ids_str) {
                    $item_ids = explode(',', $item_ids_str);
                    $start_date = get_post_meta($request_id, '_ep_request_start_date', true);
                    $end_date = get_post_meta($request_id, '_ep_request_end_date', true);

                    foreach ($item_ids as $item_id) {
                        $item_id = intval($item_id);
                        update_post_meta($item_id, '_ep_item_itinerant_status', 'loaned');
                        // El solicitante es el PRESTATARIO, no el responsable del equipo:
                        // escribir en _ep_item_assigned_to borraba al responsable interno
                        // y dejaba el prestatario vacio en los reportes.
                        update_post_meta($item_id, '_ep_item_loaned_to', $user_id);
                        update_post_meta($item_id, '_ep_item_loan_date', $start_date);
                        update_post_meta($item_id, '_ep_item_estimated_return', $end_date);
                        update_post_meta($item_id, '_ep_item_last_checkout_by', get_current_user_id());
                    }
                }
            }
            // Sync Commitment PDF for user
            $pdf = new EP_Inventory_PDF();
            $pdf->sync_commitment_to_portal($user_id);
        }

        // Log stats
        if (function_exists('ep_stats_log')) {
            ep_stats_log('inventory', 'request_status_change', get_current_user_id(), [
                'request_id' => $request_id,
                'status' => $status,
                'user_id' => get_post_meta($request_id, '_ep_request_user_id', true)
            ]);
        }

        // Optional: Email User
        $user_id = get_post_meta($request_id, '_ep_request_user_id', true);
        if ($user_id) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                $subject = 'Actualización de tu solicitud de material';
                $message = "Hola {$user_info->display_name},\n\n";
                $message .= "El estado de tu solicitud de material ha cambiado a: " . ucfirst($status) . ".\n\n";
                $message .= "Saludos,\nPortal del Empleado";
                wp_mail($user_info->user_email, $subject, $message);
            }
        }

        wp_send_json_success('Estado actualizado.');
    }

    public function ajax_clone_item()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id = intval($_POST['id']);
        $post = get_post($id);

        if (!$post || $post->post_type !== 'ep_inventory_item') {
            wp_send_json_error('Item no encontrado.');
        }

        $new_post_data = array(
            'post_title' => $post->post_title . ' (Copia)',
            'post_type' => 'ep_inventory_item',
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        );

        $new_post_id = wp_insert_post($new_post_data);

        if (is_wp_error($new_post_id)) {
            wp_send_json_error($new_post_id->get_error_message());
        }

        // Clone Meta
        $meta_keys = array(
            '_ep_item_type',
            '_ep_item_provider',
            '_ep_item_purchase_date',
            '_ep_item_warranty_date',
            '_ep_item_assigned_to'
        );

        foreach ($meta_keys as $key) {
            $val = get_post_meta($id, $key, true);
            update_post_meta($new_post_id, $key, $val);
        }

        // We explicitly empty Serial Number for the clone to avoid duplicates/confusion
        update_post_meta($new_post_id, '_ep_item_serial', '');

        wp_send_json_success('Item clonado correctamente. El número de serie se ha dejado vacío.');
    }

    public function ajax_unassign_user()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (!$user_id) {
            wp_send_json_error('ID de usuario no válido.');
        }

        $args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_ep_item_assigned_to',
                    'value' => $user_id,
                    'compare' => '='
                )
            ),
            'fields' => 'ids'
        );

        $items = get_posts($args);

        if (empty($items)) {
            wp_send_json_success('No hay material asignado a este usuario.');
        }

        foreach ($items as $item_id) {
            update_post_meta($item_id, '_ep_item_assigned_to', 0);
            delete_post_meta($item_id, '_ep_item_assigned_date');

            // Sync Itinerant Status
            $is_itinerant = get_post_meta($item_id, '_ep_item_is_itinerant', true);
            if ($is_itinerant === '1') {
                update_post_meta($item_id, '_ep_item_itinerant_status', 'available');
                update_post_meta($item_id, '_ep_item_loan_date', '');
                update_post_meta($item_id, '_ep_item_estimated_return', '');
            }
        }

        // PDF Sync
        if (class_exists('EP_Inventory_PDF')) {
            $pdf = new EP_Inventory_PDF();
            $pdf->sync_commitment_to_portal($user_id);
        }

        wp_send_json_success(count($items) . ' items han sido liberados de este usuario.');
    }

    public function ajax_bulk_assign()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        // Handle both 'ids' (old) and 'item_ids' (new)
        $ids = isset($_POST['item_ids']) ? $_POST['item_ids'] : (isset($_POST['ids']) ? $_POST['ids'] : array());
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if (empty($ids)) {
            wp_send_json_error('No has seleccionado ningún item.');
        }

        $pdf = new EP_Inventory_PDF();
        $users_to_sync = array();
        if ($user_id > 0)
            $users_to_sync[] = $user_id;

        foreach ($ids as $id) {
            $item_id = intval($id);
            $old_assigned_to = get_post_meta($item_id, '_ep_item_assigned_to', true);

            if ($old_assigned_to > 0 && !in_array((int) $old_assigned_to, $users_to_sync)) {
                $users_to_sync[] = (int) $old_assigned_to;
            }

            update_post_meta($item_id, '_ep_item_assigned_to', $user_id);

            if ($user_id > 0) {
                update_post_meta($item_id, '_ep_item_assigned_date', current_time('mysql'));
            } else {
                delete_post_meta($item_id, '_ep_item_assigned_date');
            }

            // Sync Itinerant Status
            $is_itinerant = get_post_meta($item_id, '_ep_item_is_itinerant', true);
            if ($is_itinerant === '1') {
                if ($user_id > 0) {
                    update_post_meta($item_id, '_ep_item_itinerant_status', 'loaned');
                    // Marcar 'loaned' sin prestatario dejaba la columna vacia en el CSV.
                    update_post_meta($item_id, '_ep_item_loaned_to', $user_id);
                } else {
                    update_post_meta($item_id, '_ep_item_itinerant_status', 'available');
                    update_post_meta($item_id, '_ep_item_loaned_to', 0);
                    update_post_meta($item_id, '_ep_item_loan_date', '');
                    update_post_meta($item_id, '_ep_item_estimated_return', '');
                }
            }

            // Notify user
            if ($user_id > 0 && $user_id != $old_assigned_to && class_exists('EP_Notifications')) {
                $title = get_the_title($item_id);
                EP_Notifications::add_notification($user_id, [
                    'type' => 'info',
                    'title' => 'Material Asignado',
                    'message' => 'Se te ha asignado un nuevo material: "' . $title . '".',
                    'link' => '?view=profile'
                ]);
            }
        }

        // Sync all affected users
        if (class_exists('EP_Inventory_PDF')) {
            foreach ($users_to_sync as $uid) {
                $pdf->sync_commitment_to_portal($uid);
            }
        }

        // Log stats
        if (function_exists('ep_stats_log')) {
            ep_stats_log('inventory', 'inventory_bulk_assign', get_current_user_id(), [
                'count' => count($ids),
                'user_id' => $user_id
            ]);
        }

        wp_send_json_success(count($ids) . ' items asignados correctamente.');
    }

    public function handle_export()
    {
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_die('Permisos insuficientes.');
        }

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=inventario-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Nuevas cabeceras más completas
        fputcsv($output, array(
            'ID', 
            'Nombre Item', 
            'Tipo', 
            'Nº Serie / Licencia', 
            'Responsable Interno', 
            'Prestatario Actual', 
            'Estado Préstamo', 
            'Ubicación Préstamo',
            'Proveedor', 
            'Fecha Compra', 
            'Fin Garantía'
        ), ';', '"', "\\");

        $args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();
                $type = get_post_meta($id, '_ep_item_type', true);
                $provider = get_post_meta($id, '_ep_item_provider', true);
                $serial = get_post_meta($id, '_ep_item_serial', true);
                
                // Responsable
                $assigned_id = get_post_meta($id, '_ep_item_assigned_to', true);
                $assigned_name = 'Sin asignar';
                if ($assigned_id) {
                    $u = get_userdata($assigned_id);
                    $assigned_name = $u ? $u->display_name : 'Usuario no encontrado';
                }

                // Prestatario
                $loaned_to_id = get_post_meta($id, '_ep_item_loaned_to', true);
                $external_borrower = get_post_meta($id, '_ep_item_external_borrower', true);
                $it_status = get_post_meta($id, '_ep_item_itinerant_status', true) ?: 'available';
                // Unica fuente de verdad: el chip de la tabla, lo mismo que se ve en el
                // panel. Al dar entrada se vacia (el equipo vuelve al almacen), asi que
                // aqui tampoco debe salir la ubicacion del prestamo ya cerrado.
                $location = get_post_meta($id, '_ep_item_location', true);

                $borrower_name = '';
                if ($loaned_to_id) {
                    $lu = get_userdata($loaned_to_id);
                    $borrower_name = $lu ? $lu->display_name : 'Usuario no encontrado';
                } elseif ($external_borrower) {
                    $borrower_name = $external_borrower . ' (Externo)';
                }

                $p_date = get_post_meta($id, '_ep_item_purchase_date', true);
                $w_date = get_post_meta($id, '_ep_item_warranty_date', true);

                // Limpieza de datos
                $title = html_entity_decode(get_the_title(), ENT_QUOTES, 'UTF-8');
                $provider = html_entity_decode($provider, ENT_QUOTES, 'UTF-8');
                $serial = html_entity_decode($serial, ENT_QUOTES, 'UTF-8');
                $assigned_name = html_entity_decode($assigned_name, ENT_QUOTES, 'UTF-8');
                $borrower_name = html_entity_decode($borrower_name, ENT_QUOTES, 'UTF-8');
                $location = html_entity_decode($location, ENT_QUOTES, 'UTF-8');

                fputcsv($output, array(
                    $id,
                    $title,
                    ucfirst($type),
                    $serial,
                    $assigned_name,
                    $borrower_name,
                    ucfirst($it_status),
                    $location,
                    $provider,
                    $p_date,
                    $w_date
                ), ';', '"', "\\");
            }
        }
        wp_reset_postdata();
        fclose($output);
        exit;
    }

    /**
     * Programa el chequeo diario de garantias si aun no esta en el cron.
     */
    public function maybe_schedule_warranty_check()
    {
        if (!wp_next_scheduled('ep_inventory_daily_warranty_check')) {
            wp_schedule_event(strtotime('tomorrow 07:00:00'), 'daily', 'ep_inventory_daily_warranty_check');
        }
    }

    /**
     * Check for items with warranty expiring in 30 days and notify admins.
     */
    public function check_warranty_expirations()
    {
        $today = date('Y-m-d');
        $target_date = date('Y-m-d', strtotime('+30 days'));

        // Ventana completa en vez de la fecha exacta de hoy+30: buscando solo ese dia,
        // un cron que se salte una ejecucion pierde el aviso de ese equipo para
        // siempre. La marca _ep_item_warranty_notified evita repetirlo cada mañana.
        $args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_item_warranty_date',
                    'value' => array($today, $target_date),
                    'compare' => 'BETWEEN',
                    'type' => 'DATE'
                ),
                array(
                    'key' => '_ep_item_warranty_notified',
                    'compare' => 'NOT EXISTS'
                )
            )
        );

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $admin_email = get_option('admin_email');
            $subject = 'Aviso: Equipos con Garantía próxima a vencer (30 días)';
            $message = "Los siguientes equipos tienen la garantía a punto de vencer (hasta el $target_date):\n\n";
            $notified = array();

            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();
                $serial = get_post_meta($id, '_ep_item_serial', true);
                $w_date = get_post_meta($id, '_ep_item_warranty_date', true);
                $message .= "- " . get_the_title() . " (Serie: $serial) - Vence: $w_date - ID: $id\n";
                $notified[] = $id;
            }

            $message .= "\nPor favor, revísalos en el portal del empleado.";

            if (wp_mail($admin_email, $subject, $message)) {
                // Solo se marcan si el correo ha salido: si falla, se reintenta mañana.
                foreach ($notified as $id) {
                    update_post_meta($id, '_ep_item_warranty_notified', '1');
                }
            }
        }

        wp_reset_postdata();
    }

    /**
     * Handle itinerant check-in/check-out.
     */
    public function ajax_itinerant_action()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        $perm = $ep_app_manager->get_user_permission('inventory');

        // Both 'write' (Full) and 'manage_itinerant' (Partial) can perform these actions
        if ($perm !== 'write' && $perm !== 'manage_itinerant' && !current_user_can('administrator')) {
            wp_send_json_error('Permisos insuficientes para gestionar material itinerante.');
        }

        $ids_raw = $_POST['item_id'] ?? $_POST['item_ids'] ?? '';
        if (is_array($ids_raw)) {
            $ids = array_filter(array_map('intval', $ids_raw));
        } else {
            $ids = array_filter(array_map('intval', explode(',', (string)$ids_raw)));
        }

        $op = sanitize_text_field($_POST['op']); // check_out or check_in

        if (empty($ids)) {
            wp_send_json_error('ID de item no válido.');
        }

        if ($op === 'check_out') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $external_name = sanitize_text_field($_POST['external_name'] ?? '');
            $loan_date = sanitize_text_field($_POST['loan_date'] ?? date('Y-m-d'));
            $return_date = sanitize_text_field($_POST['return_date'] ?? '');
            $loan_location = sanitize_text_field($_POST['loan_location'] ?? '');
            $borrower_cargo = sanitize_text_field($_POST['borrower_cargo'] ?? '');
            $borrower_nif = sanitize_text_field($_POST['borrower_nif'] ?? '');

            if (!$user_id && empty($external_name)) {
                wp_send_json_error('Debes seleccionar un usuario o indicar un nombre externo.');
            }

            foreach ($ids as $id) {
                update_post_meta($id, '_ep_item_itinerant_status', 'loaned');
                update_post_meta($id, '_ep_item_loaned_to', $user_id);
                update_post_meta($id, '_ep_item_external_borrower', $external_name);
                update_post_meta($id, '_ep_item_borrower_nif', $borrower_nif);
                
                update_post_meta($id, '_ep_item_loan_date', $loan_date);
                update_post_meta($id, '_ep_item_estimated_return', $return_date);
                update_post_meta($id, '_ep_item_loan_location', $loan_location);
                update_post_meta($id, '_ep_item_location', $loan_location); // Sincroniza con chip de la tabla
                update_post_meta($id, '_ep_item_borrower_cargo', $borrower_cargo);
                update_post_meta($id, '_ep_item_last_checkout_by', get_current_user_id());
                delete_post_meta($id, '_ep_item_signed_loan_doc_id'); // Limpiar documento previo si existía

                // Log stats
                if (function_exists('ep_stats_log')) {
                    ep_stats_log('inventory', 'itinerant_check_out', get_current_user_id(), [
                        'item_id' => $id,
                        'item_title' => get_the_title($id),
                        'user_id' => $user_id,
                        'external' => $external_name,
                        'nif' => $borrower_nif
                    ]);
                }
            }

            // Sync Commitment PDF (for the internal borrower if applicable)
            if ($user_id > 0) {
                $pdf = new EP_Inventory_PDF();
                $pdf->sync_commitment_to_portal($user_id);
            }

            $ids_str = implode(',', $ids);
            $download_url = admin_url('admin-post.php?action=ep_inventory_download_itinerant_loan&item_id=' . $ids_str . '&nonce=' . wp_create_nonce('ep_inventory_download_loan_' . $ids_str));

            $msg = count($ids) > 1 ? 'Salida en lote registrada correctamente.' : 'Salida registrada correctamente.';
            wp_send_json_success([
                'message' => $msg,
                'download_url' => $download_url
            ]);
        } else {
            // Check-in (Devolución) - ahora soporta lote
            $affected_users = [];
            foreach ($ids as $id) {
                $old_borrower_id = get_post_meta($id, '_ep_item_loaned_to', true);
                if ($old_borrower_id) {
                    $affected_users[] = $old_borrower_id;
                }

                update_post_meta($id, '_ep_item_itinerant_status', 'available');
                update_post_meta($id, '_ep_item_loaned_to', 0);
                update_post_meta($id, '_ep_item_external_borrower', '');
                update_post_meta($id, '_ep_item_borrower_nif', '');
                update_post_meta($id, '_ep_item_location', ''); // Limpia el chip al devolver
                update_post_meta($id, '_ep_item_loan_location', ''); // Y la ubicacion del prestamo, para que no la arrastren los reportes
                update_post_meta($id, '_ep_item_loan_date', ''); // Un equipo en almacen no tiene fechas de prestamo vivas
                update_post_meta($id, '_ep_item_estimated_return', '');
                update_post_meta($id, '_ep_item_borrower_cargo', '');
                delete_post_meta($id, '_ep_item_signed_loan_doc_id'); // Limpiar documento al devolver

                // Log stats
                if (function_exists('ep_stats_log')) {
                    ep_stats_log('inventory', 'itinerant_check_in', get_current_user_id(), [
                        'item_id' => $id,
                        'item_title' => get_the_title($id)
                    ]);
                }
            }

            // Sync Commitment PDF for all affected users
            if (!empty($affected_users)) {
                $affected_users = array_unique($affected_users);
                $pdf = new EP_Inventory_PDF();
                foreach ($affected_users as $uid) {
                    $pdf->sync_commitment_to_portal($uid);
                }
            }

            $msg = count($ids) > 1 ? 'Entrada en lote registrada correctamente.' : 'Entrada (devolución) registrada correctamente.';
            wp_send_json_success($msg);
        }
    }

    public function handle_user_deletion($user_id)
    {
        // Find all items assigned to this user OR loaned to them
        $items = get_posts(array(
            'post_type' => 'ep_inventory_item',
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => '_ep_item_assigned_to', 'value' => $user_id, 'compare' => '='),
                array('key' => '_ep_item_loaned_to', 'value' => $user_id, 'compare' => '=')
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        foreach ($items as $item_id) {
            if (get_post_meta($item_id, '_ep_item_assigned_to', true) == $user_id) {
                update_post_meta($item_id, '_ep_item_assigned_to', '0');
                delete_post_meta($item_id, '_ep_item_assigned_date');
            }
            if (get_post_meta($item_id, '_ep_item_loaned_to', true) == $user_id) {
                update_post_meta($item_id, '_ep_item_loaned_to', '0');
                update_post_meta($item_id, '_ep_item_itinerant_status', 'available');
            }
        }

        // Sync portal
        $pdf = new EP_Inventory_PDF();
        $pdf->sync_commitment_to_portal($user_id);
    }

    public function ajax_get_available_items()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        // El nonce lo tiene cualquier usuario logueado (enqueue_assets lo publica en
        // todas las paginas), asi que sin esta comprobacion cualquier empleado podia
        // listar todo el material libre con sus numeros de serie. Se exige el mismo
        // permiso que para asignarlo, que es lo unico para lo que sirve esta lista.
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_item_assigned_to',
                    'value' => '0',
                    'compare' => '='
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => '_ep_item_is_itinerant',
                        'compare' => 'NOT EXISTS'
                    ),
                    array(
                        'key' => '_ep_item_is_itinerant',
                        'value' => '1',
                        'compare' => '!='
                    )
                )
            )
        );

        $items = get_posts($args);
        $data = array();

        foreach ($items as $item) {
            $data[] = array(
                'id' => $item->ID,
                'title' => $item->post_title,
                'type' => get_post_meta($item->ID, '_ep_item_type', true),
                'serial' => get_post_meta($item->ID, '_ep_item_serial', true)
            );
        }

        wp_send_json_success($data);
    }

    public function ajax_assign_item_to_user()
    {
        check_ajax_referer('ep_inventory_nonce', 'security');

        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission('inventory') !== 'write') {
            wp_send_json_error('Permisos insuficientes.');
        }

        $item_id = intval($_POST['item_id']);
        $user_id = intval($_POST['user_id']);

        if (!$item_id || !$user_id) {
            wp_send_json_error('Datos insuficientes.');
        }

        update_post_meta($item_id, '_ep_item_assigned_to', $user_id);
        update_post_meta($item_id, '_ep_item_assigned_date', current_time('mysql'));

        // Clear signed status of existing commitment if any, or just sync
        $pdf = new EP_Inventory_PDF();
        $pdf->sync_commitment_to_portal($user_id);

        wp_send_json_success('Item asignado correctamente.');
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intent_bot($intents)
    {
        $intents['INVENTORY'] = "El usuario pregunta por el material corporativo o equipo que tiene asignado o necesita soporte con él. Ej: 'tengo ordenador asignado', 'qué móvil tengo', 'mi inventario'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $wp_user = get_userdata($user_id);
        $nombre  = $wp_user ? $wp_user->display_name : 'Usuario';
        return $this->tarjeta_inventario($user_id, $nombre, $bot_instance);
    }

    private function tarjeta_inventario(int $user_id, string $nombre, $bot_instance): array
    {
        $assets = self::get_user_assets($user_id);

        if (empty($assets)) {
            return $bot_instance->tarjeta_simple('📦 Tu Inventario', "No tienes equipo asignado en este momento.", '');
        }

        $hechos = [];
        foreach (array_slice($assets, 0, 5) as $item) {
             $hechos[] = ['title' => $item->name, 'value' => $item->serial_number ?: 'S/N'];
        }

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "📦 Tu equipamiento, {$nombre}", 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'FactSet', 'facts' => $hechos]
        ], [['type' => 'Action.OpenUrl', 'title' => 'Ver todo mi equipo', 'url' => home_url('/?view=inventory')]]);
    }
}

// Initialize Module
new EP_Inventory();
