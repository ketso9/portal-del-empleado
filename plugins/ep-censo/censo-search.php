<?php if (!defined('ABSPATH'))
    exit; ?>

<div class="ep-container">
    <!-- Header & KPIs -->
    <div class="ep-header-section" style="margin-bottom: 20px;">
        <h2 style="color: #9C0A23;">Censo IAE - Dashboard</h2>

        <div class="ep-kpi-grid"
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 15px;">
            <!-- KPI 1: Total -->
            <div class="ep-card kpi-card">
                <h4 style="margin:0; color:#666;">Total Registros</h4>
                <div id="kpi-total" style="font-size: 2rem; font-weight: bold; color: #333; margin-bottom:10px;">...</div>
                
                <!-- Altas y Bajas (Filtros) -->
                <div style="display:flex; gap:10px; font-size:0.85rem;">
                    <div title="Nuevas de importación" class="kpi-filter" data-filter="altas_year" style="cursor:pointer; padding: 3px 6px; border-radius:4px; border:1px solid #e2e8f0; background:#f8fafc; flex:1;">
                        <span style="color:#16a34a; margin-right:4px;">🟢</span> Nuevas <span id="kpi-altas-year" style="font-weight:bold;">0</span>
                    </div>
                    <div title="Empresas de baja" class="kpi-filter" data-filter="bajas" style="cursor:pointer; padding: 3px 6px; border-radius:4px; border:1px solid #e2e8f0; background:#f8fafc; flex:1;">
                        <span style="color:#ef4444; margin-right:4px;">🔴</span> Bajas <span id="kpi-bajas" style="font-weight:bold;">0</span>
                    </div>
                </div>
            </div>

            <!-- KPI 2: Top Municipios -->
            <div class="ep-card kpi-card">
                <h4 style="margin:0; color:#666; margin-bottom: 10px;">Top Municipios</h4>
                <ul id="kpi-municipios" style="padding-left: 20px; margin: 0; font-size: 0.9em;"></ul>
            </div>

            <!-- KPI 3: Top Epígrafes -->
            <div class="ep-card kpi-card">
                <h4 style="margin:0; color:#666; margin-bottom: 10px;">Top Epígrafes</h4>
                <ul id="kpi-epigrafes" style="padding-left: 20px; margin: 0; font-size: 0.9em;"></ul>
            </div>

            <!-- KPI 4: Enriquecimiento (NUEVO) -->
            <div class="ep-card kpi-card" style="border-left: 4px solid #2c3e50;">
                <h4 style="margin:0; color:#666; margin-bottom: 10px;">Enriquecimiento</h4>
                <div style="display: flex; gap: 12px; align-items: center; margin-top: 5px; flex-wrap: wrap;">
                    <div title="Emails" class="kpi-filter" data-filter="has_email"
                        style="cursor:pointer; padding: 2px 5px; border-radius: 4px;">
                        <span class="dashicons dashicons-email" style="color:#2c3e50;"></span>
                        <span id="kpi-emails" style="font-weight:bold;">0</span>
                    </div>
                    <div title="Teléfonos" class="kpi-filter" data-filter="has_phone"
                        style="cursor:pointer; padding: 2px 5px; border-radius: 4px;">
                        <span class="dashicons dashicons-phone" style="color:#2c3e50;"></span>
                        <span id="kpi-tels" style="font-weight:bold;">0</span>
                    </div>
                    <div title="Webs" class="kpi-filter" data-filter="has_web"
                        style="cursor:pointer; padding: 2px 5px; border-radius: 4px;">
                        <span class="dashicons dashicons-admin-links" style="color:#2c3e50;"></span>
                        <span id="kpi-webs" style="font-weight:bold;">0</span>
                    </div>
                    <div title="Maps" class="kpi-filter" data-filter="has_maps"
                        style="cursor:pointer; padding: 2px 5px; border-radius: 4px;">
                        <span class="dashicons dashicons-google" style="color:#2c3e50;"></span>
                        <span id="kpi-maps" style="font-weight:bold;">0</span>
                    </div>
                </div>
                <!-- Estado del Worker de Fondo -->
                <div id="worker-status-badge"
                    style="margin-top: 10px; font-size: 0.8rem; display: flex; align-items: center; gap: 5px;">
                    <span class="worker-dot"
                        style="width: 8px; height: 8px; border-radius: 50%; background: #ccc;"></span>
                    <span id="worker-status-text">Inactivo</span>
                    <span id="worker-target-name" style="color: #666; font-style: italic; margin-left: 5px;"></span>
                </div>
            </div>

            <!-- KPI 5: Uso API y Coste (NUEVO) -->
            <div class="ep-card kpi-card" style="border-left: 4px solid #d63638;">
                <h4 style="margin:0; color:#666; margin-bottom: 8px;">Consumo y Gastos</h4>
                <div style="font-size: 0.9em; line-height: 1.4;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>Consultas Web:</span>
                        <strong id="kpi-serper">0</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Procesado IA:</span>
                        <strong id="kpi-gemini">0</strong>
                    </div>
                    <div
                        style="margin-top: 8px; padding-top: 5px; border-top: 1px solid #eee; display: flex; justify-content: space-between; font-size: 1.1em; color: #d63638;">
                        <span>Gasto Est.:</span>
                        <strong><span id="kpi-cost">0.00</span>$</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filters Panel -->
    <div class="ep-card ep-search-panel" style="padding: 20px; margin-bottom: 20px;">
        <!-- Fila 1: Búsqueda y Filtros Principales -->
        <div class="ep-search-group"
            style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9;">
            <div style="flex: 1;">
                <label
                    style="display: block; font-weight: 600; font-size: 0.85rem; color: #64748b; margin-bottom: 5px;">Búsqueda
                    General</label>
                <input type="text" id="censo-search-term" class="ep-input" placeholder="Razón, NIF o Epígrafe..."
                    style="width: 100%;">
            </div>
            <div style="flex: 1;">
                <label
                    style="display: block; font-weight: 600; font-size: 0.85rem; color: #64748b; margin-bottom: 5px;">Municipio</label>
                <input type="text" id="censo-search-municipio" class="ep-input" placeholder="Ej: Cáceres..."
                    style="width: 100%;">
            </div>
            <button id="btn-search-censo" class="ep-btn ep-btn-primary" style="height: 42px;">
                <span class="dashicons dashicons-search"></span> Buscar
            </button>
        </div>

        <!-- Fila 2: Barra de Herramientas Unificada -->
        <div class="ep-tools-toolbar-container">
            <div class="ep-toolbar-row">

                <?php if (isset($can_enrich) && $can_enrich): ?>
                <!-- Grupo IA -->
                <button id="btn-enrich-data" class="ep-btn ep-btn-primary" title="Enriquecer con IA">
                    <span class="dashicons dashicons-id-alt"></span> Enriquecer
                </button>

                <button id="btn-toggle-worker" class="ep-btn ep-btn-outline" title="Procesamiento en Segundo Plano"
                    style="color: #27ae60; border-color: #27ae60;">
                    <span class="dashicons dashicons-clock"></span> Procesado Auto
                </button>

                <div class="ep-toolbar-divider">
                </div>
                <?php endif; ?>

                <!-- Grupo Datos -->
                <button id="btn-export-censo" class="ep-btn ep-btn-outline" title="Exportar a CSV">
                    <span class="dashicons dashicons-download"></span> Exportar
                </button>

                <?php if (isset($can_import) && $can_import): ?>
                    <button id="ep-btn-open-import-unique" class="ep-btn ep-btn-outline" title="Importar Archivo">
                        <span class="dashicons dashicons-upload"></span> Importar
                    </button>
                <?php endif; ?>

                <button id="btn-refresh-table" class="ep-btn ep-btn-outline" title="Refrescar">
                    <span class="dashicons dashicons-image-rotate"></span>
                </button>

                <div class="ep-toolbar-divider">
                </div>

                <!-- Grupo Visibilidad -->
                <div class="ep-column-selector">
                    <button id="btn-toggle-columns" class="ep-btn ep-btn-outline"
                        title="Gestionar Visibilidad de Columnas">
                        <span class="dashicons dashicons-visibility"></span> Columnas
                    </button>
                </div>

                <?php if (isset($can_enrich) && $can_enrich): ?>
                    <button id="ep-btn-open-settings-unique" class="ep-btn ep-btn-outline" title="Ajustes">
                        <span class="dashicons dashicons-admin-settings" style="color: #9c0a23;"></span> Ajustes
                    </button>

                    <button id="btn-delete-selected" class="ep-btn ep-btn-danger" style="display: none;">
                        <span class="dashicons dashicons-trash"></span> Eliminar Seleccionados
                    </button>

                    <!-- Legacy Hidden Support -->
                    <div style="display:none;">
                        <button id="btn-sync-epigrafes"></button>
                        <button id="btn-sync-agrupaciones"></button>
                        <button id="btn-reset-errors"></button>
                        <button id="btn-reset-no-evidence"></button>
                        <button id="btn-reindex-all"></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- Enrichment Progress -->
        <div id="enrich-progress-bar"
            style="display:none; margin-top:15px; background:#eee; height:8px; border-radius:4px; overflow:hidden;">
            <div id="enrich-progress-fill" style="width:0%; height:100%; background:#2c3e50; transition:width 0.3s;">
            </div>
        </div>
        <div id="enrich-status-text"
            style="display:none; font-size:0.85rem; color:#666; margin-top:5px; text-align:right;"></div>
        <div id="enrich-current-item"
            style="display:none; font-size:0.75rem; color:#999; margin-top:2px; text-align:right; font-style: italic;">
        </div>
    </div>

    <!-- Results Table -->
    <div id="censo-results-container" class="ep-card" style="padding: 0; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="ep-table wp-list-table widefat fixed striped" style="width: 100%; margin-bottom:0;">
                <thead>
                    <tr>
                        <?php if (isset($can_enrich) && $can_enrich): ?>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="censo-select-all" title="Seleccionar todos" />
                        </th>
                        <?php endif; ?>
                        <th class="col-ref sortable" data-sort="REFERENCIA">Referencia</th>
                        <th class="col-nif sortable" data-sort="NIF">NIF</th>
                        <th class="col-razon sortable" data-sort="RAZON">Razón Social</th>
                        <th class="col-mun sortable" data-sort="MUNICIPIOFISC">Municipio</th>
                        <th class="col-control" style="display:none;">Control</th>
                        <th class="col-agrupacion sortable" data-sort="AGRUPACION_ELECTORAL">Agrupación Electoral</th>
                        <th class="col-desc">Descripción Epígrafe</th>
                        <th class="col-limpio sortable" data-sort="EPIGRAFE_LIMPIO">Epígrafe Limpio</th>
                        <th class="col-alta sortable" data-sort="FECHAINICIO" style="display:none;">Alta</th>
                        <th class="col-email">Email</th>
                        <th class="col-tel">Teléfono</th>
                        <th class="col-web">Web</th>
                        <th class="col-info">Info</th>
                    </tr>
                </thead>
                <tbody id="censo-results-body">
                    <tr>
                        <td colspan="10" style="text-align:center; padding: 20px;">Cargando censo...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination controls -->
        <div class="ep-pagination"
            style="display:flex; justify-content:space-between; align-items:center; padding:15px; border-top:1px solid #eee; background:#fafafa;">
            <div class="ep-pagination-info" style="font-size:0.9rem; color:#666;">
                Mostrando <span id="pag-start">0</span>-<span id="pag-end">0</span> de <span id="pag-total">0</span>
                resultados
            </div>
            <div class="ep-pagination-controls" style="display:flex; gap:10px; align-items:center;">
                <select id="censo-limit" class="ep-select" style="padding: 2px 8px; font-size: 0.9rem;">
                    <option value="50">50 por página</option>
                    <option value="100">100 por página</option>
                    <option value="500">500 por página</option>
                    <option value="1000">1000 por página</option>
                </select>

                <button id="btn-prev-page" class="ep-btn ep-btn-outline" style="padding: 4px 10px;" disabled>
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </button>

                <span id="censo-page-num" style="margin:0 5px; font-weight:600;">Página 1</span>

                <button id="btn-next-page" class="ep-btn ep-btn-outline" style="padding: 4px 10px;" disabled>
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
            </div>
        </div>

    </div>


