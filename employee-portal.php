<?php
/**
 * Plugin Name: Portal del Empleado
 * Plugin URI:  https://camaracacere.com
 * Description: Plugin completo para gestión de empleados, roles, autenticación O365, comunicaciones y tickets.
 * Version:           2.1.2
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

define('EMPLOYEE_PORTAL_VERSION', '2.1.2');
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
/**
 * Opciones que guardan un secreto y nunca deben quedar en claro en la base de
 * datos: si alguien se lleva un volcado, esto es lo que le daria acceso a
 * Microsoft 365 en nombre de la Camara.
 *
 * Se cifran al guardar (ep_update_secret_option) y se descifran al leer, de
 * forma transparente: quien las consulta con ep_get_option() no nota nada.
 *
 * @return string[]
 */
function ep_secret_options(): array
{
	return (array) apply_filters('ep_secret_options', [
		'ep_o365_client_secret',
		'ep_teams_bot_password',
		'ep_site_master_key',
	]);
}

/**
 * Carga EP_Security bajo demanda.
 *
 * El archivo se incluye mas abajo en este mismo fichero, pero los ayudantes de
 * arriba pueden usarse antes de esa linea. Sin esto, una lectura temprana
 * devolveria el texto cifrado tal cual y el fallo seria dificil de rastrear.
 */
