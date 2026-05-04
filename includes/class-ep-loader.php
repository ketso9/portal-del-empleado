<?php

defined('ABSPATH') || exit;

/**
 * Register all actions and filters for the plugin
 */
#[AllowDynamicProperties]
class EP_Loader
{

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct()
    {
        $this->plugin_name = 'employee-portal';
        $this->version = EMPLOYEE_PORTAL_VERSION;

        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function load_dependencies()
    {
        // Interfaces
        require_once EMPLOYEE_PORTAL_PATH . 'includes/interfaces/interface-ep-app.php';

        // Core Modules
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-app-manager.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-roles.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-auth-o365.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-teams-bot.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-teams-webhook.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-bot-mensajeria.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-bot-context.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-communications.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-notifications.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-setup-wizard.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-ai-service.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-graph-service.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-mailer.php';

        // Admin & Public
        require_once EMPLOYEE_PORTAL_PATH . 'admin/class-ep-admin.php';
        require_once EMPLOYEE_PORTAL_PATH . 'public/class-ep-public.php';

        // Auto-Updater (solo clientes, no el maestro)
        if (!defined('EP_IS_MASTER_PORTAL') || !EP_IS_MASTER_PORTAL) {
            require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-updater.php';
        }
    }

    private function define_admin_hooks()
    {
        $plugin_admin = new EP_Admin($this->plugin_name, $this->version);
        add_action('admin_menu', array($plugin_admin, 'add_plugin_admin_menu'));
        add_action('admin_init', array($plugin_admin, 'register_settings'));
        // Add more admin hooks here
    }

    private function define_public_hooks()
    {
        $plugin_public = new EP_Public($this->plugin_name, $this->version);
        add_action('wp_enqueue_scripts', array($plugin_public, 'enqueue_styles'), 999);
        add_action('wp_enqueue_scripts', array($plugin_public, 'enqueue_scripts'), 999);
        add_filter('template_include', array($plugin_public, 'load_portal_template'));
        add_shortcode('employee_portal', array($plugin_public, 'render_dashboard'));
    }

    public function run()
    {
        // Initialize Global App Manager
        global $ep_app_manager;
        $ep_app_manager = new EP_App_Manager();

        // Register Apps (Stubs for now)
        require_once EMPLOYEE_PORTAL_PATH . 'includes/apps/class-ep-app-stubs.php';
        require_once EMPLOYEE_PORTAL_PATH . 'includes/apps/class-ep-app-settings.php';

        // Mini apps are already loaded in employee-portal.php via ep_load_local_modules()
        // before $plugin->run() is called. No need to require them here again.


        // Trigger App Registration Hook
        $ep_app_manager->load_apps();

        // This is where we would execute the loader if we were using a separate orchestrator class like in the boilerplate
        // For simplicity, we are hooking directly in the constructor/define methods above.
        // But let's instantiate the modules that need early execution
        new EP_Roles();
        new EP_Auth_O365();
        new EP_Teams_Webhook();
        new EP_Bot_Mensajeria();
        new EP_Communications();
        new EP_Notifications();
        new EP_Setup_Wizard();
        
        // Inicializar Mailer O365
        EP_Mailer::get_instance()->init_filters();

        // Auto-Updater (solo clientes)
        if (!defined('EP_IS_MASTER_PORTAL') || !EP_IS_MASTER_PORTAL) {
            new EP_Updater();
        }
    }
}
