<?php

defined('ABSPATH') || exit;

class EP_App_Buzon implements EP_App_Interface
{
    public function get_id()
    {
        return 'buzon';
    }

    public function get_name()
    {
        return 'Buzón Ético y Sugerencias';
    }

    public function get_icon()
    {
        return 'fa-solid fa-inbox';
    }

    public function get_menu_label()
    {
        return 'Buzón';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=buzon'">
            <div class="app-icon-container color-purple">
                <i class="fa-solid fa-inbox"></i>
            </div>
            <h3>Buzón</h3>
            <p>Sugerencias y reportes</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_BUZON_PATH . 'partials/buzon-app.php';
    }

    public function handle_ajax()
    {
        // Handled in EP_Buzon for core logic
    }
}
