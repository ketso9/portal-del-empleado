<?php

defined('ABSPATH') || exit;

/**
 * App interface implementation for the portal
 */
class EP_App_Links implements EP_App_Interface
{
    public function get_id()
    {
        return 'links';
    }

    public function get_name()
    {
        return 'Enlaces de Interés';
    }

    public function get_icon()
    {
        return 'fa-solid fa-link';
    }

    public function get_menu_label()
    {
        return 'Enlaces';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=links'">
            <div class="app-icon-container color-purple">
                <i class="fa-solid fa-link"></i>
            </div>
            <h3>Enlaces</h3>
            <p>Accesos directos de interés</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_LINKS_PATH . 'partials/links-app.php';
    }

    public function handle_ajax()
    {
        $action = isset($_POST['link_action']) ? sanitize_text_field($_POST['link_action']) : '';
        
        switch ($action) {
            case 'add':
                $this->ajax_add_link();
                break;
            case 'edit':
                $this->ajax_edit_link();
                break;
            case 'delete':
                $this->ajax_delete_link();
                break;
        }
    }

    private function ajax_edit_link()
    {
        check_ajax_referer('ep_links_nonce', 'security');

        if (!current_user_can('administrator')) {
            wp_send_json_error('No tienes permisos para realizar esta acción.');
        }

        $link_id = intval($_POST['link_id']);
        $title = sanitize_text_field($_POST['title']);
        $url = esc_url_raw($_POST['url']);
        $icon = sanitize_text_field($_POST['icon']);

        if (!$link_id || empty($title) || empty($url)) {
            wp_send_json_error('Datos incompletos para la edición.');
        }

        $updated = wp_update_post(array(
            'ID'         => $link_id,
            'post_title' => $title,
        ));

        if (!is_wp_error($updated)) {
            update_post_meta($link_id, '_ep_link_url', $url);
            update_post_meta($link_id, '_ep_link_icon', $icon ?: 'fa-solid fa-link');
            wp_send_json_success('Enlace actualizado correctamente.');
        } else {
            wp_send_json_error('Error al actualizar el enlace.');
        }
    }

    private function ajax_add_link()
    {
        check_ajax_referer('ep_links_nonce', 'security');

        if (!current_user_can('administrator')) {
            wp_send_json_error('No tienes permisos para realizar esta acción.');
        }

        $title = sanitize_text_field($_POST['title']);
        $url = esc_url_raw($_POST['url']);
        $icon = sanitize_text_field($_POST['icon']);

        if (empty($title) || empty($url)) {
            wp_send_json_error('El título y el enlace son obligatorios.');
        }

        $post_id = wp_insert_post(array(
            'post_title'   => $title,
            'post_type'    => 'ep_link',
            'post_status'  => 'publish',
        ));

        if ($post_id) {
            update_post_meta($post_id, '_ep_link_url', $url);
            update_post_meta($post_id, '_ep_link_icon', $icon ?: 'fa-solid fa-link');
            wp_send_json_success('Enlace añadido correctamente.');
        } else {
            wp_send_json_error('Error al guardar el enlace.');
        }
    }

    private function ajax_delete_link()
    {
        check_ajax_referer('ep_links_nonce', 'security');

        if (!current_user_can('administrator')) {
            wp_send_json_error('No tienes permisos para realizar esta acción.');
        }

        $link_id = intval($_POST['link_id']);

        if ($link_id > 0 && get_post_type($link_id) === 'ep_link') {
            wp_delete_post($link_id, true);
            wp_send_json_success('Enlace eliminado correctamente.');
        } else {
            wp_send_json_error('Enlace no encontrado.');
        }
    }
}

/**
 * Logic and CPT registration
 */
class EP_Links
{
    public function __construct()
    {
        add_action('init', array($this, 'register_links_cpt'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('add_meta_boxes', array($this, 'add_links_metaboxes'));
        add_action('save_post', array($this, 'save_links_meta'));
        
        // Handle AJAX directly if not handled by the manager
        add_action('wp_ajax_ep_links_action', array($this, 'handle_standalone_ajax'));
    }

    public function add_links_metaboxes()
    {
        add_meta_box('ep_link_data', 'Datos del Enlace', array($this, 'render_links_metabox'), 'ep_link', 'normal', 'high');
    }

    public function render_links_metabox($post)
    {
        $url = get_post_meta($post->ID, '_ep_link_url', true);
        $icon = get_post_meta($post->ID, '_ep_link_icon', true);
        ?>
        <p>
            <label for="ep_link_url">URL del Enlace:</label><br>
            <input type="url" name="ep_link_url" id="ep_link_url" value="<?php echo esc_attr($url); ?>" class="widefat">
        </p>
        <p>
            <label for="ep_link_icon">Icono (FontAwesome):</label><br>
            <input type="text" name="ep_link_icon" id="ep_link_icon" value="<?php echo esc_attr($icon); ?>" class="widefat" placeholder="fa-solid fa-link">
        </p>
        <?php
    }

    public function save_links_meta($post_id)
    {
        if (isset($_POST['ep_link_url'])) {
            update_post_meta($post_id, '_ep_link_url', esc_url_raw($_POST['ep_link_url']));
        }
        if (isset($_POST['ep_link_icon'])) {
            update_post_meta($post_id, '_ep_link_icon', sanitize_text_field($_POST['ep_link_icon']));
        }
    }

    public function register_links_cpt()
    {
        $labels = array(
            'name'          => 'Enlaces',
            'singular_name' => 'Enlace',
            'menu_name'     => 'Enlaces Interés',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 30,
            'menu_icon'          => 'dashicons-admin-links',
            'supports'           => array('title'),
        );

        register_post_type('ep_link', $args);
    }

    public function enqueue_assets()
    {
        if (isset($_GET['view']) && $_GET['view'] === 'links') {
            wp_enqueue_style('ep-links-style', EP_LINKS_URL . 'assets/css/ep-links.css', array(), '1.0.8');
            // Enqueue SweetAlert2 for notifications
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array(), '11.0.0', true);
        }
    }

    public function handle_standalone_ajax()
    {
        $app = new EP_App_Links();
        $app->handle_ajax();
    }

    public static function get_links()
    {
        return get_posts(array(
            'post_type'      => 'ep_link',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }
}

new EP_Links();
