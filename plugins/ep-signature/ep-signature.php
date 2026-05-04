<?php
defined('ABSPATH') || exit;
/**
 * Plugin Name: EP Mini App: Firma Electrónica
 * Description: Gestión y firma de documentos PDF con certificado digital (v2.2 Integration).
 * Version: 2.2.0
 * Author: Jorge Polo
 * Package: enterprise
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
 * We load the parent libraries ONLY when this class is about to be defined.
 */
if (!class_exists('EP_Fpdi_V4')) {
    // Only load if we are in a context that requires PDF processing (AJAX prepare or Admin)
    $should_load_pdf_engines = (defined('DOING_AJAX') && DOING_AJAX);
    if (is_admin()) $should_load_pdf_engines = true;

    if ($should_load_pdf_engines) {
        $base_libs_path = WP_PLUGIN_DIR . '/Portal-empleado-1/plugins/ep-signature/libs/';
        
        // Load TCPDF
        if (!class_exists('TCPDF', false)) {
            $tcpdf_main = $base_libs_path . 'tcpdf/tcpdf.php';
            if (file_exists($tcpdf_main)) require_once $tcpdf_main;
        }
        
        // Load FPDI Autoloader
        if (!class_exists('\setasign\Fpdi\Tcpdf\Fpdi', false)) {
            $fpdi_auto = $base_libs_path . 'fpdi/src/autoload.php';
            if (file_exists($fpdi_auto)) require_once $fpdi_auto;
        }

        if (class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {

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
        }
    }
}
// --- End Global Helper Block ---

class EP_App_Signature_V4 implements EP_App_Interface
{
    private $libs_path;
    private $base_path;
    private $base_url;

    public function __construct()
    {
        $this->base_path = WP_PLUGIN_DIR . '/Portal-empleado-1/plugins/ep-signature/';
        $this->base_url = plugins_url('plugins/ep-signature/', WP_PLUGIN_DIR . '/Portal-empleado-1/employee-portal.php');
        $this->libs_path = $this->base_path . 'libs/';

        // Register AJAX actions
        add_action('wp_ajax_ep_app_signature', array($this, 'handle_ajax'));
        add_action('wp_ajax_ep_app_signature_save_user_signature', [$this, 'handle_save_user_signature']);
        add_action('wp_ajax_ep_app_signature_get_user_signature', [$this, 'handle_get_user_signature']);

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

    private function decrypt_file_content($file_path)
    {
        if (!file_exists($file_path))
            return false;
        $content = file_get_contents($file_path);

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
        if (file_exists($file_path) && strpos($file_path, 'tmp_') !== false) {
            ep_error_log("EP_App_Signature_V4: Cleaning up temp attachment: $file_path");
            @unlink($file_path);
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
        wp_enqueue_style('ep-signature-style', $this->base_url . 'assets/css/ep-signature.css', array(), '1.0.1');

        // AutoFirma / WebCrypto libs
        wp_enqueue_script('fds-autoscript', $this->base_url . 'libs/autoscript.js', array('jquery'), '1.0.0');
        wp_enqueue_script('pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.12.313/pdf.min.js', array(), '2.12.313');

        // Usamos la versión corregida de la raíz si existe, para saltar bloqueos de assets/
        $js_url = $this->base_url . 'assets/js/ep-signature.js';
        if (file_exists(EMPLOYEE_PORTAL_PATH . 'ep-signature-v2.js')) {
            $js_url = EMPLOYEE_PORTAL_URL . 'ep-signature-v2.js';
        }
        wp_enqueue_script('ep-signature-js', $js_url, array('jquery', 'fds-autoscript'), '1.0.5');

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

            if (empty($pdf_content)) {
                wp_send_json_error(['message' => 'Faltan datos del PDF.']);
            }

            $visible_signature_type = isset($_POST['visible_signature_type']) ? sanitize_text_field($_POST['visible_signature_type']) : 'none';
            $pdf_hash_original = isset($_POST['pdf_hash_original']) ? sanitize_text_field($_POST['pdf_hash_original']) : null;
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

            // Robust Library Loading
            if (!class_exists('TCPDF', false)) {
                if (file_exists($this->libs_path . 'tcpdf/tcpdf.php')) {
                    require_once $this->libs_path . 'tcpdf/tcpdf.php';
                }
            }
            if (!class_exists('setasign\Fpdi\Tcpdf\Fpdi', false)) {
                $fpdi_autoload = $this->libs_path . 'fpdi/src/autoload.php';
                if (file_exists($fpdi_autoload)) {
                    require_once $fpdi_autoload;
                }
            }

            // Load commercial PDF-Parser (Essential for signed PDFs / incremental updates)
            $parser_autoload = $this->libs_path . 'pdf-mod/src/autoload.php';
            if (!file_exists($parser_autoload)) {
                $wp_uploads = wp_upload_dir();
                $parser_autoload = $wp_uploads['basedir'] . '/pdf-mod/src/autoload.php';
            }

            if (file_exists($parser_autoload)) {
                require_once $parser_autoload;
                ep_error_log('EP_App_Signature_V4: [LIBS] PDF-Parser loaded.');
            }

            if (!class_exists('QRcode', false)) {
                if (file_exists($this->libs_path . 'phpqrcode/qrlib.php')) {
                    require_once $this->libs_path . 'phpqrcode/qrlib.php';
                }
            }

            if (!class_exists('EP_Fpdi_V4')) {
                wp_send_json_error(['message' => 'Error: Clase EP_Fpdi_V4 no encontrada.']);
                return;
            }
            $pdf = new EP_Fpdi_V4();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false);

            $stream = \setasign\Fpdi\PdfParser\StreamReader::createByString($pdf_content);
            $page_count = $pdf->setSourceFile($stream);

            $qr_img = null;
            if ($pdf_hash_original && class_exists('QRcode')) {
                $verify_url = home_url('?view=signature&sub_action=verify&csv=' . $pdf_hash_original);
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
                
                // --- ESCALADO AL 95% PARA EL PIE ---
                $scale = 0.95;
                $new_width = $size['width'] * $scale;
                $new_height = $size['height'] * $scale;
                $offset_x = ($size['width'] - $new_width) / 2;
                $offset_y = 5; // Un pequeño margen superior
                
                // Dibujamos la página original escalada
                $pdf->useTemplate($template_id, $offset_x, $offset_y, $new_width, $new_height);
                
                // --- PIE DE PÁGINA PROFESIONAL (ESTILO SEDE ELECTRÓNICA) ---
                if ($qr_img) {
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
                    $pdf->Text($col2_x + 8, $footer_base_y, $pdf_hash_original);

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

                // --- APLANADO VISUAL (Firmas previas) ---
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
                        }
                    }
                }
            }

            // Limpiamos cualquier salida previa para evitar corrupción
            if (ob_get_length()) ob_clean();

            wp_send_json_success([
                'pdf_data_to_sign_base64' => base64_encode($pdf->Output('', 'S')),
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
            $signature_b64 = isset($_POST['signature']) ? $_POST['signature'] : '';
            $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : 'documento_firmado.pdf';
            $original_hash = isset($_POST['pdf_hash']) ? sanitize_text_field($_POST['pdf_hash']) : '';
            $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
            $cert_info_json = isset($_POST['cert_info']) ? wp_unslash($_POST['cert_info']) : null;

            if (empty($signature_b64)) {
                wp_send_json_error(['message' => 'Faltan los datos de la firma.']);
            }

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

            if (file_put_contents($file_path, base64_decode(trim($signature_b64)))) {
                $this->encrypt_file($file_path);
                global $wpdb;
                $table_name = $wpdb->prefix . 'fds_documentos';

                $data = array(
                    'nombre_documento' => $final_filename,
                    'nombre_archivo_original' => $filename,
                    'ruta_documento_firmado' => $file_path,
                    'url_documento_firmado' => $upload_dir['baseurl'] . '/fds-documents/' . $final_filename,
                    'hash_documento_original' => $original_hash,
                    'usuario_id' => get_current_user_id(),
                    'nombre_firmante' => $signer_name,
                    'certificado_info' => $cert_info_json,
                    'fecha_firma' => current_time('mysql'),
                    'estado' => 'firmado',
                    'csv_documento' => $original_hash
                );

                if ($request_id) {
                    $wpdb->update($table_name, $data, ['id' => $request_id]);
                    $id_to_return = $request_id;
                } else {
                    $wpdb->insert($table_name, $data);
                    $id_to_return = $wpdb->insert_id;
                }

                wp_send_json_success([
                    'id' => $id_to_return,
                    'message' => 'Documento guardado y firmado correctamente.',
                    'download_url' => admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $id_to_return . '&nonce=' . wp_create_nonce('ep_signature_nonce')
                ]);
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Error crítico al guardar: ' . $e->getMessage()]);
        }
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

        global $wpdb, $ep_app_manager;
        $table = $wpdb->prefix . 'fds_documentos';
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if (!$doc) wp_die('Documento no encontrado');

        $user_id = get_current_user_id();
        $can_manage = $ep_app_manager->get_user_permission($this->get_id()) === 'write';

        if (!$can_manage && (int) $doc->usuario_id !== $user_id && (int) $doc->solicitante_id !== $user_id) {
            wp_die('Acceso denegado');
        }

        if (!empty($doc->ruta_documento_firmado) && file_exists($doc->ruta_documento_firmado)) {
            $content = $this->decrypt_file_content($doc->ruta_documento_firmado);
            if ($content === false) wp_die('Error al desencriptar');

            while (ob_get_level()) ob_end_clean();

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $doc->nombre_archivo_original . '"');
            header('Content-Length: ' . mb_strlen($content, '8bit'));
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
                $download_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce');
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

                $download_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce');
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
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== TRUE) {
            wp_send_json_error(['message' => 'No se pudo crear el archivo ZIP']);
        }

        foreach ($valid_files as $file) {
            $content = $this->decrypt_file_content($file['path']);
            if ($content !== false) {
                $zip->addFromString($file['name'], $content);
            }
        }
        $zip->close();

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
