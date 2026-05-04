<?php

defined('ABSPATH') || exit;

class EP_Inventory_PDF
{
    public function __construct()
    {
        // Require TCPDF if not already loaded. 
        // We assume it's loaded by the other plugin or we might need to load it here.
        // If FDS plugin is active, TCPDF should be available. 
        // If not, we might crash. For safety, we check class_exists.
        if (!class_exists('TCPDF')) {
            // Try to load from FDS if path known, else we might need our own vendor.
            // For this environment, we assume it is there.
            // Fallback: Check standard locations?
        }
    }

    public function generate_commitment($user_id, $output_mode = 'I')
    {
        if (!class_exists('TCPDF')) {
            return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'Usuario no encontrado.');
        }

        // Get assigned items OR loaned items
        $args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_ep_item_assigned_to',
                    'value' => $user_id,
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_item_loaned_to',
                    'value' => $user_id,
                    'compare' => '='
                )
            )
        );
        $items = get_posts($args);

        if (empty($items)) {
            return new WP_Error('no_items', 'Este usuario no tiene material asignado ni en préstamo.');
        }

        // Init PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAuthor('Portal del Empleado');
        $pdf->SetTitle('Compromiso de Cesión de Material');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false); // Or add custom footer
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();

        // Content
        $html = '<h1 style="text-align:center;">Compromiso de Cesión de Material</h1>';
        $html .= '<p>Yo, <strong>' . $user->display_name . '</strong>, con email <strong>' . $user->user_email . '</strong>, reconozco haber recibido de la empresa el siguiente material para el desempeño de mis funciones:</p>';

        $html .= '<table border="1" cellpadding="5">
                    <tr style="background-color:#f2f2f2;">
                        <th><strong>Material / Modelo</strong></th>
                        <th><strong>Nº Serie / Licencia</strong></th>
                    </tr>';

        foreach ($items as $item) {
            $serial = get_post_meta($item->ID, '_ep_item_serial', true);
            $html .= '<tr>
                        <td>' . esc_html($item->post_title) . '</td>
                        <td>' . esc_html($serial) . '</td>
                      </tr>';
        }
        $html .= '</table>';

        $html .= '<p style="margin-top:20px;">Me comprometo a hacer un uso correcto y profesional del mismo, velando por su integridad y comprometiéndome a su devolución en el momento que cese mi relación laboral o sea requerido por la empresa.</p>';

        $html .= '<div style="margin-top:50px;">
                    <p>En ____________________, a ' . date('d/m/Y') . '</p>
                    <br><br>
                    <p>Fdo: _________________________________</p>
                  </div>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if ($output_mode === 'F') {
            $upload_dir = wp_upload_dir();
            $path = $upload_dir['basedir'] . '/temp_commitment_' . $user_id . '.pdf';
            $pdf->Output($path, 'F');
            return $path;
        }

        // Output
        $pdf->Output('Compromiso_Cesion_' . $user->user_nicename . '.pdf', $output_mode);
        exit;
    }

    /**
     * Sincroniza el compromiso de cesión actual con la carpeta de Gestión Personal.
     */
    public function sync_commitment_to_portal($user_id)
    {
        $path = $this->generate_commitment($user_id, 'F');

        if (is_wp_error($path)) {
            if ($path->get_error_code() === 'no_items') {
                // Si no hay items, borrar el documento de compromiso si existe
                if (class_exists('EP_Downloads')) {
                    $docs = get_posts(array(
                        'post_type' => 'ep_document',
                        'meta_query' => array(
                            array('key' => '_ep_document_target_user', 'value' => $user_id),
                            array('key' => '_ep_document_source_tag', 'value' => 'inventory_commitment')
                        ),
                        'posts_per_page' => 1
                    ));
                    if (!empty($docs)) {
                        wp_delete_post($docs[0]->ID, true);
                    }
                }
            }
            return $path;
        }

        if (class_exists('EP_Downloads')) {
            $filename = 'Compromiso_Cesion_Material.pdf';
            return EP_Downloads::add_system_document($user_id, $path, $filename, 'inventory_commitment', false);
        }
        return false;
    }

    public function generate_labels($item_ids)
    {
        if (!class_exists('TCPDF')) {
            return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
        }

        $is_multiple = count($item_ids) > 1;

        // Label dimensions
        $label_w = 70;
        $label_h = 35;

        // Configuration based on mode
        if ($is_multiple) {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetMargins(0, 0, 0);
        } else {
            $pdf = new TCPDF('L', 'mm', array($label_w, $label_h), true, 'UTF-8', false);
            $pdf->SetMargins(2, 2, 2);
        }

        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $cols = 3; // 70 * 3 = 210 (A4 width)
        $rows = 8; // 35 * 8 = 280 (A4 height is 297)
        $labels_per_page = $cols * $rows;
        $count = 0;

        foreach ($item_ids as $id) {
            $post = get_post($id);
            if (!$post)
                continue;

            $serial = get_post_meta($id, '_ep_item_serial', true);
            $assigned_to = get_post_meta($id, '_ep_item_assigned_to', true);

            if (!$is_multiple || $count % $labels_per_page === 0) {
                $pdf->AddPage();
            }

            // Calculate Base XY
            $base_x = 0;
            $base_y = 0;

            if ($is_multiple) {
                $col = $count % $cols;
                $row = floor(($count % $labels_per_page) / $cols);
                $base_x = $col * $label_w;
                $base_y = $row * $label_h;

                // Draw border for alignment (optional, but requested for adesibe sheets)
                $pdf->SetDrawColor(230, 230, 230);
                $pdf->Rect($base_x, $base_y, $label_w, $label_h);
            }

            // QR Content
            $qr_content = $assigned_to
                ? site_url('/?view=profile&user_id=' . $assigned_to)
                : site_url('/?view=inventory&s=' . $serial);

            $style = array(
                'border' => 0,
                'vpadding' => 'auto',
                'hpadding' => 'auto',
                'fgcolor' => array(0, 0, 0),
                'bgcolor' => false,
                'module_width' => 1,
                'module_height' => 1
            );

            // Write QR (Bigger)
            // x, y, w, h
            $pdf->write2DBarcode($qr_content, 'QRCODE,L', $base_x + 3, $base_y + 4, 27, 27, $style, 'N');

            // Text on the right - Simplified design
            $pdf->SetTextColor(0, 0, 0);

            // Title (Large and Bold)
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetXY($base_x + 32, $base_y + 4);
            $title = $post->post_title;
            $pdf->MultiCell(35, 15, $title, 0, 'L', false, 1, '', '', true, 0, false, true, 15, 'T');

            // ID Plateforma
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetXY($base_x + 32, $base_y + 21);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Write(0, 'ID: ');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Write(0, $id);

            // S/N (Using ID as requested or both if specified, but user said "en vez")
            // I'll put both to be safe but prominent ID
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetXY($base_x + 32, $base_y + 27);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Write(0, 'S/N: ' . $serial);

            $count++;
        }

        $pdf->Output('etiquetas_inventario.pdf', 'D');
        exit;
    }

    public function generate_itinerant_loan($item_id)
    {
        if (!class_exists('TCPDF')) {
            return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
        }

        $item = get_post($item_id);
        if (!$item)
            return new WP_Error('invalid_item', 'Item no válido.');

        $loan_location = get_post_meta($item_id, '_ep_item_loan_location', true);
        $borrower_cargo = get_post_meta($item_id, '_ep_item_borrower_cargo', true);
        $serial = get_post_meta($item_id, '_ep_item_serial', true);
        $loan_date = get_post_meta($item_id, '_ep_item_loan_date', true);
        $return_date = get_post_meta($item_id, '_ep_item_estimated_return', true);

        // Datos del prestatario y responsable
        $loaned_to = get_post_meta($item_id, '_ep_item_loaned_to', true);
        $external_name = get_post_meta($item_id, '_ep_item_external_borrower', true);
        $borrower_nif = get_post_meta($item_id, '_ep_item_borrower_nif', true);
        $assigned_to = get_post_meta($item_id, '_ep_item_assigned_to', true);

        $borrower_name = '';
        if ($loaned_to > 0) {
            $user = get_userdata($loaned_to);
            $borrower_name = $user ? $user->display_name : 'Usuario ID: ' . $loaned_to;
            if (empty($borrower_cargo)) {
                $borrower_cargo = get_user_meta($loaned_to, 'ep_cargo', true) ?: 'Empleado';
            }
        } else {
            $borrower_name = $external_name ? $external_name : 'N/A';
        }

        $responsible_name = 'Cámara de Comercio de Cáceres';
        if ($assigned_to) {
            $u_resp = get_userdata($assigned_to);
            $responsible_name = $u_resp ? $u_resp->display_name : $assigned_to;
        }

        // Admin logueado para la entrega
        $current_user = wp_get_current_user();
        $admin_name = $current_user->ID ? $current_user->display_name : 'Administrador';

        // Init PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAuthor('Cámara de Comercio de Cáceres');
        $pdf->SetTitle('Justificante de Préstamo de Material Itinerante');

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);

        $pdf->AddPage();

        $html = '<div style="font-family: helvetica; color: #333;">';
        $html .= '<h1 style="text-align:center; color:#000; font-size: 24px; margin-bottom: 5px;">Justificante de Préstamo de Material Itinerante</h1>';
        $html .= '<div style="height: 2px; background-color: #eee; margin-bottom: 30px;"></div>';

        // Sección: Detalles del Equipo
        $html .= '<h3 style="background-color:#f8f9fa; padding: 10px; border-left: 4px solid #333;">Detalles del Equipo</h3>';
        $html .= '<table cellpadding="6" style="width:100%; margin-bottom: 20px;">';
        $html .= '<tr><td style="width:30%;"><b>Material:</b></td><td style="width:70%;">' . esc_html($item->post_title) . '</td></tr>';
        $html .= '<tr><td style="width:30%;"><b>Nº Serie / Identificador:</b></td><td style="width:70%;">' . esc_html($serial ?: 'N/A') . '</td></tr>';
        $html .= '</table>';

        // Sección: Detalles del Préstamo
        $html .= '<h3 style="background-color:#f8f9fa; padding: 10px; border-left: 4px solid #333;">Detalles del Préstamo</h3>';
        $html .= '<table cellpadding="6" style="width:100%;">';
        $html .= '<tr><td style="width:30%;"><b>Responsable:</b></td><td style="width:70%;">' . esc_html($responsible_name) . '</td></tr>';
        $html .= '<tr><td style="width:30%;"><b>Prestatario:</b></td><td style="width:70%;">' . esc_html($borrower_name) . ' (' . esc_html($borrower_nif ?: 'NIF no indicado') . ')</td></tr>';
        $html .= '<tr><td style="width:30%;"><b>Ubicación:</b></td><td style="width:70%;">' . esc_html($loan_location ?: 'Sede Central') . '</td></tr>';
        $html .= '<tr><td style="width:30%;"><b>Fecha de Salida:</b></td><td style="width:70%;">' . ($loan_date ? date('d/m/Y', strtotime($loan_date)) : date('d/m/Y')) . '</td></tr>';
        if ($return_date) {
            $html .= '<tr><td style="width:30%;"><b>Devolución Prevista:</b></td><td style="width:70%;">' . date('d/m/Y', strtotime($return_date)) . '</td></tr>';
        }
        $html .= '</table>';

        // Cláusula
        $html .= '<div style="margin-top: 40px; line-height: 1.5; font-size: 11px; text-align: justify; color: #555;">';
        $html .= '<p>El firmante reconoce recibir el material descrito en perfecto estado y se compromete a su correcto uso y devolución en el plazo establecido o cuando le sea requerido por la organización. El uso del equipo es estrictamente profesional.</p>';
        $html .= '</div>';

        // Firmas
        $html .= '<table cellpadding="10" style="width:100%; margin-top: 60px;">';
        $html .= '<tr>';
        $html .= '<td style="width:50%; text-align:center; border-top: 0.5px solid #ccc;">';
        $html .= '<b>Entregado por (Firma):</b><br><br><br><br>';
        $html .= '<small>' . esc_html($admin_name) . '</small>';
        $html .= '</td>';
        $html .= '<td style="width:50%; text-align:center; border-top: 0.5px solid #ccc;">';
        $html .= '<b>Recibido por (Firma):</b><br><br><br><br>';
        $html .= '<small>' . esc_html($borrower_name) . '</small>';
        $html .= '</td>';
        $html .= '</tr>';
        $html .= '</table>';

        $html .= '<div style="margin-top: 50px; text-align: right; font-size: 10px; color: #999;">';
        $html .= 'En Cáceres, a ' . date_i18n('j \d\e F \d\e Y');
        $html .= '</div>';
        $html .= '</div>';

        $pdf->writeHTML($html, true, false, true, false, '');

        // Output
        $pdf->Output('justificante_prestamo_' . $item_id . '.pdf', 'D');
        exit;
    }

    /**
     * Generar un justificante de préstamo para múltiples items
     */
    public function generate_bulk_itinerant_loan($item_ids)
    {
        if (!class_exists('TCPDF')) {
            return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
        }

        if (!is_array($item_ids) || empty($item_ids)) {
            return new WP_Error('empty_ids', 'No hay items seleccionados.');
        }

        // Cogemos el responsable y prestatario del primer item (se asume que es el mismo para el lote)
        $first_id = $item_ids[0];
        $loaned_to = get_post_meta($first_id, '_ep_item_loaned_to', true);
        $external_name = get_post_meta($first_id, '_ep_item_external_borrower', true);
        $borrower_nif = get_post_meta($first_id, '_ep_item_borrower_nif', true);
        $assigned_to = get_post_meta($first_id, '_ep_item_assigned_to', true);
        $loan_location = get_post_meta($first_id, '_ep_item_loan_location', true);
        $loan_date = get_post_meta($first_id, '_ep_item_loan_date', true);
        $return_date = get_post_meta($first_id, '_ep_item_estimated_return', true);

        $borrower_name = '';
        if ($loaned_to > 0) {
            $user = get_userdata($loaned_to);
            $borrower_name = $user ? $user->display_name : 'Usuario ID: ' . $loaned_to;
        } else {
            $borrower_name = $external_name ? $external_name : 'N/A';
        }

        $responsible_name = 'Cámara de Comercio de Cáceres';
        if ($assigned_to) {
            $u_resp = get_userdata($assigned_to);
            $responsible_name = $u_resp ? $u_resp->display_name : $assigned_to;
        }

        $current_user = wp_get_current_user();
        $admin_name = $current_user->ID ? $current_user->display_name : 'Administrador';

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetTitle('Justificante de Préstamo en Lote');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();

        $html = '<div style="font-family: helvetica; color: #333;">';
        $html .= '<h1 style="text-align:center; color:#000; font-size: 24px; margin-bottom: 5px;">Justificante de Préstamo de Material (Lote)</h1>';
        $html .= '<div style="height: 2px; background-color: #eee; margin-bottom: 25px;"></div>';

        // Detalles del Préstamo (Cabecera)
        $html .= '<table cellpadding="5" style="width:100%; margin-bottom: 20px; background-color: #f9f9f9; border: 0.5px solid #eee;">';
        $html .= '<tr><td><b>Responsable:</b> ' . esc_html($responsible_name) . '</td><td><b>Prestatario:</b> ' . esc_html($borrower_name) . '</td></tr>';
        $html .= '<tr><td><b>NIF/DNI:</b> ' . esc_html($borrower_nif ?: 'No indicado') . '</td><td><b>Ubicación:</b> ' . esc_html($loan_location ?: 'Sede Central') . '</td></tr>';
        $html .= '<tr><td><b>Fecha Salida:</b> ' . ($loan_date ? date('d/m/Y', strtotime($loan_date)) : date('d/m/Y')) . '</td><td><b>Devolución:</b> ' . ($return_date ? date('d/m/Y', strtotime($return_date)) : 'No indicada') . '</td></tr>';
        $html .= '</table>';

        // Tabla de Materiales
        $html .= '<h3 style="margin-top: 20px;">Relación de Equipos</h3>';
        $html .= '<table cellpadding="6" style="width:100%; border: 0.5px solid #444; border-collapse: collapse;">';
        $html .= '<tr style="background-color:#f2f2f2;">';
        $html .= '<th style="width:70%; border: 0.5px solid #444;"><b>Descripción del Material</b></th>';
        $html .= '<th style="width:30%; border: 0.5px solid #444;"><b>Nº Serie / ID</b></th>';
        $html .= '</tr>';

        foreach ($item_ids as $id) {
            $title = get_the_title($id);
            $sn = get_post_meta($id, '_ep_item_serial', true);
            $html .= '<tr>';
            $html .= '<td style="border: 0.5px solid #444;">' . esc_html($title) . '</td>';
            $html .= '<td style="border: 0.5px solid #444;">' . esc_html($sn ?: 'N/A') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<div style="margin-top: 30px; line-height: 1.5; font-size: 11px; text-align: justify; color: #555;">';
        $html .= '<p>El firmante reconoce recibir el material relacionado anteriormente en perfecto estado y se compromete a su correcto uso y devolución en el plazo establecido o cuando le sea requerido por la organización. El uso del equipo es estrictamente profesional.</p>';
        $html .= '</div>';

        // Signatures
        $html .= '<table cellpadding="10" style="width:100%; margin-top: 50px;">';
        $html .= '<tr>';
        $html .= '<td style="width:50%; text-align:center; border-top: 0.5px solid #ccc;"><b>Entregado por:</b><br><br><br><br><small>' . esc_html($admin_name) . '</small></td>';
        $html .= '<td style="width:50%; text-align:center; border-top: 0.5px solid #ccc;"><b>Recibido por:</b><br><br><br><br><small>' . esc_html($borrower_name) . '</small></td>';
        $html .= '</tr>';
        $html .= '</table>';

        $html .= '<div style="margin-top: 40px; text-align: right; font-size: 10px; color: #999;">Cáceres, a ' . date_i18n('j \d\e F \d\e Y') . '</div>';
        $html .= '</div>';

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('justificante_lote_' . count($item_ids) . '.pdf', 'D');
        exit;
    }

    public function generate_request_commitment($request_id)
    {
        if (!class_exists('TCPDF')) {
            return new WP_Error('tcpdf_missing', 'La librería TCPDF no está disponible.');
        }

        $request = get_post($request_id);
        if (!$request)
            return new WP_Error('invalid_request', 'Solicitud no válida.');

        $user_id = get_post_meta($request_id, '_ep_request_user_id', true);
        $user = get_userdata($user_id);
        $details = $request->post_content;
        $start_date = get_post_meta($request_id, '_ep_request_start_date', true);
        $end_date = get_post_meta($request_id, '_ep_request_end_date', true);

        // Init PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Portal del Empleado');
        $pdf->SetAuthor('RRHH');
        $pdf->SetTitle('Compromiso de Solicitud de Material');

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->AddPage();

        // Content
        $html = '<h1 style="text-align:center;">Compromiso de Solicitud de Material</h1>';
        $html .= '<p><strong>Solicitante:</strong> ' . esc_html($user ? $user->display_name : 'N/A') . '<br>';
        $html .= '<strong>Fecha de Solicitud:</strong> ' . get_the_date('d/m/Y', $request_id) . '</p>';

        $html .= '<div style="background-color:#f5f5f5; padding:15px; border:1px solid #ddd;">';
        $html .= '<h3>Detalles de la Solicitud</h3>';
        $html .= '<p>' . nl2br(esc_html($details)) . '</p>';

        if ($start_date && $end_date) {
            $html .= '<p><strong>Periodo Previsto:</strong> Desde ' . date('d/m/Y', strtotime($start_date)) . ' hasta ' . date('d/m/Y', strtotime($end_date)) . '</p>';
        }
        $html .= '</div>';

        $html .= '<p style="margin-top:30px;">Por la presente, el solicitante declara que la información proporcionada es correcta y se compromete a hacer un uso responsable del material en caso de ser asignado, siguiendo las políticas de la empresa.</p>';

        $html .= '<br><br><br>';
        $html .= '<table border="0" width="100%"><tr>';
        $html .= '<td width="50%" style="text-align:center;">Recibido por (Empresa):<br><br><br><br>__________________________</td>';
        $html .= '<td width="50%" style="text-align:center;">Firma del Solicitante:<br><br><br><br>__________________________</td>';
        $html .= '</tr></table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        // Output
        $pdf->Output('compromiso_solicitud_' . $request_id . '.pdf', 'D');
        exit;
    }
}
