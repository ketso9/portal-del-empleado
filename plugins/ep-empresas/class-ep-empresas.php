<?php
defined('ABSPATH') || exit;

/* ============================================================
 *  EP_App_Empresas  –  Interfaz con el Portal Manager
 * ============================================================ */
class EP_App_Empresas implements EP_App_Interface
{
    public function get_id()        { return 'empresas'; }
    public function get_name()      { return EP_Empresas::get_setting('app_name', 'Club 18|99'); }
    public function get_icon()      { return 'fa-solid fa-building'; }
    public function get_menu_label(){ return EP_Empresas::get_setting('app_name', 'Club 18|99'); }

    public function render_dashboard_card()
    {
        $logo = EP_Empresas::get_setting('app_logo', EP_EMPRESAS_URL . 'assets/logo-app.svg');
        $name = EP_Empresas::get_setting('app_name', 'Club 18|99');
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=empresas'">
            <div class="app-icon-container" style="background: transparent; box-shadow: none; padding: 0;">
                <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($name); ?>" style="width: 100%; height: 100%; object-fit: contain; max-height: 55px;">
            </div>
            <h3><?php echo esc_html($name); ?></h3>
            <p>Directorio de empresas asociadas</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_EMPRESAS_PATH . 'partials/empresas-app.php';
    }

    public function handle_ajax()
    {
        $action = isset($_POST['empresa_action']) ? sanitize_text_field($_POST['empresa_action']) : '';
        switch ($action) {
            case 'add':    EP_Empresas::ajax_add();    break;
            case 'edit':   EP_Empresas::ajax_edit();   break;
            case 'edit_from_club': EP_Empresas::ajax_edit_from_club(); break;
            case 'get_all_from_portal': EP_Empresas::ajax_get_all_from_portal(); break;
            case 'delete': EP_Empresas::ajax_delete(); break;
            case 'list':   EP_Empresas::ajax_list();   break;
            case 'import': EP_Empresas::ajax_import(); break;
            case 'import_json': EP_Empresas::ajax_import_json(); break;
            case 'save_settings': EP_Empresas::ajax_save_settings(); break;
        }
    }
}

/* ============================================================
 *  EP_Empresas  –  Lógica principal
 * ============================================================ */
