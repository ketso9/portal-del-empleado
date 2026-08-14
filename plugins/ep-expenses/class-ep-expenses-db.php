<?php
defined('ABSPATH') || exit;

/**
 * Gestor de Base de Datos para el Módulo de Control de Gastos y Dietas
 */
if (class_exists('EP_Expenses_DB', false)) {
    return; // Ya cargada (evitar redeclaración)
}
class EP_Expenses_DB
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function get_expenses_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'ep_expenses';
    }

    public static function get_closures_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'ep_expense_closures';
    }

    public static function get_liquidations_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'ep_liquidations';
    }

    /**
     * Crea las tablas necesarias en la base de datos de WordPress
     */
    public static function init_db()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_expenses = self::get_expenses_table();
        $table_closures = self::get_closures_table();
        $table_liquidations = self::get_liquidations_table();

        $sql_expenses = "CREATE TABLE IF NOT EXISTS $table_expenses (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_number varchar(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            expense_date date NOT NULL,
            concept varchar(255) NOT NULL,
            category varchar(50) DEFAULT 'dieta' NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT '0.00',
            payment_method varchar(50) DEFAULT 'personal' NOT NULL,
            notes text DEFAULT NULL,
            attachment_path text DEFAULT NULL,
            attachment_url text DEFAULT NULL,
            `year_month` varchar(7) NOT NULL,
            `status` varchar(20) DEFAULT 'pending' NOT NULL,
            signature_request_id bigint(20) DEFAULT NULL,
            admin_signature_request_id bigint(20) DEFAULT NULL,
            closure_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_number (ticket_number),
            KEY user_id (user_id),
            KEY `year_month` (`year_month`),
            KEY `status` (`status`)
        ) $charset_collate;";

        $sql_closures = "CREATE TABLE IF NOT EXISTS $table_closures (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            `year_month` varchar(7) NOT NULL,
            total_tickets int(11) DEFAULT 0 NOT NULL,
            total_amount decimal(10,2) DEFAULT '0.00' NOT NULL,
            `status` varchar(20) DEFAULT 'declared' NOT NULL,
            declared_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            notes text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_year_month (user_id, `year_month`),
            KEY user_id (user_id),
            KEY `year_month` (`year_month`)
        ) $charset_collate;";

        $sql_liquidations = "CREATE TABLE IF NOT EXISTS $table_liquidations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            liquidation_number varchar(50) NOT NULL,
            user_id bigint(20) NOT NULL,
            sede_id int(11) NOT NULL,
            destino varchar(255) NOT NULL,
            motivo text NOT NULL,
            imputa varchar(255) DEFAULT 'Ninguno',
            fecha_desde date NOT NULL,
            hora_desde time DEFAULT NULL,
            fecha_hasta date NOT NULL,
            hora_hasta time DEFAULT NULL,
            kilometros decimal(10,2) NOT NULL DEFAULT '0.00',
            precio_km decimal(10,2) NOT NULL DEFAULT '0.00',
            exento_km decimal(10,2) NOT NULL DEFAULT '0.00',
            sujeto_km decimal(10,2) NOT NULL DEFAULT '0.00',
            gastos_manutencion longtext DEFAULT NULL,
            gastos_alojamiento longtext DEFAULT NULL,
            otros_gastos longtext DEFAULT NULL,
            total_bruto decimal(10,2) NOT NULL DEFAULT '0.00',
            base_retencion decimal(10,2) NOT NULL DEFAULT '0.00',
            renta_exenta decimal(10,2) NOT NULL DEFAULT '0.00',
            irpf_porcentaje decimal(5,2) NOT NULL DEFAULT '0.00',
            irpf_importe decimal(10,2) NOT NULL DEFAULT '0.00',
            ss_porcentaje decimal(5,2) NOT NULL DEFAULT '0.00',
            ss_importe decimal(10,2) NOT NULL DEFAULT '0.00',
            total_percibir decimal(10,2) NOT NULL DEFAULT '0.00',
            fecha_documento date NOT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            signature_request_id bigint(20) DEFAULT NULL,
            admin_signature_request_id bigint(20) DEFAULT NULL,
            notes text DEFAULT NULL,
            payment_method varchar(50) DEFAULT 'personal' NOT NULL,
            google_maps_url varchar(255) DEFAULT NULL,
            attachments longtext DEFAULT NULL,
            approved_by bigint(20) DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            liquidated_by bigint(20) DEFAULT NULL,
            liquidated_at datetime DEFAULT NULL,
            liquidation_method varchar(100) DEFAULT NULL,
            liquidation_notes text DEFAULT NULL,
            rejection_reason text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY liquidation_number (liquidation_number),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_expenses);
        dbDelta($sql_closures);
        dbDelta($sql_liquidations);

        // Inicializar opciones por defecto para numeración de sedes
        if (!get_option('ep_expenses_numbering_config')) {
            $default_numbering = [
                1 => ['name' => 'Sede Cáceres', 'prefix' => 'CAC-'],
                2 => ['name' => 'Sede Plasencia', 'prefix' => 'PLA-'],
                3 => ['name' => 'Sede Badajoz', 'prefix' => 'BAD-'],
                4 => ['name' => 'Sede Mérida', 'prefix' => 'MER-']
            ];
            update_option('ep_expenses_numbering_config', $default_numbering);
        }

        // Inicializar límite exento si no existe
        if (get_option('ep_expenses_km_exempt_limit') === false) {
            update_option('ep_expenses_km_exempt_limit', '0.26');
        }

        // Inicializar mapeo de trabajadores vacío si no existe
        if (!get_option('ep_expenses_workers_config')) {
            update_option('ep_expenses_workers_config', []);
        }

        // Asegurar columnas de trazabilidad de liquidación y google_maps_url si no existen
        $col_check = $wpdb->get_results("SHOW COLUMNS FROM $table_expenses LIKE 'liquidated_by'");
        if (empty($col_check)) {
            $wpdb->query("ALTER TABLE $table_expenses 
                ADD COLUMN liquidated_by bigint(20) DEFAULT NULL AFTER closure_id,
                ADD COLUMN liquidated_at datetime DEFAULT NULL AFTER liquidated_by,
                ADD COLUMN liquidation_method varchar(100) DEFAULT NULL AFTER liquidated_at,
                ADD COLUMN liquidation_notes text DEFAULT NULL AFTER liquidation_method");
        }

        $col_maps = $wpdb->get_results("SHOW COLUMNS FROM $table_expenses LIKE 'google_maps_url'");
        if (empty($col_maps)) {
            $wpdb->query("ALTER TABLE $table_expenses ADD COLUMN google_maps_url text DEFAULT NULL AFTER attachment_url;");
        }

        $col_approved = $wpdb->get_results("SHOW COLUMNS FROM $table_expenses LIKE 'approved_by'");
        if (empty($col_approved)) {
            $wpdb->query("ALTER TABLE $table_expenses 
                ADD COLUMN approved_by bigint(20) DEFAULT NULL AFTER closure_id,
                ADD COLUMN approved_at datetime DEFAULT NULL AFTER approved_by");
        }

        $col_rejected = $wpdb->get_results("SHOW COLUMNS FROM $table_expenses LIKE 'rejection_reason'");
        if (empty($col_rejected)) {
            $wpdb->query("ALTER TABLE $table_expenses ADD COLUMN rejection_reason text DEFAULT NULL AFTER liquidation_notes;");
        }

        // Firma electrónica de los tickets individuales: igual que las liquidaciones,
        // el empleado firma su justificante y Administración firma el abono.
        $col_exp_sig = $wpdb->get_results("SHOW COLUMNS FROM $table_expenses LIKE 'signature_request_id'");
        if (empty($col_exp_sig)) {
            $wpdb->query("ALTER TABLE $table_expenses
                ADD COLUMN signature_request_id bigint(20) DEFAULT NULL AFTER `status`,
                ADD COLUMN admin_signature_request_id bigint(20) DEFAULT NULL AFTER signature_request_id;");
        }

        // Asegurar columnas en la tabla de liquidaciones si ya existía antes de las actualizaciones
        $col_liq_pay = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'payment_method'");
        if (empty($col_liq_pay)) {
            $wpdb->query("ALTER TABLE $table_liquidations ADD COLUMN payment_method varchar(50) DEFAULT 'personal' NOT NULL AFTER notes;");
        }

        $col_liq_imputa = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'imputa'");
        if (empty($col_liq_imputa)) {
            $wpdb->query("ALTER TABLE $table_liquidations ADD COLUMN imputa varchar(255) DEFAULT 'Ninguno' AFTER motivo;");
        }

        $col_liq_maps = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'google_maps_url'");
        if (empty($col_liq_maps)) {
            $wpdb->query("ALTER TABLE $table_liquidations ADD COLUMN google_maps_url varchar(255) DEFAULT NULL AFTER payment_method;");
        }

        $col_liq_atts = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'attachments'");
        if (empty($col_liq_atts)) {
            $wpdb->query("ALTER TABLE $table_liquidations ADD COLUMN attachments longtext DEFAULT NULL AFTER google_maps_url;");
        }

        $col_liq_app = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'approved_by'");
        if (empty($col_liq_app)) {
            $wpdb->query("ALTER TABLE $table_liquidations 
                ADD COLUMN approved_by bigint(20) DEFAULT NULL AFTER attachments,
                ADD COLUMN approved_at datetime DEFAULT NULL AFTER approved_by;");
        }

        $col_liq_admin_sig = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'admin_signature_request_id'");
        if (empty($col_liq_admin_sig)) {
            $wpdb->query("ALTER TABLE $table_liquidations ADD COLUMN admin_signature_request_id bigint(20) DEFAULT NULL AFTER signature_request_id;");
        }

        $col_liq_liq = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'liquidated_by'");
        if (empty($col_liq_liq)) {
            $wpdb->query("ALTER TABLE $table_liquidations 
                ADD COLUMN liquidated_by bigint(20) DEFAULT NULL AFTER approved_at,
                ADD COLUMN liquidated_at datetime DEFAULT NULL AFTER liquidated_by;");
        }

        $col_liq_rej = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'rejection_reason'");
        if (empty($col_liq_rej)) {
            $wpdb->query("ALTER TABLE $table_liquidations ADD COLUMN rejection_reason text DEFAULT NULL AFTER liquidated_at;");
        }

        // Forma de pago y notas del abono de la liquidación. Existían en la tabla de
        // tickets pero no aquí, por lo que los datos del modal de abono se perdían.
        $col_liq_method = $wpdb->get_results("SHOW COLUMNS FROM $table_liquidations LIKE 'liquidation_method'");
        if (empty($col_liq_method)) {
            $wpdb->query("ALTER TABLE $table_liquidations
                ADD COLUMN liquidation_method varchar(100) DEFAULT NULL AFTER liquidated_at,
                ADD COLUMN liquidation_notes text DEFAULT NULL AFTER liquidation_method;");
        }

        // Corrección de una sola vez para restablecer liquidaciones marcadas erróneamente como liquidadas por problemas de carga de clases
        $wpdb->query("UPDATE $table_liquidations SET status = 'approved' WHERE status = 'liquidated' AND (admin_signature_request_id IS NULL OR admin_signature_request_id = 0 OR admin_signature_request_id = '')");
    }

    /**
     * Sanea un campo de adjunto que puede contener una URL/ruta simple o un JSON
     * con varias. esc_url_raw() sobre el JSON lo destruía y se perdían los adjuntos.
     */
    public static function sanitize_attachment_field($value, $is_url = true)
    {
        $value = (string) $value;

        if (strpos(trim($value), '[') === 0) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $clean = array();
                foreach ($decoded as $item) {
                    $item = $is_url ? esc_url_raw($item) : sanitize_text_field($item);
                    if ($item !== '') {
                        $clean[] = $item;
                    }
                }
                return wp_json_encode($clean);
            }
        }

        return $is_url ? esc_url_raw($value) : sanitize_text_field($value);
    }

    /**
     * Devuelve siempre un array de rutas/URLs a partir de un campo de adjunto.
     */
    public static function decode_attachment_field($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }
        if (strpos($value, '[') === 0) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : array();
        }
        return array($value);
    }

    /**
     * Genera el siguiente ticket_number del ejercicio (formato: T-AAAA/NNN).
     *
     * Antes el prefijo llevaba año y mes (202608T0001), así que el correlativo
     * se reiniciaba cada mes. Ahora la serie es anual y arranca sola el 1 de
     * enero, igual que la de las liquidaciones.
     */
    public function generate_ticket_number($expense_date = null)
    {
        $expense_date = $expense_date ? $expense_date : current_time('Y-m-d');

        return self::next_in_series(
            self::get_expenses_table(),
            'ticket_number',
            'T-' . date('Y', strtotime($expense_date)) . '/'
        );
    }

    /**
     * Siguiente correlativo de una serie, deducido de lo ya emitido.
     *
     * El número sale de consultar el máximo ya usado en la serie y no de un
     * contador guardado en opciones. Eso es lo que hace que la numeración se
     * reinicie sola cada 1 de enero (la serie lleva el año, y en enero no hay
     * nada emitido todavía) y lo que impide que choque con el índice único de
     * la columna: no hay forma de "rebobinar" el contador a mano y provocar un
     * duplicado.
     */
    private static function next_in_series($table, $column, $serie)
    {
        global $wpdb;

        // MySQL cuenta SUBSTRING en caracteres, no en bytes: el prefijo de sede
        // lo escribe un humano y puede llevar acentos.
        $desde = function_exists('mb_strlen') ? mb_strlen($serie, 'UTF-8') : strlen($serie);

        // Se ordena por el número, no por id: si se insertan fuera de orden, el
        // correlativo se repetiría y chocaría con el UNIQUE KEY.
        $latest = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(`" . $column . "`, %d) AS UNSIGNED)) FROM $table WHERE `" . $column . "` LIKE %s",
            $desde + 1,
            $wpdb->esc_like($serie) . '%'
        ));

        $siguiente = $latest ? intval($latest) + 1 : 1;

        return $serie . str_pad($siguiente, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Guarda o actualiza un gasto/ticket
     */
    public function save_expense($data)
    {
        global $wpdb;
        $table = self::get_expenses_table();

        $expense_date = !empty($data['expense_date']) ? sanitize_text_field($data['expense_date']) : current_time('Y-m-d');
        $year_month = date('Y-m', strtotime($expense_date));

        $id = !empty($data['id']) ? intval($data['id']) : 0;

        if ($id > 0) {
            // Edición: verificar propiedad y estado antes de tocar nada.
            // Sin esta comprobación cualquier usuario podía reescribir el ticket de otro.
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
            if (!$existing) {
                return false;
            }

            $actor_id       = !empty($data['actor_id']) ? intval($data['actor_id']) : get_current_user_id();
            $can_manage_all = !empty($data['can_manage_all']);

            if (!$can_manage_all) {
                if (intval($existing['user_id']) !== $actor_id) {
                    return false; // No es su gasto
                }
                if (!in_array($existing['status'], array('pending', 'rejected'), true)) {
                    return false; // Ya autorizado o liquidado: no editable por el empleado
                }
            }

            $update_data = array(
                'expense_date'    => $expense_date,
                'concept'         => sanitize_text_field($data['concept']),
                'category'        => sanitize_text_field($data['category']),
                'amount'          => floatval($data['amount']),
                'payment_method'  => sanitize_text_field($data['payment_method']),
                'notes'           => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
                'google_maps_url' => isset($data['google_maps_url']) ? esc_url_raw($data['google_maps_url']) : '',
                'year_month'      => $year_month,
            );

            if (isset($data['attachment_path'])) {
                $update_data['attachment_path'] = self::sanitize_attachment_field($data['attachment_path'], false);
            }
            if (isset($data['attachment_url'])) {
                $update_data['attachment_url'] = self::sanitize_attachment_field($data['attachment_url'], true);
            }

            $wpdb->update($table, $update_data, array('id' => $id));
            return $id;
        } else {
            // Nuevo gasto: Generar ticket_number correlativo
            $ticket_number = $this->generate_ticket_number($expense_date);

            $insert_data = array(
                'ticket_number'   => $ticket_number,
                'user_id'         => intval($data['user_id']),
                'expense_date'    => $expense_date,
                'concept'         => sanitize_text_field($data['concept']),
                'category'        => sanitize_text_field($data['category']),
                'amount'          => floatval($data['amount']),
                'payment_method'  => sanitize_text_field($data['payment_method']),
                'notes'           => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
                'attachment_path' => isset($data['attachment_path']) ? self::sanitize_attachment_field($data['attachment_path'], false) : '',
                'attachment_url'  => isset($data['attachment_url']) ? self::sanitize_attachment_field($data['attachment_url'], true) : '',
                'google_maps_url' => isset($data['google_maps_url']) ? esc_url_raw($data['google_maps_url']) : '',
                'year_month'      => $year_month,
                'status'          => 'pending',
                'created_at'      => current_time('mysql'),
            );

            $inserted = $wpdb->insert($table, $insert_data);
            if ($inserted) {
                return $wpdb->insert_id;
            }
            return false;
        }
    }

    /**
     * Obtiene los gastos con filtros opcionales
     */
    public function get_expenses($args = array())
    {
        global $wpdb;
        $table = self::get_expenses_table();

        $where = array("1=1");
        $params = array();

        if (!empty($args['user_id'])) {
            $where[] = "`user_id` = %d";
            $params[] = intval($args['user_id']);
        }

        if (!empty($args['year'])) {
            $where[] = "YEAR(`expense_date`) = %d";
            $params[] = intval($args['year']);
        }

        if (!empty($args['month'])) {
            $where[] = "MONTH(`expense_date`) = %d";
            $params[] = intval($args['month']);
        }

        if (!empty($args['year_month'])) {
            $where[] = "`year_month` = %s";
            $params[] = sanitize_text_field($args['year_month']);
        }

        if (!empty($args['start_date'])) {
            $where[] = "`expense_date` >= %s";
            $params[] = sanitize_text_field($args['start_date']);
        }

        if (!empty($args['end_date'])) {
            $where[] = "`expense_date` <= %s";
            $params[] = sanitize_text_field($args['end_date']);
        }

        if (!empty($args['years']) && is_array($args['years'])) {
            $placeholders = implode(',', array_fill(0, count($args['years']), '%d'));
            $where[] = "YEAR(`expense_date`) IN ($placeholders)";
            foreach ($args['years'] as $y) {
                $params[] = intval($y);
            }
        }

        if (!empty($args['category'])) {
            $where[] = "`category` = %s";
            $params[] = sanitize_text_field($args['category']);
        }

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "`status` IN ($placeholders)";
                foreach ($args['status'] as $st) {
                    $params[] = sanitize_text_field($st);
                }
            } else {
                $where[] = "`status` = %s";
                $params[] = sanitize_text_field($args['status']);
            }
        }

        if (!empty($args['search'])) {
            $where[] = "(`ticket_number` LIKE %s OR `concept` LIKE %s OR `notes` LIKE %s)";
            $search_like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = $search_like;
        }

        $where_sql = implode(' AND ', $where);
        $order_by = !empty($args['order_by']) ? sanitize_sql_orderby($args['order_by']) : '`id` DESC';

        $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY $order_by";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Obtiene un gasto individual por ID
     */
    public function get_expense($id)
    {
        global $wpdb;
        $table = self::get_expenses_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($id)), ARRAY_A);
    }

    /**
     * Actualiza el estado de un gasto (ej. 'pending' -> 'approved' -> 'declared' / 'rejected')
     */
    public function update_status($id, $status, $liquidation_data = array(), $approval_data = array())
    {
        global $wpdb;
        $table = self::get_expenses_table();

        $update_fields = array(
            'status'     => $status,
            'updated_at' => current_time('mysql')
        );

        if ($status === 'approved') {
            $current_user_id = get_current_user_id();
            $update_fields['approved_by'] = !empty($approval_data['approved_by']) ? intval($approval_data['approved_by']) : $current_user_id;
            $update_fields['approved_at'] = current_time('mysql');
            $update_fields['rejection_reason'] = null;
        } elseif ($status === 'rejected') {
            $current_user_id = get_current_user_id();
            $update_fields['approved_by']       = !empty($approval_data['approved_by']) ? intval($approval_data['approved_by']) : $current_user_id;
            $update_fields['approved_at']       = current_time('mysql');
            $update_fields['rejection_reason']  = !empty($approval_data['rejection_reason']) ? sanitize_textarea_field($approval_data['rejection_reason']) : '';
        } elseif ($status === 'declared') {
            $current_user_id = get_current_user_id();
            $update_fields['liquidated_by']     = !empty($liquidation_data['liquidated_by']) ? intval($liquidation_data['liquidated_by']) : $current_user_id;
            $update_fields['liquidated_at']     = current_time('mysql');
            $update_fields['liquidation_method'] = !empty($liquidation_data['liquidation_method']) ? sanitize_text_field($liquidation_data['liquidation_method']) : 'Transferencia Bancaria';
            $update_fields['liquidation_notes']  = !empty($liquidation_data['liquidation_notes']) ? sanitize_textarea_field($liquidation_data['liquidation_notes']) : '';
        } elseif ($status === 'pending') {
            // Si se reabre a pendiente, reseteamos la trazabilidad
            $update_fields['approved_by']       = null;
            $update_fields['approved_at']       = null;
            $update_fields['liquidated_by']     = null;
            $update_fields['liquidated_at']     = null;
            $update_fields['liquidation_method'] = null;
            $update_fields['liquidation_notes']  = null;
            $update_fields['rejection_reason']  = null;
            // El abono deberá firmarse de nuevo con los datos actualizados
            $update_fields['admin_signature_request_id'] = null;
        }

        return $wpdb->update(
            $table,
            $update_fields,
            array('id' => intval($id))
        );
    }

    /**
     * Elimina un gasto por ID
     */
    public function delete_expense($id, $user_id = null, $can_manage_all = false)
    {
        global $wpdb;
        $table = self::get_expenses_table();

        $expense = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($id)), ARRAY_A);
        if (!$expense) {
            return false;
        }

        // El WHERE incluye siempre el propietario salvo que el actor sea gestor.
        $where = array('id' => intval($id));
        if (!$can_manage_all) {
            if ($user_id === null) {
                return false;
            }
            $where['user_id'] = intval($user_id);
            // Un empleado no borra un gasto ya autorizado o liquidado.
            if (!in_array($expense['status'], array('pending', 'rejected'), true)) {
                return false;
            }
        }

        $deleted = $wpdb->delete($table, $where);
        if (!$deleted) {
            return false;
        }

        // Eliminar archivos adjuntos físicos (uno o varios en JSON)
        foreach (self::decode_attachment_field($expense['attachment_path']) as $path) {
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        return $deleted;
    }

    /**
     * Procesa la declaración y cierre mensual del usuario (para el día 27 o posterior)
     */
    public function declare_monthly_closure($user_id, $year_month, $notes = '')
    {
        global $wpdb;
        $table_exp = self::get_expenses_table();
        $table_cls = self::get_closures_table();

        // Obtener tickets del usuario en ese mes
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT `id`, `amount` FROM $table_exp WHERE `user_id` = %d AND `year_month` = %s",
            $user_id,
            $year_month
        ), ARRAY_A);

        if (empty($tickets)) {
            return false; // No hay tickets en el mes
        }

        $total_tickets = count($tickets);
        $total_amount = 0.00;
        $ticket_ids = array();

        foreach ($tickets as $t) {
            $total_amount += floatval($t['amount']);
            $ticket_ids[] = intval($t['id']);
        }

        // Insertar o actualizar registro de cierre
        $closure_data = array(
            'user_id'       => intval($user_id),
            'year_month'    => sanitize_text_field($year_month),
            'total_tickets' => $total_tickets,
            'total_amount'  => $total_amount,
            'status'        => 'declared',
            'declared_at'   => current_time('mysql'),
            'notes'         => sanitize_textarea_field($notes),
        );

        $existing_closure_id = $wpdb->get_var($wpdb->prepare(
            "SELECT `id` FROM $table_cls WHERE `user_id` = %d AND `year_month` = %s",
            $user_id,
            $year_month
        ));

        if ($existing_closure_id) {
            $wpdb->update($table_cls, $closure_data, array('id' => $existing_closure_id));
            $closure_id = $existing_closure_id;
        } else {
            $wpdb->insert($table_cls, $closure_data);
            $closure_id = $wpdb->insert_id;
        }

        // Asociar los tickets al cierre SIN tocar su estado: 'declared' significa
        // "liquidado por Administración" y el empleado no puede otorgárselo.
        if (!empty($ticket_ids)) {
            $ids_placeholder = implode(',', array_map('intval', $ticket_ids));
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_exp SET `closure_id` = %d WHERE `id` IN ($ids_placeholder)",
                $closure_id
            ));
        }

        return array(
            'closure_id'    => $closure_id,
            'total_tickets' => $total_tickets,
            'total_amount'  => $total_amount,
            'year_month'    => $year_month
        );
    }

    /**
     * Obtiene el estado del cierre mensual de un usuario para un mes concreto
     */
    public function get_closure_status($user_id, $year_month)
    {
        global $wpdb;
        $table_cls = self::get_closures_table();

        if (empty($user_id)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_cls WHERE `user_id` = %d AND `year_month` = %s",
            $user_id,
            $year_month
        ), ARRAY_A);
    }

    /**
     * Genera el siguiente número correlativo para una liquidación de una sede específica
     */
    public function generate_liquidation_number($sede_id, $fecha_documento = null)
    {
        $numbering_config = get_option('ep_expenses_numbering_config', []);
        if (!isset($numbering_config[$sede_id])) {
            $sede_id = 1;
        }

        $prefix = isset($numbering_config[$sede_id]['prefix']) ? $numbering_config[$sede_id]['prefix'] : 'CAC-';
        $anio   = date('Y', $fecha_documento ? strtotime($fecha_documento) : current_time('timestamp'));

        // Serie por sede y ejercicio: CAC-2026/001.
        return self::next_in_series(
            self::get_liquidations_table(),
            'liquidation_number',
            $prefix . $anio . '/'
        );
    }

    /**
     * Guarda o edita una liquidación
     */
    public function save_liquidation($data)
    {
        global $wpdb;
        $table = self::get_liquidations_table();

        $id = !empty($data['id']) ? intval($data['id']) : 0;
        $sede_id = !empty($data['sede_id']) ? intval($data['sede_id']) : 1;

        // El propietario NUNCA se toma de la petición: siempre es el actor autenticado
        // (o el propietario original si se trata de una edición).
        $actor_id       = !empty($data['actor_id']) ? intval($data['actor_id']) : get_current_user_id();
        $can_manage_all = !empty($data['can_manage_all']);
        $user_id        = $actor_id;

        $existing = null;
        if ($id > 0) {
            $existing = $this->get_liquidation($id);
            if (!$existing) {
                return false;
            }
            if (!$can_manage_all) {
                if (intval($existing['user_id']) !== $actor_id) {
                    return false; // No es su liquidación
                }
                if (!in_array($existing['status'], array('pending', 'rejected'), true)) {
                    return false; // Ya aprobada / abonada
                }
            }
            $user_id = intval($existing['user_id']);
        }

        // Obtener retenciones configuradas del usuario
        $workers_config = get_option('ep_expenses_workers_config', []);
        $user_config = isset($workers_config[$user_id]) ? $workers_config[$user_id] : [];
        $precio_km = isset($user_config['km_rate']) ? floatval($user_config['km_rate']) : 0.25;
        $irpf_porcentaje = isset($user_config['irpf']) ? floatval($user_config['irpf']) : 2.00;
        $ss_porcentaje = isset($user_config['ss']) ? floatval($user_config['ss']) : 1.00;

        $exempt_limit = floatval(get_option('ep_expenses_km_exempt_limit', '0.26'));

        // Cálculos
        $kilometros = isset($data['kilometros']) ? floatval($data['kilometros']) : 0;
        $total_km_amount = $kilometros * $precio_km;
        if ($precio_km <= $exempt_limit) {
            $exento_km = $total_km_amount;
            $sujeto_km = 0;
        } else {
            $exento_km = $kilometros * $exempt_limit;
            $sujeto_km = $total_km_amount - $exento_km;
        }

        // Sumar manutención, alojamiento, otros (vienen como JSON de entrada o arrays)
        $manutencion_list = isset($data['gastos_manutencion']) ? $data['gastos_manutencion'] : [];
        $alojamiento_list = isset($data['gastos_alojamiento']) ? $data['gastos_alojamiento'] : [];
        $otros_list = isset($data['otros_gastos']) ? $data['otros_gastos'] : [];

        // Si son strings JSON, los decodificamos para calcular
        $manutencion_arr = is_string($manutencion_list) ? json_decode($manutencion_list, true) : $manutencion_list;
        $alojamiento_arr = is_string($alojamiento_list) ? json_decode($alojamiento_list, true) : $alojamiento_list;
        $otros_arr = is_string($otros_list) ? json_decode($otros_list, true) : $otros_list;

        $sum_manutencion = 0;
        if (is_array($manutencion_arr)) {
            foreach ($manutencion_arr as $row) {
                $sum_manutencion += isset($row['importe']) ? floatval($row['importe']) : 0;
            }
        }

        $sum_alojamiento = 0;
        if (is_array($alojamiento_arr)) {
            foreach ($alojamiento_arr as $row) {
                $sum_alojamiento += isset($row['importe']) ? floatval($row['importe']) : 0;
            }
        }

        $sum_otros = 0;
        if (is_array($otros_arr)) {
            foreach ($otros_arr as $row) {
                $sum_otros += isset($row['importe']) ? floatval($row['importe']) : 0;
            }
        }

        $base_retencion = $sujeto_km;
        $renta_exenta = $exento_km + $sum_manutencion + $sum_alojamiento + $sum_otros;
        $total_bruto = $base_retencion + $renta_exenta;

        $irpf_importe = $base_retencion * ($irpf_porcentaje / 100);
        $ss_importe = $base_retencion * ($ss_porcentaje / 100);
        $total_percibir = $total_bruto - $irpf_importe - $ss_importe;

        $fields = array(
            'user_id' => $user_id,
            'sede_id' => $sede_id,
            'destino' => sanitize_text_field($data['destino']),
            'motivo' => sanitize_textarea_field($data['motivo']),
            'imputa' => !empty($data['imputa']) ? sanitize_text_field($data['imputa']) : 'Ninguno',
            'fecha_desde' => sanitize_text_field($data['fecha_desde']),
            'hora_desde' => !empty($data['hora_desde']) ? sanitize_text_field($data['hora_desde']) : null,
            'fecha_hasta' => sanitize_text_field($data['fecha_hasta']),
            'hora_hasta' => !empty($data['hora_hasta']) ? sanitize_text_field($data['hora_hasta']) : null,
            'kilometros' => $kilometros,
            'precio_km' => $precio_km,
            'exento_km' => $exento_km,
            'sujeto_km' => $sujeto_km,
            'gastos_manutencion' => is_string($manutencion_list) ? $manutencion_list : wp_json_encode($manutencion_list),
            'gastos_alojamiento' => is_string($alojamiento_list) ? $alojamiento_list : wp_json_encode($alojamiento_list),
            'otros_gastos' => is_string($otros_list) ? $otros_list : wp_json_encode($otros_list),
            'total_bruto' => $total_bruto,
            'base_retencion' => $base_retencion,
            'renta_exenta' => $renta_exenta,
            'irpf_porcentaje' => $irpf_porcentaje,
            'irpf_importe' => $irpf_importe,
            'ss_porcentaje' => $ss_porcentaje,
            'ss_importe' => $ss_importe,
            'total_percibir' => $total_percibir,
            'fecha_documento' => !empty($data['fecha_documento']) ? sanitize_text_field($data['fecha_documento']) : current_time('Y-m-d'),
            'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
            'payment_method' => !empty($data['payment_method']) ? sanitize_text_field($data['payment_method']) : 'personal',
            'google_maps_url' => !empty($data['google_maps_url']) ? esc_url_raw($data['google_maps_url']) : '',
            'updated_at' => current_time('mysql'),
        );

        if (isset($data['attachments'])) {
            $fields['attachments'] = $data['attachments'];
        }

        // Los identificadores de firma y el estado NO se aceptan desde la petición:
        // se gestionan únicamente desde el flujo de aprobación / abono.

        if ($id > 0) {
            // Edición: el estado vuelve a 'pending' porque los importes han cambiado.
            $fields['status'] = 'pending';
            $wpdb->update($table, $fields, array('id' => $id));
            return $id;
        } else {
            // Nuevo: Generar número correlativo
            $fields['liquidation_number'] = $this->generate_liquidation_number($sede_id, $fields['fecha_documento']);
            $fields['status'] = 'pending';
            $fields['created_at'] = current_time('mysql');
            
            $inserted = $wpdb->insert($table, $fields);
            if ($inserted) {
                return $wpdb->insert_id;
            }
            return false;
        }
    }

    /**
     * Obtiene las liquidaciones filtradas
     */
    public function get_liquidations($args = array())
    {
        global $wpdb;
        $table = self::get_liquidations_table();

        $where = array("1=1");
        $params = array();

        if (!empty($args['user_id'])) {
            $where[] = "`user_id` = %d";
            $params[] = intval($args['user_id']);
        }

        if (!empty($args['status'])) {
            $where[] = "`status` = %s";
            $params[] = sanitize_text_field($args['status']);
        }

        if (!empty($args['sede_id'])) {
            $where[] = "`sede_id` = %d";
            $params[] = intval($args['sede_id']);
        }

        if (!empty($args['years']) && is_array($args['years'])) {
            $placeholders = implode(',', array_fill(0, count($args['years']), '%d'));
            $where[] = "YEAR(`fecha_documento`) IN ($placeholders)";
            foreach ($args['years'] as $y) {
                $params[] = intval($y);
            }
        }

        if (!empty($args['start_date'])) {
            $where[] = "`fecha_documento` >= %s";
            $params[] = sanitize_text_field($args['start_date']);
        }

        if (!empty($args['end_date'])) {
            $where[] = "`fecha_documento` <= %s";
            $params[] = sanitize_text_field($args['end_date']);
        }

        if (!empty($args['search'])) {
            $where[] = "(`liquidation_number` LIKE %s OR `destino` LIKE %s OR `motivo` LIKE %s)";
            $search_like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = $search_like;
        }

        $where_sql = implode(' AND ', $where);
        $order_by = !empty($args['order_by']) ? sanitize_sql_orderby($args['order_by']) : '`id` DESC';

        $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY $order_by";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Obtiene una liquidación individual
     */
    public function get_liquidation($id)
    {
        global $wpdb;
        $table = self::get_liquidations_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($id)), ARRAY_A);
    }

    /**
     * Elimina una liquidación por ID
     */
    public function delete_liquidation($id, $user_id = null, $can_manage_all = false)
    {
        global $wpdb;
        $table = self::get_liquidations_table();

        $liq = $this->get_liquidation($id);
        if (!$liq) {
            return false;
        }

        $where = array('id' => intval($id));
        if (!$can_manage_all) {
            if ($user_id === null) {
                return false;
            }
            $where['user_id'] = intval($user_id);
            if (!in_array($liq['status'], array('pending', 'rejected'), true)) {
                return false;
            }
        }

        $deleted = $wpdb->delete($table, $where);
        if (!$deleted) {
            return false;
        }

        // Limpiar comprobantes físicos asociados
        if (!empty($liq['attachments'])) {
            $atts = json_decode($liq['attachments'], true);
            if (!empty($atts['paths']) && is_array($atts['paths'])) {
                foreach ($atts['paths'] as $path) {
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Cambia el estado de una liquidación
     */
    public function update_liquidation_status($id, $status, $liquidation_data = array(), $approval_data = array())
    {
        global $wpdb;
        $table = self::get_liquidations_table();

        $update_fields = array(
            'status'     => $status,
            'updated_at' => current_time('mysql')
        );

        $current_user_id = get_current_user_id();

        if ($status === 'approved') {
            $update_fields['approved_by'] = !empty($approval_data['approved_by']) ? intval($approval_data['approved_by']) : $current_user_id;
            $update_fields['approved_at'] = current_time('mysql');
            $update_fields['rejection_reason'] = null;
        } elseif ($status === 'rejected') {
            $update_fields['approved_by']       = !empty($approval_data['approved_by']) ? intval($approval_data['approved_by']) : $current_user_id;
            $update_fields['approved_at']       = current_time('mysql');
            $update_fields['rejection_reason']  = !empty($approval_data['rejection_reason']) ? sanitize_textarea_field($approval_data['rejection_reason']) : '';
        } elseif ($status === 'liquidated' || $status === 'declared') {
            $update_fields['liquidated_by']      = !empty($liquidation_data['liquidated_by']) ? intval($liquidation_data['liquidated_by']) : $current_user_id;
            $update_fields['liquidated_at']      = !empty($liquidation_data['liquidated_at']) ? $liquidation_data['liquidated_at'] : current_time('mysql');
            $update_fields['liquidation_method'] = !empty($liquidation_data['liquidation_method']) ? sanitize_text_field($liquidation_data['liquidation_method']) : 'Transferencia Bancaria';
            $update_fields['liquidation_notes']  = !empty($liquidation_data['liquidation_notes']) ? sanitize_textarea_field($liquidation_data['liquidation_notes']) : '';
        } elseif ($status === 'pending') {
            $update_fields['approved_by']        = null;
            $update_fields['approved_at']        = null;
            $update_fields['liquidated_by']      = null;
            $update_fields['liquidated_at']      = null;
            $update_fields['liquidation_method'] = null;
            $update_fields['liquidation_notes']  = null;
            $update_fields['rejection_reason']   = null;
            // El abono deberá firmarse de nuevo con los datos actualizados
            $update_fields['admin_signature_request_id'] = null;
        }

        return $wpdb->update(
            $table,
            $update_fields,
            array('id' => intval($id))
        );
    }
}

