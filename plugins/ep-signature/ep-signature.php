<?php
defined('ABSPATH') || exit;
/**
 * Plugin Name: EP Mini App: Firma Electrónica
 * Description: Gestión y firma de documentos PDF con certificado digital (v2.2 Integration).
 * Version: 2.2.0
 * Author: Jorge Polo
 * Package: pro_plus
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('EP_SIGNATURE_V4_LOADED')) {
    return;
}
define('EP_SIGNATURE_V4_LOADED', true);

/**
 * Extended FPDI class to support visual flattening for annotations (Acrobat/AutoFirma signatures)
 */

// --- Start Global Helper Block ---
/**
 * Define the extended FPDI class at global scope.
 * Las librerías se cargan de forma LAZY dentro de handle_prepare_pdf().
 * Este bloque solo define la clase si las librerías ya están disponibles
 * (por ejemplo, si otro plugin las cargó antes).
 */
function ep_signature_define_fpdi_class() {
    if (class_exists('EP_Fpdi_V4')) {
        return true;
    }
    if (!class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi')) {
        return false;
    }

    class EP_Fpdi_V4 extends \setasign\Fpdi\Tcpdf\Fpdi {
        public function getPageAnnots($pageNumber) {
            try {
                $reader = $this->getPdfReader($this->currentReaderId);
                $page = $reader->getPage($pageNumber);
                $dict = $page->getPageDictionary();
                $annots = \setasign\Fpdi\PdfParser\Type\PdfType::resolve(\setasign\Fpdi\PdfParser\Type\PdfDictionary::get($dict, 'Annots'), $reader->getParser());
                return ($annots instanceof \setasign\Fpdi\PdfParser\Type\PdfArray) ? $annots : null;
            } catch (\Exception $e) {
                return null;
            }
        }

        public function importAnnotAppearance($annot, $pageNumber) {
            try {
                $reader = $this->getPdfReader($this->currentReaderId);
                $parser = $reader->getParser();
                $annotDict = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($annot, $parser);
                if (!($annotDict instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary)) return false;

                $page = $reader->getPage($pageNumber);
                $bbox = $page->getBoundary(\setasign\Fpdi\PdfReader\PageBoundaries::CROP_BOX);
                $llx = $bbox->getLlx();
                $lly = $bbox->getLly();

                $ap = \setasign\Fpdi\PdfParser\Type\PdfType::resolve(\setasign\Fpdi\PdfParser\Type\PdfDictionary::get($annotDict, 'AP'), $parser);
                if (!($ap instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary)) return false;

                $n = \setasign\Fpdi\PdfParser\Type\PdfType::resolve(\setasign\Fpdi\PdfParser\Type\PdfDictionary::get($ap, 'N'), $parser);
                if (!($n instanceof \setasign\Fpdi\PdfParser\Type\PdfStream)) return false;

                // --- ASEGURAR CABECERA FORM XOBJECT VÁLIDA PARA ACROBAT (Evita "Se esperaba un objeto nominal") ---
                if ($n->value instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary) {
                    $n->value->value['Type'] = \setasign\Fpdi\PdfParser\Type\PdfName::create('XObject');
                    $n->value->value['Subtype'] = \setasign\Fpdi\PdfParser\Type\PdfName::create('Form');
                    if (!isset($n->value->value['FormType'])) {
                        $n->value->value['FormType'] = \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(1);
                    }
                    if (!isset($n->value->value['Matrix'])) {
                        $n->value->value['Matrix'] = \setasign\Fpdi\PdfParser\Type\PdfArray::create([
                            \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(1),
                            \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(0),
                            \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(0),
                            \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(1),
                            \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(0),
                            \setasign\Fpdi\PdfParser\Type\PdfNumeric::create(0)
                        ]);
                    }
                }
                // --------------------------------------------------------------------------------------------------

                $rect = \setasign\Fpdi\PdfParser\Type\PdfType::resolve(\setasign\Fpdi\PdfParser\Type\PdfDictionary::get($annotDict, 'Rect'), $parser);
                if (!($rect instanceof \setasign\Fpdi\PdfParser\Type\PdfArray) || count($rect->value) !== 4) return false;

                $lx = \setasign\Fpdi\PdfParser\Type\PdfNumeric::ensure(\setasign\Fpdi\PdfParser\Type\PdfType::resolve($rect->value[0], $parser))->value;
                $ly = \setasign\Fpdi\PdfParser\Type\PdfNumeric::ensure(\setasign\Fpdi\PdfParser\Type\PdfType::resolve($rect->value[1], $parser))->value;
                $ux = \setasign\Fpdi\PdfParser\Type\PdfNumeric::ensure(\setasign\Fpdi\PdfParser\Type\PdfType::resolve($rect->value[2], $parser))->value;
                $uy = \setasign\Fpdi\PdfParser\Type\PdfNumeric::ensure(\setasign\Fpdi\PdfParser\Type\PdfType::resolve($rect->value[3], $parser))->value;

                $width = abs($ux - $lx); $height = abs($uy - $ly);

                // SANITY CHECK: Evitar dimensiones cero que rompen TCPDF
                if ($width <= 0.0001 || $height <= 0.0001) {
                    return false;
                }

                $rel_x = ($lx - $llx); $rel_y_top = ($uy - $lly);

                // --- FUSIÓN DE RECURSOS PARA EVITAR ACROBAT ERROR 18 ---
                if ($n->value instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary) {
                    // 1. Obtener los recursos de la página original
                    $pageDict = $page->getPageDictionary();
                    $pageResources = null;
                    if (isset($pageDict->value['Resources'])) {
                        $pageResources = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($pageDict->value['Resources'], $parser);
                    }

                    // 2. Obtener los recursos globales de AcroForm (DR)
                    $catalog = $parser->getCatalog();
                    $acroForm = null;
                    $drResources = null;
                    if (isset($catalog->value['AcroForm'])) {
                        $acroForm = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($catalog->value['AcroForm'], $parser);
                        if ($acroForm instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary && isset($acroForm->value['DR'])) {
                            $drResources = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($acroForm->value['DR'], $parser);
                        }
                    }

                    // 3. Obtener o crear el diccionario de recursos de la anotación
                    $annotResources = null;
                    if (isset($n->value->value['Resources'])) {
                        $annotResources = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($n->value->value['Resources'], $parser);
                    }
                    if (!($annotResources instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary)) {
                        $annotResources = \setasign\Fpdi\PdfParser\Type\PdfDictionary::create([]);
                    }

                    // Lista de claves estándar válidas para un diccionario /Resources (PDF Reference Table 3.35)
                    $allowedKeys = ['ExtGState', 'ColorSpace', 'Pattern', 'Shading', 'XObject', 'Font', 'ProcSet', 'Properties'];

                    // Función auxiliar para fusionar diccionarios de recursos de forma recursiva/segura
                    $mergeResources = function($sourceRes, &$targetRes) use ($parser, $allowedKeys) {
                        if ($sourceRes instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary) {
                            foreach ($sourceRes->value as $resKey => $resVal) {
                                // FILTRADO: Copiar únicamente claves estándar para evitar corromper el diccionario /Resources
                                if (!in_array($resKey, $allowedKeys)) {
                                    continue;
                                }

                                $resValResolved = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($resVal, $parser);
                                if ($resValResolved instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary) {
                                    $targetResItem = null;
                                    if (isset($targetRes->value[$resKey])) {
                                        $targetResItem = \setasign\Fpdi\PdfParser\Type\PdfType::resolve($targetRes->value[$resKey], $parser);
                                    }
                                    if ($targetResItem instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary) {
                                        foreach ($resValResolved->value as $subKey => $subVal) {
                                            if (!isset($targetResItem->value[$subKey])) {
                                                $targetResItem->value[$subKey] = $subVal;
                                            }
                                        }
                                    } else {
                                        $targetRes->value[$resKey] = $resValResolved;
                                    }
                                } else {
                                    if (!isset($targetRes->value[$resKey])) {
                                        $targetRes->value[$resKey] = $resVal;
                                    }
                                }
                            }
                        }
                    };

                    // Fusionar recursos globales de AcroForm (DR) y de la página en la anotación
                    $mergeResources($drResources, $annotResources);
                    $mergeResources($pageResources, $annotResources);

                    // Asignar el diccionario de recursos fusionados de vuelta al stream de apariencia de la anotación
                    $n->value->value['Resources'] = $annotResources;
                }
                // --------------------------------------------------------

                $tplId = 'ANN_TPL_' . $this->getNextTemplateId();
                $this->importedPages[$tplId] = [
                    'objectNumber' => null, 'readerId' => $this->currentReaderId,
                    'id' => 'TPL' . $this->getNextTemplateId(),
                    'width' => $width / $this->k, 'height' => $height / $this->k,
                    'stream' => $n, 'externalLinks' => []
                ];

                return ['id' => $tplId, 'x' => $rel_x / $this->k, 'y' => $rel_y_top / $this->k, 'w' => $width / $this->k, 'h' => $height / $this->k];
            } catch (\Exception $e) {
                ep_error_log("EP_App_Signature_V4: Error importing annot: " . $e->getMessage());
                return false;
            }
        }
    }
    return true;
}
// --- End Global Helper Block ---

