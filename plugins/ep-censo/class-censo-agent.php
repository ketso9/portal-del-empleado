<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Agente de IA para el Censo IAE
 * Versión 4.1: Robusta, con registro de errores y manejo de Not Found.
 */
class CensoAgent
{

    private $serper_key;
    private $gemini_key;
    private $maps_key;

    private $maps_daily_limit;

    public function __construct()
    {
        $this->serper_key = ep_get_option(CensoConfig::OPTION_SERPER_KEY);
        $this->gemini_key = ep_get_option(CensoConfig::OPTION_GEMINI_KEY);
        $this->maps_key = ep_get_option(CensoConfig::OPTION_MAPS_KEY);
        $this->maps_daily_limit = (int) get_option(CensoConfig::OPTION_MAPS_DAILY_LIMIT, 100);
    }



    public function enrich_batch($records)
    {
        if (empty($this->gemini_key)) {
            return ['error' => 'Configuración de API de Gemini incompleta.'];
        }

        $final_results = array_fill(0, count($records), null);

        // 1. FASE ÚNICA: Búsqueda en Serper para todos los registros
        if (!empty($this->serper_key)) {
            $queries = [];
            foreach ($records as $record) {
                $clean_name = $this->clean_business_name($record['RAZON']);
                // Query: Intentamos ser un poco más amplios para evitar resultados vacíos
                $query = $clean_name . ' ' . $record['MUNICIPIOFISC'];
                // Si el NIF es de empresa (empieza por B, A, etc), lo añadimos
                if ($this->is_company($clean_name) || !preg_match('/^[0-9]/', (string) ($record['NIF'] ?? ''))) {
                    $query .= ' ' . ($record['NIF'] ?? '');
                }

                $queries[] = [
                    'q' => $query,
                    'num' => 10, // Aumentar snippets para dar más contexto a la IA
                    'type' => 'search'
                ];
            }

            $batch_serper = $this->search_google_batch($queries);

            if ($batch_serper && !isset($batch_serper['error'])) {
                $serper_snippets = [];
                foreach ($batch_serper as $res_serper) {
                    $useful_text = "SERPER DATA:\n";

                    // Knowledge Graph (Datos de alta calidad)
                    if (!empty($res_serper['knowledgeGraph'])) {
                        $kg = $res_serper['knowledgeGraph'];
                        if (!empty($kg['title']))
                            $useful_text .= "NAME: " . $kg['title'] . "\n";
                        if (!empty($kg['website']))
                            $useful_text .= "OFFICIAL WEB: " . $kg['website'] . "\n";
                        if (!empty($kg['phoneNumber']))
                            $useful_text .= "PHONE: " . $kg['phoneNumber'] . "\n";
                        if (!empty($kg['description']))
                            $useful_text .= "DESCRIPTION: " . $kg['description'] . "\n";
                        if (!empty($kg['cid']))
                            $useful_text .= "CID: " . $kg['cid'] . " (G-MAPS)\n";
                    }

                    // Resultados Orgánicos (Más snippets y con links para que la IA los valide)
                    if (!empty($res_serper['organic'])) {
                        $useful_text .= "ORGANIC RESULTS:\n";
                        foreach (array_slice($res_serper['organic'], 0, 8) as $o) {
                            $useful_text .= "TITLE: " . ($o['title'] ?? '') . "\n";
                            $useful_text .= "LINK: " . ($o['link'] ?? '') . "\n";
                            $useful_text .= "SNIPPET: " . ($o['snippet'] ?? '') . "\n---\n";
                        }
                    }
                    $serper_snippets[] = $useful_text;
                }

                // 2. FASE FINAL: Extracción con Gemini basada en los hallazgos de Serper
                // Procesamos por sub-lotes de 10 para máxima velocidad en GCP
                for ($i = 0; $i < count($records); $i += 10) {
                    if ($i > 0)
                        sleep(1); // Pausa reducida para GCP

                    $sub_records = array_slice($records, $i, 10);
                    $sub_snippets = array_slice($serper_snippets, $i, 10);
                    $sub_indices = range($i, min($i + 9, count($records) - 1));

                    $extracted = $this->extract_batch_with_gemini($sub_records, $sub_snippets);

                    if (is_array($extracted) && !isset($extracted['error'])) {
                        foreach ($extracted as $sub_idx => $item) {
                            $real_idx = $sub_indices[$sub_idx];
                            $item['search_data'] = $sub_snippets[$sub_idx];
                            $final_results[$real_idx] = $item;
                        }
                    } else {
                        // FALLBACK: Si Gemini falla, intentamos extracción por RegEx directa de los snippets de Serper
                        ep_error_log("Censo: Gemini falló (" . ($extracted['error'] ?? '404') . "). Usando fallback RegEx.");
                        foreach ($sub_indices as $sub_idx => $idx_in_records) {
                            $snippet_idx = $idx_in_records;
                            $raw_text = $serper_snippets[$snippet_idx] ?? '';
                            $regex_data = $this->extract_raw_fallback($raw_text);

                            $final_results[$idx_in_records] = [
                                'email' => $regex_data['email'],
                                'phone' => $regex_data['phone'],
                                'web' => null,
                                'has_maps' => false,
                                'search_data' => $raw_text,
                                'status' => ($regex_data['email'] || $regex_data['phone']) ? 'Enriched' : 'Not Found',
                                'ENRICH_LOG' => 'Extracción RegEx (Fallback IA)'
                            ];
                        }
                    }
                }
            } else {
                return ['error' => 'Error en la búsqueda de Serper: ' . ($batch_serper['error'] ?? 'Unknown error')];
            }
        }

        // Garantizar que todos tengan status y web-crawl si es necesario
        foreach ($final_results as $idx => &$res) {
            if ($res === null) {
                $res = ['status' => 'Not Found'];
                continue;
            }
            if (!isset($res['status'])) {
                $res['status'] = (!empty($res['email']) || !empty($res['phone'])) ? 'Enriched' : 'Not Found';
            }
        }

        return $final_results;
    }


