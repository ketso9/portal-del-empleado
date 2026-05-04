<?php defined('ABSPATH') || exit; ?>

<div class="ep-table-container ep-table-responsive">
    <table class="ep-table">
        <thead>
            <tr>
                <th>Documento</th>
                <th>Destinatario</th>
                <th>Fecha Envío</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($docs)): ?>
                <tr>
                    <td colspan="5" style="text-align:center;">No has enviado ninguna solicitud de firma.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($docs as $doc): ?>
                    <tr>
                        <td>
                            <div class="file-name-cell">
                                <i class="fa-solid fa-file-pdf color-red"></i>
                                <span>
                                    <?php echo esc_html($doc->nombre_documento); ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php echo esc_html($doc->recipient_name); ?>
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
                                <?php $proxy_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce'); ?>
                                <a href="<?php echo esc_url($proxy_url); ?>" class="ep-btn-icon-text" target="_blank">
                                    <i class="fa-solid fa-download"></i> Ver PDF
                                </a>
                                <?php if ($doc->estado === 'pendiente'): ?>
                                    <button class="fds-btn-delete-request ep-btn-icon text-red" data-id="<?php echo $doc->id; ?>"
                                        title="Cancelar solicitud">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>