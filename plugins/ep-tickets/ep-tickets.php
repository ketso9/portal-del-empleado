<?php

defined('ABSPATH') || exit;
/**
 * Plugin Name: EP Mini App: Tickets
 * Description: Sistema de Tickets y Soporte para el Portal del Empleado.
 * Version: 1.0.0
 * Author: Jorge Polo
 * Package: pro
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('EP_TICKETS_LOADED')) {
    return;
}
define('EP_TICKETS_LOADED', true);

// Define paths
define('EP_TICKETS_PATH', plugin_dir_path(__FILE__));
define('EP_TICKETS_URL', plugin_dir_url(__FILE__));

// Load Class
require_once EP_TICKETS_PATH . 'class-ep-tickets.php';

// Register App
add_action('ep_register_apps', function ($manager) {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    $manager->register_app(new EP_App_Tickets());
});