    /**
     * Detecta si un nombre de razón social parece una empresa jurídica
     */
    private function is_company($name)
    {
        $name = strtoupper((string) $name);
        $patterns = [
            '/\bS\.L\b/',
            '/\bSL\b/',
            '/\bS\.A\b/',
            '/\bSA\b/',
            '/\bS\.C\b/',
            '/\bSLL\b/',
            '/\bS\.L\.U\b/',
            '/\bSLU\b/',
            '/\bSOCIEDAD\b/',
            '/\bCOOP\b/',
            '/\bS\.A\.U\b/',
            '/\bSAU\b/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        // Si tiene caracteres típicos de nombre de persona física (coma entre apellidos?)
        // Por ahora nos basamos en los sufijos legales.
        return false;
    }

    private function search_google_batch($queries)
    {
        $url = 'https://google.serper.dev/search';
        $payload = json_encode($queries);

        $response = wp_remote_post($url, [
            'headers' => [
                'X-API-KEY' => $this->serper_key,
                'Content-Type' => 'application/json',
                'Referer' => home_url()
            ],
            'body' => $payload,
            'timeout' => 30
        ]);

        if (is_wp_error($response))
            return null;

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // DEBUG: Muestra de resultados (Aumentado para ver más contexto)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ep_error_log("Censo Serper FULL Result: " . print_r($body, true));
        }

        // Incrementar contador de uso
        $current_usage = (int) get_option(CensoConfig::OPTION_SERPER_USAGE, 0);
        update_option(CensoConfig::OPTION_SERPER_USAGE, $current_usage + count($queries));

        return $body;
    }

