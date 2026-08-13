<?php

defined('ABSPATH') || exit;

/**
 * EP_OOF_Sync
 *
 * Sincronización BIDIRECCIONAL del estado "Fuera de la oficina" (OOF).
 *
 * - Portal -> Microsoft 365: lo hace EP_Auth_O365::handle_oof_update_ajax()
 *   escribiendo en /me/mailboxSettings.
 * - Microsoft 365 -> Portal: esta clase. Lee automaticRepliesSetting de cada
 *   usuario y lo cachea en el user_meta 'ep_oof_info', que es lo que consumen
 *   el Directorio, el bot y los widgets de presencia.
 *
 * Estrategia de lectura (en este orden):
 *   1. Token de aplicación (client_credentials) + $batch -> lee el buzón de
 *      todos los usuarios de golpe (20 por llamada). Requiere el permiso de
 *      aplicación MailboxSettings.Read con consentimiento de administrador.
 *   2. Token delegado del propio usuario (/me/mailboxSettings) -> sólo puede
 *      leer su propio buzón, pero funciona sin permisos de aplicación.
 */
class EP_OOF_Sync
{
    const META_KEY      = 'ep_oof_info';
    const CRON_HOOK     = 'ep_oof_sync_cron';
    const SCHEDULE_NAME = 'ep_oof_fifteen_minutes';

    /** Transient que marca que el permiso de aplicación no está concedido. */
    const PERM_FLAG = 'ep_oof_app_perm_missing';

    /** Candado para que no se solapen sincronizaciones bajo demanda. */
    const LOCK_KEY = 'ep_oof_sync_lock';

    /** Máximo de peticiones por lote admitido por Microsoft Graph en /$batch. */
    const BATCH_SIZE = 20;

    /** Segundos que se considera "fresco" un estado ya sincronizado. */
    const FRESH_TTL = 900; // 15 min

    /** Ventana de gracia tras un cambio hecho desde el portal (propagación Graph). */
    const LOCAL_GRACE = 120; // 2 min

    public function __construct()
    {
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action('init', array($this, 'maybe_schedule_cron'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'sync_all'));
    }

    public function add_cron_schedule($schedules)
    {
        if (!isset($schedules[self::SCHEDULE_NAME])) {
            $schedules[self::SCHEDULE_NAME] = array(
                'interval' => 900,
                'display'  => 'Cada 15 minutos (Fuera de la oficina)'
            );
        }
        return $schedules;
    }

