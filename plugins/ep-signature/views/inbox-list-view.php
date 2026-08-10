<?php defined('ABSPATH') || exit; ?>

<div class="fds-bulk-actions" style="margin-bottom: 15px;">
    <?php if ($permission !== 'read' && !empty($docs)): ?>
        <button id="fds-btn-sign-bulk" class="ep-btn ep-btn-primary">
            <i class="fa-solid fa-pen-nib"></i> Firmar Seleccionados
        </button>
    <?php endif; ?>
</div>
<div class="ep-table-container ep-table-responsive">
    <table class="ep-table" id="fds-inbox-docs-table">
        <thead>
            <tr>
                <th style="width: 40px;"><input type="checkbox" class="select-all-inbox"></th>
                <th>Documento</th>
                <th>Solicitante</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($docs)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;">No tienes solicitudes de firma pendientes.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($docs as $doc): ?>
                    <tr>
                        <td>
                            <?php if ($doc->estado === 'pendiente' && $permission !== 'read'): ?>
                                <input type="checkbox" class="inbox-doc-checkbox" value="<?php echo $doc->id; ?>" 
                                    data-url="<?php echo esc_attr(admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce') . '&t=' . time()); ?>"
                                    data-name="<?php echo esc_attr($doc->nombre_documento); ?>"
                                    data-hash="<?php echo esc_attr($doc->hash_documento_original); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="file-name-cell">
                                <i class="fa-solid fa-file-pdf color-red"></i>
                                <span>
                                    <?php echo esc_html($doc->nombre_documento); ?>
                                    <?php if (!empty($doc->observaciones)): ?>
                                        <div class="ep-document-instructions" style="margin-top: 5px; font-size: 0.85em; background: #fff8e1; border-left: 3px solid #ffc107; padding: 5px 8px; border-radius: 2px; color: #856404;">
                                            <i class="fa-solid fa-circle-info" style="margin-right: 5px;"></i>
                                            <strong>Instrucciones:</strong> <?php echo esc_html($doc->observaciones); ?>
                                        </div>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php echo esc_html($doc->solicitante_name); ?>
                        </td>
                        <td>
                            <?php echo date_i18n('d/m/Y H:i', strtotime($doc->fecha_firma)); ?>
                        </td>
                        <td>
                            <span
                                class="ep-badge <?php echo $doc->estado === 'firmado' ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo ucfirst($doc->estado); ?>
                            </span>
                        </td>
                        <td>
                            <div class="ep-actions-row-mini">
                                <?php
                                $proxy_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce') . '&t=' . time();
                                if ($doc->estado === 'pendiente'): ?>
                                    <?php if ($permission !== 'read'): ?>
                                        <button class="fds-btn-sign-now ep-btn-icon-text" data-id="<?php echo $doc->id; ?>"
                                            data-url="<?php echo esc_attr($proxy_url); ?>"
                                            data-name="<?php echo esc_attr($doc->nombre_documento); ?>"
                                            data-hash="<?php echo esc_attr($doc->hash_documento_original); ?>">
                                            <i class="fa-solid fa-pen-nib"></i> Firmar ahora
                                        </button>
                                    <?php else: ?>
                                        <span class="ep-badge badge-info"><i class="fa-solid fa-lock"></i> Pendiente de firma</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="<?php echo esc_url($proxy_url); ?>" class="ep-btn-icon-text" target="_blank">
                                        <i class="fa-solid fa-download"></i> Descargar
                                    </a>
                                <?php endif; ?>
                                <button class="fds-btn-delete-request ep-btn-icon text-red" data-id="<?php echo $doc->id; ?>"
                                    title="Eliminar de mi lista">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>