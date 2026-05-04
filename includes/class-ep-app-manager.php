<?php

defined('ABSPATH') || exit;

/**
 * Manages Mini Apps and their permissions.
 */
#[AllowDynamicProperties]
class EP_App_Manager
{

    private $apps = array();
    private $option_name = 'ep_apps_config';

    public function __construct()
    {
        // Load configuration
    }

    /**
     * Trigger app registration via hooks.
     */
    public function load_apps()
    {
        /**
         * Fires when apps should register themselves.
         * 
         * @param EP_App_Manager $this
         */
        do_action('ep_register_apps', $this);
    }

    /**
     * Register an app instance.
     * 
     * @param EP_App_Interface $app
     */
    public function register_app(EP_App_Interface $app)
    {
        $this->apps[$app->get_id()] = $app;
    }

    /**
     * Get all registered apps.
     * 
     * @return array
     */
    public function get_apps()
    {
        return $this->apps;
    }

    /**
     * Get a specific app by ID.
     * 
     * @param string $app_id
     * @return EP_App_Interface|null
     */
    public function get_app($app_id)
    {
        return isset($this->apps[$app_id]) ? $this->apps[$app_id] : null;
    }

    /**
     * Check if an app is globally active.
     * 
     * @param string $app_id
     * @return boolean
     */
    public function is_app_active($app_id)
    {
        $config = get_option($this->option_name, array());
        if (isset($config[$app_id]['active'])) {
            return $config[$app_id]['active'];
        }
        return true; // Default to active
    }

    /**
     * Get the permission level for a user on a specific app.
     * 
     * @param string $app_id
     * @param int $user_id
     * @return string 'none', 'read', 'write'
     */
    public function get_user_permission($app_id, $user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        // 0. Administrators always have full access, even if app is "inactive"
        if (user_can($user_id, 'administrator')) {
            return 'write';
        }

        if (!$this->is_app_active($app_id)) {
            return 'none';
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return 'none';
        }

        // 1. Check User-Specific Permission (Override)
        $user_permissions = get_user_meta($user_id, 'ep_app_permissions', true);
        if (is_array($user_permissions) && isset($user_permissions[$app_id]) && $user_permissions[$app_id] !== '') {
            return $user_permissions[$app_id];
        }

        // 2. Check Role-Based Permissions
        $user_roles = $user->roles;
        $config = get_option($this->option_name, array());

        // Default permission if not configured
        $final_permission = 'read';

        // Check permissions for user roles. 
        // If multiple roles, take the most permissive? Or just the first found?
        // Let's go with most permissive: write > read > none

        $has_write = false;
        $has_read = false;
        $has_none = false;

        // If no config for this app, default to 'read' for everyone.
        // This ensures new apps are visible by default.
        if (!isset($config[$app_id]['permissions'])) {
            return 'read';
        }

        foreach ($user_roles as $role) {
            if ($role === 'administrator') {
                return 'write';
            }

            $role_perm = isset($config[$app_id]['permissions'][$role]) ? $config[$app_id]['permissions'][$role] : 'none';

            if ($role_perm === 'write')
                $has_write = true;
            if ($role_perm === 'read')
                $has_read = true;
            if ($role_perm === 'none')
                $has_none = true;
        }

        if ($has_write)
            return 'write';
        if ($has_read)
            return 'read';

        // If explicitly set to none for all roles, return none.
        // If not set for any role (e.g. new role), what is the default?
        // Let's assume 'none' is the safe default if explicit config exists but role is missing.
        return 'none';
    }
    /**
     * Save app configuration.
     * 
     * @param array $new_config
     */
    public function save_config($new_config)
    {
        update_option($this->option_name, $new_config);
    }

    /**
     * Get current configuration.
     * 
     * @return array
     */
    public function get_config()
    {
        return get_option($this->option_name, array());
    }
    /**
     * Static helper to get permission for a user.
     */
    public static function get_permission($app_id, $user_id = null) {
        global $ep_app_manager;
        if (!$ep_app_manager) {
            $ep_app_manager = new self();
        }
        return $ep_app_manager->get_user_permission($app_id, $user_id);
    }
}
