<?php

defined('ABSPATH') || exit;

class EP_Buzon
{
    private static $table_name;

    public function __construct()
    {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'ep_buzon';

        add_action('init', array($this, 'maybe_create_table'));
        add_action('wp_ajax_ep_submit_buzon',   array($this, 'handle_buzon_submission'));
        add_action('wp_ajax_ep_archive_buzon',  array($this, 'handle_archive_message'));
        add_action('wp_ajax_ep_delete_buzon',   array($this, 'handle_delete_message'));

        // Background Notifications hook
        add_action('ep_buzon_notify_managers_bg', array($this, 'process_bg_notifications'), 10, 3);

        // --- Integración con IA Bot ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_buzon', array($this, 'responder_intent_bot'), 10, 5);
    }

    public function registrar_intent_bot($intents)
    {
        $intents['BUZON'] = "El usuario quiere ver mensajes del buzón (suyos o todos si es responsable). Ej: 'mensajes del buzón', 'mis sugerencias'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_buzon';

        // Check if they are admin/HR to see everything
        $user = get_userdata($user_id);
        $is_admin = in_array('administrator', (array)$user->roles) || EP_App_Manager::get_permission('buzon', $user_id) === 'write';

        if ($is_admin) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND archived = 0");
            $recent = $wpdb->get_results("SELECT type, title, created_at FROM $table_name WHERE archived = 0 ORDER BY created_at DESC LIMIT 3");
        } else {
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND status = 'pending' AND archived = 0", $user_id));
            $recent = $wpdb->get_results($wpdb->prepare("SELECT type, title, created_at FROM $table_name WHERE user_id = %d AND archived = 0 ORDER BY created_at DESC LIMIT 3", $user_id));
        }

        if (empty($recent)) {
            $msg = $is_admin ? "No hay mensajes recientes en el Buzón del Empleado." : "No tienes mensajes enviados en el Buzón.";
            return $bot_instance->tarjeta_simple("📬 Buzón del Empleado", $msg, home_url('/?view=buzon'));
        }

        $facts = [];
        foreach ($recent as $msg) {
            $dt = date('d/m', strtotime($msg->created_at));
            $type_translate = [
                'suggestion' => 'Sugerencia',
                'safety' => 'Seguridad',
                'other' => 'Otro'
            ];
            $t = $type_translate[$msg->type] ?? 'Mensaje';
            $facts[] = ['title' => "🗓️ $dt [$t]", 'value' => mb_substr($msg->title, 0, 40)];
        }

