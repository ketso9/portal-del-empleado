<?php

defined('ABSPATH') || exit;

/**
 * Plugin Name: EP Mini App: Estadísticas
 * Description: Sistema centralizado de estadísticas y trazabilidad para el Portal del Empleado.
 * Version: 1.0.0
 * Author: Jorge Polo
 * Package: enterprise
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('EP_STATS_LOADED')) {
    return;
}
define('EP_STATS_LOADED', true);

// Define paths
define('EP_STATS_PATH', plugin_dir_path(__FILE__));
define('EP_STATS_URL', plugin_dir_url(__FILE__));

// Load Classes
require_once EP_STATS_PATH . 'includes/class-ep-stats-db.php';
require_once EP_STATS_PATH . 'class-ep-stats.php';

// Initialize Database
EP_Stats_DB::init();

/**
 * Hook de activación para asegurar la creación de tablas.
 */
register_activation_hook(__FILE__, array('EP_Stats_DB', 'create_table'));

/**
 * WP-Cron: Teams presence tracking every 5 minutes (server-side).
 * This runs independently of the portal being open in the browser.
 */

// Register custom 5-minute cron interval
add_filter('cron_schedules', function ($schedules) {
    $schedules['ep_five_minutes'] = array(
        'interval' => 300,
        'display' => 'Cada 5 minutos (EP Stats)'
    );
    return $schedules;
});

// Schedule the cron events
add_action('init', function () {
    // 1. Teams presence tracking (every 5 minutes)
    if (!wp_next_scheduled('ep_stats_teams_cron')) {
        wp_schedule_event(time(), 'ep_five_minutes', 'ep_stats_teams_cron');
    }

    // 2. M365 Activity Reports sync (Daily at 06:00)
    if (!wp_next_scheduled('ep_stats_m365_report_sync')) {
        $six_am = strtotime('06:00:00');
        if ($six_am < time()) {
            $six_am = strtotime('tomorrow 06:00:00');
        }
        wp_schedule_event($six_am, 'daily', 'ep_stats_m365_report_sync');
    }
});

// Hook the cron event to the Teams presence checker
add_action('ep_stats_teams_cron', array('EP_Stats_DB', 'update_teams_for_active_sessions'));

// Hook the daily cron to sync M365 reports
add_action('ep_stats_m365_report_sync', array('EP_Stats_DB', 'sync_m365_reports'));

// Clean up on plugin deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('ep_stats_teams_cron');
    wp_clear_scheduled_hook('ep_stats_m365_report_sync');
});

// Register App
add_action('ep_register_apps', function ($manager) {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    $manager->register_app(new EP_App_Stats());
});

/**
 * Global helper function to log events from any app.
 * 
 * @param string $app_id ID of the app generating the event.
 * @param string $event_type Type of event (e.g., 'login', 'ticket_created').
 * @param int $user_id User responsible for the event.
 * @param array $metadata Additional data (optional).
 */
function ep_stats_log($app_id, $event_type, $user_id = null, $metadata = [])
{
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    EP_Stats_DB::log_event($app_id, $event_type, $user_id, $metadata);
}

/**
 * Global Audit Hooks
 */

// Login/Logout
add_action('wp_login', function ($user_login, $user) {
    ep_stats_log('system', 'login', $user->ID, ['user_login' => $user_login, 'method' => 'wp_standard']);
    if (class_exists('EP_Stats_DB')) {
        EP_Stats_DB::start_session($user->ID);
    }
}, 10, 2);

add_action('wp_logout', function () {
    $user_id = get_current_user_id();
    if ($user_id) {
        ep_stats_log('system', 'logout', $user_id);
        if (class_exists('EP_Stats_DB')) {
            EP_Stats_DB::close_session($user_id);
        }
    }
});

/**
 * Session Heartbeat Logic
 */
