<?php
/**
 * EP_Updater — Sistema de Auto-Actualización del Portal del Empleado
 *
 * Consulta al portal maestro periódicamente para verificar si hay una versión
 * nueva del plugin. Se integra con el sistema nativo de actualizaciones de WordPress
 * para que el administrador pueda actualizar desde Escritorio → Actualizaciones.
 *
 * IMPORTANTE: El paquete descargado siempre contiene TODOS los módulos.
 * La licencia (plan/apps autorizadas) controla qué módulos son visibles,
 * no cuáles se instalan. Así, aunque un cliente no tenga contratado el Censo,
 * recibirá su código actualizado pero seguirá sin verlo.
 *
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class EP_Updater
{
    /** @var string Slug del plugin (nombre del directorio) */
    private $plugin_slug;

    /** @var string Ruta relativa del plugin principal (ej: mi-plugin/mi-plugin.php) */
    private $plugin_file;

    /** @var string Versión actual del plugin */
    private $current_version;

    /** @var string URL del endpoint de la API del maestro */
    private $api_url;

    /** @var string Clave maestra del sitio */
    private $master_key;

    /** @var string URL del sitio cliente */
    private $site_url;

    /** @var string Clave del transient de caché */
    private $cache_key = 'ep_update_check_cache';

    /** @var int Duración del caché en segundos (6 horas) */
    private $cache_duration = 21600;

    public function __construct()
    {
        // Detectar dinamicamente la ruta del plugin (clave para que WP empareje la actualizacion)
        $this->plugin_file = plugin_basename(EMPLOYEE_PORTAL_PATH . 'employee-portal.php');
        $this->plugin_slug = dirname($this->plugin_file);
        $this->current_version = EMPLOYEE_PORTAL_VERSION;
        $this->master_key = ep_get_option('ep_site_master_key');
        $this->site_url = untrailingslashit(home_url());

        // Obtener la URL base del maestro
        $remote_url = ep_get_option('ep_auth_remote_url');
        ep_error_log('EP Updater INIT: plugin_file=[' . $this->plugin_file . '] plugin_slug=[' . $this->plugin_slug . ']');
        ep_error_log('EP Updater INIT: remote_url=[' . ($remote_url ?: 'VACIO') . '] master_key=[' . ($this->master_key ? 'SET' : 'VACIO') . '] version=[' . $this->current_version . ']');

        if (!empty($remote_url)) {
            // Intentar reemplazar /validate-key por /check-update
            if (strpos($remote_url, '/validate-key') !== false) {
                $this->api_url = str_replace('/validate-key', '/check-update', $remote_url);
            } else {
                // Fallback: construir desde base /wp-json/ep/v1/
                $pos = strpos($remote_url, '/wp-json/ep/v1/');
                if ($pos !== false) {
                    $this->api_url = substr($remote_url, 0, $pos) . '/wp-json/ep/v1/check-update';
                } else {
                    // Ultimo recurso: asumir URL base del sitio
                    $this->api_url = trailingslashit($remote_url) . 'wp-json/ep/v1/check-update';
                }
            }
            ep_error_log('EP Updater INIT: api_url construida=[' . $this->api_url . ']');
        }

        // Solo activar si tenemos configuracion valida
        if (!empty($this->master_key) && !empty($this->api_url)) {
            add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
            add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
            add_filter('upgrader_post_install', array($this, 'after_update'), 10, 3);
            add_filter('auto_update_plugin', array($this, 'enable_auto_update'), 10, 2);
            ep_error_log('EP Updater INIT: Hooks registrados correctamente.');
        } else {
            ep_error_log('EP Updater INIT: NO se registraron hooks. master_key=' . (!empty($this->master_key) ? 'OK' : 'VACIO') . ' api_url=' . (!empty($this->api_url) ? 'OK' : 'VACIO'));
        }
    }

    /**
     * Consulta al maestro para verificar si hay actualización disponible.
     * Se ejecuta cada vez que WordPress comprueba actualizaciones de plugins.
     *
     * @param object $transient El transient de actualizaciones de plugins.
     * @return object El transient modificado con info de actualización si corresponde.
     */
    public function check_for_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        ep_error_log('EP Updater: check_for_update disparado. Version local: ' . $this->current_version);

        // Comprobar cache primero
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            if ($cached === 'no_update') {
                ep_error_log('EP Updater: Cache dice no_update, saltando.');
                return $transient;
            }
            // Verificar que la versión cacheada sigue siendo mayor que la actual
            // (evita ofrecer actualizar a una versión ya instalada)
            if (isset($cached->new_version) && version_compare($this->current_version, $cached->new_version, '>=')) {
                ep_error_log('EP Updater: Cache obsoleto (v' . $cached->new_version . ' ya instalada). Limpiando.');
                delete_transient($this->cache_key);
                // No retornar: dejar que se haga una comprobación fresca al maestro
            } else {
                ep_error_log('EP Updater: Usando resultado cacheado.');
                $transient->response[$this->plugin_file] = $cached;
                return $transient;
            }
        }

        // Consultar al maestro
        ep_error_log('EP Updater: Sin cache, consultando al maestro...');
        $response = $this->api_request();

        if ($response && isset($response->update) && $response->update === true) {
            ep_error_log('EP Updater: Actualizacion encontrada! Nueva version: ' . $response->new_version);
            $plugin_data = new stdClass();
            $plugin_data->slug = $this->plugin_slug;
            $plugin_data->plugin = $this->plugin_file;
            $plugin_data->new_version = $response->new_version;
            $plugin_data->url = '';
            $plugin_data->package = $response->package;
            $plugin_data->tested = $response->tested ?? '';
            $plugin_data->requires_php = $response->requires_php ?? '7.4';
            $plugin_data->icons = [
                'default' => EMPLOYEE_PORTAL_URL . 'public/images/logo-portal.jpg',
            ];

            $transient->response[$this->plugin_file] = $plugin_data;

            // Cachear el resultado
            set_transient($this->cache_key, $plugin_data, $this->cache_duration);
        } elseif ($response === null) {
            // Error de conexion: NO cachear, reintentar en la proxima comprobacion
            ep_error_log('EP Updater: Error de conexion con el maestro. No se cachea, se reintentara.');
        } else {
            ep_error_log('EP Updater: Maestro respondio sin actualizacion. Respuesta: ' . wp_json_encode($response));
            // Solo cachear 'no_update' cuando el maestro respondio correctamente
            set_transient($this->cache_key, 'no_update', $this->cache_duration);
        }

        return $transient;
    }

    /**
     * Proporciona información del plugin cuando WordPress la solicita
     * (por ejemplo, al clicar "Ver detalles" en la página de actualizaciones).
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object
     */
    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $response = $this->api_request();

        if (!$response || !isset($response->update) || $response->update !== true) {
            return $result;
        }

        $plugin_info = new stdClass();
        $plugin_info->name = 'Portal del Empleado';
        $plugin_info->slug = $this->plugin_slug;
        $plugin_info->version = $response->new_version;
        $plugin_info->author = 'Jorge Polo - Cámara de Comercio de Cáceres';
        $plugin_info->homepage = 'https://camaracaceres.com';
        $plugin_info->requires_php = $response->requires_php ?? '7.4';
        $plugin_info->tested = $response->tested ?? '';
        $plugin_info->download_link = $response->package;
        $plugin_info->sections = [
            'description' => 'Plugin completo para gestión de empleados, roles, autenticación O365, comunicaciones y tickets.',
            'changelog' => '<h4>Versión ' . esc_html($response->new_version) . '</h4><p>Actualización disponible desde el portal maestro. Incluye mejoras y correcciones para todos los módulos.</p>',
        ];
        $plugin_info->banners = [];
        $plugin_info->icons = [
            'default' => EMPLOYEE_PORTAL_URL . 'public/images/logo-portal.jpg',
        ];

        return $plugin_info;
    }

    /**
     * Activar auto-updates para este plugin.
     *
     * @param bool|null $update
     * @param object $item
     * @return bool|null
     */
    public function enable_auto_update($update, $item)
    {
        if (isset($item->slug) && $item->slug === $this->plugin_slug) {
            return true;
        }
        return $update;
    }

    /**
     * Acciones post-actualización: limpiar caché y renombrar carpeta si es necesario.
     *
     * @param bool $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function after_update($response, $hook_extra, $result)
    {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $result;
        }

        // Limpiar caché de actualización
        delete_transient($this->cache_key);
        // Limpiar el transient de WordPress para forzar comprobación fresca
        delete_site_transient('update_plugins');

        // Asegurar que la carpeta del plugin tiene el nombre correcto
        global $wp_filesystem;
        $plugin_dir = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

        if (isset($result['destination']) && $result['destination'] !== $plugin_dir) {
            $wp_filesystem->move($result['destination'], $plugin_dir);
            $result['destination'] = $plugin_dir;
        }

        // Reactivar el plugin
        activate_plugin($this->plugin_file);

        ep_error_log('EP Updater: Plugin actualizado correctamente a la nueva versión.');

        return $result;
    }

    /**
     * Realiza la petición HTTP al endpoint del maestro.
     * Incluye caché de respuesta para evitar llamadas excesivas.
     *
     * @return object|null Respuesta del maestro o null en caso de error.
     */
    private function api_request()
    {
        $url = add_query_arg([
            'key' => $this->master_key,
            'site' => $this->site_url,
            'ep_version' => $this->current_version,
        ], $this->api_url);

        ep_error_log('EP Updater: Llamando a URL: ' . $url);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'sslverify' => false,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            ep_error_log('EP Updater: Error al consultar maestro - ' . $response->get_error_message());
            return null; // null = error, no cachear
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            ep_error_log('EP Updater: Respuesta no-200 del maestro - Codigo: ' . $code . ' Body: ' . substr(wp_remote_retrieve_body($response), 0, 200));
            return null; // null = error, no cachear
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ep_error_log('EP Updater: JSON invalido del maestro. Body: ' . substr($body, 0, 200));
            return null; // null = error, no cachear
        }

        return $data;
    }
}
