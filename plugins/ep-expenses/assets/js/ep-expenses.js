/**
 * Lógica Frontend para la Mini App de Control de Gastos y Dietas (ep-expenses)
 */
(function ($) {
    'use strict';

    window.EP_Expenses_App = {
        currentExpenses: [],
        currentPeriod: '',
        currentLiqs: [],
        currentLiqRate: 0,
        currentLiqIrpf: 0,
        currentLiqSs: 0,
        
        init: function () {
            this.bindEvents();
            this.loadExpenses();
        },

        bindEvents: function () {
            var self = this;

            // --- Control de Tabs ---
            $('.ep-expenses-tabs .ep-tab-btn').on('click', function () {
                var tabId = $(this).data('tab');
                
                $('.ep-tab-btn').removeClass('active');
                $(this).addClass('active');
                
                $('.ep-tab-content').removeClass('active');
                $('#' + tabId).addClass('active');
            });

            // --- Gestión de Sedes (Añadir / Eliminar) ---
            $('#btn-add-sede').on('click', function () {
                var tr = $('<tr class="sede-row"></tr>');
                tr.append('<td><input type="text" class="ep-input cfg-sede-name" value="" required placeholder="Ej. Nueva Sede..."></td>');
                tr.append('<td><input type="text" class="ep-input cfg-sede-prefix" value="" required placeholder="Ej. NSEDE-"></td>');
                
                var deleteBtnTd = $('<td class="text-center"></td>');
                var deleteBtn = $('<button type="button" class="ep-btn ep-btn-sm ep-btn-light btn-delete-sede" style="color: #ef4444; padding: 4px 8px;" title="Eliminar Sede"><i class="fa-solid fa-trash"></i></button>');
                deleteBtnTd.append(deleteBtn);
                tr.append(deleteBtnTd);
                
                $('#cfg-sedes-tbody').append(tr);
            });

            $(document).on('click', '.btn-delete-sede', function () {
                var row = $(this).closest('tr');
                var name = row.find('.cfg-sede-name').val() || 'esta sede';
                if (confirm('¿Estás seguro de que deseas eliminar "' + name + '" de la configuración?')) {
                    row.remove();
                }
            });

            // --- Filtro tickets (Listado Unificado) ---
            $('#ep-expenses-filter-form').on('submit', function (e) {
                e.preventDefault();
                self.loadExpenses();
            });

            $('#ep-btn-reset-filters').on('click', function () {
                var now = new Date();
                $('#filter-year').val(now.getFullYear());
                $('#filter-month').val(('0' + (now.getMonth() + 1)).slice(-2));
                $('#filter-category').val('');
                if ($('#filter-user-id').length) {
                    $('#filter-user-id').val('');
                }
                self.loadExpenses();
            });

            // --- Buscar trabajador en configuración admin ---
            $('#cfg-worker-search').on('input', function () {
                var query = $(this).val().toLowerCase();
                $('#cfg-workers-table tbody tr').each(function () {
                    var name = $(this).data('name') || '';
                    if (name.indexOf(query) > -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // --- Botones de Cambio Masivo (Bulk config) ---
            $('#btn-bulk-apply-km').on('click', function () {
                var val = $('#bulk-km-rate').val();
                if (val === '') {
                    alert('Indique un valor en el campo de precio de Km.');
                    return;
                }
                var valFloat = parseFloat(val);
                if (isNaN(valFloat) || valFloat < 0) {
                    alert('Indique un valor numérico válido.');
                    return;
                }
                if (confirm('¿Estás seguro de aplicar ' + valFloat.toFixed(2) + ' €/Km a TODOS los empleados?\n(Los cambios se aplicarán en la tabla de abajo, debe pulsar "Guardar Todos los Cambios" para confirmar).')) {
                    $('.cfg-w-km').val(valFloat.toFixed(2));
                }
            });

            // --- Botones de Cambio Masivo (IRPF) ---
            $('#btn-bulk-apply-irpf').on('click', function () {
                var val = $('#bulk-irpf').val();
                if (val === '') {
                    alert('Indique un valor en el campo de IRPF %.');
                    return;
                }
                var valFloat = parseFloat(val);
                if (isNaN(valFloat) || valFloat < 0) {
                    alert('Indique un valor numérico válido.');
                    return;
                }
                if (confirm('¿Estás seguro de aplicar ' + valFloat.toFixed(2) + '% de IRPF a TODOS los empleados?\n(Los cambios se aplicarán en la tabla de abajo, debe pulsar "Guardar Todos los Cambios" para confirmar).')) {
                    $('.cfg-w-irpf').val(valFloat.toFixed(2));
                }
            });

            // --- Botones de Cambio Masivo (S. Social) ---
            $('#btn-bulk-apply-ss').on('click', function () {
                var val = $('#bulk-ss').val();
                if (val === '') {
                    alert('Indique un valor en el campo de S. Social %.');
                    return;
                }
                var valFloat = parseFloat(val);
                if (isNaN(valFloat) || valFloat < 0) {
                    alert('Indique un valor numérico válido.');
                    return;
                }
                if (confirm('¿Estás seguro de aplicar ' + valFloat.toFixed(2) + '% de Seguridad Social a TODOS los empleados?\n(Los cambios se aplicarán en la tabla de abajo, debe pulsar "Guardar Todos los Cambios" para confirmar).')) {
                    $('.cfg-w-ss').val(valFloat.toFixed(2));
                }
            });

            // --- Guardar configuración de administración ---
            $('#ep-admin-config-form').on('submit', function (e) {
                e.preventDefault();
                
                var msg = "¿Estás seguro de que deseas guardar estos cambios en la configuración?\n\n";
                msg += "⚠️ ADVERTENCIA: Los nuevos tipos de IRPF, Seguridad Social y Precios por Km se aplicarán únicamente a las nuevas liquidaciones que se creen. No afectarán a las existentes.";
                
                if (!confirm(msg)) {
                    return;
                }

                var workersConfig = {};
                $('#cfg-workers-table tbody tr').each(function () {
                    var uid = $(this).find('.cfg-w-km').data('uid');
                    if (uid) {
                        workersConfig[uid] = {
                            km_rate: parseFloat($(this).find('.cfg-w-km').val()) || 0,
                            irpf: parseFloat($(this).find('.cfg-w-irpf').val()) || 0,
                            ss: parseFloat($(this).find('.cfg-w-ss').val()) || 0
                        };
                    }
                });

                var numberingConfig = {};
                $('#cfg-sedes-tbody tr').each(function (index) {
                    var name = $(this).find('.cfg-sede-name').val();
                    var prefix = $(this).find('.cfg-sede-prefix').val();
                    if (name && prefix) {
                        numberingConfig[index + 1] = {
                            name: name,
                            prefix: prefix
                        };
                    }
                });

                var formData = {
                    action: 'ep_expenses_save_admin_config',
                    nonce: ep_vars.nonce,
                    workers_config: JSON.stringify(workersConfig),
                    numbering_config: JSON.stringify(numberingConfig),
                    km_exempt_limit: $('#cfg-km-exempt-limit').val()
                };

                $('#ep-btn-save-config').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');

                $.post(ep_vars.ajax_url, formData, function (response) {
                    $('#ep-btn-save-config').prop('disabled', false).html('<i class="fa-solid fa-cloud-arrow-up"></i> Guardar Todos los Cambios');
                    if (response.success) {
                        alert('¡Configuración guardada correctamente!');
                        location.reload();
                    } else {
                        alert('Error al guardar la configuración: ' + (response.data || 'Desconocido'));
                    }
                }).fail(function () {
                    $('#ep-btn-save-config').prop('disabled', false).html('<i class="fa-solid fa-cloud-arrow-up"></i> Guardar Todos los Cambios');
                    alert('Error en la comunicación con el servidor.');
                });
            });

            // --- Abrir Modal de Nuevo Registro ---
            $('#ep-btn-new-expense').on('click', function () {
                self.openExpenseModal();
            });

            // --- Exportación a Excel ---
            $('#ep-btn-export-xlsx').on('click', function () {
                $('#ep-modal-export').fadeIn();
            });

            $('input[name="ep_export_periodo"]').on('change', function () {
                if ($(this).val() === 'years') {
                    $('#ep-export-years').slideDown();
                } else {
                    $('#ep-export-years').slideUp();
                }
            });

            // Máximo 5 años
            $(document).on('change', '.ep-export-anio', function () {
                var marcados = $('.ep-export-anio:checked');
                if (marcados.length > 5) {
                    $(this).prop('checked', false);
                    alert('Puedes seleccionar como máximo 5 años.');
                    marcados = $('.ep-export-anio:checked');
                }
                var n = marcados.length;
                $('#ep-export-years-aviso').text(
                    n === 0 ? 'Ningún año seleccionado.' : n + ' de 5 años seleccionados.'
                );
            });

            $('#ep-btn-confirm-export').on('click', function () {
                self.downloadExport();
            });

            // --- Toggle de Tipo de Justificación/Gasto ---
            $('#registration_type').on('change', function () {
                self.toggleModalFields();
            });

            // --- Previsualización de archivos ---
            $('#ep_expense_file').on('change', function (e) {
                var files = e.target.files;
                var previewGrid = $('#ep-files-grid-preview').empty();
                
                if (files && files.length > 0) {
                    $('#ep-file-preview-container').show();
                    
                    $.each(files, function(i, file) {
                        var card = $('<div style="padding:6px 10px; background:rgba(0,0,0,0.04); border-radius:6px; font-size:11px; display:flex; align-items:center; gap:8px;"></div>');
                        if (file.type.startsWith('image/')) {
                            card.append('<i class="fa-solid fa-image text-success"></i>');
                        } else if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                            card.append('<i class="fa-solid fa-file-pdf text-danger"></i>');
                        } else {
                            card.append('<i class="fa-solid fa-paperclip"></i>');
                        }
                        card.append('<span>' + self.escapeHtml(file.name) + '</span>');
                        previewGrid.append(card);
                    });
                } else {
                    $('#ep-file-preview-container').hide();
                }
            });

            // --- Submit del Formulario Unificado ---
            $('#ep-expense-form').on('submit', function (e) {
                e.preventDefault();
                self.saveExpenseUnified();
            });

            // --- Cambios reactivos en campos de Kilometraje ---
            $('#liq_kilometros').on('input change', function () {
                self.calculateLiqTotals();
            });

            // --- Abrir / Confirmar Cierre Mensual ---
            $('#ep-btn-open-closure').on('click', function () {
                self.openClosureModal();
            });

            $('#ep-btn-confirm-closure').on('click', function () {
                self.confirmClosure();
            });

            // --- Formulario de Liquidación de Ticket Manual ---
            $('#ep-liquidate-form').on('submit', function (e) {
                e.preventDefault();
                self.confirmLiquidation();
            });

            // --- Formulario de Gestión de Liquidación (Aprobar/Rechazar/Abonar) ---
            $('#ep-liq-manage-form').on('submit', function (e) {
                e.preventDefault();
                self.confirmLiqManage();
            });
        },

        loadExpenses: function () {
            var self = this;
            var formData = {
                action: 'ep_expenses_get_list',
                nonce: ep_vars.nonce,
                year: $('#filter-year').val(),
                month: $('#filter-month').val(),
                category: $('#filter-category').val(),
                user_id: $('#filter-user-id').length ? $('#filter-user-id').val() : ''
            };

            $('#ep-expenses-table-body').html('<tr><td colspan="10" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Cargando justificantes...</td></tr>');

            $.post(ep_vars.ajax_url, formData, function (response) {
                if (response.success) {
                    self.currentExpenses = response.data.expenses;
                    self.currentPeriod = response.data.year_month;
                    self.isDirection = !!response.data.is_direction;
                    self.isAdminDept = !!response.data.is_admin_dept;
                    self.currentUserId = response.data.current_user_id;
                    self.renderExpensesTable(response.data.expenses);
                    self.updateKPIs(response.data);
                    self.checkDay28ClosureBanner(response.data);
                } else {
                    $('#ep-expenses-table-body').html('<tr><td colspan="10" class="text-center text-muted">Error al cargar datos: ' + (response.data || 'Sin respuesta') + '</td></tr>');
                }
            }).fail(function () {
                $('#ep-expenses-table-body').html('<tr><td colspan="10" class="text-center text-muted">Error de conexión al servidor.</td></tr>');
            });
        },

        renderExpensesTable: function (expenses) {
            var self = this;
            var tbody = $('#ep-expenses-table-body');
            tbody.empty();

            var isAdminView = (typeof window.ep_expenses_can_manage !== 'undefined') ? !!window.ep_expenses_can_manage : ($('#filter-user-id').length > 0);

            if (!expenses || expenses.length === 0) {
                var cols = isAdminView ? 10 : 9;
                tbody.append('<tr><td colspan="' + cols + '" class="text-center text-muted">No se han registrado tickets ni viajes en este periodo.</td></tr>');
                return;
            }

            $.each(expenses, function (index, exp) {
                var tr = $('<tr></tr>');

                // 1. Nº Justificante
                var numClass = exp.is_liquidation ? 'text-success' : 'text-primary';
                tr.append('<td><strong class="' + numClass + '">' + self.escapeHtml(exp.ticket_number) + '</strong></td>');
                
                // 2. Fecha
                tr.append('<td>' + self.formatDate(exp.expense_date) + '</td>');

                // 3. Empleado (Vista Admin)
                if (isAdminView) {
                    tr.append('<td>' + self.escapeHtml(exp.user_name || ('Usuario #' + exp.user_id)) + '</td>');
                }

                // 4. Concepto / Destino
                var conceptHtml = self.escapeHtml(exp.concept);
                var badgesHtml = '<div style="margin-top:4px; display:flex; gap:6px; flex-wrap:wrap; align-items: center;">';
                var hasBadges = false;
                if (exp.google_maps_url) {
                    badgesHtml += '<a href="' + self.escapeHtml(exp.google_maps_url) + '" target="_blank" class="ep-btn ep-btn-sm ep-btn-light" style="padding:2px 8px; font-size:11px; color:#dc2626; border-color:#fca5a5;" title="Ver ruta en Google Maps"><i class="fa-solid fa-map-location-dot"></i> Ruta Maps</a>';
                    hasBadges = true;
                }
                if (exp.is_liquidation && exp.attachments_list && exp.attachments_list.length > 0) {
                    badgesHtml += '<a href="#" class="ep-btn ep-btn-sm ep-btn-light" style="padding:2px 8px; font-size:11px; color:#2563eb; border-color:#93c5fd;" onclick="EP_Expenses_App.openGalleryModal(\'' + self.escapeHtml(exp.ticket_number) + '\', ' + JSON.stringify(exp.attachments_list).replace(/"/g, '&quot;') + ', event)" title="Ver comprobantes adjuntos"><i class="fa-solid fa-paperclip"></i> ' + exp.attachments_list.length + ' Adjunto(s)</a>';
                    hasBadges = true;
                }
                badgesHtml += '</div>';
                if (hasBadges) {
                    conceptHtml += badgesHtml;
                }
                tr.append('<td>' + conceptHtml + '</td>');

                // 5. Tipo Justificación (Categoría)
                var categoryHtml = '';
                if (exp.is_liquidation) {
                    categoryHtml = '<span class="ep-badge" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-file-invoice-dollar"></i> Liquidación de Viaje</span>';
                } else {
                    categoryHtml = '<span class="ep-badge ep-badge-info">' + self.formatCategory(exp.category) + '</span>';
                }
                tr.append('<td>' + categoryHtml + '</td>');

                // 6. Forma Pago
                tr.append('<td>' + (exp.payment_method === 'empresa' ? 'Tarjeta Empresa' : (exp.payment_method === 'efectivo' ? 'Efectivo' : 'Personal')) + '</td>');
                
                // 7. Importe
                var amtClass = exp.is_liquidation ? 'color: var(--ep-primary); font-weight:700;' : '';
                tr.append('<td><strong style="' + amtClass + '">' + parseFloat(exp.amount).toFixed(2) + ' €</strong></td>');

                // 8. Adjunto / PDF
                if (exp.is_liquidation) {
                    tr.append('<td><a href="' + exp.attachment_url + '" target="_blank" class="ep-btn ep-btn-sm ep-btn-light"><i class="fa-solid fa-file-pdf text-danger"></i> PDF Viaje</a></td>');
                } else {
                    // Justificante generado por el portal (el documento que se firma)
                    var cellHtml = '<div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">';
                    cellHtml += '<button class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.viewAttachment(\'' + exp.justificante_url + '\', \'' + self.escapeHtml(exp.ticket_number) + '\', true)" title="Ver el justificante generado"><i class="fa-solid fa-file-pdf text-danger"></i> PDF Justificante</button>';

                    // Comprobante que subió el empleado
                    if (exp.attachment_url) {
                        var isPdf = exp.attachment_url.toLowerCase().endsWith('.pdf');
                        var iconClass = isPdf ? 'fa-file-pdf text-danger' : 'fa-paperclip';
                        var labelText = isPdf ? ' Comprobante' : ' Comprobante';
                        cellHtml += '<button class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.viewAttachment(\'' + exp.attachment_url + '\', \'' + self.escapeHtml(exp.ticket_number) + '\')" title="Ver el comprobante adjunto"><i class="fa-solid ' + iconClass + '"></i>' + labelText + '</button>';
                    }

                    cellHtml += '</div>';
                    tr.append('<td>' + cellHtml + '</td>');
                }

                // 9. Estado y Firmas
                var statusBadge = '';
                if (exp.status === 'pending') {
                    statusBadge = '<span class="ep-badge ep-badge-warning" style="background:#fff3e0; color:#e65100;"><i class="fa-solid fa-clock"></i> Pendiente</span>';
                } else if (exp.status === 'approved') {
                    statusBadge = '<span class="ep-badge ep-badge-info" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-check-double"></i> Aprobado</span>';
                } else if (exp.status === 'declared' || exp.status === 'liquidated') {
                    statusBadge = '<span class="ep-badge ep-badge-success" style="cursor:pointer;" onclick="EP_Expenses_App.' + (exp.is_liquidation ? 'showLiqTrace' : 'showTraceability') + '(' + exp.id + ')" title="Ver detalles"><i class="fa-solid fa-circle-check"></i> Liquidado</span>';
                } else if (exp.status === 'rejected') {
                    statusBadge = '<span class="ep-badge ep-badge-danger" style="background:#ffebee; color:#c62828;"><i class="fa-solid fa-circle-xmark"></i> Rechazado</span>';
                }

                if (!exp.is_liquidation) {
                    // Los tickets individuales también se firman
                    if (exp.admin_signature_pending) {
                        statusBadge += '<div style="margin-top:4px;"><span class="ep-badge ep-badge-warning" style="background:#fef3c7; color:#92400e; font-size:10px;"><i class="fa-solid fa-pen-nib"></i> Pte. firma del abono</span></div>';
                    } else if (exp.is_signed) {
                        statusBadge += '<div style="margin-top:4px;"><span class="ep-badge ep-badge-success" style="background:#d1fae5; color:#065f46; font-size:10px;"><i class="fa-solid fa-signature"></i> Firmado</span></div>';
                    } else if (exp.signature_request_id) {
                        statusBadge += '<div style="margin-top:4px;"><span class="ep-badge ep-badge-warning" style="background:#fef3c7; color:#92400e; font-size:10px;"><i class="fa-solid fa-pen-nib"></i> Pte. Firma</span></div>';
                    }
                }

                if (exp.is_liquidation) {
                    var sigBadge = '';
                    if (exp.admin_signature_pending) {
                        sigBadge = '<div style="margin-top:4px;"><span class="ep-badge ep-badge-warning" style="background:#fef3c7; color:#92400e; font-size:10px;"><i class="fa-solid fa-pen-nib"></i> Pte. firma del abono</span></div>';
                    } else if (exp.is_signed) {
                        sigBadge = '<div style="margin-top:4px;"><span class="ep-badge ep-badge-success" style="background:#d1fae5; color:#065f46; font-size:10px;"><i class="fa-solid fa-signature"></i> Firmado</span></div>';
                    } else if (exp.signature_request_id) {
                        sigBadge = '<div style="margin-top:4px;"><span class="ep-badge ep-badge-warning" style="background:#fef3c7; color:#92400e; font-size:10px;"><i class="fa-solid fa-pen-nib"></i> Pte. Firma</span></div>';
                    }
                    statusBadge += sigBadge;
                }
                tr.append('<td>' + statusBadge + '</td>');

                // 10. Acciones
                var actions = '<td><div class="ep-btn-group" style="display:flex; gap:4px;">';

                if (exp.is_liquidation) {
                    // Acciones de Liquidaciones de Viaje
                    if (exp.signature_request_id && !exp.is_signed && exp.user_id == self.currentUserId) {
                        actions += '<a href="?view=signature" class="ep-btn ep-btn-sm ep-btn-success" title="Firmar digitalmente"><i class="fa-solid fa-pen-nib"></i> Firmar</a>';
                    }
                    if (self.isDirection && exp.status === 'pending') {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-success" onclick="EP_Expenses_App.openLiqManageModal(' + exp.id + ', \'approved\', \'' + self.escapeHtml(exp.ticket_number) + '\')" title="Aprobar"><i class="fa-solid fa-check"></i></button>';
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-danger" style="background:#ef4444; color:#fff;" onclick="EP_Expenses_App.openLiqManageModal(' + exp.id + ', \'rejected\', \'' + self.escapeHtml(exp.ticket_number) + '\')" title="Rechazar"><i class="fa-solid fa-xmark"></i></button>';
                    }
                    if ((self.isDirection || self.isAdminDept) && exp.status === 'approved') {
                        if (exp.admin_signature_pending) {
                            // El abono ya está preparado: solo falta la firma de Administración.
                            actions += '<a href="?view=signature&request_id=' + exp.admin_signature_request_id + '" class="ep-btn ep-btn-sm ep-btn-success" title="Firmar el abono para completar la liquidación"><i class="fa-solid fa-pen-nib"></i> Firmar abono</a>';
                        } else {
                            actions += '<button class="ep-btn ep-btn-sm ep-btn-success" onclick="EP_Expenses_App.openLiqManageModal(' + exp.id + ', \'liquidated\', \'' + self.escapeHtml(exp.ticket_number) + '\')" title="Liquidar / Abonar"><i class="fa-solid fa-euro-sign"></i></button>';
                        }
                    }
                    if (exp.status === 'rejected' || exp.status === 'liquidated' || exp.status === 'declared') {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.showLiqTrace(' + exp.id + ')" title="Detalles"><i class="fa-solid fa-info-circle"></i> Info</button>';
                    }
                    if ((exp.user_id == self.currentUserId && exp.status === 'pending') || self.isDirection) {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.deleteLiq(' + exp.id + ')" title="Eliminar"><i class="fa-solid fa-trash text-danger"></i></button>';
                    }
                } else {
                    // Acciones de Gasto / Ticket individual
                    if (exp.signature_request_id && !exp.is_signed && exp.user_id == self.currentUserId) {
                        actions += '<a href="?view=signature&request_id=' + exp.signature_request_id + '" class="ep-btn ep-btn-sm ep-btn-success" title="Firmar digitalmente"><i class="fa-solid fa-pen-nib"></i> Firmar</a>';
                    }
                    if (self.isDirection && exp.status === 'pending') {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-success" onclick="EP_Expenses_App.toggleStatus(' + exp.id + ', \'approved\')" title="Aprobar"><i class="fa-solid fa-check"></i></button>';
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-danger" style="background:#ef4444; color:#ffffff; border:none;" onclick="EP_Expenses_App.toggleStatus(' + exp.id + ', \'rejected\')" title="Rechazar"><i class="fa-solid fa-xmark"></i></button>';
                    }
                    if ((self.isAdminDept || self.isDirection) && exp.status === 'approved') {
                        if (exp.admin_signature_pending) {
                            actions += '<a href="?view=signature&request_id=' + exp.admin_signature_request_id + '" class="ep-btn ep-btn-sm ep-btn-success" title="Firmar el abono para completar la liquidación"><i class="fa-solid fa-pen-nib"></i> Firmar abono</a>';
                        } else {
                            actions += '<button class="ep-btn ep-btn-sm ep-btn-success" onclick="EP_Expenses_App.openLiquidateModal(' + exp.id + ', \'' + self.escapeHtml(exp.ticket_number) + '\')" title="Liquidar"><i class="fa-solid fa-euro-sign"></i></button>';
                        }
                    }
                    if (exp.status === 'declared') {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-info" onclick="EP_Expenses_App.showTraceability(' + exp.id + ')" title="Detalles"><i class="fa-solid fa-info-circle"></i> Info</button>';
                    }
                    if (self.isDirection && (exp.status === 'approved' || exp.status === 'declared' || exp.status === 'rejected')) {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.toggleStatus(' + exp.id + ', \'pending\')" title="Reabrir a pendiente"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>';
                    }
                    if ((exp.user_id == self.currentUserId && exp.status === 'pending') || self.isDirection) {
                        actions += '<button class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.deleteExpense(' + exp.id + ')" title="Eliminar"><i class="fa-solid fa-trash text-danger"></i></button>';
                    }
                }

                actions += '</div></td>';
                tr.append(actions);
                tbody.append(tr);
            });
        },

        updateKPIs: function (data) {
            var totalTickets = data.expenses ? data.expenses.length : 0;
            var totalAmount = 0;
            var totalDietas = 0;
            var pendingTickets = 0;

            if (data.expenses) {
                $.each(data.expenses, function (i, exp) {
                    var amt = parseFloat(exp.amount) || 0;
                    totalAmount += amt;
                    if (exp.category === 'dieta') {
                        totalDietas += amt;
                    }
                    if (exp.status !== 'declared' && exp.status !== 'liquidated') {
                        pendingTickets++;
                    }
                });
            }

            $('#kpi-total-tickets').text(totalTickets);
            $('#kpi-total-amount').text(totalAmount.toFixed(2) + ' €');
            $('#kpi-total-dietas').text(totalDietas.toFixed(2) + ' €');

            if (totalTickets === 0) {
                $('#kpi-closure-status').html('<span class="ep-badge ep-badge-light" style="background:#e2e8f0; color:#475569;"><i class="fa-solid fa-minus-circle"></i> Sin Gastos</span>');
            } else if (data.closure !== null || (totalTickets > 0 && pendingTickets === 0)) {
                $('#kpi-closure-status').html('<span class="ep-badge ep-badge-success"><i class="fa-solid fa-check-double"></i> Liquidado / Cerrado</span>');
            } else {
                $('#kpi-closure-status').html('<span class="ep-badge ep-badge-warning"><i class="fa-solid fa-clock"></i> Pendiente</span>');
            }
        },

        checkDay28ClosureBanner: function (data) {
            var today = new Date();
            var dayOfMonth = today.getDate();
            var hasExpenses = data.expenses && data.expenses.length > 0;

            if (hasExpenses && (dayOfMonth >= 28 || data.month_is_past)) {
                var pendingCount = 0;
                var declaredCount = 0;
                var totalAmount = 0;

                $.each(data.expenses, function (i, exp) {
                    var amt = parseFloat(exp.amount) || 0;
                    totalAmount += amt;
                    if (exp.status === 'declared' || exp.status === 'liquidated') {
                        declaredCount++;
                    } else {
                        pendingCount++;
                    }
                });

                var statusText = '';
                if (pendingCount === 0) {
                    statusText = 'Todos tus justificantes de este mes (' + declaredCount + ') han sido liquidados por Administración (' + totalAmount.toFixed(2) + ' €).';
                } else if (declaredCount === 0) {
                    statusText = 'Tienes ' + pendingCount + ' justificante(s) registrados este mes por ' + totalAmount.toFixed(2) + ' € (Pendientes de abonar).';
                } else {
                    statusText = 'De los ' + data.expenses.length + ' justificantes del mes (' + totalAmount.toFixed(2) + ' €), ' + declaredCount + ' ya han sido liquidados y ' + pendingCount + ' están pendientes de abonar.';
                }

                $('#closure-banner-message').html('<strong>Periodo ' + (data.year_month || '') + ':</strong> ' + statusText);

                // El botón de declaración solo aplica sobre un mes concreto y propio,
                // y desaparece cuando el cierre ya se ha declarado.
                var isSingleMonth = (data.year_month || '').length === 7;
                if (isSingleMonth && !data.closure && !this.isViewingOtherEmployee()) {
                    $('#ep-btn-open-closure').show();
                } else {
                    $('#ep-btn-open-closure').hide();
                }

                $('#ep-expenses-closure-banner').slideDown();
            } else {
                $('#ep-expenses-closure-banner').slideUp();
            }
        },

        openExpenseModal: function () {
            $('#ep-expense-form')[0].reset();
            $('#expense_id').val('');
            $('#google_maps_url').val('');
            $('#notes').val('');
            $('#payment_method').val('personal');
            $('#ep-file-preview-container').hide();
            $('#ep-files-grid-preview').empty();

            // Default to 'dieta' (Liquidación de Viaje)
            $('#registration_type').val('dieta');

            // Vaciar tablas dinámicas de liquidación
            $('#liq-tbody-manutencion').empty();
            $('#liq-tbody-alojamiento').empty();
            $('#liq-tbody-otros').empty();

            this.currentLiqRate = parseFloat(window.ep_current_user_config.km_rate) || 0.25;
            this.currentLiqIrpf = parseFloat(window.ep_current_user_config.irpf) || 2.00;
            this.currentLiqSs = parseFloat(window.ep_current_user_config.ss) || 1.00;

            // Las tablas dinámicas de dietas inician vacías para que el usuario las agregue a propósito

            this.calculateLiqTotals();
            this.toggleModalFields();
            
            $('#ep-modal-backdrop-unified').fadeIn();
        },

        toggleModalFields: function () {
            var type = $('#registration_type').val();
            if (type === 'dieta') {
                // Liquidación de Viaje y Dietas
                $('.group-ticket-only').hide();
                $('.group-liq-only').show();
                
                $('#ep-expense-modal-title').html('<i class="fa-solid fa-file-invoice-dollar"></i> Nueva Liquidación de Viaje y Dietas');
                $('#ep-btn-save-expense').html('<i class="fa-solid fa-signature"></i> Guardar y Firmar Documento');
            } else {
                // Ticket individual
                $('.group-ticket-only').show();
                $('.group-liq-only').hide();
                
                $('#ep-expense-modal-title').html('<i class="fa-solid fa-file-circle-plus"></i> Registrar Gasto / Ticket');
                $('#ep-btn-save-expense').html('<i class="fa-solid fa-signature"></i> Guardar y Firmar Documento');
            }
        },

        closeExpenseModal: function () {
            $('#ep-modal-backdrop-unified').fadeOut();
        },

        saveExpenseUnified: function () {
            var self = this;
            var type = $('#registration_type').val();

            if (type !== 'dieta') {
                // Registro de Ticket Individual (categoría = type)
                var concept = $('#concept').val();
                var amount = parseFloat($('#amount').val()) || 0;
                if (!concept || amount <= 0) {
                    alert('Debes especificar un concepto e importe válido.');
                    return;
                }

                var form = $('#ep-expense-form')[0];
                var formData = new FormData(form);
                formData.append('action', 'ep_expenses_save');
                formData.append('nonce', ep_vars.nonce);
                formData.append('category', type); // usar la selección como categoría

                $('#ep-btn-save-expense').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function (response) {
                        $('#ep-btn-save-expense').prop('disabled', false).html('<i class="fa-solid fa-signature"></i> Guardar y Firmar Documento');
                        if (response.success) {
                            self.closeExpenseModal();
                            // Todo justificante se firma, igual que las liquidaciones de viaje
                            if (response.data && response.data.signature_request_id) {
                                window.location.href = '?view=signature&request_id=' + response.data.signature_request_id;
                            } else {
                                self.loadExpenses();
                            }
                        } else {
                            alert('Error al guardar el gasto: ' + (response.data || 'Desconocido'));
                        }
                    },
                    error: function () {
                        $('#ep-btn-save-expense').prop('disabled', false).html('<i class="fa-solid fa-signature"></i> Guardar y Firmar Documento');
                        alert('Error en la comunicación con el servidor.');
                    }
                });
            } else {
                // Registro de Liquidación de Viajes
                var destino = $('#liq_destino').val();
                var motivo = $('#liq_motivo').val();
                if (!destino || !motivo) {
                    alert('Por favor, rellene el destino y el motivo del viaje.');
                    return;
                }

                var imputa = $.trim($('#liq_imputa').val() || '');
                if (!imputa) {
                    alert('Indica el programa imputado. Si el gasto no se imputa a ninguno, escribe «Ninguno».');
                    $('#liq_imputa').focus();
                    return;
                }

                // El justificante del trayecto solo es obligatorio en la liquidación de viaje.
                var mapsUrl = $.trim($('#google_maps_url').val() || '');
                if (!mapsUrl) {
                    alert('Debes indicar el enlace de ruta (Google Maps) que justifica el trayecto.');
                    $('#google_maps_url').focus();
                    return;
                }

                var form = $('#ep-expense-form')[0];
                var formData = new FormData(form);
                formData.append('action', 'ep_expenses_save_liq');
                formData.append('nonce', ep_vars.nonce);

                // Serializar y añadir tablas dinámicas de dietas
                var manutencion = self.serializeLiqTable('manutencion');
                var alojamiento = self.serializeLiqTable('alojamiento');
                var otros = self.serializeLiqTable('otros');

                formData.append('id', $('#expense_id').val());
                formData.append('sede_id', $('#liq_sede').val());
                formData.append('destino', destino);
                formData.append('motivo', motivo);
                formData.append('imputa', imputa);
                formData.append('fecha_desde', $('#liq_fecha_desde').val());
                formData.append('hora_desde', $('#liq_hora_desde').val());
                formData.append('fecha_hasta', $('#liq_fecha_hasta').val());
                formData.append('hora_hasta', $('#liq_hora_hasta').val());
                formData.append('kilometros', $('#liq_kilometros').val());
                formData.append('gastos_manutencion', JSON.stringify(manutencion));
                formData.append('gastos_alojamiento', JSON.stringify(alojamiento));
                formData.append('otros_gastos', JSON.stringify(otros));

                $('#ep-btn-save-expense').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function (response) {
                        $('#ep-btn-save-expense').prop('disabled', false).html('<i class="fa-solid fa-signature"></i> Guardar y Firmar Documento');
                        if (response.success) {
                            self.closeExpenseModal();
                            if (response.data && response.data.signature_request_id) {
                                window.location.href = '?view=signature&request_id=' + response.data.signature_request_id;
                            } else {
                                window.location.href = '?view=signature';
                            }
                        } else {
                            alert('Error al guardar la liquidación: ' + (response.data || 'Inténtelo de nuevo'));
                        }
                    },
                    error: function () {
                        $('#ep-btn-save-expense').prop('disabled', false).html('<i class="fa-solid fa-signature"></i> Guardar y Firmar Documento');
                        alert('Error de conexión al servidor.');
                    }
                });
            }
        },

        deleteExpense: function (id) {
            if (!confirm('¿Estás seguro de que deseas eliminar este ticket de gasto?')) {
                return;
            }

            var self = this;
            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_delete',
                nonce: ep_vars.nonce,
                id: id
            }, function (response) {
                if (response.success) {
                    self.loadExpenses();
                } else {
                    alert('Error al eliminar: ' + (response.data || 'No autorizado'));
                }
            });
        },

        approveExpense: function (id) {
            var self = this;
            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_toggle_status',
                nonce: ep_vars.nonce,
                id: id,
                status: 'approved'
            }, function (response) {
                if (response.success) {
                    self.loadExpenses();
                } else {
                    alert('Error al aprobar el gasto: ' + (response.data || 'Sin respuesta'));
                }
            }).fail(function () {
                alert('Error de comunicación con el servidor.');
            });
        },

        openRejectModal: function (id, ticketNumber) {
            $('#reject_expense_id').val(id);
            $('#reject-ticket-number').text(ticketNumber);
            $('#rejection_reason').val('');
            $('#ep-modal-reject').fadeIn();
        },

        closeRejectModal: function () {
            $('#ep-modal-reject').fadeOut();
        },

        confirmRejection: function () {
            var self = this;
            var id = $('#reject_expense_id').val();
            var reason = $('#rejection_reason').val();

            if (!reason || !reason.trim()) {
                alert('Por favor especifica un motivo para el rechazo.');
                return;
            }

            $('#ep-btn-confirm-reject').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Procesando...');

            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_toggle_status',
                nonce: ep_vars.nonce,
                id: id,
                status: 'rejected',
                rejection_reason: reason
            }, function (response) {
                $('#ep-btn-confirm-reject').prop('disabled', false).html('<i class="fa-solid fa-xmark"></i> Confirmar Rechazo');
                if (response.success) {
                    self.closeRejectModal();
                    self.loadExpenses();
                } else {
                    alert('Error al rechazar el gasto: ' + (response.data || 'Sin respuesta'));
                }
            }).fail(function () {
                $('#ep-btn-confirm-reject').prop('disabled', false).html('<i class="fa-solid fa-xmark"></i> Confirmar Rechazo');
                alert('Error de conexión al servidor.');
            });
        },

        toggleStatus: function (id, newStatus) {
            var self = this;
            if (newStatus === 'approved') {
                self.approveExpense(id);
                return;
            }
            if (newStatus === 'rejected') {
                var exp = self.currentExpenses ? self.currentExpenses.find(function (e) { return e.id == id; }) : null;
                var tNum = exp ? exp.ticket_number : '#' + id;
                self.openRejectModal(id, tNum);
                return;
            }

            if (newStatus === 'pending') {
                if (!confirm('¿Deseas reabrir este gasto a estado PENDIENTE de autorización?')) {
                    return;
                }
            }

            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_toggle_status',
                nonce: ep_vars.nonce,
                id: id,
                status: newStatus
            }, function (response) {
                if (response.success) {
                    self.loadExpenses();
                } else {
                    alert('Error: ' + (response.data || 'No se pudo actualizar el estado'));
                }
            });
        },

        openLiquidateModal: function (id, ticketNumber) {
            $('#liquidate_expense_id').val(id);
            $('#liquidate-ticket-number').text(ticketNumber);
            $('#liquidation_method').val('Transferencia Bancaria');
            $('#liquidation_notes').val('');
            $('#ep-modal-liquidate').fadeIn();
        },

        closeLiquidateModal: function () {
            $('#ep-modal-liquidate').fadeOut();
        },

        confirmLiquidation: function () {
            var self = this;
            var id = $('#liquidate_expense_id').val();
            var method = $('#liquidation_method').val();
            var notes = $('#liquidation_notes').val();

            $('#ep-btn-confirm-liquidate').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Procesando...');

            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_toggle_status',
                nonce: ep_vars.nonce,
                id: id,
                status: 'declared',
                liquidation_method: method,
                liquidation_notes: notes
            }, function (response) {
                $('#ep-btn-confirm-liquidate').prop('disabled', false).html('<i class="fa-solid fa-check-circle"></i> Confirmar Liquidación');
                if (response.success) {
                    self.closeLiquidateModal();
                    // El abono se cierra con la firma de Administración
                    if (response.data && response.data.requires_signature) {
                        window.location.href = '?view=signature&request_id=' + response.data.signature_request_id;
                    } else {
                        self.loadExpenses();
                    }
                } else {
                    alert('Error: ' + (response.data || 'No se pudo liquidar el gasto'));
                }
            }).fail(function () {
                $('#ep-btn-confirm-liquidate').prop('disabled', false).html('<i class="fa-solid fa-check-circle"></i> Confirmar Liquidación');
                alert('Error de conexión al servidor.');
            });
        },

        showTraceability: function (id) {
            var exp = this.currentExpenses ? this.currentExpenses.find(function (e) { return e.id == id; }) : null;
            if (!exp) return;

            $('#trace-approved-by').text(exp.approved_by_name || 'Dirección / Administración');

            if (exp.status === 'rejected') {
                $('#trace-modal-title').html('<i class="fa-solid fa-circle-xmark text-danger"></i> Detalles de Rechazo');
                $('#trace-status-badge').html('<span class="ep-badge ep-badge-danger" style="background:#ffebee; color:#c62828;"><i class="fa-solid fa-circle-xmark"></i> Rechazado</span>');
                $('#trace-liq-date-row, #trace-liq-by-row, #trace-liq-method-row, #trace-liq-notes-row').hide();
                $('#trace-rejection-reason').text(exp.rejection_reason || 'Sin motivo especificado.');
                $('#trace-rejection-container').show();
            } else {
                $('#trace-modal-title').html('<i class="fa-solid fa-info-circle text-info"></i> Detalles de Liquidación');
                $('#trace-status-badge').html('<span class="ep-badge ep-badge-success"><i class="fa-solid fa-circle-check"></i> Liquidado</span>');
                $('#trace-liquidated-at').text(exp.liquidated_at ? this.formatDate(exp.liquidated_at) : 'No consta');
                $('#trace-liquidated-by').text(exp.liquidated_by_name || 'Dpto. Administración');
                $('#trace-liquidation-method').text(exp.liquidation_method || 'No consta');
                $('#trace-liquidation-notes').text(exp.liquidation_notes || 'Sin observaciones adicionales.');
                $('#trace-liq-date-row, #trace-liq-by-row, #trace-liq-method-row, #trace-liq-notes-row').show();
                $('#trace-rejection-container').hide();
            }

            $('#ep-modal-traceability').fadeIn();
        },

        closeTraceabilityModal: function () {
            $('#ep-modal-traceability').fadeOut();
        },

        closeExportModal: function () {
            $('#ep-modal-export').fadeOut();
        },

        downloadExport: function () {
            var periodo = $('input[name="ep_export_periodo"]:checked').val() || '1m';
            var url = ep_vars.ajax_url
                + '?action=ep_expenses_export_xlsx'
                + '&nonce=' + encodeURIComponent(ep_vars.nonce)
                + '&periodo=' + encodeURIComponent(periodo);

            if (periodo === 'years') {
                var anios = $('.ep-export-anio:checked').map(function () {
                    return this.value;
                }).get();

                if (anios.length === 0) {
                    alert('Selecciona al menos un año.');
                    return;
                }

                $.each(anios, function (i, a) {
                    url += '&anios[]=' + encodeURIComponent(a);
                });
            }

            this.closeExportModal();
            // La descarga la resuelve el navegador: no hace falta AJAX
            window.location.href = url;
        },

        isViewingOtherEmployee: function () {
            var selected = $('#filter-user-id').length ? $('#filter-user-id').val() : '';
            return !!(selected && String(selected) !== String(this.currentUserId));
        },

        openClosureModal: function () {
            $('#closure-modal-period').text(this.currentPeriod);
            $('#closure-modal-tickets-count').text(this.currentExpenses.length);
            $('#closure-modal-total-amount').text($('#kpi-total-amount').text());
            $('#closure_notes').val('');
            $('#ep-modal-closure').fadeIn();
        },

        closeClosureModal: function () {
            $('#ep-modal-closure').fadeOut();
        },

        confirmClosure: function () {
            var self = this;
            var yearMonth = this.currentPeriod;
            var notes = $('#closure_notes').val();

            $('#ep-btn-confirm-closure').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Declarando...');

            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_declare_closure',
                nonce: ep_vars.nonce,
                year_month: yearMonth,
                notes: notes
            }, function (response) {
                $('#ep-btn-confirm-closure').prop('disabled', false).html('<i class="fa-solid fa-check-circle"></i> Confirmar y Declarar Cierre');
                if (response.success) {
                    self.closeClosureModal();
                    alert('¡Cierre mensual declarado con éxito! Se ha consolidado el total para Administración.');
                    self.loadExpenses();
                } else {
                    alert('Error al realizar la declaración: ' + (response.data || 'Inténtelo de nuevo'));
                }
            });
        },

        viewAttachment: function (url, ticketNum, forcePdf) {
            // Los justificantes generados se sirven por admin-ajax, sin extensión
            // en la URL: por eso hace falta poder forzar el visor de PDF.
            var isPdf = forcePdf === true || url.toLowerCase().indexOf('.pdf') !== -1;
            url = this.proxyUrl(url);

            var titleIcon = isPdf ? 'fa-file-pdf text-danger' : 'fa-image';
            var titleText = isPdf ? ' Vista Previa Documento' : ' Vista Previa del Ticket';
            $('#ep-viewer-title').html('<i class="fa-solid ' + titleIcon + '"></i> ' + titleText + ' (Nº ' + ticketNum + ')');
            $('#ep-viewer-download').attr('href', url);

            if (isPdf) {
                $('#ep-viewer-img').hide();
                $('#ep-viewer-pdf').attr('src', url).show();
            } else {
                $('#ep-viewer-pdf').hide();
                $('#ep-viewer-img').attr('src', url).show();
            }

            $('#ep-modal-viewer').fadeIn();
        },

        closeViewerModal: function () {
            $('#ep-modal-viewer').fadeOut();
        },

        openGalleryModal: function (ticketNum, attachments, event) {
            if (event) event.preventDefault();
            $('#gallery-ticket-number').text(ticketNum);
            
            var grid = $('#gallery-attachments-grid');
            grid.empty();

            if (attachments && attachments.length > 0) {
                $.each(attachments, function (idx, url) {
                    var filename = url.substring(url.lastIndexOf('/') + 1);
                    var isPdf = url.toLowerCase().endsWith('.pdf');
                    
                    var safeUrl = EP_Expenses_App.proxyUrl(url);
                    var card = $('<div class="gallery-item-card" style="border:1px solid #cbd5e1; border-radius:8px; padding:12px; text-align:center; background:#ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"></div>');
                    
                    if (isPdf) {
                        card.append('<div style="cursor:pointer; margin-bottom:8px;" onclick="EP_Expenses_App.viewAttachment(\'' + url + '\', \'' + ticketNum + '\')"><i class="fa-solid fa-file-pdf text-danger" style="font-size:32px;"></i></div>');
                        card.append('<div style="font-size:11px; margin-bottom:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' + filename + '">' + filename + '</div>');
                        card.append('<div style="display:flex; gap:4px; justify-content:center;">' +
                            '<button type="button" class="ep-btn ep-btn-sm ep-btn-light" style="padding:2px 8px; font-size:11px;" onclick="EP_Expenses_App.viewAttachment(\'' + url + '\', \'' + ticketNum + '\')"><i class="fa-solid fa-eye"></i> Ver</button>' +
                            '<a href="' + safeUrl + '" target="_blank" class="ep-btn ep-btn-sm ep-btn-light" style="padding:2px 8px; font-size:11px;"><i class="fa-solid fa-download"></i> Descargar</a>' +
                            '</div>');
                    } else {
                        card.append('<img src="' + safeUrl + '" style="width:100%; height:80px; object-fit:cover; border-radius:4px; margin-bottom:8px; cursor:pointer;" onclick="EP_Expenses_App.viewAttachment(\'' + url + '\', \'' + ticketNum + '\')">');
                        card.append('<div style="font-size:11px; margin-bottom:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' + filename + '">' + filename + '</div>');
                        card.append('<div style="display:flex; gap:4px; justify-content:center;">' +
                            '<button type="button" class="ep-btn ep-btn-sm ep-btn-light" style="padding:2px 8px; font-size:11px;" onclick="EP_Expenses_App.viewAttachment(\'' + url + '\', \'' + ticketNum + '\')"><i class="fa-solid fa-eye"></i> Ampliar</button>' +
                            '<a href="' + safeUrl + '" target="_blank" class="ep-btn ep-btn-sm ep-btn-light" style="padding:2px 8px; font-size:11px;"><i class="fa-solid fa-download"></i> Descargar</a>' +
                            '</div>');
                    }
                    
                    grid.append(card);
                });
            } else {
                grid.html('<p class="text-muted text-center" style="grid-column: 1 / -1;">No hay comprobantes adjuntos en esta liquidación.</p>');
            }

            $('#ep-modal-gallery').fadeIn();
        },

        closeGalleryModal: function () {
            $('#ep-modal-gallery').fadeOut();
        },

        formatCategory: function (cat) {
            switch (cat) {
                case 'dieta': return 'Liquidación de Viaje y Dietas';
                case 'transporte_peaje': return 'Taxi / Parking / Uber / Tren / Peaje';
                case 'material': return 'Material / Suministros';
                case 'otros': return 'Otros Gastos';
                default: return cat ? cat : 'Otros Gastos';
            }
        },

        formatDate: function (dateStr) {
            if (!dateStr) return '';
            var parts = dateStr.split(' ')[0].split('-');
            if (parts.length === 3) {
                return parts[2] + '/' + parts[1] + '/' + parts[0];
            }
            return dateStr;
        },

        escapeHtml: function (str) {
            return $('<div>').text(str || '').html();
        },

        /**
         * Los comprobantes no son accesibles por URL directa: se sirven siempre por
         * el proxy del portal, que comprueba que el justificante es tuyo o que
         * gestionas gastos.
         */
        proxyUrl: function (url) {
            if (!url || url.indexOf('/uploads/ep-expenses/') === -1) {
                return url;
            }
            var filename = url.substring(url.lastIndexOf('/') + 1).split('?')[0];
            return ep_vars.ajax_url + '?action=ep_expenses_view_attachment_preview&file=' + encodeURIComponent(filename);
        },

        // ==========================================
        // FUNCIONALIDADES DE LIQUIDACIONES DE VIAJE
        // ==========================================

        addLiqRow: function (type, data) {
            var self = this;
            var tbody = $('#liq-tbody-' + type);
            var dateVal = (data && data.fecha) ? data.fecha : new Date().toISOString().split('T')[0];
            var conceptVal = (data && data.concepto) ? data.concepto : '';
            var amountVal = (data && data.importe) ? parseFloat(data.importe).toFixed(2) : '';

            var tr = $('<tr class="dynamic-row"></tr>');
            tr.append('<td><input type="date" class="ep-input row-date" value="' + dateVal + '" required style="padding: 5px;"></td>');
            tr.append('<td><input type="text" class="ep-input row-concept" value="' + self.escapeHtml(conceptVal) + '" placeholder="Ej. Almuerzo..." required style="padding: 5px;"></td>');
            tr.append('<td><input type="number" step="0.01" min="0.00" class="ep-input row-amount" value="' + amountVal + '" placeholder="0.00" required style="padding: 5px; text-align:right;"></td>');
            
            var actionsTd = $('<td class="text-center"></td>');
            var removeBtn = $('<button type="button" class="ep-btn ep-btn-sm ep-btn-light" style="padding: 3px 8px; color:#dc2626;" title="Quitar Fila"><i class="fa-solid fa-trash"></i></button>');
            removeBtn.on('click', function () {
                tr.remove();
                self.calculateLiqTotals();
            });
            actionsTd.append(removeBtn);
            tr.append(actionsTd);

            tr.find('input').on('input change', function () {
                self.calculateLiqTotals();
            });

            tbody.append(tr);
            self.calculateLiqTotals();
        },

        calculateLiqTotals: function () {
            var kms = parseFloat($('#liq_kilometros').val()) || 0;
            var rate = parseFloat(this.currentLiqRate) || parseFloat(window.ep_current_user_config.km_rate) || 0.25;
            var irpfPct = parseFloat(this.currentLiqIrpf) || parseFloat(window.ep_current_user_config.irpf) || 2.00;
            var ssPct = parseFloat(this.currentLiqSs) || parseFloat(window.ep_current_user_config.ss) || 1.00;
            var limit = parseFloat(window.ep_km_exempt_limit) || 0.26;

            var totalKm = kms * rate;
            var exemptKm = 0;
            var subjectKm = 0;
            if (rate <= limit) {
                exemptKm = totalKm;
                subjectKm = 0;
            } else {
                exemptKm = kms * limit;
                subjectKm = totalKm - exemptKm;
            }

            $('#liq-calc-rate').text(rate.toFixed(2) + ' €');
            $('#liq-calc-exempt').text(exemptKm.toFixed(2) + ' €');
            $('#liq-calc-subject').text(subjectKm.toFixed(2) + ' €');

            var sumManutencion = 0;
            $('#liq-tbody-manutencion tr').each(function () {
                var val = parseFloat($(this).find('.row-amount').val()) || 0;
                sumManutencion += val;
            });

            var sumAlojamiento = 0;
            $('#liq-tbody-alojamiento tr').each(function () {
                var val = parseFloat($(this).find('.row-amount').val()) || 0;
                sumAlojamiento += val;
            });

            var sumOtros = 0;
            $('#liq-tbody-otros tr').each(function () {
                var val = parseFloat($(this).find('.row-amount').val()) || 0;
                sumOtros += val;
            });

            var baseRetencion = subjectKm;
            var rentaExenta = exemptKm + sumManutencion + sumAlojamiento + sumOtros;
            var totalBruto = baseRetencion + rentaExenta;

            var irpfAmt = baseRetencion * (irpfPct / 100);
            var ssAmt = baseRetencion * (ssPct / 100);
            var totalNeto = totalBruto - irpfAmt - ssAmt;

            $('#liq-show-bruto').text(totalBruto.toFixed(2) + ' €');
            $('#liq-show-base').text(baseRetencion.toFixed(2) + ' €');
            $('#liq-show-exento').text(rentaExenta.toFixed(2) + ' €');
            
            $('#liq-show-irpf-pct').text(irpfPct.toFixed(2));
            $('#liq-show-irpf-amt').text('- ' + irpfAmt.toFixed(2) + ' €');
            
            $('#liq-show-ss-pct').text(ssPct.toFixed(2));
            $('#liq-show-ss-amt').text('- ' + ssAmt.toFixed(2) + ' €');
            
            $('#liq-show-neto').text(totalNeto.toFixed(2) + ' €');
        },

        serializeLiqTable: function (type) {
            var arr = [];
            $('#liq-tbody-' + type + ' tr').each(function () {
                var date = $(this).find('.row-date').val();
                var concept = $(this).find('.row-concept').val();
                var amount = parseFloat($(this).find('.row-amount').val()) || 0;
                
                if (date && concept && amount > 0) {
                    arr.push({
                        fecha: date,
                        concepto: concept,
                        importe: amount
                    });
                }
            });
            return arr;
        },

        deleteLiq: function (id) {
            if (!confirm('¿Estás seguro de que deseas eliminar esta liquidación? Esta acción borrará el documento y la firma pendiente asociada.')) {
                return;
            }

            var self = this;
            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_delete_liq',
                nonce: ep_vars.nonce,
                id: id
            }, function (response) {
                if (response.success) {
                    self.loadExpenses();
                } else {
                    alert('Error al eliminar liquidación: ' + (response.data || 'No autorizado'));
                }
            });
        },

        openLiqManageModal: function (id, action, number) {
            $('#liq_manage_id').val(id);
            $('#liq_manage_action').val(action);
            $('#liq-manage-number').text(number);
            $('#liq_manage_reason').val('');
            
            var confirmBtn = $('#ep-btn-liq-manage-confirm');
            confirmBtn.removeClass('ep-btn-success ep-btn-danger ep-btn-primary');

            if (action === 'approved') {
                $('#liq-manage-title').html('<i class="fa-solid fa-check text-success"></i> Aprobar Liquidación');
                $('#liq-manage-prompt').html('¿Deseas <strong>aprobar</strong> la liquidación <strong id="liq-manage-number">' + number + '</strong>? Se enviará al Dpto. de Administración para su abono.');
                $('#liq-manage-reason-group').hide();
                $('#liq-manage-payment-group').hide();
                confirmBtn.addClass('ep-btn-success').text('Aprobar');
            } else if (action === 'rejected') {
                $('#liq-manage-title').html('<i class="fa-solid fa-xmark text-danger"></i> Rechazar Liquidación');
                $('#liq-manage-prompt').html('Vas a <strong>rechazar</strong> la liquidación <strong id="liq-manage-number">' + number + '</strong>. Por favor indique el motivo:');
                $('#liq-manage-reason-group').show();
                $('#liq-manage-payment-group').hide();
                confirmBtn.addClass('ep-btn-danger').text('Rechazar');
            } else if (action === 'liquidated') {
                $('#liq-manage-title').html('<i class="fa-solid fa-euro-sign text-success"></i> Abonar Liquidación');
                $('#liq-manage-prompt').html('Por favor, selecciona la forma de abono para la liquidación <strong id="liq-manage-number">' + number + '</strong>:');
                $('#liq-manage-reason-group').hide();
                $('#liq-manage-payment-group').show();
                $('#liq_manage_payment_method').val('Transferencia Bancaria');
                $('#liq_manage_payment_notes').val('');
                confirmBtn.addClass('ep-btn-success').text('Confirmar y Firmar Abono');
            }

            $('#ep-modal-liq-manage').fadeIn();
        },

        closeLiqManageModal: function () {
            $('#ep-modal-liq-manage').fadeOut();
        },

        confirmLiqManage: function () {
            var self = this;
            var id = $('#liq_manage_id').val();
            var action = $('#liq_manage_action').val();
            var reason = $('#liq_manage_reason').val();
            var payMethod = $('#liq_manage_payment_method').val();
            var payNotes = $('#liq_manage_payment_notes').val();

            if (action === 'rejected' && (!reason || !reason.trim())) {
                alert('Debes indicar un motivo de rechazo.');
                return;
            }

            $('#ep-btn-liq-manage-confirm').prop('disabled', true).text('Procesando...');

            $.post(ep_vars.ajax_url, {
                action: 'ep_expenses_toggle_liq_status',
                nonce: ep_vars.nonce,
                id: id,
                status: action,
                rejection_reason: reason,
                liquidation_method: payMethod,
                liquidation_notes: payNotes
            }, function (response) {
                $('#ep-btn-liq-manage-confirm').prop('disabled', false);
                if (response.success) {
                    self.closeLiqManageModal();
                    if (response.data && response.data.requires_signature) {
                        window.location.href = '?view=signature&request_id=' + response.data.signature_request_id;
                    } else {
                        self.loadExpenses();
                    }
                } else {
                    alert('Error en la gestión de la liquidación: ' + (response.data || 'Inténtelo de nuevo'));
                }
            }).fail(function () {
                $('#ep-btn-liq-manage-confirm').prop('disabled', false);
                alert('Error de conexión al servidor.');
            });
        },

        showLiqTrace: function (id) {
            var self = this;
            var liq = this.currentExpenses ? this.currentExpenses.find(function (l) { return l.id == id && l.is_liquidation; }) : null;
            if (!liq) return;

            var statusHtml = '';
            if (liq.status === 'approved') {
                statusHtml = '<span class="ep-badge ep-badge-info">Aprobado / Pendiente Pago</span>';
            } else if (liq.status === 'liquidated' || liq.status === 'declared') {
                statusHtml = '<span class="ep-badge ep-badge-success"><i class="fa-solid fa-circle-check"></i> Liquidado</span>';
            } else if (liq.status === 'rejected') {
                statusHtml = '<span class="ep-badge ep-badge-danger"><i class="fa-solid fa-circle-xmark"></i> Rechazado</span>';
            }
            $('#liq-trace-status').html(statusHtml);

            // Aprobador
            if (liq.approved_by_name) {
                $('#liq-trace-approved-by').text(liq.approved_by_name);
                $('#liq-trace-approved-row').show();
            } else {
                $('#liq-trace-approved-row').hide();
            }

            // Liquidador
            if (liq.liquidated_by_name) {
                $('#liq-trace-liquidated-by').text(liq.liquidated_by_name);
                $('#liq-trace-liquidated-row').show();
            } else {
                $('#liq-trace-liquidated-row').hide();
            }

            // Forma de pago y notas del abono
            if (liq.liquidation_method) {
                $('#liq-trace-method').text(liq.liquidation_method);
                $('#liq-trace-method-row').show();
            } else {
                $('#liq-trace-method-row').hide();
            }

            if (liq.liquidation_notes) {
                $('#liq-trace-notes').text(liq.liquidation_notes);
                $('#liq-trace-notes-row').show();
            } else {
                $('#liq-trace-notes-row').hide();
            }

            // Motivo de rechazo
            if (liq.status === 'rejected' && liq.rejection_reason) {
                $('#liq-trace-reject-reason').text(liq.rejection_reason);
                $('#liq-trace-reject-row').show();
            } else {
                $('#liq-trace-reject-row').hide();
            }

            // Adjuntos / Comprobantes
            var attachmentsContainer = $('#liq-trace-attachments-row');
            var attachmentsList = $('#liq-trace-attachments-list').empty();
            attachmentsContainer.hide();

            if (liq.attachments_list && liq.attachments_list.length > 0) {
                attachmentsContainer.show();
                $.each(liq.attachments_list, function (i, url) {
                    var filename = url.substring(url.lastIndexOf('/') + 1);
                    var isPdf = url.toLowerCase().endsWith('.pdf');
                    var iconClass = isPdf ? 'fa-file-pdf text-danger' : 'fa-file-image text-success';
                    
                    var card = $('<a href="' + self.proxyUrl(url) + '" target="_blank" class="ep-btn ep-btn-sm ep-btn-light" style="display:inline-flex; align-items:center; gap:6px; margin:4px 0;"></a>');
                    card.append('<i class="fa-solid ' + iconClass + '"></i>');
                    card.append('<span>' + self.escapeHtml(filename) + '</span>');
                    attachmentsList.append(card);
                });
            }

            $('#ep-modal-liq-trace').fadeIn();
        },

        closeLiqTraceModal: function () {
            $('#ep-modal-liq-trace').fadeOut();
        }
    };

    $(document).ready(function () {
        if ($('.ep-expenses-app').length) {
            window.EP_Expenses_App.init();
        }
    });

})(jQuery);
