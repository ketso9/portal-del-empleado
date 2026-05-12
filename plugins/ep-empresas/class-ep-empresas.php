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