    public function maybe_schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, self::SCHEDULE_NAME, self::CRON_HOOK);
        }
    }

    // ---------------------------------------------------------------------
    // Lectura del estado (consumo)
    // ---------------------------------------------------------------------

    /**
     * Devuelve el estado OOF vigente de un usuario según el meta cacheado.
     *
     * @return array{is_oof:bool, message:string, status:string, start_ts:int, end_ts:int, source:string}
     */
    public static function get_user_oof_data($user_id)
    {
        $empty = array(
            'is_oof'   => false,
            'message'  => '',
            'status'   => 'disabled',
            'start_ts' => 0,
            'end_ts'   => 0,
            'source'   => ''
        );

        $meta = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($meta) || empty($meta['status']) || $meta['status'] === 'disabled') {
            return $empty;
        }

        $base = array(
            'is_oof'   => false,
            'message'  => isset($meta['message']) ? (string) $meta['message'] : '',
            'status'   => (string) $meta['status'],
            'start_ts' => intval($meta['start_ts'] ?? 0),
            'end_ts'   => intval($meta['end_ts'] ?? 0),
            'source'   => (string) ($meta['source'] ?? '')
        );

        if ($meta['status'] === 'alwaysEnabled') {
            $base['is_oof'] = true;
            return $base;
        }

        if ($meta['status'] === 'scheduled') {
            $now   = time();
            $start = $base['start_ts'];
            $end   = $base['end_ts'];

            if (($start === 0 || $now >= $start) && ($end === 0 || $now <= $end)) {
                $base['is_oof'] = true;
            }
            return $base;
        }

        return $empty;
    }

    /** Momento de la última sincronización con Graph (0 si nunca). */
    public static function get_synced_at($user_id)
    {
        $meta = get_user_meta($user_id, self::META_KEY, true);
        return is_array($meta) ? intval($meta['synced_at'] ?? 0) : 0;
    }

    // ---------------------------------------------------------------------
    // Escritura del estado (persistencia)
    // ---------------------------------------------------------------------

    /**
     * Guarda en el meta el estado OOF a partir de un automaticRepliesSetting de Graph.
     *
     * @param array  $auto_replies Nodo automaticRepliesSetting devuelto por Graph.
     * @param string $source       'graph-app' | 'graph-self' | 'portal'
     */
    public static function save_from_graph($user_id, $auto_replies, $source = 'graph-app')
    {
        if (!is_array($auto_replies) || empty($auto_replies)) {
            return false;
        }

        $previous = get_user_meta($user_id, self::META_KEY, true);
        $previous = is_array($previous) ? $previous : array();

        // Si el usuario acaba de cambiarlo desde el portal, no lo pisamos: Graph
        // puede tardar unos segundos en devolver el valor nuevo.
        $local_at = intval($previous['local_updated_at'] ?? 0);
        if ($source !== 'portal' && $local_at && (time() - $local_at) < self::LOCAL_GRACE) {
            $previous['synced_at'] = time();
            update_user_meta($user_id, self::META_KEY, $previous);
            return false;
        }

        $status = isset($auto_replies['status']) ? (string) $auto_replies['status'] : 'disabled';
        if (!in_array($status, array('disabled', 'alwaysEnabled', 'scheduled'), true)) {
            $status = 'disabled';
        }

        $data = array(
            'status'            => $status,
            'message'           => self::html_to_text($auto_replies['internalReplyMessage'] ?? ''),
            'external_message'  => self::html_to_text($auto_replies['externalReplyMessage'] ?? ''),
            'external_audience' => sanitize_text_field($auto_replies['externalAudience'] ?? 'none'),
            'start_ts'          => self::parse_graph_datetime($auto_replies['scheduledStartDateTime'] ?? null),
            'end_ts'            => self::parse_graph_datetime($auto_replies['scheduledEndDateTime'] ?? null),
            'source'            => $source,
            'updated_at'        => time(),
            'synced_at'         => time(),
            'local_updated_at'  => ($source === 'portal') ? time() : $local_at
        );

        update_user_meta($user_id, self::META_KEY, $data);
        return true;
    }

    /**
     * Guarda el estado tal y como lo envió el portal (dirección portal -> M365),
     * para que el Directorio lo refleje al instante sin esperar al cron.
     */
    public static function save_from_portal($user_id, $status, $internal_text, $start_ts, $end_ts, $external_audience = 'none', $external_text = '')
    {
        $data = array(
            'status'            => $status,
            'message'           => sanitize_textarea_field($internal_text),
            'external_message'  => sanitize_textarea_field($external_text),
            'external_audience' => sanitize_text_field($external_audience),
            'start_ts'          => intval($start_ts),
            'end_ts'            => intval($end_ts),
            'source'            => 'portal',
            'updated_at'        => time(),
            'synced_at'         => time(),
            'local_updated_at'  => time()
        );

        update_user_meta($user_id, self::META_KEY, $data);
        return $data;
    }

    // ---------------------------------------------------------------------
    // Sincronización desde Microsoft 365
    // ---------------------------------------------------------------------

    /**
     * Cron: sincroniza a todos los usuarios con cuenta de M365 vinculada.
     */
    public static function sync_all()
    {
        $map = self::build_ms_map();
        if (empty($map)) {
            return 0;
        }
        return self::sync_users($map, 0);
    }

    /**
     * Sincroniza sólo los usuarios cuyo estado esté caducado.
     *
     * @param array $ms_to_wp  [ms_user_id => wp_user_id]
     * @param int   $max_age   Segundos de frescura aceptables.
     * @return int             Nº de usuarios refrescados.
     */
    public static function sync_stale($ms_to_wp, $max_age = self::FRESH_TTL)
    {
        // Si varios empleados abren el directorio a la vez, que sólo uno pida
        // los datos a Graph; el resto lee el meta que acaba de quedar fresco.
        if (get_transient(self::LOCK_KEY)) {
            return 0;
        }
        set_transient(self::LOCK_KEY, 1, 60);

        $updated = self::sync_users($ms_to_wp, $max_age);

        delete_transient(self::LOCK_KEY);
        return $updated;
    }

    /**
     * Refresca el estado del propio usuario con su token delegado (/me).
     * No requiere permisos de aplicación. Throttled por frescura del meta.
     */
    public static function sync_self($user_id, $max_age = 300)
    {
        if (!$user_id || !class_exists('EP_Graph_Service')) {
            return false;
        }

        $synced_at = self::get_synced_at($user_id);
        if ($synced_at && (time() - $synced_at) < $max_age) {
            return false;
        }

        $settings = EP_Graph_Service::get_instance()->get_mailbox_settings($user_id);
        if (is_wp_error($settings) || !is_array($settings) || isset($settings['code'])) {
            return false;
        }

        return self::save_from_graph($user_id, $settings['automaticRepliesSetting'] ?? array(), 'graph-self');
    }

    /**
     * Núcleo: lee mailboxSettings por lotes con el token de aplicación.
     */
    private static function sync_users($ms_to_wp, $max_age)
    {
        if (empty($ms_to_wp) || !class_exists('EP_Graph_Service')) {
            return 0;
        }

        if (get_transient(self::PERM_FLAG)) {
            return 0; // Sin permiso de aplicación: no insistimos cada pocos minutos.
        }

        // Filtrar los que siguen frescos.
        if ($max_age > 0) {
            $now = time();
            $ms_to_wp = array_filter($ms_to_wp, function ($wp_id) use ($now, $max_age) {
                $synced_at = self::get_synced_at($wp_id);
                return !$synced_at || ($now - $synced_at) >= $max_age;
            });
        }

        if (empty($ms_to_wp)) {
            return 0;
        }

        $graph = EP_Graph_Service::get_instance();
        $token = $graph->get_app_token();
        if (is_wp_error($token)) {
            return 0;
        }

        $ms_ids  = array_keys($ms_to_wp);
        $updated = 0;

        foreach (array_chunk($ms_ids, self::BATCH_SIZE) as $chunk) {
            $requests = array();
            $index    = array();

            foreach ($chunk as $i => $ms_id) {
                $req_id         = (string) $i;
                $index[$req_id] = $ms_id;
                $requests[]     = array(
                    'id'      => $req_id,
                    'method'  => 'GET',
                    'url'     => '/users/' . rawurlencode($ms_id) . '/mailboxSettings/automaticRepliesSetting',
                    // Fuerza a Graph a devolver las fechas programadas en UTC.
                    'headers' => array('Prefer' => 'outlook.timezone="UTC"')
                );
            }

            $response = wp_remote_post('https://graph.microsoft.com/v1.0/$batch', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json'
                ),
                'body'    => wp_json_encode(array('requests' => $requests)),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                self::log('Error de red al leer OOF por lotes: ' . $response->get_error_message());
                break;
            }

            $body      = json_decode(wp_remote_retrieve_body($response), true);
            $responses = isset($body['responses']) && is_array($body['responses']) ? $body['responses'] : array();

            if (empty($responses)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 401 || $code === 403) {
                    self::flag_missing_permission();
                }
                break;
            }

            $denied = 0;
            foreach ($responses as $r) {
                $req_id = (string) ($r['id'] ?? '');
                $status = intval($r['status'] ?? 0);

                if (!isset($index[$req_id])) {
                    continue;
                }

                $wp_id = $ms_to_wp[$index[$req_id]];

                if ($status === 403 || $status === 401) {
                    $denied++;
                    continue;
                }

                if ($status !== 200 || !isset($r['body']) || !is_array($r['body'])) {
                    continue;
                }

                if (self::save_from_graph($wp_id, $r['body'], 'graph-app')) {
                    $updated++;
                }
            }

            // Si TODO el lote fue denegado, el permiso de aplicación no está concedido.
            if ($denied > 0 && $denied === count($responses)) {
                self::flag_missing_permission();
                break;
            }
        }

        return $updated;
    }

    /** [ms_user_id => wp_user_id] de todos los usuarios vinculados a M365. */
    public static function build_ms_map()
    {
        $users = get_users(array(
            'meta_key' => 'ep_o365_user_id',
            'fields'   => array('ID')
        ));

        $map = array();
        foreach ($users as $user) {
            $ms_id = get_user_meta($user->ID, 'ep_o365_user_id', true);
            if (!empty($ms_id)) {
                $map[$ms_id] = $user->ID;
            }
        }
        return $map;
    }

    /** True si el permiso de aplicación MailboxSettings.Read no está disponible. */
    public static function app_permission_missing()
    {
        return (bool) get_transient(self::PERM_FLAG);
    }

    private static function flag_missing_permission()
    {
        set_transient(self::PERM_FLAG, 1, 6 * HOUR_IN_SECONDS);
        self::log('Sin permiso de aplicación MailboxSettings.Read: la sincronización masiva de "Fuera de la oficina" queda deshabilitada 6h. Concédelo en Azure AD (Permisos de API > Microsoft Graph > Aplicación > MailboxSettings.Read) y otorga el consentimiento de administrador.');
    }

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------

    /**
     * Convierte el dateTime de Graph a timestamp UNIX.
     *
     * @param array|null $node ['dateTime' => '...', 'timeZone' => '...']
     */
    public static function parse_graph_datetime($node)
    {
        if (!is_array($node) || empty($node['dateTime'])) {
            return 0;
        }

        // Graph devuelve hasta 7 decimales de segundo; PHP sólo digiere 6.
        $raw = preg_replace('/(\.\d{0,6})\d*/', '$1', trim($node['dateTime']));
        $tz  = self::resolve_timezone($node['timeZone'] ?? 'UTC');

        try {
            $dt = new DateTime($raw, $tz);
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Graph puede devolver nombres de zona horaria de Windows.
     */
    private static function resolve_timezone($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            $name = 'UTC';
        }

        $windows_map = array(
            'UTC'                            => 'UTC',
            'Romance Standard Time'          => 'Europe/Madrid',
            'W. Europe Standard Time'        => 'Europe/Berlin',
            'Central European Standard Time' => 'Europe/Warsaw',
            'GMT Standard Time'              => 'Europe/London',
            'Greenwich Standard Time'        => 'UTC',
            'Canary Islands Standard Time'   => 'Atlantic/Canary',
            'Morocco Standard Time'          => 'Africa/Casablanca'
        );

        if (isset($windows_map[$name])) {
            $name = $windows_map[$name];
        }

        try {
            return new DateTimeZone($name);
        } catch (Exception $e) {
            // Última red: la zona horaria del sitio.
            if (function_exists('wp_timezone')) {
                return wp_timezone();
            }
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Los mensajes de respuesta automática llegan como HTML completo de Outlook.
     * Extraemos un texto plano corto y presentable para el Directorio.
     */
    public static function html_to_text($html, $max_length = 240)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        // Fuera estilos, scripts y comentarios condicionales de Word/Outlook.
        $text = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = preg_replace('#<!--.*?-->#s', ' ', $text);
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</tr>#i', "\n", $text);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Outlook cuela espacios duros, BOM y caracteres de ancho cero al
        // principio del mensaje; sin limpiarlos el banner arranca con un hueco.
        $text = str_replace(
            array("\xC2\xA0", "\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"),
            array(' ', '', '', '', ''),
            $text
        );
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = trim($text);

        if (function_exists('mb_strlen') && mb_strlen($text) > $max_length) {
            $text = rtrim(mb_substr($text, 0, $max_length)) . '…';
        }

        return sanitize_textarea_field($text);
    }

    private static function log($message)
    {
        if (function_exists('ep_error_log')) {
            ep_error_log('EP_OOF_Sync: ' . $message);
        }
    }
}
