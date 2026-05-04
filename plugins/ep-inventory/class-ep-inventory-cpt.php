<?php

defined('ABSPATH') || exit;

class EP_Inventory_CPT
{
    public function __construct()
    {
        add_action('init', array($this, 'register_cpt'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));

        // Security: Block direct access to ep_inventory_item posts
        add_action('template_redirect', array($this, 'block_direct_access'));
    }

    public function block_direct_access()
    {
        if (is_singular('ep_inventory_item')) {
            wp_redirect(home_url());
            exit;
        }
    }

    public function register_cpt()
    {
        $labels = array(
            'name' => _x('Inventario', 'Post Type General Name', 'employee-portal'),
            'singular_name' => _x('Item Inventario', 'Post Type Singular Name', 'employee-portal'),
            'menu_name' => __('Inventario', 'employee-portal'),
            'name_admin_bar' => __('Inventario', 'employee-portal'),
        );
        $args = array(
            'label' => __('Item Inventario', 'employee-portal'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'thumbnail', 'custom-fields'),
            'hierarchical' => false,
            'public' => false, // Accessed via our portal interface mainly
            'show_ui' => true,  // Show in WP Admin for backup/debug
            'show_in_menu' => true,
            'menu_position' => 20,
            'menu_icon' => 'dashicons-desktop',
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => false,
            'can_export' => true,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'capability_type' => 'post',
        );
        register_post_type('ep_inventory_item', $args);

        // Register Material Request CPT
        $labels_req = array(
            'name' => 'Solicitudes Material',
            'singular_name' => 'Solicitud Material'
        );
        $args_req = array(
            'labels' => $labels_req,
            'public' => false,
            'show_ui' => false, // We will show it in our custom admin page
            'supports' => array('title', 'editor', 'author', 'custom-fields'),
            'capability_type' => 'post'
        );
        register_post_type('ep_material_request', $args_req);
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'ep_inventory_details',
            'Detalles del Item',
            array($this, 'render_meta_box'),
            'ep_inventory_item',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        // Retrieve current values
        $type = get_post_meta($post->ID, '_ep_item_type', true); // hardware / software
        $serial = get_post_meta($post->ID, '_ep_item_serial', true);
        $purchase_date = get_post_meta($post->ID, '_ep_item_purchase_date', true);
        $warranty_date = get_post_meta($post->ID, '_ep_item_warranty_date', true);
        $provider = get_post_meta($post->ID, '_ep_item_provider', true);
        $assigned_to = get_post_meta($post->ID, '_ep_item_assigned_to', true); // User ID
        $is_itinerant = get_post_meta($post->ID, '_ep_item_is_itinerant', true);
        $itinerant_status = get_post_meta($post->ID, '_ep_item_itinerant_status', true);

        wp_nonce_field('ep_inventory_save_meta', 'ep_inventory_nonce');
        ?>
        <table class="form-table">
            <tr>
                <th><label for="ep_item_type">Tipo</label></th>
                <td>
                    <select name="ep_item_type" id="ep_item_type">
                        <option value="hardware" <?php selected($type, 'hardware'); ?>>Hardware</option>
                        <option value="software" <?php selected($type, 'software'); ?>>Software</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ep_item_serial">Nº Serie / Licencia</label></th>
                <td>
                    <input type="text" name="ep_item_serial" id="ep_item_serial" value="<?php echo esc_attr($serial); ?>"
                        class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="ep_item_purchase_date">Fecha Compra</label></th>
                <td>
                    <input type="date" name="ep_item_purchase_date" id="ep_item_purchase_date"
                        value="<?php echo esc_attr($purchase_date); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="ep_item_warranty_date">Fin Garantía / Renovación</label></th>
                <td>
                    <input type="date" name="ep_item_warranty_date" id="ep_item_warranty_date"
                        value="<?php echo esc_attr($warranty_date); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="ep_item_provider">Proveedor</label></th>
                <td>
                    <input type="text" name="ep_item_provider" id="ep_item_provider" value="<?php echo esc_attr($provider); ?>"
                        class="regular-text">
                </td>
            </tr>
            <tr>
                </td>
            </tr>
            <tr>
                <th><label for="ep_item_is_itinerant">Material Itinerante</label></th>
                <td>
                    <input type="checkbox" name="ep_item_is_itinerant" id="ep_item_is_itinerant" value="1" <?php checked($is_itinerant, '1'); ?>>
                    <span class="description">Marcar si este item es para préstamos cortos (cursos, etc.)</span>
                </td>
            </tr>
            <?php if ($is_itinerant): ?>
                <tr>
                    <th><label for="ep_item_itinerant_status">Estado Préstamo</label></th>
                    <td>
                        <select name="ep_item_itinerant_status" id="ep_item_itinerant_status">
                            <option value="available" <?php selected($itinerant_status, 'available'); ?>>Disponible</option>
                            <option value="loaned" <?php selected($itinerant_status, 'loaned'); ?>>Prestado</option>
                            <option value="maintenance" <?php selected($itinerant_status, 'maintenance'); ?>>En Mantenimiento
                            </option>
                        </select>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    public function save_meta_boxes($post_id)
    {
        if (!isset($_POST['ep_inventory_nonce']) || !wp_verify_nonce($_POST['ep_inventory_nonce'], 'ep_inventory_save_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array(
            'ep_item_type',
            'ep_item_serial',
            'ep_item_purchase_date',
            'ep_item_warranty_date',
            'ep_item_provider',
            'ep_item_assigned_to',
            'ep_item_is_itinerant',
            'ep_item_itinerant_status'
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
}
