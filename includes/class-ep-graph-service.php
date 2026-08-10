<?php
defined('ABSPATH') || exit;

/**
 * Service class for Microsoft Graph API interactions.
 */
class EP_Graph_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Gets a valid access token for the user, refreshing it if necessary.
     */
    public function get_valid_token($user_id) {
        $token = get_user_meta($user_id, 'ep_o365_access_token', true);
        if (!$token) {
            return new WP_Error('no_token', 'No hay conexión con Office 365.');
        }

        // Migración JIT: Si el token existe pero NO está cifrado, cifrarlo ahora
        if (class_exists('EP_Security')) {
             if (!EP_Security::is_encrypted($token)) {
                $encrypted_token = EP_Security::encrypt($token);
                update_user_meta($user_id, 'ep_o365_access_token', $encrypted_token);
                
                // También el refresh token si existe
                $refresh_token = get_user_meta($user_id, 'ep_o365_refresh_token', true);
                if ($refresh_token && !EP_Security::is_encrypted($refresh_token)) {
                    update_user_meta($user_id, 'ep_o365_refresh_token', EP_Security::encrypt($refresh_token));
                }
            } else {
                // Si estaba cifrado, lo desciframos para su uso en memoria
                $token = EP_Security::decrypt($token);
            }
        } else {
            ep_error_log("EP_Graph_Service: EP_Security no disponible en este proceso. Se asume token plano o se omite descifrado.");
        }

        $last_refresh = (int) get_user_meta($user_id, 'ep_o365_token_last_refresh', true);
        $now = time();

        if ($now - $last_refresh > 3000) { // 50 minutes
            return $this->refresh_access_token($user_id);
        }

        return $token;
    }

    /**
     * Refresh access token using Microsoft OAuth2.
     */
    public function refresh_access_token($user_id) {
        $refresh_token = get_user_meta($user_id, 'ep_o365_refresh_token', true);
        if (!$refresh_token) {
            return new WP_Error('no_refresh_token', 'No refresh token available.');
        }

        // Descifrar refresh token
        if (class_exists('EP_Security') && EP_Security::is_encrypted($refresh_token)) {
            $refresh_token = EP_Security::decrypt($refresh_token);
        }

        $client_id     = ep_get_option('ep_o365_client_id');
        $client_secret = ep_get_option('ep_o365_client_secret');
        $tenant_id     = ep_get_option('ep_o365_tenant_id');

        // Robustez: Si el secreto está cifrado por EP_Security, descifrarlo
        if (class_exists('EP_Security') && EP_Security::is_encrypted($client_secret)) {
            $client_secret = EP_Security::decrypt($client_secret);
        }

        $url = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/token";

        $body = array(
            'client_id'     => $client_id,
            'scope'         => 'User.Read User.ReadWrite offline_access User.Read.All Mail.Read Mail.Send Mail.Send.Shared Calendars.Read Calendars.ReadWrite Presence.Read.All Tasks.ReadWrite Files.ReadWrite.All MailboxSettings.ReadWrite People.Read',
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
            'client_secret' => $client_secret,
        );

        $response = wp_remote_post($url, array(
            'body'    => $body,
            'timeout' => 45
        ));

        if (is_wp_error($response)) return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error'])) {
            return new WP_Error('token_refresh_error', $data['error_description']);
        }

        $access_token = $data['access_token'];
        $new_refresh_token = $data['refresh_token'] ?? null;

        // Cifrar nuevos tokens antes de guardar
        if (class_exists('EP_Security')) {
            $access_token_to_save = EP_Security::encrypt($access_token);
            if ($new_refresh_token) {
                $new_refresh_token = EP_Security::encrypt($new_refresh_token);
            }
        } else {
            $access_token_to_save = $access_token;
        }

        update_user_meta($user_id, 'ep_o365_access_token', $access_token_to_save);
        update_user_meta($user_id, 'ep_o365_token_last_refresh', time());
        if ($new_refresh_token) {
            update_user_meta($user_id, 'ep_o365_refresh_token', $new_refresh_token);
        }

        return $access_token;
    }

    /**
     * Get user presence status from Microsoft Teams.
     */
    public function get_users_presence($user_id, $ms_user_ids) {
        $cache_key = 'ep_presence_' . md5(implode(',', $ms_user_ids));
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = 'https://graph.microsoft.com/v1.0/communications/getPresencesByUserId';
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode(['ids' => $ms_user_ids]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $result = $body['value'] ?? [];

        if (!empty($result)) {
            set_transient($cache_key, $result, 120); // 2 minutes
        }
        
        return $result;
    }

    /**
     * Get next upcoming calendar event.
     */
    public function get_next_event($user_id) {
        $cache_key = 'ep_event_' . $user_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $start = gmdate('Y-m-d\TH:i:s\Z');
        $end   = gmdate('Y-m-d\TH:i:s\Z', strtotime('+14 days'));

        $url = "https://graph.microsoft.com/v1.0/me/calendarview?startdatetime={$start}&enddatetime={$end}&\$orderby=start/dateTime&\$top=10";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Prefer'        => 'outlook.timezone="Europe/Madrid"'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        $result = null;
        if (!empty($body['value'])) {
            foreach ($body['value'] as $event) {
                if (empty($event['isAllDay'])) {
                    $result = $event;
                    break;
                }
            }
        }

        set_transient($cache_key, $result, 300); // 5 minutes
        return $result;
    }

    /**
     * Get unread emails from Outlook.
     */
    public function get_recent_emails($user_id, $top = 5) {
        $cache_key = 'ep_emails_' . $user_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?\$filter=isRead eq false&\$orderby=receivedDateTime desc&\$top={$top}&\$select=subject,from,receivedDateTime,webLink";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $result = $body['value'] ?? [];

        set_transient($cache_key, $result, 300); // 5 minutes
        return $result;
    }

    /**
     * Get user profile from Microsoft Graph.
     */
    public function get_user_profile_from_graph($access_token) {
        $url = "https://graph.microsoft.com/v1.0/me?\$select=id,displayName,givenName,surname,mail,userPrincipalName,mobilePhone,officeLocation,jobTitle,department,businessPhones";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 45
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('graph_error', $body['error']['message']);
        }

        return $body;
    }

    /**
     * Get user tasks from Microsoft To Do.
     */
    public function get_my_tasks($user_id) {
        $cache_key = 'ep_tasks_' . $user_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/todo/lists/tasks?\$filter=status ne 'completed'&\$top=5";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $result = $body['value'] ?? [];

        set_transient($cache_key, $result, 300); // 5 minutes
        return $result;
    }

    /**
     * Ensure OneDrive folder structure exist.
     */
    public function ensure_onedrive_structure($user_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        // Intentar borrar carpetas antiguas si no se ha hecho antes
        if (!get_user_meta($user_id, 'ep_old_onedrive_deleted', true)) {
            $old_root_names = ['Portal del Empleado', 'AA - Portal del Empleado', 'AA - Portal'];
            foreach ($old_root_names as $old_name) {
                $url_search = "https://graph.microsoft.com/v1.0/me/drive/root/children?\$filter=name eq '" . rawurlencode($old_name) . "'";
                $response = wp_remote_get($url_search, ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 30]);
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (!empty($body['value'])) {
                        $old_id = $body['value'][0]['id'];
                        $this->delete_onedrive_item($user_id, $old_id);
                    }
                }
            }
            update_user_meta($user_id, 'ep_old_onedrive_deleted', 1);
        }

        // Crear nuevas carpetas en la raíz
        $public_id = $this->ensure_folder($token, 'me/drive/root', 'AA Portal Publico');
        if (is_wp_error($public_id)) return $public_id;

        $private_id = $this->ensure_folder($token, 'me/drive/root', 'AA Portal Privado');
        if (is_wp_error($private_id)) return $private_id;

        // Subcarpeta Firmas dentro del privado
        $firmas_id = $this->ensure_folder($token, "me/drive/items/$private_id", 'Firmas');

        $folder_ids = [
            'publico'  => $public_id,
            'personal' => $private_id,
            'firmas'   => $firmas_id,
            'root'     => $private_id // Fallback por compatibilidad con métodos viejos
        ];

        update_user_meta($user_id, 'ep_onedrive_folder_ids', $folder_ids);
        return $folder_ids;
    }

    public function ensure_folder($token, $parent_path, $name) {
        // Intentar acceso directo por ruta (más robusto que búsqueda por filtro)
        // Nota: Solo funciona si parent_path es 'me/drive/root' o similar que soporte acceso por ruta.
        $url_direct = "https://graph.microsoft.com/v1.0/$parent_path:/" . rawurlencode($name);
        
        // Si el parent_path contiene 'items/', usamos la URL de items
        if (strpos($parent_path, 'items/') !== false) {
            $url_direct = "https://graph.microsoft.com/v1.0/$parent_path/children/" . rawurlencode($name);
        }

        $response = wp_remote_get($url_direct, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20
        ]);

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['id'])) return $body['id'];
            }
        }

        // Si no existe, crearla
        // Si parent_path tiene :/ al final, hay que limpiarlo para el POST de children
        // Cuidado de no reemplazar el '://' de 'https://'
        $clean_parent = rtrim($parent_path, ':/');
        $url_create = "https://graph.microsoft.com/v1.0/$clean_parent/children";

        $response = wp_remote_post($url_create, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token, 
                'Content-Type' => 'application/json'
            ],
            'body'    => json_encode([
                'name'   => $name,
                'folder' => (object)[],
                '@microsoft.graph.conflictBehavior' => 'rename' // Evitar error 409 si existe pero el GET falló
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            ep_error_log("EP_Graph ensure_folder ERROR (HTTP): " . $response->get_error_message(), true);
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['id'])) {
            $msg = $body['error']['message'] ?? wp_json_encode($body);
            ep_error_log("EP_Graph ensure_folder FAIL creating $name: " . $msg, true);
            return new WP_Error('graph_error', "Error al gestionar la carpeta '$name' en OneDrive: " . $msg);
        }

        return $body['id'];
    }

    /**
     * Get mailbox settings (for OOF).
     */
    public function get_mailbox_settings($user_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = 'https://graph.microsoft.com/v1.0/me/mailboxSettings';
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['error'] ?? $body;
    }

    /**
     * Update mailbox settings.
     */
    public function update_mailbox_settings($user_id, $settings) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = 'https://graph.microsoft.com/v1.0/me/mailboxSettings';
        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($settings),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return true;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return new WP_Error('graph_error', $body['error']['message'] ?? 'Error desconocido');
    }

    /**
     * Update Graph profile info.
     */
    public function update_graph_profile($access_token, $data) {
        $url = 'https://graph.microsoft.com/v1.0/me';
        
        ep_error_log("Graph API: PATCH request to $url");
        ep_error_log("Graph API: Payload: " . json_encode($data));

        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($data),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            ep_error_log("Graph API: Request failed. Error: " . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        ep_error_log("Graph API: Response Code: $code");
        if ($code < 200 || $code >= 300) {
            ep_error_log("Graph API: Error Response: " . $body);
        }

        return ($code >= 200 && $code < 300);
    }

    /**
     * Fetch user photo from Graph.
     */
    public function fetch_user_photo($user_id, $token) {
        $url = "https://graph.microsoft.com/v1.0/me/photo/\$value";
        return wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30
        ]);
    }
    /**
     * Upload a file to OneDrive.
     *
     * Arquitectura:
     *  - Público  → OneDrive del ADMIN  /  AA Portal Publico / [archivo]
     *  - Privado  → OneDrive del PROPIO USUARIO / AA Portal Privado / [archivo]
     *  - Firmas   → OneDrive del ADMIN  /  AA Portal Privado / Firmas / [archivo]
     *
     * @param int    $user_id     ID del usuario cuyo token se usa para públicos/firmas (admin).
     * @param string $local_path  Ruta local del archivo temporal.
     * @param string $filename    Nombre final del archivo en OneDrive.
     * @param string $folder_type Subcarpeta opcional (p.ej. categoría).
     * @param string $doc_type    'public', 'private' o 'firmas'.
     * @param int    $owner_id    ID del propietario real (para privados usa SU OneDrive).
     */
    public function upload_to_onedrive($user_id, $local_path, $filename, $folder_type = 'documentos', $doc_type = 'public', $owner_id = 0) {

        // ── PRIVADOS: usar el token y el OneDrive del propietario real ──
        if ($doc_type === 'private') {
            $real_owner_id = ($owner_id > 0) ? $owner_id : $user_id;
            return $this->upload_private_to_owner_drive($real_owner_id, $local_path, $filename);
        }

        // ── PÚBLICOS / FIRMAS: usar el OneDrive del admin ──
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $folder_ids = get_user_meta($user_id, 'ep_onedrive_folder_ids', true) ?: [];
        $key = ($doc_type === 'firmas') ? 'firmas' : 'publico';
        $cached_id = $folder_ids[$key] ?? '';
        $parent_id = '';

        if ($cached_id) {
            // Validar que el ID cacheado sigue existiendo en OneDrive
            $check_url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($cached_id);
            $check_resp = wp_remote_get($check_url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 15
            ]);

            if (!is_wp_error($check_resp) && wp_remote_retrieve_response_code($check_resp) === 200) {
                $parent_id = $cached_id;
            } else {
                ep_error_log("EP_Graph upload: ID cacheado '$cached_id' no encontrado para tipo $key. Recreando estructura.");
                $folder_ids = $this->ensure_onedrive_structure($user_id);
                if (is_wp_error($folder_ids)) return $folder_ids;
                $parent_id = $folder_ids[$key] ?? '';
            }
        } else {
            $folder_ids = $this->ensure_onedrive_structure($user_id);
            if (is_wp_error($folder_ids)) return $folder_ids;
            $parent_id = $folder_ids[$key] ?? '';
        }

        if (!$parent_id) return new WP_Error('folder_not_found', 'No se pudo localizar ni recrear la carpeta destino en OneDrive.');

        $content = file_get_contents($local_path);
        $url = "https://graph.microsoft.com/v1.0/me/drive/items/$parent_id:/" . urlencode($filename) . ":/content";

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/octet-stream'
            ],
            'body'    => $content,
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return $response;
        
        $result = json_decode(wp_remote_retrieve_body($response), true);

        // Si el PUT falla con itemNotFound, invalidamos la cache para la próxima vez
        if (isset($result['error']['code']) && $result['error']['code'] === 'itemNotFound') {
            $folder_ids[$key] = '';
            update_user_meta($user_id, 'ep_onedrive_folder_ids', $folder_ids);
        }

        return $result;
    }

    /**
     * Sube un archivo PRIVADO al OneDrive propio del usuario.
     * Usa el token del propio usuario → el admin nunca tiene acceso.
     * Crea/asegura la carpeta 'AA Portal Privado' en la raíz de su unidad.
     *
     * @param int    $owner_id   ID del usuario propietario.
     * @param string $local_path Ruta local del archivo.
     * @param string $filename   Nombre del archivo en OneDrive.
     */
    public function upload_private_to_owner_drive($owner_id, $local_path, $filename) {
        // Obtener token del propietario real
        $token = $this->get_valid_token($owner_id);
        if (is_wp_error($token)) {
            ep_error_log("EP_Graph upload_private: no hay token para user=$owner_id — " . $token->get_error_message());
            return $token;
        }

        // Recuperar folder_id cacheado, pero SIEMPRE validar que sigue existiendo en OneDrive.
        // Si está obsoleto (carpeta borrada en staging/migración) → recrear.
        $user_folder_ids = get_user_meta($owner_id, 'ep_onedrive_folder_ids', true) ?: [];
        $cached_id = $user_folder_ids['personal'] ?? '';
        $private_folder_id = '';

        if ($cached_id) {
            // Verificar que el item todavía existe en OneDrive
            $check_url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($cached_id);
            $check_resp = wp_remote_get($check_url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 15
            ]);
            if (!is_wp_error($check_resp) && wp_remote_retrieve_response_code($check_resp) === 200) {
                $private_folder_id = $cached_id;
            } else {
                // ID obsoleto — limpiar cache y recrear
                ep_error_log("EP_Graph upload_private: folder_id cacheado '$cached_id' no existe para user=$owner_id. Recreando carpeta.");
                unset($user_folder_ids['personal']);
                update_user_meta($owner_id, 'ep_onedrive_folder_ids', $user_folder_ids);
            }
        }

        if (!$private_folder_id) {
            // Crear/asegurar la carpeta 'AA Portal Privado' en la raíz del usuario
            $private_folder_id = $this->ensure_folder($token, 'me/drive/root', 'AA Portal Privado');
            if (is_wp_error($private_folder_id)) return $private_folder_id;

            // Actualizar el cache con el ID válido
            $user_folder_ids['personal'] = $private_folder_id;
            update_user_meta($owner_id, 'ep_onedrive_folder_ids', $user_folder_ids);
        }

        // Subir el archivo al OneDrive del usuario
        $content = file_get_contents($local_path);
        $url = "https://graph.microsoft.com/v1.0/me/drive/items/$private_folder_id:/" . urlencode($filename) . ":/content";

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/octet-stream'
            ],
            'body'    => $content,
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return $response;

        $result = json_decode(wp_remote_retrieve_body($response), true);

        // Si Microsoft devuelve itemNotFound (el folder fue borrado entre validación y subida),
        // limpiamos la cache y devolvemos un error descriptivo para que el usuario reintente.
        if (isset($result['error']['code']) && $result['error']['code'] === 'itemNotFound') {
            ep_error_log("EP_Graph upload_private: itemNotFound al subir a folder '$private_folder_id' para user=$owner_id. Limpiando cache.");
            $user_folder_ids['personal'] = '';
            update_user_meta($owner_id, 'ep_onedrive_folder_ids', $user_folder_ids);
        }

        return $result;
    }


    /**
     * Get live OneDrive items.
     */
    public function get_live_onedrive_contents($user_id, $folder_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $items = [];
        $url = "https://graph.microsoft.com/v1.0/me/drive/items/$folder_id/children?\$select=id,name,folder,file,webUrl,size,lastModifiedDateTime,fileSystemInfo,@microsoft.graph.downloadUrl&\$top=100";
        
        while ($url) {
            $response = wp_remote_get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body_raw = wp_remote_retrieve_body($response);
            $body = json_decode($body_raw, true);

            if ($code < 200 || $code >= 300) {
                $msg = $body['error']['message'] ?? 'Error desconocido de Graph API (' . $code . ')';
                return new WP_Error('graph_http_error', "Error al listar contenidos de OneDrive ($code): $msg");
            }
            
            if (!empty($body['value'])) {
                foreach ($body['value'] as $item) {
                    $items[] = [
                        'id'           => $item['id'],
                        'name'         => $item['name'],
                        'type'         => isset($item['folder']) ? 'folder' : 'file',
                        'size'         => $item['size'] ?? 0,
                        'lastModified' => $item['lastModifiedDateTime'] ?? '',
                        'webUrl'       => $item['webUrl'] ?? '',
                        'downloadUrl'  => $item['@microsoft.graph.downloadUrl'] ?? ''
                    ];
                }
            }

            $url = $body['@odata.nextLink'] ?? null;
            // Cap at 1000 items to prevent infinite loops or memory issues in a single request
            if (count($items) >= 1000) break;
        }

        return $items;
    }

    /**
     * Get user activity insights (Calendar metrics + Presence).
     */
    public function get_activity_insights($user_id, $days = 30) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        // 1. Calculate time range
        $now = time();
        $start_ts = $now - ($days * 86400);
        
        // If "today" (1 day), we want from the start of the current day
        if ($days <= 1) {
            $start_ts = strtotime('today midnight');
        }

        $start_date = gmdate('Y-m-d\T00:00:00\Z', $start_ts);
        $end_date   = gmdate('Y-m-d\T23:59:59\Z', $now);

        // 2. Fetch Calendar View
        $url = "https://graph.microsoft.com/v1.0/me/calendarView?startDateTime={$start_date}&endDateTime={$end_date}&\$select=subject,start,end,showAs&\$top=999";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Prefer'        => 'outlook.timezone="Europe/Madrid"'
            ],
            'timeout' => 30
        ]);

        $metrics = [
            'total_meetings' => 0,
            'total_hours'    => 0,
            'most_busy_day'  => null,
            'teams_status'   => 'unknown',
            'teams_activity' => 'unknown'
        ];

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $events = $body['value'] ?? [];
            
            $day_hours = [];
            foreach ($events as $event) {
                // We only count "Busy" or "Tentative" time that is NOT all-day
                if (($event['showAs'] === 'busy' || $event['showAs'] === 'tentative')) {
                    $start = new DateTime($event['start']['dateTime']);
                    $end   = new DateTime($event['end']['dateTime']);
                    
                    $duration_s = $end->getTimestamp() - $start->getTimestamp();
                    if ($duration_s > 0 && $duration_s < 86400) { // Skip all-day blocks
                        $metrics['total_meetings']++;
                        $hours = $duration_s / 3600;
                        $metrics['total_hours'] += $hours;
                        
                        $day_key = $start->format('Y-m-d');
                        $day_hours[$day_key] = ($day_hours[$day_key] ?? 0) + $hours;
                    }
                }
            }

            if (!empty($day_hours)) {
                arsort($day_hours);
                $metrics['most_busy_day'] = array_key_first($day_hours);
            }
        }

        // 3. Fetch Real-time Presence
        $presence = $this->get_user_presence($user_id);
        if (!is_wp_error($presence)) {
            $metrics['teams_status'] = $presence['availability'];
            $metrics['teams_activity'] = $presence['activity'];
        }

        return $metrics;
    }

    /**
     * Get a temporary download URL for a file.
     */
    public function get_item_download_url($user_id, $item_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($item_id);
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['@microsoft.graph.downloadUrl'] ?? new WP_Error('no_download_url', 'No download URL');
    }

    /**
     * Get a preview/embed URL for a file.
     */
    public function get_item_preview_url($user_id, $item_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($item_id) . "/preview";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode(new stdClass()),
            'timeout' => 20
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['getUrl'] ?? new WP_Error('no_preview', 'No preview URL');
    }

    /**
     * Delete an item in OneDrive.
     */
    public function delete_onedrive_item($user_id, $item_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/$item_id";
        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300);
    }

    /**
     * Move or rename an item in OneDrive.
     */
    public function move_onedrive_item($user_id, $item_id, $new_parent_id, $new_name = '') {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/$item_id";
        $body = [];
        if ($new_parent_id) $body['parentReference'] = ['id' => $new_parent_id];
        if ($new_name) $body['name'] = $new_name;

        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Upload a file to a specific folder ID directly.
     */
    public function upload_to_onedrive_folder($user_id, $folder_id, $local_path, $filename) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $content = file_get_contents($local_path);
        $url = "https://graph.microsoft.com/v1.0/me/drive/items/$folder_id:/" . urlencode($filename) . ":/content";

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/octet-stream'
            ],
            'body'    => $content,
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return $response;
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    /**
     * Invite collaboration for a folder/file.
     */
    public function invite_collaboration($user_id, $item_id, $recipients, $role = 'write') {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $payload = [
            'recipients'    => array_map(function($email) { return ['email' => $email]; }, (array)$recipients),
            'message'       => 'Acceso compartido a la carpeta del Portal del Empleado.',
            'requireSignIn' => true,
            'sendInvitation' => false,
            'roles'         => [$role]
        ];

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($item_id) . "/invite";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log("EP_Graph: Error invitando a " . implode(',', (array)$recipients) . ": " . $response->get_error_message());
            return $response;
        }
        $body = @json_decode(wp_remote_retrieve_body($response), true);
        error_log("EP_Graph: Respuesta invitación: " . wp_json_encode($body));
        return $body;
    }

    /**
     * Add a shortcut to a shared folder in user's root drive.
     */
    public function add_onedrive_shortcut($user_id, $remote_item_id, $remote_drive_id, $name, $parent_id = 'root') {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $payload = [
            'name'       => $name,
            'remoteItem' => [
                'id'      => $remote_item_id,
                'parentReference' => [
                    'driveId' => $remote_drive_id
                ]
            ]
        ];

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($parent_id) . "/children";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log("EP_Graph: Error creando acceso directo para usuario $user_id: " . $response->get_error_message());
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            error_log("EP_Graph: Acceso directo creado con éxito para usuario $user_id");
            return $body['id'] ?? true;
        }

        if (isset($body['error']['code']) && $body['error']['code'] === 'nameAlreadyExists') {
            error_log("EP_Graph: El nombre del acceso directo ya existe para usuario $user_id. Se asume correcto o conflicto previo.");
            return true;
        }

        error_log("EP_Graph: Error Graph creando acceso directo: " . wp_json_encode($body));
        return new WP_Error('graph_error', $body['error']['message'] ?? 'Error creando acceso directo');
    }

    /**
     * Rename a drive item (folder, file or shortcut).
     */
    public function rename_drive_item($user_id, $item_id, $new_name) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($item_id);
        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode(['name' => $new_name]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Create a real folder in OneDrive.
     */
    public function create_folder_item($user_id, $parent_id, $folder_name) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($parent_id) . "/children";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode([
                'name'   => $folder_name,
                'folder' => new stdClass(),
                '@microsoft.graph.conflictBehavior' => 'fail'
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return $body;
        
        // Si ya existe, intentamos buscarlo por nombre
        if (isset($body['error']['code']) && $body['error']['code'] === 'nameAlreadyExists') {
            return $this->get_child_by_name($user_id, $parent_id, $folder_name);
        }

        return new WP_Error('graph_error', $body['error']['message'] ?? 'Error creando carpeta');
    }

    /**
     * Get a child item by name in a specific parent.
     */
    public function get_child_by_name($user_id, $parent_id, $name) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/" . urlencode($parent_id) . "/children?\$filter=name eq '" . rawurlencode($name) . "'";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body['value'])) {
            return $body['value'][0];
        }

        return new WP_Error('not_found', 'Elemento no encontrado');
    }

    /**
     * Specialized: Find a user's personal folder in the Admin's Personal directory.
     */
    public function get_user_personal_folder_id($admin_id, $personal_root_id, $user_email) {
        $token = $this->get_valid_token($admin_id);
        if (is_wp_error($token)) return $token;

        // Buscamos por nombre (email del usuario)
        $folder = $this->get_child_by_name($admin_id, $personal_root_id, $user_email);
        if (!is_wp_error($folder)) return $folder['id'];

        // Si no existe, lo creamos
        $new_folder = $this->create_folder_item($admin_id, $personal_root_id, $user_email);
        if (is_wp_error($new_folder)) return $new_folder;

        return $new_folder['id'];
    }

    /**
     * Get user's default drive information.
     */
    public function get_my_drive($user_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/me/drive";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20
        ]);

        if (is_wp_error($response)) return $response;
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Get an Application-level token (client_credentials).
     */
    public function get_app_token() {
        $cached = get_transient('ep_o365_app_token');
        if ($cached) return $cached;

        $tenant_id     = ep_get_option('ep_o365_tenant_id');
        $client_id     = ep_get_option('ep_o365_client_id');
        $client_secret = ep_get_option('ep_o365_client_secret');

        // Robustez: Si el secreto está cifrado por EP_Security, descifrarlo
        if (class_exists('EP_Security') && EP_Security::is_encrypted($client_secret)) {
            $client_secret = EP_Security::decrypt($client_secret);
        }

        if (empty($tenant_id) || empty($client_id) || empty($client_secret)) {
            return new WP_Error('missing_config', 'Faltan credenciales O365.');
        }

        $url = "https://login.microsoftonline.com/$tenant_id/oauth2/v2.0/token";
        $response = wp_remote_post($url, [
            'body'    => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'scope'         => 'https://graph.microsoft.com/.default',
                'grant_type'    => 'client_credentials',
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) return new WP_Error('token_error', $body['error_description'] ?? $body['error']);
        if (!isset($body['access_token'])) return new WP_Error('no_token', 'No access_token');

        set_transient('ep_o365_app_token', $body['access_token'], 3000);
        return $body['access_token'];
    }

    /**
     * Get Teams User Activity report.
     */
    public function get_teams_activity_report($period = 'D30') {
        $token = $this->get_app_token();
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/reports/getTeamsUserActivityUserDetail(period='{$period}')";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new WP_Error('report_error', "Error $code al obtener reporte");

        return wp_remote_retrieve_body($response); // CSV content
    }

    /**
     * Create presence subscription.
     */
    public function create_presence_subscription($ms_user_ids, $webhook_url, $expiration) {
        $token = $this->get_app_token();
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/subscriptions";
        $resource = "/communications/presences?\$filter=id in ('" . implode("','", $ms_user_ids) . "')";

        $body = [
            'changeType'         => 'updated',
            'notificationUrl'    => $webhook_url,
            'resource'           => $resource,
            'expirationDateTime' => $expiration,
            'clientState'        => wp_hash('ep_teams_webhook')
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $res_body = json_decode(wp_remote_retrieve_body($response), true);
        return $res_body['id'] ?? new WP_Error('graph_error', $res_body['error']['message'] ?? 'No sub ID');
    }

    /**
     * Renew presence subscription.
     */
    public function renew_presence_subscription($sub_id, $expiration) {
        $token = $this->get_app_token();
        if (is_wp_error($token)) return $token;

        $url = "https://graph.microsoft.com/v1.0/subscriptions/$sub_id";
        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode(['expirationDateTime' => $expiration]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300);
    }

    /**
     * Get individual user presence.
     */
    public function get_user_presence($user_id) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me/presence', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return [
            'availability' => $body['availability'] ?? 'Offline',
            'activity'     => $body['activity'] ?? ''
        ];
    }

    /**
     * Send email via Microsoft Graph.
     */
    public function send_mail($user_id, $to, $subject, $body, $is_html = true, $from_email = '', $attachments = []) {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) return $token;

        // Prepare recipients
        $to_recipients = [];
        $emails = is_array($to) ? $to : explode(',', $to);
        foreach ($emails as $email) {
            $email = trim($email);
            if (!empty($email)) {
                $to_recipients[] = [
                    'emailAddress' => [
                        'address' => $email
                    ]
                ];
            }
        }

        $payload = [
            'message' => [
                'subject' => $subject,
                'body'    => [
                    'contentType' => $is_html ? 'HTML' : 'Text',
                    'content'     => $body
                ],
                'toRecipients' => $to_recipients,
                'importance'   => 'high'
            ],
            'saveToSentItems' => 'true'
        ];

        // Handle Attachments
        if (!empty($attachments) && is_array($attachments)) {
            $graph_attachments = [];
            ep_error_log("EP_Graph_Service: [DEBUG] Procesando " . count($attachments) . " adjuntos...");
            foreach ($attachments as $file_path) {
                if (file_exists($file_path)) {
                    $content = file_get_contents($file_path);
                    $filename = basename($file_path);
                    $filetype = wp_check_filetype($filename);
                    
                    $graph_attachments[] = [
                        '@odata.type'  => '#microsoft.graph.fileAttachment',
                        'name'         => $filename,
                        'contentType'  => $filetype['type'] ?? 'application/octet-stream',
                        'contentBytes' => base64_encode($content)
                    ];
                    ep_error_log("EP_Graph_Service: [DEBUG] Adjunto añadido: $filename (" . strlen($content) . " bytes)");
                } else {
                    ep_error_log("EP_Graph_Service: [DEBUG] ERROR: El archivo no existe en la ruta: $file_path");
                }
            }
            if (!empty($graph_attachments)) {
                $payload['message']['attachments'] = $graph_attachments;
            }
        } else {
             ep_error_log("EP_Graph_Service: [DEBUG] No hay adjuntos detectados en la llamada.");
        }

        // Si hay un alias configurado, lo pasamos en el cuerpo pero seguimos usando /me/sendMail
        // pues el anterior 404 indica que no es un buzón independiente.
        if (!empty($from_email)) {
            $payload['message']['from'] = [
                'emailAddress' => [
                    'address' => $from_email,
                    'name'    => 'Notificaciones Portal del Empleado'
                ]
            ];
        }

        $url = 'https://graph.microsoft.com/v1.0/me/sendMail';
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) return $response;
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return true;

        $body_res = json_decode(wp_remote_retrieve_body($response), true);
        $error_msg = $body_res['error']['message'] ?? 'Error desconocido enviando mail';
        
        // Log detailed error for debugging
        ep_error_log("EP Graph Mail FAIL: HTTP $code | Error: $error_msg | Response: " . wp_remote_retrieve_body($response));
        
        return new WP_Error('graph_error', $error_msg);
    }

    /**
     * Obtiene los contactos relevantes del usuario usando /me/people (People.Read).
     * Devuelve un array de ['displayName' => '...', 'email' => '...'].
     * Usa caché de 30 minutos para no sobrecargar la API.
     */
    public function get_user_contacts_ms_ids(int $user_id, bool $fetch_all_org = false): array
    {
        $cache_key = 'ep_people_contacts_' . $user_id . ($fetch_all_org ? '_all' : '');
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) {
            ep_error_log('EP Graph get_user_contacts: Error de token - ' . $token->get_error_message());
            return [];
        }

        if ($fetch_all_org) {
            // Usuarios de toda la organización (top 150) omitiendo deshabilitados o cuentas de sistema
            $url = "https://graph.microsoft.com/v1.0/users?\$top=150&\$select=displayName,mail,userPrincipalName,userType&\$filter=accountEnabled eq true";
        } else {
            // Personas relevantes (compañeros frecuentes)
            $url = "https://graph.microsoft.com/v1.0/me/people?\$top=25&\$select=displayName,scoredEmailAddresses,personType";
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'ConsistencyLevel' => 'eventual'
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            ep_error_log('EP Graph get_user_contacts: Error HTTP - ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            ep_error_log('EP Graph get_user_contacts: HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $people = $body['value'] ?? [];

        $contacts = [];
        foreach ($people as $person) {
            if ($fetch_all_org) {
                // Filtros básicos para omitir invitados o buzones sin nombre real
                $type = $person['userType'] ?? 'Member';
                if ($type === 'Guest' || empty($person['displayName'])) continue;
                
                $email = !empty($person['mail']) ? $person['mail'] : $person['userPrincipalName'];
                if (empty($email)) continue;
                
                $contacts[] = [
                    'displayName' => $person['displayName'],
                    'email'       => strtolower($email)
                ];
            } else {
                // Sólo personas de la organización (class: 'Person', subclass: 'OrganizationUser')
                $type_class = $person['personType']['class'] ?? '';
                $type_subclass = $person['personType']['subclass'] ?? '';
                if ($type_class !== 'Person' || $type_subclass !== 'OrganizationUser') {
                    continue;
                }

                $emails = $person['scoredEmailAddresses'] ?? [];
                if (empty($emails)) continue;

                $contacts[] = [
                    'displayName' => $person['displayName'] ?? 'Compañero',
                    'email'       => strtolower($emails[0]['address'] ?? '')
                ];
            }
        }

        ep_error_log('EP Graph get_user_contacts: Encontrados ' . count($contacts) . ' contactos para user ' . $user_id);
        set_transient($cache_key, $contacts, 30 * MINUTE_IN_SECONDS);
        return $contacts;
    }

    /**
     * Busca huecos comunes de $duration_hours horas en los calendarios del usuario
     * y sus contactos durante el rango de fechas indicado.
     *
     * Usa la API getSchedule de Microsoft Graph.
     *
     * @param int    $user_id        ID de WordPress del usuario solicitante.
     * @param array  $contacts       Array de ['displayName' => '...', 'email' => '...']
     * @param string $start_date     Fecha inicio en formato YYYY-MM-DD.
     * @param string $end_date       Fecha fin en formato YYYY-MM-DD.
     * @param int    $duration_hours Duración del hueco en horas.
     * @param bool   $morning_only   Si true, sólo busca en horario de mañana (08:00-14:00).
     * @return array Lista de huecos: [['start' => DateTime, 'end' => DateTime, 'label' => '...']]
     */
    public function get_free_slots_for_contacts(
        int    $user_id,
        array  $contacts,
        string $start_date,
        string $end_date,
        int    $duration_hours = 1,
        bool   $morning_only   = false
    ): array {
        $token = $this->get_valid_token($user_id);
        if (is_wp_error($token)) {
            ep_error_log('EP Graph get_free_slots: Error de token.');
            return [];
        }

        // Añadimos al propio usuario para que su propio calendario entre en el cálculo
        $wp_user = get_userdata($user_id);
        $schedules_emails = [$wp_user->user_email];
        foreach ($contacts as $c) {
            if (!empty($c['email'])) $schedules_emails[] = $c['email'];
        }
        $schedules_emails = array_unique($schedules_emails);

        // La API getSchedule acepta máx 20 emails por llamada, por lo que troceamos
        $email_chunks = array_chunk($schedules_emails, 20);

        // Definir horario laboral de búsqueda
        $work_start = $morning_only ? '08:00:00' : '08:00:00';
        $work_end   = $morning_only ? '14:00:00' : '18:00:00';
        $tz         = 'Romance Standard Time'; // Europe/Madrid en formato MS

        // Iterar cada día laborable del rango para construir los intervalos a comprobar
        $start_dt = new DateTime($start_date, new DateTimeZone('Europe/Madrid'));
        $end_dt   = new DateTime($end_date,   new DateTimeZone('Europe/Madrid'));
        $end_dt->setTime(23, 59, 59);

        // getSchedule trabaja en rangos; haremos una sola llamada por semana como máximo
        // para no exceder los límites. Particionamos el rango en semanas.
        $free_slots = [];
        $max_slots  = 5;
        $cursor     = clone $start_dt;

        while ($cursor <= $end_dt && count($free_slots) < $max_slots) {

            $week_end = clone $cursor;
            $week_end->modify('+6 days');
            if ($week_end > $end_dt) $week_end = clone $end_dt;

            // Formato requerido por la API: ISO 8601 con timezone
            $api_start = $cursor->format('Y-m-d') . 'T' . $work_start;
            $api_end   = $week_end->format('Y-m-d') . 'T' . $work_end;

            $schedules = [];
            $has_error = false;

            foreach ($email_chunks as $chunk) {
                $payload = [
                    'schedules'             => array_values($chunk),
                    'startTime'             => ['dateTime' => $api_start, 'timeZone' => $tz],
                    'endTime'               => ['dateTime' => $api_end,   'timeZone' => $tz],
                    'availabilityViewInterval' => ($duration_hours * 60), // minutos
                ];

                $response = wp_remote_post('https://graph.microsoft.com/v1.0/me/calendar/getSchedule', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                        'Prefer'        => 'outlook.timezone="Romance Standard Time"',
                    ],
                    'body'    => json_encode($payload),
                    'timeout' => 30,
                ]);

                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (!empty($body['value'])) {
                        $schedules = array_merge($schedules, $body['value']);
                    }
                } else {
                    $has_error = true;
                    $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
                    ep_error_log('EP Graph getSchedule ERROR: ' . $err);
                }
            }

            if (!$has_error && !empty($schedules)) {
                // Analizar los bloques de disponibilidad
                $slots = $this->parse_free_slots(
                    $schedules,
                    $cursor,
                    $week_end,
                    $duration_hours,
                    $work_start,
                    $work_end
                );

                foreach ($slots as $slot) {
                    // Excluir fines de semana
                    $dow = (int) $slot['start']->format('N'); // 6=Sáb, 7=Dom
                    if ($dow >= 6) continue;

                    $free_slots[] = $slot;
                    if (count($free_slots) >= $max_slots) break;
                }
            }

            // Avanzar al siguiente bloque semanal
            $cursor->modify('+7 days');
        }

        return $free_slots;
    }

    /**
     * Analiza las respuestas de getSchedule y devuelve los huecos donde TODOS están libres.
     * availabilityView es un string donde '0'=libre, '1'=ocupado(tentative), '2'=ocupado, '3'=OOF, '4'=trabajandoRemoto.
     */
    private function parse_free_slots(
        array    $schedules,
        DateTime $range_start,
        DateTime $range_end,
        int      $duration_hours,
        string   $work_start,
        string   $work_end
    ): array {
        if (empty($schedules)) return [];

        // Obtenemos los availability views de todos los usuarios
        // Todos deben tener la misma longitud (1 carácter por intervalo)
        $views = [];
        foreach ($schedules as $sched) {
            $view = $sched['availabilityView'] ?? '';
            if (!empty($view)) $views[] = $view;
        }

        if (empty($views)) return [];

        $interval_len = strlen($views[0]);
        $slots = [];

        // Calcular la duración del intervalo en minutos
        // La API genera 1 carácter por cada $availabilityViewInterval minutos
        $interval_minutes = $duration_hours * 60;

        // Punto de partida temporal: el inicio del rango laboral del primer día
        $slot_dt = clone $range_start;
        list($wh, $wm) = explode(':', $work_start);
        $slot_dt->setTime((int)$wh, (int)$wm, 0);

        for ($i = 0; $i < $interval_len; $i++) {
            $slot_start = clone $slot_dt;
            $slot_end   = clone $slot_dt;
            $slot_end->modify("+{$interval_minutes} minutes");

            // Mantenemos sincronizado el puntero temporal exactamente con la cadena de Graph
            $slot_dt->modify("+{$interval_minutes} minutes");

            // Excluir horas fuera del horario laboral
            $time_val = (int)$slot_start->format('H') * 60 + (int)$slot_start->format('i');
            $start_val = (int)$wh * 60 + (int)$wm;
            list($weh, $wem) = explode(':', $work_end);
            $end_val = (int)$weh * 60 + (int)$wem;

            if ($time_val < $start_val || $time_val >= $end_val) continue;

            // Comprobar si todos están libres en este intervalo ('0' o '4'=remote work)
            $all_free = true;
            foreach ($views as $view) {
                $status = $view[$i] ?? '2';
                // 0 = libre, 4 = trabajando en remoto (consideramos libre para reuniones)
                if ($status !== '0' && $status !== '4') {
                    $all_free = false;
                    break;
                }
            }

            if ($all_free) {
                $slots[] = [
                    'start'  => clone $slot_start,
                    'end'    => clone $slot_end,
                    'label'  => $slot_start->format('d/m/Y') . ' de ' .
                                $slot_start->format('H:i') . ' a ' .
                                $slot_end->format('H:i'),
                ];
            }
        }

        return $slots;
    }
}

