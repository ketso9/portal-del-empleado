<?php
if (!defined('ABSPATH')) {
    exit;
}

class CensoManager
{
    /** Evento diario que retira los archivos que el modulo deja en uploads/. */
    const CLEANUP_HOOK = 'censo_daily_cleanup';

    private $db;
    private $parser;

    public function __construct()
    {
        require_once plugin_dir_path(__FILE__) . 'class-censo-config.php';
        require_once plugin_dir_path(__FILE__) . 'class-censo-db.php';
        require_once plugin_dir_path(__FILE__) . 'class-censo-parser.php';
        require_once plugin_dir_path(__FILE__) . 'libs/SimpleXLSX.php';

        $this->db = new CensoDB();
        $this->parser = new CensoParser();

        // AJAX Hooks
        // AJAX Hooks
        add_action('wp_ajax_censo_upload_chunk', [$this, 'handle_upload_chunk']);
        add_action('wp_ajax_censo_process_batch', [$this, 'handle_process_batch']);
        add_action('wp_ajax_censo_sync_epigrafes', [$this, 'handle_sync_epigrafes']);
        add_action('wp_ajax_censo_search', [$this, 'handle_search']);
        add_action('wp_ajax_censo_get_stats', [$this, 'handle_get_stats']);
        add_action('wp_ajax_censo_export_csv', [$this, 'handle_export_csv']);
        add_action('wp_ajax_censo_enrich_batch', [$this, 'handle_enrich_batch']);
        add_action('wp_ajax_censo_reset_errors', [$this, 'handle_reset_errors']);
        add_action('wp_ajax_censo_reset_notfound', [$this, 'handle_reset_notfound']);
        add_action('wp_ajax_censo_save_api_settings', [$this, 'handle_save_api_settings']);
        add_action('wp_ajax_censo_ai_map_headers', [$this, 'handle_ai_map_headers']);
        add_action('wp_ajax_censo_toggle_worker', [$this, 'handle_toggle_worker']);
        add_action('wp_ajax_censo_get_search_data', [$this, 'handle_get_search_data']);
        add_action('wp_ajax_censo_reset_no_evidence', [$this, 'handle_reset_no_evidence']);
        add_action('wp_ajax_censo_sync_agrupaciones', [$this, 'handle_sync_agrupaciones']);
        add_action('wp_ajax_censo_delete_multiple', [$this, 'ajax_delete_multiple']);
        add_action('wp_ajax_censo_reindex', [$this, 'handle_reindex_census']);
        add_action('wp_ajax_censo_update_field', [$this, 'handle_update_field']);
        add_action('wp_ajax_censo_get_last_reports', [$this, 'handle_get_last_reports']);

        // Cron Hook
        add_action('censo_worker_cron_event', [$this, 'run_background_worker']);
        add_action(self::CLEANUP_HOOK, [$this, 'run_scheduled_cleanup']);
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::CLEANUP_HOOK);
        }

        // Shortcodes
        add_shortcode('portal_censo_manager', [$this, 'render_manager_view']);
    }

    /**
     * Sincroniza las descripciones de los epígrafes para los registros existentes que no las tengan
     */
    public function handle_sync_epigrafes()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import())
            wp_send_json_error('No autorizado');

        set_time_limit(300); // 5 minutos por lote

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // Cargar parser y epígrafes
        $parser = new CensoParser();

        // El lote será de 1000 para evitar timeouts
        $batch_size = 1000;

        // Obtener registros sin descripción
        $records = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT REFERENCIA, EPIGRAFE, EPIGRAFE_LIMPIO FROM $table_name WHERE DESCRIPCION_EPIGRAFE IS NULL OR DESCRIPCION_EPIGRAFE = '' LIMIT %d",
                $batch_size
            )
        );

        if (empty($records)) {
            wp_send_json_success([
                'message' => 'Sincronización completada.',
                'count' => 0,
                'finished' => true,
                'new_nonce' => wp_create_nonce('censo_nonce')
            ]);
        }

        $count = 0;
        foreach ($records as $row) {
            $desc = $parser->get_epigrafe_description($row->EPIGRAFE, $row->EPIGRAFE_LIMPIO);
            if (!empty($desc)) {
                $wpdb->update($table_name, ['DESCRIPCION_EPIGRAFE' => $desc], ['REFERENCIA' => $row->REFERENCIA]);
                $count++;
            } else {
                // MySQL ignora espacios al final en comparaciones convencionales (' ' == ''). 
                // Usamos un marcador visible para no procesar de nuevo.
                $wpdb->update($table_name, ['DESCRIPCION_EPIGRAFE' => ' - '], ['REFERENCIA' => $row->REFERENCIA]);
            }
        }

        // Ver si quedan más
        // Nota: El query anterior es lento para contar, mejor simplemente decir que sigamos si el lote vino lleno
        $is_finished = (count($records) < $batch_size);

        wp_send_json_success([
            'message' => "Procesados " . count($records) . " registros.",
            'count' => count($records),
            'finished' => $is_finished,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Sincroniza la Agrupación Electoral para los registros existentes (Fase 7)
     */
    public function handle_sync_agrupaciones()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import())
            wp_send_json_error('No autorizado');

        set_time_limit(300);

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        $batch_size = 2000;
        $records = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, EPIGRAFE_LIMPIO FROM $table_name WHERE AGRUPACION_ELECTORAL IS NULL OR AGRUPACION_ELECTORAL = '' LIMIT %d",
                $batch_size
            )
        );

        if (empty($records)) {
            wp_send_json_success([
                'message' => 'Sincronización de agrupaciones completada.',
                'count' => 0,
                'finished' => true,
                'new_nonce' => wp_create_nonce('censo_nonce')
            ]);
        }

        $count = 0;
        foreach ($records as $row) {
            $agrupacion = $this->get_agrupacion_electoral($row->EPIGRAFE_LIMPIO);
            $wpdb->update($table_name, ['AGRUPACION_ELECTORAL' => $agrupacion], ['id' => $row->id]);
            $count++;
        }

        $is_finished = (count($records) < $batch_size);

        wp_send_json_success([
            'message' => "Procesados $count registros.",
            'count' => $count,
            'finished' => $is_finished,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Obtiene la Agrupación Electoral basada en los primeros 2 dígitos del epígrafe (Fase 7)
     */
    private function get_agrupacion_electoral($epigrafe)
    {
        if (empty($epigrafe))
            return ' - ';

        // Normalizar: quedarnos con los primeros dígitos antes de puntos o espacios
        // Ej: 654.1 -> 65
        $clean = preg_replace('/[^0-9]/', '', $epigrafe);
        $group = substr($clean, 0, 2);

        if (strlen($group) < 2) {
            return ' - ';
        }

        $group_int = intval($group);

        // Agrupación Electoral	Epígrafes (Grupos IAE incluidos)
        // 1. Energía, Agua e Industria (excepto metales)	11, 12, 13, 14, 15, 16, 21, 23, 24, 25, 41, 42, 43, 44, 45, 46, 47, 48, 49
        $g1 = [11, 12, 13, 14, 15, 16, 21, 23, 24, 25, 41, 42, 43, 44, 45, 46, 47, 48, 49];
        // 2. Industrias transformadoras de los metales	22, 31, 32, 33, 34, 35, 36, 37, 38, 39
        $g2 = [22, 31, 32, 33, 34, 35, 36, 37, 38, 39];
        // 3. Construcción	50
        $g3 = [50];
        // 4. Comercio mayor y reparaciones	61, 62, 63
        $g4 = [61, 62, 63];
        // 5. Comercio menor	64, 65, 66
        $g5 = [64, 65, 66];
        // 6. Hostelería y restauración	67, 68
        $g6 = [67, 68];
        // 7. Transportes y comunicaciones	71, 72, 73, 74, 75, 76
        $g7 = [71, 72, 73, 74, 75, 76];
        // 8. Intermediación financiera y servicios a empresas	81, 82, 83, 84, 85, 86
        $g8 = [81, 82, 83, 84, 85, 86];
        // 9. Otros servicios	91, 92, 93, 94, 95, 96
        $g9 = [91, 92, 93, 94, 95, 96];

        if (in_array($group_int, $g1))
            return "1. Energía, Agua e Industria (excepto metales)";
        if (in_array($group_int, $g2))
            return "2. Industrias transformadoras de los metales";
        if (in_array($group_int, $g3))
            return "3. Construcción";
        if (in_array($group_int, $g4))
            return "4. Comercio mayor y reparaciones";
        if (in_array($group_int, $g5))
            return "5. Comercio menor";
        if (in_array($group_int, $g6))
            return "6. Hostelería y restauración";
        if (in_array($group_int, $g7))
            return "7. Transportes y comunicaciones";
        if (in_array($group_int, $g8))
            return "8. Intermediación financiera y servicios a empresas";
        if (in_array($group_int, $g9))
            return "9. Otros servicios";

        return " - ";
    }

    public function handle_get_stats()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // Intentar obtener de caché (transient) por 5 minutos
        $stats = get_transient('ep_censo_kpi_stats');

        if ($stats === false) {
            // 1. Totales
            $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

            // 2. Por Municipio (Top 5)
            $municipios = $wpdb->get_results($wpdb->prepare(
                "SELECT MUNICIPIOFISC as name, COUNT(*) as count 
                FROM $table_name 
                WHERE MUNICIPIOFISC != '' 
                GROUP BY MUNICIPIOFISC 
                ORDER BY count DESC 
                LIMIT %d",
                5
            ));

            // 3. Por Epígrafe (Top 5)
            $epigrafes = $wpdb->get_results($wpdb->prepare(
                "SELECT EPIGRAFE as name, COUNT(*) as count 
                FROM $table_name 
                GROUP BY EPIGRAFE 
                ORDER BY count DESC 
                LIMIT %d",
                5
            ));

            // 4. Estadísticas de Enriquecimiento
            $emails = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE EMAIL_ENRICH IS NOT NULL AND EMAIL_ENRICH != ''");
            $telefonos = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE TELEFONO_ENRICH IS NOT NULL AND TELEFONO_ENRICH != ''");
            $webs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE WEB_ENRICH IS NOT NULL AND WEB_ENRICH != ''");
            $maps = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE MAPS_LINK IS NOT NULL AND MAPS_LINK != ''");
            $enriquecidos = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Enriched'");

            $current_year = date('Y');
            $altas_year = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE FUENTE_IMPORTACION IS NOT NULL AND FUENTE_IMPORTACION != 'Enriquecido-IA'");
            $bajas = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ESTADO_INTERNO = 'Baja'");

            $stats = [
                'total' => $total,
                'municipios' => $municipios,
                'epigrafes' => $epigrafes,
                'emails' => (int) $emails,
                'telefonos' => (int) $telefonos,
                'webs' => (int) $webs,
                'maps' => (int) $maps,
                'enriquecidos' => (int) $enriquecidos,
                'altas_year' => (int) $altas_year,
                'bajas' => (int) $bajas
            ];

            // Guardar en caché 5 min
            set_transient('ep_censo_kpi_stats', $stats, 5 * MINUTE_IN_SECONDS);
        }

        // 5. KPIs de Uso y Coste (Cambian en tiempo real, no se cachean)
        $serper_usage = (int) get_option(CensoConfig::OPTION_SERPER_USAGE, 0);
        $gemini_usage = (int) get_option(CensoConfig::OPTION_GEMINI_USAGE, 0);
        $est_cost = ($serper_usage * 0.001) + ($gemini_usage * 5 * 0.00015);

        $worker_status = get_option(CensoConfig::OPTION_WORKER_STATUS, 'stopped');
        $processing_name = '';
        if ($worker_status === 'active') {
            $processing_name = $wpdb->get_var("SELECT RAZON FROM $table_name WHERE ENRICH_STATUS = 'Processing' LIMIT 1");
        }

        wp_send_json_success(array_merge($stats, [
            'serper_usage' => $serper_usage,
            'gemini_usage' => $gemini_usage,
            'est_cost' => round($est_cost, 2),
            'worker_status' => $worker_status,
            'processing_name' => $processing_name,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]));
    }

    public function handle_export_csv()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        set_time_limit(900); // 15 minutes for very large exports

        // wp-cron solo salta si el sitio recibe visitas. El export es la operación
        // frecuente de este módulo, así que se aprovecha para ir barriendo.
        $this->run_scheduled_cleanup();

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        $scope = sanitize_text_field($_POST['scope'] ?? 'filtered');
        $requested_columns = $_POST['columns'] ?? [];
        if (!is_array($requested_columns) || empty($requested_columns)) {
            wp_send_json_error("No se han seleccionado columnas para exportar.");
        }

        $term = sanitize_text_field($_POST['term'] ?? '');
        $municipio = sanitize_text_field($_POST['municipio'] ?? '');
        $filter_type = sanitize_text_field($_POST['filter_type'] ?? '');

        // Mapeo de columnas solicitadas a nombres reales de base de datos si fuera necesario
        // En este caso coinciden casi todas. 'CONTROL' es especial (vacía).
        $available_cols = [
            'REFERENCIA',
            'NIF',
            'RAZON',
            'MUNICIPIOFISC',
            'AGRUPACION_ELECTORAL',
            'DESCRIPCION_EPIGRAFE',
            'EPIGRAFE_LIMPIO',
            'FECHAINICIO',
            'EMAIL_ENRICH',
            'TELEFONO_ENRICH',
            'WEB_ENRICH',
            'MAPS_LINK',
            'SEARCH_DATA'
        ];

        $headers = [];
        $db_cols = [];
        foreach ($requested_columns as $col) {
            $col = strtoupper(sanitize_text_field($col));
            if ($col === 'CONTROL') {
                $headers[] = 'CONTROL';
            } elseif (in_array($col, $available_cols)) {
                $headers[] = $col;
                $db_cols[] = $col;
            }
        }

        if (empty($headers)) {
            wp_send_json_error("Columnas no válidas.");
        }

        $where = "WHERE 1=1";
        $params = [];

        if ($scope === 'all') {
            // No aplicamos filtros
        } else {
            if (!empty($term)) {
                $where .= " AND (RAZON LIKE %s OR NIF LIKE %s OR EPIGRAFE LIKE %s)";
                $wild = '%' . $wpdb->esc_like($term) . '%';
                array_push($params, $wild, $wild, $wild);
            }
            if (!empty($municipio)) {
                $where .= " AND MUNICIPIOFISC LIKE %s";
                $params[] = '%' . $wpdb->esc_like($municipio) . '%';
            }
            if (!empty($filter_type)) {
                switch ($filter_type) {
                    case 'has_email':
                        $where .= " AND EMAIL_ENRICH != '' AND EMAIL_ENRICH IS NOT NULL";
                        break;
                    case 'has_phone':
                        $where .= " AND TELEFONO_ENRICH != '' AND TELEFONO_ENRICH IS NOT NULL";
                        break;
                    case 'has_web':
                        $where .= " AND WEB_ENRICH != '' AND WEB_ENRICH IS NOT NULL";
                        break;
                    case 'has_maps':
                        $where .= " AND MAPS_LINK != '' AND MAPS_LINK IS NOT NULL";
                        break;
                }
            }
        }

        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name $where", $params));
        if (!$total) {
            wp_send_json_error("No se encontraron registros para exportar.");
        }

        $filename = 'censo_export_' . date('Y-m-d_H-i-s') . '.csv';
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $filename;
        $file_url = $upload_dir['baseurl'] . '/' . $filename;

        $fp = fopen($file_path, 'w');
        if (!$fp) {
            wp_send_json_error("Error al crear el archivo CSV.");
        }

        fputs($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($fp, $headers, ';');

        $offset = 0;
        $batch_size = 2000;

        // Si db_cols está vacío (solo exportan 'CONTROL'), evitamos error SQL
        $sql_select = !empty($db_cols) ? '`' . implode('`, `', $db_cols) . '`' : "' ' as DUMMY";

        while ($offset < $total) {
            $sql = "SELECT $sql_select FROM $table_name $where LIMIT %d, %d";
            $prepared_sql = $wpdb->prepare($sql, array_merge($params, [$offset, $batch_size]));
            $results = $wpdb->get_results($prepared_sql, ARRAY_A);

            if (empty($results))
                break;

            foreach ($results as $row) {
                $csv_row = [];
                foreach ($headers as $h) {
                    if ($h === 'CONTROL') {
                        $csv_row[] = '';
                    } else {
                        $val = $row[$h] ?? '';
                        // Eliminar saltos de línea, tabuladores y puntos y coma que rompen la estructura CSV
                        $val = str_replace(["\r", "\n", "\t", ";"], " ", (string) $val);
                        $csv_row[] = trim($val);
                    }
                }
                fputcsv($fp, $csv_row, ';');
            }
            $offset += $batch_size;
            unset($results);
        }

        fclose($fp);
        if (function_exists('ep_stats_log')) {
            ep_stats_log('censo', 'censo_export', null, ['count' => $total]);
        }
        wp_send_json_success(['url' => $file_url, 'new_nonce' => wp_create_nonce('censo_nonce')]);
    }

    /**
     * Renderiza la vista principal según permisos
     */
    public function render_manager_view()
    {
        if (!is_user_logged_in()) {
            return '<p>Debes iniciar sesión.</p>';
        }

        // Enqueue styles
        wp_enqueue_style('dashicons');
        wp_enqueue_style('ep-censo-style', plugin_dir_url(__FILE__) . 'assets/css/censo.css', [], '1.0.1');

        ob_start();

        // Tabs de navegación simple
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'search';

        $can_write_basic = $this->can_write_basic();
        $can_write_total = $this->can_write_total();
        $can_write = $can_write_total;
        $can_enrich = $this->can_enrich_and_import();
        $can_import = $this->can_enrich_and_import();
        $is_admin = current_user_can('manage_options');

        // Vista de Búsqueda
        include plugin_dir_path(__FILE__) . 'censo-search.php';

        // Vista de Configuración (Admin o Editor de empresas)
        if ($can_enrich) {
            include plugin_dir_path(__FILE__) . 'view-censo-settings.php';
        }

        // Vista de Importación
        if ($can_import) {
            include plugin_dir_path(__FILE__) . 'censo-import.php';
        }

        return ob_get_clean();
    }


    /**
     * Maneja la búsqueda AJAX en la base de datos con PAGINACIÓN
     */
    public function handle_search()
    {
        if (ob_get_length()) ob_clean(); // Asegurar JSON limpio
        check_ajax_referer('censo_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        $municipio = isset($_POST['municipio']) ? sanitize_text_field($_POST['municipio']) : '';

        // Paginación
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $limit = isset($_POST['limit']) ? max(1, intval($_POST['limit'])) : 50;
        $offset = ($page - 1) * $limit;

        $query_where = "WHERE 1=1";
        $params = [];

        // 1. Filtro de Municipio
        if (!empty($municipio)) {
            $query_where .= " AND MUNICIPIOFISC LIKE %s";
            $params[] = '%' . $wpdb->esc_like($municipio) . '%';
        }

        // 2. Filtro de KPI
        $filter_type = isset($_POST['filter_type']) ? sanitize_text_field($_POST['filter_type']) : '';
        if ($filter_type === 'has_email') {
            $query_where .= " AND EMAIL_ENRICH IS NOT NULL AND EMAIL_ENRICH != ''";
        } elseif ($filter_type === 'has_phone') {
            $query_where .= " AND TELEFONO_ENRICH IS NOT NULL AND TELEFONO_ENRICH != ''";
        } elseif ($filter_type === 'has_web') {
            $query_where .= " AND WEB_ENRICH IS NOT NULL AND WEB_ENRICH != ''";
        } elseif ($filter_type === 'has_maps') {
            $query_where .= " AND MAPS_LINK IS NOT NULL AND MAPS_LINK != ''";
        } elseif ($filter_type === 'altas_year') {
            $query_where .= " AND FUENTE_IMPORTACION IS NOT NULL AND FUENTE_IMPORTACION != 'Enriquecido-IA'";
        } elseif ($filter_type === 'bajas') {
            $query_where .= " AND ESTADO_INTERNO = 'Baja'";
        }

        // 3. Búsqueda Simplificada
        if (!empty($term)) {
            $query_where .= " AND (RAZON LIKE %s OR NIF LIKE %s OR EPIGRAFE LIKE %s OR EPIGRAFE_LIMPIO LIKE %s OR REFERENCIA LIKE %s)";
            $wild = '%' . $wpdb->esc_like($term) . '%';
            $params[] = $wild; 
            $params[] = $wild; 
            $params[] = $wild; 
            $params[] = $wild;
            $params[] = $wild;
        }

        // 4. Obtener TOTAL
        $query_count = "SELECT COUNT(*) FROM $table_name $query_where";
        $total_sql = empty($params) ? $query_count : $wpdb->prepare($query_count, $params);
        $total = $wpdb->get_var($total_sql);
        $error_count = $wpdb->last_error;

        // 5. Obtener RESULTADOS
        $query_data = "SELECT * FROM $table_name $query_where LIMIT %d OFFSET %d";
        $results_sql = $wpdb->prepare($query_data, array_merge($params, [$limit, $offset]));
        $results = $wpdb->get_results($results_sql);
        $error_results = $wpdb->last_error;

        wp_send_json_success([
            'results' => $results,
            'total' => intval($total),
            'page' => $page,
            'limit' => $limit,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
        exit;
    }






    /**
     * FASE 1: Subida de archivo por chunks (Solo guardar disco)
     */
    public function handle_upload_chunk()
    {
        check_ajax_referer('censo_nonce', 'nonce');

        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No tienes permisos.');
        }

        if (!isset($_FILES['file'])) {
            wp_send_json_error('No se recibió archivo.');
        }

        $session_id = sanitize_text_field($_POST['session_id']);
        $chunk_start = intval($_POST['chunk_start']);

        // Archivo temporal maestro
        $upload_dir = wp_upload_dir();
        // El session_id ahora incluye la extensión del archivo original (ej: sess_123.xlsx)
        $target_file = $upload_dir['basedir'] . '/censo_temp_' . $session_id;

        // Si por alguna razón no tiene extensión, le ponemos .txt por defecto
        if (strpos((string) $session_id, '.') === false) {
            $target_file .= '.txt';
        }

        // Append chunk
        // IMPORTANTE: 'b' force binary mode. Essential on Windows to avoid \n -> \r\n translation
        // which corrupts byte offsets for fseek.
        $mode = ($chunk_start === 0) ? 'wb' : 'ab';
        $fp = fopen($target_file, $mode);
        if (!$fp) {
            wp_send_json_error('No se pudo escribir el archivo temporal.');
        }

        $chunk_content = file_get_contents($_FILES['file']['tmp_name']);
        if ($chunk_content === false) {
            wp_send_json_error('Error leyendo chunk subido.');
        }

        fwrite($fp, $chunk_content);
        fclose($fp);

        wp_send_json_success(['status' => 'uploaded', 'new_nonce' => wp_create_nonce('censo_nonce')]);
    }


    /**
     * Retira los archivos que el módulo va dejando en uploads/.
     *
     * Antes existía una limpieza de temporales que no llamaba nadie, así que en
     * uploads/ se acumularon 2 GB entre las dos instalaciones: temporales de
     * importación de hace seis meses y, sobre todo, exports. El censo se importa
     * una o dos veces al año, pero los exports se piden a diario (32 solo en
     * junio) y pesan unos 8 MB cada uno.
     *
     * El informe de cambios más reciente NO caduca nunca: es el que la pantalla
     * de censo muestra como "última importación" y entre una importación y la
     * siguiente pueden pasar meses.
     */
    public function run_scheduled_cleanup()
    {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'];

        // Temporales de importación: solo viven mientras dura la subida por trozos.
        // Se buscan sin filtrar por extensión porque el nombre lleva la del archivo
        // original (.xlsx, .xls) y el de proceso añade .txt encima.
        $this->delete_older_than(
            glob($path . '/censo_temp_*'),
            (int) apply_filters('ep_censo_temp_ttl', DAY_IN_SECONDS)
        );

        // Exports bajo demanda: el navegador se los descarga en el momento y no
        // vuelven a consultarse. Son volcados del censo con datos de empresas,
        // así que cuanto menos tiempo estén en uploads/, mejor.
        $this->delete_older_than(
            glob($path . '/censo_export_*.csv'),
            (int) apply_filters('ep_censo_export_ttl', 7 * DAY_IN_SECONDS)
        );

        // Informes de cambios: se conserva siempre el más reciente, tenga la edad
        // que tenga, y de los demás se aplica caducidad.
        $reports = glob($path . '/censo_cambios_*.csv');
        if (is_array($reports) && count($reports) > 1) {
            usort($reports, static function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            array_shift($reports);
            $this->delete_older_than(
                $reports,
                (int) apply_filters('ep_censo_report_ttl', 30 * DAY_IN_SECONDS)
            );
        }
    }

    /**
     * Borra los archivos de la lista con más antigüedad que $ttl segundos.
     * Devuelve cuántos se han retirado.
     */
    private function delete_older_than($files, $ttl)
    {
        if (!is_array($files) || $ttl <= 0) {
            return 0;
        }

        $now = time();
        $deleted = 0;
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (($now - filemtime($file)) > $ttl && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Guarda las llaves de API (Solo Admin)
     */
    public function handle_save_api_settings()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import())
            wp_send_json_error('No autorizado');

        update_option(CensoConfig::OPTION_SERPER_KEY, sanitize_text_field($_POST['serper_key']));
        update_option(CensoConfig::OPTION_GEMINI_KEY, sanitize_text_field($_POST['gemini_key']));
        update_option(CensoConfig::OPTION_MAPS_KEY, sanitize_text_field($_POST['maps_key']));

        update_option(CensoConfig::OPTION_MAPS_DAILY_LIMIT, intval($_POST['maps_limit']));
        update_option(CensoConfig::OPTION_MAX_BUDGET, floatval($_POST['max_budget'] ?? 250));

        wp_send_json_success([
            'message' => 'Configuración guardada correctamente.',
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Lógica de enriquecimiento REAL con IA (Serper + Gemini)
     */
    public function handle_enrich_batch()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No autorizado');
        }

        // Evitar timeouts y abortos
        set_time_limit(0);
        ignore_user_abort(true);

        // Bloqueo de concurrencia (Lock)
        if (get_transient('censo_worker_lock')) {
            wp_send_json_success([
                'waiting' => true,
                'message' => 'Lote en proceso por otro worker...',
                'new_nonce' => wp_create_nonce('censo_nonce')
            ]);
        }
        set_transient('censo_worker_lock', true, 60); // 1 minuto de bloqueo máximo por lote

        require_once 'class-censo-agent.php';
        $agent = new CensoAgent();

        // 0. Control de Presupuesto (Hard Stop)
        $max_budget = floatval(get_option(CensoConfig::OPTION_MAX_BUDGET, 250));
        $serper_usage = (int) get_option(CensoConfig::OPTION_SERPER_USAGE, 0);
        $gemini_usage = (int) get_option(CensoConfig::OPTION_GEMINI_USAGE, 0);
        // Coste Serper (1$ / 1k) + Gemini (0.15$ / 1k records)
        $est_cost = ($serper_usage * 0.001) + ($gemini_usage * 5 * 0.00015);

        if ($est_cost >= $max_budget) {
            delete_transient('censo_worker_lock');
            wp_send_json_error("Límite de presupuesto alcanzado ($max_budget €). El proceso se ha detenido por seguridad.");
        }

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // 0. Auto-reseteo mejorado: 
        // a) Si están 'Processing' hace > 5 min
        $wpdb->query("UPDATE $table_name SET ENRICH_STATUS = 'Pending', ENRICH_LOG = 'Auto-reset (timeout)' WHERE ENRICH_STATUS = 'Processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

        // b) Si están 'Error' por fallos genéricos de la IA (JSON inválido, timeout de red), reintentar una vez
        $wpdb->query("UPDATE $table_name SET ENRICH_STATUS = 'Pending', ENRICH_LOG = 'Auto-retry' WHERE ENRICH_STATUS = 'Error' AND (ENRICH_LOG LIKE '%JSON%' OR ENRICH_LOG LIKE '%timeout%' OR ENRICH_LOG IS NULL)");

        // Rate Limit Protection: Esperar un poco entre lotes para no saturar Serper/Gemini
        usleep(500000); // 0.5 segundos de pausa

        // 1. Obtener registros pendientes (Incluyendo NIF para mejor matching)
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Pending' OR ENRICH_STATUS IS NULL");
        $error_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Error'");
        $enriched_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Enriched'");
        $notfound_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Not Found'");

        ep_error_log("Censo Worker Stats - Pending: $pending_count, Errors: $error_count, Enriched: $enriched_count, Not Found: $notfound_count");

        $records_obj = $wpdb->get_results("SELECT id, NIF, RAZON, MUNICIPIOFISC FROM $table_name WHERE ENRICH_STATUS = 'Pending' OR ENRICH_STATUS IS NULL LIMIT 10");

        if (empty($records_obj)) {
            // Verificar si quedan registros en 'Processing' (que aún no han expirado)
            $processing_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Processing'");
            ep_error_log("Censo Worker - No pending records found. Processing count: $processing_count");

            if ($processing_count > 0) {
                wp_send_json_success([
                    'finished' => false,
                    'count' => 0,
                    'waiting' => true,
                    'new_nonce' => wp_create_nonce('censo_nonce')
                ]);
            }
            wp_send_json_success([
                'finished' => true,
                'count' => 0,
                'new_nonce' => wp_create_nonce('censo_nonce')
            ]);
        }

        // 2. Marcarlos como 'Processing' inmediatamente para que otros workers no los cojan
        $ids = wp_list_pluck($records_obj, 'id');
        $wpdb->query("UPDATE $table_name SET ENRICH_STATUS = 'Processing' WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")");

        // 3. Preparar array para el agente
        $records_array = [];
        foreach ($records_obj as $row) {
            $records_array[] = [
                'id' => $row->id,
                'NIF' => $row->NIF,
                'RAZON' => $row->RAZON,
                'MUNICIPIOFISC' => $row->MUNICIPIOFISC
            ];
        }

        // 4. Llamar al Agente (Batch Serper + Batch Gemini)
        $batch_data = $agent->enrich_batch($records_array);

        if (is_array($batch_data) && isset($batch_data['error'])) {
            $error_msg = $batch_data['error'];
            $wpdb->query($wpdb->prepare("UPDATE $table_name SET ENRICH_STATUS = 'Error', ENRICH_LOG = %s WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")", $error_msg));
            delete_transient('censo_worker_lock');
            wp_send_json_error($error_msg);
        }

        // Limpieza de booleanos (a veces vienen como strings) y asegurar formato
        $cleaned_batch_data = [];
        if (is_array($batch_data)) {
            foreach ($batch_data as $item) {
                // Protección adicional: asegurar que $item sea un array antes de acceder
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['has_maps'])) {
                    $item['has_maps'] = ($item['has_maps'] === true || $item['has_maps'] === 'true' || $item['has_maps'] === 1);
                }
                $cleaned_batch_data[] = $item;
            }
        }
        $batch_data = $cleaned_batch_data;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            ep_error_log("Censo Batch Data from Agent: " . print_r($batch_data, true));
        }

        // 5. Procesar resultados y guardar en DB
        $count = 0;
        $enrich_changes = [];
        foreach ($batch_data as $idx => $data) {
            if (!isset($records_array[$idx]))
                continue; // Seguridad

            $original_id = $records_array[$idx]['id'];
            $razon = $records_array[$idx]['RAZON'];
            $mun = $records_array[$idx]['MUNICIPIOFISC'];

            if (isset($data['error'])) {
                $wpdb->update($table_name, [
                    'ENRICH_STATUS' => 'Error',
                    'ENRICH_LOG' => $data['error']
                ], ['id' => $original_id]);
                continue;
            }

            $status = ($data['status'] ?? '') === 'Not Found' ? 'Not Found' : 'Enriched';

            // Detectar Maps del objeto JSON o del fallback
            $has_maps = !empty($data['has_maps']) && ($data['has_maps'] === true || $data['has_maps'] === 'true');

            // Si el agente nos da un CID, lo usamos para el link directo (más fiable)
            $maps_link = null;
            if (!empty($data['cid'])) {
                $cid = preg_replace('/[^0-9]/', '', (string) $data['cid']);
                if ($cid) {
                    $maps_link = "https://www.google.com/maps?cid=" . $cid;
                }
            }

            if (!$maps_link && $has_maps) {
                // Search link como fallback si hay maps pero no CID
                $maps_link = 'https://www.google.com/maps/search/' . urlencode($razon . ' ' . $mun);
            }

            // PROTECCIÓN: Leer datos actuales para no sobrescribir datos buenos con vacíos
            $existing = $wpdb->get_row($wpdb->prepare("SELECT EMAIL_ENRICH, TELEFONO_ENRICH, WEB_ENRICH FROM $table_name WHERE id = %d", $original_id));

            $new_email = $data['email'] ?? '';
            $new_phone = $data['phone'] ?? '';
            $new_web = $data['web'] ?? '';

            // VALIDACIÓN INTELIGENTE: solo sobrescribir si el dato nuevo es válido
            // y el existente NO es válido (o está vacío)
            $final_email = $this->smart_merge_field($existing->EMAIL_ENRICH ?? '', $new_email, 'email');
            $final_phone = $this->smart_merge_field($existing->TELEFONO_ENRICH ?? '', $new_phone, 'phone');
            $final_web = $this->smart_merge_field($existing->WEB_ENRICH ?? '', $new_web, 'web');

            $update_data = [
                'EMAIL_ENRICH'        => $final_email,
                'TELEFONO_ENRICH'     => $final_phone,
                'WEB_ENRICH'          => $final_web,
                'MAPS_LINK'           => $maps_link,
                'SEARCH_DATA'         => $data['search_data'] ?? '',
                'AGRUPACION_ELECTORAL'=> $this->get_agrupacion_electoral($records_array[$idx]['EPIGRAFE_LIMPIO'] ?? ''),
                'ENRICH_STATUS'       => $status,
                'ENRICH_LOG'          => $status === 'Not Found' ? 'No se encontraron resultados en búsqueda' : 'Éxito'
            ];

            $wpdb->update($table_name, $update_data, ['id' => $original_id]);
            $count++;

            // Registrar en CSV de enriquecimiento
            $enrich_changes[] = [
                'ENRIQUECIMIENTO',
                '', // REFERENCIA no disponible aquí
                $records_array[$idx]['NIF'] ?? '',
                $records_array[$idx]['RAZON'] ?? '',
                implode(' | ', array_filter([
                    !empty($final_email)  ? "Email: $final_email"  : '',
                    !empty($final_phone)  ? "Tel: $final_phone"    : '',
                    !empty($final_web)    ? "Web: $final_web"      : '',
                    $maps_link            ? "Maps: OK"             : ''
                ])),
                '', '',
                current_time('Y-m-d H:i:s')
            ];
        }

        // Guardar CSV de enriquecimiento (acumulativo por día)
        if (!empty($enrich_changes)) {
            $upload_dir_e = wp_upload_dir();
            $enrich_report_file = $upload_dir_e['basedir'] . '/censo_enriquecimiento_' . date('Y-m-d') . '.csv';
            $enrich_report_url  = $upload_dir_e['baseurl']  . '/censo_enriquecimiento_' . date('Y-m-d') . '.csv';
            $write_header = !file_exists($enrich_report_file);
            $fp_e = fopen($enrich_report_file, 'a');
            if ($fp_e) {
                if ($write_header) {
                    fputs($fp_e, chr(0xEF) . chr(0xBB) . chr(0xBF));
                    fputcsv($fp_e, ['TIPO', 'REFERENCIA', 'NIF', 'RAZON', 'DATOS_ENCONTRADOS', 'VALOR_ANTERIOR', 'VALOR_NUEVO', 'FECHA'], ';');
                }
                foreach ($enrich_changes as $row) fputcsv($fp_e, $row, ';');
                fclose($fp_e);
            }
        } else {
            $enrich_report_url = null;
        }

        // Verificar si quedan más pendientes
        $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ENRICH_STATUS = 'Pending' OR ENRICH_STATUS IS NULL");

        // Liberar bloqueo
        delete_transient('censo_worker_lock');

        if (function_exists('ep_stats_log')) {
            ep_stats_log('censo', 'censo_enrichment', null, ['count' => count($records_obj)]);
        }

        wp_send_json_success([
            'count'       => count($records_obj),
            'processed'   => array_map(function ($r) { return ['razon' => $r->RAZON, 'nif' => $r->NIF]; }, $records_obj),
            'remaining'   => (int) $remaining,
            'finished'    => ($remaining == 0),
            'report_url'  => $enrich_report_url ?? null,
            'new_nonce'   => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Resetea registros con estado 'Error' a 'Pending' para re-intentar
     */
    public function handle_reset_errors()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No autorizado');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // SOLO reseteamos errores puros - NO tocamos los Enriched sin datos
        // (esos se manejan por separado con protección anti-sobrescritura)
        $count = $wpdb->query("
            UPDATE $table_name 
            SET ENRICH_STATUS = 'Pending', ENRICH_LOG = 'Reset manual (solo errores)' 
            WHERE ENRICH_STATUS = 'Error'
        ");

        wp_send_json_success([
            'count' => $count,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Resetea un lote de 500 registros 'Not Found' a 'Pending' para probar mejoras de IA
     */
    public function handle_reset_notfound()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No autorizado');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // Cogemos 500 registros que NO fueron encontrados y los volvemos a poner en Pending
        $count = $wpdb->query("
            UPDATE $table_name 
            SET ENRICH_STATUS = 'Pending', ENRICH_LOG = 'Reset for testing' 
            WHERE ENRICH_STATUS = 'Not Found' 
            LIMIT 500
        ");

        wp_send_json_success([
            'count' => (int) $count,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Alterna el estado del worker de fondo
     */
    public function handle_toggle_worker()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import())
            wp_send_json_error('No autorizado');

        $active = !empty($_POST['active']) && $_POST['active'] === 'true';
        update_option(CensoConfig::OPTION_WORKER_STATUS, $active ? 'active' : 'stopped');

        if ($active) {
            if (!wp_next_scheduled('censo_worker_cron_event')) {
                wp_schedule_event(time(), 'every_minute', 'censo_worker_cron_event');
            }
        } else {
            $timestamp = wp_next_scheduled('censo_worker_cron_event');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'censo_worker_cron_event');
            }
        }

        wp_send_json_success([
            'status' => $active ? 'active' : 'stopped',
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Función ejecutada por el Cron para procesar lotes
     */
    public function run_background_worker()
    {
        $status = get_option(CensoConfig::OPTION_WORKER_STATUS);
        if ($status !== 'active')
            return;

        ep_error_log("Censo IAE: Background Worker Pulse...");

        // Usamos la misma lógica que el batch de enriquecimiento pero sin salida JSON
        require_once 'class-censo-agent.php';
        $agent = new CensoAgent();

        // 0. Control de Presupuesto (Hard Stop)
        $max_budget = floatval(get_option(CensoConfig::OPTION_MAX_BUDGET, 250));
        $serper_usage = (int) get_option(CensoConfig::OPTION_SERPER_USAGE, 0);
        $gemini_usage = (int) get_option(CensoConfig::OPTION_GEMINI_USAGE, 0);
        $est_cost = ($serper_usage * 0.001) + ($gemini_usage * 5 * 0.00015);

        if ($est_cost >= $max_budget) {
            ep_error_log("Censo IAE: Límite de presupuesto ($max_budget €) alcanzado. Deteniendo worker.");
            update_option(CensoConfig::OPTION_WORKER_STATUS, 'stopped');
            $timestamp = wp_next_scheduled('censo_worker_cron_event');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'censo_worker_cron_event');
            }
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // Auto-reseteo y limpieza
        $wpdb->query("UPDATE $table_name SET ENRICH_STATUS = 'Pending', ENRICH_LOG = NULL WHERE ENRICH_STATUS = 'Processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

        // Lote de 10 para mayor velocidad en GCP
        $records_obj = $wpdb->get_results("SELECT id, NIF, RAZON, MUNICIPIOFISC FROM $table_name WHERE ENRICH_STATUS = 'Pending' OR ENRICH_STATUS IS NULL LIMIT 10");

        if (empty($records_obj)) {
            // Si no hay nada, paramos el worker para ahorrar recursos
            update_option(CensoConfig::OPTION_WORKER_STATUS, 'completed');
            $timestamp = wp_next_scheduled('censo_worker_cron_event');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'censo_worker_cron_event');
            }
            return;
        }

        $ids = wp_list_pluck($records_obj, 'id');
        $wpdb->query("UPDATE $table_name SET ENRICH_STATUS = 'Processing' WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")");

        $records_array = [];
        foreach ($records_obj as $row) {
            $records_array[] = [
                'id' => $row->id,
                'NIF' => $row->NIF,
                'RAZON' => $row->RAZON,
                'MUNICIPIOFISC' => $row->MUNICIPIOFISC
            ];
        }

        $batch_data = $agent->enrich_batch($records_array);

        if (isset($batch_data['error'])) {
            $error_msg = $batch_data['error'];
            $wpdb->query($wpdb->prepare("UPDATE $table_name SET ENRICH_STATUS = 'Error', ENRICH_LOG = %s WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")", $error_msg));
            return;
        }

        foreach ($batch_data as $idx => $data) {
            if (!isset($records_array[$idx]))
                continue;

            $original_id = $records_array[$idx]['id'];
            $razon = $records_array[$idx]['RAZON'];
            $mun = $records_array[$idx]['MUNICIPIOFISC'];

            if (isset($data['error'])) {
                $wpdb->update($table_name, ['ENRICH_STATUS' => 'Error', 'ENRICH_LOG' => $data['error']], ['id' => $original_id]);
                continue;
            }

            $st = ($data['status'] ?? '') === 'Not Found' ? 'Not Found' : 'Enriched';
            $has_maps_cron = !empty($data['has_maps']) && $data['has_maps'] === true;

            // PROTECCIÓN: Leer datos actuales para no sobrescribir datos buenos con vacíos
            $existing = $wpdb->get_row($wpdb->prepare("SELECT EMAIL_ENRICH, TELEFONO_ENRICH, WEB_ENRICH FROM $table_name WHERE id = %d", $original_id));

            $new_email = $data['email'] ?? '';
            $new_phone = $data['phone'] ?? '';
            $new_web = $data['web'] ?? '';

            // VALIDACIÓN INTELIGENTE: solo sobrescribir si el dato nuevo es válido
            $final_email = $this->smart_merge_field($existing->EMAIL_ENRICH ?? '', $new_email, 'email');
            $final_phone = $this->smart_merge_field($existing->TELEFONO_ENRICH ?? '', $new_phone, 'phone');
            $final_web = $this->smart_merge_field($existing->WEB_ENRICH ?? '', $new_web, 'web');

            $wpdb->update($table_name, [
                'EMAIL_ENRICH' => $final_email,
                'TELEFONO_ENRICH' => $final_phone,
                'WEB_ENRICH' => $final_web,
                'MAPS_LINK' => $has_maps_cron ? 'https://www.google.com/maps/search/' . urlencode($razon . ' ' . $mun) : null,
                'SEARCH_DATA' => $data['search_data'] ?? '',
                'ENRICH_STATUS' => $st,
                'ENRICH_LOG' => $st === 'Not Found' ? 'No se encontraron resultados' : 'Éxito (Cron)'
            ], ['id' => $original_id]);
        }
    }

    /**
     * Añade intervalos personalizados al cron
     */
    public function add_cron_intervals($schedules)
    {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => 'Cada minuto'
        ];
        return $schedules;
    }

    /**
     * Obtiene los metadatos de búsqueda de un registro específico
     */
    public function handle_get_search_data()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_read()) {
            wp_send_json_error('No autorizado');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;
        $id = intval($_POST['id']);

        $row = $wpdb->get_row($wpdb->prepare("SELECT SEARCH_DATA, MAPS_LINK FROM $table_name WHERE id = %d", $id));

        wp_send_json_success([
            'search_data' => $row->SEARCH_DATA,
            'maps_link' => $row->MAPS_LINK,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * FASE 9: Mapeo inteligente con IA
     */
    public function handle_ai_map_headers()
    {
        check_ajax_referer('censo_nonce', 'nonce');

        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No tienes permisos.');
        }

        set_time_limit(120); // Aumentar a 2 minutos
        @ini_set('memory_limit', '512M'); // Aumentar memoria para archivos Excel grandes

        $session_id = sanitize_text_field($_POST['session_id']);
        $upload_dir = wp_upload_dir();
        $temp_path = $upload_dir['basedir'] . '/censo_temp_' . $session_id;

        if (!file_exists($temp_path)) {
            error_log("Censo: Archivo de mapeo no encontrado: " . $temp_path);
            wp_send_json_error('Archivo no encontrado.');
        }

        $extension = strtolower(pathinfo($temp_path, PATHINFO_EXTENSION));
        error_log("Censo: Procesando extensión: " . $extension);
        $headers = [];
        $samples = [];
        $delimiter = ",";

        if ($extension === 'xlsx' || $extension === 'xls') {
            error_log("Censo: Intentando parsear Excel con SimpleXLSX");
            if ($xlsx = SimpleXLSX::parse($temp_path)) {
                error_log("Censo: Excel parseado. Hojas encontradas: " . implode(', ', $xlsx->sheetNames()));
                $rows = [];
                $rowCount = 0;
                foreach ($xlsx->readRows(0, 6) as $r) {
                    $rows[] = $r;
                    $rowCount++;
                }
                error_log("Censo: Excel parseado. Filas leídas para cabeceras: " . $rowCount);
                $headers = array_shift($rows);
                $samples = $rows;
                if ($headers) {
                    error_log("Censo: Cabeceras detectadas (Excel): " . implode(', ', $headers));
                } else {
                    error_log("Censo: No se encontraron cabeceras en el Excel.");
                }
            } else {
                error_log("Censo: Error en SimpleXLSX::parse. Posible archivo corrupto.");
                wp_send_json_error('Error al leer el archivo Excel.');
            }
        } else {
            $file = fopen($temp_path, 'r');
            if (!$file) {
                wp_send_json_error('No se pudo abrir el archivo.');
            }

            // Leer primera fila (cabeceras)
            $headers_line = fgets($file);
            if (!$headers_line) {
                fclose($file);
                wp_send_json_error('Archivo vacío.');
            }

            // Detectar delimitador (Tab o Coma/Punto-y-coma)
            $delimiter = ",";
            if (strpos((string) $headers_line, "\t") !== false)
                $delimiter = "\t";
            elseif (strpos((string) $headers_line, ";") !== false)
                $delimiter = ";";

            // Detección de Formato (Delimitado o Ancho Fijo) siguiendo la lógica del Parser
            // $extension ya está definida al inicio de la función desde el temp_path
            $is_txt_dat = in_array($extension, ['txt', 'dat']);

            // Si es TXT/DAT o si no tiene delimitadores claros en una línea larga
            $is_delimited = ($delimiter !== null && (strlen($headers_line) < 200 || count(explode($delimiter, $headers_line)) > 5));

            if (!$is_delimited || $is_txt_dat) {
                error_log("Censo: Detectado archivo Legado (Ext: $extension, Delimited: " . ($is_delimited ? 'SI' : 'NO') . "). Saltando mapeo IA.");
                wp_send_json_success([
                    'mapping' => [],
                    'all_headers' => [],
                    'internal_fields' => CensoConfig::get_field_definitions(),
                    'new_nonce' => wp_create_nonce('censo_nonce'),
                    'is_fixed_width' => true
                ]);
            }

            rewind($file);
            $headers = fgetcsv($file, 0, $delimiter, '"', "\\");

            // Leer 5 filas de ejemplo
            for ($i = 0; $i < 5; $i++) {
                $row = fgetcsv($file, 0, $delimiter, '"', "\\");
                if ($row)
                    $samples[] = $row;
            }
            fclose($file);
        }

        if (empty($headers)) {
            error_log("Censo: Error final - No se detectaron cabeceras. Samples count: " . count($samples));
            wp_send_json_error('No se detectaron cabeceras.');
        }

        // Llamar a la IA
        require_once plugin_dir_path(__FILE__) . 'class-censo-agent.php';
        $agent = new CensoAgent();
        $mapping = $agent->map_csv_headers($headers, $samples);
        error_log("Censo: Proceso de mapeo IA finalizado. Mapeo generado: " . ($mapping ? json_encode($mapping) : 'FALLIDO'));

        if (!$mapping) {
            error_log("Censo: La IA no pudo determinar el mapeo automáticamente. Headers: " . implode(', ', $headers));
            wp_send_json_error('La IA no pudo determinar el mapeo automáticamente.');
        }

        error_log("Censo: Enviando respuesta AJAX exitosa con mapeo e internal_fields.");
        wp_send_json_success([
            'mapping' => $mapping,
            'all_headers' => $headers,
            'internal_fields' => CensoConfig::get_field_definitions(),
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Maneja el procesamiento por lotes del archivo de importación.
     */
    public function handle_process_batch()
    {
        check_ajax_referer('censo_nonce', 'nonce');

        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No tienes permisos.');
        }

        @set_time_limit(300); // 5 minutos por lote
        @ini_set('memory_limit', '512M');

        $session_id = sanitize_text_field($_POST['session_id']);
        $year = intval($_POST['year']);
        $mode = sanitize_text_field($_POST['mode']);

        // El mapeo puede venir como objeto o como string JSON (para evitar bloqueos de WAF)
        $mapping = [];
        if (isset($_POST['mapping'])) {
            if (is_string($_POST['mapping'])) {
                $mapping = json_decode(stripslashes($_POST['mapping']), true);
            } else {
                $mapping = (array) $_POST['mapping'];
            }
        }

        if (!is_array($mapping))
            $mapping = [];

        $file_pos = intval($_POST['file_pos']);
        $batch_size = 500; // Reducido para mayor estabilidad
        $new_nonce = wp_create_nonce('censo_nonce');

        $upload_dir = wp_upload_dir();
        // El archivo final de procesamiento siempre será un .txt (CSV)
        $processing_path = $upload_dir['basedir'] . '/censo_temp_' . $session_id . '.txt';
        $original_path = $upload_dir['basedir'] . '/censo_temp_' . $session_id;

        // Si es el inicio, forzamos recreación del archivo de procesamiento si es Excel
        if ($file_pos === 0) {
            $ext = strtolower(pathinfo($original_path, PATHINFO_EXTENSION));
            if (($ext === 'xlsx' || $ext === 'xls') && file_exists($processing_path)) {
                unlink($processing_path);
                error_log("Censo: Eliminado archivo temporal previo para reconversión limpia.");
            }
        }

        // Si el archivo original es Excel y no existe la conversión, convertirlo
        if (!file_exists($processing_path)) {
            $ext = strtolower(pathinfo($original_path, PATHINFO_EXTENSION));
            if ($ext === 'xlsx' || $ext === 'xls') {
                if (file_exists($original_path)) {
                    if ($xlsx = SimpleXLSX::parse($original_path)) {
                        $fp = fopen($processing_path, 'w');
                        foreach ($xlsx->readRows() as $row) {
                            fputcsv($fp, $row, ",", "\"", "\\");
                        }
                        fclose($fp);
                    } else {
                        wp_send_json_error('Error al convertir el archivo Excel.');
                    }
                } else {
                    wp_send_json_error('Archivo original Excel no encontrado.');
                }
            } else {
                // Si no es Excel, el processing_path debería ser el mismo que original_path
                // Pero para mantener la consistencia en el resto de la función:
                $processing_path = $original_path;
            }
        }

        if (!file_exists($processing_path)) {
            wp_send_json_error('Archivo temporal no encontrado.');
        }

        $file_handle = fopen($processing_path, 'r');
        if (!$file_handle) {
            wp_send_json_error('No se pudo abrir el archivo de procesamiento.');
        }
        fseek($file_handle, $file_pos);

        // Detectar delimitador si no es ancho fijo
        $delimiter = ","; // Valor por defecto
        if (!empty($mapping)) {
            // Intentamos detectar el delimitador de la primera línea si file_pos es 0
            if ($file_pos === 0) {
                $check_line = fgets($file_handle);
                if ($check_line) {
                    if (strpos($check_line, "\t") !== false)
                        $delimiter = "\t";
                    elseif (strpos($check_line, ";") !== false)
                        $delimiter = ";";
                }
                rewind($file_handle);
            } else {
                // Si no es el inicio, intentamos deducir el delimitador o usamos coma por defecto
                // En un entorno real, esto debería persistirse en la sesión, pero el .txt generado
                // por SimpleXLSX siempre usa coma.
                $delimiter = ",";
            }
        }

        if ($file_pos === 0) {
            error_log("Censo: Delimitador detectado: " . $delimiter);
        }

        // Si es el inicio (filePos 0) y hay mapeo, saltar cabecera usando fgetcsv para ser precisos
        if ($file_pos === 0 && !empty($mapping)) {
            $skipped = fgetcsv($file_handle, 0, $delimiter, "\"", "\\");
            error_log("Censo: Cabecera saltada: " . ($skipped ? implode('|', $skipped) : 'FALLO'));
        }

        $records_to_process = [];
        for ($i = 0; $i < $batch_size; $i++) {
            if (!empty($mapping)) {
                // MODO CSV/EXCEL: Usamos fgetcsv para soportar saltos de línea dentro de celdas
                $row = fgetcsv($file_handle, 0, $delimiter, "\"", "\\");
                if ($row === false)
                    break;
                // Limpieza de espacios en celdas para evitar fallos de mapeo
                $records_to_process[] = array_map('trim', $row);
            } else {
                // MODO ANCHO FIJO: Seguimos usando fgets
                $line = fgets($file_handle);
                if ($line === false)
                    break;
                $records_to_process[] = $line;
            }
        }

        $new_file_pos = ftell($file_handle);
        $total_size = filesize($processing_path);
        fclose($file_handle);

        $is_done = ($new_file_pos >= $total_size || empty($records_to_process));

        if ($file_pos === 0) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            require_once plugin_dir_path(__FILE__) . 'class-censo-db.php';
            $censo_db = new CensoDB();
            $censo_db->create_table(); // dbDelta crea la columna si no existe

            global $wpdb;
            $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;
            $col = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'FUENTE_IMPORTACION'");
            if ($col) {
                $wpdb->query("ALTER TABLE $table_name MODIFY FUENTE_IMPORTACION varchar(255) DEFAULT NULL;");
            } else {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN FUENTE_IMPORTACION varchar(255) DEFAULT NULL;");
            }
            // Ampliar RAZON por NIFs UTE o Asociaciones con nombres extralargos (evita truncation error 1406)
            $wpdb->query("ALTER TABLE $table_name MODIFY RAZON varchar(255) DEFAULT NULL;");
        }

        if ($mode === 'truncate' && $file_pos === 0) {
            error_log("Censo: Iniciando limpieza de tabla (modo truncate)...");
            $this->db->truncate_iae_table();
        } elseif ($file_pos === 0) {
            error_log("Censo: Iniciando importación en modo: $mode (sin limpieza previa)");
        }

        $stats = [
            'inserted' => 0,
            'updated' => 0,
            'ignored' => 0,
            'errors' => 0,
            'bajas' => 0
        ];

        $import_type = sanitize_text_field($_POST['import_type'] ?? 'enrichment');

        // CSV de cambios: ruta del archivo acumulativo por sesión
        $upload_dir = wp_upload_dir();
        $report_filename = 'censo_cambios_' . $session_id . '.csv';
        $report_path = $upload_dir['basedir'] . '/' . $report_filename;
        $report_url  = $upload_dir['baseurl'] . '/' . $report_filename;

        // Cabecera del CSV solo en el primer batch (file_pos === 0)
        if ($file_pos === 0 && $mode !== 'truncate') {
            $fp_report = fopen($report_path, 'w');
            if ($fp_report) {
                fputs($fp_report, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
                fputcsv($fp_report, ['TIPO', 'REFERENCIA', 'NIF', 'RAZON', 'CAMPO', 'VALOR_ANTERIOR', 'VALOR_NUEVO', 'FECHA'], ';');
                fclose($fp_report);
            }
        }

        $change_rows = [];
        $now_str = current_time('Y-m-d H:i:s');

        $original_filename = isset($_POST['original_filename']) ? sanitize_text_field($_POST['original_filename']) : '';

        foreach ($records_to_process as $item) {
            $parsed_data = $this->parse_line_with_mapping($item, $year, $mapping, $delimiter, $processing_path, $import_type);
            if ($parsed_data) {
                $parsed_data['_import_type'] = $import_type;
                $parsed_data['_original_filename'] = $original_filename;
            }

            if (!$parsed_data) {
                $stats['errors']++;
                continue;
            }

            if ($mode === 'truncate') {
                $parsed_data['ULTIMA_IMPORTACION'] = current_time('mysql');
                $parsed_data['ESTADO_INTERNO'] = 'Activo';
                $parsed_data['AGRUPACION_ELECTORAL'] = $this->get_agrupacion_electoral($parsed_data['EPIGRAFE_LIMPIO'] ?? '');
                
                if (!empty($original_filename)) {
                    $parsed_data['FUENTE_IMPORTACION'] = substr($original_filename, 0, 255);
                } else {
                    $parsed_data['FUENTE_IMPORTACION'] = ($import_type === 'standard') ? 'TXT-AEAT' : 'CSV-Enriquecimiento';
                }

                if ($this->db->insert_iae($parsed_data)) {
                    $stats['inserted']++;
                } else {
                    $stats['errors']++;
                }
            } else {
                $res = $this->process_merge_line_with_data($parsed_data, $year);
                $action = is_array($res) ? $res['action'] : $res;

                if ($action === 'inserted') {
                    $stats['inserted']++;
                    $change_rows[] = [
                        'ALTA',
                        $parsed_data['REFERENCIA'] ?? '',
                        $parsed_data['NIF'] ?? '',
                        $parsed_data['RAZON'] ?? '',
                        'NUEVO_REGISTRO', '', '', $now_str
                    ];
                } elseif ($action === 'updated') {
                    $stats['updated']++;
                    if (is_array($res) && !empty($res['changes'])) {
                        foreach ($res['changes'] as $field => $vals) {
                            $change_rows[] = [
                                'ACTUALIZACIÓN',
                                $parsed_data['REFERENCIA'] ?? '',
                                $parsed_data['NIF'] ?? '',
                                $parsed_data['RAZON'] ?? '',
                                $field,
                                $vals['old'],
                                $vals['new'],
                                $now_str
                            ];
                        }
                    }
                } elseif ($action === 'baja') {
                    $stats['bajas']++;
                    $change_rows[] = [
                        'BAJA',
                        $parsed_data['REFERENCIA'] ?? '',
                        $parsed_data['NIF'] ?? '',
                        $parsed_data['RAZON'] ?? '',
                        'ESTADO_INTERNO', 'Activo', 'Baja', $now_str
                    ];
                } elseif ($action === 'none') {
                    $stats['ignored']++;
                } elseif ($action === 'error') {
                    $stats['errors']++;
                    error_log("Censo: Fallo en process_merge_line_with_data para registro: " . ($parsed_data['NIF'] ?? 'SIN NIF'));
                }
            }
        }

        // Volcar filas de cambio al CSV acumulativo
        if (!empty($change_rows)) {
            $fp_report = fopen($report_path, 'a');
            if ($fp_report) {
                foreach ($change_rows as $row) {
                    fputcsv($fp_report, $row, ';');
                }
                fclose($fp_report);
            }
        }

        if ($is_done && function_exists('ep_stats_log')) {
            ep_stats_log('censo', 'censo_import', null, ['filename' => basename($processing_path)]);
        }

        // Solo devolver report_url al terminar y si hay cambios reales
        $final_report_url = null;
        if ($is_done && $mode !== 'truncate' && file_exists($report_path) && filesize($report_path) > 5) {
            $final_report_url = $report_url;
        }

        wp_send_json_success([
            'is_done'          => $is_done,
            'new_file_pos'     => $new_file_pos,
            'progress_percent' => round(($new_file_pos / $total_size) * 100),
            'stats'            => $stats,
            'report_url'       => $final_report_url,
            'new_nonce'        => $new_nonce
        ]);
    }

    /**
     * Helper centralizado para parsear una línea (Fixed o CSV con mapeo)
     */
    private function parse_line_with_mapping($input, $year, $mapping = [], $delimiter = null, $processing_path = null, $import_type = 'enrichment')
    {
        if (empty($mapping)) {
            return $this->parser->parse_line($input, $year, $import_type);
        }

        // Si input ya es un array (proviene de fgetcsv), lo usamos directamente
        $csv_row = is_array($input) ? $input : str_getcsv($input, $delimiter ? $delimiter : ",", "\"", "\\");
        static $col_indexes = null;
        if ($col_indexes === null) {
            $headers = $this->get_file_headers($delimiter, $processing_path);
            $col_indexes = !empty($headers) ? array_flip($headers) : [];
        }

        $data = [];
        foreach ($mapping as $internal_field => $csv_col) {
            $idx = $col_indexes[$csv_col] ?? -1;
            if ($idx !== -1) {
                $data[$internal_field] = $csv_row[$idx] ?? '';
            }
        }

        if (empty($data['REFERENCIA']) && empty($data['NIF'])) {
            error_log("Censo: Registro omitido - No se encontró NIF o REFERENCIA. CSV Row: " . implode('|', $csv_row));
            // Log de depuración: ver qué buscábamos y qué había en col_indexes
            if (empty($col_indexes)) {
                error_log("Censo: col_indexes está vacío!");
            }
            return false;
        }

        $data['EJERCICIO'] = $year;
        return $data;
    }

    /**
     * Procesa la lógica de MERGE sobre datos ya parseados.
     * Retorna un array ['action' => string, 'changes' => array] en modo merge
     * o un string simple ('inserted'/'error') para compatibilidad.
     */
    private function process_merge_line_with_data($data, $year)
    {
        $data['ULTIMA_IMPORTACION'] = current_time('mysql');

        $is_baja = (!empty($data['FECHACESE']) || !empty($data['MOTIVOBAJA']));
        $data['ESTADO_INTERNO'] = $is_baja ? 'Baja' : 'Activo';

        $import_type = $data['_import_type'] ?? 'enrichment';
        $original_filename = $data['_original_filename'] ?? '';
        unset($data['_import_type'], $data['_original_filename']);

        // Campos que no queremos en el informe de cambios (metadatos internos)
        $skip_diff_fields = ['ULTIMA_IMPORTACION', 'updated_at', 'SEARCH_DATA', 'ENRICH_STATUS', 'ENRICH_LOG'];

        $existing = null;

        if (!empty($data['REFERENCIA'])) {
            $existing = $this->db->get_by_referencia($data['REFERENCIA']);
        }

        if (!$existing || $import_type === 'enrichment') {
            $by_nif = !empty($data['NIF']) ? $this->db->get_by_nif($data['NIF']) : null;
            if ($by_nif) {
                $existing = $by_nif;
            } elseif (!empty($data['RAZON'])) {
                $existing = $this->db->get_by_razon($data['RAZON']);
            }
        }

        if ($existing) {
            if (!empty($data['REFERENCIA']) && (!isset($existing['REFERENCIA']) || $existing['REFERENCIA'] !== $data['REFERENCIA'])) {
                $collision = $this->db->get_by_referencia($data['REFERENCIA']);
                if ($collision && $collision['id'] !== $existing['id']) {
                    error_log("Censo: Reference collision detected for NIF: " . ($data['NIF'] ?? 'Unknown') . ". File Ref: " . $data['REFERENCIA'] . " already belongs to ID: " . $collision['id']);
                    return ['action' => 'error', 'changes' => []];
                }
            }

            if (!empty($original_filename)) {
                $data['FUENTE_IMPORTACION'] = substr($original_filename, 0, 255);
            }

            $protected_fields = ['EMAIL_ENRICH', 'TELEFONO_ENRICH', 'WEB_ENRICH', 'MAPS_LINK', 'AGRUPACION_ELECTORAL', 'DESCRIPCION_EPIGRAFE'];
            foreach ($protected_fields as $f) {
                if (!empty($existing[$f]) && empty($data[$f])) {
                    unset($data[$f]);
                }
            }

            // Detectar cambios campo a campo para el informe
            $changes = [];
            foreach ($data as $k => $v) {
                if (in_array($k, $skip_diff_fields)) continue;
                if (isset($existing[$k]) && (string) $existing[$k] !== (string) $v) {
                    $changes[$k] = [
                        'old' => (string) $existing[$k],
                        'new' => (string) $v
                    ];
                }
            }

            $updated = $this->db->update_iae($existing['id'], $data);
            if ($updated === false)
                return ['action' => 'error', 'changes' => []];

            if ($is_baja)   return ['action' => 'baja',    'changes' => $changes];
            if (!empty($changes)) return ['action' => 'updated', 'changes' => $changes];
            return ['action' => 'none', 'changes' => []];

        } else {
            // Si es una baja y no existe en BD, no la insertamos (no pertenece al censo activo)
            if ($is_baja) {
                return ['action' => 'none', 'changes' => []];
            }
            if (empty($data['REFERENCIA'])) {
                $data['REFERENCIA'] = !empty($data['NIF']) ? 'NEW-' . $data['NIF'] : 'TMP-' . uniqid();
            }
            // Marcar fuente de importación con el nombre del archivo
            if (!empty($original_filename)) {
                $data['FUENTE_IMPORTACION'] = substr($original_filename, 0, 255);
            } else {
                $data['FUENTE_IMPORTACION'] = ($import_type === 'standard') ? 'TXT-AEAT' : 'CSV-Enriquecimiento';
            }
            $ok = $this->db->insert_iae($data);
            return ['action' => $ok ? 'inserted' : 'error', 'changes' => []];
        }
    }

    /**
     * Helper para obtener cabeceras del archivo actual
     */
    private function get_file_headers($delimiter = ",", $file_path = null)
    {
        if (!$file_path) {
            $session_id = sanitize_text_field($_POST['session_id']);
            $upload_dir = wp_upload_dir();
            // Fallback por si no se pasa el path directamente, pero priorizamos $file_path
            $file_path = $upload_dir['basedir'] . '/censo_temp_' . $session_id . '.txt';
            if (!file_exists($file_path)) {
                $file_path = $upload_dir['basedir'] . '/censo_temp_' . $session_id;
            }
        }

        if (!file_exists($file_path)) {
            error_log("Censo: FATAL - No se encontró el archivo para leer cabeceras: $file_path");
            return [];
        }

        $f = fopen($file_path, 'r');
        if ($f === false) {
            error_log("Censo: FATAL - No se pudo abrir el archivo para leer cabeceras: $file_path");
            return [];
        }
        $headers = fgetcsv($f, 0, $delimiter, "\"", "\\");
        fclose($f);
        if ($headers) {
            $headers = array_map('trim', $headers);
            // Eliminar BOM si existe en el primer encabezado
            $headers[0] = preg_replace('/^[\xEF\xBB\xBF\xFE\xFF]+/', '', $headers[0]);
            error_log("Censo: Cabeceras leídas del archivo: " . implode(', ', $headers));
        } else {
            error_log("Censo: Error leyendo cabeceras con delimitador: " . $delimiter);
        }
        return is_array($headers) ? $headers : [];
    }

    /**
     * Recupera links de Maps (CID) para registros que no los tienen.
     * IMPORTANTE: NO resetea email/teléfono/web - solo busca completar el MAPS_LINK con CID.
     * Los registros se marcan como 'Pending' pero la protección anti-sobrescritura
     * en handle_enrich_batch y run_background_worker asegura que los datos buenos se conservan.
     */
    public function handle_reset_no_evidence()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No autorizado');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;

        // Primero contamos cuántos registros se van a afectar para informar al usuario
        $preview_count = $wpdb->get_var("
            SELECT COUNT(*) FROM $table_name 
            WHERE ENRICH_STATUS = 'Enriched' 
            AND (
                MAPS_LINK IS NULL 
                OR MAPS_LINK = '' 
                OR MAPS_LINK NOT LIKE '%?cid=%'
            )
        ");

        // Solo reseteamos registros SIN CID de Maps. Los datos de email/tel/web
        // quedan protegidos por la lógica anti-sobrescritura del worker.
        $count = $wpdb->query("
            UPDATE $table_name 
            SET ENRICH_STATUS = 'Pending', ENRICH_LOG = 'Recuperación de Maps (datos protegidos)' 
            WHERE ENRICH_STATUS = 'Enriched' 
            AND (
                MAPS_LINK IS NULL 
                OR MAPS_LINK = '' 
                OR MAPS_LINK NOT LIKE '%?cid=%'
            )
        ");

        wp_send_json_success([
            'count' => $count,
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    public function ajax_delete_multiple()
    {
        check_ajax_referer('censo_nonce', 'nonce');

        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('Sin permisos');
        }

        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];

        if (empty($ids)) {
            wp_send_json_error('No se proporcionaron IDs');
        }

        $deleted = $this->db->delete_multiple_iae($ids);

        wp_send_json_success([
            'deleted' => $deleted,
            'message' => sprintf('Se eliminaron %d registros correctamente', $deleted),
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Endpoint para re-indexar registros antiguos masivamente
     */
    public function handle_reindex_census()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No autorizado');
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 500;

        $processed = $this->db->reindex_all_search_data($batch_size, $offset);

        wp_send_json_success([
            'count' => $processed,
            'new_offset' => $offset + $processed,
            'finished' => ($processed < $batch_size),
            'new_nonce' => wp_create_nonce('censo_nonce')
        ]);
    }

    /**
     * Permite actualizar un campo específico de un registro (Email, Teléfono, Web)
     */
    public function handle_update_field()
    {
        check_ajax_referer('censo_nonce', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';

        $allowed_fields = ['EMAIL_ENRICH', 'TELEFONO_ENRICH', 'WEB_ENRICH'];
        if (!in_array($field, $allowed_fields)) {
            wp_send_json_error('Campo no permitido');
        }

        if ($field === 'WEB_ENRICH') {
            if (!$this->can_write_total()) {
                wp_send_json_error('No autorizado');
            }
        } else {
            if (!$this->can_write_basic()) {
                wp_send_json_error('No autorizado');
            }
        }

        $res = $this->db->update_iae($id, [$field => $value]);

        if ($res !== false) {
            wp_send_json_success([
                'message' => 'Campo actualizado correctamente',
                'new_nonce' => wp_create_nonce('censo_nonce')
            ]);
        } else {
            wp_send_json_error('Error al actualizar la base de datos');
        }
    }

    /**
     * Devuelve las URLs de los últimos CSVs de cambios generados:
     *  - Último archivo censo_cambios_*.csv (importación)
     *  - Archivo censo_enriquecimiento_HOY.csv (enriquecimiento)
     */
    public function handle_get_last_reports()
    {
        check_ajax_referer('censo_nonce', 'nonce');
        if (!$this->can_enrich_and_import()) {
            wp_send_json_error('No autorizado');
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        $base_url   = $upload_dir['baseurl'];

        // --- Último CSV de importación (el más reciente por fecha de modificación) ---
        $import_files = glob($base_dir . '/censo_cambios_*.csv');
        $last_import_url = null;
        $last_import_date = null;
        if (!empty($import_files)) {
            // Ordenar por fecha de modificación descendente
            usort($import_files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $newest = $import_files[0];
            $last_import_url  = $base_url . '/' . basename($newest);
            $last_import_date = date('d/m/Y H:i', filemtime($newest));
        }

        // --- CSV de enriquecimiento de hoy ---
        $today_file = $base_dir . '/censo_enriquecimiento_' . date('Y-m-d') . '.csv';
        $enrich_url  = null;
        $enrich_date = null;
        if (file_exists($today_file) && filesize($today_file) > 10) {
            $enrich_url  = $base_url . '/censo_enriquecimiento_' . date('Y-m-d') . '.csv';
            $enrich_date = date('d/m/Y H:i', filemtime($today_file));
        }

        wp_send_json_success([
            'import_url'   => $last_import_url,
            'import_date'  => $last_import_date,
            'enrich_url'   => $enrich_url,
            'enrich_date'  => $enrich_date,
            'new_nonce'    => wp_create_nonce('censo_nonce')
        ]);
    }


    /**
     * Validación inteligente de email.
     * Comprueba que tenga estructura básica de email: algo@algo.algo
     */
    private function is_valid_email($value)
    {
        if (empty($value))
            return false;
        $value = trim($value);
        // Debe tener @ y al menos un punto después del @
        return (bool) preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $value);
    }

    /**
     * Validación inteligente de teléfono.
     * Acepta formatos españoles e internacionales: +34 912345678, 912 345 678, etc.
     * Mínimo 6 dígitos para considerarse válido.
     */
    private function is_valid_phone($value)
    {
        if (empty($value))
            return false;
        $value = trim($value);
        // Eliminar espacios, guiones, paréntesis y puntos para contar dígitos
        $digits_only = preg_replace('/[^0-9]/', '', $value);
        // Debe tener al menos 6 dígitos y solo caracteres válidos de teléfono
        return strlen($digits_only) >= 6 && (bool) preg_match('/^[\+]?[0-9\s\-\.\(\)]{6,20}$/', $value);
    }

    /**
     * Validación inteligente de URL/web.
     * Comprueba que tenga estructura básica de URL.
     */
    private function is_valid_web($value)
    {
        if (empty($value))
            return false;
        $value = trim($value);
        // Debe contener al menos un punto y parecer un dominio
        return (bool) preg_match('/^(https?:\/\/)?[a-zA-Z0-9][a-zA-Z0-9\-]*\.[a-zA-Z]{2,}/', $value);
    }

    /**
     * Merge inteligente de un campo de enriquecimiento.
     * Reglas:
     * 1. Si el existente es VÁLIDO → conservarlo siempre (protección)
     * 2. Si el existente NO es válido y el nuevo SÍ → usar el nuevo
     * 3. Si ambos son inválidos → conservar lo que haya (no empeorar)
     * 4. Si ambos están vacíos → dejar vacío
     *
     * @param string $existing Valor actual en la BD
     * @param string $new_value Valor nuevo de la IA/Serper
     * @param string $type Tipo de dato: 'email', 'phone', 'web'
     * @return string El valor final a guardar
     */
    private function smart_merge_field($existing, $new_value, $type)
    {
        $existing = trim($existing);
        $new_value = trim($new_value);

        // Determinar validez según tipo
        switch ($type) {
            case 'email':
                $existing_valid = $this->is_valid_email($existing);
                $new_valid = $this->is_valid_email($new_value);
                break;
            case 'phone':
                $existing_valid = $this->is_valid_phone($existing);
                $new_valid = $this->is_valid_phone($new_value);
                break;
            case 'web':
                $existing_valid = $this->is_valid_web($existing);
                $new_valid = $this->is_valid_web($new_value);
                break;
            default:
                // Fallback: usar lógica simple (no vacío)
                return !empty($new_value) ? $new_value : $existing;
        }

        // Regla 1: Si existente es válido → conservar SIEMPRE
        if ($existing_valid) {
            return $existing;
        }

        // Regla 2: Existente inválido + nuevo válido → usar nuevo
        if ($new_valid) {
            return $new_value;
        }

        // Regla 3: Ambos inválidos → conservar existente (no empeorar)
        return $existing;
    }

    /**
     * Helpers de permisos para el Censo IAE
     */
    public function can_read()
    {
        if (current_user_can('manage_options') || current_user_can('edit_pages')) {
            return true;
        }
        $user = wp_get_current_user();
        $allowed_roles = ['direccion', 'rrhh', 'trabajador'];
        foreach ($allowed_roles as $role) {
            if (in_array($role, (array) $user->roles)) {
                return true;
            }
        }
        if (class_exists('EP_App_Manager')) {
            $perm = EP_App_Manager::get_permission('censo');
            if ($perm === 'read' || $perm === 'write') {
                return true;
            }
        }
        return false;
    }

    public function can_write_basic()
    {
        $user = wp_get_current_user();
        $allowed_roles = ['direccion', 'rrhh', 'trabajador'];
        foreach ($allowed_roles as $role) {
            if (in_array($role, (array) $user->roles)) {
                return true;
            }
        }
        return $this->can_write_total();
    }

    public function can_write_total()
    {
        if (current_user_can('manage_options') || current_user_can('edit_pages')) {
            return true;
        }
        if ($this->can_enrich_and_import()) {
            return true;
        }
        if (class_exists('EP_App_Manager')) {
            if (EP_App_Manager::get_permission('censo') === 'write') {
                return true;
            }
        }
        return false;
    }

    public function can_enrich_and_import()
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $user = wp_get_current_user();
        if (in_array('direccion', (array) $user->roles)) {
            return true;
        }
        if (class_exists('EP_App_Manager')) {
            if (EP_App_Manager::get_permission('empresas') === 'write') {
                return true;
            }
        }
        return false;
    }
}
