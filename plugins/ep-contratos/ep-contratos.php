<?php

defined('ABSPATH') || exit;

/**
 * Plugin Name: EP Mini App: Registro de Contratos Menores
 * Description: Gestión del registro de contratos menores con numeración anual correlativa.
 * Version: 1.0.0
 * Author: Jorge Polo - Cámara de Comercio de Cáceres
 * Package: pro
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('EP_CONTRATOS_LOADED')) {
    return;
}
define('EP_CONTRATOS_LOADED', true);

// Define paths
define('EP_CONTRATOS_PATH', plugin_dir_path(__FILE__));
define('EP_CONTRATOS_URL', plugin_dir_url(__FILE__));

// Load Class
require_once EP_CONTRATOS_PATH . 'class-ep-contratos.php';

// Install table on first load (safe to call multiple times via dbDelta)
add_action('init', function () {
    static $installed = false;
    if ($installed) return;
    $installed = true;
    EP_Contratos::install_table();
}, 5);

// Register App in the portal manager
add_action('ep_register_apps', function ($manager) {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    $manager->register_app(new EP_App_Contratos());
});