class EP_App_Signature_V4 implements EP_App_Interface
{
    private $libs_path;
    private $base_path;
    private $base_url;

    public function __construct()
    {
        $this->base_path = EMPLOYEE_PORTAL_PATH . 'plugins/ep-signature/';
        $this->base_url  = EMPLOYEE_PORTAL_URL . 'plugins/ep-signature/';
        $this->libs_path = $this->base_path . 'libs/';

        // Register AJAX actions
        add_action('wp_ajax_ep_app_signature', array($this, 'handle_ajax'));
        add_action('wp_ajax_ep_app_signature_save_user_signature', [$this, 'handle_save_user_signature']);
        add_action('wp_ajax_ep_app_signature_get_user_signature', [$this, 'handle_get_user_signature']);
        add_action('wp_ajax_ep_app_signature_save_user_logo', [$this, 'handle_save_user_logo']);
        add_action('wp_ajax_ep_app_signature_get_user_logo', [$this, 'handle_get_user_logo']);

        // Debug mail failures
        add_action('wp_mail_failed', function ($error) {
            ep_error_log('EP_App_Signature_V4: [MAIL_ERR] wp_mail failed: ' . print_r($error, true));
        });

        // --- IA Bot Integration ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_signature', array($this, 'responder_intent_bot'), 10, 5);

        add_action('init', [$this, 'maybe_update_db_schema']);
        add_action('ep_cleanup_temp_file', [$this, 'delete_temp_file'], 10, 1);
    }

    /**
     * Update DB schema if necessary
     */
    public function maybe_update_db_schema() {
        if (get_transient('fds_db_schema_ready_v4')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        
        // Comprobar si la columna existe de forma rápida
        $row = $wpdb->get_row("SELECT * FROM $table LIMIT 1", ARRAY_A);
        if ($row !== null && !isset($row['observaciones'])) {
            $wpdb->query("ALTER TABLE $table ADD observaciones longtext DEFAULT NULL AFTER hash_documento_original");
            ep_error_log("EP_App_Signature_V4: [DB] Added 'observaciones' column to $table");
        }
        
        set_transient('fds_db_schema_ready_v4', true, DAY_IN_SECONDS);
    }

    public function get_id()
    {
        return 'signature';
    }

    public function get_name()
    {
        return 'Firma Electrónica';
    }

    public function get_icon()
    {
        return 'fa-solid fa-file-signature';
    }

    /**
     * Código Seguro de Verificación de un documento.
     *
     * Debe ser idéntico al preparar el PDF (cuando se imprime en el pie y en el QR)
     * y al guardarlo firmado (cuando se almacena en base de datos), por eso se deriva
     * en servidor a partir del id de la solicitud y no del contenido del fichero.
     * Para firmas sueltas sin solicitud previa se mantiene el hash del documento.
     */
    public static function build_csv_for_request($request_id, $fallback_hash = '')
    {
        $request_id = absint($request_id);
        if (!$request_id) {
            return (string) $fallback_hash;
        }

        $csv = strtoupper(substr(hash_hmac('sha256', 'ep_sig_csv|' . $request_id, wp_salt('auth')), 0, 32));

        // Otros módulos pueden haber impreso ya un CSV propio en el documento
        // (por ejemplo el pie de las liquidaciones de gastos). En ese caso manda
        // el suyo, para que lo impreso y lo almacenado coincidan.
        return (string) apply_filters('ep_signature_csv_for_request', $csv, $request_id);
    }

    private function get_encryption_key()
    {
        $salt = defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'default_salt_for_encryption_ep_portal';
        return hash('sha256', $salt, true);
    }

    private function encrypt_file($file_path)
    {
        if (!file_exists($file_path))
            return false;
        $data = file_get_contents($file_path);

        // Don't encrypt if already encrypted (safety check) or if it doesn't look like a PDF
        if (mb_substr($data, 0, 5, '8bit') !== '%PDF-') {
            return true; // Already processed or invalid
        }

        $key = $this->get_encryption_key();
        $iv_len = openssl_cipher_iv_length('aes-256-ctr');
        $iv = openssl_random_pseudo_bytes($iv_len);

        // Use OPENSSL_RAW_DATA for reliable binary handling
        $encrypted = openssl_encrypt($data, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false)
            return false;

        return file_put_contents($file_path, 'ENC:' . $iv . $encrypted);
    }

    private function decrypt_content($content)
    {
        // Check if it's one of our encrypted files using 8-bit safe check
        if (mb_substr($content, 0, 4, '8bit') !== 'ENC:') {
            return $content; // Return as is (legacy or unencrypted)
        }

        $content = mb_substr($content, 4, null, '8bit'); // Strip prefix
        $key = $this->get_encryption_key();
        $iv_len = openssl_cipher_iv_length('aes-256-ctr');
        $iv = mb_substr($content, 0, $iv_len, '8bit');
        $encrypted = mb_substr($content, $iv_len, null, '8bit');

        return openssl_decrypt($encrypted, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
    }

    private function decrypt_file_content($file_path)
    {
        if (!file_exists($file_path))
            return false;
        $content = file_get_contents($file_path);
        return $this->decrypt_content($content);
    }

    private function decrypt_file_to_temp($file_path, $original_name)
    {
        $upload_dir = wp_upload_dir();
        $fds_dir = $upload_dir['basedir'] . '/fds-documents/';
        $content = $this->decrypt_file_content($file_path);
        if ($content !== false) {
            $temp_path = $fds_dir . 'tmp_' . bin2hex(random_bytes(4)) . '_' . sanitize_file_name($original_name);
            file_put_contents($temp_path, $content);
            return $temp_path;
        }
        return false;
    }

    public function delete_temp_file($file_path)
    {
        if (empty($file_path)) {
            return;
        }

        // Protección anti-path-traversal: verificar que el archivo esté dentro del directorio uploads
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath($upload_dir['basedir']);
        $real_path  = realpath($file_path);

        if ($real_path !== false && $base_dir !== false && strpos($real_path, $base_dir) === 0) {
            // SIG-04: Permitir la eliminación de archivos ZIP temporales (firma_...) además de los tmp_
            if (file_exists($real_path) && (strpos($real_path, 'tmp_') !== false || strpos($real_path, 'firma_') !== false || strpos($real_path, 'mail_tmp_') !== false)) {
                ep_error_log("EP_App_Signature_V4: Cleaning up temp file: $real_path");
                @unlink($real_path);
            }
        }
    }

    public function get_menu_label()
    {
        return 'Firma Electrónica';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=signature'">
            <div class="app-icon-container color-blue">
                <i class="fa-solid fa-file-signature"></i>
            </div>
            <h3>Firma Electrónica</h3>
            <p>Firma digital de documentos</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        // Enqueue required scripts and styles
        wp_enqueue_style('ep-signature-style', $this->base_url . 'assets/css/ep-signature.css', array(), '1.0.5');

        // AutoFirma / WebCrypto libs
        wp_enqueue_script('fds-autoscript', $this->base_url . 'libs/autoscript.js', array('jquery'), '1.0.4');
        wp_enqueue_script('pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.12.313/pdf.min.js', array(), '2.12.313');

        wp_enqueue_script('ep-signature-js', $this->base_url . 'assets/js/ep-signature.js', array('jquery', 'fds-autoscript'), '1.1.4');

        // Get permission level
        global $ep_app_manager;
        $permission = $ep_app_manager->get_user_permission($this->get_id());

        // Localize script
        wp_localize_script('ep-signature-js', 'ep_signature_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ep_signature_nonce'),
            'pdf_worker_src' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.12.313/pdf.worker.min.js',
            'user_info' => array(
                'is_logged_in' => is_user_logged_in(),
                'display_name' => wp_get_current_user()->display_name,
                'dni' => get_user_meta(get_current_user_id(), 'fds_user_dni', true),
                'can_manage' => ($permission === 'write'),
                'permission' => $permission
            ),
            'text' => array(
                'loading' => 'Cargando...',
                'signing' => 'Firmando documento...',
                'success' => 'Documento firmado con éxito'
            )
        ));

        // Check for verification view
        if (isset($_GET['sub_action']) && $_GET['sub_action'] === 'verify' && isset($_GET['csv'])) {
            $this->render_verification_view(sanitize_text_field($_GET['csv']));
            return;
        }

        if (function_exists('ep_stats_log')) {
            ep_stats_log('signature', 'app_viewed', get_current_user_id());
        }

        include $this->base_path . 'views/signature-view.php';
    }

    private function render_verification_view($csv)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE csv_documento = %s", $csv));

