<?php

defined('ABSPATH') || exit;

/**
 * EP_Dashboard handler for custom WordPress dashboard widgets.
 */
class EP_Dashboard
{
    public function __construct()
    {
        add_action('wp_dashboard_setup', [$this, 'register_widgets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_dashboard_styles']);
    }

    /**
     * Enqueue minimal styles for dashboard widgets
     */
    public function enqueue_dashboard_styles($hook)
    {
        if ('index.php' !== $hook) {
            return;
        }
        wp_add_inline_style('dashboard', "
            .ep-dashboard-widget { padding: 10px 0; }
            .ep-stat-row { display: flex; justify-content: space-between; margin-bottom: 8px; align-items: center; border-bottom: 1px solid #f0f0f1; padding-bottom: 6px; }
            .ep-stat-row:last-child { border-bottom: none; }
            .ep-stat-label { font-weight: 600; color: #50575e; }
            .ep-stat-value { font-weight: 700; font-size: 1.1em; color: #2271b1; }
            .ep-status-pill { padding: 2px 8px; border-radius: 12px; font-size: 10px; text-transform: uppercase; font-weight: bold; }
            .ep-status-ok { background: #dcfce7; color: #166534; }
            .ep-status-warning { background: #fef9c3; color: #854d0e; }
            .ep-status-error { background: #fee2e2; color: #991b1b; }
            .ep-dashboard-footer { margin-top: 10px; border-top: 1px solid #ccd0d4; padding-top: 10px; }
            .ep-activity-icon { color: #8c8f94; margin-right: 8px; }
        ");
    }

    public function register_widgets()
    {
        // Only for Administrators or Managers
        if (!current_user_can('manage_options') && !current_user_can('ep_manage_portal')) {
            return;
        }

        wp_add_dashboard_widget(
            'ep_portal_status_widget',
            '<span class="dashicons dashicons-admin-site-alt"></span> Portal: Clientes y Conexiones',
            [$this, 'render_clients_widget']
        );

        wp_add_dashboard_widget(
            'ep_portal_activity_widget',
            '<span class="dashicons dashicons-groups"></span> Portal: Usuarios Online',
            [$this, 'render_active_users_widget']
        );

        wp_add_dashboard_widget(
            'ep_portal_health_widget',
            '<span class="dashicons dashicons-shield"></span> Portal: Seguridad y Salud',
            [$this, 'render_security_health_widget']
        );

        wp_add_dashboard_widget(
            'ep_portal_summary_widget',
            '<span class="dashicons dashicons-chart-area"></span> Portal: Resumen de Actividad',
            [$this, 'render_activity_summary_widget']
        );
    }

    public function render_clients_widget()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_subscribers';

        // If table doesn't exist, just show message
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo '<p>El sistema de licencias no está activo en este servidor.</p>';
            return;
        }

        $total_clients = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $active_clients = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $errors = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status != 'active'");

        echo '<div class="ep-dashboard-widget">';
        $this->print_stat_row('Total Clientes Registrados', $total_clients);
        $this->print_stat_row('Clientes Activos (24h)', $active_clients);

        $status_class = ($errors > 0) ? 'ep-status-error' : 'ep-status-ok';
        $status_text = ($errors > 0) ? "$errors con problemas" : "Todo correcto";
        $this->print_stat_row('Alertas de Conexión', "<span class='ep-status-pill $status_class'>$status_text</span>");

        echo '<div class="ep-dashboard-footer"><a href="' . admin_url('admin.php?page=employee-portal&tab=subscribers') . '" class="button button-secondary">Gestionar Clientes</a></div>';
        echo '</div>';
    }

    public function render_active_users_widget()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo '<p>El módulo de estadísticas no está activo.</p>';
            return;
        }

        // Active sessions (last 15 minutes)
        $active_now = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'active' AND last_activity >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $today_total = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name WHERE login_time >= CURDATE()");

        $portal_url = get_permalink(get_option('ep_portal_page_id'));
        $stats_url = add_query_arg('view', 'stats', $portal_url);

        echo '<div class="ep-dashboard-widget">';
        $this->print_stat_row('Usuarios naveguando ahora', $active_now, 'dashicons-visibility');
        $this->print_stat_row('Usuarios únicos hoy', $today_total, 'dashicons-admin-users');

        echo '<div class="ep-dashboard-footer"><a href="' . esc_url($stats_url) . '" class="button button-secondary">Ver Estadísticas Detalladas</a></div>';
        echo '</div>';
    }

    public function render_security_health_widget()
    {
        echo '<div class="ep-dashboard-widget">';

        // Check O365
        $client_id = ep_get_option('ep_o365_client_id');
        $o365_status = !empty($client_id) ? 'ep-status-ok' : 'ep-status-error';
        $o365_text = !empty($client_id) ? 'Conectado' : 'Sin Configurar';
        $this->print_stat_row('Microsoft 365 / Graph', "<span class='ep-status-pill $o365_status'>$o365_text</span>");

        // Check HTTPS
        $is_https = is_ssl();
        $https_status = $is_https ? 'ep-status-ok' : 'ep-status-warning';
        $https_text = $is_https ? 'Seguro (HTTPS)' : 'No Seguro (HTTP)';
        $this->print_stat_row('Conexión SSL', "<span class='ep-status-pill $https_status'>$https_text</span>");

        // Check Notifications
        $disabled_globally = get_option('ep_global_notifications_disabled', 0);
        $notif_status = $disabled_globally ? 'ep-status-warning' : 'ep-status-ok';
        $notif_text = $disabled_globally ? 'Desactivadas' : 'Activas';
        $this->print_stat_row('Notificaciones Globales', "<span class='ep-status-pill $notif_status'>$notif_text</span>");

        echo '<div class="ep-dashboard-footer"><a href="' . admin_url('admin.php?page=employee-portal&tab=general') . '" class="button button-secondary">Ajustes del Portal</a></div>';
        echo '</div>';
    }

    public function render_activity_summary_widget()
    {
        global $wpdb;

        // 1. Tickets
        $open_tickets = count(get_posts(['post_type' => 'ep_ticket', 'post_status' => 'publish', 'meta_query' => [['key' => '_ep_ticket_status', 'value' => 'closed', 'compare' => '!=']]]));

        // 2. Firmas (Bandeja de Entrada)
        $signatures_table = $wpdb->prefix . 'fds_documentos';
        $pending_signatures = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$signatures_table'") == $signatures_table) {
            $pending_signatures = $wpdb->get_var("SELECT COUNT(*) FROM $signatures_table WHERE status = 'pendiente'");
        }

        echo '<div class="ep-dashboard-widget">';
        $this->print_stat_row('Tickets abiertos (Total)', $open_tickets, 'dashicons-tickets-alt');
        $this->print_stat_row('Documentos por firmar', $pending_signatures, 'dashicons-edit');

        // Latest Activity Hint
        $last_event = $wpdb->get_row("SELECT event_type, event_time FROM {$wpdb->prefix}ep_stats_events ORDER BY event_time DESC LIMIT 1");
        if ($last_event) {
            $time_diff = human_time_diff(strtotime($last_event->event_time), current_time('timestamp'));
            echo "<p style='font-size: 11px; color: #8c8f94; margin-top: 10px;'><span class='dashicons dashicons-backup' style='font-size: 14px; width: 14px; height: 14px;'></span> Última actividad: hace $time_diff ({$last_event->event_type})</p>";
        }

        echo '</div>';
    }

    private function print_stat_row($label, $value, $icon = null)
    {
        echo '<div class="ep-stat-row">';
        echo '<span class="ep-stat-label">';
        if ($icon) {
            echo "<span class='dashicons $icon ep-activity-icon'></span>";
        }
        echo esc_html($label) . '</span>';
        echo '<span class="ep-stat-value">' . $value . '</span>';
        echo '</div>';
    }
}
