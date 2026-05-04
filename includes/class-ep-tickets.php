<?php

class EP_Tickets
{

    public function __construct()
    {
        add_action('init', array($this, 'register_tickets_cpt'));
        add_action('init', array($this, 'handle_ticket_submission'));
    }

    public function register_tickets_cpt()
    {
        $labels = array(
            'name' => _x('Tickets', 'Post Type General Name', 'employee-portal'),
            'singular_name' => _x('Ticket', 'Post Type Singular Name', 'employee-portal'),
            'menu_name' => __('Tickets', 'employee-portal'),
        );
        $args = array(
            'label' => __('Ticket', 'employee-portal'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'author', 'comments'), // Comments for ticket replies
            'hierarchical' => false,
            'public' => false, // Not publicly queryable like posts
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 6,
            'menu_icon' => 'dashicons-tickets-alt',
            'capability_type' => 'post',
            // 'capabilities' => array(...) // Could refine capabilities here
        );
        register_post_type('ep_ticket', $args);
    }

    public function handle_ticket_submission()
    {
        if (isset($_POST['ep_submit_ticket']) && isset($_POST['ep_ticket_nonce'])) {
            if (!wp_verify_nonce($_POST['ep_ticket_nonce'], 'ep_new_ticket')) {
                return;
            }

            $title = sanitize_text_field($_POST['ticket_subject']);
            $content = sanitize_textarea_field($_POST['ticket_message']);
            $type = sanitize_text_field($_POST['ticket_type']); // IT or Communication

            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'publish', // Or 'pending'
                'post_type' => 'ep_ticket',
                'post_author' => get_current_user_id(),
            ));

            if ($post_id) {
                update_post_meta($post_id, '_ep_ticket_type', $type);
                update_post_meta($post_id, '_ep_ticket_status', 'open');

                if (isset($_POST['ticket_asset']) && !empty($_POST['ticket_asset'])) {
                    update_post_meta($post_id, '_ep_ticket_related_asset', intval($_POST['ticket_asset']));
                }

                // EP_Notifications integration
                if (class_exists('EP_Notifications')) {
                    EP_Notifications::add_notification(get_current_user_id(), array(
                        'type' => 'success',
                        'title' => 'Ticket Creado',
                        'message' => 'Tu ticket "' . $title . '" ha sido registrado correctamente.',
                        'link' => '?view=tickets' // Or a specific link to the ticket if applicable
                    ));
                }

                // Log stats
                if (function_exists('ep_stats_log')) {
                    ep_stats_log('tickets', 'ticket_created', get_current_user_id(), [
                        'ticket_id' => $post_id,
                        'type' => $type,
                        'subject' => $title
                    ]);
                }

                // Redirect to avoid resubmission
                wp_redirect(add_query_arg('ticket_submitted', 'true', (string) ($_SERVER['REQUEST_URI'] ?? '')));
                exit;
            }
        }
    }

    public static function get_user_tickets($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $args = array(
            'post_type' => 'ep_ticket',
            'author' => $user_id,
            'posts_per_page' => -1,
        );

        return get_posts($args);
    }

    public static function get_user_assets_for_select($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_ep_item_assigned_to',
                    'value' => $user_id,
                    'compare' => '='
                )
            )
        );

        $items = get_posts($args);
        $assets = [];

        foreach ($items as $item) {
            $serial = get_post_meta($item->ID, '_ep_item_serial', true);
            $assets[$item->ID] = $item->post_title . ($serial ? " ($serial)" : "");
        }

        return $assets;
    }
}
