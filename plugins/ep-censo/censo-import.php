<?php if (!defined('ABSPATH'))
    exit; ?>

<!-- Import Modal (Hidden by default) -->
<div id="censo-import-modal" class="ep-modal-overlay"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:99999; justify-content:center; align-items:center;">
    <div class="ep-modal-container"
        style="display:flex; flex-direction:column; background:#fff; width:90%; max-width:500px; border-radius:12px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); overflow:visible !important; opacity:1 !important; visibility:visible !important;">
        <div class="ep-modal-header">
            <h3>Importar Censo IAE</h3>
            <span id="close-import-modal" class="ep-modal-close">&times;</span>
        </div>

        <div class="ep-modal-body">
            <!-- Progress / Status Area -->
            <div id="censo-import-status" style="display:none; margin-bottom: 20px;">
                <div class="ep-notice notice-info">
                    <p id="censo-status-text">Preparando...</p>
                    <div style="background:#eee; height:10px; border-radius:5px; margin-top:5px; overflow:hidden;">
                        <div id="censo-progress-bar"
                            style="width:0%; height:100%; background:#9C0A23; transition:width 0.3s;"></div>
                    </div>
                </div>
            </div>

            <!-- Import Form -->
            <div id="censo-import-form-wrapper">
                <div class="ep-form-group">
                    <label>Año del Ejercicio:</label>
                    <input type="number" id="censo-year" class="ep-input" value="<?php echo date('Y'); ?>" min="2000"
                        max="2100">
                </div>

                <div class="ep-form-group">
                    <label>Modo de Importación:</label>
                    <select id="censo-import-action" class="ep-select">
                        <option value="merge">Actualizar sin borrar (Genera informe de cambios)</option>
                        <option value="truncate">Borrar todo y recargar (Más rápido, sin informe)</option>
                    </select>
                </div>

                <div class="ep-form-group">
                    <label>Tipo de Proceso:</label>
                    <select id="censo-import-type" class="ep-select">
                        <option value="standard">Importación Estándar (TXT AEAT - Sin IA)</option>
                        <option value="enrichment">Enriquecimiento con IA (Excel/CSV)</option>
                    </select>
                    <small id="import-type-hint" style="color:#777; display:block; margin-top:2px;">
                        Modo Estándar: Ideal para archivos .txt/.dat originales. Búsqueda por REFERENCIA.
                    </small>
                </div>

                <div class="ep-form-group">
                    <label>Archivo de Censo (Texto ancho fijo o CSV):</label>
                    <div class="ep-file-input-wrapper" id="censo-drop-zone">
                        <span class="dashicons dashicons-media-spreadsheet"
                            style="font-size: 2.5rem; width: auto; height: auto; color: #666; margin-bottom: 10px; display: block;"></span>
                        <p id="censo-file-label" style="margin: 0; font-weight: 500;">Arrastra tu archivo aquí o haz
                            clic para buscar</p>
                        <span class="ep-upload-hint"
                            style="display: block; margin-top: 5px; color: #777; font-size: 0.85rem;">Soportado: .xlsx,
                            .xls, .csv, .txt (Formato AEAT)</span>
                        <input type="file" id="censo-file" style="display:none;" accept=".txt,.csv,.xlsx,.xls">
                    </div>

                    <div style="text-align: right; margin-top: 20px;">
                        <button id="btn-start-import" class="ep-btn ep-btn-primary" type="button"
                            style="width: 100%; justify-content: center;">
                            <span class="dashicons dashicons-upload"></span> Subir y Analizar Columnas
                        </button>
                    </div>
                </div>

                <!-- AI Mapping Step -->
                <div id="censo-import-mapping" style="display:none;">
                    <h4 style="margin-top:0;">Verificar Mapeo de Columnas (IA)</h4>
                    <p style="font-size:0.9rem; color:#666; margin-bottom:15px;">
                        La IA ha analizado el archivo. Por favor, confirma que las columnas coinciden correctamente:
                    </p>
                    <div id="mapping-table-container"
                        style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 15px;">
                        <table class="ep-table" style="width: 100%; font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th>Campo Interno</th>
                                    <th>Columna en CSV</th>
                                </tr>
                            </thead>
                            <tbody id="mapping-table-body">
                                <!-- Dynamic content -->
                            </tbody>
                        </table>
                    </div>
                    <div style="text-align: right;">
                        <button id="btn-cancel-mapping" class="ep-btn ep-btn-outline">Cancelar</button>
                        <button id="btn-confirm-mapping" class="ep-btn ep-btn-primary">Confirmar e Importar</button>
                    </div>
                </div>

                <!-- Completion View -->
                <div id="censo-import-complete" style="display:none; text-align:center;">
                    <div style="font-size: 3rem; color: #2e7d32; margin-bottom: 15px;">
                        <span class="dashicons dashicons-yes" style="font-size: 3rem; width:auto; height:auto;"></span>
                    </div>
                    <h3>¡Proceso completado!</h3>
                    <p id="censo-final-stats"></p>

                    <div id="censo-report-container" style="margin-top:20px; display:none;">
                        <a id="btn-download-report" href="#" class="ep-btn ep-btn-secondary" target="_blank">
                            <span class="dashicons dashicons-media-spreadsheet"></span> Descargar Informe de Cambios
                        </a>
                    </div>

                    <button id="btn-close-modal-final" class="ep-btn ep-btn-outline"
                        style="margin-top: 20px;">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            // Open Import Modal (Moved to search view for stability)

            // Close
            $(document).on('click', '#close-import-modal, #btn-close-modal-final', function () {
                $('#censo-import-modal').hide();
                if ($('#censo-import-complete').is(':visible')) {
                    location.reload();
                }
            });

            // --- File Input Logic (Click & Drag-n-Drop) ---

            // Click trigger
            $(document).on('click', '#censo-drop-zone', function (e) {
                // Si el clic viene del input de archivo, no hacer nada (para evitar bucle infinito)
                if (e.target.id === 'censo-file') return;
                $('#censo-file').click();
            });

            // Evitar propagación si se hace clic directamente en el input o sus alrededores
            $(document).on('click', '#censo-file', function (e) {
                e.stopPropagation();
            });

            $(document).on('change', '#censo-file', function () {
                if (this.files && this.files.length > 0) {
                    $('#censo-file-label').text(this.files[0].name);
                    $('#censo-drop-zone').addClass('has-file');
                    
                    // Sugerir modo según extensión
                    var ext = this.files[0].name.split('.').pop().toLowerCase();
                    if (ext === 'xlsx' || ext === 'xls' || ext === 'csv') {
                        $('#censo-import-type').val('enrichment').trigger('change');
                    } else if (ext === 'txt' || ext === 'dat') {
                        $('#censo-import-type').val('standard').trigger('change');
                    }
                }
            });

            $(document).on('change', '#censo-import-type', function() {
                var val = $(this).val();
                if (val === 'standard') {
                    $('#import-type-hint').text('Modo Estándar: Ideal para archivos .txt/.dat originales. Búsqueda por REFERENCIA.');
                    $('#censo-file').attr('accept', '.txt,.dat');
                } else {
                    $('#import-type-hint').text('Modo Enriquecimiento: Usa IA para mapear columnas y buscar correos/webs. Búsqueda por NIF.');
                    $('#censo-file').attr('accept', '.xlsx,.xls,.csv');
                }
            });

            // Drag & Drop Events (Delegated)
            $(document).on('dragover', '#censo-drop-zone', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('drag-over');
            });

            $(document).on('dragleave', '#censo-drop-zone', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            });

            $(document).on('drop', '#censo-drop-zone', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');

                var files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    // Manually assign files to input
                    $('#censo-file')[0].files = files;
                    $('#censo-file-label').text(files[0].name);
                    $(this).addClass('has-file');
                }
            });

            // --- Import Logic (2-Phase: Upload + Process) ---
            var chunkUploadSize = 5 * 1024 * 1024; // 5MB chunks for upload
            var file, totalSize, offset, sessionId;
            var totalInserted = 0;
            var totalUpdated = 0;
            var totalBajas = 0;
            var totalIgnored = 0;
            var totalErrors = 0;
            var maxRetries = 3;
            var currentCensoNonce = '<?php echo wp_create_nonce("censo_nonce"); ?>';

            $(document).on('click', '#btn-start-import', function () {
                var fileInput = $('#censo-file')[0];
                if (!fileInput.files.length) {
                    alert('Por favor selecciona un archivo.');
                    return;
                }
                file = fileInput.files[0];

                if (!confirm('¿Estás seguro de iniciar la importación? Esto puede tardar unos minutos.')) return;

                $('#censo-import-form-wrapper').hide();
                $('#censo-import-status').show();
                $('#censo-status-text').text('Iniciando subida...');
                $('#censo-progress-bar').css('width', '0%');

                totalSize = file.size;
                offset = 0;
                totalInserted = 0;
                totalUpdated = 0;
                totalBajas = 0;
                totalIgnored = 0;
                totalErrors = 0;
                
                var ext = file.name.split('.').pop().toLowerCase();
                sessionId = 'sess_' + Date.now() + '.' + ext;

                // Start Phase 1: Upload
                uploadLoop(0);
            });

            function uploadLoop(retryCount) {
                var chunk = file.slice(offset, offset + chunkUploadSize);
                var formData = new FormData();
                formData.append('file', chunk);
                formData.append('action', 'censo_upload_chunk');
                formData.append('nonce', currentCensoNonce);
                formData.append('year', $('#censo-year').val());
                formData.append('mode', $('#censo-import-action').val());
                formData.append('import_type', $('#censo-import-type').val());
                formData.append('mapping', JSON.stringify(currentMapping));
                formData.append('chunk_start', offset);
                formData.append('session_id', sessionId);

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        if (response.success) {
                            if (response.data && response.data.new_nonce) {
                                currentCensoNonce = response.data.new_nonce;
                            }
                            offset += chunkUploadSize;
                            var percent = Math.min(100, Math.round((offset / totalSize) * 100));

                            $('#censo-progress-bar').css('width', (percent * 0.5) + '%');
                            $('#censo-status-text').text('Subiendo archivo... ' + percent + '%');

                            if (offset < totalSize) {
                                uploadLoop(0);
                            } else {
                                startMappingStep();
                            }
                        } else {
                            if (retryCount < maxRetries) {
                                console.warn('Upload failed, retrying...', retryCount + 1);
                                setTimeout(function () { uploadLoop(retryCount + 1); }, 2000);
                            } else {
                                handleError(response.data);
                            }
                        }
                    },
                    error: function () {
                        if (retryCount < maxRetries) {
                            console.warn('Network error in upload, retrying...', retryCount + 1);
                            setTimeout(function () { uploadLoop(retryCount + 1); }, 3000);
                        } else {
                            handleError('Error de conexión en subida.');
                        }
                    }
                });
            }

            var currentMapping = {};

            function startMappingStep() {
                var importType = $('#censo-import-type').val();
                console.log('Censo: Iniciando paso de mapeo. Tipo:', importType);
                
                if (importType === 'standard') {
                    $('#censo-status-text').text('Modo Estándar: Saltando IA. Preparando importación...');
                    $('#censo-progress-bar').css('width', '55%');
                    currentMapping = {}; // El parser usará offsets fijos
                    processLoop(0, 0);
                    return;
                }

                $('#censo-status-text').text('Analizando columnas con IA...');
                $('#censo-progress-bar').css('width', '55%');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'censo_ai_map_headers',
                        nonce: currentCensoNonce,
                        session_id: sessionId
                    },
                    success: function (response) {
                        console.log('Censo: Respuesta de mapeo recibida:', response);
                        if (response.success) {
                            if (response.data && response.data.new_nonce) {
                                currentCensoNonce = response.data.new_nonce;
                            }
                            // Aunque el servidor autodetecte Fixed Width, respetamos el selector del usuario
                            renderMappingUI(response.data);
                        } else {
                            console.error('Censo: Error en respuesta de mapeo:', response.data);
                            handleError(response.data);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Censo: Error AJAX en mapeo:', status, error);
                        handleError('Error al contactar con la IA para el mapeo.');
                    }
                });
            }

            function renderMappingUI(data) {
                console.log('Censo: Renderizando interfaz de mapeo...', data);

                // CRÍTICO: Asegurar que el contenedor padre esté visible
                $('#censo-import-form-wrapper').css('display', 'block');

                // Ocultar elementos del formulario inicial (hijos directos)
                $('#censo-import-form-wrapper > .ep-form-group').css('display', 'none');
                $('#btn-start-import').parent().css('display', 'none');

                // Forzar limpieza y visibilidad del contenedor de mapeo
                $('#censo-import-status').css('display', 'none');
                $('#censo-import-mapping').css({ 'display': 'block', 'visibility': 'visible', 'opacity': '1' });
                $('#mapping-table-body').empty();

                console.log('Censo: UI inicial ocultada, mostrando contenedor de mapeo');

                if (!data || !data.internal_fields) {
                    console.error('Censo: Datos incompletos recibidos de la IA');
                    handleError('Los datos recibidos para el mapeo están incompletos.');
                    return;
                }

                var tbody = $('#mapping-table-body');
                var internalFields = data.internal_fields;
                var aiMapping = data.mapping || {}; // Blindaje para mapeo vacío
                var allHeaders = data.all_headers || [];

                console.log('Censo: internal_fields count:', internalFields.length);
                console.log('Censo: aiMapping:', aiMapping);
                console.log('Censo: allHeaders:', allHeaders);

                try {
                    internalFields.forEach(function (field) {
                        if (field.name === 'ID' || field.name === 'updated_at' || field.name === 'ENRICH_STATUS' || field.name === 'ENRICH_LOG') return;

                        var row = $('<tr>');
                        row.append($('<td>').html('<strong>' + field.name + '</strong><br><small>' + (field.label || '') + '</small>'));

                        var select = $('<select class="ep-select" style="width:100%;">');
                        select.append($('<option value="">-- No importar --</option>'));

                        allHeaders.forEach(function (h) {
                            var selected = (aiMapping[field.name] === h) ? 'selected' : '';
                            select.append($('<option value="' + h + '" ' + selected + '>' + h + '</option>'));
                        });

                        select.attr('data-field', field.name);
                        row.append($('<td>').append(select));
                        tbody.append(row);
                    });
                    console.log('Censo: Filas de mapeo renderizadas exitosamente');
                } catch (e) {
                    console.error('Censo: Error al renderizar tabla de mapeo:', e);
                    handleError('Ocurrió un error al procesar las columnas del archivo.');
                    return;
                }

                // Asegurar que el contenedor es visible (forzado por CSS inline si es necesario)
                $('#censo-import-mapping').css({ 'display': 'block', 'visibility': 'visible', 'opacity': '1' });
                console.log('Censo: renderMappingUI completado correctamente. Modal visible.');
            }

            $('#btn-confirm-mapping').on('click', function () {
                currentMapping = {};
                $('#mapping-table-body select').each(function () {
                    var field = $(this).data('field');
                    var val = $(this).val();
                    if (val) currentMapping[field] = val;
                });

                if (!currentMapping['REFERENCIA'] && !currentMapping['NIF']) {
                    alert('Debes mapear al menos el campo REFERENCIA o NIF para identificar los registros.');
                    return;
                }

                $('#censo-import-mapping').hide();
                $('#censo-import-status').show();
                $('#censo-status-text').text('Procesando datos (0%)...');
                processLoop(0, 0);
            });

            $('#btn-cancel-mapping').on('click', function () {
                $('#censo-import-mapping').hide();
                $('#censo-import-form-wrapper').show();
            });

            function processLoop(filePos, retryCount) {
                var year = $('#censo-year').val();
                var mode = $('#censo-import-action').val();
                var importType = $('#censo-import-type').val();

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'censo_process_batch',
                        nonce: currentCensoNonce,
                        session_id: sessionId,
                        year: year,
                        mode: mode,
                        import_type: importType,
                        file_pos: filePos,
                        mapping: JSON.stringify(currentMapping)
                    },
                    success: function (response) {
                        if (response.success) {
                            var data = response.data;
                            if (data.new_nonce) {
                                currentCensoNonce = data.new_nonce;
                            }
                            var processPercent = data.progress_percent;
                            var totalPercent = 50 + (processPercent * 0.5);

                            $('#censo-progress-bar').css('width', totalPercent + '%');
                            $('#censo-status-text').text('Procesando registros... ' + processPercent + '%');

                            if (!data.is_done) {
                                totalInserted += (data.stats && data.stats.inserted) ? parseInt(data.stats.inserted) : 0;
                                totalUpdated += (data.stats && data.stats.updated) ? parseInt(data.stats.updated) : 0;
                                totalBajas += (data.stats && data.stats.bajas) ? parseInt(data.stats.bajas) : 0;
                                totalIgnored += (data.stats && data.stats.ignored) ? parseInt(data.stats.ignored) : 0;
                                totalErrors += (data.stats && data.stats.errors) ? parseInt(data.stats.errors) : 0;
                                processLoop(data.new_file_pos, 0);
                            } else {
                                totalInserted += (data.stats && data.stats.inserted) ? parseInt(data.stats.inserted) : 0;
                                totalUpdated += (data.stats && data.stats.updated) ? parseInt(data.stats.updated) : 0;
                                totalBajas += (data.stats && data.stats.bajas) ? parseInt(data.stats.bajas) : 0;
                                totalIgnored += (data.stats && data.stats.ignored) ? parseInt(data.stats.ignored) : 0;
                                totalErrors += (data.stats && data.stats.errors) ? parseInt(data.stats.errors) : 0;
                                finishImport(data);
                            }
                        } else {
                            if (retryCount < maxRetries) {
                                console.warn('Process batch failed, retrying...', retryCount + 1);
                                setTimeout(function () { processLoop(filePos, retryCount + 1); }, 2000);
                            } else {
                                var errorMsg = response.data || 'Error desconocido del servidor.';
                                handleError(errorMsg);
                            }
                        }
                    },
                    error: function (xhr, status, error) {
                        if (retryCount < maxRetries) {
                            console.warn('Network error in process batch, retrying...', retryCount + 1);
                            setTimeout(function () { processLoop(filePos, retryCount + 1); }, 3000);
                        } else {
                            var detailedError = 'Error de conexión (' + status + '): ' + error;
                            if (xhr.status === 403) {
                                detailedError = 'Error 403: Sesión expirada o bloqueada por el servidor.\n\nEsto suele ser un bloqueo de seguridad (WAF) o que se ha cerrado tu sesión.\nPor favor, recarga la página (F5) para obtener un nuevo token e inténtalo de nuevo.';
                                alert(detailedError);
                                location.reload();
                                return;
                            }
                            handleError(detailedError);
                        }
                    }
                });
            }

            function handleError(msg) {
                $('#censo-import-form-wrapper').show();
                $('#censo-import-status').hide();
                alert('Error: ' + (msg || 'Desconocido'));
            }

            function finishImport(data) {
                $('#censo-import-form-wrapper').show();
                // Ocultar campos de formulario para dejar sitio al reporte
                $('.ep-form-group', '#censo-import-form-wrapper').hide();
                $('#censo-import-mapping').hide();
                
                $('#censo-import-status').hide();
                $('#censo-import-complete').show();
                $('#censo-progress-bar').css('width', '100%');

                var statsHtml = 'Proceso finalizado.<br>' +
                    'Insertados: <strong>' + totalInserted + '</strong><br>' +
                    'Actualizados: <strong>' + totalUpdated + '</strong><br>' +
                    'Sin cambios (omitidos): <strong>' + totalIgnored + '</strong><br>' +
                    'Errores: <strong style="color:' + (totalErrors > 0 ? 'red' : 'inherit') + '">' + totalErrors + '</strong><br>' +
                    'Bajas detectadas: <strong>' + totalBajas + '</strong>';
                $('#censo-final-stats').html(statsHtml);

                if (data.report_url) {
                    $('#btn-download-report').attr('href', data.report_url);
                    $('#censo-report-container').show();
                } else {
                    $('#censo-report-container').hide();
                }
            }
        });
    </script>