add_action('wp_ajax_ep_stats_heartbeat', function () {
    check_ajax_referer('ep_stats_nonce', 'nonce');
    $user_id = get_current_user_id();
    if ($user_id && class_exists('EP_Stats_DB')) {
        // Heartbeat only tracks portal activity (session alive + duration)
        // Teams presence is now tracked by server-side WP-Cron independently

        // Check if user has an active session; if not, start one
        // This handles split-shift returns: user comes back after lunch,
        // the cron closed the morning session, so we start a fresh one
        global $wpdb;
        $table = $wpdb->prefix . 'ep_stats_sessions';
        $has_active = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        if (!$has_active) {
            EP_Stats_DB::start_session($user_id);
        }

        EP_Stats_DB::update_session_activity($user_id, 0);
        wp_send_json_success();
    }
    wp_send_json_error();
});

add_action('wp_footer', function () {
    if (is_user_logged_in()) {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('ep_stats_nonce');
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                function startStatsHeartbeat() {
                    // Send an initial heartbeat right away to catch fast navigation
                    sendHeartbeat();
                    // Heartbeat every 5 minutes (using fetch or jQuery)
                    setInterval(sendHeartbeat, 300000);

                    // Also fire on visibility change to catch tab resuming
                    document.addEventListener('visibilitychange', function () {
                        if (document.visibilityState === 'visible') {
                            sendHeartbeat();
                        }
                    });
                }

                function sendHeartbeat() {
                    const data = new URLSearchParams();
                    data.append('action', 'ep_stats_heartbeat');
                    data.append('nonce', '<?php echo esc_js($nonce); ?>');

                    fetch('<?php echo esc_js($ajax_url); ?>', {
                        method: 'POST',
                        body: data
                    }).then(response => {
                        if (!response.ok && response.status === 403) {
                            console.error('Heartbeat 403: Session might have expired.');
                        }
                    }).catch(error => {
                        console.error('Heartbeat failed:', error);
                    });
                }

                startStatsHeartbeat();
            });
        </script>
        <?php
    }
});

// Post creation/modification
add_action('save_post', function ($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if ($post->post_status === 'auto-draft' || $post->post_status === 'inherit')
        return;

    // Detect app by post type
    $app_id = 'system';
    switch ($post->post_type) {
        case 'ep_ticket':
            $app_id = 'tickets';
            break;
        case 'ep_material_request':
        case 'ep_inventory_item':
            $app_id = 'inventory';
            break;
        case 'fds_documentos':
            $app_id = 'signature';
            break;
        case 'ep_aviso':
            $app_id = 'avisos';
            break;
        case 'ep_document':
            $app_id = 'downloads';
            break;
        case 'ep_link':
            $app_id = 'links';
            break;
    }

    $event = $update ? 'post_updated' : 'post_created';
    ep_stats_log($app_id, $event, get_current_user_id(), [
        'post_id' => $post_id,
        'post_type' => $post->post_type,
        'post_title' => $post->post_title
    ]);
}, 10, 3);

// Post deletion
add_action('before_delete_post', function ($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_status === 'auto-draft')
        return;

    $app_id = 'system';
    switch ($post->post_type) {
        case 'ep_ticket':
            $app_id = 'tickets';
            break;
        case 'ep_material_request':
        case 'ep_inventory_item':
            $app_id = 'inventory';
            break;
        case 'ep_aviso':
            $app_id = 'avisos';
            break;
        case 'ep_document':
            $app_id = 'downloads';
            break;
        case 'ep_link':
            $app_id = 'links';
            break;
        case 'fds_documentos':
            $app_id = 'signature';
            break;
    }

    ep_stats_log($app_id, 'post_deleted', get_current_user_id(), [
        'post_id' => $post_id,
        'post_type' => $post->post_type,
        'post_title' => $post->post_title
    ]);
});

// Plugin changes
add_action('activated_plugin', function ($plugin) {
    ep_stats_log('system', 'plugin_activated', get_current_user_id(), ['plugin' => $plugin]);
});

add_action('deactivated_plugin', function ($plugin) {
    ep_stats_log('system', 'plugin_deactivated', get_current_user_id(), ['plugin' => $plugin]);
});
