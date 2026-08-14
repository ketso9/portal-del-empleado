<?php
/**
 * Module Name: Control de Gastos y Dietas Mini App
 * Description: Gestión, justificación de dietas y tickets de compra por usuario con numeración secuencial global y declaración obligatoria de cierre.
 * Package: pro_plus
 */

defined('ABSPATH') || exit;

// Guard de carga única - evitar errores por doble inclusión
if (defined('EP_EXPENSES_LOADED')) {
    return;
}
define('EP_EXPENSES_LOADED', true);

// Alias de retrocompatibilidad para la app de firmas (EP_App_Signature_V4 -> EP_Signature)
if (class_exists('EP_App_Signature_V4') && !class_exists('EP_Signature')) {
    class_alias('EP_App_Signature_V4', 'EP_Signature');
}

defined('EP_EXPENSES_PATH') || define('EP_EXPENSES_PATH', plugin_dir_path(__FILE__));
defined('EP_EXPENSES_URL')  || define('EP_EXPENSES_URL',  plugin_dir_url(__FILE__));
// Subir esta versión fuerza una única pasada de dbDelta / migración de columnas.
defined('EP_EXPENSES_DB_VERSION') || define('EP_EXPENSES_DB_VERSION', '1.3.0');

if (!class_exists('EP_Expenses_DB', false)) {
    require_once EP_EXPENSES_PATH . 'class-ep-expenses-db.php';
}
if (!class_exists('EP_App_Expenses', false)) {
    require_once EP_EXPENSES_PATH . 'class-ep-app-expenses.php';
}