    private function extract_batch_with_gemini($records, $data_batch, $force_grounding = false)
    {
        $model = CensoConfig::GEMINI_MODEL;

        $prompt = "Act as an expert business data researcher specialized in Spanish companies. I will provide you with search results from Google Serper.\n";
        $prompt .= "Your goal is to extract the contact EMAIL, PHONE number, and the OFFICIAL WEBSITE (URL) for each business. Also, indicate if a business profile exists on Google Maps.\n";
        $prompt .= "CRITICAL RULES:\n";
        $prompt .= "1. Return ONLY a JSON array of objects in the SAME ORDER as provided.\n";
        $prompt .= "2. DATA CROSS-CHECK: Verify the business name and location in the snippets match the record I provide. If the NIF is mentioned in snippets, use it to confirm 100% identity.\n";
        $prompt .= "3. OFFICIAL WEB (web): Prioritize the 'OFFICIAL WEB' from SERPER DATA if available. Otherwise, look for the main domain in organic links. Ignore directory sites (einforma, axesor, etc.) unless they include the email.\n";
        $prompt .= "4. GOOGLE MAPS (has_maps): Set to true if 'CID' or 'G-MAPS' is mentioned in SERPER DATA, or if organic snippets clearly show a Google Business Profile (Reviews, address markers, etc.).\n";
        $prompt .= "5. NO HALLUCINATIONS: If not 90% sure, return null for that field. Do not invent data.\n";
        $prompt .= "6. Output format: [{\"email\": \"...\", \"phone\": \"...\", \"web\": \"...\", \"has_maps\": true/false}, ...]\n\n";

        foreach ($records as $idx => $record) {
            $prompt .= "RECORD TO ENRICH " . ($idx + 1) . ":\n";
            $prompt .= "NAME: " . $record['RAZON'] . "\n";
            $prompt .= "LOCATION: " . $record['MUNICIPIOFISC'] . "\n";
            $prompt .= "NIF: " . ($record['NIF'] ?? 'N/A') . "\n";
            $prompt .= "SEARCH DATA FOUND:\n";
            $prompt .= ($data_batch[$idx] ?? 'No data found for this business.') . "\n";
            $prompt .= "\n----- \n";
        }

        $payload_data = [
            'contents' => [['parts' => [['text' => $prompt]]]]
        ];


        if (defined('WP_DEBUG') && WP_DEBUG) {
            ep_error_log("Censo Gemini PROMPT: " . $prompt);
        }

        // Pausa anti-burst
        sleep(2);

        $response_text = $this->call_gemini_with_retry($payload_data);

        if (!$response_text) {
            return ['error' => 'Gemini API Error (Overloaded or Rate Limited)'];
        }

        // Incrementar contador de uso
        $current_usage = (int) get_option(CensoConfig::OPTION_GEMINI_USAGE, 0);
        update_option(CensoConfig::OPTION_GEMINI_USAGE, $current_usage + 1);

        $content = $response_text;

        if (!$content)
            return ['error' => 'Empty Response'];

        $content = preg_replace('/^```json\s*|\s*```$/m', '', trim($content));
        $data = json_decode($content, true);

        // --- NUEVA LÓGICA FASE 2: RASTREO WEB ---
        if (is_array($data) && !isset($data['error'])) {
            $this->deep_crawl_if_needed($data);
        }

        return is_array($data) ? $data : ['error' => 'Invalid JSON'];
    }

    /**
     * Si encontramos la web pero no el email, intentamos visitar la web directamente.
     */
    private function deep_crawl_if_needed(&$results)
    {
        if (!is_array($results) || isset($results['error']))
            return;

        $crawl_count = 0;
        $max_crawls = 5; // Limitar a 5 rastreos por lote para evitar timeouts

        foreach ($results as &$res) {
            if (!is_array($res))
                continue;
            if ($crawl_count >= $max_crawls)
                break;

            if (isset($res['web']) && !empty($res['web']) && (empty($res['email']) || $res['email'] === null)) {
                $web_content = $this->fetch_website_summary($res['web']);
                if ($web_content) {
                    $extra_data = $this->extract_from_raw_text($web_content);
                    if (!empty($extra_data['email'])) {
                        $res['email'] = $extra_data['email'];
                    }
                    if (empty($res['phone']) && !empty($extra_data['phone'])) {
                        $res['phone'] = $extra_data['phone'];
                    }
                }
                $crawl_count++;
            }
        }
    }

    /**
     * Descarga y limpia el texto de una web (Home y posiblemente aviso legal/contacto)
     */
    private function fetch_website_summary($url)
    {
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);

        if (is_wp_error($response))
            return null;

        $html = wp_remote_retrieve_body($response);
        if (empty($html))
            return null;

