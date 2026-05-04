<?php
defined('ABSPATH') || exit;

/**
 * EP_Teams_Bot
 *
 * Envía notificaciones proactivas a usuarios de Teams usando la
 * Microsoft Graph API (aplicación → usuario).
 */
class EP_Teams_Bot
{
    const CHAT_CACHE_TTL = 21600;

    private static function get_manifest_app_id()
    {
        $client_id = ep_get_option('ep_o365_client_id');
        if (!empty($client_id)) return $client_id;
        $id = ep_get_option('ep_teams_bot_id');
        if (!empty($id)) return $id;
        return 'e77c3d1b-f248-4e83-b2ec-454fb9b45f6c';
    }

    private static function get_catalog_app_id($token = null)
    {
        $id = ep_get_option('ep_teams_catalog_app_id');
        if (!empty($id)) return $id;
        if (!$token) return null;
        $external_id = self::get_manifest_app_id();
        $url = "https://graph.microsoft.com/v1.0/appCatalogs/teamsApps?\$filter=externalId eq '{$external_id}'";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20
        ]);
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['value'][0]['id'])) {
                $catalog_id = $data['value'][0]['id'];
                update_option('ep_teams_catalog_app_id', $catalog_id);
                return $catalog_id;
            }
        }
        return $external_id;
    }

    public static function send_proactive_message($user_id, $title, $message, $action_link = '')
    {
        $tenant_id     = ep_get_option('ep_o365_tenant_id');
        $client_id     = ep_get_option('ep_o365_client_id');
        $client_secret = ep_get_option('ep_o365_client_secret');
        $employee_oid  = get_user_meta($user_id, 'ep_o365_user_id', true);

        if (empty($tenant_id) || empty($client_id) || empty($client_secret) || empty($employee_oid)) {
            ep_error_log("EP Teams Bot: Faltan credenciales o OID de usuario ($user_id). Notificación cancelada.");
            return false;
        }

        // Descifrar el client_secret si está cifrado por EP_Security
        if (class_exists('EP_Security') && EP_Security::is_encrypted($client_secret)) {
            $client_secret = EP_Security::decrypt($client_secret);
            ep_error_log('EP Teams Bot: client_secret descifrado correctamente para Graph token.');
        }

        $token = self::get_app_only_token($tenant_id, $client_id, $client_secret);
        if (!$token) {
            ep_error_log("EP Teams Bot: No se pudo obtener token de Graph. Notificación a user $user_id cancelada.");
            return false;
        }

        if (!empty($action_link) && strpos($action_link, 'http') !== 0) {
            $action_link = home_url('/' . ltrim($action_link, '/'));
        }

        // Pasar user_id directamente para evitar consulta BD adicional
        $sent_chat = self::send_via_oneonone_chat($token, $employee_oid, $title, $message, $action_link, $user_id);
        $sent_feed = self::send_activity_notification($token, $employee_oid, $title, $message, $action_link);

        ep_error_log("EP Teams Bot: Resultado para user $user_id → chat:" . ($sent_chat ? 'OK' : 'FAIL') . " feed:" . ($sent_feed ? 'OK' : 'FAIL'));

        return $sent_chat || $sent_feed;
    }

    private static function send_activity_notification($token, $user_oid, $title, $message, $action_link = '')
    {
        $catalog_app_id = self::get_catalog_app_id($token);
        $manifest_id = self::get_manifest_app_id();
        $url_to_open = !empty($action_link) ? $action_link : home_url('/?view=dashboard&teams=true');
        
        $deeplink = sprintf(
            "https://teams.microsoft.com/l/entity/%s/portal_dashboard?webUrl=%s&label=%s",
            $manifest_id,
            rawurlencode($url_to_open),
            rawurlencode('Portal del Empleado')
        );

        $body = [
            'teamsAppId'   => $catalog_app_id,
            'topic'        => ['source' => 'text', 'value' => $title, 'webUrl' => $deeplink],
            'activityType' => 'portalNotification',
            'previewText'  => ['content' => mb_substr(strip_tags($message), 0, 150)],
            'templateParameters' => [['name' => 'messageContent', 'value' => mb_substr(strip_tags($message), 0, 200)]],
            'recipient' => ['@odata.type' => '#microsoft.graph.aadUserNotificationRecipient', 'userId' => $user_oid],
        ];

        $url = "https://graph.microsoft.com/v1.0/users/{$user_oid}/teamwork/sendActivityNotification";
        $response = wp_remote_post($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return false;
        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MÉTODO 2 (FALLBACK): Chat 1:1 vía Bot Framework (Standard para Bots)
    // ═══════════════════════════════════════════════════════════════════════

    private static function send_via_oneonone_chat($graph_token, $user_oid, $title, $message, $action_link = '', $user_id = null)
    {
        // Si no se pasó user_id directamente, buscarlo por OID (consulta BD de respaldo)
        if (!$user_id) {
            $wp_user = get_users(['meta_key' => 'ep_o365_user_id', 'meta_value' => $user_oid, 'number' => 1]);
            $user_id = !empty($wp_user) ? $wp_user[0]->ID : null;
        }

        $stored_service_url     = $user_id ? get_user_meta($user_id, 'ep_bot_service_url', true) : null;
        $stored_conversation_id = $user_id ? get_user_meta($user_id, 'ep_bot_conversation_id', true) : null;

        if ($stored_service_url && $stored_conversation_id) {
            $result = self::send_via_bot_framework($stored_conversation_id, $title, $message, $action_link, $stored_service_url);
            if ($result['success'] ?? false) return true;
        }

        $chat_id = self::get_cached_chat_id($user_oid);
        if (!$chat_id) {
            $install_id = self::get_or_install_app($graph_token, $user_oid);
            if ($install_id) {
                $chat_resp = self::get_chat_from_installation($graph_token, $user_oid, $install_id);
                $chat_id = $chat_resp['id'] ?? null;
            }
            if ($chat_id) self::set_cached_chat_id($user_oid, $chat_id);
        }

        if (!$chat_id) return false;

        $result = self::send_via_bot_framework($chat_id, $title, $message, $action_link);
        return $result['success'] ?? false;
    }

    private static function send_via_bot_framework($conversation_id, $title, $message, $action_link = '', $preferred_service_url = null)
    {
        $bot_id     = ep_get_option('ep_teams_bot_id');
        $bot_secret = ep_get_option('ep_teams_bot_secret');
        $app_id     = ep_get_option('ep_o365_client_id');
        $app_secret = ep_get_option('ep_o365_client_secret');

        $action_url = !empty($action_link) ? $action_link : home_url('/?view=dashboard&teams=true');
        
        $body_template = [
            'type' => 'message',
            'from' => ['id' => ''], 
            'recipient' => ['id' => $conversation_id],
            'conversation' => ['id' => $conversation_id],
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type'    => 'AdaptiveCard',
                    'version' => '1.4',
                    'body'    => [
                        ['type' => 'TextBlock', 'text' => '🔔 ' . strip_tags($title), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => strip_tags($message), 'wrap' => true],
                    ],
                    'actions' => [
                        ['type' => 'Action.OpenUrl', 'title' => '🌐 Ver en el Portal', 'url' => $action_url],
                    ],
                ],
            ]],
        ];

        $regions = [];
        if ($preferred_service_url) {
            $regions['stored'] = rtrim($preferred_service_url, '/') . '/v3/conversations/' . urlencode($conversation_id) . '/activities';
        }
        $regions['emea']   = 'https://smba.trafficmanager.net/emea/v3/conversations/' . urlencode($conversation_id) . '/activities';
        $regions['global'] = 'https://smba.trafficmanager.net/v3/conversations/' . urlencode($conversation_id) . '/activities';

        $credentials_to_try = [
            ['id' => $bot_id, 'secret' => $bot_secret, 'label' => 'Bot Credentials'],
            ['id' => $app_id, 'secret' => $app_secret, 'label' => 'App Credentials']
        ];

        $last_code = 0; $last_error = 'No iniciado';

        foreach ($credentials_to_try as $creds) {
            if (empty($creds['id']) || empty($creds['secret'])) continue;

            $token_resp = self::get_bot_framework_token($creds['id'], $creds['secret']);
            if (!isset($token_resp['token'])) continue;
            
            $token = $token_resp['token'];
            $body = $body_template;
            $body['from']['id'] = "28:" . $creds['id'];

            foreach ($regions as $region => $service_url) {
                $response = wp_remote_post($service_url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode($body),
                    'timeout' => 15,
                ]);

                if (is_wp_error($response)) {
                    $last_error = $response->get_error_message();
                    continue;
                }

                $code = wp_remote_retrieve_response_code($response);
                $body_resp = wp_remote_retrieve_body($response);
                $last_code = $code;
                $last_error = $body_resp;

                if ($code >= 200 && $code < 300) {
                    error_log("EP Teams Bot: ✅ Éxito con {$creds['label']} en región {$region}");
                    return ['success' => true, 'code' => $code, 'region' => $region, 'creds' => $creds['label']];
                }

                if ($code === 401) {
                    error_log("EP Teams Bot: ❌ 401 con {$creds['label']} en {$region}. Probando siguiente credencial...");
                    break; 
                }
            }
        }

        return ['success' => false, 'code' => $last_code, 'error' => $last_error];
    }

    private static function get_bot_framework_token($bot_id, $bot_secret)
    {
        $transient_key = "ep_bt_token_" . md5($bot_id);
        $cached = get_transient($transient_key);
        if ($cached) return ['token' => $cached, 'source' => 'cache'];

        if (empty($bot_id) || empty($bot_secret)) {
            return ['error' => 'Bot ID o Bot Secret están vacíos'];
        }

        if (class_exists('EP_Security') && EP_Security::is_encrypted($bot_secret)) {
            $bot_secret = EP_Security::decrypt($bot_secret);
        }

        $tenant_id_o365 = ep_get_option('ep_o365_tenant_id');
        $endpoints = ['botframework.com'];
        if (!empty($tenant_id_o365)) {
            array_unshift($endpoints, $tenant_id_o365);
        }

        $last_error = '';
        foreach ($endpoints as $tenant) {
            $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
            $response = wp_remote_post($url, [
                'body' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $bot_id,
                    'client_secret' => $bot_secret,
                    'scope'         => 'https://api.botframework.com/.default',
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $last_error = "Red: " . $response->get_error_message();
                continue;
            }
            $code = wp_remote_retrieve_response_code($response);
            $body_raw = wp_remote_retrieve_body($response);
            $body = json_decode($body_raw, true);

            if ($code !== 200) {
                $azure_error = $body['error'] ?? 'unknown';
                $azure_desc  = $body['error_description'] ?? $body_raw;
                $last_error  = "HTTP {$code} [{$tenant}]: {$azure_error} - " . mb_substr($azure_desc, 0, 200);
                error_log("EP Bot Token FAIL [{$tenant}]: {$last_error}");
                continue;
            }

            $token = $body['access_token'] ?? null;

            if ($token) {
                set_transient($transient_key, $token, ($body['expires_in'] ?? 3600) - 300);
                return ['token' => $token, 'source' => $tenant];
            }
        }

        return ['error' => $last_error ?: 'No se pudo obtener token de ningún endpoint'];
    }

    private static function get_chat_from_installation($token, $user_oid, $install_id)
    {
        $url = "https://graph.microsoft.com/v1.0/users/{$user_oid}/teamwork/installedApps/{$install_id}/chat";
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'timeout' => 20
        ]);
        if (is_wp_error($response)) return ['id' => null, 'error' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code === 200 && !empty($data['id'])) return ['id' => $data['id'], 'error' => null];
        return ['id' => null, 'error' => "Búsqueda chat: " . ($data['error']['message'] ?? "Código HTTP {$code}")];
    }

    private static function send_chat_message($token, $chat_id, $title, $message, $action_link)
    {
        $url = "https://graph.microsoft.com/v1.0/chats/{$chat_id}/messages";
        $html_body = "<strong>" . esc_html($title) . "</strong><br><br>" . nl2br(esc_html($message));
        if (!empty($action_link)) {
            $html_body .= '<br><br><a href="' . esc_url($action_link) . '">🟢 Ver en el Portal del Empleado</a>';
        }
        $response = wp_remote_post($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['body' => ['contentType' => 'html', 'content' => $html_body]]),
            'timeout' => 20,
        ]);
        return (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300);
    }

    private static function get_app_only_token($tenant_id, $client_id, $client_secret)
    {
        if (class_exists('EP_Security') && EP_Security::is_encrypted($client_secret)) {
            $client_secret = EP_Security::decrypt($client_secret);
        }
        $url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        $response = wp_remote_post($url, [
            'body'    => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['access_token'] ?? false;
    }

    private static function get_or_install_app($token, $user_oid)
    {
        $manifest_id = self::get_manifest_app_id();
        $catalog_id  = self::get_catalog_app_id($token);
        if (!$catalog_id) return false;
        $base_url = "https://graph.microsoft.com/v1.0/users/{$user_oid}/teamwork/installedApps";
        $response = wp_remote_get($base_url . '?$expand=teamsApp', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'timeout' => 20,
        ]);
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['value'])) {
                foreach ($data['value'] as $app) {
                    $curr_id = $app['teamsApp']['id'] ?? '';
                    if ($curr_id === $catalog_id || ($app['teamsApp']['externalId'] ?? '') === $manifest_id) return $app['id'];
                }
            }
        }
        $install_resp = wp_remote_post($base_url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['teamsApp@odata.bind' => "https://graph.microsoft.com/v1.0/appCatalogs/teamsApps/" . $catalog_id]),
            'timeout' => 20,
        ]);
        if (wp_remote_retrieve_response_code($install_resp) > 299) return false;
        return self::get_or_install_app($token, $user_oid); // Re-buscar
    }

    public static function clear_user_chat_cache($user_id)
    {
        $employee_oid = get_user_meta($user_id, 'ep_o365_user_id', true);
        if ($employee_oid) delete_transient('ep_teams_chat_' . md5($employee_oid));
    }

    public static function ajax_diagnose()
    {
        if (!current_user_can('manage_options')) wp_send_json_error('No autorizado');
        $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : get_current_user_id();
        $tenant_id     = ep_get_option('ep_o365_tenant_id');
        $client_id     = ep_get_option('ep_o365_client_id');
        $client_secret = ep_get_option('ep_o365_client_secret');
        $employee_oid  = get_user_meta($target_user_id, 'ep_o365_user_id', true);
        $token         = self::get_app_only_token($tenant_id, $client_id, $client_secret);
        $diag = [
            'user_id' => $target_user_id,
            'employee_oid' => $employee_oid ?: '❌ NO encontrado',
            'configured_app_id' => $client_id,
            'tenant_id' => $tenant_id,
            'steps' => [],
            'endpoint_info' => [
                'rest_url' => get_rest_url(null, '/employee-portal/v1/teams-bot'),
                'native_url' => home_url('/?ep_bot=1'),
                'emergency_url' => plugins_url('test-bot.php', EMPLOYEE_PORTAL_PATH . 'employee-portal.php')
            ],
            'resultado' => ''
        ];
        $diag['steps'][] = $token ? '✅ Token Graph obtenido' : '❌ FALLO Token Graph';
        if (!$token || !$employee_oid) {
            $diag['resultado'] = '❌ Faltan datos críticos.';
            wp_send_json_success($diag); return;
        }
        $bot_id = ep_get_option('ep_teams_bot_id');
        $bot_secret = ep_get_option('ep_teams_bot_secret');
        $diag['bot_id_used'] = $bot_id ?: '⚠️ VACÍO';
        $diag['bot_secret_len'] = strlen($bot_secret);
        $diag['bot_same_as_app'] = ($bot_id === $client_id) ? 'Sí (misma app)' : 'No (apps distintas)';
        delete_transient("ep_bt_token_" . md5($bot_id));
        delete_transient("ep_bt_token_v3_" . md5($bot_id));
        $bt_resp = self::get_bot_framework_token($bot_id, $bot_secret);
        if (isset($bt_resp['token'])) {
            $diag['steps'][] = "✅ Token Bot Framework obtenido (Endpoint: {$bt_resp['source']})";
        } else {
            $error_detail = $bt_resp['error'] ?? 'Sin detalles';
            $diag['steps'][] = "❌ FALLO Token Bot Framework: {$error_detail}";
        }
        $install_id = self::get_or_install_app($token, $employee_oid);
        $diag['steps'][] = $install_id ? "✅ App Detectada" : "❌ App NO instalada";
        $ok1 = self::send_activity_notification($token, $employee_oid, 'Test', 'Feed');
        $diag['steps'][] = $ok1 ? "✅ MÉTODO 1 (Feed) -> Éxito" : "❌ MÉTODO 1 (Feed) -> Falló";
        $chat_resp = self::get_chat_from_installation($token, $employee_oid, $install_id);
        if ($chat_resp['id']) {
            $diag['steps'][] = "✅ Chat ID encontrado: " . $chat_resp['id'];
            $bf_result = self::send_via_bot_framework($chat_resp['id'], 'Test Bot', 'Adaptive Card');
            if ($bf_result['success']) {
                $diag['steps'][] = "✅ MÉTODO 2 (Bot Framework) -> Éxito (Uso: {$bf_result['creds']})";
            } else {
                $diag['steps'][] = "❌ MÉTODO 2 (Bot Framework) -> Falló ({$bf_result['code']}): {$bf_result['error']}";
                $ok3 = self::send_chat_message($token, $chat_resp['id'], 'Test Graph', 'Chat API', '');
                $diag['steps'][] = $ok3 ? "✅ MÉTODO 3 (Graph Chat) -> Éxito" : "❌ MÉTODO 3 (Graph Chat) -> Falló";
            }
        }

        $diag['resultado'] = "Finalizado.\n\nCONSEJO PARA CLOUDFLARE:\nSi el bot no responde a mensajes entrantes, asegúrate de que Cloudflare permita el acceso a los siguientes endpoints:\n1. {$diag['endpoint_info']['rest_url']}\n2. {$diag['endpoint_info']['native_url']}\n\nSi el WAF de Cloudflare sigue bloqueando, puedes intentar usar el endpoint de emergencia:\n{$diag['endpoint_info']['emergency_url']}";
        wp_send_json_success($diag);
    }

    private static function get_cached_chat_id($user_oid) { 
        return get_transient('ep_teams_chat_' . md5($user_oid)); 
    }
    private static function set_cached_chat_id($user_oid, $chat_id) { 
        set_transient('ep_teams_chat_' . md5($user_oid), $chat_id, self::CHAT_CACHE_TTL); 
    }
}