class EP_Empresas
{
    /* ----------------------------------------------------------
     *  Constructor – hooks
     * ---------------------------------------------------------- */
    public function __construct()
    {
        add_action('init',               [$this, 'register_roles']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Standalone AJAX (por si el manager no lo engloba)
        add_action('wp_ajax_ep_empresas_action', [$this, 'handle_standalone_ajax']);

        // AJAX público para recibir las sincronizaciones externas del Club
        add_action('wp_ajax_nopriv_ep_empresas_action', [$this, 'handle_standalone_ajax']);

        // Upload AJAX para imágenes
        add_action('wp_ajax_ep_empresas_upload_image', [__CLASS__, 'ajax_upload_image']);
    }

    /* ----------------------------------------------------------
     *  Roles y Capacidades
     * ---------------------------------------------------------- */
    public function register_roles()
    {
        // Editor: puede crear, editar y borrar empresas
        if (!get_role('ep_empresas_editor')) {
            add_role('ep_empresas_editor', __('Editor Empresas', 'employee-portal'), [
                'read'                    => true,
                'ep_empresas_write'       => true,
                'ep_empresas_read'        => true,
            ]);
        }
        // Viewer: solo lectura
        if (!get_role('ep_empresas_viewer')) {
            add_role('ep_empresas_viewer', __('Visor Empresas', 'employee-portal'), [
                'read'               => true,
                'ep_empresas_read'   => true,
            ]);
        }

        // Asegurar caps en roles de portal existentes
        $roles_with_write = ['administrator', 'ep_super_admin', 'ep_hr', 'ep_direction'];
        $roles_with_read  = ['ep_worker', 'ep_communication', 'ep_maintenance'];

        foreach ($roles_with_write as $r) {
            $role = get_role($r);
            if ($role) {
                $role->add_cap('ep_empresas_write');
                $role->add_cap('ep_empresas_read');
            }
        }
        foreach ($roles_with_read as $r) {
            $role = get_role($r);
            if ($role) {
                $role->add_cap('ep_empresas_read');
            }
        }
    }

    /* ----------------------------------------------------------
     *  Assets
     * ---------------------------------------------------------- */
    public function enqueue_assets()
    {
        if (isset($_GET['view']) && $_GET['view'] === 'empresas') {
            wp_enqueue_script('xlsx-lib', 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', [], '0.18.5', true);
            wp_enqueue_style(
                'ep-empresas-style',
                EP_EMPRESAS_URL . 'assets/css/ep-empresas.css',
                [],
                '1.1.0'
            );
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11.0', true);
            wp_enqueue_script('ep-empresas-js', EP_EMPRESAS_URL . 'assets/js/ep-empresas.js', ['jquery', 'sweetalert2', 'xlsx-lib'], '1.1.0', true);
        }
    }

    public function handle_standalone_ajax()
    {
        (new EP_App_Empresas())->handle_ajax();
    }

    /* ----------------------------------------------------------
     *  Tabla de Base de Datos
     * ---------------------------------------------------------- */
    public static function get_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'ep_empresas';
    }

    public static function create_table()
    {
        global $wpdb;
        $table   = self::get_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id            bigint(20)   NOT NULL AUTO_INCREMENT,
            nombre        varchar(255) NOT NULL,
            responsable   varchar(255) NOT NULL DEFAULT '',
            cif           varchar(30)  NOT NULL DEFAULT '',
            telefono      varchar(50)  NOT NULL DEFAULT '',
            num_trabajadores int(11)   NOT NULL DEFAULT 0,
            direccion     varchar(500) NOT NULL DEFAULT '',
            email         varchar(255) NOT NULL DEFAULT '',
            zona          varchar(100) NOT NULL DEFAULT '',
            tipo_membresia varchar(50) NOT NULL DEFAULT 'Basic',
            iae           varchar(100) NOT NULL DEFAULT '',
            logo_url      varchar(500) NOT NULL DEFAULT '',
            foto_url      varchar(500) NOT NULL DEFAULT '',
            observaciones text         NOT NULL DEFAULT '',
            activo        tinyint(1)   NOT NULL DEFAULT 1,
            created_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tipo_membresia (tipo_membresia),
            KEY zona (zona),
            KEY activo (activo)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ----------------------------------------------------------
     *  Helpers de permisos
     * ---------------------------------------------------------- */
    public static function can_write(): bool
    {
        if (current_user_can('administrator')) return true;
        
        if (class_exists('EP_App_Manager')) {
            return EP_App_Manager::get_permission('empresas') === 'write';
        }
        
        return current_user_can('ep_empresas_write');
    }

    public static function can_read(): bool
    {
        if (current_user_can('administrator')) return true;

        if (class_exists('EP_App_Manager')) {
            $perm = EP_App_Manager::get_permission('empresas');
            return $perm === 'read' || $perm === 'write';
        }

        return current_user_can('ep_empresas_read');
    }

    /* ----------------------------------------------------------
     *  AJAX: Listar empresas
     * ---------------------------------------------------------- */
    public static function ajax_list()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_read()) {
            wp_send_json_error('Sin permisos de lectura.');
        }

        global $wpdb;
        $table = self::get_table();

        $where  = ['1=1'];
        $params = [];

        if (!empty($_POST['search'])) {
            $s = '%' . $wpdb->esc_like(sanitize_text_field($_POST['search'])) . '%';
            $where[]  = '(nombre LIKE %s OR cif LIKE %s)';
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($_POST['membresia'])) {
            $where[]  = 'tipo_membresia = %s';
            $params[] = sanitize_text_field($_POST['membresia']);
        }
        if (!empty($_POST['zona'])) {
            $where[]  = 'zona = %s';
            $params[] = sanitize_text_field($_POST['zona']);
        }

        $allowed_order = ['nombre', 'tipo_membresia', 'zona', 'created_at', 'num_trabajadores'];
        $orderby = in_array($_POST['orderby'] ?? '', $allowed_order) ? sanitize_key($_POST['orderby']) : 'nombre';
        $order   = (($_POST['order'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

        $sql  = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY $orderby $order";
        $rows = empty($params) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, $params));

        wp_send_json_success($rows);
    }

    /* ----------------------------------------------------------
     *  AJAX: Añadir empresa
     * ---------------------------------------------------------- */
    public static function ajax_add()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos de escritura.');
        }

