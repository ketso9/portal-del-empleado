<?php

/**
 * Interface for Employee Portal Mini Apps
 */
interface EP_App_Interface
{
    /**
     * Get the unique ID of the app.
     * @return string
     */
    public function get_id();

    /**
     * Get the display name of the app.
     * @return string
     */
    public function get_name();

    /**
     * Get the FontAwesome icon class for the app.
     * @return string
     */
    public function get_icon();

    /**
     * Get the label for the menu item.
     * @return string
     */
    public function get_menu_label();

    /**
     * Render the dashboard card content.
     */
    public function render_dashboard_card();

    /**
     * Render the full view content.
     */
    public function render_full_view();

    /**
     * Handle app-specific AJAX requests.
     */
    public function handle_ajax();
}
