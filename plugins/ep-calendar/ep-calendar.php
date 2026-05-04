<?php

defined('ABSPATH') || exit;

/**
 * Plugin Name: EP Mini App: Agenda
 * Description: Sistema de Agenda sincronizado con Microsoft O365.
 * Version: 1.0.0
 * Author: Jorge Polo
 * Package: pro
 */

// Define paths
define('EP_CALENDAR_PATH', plugin_dir_path(__FILE__));
define('EP_CALENDAR_URL', plugin_dir_url(__FILE__));

// Load Classes
require_once EP_CALENDAR_PATH . 'class-ep-calendar.php';

// Register App
add_action('ep_register_apps', function ($manager) {
    $calendar_app = new EP_App_Calendar();
    $manager->register_app($calendar_app);
});
