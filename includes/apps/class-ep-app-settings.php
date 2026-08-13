<?php
defined('ABSPATH') || exit;

class EP_App_Settings implements EP_App_Interface
{
    public function __construct()
    {
        add_action('wp_ajax_ep_settings_action', array($this, 'handle_ajax'));
    }

    public function get_id()
    {
        return 'settings';
    }

    public function get_name()
    {
        return 'Configuración';
    }

    public function get_icon()
    {
        return 'fa-solid fa-cog';
    }

    public function get_menu_label()
    {
        return 'Ajustes';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=settings'">
            <div class="app-icon-container color-gray">
                <i class="fa-solid fa-gear"></i>
            </div>
            <h3>Configuración</h3>
            <p>Preferencias y ajustes del portal</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EMPLOYEE_PORTAL_PATH . 'public/partials/settings-app.php';
    }

    public function handle_ajax()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $action = isset($_POST['ep_action']) ? sanitize_text_field($_POST['ep_action']) : '';

        if ($action === 'save_user_settings') {
            $user_id = get_current_user_id();
            parse_str($_POST['formData'], $form_data);

            $notifications_email = isset($form_data['notifications_email']) ? 1 : 0;
            $notifications_app = isset($form_data['notifications_app']) ? 1 : 0;

            update_user_meta($user_id, 'ep_notifications_email', $notifications_email);
            update_user_meta($user_id, 'ep_notifications_app', $notifications_app);

            // La preferencia de Teams solo se toca si el portal tiene el canal
            // contratado; si no, el interruptor ni siquiera se pinta y guardar
            // el formulario la pondria a 0 para todo el mundo. El dia que la
            // Camara subiera a PRO MAX, nadie recibiria nada en Teams y nadie
            // sabria por que.
            if (!function_exists('ep_teams_channel_enabled') || ep_teams_channel_enabled()) {
                update_user_meta($user_id, 'ep_notifications_teams', isset($form_data['notifications_teams']) ? 1 : 0);
            }

            wp_send_json_success('Preferencias guardadas.');
        }

        if ($action === 'save_user_signature_details') {
            $user_id = get_current_user_id();
            $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $mobile = isset($_POST['mobile']) ? sanitize_text_field($_POST['mobile']) : '';

            update_user_meta($user_id, 'ep_phone', $phone);
            update_user_meta($user_id, 'ep_mobile', $mobile);

            wp_send_json_success('Datos de firma actualizados.');
        }

        if ($action === 'save_settings_layout') {
            $user_id = get_current_user_id();
            $order = isset($_POST['order']) ? $_POST['order'] : array();
            update_user_meta($user_id, 'ep_settings_layout_order', $order);
            wp_send_json_success('Orden de diseño guardado.');
        }