        $data = self::sanitize_empresa_post();
        if (is_wp_error($data)) {
            wp_send_json_error($data->get_error_message());
        }

        global $wpdb;
        $inserted = $wpdb->insert(self::get_table(), $data);

        if ($inserted) {
            $data['activo'] = 1; // Por defecto al añadir
            self::sync_to_club($data);
            if (function_exists('ep_stats_log')) {
                ep_stats_log('empresas', 'empresa_created', get_current_user_id(), [
                    'empresa_id' => $wpdb->insert_id,
                    'nombre' => $data['nombre']
                ]);
            }
            wp_send_json_success(['message' => 'Empresa añadida correctamente.', 'id' => $wpdb->insert_id]);
        } else {
            wp_send_json_error('Error al guardar la empresa en la base de datos.');
        }
    }

    /* ----------------------------------------------------------
     *  AJAX: Editar empresa
     * ---------------------------------------------------------- */
    public static function ajax_edit()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos de escritura.');
        }

        $id = intval($_POST['empresa_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID de empresa no válido.');
        }

        $data = self::sanitize_empresa_post();
        if (is_wp_error($data)) {
            wp_send_json_error($data->get_error_message());
        }

        global $wpdb;
        $updated = $wpdb->update(self::get_table(), $data, ['id' => $id]);

        if ($updated !== false) {
            $activo_post = isset($_POST['activo']) ? intval($_POST['activo']) : 1;
            $data['activo'] = $activo_post;
            self::sync_to_club($data);
            if (function_exists('ep_stats_log')) {
                ep_stats_log('empresas', 'empresa_updated', get_current_user_id(), [
                    'empresa_id' => $id,
                    'nombre' => $data['nombre']
                ]);
            }
            wp_send_json_success('Empresa actualizada correctamente.');
        } else {
            wp_send_json_error('Error al actualizar la empresa.');
        }
    }

    /* ----------------------------------------------------------
     *  AJAX: Eliminar empresa
     * ---------------------------------------------------------- */
    public static function ajax_delete()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos para eliminar.');
        }

        $id = intval($_POST['empresa_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID no válido.');
        }

        global $wpdb;
        $deleted = $wpdb->delete(self::get_table(), ['id' => $id]);

        if ($deleted) {
            wp_send_json_success('Empresa eliminada correctamente.');
        } else {
            wp_send_json_error('Error al eliminar la empresa.');
        }
    }

    /* ----------------------------------------------------------
     *  AJAX: Subida de imagen (logo / foto)
     * ---------------------------------------------------------- */
    public static function ajax_upload_image()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos de escritura.');
        }

        if (empty($_FILES['image'])) {
            wp_send_json_error('No se ha enviado ningún archivo.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('image', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error($attachment_id->get_error_message());
        }

        $url = wp_get_attachment_url($attachment_id);
        wp_send_json_success(['url' => $url, 'attachment_id' => $attachment_id]);
    }

    /* ----------------------------------------------------------
     *  AJAX: Importar desde JSON (Enviado por el frontend)
     * ---------------------------------------------------------- */
    public static function ajax_import_json()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos de escritura.');
        }

        $json_rows = $_POST['rows'] ?? '';
        $rows = json_decode(stripslashes($json_rows), true);

        if (empty($rows) || !is_array($rows)) {
            wp_send_json_error('No se recibieron datos válidos.');
        }

        global $wpdb;
        $table = self::get_table();
        $count = 0;
        $errors = [];

        foreach ($rows as $row) {
            // nombre, responsable, cif, telefono, email, direccion, zona, tipo_membresia
            if (empty($row[0])) continue;

            $data = [
                'nombre'          => sanitize_text_field($row[0] ?? ''),
                'responsable'     => sanitize_text_field($row[1] ?? ''),
                'cif'             => sanitize_text_field($row[2] ?? ''),
                'telefono'        => sanitize_text_field($row[3] ?? ''),
                'email'           => sanitize_email($row[4] ?? ''),
                'direccion'       => sanitize_text_field($row[5] ?? ''),
                'zona'            => sanitize_text_field($row[6] ?? ''),
                'tipo_membresia'  => sanitize_text_field($row[7] ?? 'Basic'),
                'num_trabajadores'=> intval($row[8] ?? 0),
                'iae'             => sanitize_text_field($row[9] ?? ''),
                'observaciones'   => sanitize_text_field($row[10] ?? 'Importado vía Excel'),
                'logo_url'        => '',
                'foto_url'        => '',
            ];

            $inserted = $wpdb->insert($table, $data);
            if ($inserted) {
                $count++;
            } else {
                $errors[] = "Error insertando: " . $data['nombre'];
            }
        }

        wp_send_json_success([
            'message' => "Importación completada: $count registros añadidos.",
            'errors'  => $errors
        ]);
    }

    /* ----------------------------------------------------------
     *  AJAX: Importar CSV (Legacy)
     * ---------------------------------------------------------- */
    public static function ajax_import()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos de escritura.');
        }

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error('No se ha subido ningún archivo.');
        }

        $file_name = $_FILES['csv_file']['name'];
        $file_tmp  = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!is_file($file_tmp)) {
            wp_send_json_error('Archivo no válido.');
        }

        $rows = [];

        if ($ext === 'csv') {
            $handle = fopen($file_tmp, 'r');
            if ($handle) {
                fgetcsv($handle); // Cabecera
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            }
        } else if ($ext === 'xlsx' || $ext === 'xls') {
            // Intentamos localizar la librería en ep-censo (que ya existe en producción)
            $lib_path = dirname(dirname(__FILE__)) . '/ep-censo/libs/SimpleXLSX.php';
            
            if (file_exists($lib_path)) {
                require_once $lib_path;
                $xlsx = SimpleXLSX::parse($file_tmp);
                if ($xlsx) {
                    $all_rows = [];
                    foreach ($xlsx->readRows() as $r) {
                        $all_rows[] = $r;
                    }
                    array_shift($all_rows); // Quitar cabecera
                    $rows = $all_rows;
                } else {
                    wp_send_json_error('Error al parsear el archivo Excel.');
                }
            } else {
                wp_send_json_error('Librería de Excel no encontrada. Ruta intentada: ' . $lib_path);
            }
        } else {
            wp_send_json_error('Formato de archivo no soportado (.csv, .xlsx).');
        }

        if (empty($rows)) {
            wp_send_json_error('No se encontraron datos válidos para importar.');
        }

        global $wpdb;
        $table = self::get_table();
        $count = 0;
        $errors = [];

        foreach ($rows as $row) {
            // nombre, responsable, cif, telefono, email, direccion, zona, tipo_membresia
            if (count($row) < 1) continue;

            $data = [
                'nombre'          => sanitize_text_field($row[0] ?? ''),
                'responsable'     => sanitize_text_field($row[1] ?? ''),
                'cif'             => sanitize_text_field($row[2] ?? ''),
                'telefono'        => sanitize_text_field($row[3] ?? ''),
                'email'           => sanitize_email($row[4] ?? ''),
                'direccion'       => sanitize_text_field($row[5] ?? ''),
                'zona'            => sanitize_text_field($row[6] ?? ''),
                'tipo_membresia'  => sanitize_text_field($row[7] ?? 'Basic'),
                'num_trabajadores'=> 0,
                'iae'             => '',
                'logo_url'        => '',
                'foto_url'        => '',
                'observaciones'   => 'Importado masivamente',
            ];

            if (empty($data['nombre'])) continue;

            $inserted = $wpdb->insert($table, $data);
            if ($inserted) {
                $count++;
            } else {
                $errors[] = "Error insertando: " . ($data['nombre'] ?: 'Fila desconocida');
            }
        }

        wp_send_json_success([
            'message' => "Importación completada: $count registros añadidos.",
            'errors'  => $errors
        ]);
    }

    /* ----------------------------------------------------------
     *  Ajustes de la App
     * ---------------------------------------------------------- */
    public static function get_settings()
    {
        $default = [
            'app_name' => 'Empresas socias',
            'app_logo' => EP_EMPRESAS_URL . 'assets/logo-app.svg'
        ];
        $settings = get_option('ep_empresas_settings', []);
        return wp_parse_args($settings, $default);
    }

    public static function get_setting($key, $default = '')
    {
        if ($key === 'app_name' && empty($default)) $default = 'Empresas socias';
        $settings = self::get_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    public static function ajax_save_settings()
    {
        check_ajax_referer('ep_empresas_nonce', 'security');

        if (!self::can_write()) {
            wp_send_json_error('Sin permisos para cambiar los ajustes.');
        }

        $app_name = sanitize_text_field($_POST['app_name'] ?? 'Empresas socias');
        $app_logo = esc_url_raw($_POST['app_logo'] ?? '');

        $settings = [
            'app_name' => $app_name,
            'app_logo' => $app_logo
        ];

        update_option('ep_empresas_settings', $settings);
        wp_send_json_success('Ajustes guardados correctamente.');
    }

    /* ----------------------------------------------------------
     *  Helper: Sanitizar POST de empresa
     * ---------------------------------------------------------- */
    private static function sanitize_empresa_post()
    {
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $cif    = sanitize_text_field($_POST['cif'] ?? '');

        $membresías_válidas = ['Basic', 'Corporate', 'Premium'];
        $membresia = sanitize_text_field($_POST['tipo_membresia'] ?? 'Basic');
        if (!in_array($membresia, $membresías_válidas, true)) {
            $membresia = 'Basic';
        }

        return [
            'nombre'          => $nombre,
            'responsable'     => sanitize_text_field($_POST['responsable']     ?? ''),
            'cif'             => $cif,
            'telefono'        => sanitize_text_field($_POST['telefono']         ?? ''),
            'num_trabajadores'=> intval($_POST['num_trabajadores']              ?? 0),
            'direccion'       => sanitize_text_field($_POST['direccion']        ?? ''),
            'email'           => sanitize_email($_POST['email']                 ?? ''),
            'zona'            => sanitize_text_field($_POST['zona']             ?? ''),
            'tipo_membresia'  => $membresia,
            'iae'             => sanitize_text_field($_POST['iae']              ?? ''),
            'logo_url'        => esc_url_raw($_POST['logo_url']                ?? ''),
            'foto_url'        => esc_url_raw($_POST['foto_url']                ?? ''),
            'observaciones'   => sanitize_textarea_field($_POST['observaciones'] ?? ''),
        ];
    }

    /* ----------------------------------------------------------
     *  Zonas disponibles (configurable)
     * ---------------------------------------------------------- */
    public static function get_zonas(): array
    {
        return apply_filters('ep_empresas_zonas', [
            'Cáceres capital y entorno',
            'Plasencia y entorno',
            'Navalmoral de la Mata',
            'Coria y noroeste',
            'Trujillo y comarca',
            'Miajadas y sur',
            'Valle del Jerte',
            'La Vera',
            'Las Hurdes',
            'Sierra de Gata',
            'Tajo-Salor-Almonte',
            'Villuercas-Ibores-Jara'
        ]);
    }

    /**
     * Valida el token de sincronizacion del Club.
     *
     * Deniega siempre si el portal no tiene token configurado: sin esta guarda,
     * hash_equals('', '') seria cierto y cualquiera podria volcar la tabla de
     * empresas enviando un security_token vacio.
     */
    private static function verify_sync_token(string $received): void
    {
        $expected = self::get_sync_token();

        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            wp_send_json_error('Token de seguridad invalido.', 401);
        }
    }

    /**
     * Obtiene el token de sincronizacion configurado en el portal.
     */
    public static function get_sync_token(): string
    {
        // Sin valor por defecto a proposito: un token escrito en el codigo (y por
        // tanto en el repositorio) dejaria el endpoint abierto si algun dia se
        // borrase la opcion. Si esta vacia, verify_sync_token() deniega.
        return (string) get_option('ep_club_sync_token', '');
    }

    /**
     * Obtiene la URL de la App/Club configurada.
     */
    public static function get_sync_url(): string
    {
        $home_url   = get_bloginfo('url');
        $is_staging = (stripos($home_url, 'devpruebas') !== false);
        $default    = $is_staging
            ? 'https://camaracaceres.com/prepro'
            : 'https://club1899.es';
        return rtrim(get_option('ep_club_sync_url', $default), '/');
    }

    /**
     * Comprueba si el connector está activado.
     */
    public static function is_sync_enabled(): bool
    {
        return get_option('ep_club_sync_enabled', '1') === '1';
    }

    public static function sync_to_club($data)
    {
        if (!self::is_sync_enabled()) {
            return;
        }

        $base_url     = self::get_sync_url();
        $club_api_url = $base_url . '/wp-json/club-camara/v1/empresa/sincronizar';
        $token        = self::get_sync_token();

        $body = [
            'cif'              => $data['cif'] ?? '',
            'nombre'           => $data['nombre'] ?? '',
            'tipo_membresia'   => $data['tipo_membresia'] ?? 'Basic',
            'logo_url'         => $data['logo_url'] ?? '',
            'activo'           => isset($data['activo']) ? intval($data['activo']) : 1,
            'email_contacto'   => $data['email'] ?? '',
            'responsable'      => $data['responsable'] ?? '',
            'telefono'         => $data['telefono'] ?? '',
            'num_trabajadores' => intval($data['num_trabajadores'] ?? 0),
            'direccion'        => $data['direccion'] ?? '',
            'zona'             => $data['zona'] ?? '',
            'iae'              => $data['iae'] ?? '',
            'foto_url'         => $data['foto_url'] ?? '',
            'observaciones'    => $data['observaciones'] ?? '',
        ];

        $res = wp_remote_post($club_api_url, [
            'blocking'    => true,
            'timeout'     => 15,
            'sslverify'   => false,
            'headers'     => [
                'X-Club-Camara-Token' => $token,
                'Content-Type'        => 'application/json'
            ],
            'body'        => wp_json_encode($body)
        ]);

        if (is_wp_error($res)) {
            error_log("[CLUB SYNC ERROR] Falla conexión con $club_api_url: " . $res->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($res);
            $resp_body = wp_remote_retrieve_body($res);
            if ($code !== 200) {
                error_log("[CLUB SYNC FAIL] HTTP $code en $club_api_url | Respuesta: $resp_body");
            } else {
                error_log("[CLUB SYNC SUCCESS] HTTP 200 de $club_api_url");
            }
        }
    }

    public static function ajax_edit_from_club()
    {
        $security_token = isset($_POST['security_token']) ? sanitize_text_field($_POST['security_token']) : '';
        self::verify_sync_token($security_token);

        $cif = sanitize_text_field($_POST['cif'] ?? '');
        if (empty($cif)) {
            wp_send_json_error('CIF obligatorio.');
        }

        // Importar logo del Club al Portal (servidor-a-servidor para garantizar acceso)
        $logo_url_club = esc_url_raw($_POST['logo_url'] ?? '');
        $nombre_empresa = sanitize_text_field($_POST['nombre'] ?? $cif);
        $logo_url_local = self::import_logo_from_external($logo_url_club, $nombre_empresa);

        $data = [
            'nombre'          => sanitize_text_field($_POST['nombre'] ?? ''),
            'responsable'     => sanitize_text_field($_POST['responsable'] ?? ''),
            'cif'             => $cif,
            'telefono'        => sanitize_text_field($_POST['telefono'] ?? ''),
            'num_trabajadores'=> intval($_POST['num_trabajadores'] ?? 0),
            'direccion'       => sanitize_text_field($_POST['direccion'] ?? ''),
            'email'           => sanitize_email($_POST['email'] ?? ''),
            'zona'            => sanitize_text_field($_POST['zona'] ?? ''),
            'tipo_membresia'  => sanitize_text_field($_POST['plan_membresia'] ?? 'Basic'),
            'iae'             => sanitize_text_field($_POST['iae'] ?? ''),
            'logo_url'        => ! empty($logo_url_local) ? $logo_url_local : $logo_url_club,
            'foto_url'        => esc_url_raw($_POST['foto_url'] ?? ''),
            'observaciones'   => sanitize_textarea_field($_POST['observaciones'] ?? ''),
            'activo'          => 1,
        ];

        // Mapear tipos de membresía del club a los del portal
        if (stripos($data['tipo_membresia'], 'oro') !== false || stripos($data['tipo_membresia'], 'plata') !== false || stripos($data['tipo_membresia'], 'premium') !== false) {
            $plan = 'Basic';
            if (stripos($data['tipo_membresia'], 'premium') !== false) {
                $plan = 'Premium';
            } elseif (stripos($data['tipo_membresia'], 'oro') !== false) {
                $plan = 'Corporate';
            }
            $data['tipo_membresia'] = $plan;
        }

        global $wpdb;
        $table = self::get_table();
        $empresa = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE cif = %s", $cif));

        if ($empresa) {
            $wpdb->update($table, $data, ['id' => $empresa->id]);
            wp_send_json_success(['message' => 'Empresa actualizada en el Portal desde el Club.', 'id' => $empresa->id]);
        } else {
            $wpdb->insert($table, $data);
            wp_send_json_success(['message' => 'Empresa creada en el Portal desde el Club.', 'id' => $wpdb->insert_id]);
        }
    }

    /**
     * Descarga una imagen de una URL externa y la importa a la librería de medios del Portal.
     * Usa caché en opciones de WP para no re-descargar la misma imagen.
     *
     * @param string $url_externa URL de la imagen (puede ser de club1899.es u otro dominio).
     * @param string $nombre_empresa Nombre de la empresa para nombrar el archivo.
     * @return string URL local de la imagen importada, o cadena vacía si falla.
     */
    public static function import_logo_from_external(string $url_externa, string $nombre_empresa = ''): string
    {
        if (empty($url_externa)) {
            return '';
        }

        // Si ya es una URL del propio portal, no hacer nada
        if (false !== strpos($url_externa, 'portal.camaracaceres.com')) {
            return $url_externa;
        }

        // Comprobar caché
        $cache_option = 'ep_empresas_logo_cache';
        $logo_cache   = get_option($cache_option, []);

        if (isset($logo_cache[$url_externa]) && ! empty($logo_cache[$url_externa])) {
            return $logo_cache[$url_externa];
        }

        // Descargar imagen (servidor-a-servidor)
        if (!function_exists('wp_remote_get')) {
            return '';
        }

        $response = wp_remote_get($url_externa, [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'Portal-LogoSync/1.0'],
        ]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $logo_cache[$url_externa] = '';
            update_option($cache_option, $logo_cache, false);
            return '';
        }

        $image_data   = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        if (empty($image_data)) {
            return '';
        }

        // Determinar extensión
        $ext_map = [
            'image/jpeg' => 'jpg', 'image/png'  => 'png',
            'image/gif'  => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
        ];
        $extension = '';
        foreach ($ext_map as $mime => $ext) {
            if (false !== strpos($content_type, $mime)) { $extension = $ext; break; }
        }
        if (empty($extension)) {
            $path_ext  = strtolower(pathinfo(parse_url($url_externa, PHP_URL_PATH), PATHINFO_EXTENSION));
            $extension = in_array($path_ext, ['jpg','jpeg','png','gif','webp','svg'], true) ? $path_ext : 'png';
        }

        // Nombre de archivo
        $safe_name = 'logo-' . sanitize_title($nombre_empresa) . '-' . substr(md5($url_externa), 0, 6);
        $filename  = $safe_name . '.' . $extension;

        // Guardar en uploads
        if (!function_exists('wp_upload_bits')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $upload = wp_upload_bits($filename, null, $image_data);
        if (!empty($upload['error'])) {
            return '';
        }

        // Registrar como adjunto en la librería de medios
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment = [
            'post_mime_type' => $content_type ?: 'image/' . $extension,
            'post_title'     => 'Logo ' . $nombre_empresa,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        if (is_wp_error($attach_id) || !$attach_id) {
            return '';
        }
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        $local_url = wp_get_attachment_url($attach_id);

        // Guardar en caché
        $logo_cache[$url_externa] = $local_url;
        update_option($cache_option, $logo_cache, false);

        return $local_url ?: '';
    }

    public static function ajax_get_all_from_portal()
    {
        $security_token = isset($_POST['security_token']) ? sanitize_text_field($_POST['security_token']) : '';
        self::verify_sync_token($security_token);

        global $wpdb;
        $table = self::get_table();
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY nombre ASC");

        wp_send_json_success(['empresas' => $rows]);
    }
}

// Instanciar la clase principal (hooks)
new EP_Empresas();

// Crear la tabla al activar el plugin / en cada carga (seguro con CREATE TABLE IF NOT EXISTS)
add_action('init', function () {
    static $done = false;
    if ($done) return;
    $done = true;
    EP_Empresas::create_table();
}, 5);

// ============================================================
// Connector Settings Page
// ============================================================
add_action('admin_menu', 'ep_empresas_connector_menu');
function ep_empresas_connector_menu()
{
    add_submenu_page(
        'employee-portal',      // Padre correcto
        'Connector App/Club',
        '🔗 Connector App',
        'manage_options',
        'ep-connector-club',
        'ep_empresas_connector_page'
    );
}

function ep_empresas_connector_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos.');
    }

    // Procesar formulario
    if (isset($_POST['ep_connector_save']) && check_admin_referer('ep_connector_nonce')) {
        $enabled = isset($_POST['ep_club_sync_enabled']) ? '1' : '0';
        $url     = esc_url_raw(trim($_POST['ep_club_sync_url'] ?? ''));
        update_option('ep_club_sync_enabled', $enabled);
        update_option('ep_club_sync_url', $url);
        echo '<div class="notice notice-success"><p>✅ Configuración guardada.</p></div>';
    }

    // Regenerar token
    if (isset($_POST['ep_connector_regen']) && check_admin_referer('ep_connector_nonce')) {
        $new_token = bin2hex(random_bytes(32));
        update_option('ep_club_sync_token', $new_token);
        echo '<div class="notice notice-warning"><p>⚠️ Token regenerado. Actualízalo también en el Club/App.</p></div>';
    }

    // Probar conexión
    $test_result = '';
    if (isset($_POST['ep_connector_test']) && check_admin_referer('ep_connector_nonce')) {
        $base_url = rtrim(get_option('ep_club_sync_url', ''), '/');
        $token    = EP_Empresas::get_sync_token();
        if (empty($base_url)) {
            $test_result = '<span style="color:red">❌ Configura primero la URL del Club.</span>';
        } else {
            $test_url = $base_url . '/wp-json/club-camara/v1/status';
            $res      = wp_remote_get($test_url, [
                'timeout'   => 10,
                'sslverify' => false,
                'headers'   => ['X-Club-Camara-Token' => $token],
            ]);
            if (is_wp_error($res)) {
                $test_result = '<span style="color:red">❌ Error: ' . esc_html($res->get_error_message()) . '</span>';
            } elseif (wp_remote_retrieve_response_code($res) === 200) {
                $test_result = '<span style="color:green">🟢 Conexión exitosa con el Club/App.</span>';
            } else {
                $test_result = '<span style="color:orange">⚠️ Respuesta HTTP ' . wp_remote_retrieve_response_code($res) . ' — verifica la URL y el token.</span>';
            }
        }
    }

    $enabled = get_option('ep_club_sync_enabled', '1');
    $url     = get_option('ep_club_sync_url', '');
    $token   = EP_Empresas::get_sync_token();
    ?>
    <div class="wrap">
        <h1>🔗 Connector: App / Club Externa</h1>
        <p>Configura aquí la sincronización bilateral de empresas entre este Portal y una App de Club externa.</p>

        <form method="post">
            <?php wp_nonce_field('ep_connector_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Sincronización</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ep_club_sync_enabled" value="1" <?php checked($enabled, '1'); ?>>
                            Activar sincronización con App/Club externa
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ep_club_sync_url">URL del Club / App</label></th>
                    <td>
                        <input type="url" id="ep_club_sync_url" name="ep_club_sync_url"
                               value="<?php echo esc_attr($url); ?>"
                               class="regular-text"
                               placeholder="https://tuclub.es">
                        <p class="description">URL base de la instalación del Club/App (sin barra final).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Token de sincronización</th>
                    <td>
                        <code style="background:#f0f0f0;padding:6px 10px;border-radius:4px;font-size:13px;user-select:all;"><?php echo esc_html($token); ?></code>
                        <br><br>
                        <strong>📋 Copia este token y pégalo en la configuración del Club/App.</strong>
                        <p class="description">El token es secreto. Si lo regeneras, actualízalo también en el Club.</p>
                    </td>
                </tr>
            </table>

            <?php if ($test_result) : ?>
                <p style="margin: 10px 0; font-size: 14px;"><?php echo $test_result; ?></p>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" name="ep_connector_save" class="button button-primary">💾 Guardar configuración</button>
                &nbsp;
                <button type="submit" name="ep_connector_test" class="button">🔁 Probar conexión</button>
                &nbsp;
                <button type="submit" name="ep_connector_regen"
                        class="button"
                        onclick="return confirm('¿Seguro? Tendrás que actualizar el token en el Club/App.')">
                    🔄 Regenerar token
                </button>
            </p>
        </form>
    </div>
    <?php
}
