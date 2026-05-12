<?php

defined('ABSPATH') || exit;

class EP_App_Stats implements EP_App_Interface
{
    public function __construct()
    {
        add_action('wp_ajax_ep_stats_export', array($this, 'handle_ajax'));
        add_action('wp_ajax_ep_stats_export_connections', array($this, 'handle_export_connections_ajax'));
        add_action('wp_ajax_ep_stats_get_connections', array($this, 'handle_get_connections_ajax'));
        add_action('wp_ajax_ep_stats_get_m365_activity', array($this, 'handle_get_m365_activity_ajax'));
        add_action('wp_ajax_ep_stats_get_users_summary', array($this, 'handle_get_users_summary_ajax'));
        add_action('wp_ajax_ep_stats_export_users', array($this, 'handle_export_users_ajax'));
        add_action('wp_ajax_ep_stats_sync_m365', array($this, 'handle_sync_m365_ajax'));
        
        // --- IA Bot Integration ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_stats', array($this, 'responder_intent_bot'), 10, 5);
    }

    /**
     * Check if the current user has write access to stats.
     * Administrators, Dirección and RRHH roles have write access.
     */
    private function has_write_access()
    {
        global $ep_app_manager;
        $permission = $ep_app_manager->get_user_permission('stats');

        if ($permission === 'write') {
            return true;
        }

        // Additional role-based check for high-level roles if not explicitly set in meta
        $user = wp_get_current_user();
        $allowed_roles = array('administrator', 'direccion', 'rrhh');
        foreach ($allowed_roles as $role) {
            if (in_array($role, (array) $user->roles)) {
                return true;
            }
        }

        return false;
    }

    public function get_id()
    {
        return 'stats';
    }

    public function get_name()
    {
        return 'Estadísticas';
    }

    public function get_icon()
    {
        return 'fas fa-chart-line';
    }

    public function get_menu_label()
    {
        return 'Estadísticas';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=stats'">
            <div class="app-icon-container color-blue">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Estadísticas</h3>
            <p>Auditoría y Uso</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        global $ep_app_manager;

        // Enqueue Chart.js for this view
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', true);

        $filters = [
            'app_id' => isset($_GET['app_id']) ? sanitize_text_field($_GET['app_id']) : '',
            'user_id' => isset($_GET['user_id']) ? intval($_GET['user_id']) : '',
            'event_type' => isset($_GET['event_type']) ? sanitize_text_field($_GET['event_type']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
        ];

        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $events = EP_Stats_DB::get_events($filters, $limit, $offset);
        $summary = EP_Stats_DB::get_stats_summary($filters);

        // Get users for filter
        $users = get_users(['number' => 100, 'fields' => ['ID', 'display_name']]);

        include EP_STATS_PATH . 'views/full-view.php';
    }

    public function handle_ajax()
    {
        if (!isset($_POST['action']) || $_POST['action'] !== 'ep_stats_export') {
            return;
        }

        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        $filters = isset($_POST['filters']) ? $_POST['filters'] : [];
        $events = EP_Stats_DB::get_events($filters, 1000, 0); // Export limit 1000

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=estadisticas_portal.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'Fecha', 'Aplicación', 'Evento', 'Usuario', 'Detalles'));