        include $this->base_path . 'views/verification-view.php';
    }

    public function handle_ajax()
    {
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        $action = isset($_REQUEST['sub_action']) ? sanitize_text_field($_REQUEST['sub_action']) : '';

        if (!wp_verify_nonce($nonce, 'ep_signature_nonce')) {
            wp_send_json_error(['message' => 'Acceso no autorizado (Nonce)']);
        }

        switch ($action) {
            case 'serve_doc':
                $this->handle_serve_doc();
                break;
            case 'prepare_pdf':
                $this->handle_prepare_pdf();
                break;
            case 'save_signed_pdf':
                $this->handle_save_signed_pdf();
                break;
            case 'get_my_docs':
                $this->handle_get_my_docs();
                break;
            case 'get_admin_docs':
                $this->handle_get_admin_docs();
                break;
            case 'delete_doc':
                $this->handle_delete_doc();
                break;
            case 'bulk_delete':
                $this->handle_bulk_delete();
                break;
            case 'generate_zip':
                $this->handle_generate_zip();
                break;
            case 'send_by_email':
                $this->handle_send_by_email();
                break;
            case 'get_users':
                $this->handle_get_users();
                break;
            case 'request_signature':
                $this->handle_request_signature();
                break;
            case 'get_inbox':
                $this->handle_get_inbox();
                break;
            case 'get_sent_requests':
                $this->handle_get_sent_requests();
                break;
            case 'save_user_dni':
                $this->handle_save_user_dni();
                break;
            default:
                wp_send_json_error(['message' => 'Acción no válida']);
        }
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intent_bot($intents)
    {
        $intents['SIGNATURE'] = "El usuario quiere saber si tiene documentos pendientes de firmar o validar. Ej: 'tengo algo que firmar', 'firmas pendientes'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        return $this->tarjeta_firmas_pendientes($user_id, $bot_instance);
    }

    private function tarjeta_firmas_pendientes(int $user_id, $bot_instance): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';

        // Buscar documentos pendientes
        $pendientes = $wpdb->get_results($wpdb->prepare(
            "SELECT nombre_archivo_original, fecha_firma FROM $table WHERE usuario_id = %d AND estado = 'pendiente' ORDER BY fecha_firma DESC LIMIT 5",
            $user_id
        ));

        if (empty($pendientes)) {
            return $bot_instance->tarjeta_simple('✍️ Firma Electrónica', "¡Todo al día! No tienes ningún documento pendiente de firma.", home_url('/?view=signature'));
        }

        $facts = [];
        foreach ($pendientes as $doc) {
            $facts[] = ['title' => '📄', 'value' => $doc->nombre_archivo_original];
        }

        return $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "✍️ Documentos pendientes", 'weight' => 'Bolder', 'color' => 'Accent', 'size' => 'Medium'],
            ['type' => 'TextBlock', 'text' => "Tienes **" . count($pendientes) . "** documento(s) esperando tu firma:"],
            ['type' => 'FactSet', 'facts' => $facts]
        ], [
            ['type' => 'Action.OpenUrl', 'title' => 'Firmar Ahora', 'url' => home_url('/?view=signature&teams=true')]
        ]);
    }


    private function handle_delete_doc()
    {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'ID no válido']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if ($doc) {
            global $ep_app_manager;
            $current_user_id = get_current_user_id();
            $can_manage = $ep_app_manager->get_user_permission($this->get_id()) === 'write';
            $is_owner = (int) $doc->usuario_id === $current_user_id;
            $is_solicitor = (int) $doc->solicitante_id === $current_user_id;

            if (!$can_manage && !$is_owner && !$is_solicitor) {
                wp_send_json_error(['message' => 'No tienes permiso para eliminar este documento']);
            }
            // Delete file
            if (file_exists($doc->ruta_documento_firmado)) {
                unlink($doc->ruta_documento_firmado);
            }
            if (function_exists('ep_stats_log')) {
                ep_stats_log('signature', 'document_deleted', get_current_user_id(), ['doc_id' => $id, 'filename' => $doc->nombre_archivo_original]);
            }
            // Delete DB record
            $wpdb->delete($table, ['id' => $id]);
            wp_send_json_success(['message' => 'Documento eliminado']);
        } else {
            wp_send_json_error(['message' => 'Documento no encontrado']);
        }
    }

    public function handle_prepare_pdf()
    {
        ep_error_log('EP_App_Signature_V4: [DEBUG] [1] Inidando handle_prepare_pdf');
        try {
            global $ep_app_manager;
            $target_user_type = isset($_POST['target_user_type']) ? sanitize_text_field($_POST['target_user_type']) : 'me';
            
            if ($ep_app_manager->get_user_permission($this->get_id()) === 'read' && $target_user_type === 'me') {
                wp_send_json_error(['message' => 'No tienes permiso para firmar documentos propios. Solo puedes enviar documentos a otros.']);
            }

            $pdf_content = null;
            if (isset($_FILES['original_pdf']) && !empty($_FILES['original_pdf']['tmp_name'])) {
                $pdf_content = file_get_contents($_FILES['original_pdf']['tmp_name']);
            } elseif (isset($_POST['original_pdf_base64'])) {
                $pdf_content = base64_decode($_POST['original_pdf_base64']);
            }

            if (!empty($pdf_content)) {
                $pdf_content = $this->decrypt_content($pdf_content);
            }

            if (empty($pdf_content)) {
                wp_send_json_error(['message' => 'Faltan datos del PDF.']);
            }

            $visible_signature_type = isset($_POST['visible_signature_type']) ? sanitize_text_field($_POST['visible_signature_type']) : 'none';
            $pdf_hash_original = isset($_POST['pdf_hash_original']) ? sanitize_text_field($_POST['pdf_hash_original']) : null;
            $stamp_footer = isset($_POST['stamp_footer']) ? $_POST['stamp_footer'] === '1' : true;

            // Liquidaciones de gastos: el empleado firma sin CSV/QR (es una presentación),
            // y el documento de abono ya se genera con su propio pie desde la app
            // de gastos, así que aquí nunca hay que volver a estamparlo.
            $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
            if ($request_id) {
                global $wpdb;
                $liq_table = $wpdb->prefix . 'ep_liquidations';
                $has_liq_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $liq_table)) === $liq_table;
                if ($has_liq_table) {
                    $is_employee_sig = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $liq_table WHERE signature_request_id = %d",
                        $request_id
                    ));
                    if ($is_employee_sig > 0) {
                        $stamp_footer = false;
                    }

                    // El documento de abono ya se genera con su pie de sede
                    // electrónica (CSV y QR) desde la app de gastos: no se puede
                    // volver a estampar aquí sin duplicarlo.
                    $is_admin_sig = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $liq_table WHERE admin_signature_request_id = %d",
                        $request_id
                    ));
                    if ($is_admin_sig > 0) {
                        $stamp_footer = false;
                    }
                }

                // Lo mismo para los tickets individuales de gasto.
                $exp_table = $wpdb->prefix . 'ep_expenses';
                $has_exp_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $exp_table)) === $exp_table;
                if ($has_exp_table) {
                    $is_expense_sig = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $exp_table
                         WHERE signature_request_id = %d OR admin_signature_request_id = %d",
                        $request_id,
                        $request_id
                    ));
                    if ($is_expense_sig > 0) {
                        $stamp_footer = false;
                    }
                }
            }

            // Código Seguro de Verificación: estable entre la preparación y el guardado,
            // derivado en servidor. Antes el QR llevaba el hash previo a la firma y en
            // base de datos se guardaba el posterior, así que el enlace nunca resolvía.
            $csv_code = self::build_csv_for_request($request_id, $pdf_hash_original);

            $stamps_json = isset($_POST['stamps']) ? wp_unslash($_POST['stamps']) : null;
            $stamps = [];

            if ($stamps_json) {
                $stamps = json_decode($stamps_json, true);
            }

            // Fallback for single signature if stamps is empty (v2.2 style)
            if (empty($stamps) && $visible_signature_type !== 'none') {
                $stamps[] = [
                    'type' => $visible_signature_type,
                    'page' => isset($_POST['visible_signature_page']) ? absint($_POST['visible_signature_page']) : 1,
                    'x_ratio' => isset($_POST['visible_signature_x_ratio']) ? floatval($_POST['visible_signature_x_ratio']) : (isset($_POST['visible_signature_x']) ? floatval($_POST['visible_signature_x']) / (isset($_POST['pdf_canvas_width']) ? floatval($_POST['pdf_canvas_width']) : 1) : 0),
                    'y_ratio' => isset($_POST['visible_signature_y_ratio']) ? floatval($_POST['visible_signature_y_ratio']) : (isset($_POST['visible_signature_y']) ? floatval($_POST['visible_signature_y']) / (isset($_POST['pdf_canvas_height']) ? floatval($_POST['pdf_canvas_height']) : 1) : 0),
                    'data' => isset($_POST['visible_signature_data']) ? $_POST['visible_signature_data'] : null
                ];
            }

            // === CARGA ROBUSTA DE LIBRERÍAS PDF ===
            // Paso 1: TCPDF
            if (!class_exists('TCPDF', false)) {
                $tcpdf_path = $this->libs_path . 'tcpdf/tcpdf.php';
                if (file_exists($tcpdf_path)) {
                    require_once $tcpdf_path;
                } else {
                    wp_send_json_error(['message' => 'Error: No se encontró la librería TCPDF en: ' . $tcpdf_path]);
                    return;
                }
            }

            // Paso 2: FPDI Autoloader
            if (!class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi', false)) {
                $fpdi_autoload = $this->libs_path . 'fpdi/src/autoload.php';
                if (file_exists($fpdi_autoload)) {
                    require_once $fpdi_autoload;
                } else {
                    wp_send_json_error(['message' => 'Error: No se encontró el autoloader de FPDI en: ' . $fpdi_autoload]);
                    return;
                }
            }

            // Paso 3: Definir EP_Fpdi_V4 ahora que las libs están cargadas
            ep_signature_define_fpdi_class();

            // Verificar que la clase quedó definida
            if (!class_exists('EP_Fpdi_V4')) {
                wp_send_json_error(['message' => 'Error: No se pudo definir la clase EP_Fpdi_V4. Verifique que TCPDF y FPDI se cargaron correctamente.']);
                return;
            }

            // Paso 4: PDF-Parser (Essential for signed PDFs / incremental updates)
            $parser_autoload = $this->libs_path . 'pdf-mod/src/autoload.php';
            if (!file_exists($parser_autoload)) {
                $wp_uploads = wp_upload_dir();
                $parser_autoload = $wp_uploads['basedir'] . '/pdf-mod/src/autoload.php';
            }
            if (file_exists($parser_autoload)) {
                require_once $parser_autoload;
                ep_error_log('EP_App_Signature_V4: [LIBS] PDF-Parser loaded.');
            }

            // Paso 5: QR Code
            if (!class_exists('QRcode', false)) {
                if (file_exists($this->libs_path . 'phpqrcode/qrlib.php')) {
                    require_once $this->libs_path . 'phpqrcode/qrlib.php';
                }
            }

            ep_error_log('EP_App_Signature_V4: [LIBS] Todas las librerías cargadas. EP_Fpdi_V4 disponible.');

            // --- DETECCIÓN DE FIRMAS DIGITALES EXISTENTES ---
            // Buscamos si el PDF ya está firmado criptográficamente para no dañar las firmas anteriores.
            $is_already_signed = (
                strpos($pdf_content, '/Type /Sig') !== false || 
                strpos($pdf_content, '/Type/Sig') !== false ||
                strpos($pdf_content, '/ByteRange') !== false
            );

            if ($is_already_signed) {
                ep_error_log('EP_App_Signature_V4: [INFO] El PDF ya contiene firmas criptográficas previas. Saltando la preparación en el servidor para conservar las firmas anteriores.');
                wp_send_json_success([
                    'pdf_data_to_sign_base64' => base64_encode($pdf_content),
                    'message' => 'El documento ya está firmado digitalmente. Se firmará de forma incremental para conservar las firmas anteriores.',
                    'skipped_prep' => true
                ]);
                return;
            }

            $pdf = new EP_Fpdi_V4();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false);

            $stream = \setasign\Fpdi\PdfParser\StreamReader::createByString($pdf_content);
            try {
                $page_count = $pdf->setSourceFile($stream);
            } catch (\Throwable $e) {
                ep_error_log('EP_App_Signature_V4: [WARN] Fallo en apertura de PDF (¿protegido o ya firmado?): ' . $e->getMessage());
                // Si el PDF está encriptado, protegido o ya firmado, permitimos firmar el original como fallback
                wp_send_json_success([
                    'pdf_data_to_sign_base64' => base64_encode($pdf_content),
                    'message' => 'Documento protegido o ya firmado: se firmará el archivo original conservando firmas anteriores.',
                    'skipped_prep' => true
                ]);
                return;
            }

            $qr_img = null;
            if ($csv_code && class_exists('QRcode')) {
                $verify_url = home_url('?view=signature&sub_action=verify&csv=' . $csv_code);
                ob_start();
                \QRcode::png($verify_url, null, QR_ECLEVEL_L, 3, 1);
                $qr_img = ob_get_clean();
            }

            for ($i = 1; $i <= $page_count; $i++) {
                $template_id = $pdf->importPage($i, \setasign\Fpdi\PdfReader\PageBoundaries::CROP_BOX, true);
                $size = $pdf->getTemplateSize($template_id);
                
                // Determinamos orientación y añadimos página con dimensiones originales
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                
                // --- ESCALADO AL 90% PARA EL PIE ---
                $scale = 0.90;
                $new_width = $size['width'] * $scale;
                $new_height = $size['height'] * $scale;
                $offset_x = ($size['width'] - $new_width) / 2;
                $offset_y = 4; // Un pequeño margen superior
                
                // Dibujamos la página original escalada
                $pdf->useTemplate($template_id, $offset_x, $offset_y, $new_width, $new_height);
                
                // --- PIE DE PÁGINA PROFESIONAL (ESTILO SEDE ELECTRÓNICA) ---
                if ($qr_img && $stamp_footer) {
                    $footer_base_y = $size['height'] - 20;
                    $footer_margin = 10;
                    $table_width = $size['width'] - ($footer_margin * 2) - 25; // Espacio para el QR
                    
                    // Línea decorativa superior
                    $pdf->SetDrawColor(200, 200, 200);
                    $pdf->SetLineWidth(0.1);
                    $pdf->Line($footer_margin, $footer_base_y - 2, $size['width'] - $footer_margin, $footer_base_y - 2);

                    // Información del Firmante y Verificación
                    $pdf->SetFont('helvetica', '', 6);
                    $pdf->SetTextColor(80, 80, 80);
                    
                    $current_user = wp_get_current_user();
                    $fecha_hora = date_i18n('d/m/Y H:i:s');
                    
                    // Columna 1: Firmado por y Fecha
                    $pdf->SetFont('helvetica', 'B', 6);
                    $pdf->Text($footer_margin, $footer_base_y, "FIRMADO POR:");
                    $pdf->SetFont('helvetica', '', 6);
                    $pdf->Text($footer_margin + 18, $footer_base_y, $current_user->display_name);
                    
                    $pdf->SetFont('helvetica', 'B', 6);
                    $pdf->Text($footer_margin, $footer_base_y + 3, "FECHA Y HORA:");
                    $pdf->SetFont('helvetica', '', 6);
                    $pdf->Text($footer_margin + 18, $footer_base_y + 3, $fecha_hora);

                    // Columna 2: CSV y Verificación
                    $col2_x = $footer_margin + ($table_width / 2);
                    $pdf->SetFont('helvetica', 'B', 6);
                    $pdf->Text($col2_x, $footer_base_y, "CSV:");
                    $pdf->SetFont('helvetica', '', 5); // Fuente más pequeña para el hash largo
                    $pdf->Text($col2_x + 8, $footer_base_y, $csv_code);

                    // Código QR a la derecha
                    $pdf->Image('@' . $qr_img, $size['width'] - $footer_margin - 12, $footer_base_y - 1, 12, 12, 'PNG');
                    
                    // Texto Vertical Lateral (Copia Electrónica)
                    $pdf->SetTextColor(150, 150, 150);
                    $pdf->SetFont('helvetica', 'B', 6);
                    $pdf->StartTransform();
                    $pdf->Rotate(90, $size['width'] - 5, $size['height'] / 2);
                    $pdf->Text($size['width'] - 5, $size['height'] / 2, "COPIA ELECTRÓNICA AUTÉNTICA");
                    $pdf->StopTransform();
                }

                // --- APLANADO VISUAL (Reactivado con fusión de recursos para evitar Acrobat Error 18) ---
                $annots = $pdf->getPageAnnots($i);
                if ($annots) {
                    foreach ($annots->value as $index => $annot) {
                        $annotData = $pdf->importAnnotAppearance($annot, $i);
                        if ($annotData && $annotData['w'] > 0.1 && $annotData['h'] > 0.1) {
                            // Aplicamos el mismo escalado y offset a las firmas previas para que coincidan
                            $pdf->useTemplate(
                                $annotData['id'], 
                                ($annotData['x'] * $scale) + $offset_x, 
                                (($size['height'] - $annotData['y']) * $scale) + $offset_y, 
                                $annotData['w'] * $scale, 
                                $annotData['h'] * $scale
                            );
                        }
                    }
                }

                // --- NUEVAS ESTAMPAS (Rúbricas colocadas ahora) ---
                foreach ($stamps as $stamp) {
                    if ($stamp['page'] == $i && $stamp['type'] !== 'none') {
                        // Coordenadas relativas escaladas
                        $x_mm = (floatval($stamp['x_ratio'] ?? 0) * $new_width) + $offset_x;
                        $y_mm = (floatval($stamp['y_ratio'] ?? 0) * $new_height) + $offset_y;

                        if ($stamp['type'] === 'image' && !empty($stamp['data'])) {
                            if (preg_match('/^data:image\/(png|jpeg|jpg);base64,(.*)/i', (string)$stamp['data'], $matches)) {
                                $img_data = base64_decode($matches[2]);
                                if (!empty($img_data)) {
                                    // Ajustamos el tamaño de la rúbrica (60mm de ancho base, escalado)
                                    $pdf->Image('@' . $img_data, $x_mm - (30 * $scale), $y_mm - (15 * $scale), 60 * $scale, 0);
                                }
                            }
                        } elseif ($stamp['type'] === 'text' && !empty($stamp['data'])) {
                            $text_info = json_decode($stamp['data'], true);
                            if ($text_info) {
                                $pdf->SetFont('helvetica', 'B', 8 * $scale);
                                $pdf->SetTextColor(0, 0, 0);
                                $pdf->SetFillColor(245, 245, 245);
                                $pdf->SetDrawColor(0, 123, 255);
                                $pdf->SetLineWidth(0.3 * $scale);
                                
                                $txt = "Firmado por:\n" . ($text_info['name'] ?? '---') . "\nDNI/CIF: " . ($text_info['dni'] ?? '---');
                                $pdf->MultiCell(50 * $scale, 15 * $scale, $txt, 1, 'L', true, 1, $x_mm - (25 * $scale), $y_mm - (7.5 * $scale), true);
                            }
                        } elseif ($stamp['type'] === 'details' && !empty($stamp['data'])) {
                            $text_info = json_decode($stamp['data'], true);
                            if ($text_info) {
                                $pdf->SetFont('helvetica', '', 5.5 * $scale);
                                $pdf->SetTextColor(0, 0, 0);
                                $pdf->SetFillColor(245, 245, 245);
                                $pdf->SetDrawColor(0, 123, 255);
                                $pdf->SetLineWidth(0.3 * $scale);
                                
                                $fecha_hora = date_i18n('d/m/Y H:i:s');
                                $name = $text_info['name'] ?? '---';
                                $dni = $text_info['dni'] ?? '---';
                                $dnText = "DN: cn=" . $name . ", serialNumber=IDCES-" . $dni . ", o=Cámara Oficial de Comercio, c=ES";
                                $txt = "Firmado digitalmente por:\n" . $name . "\nDNI/CIF: " . $dni . "\n" . $dnText . "\nFecha: " . $fecha_hora . "\nPortal del Empleado - Cámara de Cáceres";
                                
                                $logo_b64 = isset($text_info['logo']) ? $text_info['logo'] : null;
                                if ($logo_b64 && preg_match('/^data:image\/(png|jpeg|jpg);base64,(.*)/i', $logo_b64, $logo_matches)) {
                                    $logo_data = base64_decode($logo_matches[2]);
                                    if (!empty($logo_data)) {
                                        $box_x = $x_mm - (35 * $scale);
                                        $box_y = $y_mm - (12 * $scale);
                                        
                                        // Dibujamos el logo directamente (sin rectángulo de fondo ni bordes)
                                        $pdf->Image('@' . $logo_data, $box_x + (2 * $scale), $box_y + (3 * $scale), 13 * $scale, 18 * $scale, '', '', '', false, 300, '', false, false, 0, 'CM');
                                        
                                        // Dibujamos el texto al lado del logo (transparente, sin bordes)
                                        $pdf->MultiCell(51 * $scale, 21 * $scale, $txt, 0, 'L', false, 1, $box_x + (17 * $scale), $box_y + (1.5 * $scale), true);
                                    } else {
                                        $box_x = $x_mm - (35 * $scale);
                                        $box_y = $y_mm - (12 * $scale);
                                        $pdf->MultiCell(70 * $scale, 24 * $scale, $txt, 0, 'L', false, 1, $box_x, $box_y, true);
                                    }
                                } else {
                                    $box_x = $x_mm - (35 * $scale);
                                    $box_y = $y_mm - (12 * $scale);
                                    $pdf->MultiCell(70 * $scale, 24 * $scale, $txt, 0, 'L', false, 1, $box_x, $box_y, true);
                                }
                            }
                        }
                    }
                }
            }

            // Limpiamos cualquier salida previa para evitar corrupción
            if (ob_get_length()) ob_clean();

            $prep_pdf_data = $pdf->Output('', 'S');
            $upload_dir = wp_upload_dir();
            file_put_contents($upload_dir['basedir'] . '/fds-documents/prep_test.pdf', $prep_pdf_data);

            wp_send_json_success([
                'pdf_data_to_sign_base64' => base64_encode($prep_pdf_data),
                'message' => 'PDF preparado para la firma'
            ]);

        } catch (\Throwable $e) {
            ep_error_log('EP_App_Signature_V4 FATAL ERROR: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error crítico: ' . $e->getMessage()]);
        }
    }

    private function handle_save_signed_pdf()
    {
        try {
            // SIG-02: Forzar inicio de sesión antes de realizar cualquier acción de guardado
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Acceso denegado. Debe iniciar sesión.']);
            }

            $signature_b64 = isset($_POST['signature']) ? $_POST['signature'] : '';
            $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : 'documento_firmado.pdf';
            $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
            $cert_info_json = isset($_POST['cert_info']) ? wp_unslash($_POST['cert_info']) : null;
            $send_to_sender = isset($_POST['send_to_sender']) && $_POST['send_to_sender'] === '1';

            if (empty($signature_b64)) {
                wp_send_json_error(['message' => 'Faltan los datos de la firma.']);
            }

            global $wpdb, $ep_app_manager;
            $table_name = $wpdb->prefix . 'fds_documentos';
            $current_user_id = get_current_user_id();

            // --- FIX SIG-02: Control de acceso al guardar la firma ---
            if ($request_id) {
                $old_doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $request_id));
                if (!$old_doc) {
                    wp_send_json_error(['message' => 'Solicitud de firma no encontrada.']);
                }
                
                $can_manage = $ep_app_manager->get_user_permission($this->get_id()) === 'write';
                $is_recipient = (int) $old_doc->usuario_id === $current_user_id;

                if (!$can_manage && !$is_recipient) {
                    wp_send_json_error(['message' => 'No tienes permiso para completar esta solicitud de firma.']);
                }
            }
            // ---------------------------------------------------------

            // --- FIX SIG-03: Recalcular Hash en Servidor en base al PDF real firmado ---
            $decoded_pdf = base64_decode(trim($signature_b64));
            if (!$decoded_pdf) {
                wp_send_json_error(['message' => 'Datos de firma dañados o no válidos.']);
            }
            $server_hash = hash('sha256', $decoded_pdf);
            // ---------------------------------------------------------

            $signer_name = wp_get_current_user()->display_name;
            if ($cert_info_json) {
                $cert_details = json_decode($cert_info_json, true);
                if (isset($cert_details['certificateBase64'])) {
                    $pem_cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert_details['certificateBase64'], 64, "\n") . "-----END CERTIFICATE-----\n";
                    $parsed_cert = openssl_x509_parse($pem_cert);
                    if ($parsed_cert && isset($parsed_cert['subject']['CN'])) {
                        $signer_name = $parsed_cert['subject']['CN'];
                    }
                }
            }

            $upload_dir = wp_upload_dir();
            $fds_dir = $upload_dir['basedir'] . '/fds-documents/';
            if (!file_exists($fds_dir)) wp_mkdir_p($fds_dir);

            $final_filename = sanitize_file_name(wp_get_current_user()->user_nicename . '_' . date('Ymd_His') . '_' . $filename);
            $file_path = $fds_dir . $final_filename;

            if (file_put_contents($file_path, $decoded_pdf)) {
                $this->encrypt_file($file_path);

                $data = array(
                    'nombre_documento' => $final_filename,
                    'nombre_archivo_original' => $filename,
                    'ruta_documento_firmado' => $file_path,
                    'url_documento_firmado' => $upload_dir['baseurl'] . '/fds-documents/' . $final_filename,
                    'hash_documento_original' => $server_hash, // Hash real
                    'usuario_id' => $current_user_id,
                    'nombre_firmante' => $signer_name,
                    'certificado_info' => $cert_info_json,
                    'fecha_firma' => current_time('mysql'),
                    'estado' => 'firmado',
                    // Debe coincidir con el CSV impreso en el pie y codificado en el QR
                    'csv_documento' => self::build_csv_for_request($request_id, $server_hash)
                );

                if ($request_id) {
                    $wpdb->update($table_name, $data, ['id' => $request_id]);
                    $id_to_return = $request_id;

                    // Si se solicita enviar al remitente y hay un solicitante
                    if ($send_to_sender && $old_doc && !empty($old_doc->solicitante_id)) {
                        $this->send_signed_copy_to_requester($id_to_return, $old_doc->solicitante_id);

                        // SI VIENE DE EP-DOWNLOADS: Actualizar el estado del documento original
                        // Buscamos si hay un post vinculado
                        global $wpdb;
                        $external_post_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ep_signature_request_id' AND meta_value = %s",
                            $id_to_return
                        ));

                        if ($external_post_id) {
                            update_post_meta($external_post_id, '_ep_document_review_status', 'ok');
                            update_post_meta($external_post_id, '_ep_document_is_signed', '1');
                            update_post_meta($external_post_id, '_ep_signature_request_completed', '1');
                            
                            // Notificar al solicitante en el portal también
                            if (class_exists('EP_Notifications')) {
                                EP_Notifications::add_notification($old_doc->solicitante_id, [
                                    'type' => 'success',
                                    'title' => 'Documento Firmado',
                                    'message' => wp_get_current_user()->display_name . ' ha firmado el documento "' . $filename . '".',
                                    'link' => '?view=downloads'
                                ]);
                            }
                        }
                    }
                } else {
                    $wpdb->insert($table_name, $data);
                    $id_to_return = $wpdb->insert_id;
                }

                // Log to stats
                if (function_exists('ep_stats_log')) {
                    ep_stats_log('signature', 'document_signed', get_current_user_id(), [
                        'doc_id' => $id_to_return,
                        'filename' => $filename,
                        'signer' => $signer_name
                    ]);
                }

                wp_send_json_success([
                    'id' => $id_to_return,
                    'message' => 'Documento guardado y firmado correctamente.',
                    'download_url' => admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $id_to_return . '&nonce=' . wp_create_nonce('ep_signature_nonce') . '&t=' . time()
                ]);
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Error crítico al guardar: ' . $e->getMessage()]);
        }
    }

    private function send_signed_copy_to_requester($doc_id, $requester_id)
    {
        $requester = get_userdata($requester_id);
        if (!$requester) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $doc_id));
        if (!$doc || !file_exists($doc->ruta_documento_firmado)) return false;

        $temp_path = $this->decrypt_file_to_temp($doc->ruta_documento_firmado, $doc->nombre_archivo_original);
        if (!$temp_path) return false;

        $signer = wp_get_current_user();
        $to = $requester->user_email;
        $subject = 'Documento Firmado: ' . $doc->nombre_archivo_original;
        $body = "Hola " . $requester->display_name . ",\n\n" . $signer->display_name . " ha firmado el documento \"" . $doc->nombre_archivo_original . "\".\n\nAdjunto encontrarás la copia firmada.\n\nSaludos.";
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        $sent = wp_mail($to, $subject, $body, $headers, [$temp_path]);

        // Limpieza del temporal después de un tiempo
        wp_schedule_single_event(time() + 600, 'ep_cleanup_temp_file', [$temp_path]);

        return $sent;
    }

    private function handle_save_user_dni()
    {
        $user_id = get_current_user_id();
        $dni = isset($_POST['user_dni']) ? sanitize_text_field($_POST['user_dni']) : '';
        if ($user_id && !empty($dni)) {
            update_user_meta($user_id, 'fds_user_dni', $dni);
            wp_send_json_success(['message' => 'DNI guardado correctamente.']);
        } else {
            wp_send_json_error(['message' => 'Datos inválidos.']);
        }
    }

    public function handle_save_user_signature()
    {
        check_ajax_referer('ep_signature_nonce', 'nonce');
        $user_id = get_current_user_id();
        $signature_base64 = isset($_POST['signature_base64']) ? $_POST['signature_base64'] : '';

        if (empty($signature_base64)) {
            wp_send_json_error(['message' => 'No se ha proporcionado ninguna imagen.']);
        }

        update_user_meta($user_id, '_ep_signature_image', $signature_base64);
        wp_send_json_success(['message' => 'Firma guardada correctamente.']);
    }

    public function handle_get_user_signature()
    {
        check_ajax_referer('ep_signature_nonce', 'nonce');
        $user_id = get_current_user_id();
        $signature_base64 = get_user_meta($user_id, '_ep_signature_image', true);

        if (empty($signature_base64)) {
            wp_send_json_error(['message' => 'No hay una firma guardada.']);
        }

        wp_send_json_success(['signature_base64' => $signature_base64]);
    }

    public function handle_save_user_logo()
    {
        check_ajax_referer('ep_signature_nonce', 'nonce');
        $user_id = get_current_user_id();
        $logo_base64 = isset($_POST['logo_base64']) ? $_POST['logo_base64'] : '';

        if (empty($logo_base64)) {
            wp_send_json_error(['message' => 'No se ha proporcionado ninguna imagen para el logo.']);
        }

        update_user_meta($user_id, '_ep_signature_logo', $logo_base64);
        wp_send_json_success(['message' => 'Logo guardado correctamente.']);
    }

    public function handle_get_user_logo()
    {
        check_ajax_referer('ep_signature_nonce', 'nonce');
        $user_id = get_current_user_id();
        $logo_base64 = get_user_meta($user_id, '_ep_signature_logo', true);

        if (empty($logo_base64)) {
            wp_send_json_error(['message' => 'No hay un logo guardado.']);
        }

        wp_send_json_success(['logo_base64' => $logo_base64]);
    }

    private function handle_get_users()
    {
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $users = get_users([
            'search' => "*{$search}*",
            'search_columns' => ['user_login', 'user_nicename', 'user_email', 'display_name'],
            'number' => 10,
            'exclude' => [get_current_user_id()]
        ]);

        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email
            ];
        }

        wp_send_json_success($results);
    }

    private function handle_request_signature()
    {
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No se ha subido ningún archivo']);
        }

        $recipient_id = isset($_POST['recipient_id']) ? absint($_POST['recipient_id']) : 0;
        if (!$recipient_id) {
            wp_send_json_error(['message' => 'Debes seleccionar un destinatario']);
        }

        $file = $_FILES['file'];

        // Security: Validate PDF Extension and MIME Type Strict
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = isset($file['type']) ? $file['type'] : '';
        
        if ($ext !== 'pdf' || $mime !== 'application/pdf') {
            wp_send_json_error(['message' => 'Solo se permiten archivos PDF. Formato detectado no válido.']);
        }
        
        // Security: Deep check magic bytes
        $fh = fopen($file['tmp_name'], 'r');
        $magic_bytes = fread($fh, 5);
        fclose($fh);
        
        if ($magic_bytes !== "%PDF-") {
            wp_send_json_error(['message' => 'El archivo no parece ser un documento PDF legítimo.']);
        }

        $upload_dir = wp_upload_dir();
        $fds_dir = $upload_dir['basedir'] . '/fds-documents/';
        if (!file_exists($fds_dir)) {
            wp_mkdir_p($fds_dir);
        }

        $filename = time() . '_' . sanitize_file_name($file['name']);
        $destination = $fds_dir . $filename;

        // Calcular el hash ANTES de la encriptación
        $pdf_hash = hash_file('sha256', $file['tmp_name']);

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $this->encrypt_file($destination);
            global $wpdb;
            $table = $wpdb->prefix . 'fds_documentos';
            $wpdb->insert($table, [
                'nombre_documento' => $filename,
                'nombre_archivo_original' => sanitize_text_field($file['name']),
                'ruta_documento_firmado' => $destination,
                'url_documento_firmado' => $upload_dir['baseurl'] . '/fds-documents/' . $filename,
                'usuario_id' => $recipient_id,
                'solicitante_id' => get_current_user_id(),
                'hash_documento_original' => $pdf_hash,
                'observaciones' => !empty($_POST['instructions']) ? wp_kses_post($_POST['instructions']) : '',
                'estado' => 'pendiente',
                'fecha_firma' => current_time('mysql'),
                'csv_documento' => $pdf_hash
            ]);

            $request_id = $wpdb->insert_id;

            // Log to stats
            if (function_exists('ep_stats_log')) {
                ep_stats_log('signature', 'signature_requested', get_current_user_id(), [
                    'request_id' => $request_id,
                    'filename' => $file['name'],
                    'target_user_id' => $recipient_id
                ]);
            }

            // Add Portal Notification
            if (class_exists('EP_Notifications')) {
                EP_Notifications::add_notification($recipient_id, [
                    'type' => 'warning',
                    'title' => 'Nueva solicitud de firma',
                    'message' => wp_get_current_user()->display_name . ' te ha enviado el documento "' . sanitize_text_field($file['name']) . '" para firmar.',
                    'link' => '?view=signature'
                ]);
            }

            wp_send_json_success(['message' => 'Solicitud enviada correctamente']);
        } else {
            wp_send_json_error(['message' => 'Error al mover el archivo subido']);
        }
    }

    private function handle_serve_doc()
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$id) wp_die('ID no válido');

        check_ajax_referer('ep_signature_nonce', 'nonce');

        // SIG-01: Control estricto de sesión
        if (!is_user_logged_in()) {
            wp_die('Acceso denegado. Debe iniciar sesión.');
        }

        global $wpdb, $ep_app_manager;
        $table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if (!$doc) wp_die('Documento no encontrado');

        // --- FIX SIG-01: Control estricto de acceso al PDF ---
        $current_user_id = get_current_user_id();
        $can_manage = $ep_app_manager->get_user_permission($this->get_id()) === 'write';
        $is_owner = (int) $doc->usuario_id === $current_user_id;
        $is_solicitor = (int) $doc->solicitante_id === $current_user_id;

        if (!$can_manage && !$is_owner && !$is_solicitor) {
            wp_die('Acceso denegado: No tienes permisos para visualizar o descargar este documento.');
        }
        // -----------------------------------------------------

        $file_to_serve = '';
        if (!empty($doc->ruta_documento_firmado) && file_exists($doc->ruta_documento_firmado)) {
            $file_to_serve = $doc->ruta_documento_firmado;
        } else {
            $upload_dir = wp_upload_dir();
            $fds_dir = $upload_dir['basedir'] . '/fds-documents/';
            $original_path = $fds_dir . $doc->nombre_documento;
            if (file_exists($original_path)) {
                $file_to_serve = $original_path;
            }
        }

        if ($file_to_serve) {
            // Start output buffering to capture any warnings/notices/BOM/whitespace
            ob_start();

            $content = $this->decrypt_file_content($file_to_serve);
            if ($content === false) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                wp_die('Error al desencriptar');
            }

            // Neutralizar cualquier orden de auto-impresión o acción automática incrustada en el PDF
            // Preservamos el tamaño de bytes exacto para evitar dañar la tabla de offsets (xref) del PDF
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

            // Log to stats before we clean buffers and send headers
            if (function_exists('ep_stats_log')) {
                ep_stats_log('signature', 'document_download', get_current_user_id(), [
                    'doc_id' => $id,
                    'filename' => $doc->nombre_archivo_original
                ]);
            }

            // Clean all active output buffers to discard any warnings/notices/BOM/whitespace
            while (ob_get_level()) {
                ob_end_clean();
            }

            $safe_filename = sanitize_file_name($doc->nombre_archivo_original);
            if (empty($safe_filename) || strpos($safe_filename, '.') === false) {
                $safe_filename = 'documento.pdf';
            }

            // Con 'inline' el documento se puede previsualizar dentro del portal
            // (visor en modal). Sin el parámetro se mantiene la descarga de siempre.
            $disposition = (isset($_GET['inline']) && $_GET['inline'] === '1') ? 'inline' : 'attachment';

            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . $disposition . '; filename="' . $safe_filename . '"; filename*=UTF-8\'\'' . rawurlencode($doc->nombre_archivo_original));
            header('Content-Length: ' . mb_strlen($content, '8bit'));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            echo $content;
            exit;
        } else {
            wp_die('Archivo no encontrado');
        }
    }

    private function handle_get_admin_docs()
    {
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission($this->get_id()) !== 'write') {
            wp_die('No permitido');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY fecha_firma DESC");

        if (!$results) {
            echo '<p class="no-docs">No hay documentos registrados.</p>';
        } else {
            echo '<div class="fds-bulk-actions">
                <button class="ep-btn ep-btn-mini ep-btn-danger bulk-delete" data-target="admin-docs"><i class="fa-solid fa-trash"></i> Borrar Seleccionados</button>
                <button class="ep-btn ep-btn-mini ep-btn-secondary bulk-email" data-target="admin-docs"><i class="fa-solid fa-envelope"></i> Enviar Seleccionados</button>
            </div>';
            echo '<table class="ep-table" id="fds-admin-docs-table">';
            echo '<thead><tr><th><input type="checkbox" class="select-all"></th><th>Firmante</th><th>Documento</th><th>Fecha</th><th>Acciones</th></tr></thead>';
            echo '<tbody>';
            foreach ($results as $doc) {
                $download_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce') . '&t=' . time();
                echo '<tr>';
                echo '<td><input type="checkbox" class="doc-checkbox" value="' . $doc->id . '"></td>';
                echo '<td>' . esc_html($doc->nombre_firmante ?: 'Pendiente') . '</td>';
                echo '<td>' . esc_html($doc->nombre_archivo_original) . '</td>';
                echo '<td>' . date_i18n('d/m/Y H:i', strtotime($doc->fecha_firma)) . '</td>';
                echo '<td>
                    <a href="' . esc_url($download_url) . '" target="_blank" class="ep-btn ep-btn-mini" title="Descargar"><i class="fa-solid fa-download"></i></a>
                    <button class="ep-btn ep-btn-mini ep-btn-secondary send-email" data-id="' . $doc->id . '" title="Enviar por Email"><i class="fa-solid fa-envelope"></i></button>
                    <button class="ep-btn ep-btn-mini ep-btn-danger delete-doc" data-id="' . $doc->id . '" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        exit;
    }

    private function handle_get_inbox()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $user_id = get_current_user_id();

        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, u.display_name as solicitante_name 
             FROM $table d 
             LEFT JOIN {$wpdb->users} u ON d.solicitante_id = u.ID 
             WHERE d.usuario_id = %d AND d.solicitante_id IS NOT NULL AND d.estado = 'pendiente'
             ORDER BY d.fecha_firma DESC",
            $user_id
        ));

        global $ep_app_manager;
        $permission = $ep_app_manager->get_user_permission($this->get_id());

        include $this->base_path . 'views/inbox-list-view.php';
        die();
    }

    private function handle_get_my_docs()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $user_id = get_current_user_id();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, u_signer.display_name as signer_name, u_solicitor.display_name as solicitor_name 
             FROM $table d 
             LEFT JOIN {$wpdb->users} u_signer ON d.usuario_id = u_signer.ID 
             LEFT JOIN {$wpdb->users} u_solicitor ON d.solicitante_id = u_solicitor.ID 
             WHERE (d.usuario_id = %d OR d.solicitante_id = %d) AND d.estado = 'firmado' 
             ORDER BY d.fecha_firma DESC",
            $user_id,
            $user_id
        ));

        if (!$results) {
            echo '<p class="no-docs">No tienes documentos firmados.</p>';
        } else {
            echo '<div class="fds-bulk-actions">
                <button class="ep-btn ep-btn-mini ep-btn-danger bulk-delete" data-target="my-docs"><i class="fa-solid fa-trash"></i> Borrar Seleccionados</button>
                <button class="ep-btn ep-btn-mini ep-btn-secondary bulk-email" data-target="my-docs"><i class="fa-solid fa-envelope"></i> Enviar Seleccionados</button>
            </div>';
            echo '<div class="ep-table-responsive">';
            echo '<table class="ep-table" id="fds-my-docs-table">';
            echo '<thead><tr><th><input type="checkbox" class="select-all"></th><th>Documento</th><th>Participante</th><th>Fecha</th><th>Acciones</th></tr></thead>';
            echo '<tbody>';
            foreach ($results as $doc) {
                $is_signer = (int) $doc->usuario_id === $user_id;
                $participant = $is_signer ?
                    ('<i class="fa-solid fa-file-export"></i> Para: ' . esc_html($doc->solicitor_name)) :
                    ('<i class="fa-solid fa-file-import"></i> De: ' . esc_html($doc->signer_name));

                if (!$doc->solicitante_id)
                    $participant = '<i class="fa-solid fa-user"></i> Personal';

                $download_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce') . '&t=' . time();
                echo '<tr>';
                echo '<td><input type="checkbox" class="doc-checkbox" value="' . $doc->id . '"></td>';
                echo '<td>' . esc_html($doc->nombre_archivo_original) . '</td>';
                echo '<td>' . $participant . '</td>';
                echo '<td>' . date_i18n('d/m/Y H:i', strtotime($doc->fecha_firma)) . '</td>';
                echo '<td>
                    <a href="' . esc_url($download_url) . '" target="_blank" class="ep-btn ep-btn-mini" title="Descargar"><i class="fa-solid fa-download"></i></a>
                    <button class="ep-btn ep-btn-mini ep-btn-secondary send-email" data-id="' . $doc->id . '" title="Enviar por Email"><i class="fa-solid fa-envelope"></i></button>
                    <button class="ep-btn ep-btn-mini ep-btn-danger delete-doc" data-id="' . $doc->id . '" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        exit;
    }

    private function handle_bulk_delete()
    {
        global $ep_app_manager;
        if ($ep_app_manager->get_user_permission($this->get_id()) !== 'write') {
            $user_id = get_current_user_id();
        } else {
            $user_id = null;
        }

        $ids = (!empty($_POST['ids']) && is_array($_POST['ids'])) ? array_map('absint', $_POST['ids']) : [];
        if (empty($ids)) {
            wp_send_json_error(['message' => 'No hay documentos seleccionados']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $deleted_count = 0;

        foreach ($ids as $id) {
            if ($user_id) {
                $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND (usuario_id = %d OR solicitante_id = %d)", $id, $user_id, $user_id));
            } else {
                $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
            }

            if ($doc) {
                if (file_exists($doc->ruta_documento_firmado)) {
                    unlink($doc->ruta_documento_firmado);
                }
                $wpdb->delete($table, ['id' => $id]);
                if (function_exists('ep_stats_log')) {
                    ep_stats_log('signature', 'document_deleted_bulk', get_current_user_id(), ['doc_id' => $id, 'filename' => $doc->nombre_archivo_original]);
                }
                $deleted_count++;
            }
        }

        wp_send_json_success(['message' => "$deleted_count documentos eliminados correctamente."]);
    }

    private function handle_generate_zip()
    {
        $urls = isset($_POST['urls']) ? $_POST['urls'] : [];
        if (empty($urls)) {
            wp_send_json_error(['message' => 'No hay archivos para comprimir']);
        }

        $user_id = get_current_user_id();
        global $ep_app_manager;
        $is_admin = $ep_app_manager->get_user_permission($this->get_id()) === 'write';

        $upload_dir = wp_upload_dir();
        $fds_dir = $upload_dir['basedir'] . '/fds-documents/';
        $zip_name = 'firma_' . $user_id . '_' . date('Ymd_His') . '.zip';
        $zip_path = $fds_dir . $zip_name;

        if (!class_exists('ZipArchive')) {
            wp_send_json_error(['message' => 'Librería ZipArchive no disponible en el servidor']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $valid_files = [];

        foreach ($urls as $url) {
            $doc = null;
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (isset($params['id'])) {
                    $doc_id = absint($params['id']);
                    $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $doc_id));
                }
            }

            if (!$doc) {
                $filename = basename($url);
                $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE nombre_documento = %s", $filename));
            }

            if ($doc) {
                $is_authorized = $is_admin || (int) $doc->usuario_id === $user_id || (int) $doc->solicitante_id === $user_id;

                if ($is_authorized) {
                    $file_path = $fds_dir . $doc->nombre_documento;
                    if (file_exists($file_path)) {
                        $valid_files[] = ['path' => $file_path, 'name' => $doc->nombre_archivo_original];
                    }
                }
            }
        }

        if (empty($valid_files)) {
            wp_send_json_error(['message' => 'No tienes permiso sobre ninguno de los archivos seleccionados']);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
            wp_send_json_error(['message' => 'No se pudo crear el archivo ZIP']);
        }

        $temp_pdfs_for_zip = [];
        foreach ($valid_files as $file) {
            $content = $this->decrypt_file_content($file['path']);
            if ($content !== false) {
                $tmp_pdf = $fds_dir . 'tmp_z_' . bin2hex(random_bytes(4)) . '.pdf';
                file_put_contents($tmp_pdf, $content);
                $zip->addFile($tmp_pdf, $file['name']);
                $temp_pdfs_for_zip[] = $tmp_pdf;
            }
        }
        $zip->close();
        
        // Limpiar temporales
        if (!empty($temp_pdfs_for_zip)) {
            foreach ($temp_pdfs_for_zip as $tp) { @unlink($tp); }
        }

        clearstatcache(true, $zip_path);

        // --- FIX SIG-04: Autolimpieza de archivo ZIP (eliminación retardada a los 10 minutos) ---
        wp_schedule_single_event(time() + 600, 'ep_cleanup_temp_file', [$zip_path]);
        // ----------------------------------------------------------------------------------------

        wp_send_json_success(['url' => $upload_dir['baseurl'] . '/fds-documents/' . $zip_name]);
    }

    private function handle_send_by_email()
    {
        try {
            @set_time_limit(120);
            $ids = (!empty($_POST['ids']) && is_array($_POST['ids'])) ? array_map('absint', $_POST['ids']) : [];
            $urls = (!empty($_POST['urls']) && is_array($_POST['urls'])) ? $_POST['urls'] : [];

            $current_user = wp_get_current_user();
            $custom_to = !empty($_POST['custom_to']) ? sanitize_text_field($_POST['custom_to']) : '';
            $to = !empty($custom_to) ? $custom_to : $current_user->user_email;

            $attachments = [];
            $temp_files = [];
            $upload_dir = wp_upload_dir();
            $fds_dir = $upload_dir['basedir'] . '/fds-documents/';

            global $wpdb, $ep_app_manager;
            $table = $wpdb->prefix . 'fds_documentos';
            $can_manage = $ep_app_manager->get_user_permission($this->get_id()) === 'write';
            $user_id = get_current_user_id();

            if (!empty($urls)) {
                foreach ($urls as $url) {
                    if (preg_match('/id=([0-9]+)/', $url, $matches)) {
                        $ids[] = intval($matches[1]);
                    } else {
                        // Si no tiene ID, puede ser un ZIP de lote o un archivo directo
                        $filename = basename($url);
                        $file_path = $fds_dir . $filename;
                        if (file_exists($file_path)) {
                            clearstatcache(true, $file_path);
                            // Crear una copia temporal para el envío para evitar bloqueos/corrupción
                            $temp_send_path = $fds_dir . 'mail_tmp_' . bin2hex(random_bytes(4)) . '_' . $filename;
                            if (copy($file_path, $temp_send_path)) {
                                $attachments[] = $temp_send_path;
                                $temp_files[] = $temp_send_path;
                                clearstatcache(true, $temp_send_path);
                            }
                        }
                    }
                }
                $ids = array_unique($ids);
            }

            if (!empty($ids)) {
                foreach ($ids as $id) {
                    $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
                    if ($doc && ($can_manage || (int) $doc->usuario_id === $user_id || (int) $doc->solicitante_id === $user_id)) {
                        if (!empty($doc->ruta_documento_firmado) && file_exists($doc->ruta_documento_firmado)) {
                            $temp_path = $this->decrypt_file_to_temp($doc->ruta_documento_firmado, $doc->nombre_archivo_original);
                            if ($temp_path) {
                                $attachments[] = $temp_path;
                                $temp_files[] = $temp_path;
                            }
                        }
                    }
                }
            }

            if (empty($attachments)) {
                wp_send_json_error(['message' => 'No se encontraron archivos para enviar']);
            }

            $subject = 'Documentos Firmados - ' . get_bloginfo('name');
            $body = "Hola " . $current_user->display_name . ",\n\nAdjunto encontrarás los documentos firmados electrónicamente.\n\nSaludos.";
            $headers = array('Content-Type: text/plain; charset=UTF-8');

            ep_error_log("EP_App_Signature_V4: Invocando wp_mail para $to con " . count($attachments) . " adjuntos.");
        foreach ($attachments as $att) {
            ep_error_log("EP_App_Signature_V4: Verificando archivo: $att | Existe: " . (file_exists($att) ? 'SÍ' : 'NO') . " | Tamaño: " . (file_exists($att) ? filesize($att) : 0));
        }

        $sent = wp_mail($to, $subject, $body, $headers, $attachments);
        ep_error_log("EP_App_Signature_V4: Resultado de wp_mail: " . ($sent ? 'ÉXITO' : 'FALLO'));

            // Borrado retardado para evitar fallos en correos asíncronos/SMTP
            foreach ($temp_files as $tf) { 
                wp_schedule_single_event(time() + 600, 'ep_cleanup_temp_file', [$tf]); 
            }

            if ($sent) {
                wp_send_json_success(['message' => 'Correo enviado correctamente a ' . $to]);
            } else {
                wp_send_json_error(['message' => 'Error al enviar el correo.']);
            }
        } catch (\Throwable $th) {
            wp_send_json_error(['message' => 'Error interno: ' . $th->getMessage()]);
        }
    }

    private function handle_get_sent_requests()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fds_documentos';
        $user_id = get_current_user_id();

        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, u.display_name as recipient_name 
             FROM $table d 
             LEFT JOIN {$wpdb->users} u ON d.usuario_id = u.ID 
             WHERE d.solicitante_id = %d 
             ORDER BY d.fecha_firma DESC",
            $user_id
        ));

        include $this->base_path . 'views/sent-requests-view.php';
        die();
    }

    /**
     * Permite a otros plugins añadir una solicitud de firma programáticamente.
     */
    public static function add_signature_request($recipient_id, $file_path, $title, $solicitor_id = 0, $external_post_id = 0)
    {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $fds_dir = $upload_dir['basedir'] . '/fds-documents/';
        if (!file_exists($fds_dir)) wp_mkdir_p($fds_dir);

        $final_filename = 'req_' . date('Ymd_His') . '_' . sanitize_file_name(basename($file_path));
        $dest_path = $fds_dir . $final_filename;

        if (copy($file_path, $dest_path)) {
            // Cifrar el archivo en el repositorio de firmas
            $instance = new self();
            $instance->encrypt_file($dest_path);

            $pdf_content = $instance->decrypt_file_content($dest_path);
            $pdf_hash = hash('sha256', $pdf_content);

            $table_name = $wpdb->prefix . 'fds_documentos';
            $wpdb->insert($table_name, [
                'nombre_documento' => $final_filename,
                'nombre_archivo_original' => basename($file_path),
                'ruta_documento_firmado' => '', // Aún no firmado
                'url_documento_firmado' => '',
                'usuario_id' => $recipient_id,
                'solicitante_id' => $solicitor_id,
                'hash_documento_original' => $pdf_hash,
                'estado' => 'pendiente',
                'fecha_firma' => current_time('mysql'),
                'csv_documento' => $pdf_hash
            ]);

            $request_id = $wpdb->insert_id;

            // Guardar el ID externo para trazabilidad
            if ($external_post_id) {
                update_post_meta($external_post_id, '_ep_signature_request_id', $request_id);
            }

            // Add Portal Notification
            if (class_exists('EP_Notifications')) {
                $solicitor = get_userdata($solicitor_id);
                $solicitor_name = $solicitor ? $solicitor->display_name : 'Sistema';
                EP_Notifications::add_notification($recipient_id, [
                    'type' => 'warning',
                    'title' => 'Nueva solicitud de firma',
                    'message' => $solicitor_name . ' te ha enviado el documento "' . $title . '" para firmar.',
                    'link' => '?view=signature'
                ]);
            }

            return $request_id;
        } else {
            return new WP_Error('upload_error', 'Error al copiar el archivo al repositorio de firmas.');
        }
    }
}

