<?php
/**
 * Template for the Inventory & Maintenance Application
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$is_maintenance = current_user_can('manage_options') || current_user_can('edit_others_posts'); // Adjust capability as needed
?>

<div class="ep-inventory-app">
    <div class="ep-app-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <a href="?view=dashboard" class="ep-btn-back"><i class="fa-solid fa-arrow-left"></i> Volver</a>
            <h2><i class="fa-solid fa-boxes-stacked"></i> Inventario y Mantenimiento</h2>
        </div>
        <div class="ep-app-tabs">
            <button class="ep-tab-btn active" data-tab="my-inventory">Mi Inventario</button>
            <button class="ep-tab-btn" data-tab="request-material">Solicitar Material</button>
            <?php if ($is_maintenance): ?>
                <button class="ep-tab-btn" data-tab="maintenance">Mantenimiento</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="ep-app-content">
        <!-- Tab: My Inventory -->
        <div id="my-inventory" class="ep-tab-content active">
            <div class="ep-card">
                <h3>Mis Activos Asignados</h3>
                <div id="ep-my-inventory-list">
                    <p>Cargando inventario...</p>
                </div>
            </div>
        </div>

        <!-- Tab: Request Material -->
        <div id="request-material" class="ep-tab-content">
            <div class="ep-card">
                <h3>Solicitar Nuevo Material</h3>
                <form id="ep-request-material-form">
                    <div class="form-group">
                        <label>Tipo de Material</label>
                        <select name="material_type" required>
                            <option value="">Seleccionar...</option>
                            <option value="laptop">Portátil</option>
                            <option value="monitor">Monitor</option>
                            <option value="peripheral">Periférico (Teclado/Ratón)</option>
                            <option value="mobile">Móvil</option>
                            <option value="other">Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Justificación</label>
                        <textarea name="justification" required></textarea>
                    </div>
                    <button type="submit" class="ep-btn">Enviar Solicitud</button>
                </form>
            </div>
        </div>

        <?php if ($is_maintenance): ?>
            <!-- Tab: Maintenance -->
            <div id="maintenance" class="ep-tab-content">
                <div class="ep-maintenance-grid">
                    <!-- QR Scanner -->
                    <div class="ep-card">
                        <h3><i class="fa-solid fa-qrcode"></i> Escáner QR</h3>
                        <div id="reader" style="width: 100%; min-height: 250px; background: #eee;"></div>
                        <p id="qr-result" style="margin-top: 10px; font-weight: bold;"></p>
                    </div>

                    <!-- Quick Actions -->
                    <div class="ep-card">
                        <h3>Acciones Rápidas</h3>
                        <button id="btn-assign-item" class="ep-btn full-width"><i class="fa-solid fa-user-plus"></i> Asignar
                            Artículo</button>
                        <button id="btn-generate-stickers" class="ep-btn full-width"><i class="fa-solid fa-print"></i>
                            Imprimir Pegatinas QR</button>
                    </div>

                    <!-- Generate Agreement -->
                    <div class="ep-card full-width">
                        <h3>Generar Acuerdo de Responsabilidad</h3>
                        <div class="form-group">
                            <label>ID del Artículo</label>
                            <input type="number" id="agreement-item-id" placeholder="Ej: 123">
                        </div>
                        <div class="form-group">
                            <label>Email del Empleado</label>
                            <input type="email" id="agreement-user-email" placeholder="empleado@empresa.com">
                        </div>
                        <button id="generate-agreement-btn" class="ep-btn"><i class="fas fa-file-contract"></i> Generar y
                            Descargar PDF</button>
                        <div id="agreement-result" style="margin-top: 15px;"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .ep-inventory-app {
        padding: 20px;
    }

    .ep-app-header {
        display: flex;
        justify_content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .ep-app-tabs {
        display: flex;
        gap: 10px;
    }

    .ep-tab-btn {
        background: transparent;
        border: none;
        padding: 10px 20px;
        cursor: pointer;
        font-weight: 600;
        color: #666;
        border-bottom: 2px solid transparent;
        transition: all 0.3s ease;
    }

    .ep-tab-btn.active {
        color: #0073aa;
        border-bottom-color: #0073aa;
    }

    .ep-tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }

    .ep-tab-content.active {
        display: block;
    }

    .ep-maintenance-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .full-width {
        width: 100%;
        margin-bottom: 10px;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .inventory-link {
        text-decoration: none;
        font-weight: bold;
    }

    .inventory-link.success {
        color: #28a745;
    }

    .inventory-link:hover {
        text-decoration: underline;
    }
</style>

<!-- Load html5-qrcode library -->
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Tabs Logic
        const tabs = document.querySelectorAll('.ep-tab-btn');
        const contents = document.querySelectorAll('.ep-tab-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.getAttribute('data-tab')).classList.add('active');

                // Init QR Scanner if maintenance tab is selected
                if (tab.getAttribute('data-tab') === 'maintenance') {
                    initQRScanner();
                }
            });
        });

        // Load User Inventory
        loadUserInventory();

        function loadUserInventory() {
            const listContainer = document.getElementById('ep-my-inventory-list');
            listContainer.innerHTML = '<p>Cargando inventario...</p>';

            fetch(ajaxurl + '?action=imjc_get_user_inventory')
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        if (response.data.length === 0) {
                            listContainer.innerHTML = '<p>No tienes artículos asignados.</p>';
                            return;
                        }
                        let html = '<ul class="ep-inventory-list">';
                        response.data.forEach(item => {
                            let signedHtml = '';
                            if (item.signed_agreement_url) {
                                signedHtml = `<br><a href="${item.signed_agreement_url}" target="_blank" class="inventory-link success"><i class="fas fa-file-signature"></i> Ver Acuerdo Firmado</a> <small>(${item.signed_agreement_date})</small>`;
                            }

                            html += `<li>
                                <strong>${item.name}</strong> ${signedHtml} <br>
                                <small>SKU: ${item.sku} | Serial: ${item.serial}</small>
                            </li>`;
                        });
                        html += '</ul>';
                        listContainer.innerHTML = html;
                    } else {
                        listContainer.innerHTML = '<p>Error al cargar inventario.</p>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    listContainer.innerHTML = '<p>Error de conexión.</p>';
                });
        }

        // QR Scanner Logic
        let html5QrcodeScanner;
        function initQRScanner() {
            if (html5QrcodeScanner) return;

            html5QrcodeScanner = new Html5QrcodeScanner(
                "reader", { fps: 10, qrbox: 250 }
            );

            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }

        function onScanSuccess(decodedText, decodedResult) {
            console.log(`Code matched = ${decodedText}`, decodedResult);
            document.getElementById('qr-result').innerText = "Detectado: " + decodedText;

            // Parse QR Data (Expected format: ID:123|SKU:ABC)
            // For now just alert
            alert("QR Detectado: " + decodedText);
        }

        function onScanFailure(error) {
            // handle scan failure, usually better to ignore and keep scanning.
        }

        // Assign Item Logic
        const btnAssign = document.getElementById('btn-assign-item');
        if (btnAssign) {
            btnAssign.addEventListener('click', () => {
                const itemId = prompt("ID del Artículo:");
                const userEmail = prompt("Email del Usuario:");

                if (itemId && userEmail) {
                    const formData = new FormData();
                    formData.append('action', 'imjc_assign_item');
                    formData.append('item_id', itemId);
                    formData.append('user_email', userEmail);

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: formData
                    })
                        .then(res => res.json())
                        .then(res => {
                            if (res.success) {
                                alert(res.data);
                            } else {
                                alert("Error: " + res.data);
                            }
                        });
                }
            });
        }

        // Generate Stickers Logic
        const btnStickers = document.getElementById('btn-generate-stickers');
        if (btnStickers) {
            btnStickers.addEventListener('click', () => {
                btnStickers.innerText = "Generando...";
                fetch(ajaxurl + '?action=imjc_generate_stickers')
                    .then(res => res.json())
                    .then(res => {
                        btnStickers.innerHTML = '<i class="fa-solid fa-print"></i> Imprimir Pegatinas QR';
                        if (res.success) {
                            // Open PDF in new tab
                            const pdfWindow = window.open("");
                            pdfWindow.document.write(
                                "<iframe width='100%' height='100%' src='data:application/pdf;base64, " +
                                encodeURI(res.data.pdf_base64) + "'></iframe>"
                            );
                        } else {
                            alert("Error al generar pegatinas: " + res.data);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        btnStickers.innerHTML = '<i class="fa-solid fa-print"></i> Imprimir Pegatinas QR';
                        alert("Error de conexión");
                    });
            });
        }
        // Generate Agreement
        $('#generate-agreement-btn').on('click', function () {
            var itemId = $('#agreement-item-id').val();
            var userEmail = $('#agreement-user-email').val();
            var $resultDiv = $('#agreement-result');

            if (!itemId || !userEmail) {
                alert('Por favor, introduce el ID del artículo y el email del empleado.');
                return;
            }

            $(this).prop('disabled', true).text('Generando...');

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'imjc_generate_agreement_pdf',
                    item_id: itemId,
                    user_email: userEmail
                },
                success: function (response) {
                    if (response.success) {
                        var link = document.createElement('a');
                        link.href = 'data:application/pdf;base64,' + response.data.pdf_base64;
                        link.download = 'acuerdo_responsabilidad_' + itemId + '.pdf';
                        link.click();

                        $resultDiv.html('<div class="inventory-message success"><i class="fas fa-check-circle"></i> Acuerdo generado. <a href="<?php echo home_url('?view=signature'); ?>" target="_blank" class="inventory-link">Ir a la App de Firma</a> para firmarlo.</div>');
                    } else {
                        $resultDiv.html('<div class="inventory-message error"><i class="fas fa-exclamation-circle"></i> Error: ' + response.data + '</div>');
                    }
                },
                error: function () {
                    $resultDiv.html('<div class="inventory-message error"><i class="fas fa-exclamation-circle"></i> Error de conexión.</div>');
                },
                complete: function () {
                    $('#generate-agreement-btn').prop('disabled', false).text('Generar y Descargar PDF');
                }
            });
        });

    });
</script>