        foreach ($events as $event) {
            $user = get_userdata($event->user_id);
            $username = $user ? $user->display_name : 'ID: ' . $event->user_id;
            $metadata = maybe_unserialize($event->metadata);

            fputcsv($output, array(
                $event->id,
                $event->event_time,
                self::format_app($event->app_id),
                self::format_event($event->event_type),
                $username,
                strip_tags(self::format_details($metadata, $event->event_type))
            ));
        }
        fclose($output);
        exit;
    }

    public function handle_get_connections_ajax()
    {
        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        $page = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $stats = EP_Stats_DB::get_connection_stats($limit, $offset);

        // Format durations for the frontend
        foreach ($stats as &$s) {
            $s->duration_human = $this->format_seconds($s->duration_seconds);
            $s->login_time_formatted = date_i18n('d/m/Y H:i', strtotime($s->login_time));
            $s->last_activity_formatted = date_i18n('d/m/Y H:i', strtotime($s->last_activity));
        }

        wp_send_json_success($stats);
    }

    public function handle_export_connections_ajax()
    {
        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        $stats = EP_Stats_DB::get_connection_stats(1000, 0); // Export limit 1000

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=conexiones_portal.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'Usuario', 'Inicio de Sesión', 'Última Actividad', 'Duración Total', 'Estado', 'IP', 'User Agent'));

        foreach ($stats as $s) {
            fputcsv($output, array(
                $s->id,
                $s->display_name ?: 'ID: ' . $s->user_id,
                $s->login_time,
                $s->last_activity,
                $this->format_seconds($s->duration_seconds),
                ($s->status === 'active' ? 'Conectado' : 'Finalizada'),
                $s->ip_address,
                $s->user_agent
            ));
        }
        fclose($output);
        exit;
    }

    private function format_seconds($seconds)
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;

        $out = [];
        if ($h > 0)
            $out[] = "{$h}h";
        if ($m > 0 || $h > 0)
            $out[] = "{$m}m";
        $out[] = "{$s}s";

        return implode(' ', $out);
    }

    public static function format_app($app_id)
    {
        $apps = [
            'auth' => 'Autenticación',
            'tickets' => 'Tickets',
            'signature' => 'Firmas',
            'inventory' => 'Inventario',
            'avisos' => 'Avisos',
            'downloads' => 'Descargas',
            'censo' => 'Censo',
            'directory' => 'Directorio',
            'calendar' => 'Agenda',
            'empresas' => 'Empresas',
            'links' => 'Enlaces',
            'buzon' => 'Buzón',
            'gdpr' => 'GDPR',
            'contratos' => 'Contratos',
            'o365' => 'Office 365',
            'system' => 'Sistema',
            'stats' => 'Estadísticas'
        ];
        return isset($apps[$app_id]) ? $apps[$app_id] : ucfirst($app_id);
    }

    public static function format_event($event_type)
    {
        $events = [
            'login' => 'Inicio de Sesión',
            'logout' => 'Cierre de Sesión',
            'ticket_created' => 'Creación de Ticket',
            'ticket_resolved' => 'Ticket Resuelto',
            'document_signed' => 'Documento Firmado',
            'signature_requested' => 'Solicitud de Firma',
            'post_created' => 'Creación de Contenido',
            'post_updated' => 'Actualización de Contenido',
            'post_deleted' => 'Borrado de Contenido',
            'plugin_activated' => 'Plugin Activado',
            'plugin_deactivated' => 'Plugin Desactivado',
            'request_status_change' => 'Cambio de Estado (Solicitud)',
            'inventory_bulk_assign' => 'Asignación Masiva',
            'itinerant_check_out' => 'Salida de Material',
            'itinerant_check_in' => 'Entrada de Material',
            'material_request_created' => 'Nueva Solicitud de Material',
            'security_alert' => 'Alerta de Seguridad',
            'user_import' => 'Importación de Usuarios',
            'survey_completed' => 'Encuesta Completada',
            'vcard_download' => 'Descarga de VCard',
            'censo_export' => 'Exportación de Censo',
            'censo_import' => 'Importación de Censo',
            'censo_enrichment' => 'Enriquecimiento de Censo'
        ];
        return isset($events[$event_type]) ? $events[$event_type] : ucfirst(str_replace('_', ' ', (string) $event_type));
    }

    public static function format_details($metadata, $event_type)
    {
        if (!is_array($metadata))
            return $metadata;

        switch ($event_type) {
            case 'login':
                return "Acceso mediante " . (isset($metadata['method']) ? $metadata['method'] : 'estándar');
            case 'ticket_created':
                $t_id = isset($metadata['ticket_id']) ? $metadata['ticket_id'] : 0;
                $title = $t_id ? get_the_title($t_id) : (isset($metadata['subject']) ? $metadata['subject'] : 'Sin título');
                $type = isset($metadata['type']) ? $metadata['type'] : 'General';
                return "Nuevo ticket ($type): <strong>$title</strong>";
            case 'ticket_resolved':
                $t_id = isset($metadata['ticket_id']) ? $metadata['ticket_id'] : 0;
                $title = $t_id ? get_the_title($t_id) : 'Ticket';
                return "Ticket resuelto/cerrado: <strong>$title</strong>";
            case 'document_signed':
                return "Firma de: " . (isset($metadata['filename']) ? $metadata['filename'] : 'Documento');
            case 'signature_requested':
                return "Solicitud enviada para: " . (isset($metadata['filename']) ? $metadata['filename'] : 'Documento');
            case 'post_created':
            case 'post_updated':
            case 'post_deleted':
                $type = isset($metadata['post_type']) ? $metadata['post_type'] : 'elemento';
                $title = isset($metadata['post_title']) ? $metadata['post_title'] : (isset($metadata['post_id']) ? 'ID: ' . $metadata['post_id'] : 'Sin título');

                // Humanize post types
                $type_labels = [
                    'ep_ticket' => 'Ticket',
                    'ep_material_request' => 'Solicitud de Material',
                    'ep_inventory_item' => 'Elemento de Inventario',
                    'fds_documentos' => 'Documento de Firma',
                    'ep_announcement' => 'Aviso',
                    'ep_aviso' => 'Aviso',
                    'ep_document' => 'Documento'
                ];
                if (isset($type_labels[$type]))
                    $type = $type_labels[$type];

                $action = ($event_type === 'post_created') ? 'Creado' : (($event_type === 'post_updated') ? 'Actualizado' : 'Borrado');
                return "$action $type: <strong>$title</strong>";
            case 'request_status_change':
                $status = isset($metadata['status']) ? $metadata['status'] : 'desconocido';
                $status_labels = [
                    'pending' => 'Pendiente',
                    'approved' => 'Aprobada',
                    'rejected' => 'Rechazada',
                    'delivered' => 'Entregada'
                ];
                $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
                return "Solicitud de material marcada como: <strong>$label</strong>";
            case 'inventory_bulk_assign':
                $count = isset($metadata['count']) ? $metadata['count'] : 0;
                $u_id = isset($metadata['user_id']) ? $metadata['user_id'] : 0;
                $user = get_userdata($u_id);
                $name = $user ? $user->display_name : 'Usuario';
                return "Asignación masiva de $count elementos a: <strong>$name</strong>";
            case 'itinerant_check_out':
                $item = isset($metadata['item_title']) ? $metadata['item_title'] : 'Material';
                $u_id = isset($metadata['user_id']) ? $metadata['user_id'] : 0;
                $user = get_userdata($u_id);
                $name = $user ? $user->display_name : 'Usuario';
                return "Salida de material para uso itinerante: <strong>$item</strong> (Prestado a $name)";
            case 'itinerant_check_in':
                $item = isset($metadata['item_title']) ? $metadata['item_title'] : 'Material';
                return "Entrada (devolución) de material itinerante: <strong>$item</strong>";
            case 'material_request_created':
                return "Usuario ha solicitado material nuevo.";
            case 'security_alert':
                $action = isset($metadata['action']) ? $metadata['action'] : 'desconocida';
                $detail = isset($metadata['detail']) ? $metadata['detail'] : 'Sin detalles';
                $severity = isset($metadata['severity']) ? $metadata['severity'] : 'low';
                $color = ($severity === 'high') ? 'red' : 'orange';
                return "<span style='color:$color; font-weight:bold;'><i class='fas fa-shield-alt'></i> Acción: $action</span> - $detail";
            case 'vcard_download':
                $target_id = isset($metadata['target_user_id']) ? $metadata['target_user_id'] : 0;
                $target_user = get_userdata($target_id);
                $target_name = $target_user ? $target_user->display_name : 'Usuario';
                return "Descargada tarjeta de contacto de: <strong>$target_name</strong>";
            case 'censo_export':
                $count = isset($metadata['count']) ? $metadata['count'] : 'varios';
                return "Exportación del censo realizada ($count registros).";
            case 'censo_import':
                $filename = isset($metadata['filename']) ? $metadata['filename'] : 'archivo';
                return "Importación de censo desde: <strong>$filename</strong>";
            case 'censo_enrichment':
                $count = isset($metadata['count']) ? $metadata['count'] : 0;
                return "Enriquecimiento IA de $count registros en el censo.";
            default:
                $out = '';
                foreach ($metadata as $key => $val) {
                    $out .= "<strong>$key:</strong> $val ";
                }
                return $out;
        }
    }

    /**
     * AJAX: Get M365 Activity Insights for a user
     */
    public function handle_get_m365_activity_ajax()
    {
        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $period = isset($_POST['period']) ? intval($_POST['period']) : 30; // Default 30 days

        if (!$user_id) {
            wp_send_json_error('Usuario no especificado.');
        }

        if (!class_exists('EP_Auth_O365')) {
            wp_send_json_error('Módulo O365 no disponible.');
        }

        $auth = EP_Auth_O365::get_instance();
        $insights = $auth->get_activity_insights($user_id, $period);

        if (is_wp_error($insights)) {
            wp_send_json_error($insights->get_error_message());
        }

        wp_send_json_success($insights);
    }


    /**
     * AJAX: Get User Summary Stats
     */
    public function handle_get_users_summary_ajax()
    {
        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        $page = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $period = isset($_POST['period']) ? intval($_POST['period']) : 30;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $stats = EP_Stats_DB::get_users_summary($limit, $offset, $period);

        // For periods > 1 day, enrich with M365 Reports data
        $m365_data = array();
        if ($period > 1) {
            $m365_data = EP_Stats_DB::get_m365_activity_summary($period);
        }

        // Format
        foreach ($stats as &$s) {
            $s->duration_human = $this->format_seconds($s->total_duration);
            $s->teams_human = $this->format_seconds($s->teams_seconds ?? 0);

            // Merge M365 activity data if available
            $user_data = get_userdata($s->user_id);
            $user_email = $user_data ? strtolower($user_data->user_email) : '';
            if (!empty($user_email) && isset($m365_data[$user_email])) {
                $m365 = $m365_data[$user_email];
                $s->m365_chats = intval($m365->team_chat_count) + intval($m365->private_chat_count);
                $s->m365_calls = intval($m365->call_count);
                $s->m365_meetings = intval($m365->meeting_count);
                $s->m365_last_activity = $m365->last_activity_date;
                $s->m365_synced = $m365->synced_at;
            } else {
                $s->m365_chats = null;
                $s->m365_calls = null;
                $s->m365_meetings = null;
                $s->m365_last_activity = null;
                $s->m365_synced = null;
            }
        }

        wp_send_json_success($stats);
    }

    /**
     * AJAX: Export Users Summary
     */
    public function handle_export_users_ajax()
    {
        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        $period = isset($_POST['period']) ? intval($_POST['period']) : 30;

        // Use a large limit to export all users realistically needed
        $stats = EP_Stats_DB::get_users_summary(2000, 0, $period);

        // For periods > 1 day, enrich with M365 Reports data
        $m365_data = array();
        if ($period > 1) {
            $m365_data = EP_Stats_DB::get_m365_activity_summary($period);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=resumen_usuarios_portal.csv');
        $output = fopen('php://output', 'w');

        fputcsv($output, array('Empleado', 'Tiempo Portal', 'Tiempo Teams', 'Mensajes M365', 'Llamadas M365', 'Reuniones M365', 'Tickets', 'Firmas', 'Inventario', 'Censo', 'Descargas', 'Directorio'));

        foreach ($stats as $s) {
            $user_data = get_userdata($s->user_id);
            $user_email = $user_data ? strtolower($user_data->user_email) : '';

            $chats = 0;
            $calls = 0;
            $meetings = 0;

            if (!empty($user_email) && isset($m365_data[$user_email])) {
                $m365 = $m365_data[$user_email];
                $chats = intval($m365->team_chat_count) + intval($m365->private_chat_count);
                $calls = intval($m365->call_count);
                $meetings = intval($m365->meeting_count);
            }

            fputcsv($output, array(
                $s->display_name ?: 'ID: ' . $s->user_id,
                $this->format_seconds($s->total_duration),
                $this->format_seconds($s->teams_seconds ?? 0),
                $chats,
                $calls,
                $meetings,
                $s->tickets_count,
                $s->signatures_count,
                $s->inventory_count,
                $s->censo_count,
                $s->downloads_count,
                $s->directory_count
            ));
        }
        fclose($output);
        exit;
    }

    /**
     * AJAX: Manually trigger M365 reports sync
     */
    public function handle_sync_m365_ajax()
    {
        check_ajax_referer('ep_stats_nonce', 'security');

        if (!$this->has_write_access()) {
            wp_send_json_error('No tienes permisos.');
        }

        // Run the sync (Application Level)
        EP_Stats_DB::sync_m365_reports();

        wp_send_json_success('Sincronización completada.');
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intent_bot($intents)
    {
        $intents['STATS'] = "El usuario pregunta por estadísticas de uso del portal, horas dedicadas o uso del bot. Ej: 'estadísticas de hoy', 'muéstrame las estadísticas'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        return $this->tarjeta_stats($user_id, $texto, $bot_instance);
    }

    private function tarjeta_stats(int $user_id, string $texto, $bot_instance): array
    {
        if (!$this->has_write_access()) {
             return $bot_instance->tarjeta_simple('🚫 Acceso Denegado', "No tienes permisos para ver estadísticas del sistema.", '');
        }
        
        if (!class_exists('EP_Stats_DB')) {
             return $bot_instance->tarjeta_simple('📊 Estadísticas', "El módulo de estadísticas no está activo.", '');
        }

        $days = 30;
        $label = "los últimos 30 días";
        if (mb_strpos($texto, 'hoy') !== false) {
             $days = 1;
             $label = "hoy";
        } elseif (mb_strpos($texto, 'semana') !== false) {
             $days = 7;
             $label = "los últimos 7 días";
        }

        $summary = EP_Stats_DB::get_stats_summary($days);
        $top_apps = $summary['top_apps'] ?? [];
        
        $facts = [];
        foreach ($top_apps as $app) {
            $facts[] = ['title' => "App: " . strtoupper($app->app_id), 'value' => (string)$app->count . " eventos"];
        }
        
        if (empty($facts)) {
             return $bot_instance->tarjeta_simple("📊 Estadísticas de $label", "No hay datos de actividad registrados en este periodo.", '');
        }

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "📊 Resumen de Actividad ($label)", 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'TextBlock', 'text' => 'Eventos registrados en el portal:', 'isSubtle' => true],
            ['type' => 'FactSet', 'facts' => $facts],
        ], [['type' => 'Action.OpenUrl', 'title' => '📈 Ver Panel Estadísticas', 'url' => home_url('/?view=stats')]]);
    }
}
