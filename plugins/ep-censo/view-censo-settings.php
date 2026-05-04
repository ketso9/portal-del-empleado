<?php
if (!defined('ABSPATH'))
    exit;
$serper_key = get_option(CensoConfig::OPTION_SERPER_KEY);
$gemini_key = get_option(CensoConfig::OPTION_GEMINI_KEY);
$maps_key = get_option(CensoConfig::OPTION_MAPS_KEY);
$maps_limit = get_option(CensoConfig::OPTION_MAPS_DAILY_LIMIT, 100);
$usage_key = 'ep_censo_maps_usage_' . date('Y-m-d');
$current_usage = get_option($usage_key, 0);
$max_budget = get_option(CensoConfig::OPTION_MAX_BUDGET, 250);
?>
<div id="censo-settings-modal" class="ep-modal" style="display:none;">
    <div class="ep-modal-content" style="max-width: 650px;">
        <div class="ep-modal-header">
            <h3 style="display:flex; align-items:center; gap:10px;">
                <span class="dashicons dashicons-admin-settings" style="color: #9c0a23;"></span>
                Configuración y Mantenimiento del Censo
            </h3>
            <span class="ep-close-modal">&times;</span>
        </div>

        <!-- Pestañas simples para organizar -->
        <div class="ep-modal-tabs" style="display: flex; background: #f9f9f9; border-bottom: 1px solid #eee;">
            <div class="ep-modal-tab active" data-tab="api-settings"
                style="padding: 12px 20px; cursor: pointer; border-bottom: 2px solid #9c0a23; font-weight: 600; color: #9c0a23;">
                APIs y Límites
            </div>
            <div class="ep-modal-tab" data-tab="maintenance-tools"
                style="padding: 12px 20px; cursor: pointer; border-bottom: 2px solid transparent; color: #666;">
                Herramientas Avanzadas
            </div>
        </div>

        <div class="ep-modal-body" style="padding: 25px;">

            <!-- Tab 1: Configuración de APIs -->
            <div id="tab-api-settings" class="ep-tab-content">
                <div class="ep-form-group">
                    <label>Serper.dev API Key (Búsqueda)</label>
                    <input type="password" id="serper-api-key" class="ep-input"
                        value="<?php echo esc_attr($serper_key); ?>" placeholder="Ingrese su clave de Serper.dev">
                    <small class="ep-help-text">Obtenga una gratis en <a href="https://serper.dev/"
                            target="_blank">serper.dev</a></small>
                </div>

                <div class="ep-form-group" style="margin-top:15px;">
                    <label>Gemini API Key (Extracción)</label>
                    <input type="password" id="gemini-api-key" class="ep-input"
                        value="<?php echo esc_attr($gemini_key); ?>" placeholder="Ingrese su clave de Gemini">
                    <small class="ep-help-text">Obtenga una gratis en <a href="https://aistudio.google.com/"
                            target="_blank">Google AI Studio</a></small>
                </div>

                <div class="ep-form-group" style="margin-top:15px;">
                    <label>Google Maps/Cloud API Key</label>
                    <input type="password" id="maps-api-key" class="ep-input" value="<?php echo esc_attr($maps_key); ?>"
                        placeholder="Ingrese su clave de Google Cloud">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                    <div style="background: #fff8f8; padding: 15px; border-radius: 8px; border: 1px solid #ffebeb;">
                        <label
                            style="color: #d63638; font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 10px;">LÍMITE
                            DIARIO MAPS</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" id="maps-daily-limit" class="ep-input"
                                value="<?php echo esc_attr($maps_limit); ?>" style="width: 80px; padding: 5px;">
                            <span style="font-size: 0.8rem; color: #666;">peticiones</span>
                        </div>
                        <div style="margin-top: 10px; font-size: 0.75rem;">
                            Uso hoy: <?php echo (int) $current_usage; ?> / <?php echo (int) $maps_limit; ?>
                            <div
                                style="width: 100%; background: #eee; height: 4px; border-radius: 2px; margin-top: 4px;">
                                <div
                                    style="width: <?php echo min(100, ($current_usage / max(1, $maps_limit)) * 100); ?>%; background: #d63638; height: 100%; border-radius: 2px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="background: #f0f7ff; padding: 15px; border-radius: 8px; border: 1px solid #d9eaff;">
                        <label
                            style="color: #2271b1; font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 10px;">PRESUPUESTO
                            ESTIMADO</label>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <input type="number" id="max-budget" class="ep-input"
                                value="<?php echo esc_attr($max_budget); ?>" style="width: 80px; padding: 5px;"
                                step="0.1">
                            <span style="font-size: 0.8rem; color: #666;">€ (Límite GCP)</span>
                        </div>
                        <small style="font-size: 0.7rem; color: #666; display: block; margin-top: 5px;">El proceso se
                            detendrá al alcanzar este coste.</small>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Herramientas de Mantenimiento -->
            <div id="tab-maintenance-tools" class="ep-tab-content" style="display:none;">
                <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">Utilice estas herramientas para forzar
                    la sincronización de datos o recuperar registros incompletos.</p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <button id="btn-sync-epigrafes-settings" class="ep-btn ep-btn-outline"
                        style="width: 100%; justify-content: flex-start; text-align: left; padding: 12px;">
                        <span class="dashicons dashicons-update" style="color: #9c0a23;"></span>
                        <div style="display: flex; flex-direction: column; align-items: flex-start;">
                            <span style="font-size: 0.9rem;">Sincronizar Epígrafes</span>
                            <small style="font-weight: normal; font-size: 0.75rem; color: #888;">Actualiza descripciones
                                IAE</small>
                        </div>
                    </button>

                    <button id="btn-sync-agrupaciones-settings" class="ep-btn ep-btn-outline"
                        style="width: 100%; justify-content: flex-start; text-align: left; padding: 12px;">
                        <span class="dashicons dashicons-groups" style="color: #6d28d9;"></span>
                        <div style="display: flex; flex-direction: column; align-items: flex-start;">
                            <span style="font-size: 0.9rem;">Sincronizar Agrupaciones</span>
                            <small style="font-weight: normal; font-size: 0.75rem; color: #888;">Asignar grupos
                                electorales</small>
                        </div>
                    </button>

                    <button id="btn-reset-errors-settings" class="ep-btn ep-btn-outline"
                        style="width: 100%; justify-content: flex-start; text-align: left; padding: 12px;">
                        <span class="dashicons dashicons-undo" style="color: #d63638;"></span>
                        <div style="display: flex; flex-direction: column; align-items: flex-start;">
                            <span style="font-size: 0.9rem;">Reintentar Errores</span>
                            <small style="font-weight: normal; font-size: 0.75rem; color: #888;">Reiniciar registros
                                fallidos</small>
                        </div>
                    </button>

                    <button id="btn-reset-no-evidence-settings" class="ep-btn ep-btn-outline"
                        style="width: 100%; justify-content: flex-start; text-align: left; padding: 12px;">
                        <span class="dashicons dashicons-admin-links" style="color: #4285F4;"></span>
                        <div style="display: flex; flex-direction: column; align-items: flex-start;">
                            <span style="font-size: 0.9rem;">Recuperar Maps</span>
                            <small style="font-weight: normal; font-size: 0.75rem; color: #888;">Buscar evidencias
                                faltantes</small>
                        </div>
                    </button>
                </div>

                <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #eee;">
                    <h4 style="margin: 0 0 10px 0; font-size: 0.9rem; color: #333;">Desarrollador / Pruebas</h4>
                    <button id="btn-reset-notfound-settings" class="ep-btn ep-btn-outline"
                        style="color: #2271b1; border-color: #2271b1;">
                        <span class="dashicons dashicons-controls-repeat"></span> Probar Lote (500 'No encontrados')
                    </button>
                </div>
            </div>

            <div id="settings-save-status"
                style="margin-top:20px; padding: 10px; border-radius: 6px; display:none; text-align: center; font-weight: 600;">
            </div>
        </div>

        <div class="ep-modal-footer"
            style="padding: 15px 25px; background: #fafafa; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px;">
            <button class="ep-btn ep-btn-outline ep-close-modal">Cancelar</button>
            <button id="btn-save-api-settings" class="ep-btn ep-btn-primary">Guardar Configuración</button>
        </div>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            var censo_nonce = '<?php echo wp_create_nonce("censo_nonce"); ?>';

            function updateCensoNonce(newNonce) {
                if (newNonce) {
                    censo_nonce = newNonce;
                }
            }
            // Manejo de Pestañas
            $('.ep-modal-tab').on('click', function () {
                var tabId = $(this).data('tab');
                $('.ep-modal-tab').css({ 'border-bottom-color': 'transparent', 'color': '#666', 'font-weight': 'normal' });
                $(this).css({ 'border-bottom-color': '#9c0a23', 'color': '#9c0a23', 'font-weight': '600' });
                $('.ep-tab-content').hide();
                $('#tab-' + tabId).show();
            });

            // Abrir modal (ID Único para evitar conflictos)
            $(document).on('click', '#ep-btn-open-settings-unique', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $('#censo-settings-modal').fadeIn(200);
            });

            $('.ep-close-modal').on('click', function () {
                $('#censo-settings-modal').fadeOut(200);
            });

            // Reintentar Errores
            $('#btn-reset-errors-settings').on('click', function () {
                if (!confirm('¿Seguro que quieres volver a intentar los registros que dieron error o están vacíos?')) return;
                var btn = $(this);
                btn.prop('disabled', true).css('opacity', '0.5');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_reset_errors',
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data.new_nonce);
                    if (response.success) {
                        alert('Reseteados ' + response.data.count + ' registros.');
                        location.reload();
                    }
                }).always(function () { btn.prop('disabled', false).css('opacity', '1'); });
            });

            // Sincronizar Epígrafes
            $('#btn-sync-epigrafes-settings').on('click', function () {
                // Disparar el click del botón original si aún está en el DOM, o replicar lógica
                if ($('#btn-sync-epigrafes').length) {
                    $('#btn-sync-epigrafes').trigger('click');
                } else {
                    // Replicar lógica de censo-search si es necesario
                    alert('Iniciando sincronización desde la vista principal...');
                    $('#censo-settings-modal').fadeOut();
                    $('#btn-sync-epigrafes').trigger('click');
                }
            });

            // Sincronizar Agrupaciones
            $('#btn-sync-agrupaciones-settings').on('click', function () {
                if ($('#btn-sync-agrupaciones').length) {
                    $('#btn-sync-agrupaciones').trigger('click');
                }
            });

            // Recuperar Maps
            $('#btn-reset-no-evidence-settings').on('click', function () {
                if ($('#btn-reset-no-evidence').length) {
                    $('#btn-reset-no-evidence').trigger('click');
                }
            });

            // Probar Lote 500
            $('#btn-reset-notfound-settings').on('click', function () {
                if (!confirm('¿Reseteamos 500 registros para pruebas?')) return;
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_reset_notfound',
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data.new_nonce);
                    if (response.success) { location.reload(); }
                });
            });

            $('#btn-save-api-settings').on('click', function () {
                var btn = $(this);
                var status = $('#settings-save-status');
                btn.prop('disabled', true).text('Guardando...');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_save_api_settings',
                    nonce: censo_nonce,
                    serper_key: $('#serper-api-key').val(),
                    gemini_key: $('#gemini-api-key').val(),
                    maps_key: $('#maps-api-key').val(),
                    maps_limit: $('#maps-daily-limit').val(),
                    max_budget: $('#max-budget').val()
                }, function (response) {
                    status.show();
                    if (response.success) {
                        updateCensoNonce(response.data.new_nonce);
                        status.css({ 'color': '#2e7d32', 'background': '#e8f5e9' }).text(response.data.message || response.data);
                        setTimeout(function () { $('#censo-settings-modal').fadeOut(); }, 1500);
                    } else {
                        status.css({ 'color': '#d32f2f', 'background': '#ffebee' }).text(response.data);
                    }
                });
            });
        });
    </script>
</div>