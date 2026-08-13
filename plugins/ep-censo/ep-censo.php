<?php
/**
 * Module Name: Employee Portal - Censo IAE
 * Description: Módulo para la gestión del Censo IAE
 * Package: pro_max
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-censo-manager.php';
require_once plugin_dir_path(__FILE__) . 'class-ep-app-censo.php';

// Inicializar el manager logic
new CensoManager();

/**
 * Hook de activación para crear la tabla de la base de datos si no existe.
 */
register_activation_hook(__FILE__, function () {
    require_once plugin_dir_path(__FILE__) . 'class-censo-db.php';
    require_once plugin_dir_path(__FILE__) . 'class-censo-config.php';
    $censo_db = new CensoDB();
    $censo_db->create_table();
});

// Registrar la APP Gráfica en el Portal
add_action('ep_register_apps', function ($manager) {
    if (class_exists('EP_App_Censo')) {
        $manager->register_app(new EP_App_Censo());
    }
});
