<?php
defined('ABSPATH') || exit;

class EP_App_Inventory implements EP_App_Interface
{
    public function get_id()
    {
        return 'inventory';
    }

    public function get_name()
    {
        return 'Inventario';
    }

    public function get_icon()
    {
        return 'fa-solid fa-boxes-stacked';
    }

    public function get_menu_label()
    {
        return 'Inventario';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=inventory'">
            <div class="app-icon-container color-purple">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <h3>Inventario</h3>
            <p>Gestión de Hardware y Software</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        global $ep_app_manager;
        $perm = $ep_app_manager->get_user_permission('inventory');

        // Managers and Itinerant Managers
        if ($perm === 'write' || $perm === 'manage_itinerant') {
            include EP_INVENTORY_PATH . 'views/admin-inventory.php';
        } else {
            // Employees
            include EP_INVENTORY_PATH . 'views/user-inventory.php';
        }
    }

    public function handle_ajax()
    {
        // Handled globally in main class or here if preferred. 
        // We left it in global class for now.
    }
}
