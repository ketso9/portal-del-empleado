<?php

defined('ABSPATH') || exit;

/**
 * Plugin Name: EP Mini App: Avisos
 * Description: Sistema de Avisos Generales para el Portal del Empleado.
 * Version: 1.0.0
 * Author: Jorge Polo
 * Package: basic
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define paths
define('EP_AVISOS_PATH', plugin_dir_path(__FILE__));
define('EP_AVISOS_URL', plugin_dir_url(__FILE__));

// Load Class
require_once EP_AVISOS_PATH . 'class-ep-avisos.php';

// Register App
add_action('ep_register_apps', function ($manager) {
    $manager->register_app(new EP_App_Avisos());
});
