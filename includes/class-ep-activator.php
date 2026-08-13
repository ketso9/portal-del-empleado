<?php

class EP_Activator
{
    public static function activate()
    {
        // Trigger role creation on activation
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-roles.php';
        $roles = new EP_Roles();
        $roles->add_roles();

        self::create_notification_table();
        self::create_subscribers_table();
        self::create_portal_page();

        // Latido horario: refresca plan y apps autorizadas y mantiene vivo el
        // estado del cliente en el panel de Suscriptores del maestro.
        if (!wp_next_scheduled('ep_daily_license_sync')) {
            wp_schedule_event(time() + 300, 'hourly', 'ep_daily_license_sync');
        }

        flush_rewrite_rules();
    }

    private static function create_notification_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_notifications';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            type varchar(50) DEFAULT 'info' NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            link varchar(255) DEFAULT '',
            is_read tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY is_read (is_read)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function create_subscribers_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_subscribers';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            site_url varchar(255) NOT NULL,
            master_key varchar(255) NOT NULL,
            master_key_hash varchar(64) DEFAULT '' NOT NULL,
            master_key_hint varchar(32) DEFAULT '' NOT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            package varchar(50) DEFAULT 'pro' NOT NULL,
            authorized_apps text DEFAULT NULL,
            wp_version varchar(10) DEFAULT NULL,
            ep_version varchar(10) DEFAULT NULL,
            php_version varchar(10) DEFAULT NULL,
            last_seen datetime DEFAULT NULL,
            last_download datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY site_url (site_url)

        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function create_portal_page()
    {
        $page_title = 'Portal del Empleado';
        $page_content = '[employee_portal]';

        // 1. Check if we already have a stored page ID
        $stored_id = get_option('ep_portal_page_id');
        if ($stored_id && get_post_status($stored_id)) {
            // Page exists - ensure it's published
            if (get_post_status($stored_id) !== 'publish') {
                wp_update_post([
                    'ID' => $stored_id,
                    'post_status' => 'publish',
                ]);
            }
            return;
        }

        // 2. Search for an existing page with the shortcode (any status)
        global $wpdb;
        $existing_id = $wpdb->get_var(
            "SELECT ID FROM $wpdb->posts WHERE post_content LIKE '%[employee_portal]%' AND post_type = 'page' ORDER BY post_status = 'publish' DESC LIMIT 1"
        );

        if ($existing_id) {
            // Republish if trashed/draft
            if (get_post_status($existing_id) !== 'publish') {
                wp_update_post([
                    'ID' => $existing_id,
                    'post_status' => 'publish',
                ]);
            }
            update_option('ep_portal_page_id', $existing_id);
            return;
        }

        // 3. Create fresh page
        $new_page_id = wp_insert_post([
            'post_title' => $page_title,
            'post_content' => $page_content,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'page',
        ]);

        if ($new_page_id && !is_wp_error($new_page_id)) {
            update_option('ep_portal_page_id', $new_page_id);
        }
    }
}
