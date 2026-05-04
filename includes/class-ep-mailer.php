<?php
defined('ABSPATH') || exit;

/**
 * EP_Mailer
 * 
 * Intercepts wp_mail and sends emails via Microsoft Graph API.
 */
class EP_Mailer {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Intercept WordPress mail
        add_action('phpmailer_init', array($this, 'intercept_phpmailer'));
    }

    /**
     * Intercepts PHPMailer to reroute email via Graph API.
     */
    public function intercept_phpmailer($phpmailer) {
        $active = ep_get_option('ep_o365_smtp_active');
        if ($active !== '1') return;

        // Determine sender user ID
        $sender_user_id = ep_get_option('ep_o365_system_sender_id');
        
        // If not set, try to find an administrator who is connected to O365
        if (!$sender_user_id) {
            $admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));
            foreach ($admins as $admin_id) {
                $token = get_user_meta($admin_id, 'ep_o365_access_token', true);
                if ($token) {
                    $sender_user_id = $admin_id;
                    break;
                }
            }
        }

        if (!$sender_user_id) {
            ep_error_log("EP Mailer ERROR: No se puede enviar correo vía O365. No hay 'System Sender' configurado o conectado.");
            return;
        }

        // We extend PHPMailer to override the send() method
        // or we simply prevent PHPMailer from sending and do it ourselves
        // Actually, the cleanest way in WP without a custom PHPMailer class is to hook into 'wp_mail' 
        // OR better, we use the phpmailer_init to stop it and call Graph.
        
        // But PHPMailer doesn't have an easy "cancel" flag that doesn't trigger an error in wp_mail.
        // A better approach is to use the 'pre_wp_mail' filter.
    }

    /**
     * Better approach: use pre_wp_mail filter.
     */
    public function init_filters() {
        add_filter('pre_wp_mail', array($this, 'handle_pre_wp_mail'), 10, 2);
    }

    public function handle_pre_wp_mail($return, $args) {
        $active = ep_get_option('ep_o365_smtp_active');
        ep_error_log("EP_Mailer: [DEBUG] pre_wp_mail interceptado. Activo: $active, To: " . (is_array($args['to']) ? implode(',', $args['to']) : $args['to']));
        if ($active !== '1') return $return;

        $sender_user_id = ep_get_option('ep_o365_system_sender_id');
        
        if (!$sender_user_id) {
            // Fallback to searching connected admin
            $admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));
            foreach ($admins as $admin_id) {
                if (get_user_meta($admin_id, 'ep_o365_access_token', true)) {
                    $sender_user_id = $admin_id;
                    break;
                }
            }
        }

        if (!$sender_user_id) {
            ep_error_log("EP Mailer ERROR: O365 SMTP activo pero no hay remitente conectado.");
            return $return; 
        }

        $to      = $args['to'];
        $subject = $args['subject'];
        $message = $args['message'];
        $headers = $args['headers'];
        $attachments = $args['attachments'] ?? [];
        
        // Convert to string if array
        if (is_array($to)) $to = implode(',', $to);

        $is_html = true;
        // Simple check for HTML in message
        if (strpos($message, '<body') === false && strpos($message, '<p') === false && strpos($message, '<br') === false) {
            $is_html = false;
        }

        $service   = EP_Graph_Service::get_instance();
        $from_alias = ep_get_option('ep_o365_smtp_custom_from');
        $result    = $service->send_mail($sender_user_id, $to, $subject, $message, $is_html, $from_alias, $attachments);

        if (is_wp_error($result)) {
            ep_error_log("EP Mailer FAIL: " . $result->get_error_message());
            return $return; // Let standard wp_mail try if Graph fails? 
            // Or return true to say we handled it? 
            // Better return true to avoid double send if it partially worked, 
            // but return false if it definitely failed.
        }

        ep_error_log("EP Mailer SUCCESS: Mail '$subject' enviado a '$to' vía O365 (Sender ID: $sender_user_id)");
        
        return true; // We handled it.
    }
}
