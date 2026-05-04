<?php
/**
 * Plugin Name: EP Mini App: Buzón
 * Description: Buzón de sugerencias, incidencias y reportes confidenciales.
 * Version: 1.0.0
 * Author: Antigravity
 * Package: pro
 */

defined('ABSPATH') || exit;

if (defined('EP_BUZON_LOADED')) {
    return;
}
define('EP_BUZON_LOADED', true);

// Define paths
define('EP_BUZON_PATH', plugin_dir_path(__FILE__));
define('EP_BUZON_URL', plugin_dir_url(__FILE__));

// Load Classes
require_once EP_BUZON_PATH . 'class-ep-buzon.php';
require_once EP_BUZON_PATH . 'class-ep-app-buzon.php';

// Register App
add_action('ep_register_apps', function ($manager) {
    $manager->register_app(new EP_App_Buzon());
});
