<?php
defined('ABSPATH') || exit;

$current_user_id = get_current_user_id();

$user_permission = $ep_app_manager->get_user_permission('signature');
$user_can_manage = ($user_permission === 'write');
$is_admin_role = in_array('administrator', (array) wp_get_current_user()->roles);
?>

<div class="ep-app-container ep-signature-app">
    <div class="ep-app-header">
        <div class="header-left">
            <i class="fa-solid fa-file-signature"></i>
            <div>
                <h1>Firma Electrónica</h1>
                <p>Gestiona y firma tus documentos PDF de forma segura</p>
                <?php if ($user_permission === 'read'): ?>
                    <div class="ep-badge badge-warning"><i class="fa-solid fa-eye"></i> Modo Lectura: Solo puedes enviar
                        documentos a firmar</div>
                <?php elseif (!$user_can_manage): ?>
                    <div class="ep-badge badge-info"><i class="fa-solid fa-info-circle"></i> Solo puedes solicitar firmas o
                        firmar lo recibido</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="ep-tabs">
        <button class="ep-tab-btn active" data-tab="tab-sign">
            <i class="fa-solid fa-pen-nib"></i> Nueva Firma
        </button>
        <button class="ep-tab-btn" data-tab="tab-my-docs">
            <i class="fa-solid fa-folder-open"></i> Mis Documentos
        </button>
        <button class="ep-tab-btn" data-tab="tab-inbox">
            <i class="fa-solid fa-inbox"></i> Buzón de Firmas
        </button>
        <?php if ($is_admin_role): ?>
            <button class="ep-tab-btn" data-tab="tab-admin">
                <i class="fa-solid fa-gears"></i> Gestión
            </button>
        <?php endif; ?>
    </div>

    <!-- Tab Content: Sign -->
    <div id="tab-sign" class="ep-tab-content active">
        <div class="ep-signature-workflow">

            <!-- Step 1: Upload -->
            <div id="fds-drop-zone" class="ep-drag-drop-zone">
                <div class="dz-content">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <h3>Arrastra tus PDFs aquí</h3>
                    <p>o haz clic para seleccionar uno o varios archivos</p>
                    <input type="file" id="fds-pdf-file" accept="application/pdf" multiple style="display:none;">
                </div>
            </div>


            <!-- PDF Preview & Options (Hidden initially) -->
            <div id="fds-editor-area" style="display:none;">
                <div class="ep-grid-layout">
                    <!-- Left: Preview -->
                    <div class="ep-preview-panel">
                        <div class="panel-header pdf-editor-header">
                            <div class="pdf-title">
                                <i class="fa-solid fa-file-pdf"></i>
                                <span id="fds-file-name">documento.pdf</span>
                            </div>
                            <div class="canvas-controls">
                                <button id="fds-prev-page" class="ep-btn-icon" title="Anterior">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>
                                <span class="page-info">
                                    Página <span id="fds-current-page">1</span> de <span id="fds-total-pages">--</span>
                                </span>
                                <button id="fds-next-page" class="ep-btn-icon" title="Siguiente">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                        <div class="canvas-wrapper">
                            <canvas id="fds-pdf-canvas"></canvas>
                            <div id="fds-signature-marker" style="display:none;"></div>
                            <!-- Markers will be injected here -->
                        </div>
                        <div id="fds-marks-counter" class="ep-info-box" style="margin-top:10px;">
                            <i class="fa-solid fa-list-check"></i>
                            Firmas colocadas: <span id="fds-signature-count">0</span>
                        </div>
                    </div>

                    <!-- Right: Options -->
                    <div class="ep-options-panel">
                        <!-- Queue Area Moved Here -->
                        <?php if ($user_permission !== 'read'): ?>
                            <div id="fds-queue-area" class="ep-signature-queue" style="display:none;">
                                <h4>Documentos en cola (<span id="fds-queue-count">0</span>)</h4>
                                <div id="fds-queue-list" class="queue-list"></div>
                            </div>
                            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #1e293b;">Opciones de Firma</h3>
                        <?php endif; ?>

                        <div id="fds-post-sign-options" class="option-group" style="border: 1px solid var(--ep-primary); background: #f0f7ff; padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: var(--ep-primary); margin: 0;">
                                <input type="checkbox" id="fds-send-to-sender" checked style="width: 18px; height: 18px;"> 
                                <i class="fa-solid fa-paper-plane"></i> Enviar copia al remitente
                            </label>
                            <small style="display: block; margin-top: 5px; color: #555; font-size: 0.75rem; line-height: 1.2;">
                                Se enviará un correo automático al solicitante.
                            </small>
                        </div>

                        <?php if ($user_can_manage): ?>
                            <div class="option-group">
                                <label>Tipo de firma visible:</label>
                                <div class="ep-radio-group" style="flex-wrap: wrap;">
                                    <label><input type="radio" name="fds_visible_signature_type" value="none" checked>
                                        Ninguna</label>
                                    <label><input type="radio" name="fds_visible_signature_type" value="text"> Texto
                                        informativo</label>
                                    <label><input type="radio" name="fds_visible_signature_type" value="image">
                                        Imagen/Rúbrica</label>
                                    <label style="display: inline-flex; align-items: center; gap: 4px;">
                                        <input type="radio" name="fds_visible_signature_type" value="details"> 
                                        Datos de Firma (Desbloqueado)
                                        <a href="#" id="fds-btn-config-unlocked" style="display:none; font-size:0.75rem; color:var(--ep-primary); text-decoration:underline;" title="Configurar pie de página para modo desbloqueado"><i class="fa-solid fa-cog"></i> Configurar</a>
                                    </label>
                                </div>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="fds_visible_signature_type" value="none">
                        <?php endif; ?>

                        <div class="option-group">
                            <label><i class="fa-solid fa-user-plus"></i> ¿Quién debe firmar?</label>
                            <div class="ep-radio-group">
                                <?php if ($user_permission !== 'read'): ?>
                                    <label><input type="radio" name="fds_target_user_type" value="me" checked> Yo
                                        mismo</label>
                                <?php endif; ?>
                                <label><input type="radio" name="fds_target_user_type" value="other" <?php echo ($user_permission === 'read') ? 'checked' : ''; ?>> Otro usuario
                                    (Buzón)</label>
                            </div>
                        </div>

                        <!-- User Selector (Hidden by default) -->
                        <div id="fds-recipient-selector-area" style="display:none;" class="option-group">
                            <div class="input-field">
                                <label>Seleccionar Destinatario:</label>
                                <div class="ep-search-select">
                                    <input type="text" id="fds-recipient-search"
                                        placeholder="Buscar usuario por nombre o email...">
                                    <div id="fds-recipient-results" class="search-results" style="display:none;"></div>
                                    <input type="hidden" id="fds-recipient-id" value="">
                                </div>
                                <div id="fds-selected-recipient-badge" class="selected-badge" style="display:none;">
                                    <i class="fa-solid fa-user-check"></i> <span id="fds-selected-recipient-name"></span>
                                    <button type="button" id="fds-remove-recipient">&times;</button>
                                </div>
                            </div>
                        </div>

                        <!-- Instructions Field (New) -->
                        <div id="fds-instructions-area" style="display:none;" class="option-group">
                            <div class="input-field">
                                <label><i class="fa-solid fa-comment-dots"></i> Instrucciones para el firmante:</label>
                                <textarea id="fds-instructions" rows="3" placeholder="Escribe aquí las instrucciones o comentarios para el firmante..."></textarea>
                                <small>Este texto será visible para el destinatario antes de firmar.</small>
                            </div>
                        </div>

                        <!-- Text Options -->
                        <div id="fds-visible-signature-user-data-area" style="display:none;">
                            <div class="input-field">
                                <label>Nombre:</label>
                                <input type="text" id="fds-user-display-name"
                                    value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>">
                            </div>
                            <div class="input-field">
                                <label>DNI/CIF:</label>
                                <input type="text" id="fds-user-dni"
                                    value="<?php echo esc_attr(get_user_meta($current_user_id, 'fds_user_dni', true)); ?>">
                            </div>
                        </div>

                        <!-- Image Options -->
                        <div id="fds-visible-signature-image-upload-area" style="display:none;">
                            <div style="display:flex; gap:10px; flex-direction:column;">
                                <div style="display:flex; gap:10px;">
                                    <label class="ep-btn ep-btn-secondary" style="flex:1;">
                                        <i class="fa-solid fa-upload"></i> Subir imagen (PNG/JPG)
                                        <input type="file" id="fds-visible-signature-image-file" accept="image/png,image/jpeg"
                                            style="display:none;">
                                    </label>
                                    <button type="button" id="fds-btn-use-saved-signature" class="ep-btn ep-btn-ghost" style="flex:1; display:none;" title="Usar firma guardada anteriormente">
                                        <i class="fa-solid fa-bookmark"></i> Usar guardada
                                    </button>
                                </div>
                                <div id="fds-save-signature-container" style="display:none; font-size: 0.85rem; color: var(--ep-text-muted);">
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:5px;">
                                        <input type="checkbox" id="fds-save-signature-checkbox"> Recordar esta firma para el futuro
                                    </label>
                                </div>
                            </div>
                            <img id="fds-visible-signature-image-preview" src="#"
                                style="display:none; max-width:100%; margin-top:10px; border-radius:4px; border: 1px solid #ddd; padding: 5px; background: #fff;">
                        </div>

                        <!-- Details Logo Options -->
                        <div id="fds-details-logo-area" style="display:none; margin-top:15px; border-top: 1px dashed #e2e8f0; padding-top: 15px;">
                            <label style="font-weight:600; font-size:0.85rem; display:block; margin-bottom:8px;">Logo del Sello (Opcional):</label>
                            <div style="display:flex; gap:10px; flex-direction:column;">
                                <div style="display:flex; gap:10px;">
                                    <label class="ep-btn ep-btn-secondary ep-btn-mini" style="flex:1; font-size: 0.8rem; padding: 6px 12px; height: auto; display: inline-flex; align-items: center; justify-content: center; gap: 5px; cursor: pointer;">
                                        <i class="fa-solid fa-upload"></i> Subir Logo
                                        <input type="file" id="fds-details-logo-file" accept="image/png,image/jpeg" style="display:none;">
                                    </label>
                                    <button type="button" id="fds-btn-use-saved-logo" class="ep-btn ep-btn-ghost ep-btn-mini" style="flex:1; display:none; font-size: 0.8rem; padding: 6px 12px; height: auto;" title="Usar logo guardado anteriormente">
                                        <i class="fa-solid fa-bookmark"></i> Usar guardado
                                    </button>
                                </div>
                                <div id="fds-save-logo-container" style="display:none; font-size: 0.85rem; color: var(--ep-text-muted);">
                                    <label style="cursor:pointer; display:flex; align-items:center; gap:5px; font-weight: normal; margin: 0;">
                                        <input type="checkbox" id="fds-save-logo-checkbox"> Recordar este logo
                                    </label>
                                </div>
                            </div>
                            <img id="fds-details-logo-preview" src="#" style="display:none; max-width:60px; max-height:60px; margin-top:10px; border-radius:4px; border: 1px solid #ddd; padding: 3px; background: #fff;">
                        </div>

                        <div id="fds-visible-signature-positioning-area" class="ep-info-box" style="display:none;">
                            <i class="fa-solid fa-circle-info"></i>
                            <p>Haz clic en el PDF para posicionar la firma.</p>
                            <div class="coords">X: <span id="fds-coords-x">--</span>, Y: <span
                                    id="fds-coords-y">--</span></div>
                        </div>


                        <div class="ep-actions-row">
                            <button id="fds-sign-button" class="ep-btn ep-btn-primary full-width">
                                <span class="fds-button-text">
                                    <i class="fa-solid fa-pen-nib"></i> Firmar Documentos
                                </span>
                                <span class="spinner" style="display:none;"></span>
                            </button>

                            <!-- Status Bar -->
                            <div id="fds-status-bar" class="fds-status-bar" style="display:none;">
                                <div class="fds-status-content">
                                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                                    <span id="fds-status-message">Procesando...</span>
                                </div>
                                <div class="fds-progress-line">
                                    <div id="fds-progress-fill" style="width: 0%;"></div>
                                </div>
                            </div>

                            <button id="fds-cancel-button" class="ep-btn ep-btn-ghost full-width">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Result Area (Hidden initially) -->
            <div id="fds-sign-result" class="ep-result-panel" style="display:none;">
                <div class="success-icon"><i class="fa-solid fa-circle-check"></i></div>
                <h2 id="fds-result-title">¡Documento Firmado!</h2>
                <p id="fds-result-text"></p>

                <div id="fds-result-info-container" class="ep-info-summary"></div>

                <div class="ep-actions-row">
                    <a id="fds-download-link" href="#" class="ep-btn ep-btn-primary">
                        <i class="fa-solid fa-download"></i> Descargar Documento
                    </a>
                    <button id="fds-email-button" class="ep-btn ep-btn-secondary">
                        <i class="fa-solid fa-envelope"></i> Enviar por Email
                    </button>
                    <button id="fds-sign-another-button" class="ep-btn ep-btn-ghost">
                        Firmar otro documento
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Tab Content: My Docs -->
    <div id="tab-my-docs" class="ep-tab-content">
        <div class="ep-card">
            <div id="fds-my-documents-list">
                <!-- Loaded via AJAX -->
                <p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando tus documentos...</p>
            </div>
        </div>
    </div>

    <!-- Tab Content: Inbox -->
    <div id="tab-inbox" class="ep-tab-content">
        <div class="ep-tab-sub-nav">
            <button class="ep-sub-tab-btn active" data-sub-tab="inbox-received">
                <i class="fa-solid fa-file-import"></i> Recibidos (Pendientes)
            </button>
            <button class="ep-sub-tab-btn" data-sub-tab="inbox-sent">
                <i class="fa-solid fa-file-export"></i> Solicitudes Enviadas
            </button>
        </div>

        <div id="inbox-received" class="ep-sub-tab-content active">
            <div class="ep-card">
                <div id="fds-inbox-list">
                    <!-- Loaded via AJAX -->
                    <p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando el buzón...</p>
                </div>
            </div>
        </div>

        <div id="inbox-sent" class="ep-sub-tab-content">
            <div class="ep-card">
                <div id="fds-sent-list">
                    <!-- Loaded via AJAX -->
                    <p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando solicitudes
                        enviadas...</p>
                </div>
            </div>
        </div>
    </div>

