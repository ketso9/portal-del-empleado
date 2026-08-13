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

// El registro en EP_App_Manager lo hace class-ep-calendar.php (con guarda
// class_exists). Registrarlo también aquí instanciaba la app dos veces en cada
// petición sin aportar nada, porque el gestor indexa por id.
