<?php

defined('ABSPATH') || exit;

/**
 * EP_Notifications handler
 */
class EP_Notifications
{
    private static $is_sending_notification = false;

    public function __construct()
    {
        add_action('wp_ajax_ep_get_notifications', array($this, 'ajax_get_notifications'));
        add_action('wp_ajax_ep_mark_notification_read', array($this, 'ajax_mark_notification_read'));
        add_action('wp_ajax_ep_mark_all_notifications_read', array($this, 'ajax_mark_all_read'));
        add_filter('pre_wp_mail', array($this, 'gatekeeper_block_and_bridge_emails'), 10, 1);

        // Self-healing: ensure table exists
        self::ensure_table_exists();
    }

    public static function ensure_table_exists()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_notifications';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-activator.php';
            EP_Activator::activate(); // Re-run activation logic to create table
        }
    }

    /**
     * Add a new notification
     * 
     * @param int $user_id
     * @param array $data {
     *     @type string $type (info|success|warning|error)
     *     @type string $title
     *     @type string $message
     *     @type string $link
     * }
     * @return int|bool ID of the notification or false on failure
     */
    public static function add_notification($user_id, $data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_notifications';

        $defaults = array(
            'type' => 'info',
            'title' => '',
            'message' => '',
            'link' => '',
            'is_read' => 0
        );

        $data = wp_parse_args($data, $defaults);
        $data['user_id'] = $user_id;

        ep_error_log("EP_Notifications: add_notification para user $user_id. Título: " . $data['title'], true);

        // Check if notifications are disabled globally
        if (get_option('ep_global_notifications_disabled', 0)) {
            ep_error_log("EP_Notifications: Notifications DESACTIVADAS globalmente.", true);
            return false;
        }

        // Check if user wants app notifications
        $app_enabled = get_user_meta($user_id, 'ep_notifications_app', true);
        if ($app_enabled === '0') {
            ep_error_log("EP_Notifications: Notificación de app desactivada para user $user_id", true);
            return false;
        }

        $inserted = $wpdb->insert($table_name, $data);

        if (!$inserted) {
            ep_error_log("EP_Notifications: FALLO al insertar notificación en BD para user $user_id. DB Error: " . $wpdb->last_error, true);
            return false;
        }

        $notification_id = $wpdb->insert_id;
        ep_error_log("EP_Notifications: Notificación #$notification_id insertada correctamente para user $user_id.", true);

        // Send external alerts (Teams/Email) based on preferences
        self::send_notification_alerts($user_id, $data);

        return $notification_id;
    }

    /**
     * Send alerts for a notification based on user preferences.
     */
    private static function send_notification_alerts($user_id, $data)
    {
        $user = get_userdata($user_id);
        if (!$user)
            return;

        // 1. Try Teams first if user has O365 and HAS ENABLED it
        $teams_enabled = get_user_meta($user_id, 'ep_notifications_teams', true);
        $ms_user_id = get_user_meta($user_id, 'ep_o365_user_id', true);

        ep_error_log("EP_Notifications: Checking Teams for user $user_id. Enabled: $teams_enabled, MS_ID: $ms_user_id");

        if ($teams_enabled !== '0' && $ms_user_id && class_exists('EP_Auth_O365')) {
            ep_error_log("EP_Notifications: Attempting to send Teams message to $user_id...");
            $sent_teams = EP_Auth_O365::send_teams_message($user_id, $data['title'], $data['message'], $data['link']);
            if (!is_wp_error($sent_teams)) {
                ep_error_log("EP_Notifications: Teams message sent successfully to user $user_id.");
                // If teams is sent, we might still want email if explicitly enabled, 
                // but usually Teams "replaces" the need for email.
                // However, we follow the user's specific toggle for email below.
            } else {
                ep_error_log("EP_Notifications: Teams message FAILED for user $user_id: " . $sent_teams->get_error_message());
            }
        }

        // 2. Email alert if enabled
        $email_enabled = get_user_meta($user_id, 'ep_notifications_email', true);
        if ($email_enabled !== '0') { // Default to enabled
            self::send_notification_email($user_id, $data);
        }
    }

    /**
     * Send email alert (legacy name, now just sends the actual email part)
     */
    private static function send_notification_email($user_id, $data)
    {
        $user = get_userdata($user_id);
        if (!$user)
            return;

        // Email Fallback (only if Teams failed or user is not O365)
        // Check if we should really send an email or if the user/system wants it cleaner
        // For now, we keep email as fallback to avoid missing notifications
        $subject = '[' . get_bloginfo('name') . '] ' . $data['title'];
        $message = "Hola " . $user->display_name . ",\n\n";
        $message .= "Has recibido una nueva notificación en el Portal del Empleado:\n\n";
        $message .= "Asunto: " . $data['title'] . "\n";
        $message .= "Mensaje: " . $data['message'] . "\n\n";
        
        if (!empty($data['link'])) {
            $link = (strpos((string)$data['link'], 'http') === 0) ? $data['link'] : home_url($data['link']);
            $message .= "Puedes verla aquí: " . $link . "\n\n";
        }
        
        $message .= "Saludos,\nEquipo del Portal del Empleado";

        self::$is_sending_notification = true;
        $sent = wp_mail($user->user_email, $subject, $message);
        self::$is_sending_notification = false;

        if (!$sent) {
            ep_error_log("EP_Notifications: wp_mail FAILED for user $user_id (" . $user->user_email . ").");
        }
    }

    /**
     * Get recent notifications for a user
     */
    public static function get_user_notifications($user_id, $limit = 10, $only_unread = false)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_notifications';

        $query = "SELECT * FROM $table_name WHERE user_id = %d";
        if ($only_unread) {
            $query .= " AND is_read = 0";
        }
        $query .= " ORDER BY created_at DESC LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($query, $user_id, $limit));
    }

    /**
     * Count unread notifications
     */
    public static function count_unread($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_notifications';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND is_read = 0",
            $user_id
        ));
    }

    /**
     * AJAX: Get notifications for current user
     */
    public function ajax_get_notifications()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }

        $notifications = self::get_user_notifications($user_id);
        $unread_count = self::count_unread($user_id);

        wp_send_json_success(array(
            'notifications' => $notifications,
            'unread_count' => $unread_count
        ));
    }

    /**
     * AJAX: Mark a specific notification as read
     */
    public function ajax_mark_notification_read()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $notification_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $user_id = get_current_user_id();

        if (!$notification_id || !$user_id) {
            wp_send_json_error('Parámetros inválidos');
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ep_notifications',
            array('is_read' => 1),
            array('id' => $notification_id, 'user_id' => $user_id)
        );

        wp_send_json_success();
    }

    /**
     * AJAX: Mark all as read
     */
    public function ajax_mark_all_read()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ep_notifications',
            array('is_read' => 1),
            array('user_id' => $user_id, 'is_read' => 0)
        );

        wp_send_json_success();
    }

    /**
     * Gatekeeper for ALL emails sent by WordPress.
     * Redirects platform emails to Teams/Portal and blocks standard delivery if appropriate.
     */
    public function gatekeeper_block_and_bridge_emails($args)
    {
        // 1. Global disable check
        if (get_option('ep_global_notifications_disabled', 0)) {
            ep_error_log("EP_Notifications: Email BLOQUEADO globalmente.");
            return false;
        }

        // 2. SIEMPRE PERMITIR emails con adjuntos (documentos firmados, etc.)
        if (!empty($args['attachments'])) {
            return null; // Dejar que wp_mail continúe
        }

        // 3. GUARD ANTI-BUCLE: Si ya estamos enviando una notificación desde el portal,
        //    NO interceptar este email (evita recursión infinita).
        if (self::$is_sending_notification) {
            ep_error_log("EP_Notifications: PERMITIENDO email de alerta del portal (fallback).");
            return null;
        }

        // 4. Bridge de emails externos (de otros plugins) al Portal/Teams
        return $this->bridge_and_consume_external_email($args);
    }

    /**
     * Intercepta emails de otros plugins, crea una notificación en el portal y cancela el email original.
     * IMPORTANTE: No se llama cuando $is_sending_notification está activo (guard anti-bucle).
     */
    private function bridge_and_consume_external_email($mail_data)
    {
        $to = $mail_data['to'];
        if (!is_array($to)) {
            $to = explode(',', (string)$to);
        }

        $consumed = false;

        foreach ($to as $recipient) {
            $email = trim($recipient);
            // Extraer email del formato "Nombre <email@example.com>"
            if (preg_match('/<(.*)>/', $email, $matches)) {
                $email = $matches[1];
            }

            $user = get_user_by('email', $email);
            if ($user) {
                // Activamos la bandera ANTES de llamar a add_notification para
                // evitar que el email de alerta interna vuelva a entrar en el gatekeeper.
                self::$is_sending_notification = true;
                self::add_notification($user->ID, array(
                    'type'    => 'info',
                    'title'   => $mail_data['subject'],
                    'message' => is_string($mail_data['message']) ? wp_strip_all_tags($mail_data['message']) : '',
                    'link'    => '?view=dashboard'
                ));
                self::$is_sending_notification = false;
                $consumed = true;
                ep_error_log("EP_Notifications: Email externo a $email capturado y convertido en notificación de portal.", true);
            }
        }

        // Si se convirtió para al menos un usuario, bloqueamos el email original.
        return $consumed ? false : null;
    }

    /**
     * Legacy bridge (retained as empty for compatibility if needed, though unused now)
     */
    public function bridge_email_to_notification($mail_data) {}

    /**
     * Legacy helper
     */
    public function maybe_block_all_emails($args) { return $args; }
}