// Global scope registration
add_action('ep_register_apps', function($manager) {
    ep_error_log("[FDS-NUCLEAR-V4]: Registrando APP en ep_register_apps");
    if (class_exists('EP_App_Signature_V4')) {
        $manager->register_app(new EP_App_Signature_V4());
        ep_error_log("[FDS-NUCLEAR-V4]: REGISTRO EXITOSO");
    } else {
        ep_error_log("[FDS-NUCLEAR-V4]: ERROR - La clase EP_App_Signature_V4 no existe!");
    }
});

// Register cron event for 48h pending signature reminders
add_action('ep_signature_pending_reminders_cron', 'ep_signature_send_pending_reminders');

if (!wp_next_scheduled('ep_signature_pending_reminders_cron')) {
    wp_schedule_event(time(), 'daily', 'ep_signature_pending_reminders_cron');
}

function ep_signature_send_pending_reminders() {
    global $wpdb;
    $table = $wpdb->prefix . 'fds_documentos';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return;
    }

    $two_days_ago = date('Y-m-d H:i:s', strtotime('-48 hours'));

    // Find pending docs created over 48h ago
    $pending_docs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE estado = 'pendiente' AND fecha_creacion <= %s",
        $two_days_ago
    ));

    if (empty($pending_docs)) {
        return;
    }

    foreach ($pending_docs as $doc) {
        $user_id = $doc->user_id;
        $title = $doc->nombre_archivo ?: 'Documento';

        if ($user_id && class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($user_id, array(
                'type'    => 'warning',
                'title'   => '✍️ Recordatorio: Firma Pendiente',
                'message' => 'Tienes el documento "' . $title . '" pendiente de firma desde hace más de 48 horas.',
                'link'    => '?view=signature'
            ));
        }
    }
}

