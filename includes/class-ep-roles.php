<?php

class EP_Roles
{

    public function __construct()
    {
        // Hook into init to ensure roles exist, though activation hook is primary
        add_action('init', array($this, 'add_roles'));
    }

    public function add_roles()
    {
        // Worker Role
        add_role(
            'ep_worker',
            __('Trabajador', 'employee-portal'),
            array(
                'read' => true,
                'edit_posts' => false,
                'delete_posts' => false,
            )
        );

        // HR Role
        add_role(
            'ep_hr',
            __('Recursos Humanos', 'employee-portal'),
            array(
                'read' => true,
                'edit_posts' => true, // Can create announcements
                'manage_options' => false,
            )
        );

        // Direction Role
        add_role(
            'ep_direction',
            __('Dirección', 'employee-portal'),
            array(
                'read' => true,
                'edit_posts' => true,
            )
        );

        // Communication Role
        add_role(
            'ep_communication',
            __('Comunicación', 'employee-portal'),
            array(
                'read' => true,
                'edit_posts' => true,
                'publish_posts' => true,
            )
        );

        // Maintenance/IT Role
        add_role(
            'ep_maintenance',
            __('Mantenimiento', 'employee-portal'),
            array(
                'read' => true,
                'edit_posts' => true,
            )
        );

        // Super Admin Role
        $admin_role = get_role('administrator');
        $admin_caps = $admin_role ? $admin_role->capabilities : array('manage_options' => true, 'read' => true);
        add_role(
            'ep_super_admin',
            __('Super Administrador', 'employee-portal'),
            $admin_caps
        );
    }

    public static function get_roles_list()
    {
        $wp_roles = wp_roles();
        $roles = array();

        // Core WP roles to exclude from the portal (they're not relevant)
        $exclude = array('subscriber', 'contributor', 'author', 'editor');

        foreach ($wp_roles->roles as $role_id => $role_info) {
            if (in_array($role_id, $exclude)) {
                continue;
            }
            $roles[$role_id] = translate_user_role($role_info['name']);
        }

        return $roles;
    }
}