class EP_Expenses
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        // Inicializar / actualizar tablas en DB (solo cuando cambia la versión del esquema)
        add_action('init', array($this, 'maybe_upgrade_db'));

        // Registrar App en EP_App_Manager
        add_action('ep_register_apps', array($this, 'register_app'));

        // Qué se le cuenta al empleado cuando termina de firmar un justificante
        add_filter('ep_signature_after_sign', array($this, 'signature_next_step'), 10, 2);

        // Encolar assets CSS y JS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Hooks AJAX
        add_action('wp_ajax_ep_expenses_get_list', array($this, 'ajax_get_list'));
        add_action('wp_ajax_ep_expenses_save', array($this, 'ajax_save'));
        add_action('wp_ajax_ep_expenses_delete', array($this, 'ajax_delete'));
        add_action('wp_ajax_ep_expenses_toggle_status', array($this, 'ajax_toggle_status'));
        add_action('wp_ajax_ep_expenses_declare_closure', array($this, 'ajax_declare_closure'));
        
        // Hooks AJAX para Liquidaciones de Viaje y Dietas
        add_action('wp_ajax_ep_expenses_get_liqs', array($this, 'ajax_get_liqs'));
        add_action('wp_ajax_ep_expenses_save_liq', array($this, 'ajax_save_liq'));
        add_action('wp_ajax_ep_expenses_delete_liq', array($this, 'ajax_delete_liq'));
        add_action('wp_ajax_ep_expenses_toggle_liq_status', array($this, 'ajax_toggle_liq_status'));
        add_action('wp_ajax_ep_expenses_save_admin_config', array($this, 'ajax_save_admin_config'));
        add_action('wp_ajax_ep_expenses_download_liq_pdf', array($this, 'ajax_download_liq_pdf'));
        add_action('wp_ajax_ep_expenses_download_expense_pdf', array($this, 'ajax_download_expense_pdf'));
        add_action('wp_ajax_ep_expenses_export_xlsx', array($this, 'ajax_export_xlsx'));
        add_action('wp_ajax_ep_expenses_view_attachment_preview', array($this, 'ajax_view_attachment_preview'));

        // El documento de abono lleva impreso su propio CSV: la app de firmas debe
        // almacenar ese mismo, no uno derivado de la solicitud.
        add_filter('ep_signature_csv_for_request', array($this, 'filter_signature_csv'), 10, 2);
    }

    /**
     * Código Seguro de Verificación de una liquidación abonada.
     * Se deriva del id de la liquidación para poder imprimirlo en el PDF antes
     * de que exista la solicitud de firma.
     */
    public static function liquidation_csv($liq_id)
    {
        return strtoupper(substr(hash_hmac('sha256', 'ep_liq_csv|' . intval($liq_id), wp_salt('auth')), 0, 32));
    }

    /**
     * Si la solicitud de firma corresponde al abono de una liquidación, el CSV
     * es el que ya va impreso en el pie del documento.
     */
    public function filter_signature_csv($csv, $request_id)
    {
        global $wpdb;

        $table = EP_Expenses_DB::get_liquidations_table();
        $liq_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE admin_signature_request_id = %d",
            intval($request_id)
        ));
        if ($liq_id) {
            return self::liquidation_csv($liq_id);
        }

        $exp_table = EP_Expenses_DB::get_expenses_table();
        $exp_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $exp_table WHERE admin_signature_request_id = %d",
            intval($request_id)
        ));
        if ($exp_id) {
            return self::expense_csv($exp_id);
        }

        return $csv;
    }

    public function register_app($manager)
    {
        $manager->register_app(new EP_App_Expenses());
    }

    /**
     * Ejecuta la creación/migración de tablas únicamente cuando cambia la versión del esquema.
     * Antes se lanzaba en cada carga de página (dbDelta + SHOW COLUMNS + UPDATE masivo).
     */
    public function maybe_upgrade_db()
    {
        if (get_option('ep_expenses_db_version') === EP_EXPENSES_DB_VERSION) {
            return;
        }
        EP_Expenses_DB::init_db();
        self::ensure_upload_dir();
        update_option('ep_expenses_db_version', EP_EXPENSES_DB_VERSION);
    }

    public function enqueue_assets()
    {
        if (isset($_GET['view']) && $_GET['view'] === 'expenses') {
            $css_path = EP_EXPENSES_PATH . 'assets/css/ep-expenses.css';
            $js_path  = EP_EXPENSES_PATH . 'assets/js/ep-expenses.js';
            $css_ver  = file_exists($css_path) ? filemtime($css_path) : EP_EXPENSES_DB_VERSION;
            $js_ver   = file_exists($js_path)  ? filemtime($js_path)  : EP_EXPENSES_DB_VERSION;

            wp_enqueue_style('ep-expenses-css', EP_EXPENSES_URL . 'assets/css/ep-expenses.css', array(), $css_ver);
            wp_enqueue_script('ep-expenses-js', EP_EXPENSES_URL . 'assets/js/ep-expenses.js', array('jquery'), $js_ver, true);
        }
    }

    /**
     * ==========================================================================
     * MODELO DE PERMISOS CENTRALIZADO DE LA APP DE GASTOS
     * ==========================================================================
     * - Administrador WordPress / Super Admin : lo ve y lo hace todo.
     * - Dirección (ep_direction / ep_hr)      : autoriza o rechaza. Ve todo.
     * - Dpto. Administración (ep_administration) o usuarios con permiso
     *   de escritura sobre la app             : liquidan / abonan. Ven todo.
     * - Resto de empleados                    : solo sus propios gastos.
     *
     * Devuelve siempre el mismo array de contexto para no repetir la lógica
     * (y no volver a desincronizarla) en cada handler AJAX.
     */
    public static function get_user_context($user_id = null)
    {
        $user_id = $user_id ? intval($user_id) : get_current_user_id();

        $perm = 'read';
        if (class_exists('EP_App_Manager')) {
            $perm = EP_App_Manager::get_permission('expenses', $user_id);
        }

        $is_super = user_can($user_id, 'manage_options')
            || user_can($user_id, 'administrator')
            || user_can($user_id, 'ep_super_admin');

        // Ver los gastos de toda la plantilla, liquidarlos y configurar retenciones
        // queda reservado a: administradores de WordPress, Dpto. Administración y
        // usuarios con permiso explícito de escritura sobre la app.
        // Dirección NO entra aquí: por defecto ve solo lo suyo, como cualquier
        // empleado, salvo que se le conceda además permiso de escritura.
        $tiene_poderes = $is_super
            || user_can($user_id, 'ep_administration')
            || ($perm === 'write');

        // Dirección: autoriza y rechaza gastos y liquidaciones.
        $can_approve = $is_super
            || user_can($user_id, 'ep_direction')
            || user_can($user_id, 'ep_hr');

        // Administración: liquida / abona.
        $can_liquidate = $tiene_poderes;

        $can_view_all = $tiene_poderes;

        return array(
            'user_id'       => $user_id,
            'perm'          => $perm,
            'has_access'    => ($perm !== 'none') || $is_super,
            'is_super'      => $is_super,
            'can_approve'   => $can_approve,
            'can_liquidate' => $can_liquidate,
            'can_view_all'  => $can_view_all,
            // Configuración global (IRPF, SS, €/Km, sedes): Administración la necesita
            // para dar de alta las retenciones de cada empleado.
            'can_configure' => $tiene_poderes,
        );
    }

    /**
     * Devuelve (creándolo si hace falta) el directorio de comprobantes, blindado
     * frente al acceso directo por URL: los justificantes solo deben servirse a
     * través de ep_expenses_view_attachment_preview, que comprueba permisos.
     */
    public static function ensure_upload_dir()
    {
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'ep-expenses/';
        $target_url = trailingslashit($upload_dir['baseurl']) . 'ep-expenses/';

        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $htaccess = $target_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "# Justificantes de gastos: acceso solo a través del portal\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
                . "Options -Indexes\n";
            @file_put_contents($htaccess, $rules);
        }

        $index = $target_dir . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return array($target_dir, $target_url);
    }

    /**
     * URL del proxy que sirve un comprobante comprobando permisos.
     */
    public static function get_attachment_proxy_url($filename)
    {
        return admin_url('admin-ajax.php')
            . '?action=ep_expenses_view_attachment_preview&file=' . rawurlencode($filename);
    }

    /**
     * Nombre de usuario cacheado por petición (evita un get_userdata por fila).
     */
    private static function get_display_name($user_id, &$cache)
    {
        $user_id = intval($user_id);
        if (!isset($cache[$user_id])) {
            $info = get_userdata($user_id);
            $cache[$user_id] = $info ? $info->display_name : 'Usuario #' . $user_id;
        }
        return $cache[$user_id];
    }

    /**
     * Estado de una solicitud de firma ('pendiente', 'firmado' o '' si ya no existe).
     */
    private function get_signature_state($request_id)
    {
        $request_id = intval($request_id);
        if (!$request_id || !self::fds_table_exists()) {
            return '';
        }

        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        $state = $wpdb->get_var($wpdb->prepare("SELECT estado FROM $fds_table WHERE id = %d", $request_id));

        return $state ? $state : '';
    }

    /**
     * ¿Existe la tabla de la app de firmas? Se resuelve una sola vez por petición.
     */
    private static function fds_table_exists()
    {
        static $exists = null;
        if ($exists === null) {
            global $wpdb;
            $table  = $wpdb->prefix . 'fds_documentos';
            $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        }
        return $exists;
    }

    /**
     * AJAX: Obtiene el listado de gastos y liquidaciones unificado
     */
    public function ajax_get_list()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $current_user_id = $ctx['user_id'];
        if (!$current_user_id) {
            wp_send_json_error('No autorizado');
        }

        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        $is_direction  = $ctx['can_approve'];
        $is_admin_dept = $ctx['can_liquidate'];
        $is_admin      = $ctx['can_view_all'];

        $year = !empty($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
        // Mes vacío = "Todos los meses" (año completo). Antes caía silenciosamente al mes actual.
        $month = (isset($_POST['month']) && $_POST['month'] !== '') ? sprintf('%02d', intval($_POST['month'])) : '';
        $category = !empty($_POST['category']) ? sanitize_text_field($_POST['category']) : '';

        // Determinación de filtrado por usuario: quien no gestiona solo ve lo suyo.
        if ($is_admin) {
            $requested_user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        } else {
            $requested_user_id = $current_user_id;
        }

        $db = EP_Expenses_DB::get_instance();
        $year_month = $month !== '' ? $year . '-' . $month : '';

        // Rango real del periodo (mes concreto o año completo), con último día correcto.
        if ($month !== '') {
            $period_start = $year . '-' . $month . '-01';
            $period_end   = date('Y-m-t', strtotime($period_start));
        } else {
            $period_start = $year . '-01-01';
            $period_end   = $year . '-12-31';
        }

        $merged_list = array();
        $name_cache  = array();

        // 1. OBTENER TICKETS (si la categoría no es exclusivamente "dieta")
        if ($category !== 'dieta') {
            $args = array();
            if ($year_month !== '') {
                $args['year_month'] = $year_month;
            } else {
                $args['year'] = $year;
            }
            if (!empty($requested_user_id)) {
                $args['user_id'] = $requested_user_id;
            }
            if (!empty($category)) {
                $args['category'] = $category;
            }

            $expenses = $db->get_expenses($args);

            global $wpdb;
            $fds_table_exp = $wpdb->prefix . 'fds_documentos';
            $has_fds_exp = self::fds_table_exists();

            foreach ($expenses as &$exp) {
                $this->sync_expense_status($exp);
                $user_name = self::get_display_name($exp['user_id'], $name_cache);

                // Estado de las firmas del ticket
                $exp_is_signed = false;
                if (!empty($exp['signature_request_id']) && $has_fds_exp) {
                    $estado = $wpdb->get_var($wpdb->prepare(
                        "SELECT estado FROM $fds_table_exp WHERE id = %d",
                        $exp['signature_request_id']
                    ));
                    $exp_is_signed = ($estado === 'firmado');
                }

                $exp_admin_pending = false;
                if (!empty($exp['admin_signature_request_id']) && $has_fds_exp && $exp['status'] !== 'declared') {
                    $estado = $wpdb->get_var($wpdb->prepare(
                        "SELECT estado FROM $fds_table_exp WHERE id = %d",
                        $exp['admin_signature_request_id']
                    ));
                    $exp_admin_pending = ($estado === 'pendiente');
                }

                $raw_url = $exp['attachment_url'];
                if (empty($raw_url)) {
                    $atts_list = array();
                } elseif (strpos(trim((string)$raw_url), '[') === 0) {
                    $atts_list = json_decode($raw_url, true) ?: array();
                } else {
                    $atts_list = array($raw_url);
                }

                $approved_by_name   = !empty($exp['approved_by']) ? self::get_display_name($exp['approved_by'], $name_cache) : '';
                $liquidated_by_name = !empty($exp['liquidated_by']) ? self::get_display_name($exp['liquidated_by'], $name_cache) : '';

                $merged_list[] = array(
                    'id'                 => intval($exp['id']),
                    'ticket_number'      => $exp['ticket_number'],
                    'user_id'            => intval($exp['user_id']),
                    'user_name'          => $user_name,
                    'expense_date'       => $exp['expense_date'],
                    'concept'            => $exp['concept'],
                    'category'           => $exp['category'],
                    'payment_method'     => $exp['payment_method'],
                    'amount'             => floatval($exp['amount']),
                    'attachment_url'     => !empty($atts_list) ? $atts_list[0] : '',
                    'attachments_list'   => $atts_list,
                    'status'             => $exp['status'],
                    'approved_by_name'   => $approved_by_name,
                    'liquidated_by_name' => $liquidated_by_name,
                    // Datos de trazabilidad que el modal de detalles necesita para no
                    // mostrar siempre los valores por defecto.
                    'liquidated_at'      => isset($exp['liquidated_at']) ? $exp['liquidated_at'] : '',
                    'liquidation_method' => isset($exp['liquidation_method']) ? $exp['liquidation_method'] : '',
                    'liquidation_notes'  => isset($exp['liquidation_notes']) ? $exp['liquidation_notes'] : '',
                    'rejection_reason'   => isset($exp['rejection_reason']) ? $exp['rejection_reason'] : '',
                    'google_maps_url'    => isset($exp['google_maps_url']) ? $exp['google_maps_url'] : '',
                    'is_liquidation'     => false,
                    // PDF del justificante generado por el portal (distinto del comprobante adjunto)
                    'justificante_url'   => admin_url('admin-ajax.php') . '?action=ep_expenses_download_expense_pdf&id=' . intval($exp['id']),
                    'signature_request_id' => !empty($exp['signature_request_id']) ? intval($exp['signature_request_id']) : null,
                    'is_signed'          => $exp_is_signed,
                    'admin_signature_request_id' => !empty($exp['admin_signature_request_id']) ? intval($exp['admin_signature_request_id']) : null,
                    'admin_signature_pending'    => $exp_admin_pending
                );
            }
        }

        // 2. OBTENER LIQUIDACIONES (si la categoría es vacía o es exclusivamente "dieta")
        if (empty($category) || $category === 'dieta') {
            $liq_args = array(
                'start_date' => $period_start,
                'end_date'   => $period_end,
            );
            if (!empty($requested_user_id)) {
                $liq_args['user_id'] = $requested_user_id;
            }
            // Dirección y Administración ven TODOS los estados: si se filtrara por
            // 'approved' la liquidación desaparecía del listado nada más abonarla.

            $liquidations = $db->get_liquidations($liq_args);

            global $wpdb;
            $fds_table = $wpdb->prefix . 'fds_documentos';
            $has_fds = self::fds_table_exists();

            foreach ($liquidations as &$liq) {
                $this->sync_liquidation_status($liq);
                $user_name = self::get_display_name($liq['user_id'], $name_cache);

                $approved_by_name   = !empty($liq['approved_by']) ? self::get_display_name($liq['approved_by'], $name_cache) : '';
                $liquidated_by_name = !empty($liq['liquidated_by']) ? self::get_display_name($liq['liquidated_by'], $name_cache) : '';

                $is_signed = false;
                if ($liq['signature_request_id'] && $has_fds) {
                    $sig_doc = $wpdb->get_var($wpdb->prepare(
                        "SELECT estado FROM $fds_table WHERE id = %d",
                        $liq['signature_request_id']
                    ));
                    $is_signed = ($sig_doc === 'firmado');
                }

                // ¿Queda pendiente la firma del abono por parte de Administración?
                $admin_signature_pending = false;
                if (!empty($liq['admin_signature_request_id']) && $has_fds && $liq['status'] !== 'liquidated') {
                    $admin_sig = $wpdb->get_var($wpdb->prepare(
                        "SELECT estado FROM $fds_table WHERE id = %d",
                        $liq['admin_signature_request_id']
                    ));
                    $admin_signature_pending = ($admin_sig === 'pendiente');
                }

                $pdf_url = admin_url('admin-ajax.php') . '?action=ep_expenses_download_liq_pdf&id=' . $liq['id'];

                $atts_list = array();
                if (!empty($liq['attachments'])) {
                    $atts_data = json_decode($liq['attachments'], true);
                    if (!empty($atts_data['urls'])) {
                        $atts_list = $atts_data['urls'];
                    }
                }

                $merged_list[] = array(
                    'id'                 => intval($liq['id']),
                    'ticket_number'      => $liq['liquidation_number'],
                    'user_id'            => intval($liq['user_id']),
                    'user_name'          => $user_name,
                    'expense_date'       => $liq['fecha_documento'],
                    'concept'            => 'Liquidación de viaje a ' . $liq['destino'] . ' (Motivo: ' . $liq['motivo'] . ')',
                    'category'           => 'dieta',
                    'payment_method'     => $liq['payment_method'],
                    'amount'             => floatval($liq['total_percibir']),
                    'attachment_url'     => $pdf_url,
                    'attachments_list'   => $atts_list,
                    'status'             => $liq['status'],
                    'approved_by_name'   => $approved_by_name,
                    'liquidated_by_name' => $liquidated_by_name,
                    'liquidated_at'      => isset($liq['liquidated_at']) ? $liq['liquidated_at'] : '',
                    'liquidation_method' => isset($liq['liquidation_method']) ? $liq['liquidation_method'] : '',
                    'liquidation_notes'  => isset($liq['liquidation_notes']) ? $liq['liquidation_notes'] : '',
                    'rejection_reason'   => isset($liq['rejection_reason']) ? $liq['rejection_reason'] : '',
                    'google_maps_url'    => isset($liq['google_maps_url']) ? $liq['google_maps_url'] : '',
                    'imputa'             => isset($liq['imputa']) ? $liq['imputa'] : '',
                    'is_liquidation'     => true,
                    'signature_request_id' => $liq['signature_request_id'] ? intval($liq['signature_request_id']) : null,
                    'is_signed'          => $is_signed,
                    'admin_signature_request_id' => !empty($liq['admin_signature_request_id']) ? intval($liq['admin_signature_request_id']) : null,
                    'admin_signature_pending'    => $admin_signature_pending
                );
            }
        }

        // Ordenar la lista combinada por fecha descendente
        usort($merged_list, function ($a, $b) {
            return strcmp($b['expense_date'], $a['expense_date']);
        });

        // El cierre mensual solo tiene sentido sobre un mes concreto.
        $closure = ($year_month !== '') ? $db->get_closure_status($requested_user_id, $year_month) : null;
        $current_year_month = date('Y-m');
        $month_is_past = ($year_month !== '' && $year_month < $current_year_month);

        wp_send_json_success(array(
            'expenses'       => $merged_list,
            'closure'        => $closure,
            'year_month'     => $year_month !== '' ? $year_month : (string) $year,
            'month_is_past'  => $month_is_past,
            'is_direction'   => $is_direction,
            'is_admin_dept'  => ($is_admin_dept || $is_direction),
            'current_user_id'=> $current_user_id
        ));
    }


    /**
     * Envía una notificación individual a un usuario (Campana Portal, Bot Teams y Email)
     */
    private function notify_user($user_id, $title, $message, $type = 'info')
    {
        if (class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($user_id, array(
                'type'    => $type,
                'title'   => $title,
                'message' => $message,
                'link'    => '?view=expenses'
            ));
        }
    }

    /**
     * Devuelve los usuarios que cumplen una capacidad del contexto de la app
     * ('can_approve' para Dirección, 'can_liquidate' para Administración).
     *
     * Se resuelve con el mismo get_user_context() que autoriza cada acción, y no
     * con una lista de roles de WordPress. Esa era la avería: los avisos se
     * mandaban a get_users(role__in => 'ep_administration') y ese rol no lo
     * tiene nadie en las instalaciones reales. El Dpto. de Administración se
     * define por el permiso de escritura sobre la app (matriz de permisos u
     * override por usuario), así que la lista salía vacía y ni el portal ni el
     * bot de Teams entregaban nada, sin error visible en ninguna parte.
     */
    private function get_users_with_capability($capability)
    {
        $all_ids = get_users(array('fields' => 'ID'));
        if (empty($all_ids)) {
            return array();
        }

        // Una sola consulta para los datos y los metadatos de todos: si no, cada
        // get_user_context() de dentro del bucle dispara las suyas.
        cache_users($all_ids);

        $matches = array();
        foreach ($all_ids as $uid) {
            $ctx = self::get_user_context($uid);
            if (!empty($ctx[$capability])) {
                $matches[] = intval($uid);
            }
        }

        return $matches;
    }

    /**
     * Avisa a quien gestiona el gasto: Dirección ('can_approve'), Administración
     * ('can_liquidate') o ambos. Cada aviso sale por los tres canales del portal
     * (campana, bot de Teams y correo) según las preferencias de cada usuario.
     */
    private function notify_managers($capabilities, $title, $message, $type = 'info', $exclude_user_id = 0)
    {
        if (!class_exists('EP_Notifications')) {
            if (function_exists('ep_error_log')) {
                ep_error_log('EP Gastos: EP_Notifications no está disponible; se pierde el aviso "' . $title . '".');
            }
            return;
        }

        $target_ids = array();
        foreach ((array) $capabilities as $capability) {
            $target_ids = array_merge($target_ids, $this->get_users_with_capability($capability));
        }
        $target_ids = array_unique($target_ids);

        $sent = array();
        foreach ($target_ids as $uid) {
            if ($uid == $exclude_user_id) continue;
            EP_Notifications::add_notification($uid, array(
                'type'    => $type,
                'title'   => $title,
                'message' => $message,
                'link'    => '?view=expenses'
            ));
            $sent[] = $uid;
        }

        // Sin este rastro, un aviso que no llega es indistinguible de un aviso
        // que sí se mandó y el usuario no vio.
        if (function_exists('ep_error_log')) {
            ep_error_log(
                'EP Gastos: aviso "' . $title . '" para [' . implode(', ', (array) $capabilities) . ']. '
                . 'Destinatarios: ' . count($sent) . ' (' . implode(',', $sent) . '), '
                . 'excluido: ' . intval($exclude_user_id) . '.'
            );
        }
    }

    /**
     * AJAX: Guarda un gasto (Nuevo o Edición) procesando la subida de foto/adjunto
     */
    public function ajax_save()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        // Asegurar que el esquema está al día (no-op salvo cambio de versión)
        $this->maybe_upgrade_db();

        $concept = !empty($_POST['concept']) ? sanitize_text_field($_POST['concept']) : '';
        $amount = !empty($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $expense_date = !empty($_POST['expense_date']) ? sanitize_text_field($_POST['expense_date']) : date('Y-m-d');
        $category = !empty($_POST['category']) ? sanitize_text_field($_POST['category']) : 'dieta';
        $payment_method = !empty($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'personal';
        $notes = !empty($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if (empty($concept) || $amount <= 0) {
            wp_send_json_error('Debes especificar un concepto e importe válido.');
        }

        $attachment_path = '';
        $attachment_url = '';

        $google_maps_url = !empty($_POST['google_maps_url']) ? esc_url_raw($_POST['google_maps_url']) : '';

        // Procesar subida de múltiples archivos / fotos / PDFs (hasta 10)
        $uploaded_paths = array();
        $uploaded_urls  = array();

        $files = array();
        if (!empty($_FILES['expense_files']['name'])) {
            $files = $_FILES['expense_files'];
        } elseif (!empty($_FILES['expense_file']['name'])) {
            $files = $_FILES['expense_file'];
        }

        if (!empty($files['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            list($target_dir, $target_url) = self::ensure_upload_dir();

            $names     = is_array($files['name']) ? $files['name'] : array($files['name']);
            $tmp_names = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
            $errors    = is_array($files['error']) ? $files['error'] : array($files['error']);

            $max_files = 10;
            $count = 0;

            foreach ($names as $i => $name) {
                if (empty($name) || $errors[$i] !== UPLOAD_ERR_OK) continue;
                if ($count >= $max_files) break;

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');

                if (!in_array($ext, $allowed_exts)) {
                    continue;
                }

                $filename = 'gasto_' . time() . '_' . $count . '_' . wp_generate_password(6, false) . '.' . $ext;
                $destination = $target_dir . $filename;

                if (move_uploaded_file($tmp_names[$i], $destination)) {
                    $uploaded_paths[] = $destination;
                    $uploaded_urls[]  = $target_url . $filename;
                    $count++;
                }
            }
        }

        $is_new = empty($_POST['id']);
        $data = array(
            'id'              => !empty($_POST['id']) ? intval($_POST['id']) : 0,
            'user_id'         => $user_id,
            'actor_id'        => $user_id,
            // Editar el registro de otro empleado es cosa de Dirección, no de quien solo liquida.
            'can_manage_all'  => $ctx['can_approve'],
            'expense_date'    => $expense_date,
            'concept'         => $concept,
            'category'        => $category,
            'amount'          => $amount,
            'payment_method'  => $payment_method,
            'notes'           => $notes,
            'google_maps_url' => $google_maps_url,
        );

        if (!empty($uploaded_urls)) {
            if (count($uploaded_urls) === 1) {
                $data['attachment_path'] = $uploaded_paths[0];
                $data['attachment_url']  = $uploaded_urls[0];
            } else {
                $data['attachment_path'] = wp_json_encode($uploaded_paths);
                $data['attachment_url']  = wp_json_encode($uploaded_urls);
            }
        }

        $db = EP_Expenses_DB::get_instance();
        $expense_id = $db->save_expense($data);

        if ($expense_id) {
            if (function_exists('ep_stats_log')) {
                $event_type = $is_new ? 'expense_created' : 'expense_updated';
                ep_stats_log('expenses', $event_type, $user_id, array(
                    'expense_id' => $expense_id,
                    'concept'    => $concept,
                    'amount'     => $amount,
                    'category'   => $category
                ));
            }

            // Notificación a Dirección cuando un empleado registra un gasto NUEVO
            if ($is_new) {
                $user_data = get_userdata($user_id);
                $user_name = $user_data ? $user_data->display_name : 'Un empleado';
                $this->notify_managers(
                    'can_approve',
                    'Nuevo Ticket de Gasto Registrado',
                    "El empleado {$user_name} ha registrado un nuevo ticket de gasto ({$concept}, " . number_format($amount, 2) . " €). Pendiente de autorización por Dirección.",
                    'warning',
                    $user_id
                );
            }

            // Todo justificante se firma electrónicamente, sea del tipo que sea.
            $signature_request_id = null;
            if ($is_new) {
                $signature_request_id = $this->create_expense_signature_request($expense_id);
            }

            wp_send_json_success(array(
                'expense_id'           => $expense_id,
                'signature_request_id' => $signature_request_id ? intval($signature_request_id) : null,
            ));
        } else {
            if (!$is_new) {
                wp_send_json_error('No puedes modificar este gasto: no es tuyo o ya ha sido autorizado o liquidado.');
            }
            global $wpdb;
            $err = !empty($wpdb->last_error) ? $wpdb->last_error : 'No se pudo registrar el gasto en la base de datos.';
            wp_send_json_error($err);
        }
    }

    /**
     * AJAX: Elimina un gasto
     */
    public function ajax_delete()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;

        if (!$user_id || !$id) {
            wp_send_json_error('Parámetros inválidos');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        $db = EP_Expenses_DB::get_instance();
        $success = $db->delete_expense($id, $user_id, $ctx['can_approve']);

        if ($success) {
            if (function_exists('ep_stats_log')) {
                ep_stats_log('expenses', 'expense_deleted', $user_id, array('expense_id' => $id));
            }
            wp_send_json_success();
        } else {
            wp_send_json_error('No se pudo eliminar el gasto o careces de permisos.');
        }
    }

    /**
     * AJAX: Ejecuta la declaración de cierre de gastos del mes (Día 28)
     */
    public function ajax_declare_closure()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        $year_month = !empty($_POST['year_month']) ? sanitize_text_field($_POST['year_month']) : date('Y-m');
        $notes = !empty($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }
        // El cierre siempre es del periodo propio del usuario autenticado.
        if (!preg_match('/^\d{4}-\d{2}$/', $year_month)) {
            wp_send_json_error('Periodo no válido.');
        }

        $db = EP_Expenses_DB::get_instance();
        $result = $db->declare_monthly_closure($user_id, $year_month, $notes);

        if ($result) {
            if (function_exists('ep_stats_log')) {
                ep_stats_log('expenses', 'expense_closure_declared', $user_id, array('year_month' => $year_month));
            }

            // Notificar a Administración y Dirección
            $user_data = get_userdata($user_id);
            $user_name = $user_data ? $user_data->display_name : 'Un empleado';
            $this->notify_managers(
                array('can_liquidate', 'can_approve'),
                'Cierre Mensual de Gastos Declarado',
                "El empleado {$user_name} ha declarado su resumen mensual de gastos para el periodo {$year_month}.",
                'info',
                $user_id
            );

            wp_send_json_success(array('success' => true));
        } else {
            wp_send_json_error('No hay tickets registrados en este mes para cerrar.');
        }
    }

    /**
     * AJAX: Cambia el estado de un gasto individual ('approved', 'rejected', 'declared', 'pending')
     */
    public function ajax_toggle_status()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        $is_direction  = $ctx['can_approve'];
        $is_admin_dept = $ctx['can_liquidate'];

        $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
        $status = !empty($_POST['status']) ? sanitize_text_field($_POST['status']) : 'approved';

        if (!$id) {
            wp_send_json_error('ID de gasto no especificado');
        }

        $allowed_statuses = array('pending', 'approved', 'rejected', 'declared');
        if (!in_array($status, $allowed_statuses, true)) {
            wp_send_json_error('Estado no válido.');
        }

        // Validación de Permisos por Acción:
        // 1. Aprobar / Rechazar / Reabrir gasto: Requiere rol de Dirección
        if (($status === 'approved' || $status === 'rejected' || $status === 'pending') && !$is_direction) {
            wp_send_json_error('Solo el personal de Dirección o Administración General puede autorizar, rechazar o reabrir gastos.');
        }

        // 2. Liquidar gasto: Requiere Dpto. Administración o Dirección/Admin
        if ($status === 'declared' && !$is_admin_dept) {
            wp_send_json_error('Solo el personal del Dpto. de Administración puede liquidar gastos.');
        }

        $db = EP_Expenses_DB::get_instance();
        $expense_before = $db->get_expense($id);
        if (!$expense_before) {
            wp_send_json_error('Gasto no encontrado.');
        }

        $liquidation_data = array(
            'liquidated_by'      => $user_id,
            'liquidation_method' => !empty($_POST['liquidation_method']) ? sanitize_text_field($_POST['liquidation_method']) : 'Transferencia Bancaria',
            'liquidation_notes'  => !empty($_POST['liquidation_notes']) ? sanitize_textarea_field($_POST['liquidation_notes']) : ''
        );

        // --------------------------------------------------------------------
        // ABONO: igual que en las liquidaciones de viaje, el ticket no pasa a
        // liquidado hasta que Administración firma el documento del abono.
        // --------------------------------------------------------------------
        if ($status === 'declared') {
            if (!class_exists('EP_App_Signature_V4')) {
                wp_send_json_error('La aplicación de Firma Electrónica no está disponible; no se puede completar el abono.');
            }

            global $wpdb;
            $wpdb->update(
                EP_Expenses_DB::get_expenses_table(),
                array(
                    'liquidation_method' => $liquidation_data['liquidation_method'],
                    'liquidation_notes'  => $liquidation_data['liquidation_notes'],
                ),
                array('id' => $id)
            );
            $expense_before['liquidation_method'] = $liquidation_data['liquidation_method'];
            $expense_before['liquidation_notes']  = $liquidation_data['liquidation_notes'];

            $admin_req_id = intval($expense_before['admin_signature_request_id']);
            $sig_state    = $admin_req_id ? $this->get_signature_state($admin_req_id) : '';

            if ($sig_state !== 'firmado') {
                if (!$admin_req_id || $sig_state === '') {
                    if ($admin_req_id) {
                        $wpdb->update(
                            EP_Expenses_DB::get_expenses_table(),
                            array('admin_signature_request_id' => null),
                            array('id' => $id)
                        );
                    }
                    $admin_req_id = $this->create_admin_expense_signature_request($id);
                }

                if (!$admin_req_id) {
                    wp_send_json_error('Error al generar la solicitud de firma para el abono.');
                }

                wp_send_json_success(array(
                    'requires_signature'   => true,
                    'signature_request_id' => intval($admin_req_id)
                ));
            }
        }

        $approval_data = array(
            'approved_by'      => $user_id,
            'rejection_reason' => !empty($_POST['rejection_reason']) ? sanitize_textarea_field($_POST['rejection_reason']) : ''
        );

        $updated = $db->update_status($id, $status, $liquidation_data, $approval_data);

        if ($updated !== false) {
            if (function_exists('ep_stats_log')) {
                $event_type = 'expense_' . $status;
                if ($status === 'declared') $event_type = 'expense_liquidated';
                if ($status === 'pending')  $event_type = 'expense_reopened';

                ep_stats_log('expenses', $event_type, $user_id, array(
                    'expense_id' => $id,
                    'status'     => $status
                ));
            }

            // NOTIFICACIONES MULTICANAL (Campana, Teams Bot y Email)
            if ($expense_before) {
                $emp_id    = intval($expense_before['user_id']);
                $ticket_num= $expense_before['ticket_number'];
                $concept   = $expense_before['concept'];
                $amount_fmt= number_format(floatval($expense_before['amount']), 2) . ' €';

                $emp_data  = get_userdata($emp_id);
                $emp_name  = $emp_data ? $emp_data->display_name : 'Un empleado';

                if ($status === 'approved') {
                    // Notificar al Empleado
                    $this->notify_user(
                        $emp_id,
                        'Tu Ticket de Gasto ha sido Aprobado',
                        "Tu ticket {$ticket_num} ({$concept}, {$amount_fmt}) ha sido AUTORIZADO por Dirección y enviado al Dpto. de Administración para su liquidación.",
                        'success'
                    );
                    // Notificar al Dpto. de Administración
                    $this->notify_managers(
                        'can_liquidate',
                        'Nuevo Gasto Aprobado para Liquidar',
                        "El ticket {$ticket_num} de {$emp_name} ({$concept}, {$amount_fmt}) ha sido autorizado por Dirección y está listo para su liquidación.",
                        'info',
                        $user_id
                    );
                } elseif ($status === 'rejected') {
                    $reason = !empty($_POST['rejection_reason']) ? sanitize_text_field($_POST['rejection_reason']) : 'Sin motivo especificado';
                    // Notificar al Empleado
                    $this->notify_user(
                        $emp_id,
                        'Tu Ticket de Gasto ha sido Rechazado',
                        "Tu ticket {$ticket_num} ({$concept}, {$amount_fmt}) ha sido RECHAZADO por Dirección. Motivo: {$reason}.",
                        'error'
                    );
                    $this->notify_managers(
                        'can_liquidate',
                        'Ticket de Gasto Rechazado',
                        "El ticket {$ticket_num} de {$emp_name} ({$concept}, {$amount_fmt}) ha sido rechazado por Dirección. Motivo: {$reason}.",
                        'warning',
                        $user_id
                    );
                } elseif ($status === 'declared') {
                    $method = $liquidation_data['liquidation_method'];
                    // Notificar al Empleado
                    $this->notify_user(
                        $emp_id,
                        'Tu Gasto ha sido Liquidado / Abonado',
                        "¡Buenas noticias! Tu ticket {$ticket_num} ({$concept}, {$amount_fmt}) ha sido LIQUIDADO por Administración vía {$method}.",
                        'success'
                    );
                    $this->notify_managers(
                        'can_approve',
                        'Ticket de Gasto Abonado',
                        "El ticket {$ticket_num} de {$emp_name} ({$concept}, {$amount_fmt}) ha sido abonado por Administración vía {$method}.",
                        'info',
                        $user_id
                    );
                }
            }

            wp_send_json_success(array('id' => $id, 'status' => $status));
        } else {
            wp_send_json_error('Error al actualizar el estado del gasto.');
        }
    }

    /**
     * AJAX: Obtiene el listado de liquidaciones de viaje filtrado
     */
    public function ajax_get_liqs()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación.');
        }

        $is_admin = $ctx['can_view_all'];

        $args = array();
        if ($is_admin) {
            $requested_user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            if ($requested_user_id > 0) {
                $args['user_id'] = $requested_user_id;
            }
        } else {
            $args['user_id'] = $user_id;
        }

        if (!empty($_POST['status'])) {
            $args['status'] = sanitize_text_field($_POST['status']);
        }
        if (!empty($_POST['sede_id'])) {
            $args['sede_id'] = intval($_POST['sede_id']);
        }
        if (!empty($_POST['start_date'])) {
            $args['start_date'] = sanitize_text_field($_POST['start_date']);
        }
        if (!empty($_POST['end_date'])) {
            $args['end_date'] = sanitize_text_field($_POST['end_date']);
        }

        $db = EP_Expenses_DB::get_instance();
        $liquidations = $db->get_liquidations($args);

        // Añadir display_name y comprobar estado de la firma en wp_fds_documentos
        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        $has_fds = self::fds_table_exists();
        $name_cache = array();

        foreach ($liquidations as &$liq) {
            $liq['user_name'] = self::get_display_name($liq['user_id'], $name_cache);
            
            // Trazabilidad de firma
            $liq['is_signed'] = false;
            $liq['signature_url'] = '';
            if ($liq['signature_request_id'] && $has_fds) {
                $sig_doc = $wpdb->get_row($wpdb->prepare(
                    "SELECT estado, url_documento_firmado FROM $fds_table WHERE id = %d",
                    $liq['signature_request_id']
                ));
                if ($sig_doc) {
                    $liq['is_signed'] = ($sig_doc->estado === 'firmado');
                    $liq['signature_url'] = $sig_doc->url_documento_firmado;
                }
            }
        }

        // Obtener la configuración de sedes
        $numbering_config = get_option('ep_expenses_numbering_config', []);

        wp_send_json_success(array(
            'liquidations' => $liquidations,
            'numbering_config' => $numbering_config,
            'is_admin' => $is_admin
        ));
    }

    /**
     * AJAX: Guarda una liquidación de viaje y crea la solicitud de firma automáticamente
     */
    public function ajax_save_liq()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        $destino = !empty($_POST['destino']) ? sanitize_text_field($_POST['destino']) : '';
        $motivo = !empty($_POST['motivo']) ? sanitize_textarea_field($_POST['motivo']) : '';
        $imputa = !empty($_POST['imputa']) ? sanitize_text_field($_POST['imputa']) : 'Ninguno';
        $google_maps_url = !empty($_POST['google_maps_url']) ? esc_url_raw($_POST['google_maps_url']) : '';
        $fecha_desde = !empty($_POST['fecha_desde']) ? sanitize_text_field($_POST['fecha_desde']) : '';
        $fecha_hasta = !empty($_POST['fecha_hasta']) ? sanitize_text_field($_POST['fecha_hasta']) : '';

        // El justificante del trayecto es obligatorio en la liquidación de viaje
        // (y solo en ella: los tickets sueltos no lo piden).
        if (empty($google_maps_url)) {
            wp_send_json_error('Debes indicar el enlace de ruta (Google Maps) que justifica el trayecto.');
        }

        if (empty($destino) || empty($motivo) || empty($fecha_desde) || empty($fecha_hasta)) {
            wp_send_json_error('Por favor, rellene todos los campos obligatorios del viaje.');
        }

        // Procesar subida de múltiples archivos / comprobantes para la liquidación
        $uploaded_paths = array();
        $uploaded_urls  = array();

        $files = array();
        if (!empty($_FILES['expense_files']['name'])) {
            $files = $_FILES['expense_files'];
        } elseif (!empty($_FILES['expense_file']['name'])) {
            $files = $_FILES['expense_file'];
        }

        if (!empty($files['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            list($target_dir, $target_url) = self::ensure_upload_dir();

            $names     = is_array($files['name']) ? $files['name'] : array($files['name']);
            $tmp_names = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
            $errors    = is_array($files['error']) ? $files['error'] : array($files['error']);

            $max_files = 10;
            $count = 0;

            foreach ($names as $i => $name) {
                if (empty($name) || $errors[$i] !== UPLOAD_ERR_OK) continue;
                if ($count >= $max_files) break;

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');

                if (!in_array($ext, $allowed_exts)) {
                    continue;
                }

                $filename = 'liq_adjunto_' . time() . '_' . $count . '_' . wp_generate_password(6, false) . '.' . $ext;
                $destination = $target_dir . $filename;

                if (move_uploaded_file($tmp_names[$i], $destination)) {
                    $uploaded_paths[] = $destination;
                    $uploaded_urls[]  = $target_url . $filename;
                    $count++;
                }
            }
        }

        // Lista blanca explícita de campos: nunca se vuelca $_POST entero, para que
        // no puedan llegar user_id, status ni los identificadores de firma.
        $data = array(
            'id'                 => !empty($_POST['id']) ? intval($_POST['id']) : 0,
            'actor_id'           => $user_id,
            'can_manage_all'     => $ctx['can_approve'],
            'sede_id'            => !empty($_POST['sede_id']) ? intval($_POST['sede_id']) : 1,
            'destino'            => $destino,
            'motivo'             => $motivo,
            'imputa'             => $imputa,
            'fecha_desde'        => $fecha_desde,
            'fecha_hasta'        => $fecha_hasta,
            'hora_desde'         => !empty($_POST['hora_desde']) ? sanitize_text_field($_POST['hora_desde']) : '',
            'hora_hasta'         => !empty($_POST['hora_hasta']) ? sanitize_text_field($_POST['hora_hasta']) : '',
            'kilometros'         => isset($_POST['kilometros']) ? floatval($_POST['kilometros']) : 0,
            'gastos_manutencion' => isset($_POST['gastos_manutencion']) ? wp_unslash($_POST['gastos_manutencion']) : '[]',
            'gastos_alojamiento' => isset($_POST['gastos_alojamiento']) ? wp_unslash($_POST['gastos_alojamiento']) : '[]',
            'otros_gastos'       => isset($_POST['otros_gastos']) ? wp_unslash($_POST['otros_gastos']) : '[]',
            'fecha_documento'    => !empty($_POST['fecha_documento']) ? sanitize_text_field($_POST['fecha_documento']) : current_time('Y-m-d'),
            'notes'              => isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '',
            'payment_method'     => !empty($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'personal',
            'google_maps_url'    => $google_maps_url,
        );

        if (!empty($uploaded_urls)) {
            $data['attachments'] = wp_json_encode(array(
                'paths' => $uploaded_paths,
                'urls'  => $uploaded_urls
            ));
        }

        $db = EP_Expenses_DB::get_instance();
        $liq_id = $db->save_liquidation($data);

        if ($liq_id) {
            // Generar PDF y crear la solicitud de firma electrónica automáticamente
            $request_id = $this->create_liquidation_signature_request($liq_id);

            // Aviso a Dirección de que hay una liquidación nueva pendiente de autorizar,
            // igual que ya se hacía con los tickets individuales.
            if (empty($_POST['id'])) {
                $liq_new   = $db->get_liquidation($liq_id);
                $user_data = get_userdata($user_id);
                $user_name = $user_data ? $user_data->display_name : 'Un empleado';
                $liq_num   = $liq_new ? $liq_new['liquidation_number'] : '#' . $liq_id;
                $liq_total = $liq_new ? number_format(floatval($liq_new['total_percibir']), 2) . ' €' : '';

                $this->notify_managers(
                    'can_approve',
                    'Nueva Liquidación de Viaje Registrada',
                    "El empleado {$user_name} ha registrado la liquidación {$liq_num} (viaje a {$destino}, {$liq_total}). Pendiente de autorización por Dirección.",
                    'warning',
                    $user_id
                );
            }

            wp_send_json_success(array(
                'liquidation_id' => $liq_id,
                'signature_request_id' => $request_id
            ));
        } else {
            if (!empty($_POST['id'])) {
                wp_send_json_error('No puedes modificar esta liquidación: no es tuya o ya ha sido aprobada o abonada.');
            }
            wp_send_json_error('Error al guardar la liquidación en la base de datos.');
        }
    }

    /**
     * AJAX: Elimina una liquidación
     */
    public function ajax_delete_liq()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;

        if (!$user_id || !$id) {
            wp_send_json_error('Parámetros inválidos');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        $db = EP_Expenses_DB::get_instance();
        $liq = $db->get_liquidation($id);

        if (!$liq) {
            wp_send_json_error('Liquidación no encontrada');
        }

        $is_direction = $ctx['can_approve'];
        $is_owner = (intval($liq['user_id']) === $user_id);

        if (($is_owner && in_array($liq['status'], array('pending', 'rejected'), true)) || $is_direction) {
            $success = $db->delete_liquidation($id, $user_id, $is_direction);
            if ($success) {
                // Eliminar solicitudes de firma asociadas (empleado y administración)
                if (self::fds_table_exists()) {
                    global $wpdb;
                    $sig_table = $wpdb->prefix . 'fds_documentos';
                    foreach (array($liq['signature_request_id'], $liq['admin_signature_request_id']) as $sig_id) {
                        if (!empty($sig_id)) {
                            $wpdb->delete($sig_table, array('id' => intval($sig_id)));
                        }
                    }
                }
                wp_send_json_success();
            } else {
                wp_send_json_error('No se pudo eliminar de la base de datos.');
            }
        } else {
            wp_send_json_error('No tienes permisos para eliminar esta liquidación o ya no está pendiente.');
        }
    }

    /**
     * AJAX: Cambia el estado de una liquidación
     */
    public function ajax_toggle_liq_status()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }
        if (!$ctx['has_access']) {
            wp_send_json_error('Acceso denegado a la aplicación de gastos.');
        }

        $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
        $status = !empty($_POST['status']) ? sanitize_text_field($_POST['status']) : 'approved';

        if (!$id) {
            wp_send_json_error('ID no especificado');
        }

        $allowed_statuses = array('pending', 'approved', 'rejected', 'liquidated', 'declared');
        if (!in_array($status, $allowed_statuses, true)) {
            wp_send_json_error('Estado no válido.');
        }

        // Aprobar / rechazar / reabrir: Dirección. Abonar: Dpto. Administración.
        if (in_array($status, array('approved', 'rejected', 'pending'), true) && !$ctx['can_approve']) {
            wp_send_json_error('Solo el personal de Dirección puede autorizar, rechazar o reabrir liquidaciones.');
        }
        if (in_array($status, array('liquidated', 'declared'), true) && !$ctx['can_liquidate']) {
            wp_send_json_error('Solo el personal del Dpto. de Administración puede abonar liquidaciones.');
        }

        $db = EP_Expenses_DB::get_instance();
        $liq_before = $db->get_liquidation($id);
        if (!$liq_before) {
            wp_send_json_error('Liquidación no encontrada');
        }

        $method = !empty($_POST['liquidation_method']) ? sanitize_text_field($_POST['liquidation_method']) : 'Transferencia Bancaria';
        $notes  = !empty($_POST['liquidation_notes']) ? sanitize_textarea_field($_POST['liquidation_notes']) : '';

        $liquidation_data = array(
            'liquidated_by'      => $user_id,
            'liquidation_method' => $method,
            'liquidation_notes'  => $notes
        );

        $approval_data = array(
            'approved_by' => $user_id,
            'rejection_reason' => !empty($_POST['rejection_reason']) ? sanitize_textarea_field($_POST['rejection_reason']) : ''
        );

        if ($status === 'approved' && empty($liq_before['signature_request_id'])) {
            $this->create_liquidation_signature_request($id);
        }

        // --------------------------------------------------------------------
        // ABONO: la liquidación NO pasa a 'liquidated' hasta que Administración
        // firma el documento. Aquí solo se prepara la firma y se redirige.
        // --------------------------------------------------------------------
        if ($status === 'liquidated' || $status === 'declared') {
            if (!class_exists('EP_App_Signature_V4')) {
                wp_send_json_error('La aplicación de Firma Electrónica no está disponible; no se puede completar el abono.');
            }

            // Guardar YA la forma de pago y las notas: van impresas en el PDF a firmar
            // y antes se perdían (se escribían en columnas que no existían).
            global $wpdb;
            $wpdb->update(
                $db->get_liquidations_table(),
                array('liquidation_method' => $method, 'liquidation_notes' => $notes),
                array('id' => $id)
            );
            $liq_before['liquidation_method'] = $method;
            $liq_before['liquidation_notes']  = $notes;

            $admin_req_id = intval($liq_before['admin_signature_request_id']);
            $sig_state    = $admin_req_id ? $this->get_signature_state($admin_req_id) : '';

            if ($sig_state !== 'firmado') {
                // Si la solicitud anterior se quedó a medias o fue borrada, se regenera.
                if (!$admin_req_id || $sig_state === '') {
                    if ($admin_req_id) {
                        $wpdb->update(
                            $db->get_liquidations_table(),
                            array('admin_signature_request_id' => null),
                            array('id' => $id)
                        );
                        $liq_before['admin_signature_request_id'] = null;
                    }
                    $admin_req_id = $this->create_admin_liquidation_signature_request($id);
                }

                if (!$admin_req_id) {
                    wp_send_json_error('Error al generar la solicitud de firma para la liquidación.');
                }

                // Pendiente de firma: se devuelve el id para llevar al firmante al documento.
                wp_send_json_success(array(
                    'requires_signature'   => true,
                    'signature_request_id' => intval($admin_req_id)
                ));
            }

            // Ya está firmada: se puede marcar como abonada.
            $status = 'liquidated';
        }

        $updated = $db->update_liquidation_status($id, $status, $liquidation_data, $approval_data);

        if ($updated !== false) {
            $emp_id = intval($liq_before['user_id']);
            $liq_num = $liq_before['liquidation_number'];
            $destino = $liq_before['destino'];
            $amount_fmt = number_format(floatval($liq_before['total_percibir']), 2) . ' €';

            $actor_data = get_userdata($user_id);
            $actor_name = $actor_data ? $actor_data->display_name : 'Dirección';
            $emp_data   = get_userdata($emp_id);
            $emp_name   = $emp_data ? $emp_data->display_name : 'Un empleado';

            if ($status === 'approved') {
                $this->notify_user(
                    $emp_id,
                    'Tu liquidación ha sido Aprobada',
                    "La liquidación {$liq_num} ({$destino}, {$amount_fmt}) ha sido AUTORIZADA por Administración/Dirección.",
                    'success'
                );
                // Administración es quien la abona: sin este aviso la liquidación
                // aprobada se quedaba esperando a que alguien entrase a mirarla.
                $this->notify_managers(
                    'can_liquidate',
                    'Nueva Liquidación Aprobada para Abonar',
                    "La liquidación {$liq_num} de {$emp_name} ({$destino}, {$amount_fmt}) ha sido autorizada por {$actor_name} y está lista para su abono.",
                    'info',
                    $user_id
                );
            } elseif ($status === 'rejected') {
                $reason = !empty($_POST['rejection_reason']) ? sanitize_text_field($_POST['rejection_reason']) : 'Sin motivo especificado';
                $this->notify_user(
                    $emp_id,
                    'Liquidación Rechazada',
                    "Tu liquidación {$liq_num} ({$destino}, {$amount_fmt}) ha sido RECHAZADA. Motivo: {$reason}.",
                    'error'
                );
                $this->notify_managers(
                    'can_liquidate',
                    'Liquidación de Viaje Rechazada',
                    "La liquidación {$liq_num} de {$emp_name} ({$destino}, {$amount_fmt}) ha sido rechazada por {$actor_name}. Motivo: {$reason}.",
                    'warning',
                    $user_id
                );
            } elseif ($status === 'liquidated' || $status === 'declared') {
                $this->notify_user(
                    $emp_id,
                    'Liquidación Abonada',
                    "¡Buenas noticias! Tu liquidación {$liq_num} ({$destino}, {$amount_fmt}) ha sido marcada como LIQUIDADA / ABONADA.",
                    'success'
                );
                // Dirección autorizó el gasto: se le cierra el circuito avisándole del abono.
                $this->notify_managers(
                    'can_approve',
                    'Liquidación de Viaje Abonada',
                    "La liquidación {$liq_num} de {$emp_name} ({$destino}, {$amount_fmt}) ha sido abonada por {$actor_name}.",
                    'info',
                    $user_id
                );
            }

            wp_send_json_success(array('id' => $id, 'status' => $status));
        } else {
            wp_send_json_error('Error al actualizar el estado de la liquidación.');
        }
    }

    /**
     * AJAX: Guarda configuraciones generales de IRPF, precio Km y sedes (Administración)
     */
    public function ajax_save_admin_config()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_send_json_error('No autorizado');
        }

        if (!$ctx['can_configure']) {
            wp_send_json_error('No tienes permisos de administración.');
        }

        // 1. Guardar la configuración de los trabajadores
        if (isset($_POST['workers_config'])) {
            $raw_workers = $_POST['workers_config'];
            $workers_config = is_string($raw_workers) ? json_decode(wp_unslash($raw_workers), true) : $raw_workers;
            if (is_array($workers_config)) {
                $sanitized_workers = [];
                foreach ($workers_config as $uid => $conf) {
                    $sanitized_workers[intval($uid)] = [
                        'irpf' => floatval($conf['irpf']),
                        'km_rate' => floatval($conf['km_rate']),
                        'ss' => floatval($conf['ss'])
                    ];
                }
                update_option('ep_expenses_workers_config', $sanitized_workers);
            }
        }

        // 2. Guardar la numeración de las sedes (dinámicas)
        if (isset($_POST['numbering_config'])) {
            $raw_numbering = $_POST['numbering_config'];
            $numbering_config = is_string($raw_numbering) ? json_decode(wp_unslash($raw_numbering), true) : $raw_numbering;
            if (is_array($numbering_config)) {
                $sanitized_numbering = [];
                foreach ($numbering_config as $key => $sede) {
                    if (isset($sede['name']) && isset($sede['prefix'])) {
                        $sanitized_numbering[intval($key)] = [
                            'name' => sanitize_text_field($sede['name']),
                            'prefix' => sanitize_text_field($sede['prefix']),
                            // 'next_number' ya no se guarda: el correlativo se deduce de
                            // lo emitido en el ejercicio (ver next_in_series).
                        ];
                    }
                }
                update_option('ep_expenses_numbering_config', $sanitized_numbering);
            }
        }

        // 3. Guardar el límite exento global
        if (isset($_POST['km_exempt_limit'])) {
            update_option('ep_expenses_km_exempt_limit', sanitize_text_field($_POST['km_exempt_limit']));
        }

        wp_send_json_success('Configuración guardada correctamente.');
    }

    /**
     * AJAX/HTTP GET: Descarga el PDF (redirecciona a versión firmada o genera al vuelo una no firmada)
     */
    public function ajax_download_liq_pdf()
    {
        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_die('Acceso denegado. No autorizado.');
        }
        if (!$ctx['has_access']) {
            wp_die('Acceso denegado a la aplicación de gastos.');
        }

        $id = !empty($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) {
            wp_die('ID de liquidación no válido.');
        }

        $db = EP_Expenses_DB::get_instance();
        $liq = $db->get_liquidation($id);
        if (!$liq) {
            wp_die('Liquidación no encontrada.');
        }

        $is_admin = $ctx['can_view_all'];
        $is_owner = (intval($liq['user_id']) === $user_id);

        if (!$is_owner && !$is_admin) {
            wp_die('No tienes permisos para consultar esta liquidación.');
        }

        // Sincronizar el estado
        $this->sync_liquidation_status($liq);

        // Comprobar si el documento está firmado electrónicamente en wp_fds_documentos
        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        $has_fds = self::fds_table_exists();
        
        // 1. Prioridad: Firma del Administrador (Liquidación finalizada)
        if ($liq['admin_signature_request_id'] && $has_fds) {
            $sig_doc = $wpdb->get_row($wpdb->prepare(
                "SELECT estado, nombre_documento FROM $fds_table WHERE id = %d",
                $liq['admin_signature_request_id']
            ));
            
            if ($sig_doc && $sig_doc->estado === 'firmado') {
                if (class_exists('EP_App_Signature_V4')) {
                    $nonce = wp_create_nonce('ep_signature_nonce');
                    $proxy_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $liq['admin_signature_request_id'] . '&nonce=' . $nonce . '&t=' . time();
                    wp_redirect($proxy_url);
                    exit;
                }
            }
        }

        // 2. Fallback: Firma del Empleado (Presentación inicial)
        if ($liq['signature_request_id'] && $has_fds) {
            $sig_doc = $wpdb->get_row($wpdb->prepare(
                "SELECT estado, nombre_documento FROM $fds_table WHERE id = %d",
                $liq['signature_request_id']
            ));
            
            if ($sig_doc && $sig_doc->estado === 'firmado') {
                if (class_exists('EP_App_Signature_V4')) {
                    $nonce = wp_create_nonce('ep_signature_nonce');
                    $proxy_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $liq['signature_request_id'] . '&nonce=' . $nonce . '&t=' . time();
                    wp_redirect($proxy_url);
                    exit;
                }
            }
        }

        // Si no está firmado electrónicamente aún, generamos el PDF no firmado en tiempo real
        $pdf_path = $this->generate_pdf_file($liq);
        if (is_wp_error($pdf_path) || !$pdf_path || !file_exists($pdf_path)) {
            wp_die('Error al generar el PDF de liquidación.');
        }

        // Descargar el PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Liquidacion_' . self::slug_documento($liq['liquidation_number']) . '.pdf"');
        header('Content-Length: ' . filesize($pdf_path));
        readfile($pdf_path);
        
        @unlink($pdf_path);
        exit;
    }

    /**
     * AJAX/HTTP GET: Exporta a Excel los gastos y liquidaciones del periodo pedido.
     * Reservado a quien gestiona gastos (ver get_user_context).
     */
    public function ajax_export_xlsx()
    {
        check_ajax_referer('ep_ajax_nonce', 'nonce');

        $ctx = self::get_user_context();
        if (!$ctx['user_id'] || !$ctx['has_access']) {
            wp_die('Acceso denegado.');
        }
        if (!$ctx['can_view_all']) {
            wp_die('No tienes permisos para exportar los gastos de la plantilla.');
        }

        require_once EP_EXPENSES_PATH . 'class-ep-expenses-xlsx.php';

        $periodo = isset($_REQUEST['periodo']) ? sanitize_text_field($_REQUEST['periodo']) : '1m';

        $hoy    = current_time('Y-m-d');
        $years  = array();
        $start  = null;
        $end    = $hoy;
        $etiqueta = '';

        switch ($periodo) {
            case '3m':
                $start = date('Y-m-d', strtotime('-3 months', strtotime($hoy)));
                $etiqueta = 'ultimos-3-meses';
                break;
            case '6m':
                $start = date('Y-m-d', strtotime('-6 months', strtotime($hoy)));
                $etiqueta = 'ultimos-6-meses';
                break;
            case 'ytd':
                $start = date('Y', strtotime($hoy)) . '-01-01';
                $etiqueta = 'anio-' . date('Y', strtotime($hoy));
                break;
            case 'years':
                $pedidos = isset($_REQUEST['anios']) ? (array) $_REQUEST['anios'] : array();
                $tope    = intval(date('Y', strtotime($hoy)));
                foreach ($pedidos as $y) {
                    $y = intval($y);
                    // Solo años ya transcurridos: nada por delante del año en curso.
                    if ($y >= 2000 && $y <= $tope) {
                        $years[] = $y;
                    }
                }
                $years = array_slice(array_unique($years), 0, 5); // máximo 5 años
                if (empty($years)) {
                    wp_die('Selecciona al menos un año.');
                }
                sort($years);
                $start = null;
                $end   = null;
                $etiqueta = 'anios-' . implode('-', $years);
                break;
            case '1m':
            default:
                $start = date('Y-m-d', strtotime('-1 month', strtotime($hoy)));
                $etiqueta = 'ultimo-mes';
                break;
        }

        $db = EP_Expenses_DB::get_instance();

        $args_exp = array('order_by' => '`expense_date` DESC');
        $args_liq = array('order_by' => '`fecha_documento` DESC');

        if (!empty($years)) {
            $args_exp['years'] = $years;
            $args_liq['years'] = $years;
        } else {
            $args_exp['start_date'] = $start;
            $args_exp['end_date']   = $end;
            $args_liq['start_date'] = $start;
            $args_liq['end_date']   = $end;
        }

        $tickets       = $db->get_expenses($args_exp);
        $liquidaciones = $db->get_liquidations($args_liq);

        $estados = array(
            'pending'    => 'Pendiente',
            'approved'   => 'Aprobado',
            'rejected'   => 'Rechazado',
            'declared'   => 'Liquidado',
            'liquidated' => 'Liquidado',
        );
        $categorias = array(
            'dieta'            => 'Dietas y Viajes',
            'transporte_peaje' => 'Taxi / Parking / Uber / Tren / Peaje',
            'material'         => 'Material / Suministros',
            'otros'            => 'Otros Gastos',
        );
        $formas_pago = array(
            'personal' => 'Cuenta personal',
            'empresa'  => 'Tarjeta de empresa',
            'efectivo' => 'Efectivo / Caja chica',
        );

        $cache = array();
        $filas = array();

        foreach ($tickets as $t) {
            $filas[] = array(
                $t['ticket_number'],
                'Ticket',
                isset($categorias[$t['category']]) ? $categorias[$t['category']] : $t['category'],
                $t['expense_date'],
                self::get_display_name($t['user_id'], $cache),
                $t['concept'],
                '',
                isset($formas_pago[$t['payment_method']]) ? $formas_pago[$t['payment_method']] : $t['payment_method'],
                floatval($t['amount']),
                isset($estados[$t['status']]) ? $estados[$t['status']] : $t['status'],
                !empty($t['approved_by']) ? self::get_display_name($t['approved_by'], $cache) : '',
                !empty($t['liquidated_by']) ? self::get_display_name($t['liquidated_by'], $cache) : '',
                !empty($t['liquidated_at']) ? $t['liquidated_at'] : '',
                isset($t['liquidation_method']) ? $t['liquidation_method'] : '',
                isset($t['liquidation_notes']) ? $t['liquidation_notes'] : '',
                isset($t['rejection_reason']) ? $t['rejection_reason'] : '',
                count(EP_Expenses_DB::decode_attachment_field($t['attachment_url'])),
                '', '', '', '', '', '', '', '', '', '',
                isset($t['notes']) ? $t['notes'] : '',
            );
        }

        $numbering = get_option('ep_expenses_numbering_config', array());

        foreach ($liquidaciones as $l) {
            $sede = isset($numbering[$l['sede_id']]['name']) ? $numbering[$l['sede_id']]['name'] : 'Sede #' . $l['sede_id'];
            $adjuntos = 0;
            if (!empty($l['attachments'])) {
                $data = json_decode($l['attachments'], true);
                $adjuntos = !empty($data['urls']) ? count($data['urls']) : 0;
            }

            $filas[] = array(
                $l['liquidation_number'],
                'Liquidación de viaje',
                'Dietas y Viajes',
                $l['fecha_documento'],
                self::get_display_name($l['user_id'], $cache),
                'Viaje a ' . $l['destino'] . ' — ' . $l['motivo'],
                !empty($l['imputa']) ? $l['imputa'] : 'Ninguno',
                isset($formas_pago[$l['payment_method']]) ? $formas_pago[$l['payment_method']] : $l['payment_method'],
                floatval($l['total_percibir']),
                isset($estados[$l['status']]) ? $estados[$l['status']] : $l['status'],
                !empty($l['approved_by']) ? self::get_display_name($l['approved_by'], $cache) : '',
                !empty($l['liquidated_by']) ? self::get_display_name($l['liquidated_by'], $cache) : '',
                !empty($l['liquidated_at']) ? $l['liquidated_at'] : '',
                isset($l['liquidation_method']) ? $l['liquidation_method'] : '',
                isset($l['liquidation_notes']) ? $l['liquidation_notes'] : '',
                isset($l['rejection_reason']) ? $l['rejection_reason'] : '',
                $adjuntos,
                $sede,
                floatval($l['kilometros']),
                floatval($l['precio_km']),
                floatval($l['exento_km']),
                floatval($l['sujeto_km']),
                floatval($l['total_bruto']),
                floatval($l['base_retencion']),
                floatval($l['irpf_porcentaje']),
                floatval($l['irpf_importe']),
                floatval($l['ss_importe']),
                isset($l['notes']) ? $l['notes'] : '',
            );
        }

        // Más recientes primero
        usort($filas, function ($a, $b) {
            return strcmp((string) $b[3], (string) $a[3]);
        });

        $T = EP_Expenses_XLSX::TIPO_TEXTO;
        $E = EP_Expenses_XLSX::TIPO_EURO;
        $N = EP_Expenses_XLSX::TIPO_NUMERO;
        $F = EP_Expenses_XLSX::TIPO_FECHA;

        $columnas = array(
            array('titulo' => 'Nº Justificante',      'tipo' => $T, 'ancho' => 16),
            array('titulo' => 'Tipo',                 'tipo' => $T, 'ancho' => 18),
            array('titulo' => 'Categoría',            'tipo' => $T, 'ancho' => 26),
            array('titulo' => 'Fecha',                'tipo' => $F, 'ancho' => 12),
            array('titulo' => 'Empleado',             'tipo' => $T, 'ancho' => 26),
            array('titulo' => 'Concepto / Destino',   'tipo' => $T, 'ancho' => 45),
            array('titulo' => 'Programa al que imputa', 'tipo' => $T, 'ancho' => 28),
            array('titulo' => 'Forma de pago',        'tipo' => $T, 'ancho' => 20),
            array('titulo' => 'Importe',              'tipo' => $E, 'ancho' => 14),
            array('titulo' => 'Estado',               'tipo' => $T, 'ancho' => 14),
            array('titulo' => 'Autorizado por',       'tipo' => $T, 'ancho' => 24),
            array('titulo' => 'Abonado por',          'tipo' => $T, 'ancho' => 24),
            array('titulo' => 'Fecha de abono',       'tipo' => $F, 'ancho' => 14),
            array('titulo' => 'Medio de abono',       'tipo' => $T, 'ancho' => 22),
            array('titulo' => 'Notas del abono',      'tipo' => $T, 'ancho' => 32),
            array('titulo' => 'Motivo de rechazo',    'tipo' => $T, 'ancho' => 32),
            array('titulo' => 'Comprobantes',         'tipo' => $N, 'ancho' => 13),
            array('titulo' => 'Sede',                 'tipo' => $T, 'ancho' => 18),
            array('titulo' => 'Kilómetros',           'tipo' => $N, 'ancho' => 12),
            array('titulo' => 'Precio/Km',            'tipo' => $E, 'ancho' => 12),
            array('titulo' => 'Km exento',            'tipo' => $E, 'ancho' => 13),
            array('titulo' => 'Km sujeto',            'tipo' => $E, 'ancho' => 13),
            array('titulo' => 'Total bruto',          'tipo' => $E, 'ancho' => 14),
            array('titulo' => 'Base retención',       'tipo' => $E, 'ancho' => 15),
            array('titulo' => 'IRPF %',               'tipo' => $N, 'ancho' => 10),
            array('titulo' => 'IRPF importe',         'tipo' => $E, 'ancho' => 14),
            array('titulo' => 'S. Social importe',    'tipo' => $E, 'ancho' => 16),
            array('titulo' => 'Observaciones',        'tipo' => $T, 'ancho' => 32),
        );

        $fichero = EP_Expenses_XLSX::generar($columnas, $filas, 'Gastos y Dietas');

        if (is_wp_error($fichero)) {
            wp_die($fichero->get_error_message());
        }

        if (function_exists('ep_stats_log')) {
            ep_stats_log('expenses', 'expenses_exported', $ctx['user_id'], array(
                'periodo' => $periodo,
                'filas'   => count($filas),
            ));
        }

        $nombre = 'gastos-dietas-' . $etiqueta . '-' . date('Ymd') . '.xlsx';

        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($fichero));
        readfile($fichero);

        @unlink($fichero);
        exit;
    }

    /**
     * AJAX/HTTP GET: Sirve el PDF del justificante de un ticket individual.
     * Prioriza el documento firmado por Administración, luego el firmado por el
     * empleado, y si aún no hay firma lo genera al vuelo.
     */
    public function ajax_download_expense_pdf()
    {
        $ctx = self::get_user_context();
        $user_id = $ctx['user_id'];
        if (!$user_id) {
            wp_die('Acceso denegado. No autorizado.');
        }
        if (!$ctx['has_access']) {
            wp_die('Acceso denegado a la aplicación de gastos.');
        }

        $id = !empty($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) {
            wp_die('Justificante no válido.');
        }

        $db = EP_Expenses_DB::get_instance();
        $exp = $db->get_expense($id);
        if (!$exp) {
            wp_die('Justificante no encontrado.');
        }

        $is_owner = (intval($exp['user_id']) === $user_id);
        if (!$is_owner && !$ctx['can_view_all']) {
            wp_die('No tienes permisos para consultar este justificante.');
        }

        $this->sync_expense_status($exp);

        // Documento firmado: se sirve por el proxy de la app de firmas.
        if (self::fds_table_exists() && class_exists('EP_App_Signature_V4')) {
            global $wpdb;
            $fds_table = $wpdb->prefix . 'fds_documentos';

            foreach (array($exp['admin_signature_request_id'], $exp['signature_request_id']) as $sig_id) {
                if (empty($sig_id)) {
                    continue;
                }
                $estado = $wpdb->get_var($wpdb->prepare(
                    "SELECT estado FROM $fds_table WHERE id = %d",
                    intval($sig_id)
                ));
                if ($estado === 'firmado') {
                    $nonce = wp_create_nonce('ep_signature_nonce');
                    wp_redirect(
                        admin_url('admin-ajax.php')
                        . '?action=ep_app_signature&sub_action=serve_doc&id=' . intval($sig_id)
                        . '&nonce=' . $nonce . '&inline=1&t=' . time()
                    );
                    exit;
                }
            }
        }

        // Todavía sin firmar: se genera en tiempo real.
        $pdf_path = $this->generate_expense_pdf_file($exp, false);
        if (is_wp_error($pdf_path) || !$pdf_path || !file_exists($pdf_path)) {
            wp_die('Error al generar el PDF del justificante.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Justificante_' . self::slug_documento($exp['ticket_number']) . '.pdf"');
        header('Content-Length: ' . filesize($pdf_path));
        readfile($pdf_path);

        @unlink($pdf_path);
        exit;
    }

    /**
     * Genera la solicitud de firma electrónica en ep-signature
     */
    private function create_liquidation_signature_request($liq_id)
    {
        if (!class_exists('EP_App_Signature_V4')) {
            return false;
        }

        $db = EP_Expenses_DB::get_instance();
        $liq = $db->get_liquidation($liq_id);
        if (!$liq) {
            return false;
        }

        if (!empty($liq['signature_request_id'])) {
            return $liq['signature_request_id'];
        }

        $temp_pdf_path = $this->generate_pdf_file($liq);
        if (is_wp_error($temp_pdf_path) || !$temp_pdf_path || !file_exists($temp_pdf_path)) {
            return false;
        }

        $title = 'Liquidación de Dietas ' . $liq['liquidation_number'];
        $request_id = EP_App_Signature_V4::add_signature_request(
            $liq['user_id'],
            $temp_pdf_path,
            $title,
            $liq['user_id'],
            0
        );

        if ($request_id && !is_wp_error($request_id)) {
            global $wpdb;
            $table = $db->get_liquidations_table();
            $wpdb->update($table, array('signature_request_id' => $request_id), array('id' => $liq_id));

            @unlink($temp_pdf_path);
            return $request_id;
        }

        return false;
    }

    /**
     * Genera el archivo PDF físico de la liquidación usando la librería TCPDF
     */
    /**
     * Solicitud de firma del empleado sobre su ticket (sin CSV ni QR: es una
     * presentación, igual que en las liquidaciones de viaje).
     */
    private function create_expense_signature_request($expense_id)
    {
        if (!class_exists('EP_App_Signature_V4')) {
            return false;
        }

        global $wpdb;
        $db  = EP_Expenses_DB::get_instance();
        $exp = $db->get_expense($expense_id);
        if (!$exp) {
            return false;
        }

        if (!empty($exp['signature_request_id'])) {
            return $exp['signature_request_id'];
        }

        $pdf_path = $this->generate_expense_pdf_file($exp, false);
        if (is_wp_error($pdf_path) || empty($pdf_path) || !file_exists($pdf_path)) {
            return false;
        }

        $request_id = EP_App_Signature_V4::add_signature_request(
            $exp['user_id'],
            $pdf_path,
            'Justificante de Gasto ' . $exp['ticket_number'],
            $exp['user_id'],
            0
        );

        @unlink($pdf_path);

        if ($request_id && !is_wp_error($request_id)) {
            $wpdb->update(
                EP_Expenses_DB::get_expenses_table(),
                array('signature_request_id' => $request_id),
                array('id' => intval($expense_id))
            );
            return $request_id;
        }

        return false;
    }

    /**
     * Solicitud de firma de Administración al abonar un ticket. El documento se
     * genera de nuevo con el pie de sede electrónica ya impreso.
     */
    private function create_admin_expense_signature_request($expense_id)
    {
        if (!class_exists('EP_App_Signature_V4')) {
            return false;
        }

        global $wpdb;
        $db  = EP_Expenses_DB::get_instance();
        $exp = $db->get_expense($expense_id);
        if (!$exp) {
            return false;
        }

        if (!empty($exp['admin_signature_request_id'])) {
            return $exp['admin_signature_request_id'];
        }

        $pdf_path = $this->generate_expense_pdf_file($exp, true);
        if (is_wp_error($pdf_path) || empty($pdf_path) || !file_exists($pdf_path)) {
            return false;
        }

        $current_user_id = get_current_user_id();
        $request_id = EP_App_Signature_V4::add_signature_request(
            $current_user_id,
            $pdf_path,
            'Abono de Gasto ' . $exp['ticket_number'],
            $current_user_id,
            0
        );

        @unlink($pdf_path);

        if ($request_id && !is_wp_error($request_id)) {
            $wpdb->update(
                EP_Expenses_DB::get_expenses_table(),
                array('admin_signature_request_id' => $request_id),
                array('id' => intval($expense_id))
            );
            return $request_id;
        }

        return false;
    }

    /**
     * Marca el ticket como liquidado en cuanto Administración firma su abono.
     */
    private function sync_expense_status(&$exp)
    {
        if (empty($exp['admin_signature_request_id']) || $exp['status'] === 'declared') {
            return;
        }

        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        if (!self::fds_table_exists()) {
            return;
        }

        $sig_doc = $wpdb->get_row($wpdb->prepare(
            "SELECT estado, usuario_id, fecha_firma FROM $fds_table WHERE id = %d",
            intval($exp['admin_signature_request_id'])
        ));

        if (!$sig_doc || $sig_doc->estado !== 'firmado') {
            return;
        }

        $db = EP_Expenses_DB::get_instance();
        $fresh = $db->get_expense($exp['id']);
        if ($fresh) {
            $exp = array_merge($exp, $fresh);
        }

        $db->update_status($exp['id'], 'declared', array(
            'liquidated_by'      => !empty($sig_doc->usuario_id) ? intval($sig_doc->usuario_id) : get_current_user_id(),
            'liquidation_method' => !empty($exp['liquidation_method']) ? $exp['liquidation_method'] : 'Transferencia Bancaria',
            'liquidation_notes'  => !empty($exp['liquidation_notes']) ? $exp['liquidation_notes'] : '',
        ), array());

        $exp['status'] = 'declared';

        $amount_fmt = number_format(floatval($exp['amount']), 2) . ' €';
        $this->notify_user(
            intval($exp['user_id']),
            'Tu Gasto ha sido Liquidado / Abonado',
            "Tu ticket {$exp['ticket_number']} ({$exp['concept']}, {$amount_fmt}) ha sido LIQUIDADO por Administración.",
            'success'
        );
    }

    /**
     * CSV de un ticket individual, derivado de su id para poder imprimirlo antes
     * de que exista la solicitud de firma.
     */
    public static function expense_csv($expense_id)
    {
        return strtoupper(substr(hash_hmac('sha256', 'ep_exp_csv|' . intval($expense_id), wp_salt('auth')), 0, 32));
    }

    /**
     * Genera el PDF justificante de un ticket individual.
     *
     * @param array $exp         Gasto.
     * @param bool  $with_footer Pie de sede electrónica (CSV y QR). Solo en el
     *                           documento que firma Administración al abonar.
     */
    private function generate_expense_pdf_file($exp, $with_footer = false)
    {
        if (!class_exists('TCPDF')) {
            $tcpdf_path = EMPLOYEE_PORTAL_PATH . 'plugins/ep-signature/libs/tcpdf/tcpdf.php';
            if (file_exists($tcpdf_path)) {
                require_once $tcpdf_path;
            } else {
                return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
            }
        }

        $user_info = get_userdata($exp['user_id']);
        $empleado  = $user_info ? $user_info->display_name : 'Empleado #' . $exp['user_id'];

        $employee_signature = $with_footer ? $this->get_expense_signature_info($exp) : null;

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAuthor('Portal del Empleado');
        $pdf->SetTitle('Justificante de Gasto ' . $exp['ticket_number']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        if ($with_footer) {
            $pdf->SetAutoPageBreak(true, 32);
        }
        $pdf->AddPage();

        $categorias = array(
            'dieta'             => 'Dietas y Viajes',
            'transporte_peaje'  => 'Taxi / Parking / Uber / Tren / Peaje',
            'material'          => 'Material / Suministros',
            'otros'             => 'Otros Gastos',
        );
        $categoria = isset($categorias[$exp['category']]) ? $categorias[$exp['category']] : $exp['category'];

        $formas_pago = array(
            'personal' => 'Cuenta personal (a reponer)',
            'empresa'  => 'Tarjeta de empresa',
            'efectivo' => 'Efectivo / Caja chica',
        );
        $forma_pago = isset($formas_pago[$exp['payment_method']]) ? $formas_pago[$exp['payment_method']] : $exp['payment_method'];

        $adjuntos = EP_Expenses_DB::decode_attachment_field($exp['attachment_url']);

        $pal = self::get_pdf_palette();

        $html = self::get_pdf_stylesheet($pal)
            . self::get_pdf_header_html(
                $pal,
                'Justificante de gasto',
                'Ticket individual de gasto',
                'Nº de justificante',
                $exp['ticket_number']
            ) . '

        <table class="meta-table">
            <tr>
                <td class="meta-label">EMPLEADO</td>
                <td class="meta-val">' . esc_html($empleado) . '</td>
                <td class="meta-label">FECHA DEL GASTO</td>
                <td class="meta-val">' . date('d/m/Y', strtotime($exp['expense_date'])) . '</td>
            </tr>
            <tr>
                <td class="meta-label">TIPO DE GASTO</td>
                <td class="meta-val">' . esc_html($categoria) . '</td>
                <td class="meta-label">FORMA DE PAGO</td>
                <td class="meta-val">' . esc_html($forma_pago) . '</td>
            </tr>
        </table>

        <div style="height: 6px;"></div>
        <div class="section-title">CONCEPTO</div>
        <div class="nota">' . esc_html($exp['concept']) . '</div>';

        if (!empty($exp['notes'])) {
            $html .= '<div style="height: 6px;"></div><div class="section-title">OBSERVACIONES</div><div class="nota">' . esc_html($exp['notes']) . '</div>';
        }

        if (!empty($exp['google_maps_url'])) {
            $html .= '<div style="height: 6px;"></div><div class="section-title">JUSTIFICANTE DEL TRAYECTO</div><div class="nota">' . esc_html($exp['google_maps_url']) . '</div>';
        }

        $html .= '<div style="height: 6px;"></div><div class="section-title">COMPROBANTES APORTADOS</div>';
        if (empty($adjuntos)) {
            $html .= '<div class="empty-note">No se aportan comprobantes adjuntos.</div>';
        } else {
            $html .= '<div class="nota">Se aportan ' . count($adjuntos) . ' comprobante(s):</div><ul>';
            foreach ($adjuntos as $url) {
                $html .= '<li style="font-size: 10px; color: #3f4753;">' . esc_html(basename(parse_url($url, PHP_URL_PATH))) . '</li>';
            }
            $html .= '</ul>';
        }

        $liquidado = ($exp['status'] === 'liquidated' || $exp['status'] === 'declared');

        $html .= '
        <div style="height: 10px;"></div>
        <table class="totals-table">
            <tr>
                <td class="totals-highlight-label">IMPORTE TOTAL</td>
                <td class="totals-highlight-val">' . number_format(floatval($exp['amount']), 2, ',', '.') . ' €</td>
            </tr>
        </table>

        <table class="sign-table">
            <tr><td style="height: 14px;" colspan="3"></td></tr>
            <tr nobr="true">
                <td class="sign-box">
                    <span class="sign-role">CONFORME</span><br>
                    <span class="sign-note">Presentado electrónicamente por el empleado</span><br><br>
                    Fecha: ' . date('d/m/Y', strtotime($exp['expense_date'])) . '
                    ' . ($employee_signature ? '<br><span class="sign-note">Firmado electrónicamente el ' . esc_html($employee_signature['fecha']) . '</span>'
                        . ($employee_signature['csv'] ? '<br><span class="sign-fine">CSV: ' . esc_html($employee_signature['csv']) . '</span>' : '') : '') . '
                </td>
                <td class="sign-gap"></td>
                <td class="sign-box">
                    <span class="sign-role">RECIBÍ / LIQUIDADO POR</span><br>
                    <span class="sign-note">Administración / Contabilidad</span><br><br>
                    <span class="sign-state">' . ($liquidado ? 'LIQUIDADO' : 'PENDIENTE') . '</span>
                    ' . (!empty($exp['liquidation_method']) ? '<br><span class="sign-note">Forma de pago: ' . esc_html($exp['liquidation_method']) . '</span>' : '') . '
                    ' . (!empty($exp['liquidation_notes']) ? '<br><span class="sign-note">' . esc_html($exp['liquidation_notes']) . '</span>' : '') . '
                </td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if ($with_footer) {
            $this->stamp_verification_footer($pdf, array(
                'id'                 => $exp['id'],
                'liquidation_number' => $exp['ticket_number'],
                '_csv'               => self::expense_csv($exp['id']),
                '_doc_label'         => 'Justificante ' . $exp['ticket_number'],
            ));
        }

        list($target_dir, ) = self::ensure_upload_dir();
        $pdf_path = $target_dir . 'just_' . self::slug_documento($exp['ticket_number']) . '_' . time() . '.pdf';
        $pdf->Output($pdf_path, 'F');

        return $pdf_path;
    }

    /**
     * Datos de la firma electrónica del empleado sobre su ticket.
     */
    private function get_expense_signature_info($exp)
    {
        if (empty($exp['signature_request_id']) || !self::fds_table_exists()) {
            return null;
        }

        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT estado, fecha_firma, csv_documento FROM $fds_table WHERE id = %d",
            intval($exp['signature_request_id'])
        ));

        if (!$doc || $doc->estado !== 'firmado') {
            return null;
        }

        return array(
            'fecha' => !empty($doc->fecha_firma) ? date('d/m/Y H:i', strtotime($doc->fecha_firma)) : '',
            'csv'   => (string) $doc->csv_documento,
        );
    }

    /**
     * Estampa en todas las páginas el pie de sede electrónica: firmante previsto,
     * fecha, CSV y QR de verificación.
     */
    private function stamp_verification_footer($pdf, $liq)
    {
        // Los tickets pasan su CSV ya calculado; las liquidaciones lo derivan del id.
        $csv = !empty($liq['_csv']) ? $liq['_csv'] : self::liquidation_csv($liq['id']);
        $verify_url = home_url('?view=signature&sub_action=verify&csv=' . $csv);

        // El pie se dibuja por debajo del margen inferior. Con el salto de página
        // automático activo, cada Text() generaba una página nueva (así salieron
        // el CSV y el QR en páginas sueltas). Se desactiva mientras se estampa.
        $auto_break   = $pdf->getAutoPageBreak();
        $break_margin = $pdf->getBreakMargin();
        $pdf->SetAutoPageBreak(false, 0);

        // Solo las páginas existentes antes de estampar.
        $total_paginas = $pdf->getNumPages();

        // Librería de QR incluida con la app de firmas
        if (!class_exists('QRcode', false)) {
            $qrlib = EMPLOYEE_PORTAL_PATH . 'plugins/ep-signature/libs/phpqrcode/qrlib.php';
            if (file_exists($qrlib)) {
                require_once $qrlib;
            }
        }

        $qr_img = null;
        if (class_exists('QRcode')) {
            ob_start();
            \QRcode::png($verify_url, null, QR_ECLEVEL_L, 3, 1);
            $qr_img = ob_get_clean();
        }

        $fecha_hora = date_i18n('d/m/Y H:i:s');

        for ($p = 1; $p <= $total_paginas; $p++) {
            $pdf->setPage($p);

            $ancho = $pdf->getPageWidth();
            $alto  = $pdf->getPageHeight();
            $margen = 10;
            $base_y = $alto - 20;

            $pdf->SetDrawColor(200, 200, 200);
            $pdf->SetLineWidth(0.1);
            $pdf->Line($margen, $base_y - 2, $ancho - $margen, $base_y - 2);

            $pdf->SetTextColor(80, 80, 80);

            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->Text($margen, $base_y, 'DOCUMENTO:');
            $pdf->SetFont('helvetica', '', 6);
            // El pie solo existe en el documento de abono: se etiqueta como tal
            // para que no pueda confundirse con el que presenta el empleado.
            $etiqueta = !empty($liq['_doc_label']) ? $liq['_doc_label'] : 'Liquidación ' . $liq['liquidation_number'];
            $pdf->Text($margen + 20, $base_y, 'Abono de ' . $etiqueta);

            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->Text($margen, $base_y + 3, 'EMITIDO:');
            $pdf->SetFont('helvetica', '', 6);
            $pdf->Text($margen + 20, $base_y + 3, $fecha_hora);

            $col2_x = $margen + (($ancho - ($margen * 2) - 25) / 2);
            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->Text($col2_x, $base_y, 'CSV:');
            $pdf->SetFont('helvetica', '', 5);
            $pdf->Text($col2_x + 8, $base_y, $csv);

            $pdf->SetFont('helvetica', '', 5);
            $pdf->Text($col2_x, $base_y + 3, 'Verificable en ' . home_url('?view=signature'));

            if ($qr_img) {
                $pdf->Image('@' . $qr_img, $ancho - $margen - 12, $base_y - 1, 12, 12, 'PNG');
            }

            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->StartTransform();
            $pdf->Rotate(90, $ancho - 5, $alto / 2);
            $pdf->Text($ancho - 5, $alto / 2, 'COPIA ELECTRÓNICA AUTÉNTICA');
            $pdf->StopTransform();
        }

        $pdf->setPage($total_paginas);
        $pdf->SetAutoPageBreak($auto_break, $break_margin);
    }

    /**
     * Datos de la firma electrónica del empleado sobre su liquidación, para
     * dejar constancia de ella en el documento que firma Administración.
     */
    private function get_employee_signature_info($liq)
    {
        if (empty($liq['signature_request_id']) || !self::fds_table_exists()) {
            return null;
        }

        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT estado, fecha_firma, csv_documento, nombre_firmante FROM $fds_table WHERE id = %d",
            intval($liq['signature_request_id'])
        ));

        if (!$doc || $doc->estado !== 'firmado') {
            return null;
        }

        return array(
            'fecha'    => !empty($doc->fecha_firma) ? date('d/m/Y H:i', strtotime($doc->fecha_firma)) : '',
            'csv'      => (string) $doc->csv_documento,
            'firmante' => (string) $doc->nombre_firmante,
        );
    }

    /**
     * Cierre del trámite cuando se firma un documento de esta app.
     *
     * Al firmar, el empleado se quedaba en la app de firma con un "Se han
     * procesado todos los documentos de la cola" y un botón de "Firmar otro
     * documento": nada le decía que su gasto ya estaba presentado, que le tocaba
     * a Dirección ni cómo volver. Aquí se declara ese cierre; lo pinta la app de
     * firma a través del filtro ep_signature_after_sign.
     */
    public function signature_next_step($step, $request_id)
    {
        global $wpdb;

        $request_id = intval($request_id);
        if (!$request_id) {
            return $step;
        }

        $volver = array(
            'label' => 'Volver a Gastos y Dietas',
            'url'   => home_url('/?view=expenses'),
        );

        $liq_table = EP_Expenses_DB::get_liquidations_table();
        $liq = $wpdb->get_row($wpdb->prepare(
            "SELECT liquidation_number, signature_request_id FROM $liq_table
             WHERE signature_request_id = %d OR admin_signature_request_id = %d LIMIT 1",
            $request_id,
            $request_id
        ), ARRAY_A);

        if ($liq) {
            if (intval($liq['signature_request_id']) === $request_id) {
                return array(
                    'title'   => 'Liquidación presentada',
                    'message' => 'Tu liquidación ' . $liq['liquidation_number'] . ' ha quedado firmada y registrada. '
                        . 'Dirección ya tiene el aviso para autorizarla: por tu parte el trámite está terminado.',
                    'button'  => $volver,
                );
            }

            return array(
                'title'   => 'Abono firmado',
                'message' => 'La liquidación ' . $liq['liquidation_number'] . ' queda marcada como abonada '
                    . 'y el empleado ha recibido el aviso.',
                'button'  => $volver,
            );
        }

        $exp_table = EP_Expenses_DB::get_expenses_table();
        $exp = $wpdb->get_row($wpdb->prepare(
            "SELECT ticket_number, signature_request_id FROM $exp_table
             WHERE signature_request_id = %d OR admin_signature_request_id = %d LIMIT 1",
            $request_id,
            $request_id
        ), ARRAY_A);

        if ($exp) {
            if (intval($exp['signature_request_id']) === $request_id) {
                return array(
                    'title'   => 'Gasto presentado',
                    'message' => 'Tu justificante ' . $exp['ticket_number'] . ' ha quedado firmado y registrado. '
                        . 'Dirección ya tiene el aviso para autorizarlo: por tu parte el trámite está terminado.',
                    'button'  => $volver,
                );
            }

            return array(
                'title'   => 'Abono firmado',
                'message' => 'El justificante ' . $exp['ticket_number'] . ' queda marcado como abonado '
                    . 'y el empleado ha recibido el aviso.',
                'button'  => $volver,
            );
        }

        return $step;
    }

    /**
     * Convierte un número de documento en un fragmento seguro de nombre de fichero.
     *
     * La serie lleva barra (CAC-2026/001) y en una ruta la barra es un separador
     * de directorio: el PDF se intentaba escribir en una carpeta "liq_CAC-2026"
     * que no existe, fopen fallaba y con él se caía la solicitud de firma entera,
     * que es lo que veía el empleado al pulsar "Guardar y Firmar".
     *
     * El número que se guarda y se imprime no se toca: esto es solo para el
     * nombre del fichero.
     */
    private static function slug_documento($numero)
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $numero);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'documento';
    }

    /**
     * Paleta de los justificantes en PDF, tomada del color corporativo del
     * portal (Ajustes > Personalización).
     *
     * Los documentos salían en azul fijo mientras el resto del portal va en el
     * rojo de la Cámara: quien recibía el PDF no lo reconocía como del mismo
     * sitio. Al leer la misma opción que la interfaz, cambiar el color del
     * portal recolorea también los documentos, sin tocar código.
     */
    private static function get_pdf_palette()
    {
        $custom  = get_option('ep_portal_customization', array());
        $primary = !empty($custom['primary_color']) ? sanitize_hex_color($custom['primary_color']) : '';
        if (empty($primary)) {
            $primary = '#a81c24';
        }

        $portal = !empty($custom['portal_name']) ? $custom['portal_name'] : 'Portal del Empleado';

        return array(
            'primary' => $primary,
            // Titulares: el corporativo puro sobre blanco pesa demasiado en textos grandes.
            'dark'    => self::mix_hex($primary, '#000000', 0.32),
            // Fondos de banda: el mismo tono lavado, para teñir sin competir con el texto.
            'tint'    => self::mix_hex($primary, '#ffffff', 0.93),
            'portal'  => trim(preg_replace('/\s+/', ' ', $portal)),
        );
    }

    /**
     * Mezcla dos colores hexadecimales. $weight es cuánto pesa el segundo (0..1).
     */
    private static function mix_hex($hex_a, $hex_b, $weight)
    {
        $to_rgb = function ($hex) {
            $hex = ltrim((string) $hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
                return array(0, 0, 0);
            }
            return array(
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2))
            );
        };

        $a = $to_rgb($hex_a);
        $b = $to_rgb($hex_b);
        $weight = max(0, min(1, (float) $weight));

        return sprintf(
            '#%02x%02x%02x',
            (int) round($a[0] + ($b[0] - $a[0]) * $weight),
            (int) round($a[1] + ($b[1] - $a[1]) * $weight),
            (int) round($a[2] + ($b[2] - $a[2]) * $weight)
        );
    }

    /**
     * Hoja de estilos común al justificante de ticket y a la liquidación, para
     * que los dos salgan del mismo molde.
     *
     * TCPDF entiende muy poco CSS: ni flex, ni radios, ni sombras, ni márgenes
     * fiables entre bloques. Todo lo que aquí parece una caja es una tabla, la
     * separación se da con padding (no con margin) y los huecos verticales con
     * filas espaciadoras. Las abreviaturas de padding sí las admite.
     */
    private static function get_pdf_stylesheet($p)
    {
        return '
        <style>
            .doc-kicker { width: 100%; }
            .doc-kicker td { font-size: 8px; color: #9aa1ab; padding-bottom: 6px; }
            .doc-kicker-right { text-align: right; }
            .doc-kicker-num { font-size: 11px; font-weight: bold; color: ' . $p['primary'] . '; }

            .title { text-align: center; font-size: 18px; font-weight: bold; color: ' . $p['dark'] . '; padding-top: 12px; }
            .title-sub { text-align: center; font-size: 8px; color: #9aa1ab; padding-top: 6px; padding-bottom: 12px; border-bottom: 2px solid ' . $p['primary'] . '; }

            .meta-table { width: 100%; border-collapse: collapse; }
            .meta-table td { padding: 7px 0; font-size: 10px; line-height: 1.4; border-bottom: 1px solid #ebedf0; }
            .meta-label { font-weight: bold; color: #7b828c; font-size: 8px; width: 26%; }
            .meta-val { color: #1f2933; width: 24%; }
            .meta-block { width: 100%; }
            .meta-block-label { font-weight: bold; color: #7b828c; font-size: 8px; }
            .meta-block-val { color: #1f2933; font-size: 10px; }

            .section-title { font-size: 10px; font-weight: bold; color: ' . $p['dark'] . '; background-color: ' . $p['tint'] . '; padding: 6px 10px; border-left: 3px solid ' . $p['primary'] . '; }

            .data-table { width: 100%; border-collapse: collapse; }
            .data-table th { background-color: #f6f7f9; font-weight: bold; color: #5b6270; font-size: 8px; padding: 6px 7px; border-bottom: 1px solid #d7dbe0; }
            .data-table td { padding: 6px 7px; font-size: 10px; border-bottom: 1px solid #ebedf0; }

            .text-right { text-align: right; }
            .text-center { text-align: center; }

            .empty-note { font-size: 9px; color: #9aa1ab; font-style: italic; padding: 4px 0; }
            .nota { font-size: 10px; color: #3f4753; line-height: 1.5; padding: 6px 0; }

            .totals-table { width: 100%; border-collapse: collapse; }
            .totals-table td { padding: 6px 0; font-size: 10px; }
            .totals-label { color: #5b6270; text-align: right; width: 72%; }
            .totals-val { font-weight: bold; color: #1f2933; text-align: right; width: 28%; border-bottom: 1px solid #ebedf0; }
            .totals-neg { font-weight: bold; color: ' . $p['primary'] . '; text-align: right; width: 28%; border-bottom: 1px solid #ebedf0; }
            .totals-highlight-label { font-size: 12px; font-weight: bold; color: ' . $p['dark'] . '; background-color: ' . $p['tint'] . '; text-align: right; width: 72%; padding: 11px 8px; border-top: 2px solid ' . $p['primary'] . '; border-bottom: 2px solid ' . $p['primary'] . '; }
            .totals-highlight-val { font-size: 12px; font-weight: bold; color: ' . $p['dark'] . '; background-color: ' . $p['tint'] . '; text-align: right; width: 28%; padding: 11px 8px; border-top: 2px solid ' . $p['primary'] . '; border-bottom: 2px solid ' . $p['primary'] . '; }

            .sign-table { width: 100%; }
            .sign-box { width: 47%; border: 1px solid #dfe3e8; padding: 9px; font-size: 9px; line-height: 1.4; }
            .sign-gap { width: 6%; }
            .sign-role { font-size: 8px; font-weight: bold; color: ' . $p['primary'] . '; }
            .sign-note { font-size: 8px; color: #9aa1ab; }
            .sign-state { font-size: 10px; font-weight: bold; color: #1f2933; }
            .sign-fine { font-size: 7px; color: #b0b6bd; }
        </style>';
    }

    /**
     * Cabecera común: marca del portal y número de documento arriba, y el título
     * del justificante bajo un filete del color corporativo.
     *
     * El número vive aquí y no en la tabla de datos, que es donde estaba: en una
     * columna del 15% se partía por la mitad ("Nº DOCUME / NTO") y encima
     * robaba la fila a un dato del viaje.
     */
    private static function get_pdf_header_html($p, $titulo, $subtitulo, $num_label, $numero)
    {
        return '
        <table class="doc-kicker">
            <tr>
                <td>' . esc_html($p['portal']) . '</td>
                <td class="doc-kicker-right">' . esc_html($num_label) . ' <span class="doc-kicker-num">' . esc_html($numero) . '</span></td>
            </tr>
        </table>
        <div class="title">' . esc_html(mb_strtoupper($titulo, 'UTF-8')) . '</div>
        <div class="title-sub">' . esc_html(mb_strtoupper($subtitulo, 'UTF-8')) . '</div>
        <div style="height: 14px;"></div>';
    }

    /**
     * Genera el PDF de la liquidación.
     *
     * @param array $liq         Liquidación.
     * @param bool  $with_footer Añade el pie de sede electrónica con CSV y QR.
     *                           Se usa en el documento que firma Administración:
     *                           debe ir impreso ANTES de firmar, porque una vez
     *                           firmado criptográficamente el PDF ya no se puede
     *                           modificar sin invalidar la firma.
     */
    private function generate_pdf_file($liq, $with_footer = false)
    {
        $employee_signature = $with_footer ? $this->get_employee_signature_info($liq) : null;
        if (!class_exists('TCPDF')) {
            $tcpdf_path = EMPLOYEE_PORTAL_PATH . 'plugins/ep-signature/libs/tcpdf/tcpdf.php';
            if (file_exists($tcpdf_path)) {
                require_once $tcpdf_path;
            } else {
                return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
            }
        }

        $user_info = get_userdata($liq['user_id']);
        $asistente = $user_info ? $user_info->display_name : 'Empleado #' . $liq['user_id'];

        $numbering_config = get_option('ep_expenses_numbering_config', []);
        $sede_name = isset($numbering_config[$liq['sede_id']]['name']) ? $numbering_config[$liq['sede_id']]['name'] : 'Sede #' . $liq['sede_id'];

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAuthor('Portal del Empleado');
        $pdf->SetTitle('Liquidación de Dietas ' . $liq['liquidation_number']);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        // Con pie de sede electrónica se reserva la banda inferior para que el
        // contenido no llegue nunca a solaparse con él.
        if ($with_footer) {
            $pdf->SetAutoPageBreak(true, 32);
        }
        $pdf->AddPage();

        $manutencion = json_decode($liq['gastos_manutencion'], true) ?: [];
        $alojamiento = json_decode($liq['gastos_alojamiento'], true) ?: [];
        $otros = json_decode($liq['otros_gastos'], true) ?: [];

        $pal = self::get_pdf_palette();

        $fecha_ida = date('d/m/Y', strtotime($liq['fecha_desde']))
            . (!empty($liq['hora_desde']) ? ', ' . substr($liq['hora_desde'], 0, 5) . ' h' : '');
        $fecha_vuelta = date('d/m/Y', strtotime($liq['fecha_hasta']))
            . (!empty($liq['hora_hasta']) ? ', ' . substr($liq['hora_hasta'], 0, 5) . ' h' : '');

        $html = self::get_pdf_stylesheet($pal)
            . self::get_pdf_header_html(
                $pal,
                'Liquidación de dietas y gastos',
                'Justificante de desplazamiento y gastos de viaje',
                'Nº de documento',
                $liq['liquidation_number']
            ) . '

        <table class="meta-table">
            <tr>
                <td class="meta-label">ASISTENTE</td>
                <td class="meta-val">' . esc_html($asistente) . '</td>
                <td class="meta-label">SEDE</td>
                <td class="meta-val">' . esc_html($sede_name) . '</td>
            </tr>
            <tr>
                <td class="meta-label">VIAJE A (DESTINO)</td>
                <td class="meta-val">' . esc_html($liq['destino']) . '</td>
                <td class="meta-label">FECHAS DEL DESPLAZAMIENTO</td>
                <td class="meta-val">Del ' . esc_html($fecha_ida) . '<br>al ' . esc_html($fecha_vuelta) . '</td>
            </tr>
        </table>

        <table class="meta-table">
            <tr>
                <td class="meta-block"><span class="meta-block-label">MOTIVO DEL VIAJE</span><br><span class="meta-block-val">' . esc_html($liq['motivo']) . '</span></td>
            </tr>
            <tr>
                <td class="meta-block"><span class="meta-block-label">PROGRAMA AL QUE IMPUTA</span><br><span class="meta-block-val">' . esc_html(!empty($liq['imputa']) ? $liq['imputa'] : 'Ninguno') . '</span></td>
            </tr>
        </table>

        <div style="height: 6px;"></div>
        <div class="section-title">DESPLAZAMIENTOS Y KILOMETRAJE</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25%;">DISTANCIA</th>
                    <th style="width: 25%;">PRECIO / KM</th>
                    <th style="width: 25%; text-align: right;">IMPORTE EXENTO</th>
                    <th style="width: 25%; text-align: right;">IMPORTE SUJETO</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="width: 25%;">' . number_format($liq['kilometros'], 2, ',', '.') . ' Km</td>
                    <td style="width: 25%;">' . number_format($liq['precio_km'], 2, ',', '.') . ' €</td>
                    <td style="width: 25%;" class="text-right">' . number_format($liq['exento_km'], 2, ',', '.') . ' €</td>
                    <td style="width: 25%;" class="text-right">' . number_format($liq['sujeto_km'], 2, ',', '.') . ' €</td>
                </tr>
            </tbody>
        </table>
        ';

        $html .= '<div style="height: 6px;"></div><div class="section-title">GASTOS DE MANUTENCIÓN JUSTIFICADOS</div>';
        if (empty($manutencion)) {
            $html .= '<div class="empty-note">No se registran gastos de manutención.</div>';
        } else {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">FECHA</th>
                        <th style="width: 62%;">CONCEPTO / DESCRIPCIÓN</th>
                        <th style="width: 20%; text-align: right;">IMPORTE</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ($manutencion as $row) {
                $html .= '<tr>
                    <td style="width: 18%;">' . esc_html($row['fecha']) . '</td>
                    <td style="width: 62%;">' . esc_html($row['concepto']) . '</td>
                    <td style="width: 20%;" class="text-right">' . number_format(floatval($row['importe']), 2, ',', '.') . ' €</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div style="height: 6px;"></div><div class="section-title">GASTOS DE ALOJAMIENTO JUSTIFICADOS</div>';
        if (empty($alojamiento)) {
            $html .= '<div class="empty-note">No se registran gastos de alojamiento.</div>';
        } else {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">FECHA</th>
                        <th style="width: 62%;">CONCEPTO / DESCRIPCIÓN</th>
                        <th style="width: 20%; text-align: right;">IMPORTE</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ($alojamiento as $row) {
                $html .= '<tr>
                    <td style="width: 18%;">' . esc_html($row['fecha']) . '</td>
                    <td style="width: 62%;">' . esc_html($row['concepto']) . '</td>
                    <td style="width: 20%;" class="text-right">' . number_format(floatval($row['importe']), 2, ',', '.') . ' €</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div style="height: 6px;"></div><div class="section-title">OTROS GASTOS JUSTIFICADOS</div>';
        if (empty($otros)) {
            $html .= '<div class="empty-note">No se registran otros gastos.</div>';
        } else {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">FECHA</th>
                        <th style="width: 62%;">CONCEPTO / DESCRIPCIÓN</th>
                        <th style="width: 20%; text-align: right;">IMPORTE</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ($otros as $row) {
                $html .= '<tr>
                    <td style="width: 18%;">' . esc_html($row['fecha']) . '</td>
                    <td style="width: 62%;">' . esc_html($row['concepto']) . '</td>
                    <td style="width: 20%;" class="text-right">' . number_format(floatval($row['importe']), 2, ',', '.') . ' €</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }

        $liquidada = ($liq['status'] === 'liquidated' || $liq['status'] === 'declared');

        $html .= '
        <div style="height: 10px;"></div>
        <table class="totals-table">
            <tr>
                <td class="totals-label">Total bruto</td>
                <td class="totals-val">' . number_format($liq['total_bruto'], 2, ',', '.') . ' €</td>
            </tr>
            <tr>
                <td class="totals-label">Base de retención (sujeto a IRPF / SS)</td>
                <td class="totals-val">' . number_format($liq['base_retencion'], 2, ',', '.') . ' €</td>
            </tr>
            <tr>
                <td class="totals-label">Renta exenta de retenciones</td>
                <td class="totals-val">' . number_format($liq['renta_exenta'], 2, ',', '.') . ' €</td>
            </tr>
            <tr>
                <td class="totals-label">Retención I.R.P.F. (' . number_format($liq['irpf_porcentaje'], 2, ',', '.') . '% s/ base)</td>
                <td class="totals-neg">- ' . number_format($liq['irpf_importe'], 2, ',', '.') . ' €</td>
            </tr>
            <tr>
                <td class="totals-label">Seguridad Social (' . number_format($liq['ss_porcentaje'], 2, ',', '.') . '% s/ base)</td>
                <td class="totals-neg">- ' . number_format($liq['ss_importe'], 2, ',', '.') . ' €</td>
            </tr>
            <tr>
                <td class="totals-highlight-label">TOTAL NETO A PERCIBIR</td>
                <td class="totals-highlight-val">' . number_format($liq['total_percibir'], 2, ',', '.') . ' €</td>
            </tr>
        </table>

        <table class="sign-table">
            <tr><td style="height: 14px;" colspan="3"></td></tr>
            <tr nobr="true">
                <td class="sign-box">
                    <span class="sign-role">CONFORME</span><br>
                    <span class="sign-note">Presentado electrónicamente por el asistente</span><br><br>
                    Fecha: ' . date('d/m/Y', strtotime($liq['fecha_documento'])) . '
                    ' . ($employee_signature ? '<br><span class="sign-note">Firmado electrónicamente el ' . esc_html($employee_signature['fecha']) . '</span>'
                        . ($employee_signature['csv'] ? '<br><span class="sign-fine">CSV: ' . esc_html($employee_signature['csv']) . '</span>' : '') : '') . '
                </td>
                <td class="sign-gap"></td>
                <td class="sign-box">
                    <span class="sign-role">RECIBÍ / LIQUIDADO POR</span><br>
                    <span class="sign-note">Administración / Contabilidad</span><br><br>
                    <span class="sign-state">' . ($liquidada ? 'LIQUIDADO' : 'PENDIENTE') . '</span>
                    ' . (!empty($liq['liquidation_method']) ? '<br><span class="sign-note">Forma de pago: ' . esc_html($liq['liquidation_method']) . '</span>' : '') . '
                    ' . (!empty($liq['liquidation_notes']) ? '<br><span class="sign-note">' . esc_html($liq['liquidation_notes']) . '</span>' : '') . '
                </td>
            </tr>
        </table>
        ';

        $pdf->writeHTML($html, true, false, true, false, '');

        if ($with_footer) {
            $this->stamp_verification_footer($pdf, $liq);
        }

        list($target_dir, ) = self::ensure_upload_dir();

        $pdf_path = $target_dir . 'liq_' . self::slug_documento($liq['liquidation_number']) . '_' . time() . '.pdf';
        $pdf->Output($pdf_path, 'F');

        return $pdf_path;
    }

    /**
     * Sincroniza el estado de la liquidación basándose en si el administrador la ha firmado
     */
    private function sync_liquidation_status(&$liq)
    {
        if (empty($liq['admin_signature_request_id'])) {
            return;
        }
        if ($liq['status'] === 'liquidated') {
            return;
        }

        global $wpdb;
        $fds_table = $wpdb->prefix . 'fds_documentos';
        if (!self::fds_table_exists()) {
            return;
        }

        $sig_doc = $wpdb->get_row($wpdb->prepare(
            "SELECT estado, usuario_id, fecha_firma FROM $fds_table WHERE id = %d",
            $liq['admin_signature_request_id']
        ));
        $sig_status = $sig_doc ? $sig_doc->estado : '';

        if ($sig_status === 'firmado') {
            $db = EP_Expenses_DB::get_instance();

            // Re-leer la liquidación para obtener los datos de abono actualizados en la base de datos
            $fresh_liq = $db->get_liquidation($liq['id']);
            if ($fresh_liq) {
                $liq = array_merge($liq, $fresh_liq);
            }

            $liquidation_data = array(
                // Quien abona es quien firmó el documento, no quien lo aprobó.
                'liquidated_by'      => !empty($sig_doc->usuario_id) ? intval($sig_doc->usuario_id) : ($liq['approved_by'] ?: get_current_user_id()),
                'liquidated_at'      => !empty($sig_doc->fecha_firma) ? $sig_doc->fecha_firma : current_time('mysql'),
                'liquidation_method' => !empty($liq['liquidation_method']) ? $liq['liquidation_method'] : 'Transferencia Bancaria',
                'liquidation_notes'  => !empty($liq['liquidation_notes']) ? $liq['liquidation_notes'] : ''
            );
            $approval_data = array();

            $db->update_liquidation_status($liq['id'], 'liquidated', $liquidation_data, $approval_data);
            
            $liq['status'] = 'liquidated';
            $liq['liquidated_by'] = $liquidation_data['liquidated_by'];

            // Enviar notificación al usuario
            $emp_id = intval($liq['user_id']);
            $liq_num = $liq['liquidation_number'];
            $destino = $liq['destino'];
            $amount_fmt = number_format(floatval($liq['total_percibir']), 2) . ' €';
            
            $method = !empty($liq['liquidation_method']) ? $liq['liquidation_method'] : 'Transferencia Bancaria';
            $notes = !empty($liq['liquidation_notes']) ? $liq['liquidation_notes'] : '';
            
            $msg = "¡Buenas noticias! Tu liquidación {$liq_num} ({$destino}, {$amount_fmt}) ha sido marcada como LIQUIDADA / ABONADA mediante {$method}.";
            if (!empty($notes)) {
                $msg .= " Nota de administración: {$notes}";
            }

            $this->notify_user(
                $emp_id,
                'Liquidación Abonada',
                $msg,
                'success'
            );
        }
    }

    /**
     * Genera la solicitud de firma electrónica para el administrador al liquidar
     */
    private function create_admin_liquidation_signature_request($liq_id)
    {
        if (!class_exists('EP_App_Signature_V4')) {
            return false;
        }

        global $wpdb;
        $db = EP_Expenses_DB::get_instance();
        $liq = $db->get_liquidation($liq_id);
        if (!$liq) {
            return false;
        }

        if (!empty($liq['admin_signature_request_id'])) {
            return $liq['admin_signature_request_id'];
        }

        // El documento de abono se genera de nuevo, NO se reutiliza el PDF que
        // firmó el empleado: aquel ya lleva una firma criptográfica y añadirle el
        // pie de sede electrónica la invalidaría. Este documento sale con el pie
        // (CSV y QR) impreso y deja constancia de la firma previa del empleado,
        // que sigue conservándose íntegra en su propio documento.
        $temp_pdf_path = $this->generate_pdf_file($liq, true);

        if (is_wp_error($temp_pdf_path) || empty($temp_pdf_path) || !file_exists($temp_pdf_path)) {
            return false;
        }

        $title = 'Firma de Liquidación - ' . $liq['liquidation_number'];
        $current_user_id = get_current_user_id();

        $request_id = EP_App_Signature_V4::add_signature_request(
            $current_user_id, // Recipiente: el administrador que está liquidando
            $temp_pdf_path,
            $title,
            $current_user_id, // Solicitante: él mismo
            0
        );

        if ($request_id && !is_wp_error($request_id)) {
            $table = $db->get_liquidations_table();
            $wpdb->update($table, array('admin_signature_request_id' => $request_id), array('id' => $liq_id));
            @unlink($temp_pdf_path);
            return $request_id;
        }

        return false;
    }

    /**
     * ¿El adjunto pertenece a un gasto o liquidación del usuario indicado?
     */
    private function user_owns_attachment($filename, $user_id)
    {
        global $wpdb;

        $user_id = intval($user_id);
        if (!$user_id || $filename === '') {
            return false;
        }

        $like = '%' . $wpdb->esc_like($filename) . '%';

        $expenses_table = EP_Expenses_DB::get_expenses_table();
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $expenses_table
             WHERE user_id = %d AND (attachment_url LIKE %s OR attachment_path LIKE %s)",
            $user_id,
            $like,
            $like
        ));
        if ($found > 0) {
            return true;
        }

        $liq_table = EP_Expenses_DB::get_liquidations_table();
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $liq_table WHERE user_id = %d AND attachments LIKE %s",
            $user_id,
            $like
        ));

        return ($found > 0);
    }

    public function ajax_view_attachment_preview()
    {
        if (!is_user_logged_in()) {
            wp_die('Acceso denegado. Debe iniciar sesión.');
        }

        $ctx = self::get_user_context();
        if (!$ctx['has_access']) {
            wp_die('Acceso denegado a la aplicación de gastos.');
        }

        $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
        if (empty($file)) {
            wp_die('Archivo no válido');
        }

        // Solo se sirven los formatos que la app permite subir.
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
        if (!in_array($ext, $allowed_exts, true)) {
            wp_die('Tipo de archivo no permitido.');
        }

        // El adjunto solo es visible para su propietario y para quien gestiona gastos.
        if (!$ctx['can_view_all'] && !$this->user_owns_attachment($file, $ctx['user_id'])) {
            wp_die('No tienes permisos para consultar este justificante.');
        }

        list($target_dir, ) = self::ensure_upload_dir();
        $filepath = $target_dir . $file;

        // Defensa en profundidad frente a cualquier salida del directorio permitido.
        $real_path = realpath($filepath);
        $real_dir  = realpath($target_dir);
        if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0) {
            wp_die('Archivo no encontrado');
        }

        if (!file_exists($filepath)) {
            wp_die('Archivo no encontrado');
        }

        // Obtener tipo de archivo
        $filetype = wp_check_filetype($filepath);
        $content_type = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

        $content = file_get_contents($filepath);

        // Si es un PDF, neutralizamos cualquier script o auto-impresión
        if ($content_type === 'application/pdf') {
            $content = str_replace('/Print', '/None ', $content);
            $content = str_replace('this.print(', 'this.non_e(', $content);
            $content = str_replace('print(', 'non_e(', $content);
            $content = str_replace('print (', 'non_e (', $content);
            $content = str_replace('Print(', 'Non_e(', $content);
            $content = str_replace('Print (', 'Non_e (', $content);
            $content = str_replace('/OpenAction', '/NoneAction', $content);
            $content = str_replace('/AA', '/XX', $content);
            $content = str_replace('/JavaScript', '/NoneScript', $content);
            $content = str_replace('/JS', '/XX', $content);
        }

        // Desactivar buffering y limpiar
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $content;
        exit;
    }
}


// Inicializar Sub-Plugin
EP_Expenses::get_instance();
