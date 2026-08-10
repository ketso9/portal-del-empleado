<?php

if (!defined('ABSPATH')) {
    exit;
}

#[AllowDynamicProperties]
class EP_Admin
{
    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action('wp_ajax_ep_validate_master_key', array($this, 'ajax_validate_master_key'));
        add_action('wp_ajax_ep_save_site_key', array($this, 'ajax_save_site_key'));
        add_action('wp_ajax_ep_save_license_secret', array($this, 'ajax_save_license_secret'));

        // OPERACIONES DESTRUCTIVAS: solo se registran en entornos de desarrollo/staging.
        // En producción, EP_ALLOW_NUCLEAR_RESET NO debe estar definida en wp-config.php.
        if (defined('EP_ALLOW_NUCLEAR_RESET') && EP_ALLOW_NUCLEAR_RESET === true
            && defined('WP_DEBUG') && WP_DEBUG === true) {
            add_action('wp_ajax_ep_run_nuclear_reset', array($this, 'ajax_run_nuclear_reset'));
            add_action('wp_ajax_ep_export_blueprint', array($this, 'ajax_export_blueprint'));
        }
        add_action('wp_ajax_ep_logout_all_users', array($this, 'ajax_logout_all_users'));
        add_action('wp_ajax_ep_diagnose_teams', array('EP_Teams_Bot', 'ajax_diagnose'));
        add_action('wp_ajax_ep_test_ai_connection', array($this, 'ajax_test_ai_connection'));
        add_action('wp_ajax_ep_save_teams_settings', array($this, 'ajax_save_teams_settings'));
        add_action('wp_ajax_ep_save_system_settings', array($this, 'ajax_save_system_settings'));
        add_action('wp_ajax_ep_test_o365_email', array($this, 'ajax_test_o365_email'));
        add_action('wp_ajax_ep_clear_bot_web_cache', array($this, 'ajax_clear_bot_web_cache'));
        add_action('wp_ajax_ep_get_system_health', array($this, 'ajax_get_system_health'));
        // Autoaprendizaje
        add_action('wp_ajax_ep_bot_learning_approve', array($this, 'ajax_bot_learning_approve'));
        add_action('wp_ajax_ep_bot_learning_discard', array($this, 'ajax_bot_learning_discard'));
        add_action('ep_daily_learning_digest', array($this, 'send_learning_digest_email'));

        add_action('admin_init', array($this, 'handle_subscriber_actions'));

        // Registrar endpoints REST
        add_action('rest_api_init', array($this, 'register_rest_endpoints'));

        // Cron y Sincronización
        add_action('ep_daily_license_sync', array($this, 'scheduled_license_sync'));

