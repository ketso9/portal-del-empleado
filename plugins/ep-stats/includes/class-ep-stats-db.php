<?php

defined('ABSPATH') || exit;

class EP_Stats_DB
{

    private static $table_name;

    public static function init()
    {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'ep_stats_events';

        // Only run on activation or check periodically? 
        if (get_option('ep_stats_db_version') !== '1.2.0') {
            self::create_table();
        }
    }

    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_events';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            app_id varchar(50) NOT NULL,
            event_type varchar(50) NOT NULL,
            user_id bigint(20) NOT NULL,
            metadata longtext,
            PRIMARY KEY  (id),
            KEY app_id (app_id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY event_time (event_time)
        ) $charset_collate;";

        $sessions_table = $wpdb->prefix . 'ep_stats_sessions';
        $sql .= "CREATE TABLE $sessions_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            login_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_activity datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            logout_time datetime DEFAULT NULL,
            duration_seconds int(11) DEFAULT 0 NOT NULL,
            teams_available_seconds int(11) DEFAULT 0 NOT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            ip_address varchar(45),
            user_agent text,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY login_time (login_time),
            KEY status (status)
        ) $charset_collate;";

        // M365 Activity Reports cache table
        $m365_table = $wpdb->prefix . 'ep_stats_m365_activity';
        $sql .= "CREATE TABLE $m365_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_email varchar(255) NOT NULL,
            report_period varchar(10) NOT NULL DEFAULT 'D30',
            team_chat_count int(11) DEFAULT 0,
            private_chat_count int(11) DEFAULT 0,
            call_count int(11) DEFAULT 0,
            meeting_count int(11) DEFAULT 0,
            last_activity_date date DEFAULT NULL,
            synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_period (user_email, report_period),
            KEY user_email (user_email)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Ensure teams_available_seconds column exists (upgrade from 1.1.0)
        $col_exists = $wpdb->get_results("SHOW COLUMNS FROM $sessions_table LIKE 'teams_available_seconds'");
        if (empty($col_exists)) {
            $wpdb->query("ALTER TABLE $sessions_table ADD COLUMN teams_available_seconds int(11) DEFAULT 0 NOT NULL AFTER duration_seconds");
        }

        update_option('ep_stats_db_version', '1.3.0');
    }

    public static function log_event($app_id, $event_type, $user_id, $metadata = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_events';

        $wpdb->insert(
            $table_name,
            array(
                'app_id' => $app_id,
                'event_type' => $event_type,
                'user_id' => $user_id,
                'metadata' => maybe_serialize($metadata),
            ),
            array('%s', '%s', '%d', '%s')
        );
    }

    public static function get_events($filters = [], $limit = 100, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_events';

        $where = "WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $where .= " AND app_id = %s";
            $params[] = $filters['app_id'];
        }

        if (!empty($filters['user_id'])) {
            $where .= " AND user_id = %d";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['event_type'])) {
            $where .= " AND event_type = %s";
            $params[] = $filters['event_type'];
        }

        if (!empty($filters['date_from'])) {
            $where .= " AND event_time >= %s";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND event_time <= %s";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql = "SELECT * FROM $table_name $where ORDER BY event_time DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public static function get_stats_summary($filters = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_events';

        $days = 30;
        $app_id = null;
        $user_id = null;

        if (is_numeric($filters)) {
            $days = intval($filters);
        } elseif (is_array($filters)) {
            $days = isset($filters['days']) ? intval($filters['days']) : 30;
            $app_id = isset($filters['app_id']) ? $filters['app_id'] : null;
            $user_id = isset($filters['user_id']) ? $filters['user_id'] : null;
        }

        $where_base = "1=1";
        $params_base = [];

        if (!empty($filters['date_from'])) {
            $where_base .= " AND event_time >= %s";
            $params_base[] = $filters['date_from'] . ' 00:00:00';
        } elseif ($days) {
            $where_base .= " AND event_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params_base[] = $days;
        }

        if (!empty($filters['date_to'])) {
            $where_base .= " AND event_time <= %s";
            $params_base[] = $filters['date_to'] . ' 23:59:59';
        }

        if ($app_id) {
            $where_base .= " AND app_id = %s";
            $params_base[] = $app_id;
        }
        if ($user_id) {
            $where_base .= " AND user_id = %d";
            $params_base[] = $user_id;
        }

        // Basic stats for charts
        // Events by day (Last X days)
        $raw_events = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(event_time) as date, COUNT(*) as count 
             FROM $table_name 
             WHERE $where_base
             GROUP BY DATE(event_time) 
             ORDER BY date ASC", 
            ...$params_base
        ));

        $events_per_day = [];
        
        // Define range for filling gaps
        if (!empty($filters['date_from'])) {
            $current_date = new DateTime($filters['date_from']);
        } else {
            $current_date = new DateTime("- " . ($days - 1) . " days");
        }

        if (!empty($filters['date_to'])) {
            $end_date = new DateTime($filters['date_to']);
        } else {
            $end_date = new DateTime('now');
        }

        // Limit range to prevent performance issues (max 180 days for charts)
        $diff = $current_date->diff($end_date)->days;
        if ($diff > 180) {
            $current_date = clone $end_date;
            $current_date->modify('-180 days');
        }

        // Map raw results to an associative array for easy lookup
        $raw_map = [];
        foreach ($raw_events as $row) {
            $raw_map[$row->date] = intval($row->count);
        }

        // Fill gaps with 0s
        while ($current_date <= $end_date) {
            $date_str = $current_date->format('Y-m-d');
            $events_per_day[] = (object) [
                'date' => $date_str,
                'count' => isset($raw_map[$date_str]) ? $raw_map[$date_str] : 0
            ];
            $current_date->modify('+1 day');
        }

        // Top Apps
        $top_apps = $wpdb->get_results($wpdb->prepare(
            "SELECT app_id, COUNT(*) as count 
             FROM $table_name 
             WHERE $where_base
             GROUP BY app_id 
             ORDER BY count DESC 
             LIMIT 15", 
            ...$params_base
        ));

        // Top Users
        $top_users = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, COUNT(*) as count 
             FROM $table_name 
             WHERE $where_base
             GROUP BY user_id 
             ORDER BY count DESC 
             LIMIT 5", 
            ...$params_base
        ));

        return [
            'events_per_day' => $events_per_day,
            'top_apps' => $top_apps,
            'top_users' => $top_users
        ];
    }

    public static function cleanup_stale_sessions()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name 
             SET status = 'closed',
                 logout_time = last_activity,
                 duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, login_time, last_activity))
             WHERE status = 'active' 
             AND last_activity < DATE_SUB(%s, INTERVAL 20 MINUTE)",
            current_time('mysql')
        ));
    }

    public static function get_executive_kpis($filters = [])
    {
        global $wpdb;
        self::cleanup_stale_sessions();

        $events_table = $wpdb->prefix . 'ep_stats_events';
        $sessions_table = $wpdb->prefix . 'ep_stats_sessions';

        $days = 30;
        if (isset($filters['days']) && is_numeric($filters['days'])) {
            $days = intval($filters['days']);
        }

        $where_events = "1=1";
        $params_events = [];
        $where_sessions = "1=1";
        $params_sessions = [];

        if (!empty($filters['date_from'])) {
            $where_events .= " AND event_time >= %s";
            $params_events[] = $filters['date_from'] . ' 00:00:00';
            $where_sessions .= " AND login_time >= %s";
            $params_sessions[] = $filters['date_from'] . ' 00:00:00';
        } elseif ($days) {
            $where_events .= " AND event_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params_events[] = $days;
            $where_sessions .= " AND login_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params_sessions[] = $days;
        }

        if (!empty($filters['date_to'])) {
            $where_events .= " AND event_time <= %s";
            $params_events[] = $filters['date_to'] . ' 23:59:59';
            $where_sessions .= " AND login_time <= %s";
            $params_sessions[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['user_id'])) {
            $where_events .= " AND user_id = %d";
            $params_events[] = intval($filters['user_id']);
            $where_sessions .= " AND user_id = %d";
            $params_sessions[] = intval($filters['user_id']);
        }

        if (!empty($filters['department'])) {
            $dept = sanitize_text_field($filters['department']);
            $dept_user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ep_department' AND meta_value = %s",
                $dept
            ));
            if (empty($dept_user_ids)) {
                $dept_user_ids = [0];
            }
            $id_placeholders = implode(',', array_map('intval', $dept_user_ids));
            $where_events .= " AND user_id IN ($id_placeholders)";
            $where_sessions .= " AND user_id IN ($id_placeholders)";
        }

        // 1. Unique Active Users
        $active_users = $wpdb->get_var(empty($params_events) ? "SELECT COUNT(DISTINCT user_id) FROM $events_table WHERE $where_events" : $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $events_table WHERE $where_events", ...$params_events));

        // 2. Total Portal Hours
        $total_seconds = $wpdb->get_var(empty($params_sessions) ? "SELECT COALESCE(SUM(duration_seconds), 0) FROM $sessions_table WHERE $where_sessions" : $wpdb->prepare("SELECT COALESCE(SUM(duration_seconds), 0) FROM $sessions_table WHERE $where_sessions", ...$params_sessions));
        $total_hours = round(intval($total_seconds) / 3600, 1);

        // 3. Signed Documents Count
        $signed_docs = $wpdb->get_var(empty($params_events) ? "SELECT COUNT(*) FROM $events_table WHERE $where_events AND app_id = 'signature' AND event_type = 'document_signed'" : $wpdb->prepare("SELECT COUNT(*) FROM $events_table WHERE $where_events AND app_id = 'signature' AND event_type = 'document_signed'", ...$params_events));

        // 4. Resolved Tickets Count
        $resolved_tickets = $wpdb->get_var(empty($params_events) ? "SELECT COUNT(*) FROM $events_table WHERE $where_events AND app_id = 'tickets' AND (event_type = 'ticket_resolved' OR event_type = 'ticket_created')" : $wpdb->prepare("SELECT COUNT(*) FROM $events_table WHERE $where_events AND app_id = 'tickets' AND (event_type = 'ticket_resolved' OR event_type = 'ticket_created')", ...$params_events));

        // 5. Security Alerts Count
        $security_alerts = $wpdb->get_var(empty($params_events) ? "SELECT COUNT(*) FROM $events_table WHERE $where_events AND event_type = 'security_alert'" : $wpdb->prepare("SELECT COUNT(*) FROM $events_table WHERE $where_events AND event_type = 'security_alert'", ...$params_events));

        // 6. Total Events & Growth Percentage vs Previous Period
        $total_events = $wpdb->get_var(empty($params_events) ? "SELECT COUNT(*) FROM $events_table WHERE $where_events" : $wpdb->prepare("SELECT COUNT(*) FROM $events_table WHERE $where_events", ...$params_events));

        $prev_days = $days ?: 30;
        $prev_events = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events_table WHERE event_time >= DATE_SUB(DATE_SUB(NOW(), INTERVAL %d DAY), INTERVAL %d DAY) AND event_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $prev_days, $prev_days, $prev_days
        ));

        $diff = intval($total_events) - intval($prev_events);
        $growth_pct = ($prev_events > 0) ? round(($diff / $prev_events) * 100, 1) : ($total_events > 0 ? 100 : 0);

        return [
            'active_users'     => intval($active_users),
            'total_hours'      => $total_hours,
            'signed_docs'      => intval($signed_docs),
            'resolved_tickets' => intval($resolved_tickets),
            'security_alerts'  => intval($security_alerts),
            'total_events'     => intval($total_events),
            'growth_pct'       => $growth_pct
        ];
    }

    public static function get_hourly_distribution($filters = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_events';

        $days = isset($filters['days']) ? intval($filters['days']) : 30;
        $where_base = "1=1";
        $params = [];

        if (!empty($filters['date_from'])) {
            $where_base .= " AND event_time >= %s";
            $params[] = $filters['date_from'] . ' 00:00:00';
        } elseif ($days) {
            $where_base .= " AND event_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params[] = $days;
        }

        if (!empty($filters['date_to'])) {
            $where_base .= " AND event_time <= %s";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['app_id'])) {
            $where_base .= " AND app_id = %s";
            $params[] = $filters['app_id'];
        }

        if (!empty($filters['user_id'])) {
            $where_base .= " AND user_id = %d";
            $params[] = intval($filters['user_id']);
        }

        $raw = $wpdb->get_results(empty($params) ? "SELECT HOUR(event_time) as hour, COUNT(*) as count FROM $table_name WHERE $where_base GROUP BY HOUR(event_time) ORDER BY hour ASC" : $wpdb->prepare(
            "SELECT HOUR(event_time) as hour, COUNT(*) as count 
             FROM $table_name 
             WHERE $where_base
             GROUP BY HOUR(event_time)
             ORDER BY hour ASC",
            ...$params
        ));

        $hourly = array_fill(0, 24, 0);
        foreach ($raw as $row) {
            $hourly[intval($row->hour)] = intval($row->count);
        }

        return $hourly;
    }

    public static function start_session($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';

        // Close any abandoned sessions for this user, calculating their real duration
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name 
             SET status = 'closed',
                 logout_time = last_activity,
                 duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, login_time, last_activity))
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ));

        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'status' => 'active',
                'login_time' => current_time('mysql'),
                'last_activity' => current_time('mysql'),
                'teams_available_seconds' => 0
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        return $wpdb->insert_id;
    }

    public static function update_session_activity($user_id, $teams_seconds = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';

        // Get the most recent active session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT id, last_activity, teams_available_seconds FROM $table_name 
             WHERE user_id = %d AND status = 'active' 
             ORDER BY login_time DESC LIMIT 1",
            $user_id
        ));

        if (!$session) {
            // If it's a Teams update but no session exists, start a "passive" one
            if ($teams_seconds > 0) {
                self::start_session($user_id);
            }
            return;
        }

        // Use local WP time for all logic to avoid timezone mismatches
        $last_activity = strtotime($session->last_activity);
        $now = strtotime(current_time('mysql'));
        $diff = $now - $last_activity;

        // Guard: Only increment if at least 270 seconds (4.5 min) have passed
        // OR if it's the first Teams update in a long time
        $should_increment = ($diff >= 270);

        if ($should_increment) {
            $portal_increment = ($teams_seconds == 0) ? 300 : 0;
            $teams_increment = ($teams_seconds > 0) ? 300 : 0;

            $wpdb->query($wpdb->prepare(
                "UPDATE $table_name 
                 SET last_activity = %s,
                     duration_seconds = COALESCE(duration_seconds, 0) + %d,
                     teams_available_seconds = COALESCE(teams_available_seconds, 0) + %d
                 WHERE id = %d",
                current_time('mysql'),
                $portal_increment,
                $teams_increment,
                $session->id
            ));
        } else {
            // Just update activity timestamp to keep session alive
            $wpdb->update(
                $table_name,
                array('last_activity' => current_time('mysql')),
                array('id' => $session->id),
                array('%s'),
                array('%d')
            );
        }
    }

    /**
     * Server-side Teams presence check for ALL active sessions.
     * Also auto-closes stale sessions (no heartbeat for 30+ minutes).
     * Called by WP-Cron every 5 minutes. Does not depend on the user having the portal open.
     */
    public static function update_teams_for_active_sessions()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';

        // STEP 1: Auto-close stale sessions (no heartbeat activity for 30+ minutes)
        // This fixes split-shift scenarios: morning session gets closed during lunch break
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name 
             SET status = 'closed',
                 logout_time = last_activity,
                 duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, login_time, last_activity))
             WHERE status = 'active' 
             AND last_activity < DATE_SUB(%s, INTERVAL 30 MINUTE)",
            current_time('mysql')
        ));

        // STEP 2: Get remaining active sessions (truly active users)
        $active_sessions = $wpdb->get_results("SELECT id, user_id FROM $table_name WHERE status = 'active'");

        if (empty($active_sessions))
            return;

        // Check if EP_Graph_Service is available
        if (!class_exists('EP_Graph_Service'))
            return;

        $graph_service = EP_Graph_Service::get_instance();
        $online_statuses = ['Available', 'Busy', 'DoNotDisturb', 'BeRightBack', 'InACall', 'InAConferenceCall', 'InAMeeting', 'Presenting'];

        foreach ($active_sessions as $session) {
            $presence = $graph_service->get_user_presence($session->user_id);

            if (is_wp_error($presence))
                continue;

            $availability = $presence['availability'] ?? '';
            if (in_array($availability, $online_statuses)) {
                // User is active on Teams — add 5 minutes and KEEP SESSION ALIVE
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_name 
                     SET teams_available_seconds = COALESCE(teams_available_seconds, 0) + 300,
                         last_activity = %s
                     WHERE id = %d",
                    current_time('mysql'),
                    $session->id
                ));
            }
        }
    }

    public static function close_session($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';

        $time_now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name 
             SET last_activity = %s,
                 logout_time = %s,
                 duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, login_time, %s)),
                 status = 'closed'
             WHERE user_id = %d AND status = 'active'
             ORDER BY login_time DESC LIMIT 1",
            $time_now,
            $time_now,
            $time_now,
            $user_id
        ));
    }

    public static function get_connection_stats($limit = 50, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_stats_sessions';

        $sql = "SELECT s.*, 
                CASE WHEN s.status = 'active' 
                     THEN GREATEST(s.duration_seconds, TIMESTAMPDIFF(SECOND, s.login_time, s.last_activity))
                     ELSE s.duration_seconds 
                END as duration_seconds,
                u.display_name 
                FROM $table_name s
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                ORDER BY s.login_time DESC 
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $limit, $offset));
    }

    public static function get_users_summary($limit = 50, $offset = 0, $days = 30, $search = '', $department = '', $orderby = '', $order = 'DESC')
    {
        global $wpdb;
        self::cleanup_stale_sessions();

        $events_table = $wpdb->prefix . 'ep_stats_events';
        $sessions_table = $wpdb->prefix . 'ep_stats_sessions';

        $days = intval($days);
        if ($days === 1) {
            $date_limit = current_time('Y-m-d') . ' 00:00:00';
        } else {
            $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days", strtotime(current_time('mysql'))));
        }

        $user_where = "WHERE 1=1";
        $user_params = [];

        if (!empty($search)) {
            $user_where .= " AND (u.display_name LIKE %s OR u.user_email LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $user_params[] = $like;
            $user_params[] = $like;
        }

        if (!empty($department)) {
            $user_where .= " AND u.ID IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ep_department' AND meta_value = %s)";
            $user_params[] = $department;
        }

        $sql = "SELECT 
                    u.ID as user_id, 
                    u.display_name,
                    COALESCE(
                        (SELECT SUM(ls.duration_seconds) FROM $sessions_table ls WHERE ls.user_id = u.ID AND ls.login_time >= %s),
                        0
                    ) as total_duration,
                    COALESCE(
                        (SELECT ls.login_time FROM $sessions_table ls WHERE ls.user_id = u.ID ORDER BY ls.login_time DESC LIMIT 1),
                        NULL
                    ) as last_login,
                    COALESCE(
                        (SELECT ls.status FROM $sessions_table ls WHERE ls.user_id = u.ID ORDER BY ls.login_time DESC LIMIT 1),
                        'unknown'
                    ) as session_status,";

        $params = [$date_limit];

        // Check if teams_available_seconds column exists
        $teams_col = $wpdb->get_results("SHOW COLUMNS FROM $sessions_table LIKE 'teams_available_seconds'");
        if (!empty($teams_col)) {
            $sql .= "
                    COALESCE(
                        (SELECT SUM(ls.teams_available_seconds) FROM $sessions_table ls WHERE ls.user_id = u.ID AND ls.login_time >= %s),
                        0
                    ) as teams_seconds,";
            $params[] = $date_limit;
        } else {
            $sql .= "
                    0 as teams_seconds,";
        }

        $sql .= "
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'tickets' AND e.event_time >= %s) as tickets_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'signature' AND e.event_time >= %s) as signatures_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'inventory' AND e.event_time >= %s) as inventory_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'censo' AND e.event_time >= %s) as censo_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'downloads' AND e.event_time >= %s) as downloads_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'directory' AND e.event_time >= %s) as directory_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'empresas' AND e.event_time >= %s) as empresas_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'links' AND e.event_time >= %s) as links_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'buzon' AND e.event_time >= %s) as buzon_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'contratos' AND e.event_time >= %s) as contratos_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'gdpr' AND e.event_time >= %s) as gdpr_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'calendar' AND e.event_time >= %s) as calendar_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'avisos' AND e.event_time >= %s) as avisos_count,
                    (SELECT COUNT(*) FROM $events_table e WHERE e.user_id = u.ID AND e.app_id = 'expenses' AND e.event_time >= %s) as expenses_count
                FROM {$wpdb->users} u
                $user_where";

        $order_dir = (strtoupper($order) === 'ASC') ? 'ASC' : 'DESC';
        switch ($orderby) {
            case 'name':
                $sql .= " ORDER BY u.display_name $order_dir";
                break;
            case 'portal_time':
                $sql .= " ORDER BY total_duration $order_dir";
                break;
            case 'teams':
                $sql .= " ORDER BY teams_seconds $order_dir";
                break;
            default:
                $sql .= " ORDER BY (SELECT ls2.login_time FROM $sessions_table ls2 WHERE ls2.user_id = u.ID ORDER BY ls2.login_time DESC LIMIT 1) $order_dir";
                break;
        }

        $sql .= " LIMIT %d OFFSET %d";

        // Add parameters for the app counts (14 apps total)
        for ($i = 0; $i < 14; $i++) {
            $params[] = $date_limit;
        }

        // Add user where parameters if any
        if (!empty($user_params)) {
            $params = array_merge($params, $user_params);
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Sync M365 Teams Activity Reports into local cache.
     * Called by WP-Cron (daily). Uses Application token.
     * Stores per-user usage counts from Microsoft Graph Reports API.
     */
    public static function sync_m365_reports()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ep_stats_m365_activity';

        // Check the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            ep_error_log('EP Stats: M365 activity table not found. Run create_table() first.');
            return;
        }

        // Sync D7, D30, D90, and D180 reports
        $periods = ['D7', 'D30', 'D90', 'D180'];

        foreach ($periods as $period) {
            $report = EP_Auth_O365::get_teams_activity_report($period);

            if (is_wp_error($report)) {
                ep_error_log('EP Stats: Error obteniendo reporte M365 (' . $period . '): ' . $report->get_error_message());
                continue;
            }

            if (empty($report)) {
                ep_error_log('EP Stats: Reporte M365 vacío para periodo ' . $period);
                continue;
            }

            $now = current_time('mysql');

            // Actualizar synced_at para todos los registros del periodo
            $wpdb->query($wpdb->prepare("UPDATE $table SET synced_at = %s WHERE report_period = %s", $now, $period));

            foreach ($report as $email => $data) {
                // UPSERT: INSERT or UPDATE if user+period already exists
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (user_email, report_period, team_chat_count, private_chat_count, call_count, meeting_count, last_activity_date, synced_at)
                     VALUES (%s, %s, %d, %d, %d, %d, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        team_chat_count = VALUES(team_chat_count),
                        private_chat_count = VALUES(private_chat_count),
                        call_count = VALUES(call_count),
                        meeting_count = VALUES(meeting_count),
                        last_activity_date = VALUES(last_activity_date),
                        synced_at = VALUES(synced_at)",
                    $email,
                    $period,
                    $data['team_chat_count'],
                    $data['private_chat_count'],
                    $data['call_count'],
                    $data['meeting_count'],
                    $data['last_activity_date'] ?: null,
                    $now
                ));
            }

            ep_error_log('EP Stats: Sincronizados ' . count($report) . ' usuarios para M365 periodo ' . $period);
        }
    }

    /**
     * Get M365 activity data for a list of user emails.
     * Returns cached data from the most recent sync.
     * 
     * @param int $days Number of days (maps to report period)
     * @return array Indexed by lowercase email
     */
    public static function get_m365_activity_summary($days = 30)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ep_stats_m365_activity';

        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array();
        }

        // Map days to Graph API period (D7, D30, D90, D180)
        if ($days <= 7) {
            $period = 'D7';
        } elseif ($days <= 30) {
            $period = 'D30';
        } elseif ($days <= 90) {
            $period = 'D90';
        } else {
            $period = 'D180'; // Closest available to 365
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_email, team_chat_count, private_chat_count, call_count, meeting_count, last_activity_date, synced_at
             FROM $table WHERE report_period = %s",
            $period
        ));

        $indexed = array();
        foreach ($results as $row) {
            $indexed[strtolower($row->user_email)] = $row;
        }

        return $indexed;
    }
}