</div>
</div>
</div>

<!-- Tab Content: Admin -->
<?php if ($is_admin_role): ?>
    <div id="tab-admin" class="ep-tab-content">
        <div class="ep-card">
            <h3>Gestión Administrativa de Documentos</h3>
            <div id="fds-admin-documents-list">
                <p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando todos los documentos...</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Email Customization Modal -->
<div id="fds-email-modal" class="fds-modal" style="display:none;">
    <div class="fds-modal-content">
        <div class="fds-modal-header">
            <h3><i class="fa-solid fa-envelope-open-text"></i> Personalizar Envío por Email</h3>
            <span class="fds-modal-close">&times;</span>
        </div>
        <div class="fds-modal-body">
            <div class="input-field">
                <label for="fds-email-to">Destinatario(s) (separados por coma):</label>
                <input type="text" id="fds-email-to" placeholder="ejemplo@correo.com, otro@correo.com"
                    value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                <small>Por defecto se enviará a tu cuenta:
                    <?php echo esc_html(wp_get_current_user()->user_email); ?></small>
            </div>
            <div class="input-field">
                <label for="fds-email-subject">Asunto:</label>
                <input type="text" id="fds-email-subject" value="Documentos Firmados - <?php bloginfo('name'); ?>">
            </div>
            <div class="input-field">
                <label for="fds-email-body">Mensaje:</label>
                <textarea id="fds-email-body" rows="5">Hola,