        if (current_user_can('administrator')) {
            global $ep_app_manager;
            $config = $ep_app_manager->get_config();

            if ($action === 'save_admin_apps') {
                $app_status = isset($_POST['app_status']) ? $_POST['app_status'] : array();

                foreach ($ep_app_manager->get_apps() as $id => $app) {
                    if ($id === 'settings')
                        continue;
                    $config[$id]['active'] = isset($app_status[$id]) && $app_status[$id] == 1;
                }

                $ep_app_manager->save_config($config);
                wp_send_json_success('Módulos actualizados.');
            }

            if ($action === 'save_admin_perms') {
                parse_str($_POST['formData'], $form_data);
                $perms = isset($form_data['perms']) ? $form_data['perms'] : array();

                foreach ($perms as $app_id => $role_perms) {
                    $config[$app_id]['permissions'] = $role_perms;
                }

                $ep_app_manager->save_config($config);
                wp_send_json_success('Matriz de permisos actualizada.');
            }

            if ($action === 'save_user_override') {
                $user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
                $app_id = isset($_POST['app_id']) ? sanitize_key($_POST['app_id']) : '';
                $perm = isset($_POST['perm']) ? sanitize_text_field($_POST['perm']) : 'none';

                if ($user_id > 0 && !empty($app_id)) {
                    $user_perms = get_user_meta($user_id, 'ep_app_permissions', true);
                    if (!is_array($user_perms))
                        $user_perms = array();

                    if ($perm === 'default') {
                        unset($user_perms[$app_id]);
                    } else {
                        $user_perms[$app_id] = $perm;
                    }

                    update_user_meta($user_id, 'ep_app_permissions', $user_perms);
                    wp_send_json_success('Permiso específico actualizado para el usuario.');
                }
            }

            if ($action === 'save_global_signature_template') {
                parse_str($_POST['formData'], $form_data);
                $template = isset($form_data['signature_template']) ? $form_data['signature_template'] : array();
                update_option('ep_global_signature_template', $template);
                wp_send_json_success('Plantilla global de firma actualizada.');
            }

            if ($action === 'save_maintenance_mode') {
                $status = isset($_POST['maintenance_mode']) ? intval($_POST['maintenance_mode']) : 0;
                $notif_status = isset($_POST['global_notifications_disabled']) ? intval($_POST['global_notifications_disabled']) : 0;
                update_option('ep_maintenance_mode', $status);
                update_option('ep_global_notifications_disabled', $notif_status);
                wp_send_json_success('Estado de mantenimiento y notificaciones actualizado.');
            }

            if ($action === 'save_portal_customization') {
                parse_str($_POST['formData'], $form_data);
                $customization = isset($form_data['portal_customization']) ? $form_data['portal_customization'] : array();

                // Sanitize fields
                $sanitized = array(
                    'logo_url' => esc_url_raw($customization['logo_url'] ?? ''),
                    'portal_name' => sanitize_text_field($customization['portal_name'] ?? 'Portal del Empleado'),
                    'primary_color' => sanitize_hex_color($customization['primary_color'] ?? '#a81c24'),
                    'main_font' => sanitize_text_field($customization['main_font'] ?? 'Inter'),
                );

                update_option('ep_portal_customization', $sanitized);
                wp_send_json_success('Personalización del portal guardada.');
            }

            if ($action === 'save_gdpr_settings') {
                parse_str($_POST['formData'], $form_data);
                $gdpr = isset($form_data['gdpr_settings']) ? $form_data['gdpr_settings'] : array();

                $sanitized = array(
                    'policy_url' => esc_url_raw($gdpr['policy_url'] ?? ''),
                    'cookies_url' => esc_url_raw($gdpr['cookies_url'] ?? ''),
                    'banner_text' => sanitize_textarea_field($gdpr['banner_text'] ?? ''),
                    'show_on_login' => isset($gdpr['show_on_login']) ? 1 : 0,
                );

                update_option('ep_gdpr_settings', $sanitized);
                wp_send_json_success('Configuración RGPD guardada correctamente.');
            }

            // === ROLE MANAGEMENT ===

            if ($action === 'assign_user_role') {
                $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
                $new_role = isset($_POST['new_role']) ? sanitize_key($_POST['new_role']) : '';

                if ($target_user_id <= 0 || empty($new_role)) {
                    wp_send_json_error('Datos inválidos.');
                }

                // Don't allow changing your own admin role
                if ($target_user_id === get_current_user_id() && $new_role !== 'administrator') {
                    wp_send_json_error('No puedes cambiar tu propio rol de administrador.');
                }

                $user = get_userdata($target_user_id);
                if (!$user) {
                    wp_send_json_error('Usuario no encontrado.');
                }

                $user->set_role($new_role);
                wp_send_json_success('Rol actualizado correctamente para ' . $user->display_name . '.');
            }

            if ($action === 'create_custom_role') {
                $role_name = isset($_POST['role_name']) ? sanitize_text_field($_POST['role_name']) : '';

                if (empty($role_name)) {
                    wp_send_json_error('El nombre del rol no puede estar vacío.');
                }

                // Generate slug from name
                $role_id = 'ep_' . sanitize_key(str_replace(' ', '_', strtolower($role_name)));

                // Check if role already exists
                if (wp_roles()->is_role($role_id)) {
                    wp_send_json_error('El rol "' . $role_name . '" ya existe.');
                }

                add_role($role_id, $role_name, array('read' => true));
                wp_send_json_success('Rol "' . $role_name . '" creado correctamente.');
            }

            if ($action === 'delete_custom_role') {
                $role_id = isset($_POST['role_id']) ? sanitize_key($_POST['role_id']) : '';

                if (empty($role_id)) {
                    wp_send_json_error('Selecciona un rol para eliminar.');
                }

                // Protected roles that cannot be deleted
                $protected = array('administrator', 'ep_worker', 'ep_hr', 'ep_direction', 'ep_communication', 'ep_maintenance');
                if (in_array($role_id, $protected)) {
                    wp_send_json_error('Este rol está protegido y no se puede eliminar.');
                }

                // Reassign users with this role to ep_worker
                $users_with_role = get_users(array('role' => $role_id));
                foreach ($users_with_role as $user) {
                    $user->set_role('ep_worker');
                }

                remove_role($role_id);
                $count = count($users_with_role);
                $msg = 'Rol eliminado correctamente.';
                if ($count > 0) {
                    $msg .= ' ' . $count . ' usuario(s) reasignados a "Trabajador".';
                }
                wp_send_json_success($msg);
            }

            // === CUSTOM SIGNATURE HTML ===

            if ($action === 'save_custom_signature_html') {
                $raw_html = isset($_POST['custom_html']) ? $_POST['custom_html'] : '';

                // Pre-resolve {{assets_url}} before sanitizing so wp_kses_post sees valid URLs
                $assets_url = plugin_dir_url(dirname(dirname(__FILE__))) . 'public/assets/';
                $raw_html = str_replace('{{assets_url}}', esc_url($assets_url), $raw_html);

                $custom_html = wp_kses_post($raw_html);
                update_option('ep_custom_signature_html', $custom_html);

                if (empty($custom_html)) {
                    wp_send_json_success('Plantilla personalizada eliminada. Se usará la firma por defecto.');
                }
                wp_send_json_success('Plantilla HTML de firma guardada correctamente.');
            }
        }

        wp_send_json_error('Acción no permitida.');
    }
}

// Instantiate and register
$ep_app_settings = new EP_App_Settings();
add_action('ep_register_apps', function ($manager) use ($ep_app_settings) {
    $manager->register_app($ep_app_settings);
});
