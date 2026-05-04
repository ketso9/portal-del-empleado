<?php

defined('ABSPATH') || exit;

require_once plugin_dir_path(__FILE__) . 'class-ep-graph-service.php';

#[AllowDynamicProperties]
class EP_Auth_O365
{

    public function __construct()
    {
        add_action('init', array($this, 'handle_oauth_callback'));
        // Shortcode for Login Button
        add_shortcode('ep_login_button', array($this, 'render_login_button'));

        // AJAX for profile update
        add_action('wp_ajax_ep_update_profile', array($this, 'handle_profile_update_ajax'));
        add_action('wp_ajax_ep_update_oof_settings', array($this, 'handle_oof_update_ajax'));

        // Current instance registration
        self::$instance = $this;
    }

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function get_valid_token($user_id)
    {
        return EP_Graph_Service::get_instance()->get_valid_token($user_id);
    }

    /**
     * Obtiene el ID del usuario responsable de la sincronización global (Espacio Público).
     * Asegura consistencia buscando primero al administrador principal.
     */
    public static function get_sync_principal_id()
    {
        // Intentar primero con el guardado
        $saved_principal = get_option('ep_onedrive_sync_principal');
        if ($saved_principal) {
            return (int) $saved_principal;
        }

        // Buscar el primer admin que tenga un token de O365 (para asegurar consistencia)
        $admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));
        foreach ($admins as $admin_id) {
            if (get_user_meta($admin_id, 'ep_o365_access_token', true)) {
                return (int) $admin_id;
            }
        }

        // Si no hay ninguno, usar el actual (fallback)
        $principal = get_current_user_id();
        ep_error_log("EP_Auth: No se encontró principal guardado ni admin con token. Usando fallback ID=$principal");
        return $principal;
    }

    public function render_login_button()
    {
        $client_id = ep_get_option('ep_o365_client_id');
        $tenant_id = ep_get_option('ep_o365_tenant_id');
        ep_error_log("EP: Renderizando botón de login. Config: ClientID=" . ($client_id ? 'OK' : 'MISSING') . ", TenantID=" . ($tenant_id ? 'OK' : 'MISSING'));
        $login_url = $this->get_login_url();


        $custom = get_option('ep_portal_customization', array());
        $logo_url = $custom['logo_url'] ?? plugin_dir_url(dirname(__FILE__)) . 'public/images/logo-portal.jpg';

        ob_start();
        ?>
        <div class="ep-login-page-wrapper">
            <div class="ep-login-bg"
                style="background-image: url('<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'public/images/login-bg-camara.jpg'); ?>');">
            </div>
            <div class="ep-login-overlay"></div>

            <div class="ep-login-container" id="ep-app-root">
                <div class="ep-login-card">

                    <div class="ep-login-logo">

                        <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo get_bloginfo('name'); ?>">
                    </div>

                    <p class="ep-login-subtitle">Bienvenido al Portal del Empleado</p>

                    <?php
                    $is_teams = isset($_GET['teams']) && $_GET['teams'] === 'true';
                    $target_attr = $is_teams ? 'target="_blank"' : '';

                    // Si estamos en Teams, vamos a un endpoint intermedio que se abrirá en pestaña nueva
                    // Esto asegura que el 'state' se genere en la misma sesión donde se validará.
                    $button_url = $is_teams ? add_query_arg('ep_auth', 'o365_start', home_url('/')) : $login_url;
                    ?>
                    <a href="<?php echo esc_url($button_url); ?>" <?php echo $target_attr; ?> class="ep-ms-login-btn">
                        <div class="ep-ms-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 21 21">
                                <rect x="1" y="1" width="9" height="9" fill="#f25022" />
                                <rect x="1" y="11" width="9" height="9" fill="#00a4ef" />
                                <rect x="11" y="1" width="9" height="9" fill="#7fba00" />
                                <rect x="11" y="11" width="9" height="9" fill="#ffb900" />
                            </svg>
                        </div>
                        <span>Iniciar sesión con Office 365</span>
                    </a>


                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_login_url()
    {
        $client_id = ep_get_option('ep_o365_client_id');
        $tenant_id = ep_get_option('ep_o365_tenant_id');
        $redirect_uri = add_query_arg('ep_auth', 'o365', home_url('/'));

        if (empty($client_id) || empty($tenant_id)) {
            ep_error_log("EP: Error en get_login_url - ClientID o TenantID vacíos.");
            return '#';
        }

        $url = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/authorize";
        $params = array(
            'client_id'     => $client_id,
            'response_type' => 'code',
            'redirect_uri'  => $redirect_uri,
            'response_mode' => 'query',
            'scope'         => 'User.Read User.ReadWrite offline_access User.Read.All Calendars.Read Calendars.ReadWrite Presence.Read.All Tasks.ReadWrite Mail.Read Mail.Send Mail.Send.Shared Files.ReadWrite.All MailboxSettings.ReadWrite Chat.Create Chat.ReadWrite ChatMessage.Send',
            'state'         => wp_create_nonce('ep_oauth_state')
        );

        return add_query_arg($params, $url);
    }



    public function handle_oauth_callback()
    {
        if (isset($_GET['ep_auth'])) {
            if ($_GET['ep_auth'] === 'o365_start') {
                $login_url = $this->get_login_url();
                wp_redirect($login_url);
                exit;
            }

            if ($_GET['ep_auth'] === 'o365') {
                ep_error_log("EP: Callback de OAuth detectado. Parámetros: " . json_encode($_GET));

                if (!isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'ep_oauth_state')) {
                    ep_error_log("EP: Error de seguridad - Estado o Nonce inválido.");
                    wp_die('Error de seguridad: Estado inválido. Intenta iniciar sesión de nuevo.');
                }

                if (isset($_GET['error'])) {
                    ep_error_log("EP: Error de Microsoft - " . $_GET['error_description']);
                    wp_die('Error de Microsoft: ' . sanitize_text_field($_GET['error_description']));
                }

                if (isset($_GET['code'])) {
                    $code = sanitize_text_field($_GET['code']);
                    ep_error_log("EP: Código recibido, intercambiando por token...");
                    $token_data = $this->exchange_code_for_token($code);

                    if (is_wp_error($token_data)) {
                        ep_error_log("EP: Error en intercambio de token - " . $token_data->get_error_message());
                        wp_die('Error al obtener token: ' . $token_data->get_error_message());
                    }

                    ep_error_log("EP: Token obtenido correctamente.");
                    $user_profile = EP_Graph_Service::get_instance()->get_user_profile_from_graph($token_data['access_token']);

                    if (is_wp_error($user_profile)) {
                        ep_error_log("EP: Error obteniendo perfil - " . $user_profile->get_error_message());
                        wp_die('Error al obtener perfil: ' . $user_profile->get_error_message());
                    }

                    $email = $user_profile['mail'] ?? $user_profile['userPrincipalName'];
                    ep_error_log("EP: Perfil obtenido (Graph). Email: " . $email);

                    $user = get_user_by('email', $email);

                    if (!$user) {
                        ep_error_log("EP: Usuario no encontrado por email. Buscando por login...");
                        $user_login = sanitize_user(explode('@', $email)[0]);
                        $user = get_user_by('login', $user_login);
                    }

                    if (!$user) {
                        ep_error_log("EP: Usuario NO existe en WordPress. Ejecutando registro...");
                        $user_id = $this->login_or_register_user($user_profile);
                    } else {
                        ep_error_log("EP: Usuario localizado (ID: {$user->ID}).");
                        $user_id = $user->ID;
                        $this->sync_user_profile($user_id, $user_profile);
                    }

                    // Cifrar tokens antes de guardar
                    $access_token = $token_data['access_token'];
                    $refresh_token = $token_data['refresh_token'] ?? null;

                    if (class_exists('EP_Security')) {
                        $access_token = EP_Security::encrypt($access_token);
                        if ($refresh_token) {
                           $refresh_token = EP_Security::encrypt($refresh_token);
                        }
                    }

                    // Store tokens
                    update_user_meta($user_id, 'ep_o365_access_token', $access_token);
                    update_user_meta($user_id, 'ep_o365_token_last_refresh', time());
                    if ($refresh_token) {
                        update_user_meta($user_id, 'ep_o365_refresh_token', $refresh_token);
                    }

                    // Sincronizar la foto del usuario cada vez que inicia sesión para limpiar su caché de foto
                    $this->fetch_and_store_user_photo($user_id, $token_data['access_token']);

                    ep_error_log("EP: Iniciando sesión de WP para ID: " . $user_id);
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id, true); // Persistente

                    $current_user = get_userdata($user_id);
                    ep_error_log("EP: Ejecutando hook wp_login para: " . $current_user->user_login);
                    do_action('wp_login', $current_user->user_login, $current_user);

                    ep_error_log("EP: Login WP completado satisfactoriamente. Redirigiendo...");

                    wp_redirect(home_url('?view=dashboard'));
                    exit;
                }
            }
        }
    }

    private function exchange_code_for_token($code)
    {
        $client_id     = ep_get_option('ep_o365_client_id');
        $client_secret = ep_get_option('ep_o365_client_secret');
        $tenant_id     = ep_get_option('ep_o365_tenant_id');

        // Robustez: Si el secreto está cifrado por EP_Security, descifrarlo
        if (class_exists('EP_Security') && EP_Security::is_encrypted($client_secret)) {
            $client_secret = EP_Security::decrypt($client_secret);
        }

        $redirect_uri = add_query_arg('ep_auth', 'o365', home_url('/'));

        $url = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/token";

        $body = array(
            'client_id' => $client_id,
            'scope' => 'User.Read User.ReadWrite offline_access User.Read.All Calendars.Read Calendars.ReadWrite Presence.Read.All Tasks.ReadWrite Mail.Read Mail.Send Mail.Send.Shared Files.ReadWrite.All MailboxSettings.ReadWrite',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
            'client_secret' => $client_secret,
        );

        $response = wp_remote_post($url, array(
            'body' => $body,
            'timeout' => 45
        ));

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            ep_error_log("EP OAuth Error (Network): " . $err_msg, true);
            return $response;
        }

        $res_body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($res_body['error'])) {
            $err_desc = $res_body['error_description'] ?? $res_body['error'];
            ep_error_log("EP OAuth Error (Exchange): " . $err_desc, true);
            return new WP_Error('token_exchange_error', $err_desc);
        }

        return $res_body;
    }

    public function get_user_profile_from_graph($access_token)
    {
        return EP_Graph_Service::get_instance()->get_user_profile_from_graph($access_token);
    }

    private function login_or_register_user($profile)
    {
        $email = $profile['mail'] ?? $profile['userPrincipalName'];

        if (empty($email)) {
            ep_error_log("EP Auth: ERROR al intentar registrar usuario - Email vacío o nulo.");
            wp_die('Error: No se pudo obtener el email del usuario de Office 365 para completar el registro.');
        }

        $user = get_user_by('email', $email);

        if (!$user) {
            $username = sanitize_user(current(explode('@', $email)));
            $original_username = $username;
            $i = 1;
            while (username_exists($username)) {
                $username = $original_username . $i;
                $i++;
            }

            $password = wp_generate_password();
            $user_id = wp_create_user($username, $password, $email);

            if (is_wp_error($user_id)) {
                wp_die('Error al crear usuario: ' . $user_id->get_error_message());
            }

            $user = get_user_by('id', $user_id);
            $user->set_role('ep_worker');
        }

        $this->sync_user_profile($user->ID, $profile);

        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login', $user->user_login, $user);

        return $user->ID;
    }

    public function sync_user_profile($wp_user_id, $o365_data)
    {
        if (isset($o365_data['id'])) {
            update_user_meta($wp_user_id, 'ep_o365_user_id', $o365_data['id']);
        }

        // Check if we have a recent local update (within last 10 minutes)
        $last_local_update = (int) get_user_meta($wp_user_id, 'ep_profile_local_updated_at', true);
        $is_recent_local = (time() - $last_local_update < 600);

        if (!$is_recent_local) {
            $this->smart_update_meta($wp_user_id, 'first_name', $o365_data['givenName'] ?? '');
            $this->smart_update_meta($wp_user_id, 'last_name', $o365_data['surname'] ?? '');

            // Only overwrite if O365 has data, otherwise keep local changes
            if (!empty($o365_data['jobTitle'])) {
                $this->smart_update_meta($wp_user_id, 'ep_job_title', $o365_data['jobTitle']);
            }
            if (!empty($o365_data['mobilePhone'])) {
                $this->smart_update_meta($wp_user_id, 'ep_mobile_phone', $o365_data['mobilePhone']);
            }
            if (!empty($o365_data['officeLocation'])) {
                $this->smart_update_meta($wp_user_id, 'ep_office_location', $o365_data['officeLocation']);
            }
            if (!empty($o365_data['department'])) {
                $this->smart_update_meta($wp_user_id, 'ep_department', $o365_data['department']);
            }

            $business_phones = $o365_data['businessPhones'] ?? array();
            if (!empty($business_phones)) {
                $this->smart_update_meta($wp_user_id, 'ep_business_phone', $business_phones[0]);
            }

            // Update display name
            wp_update_user(array(
                'ID' => $wp_user_id,
                'display_name' => $o365_data['displayName']
            ));
        } else {
            ep_error_log("EP Auth: Sincronización entrante omitida para usuario $wp_user_id (Cambio local reciente detectado).");
        }
    }

    /**
     * Helper to update user meta only if the value actually changed.
     */
    private function smart_update_meta($user_id, $key, $new_value)
    {
        $old_value = get_user_meta($user_id, $key, true);
        if ($old_value !== $new_value) {
            update_user_meta($user_id, $key, $new_value);
        }
    }

    private function fetch_and_store_user_photo($user_id, $access_token)
    {
        $response = EP_Graph_Service::get_instance()->fetch_user_photo($user_id, $access_token);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            $image_content = wp_remote_retrieve_body($response);
            $upload_dir = wp_upload_dir();
            $filename = 'user_photo_' . $user_id . '.jpg';
            $file_path = $upload_dir['path'] . '/' . $filename;

            file_put_contents($file_path, $image_content);

            $file_url = $upload_dir['url'] . '/' . $filename;
            update_user_meta($user_id, 'ep_user_photo_url', $file_url);
        }
    }

    public function handle_profile_update_ajax()
    {
        check_ajax_referer('ep_ajax_nonce', 'security'); // Ensure you send this nonce in JS

        if (!is_user_logged_in()) {
            wp_send_json_error('No has iniciado sesión.');
        }

        $user_id = get_current_user_id();

        // Sanitize inputs
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $extension = isset($_POST['extension']) ? sanitize_text_field($_POST['extension']) : ''; // Office Location
        $job_title = isset($_POST['job_title']) ? sanitize_text_field($_POST['job_title']) : '';
        $department = isset($_POST['department']) ? sanitize_text_field($_POST['department']) : '';
        $office_phone = isset($_POST['office_phone']) ? sanitize_text_field($_POST['office_phone']) : '';

        // Update Local Meta
        update_user_meta($user_id, 'ep_mobile_phone', $phone);
        update_user_meta($user_id, 'ep_office_location', $extension);
        update_user_meta($user_id, 'ep_job_title', $job_title);
        update_user_meta($user_id, 'ep_department', $department);
        update_user_meta($user_id, 'ep_business_phone', $office_phone);

        // Mark this update to prevent incoming O365 sync from overwriting it during propagation lag
        update_user_meta($user_id, 'ep_profile_local_updated_at', time());

        // Attempt to update Office 365
        $access_token = get_user_meta($user_id, 'ep_o365_access_token', true);

        if ($access_token) {
            $update_data = array();
            if (!empty($phone))
                $update_data['mobilePhone'] = $phone;
            if (!empty($extension))
                $update_data['officeLocation'] = $extension;
            if (!empty($job_title))
                $update_data['jobTitle'] = $job_title;
            if (!empty($department))
                $update_data['department'] = $department;
            if (!empty($office_phone))
                $update_data['businessPhones'] = [$office_phone];

            if (!empty($update_data)) {
                $result = EP_Graph_Service::get_instance()->update_graph_profile($access_token, $update_data);

                if (is_wp_error($result)) {
                    // Log error but return success for local update
                    ep_error_log('O365 Update Error: ' . $result->get_error_message());
                    wp_send_json_success('Perfil actualizado localmente. Error en O365: ' . $result->get_error_message());
                }
            }
        }

        // Send portal notification
        if (class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($user_id, array(
                'type' => 'success',
                'title' => 'Perfil Actualizado',
                'message' => 'Has actualizado correctamente tu información de perfil.',
                'link' => '?view=profile'
            ));
        }

        wp_send_json_success('Perfil actualizado correctamente.');
    }

    public function update_graph_profile($access_token, $data)
    {
        return EP_Graph_Service::get_instance()->update_graph_profile($access_token, $data);
    }

    public function refresh_access_token($user_id)
    {
        return EP_Graph_Service::get_instance()->refresh_access_token($user_id);
    }

    public function get_users_presence($user_id, $ms_user_ids)
    {
        return EP_Graph_Service::get_instance()->get_users_presence($user_id, $ms_user_ids);
    }

    public function get_next_event($user_id)
    {
        return EP_Graph_Service::get_instance()->get_next_event($user_id);
    }

    public function get_my_tasks($user_id)
    {
        return EP_Graph_Service::get_instance()->get_my_tasks($user_id);
    }

    public function get_recent_emails($user_id)
    {
        return EP_Graph_Service::get_instance()->get_recent_emails($user_id);
    }

    public function ensure_onedrive_structure($user_id)
    {
        return EP_Graph_Service::get_instance()->ensure_onedrive_structure($user_id);
    }

    public function upload_to_onedrive($user_id, $local_path, $filename, $folder_type = 'documentos', $doc_type = 'public', $owner_id = 0)
    {
        return EP_Graph_Service::get_instance()->upload_to_onedrive($user_id, $local_path, $filename, $folder_type, $doc_type, $owner_id);
    }

    public function create_folder_in_onedrive($user_id, $folder_name, $doc_type = 'public')
    {
        $folder_ids = get_user_meta($user_id, 'ep_onedrive_folder_ids', true);
        if (!$folder_ids || !isset($folder_ids['publico'])) {
            $folder_ids = EP_Graph_Service::get_instance()->ensure_onedrive_structure($user_id);
        }
        $root_key = ($doc_type === 'private' || $doc_type === 'personal') ? 'personal' : 'publico';
        $parent_id = $folder_ids[$root_key] ?? '';
        
        if ($parent_id) {
            return EP_Graph_Service::get_instance()->ensure_folder(self::get_valid_token($user_id), "me/drive/items/$parent_id", $folder_name);
        }
        
        return EP_Graph_Service::get_instance()->ensure_folder(self::get_valid_token($user_id), 'me/drive/root', $folder_name);
    }

    public function upload_to_onedrive_folder($user_id, $folder_id, $local_path, $filename)
    {
        return EP_Graph_Service::get_instance()->upload_to_onedrive_folder($user_id, $folder_id, $local_path, $filename);
    }

    public function get_mailbox_settings($user_id)
    {
        return EP_Graph_Service::get_instance()->get_mailbox_settings($user_id);
    }

    public function update_mailbox_settings($user_id, $settings)
    {
        return EP_Graph_Service::get_instance()->update_mailbox_settings($user_id, $settings);
    }

    public function get_activity_insights($user_id, $days = 30)
    {
        return EP_Graph_Service::get_instance()->get_activity_insights($user_id, $days);
    }

    public function get_user_presence($user_id)
    {
        return EP_Graph_Service::get_instance()->get_user_presence($user_id);
    }


    /**
     * AJAX: Update OOF Settings
     */
    public function handle_oof_update_ajax()
    {
        check_ajax_referer('ep_ajax_nonce', 'security');

        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            wp_send_json_error('No autorizado');
        }

        $status = sanitize_text_field($_POST['status'] ?? 'disabled');
        $internal = wp_kses_post($_POST['internal_reply'] ?? '');
        $external = wp_kses_post($_POST['external_reply'] ?? '');

        $settings = array(
            'automaticRepliesSetting' => array(
                'status' => $status,
                'externalAudience' => 'all', // Or restricted if preferred
                'internalReplyMessage' => $internal,
                'externalReplyMessage' => $external
            )
        );

        $result = $this->update_mailbox_settings($current_user_id, $settings);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Configuración de Outlook actualizada correctamente.');
    }

    public function get_live_onedrive_contents($user_id, $folder_id)
    {
        return EP_Graph_Service::get_instance()->get_live_onedrive_contents($user_id, $folder_id);
    }

    public function get_item_download_url($user_id, $item_id)
    {
        return EP_Graph_Service::get_instance()->get_item_download_url($user_id, $item_id);
    }

    public function get_item_preview_url($user_id, $item_id)
    {
        return EP_Graph_Service::get_instance()->get_item_preview_url($user_id, $item_id);
    }

    public function delete_onedrive_item($user_id, $item_id)
    {
        return EP_Graph_Service::get_instance()->delete_onedrive_item($user_id, $item_id);
    }

    public function move_onedrive_item($user_id, $item_id, $new_parent_id, $new_name = '')
    {
        return EP_Graph_Service::get_instance()->move_onedrive_item($user_id, $item_id, $new_parent_id, $new_name);
    }

    public function invite_collaboration($user_id, $item_id, $recipients, $role = 'write')
    {
        return EP_Graph_Service::get_instance()->invite_collaboration($user_id, $item_id, $recipients, $role);
    }

    public function add_onedrive_shortcut($user_id, $remote_item_id, $remote_drive_id, $name, $parent_id = 'root')
    {
        return EP_Graph_Service::get_instance()->add_onedrive_shortcut($user_id, $remote_item_id, $remote_drive_id, $name, $parent_id);
    }

    public function create_folder_item($user_id, $parent_id, $folder_name)
    {
        return EP_Graph_Service::get_instance()->create_folder_item($user_id, $parent_id, $folder_name);
    }

    public function get_user_personal_folder_id($admin_id, $personal_root_id, $user_email)
    {
        return EP_Graph_Service::get_instance()->get_user_personal_folder_id($admin_id, $personal_root_id, $user_email);
    }

    public function rename_drive_item($user_id, $item_id, $new_name)
    {
        return EP_Graph_Service::get_instance()->rename_drive_item($user_id, $item_id, $new_name);
    }

    public static function get_app_token()
    {
        return EP_Graph_Service::get_instance()->get_app_token();
    }

    public static function get_teams_activity_report($period = 'D30')
    {
        $csv_data = EP_Graph_Service::get_instance()->get_teams_activity_report($period);
        if (is_wp_error($csv_data)) return $csv_data;

        // Remove UTF-8 BOM if present
        if (substr($csv_data, 0, 3) === "\xEF\xBB\xBF") {
            $csv_data = substr($csv_data, 3);
        }

        return self::parse_teams_csv_report($csv_data);
    }

    public static function create_presence_subscription($ms_user_ids, $webhook_url, $expiration)
    {
        return EP_Graph_Service::get_instance()->create_presence_subscription($ms_user_ids, $webhook_url, $expiration);
    }

    public static function renew_presence_subscription($sub_id, $expiration)
    {
        return EP_Graph_Service::get_instance()->renew_presence_subscription($sub_id, $expiration);
    }

    /**
     * Parse CSV report from Microsoft Graph Reports API.
     * 
     * @param string $csv Raw CSV string
     * @return array Parsed report data indexed by user email
     */
    private static function parse_teams_csv_report($csv)
    {
        // Normalize line endings to avoid \r at the end of lines
        $csv = str_replace("\r\n", "\n", $csv);
        $lines = explode("\n", trim($csv));
        if (count($lines) < 2) {
            return array();
        }

        // First line is headers
        $headers = str_getcsv(array_shift($lines));
        // Clean headers from any invisible chars
        $headers = array_map(function ($h) {
            return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $h));
        }, $headers);

        $results = array();

        foreach ($lines as $line) {
            if (empty(trim($line)))
                continue;

            $values = str_getcsv($line);
            $row = array();
            foreach ($headers as $i => $header) {
                $row[trim($header)] = isset($values[$i]) ? trim($values[$i]) : '';
            }

            // Index by User Principal Name (email)
            $email = $row['User Principal Name'] ?? '';
            if (!empty($email)) {
                $email_lower = strtolower($email);

                // --- DEBUG TEMPORAL PARA JORGE ---
                if (strpos($email_lower, 'jorge.polo') !== false) {
                    $hex_dump = bin2hex($email_lower);
                    ep_error_log("EP Stats CSV Debug: Encontrado 'jorge.polo'. Valor exacto: '{$email_lower}' | Hex: {$hex_dump}");
                }
                // --- FIN DEBUG ---

                $results[$email_lower] = array(
                    'display_name' => $row['User Principal Name'] ?? '',
                    'last_activity_date' => $row['Last Activity Date'] ?? '',
                    'team_chat_count' => intval($row['Team Chat Message Count'] ?? 0),
                    'private_chat_count' => intval($row['Private Chat Message Count'] ?? 0),
                    'call_count' => intval($row['Call Count'] ?? 0),
                    'meeting_count' => intval($row['Meeting Count'] ?? 0),
                    'has_other_action' => ($row['Has Other Action'] ?? '') === 'Yes',
                    'report_period' => $row['Report Period'] ?? '',
                );
            }
        }

        return $results;
    }

    /**
     * Encuentra el ID de la categoría y luego mueve el archivo
     */
    public function move_item_to_category($user_id, $item_id, $folder_name, $doc_type = "public")
    {
        $token = self::get_valid_token($user_id);
        if (is_wp_error($token))
            return $token;

        // 1. Obtener la carpeta principal
        $folder_ids = get_user_meta($user_id, "ep_onedrive_folder_ids", true);
        if (!$folder_ids || empty($folder_ids["root"]) || !isset($folder_ids["publico"])) {
            $folder_ids = $this->ensure_onedrive_structure($user_id);
            if (is_wp_error($folder_ids))
                return $folder_ids;
        }

        $root_key = ($doc_type === "private" || $doc_type === "personal") ? "personal" : "publico";
        $parent_folder_id = $folder_ids[$root_key] ?? "";

        if (!$parent_folder_id)
            return new WP_Error("folder_not_found", "No se encontró la carpeta raiz.");

        // 2. Si folder_name no es vacío, documentos o root, hay que buscar su ID real
        if ($folder_name !== "documentos" && $folder_name !== "") {
            $url_cat = "https://graph.microsoft.com/v1.0/me/drive/items/$parent_folder_id/children?\$filter=name eq '" . rawurlencode($folder_name) . "'";
            $cat_resp = wp_remote_get($url_cat, array(
                "headers" => array("Authorization" => "Bearer " . $token),
                "timeout" => 30
            ));
            $cat_body = json_decode(wp_remote_retrieve_body($cat_resp), true);

            if (!empty($cat_body["value"])) {
                $parent_folder_id = $cat_body["value"][0]["id"];
            } else {
                $new_cat = $this->create_folder_in_onedrive($user_id, $folder_name, $doc_type);
                if (is_wp_error($new_cat)) {
                    return $new_cat;
                }
                $parent_folder_id = $new_cat["id"];
            }
        }

        // 3. Mover a la verdadera carpeta encontrada sin renombrarlo
        return $this->move_onedrive_item($user_id, $item_id, $parent_folder_id, "");
    }

    /**
     * Sends a direct message to a user via Microsoft Teams.
     * 
     * @param int $user_id The local WordPress user ID of the recipient.
     * @param string $title The notification title.
     * @param string $message The notification message.
     * @param string $link Optional link to include in the message.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function send_teams_message($user_id, $title, $message, $link = '')
    {
        // Redirigimos la llamada al nuevo sistema de Bot Framework para que los mensajes
        // lleguen a nombre de la app (Bot) y no a nombre del administrador.
        $result = EP_Teams_Bot::send_proactive_message($user_id, $title, $message, $link);

        if (!$result) {
            return new WP_Error('teams_bot_error', 'Error al enviar mensaje vía Teams Bot. Revisa el debug.log y asegúrate de que el BotID/Secret estén configuardos en ajustes.');
        }

        return true;
    }
}
