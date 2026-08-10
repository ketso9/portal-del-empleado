<?php

defined('ABSPATH') || exit;

/**
 * Handles Microsoft Graph Webhooks for Teams Presence
 */
class EP_Teams_Webhook
{

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_rest_route'));
        add_action('ep_teams_sync_subscriptions', array($this, 'sync_subscriptions'));

        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('ep_teams_sync_subscriptions')) {
            wp_schedule_event(time(), 'daily', 'ep_teams_sync_subscriptions');
        }
    }

    public function register_rest_route()
    {
        register_rest_route('employee-portal/v1', '/teams-presence', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Entry point for Graph API notifications
     */
    public function handle_webhook(WP_REST_Request $request)
    {
        // 1. Handle validation challenge from Microsoft
        $validation_token = $request->get_param('validationToken');
        if ($validation_token) {
            return new WP_REST_Response($validation_token, 200, array('Content-Type' => 'text/plain'));
        }

        // 2. Process notifications
        $payload = $request->get_json_params();
        if (isset($payload['value']) && is_array($payload['value'])) {
            foreach ($payload['value'] as $notification) {
                $this->process_notification($notification);
            }
        }

        return new WP_REST_Response(null, 202);
    }

    private function process_notification($notification)
    {
        $resource = $notification['resource'] ?? '';
        // Format: users/{id}/presence
        if (preg_match('/users\/(.*?)\/presence/', $resource, $matches)) {
            $ms_user_id = $matches[1];

            // Find local user
            $users = get_users(array(
                'meta_key' => 'ep_o365_user_id',
                'meta_value' => $ms_user_id,
                'number' => 1
            ));

            if (!empty($users)) {
                $user_id = $users[0]->ID;
                $this->update_user_stats_from_presence($user_id, $notification);
            }
        }
    }

    private function update_user_stats_from_presence($user_id, $notification)
    {
        if (empty($user_id) || !is_array($notification)) {
            return;
        }

        // Presence data is usually in resourceData or encrypted. 
        // For simplicity in this logic, we treat receiving a notification as activity
        // But we specifically check for 'Available' if provided
        $presence_data = isset($notification['resourceData']) && is_array($notification['resourceData']) ? $notification['resourceData'] : [];
        $availability  = isset($presence_data['availability']) ? sanitize_text_field($presence_data['availability']) : 'Available';

        $online_statuses = ['Available', 'Busy', 'DoNotDisturb', 'BeRightBack', 'InACall', 'InAConferenceCall', 'InAMeeting', 'Presenting'];

        if (in_array($availability, $online_statuses, true)) {
            // Tell Stats DB to update (with 300 seconds increment)
            if (class_exists('EP_Stats_DB')) {
                EP_Stats_DB::update_session_activity($user_id, 300);
            }
        }
    }

    /**
     * Daily sync of subscriptions for all users
     */
    public function sync_subscriptions()
    {
        $users = get_users(array(
            'meta_key' => 'ep_o365_user_id',
            'meta_compare' => 'EXISTS',
            'fields' => array('ID')
        ));

        if (empty($users))
            return;

        $ms_ids = array();
        foreach ($users as $user) {
            $ms_id = get_user_meta($user->ID, 'ep_o365_user_id', true);
            if ($ms_id)
                $ms_ids[] = $ms_id;
        }

        // Microsoft limits: Presence subscriptions can be done in bulk
        // but often we do per-user or small groups.
        $chunks = array_chunk($ms_ids, 20);
        foreach ($chunks as $index => $chunk) {
            $this->manage_subscription_chunk($chunk, $index);
        }
    }

    private function manage_subscription_chunk($ms_ids, $index)
    {
        $option_key = 'ep_teams_sub_id_' . $index;
        $existing_sub = get_option($option_key);

        $auth = EP_Auth_O365::get_instance();

        // Subscription details
        $webhook_url = home_url('/wp-json/employee-portal/v1/teams-presence');
        $expiration = date('c', strtotime('+3 days')); // Presence subs max is 3 days approx

        if ($existing_sub) {
            // Attempt to renew (PATCH)
            $res = $auth->renew_presence_subscription($existing_sub, $expiration);
            if (is_wp_error($res)) {
                // If failed (deleted?), try creating new
                $this->create_new_subscription($ms_ids, $webhook_url, $expiration, $option_key);
            }
        } else {
            $this->create_new_subscription($ms_ids, $webhook_url, $expiration, $option_key);
        }
    }

    private function create_new_subscription($ms_ids, $url, $expiration, $option_key)
    {
        $auth = EP_Auth_O365::get_instance();
        $sub_id = $auth->create_presence_subscription($ms_ids, $url, $expiration);
        if (!is_wp_error($sub_id)) {
            update_option($option_key, $sub_id);
        }
    }
}
