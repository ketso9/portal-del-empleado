<?php
/**
 * Plugin Name: Portal del Empleado
 * Plugin URI:  https://camaracacere.com
 * Description: Plugin completo para gestión de empleados, roles, autenticación O365, comunicaciones y tickets.
 * Version:           2.0.27
 * Author:      Jorge Polo - Cámara de Comercio de Cáceres
 * Author URI:  https://camaracaceres.com
 * License:     GPL-2.0+
 * Text Domain: employee-portal
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Execution guard to prevent double-loading if the file is included multiple times
if (defined('EP_PORTAL_RUNNING')) {
	return;
}
define('EP_PORTAL_RUNNING', true);

define('EMPLOYEE_PORTAL_VERSION', '2.0.28');
define('EMPLOYEE_PORTAL_PATH', trailingslashit(dirname(__FILE__)));
define('EMPLOYEE_PORTAL_URL', trailingslashit(plugins_url('', __FILE__)));

/**
 * Función global para logging controlado.
 * Optimizada para alta concurrencia: solo escribe si el debug está activo 
 * o si es un error crítico.
 */
if (!function_exists('ep_error_log')) {
	function ep_error_log($message, $force = false)
	{
		static $debug_enabled = null;
		if ($debug_enabled === null) {
			$debug_enabled = (get_option('ep_debug_log_active') === '1');
		}

		if (!$debug_enabled && !$force) {
			return;
		}

		$log_file = EMPLOYEE_PORTAL_PATH . 'ep_debug.log';
		$timestamp = date('Y-m-d H:i:s');
		$output = (is_array($message) || is_object($message)) ? print_r($message, true) : (string)$message;
		
		// Usar error_log estándar pero solo si es necesario, para evitar I/O innecesario
		@error_log("[$timestamp] $output" . PHP_EOL, 3, $log_file);
	}
}

// Registro fundamental de arranque (solo si debug activo)
ep_error_log("EP: Sistema inicializado v" . EMPLOYEE_PORTAL_VERSION);

if (($_SERVER['REQUEST_METHOD']??'UNK') === 'POST' && (get_option('ep_debug_log_active') === '1')) {
	ep_error_log("POST DETECTADO: " . ($_SERVER['REQUEST_URI']??'N/A') . " | IP: " . ($_SERVER['REMOTE_ADDR']??'unk'));
}

// Tracer global de entrada deshabilitado



/**
 * Helper para obtener opciones de forma segura (siempre devuelve string)
 */
function ep_get_option($option, $default = '')
{
	$val = get_option($option, $default);
	return is_scalar($val) ? (string) $val : '';
}

/**
 * Helper para obtener valores POST de forma segura
 */
function ep_get_post_val($key, $default = '')
{
	return isset($_POST[$key]) ? (string) $_POST[$key] : $default;
}

/**
 * The code that runs during plugin activation.
 */
function activate_employee_portal()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-ep-activator.php';
	EP_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_employee_portal()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-ep-deactivator.php';
	EP_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_employee_portal');
register_deactivation_hook(__FILE__, 'deactivate_employee_portal');

/**
 * Log cleanup cron registration.
 * Se programa para ejecutarse cada día a las 06:00 AM.
 */
add_action('init', 'ep_ensure_scheduled_tasks');

function ep_ensure_scheduled_tasks() {
    $current_schedule = wp_next_scheduled('ep_daily_log_cleanup');
    
    // Si no está programado, o si está programado a una hora que no es las 06:00 (con margen de 10 min)
    if (!$current_schedule || date('H', $current_schedule) !== '06') {
        wp_clear_scheduled_hook('ep_daily_log_cleanup');
        $timestamp = strtotime('tomorrow 06:00:00');
        wp_schedule_event($timestamp, 'daily', 'ep_daily_log_cleanup');
        ep_error_log("EP Cron: Forzada reprogramación a las 06:00 AM.");
    }
}
add_action('ep_daily_log_cleanup', 'ep_cleanup_old_logs'); 

