<?php
defined('ABSPATH') || exit;

/**
 * EP_Bot_Mensajeria
 *
 * Recibe los mensajes que los usuarios envían al bot de Teams
 * y responde con información del portal en español.
 */
class EP_Bot_Mensajeria
{
    /** @var EP_Bot_Mensajeria|null Instancia viva, para que otros módulos (briefing) envíen tarjetas sin duplicar hooks. */
    private static $instance = null;

    public static function instance(): ?EP_Bot_Mensajeria
    {
        return self::$instance;
    }

    public function __construct()
    {
        self::$instance = $this;
        $uri = $_SERVER['REQUEST_URI'] ?? 'N/A';
        if (strpos($uri, 'teams-bot') !== false || strpos($uri, 'teams-webhook') !== false || strpos($uri, 'ep_bot=1') !== false || strpos($uri, 'ep_bot_msg') !== false) {
             $headers = function_exists('getallheaders') ? getallheaders() : [];
             $headers_str = json_encode($headers);
             ep_error_log("EP Bot IN: " . ($_SERVER['REQUEST_METHOD']??'GET') . " $uri | IP: " . ($_SERVER['REMOTE_ADDR']??'unk'));
             ep_error_log("EP Bot UA: " . ($_SERVER['HTTP_USER_AGENT']??'none'));
             ep_error_log("EP Bot Headers: $headers_str");
        }
        add_action('rest_api_init', [$this, 'registrar_ruta']);
        add_action('wp_ajax_nopriv_ep_bot_msg', [$this, 'manejar_mensaje_ajax']);
        add_action('wp_ajax_ep_bot_msg',        [$this, 'manejar_mensaje_ajax']);
        add_action('init', [$this, 'registrar_endpoint_nativo'], 1);
        
        // Notificaciones proactivas de firmas
        add_action('ep_signature_request_created', [$this, 'notificar_nueva_firma'], 10, 3);
    }

