<?php

if (!defined('ABSPATH')) {
    exit;
}

class EP_AI_Service
{
    private static $instance = null;
    private $api_key;
    private $model;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->api_key = ep_get_option('ep_ai_api_key');
        $this->model = ep_get_option('ep_ai_model', 'gemini-3.1-flash-lite-preview');
    }

    /**
     * Prueba la conexión con la API de Gemini
     */
    public function test_connection()
    {
        if (empty($this->api_key)) {
            return new WP_Error('no_key', 'API Key de Gemini no configurada.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . '?key=' . $this->api_key;
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            return new WP_Error('api_error', $data['error']['message']);
        }

        return true;
    }

    /**
     * Procesa un mensaje del bot para identificar la intención del usuario.
     * Retorna un JSON con la intención y parámetros.
     */
    public function get_intent($message, $user_context = [])
    {
        if (empty($this->api_key)) {
            return ['error' => 'API Key no configurada'];
        }

        // Intents con datos en tiempo real se cachean máximo 15 minutos.
        // Intents estáticos (ayuda, capacidades) hasta 1 hora.
        // NUNCA cacheamos un día entero: los datos (reuniones, tareas, notificaciones) cambian.
        $live_intents_keywords = ['agenda', 'reunion', 'reunión', 'tarea', 'ticket', 'notif',
                                   'firmas', 'inventario', 'directorio', 'estado', 'libre',
                                   'disponible', 'hoy', 'mañana', 'esta semana', 'resumen', 'panel'];
        $is_live = false;
        $texto_lower = strtolower($message);
        foreach ($live_intents_keywords as $kw) {
            if (strpos($texto_lower, $kw) !== false) { $is_live = true; break; }
        }
        $word_count = str_word_count($message);
        $cache_ttl  = $is_live ? (15 * MINUTE_IN_SECONDS) : HOUR_IN_SECONDS;

        // Para mensajes con contexto de usuario, incluimos el user_id en el hash
        // para que diferentes usuarios obtengan cache independiente.
        $user_id_hash = md5($user_context['user_id'] ?? 'global');

        // IMPORTANTE: Incluimos el resumen del historial en el hash para evitar que 
        // mensajes idénticos (ej: "con el") devuelvan resultados cacheados de contextos distintos.
        $history_summary = EP_Bot_Context::get_prompt_context($user_context['user_id'] ?? '');
        $msg_hash  = md5(strtolower(trim($message)) . $user_id_hash . md5($history_summary));
        $cache_key = 'ep_ai_intent_v15_' . $msg_hash; // v15: cache sensible al contexto
        $cached    = get_transient($cache_key);
        
        if ($cached && is_array($cached)) {
            ep_error_log("EP AI Cache: HIT para '$message' (user: $user_id_hash)");
            return $cached;
        }

        $stats = $this->get_usage_stats();
        if ($stats['today'] >= (int)ep_get_option('ep_ai_daily_limit', 100) || 
            $stats['month'] >= (int)ep_get_option('ep_ai_monthly_limit', 3000)) {
            return ['error' => 'Límite de uso de IA alcanzado para este periodo.'];
        }

        $prompt = $this->generate_intent_prompt($message, $user_context);
        
        $response = $this->call_gemini($prompt);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $clean_json = preg_replace('/^```json\s*|\s*```$/m', '', trim($response));
        $data = json_decode($clean_json, true);

        if (!$data) {
            return ['error' => 'La IA devolvió un formato no reconocido.'];
        }

        set_transient($cache_key, $data, $cache_ttl);
        $this->track_usage(strlen($prompt), strlen($response));

        return $data;
    }

    private function generate_intent_prompt($message, $user_context)
    {
        $prompt = "Eres el cerebro de un Bot de Microsoft Teams para el Portal del Empleado de una Cámara de Comercio.\n";
        $prompt .= "Tu tarea es clasificar la intención del usuario entre las siguientes categorías:\n\n";
        $intents = apply_filters('ep_bot_intents', [
            'AGENDA' => "El usuario quiere ver SUS PROPIOS eventos, reuniones o citas. Ej: 'qué tengo mañana', 'mis reuniones del viernes', 'agenda de esta semana'.",
            'MEETING_PLANNER' => "El usuario quiere encontrar un hueco común en el calendario FUTURO para organizar una reunión. Señales clave: menciona 'huecos', 'planificar', 'organizar reunión'.\n  * OJO: Si pregunta por la disponibilidad ACTUAL de alguien (ej: 'necesito llamar a Sandra, ¿está libre?'), usa DIRECTORY, no esto.\n  * Extrae en params: duration_hours (número, por defecto 1), date_range_start (YYYY-MM-DD), date_range_end (YYYY-MM-DD), morning_only (true/false).\n  * Usa el contexto de 'Fecha actual del sistema' para calcular correctamente días relativos (hoy, mañana, el viernes, la semana que viene). Si el usuario pide un solo día ('el viernes'), date_range_start y date_range_end deben ser ESE MISMO DÍA.\n  * Extrae en params.attendees un array de strings con los nombres o correos explícitos (ej: ['Raul', 'antonio@empresa.com']). SI EL USUARIO NO DA NOMBRES PERO HAY UNA 'Persona mencionada' EN EL HISTORIAL Y USA PRONOMBRES COMO 'con el' O REFERENCIAS RELATIVAS, USA ESE NOMBRE. NO pongas aquí grupos genéricos.\n  * Extrae en params.all_org el valor true SÓLO si dice expresamente palabras generalistas como 'con todos', 'toda mi organización', 'el personal', 'el personal de camara', 'personal', 'compañeros', 'mi equipo', 'contactos frecuentes', 'la organización' o 'la oficina'. Si all_org es true, deja attendees vacío.\n  * Si dice 'esta semana', calcula desde el lunes de la semana actual hasta el viernes.",
            'INVENTORY' => "El usuario quiere ver su equipo asignado, material, portátil, móvil, periféricos o software. Ej: 'mi inventario', 'qué equipo tengo', 'listame el material'.",
            'TICKETS' => "El usuario quiere ver sus tickets, incidencias, soporte o estado de peticiones. Ej: 'mis tickets', 'incidencias abiertas', 'estado de mi sugerencia'.",
            'DIRECTORY' => "El usuario busca el contacto, teléfono, email, ubicación o el estado/disponibilidad ACTUAL en Teams de un compañero para llamarle. Ej: 'teléfono de María', 'quién es Pedro', '¿está libre Sandra ahora?', 'necesito llamar a Raúl, ¿está ocupado?'.",
            'CENSO' => "El usuario busca datos de empresas en el censo empresarial/IAE. Ej: 'busca la empresa X', 'quién es el NIF A12345678', 'cuantas empresas hay en Coria'.\n  * Extrae el término de búsqueda en params.search_term.\n  * Pon params.mode='COUNT' si pregunta cuántas hay, o params.mode='INFO' si busca datos de una específica.",
            'SIGNATURE' => "El usuario quiere ver documentos pendientes de firma. Ej: 'firmas pendientes', 'tengo algo por firmar'.",
            'DOCUMENTS' => "El usuario busca documentos O hace una pregunta sobre información interna/normativa de la empresa (horarios, protocolos, vacaciones, convenios, manuales). Ej: 'mis nóminas', '¿qué dice el manual de bienvenida?', 'resúmeme el protocolo de incendios', 'cómo es el horario de invierno'.\n  * Extrae en params.search_term el tema principal o nombre sugerido del archivo (ej: 'horario', 'protocolo', 'convenio'). SIEMPRE extrae una palabra clave si el usuario pregunta sobre algo interno.\n  * Extrae en params.question la pregunta específica para analizar el contenido.",
            'NOTIFICATIONS' => "El usuario quiere ver notificaciones o avisos. Ej: 'mis notificaciones', 'alertas pendientes'.",
            'TASKS' => "El usuario quiere ver sus tareas pendientes de Microsoft To-Do. Ej: 'qué tareas tengo', 'lista de cosas por hacer', 'mis tareas pendientes'.",
            'DASHBOARD' => "El usuario quiere ver su panel de control personal o portal. Ej: 'mi panel', 'panel general', 'mis cosas del portal'. Importante: NO uses este intent si el usuario pide 'resumir' una noticia o texto (eso es CONVERSATIONAL).",
            'STATS' => "El usuario (admin) quiere ver estadísticas de uso del portal. Ej: 'estadísticas del mes', 'uso del portal'.",
            'CONVERSATIONAL' => "Consultas generales, saludos o preguntas que NO encajen en lo anterior. IMPORTANTE: Si la pregunta es sobre normas de la empresa, horarios, vacaciones o procedimientos internos, NO uses este modo; usa DOCUMENTS aunque no mencionen la palabra 'archivo'.",
            'UNKNOWN' => "Si no puedes determinar la intención.",
        ]);

        $prompt .= "CATEGORÍAS DISPONIBLES:\n";
        foreach ($intents as $name => $desc) {
            $prompt .= "- {$name}: {$desc}\n";
        }
        $prompt .= "\n";
        
        $prompt .= "REGLAS DE CONTEXTO:\n";
        $prompt .= "1. Si el usuario pregunta cosas relativas como '¿cuál es su email?' o 'dame más datos', usa el Historial Reciente para saber de quién o qué habla.\n";
        $prompt .= "2. RESOLUCIÓN DE PRONOMBRES: Si el usuario usa pronombres como 'con él', 'con ella', 'con ellos' o simplemente dice 'reunirme con el' sin dar un nombre, DEBES mirar la 'Persona mencionada' en el Historial Reciente y extraer ese nombre en params.attendees.\n";
        $prompt .= "3. Si el usuario pide 'inventario', 'material' o 'equipo', prioriza siempre INVENTORY.\n";
        $prompt .= "4. MEETING_PLANNER tiene prioridad sobre AGENDA cuando se quiera planificar una reunión en el calendario.\n";
        $prompt .= "5. REGLA DE ORO: Si el usuario quiere 'llamar' o saber si alguien 'está libre' ACTUALMENTE o 'AQUÍ Y AHORA', usa SIEMPRE DIRECTORY. Sólo usa MEETING_PLANNER si quiere organizar o convocar una reunión.\n";
        $prompt .= "6. Para DIRECTORY y CENSO, extrae SOLO el término de búsqueda en search_term. Ej: 'cuál es el teléfono de Antonio' -> search_term: 'Antonio'.\n";
        $prompt .= "7. Para TICKETS e INVENTORY, extrae términos específicos si los hay, de lo contrario deja search_term vacío.\n\n";

        $prompt .= "CONTEXTO ACTUAL:\n";
        $dias = ["Sunday"=>"Domingo", "Monday"=>"Lunes", "Tuesday"=>"Martes", "Wednesday"=>"Miércoles", "Thursday"=>"Jueves", "Friday"=>"Viernes", "Saturday"=>"Sábado"];
        $prompt .= "- Fecha actual del sistema: " . date('Y-m-d') . " (" . $dias[date('l')] . ")\n";
        $prompt .= "- Historial reciente: " . EP_Bot_Context::get_prompt_context($user_context['user_id'] ?? '') . "\n";
        
        // Inyección de Conocimiento Personalizado (Manual)
        $custom_knowledge = ep_get_option('ep_bot_custom_knowledge');
        if (!empty($custom_knowledge)) {
            $prompt .= "- CONOCIMIENTO MANUAL:\n$custom_knowledge\n";
        }

        // Inyección de Conocimiento Web (Scraping)
        $web_knowledge = $this->get_web_knowledge();
        if (!empty($web_knowledge)) {
            $prompt .= "- CONOCIMIENTO WEB (INFORMACIÓN DINÁMICA):\n$web_knowledge\n";
        }

        // Inyección rápida de avisos del portal
        if (class_exists('EP_Avisos')) {
            $avisos_activos = EP_Avisos::get_active_avisos(8);
            if (!empty($avisos_activos)) {
                $prompt .= "- COMUNICADOS/AVISOS INTERNOS ACTIVOS DEL PORTAL:\n";
                foreach ($avisos_activos as $av) {
                    $txt = mb_substr(wp_strip_all_tags($av['content']), 0, 250);
                    $prompt .= "  · [{$av['date']}] {$av['title']}: $txt\n";
                }
                $prompt .= "\n";
            }
        }

        // Sistema de inyección de contexto dinámico
        $extra_context = apply_filters('ep_bot_context', [], $user_context);
        if (!empty($extra_context)) {
            $prompt .= "- INFORMACIÓN ADICIONAL DEL SISTEMA:\n";
            foreach ($extra_context as $ctx) {
                $prompt .= "  · {$ctx}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "- Nombre del usuario: " . ($user_context['display_name'] ?? 'Usuario') . "\n";
        $prompt .= "- Rol: " . ($user_context['role'] ?? 'Empleado') . "\n";
        $prompt .= "- Fecha actual: " . date('Y-m-d (l)', strtotime('now')) . "\n";
        
        if (!empty($user_context['permissions'])) {
            $prompt .= "- Permisos App activas para este usuario: " . implode(', ', $user_context['permissions']) . "\n";
        }
        $prompt .= "\n";

        $prompt .= "MENSAJE DEL USUARIO: \"$message\"\n\n";
        
        $prompt .= "Responde ÚNICAMENTE en formato JSON plano con esta estructura (ajusta los params según el intent):\n";
        $prompt .= "{\n";
        $prompt .= "  \"intent\": \"CATEGORIA\",\n";
        $prompt .= "  \"params\": {\n";
        $prompt .= "    \"mode\": \"INFO|COUNT\",\n";
        $prompt .= "    \"date\": \"YYYY-MM-DD\",\n";
        $prompt .= "    \"search_term\": \"término de búsqueda\",\n";
        $prompt .= "    \"question\": \"pregunta específica para analizar un documento si aplica\",\n";
        $prompt .= "    \"duration_hours\": 2,\n";
        $prompt .= "    \"date_range_start\": \"YYYY-MM-DD\",\n";
        $prompt .= "    \"date_range_end\": \"YYYY-MM-DD\",\n";
        $prompt .= "    \"morning_only\": true,\n";
        $prompt .= "    \"attendees\": [\"nombre o email\"],\n";
        $prompt .= "    \"all_org\": false\n";
        $prompt .= "  },\n";
        $prompt .= "  \"confidence\": 0.9,\n";
        $prompt .= "  \"suggested_reply\": \"Una frase amable confirmando la acción o respondiendo directamente.\"\n";
        $prompt .= "}";

        return $prompt;
    }

    /**
     * Realiza una consulta profunda a un documento inyectando sus bytes (RAG)
     */
    public function query_document($file_content, $mime_type, $question, $filename, $user_context = [])
    {
        if (empty($this->api_key)) return new WP_Error('no_key', 'API Key no configurada');

        $stats = $this->get_usage_stats();
        if ($stats['today'] >= (int)ep_get_option('ep_ai_daily_limit', 100) || 
            $stats['month'] >= (int)ep_get_option('ep_ai_monthly_limit', 3000)) {
            return new WP_Error('limit_reached', 'Límite de uso de IA alcanzado para este periodo.');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $this->api_key;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $mime_type,
                                'data' => base64_encode($file_content)
                            ]
                        ],
                        [
                            'text' => "Eres un asistente experto del Portal del Empleado. El usuario se llama " . ($user_context['display_name'] ?? 'Usuario') . ".\nAnaliza el documento adjunto ('$filename') y responde a la siguiente pregunta de forma concisa y profesional en español:\n\nPregunta: $question"
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 1000
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($text) && isset($body['error'])) {
            return new WP_Error('gemini_error', $body['error']['message'] ?? 'Error analizando documento');
        }

        // Track usage (Estimation: file size is critical here)
        $this->track_usage(strlen($file_content), strlen($text));

        return $text;
    }

    private function call_gemini($prompt)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $this->api_key;

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 2000,
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Referer' => home_url()
            ],
            'body' => json_encode($payload),
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            ep_error_log("EP AI Service ERROR: " . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            ep_error_log("EP AI Service HTTP Error Code: $code. Body: " . wp_remote_retrieve_body($response));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('gemini_error', $body['error']['message'] ?? 'Error desconocido en Gemini');
        }

        return $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Obtiene estadísticas de uso asegurando que los contadores temporales estén al día.
     */
    public function get_usage_stats()
    {
        $today = date('Y-m-d');
        $last_reset = get_option('ep_ai_last_reset_day', '');
        
        $usage_today = (int) get_option('ep_ai_usage_today', 0);
        $usage_month = (int) get_option('ep_ai_usage_month', 0);
        $total_cost  = (float) get_option('ep_ai_total_cost', 0.0);

        if ($last_reset !== $today) {
            update_option('ep_ai_usage_today', 0);
            update_option('ep_ai_last_reset_day', $today);
            $usage_today = 0;
            
            if (substr((string) $last_reset, 0, 7) !== substr($today, 0, 7)) {
                update_option('ep_ai_usage_month', 0);
                $usage_month = 0;
            }
        }

        return [
            'today' => $usage_today,
            'month' => $usage_month,
            'total_cost' => $total_cost
        ];
    }

    private function check_limits()
    {
        $stats = $this->get_usage_stats();
        $daily_limit = (int) ep_get_option('ep_ai_daily_limit', 100);
        $monthly_limit = (int) ep_get_option('ep_ai_monthly_limit', 3000);

        if ($stats['today'] >= $daily_limit || $stats['month'] >= $monthly_limit) {
            return false;
        }

        return true;
    }

    private function track_usage($input_chars, $output_chars)
    {
        // Actualizar contadores
        $usage_today = (int) get_option('ep_ai_usage_today', 0) + 1;
        $usage_month = (int) get_option('ep_ai_usage_month', 0) + 1;
        update_option('ep_ai_usage_today', $usage_today);
        update_option('ep_ai_usage_month', $usage_month);

        // Estimar coste aproximado (Basado en Gemini 1.5 Flash Lite)
        // Precios: $0.075 / 1M input, $0.30 / 1M output
        // Estimación simplificada: 1 token ~ 4 caracteres
        $input_tokens = $input_chars / 4;
        $output_tokens = $output_chars / 4;
        
        $cost = ($input_tokens * 0.000000075) + ($output_tokens * 0.00000030);
        
        $total_cost = (float) get_option('ep_ai_total_cost', 0.0);
        update_option('ep_ai_total_cost', $total_cost + $cost);
    }

    /**
     * Obtiene el conocimiento extraído de las webs configuradas.
     * Usa caché por 12 horas.
     */
    private function get_web_knowledge()
    {
        $urls_str = ep_get_option('ep_bot_knowledge_urls', '');
        if (empty($urls_str)) return '';

        $cache_key = 'ep_bot_web_knowledge_cache';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $urls = array_filter(array_map('trim', explode("\n", $urls_str)));
        $all_content = "";

        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

            $response = wp_remote_get($url, ['timeout' => 5, 'user-agent' => 'EmployeePortalBot/1.0']);
            if (is_wp_error($response)) continue;

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) continue;

            // Limpieza básica de HTML
            $text = wp_strip_all_tags($body);
            $text = preg_replace('/\s+/', ' ', $text); // Colapsar espacios
            $text = mb_substr($text, 0, 1500); // Max 1500 caracteres por web
            
            $all_content .= "--- INFO DE " . preg_replace('/^https?:\/\//', '', $url) . " ---\n" . $text . "\n\n";
        }

        $all_content = mb_substr($all_content, 0, 5000); // Max absoluto 5000 chars
        set_transient($cache_key, $all_content, 6 * HOUR_IN_SECONDS); // 6h: suficiente para contenido web dinámico

        return $all_content;
    }

    /**
     * Registra un mensaje que el bot no ha entendido (intent UNKNOWN o confianza < 0.6)
     * en la cola de aprendizaje ep_bot_learning_queue para revisión del administrador.
     * Umbral: confidence < 0.6
     */
    public static function log_uncertain_intent(
        string $message,
        string $intent,
        float  $confidence,
        string $user_role
    ): void {
        // No registrar mensajes vacíos o muy cortos (probablemente errores de escritura)
        $msg_trim = trim($message);
        if (mb_strlen($msg_trim) < 3) return;

        $queue = get_option('ep_bot_learning_queue', []);
        if (!is_array($queue)) $queue = [];

        $queue[] = [
            'id'         => substr(sha1(uniqid($msg_trim, true)), 0, 8),
            'message'    => mb_substr($msg_trim, 0, 300),
            'intent'     => $intent,
            'confidence' => round($confidence, 2),
            'role'       => $user_role,
            'timestamp'  => time(),
        ];

        // Mantener solo las 50 más recientes
        if (count($queue) > 50) {
            $queue = array_slice($queue, -50);
        }

        update_option('ep_bot_learning_queue', $queue, false);
        ep_error_log("EP Learning: Mensaje registrado para revisión admin. Intent: $intent | Confianza: $confidence | Rol: $user_role");
    }
}
