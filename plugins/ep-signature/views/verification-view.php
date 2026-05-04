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
                <p>Este documento ha sido firmado electrónicamente a través del Portal del Empleado.</p>
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

            <div class="verification-actions" style="margin-top: 20px; text-align: center;">
                <a href="<?php echo esc_url($doc->url_documento_firmado); ?>" target="_blank" class="ep-btn ep-btn-primary">
                    <i class="fa-solid fa-download"></i> Descargar Documento Original
                </a>
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
        padding: 10px 0;
        border-bottom: 1px solid #e5e7eb;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-row .label {
        font-weight: 600;
        color: #4b5563;
    }

    .detail-row .value {
        color: #111827;
    }

    .detail-row code {
        background: #e5e7eb;
        padding: 2px 6px;
        border-radius: 4px;
        font-family: monospace;
    }
</style>