    public function registrar_endpoint_nativo()
    {
        if (isset($_GET['ep_bot']) && $_GET['ep_bot'] === '1') {
            ep_error_log('EP Bot: Endpoint nativo detectado. Método: ' . $_SERVER['REQUEST_METHOD']);
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$this->peticion_autenticada()) {
                    ep_error_log('EP Bot SECURITY ALERT: Endpoint nativo sin token Bearer válido. Petición rechazada.', true);
                    status_header(401);
                    exit;
                }
                $this->procesar_peticion_teams();
                exit;
            } else {
                ep_error_log('EP Bot: Ignorando petición que no es POST (probablemente prueba de navegador).');
            }
        }
    }

    public function registrar_ruta()
    {
        $rutas = ['/teams-bot', '/teams-webhook'];
        foreach ($rutas as $ruta) {
            register_rest_route('employee-portal/v1', $ruta, [
                'methods'             => ['POST', 'GET'],
                'callback'            => [$this, 'manejar_mensaje'],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public function manejar_mensaje_ajax()
    {
        if (!$this->peticion_autenticada()) {
            ep_error_log('EP Bot SECURITY ALERT: Petición AJAX sin token Bearer válido. Rechazada.', true);
            wp_send_json_error(['error' => 'Unauthorized. Missing or invalid Bearer token.'], 401);
            exit;
        }

        $this->procesar_peticion_teams();
        exit;
    }

    /**
     * Cabecera Authorization tal y como llega a PHP. El .htaccess del portal
     * la reenvía como HTTP_AUTHORIZATION, y en producción llega siempre
     * (comprobado en el log el 2026-09-04): no hay que tolerar su ausencia.
     */
    private function cabecera_authorization(?WP_REST_Request $request = null): string
    {
        $auth = $request ? (string)$request->get_header('authorization') : '';
        if ($auth === '') {
            $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        return trim($auth);
    }

    /**
     * True solo si la petición trae un JWT firmado por Bot Framework (o por el
     * tenant, en bots de inquilino único) y dirigido a este bot.
     */
    private function peticion_autenticada(?WP_REST_Request $request = null): bool
    {
        $auth = $this->cabecera_authorization($request);
        if ($auth === '' || stripos($auth, 'Bearer ') !== 0) {
            return false;
        }
        return $this->validar_token_microsoft(substr($auth, 7));
    }

    public function manejar_peticion_directa()
    {
        ep_error_log('EP Bot: Iniciando procesamiento de actividad de Teams (Directo/Emergencia).');
        $this->procesar_peticion_teams();
    }

    private function procesar_peticion_teams()
    {
        ep_error_log('EP Bot: Iniciando procesamiento de actividad de Teams (Nativo/AJAX/Directo).');
        
        $body_raw = file_get_contents('php://input');
        if (empty($body_raw)) {
            ep_error_log('EP Bot ERROR: El cuerpo de la petición está VACÍO.');
            return;
        }
        
        ep_error_log('EP Bot: Payload RAW recibido (' . strlen($body_raw) . ' bytes).');
        
        $activity = json_decode($body_raw, true);
        if (!$activity || !isset($activity['type'])) {
            ep_error_log('EP Bot ERROR: JSON inválido o sin "type". Body: ' . substr($body_raw, 0, 100));
            return;
        }

        ep_error_log('EP Bot: Actividad tipo "' . $activity['type'] . '" detectada.');
        $this->procesar_actividad_final($activity);
    }

    public function manejar_mensaje(WP_REST_Request $request)
    {
        ep_error_log('EP Bot: Petición REST recibida en manejar_mensaje. Método: ' . $request->get_method());
        
        if ($request->get_method() === 'GET') {
            return new WP_REST_Response([
                'status' => 'OK',
                'message' => 'Portal del Empleado Bot Endpoint is ACTIVE',
                'timestamp' => date('Y-m-d H:i:s'),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ], 200);
        }

        $validation_token = $request->get_param('validationToken');
        if ($validation_token) {
            ep_error_log('EP Bot: Validación de token recibida.');
            return new WP_REST_Response($validation_token, 200, array('Content-Type' => 'text/plain'));
        }

        // Seguridad: toda actividad entrante debe venir firmada por Bot Framework.
        // Antes se dejaba pasar sin cabecera "por si Apache la eliminaba"; el log
        // de producción demuestra que llega siempre, así que se exige sin excepción.
        // Sin esto, cualquiera que conociera la URL podía hacer hablar al bot.
        if (!$this->peticion_autenticada($request)) {
            ep_error_log('EP Bot SECURITY ALERT: Petición REST sin token Bearer válido. Rechazada.', true);
            return new WP_REST_Response(['error' => 'Unauthorized'], 401);
        }

        $body_raw = $request->get_body();
        ep_error_log('EP Bot REST: Payload recibido (' . strlen($body_raw) . ' bytes).');
        
        $actividad = json_decode($body_raw, true);
        if (!is_array($actividad)) {
            $actividad = $request->get_json_params();
        }

        if (!is_array($actividad)) {
            ep_error_log('EP Bot ERROR: No se pudo decodificar el JSON del cuerpo REST.');
            return new WP_REST_Response(['error' => 'Invalid payload', 'received' => substr($body_raw, 0, 50)], 200); // Retornamos 200 para no disparar alertas de bot si es ruido
        }

        $this->procesar_actividad_final($actividad);
        return new WP_REST_Response(new stdClass(), 200);
    }

    public function procesar_actividad_final(array $actividad)
    {
        $tipo = $actividad['type'] ?? 'unknown';
        ep_error_log('EP Bot: Procesando actividad final tipo: ' . $tipo);

        // conversationUpdate: bot añadido a un chat (miembro nuevo = el propio bot)
        if ($tipo === 'conversationUpdate') {
            $members_added = $actividad['membersAdded'] ?? [];
            $bot_id = ep_get_option('ep_teams_bot_id');
            foreach ($members_added as $member) {
                $member_id = ltrim($member['id'] ?? '', '28:');
                if ($member_id === $bot_id || strpos($member['id'] ?? '', $bot_id) !== false) {
                    $service_url     = rtrim($actividad['serviceUrl'] ?? 'https://smba.trafficmanager.net/emea', '/');
                    $conversation_id = $actividad['conversation']['id'] ?? null;
                    if ($conversation_id) {
                        $tarjeta = $this->tarjeta_simple(
                            '👋 Hola, soy el asistente del Portal del Empleado',
                            'Escríbeme **hola** para ver tu resumen del día, o **ayuda** para ver todo lo que puedo hacer.',
                            home_url('/?teams=true')
                        );
                        $tarjeta['channelId'] = $actividad['channelId'] ?? 'teams';
                        $tarjeta['from_id']   = $actividad['recipient']['id'] ?? ep_get_option('ep_teams_bot_id');
                        $this->enviar_respuesta($service_url, $conversation_id, null, $tarjeta);
                    }
                    break;
                }
            }
            return;
        }

        // invoke: botones Action.Submit de Adaptive Cards
        if ($tipo === 'invoke') {
            $service_url     = rtrim($actividad['serviceUrl'] ?? 'https://smba.trafficmanager.net/emea', '/');
            $conversation_id = $actividad['conversation']['id'] ?? null;
            $actividad_id    = $actividad['id'] ?? null;

            // Extraer el comando del payload del botón
            $data_button = $actividad['value']['action']['data'] ?? $actividad['value'] ?? [];
            $texto_cmd   = $data_button['m'] ?? $data_button['action'] ?? '';

            if ($conversation_id && !empty($texto_cmd)) {
                $oid_usuario = $actividad['from']['aadObjectId'] ?? null;
                $wp_user     = $this->buscar_usuario_por_oid($oid_usuario, $actividad['from'] ?? []);
                $tarjeta     = $this->generar_respuesta(strtolower(trim($texto_cmd)), $wp_user, $conversation_id);
                $tarjeta['channelId'] = $actividad['channelId'] ?? 'teams';
                $tarjeta['from_id']   = $actividad['recipient']['id'] ?? ep_get_option('ep_teams_bot_id');
                $this->enviar_respuesta($service_url, $conversation_id, $actividad_id, $tarjeta);
            }
            return;
        }

        if ($tipo !== 'message') {
            ep_error_log('EP Bot: Actividad ignorada (tipo: ' . $tipo . ')');
            return;
        }

        // Limpiar texto de menciones al bot si las hay
        $texto_raw = $actividad['text'] ?? '';
        
        // Soporte para botones Action.Submit (data payload en 'value')
        $data_button = $actividad['value'] ?? [];
        if (empty($texto_raw) && !empty($data_button['m'])) {
            $texto_raw = (string)$data_button['m'];
        }

        // Decodificar entidades HTML (&nbsp;, &amp;, etc.) y caracteres invisibles de Teams
        $texto_limpio = html_entity_decode((string)$texto_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto_limpio = str_replace(["\xc2\xa0", "\u{00A0}", "\r", "\n"], ' ', $texto_limpio);

        // Eliminar bloques completos de menciones <at>...</at> que inyecta Microsoft Teams
        $texto_limpio = preg_replace('/<at[^>]*>.*?<\/at>/isu', '', $texto_limpio);
        $texto_limpio = strip_tags($texto_limpio);
        $texto_usuario = strtolower(trim($texto_limpio));

        // Limpiar menciones de texto plano al bot (nombre corto y largo, con y sin "del")
        $texto_usuario = preg_replace('/^@?portal\s+del\s+empleado\s*/iu', '', $texto_usuario);
        $texto_usuario = preg_replace('/^@?portal\s+empleado\s*/iu', '', $texto_usuario);
        $texto_usuario = preg_replace('/^@?ayudante\s*/iu', '', $texto_usuario);
        // Colapsar espacios múltiples
        $texto_usuario = trim(preg_replace('/\s+/', ' ', $texto_usuario));

        ep_error_log('EP Bot: Texto procesado del usuario: "' . $texto_usuario . '" (Raw: "' . substr($texto_raw, 0, 100) . '")');

        $oid_usuario     = $actividad['from']['aadObjectId'] ?? null;
        $service_url     = rtrim($actividad['serviceUrl'] ?? 'https://smba.trafficmanager.net/emea', '/');
        $conversation_id = $actividad['conversation']['id'] ?? null;
        $actividad_id    = $actividad['id'] ?? null;

        if (!$conversation_id) {
            ep_error_log('EP Bot: Error - No hay conversation_id');
            return;
        }

        $wp_user = $this->buscar_usuario_por_oid($oid_usuario, $actividad['from'] ?? []);
        if ($wp_user) {
            ep_error_log('EP Bot: Usuario reconocido: ' . $wp_user->display_name);
            $this->guardar_referencia_conversacion($wp_user->ID, $service_url, $conversation_id);
        } else {
            ep_error_log('EP Bot: Usuario NO reconocido para OID: ' . $oid_usuario . ' | from: ' . json_encode($actividad['from'] ?? []));
        }

        $tarjeta = $this->generar_respuesta($texto_usuario, $wp_user, $conversation_id);
        
        // Propagar metadatos para la respuesta
        $tarjeta['channelId'] = $actividad['channelId'] ?? 'teams';
        $tarjeta['from_id']   = $actividad['recipient']['id'] ?? ep_get_option('ep_teams_bot_id');

        $this->enviar_respuesta($service_url, $conversation_id, $actividad_id, $tarjeta);
    }

    private function generar_respuesta(string $texto, $wp_user, string $conversation_id = ''): array
    {
        if (!$wp_user) {
            return $this->tarjeta_simple('👤 No te reconozco', "No he podido asociar tu cuenta de Teams. Inicia sesión en el portal.", home_url('/?teams=true'));
        }

        $nombre  = $wp_user->display_name ?: $wp_user->user_login;
        $user_id = $wp_user->ID;
        $texto   = trim($texto);

        // 1. GESTIÓN DE SALUDOS (HOLA) -> Tarjeta de Bienvenida con Resumen



        if (empty($texto) || $this->contiene($texto, ['hola', 'buenos dias', 'buenas tardes', 'inicio', 'empezar'])) {
             // Solo si es un mensaje de saludo "puro" (pocos caracteres o palabra exacta)
             if (strlen($texto) < 15) {
                 return $this->tarjeta_bienvenida($user_id, $nombre);
             }
        }

        // 2. GESTIÓN DE AYUDA (AYUDA) -> Tarjeta de Capacidades Dinámica
        if ($this->contiene($texto, ['ayuda', 'help', 'comandos', 'que puedes hacer', 'que haces'])) {
            if (strlen($texto) < 20) {
                return $this->tarjeta_ayuda($nombre, $user_id);
            }
        }

        // 3. ATAJOS SIN IA: frases fijas y botones de las tarjetas se resuelven por
        //    coincidencia directa. Ahorra la llamada a Gemini (latencia y cuota) en
        //    las consultas más repetidas. Cualquier matiz cae en la IA como siempre.
        $atajo = $this->atajo_directo($texto, $wp_user, $conversation_id);
        if ($atajo !== null) {
            return $atajo;
        }

        // 4. TODO LO DEMÁS -> RESOLVER CON IA
        return $this->resolver_con_ia($texto, $wp_user, $conversation_id);
    }

    /**
     * Mapa de frases fijas → intención, sin pasar por la IA.
     * Solo textos cortos (≤ 40 caracteres) y coincidencia exacta tras
     * normalizar (minúsculas, sin tildes ni signos). "mis tareas de la semana
     * que viene" no coincide y sigue yendo a Gemini.
     */
    private function atajo_directo(string $texto, $wp_user, string $conversation_id): ?array
    {
        if (mb_strlen($texto) > 40) return null;

        $clave = $this->sin_tildes(mb_strtolower($texto));
        $clave = preg_replace('/[^a-z0-9 ]/u', '', $clave);
        $clave = trim(preg_replace('/\s+/', ' ', (string)$clave));
        if ($clave === '') return null;

        $atajos = [
            'TASKS'          => ['mis tareas', 'tareas', 'ver tareas', 'tareas pendientes', 'mis tareas pendientes', 'lista de tareas', 'to do', 'todo'],
            'NOTIFICATIONS'  => ['mis notificaciones', 'notificaciones', 'ver notificaciones', 'notificaciones pendientes'],
            'DASHBOARD'      => ['resumen', 'mi resumen', 'resumen del dia', 'panel', 'mi panel', 'dashboard', 'mi dia'],
            'SIGNATURE'      => ['firmas', 'mis firmas', 'firmas pendientes', 'pendientes de firma', 'documentos pendientes de firma', 'tengo algo que firmar', 'tengo algo por firmar'],
            'TICKETS'        => ['tickets', 'mis tickets', 'incidencias', 'mis incidencias', 'estado de mis tickets', 'mis solicitudes'],
            'INVENTORY'      => ['inventario', 'mi inventario', 'mi equipo', 'mi material', 'que equipo tengo'],
            'AGENDA_HOY'     => ['agenda', 'mi agenda', 'agenda de hoy', 'mi agenda de hoy', 'reuniones de hoy', 'mis reuniones', 'mis reuniones de hoy', 'que tengo hoy', 'hoy'],
            'AGENDA_MANANA'  => ['agenda de manana', 'mi agenda de manana', 'reuniones de manana', 'mis reuniones de manana', 'que tengo manana', 'manana'],
            'AGENDA_PROXIMA' => ['proxima cita', 'proxima reunion', 'mi proxima cita', 'mi proxima reunion', 'cual es mi proxima cita', 'cual es mi proxima reunion', 'siguiente reunion', 'siguiente cita'],
        ];

        $intent = null;
        foreach ($atajos as $candidato => $frases) {
            if (in_array($clave, $frases, true)) {
                $intent = $candidato;
                break;
            }
        }
        if ($intent === null) return null;

        ep_error_log("EP Bot: Atajo directo '{$intent}' para \"{$texto}\" (sin IA).");

        $user_id = $wp_user->ID;
        $nombre  = $wp_user->display_name ?: $wp_user->user_login;

        switch ($intent) {
            case 'TASKS':
                return $this->tarjeta_tareas($user_id, $nombre);
            case 'NOTIFICATIONS':
                if (!$this->puede_ver_app('avisos', $user_id)) return $this->tarjeta_sin_permiso();
                return $this->tarjeta_notificaciones($user_id, $nombre);
            case 'DASHBOARD':
                return $this->tarjeta_resumen($user_id, $nombre);
            case 'AGENDA_PROXIMA':
                return $this->tarjeta_proxima_cita($user_id);
            case 'AGENDA_HOY':
                return $this->ejecutar_intent_app('AGENDA', 'calendar', [], $wp_user, 'hoy', $conversation_id);
            case 'AGENDA_MANANA':
                return $this->ejecutar_intent_app('AGENDA', 'calendar', [], $wp_user, 'mañana', $conversation_id);
            case 'SIGNATURE':
                return $this->ejecutar_intent_app('SIGNATURE', 'signature', [], $wp_user, $texto, $conversation_id);
            case 'TICKETS':
                return $this->ejecutar_intent_app('TICKETS', 'tickets', [], $wp_user, $texto, $conversation_id);
            case 'INVENTORY':
                return $this->ejecutar_intent_app('INVENTORY', 'inventory', [], $wp_user, $texto, $conversation_id);
        }
        return null;
    }

    /**
     * Lanza el manejador que una app registró para un intent (mismo filtro que
     * usa resolver_con_ia), comprobando antes el permiso. Devuelve null si la
     * app no está cargada, para que el texto siga su camino hacia la IA.
     */
    private function ejecutar_intent_app(string $intent, string $app_id, array $params, $wp_user, string $texto, string $conversation_id): ?array
    {
        $user_id = $wp_user->ID;
        if (!$this->puede_ver_app($app_id, $user_id)) {
            return $this->tarjeta_sin_permiso();
        }

        $intent_data = ['intent' => $intent, 'params' => $params, 'confidence' => 1.0, 'suggested_reply' => ''];
        $respuesta   = apply_filters('ep_bot_handle_intent_' . strtolower($intent), null, $intent_data, $user_id, $texto, $this);
        if ($respuesta === null) return null;

        if ($conversation_id) {
            EP_Bot_Context::set_context($user_id, [
                'intent'  => $intent,
                'params'  => $params,
                'results' => $respuesta['_meta_data'] ?? []
            ]);
        }
        return $respuesta;
    }

    private function tarjeta_sin_permiso(): array
    {
        return $this->tarjeta_simple('🚫 Acceso Denegado', "Lo siento, no tienes permisos configurados para acceder a esta sección del portal.", '');
    }

    /**
     * Próxima cita (14 días vista), con el día explícito para que nunca se
     * confunda con la agenda de hoy.
     */
    private function tarjeta_proxima_cita(int $user_id): array
    {
        if (!$this->puede_ver_app('calendar', $user_id)) return $this->tarjeta_sin_permiso();

        $url_agenda = home_url('/?view=calendar&teams=true');
        $event = EP_Graph_Service::get_instance()->get_next_event($user_id);
        if (is_wp_error($event)) {
            return $this->tarjeta_simple('📅 Próxima cita', 'No he podido consultar tu calendario. Inicia sesión en el portal desde el navegador primero.', $url_agenda);
        }
        if (empty($event)) {
            return $this->tarjeta_simple('📅 Próxima cita', 'No tienes ninguna reunión en los próximos 14 días. ☕', $url_agenda);
        }

        $tz     = new DateTimeZone('Europe/Madrid');
        $inicio = (string)($event['start']['dateTime'] ?? '');
        $fecha  = substr($inicio, 0, 10);
        $hora   = substr($inicio, 11, 5);

        $hoy    = (new DateTime('now', $tz))->format('Y-m-d');
        $manana = (new DateTime('tomorrow', $tz))->format('Y-m-d');
        if ($fecha === $hoy) {
            $cuando = 'Hoy';
        } elseif ($fecha === $manana) {
            $cuando = 'Mañana';
        } else {
            $dias  = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
            $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            $dt    = DateTime::createFromFormat('Y-m-d', $fecha, $tz);
            $cuando = $dt ? $dias[(int)$dt->format('N')] . ' ' . $dt->format('j') . ' de ' . $meses[(int)$dt->format('n')] : $fecha;
        }

        $facts = [
            ['title' => '🕒 Cuándo', 'value' => "{$cuando}, {$hora}"],
            ['title' => '📌 Asunto', 'value' => mb_substr((string)($event['subject'] ?? 'Sin asunto'), 0, 60)],
        ];
        if (!empty($event['location']['displayName'])) {
            $facts[] = ['title' => '📍 Dónde', 'value' => mb_substr((string)$event['location']['displayName'], 0, 60)];
        }

        return $this->adaptive_card([
            ['type' => 'TextBlock', 'text' => '📅 Tu próxima cita', 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
            ['type' => 'FactSet', 'facts' => $facts],
        ], [['type' => 'Action.OpenUrl', 'title' => '📅 Ver mi agenda completa', 'url' => $url_agenda]]);
    }

    /**
     * Tarjeta del briefing matinal (la de bienvenida con su propia cabecera).
     * La usa EP_Bot_Briefing desde el cron de las 8:00.
     */
    public function tarjeta_briefing(int $user_id): ?array
    {
        $wp_user = get_userdata($user_id);
        if (!$wp_user) return null;
        $nombre = $wp_user->display_name ?: $wp_user->user_login;
        return $this->tarjeta_bienvenida($user_id, $nombre, '☀️ Tu briefing de las 8:00. Esto es lo que te espera hoy:');
    }

    /**
     * Envía una tarjeta al chat 1:1 de un usuario con el bot, usando la
     * referencia de conversación guardada la última vez que le escribió.
     * Devuelve false si nunca ha hablado con el bot o si el envío falla.
     */
    public function enviar_tarjeta_a_usuario(int $user_id, array $tarjeta): bool
    {
        if (function_exists('ep_is_staging') && ep_is_staging()) return false;

        $service_url = get_user_meta($user_id, 'ep_bot_service_url', true);
        $conv_id     = get_user_meta($user_id, 'ep_bot_conversation_id', true);
        if (!$service_url || !$conv_id) return false;

        $tarjeta['channelId'] = 'teams';
        $tarjeta['from_id']   = ep_get_option('ep_teams_bot_id');
        return $this->enviar_respuesta($service_url, $conv_id, null, $tarjeta);
    }

    private function puede_ver_app(string $app_id, int $user_id): bool
    {
        global $ep_app_manager;
        if (!isset($ep_app_manager)) return true; // Fail-safe
        return $ep_app_manager->get_user_permission($app_id, $user_id) !== 'none';
    }

    /**
     * Tarjeta de ayuda dinámica según los permisos del usuario.
     */
    private function tarjeta_ayuda(string $nombre, int $user_id): array
    {
        $cuerpo = [
            ['type' => 'TextBlock', 'text' => "📖 Guía Completa del Asistente", 'weight' => 'Bolder', 'size' => 'Large', 'color' => 'Accent', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => "Hola {$nombre}. Entiendo lenguaje natural gracias a mi IA integrada. Aquí tienes todo lo que puedo hacer por ti (las opciones dependen de tus permisos):", 'wrap' => true],
        ];

        // 1. GESTIÓN PERSONAL
        $personal_facts = [];
        if ($this->puede_ver_app('calendar', $user_id)) {
            $personal_facts[] = ['title' => '📅 Agenda', 'value' => '"Mis reuniones de hoy", "Agendar sala para mañana a las 10"'];
        }
        $personal_facts[] = ['title' => '✅ Tareas', 'value' => '"Ver mis tareas pendientes", "Añadir tarea comprar billetes"'];
        if ($this->puede_ver_app('inventory', $user_id)) {
            $personal_facts[] = ['title' => '📦 Inventario', 'value' => '"¿Qué equipo informático tengo asignado?"'];
        }
        
        if (!empty($personal_facts)) {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => '👤 **Gestión Personal**', 'size' => 'Medium', 'spacing' => 'Medium'];
            $cuerpo[] = ['type' => 'FactSet', 'facts' => $personal_facts];
        }

        // 2. COMUNICACIÓN & EQUIPO
        $comms_facts = [];
        if ($this->puede_ver_app('directory', $user_id)) {
            $comms_facts[] = ['title' => '🔍 Directorio', 'value' => '"Busca a Raúl", "Dime el teléfono de Carlos"'];
        }
        if ($this->puede_ver_app('avisos', $user_id)) {
            $comms_facts[] = ['title' => '🔔 Avisos', 'value' => '"Mis notificaciones", "¿Hay comunicados nuevos?"'];
        }
        if ($this->puede_ver_app('buzon', $user_id)) {
            $comms_facts[] = ['title' => '📬 Buzón Sugerencias', 'value' => '"¿Hay mensajes nuevos en el buzón?"'];
        }

        if (!empty($comms_facts)) {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => '🗣️ **Comunicación y Equipo**', 'size' => 'Medium', 'spacing' => 'Medium'];
            $cuerpo[] = ['type' => 'FactSet', 'facts' => $comms_facts];
        }

        // 3. OPERATIVA EMPRESARIAL
        $ops_facts = [];
        $ops_facts[] = ['title' => 'Documentos', 'value' => '"¿Qué dice el documento del convenio?", "Busca el documento de protocolo"'];
        
        if ($this->puede_ver_app('censo', $user_id)) {
            $ops_facts[] = ['title' => '🏢 Censo Cameral', 'value' => '"Datos de la empresa B0100...", "¿Cuántas empresas hay en Cáceres?"'];
        }
        if ($this->puede_ver_app('signature', $user_id)) {
            $ops_facts[] = ['title' => '✍️ Firmas', 'value' => '"¿Tengo documentos pendientes de firmar?"'];
        }
        if ($this->puede_ver_app('tickets', $user_id)) {
            $ops_facts[] = ['title' => '🎫 Soporte IT', 'value' => '"Quiero abrir una incidencia", "Estado de mis tickets"'];
        }
        if ($this->puede_ver_app('stats', $user_id)) {
            $ops_facts[] = ['title' => '📊 Estadísticas', 'value' => '"Resumen de uso hoy", "Dime las estadísticas semanales"'];
        }

        if (!empty($ops_facts)) {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => '⚙️ **Operativa Empresarial**', 'size' => 'Medium', 'spacing' => 'Medium'];
            $cuerpo[] = ['type' => 'FactSet', 'facts' => $ops_facts];
        }

        // TIPS GENERALES
        $cuerpo[] = ['type' => 'TextBlock', 'text' => '💡 **Consejos Avanzados:**', 'size' => 'Medium', 'spacing' => 'Large'];
        $cuerpo[] = ['type' => 'TextBlock', 'text' => '- 🔄 Si me escribes **"resumen"** prepararé un informe rápido de alertas importantes en 1 click.', 'wrap' => true];
        $cuerpo[] = ['type' => 'TextBlock', 'text' => '- 💬 **¡Habla conmigo!**: No tienes que memorizar comandos. Háblame de forma natural y entenderé lo que necesitas gracias al motor semántico.', 'wrap' => true];

        return $this->adaptive_card($cuerpo, [
            ['type' => 'Action.OpenUrl', 'title' => '🌐 Abrir Portal Web Completo', 'url' => home_url('/?teams=true')]
        ]);
    }


    private function tarjeta_notificaciones(int $user_id, string $nombre): array
    {
        if (!class_exists('EP_Notifications')) return $this->tarjeta_simple('🔔 Notificaciones', 'No disponible.', '');
        $notifs = EP_Notifications::get_user_notifications($user_id, 5, false);
        if (empty($notifs)) return $this->tarjeta_simple('🔔 Tus Notificaciones', "Sin avisos recientes.", home_url('/?view=dashboard&teams=true'));
        $hechos = [];
        foreach ($notifs as $n) $hechos[] = ['title' => ($n->is_read ? '✅' : '🔵') . ' ' . mb_substr($n->title, 0, 35), 'value' => mb_substr($n->message, 0, 60)];
        return $this->adaptive_card([
            ['type' => 'TextBlock', 'text' => "🔔 Notificaciones de {$nombre}", 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
            ['type' => 'FactSet', 'facts' => $hechos],
        ], [['type' => 'Action.OpenUrl', 'title' => '🔔 Ver todas', 'url' => home_url('/?view=dashboard&teams=true')]]);
    }

    private function tarjeta_resumen(int $user_id, string $nombre): array
    {
        $facts = [];
        $graph = EP_Graph_Service::get_instance();
        global $wpdb;

        // 1. Firmas pendientes
        $table = $wpdb->prefix . 'fds_documentos';
        $num_firmas = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE usuario_id = %d AND estado = 'pendiente'", $user_id
        ));
        if ($num_firmas > 0) {
            $facts[] = ['title' => '✍️ Firmas pendientes', 'value' => (string)$num_firmas];
        }

        // 2. Agenda de HOY (no la próxima cita de cualquier día)
        $this->anadir_fact_agenda_hoy($user_id, $facts);

        // 3. Tareas To-Do
        $tasks = $graph->get_my_tasks($user_id);
        if (!is_wp_error($tasks) && !empty($tasks)) {
            $facts[] = ['title' => '✅ Tareas To-Do', 'value' => (string)count($tasks) . ' pendientes'];
        }

        // 4. Tickets
        if ($this->puede_ver_app('tickets', $user_id) && class_exists('EP_Tickets')) {
            $num_gestion = count(EP_Tickets::get_manageable_tickets_for_user($user_id));
            $num_propios = count(EP_Tickets::get_user_tickets($user_id));
            if ($num_gestion > 0) $facts[] = ['title' => '📥 Tickets para gestionar', 'value' => (string)$num_gestion];
            if ($num_propios > 0) $facts[] = ['title' => '📤 Mis solicitudes abiertas', 'value' => (string)$num_propios];
        }

        // 5. Notificaciones sin leer
        if ($this->puede_ver_app('avisos', $user_id) && class_exists('EP_Notifications')) {
            $num_n = EP_Notifications::count_unread($user_id);
            if ($num_n > 0) $facts[] = ['title' => '🔔 Notificaciones sin leer', 'value' => (string)$num_n];
        }

        $cuerpo = [
            ['type' => 'TextBlock', 'text' => "📊 Tu panel de hoy, {$nombre}", 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
        ];

        if (!empty($facts)) {
            $cuerpo[] = ['type' => 'FactSet', 'facts' => $facts, 'spacing' => 'Medium'];
        } else {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => '✅ ¡Todo al día! No tienes tareas urgentes pendientes.', 'spacing' => 'Medium', 'wrap' => true];
        }

        return $this->adaptive_card($cuerpo, [
            ['type' => 'Action.OpenUrl', 'title' => '🌐 Ir al Portal', 'url' => home_url('/?teams=true')],
            ['type' => 'Action.Submit', 'title' => '📅 Próxima cita', 'data' => ['m' => 'cuál es mi próxima reunión']],
            ['type' => 'Action.Submit', 'title' => '✅ Ver Tareas', 'data' => ['m' => 'mis tareas']],
            ['type' => 'Action.Submit', 'title' => '🔔 Ver Notificaciones', 'data' => ['m' => 'mis notificaciones']],
        ]);
    }

    /**
     * Añade al FactSet la agenda que queda HOY: cuántas reuniones y la primera.
     * Si no queda ninguna lo dice explícitamente, para que el resumen no se
     * rellene con una cita de otro día (eso se pide con "próxima cita").
     */
    private function anadir_fact_agenda_hoy(int $user_id, array &$facts): void
    {
        if (!$this->puede_ver_app('calendar', $user_id)) return;

        $eventos = EP_Graph_Service::get_instance()->get_today_events($user_id);
        if (is_wp_error($eventos)) return;

        if (empty($eventos)) {
            $facts[] = ['title' => '📅 Hoy', 'value' => 'Sin más reuniones'];
            return;
        }

        $n       = count($eventos);
        $primero = $eventos[0];
        // Graph devuelve la hora ya en Europe/Madrid (cabecera Prefer): "2026-09-04T10:00:00.0000000"
        $hora  = substr((string)($primero['start']['dateTime'] ?? ''), 11, 5);
        $texto = "{$hora} " . mb_substr((string)($primero['subject'] ?? 'Sin asunto'), 0, 30);
        if ($n > 1) $texto .= ' (+' . ($n - 1) . ' más)';

        $facts[] = ['title' => "📅 Hoy ({$n})", 'value' => $texto];
    }




    public function notificar_nueva_firma($user_id, $doc_id, $solicitante_id)
    {
        if (function_exists('ep_is_staging') && ep_is_staging()) {
            return;
        }

        global $wpdb;
        $doc_name = $wpdb->get_var($wpdb->prepare(
            "SELECT nombre_archivo_original FROM {$wpdb->prefix}fds_documentos WHERE id = %d",
            $doc_id
        ));
        $doc_name = $doc_name ?: 'documento';
        $solicitante = get_userdata($solicitante_id);
        $solicitante_name = $solicitante ? $solicitante->display_name : 'Un administrador';

        $titulo  = '✍️ Nueva solicitud de firma';
        $mensaje = "{$solicitante_name} te ha enviado el documento '{$doc_name}' para firmar.";
        $link    = home_url('/?view=signature&teams=true');

        // CANAL 1 (Principal): Sistema proactivo completo via Graph/Bot Framework
        // Funciona aunque el usuario nunca haya chateado con el bot.
        $sent_proactive = false;
        if (class_exists('EP_Teams_Bot') && get_user_meta($user_id, 'ep_o365_user_id', true)) {
            $sent_proactive = EP_Teams_Bot::send_proactive_message($user_id, $titulo, $mensaje, $link);
            ep_error_log("EP Bot Notif Firma: Canal proactivo para user $user_id → " . ($sent_proactive ? 'OK' : 'FAIL'));
        }

        // CANAL 2 (Fallback): Envío directo por conversation_id guardada en sesiones anteriores
        if (!$sent_proactive) {
            $service_url = get_user_meta($user_id, 'ep_bot_service_url', true);
            $conv_id     = get_user_meta($user_id, 'ep_bot_conversation_id', true);

            if ($service_url && $conv_id) {
                $tarjeta              = $this->tarjeta_simple($titulo, $mensaje, $link);
                $tarjeta['channelId'] = 'teams';
                $tarjeta['from_id']   = ep_get_option('ep_teams_bot_id');
                $this->enviar_respuesta($service_url, $conv_id, null, $tarjeta);
                ep_error_log("EP Bot Notif Firma: Fallback conversation_id para user $user_id enviado.");
            } else {
                ep_error_log("EP Bot Notif Firma: No se pudo notificar firma a user $user_id. Sin OID de Teams ni conversation_id guardada.");
            }
        }
    }

    /**
     * Notificación proactiva cuando un ticket cambia de estado o recibe respuesta.
     */
    public function notificar_cambio_ticket($user_id, $ticket_id, $nuevo_estado, $titulo_ticket)
    {
        if (function_exists('ep_is_staging') && ep_is_staging()) {
            return;
        }

        $service_url = get_user_meta($user_id, 'ep_bot_service_url', true);
        $conv_id = get_user_meta($user_id, 'ep_bot_conversation_id', true);
        
        if (!$service_url || !$conv_id) return;

        $msg = "Tu ticket '**{$titulo_ticket}**' (#{$ticket_id}) ha sido actualizado a estado: **{$nuevo_estado}**.";
        $tarjeta = $this->tarjeta_simple("🎫 Actualización de Ticket", $msg, home_url("/?view=tickets&ticket_id={$ticket_id}&teams=true"));
        
        $tarjeta['channelId'] = 'teams';
        $tarjeta['from_id']   = ep_get_option('ep_teams_bot_id');

        $this->enviar_respuesta($service_url, $conv_id, null, $tarjeta);
    }

    public function tarjeta_simple(string $titulo, string $texto, string $url): array
    {
        $acc = $url ? [['type' => 'Action.OpenUrl', 'title' => '🌐 Abrir Portal', 'url' => $url]] : [];
        return $this->adaptive_card([
            ['type' => 'TextBlock', 'text' => $titulo, 'weight' => 'Bolder', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $texto, 'wrap' => true],
        ], $acc);
    }

    public function adaptive_card(array $cuerpo, array $acciones = []): array
    {
        $card = ['type' => 'AdaptiveCard', 'version' => '1.4', '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'body' => $cuerpo];
        if (!empty($acciones)) $card['actions'] = $acciones;
        return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]]];
    }

    /**
     * Dominios oficiales a los que el bot puede responder. Cualquier otro destino
     * significa que el serviceUrl del payload ha sido manipulado.
     */
    public static function validar_service_url($service_url)
    {
        $host = parse_url((string) $service_url, PHP_URL_HOST);
        $scheme = parse_url((string) $service_url, PHP_URL_SCHEME);

        if (!$host || strtolower((string) $scheme) !== 'https') {
            return false;
        }

        $host = strtolower($host);
        $dominios_permitidos = array(
            'botframework.com',
            'trafficmanager.net',
            'skype.com',
            'microsoft.com',
        );

        foreach ($dominios_permitidos as $dominio) {
            if ($host === $dominio || substr($host, -(strlen($dominio) + 1)) === '.' . $dominio) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica la firma de un token emitido por el Bot Framework contra las claves
     * públicas publicadas por Microsoft (JWKS), además del emisor, la audiencia
     * (el id de aplicación del bot) y la caducidad.
     */
    private function validar_token_microsoft($jwt)
    {
        $partes = explode('.', (string) $jwt);
        if (count($partes) !== 3) {
            return false;
        }

        $decode = function ($b64) {
            $b64 = strtr($b64, '-_', '+/');
            $pad = strlen($b64) % 4;
            if ($pad) {
                $b64 .= str_repeat('=', 4 - $pad);
            }
            return base64_decode($b64);
        };

        $cabecera = json_decode($decode($partes[0]), true);
        $carga    = json_decode($decode($partes[1]), true);
        $firma    = $decode($partes[2]);

        if (!is_array($cabecera) || !is_array($carga) || empty($cabecera['kid'])) {
            return false;
        }

        // Emisor, audiencia y ventana temporal.
        //
        // Un bot multiinquilino recibe tokens firmados por Bot Framework. Uno de
        // inquilino único los recibe firmados por el propio tenant, con otro
        // emisor y otras claves. Azure ya no deja crear bots multiinquilino
        // ("Multitenant bot creation is deprecated"), así que se aceptan las dos
        // formas: si no, al pasar el bot a inquilino único todo entrante daría 401.
        $emisores = array(
            'https://api.botframework.com',
            'https://api.botframework.com/',
            'https://api.botframework.us',
            'https://api.botframework.us/'
        );
        $tenant_id = ep_get_option('ep_o365_tenant_id');
        if (!empty($tenant_id)) {
            $emisores[] = 'https://login.microsoftonline.com/' . $tenant_id . '/v2.0';
            $emisores[] = 'https://login.microsoftonline.com/' . $tenant_id . '/v2.0/';
            $emisores[] = 'https://sts.windows.net/' . $tenant_id . '/';
            $emisores[] = 'https://sts.windows.net/' . $tenant_id;
        }
        if (empty($carga['iss']) || !in_array($carga['iss'], $emisores, true)) {
            ep_error_log('EP Bot Auth: Rechazado por emisor no reconocido: ' . ($carga['iss'] ?? 'vacio'));
            return false;
        }

        // Aceptar como audiencia válida tanto el Bot ID como el Client ID de la app de Teams
        $valid_auds = array_filter([
            preg_replace('/^28:/', '', (string) ep_get_option('ep_teams_bot_id')),
            preg_replace('/^28:/', '', (string) ep_get_option('ep_o365_client_id')),
            'dfcd7250-abfd-4689-8ad4-a5163898de14',
            '954318d3-5750-426e-b365-74ea5c53fcf1'
        ]);
        if (!empty($valid_auds) && (empty($carga['aud']) || !in_array($carga['aud'], $valid_auds, true))) {
            ep_error_log('EP Bot Auth: Rechazado por aud mismatch. Recibido: ' . ($carga['aud'] ?? 'vacio') . ' | Esperado uno de: ' . implode(', ', $valid_auds));
            return false;
        }

        $ahora = time();
        if (!empty($carga['exp']) && $ahora > ((int) $carga['exp'] + 300)) {
            ep_error_log('EP Bot Auth: Rechazado por token expirado (exp: ' . ($carga['exp'] ?? 0) . ', ahora: ' . $ahora . ')');
            return false;
        }
        if (!empty($carga['nbf']) && $ahora < ((int) $carga['nbf'] - 300)) {
            ep_error_log('EP Bot Auth: Rechazado por token no activo aun (nbf: ' . ($carga['nbf'] ?? 0) . ', ahora: ' . $ahora . ')');
            return false;
        }

        $clave_pem = $this->obtener_clave_publica($cabecera['kid']);
        if (!$clave_pem) {
            ep_error_log('EP Bot Auth: No se pudo obtener clave publica para kid: ' . ($cabecera['kid'] ?? 'vacio'));
            return false;
        }

        $algoritmo = (isset($cabecera['alg']) && $cabecera['alg'] === 'RS512') ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256;
        $verificado = openssl_verify($partes[0] . '.' . $partes[1], $firma, $clave_pem, $algoritmo);

        if ($verificado !== 1) {
            ep_error_log('EP Bot Auth: Firma de token invalida para kid: ' . ($cabecera['kid'] ?? 'vacio'));
        }

        return ($verificado === 1);
    }

    /**
     * Devuelve en formato PEM la clave pública del Bot Framework indicada por 'kid'.
     * El juego de claves se cachea 12 horas.
     */
    private function obtener_clave_publica($kid)
    {
        // Dos juegos de claves posibles, en el mismo orden que los emisores
        // aceptados: el de Bot Framework (bot multiinquilino) y el del propio
        // tenant (bot de inquilino unico).
        $fuentes = array('https://login.botframework.com/v1/.well-known/openidconfiguration');
        $tenant_id = ep_get_option('ep_o365_tenant_id');
        if (!empty($tenant_id)) {
            $fuentes[] = 'https://login.microsoftonline.com/' . rawurlencode($tenant_id) . '/v2.0/.well-known/openid-configuration';
        }

        foreach ($fuentes as $config_url) {
            $cache_key = 'ep_bot_jwks_' . substr(md5($config_url), 0, 12);
            $claves = get_transient($cache_key);

            if (!is_array($claves) || !isset($claves[$kid])) {
                $claves = $this->descargar_jwks($config_url);
                if (!is_array($claves)) {
                    continue;
                }
                set_transient($cache_key, $claves, 12 * HOUR_IN_SECONDS);
            }

            if (empty($claves[$kid])) {
                continue;
            }

            $certificado = "-----BEGIN CERTIFICATE-----\n" . chunk_split($claves[$kid], 64, "\n") . "-----END CERTIFICATE-----\n";
            $publica = openssl_pkey_get_public($certificado);
            if ($publica) {
                return $publica;
            }
        }

        return false;
    }

    /**
     * Descarga un juego de claves públicas a partir de su documento de
     * descubrimiento OpenID. Devuelve kid => certificado, o false si falla.
     */
    private function descargar_jwks($config_url)
    {
        $config = wp_remote_get($config_url, array('timeout' => 15));
        if (is_wp_error($config)) {
            return false;
        }
        $config = json_decode(wp_remote_retrieve_body($config), true);
        if (empty($config['jwks_uri'])) {
            return false;
        }

        $jwks = wp_remote_get($config['jwks_uri'], array('timeout' => 15));
        if (is_wp_error($jwks)) {
            return false;
        }
        $jwks = json_decode(wp_remote_retrieve_body($jwks), true);
        if (empty($jwks['keys'])) {
            return false;
        }

        $claves = array();
        foreach ($jwks['keys'] as $clave) {
            if (!empty($clave['kid']) && !empty($clave['x5c'][0])) {
                $claves[$clave['kid']] = $clave['x5c'][0];
            }
        }

        return $claves;
    }

    private function enviar_respuesta(string $service_url, string $conversation_id, ?string $reply_to_id, array $actividad): bool
    {
        $token = $this->obtener_token_bot();
        if (!$token) return false;

        $channel_id = $actividad['channelId'] ?? 'teams';
        $from_id    = $actividad['from_id'] ?? ep_get_option('ep_teams_bot_id');

        // Solo añadimos el prefijo 28: si es Teams y el ID no lo tiene ya
        if (($channel_id === 'teams' || $channel_id === 'msteams') && strpos($from_id, '28:') !== 0) {
            $from_id = "28:" . $from_id;
        }

        $actividad['from'] = ['id' => $from_id, 'name' => 'Portal Empleado Bot'];
        if ($reply_to_id) $actividad['replyToId'] = $reply_to_id;
        
        // El serviceUrl llega dentro del payload de la petición. Sin esta validación,
        // una petición falsificada podía apuntarlo a un servidor cualquiera y recibir
        // el token del bot en la cabecera Authorization.
        if (!self::validar_service_url($service_url)) {
            ep_error_log('EP Bot SECURITY ALERT: serviceUrl no permitido, envío abortado: ' . $service_url, true);
            return false;
        }

        $url = rtrim($service_url, '/') . '/v3/conversations/' . rawurlencode($conversation_id) . '/activities';

        ep_error_log("EP Bot: Enviando respuesta a $url (Canal: $channel_id)");
        $res = wp_remote_post($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($actividad),
            'timeout' => 20,
        ]);

        if (is_wp_error($res)) {
            ep_error_log('EP Bot Send Error: ' . $res->get_error_message(), true);
            return false;
        }

        $code  = (int)wp_remote_retrieve_response_code($res);
        $body  = wp_remote_retrieve_body($res);
        $ok    = ($code >= 200 && $code < 300);
        ep_error_log("EP Bot Send Status [$code]: $body", !$ok);
        return $ok;
    }

    private function obtener_token_bot(): ?string
    {
        $start_time = microtime(true);
        $bot_id     = ep_get_option('ep_teams_bot_id');
        $bot_secret = ep_get_option('ep_teams_bot_secret');
        $tenant_id  = ep_get_option('ep_o365_tenant_id') ?: 'botframework.com';

        // El bot_id debe ser el GUID puro, sin el prefijo "28:" que usa Teams internamente
        $bot_id = preg_replace('/^28:/', '', $bot_id);

        if (empty($bot_id) || empty($bot_secret)) {
            ep_error_log("EP Bot Auth: CRÍTICO - Credenciales de bot no configuradas (ep_teams_bot_id o ep_teams_bot_secret vacíos).", true);
            return null;
        }

        if (class_exists('EP_Security') && EP_Security::is_encrypted($bot_secret)) {
            $bot_secret = EP_Security::decrypt($bot_secret);
        }

        $tk_key = "ep_bt_token_v3_" . md5($bot_id);
        $cached = get_transient($tk_key);
        if ($cached) {
            return $cached;
        }

        // Priorizamos el tenant de la organización si está configurado
        $tenants = ($tenant_id !== 'botframework.com') ? [$tenant_id, 'botframework.com'] : ['botframework.com'];

        foreach ($tenants as $tenant) {
            ep_error_log("EP Bot Auth: Solicitando token via $tenant...");
            $response = wp_remote_post("https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", [
                'body' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $bot_id,
                    'client_secret' => $bot_secret,
                    'scope'         => 'https://api.botframework.com/.default'
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                ep_error_log("EP Bot Auth Error: " . $response->get_error_message());
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body_str = wp_remote_retrieve_body($response);
            $body = json_decode($body_str, true);

            if ($code === 200 && !empty($body['access_token'])) {
                $elapsed = round(microtime(true) - $start_time, 2);
                ep_error_log("EP Bot Auth: Token obtenido en {$elapsed}s via $tenant.");
                set_transient($tk_key, $body['access_token'], ($body['expires_in'] ?? 3600) - 300);
                return $body['access_token'];
            }

            // Log del error exacto de Azure para diagnóstico
            $azure_error = $body['error'] ?? 'unknown';
            $azure_desc  = $body['error_description'] ?? $body_str;
            ep_error_log("EP Bot Auth FAIL [$tenant] HTTP $code: $azure_error - " . mb_substr($azure_desc, 0, 300), true);
        }

        ep_error_log("EP Bot Auth: FALLO FINAL al obtener token. Bot ID: $bot_id | Secret length: " . strlen($bot_secret), true);
        return null;
    }

    private function guardar_referencia_conversacion(int $user_id, string $service_url, string $conversation_id): void
    {
        update_user_meta($user_id, 'ep_bot_service_url', $service_url);
        update_user_meta($user_id, 'ep_bot_conversation_id', $conversation_id);
    }

    private function buscar_usuario_por_oid(?string $oid, array $from_data = []): ?\WP_User
    {
        if (!empty($oid)) {
            $usuarios = get_users(['meta_key' => 'ep_o365_user_id', 'meta_value' => $oid, 'number' => 1]);
            if (!empty($usuarios)) {
                return $usuarios[0];
            }
        }

        // Fallback 1: Buscar por UPN o email si viene en el payload de Teams
        $upn = $from_data['userPrincipalName'] ?? $from_data['email'] ?? null;
        if (!empty($upn)) {
            $u = get_user_by('email', $upn);
            if ($u) {
                ep_error_log("EP Bot: Usuario localizado por email/UPN: {$upn}");
                return $u;
            }
        }

        // Fallback 2: Si el campo name contiene un email
        $name = $from_data['name'] ?? '';
        if (!empty($name) && is_email($name)) {
            $u = get_user_by('email', $name);
            if ($u) {
                ep_error_log("EP Bot: Usuario localizado por email en name: {$name}");
                return $u;
            }
        }

        return null;
    }

    private function contiene(string $texto, array $palabras): bool
    {
        $texto_limpio = $this->sin_tildes($texto);
        foreach ($palabras as $p) {
            if (mb_strpos($texto_limpio, $this->sin_tildes($p)) !== false) return true;
        }
        return false;
    }

    private function sin_tildes(string $t): string
    {
        return str_replace(['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'], ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'], $t);
    }

    /**
     * Motor central del bot: Clasifica con IA y ejecuta la herramienta correspondiente.
     */
    private function resolver_con_ia(string $texto, $wp_user, string $conversation_id = ''): array
    {
        $user_id = $wp_user->ID;
        $nombre  = $wp_user->display_name ?: $wp_user->user_login;
        
        // Obtener permisos para el prompt de la IA
        $apps = ['tickets', 'inventory', 'signature', 'calendar', 'directory', 'avisos'];
        $permissions = [];
        foreach ($apps as $app) {
            if ($this->puede_ver_app($app, $user_id)) $permissions[] = $app;
        }

        $ai = EP_AI_Service::get_instance();
        $user_context = [
            'user_id'      => $user_id,  // FIX: necesario para que EP_Bot_Context inyecte el historial de conversación
            'display_name' => $nombre,
            'role'         => $wp_user->roles ? current($wp_user->roles) : 'empleado',
            'permissions'  => $permissions
        ];

        $intent_data = $ai->get_intent($texto, $user_context);
        
        // Safety check for surgical robustness
        if (empty($intent_data) || !is_array($intent_data)) {
            ep_error_log("EP Bot: Error crítico - La IA devolvió un resultado no válido (null o no-array).");
            return $this->tarjeta_simple('🤖 Asistente', "Lo siento, no he podido procesar tu mensaje. Inténtalo de nuevo más tarde.", '');
        }
        
        if (isset($intent_data['error'])) {
            return $this->tarjeta_simple('🤖 Asistente', "Lo siento, tengo problemas para procesar tu solicitud ahora mismo: " . $intent_data['error'], '');
        }

        $intent = $intent_data['intent'] ?? 'UNKNOWN';
        $params = $intent_data['params'] ?? [];

        // --- Autoaprendizaje: registrar si el bot no entiende o tiene baja confianza ---
        $confidence = floatval($intent_data['confidence'] ?? 1.0);
        if ($intent === 'UNKNOWN' || $confidence < 0.6) {
            EP_AI_Service::log_uncertain_intent(
                $texto,
                $intent,
                $confidence,
                $wp_user->roles ? current($wp_user->roles) : 'empleado'
            );
        }

        $app_map = [
            'TICKETS'       => 'tickets',
            'INVENTORY'     => 'inventory',
            'SIGNATURE'     => 'signature',
            'DIRECTORY'     => 'directory',
            'NOTIFICATIONS' => 'avisos'
        ];

        if (isset($app_map[$intent]) && !$this->puede_ver_app($app_map[$intent], $user_id)) {
            return $this->tarjeta_simple('🚫 Acceso Denegado', "Lo siento, no tienes permisos configurados para acceder a esta sección del portal.", '');
        }

        switch ($intent) {
            
            case 'TASKS':
                return $this->tarjeta_tareas($user_id, $nombre);
                
            case 'NOTIFICATIONS':
                return $this->tarjeta_notificaciones($user_id, $nombre);
                
            case 'DASHBOARD':
                return $this->tarjeta_resumen($user_id, $nombre);

            case 'CONVERSATIONAL':
                if (!empty($intent_data['suggested_reply'])) {
                    if ($conversation_id) {
                        EP_Bot_Context::set_context($user_id, [
                            'intent' => 'CONVERSATIONAL',
                            'params' => ['search_term' => mb_substr($texto, 0, 50)],
                            'results' => []
                        ]);
                    }
                    return $this->tarjeta_simple('🤖 Asistente', $intent_data['suggested_reply'], '');
                }
                break;

            default:
                // Hook dinámico para intents registrados por otras Apps (Fase 2)
                $dynamic_response = apply_filters('ep_bot_handle_intent_' . strtolower($intent), null, $intent_data, $user_id, $texto, $this);
                if ($dynamic_response !== null) {
                    if ($conversation_id) {
                        EP_Bot_Context::set_context($user_id, [
                            'intent' => $intent,
                            'params' => $params,
                            'results' => $dynamic_response['_meta_data'] ?? []
                        ]);
                    }
                    return $dynamic_response;
                }
                // Fallthrough natural a UNKNOWN

            case 'UNKNOWN':
                if (!empty($intent_data['suggested_reply'])) {
                    if ($conversation_id) {
                        EP_Bot_Context::set_context($user_id, [
                            'intent' => 'CONVERSATIONAL',
                            'params' => ['search_term' => mb_substr($texto, 0, 50)],
                            'results' => []
                        ]);
                    }
                    return $this->tarjeta_simple('🤖 Asistente', $intent_data['suggested_reply'], '');
                }
                break;
        }

        return $this->tarjeta_simple('❓ No entendí bien', "No estoy seguro de cómo ayudarte con eso. Escribe **ayuda** para ver mis capacidades.", '');
    }

    /**
     * Tarjeta de Bienvenida personalizada con resumen inteligente del día.
     */
    private function tarjeta_bienvenida(int $user_id, string $nombre, string $subtitulo = 'Este es tu resumen inteligente para hoy:'): array
    {
        // Hora de Madrid, no la del servidor (WordPress corre en UTC): sin esto
        // el bot daba los "buenos días" hasta las 16:00.
        $hora = (int)(new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('G');
        $saludo = "Hola";
        if ($hora >= 6 && $hora < 14) $saludo = "Buenos días";
        elseif ($hora >= 14 && $hora < 21) $saludo = "Buenas tardes";
        else $saludo = "Buenas noches";

        $cuerpo = [
            ['type' => 'TextBlock', 'text' => "{$saludo}, {$nombre} 👋", 'weight' => 'Bolder', 'size' => 'Large', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $subtitulo, 'isSubtle' => true, 'spacing' => 'None', 'wrap' => true]
        ];

        $facts = [];
        $graph = EP_Graph_Service::get_instance();
        
        // 1. Firmas Pendientes (Directo DB para velocidad)
        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $num_firmas = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE usuario_id = %d AND estado = 'pendiente'", $user_id));
        if ($num_firmas > 0) {
            $facts[] = ['title' => '✍️ Firmas pendientes', 'value' => (string)$num_firmas];
        }

        // 2. Agenda de HOY (la próxima cita de otro día se pide con el botón)
        $this->anadir_fact_agenda_hoy($user_id, $facts);

        // 3. Tareas Pendientes (To-Do)
        $tasks = $graph->get_my_tasks($user_id);
        if (!is_wp_error($tasks) && !empty($tasks)) {
            $facts[] = ['title' => '✅ Tareas To-Do', 'value' => (string)count($tasks) . " pendientes"];
        }

        // 4. Tickets (Gestión)
        if ($this->puede_ver_app('tickets', $user_id) && class_exists('EP_Tickets')) {
            $num_t = count(EP_Tickets::get_manageable_tickets_for_user($user_id));
            if ($num_t > 0) $facts[] = ['title' => '🎫 Tickets gestión', 'value' => (string)$num_t];
        }

        if (!empty($facts)) {
            $cuerpo[] = ['type' => 'FactSet', 'facts' => $facts, 'spacing' => 'Medium'];
        } else {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => '✅ ¡Todo al día! No tienes tareas urgentes registradas.', 'spacing' => 'Medium'];
        }

        $btns = [
             ['type' => 'Action.OpenUrl', 'title' => '🌐 Ir al Portal', 'url' => home_url('/?teams=true')],
             ['type' => 'Action.Submit', 'title' => '📅 Próxima cita', 'data' => ['m' => 'cuál es mi próxima reunión']],
             ['type' => 'Action.Submit', 'title' => '❓ Ver Ayuda', 'data' => ['m' => 'ayuda']],
             ['type' => 'Action.Submit', 'title' => '🔍 Buscar Personas', 'data' => ['m' => 'directorio']]
        ];

        return $this->adaptive_card($cuerpo, $btns);
    }

    /**
     * Tarjeta para visualizar tareas de Microsoft To Do.
     */
    private function tarjeta_tareas(int $user_id, string $nombre): array
    {
        $graph = EP_Graph_Service::get_instance();
        $tasks = $graph->get_my_tasks($user_id);

        if (is_wp_error($tasks)) {
            return $this->tarjeta_simple("✅ Tus Tareas", "No he podido conectar con tus tareas de Microsoft To-Do.", home_url('/?teams=true'));
        }

        if (empty($tasks)) {
            return $this->tarjeta_simple("✅ Tus Tareas", "¡Enhorabuena, {$nombre}! No tienes tareas pendientes en tu lista principal.", "https://to-do.microsoft.com/");
        }

        $facts = [];
        $count = 0;
        foreach ($tasks as $task) {
            $facts[] = ['title' => "🔘", 'value' => mb_substr($task['title'], 0, 50)];
            if (++$count >= 10) break;
        }

        return $this->adaptive_card([
            ['type' => 'TextBlock', 'text' => "✅ Tus tareas pendientes", 'weight' => 'Bolder', 'size' => 'Medium', 'color' => 'Accent'],
            ['type' => 'FactSet', 'facts' => $facts]
        ], [['type' => 'Action.OpenUrl', 'title' => '📝 Abrir Microsoft To-Do', 'url' => 'https://to-do.microsoft.com/']]);
    }

    /**
     * Traduce los estados de presencia de MS Graph a español.
     */
    private function traducir_presencia(string $status): string
    {
        $map = [
            'Available'        => 'Disponible',
            'AvailableIdle'    => 'Disponible (Inactivo)',
            'Away'             => 'Ausente',
            'BeRightBack'      => 'Vuelvo enseguida',
            'Busy'             => 'Ocupado',
            'BusyIdle'         => 'Ocupado (Inactivo)',
            'DoNotDisturb'     => 'No molestar',
            'Offline'          => 'Desconectado',
            'PresenceUnknown'  => 'Desconocido'
        ];
        return $map[$status] ?? $status;
    }


}