        // Limpieza básica del HTML para enviárselo a Gemini
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $text);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return substr(trim($text), 0, 3000); // Cogemos los primeros 3000 caracteres (suficiente para cabecera/footer)
    }

    /**
     * Usa Gemini para extraer datos de un bloque de texto sucio de una web
     */
    private function extract_from_raw_text($text)
    {
        $prompt = "Extract contact EMAIL and PHONE from the following website text content. Return ONLY JSON: {\"email\": \"...\", \"phone\": \"...\"}. If not found, use null.\n\nTEXT:\n" . $text;

        $model = CensoConfig::GEMINI_MODEL;

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]]
        ];

        $response_text = $this->call_gemini_with_retry($payload);
        if (!$response_text)
            return [];

        // Limpiar posible markdown del JSON
        $raw_json = preg_replace('/^```json\s*|\s*```$/m', '', trim($response_text));
        $data = json_decode($raw_json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Fallback: Enriquecimiento usando Google Places API (Consumiendo créditos de Google Cloud)
     */
    private function enrich_batch_with_google_maps($records)
    {
        // 1. Verificar límite diario
        $today = date('Y-m-d');
        $usage_key = 'ep_censo_maps_usage_' . $today;
        $current_usage = (int) get_option($usage_key, 0);

        if ($current_usage >= $this->maps_daily_limit) {
            return ['error' => "Google Maps: Límite diario alcanzado ({$this->maps_daily_limit}). No se realizarán más cargos hoy."];
        }

        $final_results = [];
        $to_process_records = [];
        $to_process_data = [];
        $new_usage_count = 0;

        foreach ($records as $record) {
            // Re-verificar en cada iteración del batch por si acaso
            if (($current_usage + $new_usage_count) >= $this->maps_daily_limit) {
                $final_results[] = ['error' => 'Límite diario alcanzado a mitad del lote.'];
                continue;
            }

            $query = urlencode($record['RAZON'] . ' ' . $record['MUNICIPIOFISC']);
            $url = "https://maps.googleapis.com/maps/api/place/textsearch/json?query={$query}&key=" . $this->maps_key;

            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => ['Referer' => home_url()]
            ]);
            if (is_wp_error($response)) {
                $final_results[] = ['error' => 'Google Maps Error: ' . $response->get_error_message()];
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['results'])) {
                $final_results[] = ['email' => null, 'phone' => null, 'web' => null, 'status' => 'Not Found'];
                continue;
            }

            // Cogemos el primer resultado y pedimos detalles más profundos (Phone & Website no vienen en textsearch completo a veces)
            $place = $body['results'][0];
            $place_id = $place['place_id'];

            $details_url = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$place_id}&fields=name,formatted_phone_number,website,geometry&key=" . $this->maps_key;
            $details_res = wp_remote_get($details_url, [
                'timeout' => 15,
                'headers' => ['Referer' => home_url()]
            ]);
            $details_body = json_decode(wp_remote_retrieve_body($details_res), true);
            $details = $details_body['result'] ?? $place;

            $useful_text = "GOOGLE PLACES DATA:\n";
            $useful_text .= "- Name: " . ($details['name'] ?? 'Unknown') . "\n";
            $useful_text .= "- Phone: " . ($details['formatted_phone_number'] ?? 'Not found') . "\n";
            $useful_text .= "- Website: " . ($details['website'] ?? 'Not found') . "\n";
            $useful_text .= "- Address: " . ($details['formatted_address'] ?? 'Not found') . "\n";

            $to_process_records[] = $record;
            $to_process_data[] = $useful_text;
            $final_results[] = null; // Placeholder
            $new_usage_count++;
        }

        // Actualizar contador de uso
        update_option($usage_key, $current_usage + $new_usage_count);

        // Procesar con Gemini para normalizar (Lotes de 10)
        for ($i = 0; $i < count($to_process_records); $i += 10) {
            $sub_records = array_slice($to_process_records, $i, 10);
            $sub_data = array_slice($to_process_data, $i, 10);

            $extracted = $this->extract_batch_with_gemini($sub_records, $sub_data);

            // Re-mapear resultados a los huecos null
            $extracted_idx = 0;
            foreach ($final_results as $idx => $val) {
                if ($val === null && isset($extracted[$extracted_idx])) {
                    $final_results[$idx] = $extracted[$extracted_idx];
                    $extracted_idx++;
                }
            }
        }

        return $final_results;
    }

    /**
     * Limpia el nombre legal de la empresa para mejorar la búsqueda comercial
     */
    private function clean_business_name($name)
    {
        $suffixes = [
            ' S.L.U.',
            ' S.L.',
            ' SLU',
            ' SL',
            ' S.A.U.',
            ' S.A.',
            ' SAU',
            ' SA',
            ' SOCIEDAD LIMITADA',
            ' SOCIEDAD ANONIMA',
            ' SOCIEDAD CIVIL',
            ' S.C.',
            ' HEREDEROS DE',
            ' CB',
            ' C.B.',
            ' COMUNIDAD DE BIENES',
            ' SCP',
            ' S.C.P.'
        ];

        $clean = strtoupper((string) $name);
        foreach ($suffixes as $s) {
            // Usar regex para borrar solo al final si es posible, o con espacios
            $clean = preg_replace('/\b' . preg_quote(trim($s), '/') . '\b/i', '', $clean);
        }

        return trim(preg_replace('/\s+/', ' ', $clean));
    }

    /**
     * Extracción de emergencia usando expresiones regulares
     */
    private function extract_raw_fallback($text)
    {
        $data = ['email' => null, 'phone' => null, 'web' => null, 'has_maps' => false];

        // Email
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $matches)) {
            $data['email'] = $matches[0];
        }

        // Teléfono
        $clean_text = str_replace([' ', '-', '.'], '', $text);
        if (preg_match('/[6789]\d{8}/', $clean_text, $matches)) {
            $data['phone'] = $matches[0];
        }

        // Web (Búsqueda de URLs comunes en el texto si no vienen del link de Serper)
        if (preg_match('/https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_\+.~#?&\/\/=]*)/', $text, $matches)) {
            // Evitar dominios de directorios comunes
            if (!preg_match('/(eleconomista|einforma|axesor|infocif|facebook|instagram|yelp|google|paginasamarillas)/i', $matches[0])) {
                $data['web'] = $matches[0];
            }
        }

        // Detección de Maps en snippets de fallback
        if (preg_match('/(Google Maps|G-MAPS|ABRIR EN GOOGLE MAPS|Ver en Maps)/i', $text)) {
            $data['has_maps'] = true;
        }

        return $data;
    }

    /**
     * Usa Gemini para mapear cabeceras de un CSV a campos internos del plugin.
     * 
     * @param array $csv_headers Array con los nombres de las columnas detectadas en el CSV.
     * @param array $sample_rows Unas pocas filas de ejemplo para dar contexto a la IA.
     * @return array|false Mapeo asociativo ['REFERENCIA' => 'NombreColumnaCSV', ...] o false si falla.
     */
    public function map_csv_headers($csv_headers, $sample_rows)
    {
        if (empty($csv_headers))
            return false;

        $fields_def = CensoConfig::get_field_definitions();
        $internal_fields = [];
        foreach ($fields_def as $f) {
            $internal_fields[] = $f['name'];
        }

        $prompt = "Actúa como un experto en procesamiento de datos y censo IAE. 
        Tengo un archivo CSV con las siguientes cabeceras: " . implode(', ', $csv_headers) . ".
        Aquí hay un ejemplo de las primeras filas: " . json_encode($sample_rows) . ".
        
        Debes mapear estas cabeceras a mis campos internos: " . implode(', ', $internal_fields) . ".
        
        Campos críticos:
        - REFERENCIA: Suele ser un código largo, clave única.
        - NIF: Identificación fiscal.
        - RAZON: Nombre de la empresa o persona.
        - MUNICIPIOFISC: Ciudad o municipio.
        - EPIGRAFE: Código del IAE (ej: 611, 861.2).
        - EMAIL_ENRICH: Correo electrónico de contacto.
        - TELEFONO_ENRICH: Teléfono de contacto.
        - WEB_ENRICH: Página web oficial.
        - FECHAINICIO: Fecha de alta.
        - FECHACESE: Fecha de baja (si existe).
        - MOTIVOBAJA: Razón del cese (si existe).
        
        Responde ÚNICAMENTE con un objeto JSON plano donde las claves sean mis campos internos y los valores sean los nombres de las columnas del CSV. Si un campo no tiene correspondencia, no lo incluyas.
        
        Ejemplo de respuesta:
        {
          \"REFERENCIA\": \"COD_REGISTRO\",
          \"NIF\": \"CIF_NIF\",
          \"RAZON\": \"NOMBRE_COMERCIAL\"
        }";

        ep_error_log("Censo: Enviando prompt de mapeo a Gemini...");
        $response = $this->call_gemini($prompt);
        if (!$response) {
            ep_error_log("Censo: Gemini no devolvió respuesta para el mapeo.");
            return false;
        }
        ep_error_log("Censo: Respuesta de Gemini recibida.");

        // Extraer JSON de la respuesta (manejo robusto si la IA añade explicaciones)
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $mapping = json_decode($matches[0], true);
            if (is_array($mapping)) {
                return $mapping;
            }
        }

        ep_error_log("Censo: Fallo al parsear JSON de mapeo. Raw: " . substr($response, 0, 200));
        return false;
    }

    private function call_gemini($prompt)
    {
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]]
        ];

        return $this->call_gemini_with_retry($payload);
    }

    /**
     * Limpia recursivamente caracteres no UTF-8 de strings y arrays.
     * Previene que json_encode devuelva false.
     */
    private function sanitize_utf8_deep($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitize_utf8_deep'], $data);
        }

        if (is_string($data)) {
            // Convertir a UTF-8 válido, eliminando caracteres inválidos
            return mb_convert_encoding((string) $data, 'UTF-8', 'UTF-8');
        }

        return $data;
    }

    /**
     * Helper centralizado para llamar a Gemini con reintentos para 429 y 503.
     */
    private function call_gemini_with_retry($payload_data, $max_retries = 3)
    {
        if (empty($this->gemini_key)) {
            ep_error_log("Censo: Error al llamar a Gemini. Falta la API Key.");
            return null;
        }

        $model = CensoConfig::GEMINI_MODEL;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $this->gemini_key;

        // CRÍTICO: Limpiar y validar el payload antes de codificar
        $payload_data = $this->sanitize_utf8_deep($payload_data);
        $json_payload = json_encode($payload_data);

        if ($json_payload === false) {
            ep_error_log("Censo: FATAL - json_encode falló. JSON Error: " . json_last_error_msg());
            ep_error_log("Censo: Payload problemático: " . print_r($payload_data, true));
            return null;
        }

        $retry_count = 0;
        while ($retry_count <= $max_retries) {
            $response = wp_remote_post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Referer' => home_url()
                ],
                'body' => $json_payload,
                'timeout' => 45
            ]);

            if (is_wp_error($response)) {
                ep_error_log("Censo: Error de conexión con Gemini: " . $response->get_error_message());
                return null;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body_text = wp_remote_retrieve_body($response);

            if ($status_code === 200) {
                $body = json_decode($body_text, true);
                if (!is_array($body) || !isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                    ep_error_log("Censo: Formato de respuesta Gemini no reconocido (200 OK): " . $body_text);
                    return null;
                }
                $text = $body['candidates'][0]['content']['parts'][0]['text'];
                return $text;
            }

            // Manejo de errores reintentables (429: Too Many Requests, 503: Service Unavailable)
            // Usamos == para permitir comparación flexible con strings devueltos por wp_remote_post
            if ($status_code == 429 || $status_code == 503) {
                $retry_count++;
                if ($retry_count <= $max_retries) {
                    $wait_seconds = pow(2, $retry_count) + 1; // Backoff exponencial: 3, 5, 9 segundos
                    ep_error_log("Censo: Gemini Error $status_code (Sobre carga o Cuota). Reintentando en {$wait_seconds}s... (Intento $retry_count de $max_retries)");
                    sleep($wait_seconds);
                    continue;
                }
            }

            // Otros errores no reintentables
            ep_error_log("Censo: Gemini API Error irreversible ($status_code). Body: " . $body_text);
            break;
        }

        return null;
    }

    /**
     * Limpia un número de teléfono
     */
    private function clean_phone($phone)
    {
        if (empty($phone))
            return null;
        // Eliminar espacios, guiones, paréntesis
        $clean = preg_replace('/[^0-9+]/', '', (string) $phone);
        return !empty($clean) ? $clean : null;
    }

    /**
     * Intenta extraer un email de un texto (muy básico)
     */
    private function extract_email_from_text($text)
    {
        if (empty($text))
            return null;
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', (string) $text, $matches)) {
            return $matches[0];
        }
        return null;
    }
}