function ep_security_ready(): bool
{
	if (!class_exists('EP_Security')) {
		$file = plugin_dir_path(__FILE__) . 'includes/class-ep-security.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}

	return class_exists('EP_Security');
}

function ep_get_option($option, $default = '')
{
	$val = get_option($option, $default);
	$val = is_scalar($val) ? (string) $val : '';

	// Descifrado transparente. Se limita a la lista de secretos para no
	// intentar descifrar cualquier opcion que por casualidad parezca base64.
	if ($val !== '' && in_array($option, ep_secret_options(), true) && ep_security_ready()) {
		if (EP_Security::is_encrypted($val)) {
			$plain = EP_Security::decrypt($val);
			if (is_string($plain) && $plain !== '') {
				return $plain;
			}
		}
	}

	return $val;
}

/**
 * Guarda una opcion sensible cifrada con AES-256 (EP_Security).
 *
 * Si el cifrado no esta disponible se guarda en claro antes que perder el dato,
 * pero se deja constancia en el log: un secreto sin cifrar es un problema que
 * alguien tiene que ver.
 */
function ep_update_secret_option($option, $value)
{
	$value = (string) $value;

	if ($value === '') {
		return update_option($option, '');
	}

	if (ep_security_ready()) {
		$cipher = EP_Security::encrypt($value);
		if (is_string($cipher) && $cipher !== '') {
			return update_option($option, $cipher);
		}
	}

	ep_error_log("EP: no se pudo cifrar la opcion [$option]; se guarda en claro.", true);
	return update_option($option, $value);
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
 * Catálogo de módulos locales detectados en /plugins.
 *
 * AUTODESCUBRIMIENTO: no hay ninguna lista que mantener a mano. Cualquier
 * carpeta `plugins/<app-id>/` que contenga un `<app-id>.php` se considera un
 * módulo del portal y se carga sola. Para añadir una app nueva basta con
 * copiar su carpeta; para retirarla, borrarla.
 *
 * El mismo criterio lo usan EP_Admin::get_all_available_apps(),
 * EP_Admin::get_packages_definition() y EP_Deployer::create_blueprint_zip(),
 * de modo que una app nueva aparece a la vez en el cargador, en los paquetes
 * de suscripción y en el ZIP de despliegue.
 *
 * @return array<string,string>  [app_id => ruta relativa a plugins/]
 */
function ep_discover_local_modules(): array
{
	// Se cachea en memoria: se consulta desde el cargador y desde el panel de admin.
	static $cache = null;
	if ($cache !== null) {
		return $cache;
	}

	$modules_dir = plugin_dir_path(__FILE__) . 'plugins/';
	$modules     = [];

	$dirs = glob($modules_dir . '*', GLOB_ONLYDIR);
	if (!is_array($dirs)) {
		$dirs = [];
	}
	sort($dirs);

	foreach ($dirs as $dir) {
		$app_id = basename($dir);

		// Ignorar restos de trabajo (.git, copias, carpetas ocultas o temporales)
		if ($app_id === '' || $app_id[0] === '.' || $app_id[0] === '_') {
			continue;
		}

		// Punto de entrada canónico: plugins/mi-app/mi-app.php
		$entry_point = $dir . '/' . $app_id . '.php';
		if (!file_exists($entry_point)) {
			ep_error_log("EP Loader: carpeta [plugins/$app_id] ignorada — falta $app_id.php");
			continue;
		}

		$modules[$app_id] = $app_id . '/' . $app_id . '.php';
	}

	/**
	 * Permite registrar módulos fuera de la convención o excluir alguno.
	 *
	 * @param array<string,string> $modules
	 */
	$cache = (array) apply_filters('ep_local_modules', $modules);

	return $cache;
}

/**
 * Módulos BASE: infraestructura del portal, no mini-apps contratables.
 *
 * No tienen icono en el escritorio ni se venden por plan, así que la licencia
 * no decide sobre ellos: se cargan siempre. Hoy solo hay uno.
 *
 * - ep-gdpr: inyecta el banner de cookies y el bloqueo previo de scripts. Debe
 *   estar activo en todos los portales por obligación legal, se contrate lo
 *   que se contrate.
 *
 * Cualquier módulo CON interfaz va fuera de esta lista: el ZIP los instala
 * todos y es el maestro, vía 'authorized_apps', quien decide cuáles se activan
 * en cada cliente.
 *
 * @return string[]
 */
function ep_always_on_modules(): array
{
	return (array) apply_filters('ep_always_on_modules', ['ep-gdpr']);
}

/**
 * ¿Tiene este portal contratado el canal de Microsoft Teams?
 *
 * El módulo plugins/ep-teams-bot solo lo carga el cargador cuando la licencia
 * lo autoriza (plan PRO MAX), y ese archivo es el que define la constante. Así
 * que preguntar por ella equivale a preguntar por la licencia, sin consultar
 * de nuevo a EP_License en cada notificación.
 *
 * Cuando devuelve false, el portal sigue notificando por pantalla y por correo:
 * lo único que se apaga es la salida hacia Teams y el asistente con IA del bot.
 */
/**
 * Detecta si el entorno actual es de pruebas / staging.
 */
function ep_is_staging(): bool
{
	$home = home_url();
	if (stripos($home, 'devpruebas') !== false || stripos($home, 'staging') !== false || stripos($home, 'test') !== false) {
		return true;
	}
	if (defined('EP_IS_STAGING') && EP_IS_STAGING) {
		return true;
	}
	if (function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['staging', 'development', 'local'], true)) {
		return true;
	}
	return false;
}

/**
 * Redirige a producción cualquier acceso a staging generado por Teams o enlaces de usuario.
 * Los administradores pueden navegar normalmente en staging.
 */
add_action('template_redirect', 'ep_staging_redirect_teams_to_production', 1);
function ep_staging_redirect_teams_to_production()
{
	if (!ep_is_staging()) {
		return;
	}

	// Permitir a administradores trabajar en staging
	if (is_user_logged_in() && current_user_can('manage_options')) {
		return;
	}

	// No interferir con llamadas al API REST, AJAX o panel de administración
	if (defined('REST_REQUEST') || wp_doing_ajax() || is_admin()) {
		return;
	}

	$pagenow = $GLOBALS['pagenow'] ?? '';
	if (in_array($pagenow, ['wp-login.php', 'wp-register.php'], true)) {
		return;
	}

	$is_teams_query = isset($_GET['teams']);
	$user_agent     = $_SERVER['HTTP_USER_AGENT'] ?? '';
	$is_teams_ua    = (stripos($user_agent, 'Teams') !== false || stripos($user_agent, 'SkypeSpaces') !== false);
	$has_view_param = isset($_GET['view']);
	$is_non_admin   = is_user_logged_in() && !current_user_can('manage_options');

	if ($is_teams_query || $is_teams_ua || $has_view_param || $is_non_admin) {
		$req_uri = $_SERVER['REQUEST_URI'] ?? '/';
		$target_path = preg_replace('#^/devpruebas#i', '', $req_uri);
		if (empty($target_path)) {
			$target_path = '/';
		}
		$prod_url = 'https://portal.camaracaceres.com' . $target_path;
		wp_redirect($prod_url, 302);
		exit;
	}
}

function ep_teams_channel_enabled(): bool
{
	if (ep_is_staging()) {
		return false;
	}
	return (bool) apply_filters('ep_teams_channel_enabled', defined('EP_TEAMS_CHANNEL_LICENSED'));
}


/**
 * Loads local modules from the 'plugins' directory.
 *
 * Usa EP_License para determinar qué módulos están autorizados.
 * EP_License es 100% retrocompatible: si EP_LICENSE_SHARED_SECRET no está
 * definida en wp-config.php, delega en get_option('ep_authorized_apps'),
 * exactamente igual que antes.
 */
function ep_load_local_modules()
{
	$modules_dir = plugin_dir_path(__FILE__) . 'plugins/';

	// Cargar la clase de licencia (retrocompatible)
	require_once plugin_dir_path(__FILE__) . 'includes/class-ep-license.php';

	// Obtener apps autorizadas. Retorna ['*'] para maestro, lista para clientes.
	$authorized_apps = EP_License::get_authorized_apps();
	$is_master       = ($authorized_apps === ['*']);

	$modules   = ep_discover_local_modules();
	$always_on = ep_always_on_modules();

	// Los módulos base NO se escriben en 'ep_authorized_apps': esa lista es
	// territorio del maestro y el cliente no debe alterarla por su cuenta. Se
	// cargan porque están en ep_always_on_modules(), no porque estén licenciados.

	foreach ($modules as $app_id => $file_path) {
		$module_file = $modules_dir . $file_path;

		// Módulos base (infraestructura) y maestro: cargar sin mirar la licencia
		if (in_array($app_id, $always_on, true) || $is_master) {
			if (file_exists($module_file)) {
				require_once $module_file;
			}
			continue;
		}

		// Cliente: sólo lo que la licencia autorice. Una lista vacía significa
		// "sin licencia sincronizada todavía" y mantiene el comportamiento
		// histórico de cargarlo todo.
		if (!empty($authorized_apps) && !in_array($app_id, $authorized_apps, true)) {
			continue;
		}

		if (file_exists($module_file)) {
			require_once $module_file;
		}
	}
}



run_employee_portal();

/**
 * OAUTH-01 — Verificación periódica de accountEnabled en Azure AD.
 *
 * Se ejecuta en cada petición WordPress (hook 'init') pero con throttle de 30 minutos
 * por usuario (guardado en user_meta 'ep_azure_status_checked').
 *
 * Casos cubiertos:
 *  - Cuenta desactivada en Azure AD (accountEnabled = false) → logout inmediato
 *  - Cuenta eliminada de Azure AD (HTTP 404)                 → logout inmediato
 *  - Error de red o token de app no disponible               → se ignora (no expulsar por precaución)
 *
 * @since 2.0.28
 */
add_action('init', 'ep_check_azure_account_status', 20);
function ep_check_azure_account_status()
{
	// Solo usuarios logueados con vínculo O365
	if (!is_user_logged_in()) return;

	$user_id  = get_current_user_id();
	$o365_id  = get_user_meta($user_id, 'ep_o365_user_id', true);

	// Sin vínculo O365 (admin WP puro), no verificar
	if (empty($o365_id)) return;

	// Throttle: como máximo cada 30 minutos por usuario
	$last_check = (int) get_user_meta($user_id, 'ep_azure_status_checked', true);
	if (time() - $last_check < 1800) return;

	// Necesitamos la clase cargada para obtener el app token
	if (!class_exists('EP_Auth_O365')) return;

	// Token de aplicación (client_credentials) — no depende del token del usuario
	$token = EP_Auth_O365::get_app_token();
	if (!$token || is_wp_error($token)) {
		ep_error_log("ep_check_azure: Sin app token para verificar user $user_id. Saltando.");
		return;
	}

	// Actualizar timestamp ANTES de la llamada para evitar hammering si falla la red
	update_user_meta($user_id, 'ep_azure_status_checked', time());

	$safe_o365_id = rawurlencode($o365_id);
	$response = wp_remote_get(
		"https://graph.microsoft.com/v1.0/users/{$safe_o365_id}?\$select=accountEnabled,userPrincipalName",
		[
			'headers' => ['Authorization' => 'Bearer ' . $token],
			'timeout' => 8,
		]
	);

	if (is_wp_error($response)) {
		ep_error_log("ep_check_azure: Error de red para user $user_id: " . $response->get_error_message());
		return; // No expulsar por caída de red
	}

	$code = wp_remote_retrieve_response_code($response);

	if ($code === 404) {
		// Cuenta eliminada de Azure AD
		ep_error_log("ep_check_azure: Usuario $user_id ELIMINADO de Azure AD (404). Cerrando sesión.", true);
		ep_force_sso_logout($user_id, 'account_deleted');
		return;
	}

	if ($code !== 200) {
		ep_error_log("ep_check_azure: Respuesta inesperada HTTP $code para user $user_id. Saltando.");
		return; // Error desconocido → no expulsar por precaución
	}

	$data            = json_decode(wp_remote_retrieve_body($response), true);
	$account_enabled = $data['accountEnabled'] ?? null;

	if ($account_enabled === false) {
		ep_error_log("ep_check_azure: Cuenta DESACTIVADA en Azure AD para user $user_id. Cerrando sesión.", true);
		ep_force_sso_logout($user_id, 'account_disabled');
	}
}

/**
 * Fuerza el cierre de sesión inmediato del usuario actual.
 * Destruye todas las sesiones WP, borra cookies y tokens O365, y redirige al login.
 *
 * @param int    $user_id  ID del usuario WordPress.
 * @param string $reason   Motivo del logout ('account_disabled' | 'account_deleted').
 */
function ep_force_sso_logout($user_id, $reason = 'unknown')
{
	// 1. Destruir todas las sesiones WordPress activas para este usuario
	$sessions = WP_Session_Tokens::get_instance($user_id);
	$sessions->destroy_all();

	// 2. Limpiar cookies de autenticación locales
	wp_clear_auth_cookie();

	// 3. Borrar tokens O365 para forzar un nuevo login SSO
	delete_user_meta($user_id, 'ep_o365_access_token');
	delete_user_meta($user_id, 'ep_o365_refresh_token');
	delete_user_meta($user_id, 'ep_o365_token_last_refresh');
	delete_user_meta($user_id, 'ep_azure_status_checked');

	// 4. Redirigir al login con parámetro informativo
	$login_page = add_query_arg('ep_sso_error', urlencode($reason), home_url('/'));
	wp_redirect($login_page);
	exit;
}

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