Adjunto encontrarás los documentos firmados electrónicamente.

Saludos.</textarea>
            </div>
            <input type="hidden" id="fds-email-context-ids" value="">
            <input type="hidden" id="fds-email-context-urls" value="">
        </div>
        <div class="fds-modal-footer">
            <button id="fds-email-modal-cancel" class="ep-btn ep-btn-ghost">Cancelar</button>
            <button id="fds-email-modal-send" class="ep-btn ep-btn-primary">
                <i class="fa-solid fa-paper-plane"></i> Enviar Ahora
            </button>
        </div>
    </div>
</div>

<!-- Modal de Configuración para Modo Desbloqueado -->
<div id="fds-unlocked-modal" class="fds-modal" style="display:none;">
    <div class="fds-modal-content">
        <div class="fds-modal-header">
            <h3><i class="fa-solid fa-circle-info"></i> Modo Multifirma (Desbloqueado)</h3>
            <span class="fds-modal-close" id="fds-close-unlocked-modal">&times;</span>
        </div>
        <div class="fds-modal-body">
            <p style="margin-bottom: 15px; line-height: 1.4;">Has seleccionado el modo de <strong>Firma con Datos (Desbloqueado)</strong>. Este modo permite que el documento sea firmado por múltiples personas de forma sucesiva sin bloquearlo.</p>
            
            <div style="background: #e0f2fe; border-left: 4px solid #0284c7; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 0.85rem; color: #0369a1; display: flex; gap: 8px; align-items: flex-start;">
                <i class="fa-solid fa-triangle-exclamation" style="margin-top: 2px;"></i>
                <span>Si el documento ya contiene firmas digitales de otras personas, se mantendrán válidas criptográficamente al guardarse.</span>
            </div>
            
            <div class="option-group">
                <label style="font-weight: 600; display: block; margin-bottom: 10px;">¿Deseas estampar el pie de página de verificación con CSV y código QR?</label>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-weight: normal;">
                        <input type="radio" name="fds_unlocked_stamp_footer" value="yes" checked style="margin-top: 3px;"> 
                        <span><strong>Sí, estampar pie de página</strong> (Recomendado si eres la primera persona en firmar el documento).</span>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-weight: normal;">
                        <input type="radio" name="fds_unlocked_stamp_footer" value="no" style="margin-top: 3px;"> 
                        <span><strong>No estampar pie de página</strong> (Recomendado si el documento ya viene firmado por otra persona, para evitar alterar la estructura del PDF e invalidar sus firmas).</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="fds-modal-footer">
            <button type="button" id="fds-confirm-unlocked" class="ep-btn ep-btn-primary">Aceptar y Continuar</button>
        </div>
    </div>
</div>

</div>
</div>