</div>

<script>
    jQuery(document).ready(function ($) {

        // ---- TOOLTIP CSS (inyectado una vez) ----
        if (!$('#censo-tt-style').length) {
            $('head').append('<style id="censo-tt-style">' +
                '#censo-tt-box { position:fixed; z-index:99999; pointer-events:none; background:#1e293b; color:#f1f5f9; border-radius:10px; padding:10px 14px; font-size:0.82rem; line-height:1.6; box-shadow:0 8px 24px rgba(0,0,0,0.25); min-width:220px; max-width:300px; transition: opacity .15s; opacity:0; }' +
                '#censo-tt-box.visible { opacity:1; }' +
                '.censo-tt-row { display:flex; align-items:center; gap:6px; margin-bottom:2px; }' +
                '.censo-tt-icon { font-size:1.1em; flex-shrink:0; }' +
                '.censo-tt-cell { cursor:default; border-bottom:1px dashed #cbd5e1 !important; }' +
            '</style>');
            $('body').append('<div id="censo-tt-box"></div>');
        }

        // ---- TOOLTIP EVENTS (delegados) ----
        $(document).on('mouseenter', '.censo-tt-cell', function (e) {
            var content = $(this).attr('data-censo-tt');
            if (!content) return;
            var box = $('#censo-tt-box');
            box.html(content).addClass('visible');
            posTooltip(e);
        }).on('mousemove', '.censo-tt-cell', function (e) {
            posTooltip(e);
        }).on('mouseleave', '.censo-tt-cell', function () {
            $('#censo-tt-box').removeClass('visible');
        });

        function posTooltip(e) {
            var box = $('#censo-tt-box');
            var x = e.clientX + 14;
            var y = e.clientY + 14;
            if (x + 310 > $(window).width())  x = e.clientX - 310;
            if (y + box.outerHeight() + 10 > $(window).height()) y = e.clientY - box.outerHeight() - 10;
            box.css({ left: x + 'px', top: y + 'px' });
        }
        var censo_nonce = '<?php echo wp_create_nonce("censo_nonce"); ?>';
        var canWriteBasic = <?php echo (isset($can_write_basic) && $can_write_basic) ? 'true' : 'false'; ?>;
        var canWriteTotal = <?php echo (isset($can_write_total) && $can_write_total) ? 'true' : 'false'; ?>;
        var canEnrich = <?php echo (isset($can_enrich) && $can_enrich) ? 'true' : 'false'; ?>;
        var currentPage = 1;
        var totalPages = 1;
        var limit = 50;
        var sortBy = 'id';
        var sortDir = 'DESC';
        var isSearching = false;
        var filterType = ''; // Fase 6

        function updateCensoNonce(newNonce) {
            if (newNonce) {
                censo_nonce = newNonce;
                console.log('Nonce actualizado:', censo_nonce.substring(0, 5) + '...');
            }
        }

        console.log('Censo Search Script Inicializado');

        // Init
        loadStats();
        

        
        performSearch(1); // Auto-load

        // Listeners con .off() para evitar duplicados y IDs únicos
        $('#ep-btn-open-import-unique').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Botón Importar Pulsado. Click origin:', e.target.id || e.target.tagName);

            var modal = $('#censo-import-modal');
            if (modal.length === 0) {
                console.error('ERROR: #censo-import-modal no encontrado');
                return;
            }

            $('#censo-import-form-wrapper').show();
            $('#censo-import-status').hide();
            $('#censo-import-complete').hide();

            modal.css({
                'display': 'flex',
                'visibility': 'visible',
                'opacity': '1',
                'z-index': '99999'
            }).show();
        });

        $('#btn-search-censo').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            filterType = ''; // Reset KPI filter on manual search
            $('.kpi-filter').css({ 'background': 'transparent', 'box-shadow': 'none' });
            performSearch(1);
        });

        $('#btn-export-censo').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            // Mostrar modal de exportación avanzada
            var modalHtml =
                '<div id="modal-export-censo" class="ep-modal" style="display:block;">' +
                '    <div class="ep-modal-content" style="max-width: 520px;">' +
                '        <div class="ep-modal-header" style="display:flex; justify-content: space-between; align-items: center;">' +
                '            <h3 style="margin:0;">Configurar Exportación CSV</h3>' +
                '            <span class="ep-modal-toggle-close" style="cursor:pointer; font-size: 24px; color: #999; line-height:1;">&times;</span>' +
                '        </div>' +
                '        <div class="ep-modal-body" style="padding: 20px;">' +

                '            <div style="margin-bottom: 20px;">' +
                '                <h4 style="margin-top:0;">1. Ámbito de Exportación</h4>' +
                '                <label style="display:block; margin-bottom:5px;">' +
                '                    <input type="radio" name="export-scope" value="filtered" checked> Exportar registros filtrados (vista actual)' +
                '                </label>' +
                '                <label style="display:block;">' +
                '                    <input type="radio" name="export-scope" value="all"> Exportar censo completo' +
                '                </label>' +
                '            </div>' +

                '            <div style="margin-bottom: 20px;">' +
                '                <h4 style="margin-top:0;">2. Columnas a Incluir</h4>' +
                '                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px; font-size: 0.9em;">' +
                '                    <label><input type="checkbox" class="export-col" value="REFERENCIA" checked> Referencia</label>' +
                '                    <label><input type="checkbox" class="export-col" value="NIF" checked> NIF</label>' +
                '                    <label><input type="checkbox" class="export-col" value="RAZON" checked> Razón Social</label>' +
                '                    <label><input type="checkbox" class="export-col" value="MUNICIPIOFISC" checked> Municipio</label>' +
                '                    <label><input type="checkbox" class="export-col" value="CONTROL" checked> Control</label>' +
                '                    <label><input type="checkbox" class="export-col" value="AGRUPACION_ELECTORAL" checked> Agrupación</label>' +
                '                    <label><input type="checkbox" class="export-col" value="DESCRIPCION_EPIGRAFE" checked> Descripción</label>' +
                '                    <label><input type="checkbox" class="export-col" value="EPIGRAFE_LIMPIO" checked> Epígrafe</label>' +
                '                    <label><input type="checkbox" class="export-col" value="FECHAINICIO" checked> Fecha Alta</label>' +
                '                    <label><input type="checkbox" class="export-col" value="EMAIL_ENRICH" checked> Email</label>' +
                '                    <label><input type="checkbox" class="export-col" value="TELEFONO_ENRICH" checked> Teléfono</label>' +
                '                    <label><input type="checkbox" class="export-col" value="WEB_ENRICH" checked> Web</label>' +
                '                    <label><input type="checkbox" class="export-col" value="MAPS_LINK" checked> Maps/Info</label>' +
                '                </div>' +
                '            </div>' +

                '            <div style="margin-bottom: 5px; padding-top: 15px; border-top: 2px dashed #e2e8f0;">' +
                '                <h4 style="margin-top:0; margin-bottom: 10px;">3. Últimos Informes de Cambios</h4>' +
                '                <div id="export-reports-loader" style="color:#999; font-size:0.85em; padding: 8px 0;">' +
                '                    <span class="spinner is-active" style="float:none; margin:0 6px 0 0; vertical-align:middle;"></span> Cargando informes disponibles...' +
                '                </div>' +
                '                <div id="export-reports-content" style="display:none;">' +
                '                    <div id="export-report-import" style="display:none; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px;">' +
                '                        <div>' +
                '                            <span class="dashicons dashicons-upload" style="color:#9C0A23; vertical-align:middle;"></span>' +
                '                            <strong style="font-size:0.9em;">Última Importación</strong>' +
                '                            <span id="report-import-date" style="font-size:0.8em; color:#64748b; margin-left:6px;"></span>' +
                '                        </div>' +
                '                        <a id="report-import-link" href="#" target="_blank" class="ep-btn ep-btn-outline" style="font-size:0.8em; padding:4px 10px;">' +
                '                            <span class="dashicons dashicons-download" style="vertical-align:middle; font-size:14px;"></span> Descargar' +
                '                        </a>' +
                '                    </div>' +
                '                    <div id="export-report-enrich" style="display:none; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:8px 12px;">' +
                '                        <div>' +
                '                            <span class="dashicons dashicons-id-alt" style="color:#16a34a; vertical-align:middle;"></span>' +
                '                            <strong style="font-size:0.9em;">Enriquecimiento de Hoy</strong>' +
                '                            <span id="report-enrich-date" style="font-size:0.8em; color:#64748b; margin-left:6px;"></span>' +
                '                        </div>' +
                '                        <a id="report-enrich-link" href="#" target="_blank" class="ep-btn ep-btn-outline" style="font-size:0.8em; padding:4px 10px; color:#16a34a; border-color:#16a34a;">' +
                '                            <span class="dashicons dashicons-download" style="vertical-align:middle; font-size:14px;"></span> Descargar' +
                '                        </a>' +
                '                    </div>' +
                '                    <p id="export-reports-empty" style="display:none; font-size:0.85em; color:#94a3b8; margin:0; padding:6px 0;">No hay informes de cambios disponibles aún. Se generan automáticamente al importar o enriquecer.</p>' +
                '                </div>' +
                '            </div>' +

                '            <div style="text-align: right; margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">' +
                '                <button class="ep-btn ep-btn-outline ep-modal-toggle-close" style="margin-right:10px;">Cancelar</button>' +
                '                <button id="btn-confirm-export" class="ep-btn ep-btn-primary">Descargar CSV</button>' +
                '            </div>' +
                '        </div>' +
                '    </div>' +
                '</div>';
            $('body').append(modalHtml);

            // Cargar informes disponibles vía AJAX
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_get_last_reports',
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data ? response.data.new_nonce : null);
                $('#export-reports-loader').hide();
                $('#export-reports-content').show();

                var hasAny = false;
                if (response.success) {
                    if (response.data.import_url) {
                        hasAny = true;
                        $('#report-import-date').text('(' + response.data.import_date + ')');
                        $('#report-import-link').attr('href', response.data.import_url);
                        $('#export-report-import').show();
                    }
                    if (response.data.enrich_url) {
                        hasAny = true;
                        $('#report-enrich-date').text('(' + response.data.enrich_date + ')');
                        $('#report-enrich-link').attr('href', response.data.enrich_url);
                        $('#export-report-enrich').show();
                    }
                }
                if (!hasAny) {
                    $('#export-reports-empty').show();
                }
            }).fail(function () {
                $('#export-reports-loader').html('<span style="color:#ef4444; font-size:0.85em;">No se pudieron cargar los informes.</span>');
            });

            // Cerrar modal
            $(document).on('click', '.ep-modal-toggle-close', function () {
                $('#modal-export-censo').remove();
            });

            // Confirmar Exportación
            $('#btn-confirm-export').on('click', function () {
                var scope = $('input[name="export-scope"]:checked').val();
                var columns = [];
                $('.export-col:checked').each(function () {
                    columns.push($(this).val());
                });

                if (columns.length === 0) {
                    alert('Seleccione al menos una columna para exportar.');
                    return;
                }

                var btn = $(this);
                var originalHtml = btn.html();
                btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Procesando...');

                var term = $('#censo-search-term').val();
                var mun = $('#censo-search-municipio').val();

                // La descarga va en dos pasos. Primero se valida por AJAX, porque
                // el CSV baja en streaming y en cuanto salen sus cabeceras ya no se
                // puede responder con un JSON de error. Despues se pide el volcado
                // con un formulario contra un iframe oculto: asi el navegador se
                // descarga el archivo sin salir de la pagina y sin que el servidor
                // llegue a guardar nada.
                var exportParams = {
                    scope: scope,
                    columns: columns,
                    term: (scope === 'filtered' ? term : ''),
                    municipio: (scope === 'filtered' ? mun : ''),
                    filter_type: (scope === 'filtered' ? filterType : ''),
                    nonce: censo_nonce
                };

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', $.extend({ action: 'censo_export_check' }, exportParams), function (response) {
                    btn.prop('disabled', false).html(originalHtml);

                    if (!response.success) {
                        alert('Error al exportar: ' + (response.data || 'Error desconocido'));
                        return;
                    }

                    updateCensoNonce(response.data.new_nonce);
                    exportParams.nonce = response.data.new_nonce;

                    var $frame = $('#censo-export-frame');
                    if (!$frame.length) {
                        $frame = $('<iframe>', { id: 'censo-export-frame', name: 'censo-export-frame' })
                            .css('display', 'none').appendTo('body');
                    }

                    var $form = $('<form>', {
                        method: 'POST',
                        action: '<?php echo admin_url('admin-ajax.php'); ?>',
                        target: 'censo-export-frame'
                    }).css('display', 'none');

                    $form.append($('<input>', { type: 'hidden', name: 'action', value: 'censo_export_csv' }));
                    $.each(exportParams, function (key, value) {
                        if (key === 'columns') {
                            $.each(value, function (i, col) {
                                $form.append($('<input>', { type: 'hidden', name: 'columns[]', value: col }));
                            });
                        } else {
                            $form.append($('<input>', { type: 'hidden', name: key, value: value }));
                        }
                    });

                    $form.appendTo('body').submit().remove();
                    $('#modal-export-censo').remove();
                }).fail(function (xhr) {
                    btn.prop('disabled', false).html(originalHtml);
                    alert('Error de servidor al exportar.');
                });
            });
        });

        $('#btn-sync-epigrafes').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('¿Desea actualizar las descripciones de los epígrafes para los registros existentes? Esto puede tardar si hay muchos registros.')) return;

            var btn = $(this);
            var originalHtml = btn.html();
            btn.prop('disabled', true);

            var totalProcessed = 0;

            function runSyncBatch() {
                btn.html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Procesando... (' + totalProcessed + ')');

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_sync_epigrafes',
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data.new_nonce);
                    if (response.success) {
                        totalProcessed += response.data.count;
                        if (response.data.finished) {
                            btn.prop('disabled', false).html(originalHtml);
                            alert('Sincronización completada. Se han procesado ' + totalProcessed + ' registros.');
                            performSearch(currentPage);
                        } else {
                            runSyncBatch(); // Siguiente lote
                        }
                    } else {
                        btn.prop('disabled', false).html(originalHtml);
                        alert('Error: ' + (response.data || 'Error desconocido'));
                    }
                }).fail(function () {
                    btn.prop('disabled', false).html(originalHtml);
                    alert('Error de conexión tras procesar ' + totalProcessed + ' registros.');
                });
            }

            runSyncBatch();
        });

        $('#btn-enrich-data').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('¿Desea iniciar el proceso de enriquecimiento para el lote actual de registros pendientes?')) return;

            var btn = $(this);
            var originalHtml = btn.html();
            var progressContainer = $('#enrich-progress-bar');
            var progressFill = $('#enrich-progress-fill');
            var statusText = $('#enrich-status-text');

            btn.prop('disabled', true);
            progressContainer.show();
            statusText.show().text('Iniciando enriquecimiento...');

            var totalEnriched = 0;
            var isEnriching = true;
            var activeWorkers = 0;
            var maxWorkers = 2; // Aumentado a 2 hilos para GCP
            var consecutiveErrors = 0;
            var maxRetries = 3;

            function runWorker() {
                if (!isEnriching) return;
                activeWorkers++;

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_enrich_batch',
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data ? response.data.new_nonce : null);
                    activeWorkers--;

                    if (response.success) {
                        consecutiveErrors = 0; // Resetear errores al tener éxito
                        totalEnriched += response.data.count;
                        statusText.text('Enriqueciendo... ' + totalEnriched + ' registros.');

                        // Mostrar ítems procesados en tiempo real (Lote completo)
                        if (response.data.processed && response.data.processed.length > 0) {
                            var itemsHtml = response.data.processed.map(function (item) {
                                return '• ' + item.razon + ' (' + item.nif + ')';
                            }).join('<br>');
                            $('#enrich-current-item').show().html(itemsHtml);
                        }

                        // Animación de progreso
                        var currentWidth = parseFloat(progressFill[0].style.width) || 0;
                        progressFill.css('width', Math.min(currentWidth + 2, 98) + '%');

                        // Refresco automático
                        if (totalEnriched % 50 === 0) {
                            loadStats();
                            performSearch(currentPage, true);
                        }

                        if (response.data.finished) {
                            isEnriching = false;
                            // Mostrar enlace al informe de enriquecimiento si lo hay
                            if (response.data.report_url) {
                                statusText.html('¡Completado! <a href="' + response.data.report_url + '" target="_blank" style="color:#2c3e50; font-weight:600;"><span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle;"></span> Descargar informe CSV</a>');
                            }
                        } else if (response.data.count === 0 && response.data.waiting) {
                            setTimeout(runWorker, 3000);
                        } else if (response.data.count === 0) {
                            isEnriching = false;
                        } else {
                            // Pausa de 2 segundos entre lotes para GCP (Mucha mayor velocidad)
                            setTimeout(runWorker, 2000);
                        }
                    } else {
                        consecutiveErrors++;
                        console.error('Error batch (Intento ' + consecutiveErrors + '):', response.data);
                        
                        if (consecutiveErrors <= maxRetries) {
                            var waitTime = consecutiveErrors * 10000;
                            statusText.text('⚠️ Error temporal. Reintentando (' + consecutiveErrors + '/' + maxRetries + ') en ' + (waitTime/1000) + 's...');
                            setTimeout(runWorker, waitTime);
                        } else {
                            isEnriching = false;
                            alert('Se ha detenido el proceso tras ' + maxRetries + ' errores consecutivos: ' + (response.data || 'Error desconocido'));
                        }
                    }

                    // Si todos los trabajadores han terminado
                    if (!isEnriching && activeWorkers === 0) {
                        btn.prop('disabled', false).html(originalHtml);
                        progressFill.css('width', '100%');
                        statusText.text('¡Completado! ' + totalEnriched + ' registros actualizados.');
                        setTimeout(function () {
                            progressContainer.fadeOut();
                            performSearch(currentPage);
                        }, 2000);
                    }
                }
                ).fail(function (xhr, status, error) {
                    activeWorkers--;
                    consecutiveErrors++;
                    
                    console.warn('Fallo de red/timeout (Intento ' + consecutiveErrors + '):', status, error);

                    if (isEnriching && consecutiveErrors <= maxRetries) {
                        var waitTime = consecutiveErrors * 10000;
                        statusText.text('📡 Fallo de conexión. Reintentando (' + consecutiveErrors + '/' + maxRetries + ') en ' + (waitTime/1000) + 's...');
                        setTimeout(runWorker, waitTime);
                    } else {
                        isEnriching = false;
                        if (activeWorkers === 0) {
                            btn.prop('disabled', false).html(originalHtml);
                            alert('Error de red persistente o timeout del servidor. Proceso detenido.');
                        }
                    }
                });
            }

            // Lanzar los trabajadores iniciales
            for (var i = 0; i < maxWorkers; i++) {
                setTimeout(runWorker, i * 500); // Pequeño delay inicial para escalonar
            }
        });

        $('#censo-search-term, #censo-search-municipio').on('keypress', function (e) {
            if (e.which == 13) {
                filterType = ''; // Fase 6: Reset filter on manual search
                $('.kpi-filter').css({ 'background': 'transparent', 'box-shadow': 'none' });
                performSearch(1);
            }
        });

        $('#censo-limit').on('change', function () {
            limit = parseInt($(this).val());
            performSearch(1);
        });

        $(document).on('click', '#btn-prev-page', function (e) {
            e.preventDefault();
            if (!isSearching && currentPage > 1) performSearch(currentPage - 1);
        });

        $(document).on('click', '#btn-next-page', function (e) {
            e.preventDefault();
            if (!isSearching && currentPage < totalPages) performSearch(currentPage + 1);
        });

        // Refrescar tabla manual
        $('#btn-refresh-table').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var btn = $(this);
            btn.addClass('fa-spin').prop('disabled', true);
            performSearch(currentPage);
            loadStats();
            setTimeout(function () {
                btn.removeClass('fa-spin').prop('disabled', false);
            }, 600);
        });

        // Worker Toggle
        $('#btn-toggle-worker').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var btn = $(this);
            var isActive = btn.hasClass('ep-btn-active');

            btn.prop('disabled', true);

            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_toggle_worker',
                active: !isActive,
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                btn.prop('disabled', false);
                if (response.success) {
                    updateWorkerUI(response.data.status);
                    if (response.data.status === 'active') {
                        startBackgroundPolling();
                    } else {
                        stopBackgroundPolling();
                    }
                } else {
                    alert('Error: ' + (response.data || 'No se pudo cambiar el estado del worker.'));
                }
            }).fail(function () {
                btn.prop('disabled', false);
                alert('Error de conexión o permisos al intentar activar el worker.');
            });
        });

        // Manejar Filtros de KPI (Fase 6)
        $('.kpi-filter').on('click', function () {
            var filter = $(this).data('filter');

            // Si ya está activo, lo quitamos
            if (filterType === filter) {
                filterType = '';
                $('.kpi-filter').css({ 'background': 'transparent', 'box-shadow': 'none' });
            } else {
                filterType = filter;
                $('.kpi-filter').css({ 'background': 'transparent', 'box-shadow': 'none' });
                $(this).css({ 'background': '#eef2f7', 'box-shadow': 'inset 0 0 0 1px #cbd5e1' });
            }

            performSearch(1);
        });

        var pollingInterval = null;
        function startBackgroundPolling() {
            if (pollingInterval) return;
            pollingInterval = setInterval(function () {
                loadStats();
                performSearch(currentPage, true);
            }, 10000); // Cada 10s en modo background
        }

        function stopBackgroundPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        function updateWorkerUI(status, processingName = '') {
            var btn = $('#btn-toggle-worker');
            var dot = $('.worker-dot');
            var text = $('#worker-status-text');
            var nameSpan = $('#worker-target-name');

            if (status === 'active') {
                btn.addClass('ep-btn-active').attr('title', 'Worker de Fondo Activo. Click para parar.');
                dot.css('background', '#27ae60');
                text.text('Worker Activo (Procesando...)');
                if (processingName) {
                    nameSpan.text('Investigando: ' + processingName).show();
                } else {
                    nameSpan.hide();
                }
                startBackgroundPolling();
            } else if (status === 'completed') {
                btn.removeClass('ep-btn-active').attr('title', 'Completado. Click para reiniciar.');
                dot.css('background', '#2ecc71');
                text.text('¡Todo Enriquecido!');
                nameSpan.hide();
                stopBackgroundPolling();
            } else {
                btn.removeClass('ep-btn-active').attr('title', 'Worker Desactivado. Click para activar.');
                dot.css('background', '#ccc');
                text.text('Worker Desactivado');
                nameSpan.hide();
                stopBackgroundPolling();
            }
        }

        // Toggle Column Modal (Solución para evitar recortes por overflow)
        $('#btn-toggle-columns').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            // Si el modal no existe, lo creamos
            if ($('#modal-column-visibility').length === 0) {
                var modalHtml =
                    '<div id="modal-column-visibility" class="ep-modal" style="display:block;">' +
                    '    <div class="ep-modal-content" style="max-width: 400px;">' +
                    '        <div class="ep-modal-header" style="display:flex; justify-content: space-between; align-items: center;">' +
                    '            <h3 style="margin:0;"><span class="dashicons dashicons-visibility" style="margin-top:4px;"></span> Visibilidad de Columnas</h3>' +
                    '            <span class="ep-modal-col-close" style="cursor:pointer; font-size: 24px; color: #999; line-height:1;">&times;</span>' +
                    '        </div>' +
                    '        <div class="ep-modal-body" style="padding: 20px;">' +
                    '            <p style="margin-top:0; color:#666; font-size:0.9em;">Seleccione las columnas que desea visualizar en la tabla:</p>' +
                    '            <div class="column-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-ref" checked> Referencia</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-nif" checked> NIF</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-razon" checked> Razón Social</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-mun" checked> Municipio</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-desc" checked> Descripción</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-limpio" checked> Epígrafe</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-control"> Control</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-agrupacion" checked> Agrupación</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-alta"> Fecha Alta</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-email" checked> Email</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-tel" checked> Teléfono</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-web" checked> Web</label>' +
                    '                <label><input type="checkbox" class="col-toggle" data-col="col-info" checked> Info</label>' +
                    '            </div>' +
                    '            <div style="text-align: right; margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">' +
                    '                <button class="ep-btn ep-btn-primary ep-modal-col-close">Cerrar y Aplicar</button>' +
                    '            </div>' +
                    '        </div>' +
                    '    </div>' +
                    '</div>';
                $('body').append(modalHtml);

                // Inicializar estados según la visibilidad actual
                $('.col-toggle').each(function () {
                    var colClass = $(this).data('col');
                    $(this).prop('checked', $('th.' + colClass).is(':visible'));
                });
            } else {
                $('#modal-column-visibility').show();
            }
        });

        // Manejar cierre del modal de columnas
        $(document).on('click', '.ep-modal-col-close', function () {
            $('#modal-column-visibility').hide();
        });

        // Toggle Columns Visibility
        $('.col-toggle').on('change', function () {
            var colClass = $(this).data('col');
            var isVisible = $(this).is(':checked');
            $('.' + colClass).toggle(isVisible);

            // Re-render table to ensure cells match
            if (isVisible) {
                // Si acabamos de mostrarla, forzamos que se aplique a los TD ya renderizados
                performSearch(currentPage);
            }
        });

        $(document).on('click', function (e) {
            if (e.target.id === 'modal-column-visibility') {
                $('#modal-column-visibility').hide();
            }
        });

        // Sorting con Delegación para mayor robustez
        $(document).on('click', '.sortable', function (e) {
            e.preventDefault();
            if (isSearching) return;

            var newSort = $(this).data('sort');
            if (sortBy === newSort) {
                sortDir = (sortDir === 'ASC') ? 'DESC' : 'ASC';
            } else {
                sortBy = newSort;
                sortDir = 'ASC';
            }

            $('.sortable').removeClass('asc desc');
            $(this).addClass(sortDir === 'ASC' ? 'asc' : 'desc');

            performSearch(1);
        });

        function performSearch(page, silent = false) {
            if (isSearching && !silent) return; // Evitar disparos dobles

            var term = $('#censo-search-term').val();
            var mun = $('#censo-search-municipio').val();

            currentPage = page;
            limit = parseInt($('#censo-limit').val());
            isSearching = true;

            // UI Loading state
            if (!silent) {
                $('#censo-results-body').html('<tr><td colspan="15" style="text-align:center; padding:30px;"><span class="spinner is-active" style="float:none;"></span> Cargando datos...</td></tr>');
            }
            $('#btn-prev-page, #btn-next-page').prop('disabled', true);

            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_search',
                term: term,
                municipio: mun,
                filter_type: filterType, // Fase 6
                page: currentPage,
                limit: limit,
                sort_by: sortBy,
                sort_dir: sortDir,
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                isSearching = false;
                if (response.success) {
                    console.log('Censo Search Response:', response);
                    var data = response.data;
                    


                    renderTable(data.results, data.debug);
                    updatePagination(data);
                } else {
                    $('#censo-results-body').html('<tr><td colspan="15" style="text-align:center; color:red;">Error al cargar datos.</td></tr>');
                }
            }).fail(function () {
                isSearching = false;
                $('#censo-results-body').html('<tr><td colspan="15" style="text-align:center; color:red;">Error de conexión.</td></tr>');
            });
        }

        function renderTable(results, debug) {
            if (!results || results.length === 0) {
                var html = '<tr><td colspan="15" style="text-align:center; padding:30px;">';
                html += '<p style="margin-bottom:10px;">No se encontraron resultados.</p>';

                html += '</td></tr>';
                $('#censo-results-body').html(html);
                return;
            }

            // Get current visibility state with defaults
            var visibleCols = {
                'col-ref': true, 'col-nif': true, 'col-razon': true, 'col-mun': true,
                'col-agrupacion': true, 'col-desc': true, 'col-limpio': true,
                'col-email': true, 'col-tel': true, 'col-web': true, 'col-info': true,
                'col-control': false, 'col-alta': false // Hidden by default
            };

            $('.col-toggle').each(function () {
                visibleCols[$(this).data('col')] = $(this).is(':checked');
            });

            var rows = '';
            results.forEach(function (item) {
                // --- Construir contenido del tooltip ---
                var fuenteLabel = 'Importación Inicial';
                var fuenteColor = '#64748b';
                var fuenteIcon  = '📄';
                if (item.FUENTE_IMPORTACION === 'TXT-AEAT') {
                    fuenteLabel = 'Importación AEAT (TXT)'; fuenteColor = '#1d4ed8'; fuenteIcon = '🏛️';
                } else if (item.FUENTE_IMPORTACION === 'CSV-Enriquecimiento') {
                    fuenteLabel = 'Importación CSV'; fuenteColor = '#0369a1'; fuenteIcon = '📊';
                } else if (item.FUENTE_IMPORTACION === 'Enriquecido-IA') {
                    fuenteLabel = 'Enriquecido con IA'; fuenteColor = '#7c3aed'; fuenteIcon = '🤖';
                } else if (item.ENRICH_STATUS === 'Enriched') {
                    fuenteLabel = 'Enriquecido con IA'; fuenteColor = '#7c3aed'; fuenteIcon = '🤖';
                } else if (item.FUENTE_IMPORTACION) {
                    fuenteLabel = 'Archivo: ' + item.FUENTE_IMPORTACION;
                    fuenteColor = '#0f766e';
                    fuenteIcon = '📁';
                }

                var estadoColor = item.ESTADO_INTERNO === 'Baja' ? '#ef4444' : '#16a34a';
                var estadoIcon  = item.ESTADO_INTERNO === 'Baja' ? '🔴' : '🟢';

                var enrichMap = {
                    'Enriched':  { label: 'Enriquecido', color: '#16a34a' },
                    'Pending':   { label: 'Pendiente',   color: '#d97706' },
                    'Not Found': { label: 'No encontrado', color: '#64748b' },
                    'Error':     { label: 'Error',        color: '#ef4444' },
                    'Processing':{ label: 'Procesando',  color: '#2563eb' }
                };
                var enrichInfo = enrichMap[item.ENRICH_STATUS] || { label: item.ENRICH_STATUS || '-', color: '#64748b' };

                var fechaImp = item.ULTIMA_IMPORTACION ? item.ULTIMA_IMPORTACION.substring(0,16) : '-';

                var tooltipHtml =
                    '<div class="censo-tt-row"><span class="censo-tt-icon">' + fuenteIcon + '</span>' +
                    '<span style="color:' + fuenteColor + '; font-weight:600;">' + fuenteLabel + '</span></div>' +
                    '<div class="censo-tt-row">' + estadoIcon + ' Estado: <strong style="color:' + estadoColor + '">' + (item.ESTADO_INTERNO || 'Activo') + '</strong></div>' +
                    '<div class="censo-tt-row">🔍 Enriquecimiento: <strong style="color:' + enrichInfo.color + '">' + enrichInfo.label + '</strong></div>' +
                    '<div class="censo-tt-row" style="color:#94a3b8; font-size:0.8em;">🕐 Última importación: ' + fechaImp + '</div>';

                var ttAttr = 'data-censo-tt="' + tooltipHtml.replace(/"/g, '&quot;') + '"';

                rows += '<tr' + (item.ESTADO_INTERNO === 'Baja' ? ' style="opacity:0.6; background:#fef2f2;"' : '') + '>';
                if (canEnrich) {
                    rows += '<td style="text-align: center;"><input type="checkbox" class="censo-select-item" value="' + item.id + '" /></td>';
                }
                rows += '<td class="col-ref censo-tt-cell" ' + ttAttr + (!visibleCols['col-ref'] ? ' style="display:none;"' : '') + '>' + (item.REFERENCIA || '-') + '</td>';
                rows += '<td class="col-nif censo-tt-cell" ' + ttAttr + (!visibleCols['col-nif'] ? ' style="display:none;"' : '') + '>' + (item.NIF || '-') + '</td>';
                rows += '<td class="col-razon censo-tt-cell" ' + ttAttr + (!visibleCols['col-razon'] ? ' style="display:none;"' : '') + '><strong>' + (item.RAZON || '-') + '</strong></td>';
                rows += '<td class="col-mun" ' + (!visibleCols['col-mun'] ? 'style="display:none;"' : '') + '>' + (item.MUNICIPIOFISC || '-') + '</td>';
                rows += '<td class="col-control" ' + (!visibleCols['col-control'] ? 'style="display:none;"' : '') + '>' + (item.CONTROL || '-') + '</td>';
                rows += '<td class="col-agrupacion" ' + (!visibleCols['col-agrupacion'] ? 'style="display:none;"' : '') + '><span style="font-size:0.85em; color:#444;">' + (item.AGRUPACION_ELECTORAL || '-') + '</span></td>';
                rows += '<td class="col-desc" ' + (!visibleCols['col-desc'] ? 'style="display:none;"' : '') + '>' + (item.DESCRIPCION_EPIGRAFE || '-') + '</td>';
                rows += '<td class="col-limpio" ' + (!visibleCols['col-limpio'] ? 'style="display:none;"' : '') + '><span class="ep-badge ep-badge-info">' + (item.EPIGRAFE_LIMPIO || '-') + '</span></td>';
                rows += '<td class="col-alta" ' + (!visibleCols['col-alta'] ? 'style="display:none;"' : '') + '>' + (item.FECHAINICIO || '-') + '</td>';
                var editableClassEmailPhone = canWriteBasic ? ' editable-cell' : '';
                var editableClassWeb = canWriteTotal ? ' editable-cell' : '';
                rows += '<td class="col-email' + editableClassEmailPhone + '" data-id="' + item.id + '" data-field="EMAIL_ENRICH" ' + (!visibleCols['col-email'] ? 'style="display:none;"' : '') + '>' + (item.EMAIL_ENRICH || '-') + '</td>';
                rows += '<td class="col-tel' + editableClassEmailPhone + '" data-id="' + item.id + '" data-field="TELEFONO_ENRICH" ' + (!visibleCols['col-tel'] ? 'style="display:none;"' : '') + '>' + (item.TELEFONO_ENRICH || '-') + '</td>';
                rows += '<td class="col-web' + editableClassWeb + '" data-id="' + item.id + '" data-field="WEB_ENRICH" ' + (!visibleCols['col-web'] ? 'style="display:none;"' : '') + '>' + (item.WEB_ENRICH ? '<a href="' + item.WEB_ENRICH + '" target="_blank">Web</a>' : '-') + '</td>';
                rows += '<td class="col-info" ' + (!visibleCols['col-info'] ? 'style="display:none;"' : '') + '>';
                if (item.MAPS_LINK) {
                    rows += '<a href="' + item.MAPS_LINK + '" target="_blank" title="Ficha de Google Maps" style="color: #4285F4;"><span class="dashicons dashicons-location"></span></a> ';
                }
                if (item.SEARCH_DATA) {
                    rows += '<a href="#" class="btn-view-search-data" data-id="' + item.id + '" title="Ver fragmentos de búsqueda" style="color: #666; margin-left: 5px;"><span class="dashicons dashicons-info"></span></a>';
                }
                if (!item.MAPS_LINK && !item.SEARCH_DATA) {
                    rows += '-';
                }
                rows += '</td>';
                rows += '</tr>';
            });
            $('#censo-results-body').html(rows);
        }

        function updatePagination(data) {
            var total = data.total;
            totalPages = Math.ceil(total / limit);

            // Info text
            var start = (currentPage - 1) * limit + 1;
            if (total === 0) start = 0;
            var end = Math.min(start + limit - 1, total);

            $('#pag-start').text(start);
            $('#pag-end').text(end);
            $('#pag-total').text(total);
            $('#censo-page-num').text('Página ' + currentPage + ' de ' + (totalPages || 1));

            // Buttons
            $('#btn-prev-page').prop('disabled', currentPage <= 1);
            $('#btn-next-page').prop('disabled', currentPage >= totalPages);
        }

        function loadStats() {
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_get_stats',
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                if (response.success) {
                    var d = response.data;
                    $('#kpi-total').text(d.total);
                    $('#kpi-altas-year').text(d.altas_year || 0);
                    $('#kpi-bajas').text(d.bajas || 0);

                    // Nuevos KPIs de enriquecimiento
                    $('#kpi-emails').text(d.emails || 0);
                    $('#kpi-tels').text(d.telefonos || 0);
                    $('#kpi-webs').text(d.webs || 0);
                    $('#kpi-maps').text(d.maps || 0);

                    // Nuevos KPIs de uso y coste
                    $('#kpi-serper').text(d.serper_usage || 0);
                    $('#kpi-gemini').text(d.gemini_usage || 0);
                    $('#kpi-cost').text(d.est_cost || '0.00');

                    var topMun = '';
                    d.municipios.forEach(function (m) {
                        topMun += '<li>' + m.name + ' (' + m.count + ')</li>';
                    });
                    $('#kpi-municipios').html(topMun);

                    var topEpi = '';
                    d.epigrafes.forEach(function (e) {
                        topEpi += '<li>' + e.name + ' (' + e.count + ')</li>';
                    });
                    $('#kpi-epigrafes').html(topEpi);

                    // Actualizar UI del Worker
                    if (d.worker_status) {
                        updateWorkerUI(d.worker_status, d.processing_name);
                    }
                }
            });
        }
        // Reset errores
        $('#btn-reset-errors').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('⚠️ REINTENTAR ERRORES\n\nEsto reseteará SOLO los registros con estado "Error" para que se vuelvan a procesar.\n\n✅ Los datos existentes (email, teléfono, web) están protegidos y NO se sobrescribirán.\n\n¿Continuar?')) return;
            var btn = $(this);
            btn.prop('disabled', true).addClass('fa-spin');

            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_reset_errors',
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                btn.prop('disabled', false).removeClass('fa-spin');
                if (response.success) {
                    alert('Errores reseteados: ' + response.data.count + ' registros volverán a procesarse.');
                    loadStats();
                    performSearch(1);
                } else {
                    alert('Error: ' + (response.data || 'No se pudo resetear.'));
                }
            });
        });

        // Resetear registros sin evidencias para reprocesado completo
        $('#btn-reset-no-evidence').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('⚠️ RECUPERAR MAPS (CID)\n\nEsto pondrá en cola los registros "Enriched" que NO tienen un link directo de Google Maps (CID) para volver a buscar su ubicación.\n\n✅ Los datos de email, teléfono y web están PROTEGIDOS y no se perderán.\n\n⚡ Consumirá créditos de IA.\n\n¿Continuar?')) {
                return;
            }

            var $btn = $(this);
            $btn.addClass('ep-btn-loading').prop('disabled', true);

            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_reset_no_evidence',
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                $btn.removeClass('ep-btn-loading').prop('disabled', false);
                if (response.success) {
                    alert('✅ Éxito: ' + response.data.count + ' registros puestos en cola para recuperar Maps.\nLos datos de contacto existentes están protegidos.');
                    loadStats();
                    performSearch(1);
                } else {
                    alert('Error: ' + (response.data || 'No se pudo resetear.'));
                }
            });
        });

        // Sincronizar Agrupaciones (Fase 7)
        $('#btn-sync-agrupaciones').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('¿Desea sincronizar las Agrupaciones Electorales para los registros existentes? Se procesarán registros en lotes.')) return;

            var btn = $(this);
            var originalHtml = btn.html();
            btn.prop('disabled', true);

            var totalProcessed = 0;

            function runSyncAgrupBatch() {
                btn.html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Procesando... (' + totalProcessed + ')');

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_sync_agrupaciones',
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data.new_nonce);
                    if (response.success) {
                        totalProcessed += response.data.count;
                        if (response.data.finished) {
                            btn.prop('disabled', false).html(originalHtml);
                            alert('Sincronización de agrupaciones completada. Se han procesado ' + totalProcessed + ' registros.');
                            performSearch(currentPage);
                        } else {
                            runSyncAgrupBatch(); // Siguiente lote
                        }
                    } else {
                        btn.prop('disabled', false).html(originalHtml);
                        alert('Error: ' + (response.data || 'Error desconocido'));
                    }
                }).fail(function () {
                    btn.prop('disabled', false).html(originalHtml);
                    alert('Error de conexión tras procesar ' + totalProcessed + ' registros.');
                });
            }

            runSyncAgrupBatch();
        });

        // Re-indexar todo el Censo (SEARCH_DATA)
        $('#btn-reindex-all').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('¿Desea regenerar los índices de búsqueda para todos los registros? Esto permitirá buscar por email, teléfono y otros campos en registros antiguos.')) return;

            var btn = $(this);
            var progressContainer = $('#enrich-progress-bar');
            var progressFill = $('#enrich-progress-fill');
            var statusText = $('#enrich-status-text');

            btn.prop('disabled', true);
            progressContainer.show();
            statusText.show().text('Iniciando re-indexación...');

            var totalProcessed = 0;

            function runReindexBatch(offset) {
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_reindex',
                    offset: offset,
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data.new_nonce);
                    if (response.success) {
                        totalProcessed += response.data.count;
                        statusText.text('Re-indexando... ' + totalProcessed + ' registros.');

                        // Animación de progreso (estimada)
                        var currentWidth = parseFloat(progressFill[0].style.width) || 0;
                        progressFill.css('width', Math.min(currentWidth + 5, 98) + '%');

                        if (response.data.finished) {
                            btn.prop('disabled', false);
                            progressFill.css('width', '100%');
                            statusText.text('¡Indexación completada! ' + totalProcessed + ' registros reparados.');
                            setTimeout(function () {
                                progressContainer.fadeOut();
                                performSearch(currentPage);
                            }, 2000);
                        } else {
                            runReindexBatch(response.data.new_offset);
                        }
                    } else {
                        btn.prop('disabled', false);
                        alert('Error: ' + (response.data || 'Error desconocido'));
                    }
                }).fail(function () {
                    btn.prop('disabled', false);
                    alert('Error de conexión durante la re-indexación.');
                });
            }

            runReindexBatch(0);
        });

        // Modal para Ver Search Data (Con delegación dentro del ready para asegurar $ y preventDefault)
        $(document).on('click', '.btn-view-search-data', function (e) {
            e.preventDefault();
            var rowId = $(this).data('id');

            var modalHtml =
                '<div id="modal-search-data" class="ep-modal" style="display:block;">' +
                '    <div class="ep-modal-content" style="max-width: 800px;">' +
                '        <div class="ep-modal-header" style="display:flex; justify-content: space-between; align-items: center;">' +
                '            <h3 style="margin:0;">Evidencias de Búsqueda</h3>' +
                '            <div style="display:flex; align-items:center;">' +
                '                <a id="modal-maps-link" href="#" target="_blank" class="button button-secondary" style="display:none; margin-right: 15px; color: #4285F4; border-color: #4285F4;">' +
                '                    <span class="dashicons dashicons-location" style="margin-top:4px;"></span> Ver en Google Maps' +
                '                </a>' +
                '                <span class="ep-modal-close" style="cursor:pointer; font-size: 24px; color: #999; line-height:1;">&times;</span>' +
                '            </div>' +
                '        </div>' +
                '        <div class="ep-modal-body" style="background: #f9f9f9; padding: 20px;">' +
                '            <div id="search-data-content" style="white-space: pre-wrap; font-family: monospace; font-size: 13px; max-height: 500px; overflow-y: auto; background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px; line-height: 1.4;"> Cargando evidencias... </div>' +
                '        </div>' +
                '    </div>' +
                '</div>';

            $('body').append(modalHtml);

            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_get_search_data',
                id: rowId,
                nonce: censo_nonce
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                if (response.success) {
                    $('#search-data-content').text(response.data.search_data || 'No hay fragmentos guardados para este registro.');
                    if (response.data.maps_link) {
                        $('#modal-maps-link').attr('href', response.data.maps_link).show();
                    }
                } else {
                    $('#search-data-content').text('Error al cargar datos: ' + (response.data || 'Error desconocido'));
                }
            });
        });

        $(document).on('click', '.ep-modal-close, #modal-search-data', function (e) {
            if (e.target === this || $(e.target).hasClass('ep-modal-close')) {
                $('#modal-search-data').remove();
            }
        });

        // ====================================
        // BULK DELETE FUNCTIONALITY
        // ====================================

        // Checkbox "Seleccionar todos"
        $('#censo-select-all').on('change', function () {
            $('.censo-select-item').prop('checked', this.checked);
            toggleDeleteButton();
        });

        // Checkboxes individuales
        $(document).on('change', '.censo-select-item', function () {
            toggleDeleteButton();
            const total = $('.censo-select-item').length;
            const checked = $('.censo-select-item:checked').length;
            $('#censo-select-all').prop('checked', total > 0 && checked === total);
        });

        // Mostrar/ocultar botón de eliminar según selección
        function toggleDeleteButton() {
            const count = $('.censo-select-item:checked').length;
            if (count > 0) {
                $('#btn-delete-selected').show().html('<span class="dashicons dashicons-trash"></span> Eliminar Seleccionados (' + count + ')');
            } else {
                $('#btn-delete-selected').hide();
            }
        }

        // Acción de eliminar seleccionados
        $('#btn-delete-selected').on('click', function () {
            const ids = $('.censo-select-item:checked').map(function () {
                return $(this).val();
            }).get();

            if (ids.length === 0) {
                alert('No hay registros seleccionados');
                return;
            }

            if (!confirm('¿Estás seguro de que quieres eliminar ' + ids.length + ' registro(s)?\n\nEsta acción no se puede deshacer.')) {
                return;
            }

            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'censo_delete_multiple',
                nonce: censo_nonce,
                ids: ids
            }, function (response) {
                updateCensoNonce(response.data.new_nonce);
                if (response.success) {
                    alert(response.data.message || 'Registros eliminados correctamente');
                    // Desmarcar checkbox general
                    $('#censo-select-all').prop('checked', false);
                    // Recargar resultados
                    performSearch();
                } else {
                    alert('Error: ' + (response.data || 'Error al eliminar registros'));
                }
            }).fail(function () {
                alert('Error de comunicación con el servidor');
            });
        });

        // ====================================
        // INLINE EDITING LOGIC
        // ====================================
        $(document).on('click', '.editable-cell', function (e) {
            var field = $(this).data('field');
            if (field === 'WEB_ENRICH' && !canWriteTotal) return;
            if ((field === 'EMAIL_ENRICH' || field === 'TELEFONO_ENRICH') && !canWriteBasic) return;
            // Si ya hay un input, no hacer nada
            if ($(this).find('input').length > 0) return;

            var $cell = $(this);
            var originalValue = $cell.text() === '-' ? '' : $cell.text();
            var id = $cell.data('id');
            var field = $cell.data('field');

            var $input = $('<input type="text" class="cell-edit-input">').val(originalValue);
            $cell.html($input);
            $input.focus();

            // Guardar al perder el foco o pulsar Enter
            $input.on('blur keyup', function (e) {
                if (e.type === 'keyup' && e.keyCode !== 13 && e.keyCode !== 27) return;

                if (e.keyCode === 27) { // Escape: cancelar
                    $cell.text(originalValue || '-');
                    return;
                }

                var newValue = $(this).val();
                if (newValue === originalValue) {
                    $cell.text(originalValue || '-');
                    return;
                }

                // Guardar vía AJAX
                $cell.addClass('ep-btn-loading');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'censo_update_field',
                    id: id,
                    field: field,
                    value: newValue,
                    nonce: censo_nonce
                }, function (response) {
                    updateCensoNonce(response.data.new_nonce);
                    $cell.removeClass('ep-btn-loading');
                    if (response.success) {
                        $cell.text(newValue || '-');
                    } else {
                        alert('Error: ' + (response.data || 'No se pudo guardar'));
                        $cell.text(originalValue || '-');
                    }
                }).fail(function () {
                    $cell.removeClass('ep-btn-loading');
                    alert('Error de conexión');
                    $cell.text(originalValue || '-');
                });
            });
        });

    }); // Cierre del Ready
</script>