        $header_text = $is_admin ? "📬 Tienes **$count** mensajes pendientes de revisar." : "📬 Tienes **$count** ideas/peticiones activas.";

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => 'Buzón del Empleado', 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'TextBlock', 'text' => $header_text, 'wrap' => true],
            ['type' => 'FactSet', 'facts' => $facts]
        ], [['type' => 'Action.OpenUrl', 'title' => 'Abrir Buzón', 'url' => home_url('/?view=buzon')]]);
    }

    public function maybe_create_table()
    {
        // Bump version to 1.1.0 to trigger migration (added 'archived' column)
        if (get_option('ep_buzon_db_version') === '1.1.0') {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . self::$table_name . " (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL DEFAULT 0,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            description text NOT NULL,
            proposal text DEFAULT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            archived tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY archived (archived)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option('ep_buzon_db_version', '1.1.0');
    }

    public function handle_buzon_submission()
    {
        ep_error_log("EP_Buzon: Iniciando recepción de mensaje...");
        check_ajax_referer('ep_buzon_nonce', 'nonce');

        $type        = sanitize_text_field($_POST['buzon_type']);
        $title       = sanitize_text_field($_POST['buzon_title']);
        $description = sanitize_textarea_field($_POST['buzon_description']);
        $proposal    = sanitize_textarea_field($_POST['buzon_proposal']);
        $include_name = isset($_POST['buzon_include_name']) && $_POST['buzon_include_name'] === '1';

        $user_id = $include_name ? get_current_user_id() : 0;

        global $wpdb;
        $result = $wpdb->insert(
            self::$table_name,
            array(
                'user_id'     => $user_id,
                'type'        => $type,
                'title'       => $title,
                'description' => $description,
                'proposal'    => $proposal,
                'status'      => 'pending',
                'archived'    => 0
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        if ($result) {
            $msg_id = $wpdb->insert_id;
            ep_error_log("EP_Buzon: Mensaje guardado con ID: $msg_id");
            
            // Programar notificación en segundo plano
            wp_schedule_single_event(time(), 'ep_buzon_notify_managers_bg', array($msg_id, $type, $title));
            
            wp_send_json_success('Tu mensaje ha sido enviado correctamente.');
        } else {
            ep_error_log("EP_Buzon: ERROR al insertar en la base de datos: " . $wpdb->last_error);
            wp_send_json_error('Hubo un error al enviar el mensaje.');
        }
    }

    public function handle_archive_message()
    {
        check_ajax_referer('ep_buzon_action_nonce', 'nonce');

        if (!current_user_can('administrator') && !current_user_can('ep_hr') && !current_user_can('ep_direction')) {
            wp_send_json_error('Sin permisos.');
        }

        $id = intval($_POST['msg_id']);
        if ($id <= 0) {
            wp_send_json_error('ID inválido.');
        }

        global $wpdb;
        $result = $wpdb->update(
            self::$table_name,
            array('archived' => 1),
            array('id' => $id),
            array('%d'),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success('Mensaje archivado correctamente.');
        } else {
            wp_send_json_error('Error al archivar el mensaje.');
        }
    }

    public function handle_delete_message()
    {
        check_ajax_referer('ep_buzon_action_nonce', 'nonce');

        if (!current_user_can('administrator') && !current_user_can('ep_hr') && !current_user_can('ep_direction')) {
            wp_send_json_error('Sin permisos.');
        }

        $id = intval($_POST['msg_id']);
        if ($id <= 0) {
            wp_send_json_error('ID inválido.');
        }

        // Solo se puede eliminar si está archivado
        global $wpdb;
        $msg = $wpdb->get_row($wpdb->prepare("SELECT archived FROM " . self::$table_name . " WHERE id = %d", $id));
        if (!$msg || $msg->archived != 1) {
            wp_send_json_error('Solo se pueden eliminar mensajes previamente archivados.');
        }

        $result = $wpdb->delete(self::$table_name, array('id' => $id), array('%d'));

        if ($result !== false) {
            wp_send_json_success('Mensaje eliminado permanentemente.');
        } else {
            wp_send_json_error('Error al eliminar el mensaje.');
        }
    }

    /**
     * Procesa las notificaciones en segundo plano para no bloquear al usuario.
     */
    public function process_bg_notifications($id, $type, $title)
    {
        @set_time_limit(0);

        $args = array(
            'role__in' => array('ep_hr', 'ep_direction', 'ep_super_admin', 'administrator'),
            'fields'   => 'ID'
        );
        $recipients = get_users($args);

        if (empty($recipients)) {
            return;
        }

        $type_labels = array(
            'suggestion'   => 'Sugerencia',
            'incident'     => 'Incidencia',
            'complaint'    => 'Queja',
            'recognition'  => 'Reconocimiento',
            'confidencial' => 'Reporte Confidencial',
            'medios'       => 'Necesidad de Medios'
        );

        $label = isset($type_labels[$type]) ? $type_labels[$type] : 'Comunicación';

        if (!class_exists('EP_Notifications')) {
            require_once ABSPATH . 'wp-content/plugins/Portal-empleado-1/includes/class-ep-notifications.php';
        }

        foreach ($recipients as $uid) {
            EP_Notifications::add_notification($uid, array(
                'type'    => 'info',
                'title'   => 'Nuevo mensaje en Buzón: ' . $label,
                'message' => 'Se ha recibido una nueva ' . strtolower($label) . ': "' . $title . '".',
                'link'    => '?view=buzon'
            ));
        }
    }

    public static function get_messages_for_manager($archived = false)
    {
        global $wpdb;
        $table    = $wpdb->prefix . 'ep_buzon';
        $archived_val = $archived ? 1 : 0;
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE archived = %d ORDER BY created_at DESC", $archived_val)
        );
    }
}

new EP_Buzon();
