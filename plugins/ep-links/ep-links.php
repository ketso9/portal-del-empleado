<?php
/**
 * Plugin Name: EP Mini App: Enlaces de Interés
 * Description: Gestión de enlaces de interés para el Portal del Empleado.
 * Version: 1.0.0
 * Author: Antigravity
 * Package: pro
 */

defined('ABSPATH') || exit;

if (defined('EP_LINKS_LOADED')) {
    return;
}
define('EP_LINKS_LOADED', true);

// Define paths
define('EP_LINKS_PATH', plugin_dir_path(__FILE__));
define('EP_LINKS_URL', plugin_dir_url(__FILE__));

// Load Class
require_once EP_LINKS_PATH . 'class-ep-links.php';

// Register App
add_action('ep_register_apps', function ($manager) {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    
    if (class_exists('EP_App_Links')) {
        $manager->register_app(new EP_App_Links());
    }
});
