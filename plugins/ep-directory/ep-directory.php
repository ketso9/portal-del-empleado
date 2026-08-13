<?php

defined('ABSPATH') || exit;
/**
 * Plugin Name: EP Mini App: Directory
 * Description: Directorio de Empleados.
 * Version: 1.0.0
 * Author: Jorge Polo
 * Package: pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class EP_App_Directory implements EP_App_Interface
{
    public function __construct()
    {
        // Integración con IA Bot
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_directory', array($this, 'responder_intent_bot'), 10, 5);

        // Handle exports early to avoid HTML corruption
        add_action('template_redirect', array($this, 'handle_exports_early'));
    }

    public function handle_exports_early()
    {
        if (!isset($_POST['ep_directory_action'])) {
            return;
        }

        // Handle VCard
        if ($_POST['ep_directory_action'] === 'download_vcard') {
            if (!isset($_POST['vcard_nonce']) || !wp_verify_nonce($_POST['vcard_nonce'], 'ep_download_vcard_' . $_POST['user_id'])) {
                wp_die('Error de seguridad (Nonce inválido)');
            }
            $target_id = intval($_POST['user_id']);
            if (function_exists('ep_stats_log')) {
                ep_stats_log('directory', 'vcard_download', get_current_user_id(), ['target_user_id' => $target_id]);
            }
            $this->export_vcard($target_id);
        }

        // Handle PDF
        if ($_POST['ep_directory_action'] === 'export_pdf') {
            if (!isset($_POST['pdf_nonce']) || !wp_verify_nonce($_POST['pdf_nonce'], 'ep_directory_export_pdf')) {
                wp_die('Error de seguridad (Nonce inválido)');
            }
            $this->export_pdf();
        }
    }

    /**
     * ¿Puede el usuario actual editar las fichas del directorio?
     *
     * Administradores de WordPress y Recursos Humanos, para que RR.HH. pueda
     * corregir puestos y departamentos sin entrar a Microsoft 365.
     */
    public static function can_edit_profiles($user_id = 0)
    {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        $allowed = user_can($user, 'manage_options')
            || array_intersect(array('ep_hr', 'ep_super_admin'), (array) $user->roles);

        /**
         * Permite ampliar quién edita el directorio sin tocar el plugin.
         */
        return (bool) apply_filters('ep_directory_can_edit_profiles', (bool) $allowed, $user);
    }

    // --- NORMALIZACIÓN DE DEPARTAMENTOS ---
    //
    // El campo 'ep_department' llega de M365 tal y como cada uno lo tecleó, así
    // que el mismo departamento aparece escrito de varias formas ("COMUNICACIÓN"
    // y "Comunicación", "Proyectos Europeos / Diputación Impulsa" y el mismo con
    // "|"). Aquí se agrupan por una clave normalizada para que el filtro y las
    // etiquetas los traten como uno solo, sin tocar el dato original.

    /**
     * Clave de agrupación: sin mayúsculas, sin tildes, sin espacios de más y
     * con los separadores unificados.
     */
    public static function normalize_department_key($dept)
    {
        $key = trim((string) $dept);
        if ($key === '') {
            return '';
        }

        if (function_exists('remove_accents')) {
            $key = remove_accents($key);
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);

        // "A / B", "A | B" y "A/B" son el mismo departamento.
        $key = preg_replace('/\s*[|\/]\s*/u', ' / ', $key);
        $key = preg_replace('/\s+/u', ' ', $key);
        $key = trim($key, " \t\n\r\0\x0B.,;:");

        return $key;
    }

    /**
     * Agrupa los departamentos de una lista de usuarios.
     *
     * @return array clave_normalizada => ['label' => etiqueta, 'variants' => [texto => nº], 'total' => nº]
     */
    public static function build_department_index($users)
    {
        $groups = array();

        foreach ($users as $u) {
            $raw = trim((string) get_user_meta($u->ID, 'ep_department', true));
            if ($raw === '') {
                continue;
            }

            $key = self::normalize_department_key($raw);
            if ($key === '') {
                continue;
            }

            if (!isset($groups[$key][$raw])) {
                $groups[$key][$raw] = 0;
            }
            $groups[$key][$raw]++;
        }

        $index = array();
        foreach ($groups as $key => $variants) {
            $index[$key] = array(
                'label'    => self::pick_department_label($variants),
                'variants' => $variants,
                'total'    => array_sum($variants)
            );
        }

        // Se ordena por la clave (ya en minúsculas y sin tildes) para que el
        // alfabético sea el natural y no salgan primero las MAYÚSCULAS.
        ksort($index, SORT_STRING);

        return $index;
    }

    /**
     * Elige cómo se muestra un grupo: se prefiere la variante bien capitalizada
     * frente a la escrita TODA EN MAYÚSCULAS o toda en minúsculas y, a igualdad,
     * la que usa más gente.
     */
    private static function pick_department_label($variants)
    {
        $best = '';
        $best_score = null;

        foreach ($variants as $label => $count) {
            $upper = function_exists('mb_strtoupper') ? mb_strtoupper($label, 'UTF-8') : strtoupper($label);
            $lower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);

            if ($label !== $upper && $label !== $lower) {
                $case_rank = 2; // Capitalización normal
            } elseif ($label === $lower) {
                $case_rank = 1; // todo en minúsculas
            } else {
                $case_rank = 0; // TODO EN MAYÚSCULAS
            }

            $score = ($case_rank * 1000000) + $count;

            if ($best_score === null || $score > $best_score || ($score === $best_score && strcmp($label, $best) < 0)) {
                $best = $label;
                $best_score = $score;
            }
        }

        return $best;
    }

    public function get_id()
    {
        return 'directory';
    }

    public function get_name()
    {
        return 'Directorio';
    }

    public function get_icon()
    {
        return 'fa-solid fa-address-book';
    }

    public function get_menu_label()
    {
        return 'Directorio';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=directory'">
            <div class="app-icon-container color-purple">
                <i class="fa-solid fa-address-book"></i>
            </div>
            <h3>Directorio</h3>
            <p>Contactos de empleados</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        // Enqueue styles
        wp_enqueue_style('ep-directory-style', plugin_dir_url(__FILE__) . 'assets/css/ep-directory.css', array(), '1.1.0');

        // Fetch all users
        $users = get_users(array(
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));

        include plugin_dir_path(__FILE__) . 'views/directory-view.php';
    }    private function export_pdf()
    {
        $tcpdf_path = dirname(dirname(__FILE__)) . '/ep-signature/libs/tcpdf/tcpdf.php';
        if (!file_exists($tcpdf_path)) {
            if (defined('EMPLOYEE_PORTAL_PATH')) {
                $tcpdf_path = EMPLOYEE_PORTAL_PATH . 'plugins/ep-signature/libs/tcpdf/tcpdf.php';
            }
        }

        if (!file_exists($tcpdf_path)) {
            wp_die('Error: No se encontró la librería TCPDF en la ruta esperada.');
        }

        if (!class_exists('TCPDF')) {
            require_once($tcpdf_path);
        }

        $users = get_users(array(
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));

        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Document information
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAuthor('Cámara de Comercio de Cáceres');
        $pdf->SetTitle('Directorio de Personal');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);

        // Set margins
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(TRUE, 20);

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Add a page
        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(158, 28, 46); // Corporate Red
        $pdf->Cell(0, 10, 'DIRECTORIO DE PERSONAL', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 10, 'Cámara Oficial de Comercio, Industria y Servicios de Cáceres', 0, 1, 'C');
        $pdf->Ln(5);

        // Content
        $html = '<table cellpadding="8" cellspacing="0" border="0" width="100%">';
        
        $count = 0;
        foreach ($users as $user) {
            $job_title = get_user_meta($user->ID, 'ep_job_title', true) ?: 'Personal';
            $email = $user->user_email;
            $phone = get_user_meta($user->ID, 'ep_mobile_phone', true);
            $office_phone = get_user_meta($user->ID, 'ep_business_phone', true);
            $photo_url = get_user_meta($user->ID, 'ep_user_photo_url', true);
            
            $photo_path = '';
            if ($photo_url) {
                $upload_dir = wp_upload_dir();
                if (strpos($photo_url, $upload_dir['baseurl']) !== false) {
                    $photo_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $photo_url);
                    if (!file_exists($photo_path)) $photo_path = '';
                }
            }

            if ($count % 2 == 0) {
                $html .= '<tr>';
            }

            $html .= '<td width="50%" style="border-bottom: 0.5px solid #dddddd;" nobr="true">';
            $html .= '<table cellpadding="0" cellspacing="0" border="0" nobr="true">';
            $html .= '<tr>';
            
            // Photo cell
            $html .= '<td width="60" align="center" valign="middle">';
            if ($photo_path) {
                $html .= '<img src="' . $photo_path . '" width="45" height="45" />';
            } else {
                $html .= '<div style="background-color: #f0f0f0; width: 45px; height: 45px;"></div>';
            }
            $html .= '</td>';

            // Data cell
            $html .= '<td width="200">';
            $html .= '<span style="font-size: 10.5pt; font-weight: bold; color: #333333;">' . esc_html($user->display_name) . '</span><br />';
            $html .= '<span style="font-size: 8.5pt; color: #9e1c2e;">' . esc_html($job_title) . '</span><br />';
            $html .= '<span style="font-size: 8pt; color: #666666;">' . esc_html($email) . '</span><br />';
            $html .= '<span style="font-size: 8pt; color: #666666;">Ext: ' . esc_html($office_phone ?: '-') . ' | M: ' . esc_html($phone ?: '-') . '</span>';
            $html .= '</td>';

            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</td>';

            if ($count % 2 == 1) {
                $html .= '</tr>';
            }
            $count++;
        }
        
        if ($count % 2 != 0) {
            $html .= '<td width="50%"></td></tr>';
        }

        $html .= '</table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if (ob_get_length()) {
            ob_end_clean();
        }

        $pdf->Output('Directorio_Personal_Camara.pdf', 'D');
        exit;
    }

    public function handle_ajax()
    {
        // Get presence for all directory users
        if (isset($_POST['sub_action']) && $_POST['sub_action'] === 'get_presence') {
            check_ajax_referer('ep_directory_presence', 'security');

            $current_user_id = get_current_user_id();
            if (!$current_user_id) {
                wp_send_json_error('No autenticado');
            }

            // Get all users and their MS IDs
            $users = get_users(array('fields' => array('ID')));
            $ms_ids = array();
            $ms_to_wp = array();

            foreach ($users as $user) {
                $ms_id = get_user_meta($user->ID, 'ep_o365_user_id', true);
                if (!empty($ms_id)) {
                    $ms_ids[] = $ms_id;
                    $ms_to_wp[$ms_id] = $user->ID;
                }
            }

            if (empty($ms_ids)) {
                wp_send_json_success(array());
            }

            // Sincronización inversa: traemos de Microsoft 365 las respuestas
            // automáticas que la gente haya activado desde Outlook o Teams.
            if (class_exists('EP_OOF_Sync')) {
                EP_OOF_Sync::sync_stale($ms_to_wp);

                // Si no hay permiso de aplicación, al menos refrescamos el
                // estado de quien está mirando el directorio (token delegado).
                if (EP_OOF_Sync::app_permission_missing()) {
                    EP_OOF_Sync::sync_self($current_user_id);
                }
            }

            $auth = EP_Auth_O365::get_instance();
            $presences = $auth->get_users_presence($current_user_id, $ms_ids);

            if (is_wp_error($presences)) {
                $presences = array();
            }

            // Índice de presencias por MS ID para poder recorrer TODOS los
            // usuarios: alguien puede estar de vacaciones y sin presencia Teams.
            $presence_by_ms = array();
            foreach ((array) $presences as $p) {
                if (!empty($p['id'])) {
                    $presence_by_ms[$p['id']] = $p;
                }
            }

            $result = array();
            foreach ($ms_to_wp as $ms_id => $wp_id) {
                $p     = $presence_by_ms[$ms_id] ?? array();
                $avail = $p['availability'] ?? 'Offline';
                $act   = $p['activity'] ?? '';

                $oof_data = EP_Auth_O365::get_user_oof_data($wp_id);
                $is_oof   = !empty($oof_data['is_oof']) || $avail === 'OutOfOffice' || $act === 'OutOfOffice';

                if ($is_oof) {
                    $avail = 'OutOfOffice';
                }

                // Sin datos de presencia y sin ausencia: nada que reportar.
                if (empty($p) && !$is_oof) {
                    continue;
                }

                $result[$wp_id] = array(
                    'availability' => $avail,
                    'activity'     => $act,
                    'is_oof'       => $is_oof,
                    'oof_message'  => $oof_data['message'] ?? '',
                    'oof_until'    => !empty($oof_data['end_ts'])
                        ? wp_date('j M', intval($oof_data['end_ts']))
                        : ''
                );
            }

            wp_send_json_success($result);
        }

        // Guardar la ficha de un empleado (admins y RR.HH.)
        if (isset($_POST['sub_action']) && $_POST['sub_action'] === 'save_profile') {
            check_ajax_referer('ep_directory_edit_profile', 'security');

            if (!self::can_edit_profiles()) {
                wp_send_json_error('No tienes permisos para editar el directorio.');
            }

            $target_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            $target    = $target_id ? get_userdata($target_id) : null;

            if (!$target || !$target->exists()) {
                wp_send_json_error('El empleado indicado no existe.');
            }

            $fields = array(
                'ep_job_title'       => sanitize_text_field(wp_unslash($_POST['job_title'] ?? '')),
                'ep_department'      => sanitize_text_field(wp_unslash($_POST['department'] ?? '')),
                'ep_business_phone'  => sanitize_text_field(wp_unslash($_POST['business_phone'] ?? '')),
                'ep_mobile_phone'    => sanitize_text_field(wp_unslash($_POST['mobile_phone'] ?? '')),
                'ep_office_location' => sanitize_text_field(wp_unslash($_POST['office_location'] ?? ''))
            );

            $changed = array();
            foreach ($fields as $meta_key => $value) {
                $current = (string) get_user_meta($target_id, $meta_key, true);
                if ($current === $value) {
                    continue;
                }

                update_user_meta($target_id, $meta_key, $value);
                $changed[$meta_key] = $value;
            }

            if (empty($changed)) {
                wp_send_json_success(array(
                    'message'  => 'No había cambios que guardar.',
                    'fields'   => $fields,
                    'ms_state' => 'sin-cambios'
                ));
            }

            update_user_meta($target_id, 'ep_profile_local_updated_at', time());

            if (function_exists('ep_stats_log')) {
                ep_stats_log('directory', 'profile_edit', get_current_user_id(), array(
                    'target_user_id' => $target_id,
                    'fields'         => array_keys($changed)
                ));
            }

            // Intento de propagar a Azure AD. Si el permiso no está concedido el
            // dato queda fijado en el portal y se avisa sin disfrazarlo.
            $ms_result = $this->push_profile_to_graph($target_id, $changed);

            wp_send_json_success(array(
                'message'  => $ms_result['message'],
                'fields'   => $fields,
                'ms_state' => $ms_result['state']
            ));
        }

        // Volver a dejar mandar a Microsoft 365 sobre la ficha
        if (isset($_POST['sub_action']) && $_POST['sub_action'] === 'reset_profile') {
            check_ajax_referer('ep_directory_edit_profile', 'security');

            if (!self::can_edit_profiles()) {
                wp_send_json_error('No tienes permisos para editar el directorio.');
            }

            $target_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
            if (!$target_id || !get_userdata($target_id)) {
                wp_send_json_error('El empleado indicado no existe.');
            }

            EP_Auth_O365::clear_profile_overrides($target_id);
            delete_user_meta($target_id, 'ep_profile_local_updated_at');

            // Recuperar ya los valores de M365 para no dejar la ficha a medias.
            $graph = EP_Graph_Service::get_instance();
            $token = $graph->get_valid_token($target_id);

            if (!is_wp_error($token)) {
                $profile = $graph->get_user_profile_from_graph($token);
                if (!is_wp_error($profile)) {
                    EP_Auth_O365::get_instance()->sync_user_profile($target_id, $profile);
                    wp_send_json_success('Ficha liberada y recuperada desde Microsoft 365.');
                }
            }

            wp_send_json_success('Ficha liberada. Los valores de Microsoft 365 se recuperarán en la próxima sincronización.');
        }

        // Force Sync Photos
        if (isset($_POST['sub_action']) && $_POST['sub_action'] === 'sync_photos') {
            check_ajax_referer('ep_directory_sync_photos', 'security');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Permisos insuficientes');
            }

            $users = get_users(['meta_key' => 'ep_o365_user_id']);
            $graph = EP_Graph_Service::get_instance();
            $auth = EP_Auth_O365::get_instance();
            $synced = 0;
            $errors = 0;

            foreach ($users as $user) {
                if ($user->ID == 1) continue;
                
                $token = $graph->get_valid_token($user->ID);
                if (is_wp_error($token)) {
                    $errors++;
                    continue;
                }
                
                // 1. Sync Profile Data
                $profile_data = $graph->get_user_profile_from_graph($token);
                if (!is_wp_error($profile_data)) {
                    // Force update by ignoring the 10min lock for this manual sync
                    delete_user_meta($user->ID, 'ep_profile_local_updated_at');
                    $auth->sync_user_profile($user->ID, $profile_data);
                }

                // 2. Sync Photo
                $response = $graph->fetch_user_photo($user->ID, $token);
                if (!is_wp_error($response)) {
                    $status = wp_remote_retrieve_response_code($response);
                    if ($status == 200) {
                        $body = wp_remote_retrieve_body($response);
                        $upload_dir = wp_upload_dir();
                        $filename = 'user_photo_' . $user->ID . '.jpg';
                        $file_path = $upload_dir['path'] . '/' . $filename;
                        
                        file_put_contents($file_path, $body);
                        update_user_meta($user->ID, 'ep_user_photo_url', $upload_dir['url'] . '/' . $filename);
                    }
                }
                
                $synced++;
            }

            // 3. Estados "Fuera de la oficina" desde Outlook/Teams (sync inversa)
            $oof_msg = '';
            if (class_exists('EP_OOF_Sync')) {
                delete_transient(EP_OOF_Sync::PERM_FLAG); // reintentar aunque hubiera fallado
                $oof_updated = EP_OOF_Sync::sync_all();

                if (EP_OOF_Sync::app_permission_missing()) {
                    $oof_msg = ' No se pudieron leer las ausencias del resto de buzones: falta el permiso de aplicación "MailboxSettings.Read" en Azure AD.';
                } else {
                    $oof_msg = " Ausencias actualizadas: $oof_updated.";
                }
            }

            wp_send_json_success("Se han sincronizado $synced perfiles y fotos correctamente." . ($errors > 0 ? " ($errors usuarios sin conexión)" : "") . $oof_msg);
        }
    }

    /**
     * Intenta llevar los campos editados a Azure AD.
     *
     * Escribir el perfil de otra persona exige el permiso de aplicación
     * User.ReadWrite.All. Mientras no esté concedido, el cambio vive sólo en el
     * portal (protegido por el override) y se informa de ello con claridad.
     *
     * @return array{state:string, message:string}
     */
    private function push_profile_to_graph($target_id, $changed)
    {
        $map = EP_Auth_O365::editable_profile_fields();

        $payload = array();
        foreach ($changed as $meta_key => $value) {
            if (!isset($map[$meta_key])) {
                continue;
            }

            $graph_field = $map[$meta_key];

            // businessPhones es una colección, no una cadena.
            if ($graph_field === 'businessPhones') {
                $payload[$graph_field] = $value !== '' ? array($value) : array();
            } else {
                $payload[$graph_field] = $value;
            }
        }

        if (empty($payload)) {
            return array('state' => 'sin-cambios', 'message' => 'Ficha actualizada en el portal.');
        }

        $ms_id  = get_user_meta($target_id, 'ep_o365_user_id', true);
        $result = !empty($ms_id)
            ? EP_Graph_Service::get_instance()->update_user_profile_as_app($ms_id, $payload)
            : new WP_Error('no_ms_account', 'El empleado no tiene cuenta de Microsoft 365 vinculada.');

        if ($result === true) {
            // El dato ya vive en Microsoft 365, que vuelve a ser la fuente de
            // verdad: se sueltan los pines para que un cambio hecho después en
            // Outlook o Azure siga llegando al portal.
            foreach (array_keys($changed) as $meta_key) {
                EP_Auth_O365::set_profile_override($target_id, $meta_key, false);
            }

            return array(
                'state'   => 'sincronizado',
                'message' => 'Ficha actualizada en el portal y en Microsoft 365.'
            );
        }

        // No se pudo escribir: se fija en el portal para que la sincronización
        // entrante no devuelva el valor antiguo.
        foreach (array_keys($changed) as $meta_key) {
            EP_Auth_O365::set_profile_override($target_id, $meta_key, true);
        }

        $error = is_wp_error($result) ? $result->get_error_message() : 'Error desconocido.';

        return array(
            'state'   => 'solo-portal',
            'message' => 'Ficha actualizada en el portal, pero NO se ha podido escribir en Microsoft 365: ' . $error . ' El valor queda fijado en el portal y la sincronización no lo sobrescribirá.'
        );
    }

    private function export_vcard($user_id)
    {
        $user = get_user_by('id', $user_id);
        if (!$user)
            return;

        $first_name = get_user_meta($user_id, 'first_name', true) ?: $user->display_name;
        $last_name = get_user_meta($user_id, 'last_name', true) ?: '';
        $job_title = get_user_meta($user_id, 'ep_job_title', true);
        $phone = get_user_meta($user_id, 'ep_mobile_phone', true);
        $office_phone = get_user_meta($user_id, 'ep_business_phone', true);
        $email = $user->user_email;
        $org = get_bloginfo('name');

        header('Content-Type: text/vcard');
        header('Content-Disposition: attachment; filename="' . sanitize_title($user->display_name) . '.vcf"');

        echo "BEGIN:VCARD\n";
        echo "VERSION:3.0\n";
        echo "FN:" . $user->display_name . "\n";
        echo "N:" . $last_name . ";" . $first_name . ";;;\n";
        echo "EMAIL;TYPE=INTERNET;TYPE=WORK:" . $email . "\n";
        if ($phone)
            echo "TEL;TYPE=CELL:" . $phone . "\n";
        if ($office_phone)
            echo "TEL;TYPE=WORK:" . $office_phone . "\n";
        if ($job_title)
            echo "TITLE:" . $job_title . "\n";
        echo "ORG:" . $org . "\n";
        echo "END:VCARD";
        exit;
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intent_bot($intents)
    {
        $intents['DIRECTORY'] = "El usuario busca el contacto de un empleado, su departamento, teléfono o correo. Ej: 'busca a juan', 'teléfono de maría', 'quién es el director'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $params = $intent_data['params'] ?? [];
        $busqueda = $params['search_term'] ?? $texto;

        // Si la IA no detectó un término claro y el texto es muy corto o ambiguo, mostrar la tarjeta inicial
        $busqueda = trim($busqueda);
        if (empty($busqueda) || strtolower($busqueda) === 'directorio' || strtolower($busqueda) === 'personas') {
            $wp_user = get_userdata($user_id);
            $nombre  = $wp_user ? $wp_user->display_name : 'Usuario';
            return $this->tarjeta_directorio_inicial($nombre, $bot_instance);
        }

        return $this->tarjeta_directorio($busqueda, $user_id, $bot_instance);
    }

    private function tarjeta_directorio_inicial(string $nombre, $bot_instance): array
    {
        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "🔍 Directorio de Empleados", 'weight' => 'Bolder', 'size' => 'Medium'],
            ['type' => 'TextBlock', 'text' => "Hola {$nombre}, ¿a quién buscas? Escribe su nombre o apellido aquí abajo:", 'wrap' => true],
        ], [
            ['type' => 'Action.OpenUrl', 'title' => '🌐 Ver Directorio Web', 'url' => home_url('/?view=directory&teams=true')]
        ]);
    }

    private function sin_tildes(string $t): string
    {
        return str_replace(['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ'], ['a','e','i','o','u','u','n','a','e','i','o','u','u','n'], $t);
    }

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

    private function tarjeta_directorio(string $busqueda, int $current_user_id, $bot_instance): array
    {
        // 1. Búsqueda principal (columnas estándar)
        $usuarios_std = get_users([
            'search'         => '*' . esc_attr($busqueda) . '*',
            'search_columns' => ['display_name', 'user_email', 'user_login', 'nickname'],
            'number'         => 20
        ]);

        // 2. Búsqueda por metadatos (Nombres/Apellidos/Apodo)
        $usuarios_meta = get_users([
            'meta_query' => [
                'relation' => 'OR',
                ['key' => 'first_name', 'value' => '%' . $busqueda . '%', 'compare' => 'LIKE'],
                ['key' => 'last_name', 'value' => '%' . $busqueda . '%', 'compare' => 'LIKE'],
                ['key' => 'nickname', 'value' => '%' . $busqueda . '%', 'compare' => 'LIKE'],
                ['key' => 'ep_job_title', 'value' => '%' . $busqueda . '%', 'compare' => 'LIKE']
            ],
            'number' => 20
        ]);

        // Combinar y eliminar duplicados por ID
        $all_users = array_merge($usuarios_std, $usuarios_meta);
        $usuarios = [];
        $ids_vistos = [];
        foreach ($all_users as $u) {
            if (!in_array($u->ID, $ids_vistos)) {
                $usuarios[] = $u;
                $ids_vistos[] = $u->ID;
            }
            if (count($usuarios) >= 5) break; 
        }

        // Búsqueda difusa si no hay nada
        if (empty($usuarios)) {
            $sin_tildes = $this->sin_tildes($busqueda);
            if ($sin_tildes !== $busqueda) {
                return $this->tarjeta_directorio($sin_tildes, $current_user_id, $bot_instance);
            }
            return $bot_instance->tarjeta_simple('🔍 Directorio', "No he encontrado a nadie que coincida con '{$busqueda}'.", '');
        }

        $cuerpo = [
            ['type' => 'TextBlock', 'text' => "🔍 Resultados para '{$busqueda}'", 'weight' => 'Bolder', 'size' => 'Medium']
        ];

        // 3. Obtención masiva de PRESENCIA (Para evitar timeouts en el bucle)
        $ms_ids_map = [];
        foreach ($usuarios as $u) {
            $ms_id = get_user_meta($u->ID, 'ep_o365_user_id', true);
            if ($ms_id) $ms_ids_map[$ms_id] = $u->ID;
        }

        $presencias_global = [];
        if (!empty($ms_ids_map)) {
            $graph = EP_Graph_Service::get_instance();
            $raw_pres = $graph->get_users_presence($current_user_id, array_keys($ms_ids_map));
            if (!is_wp_error($raw_pres)) {
                foreach ($raw_pres as $p) {
                    if (isset($p['id'])) $presencias_global[$p['id']] = $p['availability'] ?? 'Offline';
                }
            }
        }

        foreach ($usuarios as $u) {
            $job      = get_user_meta($u->ID, 'ep_job_title', true) ?: 'Empleado';
            $ext      = get_user_meta($u->ID, 'ep_business_phone', true) ?: '-';
            $movil    = get_user_meta($u->ID, 'ep_mobile_phone', true) ?: '-';
            $foto     = get_user_meta($u->ID, 'ep_user_photo_url', true) ?: 'https://via.placeholder.com/100';
            $ms_id    = get_user_meta($u->ID, 'ep_o365_user_id', true);
            
            $status_text = "Desconocido";
            $status_color = "Default";

            if ($ms_id && isset($presencias_global[$ms_id])) {
                $raw_status = $presencias_global[$ms_id];
                $status_text = $this->traducir_presencia($raw_status);
                if ($raw_status === 'Available') $status_color = 'Good';
                elseif ($raw_status === 'Busy' || $raw_status === 'DoNotDisturb') $status_color = 'Attention';
                elseif ($raw_status === 'Away' || $raw_status === 'BeRightBack') $status_color = 'Warning';
            }

            // La ausencia manda sobre la presencia de Teams, la haya puesto en
            // el portal o directamente en Outlook.
            $oof = EP_Auth_O365::get_user_oof_data($u->ID);
            if (!empty($oof['is_oof'])) {
                $status_text  = 'Fuera de la oficina';
                $status_color = 'Warning';
                if (!empty($oof['end_ts'])) {
                    $status_text .= ' (hasta el ' . wp_date('j M', intval($oof['end_ts'])) . ')';
                }
                if (!empty($oof['message'])) {
                    $status_text .= ' — ' . $oof['message'];
                }
            }

            $cuerpo[] = [
                'type' => 'ColumnSet',
                'columns' => [
                    [
                        'type' => 'Column',
                        'width' => 'auto',
                        'items' => [['type' => 'Image', 'url' => $foto, 'size' => 'Small', 'style' => 'Person']]
                    ],
                    [
                        'type' => 'Column',
                        'width' => 'stretch',
                        'items' => [
                            ['type' => 'TextBlock', 'text' => "**{$u->display_name}**", 'wrap' => true, 'spacing' => 'None'],
                            ['type' => 'TextBlock', 'text' => "_{$job}_", 'isSubtle' => true, 'spacing' => 'None'],
                            ['type' => 'TextBlock', 'text' => "📞 Ext: {$ext} | 📱: {$movil}", 'size' => 'Small', 'spacing' => 'None'],
                            ['type' => 'TextBlock', 'text' => "Estado: **{$status_text}**", 'size' => 'Small', 'color' => $status_color]
                        ]
                    ]
                ]
            ];
            
            $cuerpo[] = ['type' => 'ActionSet', 'actions' => [
                ['type' => 'Action.OpenUrl', 'title' => "💬 Chatear con {$u->first_name}", 'url' => "https://teams.microsoft.com/l/chat/0/0?users={$u->user_email}"]
            ]];
        }

        $card = $bot_instance->adaptive_card($cuerpo);
        if (!empty($usuarios)) {
            $u = $usuarios[0];
            $card['_meta_data'] = [
                'display_name' => $u->display_name,
                'job_title'    => get_user_meta($u->ID, 'ep_job_title', true) ?: 'Empleado',
                'department'   => get_user_meta($u->ID, 'ep_department', true) ?: ''
            ];
        }
        return $card;
    }
}

// Register App
add_action('ep_register_apps', function ($manager) {
    if (class_exists('EP_App_Directory')) {
        $manager->register_app(new EP_App_Directory());
    }
});

// Register AJAX actions for directory
add_action('wp_ajax_ep_directory_ajax', function () {
    $app = new EP_App_Directory();
    $app->handle_ajax();
});

