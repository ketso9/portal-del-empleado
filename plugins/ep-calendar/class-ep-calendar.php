<?php
defined('ABSPATH') || exit;

class EP_App_Calendar implements EP_App_Interface
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX Endpoints
        add_action('wp_ajax_ep_get_calendar_events', array($this, 'ajax_get_calendar_events'));
        add_action('wp_ajax_ep_get_calendars', array($this, 'ajax_get_calendars'));
        add_action('wp_ajax_ep_save_calendar_prefs', array($this, 'ajax_save_calendar_prefs'));
        add_action('wp_ajax_ep_search_users', array($this, 'ajax_search_users'));
        add_action('wp_ajax_ep_add_shared_calendar', array($this, 'ajax_add_shared_calendar'));
        add_action('wp_ajax_ep_save_calendar_color', array($this, 'ajax_save_calendar_color')); // NEW
        add_action('wp_ajax_ep_get_dashboard_events', array($this, 'ajax_get_dashboard_events')); // DASHBOARD SUPPORT

        // --- Integración con IA Bot ---
        add_filter('ep_bot_intents', array($this, 'registrar_intents_bot'));
        add_filter('ep_bot_handle_intent_agenda', array($this, 'responder_intent_agenda'), 10, 5);
        add_filter('ep_bot_handle_intent_meeting_planner', array($this, 'responder_intent_meeting_planner'), 10, 5);
    }

    // --- EP_App_Interface Implementation ---

    public function get_id()
    {
        return 'calendar';
    }

    public function get_name()
    {
        return 'Agenda';
    }

    public function get_icon()
    {
        return 'fa-solid fa-calendar-days';
    }

    public function get_menu_label()
    {
        return 'Agenda';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=calendar'">
            <div class="app-icon-container color-blue">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <h3>Mi Agenda</h3>
            <p>Eventos y reuniones</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_CALENDAR_PATH . 'partials/calendar-app.php';
    }

    public function handle_ajax()
    {
        // Not used, we use wp_ajax hooks in constructor
    }

    // --- Assets ---

    public function enqueue_assets()
    {
        if (isset($_GET['view']) && $_GET['view'] === 'calendar') {
            wp_enqueue_style('ep-calendar-css', EP_CALENDAR_URL . 'assets/css/calendar.css', array(), '1.0.2');
            wp_enqueue_script('ep-calendar-js', EP_CALENDAR_URL . 'assets/js/calendar.js', array(), '1.0.2', true);
        }
    }

    // --- AJAX HANDLERS ---

    public function ajax_save_calendar_color()
    {
        check_ajax_referer('ep_calendar_nonce', 'nonce');
        $id = sanitize_text_field($_POST['id']);
        $color = sanitize_hex_color($_POST['color']);

        if (!$id || !$color) {
            wp_send_json_error('Datos inválidos');
        }

        $user_id = get_current_user_id();
        $custom_colors = get_user_meta($user_id, 'ep_calendar_custom_colors', true);
        if (!is_array($custom_colors)) {
            $custom_colors = array();
        }

        $custom_colors[$id] = $color;

        update_user_meta($user_id, 'ep_calendar_custom_colors', $custom_colors);
        wp_send_json_success('Color guardado');
    }

    public function ajax_add_shared_calendar()
    {
        check_ajax_referer('ep_calendar_nonce', 'nonce');
        $id = sanitize_text_field($_POST['id']);
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);

        if (!$id || !$name) {
            wp_send_json_error('Datos inválidos');
        }

        $user_id = get_current_user_id();
        $added = get_user_meta($user_id, 'ep_calendar_added_users', true);
        if (!is_array($added)) {
            $added = array();
        }

        // Prevent duplicates
        foreach ($added as $u) {
            if ($u['id'] === $id) {
                wp_send_json_success('Ya estaba añadido');
            }
        }

        $added[] = array(
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'type' => 'shared'
        );

        update_user_meta($user_id, 'ep_calendar_added_users', $added);
        wp_send_json_success('Calendario añadido');
    }

    public function ajax_get_calendars()
    {
        check_ajax_referer('ep_calendar_nonce', 'nonce');
        $user_id = get_current_user_id();
        $access_token = EP_Auth_O365::get_valid_token($user_id);

        if (is_wp_error($access_token)) {
            wp_send_json_error(array('message' => $access_token->get_error_message(), 'code' => 'auth_error'));
        }

        // Get saved prefs (selected checkboxes)
        $saved_prefs = get_user_meta($user_id, 'ep_calendar_prefs', true);
        if (!is_array($saved_prefs)) {
            $saved_prefs = array();
        }

        // Get Custom Colors
        $custom_colors = get_user_meta($user_id, 'ep_calendar_custom_colors', true);
        if (!is_array($custom_colors)) {
            $custom_colors = array();
        }

        // 1. Fetch My Calendars from Graph
        // Simplify query to avoid $select issues and debug the response
        $url = 'https://graph.microsoft.com/v1.0/me/calendars';
        $response = wp_remote_get($url, array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token)
        ));

        // Log for developers if needed
        ep_error_log("EP_Calendar: Fetching calendars for user $user_id. Status: " . (is_wp_error($response) ? 'Error' : wp_remote_retrieve_response_code($response)));

        $calendars = array();
        $debug_info = array('url' => $url);

        if (is_wp_error($response)) {
            $debug_info['error'] = $response->get_error_message();
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body_raw = wp_remote_retrieve_body($response);
            $debug_info['status_code'] = $status_code;

            if ($status_code !== 200) {
                ep_error_log("EP_Calendar: API Error for user $user_id (Status $status_code): " . $body_raw);
                $debug_info['api_error'] = $body_raw;
            }

            $body = json_decode($body_raw, true);
            if (isset($body['value'])) {
                ep_error_log("EP_Calendar: Found " . count($body['value']) . " calendars for user $user_id.");
                foreach ($body['value'] as $cal) {
                    $is_selected = in_array($cal['id'], $saved_prefs);
                    // Fallback logic
                    if (empty($saved_prefs) && isset($cal['isDefaultCalendar']) && $cal['isDefaultCalendar']) {
                        $is_selected = true;
                    }

                    // Check Custom Color
                    $final_color = isset($custom_colors[$cal['id']]) ? $custom_colors[$cal['id']] : null;

                    $calendars[] = array(
                        'id' => $cal['id'],
                        'name' => $cal['name'],
                        'color' => $cal['color'],
                        'customColor' => $final_color, // New Field
                        'isDefault' => $cal['isDefaultCalendar'] ?? false,
                        'selected' => $is_selected,
                        'type' => 'personal'
                    );
                }
            }
        }

        // 2. Merge Shared Calendars (Added Users)
        $added_users = get_user_meta($user_id, 'ep_calendar_added_users', true);
        if (is_array($added_users)) {
            foreach ($added_users as $u) {
                // Use a prefix to distinguish user IDs from calendar IDs
                $user_cal_id = 'user::' . $u['id'];

                $is_selected = in_array($user_cal_id, $saved_prefs);
                $final_color = isset($custom_colors[$user_cal_id]) ? $custom_colors[$user_cal_id] : null;

                $calendars[] = array(
                    'id' => $user_cal_id,
                    'name' => $u['name'] . ' (Compartido)',
                    'color' => 'lightGray',
                    'customColor' => $final_color,
                    'selected' => $is_selected,
                    'type' => 'shared_user',
                    'real_user_id' => $u['id']
                );
            }
        }

        wp_send_json_success(array('calendars' => $calendars, 'debug' => $debug_info));
    }

    public function ajax_save_calendar_prefs()
    {
        check_ajax_referer('ep_calendar_nonce', 'nonce');
        $calendars = isset($_POST['calendars']) ? (array) $_POST['calendars'] : array();

        // Sanitize
        $clean_calendars = array_map('sanitize_text_field', $calendars);

        update_user_meta(get_current_user_id(), 'ep_calendar_prefs', $clean_calendars);
        wp_send_json_success('Preferencias guardadas.');
    }

    public function ajax_search_users()
    {
        check_ajax_referer('ep_calendar_nonce', 'nonce');
        $query = sanitize_text_field($_POST['query']);

        if (strlen($query) < 3) {
            wp_send_json_error('Escribe al menos 3 caracteres.');
        }

        $user_id = get_current_user_id();
        $access_token = EP_Auth_O365::get_valid_token($user_id);

        if (is_wp_error($access_token)) {
            wp_send_json_error($access_token->get_error_message());
        }

        // Search Users in Graph using simplified URL construction
        $url = 'https://graph.microsoft.com/v1.0/users?$search="displayName:' . $query . '"&$select=displayName,id,userPrincipalName,mail';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'ConsistencyLevel' => 'eventual'
            )
        ));

        if (is_wp_error($response)) {
            ep_error_log('EP_Calendar Search Error (WP_Error): ' . $response->get_error_message());
            wp_send_json_error($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($status_code !== 200) {
            ep_error_log("EP_Calendar Search Error ($status_code): " . $body_raw);
            $msg = $body['error']['message'] ?? 'Error fetching users from Microsoft Graph';
            wp_send_json_error(array('message' => $msg, 'status' => $status_code, 'details' => $body));
        }
        $users = array();

        if (isset($body['value'])) {
            foreach ($body['value'] as $u) {
                $users[] = array(
                    'id' => $u['id'],
                    'name' => $u['displayName'],
                    'email' => $u['mail'] ?? $u['userPrincipalName']
                );
            }
        }

        wp_send_json_success($users);
    }

    public function ajax_get_calendar_events()
    {
        try {
            check_ajax_referer('ep_calendar_nonce', 'nonce');

            $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';
            $end = isset($_POST['end']) ? sanitize_text_field($_POST['end']) : '';

            if (empty($start) || empty($end)) {
                wp_send_json_success(array('events' => [], 'debug' => ['error' => 'Missing dates']));
            }

            $user_id = get_current_user_id();
            $access_token = EP_Auth_O365::get_valid_token($user_id);

            if (is_wp_error($access_token)) {
                wp_send_json_error(array('message' => $access_token->get_error_message(), 'code' => 'auth_error'));
            }

            $prefs = get_user_meta($user_id, 'ep_calendar_prefs', true);
            $events = array();

            if (!empty($prefs) && is_array($prefs)) {
                $events = $this->get_multi_calendar_events($access_token, $start, $end, $prefs, $user_id);
            } else {
                // Fallback: Default Calendar
                $cal_list_url = 'https://graph.microsoft.com/v1.0/me/calendars?$select=id,isDefaultCalendar';

                $cal_list_resp = wp_remote_get($cal_list_url, array(
                    'headers' => array('Authorization' => 'Bearer ' . $access_token)
                ));

                $cal_id_to_use = null;
                if (!is_wp_error($cal_list_resp)) {
                    $body = json_decode(wp_remote_retrieve_body($cal_list_resp), true);
                    if (isset($body['value'])) {
                        foreach ($body['value'] as $cal) {
                            if (isset($cal['isDefaultCalendar']) && $cal['isDefaultCalendar']) {
                                $cal_id_to_use = $cal['id'];
                                break;
                            }
                        }
                        if (!$cal_id_to_use && count($body['value']) > 0) {
                            $cal_id_to_use = $body['value'][0]['id'];
                        }
                    }
                }

                $events = $this->get_graph_events($access_token, $start, $end, $cal_id_to_use, $user_id);
            }

            if (is_wp_error($events)) {
                wp_send_json_success(array('events' => [], 'debug' => ['error' => $events->get_error_message()]));
            }

            wp_send_json_success($events);

        } catch (Throwable $e) {
            wp_send_json_error('Server Error: ' . $e->getMessage());
        }
    }

    /**
     * Optimized fetch for Dashboard hover summary
     */
    public function ajax_get_dashboard_events()
    {
        try {
            // Using global portal nonce for simplicity
            check_ajax_referer('ep_ajax_nonce', 'nonce');

            $user_id = get_current_user_id();
            $access_token = EP_Auth_O365::get_valid_token($user_id);

            if (is_wp_error($access_token)) {
                wp_send_json_error('Auth error');
            }

            // Fetch events for the current month
            $start = date('Y-m-01');
            $end = date('Y-m-t');

            $prefs = get_user_meta($user_id, 'ep_calendar_prefs', true);
            $events = array();

            if (!empty($prefs) && is_array($prefs)) {
                $result = $this->get_multi_calendar_events($access_token, $start, $end, $prefs, $user_id);
                $events = $result['events'] ?? [];
            } else {
                $result = $this->get_graph_events($access_token, $start, $end, null, $user_id);
                $events = $result['events'] ?? [];
            }

            wp_send_json_success(array('events' => $events));

        } catch (Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function get_multi_calendar_events($access_token, $start, $end, $calendar_ids, $user_id)
    {
        $all_events = array();
        $debug_log = array();

        $calendar_ids = array_slice($calendar_ids, 0, 8); // Allow up to 8

        foreach ($calendar_ids as $cal_id) {
            $result = $this->get_graph_events($access_token, $start, $end, $cal_id, $user_id);
            $debug_log[$cal_id] = isset($result['debug']) ? $result['debug'] : 'no_debug_info';

            if (!is_wp_error($result) && isset($result['events'])) {
                $all_events = array_merge($all_events, $result['events']);
            }
        }

        return array(
            'events' => $all_events,
            'debug' => array('multi' => true, 'details' => $debug_log)
        );
    }

    private function get_graph_events($access_token, $start, $end, $calendar_id = null, $user_id = 0)
    {
        try {
            $start_dt = new DateTime($start);
            $end_dt = new DateTime($end);
            $start_iso = $start_dt->format('Y-m-d\TH:i:s\Z');
            $end_iso = $end_dt->format('Y-m-d\TH:i:s\Z');

            $url = '';

            // Build URL safely with concatenation to avoid double-quote interpolation issues
            if ($calendar_id && strpos((string) $calendar_id, 'user::') === 0) {
                $real_user_id = substr($calendar_id, 6);
                $url = 'https://graph.microsoft.com/v1.0/users/' . $real_user_id . '/calendar/calendarView';
            } elseif ($calendar_id) {
                $url = 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode($calendar_id) . '/calendarView';
            } else {
                $url = 'https://graph.microsoft.com/v1.0/me/calendarView';
            }

            // Append query params safely
            $url .= '?startDateTime=' . $start_iso . '&endDateTime=' . $end_iso . '&$top=999';
            $url .= '&$select=subject,start,end,location,bodyPreview,id,isAllDay,showAs';

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Prefer' => 'outlook.timezone="Europe/Madrid"'
                ),
                'timeout' => 45
            ));

            if (is_wp_error($response)) {
                error_log("EP_Calendar: Request failed for user $user_id: " . $response->get_error_message());
                return array('events' => [], 'debug' => ['error' => $response->get_error_message(), 'url' => $url]);
            }

            $body_raw = wp_remote_retrieve_body($response);
            $status_code = wp_remote_retrieve_response_code($response); // Can return int or empty string

            if ($status_code == 401) {
                error_log("EP_Calendar: 401 Unauthorized for user $user_id");
                return array('events' => [], 'debug' => ['error' => 'Token Expired (401)']);
            }

            $body = json_decode($body_raw, true);

            if (isset($body['error'])) {
                error_log("EP_Calendar: Graph Error for user $user_id: " . $body_raw);
                return array('events' => [], 'debug' => ['api_error' => $body['error'], 'url' => $url]);
            }

            $formatted_events = array();
            if (isset($body['value'])) {
                error_log("EP_Calendar: Found " . count($body['value']) . " events for user $user_id in calendar $calendar_id.");
                foreach ($body['value'] as $event) {
                    $cal_class = $calendar_id ? 'cal-' . substr(md5($calendar_id), 0, 6) : 'default-cal';

                    $formatted_events[] = array(
                        'id' => $event['id'],
                        'title' => $event['subject'],
                        'start' => $event['start']['dateTime'],
                        'end' => $event['end']['dateTime'],
                        'location' => isset($event['location']['displayName']) ? $event['location']['displayName'] : '',
                        'description' => isset($event['bodyPreview']) ? $event['bodyPreview'] : '',
                        'className' => 'event-o365 ' . $cal_class,
                        'isAllDay' => $event['isAllDay'] ?? false,
                        'sourceCalendar' => $calendar_id
                    );
                }
            }

            return array('events' => $formatted_events, 'debug' => ['status' => 'ok', 'count' => count($formatted_events)]);

        } catch (Exception $e) {
            return array('events' => [], 'debug' => ['exception' => $e->getMessage()]);
        }
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intents_bot($intents)
    {
        $intents['AGENDA'] = "El usuario quiere saber su agenda para un día o periodo. Ej: 'qué tengo hoy', 'reuniones mañana', 'agenda de la semana que viene'.";
        $intents['MEETING_PLANNER'] = "El usuario quiere buscar un hueco libre para reunirse con otras personas. Ej: 'busca un hueco con Juan y Maria', 'cuándo estamos libres todos para reunirnos'.";
        return $intents;
    }

    public function responder_intent_agenda($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $params = $intent_data['params'] ?? [];
        return $this->tarjeta_agenda($user_id, $params['date'] ?? $texto, $bot_instance);
    }

    public function responder_intent_meeting_planner($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $wp_user = get_userdata($user_id);
        $nombre  = $wp_user ? $wp_user->display_name : 'Usuario';
        $params  = $intent_data['params'] ?? [];
        return $this->tarjeta_planificador_reunion($user_id, $nombre, $params, $bot_instance);
    }

    private function tarjeta_agenda(int $user_id, string $texto, $bot_instance): array
    {
        $graph = EP_Graph_Service::get_instance();
        $token = $graph->get_valid_token($user_id);
        if (is_wp_error($token)) {
             return $bot_instance->tarjeta_simple("📅 Agenda", "Error de conexión con Microsoft 365. Por favor, inicia sesión en el portal desde el navegador primero.", home_url('/?view=calendar&teams=true'));
        }
        
        $periodo = $this->extraer_periodo_fecha($texto);
        $start = $periodo['start'];
        $end   = $periodo['end'];
        $label = $periodo['label'];
        
        $url   = "https://graph.microsoft.com/v1.0/me/calendarView?startDateTime=$start&endDateTime=$end&\$select=subject,start,end,location&\$top=5";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Prefer' => 'outlook.timezone="Europe/Madrid"'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
             return $bot_instance->tarjeta_simple("📅 Agenda", "No he podido consultar tu agenda de Microsoft 365. Inténtalo más tarde.", '');
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $events = $body['value'] ?? [];
        
        if (empty($events)) {
            return $bot_instance->tarjeta_simple("📅 Agenda del $label", "No tienes eventos programados para este día. ☕", home_url('/?view=calendar&teams=true'));
        }
        
        $facts = [];
        foreach ($events as $e) {
            $dt = new DateTime($e['start']['dateTime']);
            $time = $dt->format('H:i');
            $facts[] = ['title' => "🕒 $time", 'value' => mb_substr($e['subject'], 0, 40)];
        }
        
        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "📅 Tu agenda para el $label", 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'FactSet', 'facts' => $facts],
        ], [['type' => 'Action.OpenUrl', 'title' => '📅 Ver mi Agenda completa', 'url' => home_url('/?view=calendar&teams=true')]]);
    }

    private function extraer_periodo_fecha(string $texto): array
    {
        $hoy   = new DateTime('now', new DateTimeZone('Europe/Madrid'));
        $start = clone $hoy;
        $end   = clone $hoy;
        $found = false;
        $label = '';

        $meses = [
            'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
            'julio'=>7,'agosto'=>8,'septiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12
        ];

        if (preg_match('/(?:mes de )?(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)(?:\s+(\d{4}))?/iu', $texto, $m)) {
            $mes_num = $meses[mb_strtolower($this->sin_tildes($m[1]))] ?? (int)$hoy->format('m');
            $anio    = isset($m[2]) && $m[2] ? (int)$m[2] : (int)$hoy->format('Y');
            $start->setDate($anio, $mes_num, 1);
            $end->setDate($anio, $mes_num, (int)(new DateTime("{$anio}-{$mes_num}-01"))->format('t'));
            $label = ucfirst($m[1]) . ' ' . $anio;
            $found = true;
        } elseif (mb_strpos($texto, 'mañana') !== false || mb_strpos($texto, 'manana') !== false) {
            $start->modify('+1 day');
            $end->modify('+1 day');
            $found = true;
        } elseif (preg_match('/semana que viene|proxima semana|siguiente semana/iu', $texto)) {
            $start->modify('next monday');
            $end = clone $start;
            $end->modify('+4 days'); 
            $label = 'semana del ' . $start->format('d/m');
            $found = true;
        } elseif (preg_match('/esta semana/iu', $texto)) {
            $dow = (int)$hoy->format('N'); 
            $start->modify('-' . ($dow - 1) . ' days');
            $end = clone $start;
            $end->modify('+4 days');
            $label = 'esta semana';
            $found = true;
        } elseif (preg_match('/lunes|martes|miercoles|mi\u00e9rcoles|jueves|viernes|sabado|s\u00e1bado|domingo/iu', $texto, $m)) {
            $dias  = ['domingo'=>0,'lunes'=>1,'martes'=>2,'miercoles'=>3,'jueves'=>4,'viernes'=>5,'sabado'=>6];
            $key   = $this->sin_tildes(mb_strtolower($m[0]));
            $target  = $dias[$key] ?? 1;
            $current = (int)$hoy->format('w');
            $diff    = ($target - $current + 7) % 7;
            if ($diff === 0) $diff = 7;
            $start->modify("+$diff days");
            $end = clone $start;
            $found = true;
        } elseif (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {
            $start->setDate((int)$m[1], (int)$m[2], (int)$m[3]);
            $end = clone $start;
            $found = true;
        } elseif (preg_match('/\bel\s+(\d{1,2})\b/', $texto, $m)) {
            $dia = (int)$m[1];
            if ($dia >= 1 && $dia <= 31) {
                $start->setDate((int)$hoy->format('Y'), (int)$hoy->format('m'), $dia);
                $end = clone $start;
                $found = true;
            }
        }

        if (!$label) {
            $label = $found ? $start->format('d/m/Y') : 'hoy';
        }

        return [
            'start' => $start->format('Y-m-d\T00:00:00\Z'),
            'end'   => $end->format('Y-m-d\T23:59:59\Z'),
            'label' => $label
        ];
    }

    private function sin_tildes(string $t): string
    {
        return str_replace(['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'], ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'], $t);
    }

    private function tarjeta_planificador_reunion(int $user_id, string $nombre, array $params, $bot_instance): array
    {
        $graph = EP_Graph_Service::get_instance();

        $token = $graph->get_valid_token($user_id);
        if (is_wp_error($token)) {
            return $bot_instance->tarjeta_simple(
                '📅 Planificador de Reuniones',
                'No he podido conectar con tu cuenta de Microsoft 365. Por favor, inicia sesión en el portal desde el navegador primero.',
                home_url('/?view=calendar&teams=true')
            );
        }

        $start_date = $params['date_range_start'] ?? date('Y-m-d');
        $end_date   = $params['date_range_end']   ?? date('Y-m-d', strtotime('+7 days'));
        $duration   = max(1, (int)($params['duration_hours'] ?? 1));
        $morning    = (bool)($params['morning_only'] ?? false);
        $attendees  = is_array($params['attendees'] ?? []) ? $params['attendees'] : [];
        $all_org    = (bool)($params['all_org'] ?? false);

        if (empty($attendees) && !$all_org) {
            return $bot_instance->tarjeta_simple(
                '📅 Planificador de Reuniones',
                'Para buscar un hueco común, necesito saber con quién te quieres reunir. Dime los nombres o correos (ej: *busca hueco con Ana y Carlos* o *convocar a info@empresa.com*). Si quieres buscar con todo tu equipo principal, dímelo explícitamente ("con todos").',
                ''
            );
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   $end_date   = date('Y-m-d', strtotime('+7 days'));

        $dt_start = new DateTime($start_date);
        $dt_end   = new DateTime($end_date);
        $rango_label = $dt_start->format('d/m/Y') . ' — ' . $dt_end->format('d/m/Y');

        $contacts = [];

        // Check if any attendee is a general group word (fallback if AI failed to set all_org)
        if (!$all_org && !empty($attendees)) {
            foreach ($attendees as $att) {
                $check = strtolower(trim($this->sin_tildes($att)));
                if (preg_match('/(personal|todos|equipo|companer|oficina|organizacion|camara)/i', $check)) {
                    $all_org = true;
                    $attendees = [];
                    break;
                }
            }
        }

        if ($all_org) {
            $contacts = $graph->get_user_contacts_ms_ids($user_id, true);
            if (empty($contacts)) {
                return $bot_instance->tarjeta_simple(
                    '📅 Planificador de Reuniones',
                    'No he podido obtener tu lista de compañeros o contactos frecuentes de Microsoft 365. Intenta indicarme nombres o correos específicos.',
                    ''
                );
            }
        } else {
            // Resolver asistentes manuales
            foreach ($attendees as $att) {
                $att = trim($att);
                if (filter_var($att, FILTER_VALIDATE_EMAIL)) {
                    $contacts[] = ['displayName' => $att, 'email' => strtolower($att)];
                } else {
                    // Buscar por nombre en WP
                    $wp_users = get_users(['search' => "*$att*", 'search_columns' => ['display_name', 'user_login']]);
                    if (!empty($wp_users)) {
                        $u = $wp_users[0]; // Coger el primer match
                        $contacts[] = ['displayName' => $u->display_name, 'email' => strtolower($u->user_email)];
                    } else {
                        // Fallback: Buscar en todo el directorio de Microsoft 365
                        $url = 'https://graph.microsoft.com/v1.0/users?$search="displayName:' . urlencode($att) . '"&$select=displayName,mail,userPrincipalName';
                        $response = wp_remote_get($url, [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $token,
                                'ConsistencyLevel' => 'eventual'
                            ],
                            'timeout' => 15
                        ]);
                        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                            $body = json_decode(wp_remote_retrieve_body($response), true);
                            if (!empty($body['value'])) {
                                $u = $body['value'][0]; // primer match de MS Graph
                                $email_graph = !empty($u['mail']) ? $u['mail'] : $u['userPrincipalName'];
                                $contacts[] = ['displayName' => $u['displayName'], 'email' => strtolower($email_graph)];
                            }
                        }
                    }
                }
            }
        }

        if (empty($contacts)) {
            return $bot_instance->tarjeta_simple(
                '📅 Planificador de Reuniones',
                'No he podido encontrar correos válidos para los asistentes indicados. Por favor, escribe los nombres exactos o directamente sus emails.',
                ''
            );
        }

        // Para evitar timeout, limitamos a 10 contactos máximo por consulta
        $contacts_dedup = [];
        $emails_vistos = [];
        foreach ($contacts as $c) {
            if (!in_array($c['email'], $emails_vistos)) {
                $contacts_dedup[] = $c;
                $emails_vistos[] = $c['email'];
            }
        }
        $contacts = array_slice($contacts_dedup, 0, 10);
        $num_contactos = count($contacts);

        $slots = $graph->get_free_slots_for_contacts(
            $user_id,
            $contacts,
            $start_date,
            $end_date,
            $duration,
            $morning
        );

        $horario_label = $morning ? 'horario de mañana (08:00–14:00)' : 'horario laboral (08:00–18:00)';
        $nombres_contactos = implode(', ', array_map(fn($c) => $c['displayName'], array_slice($contacts, 0, 4)));
        if ($num_contactos > 4) $nombres_contactos .= ' y ' . ($num_contactos - 4) . ' más';

        $cuerpo = [
            [
                'type'   => 'TextBlock',
                'text'   => '📅 Planificador de Reuniones',
                'weight' => 'Bolder',
                'size'   => 'Large',
                'color'  => 'Accent',
                'wrap'   => true,
            ],
            [
                'type'     => 'FactSet',
                'facts'    => [
                    ['title' => '👥 Participantes',  'value' => $nombres_contactos],
                    ['title' => '📆 Rango analizado', 'value' => $rango_label],
                    ['title' => '⏱️ Duración buscada', 'value' => $duration . ' hora' . ($duration > 1 ? 's' : '')],
                    ['title' => '🌅 Horario',         'value' => $horario_label],
                ],
                'spacing' => 'Medium',
            ],
        ];

        $acciones = [];

        if (empty($slots)) {
            $cuerpo[] = [
                'type'  => 'TextBlock',
                'text'  => '😕 **No he encontrado huecos comunes** en el periodo indicado en ' . $horario_label . '. Prueba a ampliar el rango de fechas o reducir la duración.',
                'wrap'  => true,
                'color' => 'Warning',
            ];
        } else {
            $cuerpo[] = [
                'type'   => 'TextBlock',
                'text'   => '✅ He encontrado **' . count($slots) . ' huecos disponibles** donde todos están libres:',
                'wrap'   => true,
                'weight' => 'Bolder',
                'spacing' => 'Medium',
            ];

            foreach ($slots as $i => $slot) {
                /** @var DateTime $slot_start */
                $slot_start = $slot['start'];
                $slot_end   = $slot['end'];

                $dias_es = ['lunes','martes','miércoles','jueves','viernes','sábado','domingo'];
                $dia_semana = $dias_es[(int)$slot_start->format('N') - 1] ?? '';

                $cuerpo[] = [
                    'type'    => 'ColumnSet',
                    'spacing' => 'Small',
                    'columns' => [
                        [
                            'type'  => 'Column',
                            'width' => 'auto',
                            'items' => [[
                                'type'   => 'TextBlock',
                                'text'   => '🟢',
                                'spacing' => 'None',
                            ]]
                        ],
                        [
                            'type'  => 'Column',
                            'width' => 'stretch',
                            'items' => [[
                                'type'    => 'TextBlock',
                                'text'    => "**{$dia_semana}, {$slot['label']}**",
                                'wrap'    => true,
                                'spacing' => 'None',
                            ]]
                        ]
                    ]
                ];

                $subject_encoded = rawurlencode('Reunión de equipo');
                $start_iso = $slot_start->format('Y-m-d') . 'T' . $slot_start->format('H:i:s');
                $end_iso   = $slot_end->format('Y-m-d') . 'T' . $slot_end->format('H:i:s');

                $attendees = implode(',', array_map(fn($c) => $c['email'], $contacts));
                $attendees_encoded = rawurlencode($attendees);

                $teams_url = "https://teams.microsoft.com/l/meeting/new?subject={$subject_encoded}&startTime={$start_iso}&endTime={$end_iso}&attendees={$attendees_encoded}";

                $acciones[] = [
                    'type'  => 'Action.OpenUrl',
                    'title' => '📨 Convocar el ' . $dia_semana . ' ' . $slot_start->format('d/m') . ' a las ' . $slot_start->format('H:i'),
                    'url'   => $teams_url,
                ];
            }
        }

        $acciones[] = ['type' => 'Action.OpenUrl', 'title' => '📅 Ver mi Calendario', 'url' => home_url('/?view=calendar&teams=true')];

        return $bot_instance->adaptive_card($cuerpo, $acciones);
    }
}

// Register App
add_action('ep_register_apps', function ($manager) {
    if (class_exists('EP_App_Calendar')) {
        $manager->register_app(new EP_App_Calendar());
    }
});