function ep_cleanup_old_logs() {
    // Vaciar transientes/cache obsoletos para mejorar el rendimiento del Bot y Portal
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_ep_ai_%' OR option_name LIKE '_transient_timeout_ep_ai_%'");
    
    $path = EMPLOYEE_PORTAL_PATH;
    $files = glob($path . '*.log');
    $expire_days = 7;
    $max_size = 50 * 1024 * 1024; // 50MB

    foreach ($files as $file) {
        if (file_exists($file)) {
            // Delete if older than 7 days
            if (time() - filemtime($file) > ($expire_days * DAY_IN_SECONDS)) {
                unlink($file);
                continue;
            }
            // Truncate if too big
            if (filesize($file) > $max_size) {
                file_put_contents($file, "[System] Truncated log due to size on " . date('Y-m-d H:i:s') . PHP_EOL);
            }
        }
    }
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path(__FILE__) . 'includes/class-ep-security.php';
require plugin_dir_path(__FILE__) . 'includes/class-ep-graph-service.php';
require plugin_dir_path(__FILE__) . 'includes/class-ep-loader.php';

/**
 * Begins execution of the plugin.
 */
/**
 * Begins execution of the plugin.
 */
function run_employee_portal()
{
	$plugin = new EP_Loader();

	// Load local modules (plugins within plugins) BEFORE running the loader
	// This ensures that hooks registered by plugins (like ep_register_apps) are available
	ep_load_local_modules();

	$plugin->run();

	// Expiración de sesión personalizada (14 horas - cubre jornada partida completa)
	add_filter('auth_cookie_expiration', function ($expiration, $user_id, $remember) {
		return 14 * HOUR_IN_SECONDS;
	}, 99, 3);

	// Load Dashboard Widgets in Admin
	if (is_admin()) {
		require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-dashboard.php';
		new EP_Dashboard();
	}
}

/**
 * Bloqueo de escritorio (wp-admin) para empleados (subscribers)
 * Redirige al front-end del portal.
 */
add_action('admin_init', 'ep_block_wp_admin_for_employees', 1);
function ep_block_wp_admin_for_employees()
{
	if (defined('DOING_AJAX') && DOING_AJAX) {
		return;
	}

	if (is_user_logged_in()) {
		$user = wp_get_current_user();

		// Si es "subscriber" (Empleado base sin permisos de admin real ni plugin)
		if (in_array('subscriber', (array) $user->roles) && !current_user_can('manage_options')) {
			wp_redirect(home_url('/?view=dashboard'));
			exit;
		}
	}
}

/**
 * Función de rescate administrativo:
 * Asegura de que el usuario administrador o super administrador del portal
 * no sea bloqueado ni degradado por el SSO o utilidades de terceros.
 */
add_action('init', 'ep_rescue_admin_access', 1);
function ep_rescue_admin_access()
{
	if (is_user_logged_in()) {
		$user = wp_get_current_user();

		// Conceder acceso a usuarios con el nuevo rol ep_super_admin o administrator nativo
		if (in_array('ep_super_admin', (array) $user->roles) || in_array('administrator', (array) $user->roles)) {
			// Podemos asegurar que tiene el control total, quitando redirecciones en el login:
			remove_all_filters('login_redirect');
		}
	}
}

/**
 * Loads local modules from the 'plugins' directory.
 */
function ep_load_local_modules()
{
	$modules_dir = plugin_dir_path(__FILE__) . 'plugins/';

	// Si es el Portal Maestro, cargamos todo por defecto
	$is_master = defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL;

	// Si no es maestro, obtenemos las apps autorizadas (cache de la validación remota)
	$authorized_apps = get_option('ep_authorized_apps', []);
	if (!is_array($authorized_apps)) {
		$authorized_apps = json_decode($authorized_apps, true) ?: [];
	}

	$modules = [
		'ep-stats' => 'ep-stats/ep-stats.php',
		'ep-signature' => 'ep-signature/ep-signature.php',
		'ep-tickets' => 'ep-tickets/ep-tickets.php',
		'ep-downloads' => 'ep-downloads/ep-downloads.php',
		'ep-directory' => 'ep-directory/ep-directory.php',
		'ep-inventory' => 'ep-inventory/ep-inventory.php',
		'ep-avisos' => 'ep-avisos/ep-avisos.php',
		'ep-calendar' => 'ep-calendar/ep-calendar.php',
		'ep-censo' => 'ep-censo/ep-censo.php',
		'ep-gdpr' => 'ep-gdpr/ep-gdpr.php',
		'ep-buzon'     => 'ep-buzon/ep-buzon.php',
		'ep-contratos' => 'ep-contratos/ep-contratos.php',
		'ep-links'     => 'ep-links/ep-links.php'
	];

	foreach ($modules as $app_id => $file_path) {
		// ep-gdpr es obligatorio para cumplimiento legal, no depende de la licencia
		if ($app_id !== 'ep-gdpr' && !$is_master && !empty($authorized_apps) && !in_array($app_id, $authorized_apps)) {
			continue;
		}

		$module_file = $modules_dir . $file_path;
		
		if (file_exists($module_file)) {
			require_once $module_file;
		}
	}
}



run_employee_portal();

/**
 * Filtro global para forzar la foto de Microsoft 365 en todo WordPress.
 * Sobrescribe la foto por defecto para que en la barra de admin y listas
 * se vea siempre la de 'ep_user_photo_url' en lugar de la del Gravatar.
 */
add_filter('get_avatar', function ($avatar, $id_or_email, $size, $default, $alt) {
	$user_id = false;

	// Detectar de quién es el avatar pedido
	if (is_numeric($id_or_email)) {
		$user_id = (int) $id_or_email;
	} elseif (is_string($id_or_email) && ($user = get_user_by('email', $id_or_email))) {
		$user_id = $user->ID;
	} elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
		$user_id = (int) $id_or_email->user_id;
	}

	if ($user_id) {
		$photo_url = get_user_meta($user_id, 'ep_user_photo_url', true);
		if ($photo_url) {
			// Generar un <img> que sobrescriba el estándar
			$avatar = sprintf(
				"<img alt='%s' src='%s' class='avatar avatar-%d photo' height='%d' width='%d' style='border-radius:50%%; object-fit:cover;' />",
				esc_attr($alt),
				esc_url($photo_url),
				$size,
				$size,
				$size
			);
		}
	}

	return $avatar;
}, 10, 5);
