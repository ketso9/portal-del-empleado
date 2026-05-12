<?php
/**
 * Plugin Name: EP Mini App: Directorio de Empresas
 * Description: Directorio de empresas asociadas para el Portal del Empleado, con gestión de miembros, logos y exportación.
 * Version: 1.0.0
 * Author: Antigravity
 * Package: pro
 */

defined('ABSPATH') || exit;

if (defined('EP_EMPRESAS_LOADED')) {
    return;
}
define('EP_EMPRESAS_LOADED', true);

define('EP_EMPRESAS_PATH', plugin_dir_path(__FILE__));
define('EP_EMPRESAS_URL',  plugin_dir_url(__FILE__));

// Load main class
require_once EP_EMPRESAS_PATH . 'class-ep-empresas.php';

// Register App with Portal Manager
add_action('ep_register_apps', function ($manager) {
    static $registered = false;
    if ($registered) return;
    $registered = true;

    if (class_exists('EP_App_Empresas')) {
        $manager->register_app(new EP_App_Empresas());
    }
});
