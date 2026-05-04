<?php

defined('ABSPATH') || exit;
/**
 * Plugin Name: EP Mini App: Downloads
 * Description: Gestor de Descargas y Documentos.
 * Version: 1.0.0
 * Author: Jorge Polo
 * Package: pro
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EP_DOWNLOADS_PATH', plugin_dir_path(__FILE__));
define('EP_DOWNLOADS_URL', plugin_dir_url(__FILE__));

// Load Logic
require_once EP_DOWNLOADS_PATH . 'class-ep-downloads.php';
new EP_Downloads();

class EP_App_Downloads implements EP_App_Interface
{
    public function get_id()
    {
        return 'downloads';
    }

    public function get_name()
    {
        return 'Recursos y Gestión';
    }

    public function get_icon()
    {
        return 'fa-solid fa-folder-tree';
    }

    public function get_menu_label()
    {
        return 'Recursos de la empresa';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=downloads'">
            <div class="app-icon-container color-orange">
                <i class="fa-solid fa-folder-open"></i>
            </div>
            <h3>Recursos y Gestión</h3>
            <p>Documentos y archivos personales</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_DOWNLOADS_PATH . 'partials/downloads-app.php';
    }

    public function handle_ajax()
    {
    }
}

// Register App
add_action('ep_register_apps', function ($manager) {
    $manager->register_app(new EP_App_Downloads());
});
