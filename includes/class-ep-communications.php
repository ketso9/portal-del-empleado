<?php

class EP_Communications
{

    public function __construct()
    {
        add_action('init', array($this, 'register_announcements_cpt'));
        add_action('add_meta_boxes', array($this, 'add_role_visibility_metabox'));
        add_action('save_post', array($this, 'save_role_visibility_meta'));
    }

    public function register_announcements_cpt()
    {
        $labels = array(
            'name' => _x('Anuncios', 'Post Type General Name', 'employee-portal'),
            'singular_name' => _x('Anuncio', 'Post Type Singular Name', 'employee-portal'),
            'menu_name' => __('Anuncios', 'employee-portal'),
            'name_admin_bar' => __('Anuncio', 'employee-portal'),
        );
        $args = array(
            'label' => __('Anuncio', 'employee-portal'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'thumbnail', 'excerpt'),
            'hierarchical' => false,
            'public' => true, // Visible on frontend
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 5,
            'menu_icon' => 'dashicons-megaphone',
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => true,
            'can_export' => true,
            'has_archive' => true,
            'exclude_from_search' => false,
            'publicly_queryable' => true,
            'capability_type' => 'post',
        );
        register_post_type('ep_announcement', $args);
    }

    public function add_role_visibility_metabox()
    {
        add_meta_box(
            'ep_role_visibility',
            __('Visibilidad por Rol', 'employee-portal'),
            array($this, 'render_role_visibility_metabox'),
            'ep_announcement',
            'side',
            'default'
        );
    }

    public function render_role_visibility_metabox($post)
    {
        $roles = EP_Roles::get_roles_list();
        $saved_roles = get_post_meta($post->ID, '_ep_visible_roles', true);
        if (!is_array($saved_roles)) {
            $saved_roles = array();
        }

        wp_nonce_field('ep_save_role_visibility', 'ep_role_visibility_nonce');

        echo '<p>' . __('Selecciona los roles que pueden ver este anuncio:', 'employee-portal') . '</p>';
        foreach ($roles as $role_key => $role_name) {
            $checked = in_array($role_key, $saved_roles) ? 'checked' : '';
            echo '<label><input type="checkbox" name="ep_visible_roles[]" value="' . esc_attr($role_key) . '" ' . $checked . '> ' . esc_html($role_name) . '</label><br>';
        }
    }

    public function save_role_visibility_meta($post_id)
    {
        if (!isset($_POST['ep_role_visibility_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['ep_role_visibility_nonce'], 'ep_save_role_visibility')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (isset($_POST['ep_visible_roles'])) {
            update_post_meta($post_id, '_ep_visible_roles', $_POST['ep_visible_roles']);
        } else {
            delete_post_meta($post_id, '_ep_visible_roles');
        }
    }

    // Helper to get announcements for current user
    public static function get_user_announcements($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        $user = get_userdata($user_id);
        $user_roles = $user->roles;

        // Query announcements that have meta matching user roles OR no meta (public)
        // This is a simplified query logic. For scalability, might need tax_query if using taxonomies instead of meta.
        // For now, we'll fetch recent and filter in PHP or use a complex meta query.

        $args = array(
            'post_type' => 'ep_announcement',
            'posts_per_page' => 5,
            'post_status' => 'publish',
        );

        $query = new WP_Query($args);
        $announcements = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $visible_roles = get_post_meta(get_the_ID(), '_ep_visible_roles', true);

                // If no roles defined, assume visible to all. Or check intersection.
                if (empty($visible_roles) || array_intersect($user_roles, $visible_roles) || in_array('administrator', $user_roles)) {
                    $announcements[] = array(
                        'title' => get_the_title(),
                        'excerpt' => get_the_excerpt(),
                        'date' => get_the_date(),
                        'link' => get_permalink(),
                        'id' => get_the_ID()
                    );
                }
            }
            wp_reset_postdata();
        }

        return $announcements;
    }
}