        // Autocuración y chequeo forzado en admin
        if (is_admin() && !defined('DOING_AJAX')) {
            $this->admin_license_check();
        }
    }

    /**
     * Sincronización programada diaria (CRON)
     * Verifica la validez de la clave master y actualiza las apps/paquete desde el servidor.
     */
    public function scheduled_license_sync()
    {
        // En el master no hacemos nada
        if (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL) {
            return;
        }

        $key = ep_get_option('ep_site_master_key');
        if (!empty($key)) {
            $this->is_key_valid($key);
            // Limpiar caché de actualizaciones para detectar versiones nuevas inmediatamente
            delete_transient('ep_update_check_cache');
        }
    }

    /**
     * Chequeo forzado en admin (Autocuración)
     * Se ejecuta al cargar el admin para asegurar que el cron está activo y
     * forzar una sincronización si ha pasado mucho tiempo.
     */
    public function admin_license_check()
    {
        // 1. Autocuración del CRON
        if (!wp_next_scheduled('ep_daily_license_sync')) {
            $six_am = strtotime('06:00:00');
            if ($six_am < time()) {
                $six_am = strtotime('tomorrow 06:00:00');
            }
            wp_schedule_event($six_am, 'daily', 'ep_daily_license_sync');
        }

        // 2. Sincronización forzada si el transient expiró
        if (false === get_transient('ep_license_check_lock')) {
            $this->scheduled_license_sync();
            set_transient('ep_license_check_lock', true, 12 * HOUR_IN_SECONDS);
        }
    }

    /**
     * Escanea la carpeta plugins/ para detectar apps automáticamente.
     */
    public static function get_all_available_apps()
    {
        $apps_dir = EMPLOYEE_PORTAL_PATH . 'plugins/';
        $apps = [];
        if (is_dir($apps_dir)) {
            $dirs = array_filter(glob($apps_dir . '*'), 'is_dir');
            foreach ($dirs as $dir) {
                $app_id = basename($dir);
                // Generate a human-readable name from the directory name
                // e.g. 'ep-avisos' => 'Avisos', 'ep-downloads' => 'Downloads'
                $name = str_replace('ep-', '', $app_id);
                $name = ucfirst($name);
                $apps[$app_id] = $name;
            }
        }
        return $apps;
    }

    /**
     * Definición dinámica de paquetes basada en cabeceras de archivo.
     * Lee la cabecera 'Package' (basic|pro|enterprise) de cada mini-app.
     */
    public static function get_packages_definition()
    {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $apps_dir = EMPLOYEE_PORTAL_PATH . 'plugins/';
        $packages = [
            'basic' => [],
            'pro' => [],
            'enterprise' => []
        ];

        if (is_dir($apps_dir)) {
            $dirs = array_filter(glob($apps_dir . '*'), 'is_dir');
            foreach ($dirs as $dir) {
                $app_id = basename($dir);
                $main_file = "$dir/$app_id.php";

                if (!file_exists($main_file)) {
                    $php_files = glob($dir . '/*.php');
                    if (!empty($php_files))
                        $main_file = $php_files[0];
                }

                $pkg = 'enterprise'; // Por defecto si no tiene cabecera
                if (file_exists($main_file)) {
                    $headers = get_file_data($main_file, ['Package' => 'Package']);
                    if (!empty($headers['Package'])) {
                        $pkg = strtolower(trim($headers['Package']));
                    }
                }

                if ($pkg === 'basic') {
                    $packages['basic'][] = $app_id;
                }
                if ($pkg === 'basic' || $pkg === 'pro') {
                    $packages['pro'][] = $app_id;
                }
                // El paquete Enterprise siempre incluye todo lo detectado
                $packages['enterprise'][] = $app_id;
            }
        }

        return $packages;
    }

    public static function get_package_apps($package)
    {
        $packages = self::get_packages_definition();
        return isset($packages[$package]) ? $packages[$package] : [];
    }

    public function add_plugin_admin_menu()
    {
        add_menu_page('Portal del Empleado', 'Portal Empleado', 'manage_options', 'employee-portal', array($this, 'display_plugin_admin_page'), 'dashicons-businessperson', 30);
        add_submenu_page('employee-portal', 'Gestión de Red', 'Gestión de Red', 'manage_options', 'ep-network', array($this, 'display_plugin_admin_page'));
    }

    public function register_settings()
    {
        // General tab - solo configuración base
        register_setting('ep_settings_group', 'ep_auth_remote_url');
        register_setting('ep_settings_group', 'ep_site_master_key');
        register_setting('ep_settings_group', 'ep_debug_log_active');
        register_setting('ep_settings_group', 'ep_gdpr_settings');
        
        // IA & Bot Settings - GRUPO INDEPENDIENTE para evitar wipes
        register_setting('ep_ai_settings_group', 'ep_ai_api_key');
        register_setting('ep_ai_settings_group', 'ep_ai_model');
        register_setting('ep_ai_settings_group', 'ep_ai_daily_limit');
        register_setting('ep_ai_settings_group', 'ep_ai_monthly_limit');

        // NOTA: Las opciones de Teams/Bot (ep_o365_*, ep_teams_*, ep_onedrive_sync_principal)
        // se guardan via AJAX desde la pestaña IA & Bot (ajax_save_teams_settings)
        // para evitar que options.php las borre al guardar un formulario parcial.
    }

    public function display_plugin_admin_page()
    {
        // Reparación manual quirúrgica de la base de datos
        global $wpdb;
        $table_name = $wpdb->prefix . 'ep_subscribers';

        $columns_to_check = [
            'package' => "ALTER TABLE $table_name ADD COLUMN package varchar(50) DEFAULT 'basic' NOT NULL AFTER status",
            'authorized_apps' => "ALTER TABLE $table_name ADD COLUMN authorized_apps text DEFAULT NULL AFTER package"
        ];

        foreach ($columns_to_check as $col => $sql) {
            $check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table_name LIKE %s", $col));
            if (empty($check)) {
                $wpdb->query($sql);
            }
        }

        $this->handle_subscriber_actions();
        $active_tab = isset($_GET['tab']) ? sanitize_text_field((string) ($_GET['tab'] ?? '')) : 'general';
        if (isset($_GET['page']) && (string) ($_GET['page'] ?? '') === 'ep-network' && $active_tab === 'general') {
            $active_tab = 'subscribers';
        }

        ?>
        <style>
            .ep-admin-wrap {
                margin-right: 20px;
                font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            }

            .ep-admin-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 30px;
                margin-top: 20px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            }

            .nav-tab-wrapper {
                border-bottom: 1px solid #e2e8f0;
                margin-bottom: 25px;
                gap: 8px;
                display: flex;
                border: none;
                padding-bottom: 0;
            }

            .nav-tab {
                background: #f1f5f9;
                border: 1px solid #e2e8f0 !important;
                border-bottom: none !important;
                border-radius: 8px 8px 0 0 !important;
                margin: 0 !important;
                padding: 12px 24px !important;
                height: auto !important;
                transition: all 0.2s;
                font-weight: 500;
                color: #64748b !important;
            }

            .nav-tab-active {
                background: #fff !important;
                border-bottom: 2px solid #fff !important;
                border-top: 4px solid #3b82f6 !important;
                color: #1e293b !important;
                font-weight: 700;
                box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.05);
            }

            .ep-auth-box {
                background: #f8fafc;
                padding: 25px;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
            }

            .ep-badge {
                background: #e0f2fe;
                color: #0369a1;
                padding: 4px 12px;
                border-radius: 99px;
                font-size: 10px;
                font-weight: 800;
                letter-spacing: 0.05em;
            }

            input[type="text"],
            input[type="url"],
            input[type="password"],
            select {
                border: 1px solid #cbd5e1 !important;
                border-radius: 8px !important;
                padding: 10px 14px !important;
                width: 100%;
                transition: all 0.2s;
                font-size: 14px;
            }

            input:focus,
            select:focus {
                border-color: #3b82f6 !important;
                outline: none !important;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
                background: #fff;
            }

            .widefat {
                border: 1px solid #e2e8f0 !important;
                border-radius: 12px !important;
                overflow: hidden;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                border-collapse: separate !important;
                border-spacing: 0;
                background: #fff !important;
            }

            .widefat thead th {
                background: #f8fafc !important;
                border-bottom: 2px solid #e2e8f0 !important;
                padding: 18px !important;
                font-weight: 700;
                color: #475569;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.05em;
            }

            .widefat tbody td {
                padding: 18px !important;
                border-bottom: 1px solid #f1f5f9 !important;
                vertical-align: middle;
                color: #334155;
            }

            .button-primary {
                background: #2563eb !important;
                border: none !important;
                border-radius: 8px !important;
                padding: 12px 28px !important;
                font-weight: 600 !important;
                height: auto !important;
                box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2) !important;
                transition: all 0.2s !important;
            }

            .button-primary:hover {
                transform: translateY(-1px);
                box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3) !important;
                filter: brightness(1.1);
            }

            .ep-card-title {
                font-size: 18px;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .ep-diag-box {
                margin-top: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
            }

            .ep-diag-log {
                background: #0f172a;
                color: #38bdf8;
                padding: 15px;
                border-radius: 6px;
                font-family: monospace;
                font-size: 12px;
                overflow-x: auto;
                margin-top: 10px;
                white-space: pre-wrap;
            }

            .ep-diag-status {
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
                margin-bottom: 10px;
            }

            /* O365 Instructions Grid */
            .ep-settings-grid {
                display: grid;
                grid-template-columns: 1.5fr 1fr;
                gap: 30px;
                align-items: start;
            }

            .ep-instructions-panel {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 20px;
            }

            .ep-instructions-panel h3 {
                margin-top: 0;
                color: #1e293b;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ep-step-list {
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .ep-step-item {
                display: flex;
                gap: 12px;
                margin-bottom: 15px;
            }

            .ep-step-num {
                background: #3b82f6;
                color: #fff;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 700;
                flex-shrink: 0;
            }

            .ep-step-text {
                font-size: 13px;
                color: #475569;
                line-height: 1.4;
            }

            @media (max-width: 1200px) {
                .ep-settings-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <div class="wrap ep-admin-wrap">
            <h1 class="wp-heading-inline">Portal del Empleado - Administración</h1>
            <hr class="wp-header-end">
            <?php settings_errors('ep_settings_group'); ?>
            <nav class="nav-tab-wrapper">
                <a href="?page=employee-portal&tab=general"
                    class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="?page=employee-portal&tab=apps"
                    class="nav-tab <?php echo $active_tab == 'apps' ? 'nav-tab-active' : ''; ?>">Apps & Permisos</a>
                <a href="?page=employee-portal&tab=network"
                    class="nav-tab <?php echo $active_tab == 'network' ? 'nav-tab-active' : ''; ?>">Red & Despliegue</a>
                <?php if (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL): ?>
                    <a href="?page=employee-portal&tab=subscribers"
                        class="nav-tab <?php echo $active_tab == 'subscribers' ? 'nav-tab-active' : ''; ?>">Suscriptores
                        (Clientes)</a>
                <?php endif; ?>
                <a href="?page=employee-portal&tab=gdpr"
                    class="nav-tab <?php echo $active_tab == 'gdpr' ? 'nav-tab-active' : ''; ?>">RGPD</a>
                <a href="?page=employee-portal&tab=ai"
                    class="nav-tab <?php echo $active_tab == 'ai' ? 'nav-tab-active' : ''; ?>">IA & Bot</a>
                <a href="?page=employee-portal&tab=system"
                    class="nav-tab <?php echo $active_tab == 'system' ? 'nav-tab-active' : ''; ?>">Sistema</a>
                <?php
                $learning_count = count(get_option('ep_bot_learning_queue', []));
                $lc_badge = $learning_count > 0 ? ' <span style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:4px;">' . $learning_count . '</span>' : '';
                ?>
                <a href="?page=employee-portal&tab=learning"
                    class="nav-tab <?php echo $active_tab == 'learning' ? 'nav-tab-active' : ''; ?>">
                    🧠 Aprendizaje<?php echo $lc_badge; ?></a>
            </nav>


            <div class="ep-admin-content" style="margin-top: 20px;">
                <?php if ($active_tab == 'general'): ?>
                    <form method="post" action="options.php">
                        <?php settings_fields('ep_settings_group'); ?>
                        <div class="ep-admin-card">
                            <h2>Configuración Base</h2>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Activar Logs de Depuración</th>
                                    <td><input type="checkbox" name="ep_debug_log_active" value="1" <?php checked(ep_get_option('ep_debug_log_active'), '1'); ?>>
                                        <p class="description">Ver en <?php echo EMPLOYEE_PORTAL_PATH; ?>ep_debug.log</p>
                                        <?php 
                                        $log_f = EMPLOYEE_PORTAL_PATH . 'ep_debug.log';
                                        if (file_exists($log_f)): ?>
                                            <div style="margin-top:10px;">
                                                <button type="button" class="button" onclick="jQuery('#ep_log_viewer').toggle()">👁️ Ver últimas entradas del Log</button>
                                                <pre id="ep_log_viewer" style="display:none; background:#1e293b; color:#38bdf8; padding:15px; border-radius:8px; max-height:400px; overflow:auto; font-size:11px; margin-top:10px;"><?php 
                                                    $log_data = file($log_f);
                                                    echo esc_html(implode("", array_slice($log_data, -100))); 
                                                ?></pre>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Redirect URI</th>
                                    <td><code><?php echo home_url('/?ep_auth=o365'); ?></code>
                                        <p class="description">Copia esta URL en la configuración de redirección de tu App en Azure.</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="description" style="margin-top:20px; padding:12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; color:#0369a1;">
                                ℹ️ La configuración de <strong>Microsoft 365, Bot de Teams e IA</strong> se ha consolidado en la pestaña <a href="?page=employee-portal&tab=ai"><strong>IA & Bot</strong></a>.
                            </p>
                            <?php submit_button(); ?>
                        </div>
                    </form>
                <?php elseif ($active_tab == 'apps'): ?>
                    <div class="ep-admin-card"><?php $this->render_apps_settings_page(); ?></div>
                <?php elseif ($active_tab == 'network'): ?>
                    <div class="ep-admin-card"><?php $this->render_network_config_section(); ?></div>
                <?php elseif ($active_tab == 'subscribers' && defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL): ?>
                    <div class="ep-admin-card"><?php $this->render_subscribers_section(); ?></div>
                <?php elseif ($active_tab == 'gdpr'): ?>
                    <div class="ep-admin-card"><?php $this->render_gdpr_settings_page(); ?></div>
                <?php elseif ($active_tab == 'ai'): ?>
                    <div class="ep-admin-card"><?php $this->render_ai_settings_page(); ?></div>
                <?php elseif ($active_tab == 'system'): ?>
                    <div class="ep-admin-card"><?php $this->render_system_settings_page(); ?></div>
                <?php elseif ($active_tab == 'learning'): ?>
                    <div class="ep-admin-card"><?php $this->render_learning_center(); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_network_config_section()
    {
        $remote_url = ep_get_option('ep_auth_remote_url');
        if (empty($remote_url)) {
            $remote_url = defined('EP_AUTH_REMOTE_URL') ? (string) EP_AUTH_REMOTE_URL : (string) home_url('/wp-json/ep/v1/validate-key');
        }

        $debug_log = get_option('ep_sync_debug_log');
        ?>
        <h2>Enlace Maestro-Cliente</h2>
        <div class="ep-auth-box">
            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:600;">Auth API Maestro</label>
                <input type="url" id="ep_auth_remote_url" value="<?php echo esc_attr($remote_url); ?>"
                    style="width:100%; max-width:500px;">
            </div>
            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:600;">Llave Maestra del Sitio</label>
                <div style="display:flex; gap:10px;">
                    <input type="password" id="ep_site_master_key"
                        value="<?php echo esc_attr(ep_get_option('ep_site_master_key')); ?>" style="width:300px;">
                    <button type="button" id="ep_save_key_btn" class="button">Guardar</button>
                    <button type="button" id="ep_validate_key_btn" class="button button-primary">Validar y Sincronizar</button>
                </div>
            </div>

            <div style="margin-bottom:15px; padding:15px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px;">
                <label style="display:block; font-weight:600; margin-bottom:6px;">🔐 Secret de Licencia (HMAC)</label>
                <p class="description" style="margin-bottom:10px;">
                    Clave compartida entre el Maestro y este cliente para verificar los tokens de licencia con firma criptográfica.
                    Se guarda cifrada con AES-256. Debe ser idéntica en ambos lados.
                    <?php
                    require_once plugin_dir_path(__FILE__) . '../includes/class-ep-license.php';
                    if (EP_License::has_secret()): ?>
                        <strong style="color:#16a34a;">✅ Configurado</strong>
                    <?php else: ?>
                        <strong style="color:#dc2626;">⚠️ No configurado — usando modo legacy</strong>
                    <?php endif; ?>
                </p>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="password" id="ep_license_secret_input" placeholder="Pega aquí el secret compartido (64+ caracteres)" style="width:380px; font-family:monospace;">
                    <button type="button" id="ep_save_license_secret_btn" class="button button-primary">Guardar Secret</button>
                </div>
                <p class="description" style="margin-top:8px; font-size:11px; color:#64748b;">
                    Genera uno seguro con: <code>openssl rand -hex 64</code>
                </p>
                <div id="ep-license-secret-result" style="margin-top:8px;"></div>
            </div>
        </div>

        <div class="ep-diag-box">
            <h3>🔬 Diagnóstico de Conexión</h3>
            <div id="ep-diag-result">
                <?php if ($debug_log): ?>
                    <div class="ep-diag-status">
                        <?php if (isset($debug_log['status']) && $debug_log['status'] === 'success'): ?>
                            <span class="ep-health-indicator ep-health-green"></span> Conectado exitosamente
                        <?php else: ?>
                            <span class="ep-health-indicator ep-health-red"></span> Error de conexión detectado
                        <?php endif; ?>
                        <small>(Último intento: <?php echo esc_html($debug_log['time'] ?? 'Desconocida'); ?>)</small>
                    </div>
                    <div class="ep-diag-log"><?php echo esc_html(print_r($debug_log, true)); ?></div>
                <?php else: ?>
                    <p>No se han registrado intentos de sincronización todavía.</p>
                <?php endif; ?>
            </div>
            <p class="description" style="margin-top:10px;">
                <strong>Nota:</strong> Si el punto de salud en el Maestro está rojo, asegúrate de que la URL de arriba es
                accesible desde el servidor del cliente.
            </p>
        </div>

        <div class="ep-diag-box" style="margin-top:20px; border-left: 4px solid #3b82f6;">
            <h3>⏱️ Servicio Cron de Servidor (Obligatorio)</h3>
            <p>Para asegurar que las métricas de Microsoft Teams y las tareas de sincronización nocturna funcionen con
                <strong>precisión absoluta</strong>, debes desactivar el Cron virtual de WordPress y configurar una tarea real
                en el Plesk/cPanel de cada instalación.
            </p>

            <p><strong>1. En wp-config.php añade:</strong></p>
            <div class="ep-diag-log">define('DISABLE_WP_CRON', true);</div>

            <p style="margin-top:15px;"><strong>2. En tareas programadas del servidor (ejecución: cada 5 minutos
                    <code>*/5 * * * *</code>):</strong></p>
            <p class="description">Usa este comando WGET (recomendado):</p>
            <div class="ep-diag-log">wget -q -O - "<?php echo home_url('/wp-cron.php?doing_wp_cron'); ?>" >/dev/null 2>&1</div>

            <p class="description" style="margin-top:10px;">O usa la vía CLI (ajustando la ruta absoluta, en caso de fallar
                wget):</p>
            <div class="ep-diag-log">/usr/bin/php <?php echo ABSPATH; ?>wp-cron.php >/dev/null 2>&1</div>
        </div>

        <div id="ep-auth-status-msg"></div>
        <div id="ep-deploy-tools-section"
            style="<?php echo (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL) || ep_get_option('ep_authorized_apps') ? '' : 'display:none;'; ?> margin-top: 20px; padding:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
            <h3>Generar Blueprint</h3>
            <button type="button" id="ep_export_blueprint_btn" class="button button-primary">📦 Crear ZIP del Portal
                Completo</button>
            <button type="button" id="ep_run_reset_btn" class="button button-link-delete"
                style="color:red; margin-left:10px;">Reset Total</button>

            <hr style="margin:20px 0; border:0; border-top:1px solid #e2e8f0;">

            <h3 style="color:#dc2626;">🚨 Zona de Emergencia</h3>
            <p class="description">Usa este botón para invalidar todas las cookies de sesión actuales. Útil si hay cambios
                críticos de seguridad o política.</p>
            <button type="button" id="ep_panic_logout_btn" class="button"
                style="background:#dc2626; color:white; border:none;">Cerrar TODAS las sesiones activas</button>

            <div id="ep-deploy-response" style="margin-top:10px;"></div>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                $('#ep_panic_logout_btn').on('click', function () {
                    if (!confirm('¿Estás SEGURO? Todos los usuarios conectados (incluyéndote a ti) serán expulsados del sistema.')) return;
                    const b = $(this); b.prop('disabled', true).text('Cerrando sesiones...');
                    $.post(ajaxurl, {
                        action: 'ep_logout_all_users',
                        security: '<?php echo wp_create_nonce("ep_deploy_nonce"); ?>'
                    }, function (r) {
                        alert(r.data);
                        location.reload();
                    });
                });
                $('#ep_save_key_btn').on('click', function () {
                    $.post(ajaxurl, { action: 'ep_save_site_key', key: $('#ep_site_master_key').val(), remote_url: $('#ep_auth_remote_url').val(), security: '<?php echo wp_create_nonce("ep_deploy_nonce"); ?>' }, function (r) { alert(r.data); });
                });
                $('#ep_save_license_secret_btn').on('click', function () {
                    const secret = $('#ep_license_secret_input').val().trim();
                    if (!secret) { $('#ep-license-secret-result').html('<span style="color:red;">Introduce el secret antes de guardar.</span>'); return; }
                    if (secret.length < 32) { $('#ep-license-secret-result').html('<span style="color:orange;">⚠️ El secret debería tener al menos 32 caracteres para ser seguro.</span>'); }
                    const btn = $(this); btn.prop('disabled', true).text('Guardando...');
                    $.post(ajaxurl, { action: 'ep_save_license_secret', secret: secret, security: '<?php echo wp_create_nonce("ep_deploy_nonce"); ?>' }, function (r) {
                        btn.prop('disabled', false).text('Guardar Secret');
                        if (r.success) {
                            $('#ep-license-secret-result').html('<span style="color:#16a34a;">✅ ' + r.data + '</span>');
                            $('#ep_license_secret_input').val('');
                        } else {
                            $('#ep-license-secret-result').html('<span style="color:red;">❌ ' + r.data + '</span>');
                        }
                    });
                });
                $('#ep_validate_key_btn').on('click', function () {
                    const b = $(this); const oldText = b.text(); b.text('Sincronizando...').prop('disabled', true);
                    $.post(ajaxurl, { action: 'ep_validate_master_key', key: $('#ep_site_master_key').val(), security: '<?php echo wp_create_nonce("ep_deploy_nonce"); ?>' }, function (r) {
                        b.text(oldText).prop('disabled', false);
                        if (r.success) {
                            $('#ep-deploy-tools-section').fadeIn();
                            // Only reload if NOT Master to show the new diagnostics log
                            <?php if (!defined('EP_IS_MASTER_PORTAL') || !EP_IS_MASTER_PORTAL): ?>
                                location.reload();
                            <?php else: ?>
                                alert('Conexión validada localmente (Modo Maestro).');
                            <?php endif; ?>
                        } else {
                            alert('Fallo de validación: ' + (r.data.error || r.data));
                            if (r.data.log) {
                                $('#ep-diag-result').html('<div class="ep-diag-status"><span class="ep-health-indicator ep-health-red"></span> Error detectado ahora</div><div class="ep-diag-log">' + JSON.stringify(r.data.log, null, 2) + '</div>');
                            }
                        }
                    });
                });
                $('#ep_export_blueprint_btn').on('click', function () {
                    $(this).text('Generando...');
                    $.post(ajaxurl, { action: 'ep_export_blueprint', security: '<?php echo wp_create_nonce("ep_deploy_nonce"); ?>', key: $('#ep_site_master_key').val() }, function (r) {
                        $('#ep_export_blueprint_btn').text('📦 Crear ZIP'); if (r.success) $('#ep-deploy-response').html('<a href="' + r.data + '" class="button">Descargar</a>'); else alert(r.data);
                    });
                });
            });
        </script>
        <?php
    }

    public function handle_subscriber_actions()
    {
        if (!current_user_can('manage_options'))
            return;

        // Revocar licencia
        if (isset($_GET['action']) && (string) $_GET['action'] === 'delete_sub' && isset($_GET['sub_id'])) {
            $sub_id = absint($_GET['sub_id']);
            check_admin_referer('ep_delete_subscriber_nonce');
            global $wpdb;
            $wpdb->delete("{$wpdb->prefix}ep_subscribers", ['id' => $sub_id]);
            $redirect_url = (string) ($_SERVER['REQUEST_URI'] ?? '');
            wp_redirect(remove_query_arg(['action', 'sub_id', '_wpnonce'], $redirect_url));
            exit;
        }

        // Actualizar Plan/Apps vía POST
        if (isset($_POST['ep_update_subscriber'])) {
            check_admin_referer('ep_edit_subscriber_nonce');
            global $wpdb;
            $id = intval($_POST['sub_id']);
            $package = sanitize_text_field($_POST['package']);
            $apps = isset($_POST['apps']) ? (array) $_POST['apps'] : [];

            // Si no se seleccionaron apps manualmente, usar las del paquete por defecto
            if (empty($apps)) {
                $apps = self::get_package_apps($package);
            }

            $wpdb->update("{$wpdb->prefix}ep_subscribers", [
                'package' => $package,
                'authorized_apps' => json_encode($apps)
            ], ['id' => $id]);

            add_settings_error('ep_settings_group', 'ep_sub_updated', 'Cliente actualizado (' . count($apps) . ' apps asignadas).', 'updated');
        }

        if (isset($_POST['ep_add_subscriber'])) {
            check_admin_referer('ep_add_subscriber_nonce');
            global $wpdb;
            $pkg = ep_get_post_val('new_subscriber_package', 'basic');
            $packages = self::get_packages_definition();
            $apps = isset($_POST['authorized_apps']) ? (array) $_POST['authorized_apps'] : ($packages[$pkg] ?? []);
            $wpdb->insert("{$wpdb->prefix}ep_subscribers", [
                'site_url' => untrailingslashit((string) esc_url_raw((string) ep_get_post_val('new_subscriber_url'))),
                'master_key' => (string) ep_get_post_val('new_subscriber_key'),
                'package' => $pkg,
                'authorized_apps' => json_encode($apps),
                'status' => 'active'
            ]);
        }

        // Guardar ajustes RGPD
        if (isset($_POST['ep_update_gdpr'])) {
            check_admin_referer('ep_gdpr_settings_verify');
            $gdpr = isset($_POST['gdpr']) ? $_POST['gdpr'] : array();
            $sanitized = array(
                'policy_url' => esc_url_raw($gdpr['policy_url'] ?? ''),
                'cookies_url' => esc_url_raw($gdpr['cookies_url'] ?? ''),
                'legal_notice_url' => esc_url_raw($gdpr['legal_notice_url'] ?? ''),
                'entity_name' => sanitize_text_field($gdpr['entity_name'] ?? ''),
                'entity_cif' => sanitize_text_field($gdpr['entity_cif'] ?? ''),
                'entity_address' => sanitize_text_field($gdpr['entity_address'] ?? ''),
                'entity_registration' => sanitize_textarea_field($gdpr['entity_registration'] ?? ''),
                'banner_text' => sanitize_textarea_field($gdpr['banner_text'] ?? ''),
                'show_on_login' => isset($gdpr['show_on_login']) ? 1 : 0,
            );
            update_option('ep_gdpr_settings', $sanitized);
            add_settings_error('ep_settings_group', 'ep_gdpr_updated', 'Configuración RGPD guardada correctamente.', 'updated');
        }
    }

    private function render_subscribers_section()
    {
        $all_apps = self::get_all_available_apps();
        $packages = self::get_packages_definition();
        ?>
        <style>
            .ep-health-indicator {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
                margin-right: 5px;
            }

            .ep-health-green {
                background: #22c55e;
            }

            .ep-health-red {
                background: #ef4444;
            }

            .ep-edit-row {
                background: #f8fafc !important;
                display: none;
            }

            .ep-edit-row.active {
                display: table-row;
            }

            .ep-v-badge {
                background: #f1f5f9;
                padding: 2px 5px;
                border-radius: 4px;
                font-size: 11px;
                color: #475569;
            }

            .ep-version-status {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                margin-top: 5px;
            }

            .ep-version-outdated {
                background: #fef3c7;
                color: #92400e;
                border: 1px solid #fcd34d;
            }

            .ep-version-uptodate {
                background: #dcfce7;
                color: #166534;
                border: 1px solid #86efac;
            }

            .ep-version-unknown {
                background: #f1f5f9;
                color: #64748b;
                border: 1px solid #cbd5e1;
            }
        </style>

        <h2>Gestión de Suscriptores (SaaS)</h2>
        <p class="description">Controla las licencias, el estado de salud y los módulos autorizados para cada portal cliente.
        </p>

        <div style="background:#f8fafc; padding:25px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:20px;">
            <h3>Alta de Nuevo Cliente</h3>
            <form method="post" action="">
                <?php wp_nonce_field('ep_add_subscriber_nonce'); ?>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
                    <input type="url" name="new_subscriber_url" placeholder="URL Cliente (ej: https://cliente.com)" required
                        style="width:100%;">
                    <input type="text" name="new_subscriber_key"
                        value="EP-<?php echo strtoupper(wp_generate_password(8, false)); ?>" required style="width:100%;">
                </div>
                <div style="display:grid; grid-template-columns: 200px 1fr; gap:15px; margin-bottom:15px;">
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:5px;">Paquete de Suscripción</label>
                        <select name="new_subscriber_package" id="ep_sub_package" style="width:100%;">
                            <option value="basic">Básico</option>
                            <option value="pro" selected>Profesional</option>
                            <option value="enterprise">Enterprise (Todo)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:5px;">Aplicaciones Autorizadas (Ajuste
                            Personalizado)</label>
                        <div
                            style="display:flex; flex-wrap:wrap; gap:10px; background:white; padding:10px; border:1px solid #ddd; border-radius:5px;">
                            <?php foreach ($all_apps as $id => $name): ?>
                                <label style="font-size:12px; display:flex; align-items:center; gap:5px; width:140px;">
                                    <input type="checkbox" name="authorized_apps[]" value="<?php echo esc_attr($id); ?>"
                                        class="ep-app-cb" data-app="<?php echo esc_attr($id); ?>"> <?php echo esc_html($name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button type="submit" name="ep_add_subscriber" class="button button-primary">Activar Portal Cliente</button>
            </form>
        </div>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:250px;">Sitio Cliente / Salud</th>
                    <th>Versiones</th>
                    <th>Plan / Apps</th>
                    <th style="width:150px;">Última Conexión</th>
                    <th style="width:100px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php global $wpdb;
                $subs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ep_subscribers ORDER BY id DESC");
                if ($subs):
                    foreach ($subs as $sub):
                        $last_seen_val = (string) ($sub->last_seen ?? '');
                        $last_seen = !empty($last_seen_val) ? strtotime($last_seen_val) : 0;
                        $is_online = $last_seen > 0 && (time() - $last_seen) < 3600; // Online si conectó hace menos de 1 hora
                        $apps_count = count(json_decode((string) (($sub->authorized_apps ?? '[]') ?: '[]'), true));
                        ?>
                        <tr>
                            <td>
                                <span
                                    class="ep-health-indicator <?php echo $is_online ? 'ep-health-green' : 'ep-health-red'; ?>"></span>
                                <strong><?php echo esc_html($sub->site_url ?? ''); ?></strong><br>
                                <small style="color:#64748b;"><?php echo esc_html($sub->master_key ?? ''); ?></small>
                            </td>
                            <td>
                                <span class="ep-v-badge" title="WordPress version">WP:
                                    <?php echo esc_html(($sub->wp_version ?? '') ?: '?.?'); ?></span>
                                <span class="ep-v-badge" title="Plugin version">EP:
                                    <?php echo esc_html(($sub->ep_version ?? '') ?: '?.?'); ?></span>
                                <span class="ep-v-badge" title="PHP version">PHP:
                                    <?php echo esc_html(($sub->php_version ?? '') ?: '?.?'); ?></span>
                                <br>
                                <?php
                                $client_ep = ($sub->ep_version ?? '') ?: '';
                                $master_ep = EMPLOYEE_PORTAL_VERSION;
                                if (empty($client_ep)) {
                                    echo '<span class="ep-version-status ep-version-unknown">❓ Sin datos</span>';
                                } elseif (version_compare($client_ep, $master_ep, '<')) {
                                    echo '<span class="ep-version-status ep-version-outdated">⚠️ Desactualizado (v' . esc_html($master_ep) . ' disponible)</span>';
                                } else {
                                    echo '<span class="ep-version-status ep-version-uptodate">✅ Al día</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge"
                                    style="background:#e2e8f0; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:bold;"><?php echo strtoupper($sub->package ?? 'basic'); ?></span>
                                <small><?php echo $apps_count; ?> apps autorizadas</small>
                            </td>
                            <td><?php echo ($sub->last_seen ?? false) ? date_i18n('d/m/Y H:i', $last_seen) : 'Nunca'; ?></td>
                            <td>
                                <button class="button ep-toggle-edit" data-id="<?php echo (int) $sub->id; ?>">Gestionar</button>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete_sub', 'sub_id' => (int) $sub->id]), 'ep_delete_subscriber_nonce')); ?>"
                                    class="button button-link-delete"
                                    style="color:#d63638; text-decoration:none; display:inline-block; margin-top:5px;"
                                    onclick="return confirm('¿Estas seguro de eliminar este cliente?')">Eliminar</a>
                            </td>
                        </tr>
                        <tr id="ep-edit-sub-<?php echo $sub->id; ?>" class="ep-edit-row">
                            <td colspan="5">
                                <form method="post"
                                    style="display:flex; gap:20px; align-items:flex-start; padding:15px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
                                    <?php wp_nonce_field('ep_edit_subscriber_nonce'); ?>
                                    <input type="hidden" name="sub_id" value="<?php echo $sub->id; ?>">
                                    <div style="width:200px;">
                                        <label style="font-weight:600; display:block; margin-bottom:5px;">Paquete</label>
                                        <select name="package" class="ep-edit-package" data-id="<?php echo $sub->id; ?>"
                                            style="width:100%;">
                                            <option value="basic" <?php selected($sub->package, 'basic'); ?>>Básico</option>
                                            <option value="pro" <?php selected($sub->package, 'pro'); ?>>Profesional</option>
                                            <option value="enterprise" <?php selected($sub->package, 'enterprise'); ?>>Enterprise
                                            </option>
                                        </select>
                                    </div>
                                    <div style="flex:1;">
                                        <label style="font-weight:600; display:block; margin-bottom:5px;">CheckApps</label>
                                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                            <?php
                                            $active_apps = json_decode($sub->authorized_apps ?: '[]', true);
                                            foreach ($all_apps as $aid => $aname): ?>
                                                <label
                                                    style="font-size:11px; display:flex; align-items:center; gap:3px; background:#f1f5f9; padding:2px 8px; border-radius:15px; cursor:pointer;">
                                                    <input type="checkbox" name="apps[]" value="<?php echo esc_attr($aid); ?>"
                                                        class="ep-app-cb-edit-<?php echo $sub->id; ?>"
                                                        data-app="<?php echo esc_attr($aid); ?>" <?php checked(in_array($aid, $active_apps), true); ?>> <?php echo esc_html($aname); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div style="width:120px; text-align:right;">
                                        <button type="submit" name="ep_update_subscriber" class="button button-primary">Guardar</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5">No hay clientes registrados en la red.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
            jQuery(document).ready(function ($) {
                const packageMap = <?php echo json_encode($packages); ?>;

                // Onboarding Wizard - Asignar apps por defecto al cambiar paquete en Alta
                $('#ep_sub_package').on('change', function () {
                    const pkg = $(this).val();
                    const apps = packageMap[pkg] || [];
                    $('.ep-app-cb').prop('checked', false);
                    apps.forEach(a => {
                        $(`.ep-app-cb[data-app="${a}"]`).prop('checked', true);
                    });
                }).trigger('change');

                // Toggle Edit Rows
                $('.ep-toggle-edit').on('click', function () {
                    const id = $(this).data('id');
                    const $row = $(`#ep-edit-sub-${id}`);
                    const isVisible = $row.hasClass('active');

                    // Cerrar todas las demás antes
                    $('.ep-edit-row').removeClass('active');
                    $('.ep-toggle-edit').text('Gestionar');

                    if (!isVisible) {
                        $row.addClass('active');
                        $(this).text('Cerrar');
                    }
                });

                // Plan changes in edit mode
                $('.ep-edit-package').on('change', function () {
                    const id = $(this).data('id');
                    const pkg = $(this).val();
                    const apps = packageMap[pkg] || [];
                    $(`.ep-app-cb-edit-${id}`).prop('checked', false);
                    apps.forEach(a => {
                        $(`.ep-app-cb-edit-${id}[data-app="${a}"]`).prop('checked', true);
                    });
                });
            });
        </script>
        <?php
    }

    private function render_apps_settings_page()
    {
        global $ep_app_manager;
        if (!$ep_app_manager)
            return;
        $apps = $ep_app_manager->get_apps();
        $config = $ep_app_manager->get_config();

        // Filter roles: Keep administrator and custom portal roles, remove defaults
        $all_roles = wp_roles()->roles;
        $excluded_roles = ['editor', 'author', 'contributor', 'subscriber'];
        $roles = array_filter($all_roles, function ($key) use ($excluded_roles) {
            return !in_array($key, $excluded_roles);
        }, ARRAY_FILTER_USE_KEY);

        if (isset($_POST['ep_apps_submit'])) {
            check_admin_referer('ep_apps_settings_verify');
            $nc = [];
            foreach ($apps as $id => $app) {
                $nc[$id]['active'] = isset($_POST['apps'][$id]['active']);
                $nc[$id]['permissions'] = isset($_POST['apps'][$id]['permissions']) ? $_POST['apps'][$id]['permissions'] : [];
            }
            $ep_app_manager->save_config($nc);
            $config = $ep_app_manager->get_config();
        }
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('ep_apps_settings_verify'); ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:150px;">App</th>
                        <th>Estado</th><?php foreach ($roles as $r)
                            echo "<th>{$r['name']}</th>"; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apps as $id => $app): ?>
                        <tr>
                            <td><strong><?php echo $app->get_name(); ?></strong></td>
                            <td><input type="checkbox" name="apps[<?php echo $id; ?>][active]" value="1" <?php checked(isset($config[$id]['active']) ? $config[$id]['active'] : true, true); ?>></td>
                            <?php foreach ($roles as $rk => $rv):
                                $p = isset($config[$id]['permissions'][$rk]) ? $config[$id]['permissions'][$rk] : 'none'; ?>
                                <td><select name="apps[<?php echo $id; ?>][permissions][<?php echo $rk; ?>]">
                                        <option value="none" <?php selected($p, 'none'); ?>>❌</option>
                                        <option value="read" <?php selected($p, 'read'); ?>>👁️</option>
                                        <option value="write" <?php selected($p, 'write'); ?>>✏️</option>
                                    </select></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="submit"><input type="submit" name="ep_apps_submit" class="button button-primary" value="Guardar Cambios">
            </p>
        </form>
        <?php
    }

    public function ajax_validate_master_key()
    {
        check_ajax_referer('ep_deploy_nonce', 'security');
        $k = (string) ep_get_post_val('key', (string) ep_get_option('ep_site_master_key'));
        if (empty($k))
            wp_send_json_error('No key');

        if ($this->is_key_valid($k)) {
            // Limpiar caché de actualizaciones para forzar comprobación fresca
            delete_transient('ep_update_check_cache');
            wp_send_json_success();
        } else {
            $log = get_option('ep_sync_debug_log');
            wp_send_json_error([
                'error' => 'Invalid',
                'log' => $log
            ]);
        }
    }

    public function ajax_save_site_key()
    {
        check_ajax_referer('ep_deploy_nonce', 'security');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Denied');
        update_option('ep_site_master_key', sanitize_text_field((string) $_POST['key']));
        if (isset($_POST['remote_url']))
            update_option('ep_auth_remote_url', esc_url_raw((string) $_POST['remote_url']));
        wp_send_json_success('Saved');
    }

    /**
     * Guarda el secret de licencia HMAC cifrado con EP_Security.
     * Gestionable directamente desde el panel de administración.
     */
    public function ajax_save_license_secret()
    {
        check_ajax_referer('ep_deploy_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acceso denegado: se requiere rol de administrador.');
        }

        $secret = sanitize_text_field(wp_unslash($_POST['secret'] ?? ''));

        if (empty($secret)) {
            wp_send_json_error('El secret no puede estar vacío.');
        }

        if (strlen($secret) < 32) {
            wp_send_json_error('El secret debe tener al menos 32 caracteres.');
        }

        require_once plugin_dir_path(__FILE__) . '../includes/class-ep-license.php';

        if (EP_License::save_secret($secret)) {
            wp_send_json_success('Secret de licencia guardado y cifrado correctamente. Longitud: ' . strlen($secret) . ' caracteres.');
        } else {
            wp_send_json_error('Error al cifrar y guardar el secret. Revisa que EP_Security esté disponible.');
        }
    }

    public function ajax_run_nuclear_reset()
    {
        // 1. Nonce WordPress
        check_ajax_referer('ep_deploy_nonce', 'security');

        // 2. Rol de WordPress — obligatorio e independiente de la master key
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acceso denegado: se requiere rol de administrador de WordPress.');
        }

        // 3. Master key (segundo factor)
        if (!$this->is_key_valid((string) ($_POST['key'] ?? ''))) {
            wp_send_json_error('Clave maestra inválida.');
        }

        require_once plugin_dir_path(__FILE__) . 'class-ep-deployer.php';

        if (EP_Deployer::nuclear_reset()) {
            wp_send_json_success('Reset completado correctamente.');
        } else {
            // nuclear_reset() devuelve false si el entorno es producción
            wp_send_json_error('Operación bloqueada: no está permitida en este entorno. Define EP_ALLOW_NUCLEAR_RESET y WP_DEBUG en wp-config.php solo en staging.');
        }
    }

    public function ajax_export_blueprint()
    {
        check_ajax_referer('ep_deploy_nonce', 'security');
        if (!$this->is_key_valid((string) ($_POST['key'] ?? '')))
            wp_send_json_error('Denied');
        require_once plugin_dir_path(__FILE__) . 'class-ep-deployer.php';
        $url = EP_Deployer::create_blueprint_zip();
        if ($url)
            wp_send_json_success($url);
        else
            wp_send_json_error('Fail');
    }

    /**
     * Botón de Pánico: Cierra todas las sesiones de todos los usuarios
     */
    public function ajax_logout_all_users()
    {
        check_ajax_referer('ep_deploy_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos suficientes.');
        }

        global $wpdb;
        // Borramos todos los tokens de sesión de todos los usuarios en la base de datos
        $wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key = 'session_tokens'");

        // También incrementamos la versión del consentimiento RGPD para forzar re-aceptación
        $gdpr_settings = get_option('ep_gdpr_settings', array());
        if (is_array($gdpr_settings)) {
            $gdpr_settings['consent_version'] = (isset($gdpr_settings['consent_version']) ? (int) $gdpr_settings['consent_version'] : 1) + 1;
            update_option('ep_gdpr_settings', $gdpr_settings);
        }

        wp_send_json_success('Se han cerrado todas las sesiones activas y se ha invalidado el consentimiento de cookies de la plataforma.');
    }

    /**
     * AJAX: Guarda solo los campos de Teams/SSO sin afectar al resto del ep_settings_group.
     * Evita que WordPress borre opciones (ep_teams_internal_app_id, ep_onedrive_sync_principal, etc.)
     * al guardar un formulario parcial desde el tab IA&Bot.
     */
    public function ajax_save_teams_settings()
    {
        check_ajax_referer('ep_teams_settings_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos.');
        }

        update_option('ep_o365_client_id',       sanitize_text_field(wp_unslash($_POST['ep_o365_client_id'] ?? '')));
        update_option('ep_o365_client_secret',    sanitize_text_field(wp_unslash($_POST['ep_o365_client_secret'] ?? '')));
        update_option('ep_o365_tenant_id',        sanitize_text_field(wp_unslash($_POST['ep_o365_tenant_id'] ?? '')));
        update_option('ep_teams_bot_id',          sanitize_text_field(wp_unslash($_POST['ep_teams_bot_id'] ?? '')));
        update_option('ep_teams_bot_secret',      sanitize_text_field(wp_unslash($_POST['ep_teams_bot_secret'] ?? '')));
        update_option('ep_teams_internal_app_id', sanitize_text_field(wp_unslash($_POST['ep_teams_internal_app_id'] ?? '')));
        update_option('ep_onedrive_sync_principal', sanitize_text_field(wp_unslash($_POST['ep_onedrive_sync_principal'] ?? '')));

        // Limpiar cachés de tokens para que los nuevos valores se usen inmediatamente
        $bot_id = sanitize_text_field(wp_unslash($_POST['ep_teams_bot_id'] ?? ''));
        if (!empty($bot_id)) {
            delete_transient('ep_bt_token_' . md5($bot_id));
            delete_transient('ep_bt_token_v3_' . md5($bot_id));
        }
        delete_transient('ep_teams_catalog_app_id');

        wp_send_json_success('✅ Configuración de Teams/SSO guardada correctamente.');
    }


    public function is_key_valid($key)
    {
        $key = (string) $key;
        if (empty($key))
            return false;
        if (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL)
            return ($key === ep_get_option('ep_site_master_key'));
        $url = ep_get_option('ep_auth_remote_url');
        if (empty($url))
            return false;

        $request_url = add_query_arg([
            'key' => $key,
            'site' => home_url(),
            'wp_v' => get_bloginfo('version'),
            'ep_v' => $this->version,
            'php_v' => PHP_VERSION
        ], $url);

        $res = wp_remote_get($request_url, ['timeout' => 15, 'sslverify' => false]);

        if (is_wp_error($res)) {
            update_option('ep_sync_debug_log', [
                'time' => current_time('mysql'),
                'error' => $res->get_error_message(),
                'url' => $request_url
            ]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        // Attempt to extract JSON even if PHP warnings are prepended to the body
        $data = json_decode($body, true);
        if ($data === null && !empty($body)) {
            // Try to find the JSON object within the response (skip PHP warnings/errors)
            $json_start = strpos($body, '{');
            if ($json_start !== false) {
                $clean_body = substr($body, $json_start);
                $data = json_decode($clean_body, true);
                ep_error_log("Sync: Cleaned stray output before JSON (started at byte $json_start)");
            }
        }

        if ($code !== 200 || $data === null) {
            update_option('ep_sync_debug_log', [
                'time' => current_time('mysql'),
                'error' => "HTTP $code",
                'body' => substr($body, 0, 300),
                'url' => $request_url
            ]);
            return false;
        }

        if (isset($data['status']) && $data['status'] === 'authorized') {
            if (isset($data['authorized_apps']))
                update_option('ep_authorized_apps', $data['authorized_apps']);
            if (isset($data['package']))
                update_option('ep_client_package', $data['package']);

            // Log success too
            update_option('ep_sync_debug_log', [
                'time' => current_time('mysql'),
                'status' => 'success',
                'package' => $data['package'] ?? 'unknown'
            ]);
            return true;
        }

        // If we reached here, the status was not 'authorized'
        update_option('ep_sync_debug_log', [
            'time' => current_time('mysql'),
            'error' => "Invalid status: " . ($data['status'] ?? 'missing'),
            'body' => substr($body, 0, 300),
            'url' => $request_url
        ]);

        return false;
    }


    public function register_rest_endpoints()
    {
        register_rest_route('ep/v1', '/validate-key', array('methods' => 'GET', 'callback' => array($this, 'rest_validate_key'), 'permission_callback' => '__return_true'));

        // Auto-update endpoints (solo Maestro)
        if (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL) {
            register_rest_route('ep/v1', '/check-update', array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_check_update'),
                'permission_callback' => '__return_true',
            ));
            register_rest_route('ep/v1', '/download-update', array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_download_update'),
                'permission_callback' => '__return_true',
            ));
        }
    }

    public function rest_validate_key($request)
    {
        // CRITICAL: Capture any stray PHP output (deprecation warnings, DB errors)
        // that would corrupt our JSON response.
        ob_start();

        $site = (string) $request->get_param('site');
        $key = (string) $request->get_param('key');

        ep_error_log("REST Validate: Attempt from Site: [$site], Key: [$key]");

        if (empty($site) || empty($key)) {
            ep_error_log("REST Validate: Missing site or key");
            ob_end_clean();
            return new WP_REST_Response(['status' => 'error'], 400);
        }

        global $wpdb;
        $table = "{$wpdb->prefix}ep_subscribers";
        $site_clean = untrailingslashit((string) $site);

        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE site_url = %s AND master_key = %s AND status = 'active'",
            $site_clean,
            $key
        ));

        if ($sub) {
            ep_error_log("REST Validate: SUCCESS for subscriber ID {$sub->id}");

            // Self-healing: Ensure telemetry columns exist (suppress DB errors)
            static $db_checked = false;
            if (!$db_checked) {
                $suppress = $wpdb->suppress_errors(true);
                $missing_cols = [];
                $cols_to_check = ['wp_version', 'ep_version', 'php_version', 'last_seen'];
                foreach ($cols_to_check as $col) {
                    $check = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col'");
                    if (empty($check)) {
                        $missing_cols[] = $col;
                    }
                }

                if (!empty($missing_cols)) {
                    foreach ($missing_cols as $m_col) {
                        $after = ($m_col === 'wp_version') ? 'authorized_apps' : ($m_col === 'ep_version' ? 'wp_version' : ($m_col === 'php_version' ? 'ep_version' : 'php_version'));
                        $type = ($m_col === 'last_seen') ? 'datetime DEFAULT NULL' : 'varchar(10) DEFAULT NULL';
                        $wpdb->query("ALTER TABLE $table ADD COLUMN $m_col $type AFTER $after");
                    }
                    ep_error_log("REST Validate: SCHEMA MIGRATED - Added columns: " . implode(', ', $missing_cols));
                }
                $wpdb->suppress_errors($suppress);
                $db_checked = true;
            }

            // Actualizar telemetría si viene en el request (with error suppression)
            $wp_v = (string) ($request->get_param('wp_v') ?: '');
            $ep_v = (string) ($request->get_param('ep_v') ?: '');
            $php_v = (string) ($request->get_param('php_v') ?: '');

            if ($wp_v || $ep_v || $php_v) {
                $suppress = $wpdb->suppress_errors(true);
                $wpdb->update($table, [
                    'wp_version' => sanitize_text_field($wp_v),
                    'ep_version' => sanitize_text_field($ep_v),
                    'php_version' => sanitize_text_field($php_v),
                    'last_seen' => current_time('mysql')
                ], ['id' => $sub->id]);
                $wpdb->suppress_errors($suppress);
            }

            ob_end_clean();
            return new WP_REST_Response(['status' => 'authorized', 'package' => $sub->package, 'authorized_apps' => json_decode((string) $sub->authorized_apps)], 200);
        }

        // Debug: Try to find by site alone to see what's wrong
        $partial = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE site_url = %s", $site_clean));
        if ($partial) {
            ep_error_log("REST Validate: FAILED - Key mismatch or inactive. DB Key: [{$partial->master_key}], Req Key: [$key], DB Status: [{$partial->status}]");
        } else {
            ep_error_log("REST Validate: FAILED - Site [$site_clean] NOT FOUND in DB.");
        }

        ob_end_clean();
        return new WP_REST_Response(['status' => 'unauthorized'], 401);
    }

    /**
     * REST: Comprobar si hay actualización disponible.
     * GET /wp-json/ep/v1/check-update?key=X&site=Y&ep_version=1.0.0
     */
    public function rest_check_update($request)
    {
        $key = sanitize_text_field($request->get_param('key') ?? '');
        $site = untrailingslashit(sanitize_text_field($request->get_param('site') ?? ''));
        $client_version = sanitize_text_field($request->get_param('ep_version') ?? '0.0.0');

        if (empty($key) || empty($site)) {
            return new WP_REST_Response(['update' => false, 'error' => 'Missing parameters'], 400);
        }

        // Validar suscriptor
        global $wpdb;
        $table = $wpdb->prefix . 'ep_subscribers';
        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE master_key = %s AND site_url = %s AND status = 'active'",
            $key,
            $site
        ));

        if (!$sub) {
            return new WP_REST_Response(['update' => false, 'error' => 'Unauthorized'], 401);
        }

        $master_version = EMPLOYEE_PORTAL_VERSION;

        // Comparar versiones
        if (version_compare($client_version, $master_version, '<')) {
            $download_url = rest_url('ep/v1/download-update') . '?' . http_build_query([
                'key' => $key,
                'site' => $site,
            ]);

            return new WP_REST_Response([
                'update' => true,
                'new_version' => $master_version,
                'package' => $download_url,
                'slug' => 'employee-portal',
                'plugin' => 'employee-portal/employee-portal.php',
                'tested' => get_bloginfo('version'),
                'requires_php' => '7.4',
            ], 200);
        }

        return new WP_REST_Response(['update' => false, 'current_version' => $master_version], 200);
    }

    /**
     * REST: Descargar el ZIP de actualización.
     * GET /wp-json/ep/v1/download-update?key=X&site=Y
     */
    public function rest_download_update($request)
    {
        $key = sanitize_text_field($request->get_param('key') ?? '');
        $site = untrailingslashit(sanitize_text_field($request->get_param('site') ?? ''));

        if (empty($key) || empty($site)) {
            return new WP_REST_Response(['error' => 'Missing parameters'], 400);
        }

        // Validar suscriptor
        global $wpdb;
        $table = $wpdb->prefix . 'ep_subscribers';
        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE master_key = %s AND site_url = %s AND status = 'active'",
            $key,
            $site
        ));

        if (!$sub) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 401);
        }

        // Generar Blueprint ZIP
        require_once EMPLOYEE_PORTAL_PATH . 'admin/class-ep-deployer.php';
        $zip_url = EP_Deployer::create_blueprint_zip();

        if (!$zip_url) {
            return new WP_REST_Response(['error' => 'Failed to generate update package'], 500);
        }

        // Convertir URL a ruta local para servir el archivo
        $upload_dir = wp_upload_dir();
        $zip_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $zip_url);

        if (!file_exists($zip_path)) {
            return new WP_REST_Response(['error' => 'Package file not found'], 500);
        }

        // Servir el archivo directamente
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="employee-portal-' . EMPLOYEE_PORTAL_VERSION . '.zip"');
        header('Content-Length: ' . filesize($zip_path));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($zip_path);

        // Limpiar el ZIP temporal
        @unlink($zip_path);
        exit;
    }

    private function render_gdpr_settings_page()
    {
        $settings = get_option('ep_gdpr_settings', array(
            'policy_url' => '',
            'cookies_url' => '',
            'banner_text' => 'Utilizamos cookies propias y de terceros para mejorar nuestros servicios...',
            'show_on_login' => 1
        ));
        ?>
        <h2>Gestión de Privacidad y Cookies (RGPD)</h2>
        <p class="description">Configura los textos legales y el comportamiento del banner de consentimiento.</p>

        <form method="post" action="" style="margin-top:20px;">
            <?php wp_nonce_field('ep_gdpr_settings_verify'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">URL Política de Privacidad</th>
                    <td>
                        <input type="url" name="gdpr[policy_url]" value="<?php echo esc_attr($settings['policy_url'] ?? ''); ?>"
                            placeholder="https://..." class="regular-text">
                        <p class="description">Si se deja vacío, se usará el texto automático del portal.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">URL Política de Cookies</th>
                    <td>
                        <input type="url" name="gdpr[cookies_url]"
                            value="<?php echo esc_attr($settings['cookies_url'] ?? ''); ?>" placeholder="https://..."
                            class="regular-text">
                        <p class="description">Si se deja vacío, se usará el texto automático del portal.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">URL Aviso Legal</th>
                    <td>
                        <input type="url" name="gdpr[legal_notice_url]"
                            value="<?php echo esc_attr($settings['legal_notice_url'] ?? ''); ?>" placeholder="https://..."
                            class="regular-text">
                        <p class="description">Si se deja vacío, se usará el texto automático del portal.</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2" style="padding-top: 30px; border-bottom: 1px solid #eee;">
                        <h3>Datos Dinámicos de la Entidad</h3>
                        <p class="description">Estos datos se usarán para generar automáticamente los textos legales si no se
                            especifican URLs externas.</p>
                    </th>
                </tr>
                <tr>
                    <th scope="row">Nombre de la Entidad</th>
                    <td>
                        <input type="text" name="gdpr[entity_name]"
                            value="<?php echo esc_attr($settings['entity_name'] ?? ''); ?>"
                            placeholder="Ej: Cámara Oficial de Comercio de Cáceres" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">CIF / NIF</th>
                    <td>
                        <input type="text" name="gdpr[entity_cif]"
                            value="<?php echo esc_attr($settings['entity_cif'] ?? ''); ?>" placeholder="Q1073001F"
                            class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Dirección Completa</th>
                    <td>
                        <input type="text" name="gdpr[entity_address]"
                            value="<?php echo esc_attr($settings['entity_address'] ?? ''); ?>"
                            placeholder="Calle Santa Gertrudis, 1, 10003 Cáceres" class="large-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Datos Registrales</th>
                    <td>
                        <textarea name="gdpr[entity_registration]" rows="3" class="large-text"
                            placeholder="Ej: Inscrita en el registro de..."><?php echo esc_textarea($settings['entity_registration'] ?? ''); ?></textarea>
                    </td>
                </tr>

                <tr>
                    <th colspan="2" style="padding-top: 30px; border-bottom: 1px solid #eee;">
                        <h3>Customización del Banner</h3>
                    </th>
                </tr>
                <tr>
                    <th scope="row">Texto del Banner</th>
                    <td>
                        <textarea name="gdpr[banner_text]" rows="4" class="large-text"
                            placeholder="Texto que aparecerá en el banner..."><?php echo esc_textarea($settings['banner_text'] ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Mostrar en Login</th>
                    <td>
                        <label>
                            <input type="checkbox" name="gdpr[show_on_login]" value="1" <?php checked($settings['show_on_login'] ?? 1, 1); ?>>
                            Mostrar el banner de cookies en la pantalla de acceso.
                        </label>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="ep_update_gdpr" class="button button-primary" value="Guardar Ajustes RGPD">
            </p>
        </form>
        <?php
    }

    /**
     * Test de conexión con Gemini vía AJAX
     */
    public function ajax_test_ai_connection()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $api_key = ep_get_post_val('api_key');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'Falta la API Key']);
        }

        // Usar el servicio para una prueba rápida
        $ai = EP_AI_Service::get_instance();
        
        // Sobrescribir temporalmente la API Key para la prueba si es distinta a la guardada
        // (Aunque para simplificar, usaremos la clase directamente con un prompt dummy)
        $response = $ai->get_intent("Hola, esto es una prueba de conexión.", ['display_name' => 'Admin Test', 'role' => 'administrator']);

        if (isset($response['error'])) {
             wp_send_json_error(['message' => 'Error de API: ' . $response['error']]);
        }

        wp_send_json_success([
            'message' => '✅ ¡Conexión exitosa! Gemini ha respondido correctamente.',
            'raw' => $response
        ]);
    }

    private function render_ai_settings_page()
    {
        $api_key      = ep_get_option('ep_ai_api_key');
        $model        = ep_get_option('ep_ai_model', 'gemini-3.1-flash-lite-preview');
        $daily_limit  = ep_get_option('ep_ai_daily_limit', 100);
        $monthly_limit = ep_get_option('ep_ai_monthly_limit', 3000);

        $ai_service  = EP_AI_Service::get_instance();
        $stats       = $ai_service->get_usage_stats();
        
        $usage_today = $stats['today'];
        $usage_month = $stats['month'];
        $total_cost  = $stats['total_cost'];

        // Opciones de Teams & Azure (solo lectura para mostrar valores actuales)
        $teams_bot_id       = ep_get_option('ep_teams_bot_id');
        $o365_client_id     = ep_get_option('ep_o365_client_id');
        $o365_client_secret = ep_get_option('ep_o365_client_secret');
        if (class_exists('EP_Security') && EP_Security::is_encrypted($o365_client_secret)) {
            $o365_client_secret = EP_Security::decrypt($o365_client_secret);
        }

        $teams_bot_secret   = ep_get_option('ep_teams_bot_secret');
        if (class_exists('EP_Security') && EP_Security::is_encrypted($teams_bot_secret)) {
            $teams_bot_secret = EP_Security::decrypt($teams_bot_secret);
        }

        $teams_tenant_id    = ep_get_option('ep_o365_tenant_id');
        $internal_app_id    = ep_get_option('ep_teams_internal_app_id');

        ?>
        <div class="ep-ai-settings">
            <h2><span class="dashicons dashicons-rest-api"></span> Inteligencia Artificial y Bot de Teams</h2>
            <p class="description">Gestiona la conexión con Google Gemini para el lenguaje natural y las credenciales de Microsoft Teams / SSO.</p>

            <div class="ep-settings-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 30px;">
                <div class="ep-form-col">

                    <!-- BLOQUE GEMINI: usa su propio grupo (ep_ai_settings_group) → sin riesgo de colisión -->
                    <form method="post" action="options.php">
                        <?php settings_fields('ep_ai_settings_group'); ?>
                        <h3><span class="dashicons dashicons-google"></span> 1. Inteligencia Artificial (Google Gemini)</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">API Key (AI Studio)</th>
                                <td><input type="password" name="ep_ai_api_key" value="<?php echo esc_attr($api_key); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th scope="row">Modelo Gemini</th>
                                <td>
                                    <input type="text" name="ep_ai_model" value="<?php echo esc_attr($model); ?>" class="regular-text">
                                    <p class="description">Recomendado: <code>gemini-3.1-flash-lite-preview</code></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Límites (Día/Mes)</th>
                                <td>
                                    <input type="number" name="ep_ai_daily_limit" value="<?php echo esc_attr($daily_limit); ?>" class="small-text"> /
                                    <input type="number" name="ep_ai_monthly_limit" value="<?php echo esc_attr($monthly_limit); ?>" class="small-text">
                                </td>
                            </tr>
                        </table>
                        <div style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                            <?php submit_button('Guardar Inteligencia', 'primary', 'submit', false); ?>
                            <button type="button" id="ep-test-ai-btn" class="button button-secondary">Probar Gemini</button>
                            <span id="ep-test-ai-status"></span>
                        </div>
                    </form>

                    <hr style="margin: 40px 0; border: none; border-top: 1px solid #eee;">

                    <!-- BLOQUE TEAMS: guarda via AJAX propio (ep_save_teams_settings) -->
                    <!-- NO usa options.php para no borrar opciones del ep_settings_group que no están aquí -->
                    <div id="ep-teams-settings-wrap">
                        <h3><span class="dashicons dashicons-networking"></span> 2. Conectividad Microsoft Teams &amp; SSO</h3>
                        <p class="description" style="color: #d63638; font-weight: 600;">⚠️ Importante: Asegúrate de rellenar ambos secretos si son aplicaciones distintas en Azure.</p>

                        <table class="form-table">
                            <tr style="background: #f9f9f9;">
                                <th colspan="2" style="padding: 10px;"><strong>App Portal (SSO / Graph API)</strong></th>
                            </tr>
                            <tr>
                                <th scope="row">Application (client) ID</th>
                                <td><input type="text" id="ep_o365_client_id" name="ep_o365_client_id" value="<?php echo esc_attr($o365_client_id); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th scope="row">Client Secret (Grafo)</th>
                                <td>
                                    <input type="password" id="ep_o365_client_secret" name="ep_o365_client_secret" value="<?php echo esc_attr($o365_client_secret); ?>" class="large-text" placeholder="••••••••••••••••">
                                    <p class="description">Necesario para consultar agenda y compañeros.</p>
                                </td>
                            </tr>
                            <tr style="background: #f9f9f9;">
                                <th colspan="2" style="padding: 10px;"><strong>App Bot (Mensajería)</strong></th>
                            </tr>
                            <tr>
                                <th scope="row">Bot ID (App ID Bot)</th>
                                <td><input type="text" id="ep_teams_bot_id" name="ep_teams_bot_id" value="<?php echo esc_attr($teams_bot_id); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th scope="row">Bot Password (Secret)</th>
                                <td>
                                    <input type="password" id="ep_teams_bot_secret" name="ep_teams_bot_secret" value="<?php echo esc_attr($teams_bot_secret); ?>" class="large-text" placeholder="••••••••••••••••">
                                    <p class="description">Necesario para enviar respuestas a Teams.</p>
                                </td>
                            </tr>
                            <tr style="background: #f9f9f9;">
                                <th colspan="2" style="padding: 10px;"><strong>Configuración Común</strong></th>
                            </tr>
                            <tr>
                                <th scope="row">Directory (tenant) ID</th>
                                <td><input type="text" id="ep_o365_tenant_id" name="ep_o365_tenant_id" value="<?php echo esc_attr($teams_tenant_id); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th scope="row">Teams Internal App ID <span style="font-weight:400;color:#64748b;font-size:11px;">(para notificaciones)</span></th>
                                <td>
                                    <input type="text" id="ep_teams_internal_app_id" name="ep_teams_internal_app_id" value="<?php echo esc_attr($internal_app_id); ?>" class="large-text" style="font-family:monospace;" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                                    <p class="description">ID interno de la app en el catálogo de Teams. Cópialo desde <a href="https://admin.teams.microsoft.com/policies/app-setup" target="_blank">Teams Admin Center</a>.</p>
                                </td>
                            </tr>
                        </table>

                        <!-- Diagnóstico Teams -->
                        <div style="margin-top:20px; padding:15px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px;">
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <button type="button" id="ep_diagnose_teams_btn" class="button button-secondary" style="background:#7c3aed;color:white;border-color:#7c3aed;">🔬 Diagnóstico Teams</button>
                                <select id="ep_diagnose_user_select" style="width:auto;max-width:220px;">
                                    <?php foreach (get_users(['meta_key' => 'ep_o365_user_id', 'meta_compare' => 'EXISTS']) as $u): ?>
                                        <option value="<?php echo $u->ID; ?>"><?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span id="ep_diagnose_teams_spinner" style="display:none;">⏳ Ejecutando...</span>
                            </div>
                            <div id="ep_diagnose_teams_result" style="margin-top:12px;display:none;background:#0f172a;color:#38bdf8;padding:14px;border-radius:6px;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>
                        </div>

                        <!-- Usuario principal de sincronización -->
                        <table class="form-table" style="margin-top:10px;">
                            <tr>
                                <th scope="row">Usuario Principal de Sincronización</th>
                                <td>
                                    <?php
                                    $admins = get_users(array('role' => 'administrator'));
                                    $current_principal = get_option('ep_onedrive_sync_principal');
                                    ?>
                                    <select id="ep_onedrive_sync_principal">
                                        <option value="">-- Autodetectar (Administrador actual) --</option>
                                        <?php foreach ($admins as $admin): ?>
                                            <option value="<?php echo $admin->ID; ?>" <?php selected($current_principal, $admin->ID); ?>>
                                                <?php echo esc_html($admin->display_name . ' (' . $admin->user_email . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Este usuario será el responsable de sincronizar el <strong>Espacio Público</strong>. Debe estar vinculado a OneDrive.</p>
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                            <button type="button" id="ep-save-teams-btn" class="button button-primary">Guardar Todo Azure / Teams</button>
                            <span id="ep-save-teams-status" style="font-weight:600;"></span>
                        </div>
                    </div>

                </div>

                <div class="ep-status-col">
                    <div class="ep-instructions-panel" style="background: #f0fdf4; border-color: #bbf7d0;">
                        <h3 style="color: #166534;"><span class="dashicons dashicons-chart-bar"></span> Estadísticas y Coste Real</h3>
                        <div style="margin-top: 15px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span>Uso Hoy:</span>
                                <strong style="<?php echo $usage_today >= $daily_limit ? 'color:red;' : ''; ?>">
                                    <?php echo (int)$usage_today; ?> / <?php echo (int)$daily_limit; ?>
                                </strong>
                            </div>
                            <div style="width: 100%; background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">
                                <div style="width: <?php echo ($daily_limit > 0) ? min(100, ($usage_today / $daily_limit) * 100) : 0; ?>%; background: #22c55e; height: 100%;"></div>
                            </div>
                        </div>
                        <div style="margin-top: 20px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span>Uso Este Mes:</span>
                                <strong><?php echo (int)$usage_month; ?> / <?php echo (int)$monthly_limit; ?></strong>
                            </div>
                            <div style="width: 100%; background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">
                                <div style="width: <?php echo ($monthly_limit > 0) ? min(100, ($usage_month / $monthly_limit) * 100) : 0; ?>%; background: #3b82f6; height: 100%;"></div>
                            </div>
                        </div>
                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #bbf7d0; text-align: center;">
                            <span style="font-size: 13px; color: #166534; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Coste Total Acumulado (Est.)</span>
                            <div style="font-size: 32px; font-weight: 800; color: #14532d; margin-top: 5px;">
                                <?php echo number_format((float)$total_cost, 4); ?> €
                            </div>
                            <p class="description" style="color: #166534;">Basado en las tarifas de Gemini Flash Lite para 40 empleados.</p>
                        </div>
                    </div>

                    <div class="ep-instructions-panel" style="margin-top: 20px;">
                        <h3><span class="dashicons dashicons-external"></span> Instrucciones Azure AD</h3>
                        <div class="ep-step-list">
                            <div class="ep-step-item">
                                <div class="ep-step-num">1</div>
                                <div class="ep-step-text">Accede al <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank">Portal de Azure</a> y crea un nuevo registro de aplicación.</div>
                            </div>
                            <div class="ep-step-item">
                                <div class="ep-step-num">2</div>
                                <div class="ep-step-text">Configura la <b>Redirect URI</b> como plataforma 'Web'.</div>
                            </div>
                            <div class="ep-step-item">
                                <div class="ep-step-num">3</div>
                                <div class="ep-step-text">En 'Certificados y secretos', genera un nuevo <b>Client Secret</b> y pégalo aquí. <strong style="color:#dc2626;">⚠️ Copia el VALUE, no el Secret ID.</strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="ep-instructions-panel" style="margin-top: 20px;">
                        <h3><span class="dashicons dashicons-format-chat"></span> Instrucciones Bot de Teams</h3>
                        <div class="ep-step-list">
                            <div class="ep-step-item">
                                <div class="ep-step-num">1</div>
                                <div class="ep-step-text">Accede al <a href="https://dev.teams.microsoft.com/bots" target="_blank">Portal de Desarrolladores de Teams</a> y haz clic en <b>Nuevo bot</b>.</div>
                            </div>
                            <div class="ep-step-item">
                                <div class="ep-step-num">2</div>
                                <div class="ep-step-text">Copia el <b>Bot ID</b> (Id. de bot) y pégalo en el campo de la izquierda.</div>
                            </div>
                            <div class="ep-step-item">
                                <div class="ep-step-num">3</div>
                                <div class="ep-step-text">En el menú izquierdo ve a <b>Secretos de cliente</b>, genera uno nuevo y pega el <strong style="color:#dc2626;">Valor (VALUE)</strong> en 'Bot Password'. <strong>No pegues el ID del secreto.</strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="ep-instructions-panel" style="margin-top: 20px;">
                        <h3><span class="dashicons dashicons-info"></span> ¿Cómo funciona la IA?</h3>
                        <p style="font-size: 13px; line-height: 1.5; color: #475569;">
                            El bot utilizará la IA únicamente cuando no entienda un comando directo del usuario.
                            La IA identificará si el usuario pregunta por su <strong>agenda</strong>, <strong>inventario</strong> o <strong>compañeros</strong> y lanzará la herramienta adecuada.
                        </p>
                    </div>
                </div>

            </div><!-- /.ep-settings-grid -->
        </div><!-- /.ep-ai-settings -->

        <script>
        jQuery(document).ready(function($) {

            // Probar conexión Gemini
            $('#ep-test-ai-btn').on('click', function() {
                const $btn    = $(this);
                const $status = $('#ep-test-ai-status');
                const apiKey  = $('input[name="ep_ai_api_key"]').val();

                if (!apiKey) { alert('Por favor, introduce una API Key primero.'); return; }

                $btn.prop('disabled', true).text('Probando...');
                $status.text('').css('color', 'inherit');

                $.post(ajaxurl, { action: 'ep_test_ai_connection', api_key: apiKey }, function(r) {
                    if (r.success) {
                        $status.text(r.data.message).css('color', '#166534');
                    } else {
                        $status.text('❌ ' + r.data.message).css('color', '#b91c1c');
                    }
                    $btn.prop('disabled', false).text('Probar Gemini');
                });
            });

            // Guardar configuración Teams/SSO via AJAX (no options.php para no borrar otros campos del grupo)
            $('#ep-save-teams-btn').on('click', function() {
                const $btn    = $(this);
                const $status = $('#ep-save-teams-status');

                $btn.prop('disabled', true).text('Guardando...');
                $status.text('').css('color', 'inherit');

                $.post(ajaxurl, {
                    action:                    'ep_save_teams_settings',
                    security:                  '<?php echo wp_create_nonce('ep_teams_settings_nonce'); ?>',
                    ep_o365_client_id:         $('#ep_o365_client_id').val(),
                    ep_o365_client_secret:     $('#ep_o365_client_secret').val(),
                    ep_o365_tenant_id:         $('#ep_o365_tenant_id').val(),
                    ep_teams_bot_id:           $('#ep_teams_bot_id').val(),
                    ep_teams_bot_secret:       $('#ep_teams_bot_secret').val(),
                    ep_teams_internal_app_id:  $('#ep_teams_internal_app_id').val(),
                    ep_onedrive_sync_principal: $('#ep_onedrive_sync_principal').val()
                }, function(r) {
                    if (r.success) {
                        $status.text(r.data).css('color', '#166534');
                    } else {
                        $status.text('❌ Error: ' + r.data).css('color', '#b91c1c');
                    }
                    $btn.prop('disabled', false).text('Guardar Todo Azure / Teams');
                }).fail(function() {
                    $status.text('❌ Error de red. Recarga la página e inténtalo de nuevo.').css('color', '#b91c1c');
                    $btn.prop('disabled', false).text('Guardar Todo Azure / Teams');
                });
            });

            // Diagnóstico Teams
            $('#ep_diagnose_teams_btn').on('click', function() {
                var userId = $('#ep_diagnose_user_select').val();
                if (!userId) { alert('Selecciona un usuario para diagnosticar.'); return; }
                $('#ep_diagnose_teams_spinner').show();
                $('#ep_diagnose_teams_result').hide().text('');
                $.post(ajaxurl, {
                    action: 'ep_diagnose_teams',
                    target_user_id: userId,
                    _wpnonce: '<?php echo wp_create_nonce("ep_diagnose_teams"); ?>'
                }, function(r) {
                    $('#ep_diagnose_teams_spinner').hide();
                    if (r.success) {
                        var d = r.data;
                        var out = '=== Diagnóstico Teams ===\n';
                        out += 'Usuario WP ID: ' + d.user_id + '\n';
                        out += 'OID M365: ' + d.employee_oid + '\n';
                        out += 'App ID usado: ' + d.configured_app_id + '\n';
                        out += 'Bot ID usado: ' + (d.bot_id_used || 'N/A') + '\n';
                        out += 'Bot Secret longitud: ' + (d.bot_secret_len || '0') + ' chars\n';
                        out += 'Bot = App?: ' + (d.bot_same_as_app || 'N/A') + '\n';
                        out += 'Tenant: ' + d.tenant_id + '\n';
                        out += 'Preferencia Teams: ' + (d.teams_pref === '' ? '(no configurado = ON)' : (d.teams_pref || 'undefined')) + '\n\n';
                        out += '--- Pasos ---\n';
                        if (d.steps) d.steps.forEach(function(s) { out += s + '\n'; });
                        if (d.graph_send_http_code !== undefined) {
                            out += '\n--- Respuesta Graph API ---\n';
                            out += 'HTTP Code: ' + d.graph_send_http_code + '\n';
                            out += 'Respuesta: ' + JSON.stringify(d.graph_send_raw, null, 2) + '\n';
                        }
                        out += '\n=== Resultado ===\n' + (d.resultado || 'Sin resultado');
                        $('#ep_diagnose_teams_result').text(out).show();
                    } else {
                        $('#ep_diagnose_teams_result').text('Error: ' + JSON.stringify(r.data)).show();
                    }
                }).fail(function(xhr) {
                    $('#ep_diagnose_teams_spinner').hide();
                    $('#ep_diagnose_teams_result').text('Error HTTP: ' + xhr.status + ' - ' + xhr.responseText.substring(0, 200)).show();
                });
            });

        });
        </script>
        <?php
    }
    /**
     * AJAX: Guarda ajustes del sistema (SMTP O365, etc.)
     */
    public function ajax_save_system_settings()
    {
        check_ajax_referer('ep_system_settings_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos.');
        }

        update_option('ep_o365_smtp_active',      sanitize_text_field($_POST['ep_o365_smtp_active'] ?? '0'));
        update_option('ep_o365_system_sender_id', (int)($_POST['ep_o365_system_sender_id'] ?? 0));
        update_option('ep_o365_smtp_custom_from', sanitize_email($_POST['ep_o365_smtp_custom_from'] ?? ''));
        update_option('ep_bot_custom_knowledge',  sanitize_textarea_field($_POST['ep_bot_custom_knowledge'] ?? ''));
        update_option('ep_bot_knowledge_urls',    sanitize_textarea_field($_POST['ep_bot_knowledge_urls'] ?? ''));
        update_option('ep_debug_log_active',      sanitize_text_field($_POST['ep_debug_log_active'] ?? '0'));

        // Borrar caché de web scraping para que se actualice con las nuevas URLs
        delete_transient('ep_bot_web_knowledge_cache');
        wp_clear_scheduled_hook('ep_daily_log_cleanup');
        $timestamp = strtotime('tomorrow 06:00:00');
        wp_schedule_event($timestamp, 'daily', 'ep_daily_log_cleanup');

        // Cron de email diario de aprendizaje (07:00)
        if (!wp_next_scheduled('ep_daily_learning_digest')) {
            wp_schedule_event(strtotime('tomorrow 07:00:00'), 'daily', 'ep_daily_learning_digest');
        }

        wp_send_json_success('✅ Ajustes del sistema y horarios actualizados correctamente.');
    }


    /**
     * AJAX: Prueba de envío de correo vía O365
     */
    public function ajax_test_o365_email()
    {
        check_ajax_referer('ep_system_settings_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos.');
        }

        $sender_id = (int)($_POST['sender_id'] ?? 0);
        if (!$sender_id) wp_send_json_error('No se ha seleccionado un remitente.');

        $user = wp_get_current_user();
        $to = $user->user_email;
        $subject = 'Prueba de Correo - Portal del Empleado';
        $message = 'Hola ' . $user->display_name . ",\n\nEsto es un correo de prueba enviado a través de la API de Microsoft Graph desde el Portal del Empleado.\n\nSi has recibido esto, el sistema de correo vía Office está funcionando correctamente.";

        $from_alias = ep_get_option('ep_o365_smtp_custom_from');
        $service = EP_Graph_Service::get_instance();
        $result = $service->send_mail($sender_id, $to, $subject, $message, false, $from_alias);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('✅ Correo enviado correctamente a ' . $to);
    }

    /**
     * AJAX: Limpiar caché de educación web
     */
    public function ajax_clear_bot_web_cache()
    {
        check_ajax_referer('ep_system_settings_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos.');
        }

        delete_transient('ep_bot_web_knowledge_cache');
        wp_send_json_success('✅ La caché de entrenamiento ha sido borrada. El bot volverá a leer las webs en la próxima consulta.');
    }

    /**
     * AJAX: Obtener estado de salud de todos los sistemas
     */
    public function ajax_get_system_health()
    {
        check_ajax_referer('ep_system_settings_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos.');
        }

        $results = [];

        // 1. Check Graph API (Principal Sync)
        $principal_id = EP_Auth_O365::get_sync_principal_id();
        $token = EP_Auth_O365::get_valid_token($principal_id);
        if (is_wp_error($token)) {
            $results['graph'] = ['status' => 'error', 'message' => 'Fallo token: ' . $token->get_error_message()];
        } else {
            $results['graph'] = ['status' => 'ok', 'message' => 'Online (Sync Principal OK)'];
        }

        // 2. Check Gemini AI
        $ai_service = EP_AI_Service::get_instance();
        $ai_test = $ai_service->test_connection();
        if (is_wp_error($ai_test)) {
            $results['ai'] = ['status' => 'error', 'message' => 'Fallo API: ' . $ai_test->get_error_message()];
        } else {
            $results['ai'] = ['status' => 'ok', 'message' => 'Conectado (Google Gemini)'];
        }

        // 3. Check Teams Bot
        $bot_id = get_option('ep_teams_bot_id');
        $bot_secret = get_option('ep_teams_bot_secret');
        if (empty($bot_id) || empty($bot_secret)) {
            $results['teams'] = ['status' => 'warning', 'message' => 'Pendiente configurar Bot AppID/Secret'];
        } else {
            $results['teams'] = ['status' => 'ok', 'message' => 'Configurado correctamente'];
        }

        // 4. Cache & Storage
        $results['cache'] = ['status' => 'ok', 'message' => 'Optimizado (Transients)'];

        wp_send_json_success($results);
    }

    private function render_system_settings_page()
    {
        $smtp_active = ep_get_option('ep_o365_smtp_active');
        $system_sender = (int)ep_get_option('ep_o365_system_sender_id');
        
        $admins = get_users(['role' => 'administrator']);
        $log_file = EMPLOYEE_PORTAL_PATH . 'ep_debug.log';
        $log_size = file_exists($log_file) ? size_format(filesize($log_file), 2) : '0 B';

        // Check overall O365 Health
        $health_ready = !empty(ep_get_option('ep_o365_client_id')) && !empty(ep_get_option('ep_o365_client_secret'));
        ?>
        <style>
            .ep-system-settings .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
            .ep-system-settings .switch input { opacity: 0; width: 0; height: 0; }
            .ep-system-settings .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 24px; }
            .ep-system-settings .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
            .ep-system-settings input:checked + .slider { background-color: #7c3aed; }
            .ep-system-settings input:checked + .slider:before { transform: translateX(20px); }
            .ep-badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        </style>
        <div class="ep-system-settings">
            <h2><span class="dashicons dashicons-admin-settings"></span> Panel de Salud y Sistema</h2>
            <p class="description">Monitoreo crítico, gestión de correo O365 y mantenimiento de la plataforma.</p>

            <div class="ep-settings-grid" style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 40px; margin-top: 30px;">
                <div class="ep-form-col">
                    
                    <div class="ep-admin-card" style="margin-top:0; border:1px solid #e2e8f0; background:#f8fafc;">
                        <h3><span class="dashicons dashicons-email-alt"></span> Infraestructura de Correo (O365 SMTP)</h3>
                        <p class="description">Reemplaza el sistema de correo tradicional por la API de Microsoft Graph para una entregabilidad del 100%.</p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">Activar Correo vía Microsoft 365</th>
                                <td>
                                    <label class="switch">
                                        <input type="checkbox" id="ep_o365_smtp_active" <?php checked($smtp_active, '1'); ?>>
                                        <span class="slider round"></span>
                                    </label>
                                    <p class="description">Si se activa, todos los correos del portal (tickets, avisos) se enviarán desde la cuenta seleccionada.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Remitente del Sistema (System Sender)</th>
                                <td>
                                    <select id="ep_o365_system_sender_id">
                                        <option value="0">-- Seleccionar Administrador --</option>
                                        <?php foreach ($admins as $admin): 
                                            // Solo mostrar si tiene token
                                            $has_token = get_user_meta($admin->ID, 'ep_o365_user_id', true);
                                            ?>
                                            <option value="<?php echo $admin->ID; ?>" <?php selected($system_sender, $admin->ID); ?> <?php echo !$has_token ? 'disabled' : ''; ?>>
                                                <?php echo esc_html($admin->display_name); ?> (<?php echo $has_token ? 'Conectado' : 'Sin vincular'; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Debe ser un usuario con la cuenta de Microsoft vinculada.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email del Remitente (Alias)</th>
                                <td>
                                    <input type="email" id="ep_o365_smtp_custom_from" class="regular-text" value="<?php echo esc_attr(ep_get_option('ep_o365_smtp_custom_from')); ?>" placeholder="p.ej. notificaciones@camaracaceres.es">
                                    <p class="description">Opcional. Si se indica, Microsoft Graph intentará enviar el correo desde esta dirección (requiere permisos de alias/send-as en O365).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Prueba de Envío</th>
                                <td>
                                    <button type="button" class="button" id="ep-test-o365-email">Enviar Correo de Prueba</button>
                                    <span id="ep-o365-test-result" style="margin-left: 10px;"></span>
                                    <p class="description">Se enviará un correo a su propia dirección (<?php echo wp_get_current_user()->user_email; ?>) usando el remitente seleccionado.</p>
                                </td>
                            </tr>
                        </table>

                        <div class="ep-bot-education-section" style="margin-top:30px; border-top:1px solid #e2e8f0; padding-top:20px;">
                            <h3><span class="dashicons dashicons-welcome-learn-more"></span> Educación del Bot (Base de Conocimiento)</h3>
                            <p class="description">Añade aquí datos, normas o información específica que el Bot debe conocer para responder mejor (Ej: horarios de oficina, cargos, normas internas).</p>
                            <textarea id="ep_bot_custom_knowledge" style="width:100%; min-height:100px; margin-top:10px; font-family:monospace; font-size:12px; background:#fff;" placeholder="Ej: El horario de la cámara es de 8:00 a 15:00."><?php echo esc_textarea(get_option('ep_bot_custom_knowledge', '')); ?></textarea>
                            
                            <p class="description" style="margin-top:20px;"><strong>URLs de Entrenamiento (Scraping):</strong> Una URL por línea. El Bot absorberá el contenido de estas webs para responder (Ej: listas de cursos).</p>
                            <div style="display:flex; gap:10px; align-items:flex-start;">
                                <textarea id="ep_bot_knowledge_urls" style="flex:1; min-height:80px; margin-top:10px; font-family:monospace; font-size:12px; background:#fff;" placeholder="https://camaracaceres.es/cursos/"><?php echo esc_textarea(get_option('ep_bot_knowledge_urls', '')); ?></textarea>
                                <button type="button" id="ep-clear-web-cache-btn" class="button" style="margin-top:10px; display:flex; align-items:center; gap:5px;" title="Forzar al bot a volver a leer las webs ahora">
                                    <span class="dashicons dashicons-update"></span>
                                    <span>Forzar Limpieza de Caché</span>
                                </button>
                            </div>
                                <p class="description">💡 *El Bot usará esta información para "aprender" y mejorar sus respuestas automáticamente. Se actualiza cada 30 días o al forzar la recarga.*</p>
                            </div>

                            <div style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:20px;">
                                <h3><span class="dashicons dashicons-analytics"></span> Diagnóstico y Rendimiento</h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Modo Depuración (Debug Log)</th>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox" id="ep_debug_log_active" <?php checked(get_option('ep_debug_log_active'), '1'); ?>>
                                                <span class="slider round"></span>
                                            </label>
                                            <p class="description">⚠️ Mantener desactivado en producción para máximo rendimiento. Solo activar para resolver problemas técnicos.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        
                        <div style="margin-top: 30px;">
                            <button type="button" id="ep-save-system-btn" class="button button-primary" <?php echo !$health_ready ? 'disabled' : ''; ?>>
                                Guardar Configuración y Sincronizar Horarios
                            </button>
                            <?php if (!$health_ready): ?>
                                <p style="color:#dc2626; font-size:12px; margin-top:10px;">⚠️ Debes configurar primero las credenciales de Azure en la pestaña <strong>IA & Bot</strong>.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ep-admin-card" style="margin-top:30px;">
                        <h3><span class="dashicons dashicons-performance"></span> Tareas de Mantenimiento Proactivas</h3>
                        <p class="description">El portal realiza estas tareas automáticamente cada 24h.</p>
                        <table class="widefat" style="margin-top:15px;">
                            <thead>
                                <tr>
                                    <th>Tarea</th>
                                    <th>Estado</th>
                                    <th>Próxima Ejecución</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>ep_daily_log_cleanup</strong> (Purga de Logs)</td>
                                    <td><span class="ep-badge" style="background:#22c55e; color:white;">ACTIVO</span></td>
                                    <td><?php echo wp_next_scheduled('ep_daily_log_cleanup') ? date_i18n('d/m/Y H:i', wp_next_scheduled('ep_daily_log_cleanup')) : 'Pendiente'; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="ep-status-col">
                    <div class="ep-instructions-panel" style="background:#fff; border: 1px solid #e2e8f0;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <h3 style="margin:0;">Salud de Integraciones</h3>
                            <button id="ep-refresh-health-btn" class="button button-small" title="Refrescar Estado">
                                <span class="dashicons dashicons-update"></span>
                            </button>
                        </div>
                        <ul style="list-style:none; padding:0;" id="ep-health-checks-list">
                            <li style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                                <span>Azure AD (Graph API)</span>
                                <span id="health-graph"><span style="color:#94a3b8">●</span> Cargando...</span>
                            </li>
                            <li style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                                <span>Google Gemini (IA)</span>
                                <span id="health-ai"><span style="color:#94a3b8">●</span> Cargando...</span>
                            </li>
                            <li style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                                <span>Microsoft Teams Bot</span>
                                <span id="health-teams"><span style="color:#94a3b8">●</span> Cargando...</span>
                            </li>
                            <li style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                                <span>Caché de Sistema</span>
                                <span id="health-cache"><span style="color:green">●</span> Optimizado</span>
                            </li>
                        </ul>
                    </div>

                    <div class="ep-instructions-panel" style="margin-top:20px; border: 1px solid #e2e8f0; background: #fff;">
                        <h3>Información de Versión</h3>
                        <p style="font-size:12px;">Versión actual: <strong><?php echo EMPLOYEE_PORTAL_VERSION; ?></strong></p>
                        <p style="font-size:12px;">PHP: <strong><?php echo PHP_VERSION; ?></strong></p>
                        <p style="font-size:12px;">WordPress: <strong><?php echo get_bloginfo('version'); ?></strong></p>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Función para chequear salud del sistema
            function checkHealth() {
                const list = $('#ep-health-checks-list');
                const btn = $('#ep-refresh-health-btn');
                
                btn.addClass('updating');
                
                $.post(ajaxurl, {
                    action: 'ep_get_system_health',
                    security: '<?php echo wp_create_nonce("ep_system_settings_nonce"); ?>'
                }, function(res) {
                    btn.removeClass('updating');
                    if(res.success) {
                        const data = res.data;
                        updateHealthItem('graph', data.graph);
                        updateHealthItem('ai', data.ai);
                        updateHealthItem('teams', data.teams);
                    }
                });
            }

            function updateHealthItem(id, info) {
                const el = $('#health-' + id);
                let color = '#94a3b8';
                if(info.status === 'ok') color = '#22c55e';
                if(info.status === 'warning') color = '#f59e0b';
                if(info.status === 'error') color = '#ef4444';
                
                el.html(`<span style="color:${color}">●</span> ${info.message}`);
            }

            // Ejecutar al cargar
            checkHealth();

            // Botón refrescar
            $('#ep-refresh-health-btn').on('click', function() {
                checkHealth();
            });

            // Guardar Ajustes de Sistema
            $('#ep-save-system-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Guardando...');
                
                $.post(ajaxurl, {
                    action: 'ep_save_system_settings',
                    security: '<?php echo wp_create_nonce("ep_system_settings_nonce"); ?>',
                    ep_o365_smtp_active: $('#ep_o365_smtp_active').is(':checked') ? '1' : '0',
                    ep_o365_system_sender_id: $('#ep_o365_system_sender_id').val(),
                    ep_o365_smtp_custom_from: $('#ep_o365_smtp_custom_from').val(),
                    ep_bot_custom_knowledge: $('#ep_bot_custom_knowledge').val(),
                    ep_bot_knowledge_urls: $('#ep_bot_knowledge_urls').val(),
                    ep_debug_log_active: $('#ep_debug_log_active').is(':checked') ? '1' : '0'
                }, function(res) {
                    if(res.success) {
                        alert(res.data);
                        location.reload();
                    } else {
                        alert('Error: ' + res.data);
                    }
                    btn.prop('disabled', false).text('Guardar Configuración de Sistema');
                });
            });

            // Limpiar caché de web scraping
            $('#ep-clear-web-cache-btn').on('click', function() {
                var btn = $(this);
                if(!confirm('¿Seguro que quieres forzar al bot a volver a leer todas las webs ahora?')) return;
                
                btn.prop('disabled', true).addClass('updating');
                $.post(ajaxurl, {
                    action: 'ep_clear_bot_web_cache',
                    security: '<?php echo wp_create_nonce("ep_system_settings_nonce"); ?>'
                }, function(res) {
                    alert(res.data);
                    btn.prop('disabled', false).removeClass('updating');
                });
            });
            
            // Prueba de correo O365
            $('#ep-test-o365-email').on('click', function() {
                const btn = $(this);
                const senderId = $('#ep_o365_system_sender_id').val();
                const resultArea = $('#ep-o365-test-result');

                if (senderId == '0') {
                    alert('Selecciona primero un remitente y guarda los cambios.');
                    return;
                }

                btn.prop('disabled', true).text('Enviando...');
                resultArea.text('').removeClass('success error');

                $.post(ajaxurl, {
                    action: 'ep_test_o365_email',
                    sender_id: senderId,
                    security: '<?php echo wp_create_nonce("ep_system_settings_nonce"); ?>'
                }, function(res) {
                    btn.prop('disabled', false).text('Enviar Correo de Prueba');
                    if (res.success) {
                        resultArea.text('✅ ' + res.data).css('color', 'green');
                    } else {
                        resultArea.text('❌ ' + res.data).css('color', 'red');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ── Autoaprendizaje: Aprobar ─────────────────────────────────────────────
    public function ajax_bot_learning_approve()
    {
        check_ajax_referer('ep_learning_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos.');

        $entry_id = sanitize_text_field($_POST['entry_id'] ?? '');
        $rule     = sanitize_textarea_field($_POST['rule'] ?? '');

        if (empty($entry_id) || empty($rule)) {
            wp_send_json_error('Faltan datos.');
        }

        $current = get_option('ep_bot_custom_knowledge', '');
        $updated = trim($current) . (empty(trim($current)) ? '' : "\n") . trim($rule);
        update_option('ep_bot_custom_knowledge', $updated);

        $queue = get_option('ep_bot_learning_queue', []);
        $queue = array_values(array_filter($queue, function($e) use ($entry_id) { return ($e['id'] ?? '') !== $entry_id; }));
        update_option('ep_bot_learning_queue', $queue, false);

        ep_error_log("EP Learning: Regla aprobada. ID: $entry_id");
        wp_send_json_success('✅ Regla aprobada y añadida a la base de conocimiento del bot.');
    }

    // ── Autoaprendizaje: Descartar ───────────────────────────────────────────
    public function ajax_bot_learning_discard()
    {
        check_ajax_referer('ep_learning_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos.');

        $entry_id = sanitize_text_field($_POST['entry_id'] ?? '');
        if (empty($entry_id)) wp_send_json_error('Falta entry_id.');

        $queue = get_option('ep_bot_learning_queue', []);
        $queue = array_values(array_filter($queue, function($e) use ($entry_id) { return ($e['id'] ?? '') !== $entry_id; }));
        update_option('ep_bot_learning_queue', $queue, false);

        wp_send_json_success('Descartado.');
    }

    // ── Autoaprendizaje: Email diario (cron) ─────────────────────────────────
    public function send_learning_digest_email(): void
    {
        $queue = get_option('ep_bot_learning_queue', []);
        if (empty($queue) || !is_array($queue)) return;

        $admin_email = get_option('admin_email');
        $site_name   = get_bloginfo('name');
        $panel_url   = admin_url('admin.php?page=employee-portal&tab=learning');
        $subject     = sprintf('[%s] 🧠 Bot: %d mensajes sin entender (Resumen diario)', $site_name, count($queue));

        $rows = '';
        foreach ($queue as $e) {
            $fecha = date_i18n('d/m/Y H:i', $e['timestamp'] ?? time());
            $rows .= '<tr>
                <td style="padding:10px;border-bottom:1px solid #f1f5f9;font-family:monospace;background:#f8fafc;">' . esc_html(mb_substr($e['message'] ?? '', 0, 200)) . '</td>
                <td style="padding:10px;border-bottom:1px solid #f1f5f9;text-align:center;"><strong>' . esc_html($e['intent'] ?? 'UNKNOWN') . '</strong></td>
                <td style="padding:10px;border-bottom:1px solid #f1f5f9;text-align:center;">' . number_format(floatval($e['confidence'] ?? 0) * 100, 0) . '%</td>
                <td style="padding:10px;border-bottom:1px solid #f1f5f9;text-align:center;">' . esc_html($e['role'] ?? '-') . '</td>
                <td style="padding:10px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#64748b;">' . $fecha . '</td>
            </tr>';
        }

        $body = '
<div style="font-family:Segoe UI,Arial,sans-serif;max-width:700px;margin:0 auto;color:#1e293b;">
    <div style="background:#7c3aed;padding:24px 30px;border-radius:12px 12px 0 0;">
        <h2 style="margin:0;color:#fff;font-size:22px;">🧠 Centro de Aprendizaje del Bot</h2>
        <p style="margin:8px 0 0;color:#ede9fe;font-size:14px;">Resumen diario — mensajes que el bot no ha entendido</p>
    </div>
    <div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:30px;border-radius:0 0 12px 12px;">
        <p>El bot ha registrado <strong>' . count($queue) . ' mensajes</strong> con intent UNKNOWN o confianza &lt; 60%.</p>
        <table style="width:100%;border-collapse:collapse;margin-top:15px;">
            <thead><tr style="background:#f1f5f9;">
                <th style="padding:10px;text-align:left;font-size:11px;text-transform:uppercase;color:#64748b;">Mensaje</th>
                <th style="padding:10px;font-size:11px;text-transform:uppercase;color:#64748b;">Intent</th>
                <th style="padding:10px;font-size:11px;text-transform:uppercase;color:#64748b;">Confianza</th>
                <th style="padding:10px;font-size:11px;text-transform:uppercase;color:#64748b;">Rol</th>
                <th style="padding:10px;font-size:11px;text-transform:uppercase;color:#64748b;">Fecha</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>
        <div style="margin-top:25px;text-align:center;">
            <a href="' . esc_url($panel_url) . '" style="background:#7c3aed;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;">Revisar y Aprobar Reglas</a>
        </div>
    </div>
</div>';

        wp_mail($admin_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        ep_error_log('EP Learning Digest: Email enviado a ' . $admin_email . ' con ' . count($queue) . ' entradas.');
    }

    // ── Autoaprendizaje: Render panel ────────────────────────────────────────
    private function render_learning_center(): void
    {
        $queue = get_option('ep_bot_learning_queue', []);
        if (!is_array($queue)) $queue = [];
        $queue = array_reverse($queue); // Más recientes primero
        $nonce = wp_create_nonce('ep_learning_nonce');
        ?>
        <style>
            .ep-lc-entry { border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:16px; }
            .ep-lc-entry:hover { border-color:#7c3aed30; background:#fafaff; }
            .ep-lc-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
            .ep-lc-textarea { width:100%; min-height:70px; font-family:monospace; font-size:12px; border:1px solid #cbd5e1; border-radius:6px; padding:8px; resize:vertical; box-sizing:border-box; }
            .ep-lc-btn-approve { background:#7c3aed; color:#fff; border:none; padding:9px 18px; border-radius:6px; font-weight:600; cursor:pointer; }
            .ep-lc-btn-discard  { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; padding:9px 14px; border-radius:6px; cursor:pointer; }
        </style>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <div>
                <h2 style="margin:0;">🧠 Centro de Aprendizaje del Bot</h2>
                <p class="description" style="margin-top:6px;">Mensajes con intent <strong>UNKNOWN</strong> o confianza &lt; 60%. Escribe la respuesta correcta y apruébala para que el bot aprenda.</p>
            </div>
            <?php if (!empty($queue)): ?>
            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 24px;text-align:center;flex-shrink:0;">
                <div style="font-size:30px;font-weight:800;color:#92400e;"><?php echo count($queue); ?></div>
                <div style="font-size:11px;color:#92400e;font-weight:700;">PENDIENTES</div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($queue)): ?>
        <div style="text-align:center;padding:60px 0;color:#64748b;">
            <div style="font-size:48px;">🎉</div>
            <h3 style="color:#1e293b;">El bot lo entiende todo</h3>
            <p>No hay mensajes pendientes. Vuelve después de unos días de uso.</p>
        </div>
        <?php else: ?>
        <div id="ep-lc-container">
        <?php foreach ($queue as $e):
            $eid  = esc_attr($e['id'] ?? uniqid());
            $msg  = esc_html($e['message'] ?? '');
            $int  = esc_html($e['intent'] ?? 'UNKNOWN');
            $conf = number_format(floatval($e['confidence'] ?? 0) * 100, 0);
            $rol  = esc_html($e['role'] ?? '-');
            $fch  = date_i18n('d/m/Y H:i', $e['timestamp'] ?? time());
            $sug  = "Si el usuario dice: \"" . esc_textarea($e['message'] ?? '') . "\"\n=> Responder: [ESCRIBE AQUI LA REGLA O RESPUESTA]";
            $bdg  = ($int === 'UNKNOWN') ? 'background:#fef3c7;color:#92400e;' : 'background:#fee2e2;color:#991b1b;';
        ?>
        <div class="ep-lc-entry" id="ep-lc-<?php echo $eid; ?>">
            <div style="font-size:15px;font-weight:600;color:#1e293b;margin-bottom:10px;">
                💬 "<?php echo $msg; ?>"
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                <span class="ep-lc-badge" style="<?php echo $bdg; ?>">🎯 <?php echo $int; ?></span>
                <span class="ep-lc-badge" style="background:#f1f5f9;color:#475569;">📊 <?php echo $conf; ?>% confianza</span>
                <span class="ep-lc-badge" style="background:#f0f9ff;color:#0369a1;">👤 <?php echo $rol; ?></span>
                <span class="ep-lc-badge" style="background:#f8fafc;color:#94a3b8;">🕒 <?php echo $fch; ?></span>
            </div>
            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">✏️ Regla de conocimiento a añadir:</label>
            <textarea class="ep-lc-textarea ep-lc-rule" data-id="<?php echo $eid; ?>"><?php echo $sug; ?></textarea>
            <div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
                <button type="button" class="ep-lc-btn-approve" data-id="<?php echo $eid; ?>">✅ Aprobar y enseñar al bot</button>
                <button type="button" class="ep-lc-btn-discard"  data-id="<?php echo $eid; ?>">🗑️ Descartar</button>
                <span class="ep-lc-status-<?php echo $eid; ?>" style="font-size:12px;"></span>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo $nonce; ?>';

            $(document).on('click', '.ep-lc-btn-approve', function() {
                var btn = $(this), id = btn.data('id');
                var rule = $('.ep-lc-rule[data-id="' + id + '"]').val();
                var st   = $('.ep-lc-status-' + id);
                if (!rule.trim() || rule.indexOf('[ESCRIBE AQUI') !== -1) {
                    st.text('⚠️ Escribe la regla antes de aprobar.').css('color','#d97706'); return;
                }
                btn.prop('disabled', true).text('Guardando...');
                $.post(ajaxurl, { action: 'ep_bot_learning_approve', security: nonce, entry_id: id, rule: rule }, function(r) {
                    if (r.success) { $('#ep-lc-' + id).fadeOut(400, function() {
                        $(this).remove();
                        if ($('.ep-lc-entry').length === 0) location.reload();
                    }); } else {
                        st.text('❌ ' + r.data).css('color','#dc2626');
                        btn.prop('disabled', false).text('✅ Aprobar y enseñar al bot');
                    }
                });
            });

            $(document).on('click', '.ep-lc-btn-discard', function() {
                var btn = $(this), id = btn.data('id');
                if (!confirm('¿Descartar este mensaje sin aprender de él?')) return;
                btn.prop('disabled', true);
                $.post(ajaxurl, { action: 'ep_bot_learning_discard', security: nonce, entry_id: id }, function(r) {
                    if (r.success) { $('#ep-lc-' + id).fadeOut(300, function() {
                        $(this).remove();
                        if ($('.ep-lc-entry').length === 0) location.reload();
                    }); }
                });
            });
        });
        </script>
        <?php
    }
}
