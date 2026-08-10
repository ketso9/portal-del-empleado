<?php
defined('ABSPATH') || exit;
?>
<div class="ep-signature-app">
    <div class="ep-app-header">
        <div class="header-content">
            <i class="fa-solid fa-shield-check color-blue"></i>
            <h2>Verificación de Documento</h2>
        </div>
        <a href="?view=signature" class="ep-btn ep-btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Volver a Firma
        </a>
    </div>

    <div class="ep-card verification-result">
        <?php if ($doc): ?>
            <div class="verification-status success">
                <i class="fa-solid fa-circle-check"></i>
                <h3>Documento Válido</h3>
                <p>Este documento ha sido firmado electrónicamente a través del Portal Cámara de Comercio de Cáceres.</p>
            </div>

            <div class="verification-details">
                <div class="detail-row">
                    <span class="label">Documento:</span>
                    <span class="value"><?php echo esc_html($doc->nombre_archivo_original); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Firmante:</span>
                    <span class="value"><?php echo esc_html($doc->nombre_firmante); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Fecha de Firma:</span>
                    <span class="value"><?php echo date_i18n('d/m/Y H:i:s', strtotime($doc->fecha_firma)); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Código CSV:</span>
                    <span class="value"><code><?php echo esc_html($doc->csv_documento); ?></code></span>
                </div>
            </div>

            <div class="verification-actions" style="margin-top: 25px; text-align: center;">
                <?php if (is_user_logged_in()): ?>
                    <?php 
                    $download_url = admin_url('admin-ajax.php') . '?action=ep_app_signature&sub_action=serve_doc&id=' . $doc->id . '&nonce=' . wp_create_nonce('ep_signature_nonce') . '&t=' . time();
                    ?>
                    <a href="<?php echo esc_url($download_url); ?>" target="_blank" class="ep-btn ep-btn-primary">
                        <i class="fa-solid fa-download"></i> Descargar Documento Original
                    </a>
                <?php else: ?>
                    <div style="background-color: #f3f4f6; border-left: 4px solid #3b82f6; padding: 15px; border-radius: 6px; display: inline-block; text-align: left; max-width: 100%;">
                        <p style="margin: 0; color: #1f2937; font-weight: 600; font-size: 14px;">
                            <i class="fa-solid fa-lock" style="color: #3b82f6; margin-right: 8px;"></i> Descarga protegida por privacidad
                        </p>
                        <p style="margin: 5px 0 0 0; color: #4b5563; font-size: 13px;">
                            <?php 
                            $m365_login_url = add_query_arg(
                                array(
                                    'redirect_to' => home_url($_SERVER['REQUEST_URI'])
                                ), 
                                home_url('/')
                            );
                            ?>
                            Para descargar el documento firmado original, debe <a href="<?php echo esc_url($m365_login_url); ?>" style="color: #2563eb; text-decoration: underline; font-weight: 500;">iniciar sesión</a> en el portal.
                        </p>
                        <p style="margin: 8px 0 0 0; padding-top: 8px; border-top: 1px dashed #d1d5db; color: #4b5563; font-size: 12px; line-height: 1.4;">
                            <i class="fa-solid fa-circle-info" style="color: #4b5563; margin-right: 6px;"></i> Si usted es un usuario externo, puede solicitar una copia de dicho documento en <a href="mailto:info@camaracaceres.es" style="color: #2563eb; text-decoration: underline; font-weight: 500;">info@camaracaceres.es</a>.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="verification-status error">
                <i class="fa-solid fa-circle-xmark"></i>
                <h3>Documento No Encontrado</h3>
                <p>No se ha podido verificar la autenticidad de este código CSV en nuestro sistema.</p>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a href="?view=signature" class="ep-btn ep-btn-primary">Ir a Firma Electrónica</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .verification-result {
        max-width: 600px;
        margin: 20px auto;
        padding: 30px;
    }

    .verification-status {
        text-align: center;
        margin-bottom: 30px;
        padding: 20px;
        border-radius: 8px;
    }

    .verification-status i {
        font-size: 48px;
        margin-bottom: 15px;
    }

    .verification-status.success {
        background-color: #ecfdf5;
        color: #059669;
    }

    .verification-status.error {
        background-color: #fef2f2;
        color: #dc2626;
    }

    .verification-details {
        background: #f9fafb;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        padding: 12px 0;
        border-bottom: 1px solid #e5e7eb;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-row .label {
        font-weight: 600;
        color: #4b5563;
        flex-shrink: 0;
    }

    .detail-row .value {
        color: #111827;
        text-align: right;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .detail-row code {
        background: #e5e7eb;
        padding: 4px 8px;
        border-radius: 4px;
        font-family: monospace;
        font-size: 13px;
        word-break: break-all;
        overflow-wrap: anywhere;
        display: inline-block;
        max-width: 100%;
        text-align: left;
    }

    @media (max-width: 576px) {
        .detail-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }
        .detail-row .value {
            text-align: left;
            width: 100%;
        }
        .detail-row code {
            display: block;
            width: 100%;
            box-sizing: border-box;
        }
    }
</style>