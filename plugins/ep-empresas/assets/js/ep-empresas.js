jQuery(document).ready(function($) {
    if (!window.epEmpresasConfig) return;
    const { ajaxUrl, nonce, canWrite, isAdmin } = window.epEmpresasConfig;

    let currentData = [];
    let currentView = 'cards'; // 'cards' o 'table'
    let sortState = { col: 'nombre', dir: 'ASC' };

    // --- Cargar Datos ---
    function loadEmpresas() {
        const search = $('#ep_emp_search').val();
        const membresia = $('#ep_emp_filter_membresia').val();
        const zona = $('#ep_emp_filter_zona').val();

        $('#ep-emp-cards-view').html('<div class="ep-emp-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><p>Cargando empresas...</p></div>');
        $('#ep-emp-table-body').html('<tr><td colspan="7" class="ep-emp-loading"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando...</td></tr>');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'ep_empresas_action',
                empresa_action: 'list',
                security: nonce,
                search: search,
                membresia: membresia,
                zona: zona,
                orderby: sortState.col,
                order: sortState.dir
            },
            success: function(res) {
                if (res.success) {
                    currentData = res.data;
                    renderView();
                } else {
                    Swal.fire('Error', res.data || 'Error al cargar datos', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Error de red', 'error');
            }
        });
    }

    // --- Renderizar Vista (Cards o Table) ---
    function renderView() {
        if (currentData.length === 0) {
            const emptyHtml = `<div class="ep-emp-empty">
                <i class="fa-solid fa-building-circle-xmark"></i>
                <p>No se encontraron empresas con los filtros actuales.</p>
            </div>`;
            $('#ep-emp-cards-view').html(emptyHtml);
            $('#ep-emp-table-body').html(`<tr><td colspan="${canWrite ? 7 : 6}" class="ep-emp-empty">No hay datos</td></tr>`);
            return;
        }

        let cardsHtml = '';
        let tableHtml = '';

        currentData.forEach(emp => {
            // Render Cards
            const logoHtml = emp.logo_url 
                ? `<img src="${emp.logo_url}" class="ep-card-logo" alt="Logo">`
                : `<div class="ep-card-logo-placeholder"><i class="fa-regular fa-building"></i></div>`;
            
            const fotoHtml = emp.foto_url 
                ? `<img src="${emp.foto_url}" class="ep-card-foto" alt="Sede">` : '';

            let actionsHtml = '';
            if (canWrite) {
                actionsHtml = `
                    <div class="ep-card-footer">
                        <button class="ep-card-btn edit btn-edit-emp" data-id="${emp.id}"><i class="fa-solid fa-pencil"></i> Editar</button>
                        <button class="ep-card-btn delete btn-delete-emp" data-id="${emp.id}"><i class="fa-solid fa-trash"></i></button>
                    </div>`;
            }

            let obsHtml = emp.observaciones ? `<div class="ep-card-obs">${emp.observaciones}</div>` : '';

            cardsHtml += `
                <div class="ep-empresa-card">
                    <div class="ep-card-header">
                        ${logoHtml}
                        <div class="ep-card-title">
                            <h3>${emp.nombre}</h3>
                            <span>${emp.cif}</span>
                        </div>
                        <span class="ep-membresia-badge ${emp.tipo_membresia.toLowerCase()}">${emp.tipo_membresia}</span>
                    </div>
                    ${fotoHtml}
                    <div class="ep-card-body">
                        <div class="ep-card-info-row"><i class="fa-solid fa-user-tie"></i> <span>${emp.responsable}</span></div>
                        <div class="ep-card-info-row"><i class="fa-solid fa-phone"></i> <span>${emp.telefono}</span></div>
                        <div class="ep-card-info-row"><i class="fa-solid fa-envelope"></i> <span>${emp.email}</span></div>
                        <div class="ep-card-info-row"><i class="fa-solid fa-map-location-dot"></i> <span>${emp.zona} - ${emp.direccion}</span></div>
                        <div class="ep-card-info-row"><i class="fa-solid fa-users"></i> <span>${emp.num_trabajadores} emp.</span></div>
                        ${emp.iae ? `<div class="ep-card-info-row"><i class="fa-solid fa-file-invoice"></i> <span>IAE: ${emp.iae}</span></div>` : ''}
                        ${obsHtml}
                    </div>
                    ${actionsHtml}
                </div>`;

            // Render Table
            const logoTableHtml = emp.logo_url 
                ? `<img src="${emp.logo_url}" class="ep-table-logo">`
                : `<div class="ep-table-logo-ph"><i class="fa-solid fa-building"></i></div>`;
            
            let actionsTableHtml = '';
            if (canWrite) {
                actionsTableHtml = `
                    <td style="text-align: right;">
                        <div class="ep-table-actions" style="justify-content: flex-end;">
                            <button class="ep-table-btn edit btn-edit-emp" data-id="${emp.id}" title="Editar"><i class="fa-solid fa-pencil"></i></button>
                            <button class="ep-table-btn delete btn-delete-emp" data-id="${emp.id}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>`;
            }

            tableHtml += `
                <tr>
                    <td>${logoTableHtml}</td>
                    <td><strong>${emp.nombre}</strong><br><small style="color:#64748b">${emp.email}</small></td>
                    <td>${emp.cif}</td>
                    <td>${emp.responsable}<br><small style="color:#64748b">${emp.telefono}</small></td>
                    <td>${emp.zona}</td>
                    <td><span class="badge-membresia ${emp.tipo_membresia.toLowerCase()}">${emp.tipo_membresia}</span></td>
                    ${actionsTableHtml}
                </tr>`;
        });

        $('#ep-emp-cards-view').html(cardsHtml);
        $('#ep-emp-table-body').html(tableHtml);
    }

    // --- Filtros y Ordenación ---
    $('#ep_emp_search').on('keyup', function(e) {
        if (e.key === 'Enter') loadEmpresas();
    });
    // Búsqueda con delay
    let searchTimeout;
    $('#ep_emp_search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadEmpresas, 500);
    });

    $('#ep_emp_filter_membresia, #ep_emp_filter_zona').on('change', loadEmpresas);

    $('.ep-emp-sort-btn').on('click', function() {
        $('.ep-emp-sort-btn').removeClass('active');
        $(this).addClass('active');
        sortState.col = $(this).data('sort');
        sortState.dir = sortState.dir === 'ASC' ? 'DESC' : 'ASC';
        loadEmpresas();
    });

    $('.ep-emp-table th.sortable').on('click', function() {
        const col = $(this).data('sort');
        if (sortState.col === col) {
            sortState.dir = sortState.dir === 'ASC' ? 'DESC' : 'ASC';
        } else {
            sortState.col = col;
            sortState.dir = 'ASC';
        }
        loadEmpresas();
    });

    // --- Toggle Vistas ---
    $('.ep-view-btn').on('click', function() {
        $('.ep-view-btn').removeClass('active');
        $(this).addClass('active');
        currentView = $(this).data('view');
        
        if (currentView === 'cards') {
            $('#ep-emp-table-view').hide();
            $('#ep-emp-cards-view').show();
        } else {
            $('#ep-emp-cards-view').hide();
            $('#ep-emp-table-view').show();
        }
    });

    // --- Exportación Excel/CSV ---
    $('#ep_emp_export_csv').on('click', function() {
        if (!currentData || currentData.length === 0) return Swal.fire('Aviso', 'No hay datos para exportar', 'warning');
        
        let csv = 'Nombre,CIF,Responsable,Telefono,Email,Zona,Trabajadores,Membresia,IAE,Direccion,Observaciones\n';
        currentData.forEach(e => {
            const row = [
                `"${e.nombre}"`, `"${e.cif}"`, `"${e.responsable}"`, `"${e.telefono}"`, `"${e.email}"`,
                `"${e.zona}"`, e.num_trabajadores, `"${e.tipo_membresia}"`, `"${e.iae}"`,
                `"${e.direccion}"`, `"${e.observaciones.replace(/"/g, '""')}"`
            ];
            csv += row.join(',') + '\n';
        });

        const blob = new Blob(["\uFEFF"+csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'empresas_directorio.csv';
        link.click();
    });

    $('#ep_emp_export_excel').on('click', function() {
        exportToExcel('xlsx');
    });

    $('#ep_emp_export_xls').on('click', function() {
        exportToExcel('xls');
    });

    function exportToExcel(ext) {
        if (!currentData || currentData.length === 0) return Swal.fire('Aviso', 'No hay datos para exportar', 'warning');
        
        const data = currentData.map(e => ({
            'Nombre': e.nombre,
            'CIF': e.cif,
            'Responsable': e.responsable,
            'Teléfono': e.telefono,
            'Email': e.email,
            'Zona': e.zona,
            'Nº Trabajadores': e.num_trabajadores,
            'Membresía': e.tipo_membresia,
            'IAE': e.iae,
            'Dirección': e.direccion,
            'Observaciones': e.observaciones
        }));

        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Empresas");

        XLSX.writeFile(wb, `empresas_directorio.${ext}`);
    }

    // --- Funciones del Modal Formulario (Solo si canWrite) ---
    if (canWrite) {
        const $modal = $('#ep_emp_modal');
        const $form = $('#ep_emp_form');

        function openModal(isEdit = false, data = null) {
            $form[0].reset();
            $('#emp_id').val('');
            $('#ep_emp_logo_preview, #ep_emp_foto_preview').hide().attr('src', '');
            $('#emp_logo_url, #emp_foto_url').val('');

            if (isEdit && data) {
                $('#ep_emp_modal_title').html('<i class="fa-solid fa-pencil"></i> Editar Empresa');
                
                $('#emp_id').val(data.id);
                $('#emp_nombre').val(data.nombre);
                $('#emp_responsable').val(data.responsable);
                $('#emp_cif').val(data.cif);
                $('#emp_telefono').val(data.telefono);
                $('#emp_trabajadores').val(data.num_trabajadores);
                $('#emp_direccion').val(data.direccion);
                $('#emp_email').val(data.email);
                
                // Manejo de Zona (si no existe en el select, la añadimos temporalmente)
                const $zonaSelect = $('#emp_zona');
                if (data.zona && $zonaSelect.find(`option[value="${data.zona}"]`).length === 0) {
                    $zonaSelect.append(`<option value="${data.zona}">${data.zona}</option>`);
                }
                $zonaSelect.val(data.zona);

                // Manejo de Membresía (Normalizar para que coincida con las opciones del select)
                let membresia = data.tipo_membresia || 'Basic';
                // Intentar match insensible a mayúsculas
                $('#emp_membresia option').each(function() {
                    if ($(this).val().toLowerCase() === membresia.toLowerCase()) {
                        membresia = $(this).val();
                    }
                });
                $('#emp_membresia').val(membresia);

                $('#emp_iae').val(data.iae);
                $('#emp_observaciones').val(data.observaciones);

                if (data.logo_url) {
                    $('#emp_logo_url').val(data.logo_url);
                    $('#ep_emp_logo_preview').attr('src', data.logo_url).show();
                }
                if (data.foto_url) {
                    $('#emp_foto_url').val(data.foto_url);
                    $('#ep_emp_foto_preview').attr('src', data.foto_url).show();
                }
            } else {
                $('#ep_emp_modal_title').html('<i class="fa-solid fa-building"></i> Alta de Empresa');
            }
            
            $modal.addClass('active');
        }

        function closeModal() {
            $modal.removeClass('active');
        }

        $('#ep_emp_btn_add').on('click', () => openModal(false));
        $('#ep_emp_modal_close, #ep_emp_modal_cancel').on('click', closeModal);

        $(document).on('click', '.btn-edit-emp', function() {
            const id = $(this).data('id');
            const emp = currentData.find(e => e.id == id);
            if (emp) openModal(true, emp);
        });

        // Guardar Empresa
        $form.on('submit', function(e) {
            e.preventDefault();
            const $btn = $('#ep_emp_btn_save');
            const originalText = $btn.html();
            $btn.html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...').prop('disabled', true);

            const isEdit = $('#emp_id').val() !== '';
            const actionType = isEdit ? 'edit' : 'add';
            const formData = $(this).serialize() + '&action=ep_empresas_action&empresa_action=' + actionType + '&security=' + nonce;

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(res) {
                    if (res.success) {
                        Swal.fire({ title: '¡Éxito!', text: res.data.message || res.data, icon: 'success', timer: 1500, showConfirmButton: false });
                        closeModal();
                        loadEmpresas();
                    } else {
                        Swal.fire('Error', res.data, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error al guardar.', 'error');
                },
                complete: function() {
                    $btn.html(originalText).prop('disabled', false);
                }
            });
        });

        // Eliminar Empresa
        $(document).on('click', '.btn-delete-emp', function() {
            const id = $(this).data('id');
            Swal.fire({
                title: '¿Estás seguro?',
                text: 'Esta acción no se puede deshacer.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ep_empresas_action',
                            empresa_action: 'delete',
                            security: nonce,
                            empresa_id: id
                        },
                        success: function(res) {
                            if (res.success) {
                                loadEmpresas();
                                Swal.fire('Eliminada', 'La empresa ha sido eliminada.', 'success');
                            } else {
                                Swal.fire('Error', res.data, 'error');
                            }
                        }
                    });
                }
            });
        });

        // --- Subida de Imágenes ---
        function handleImageUpload(fileInputId, hiddenInputId, previewId) {
            const file = $(fileInputId)[0].files[0];
            if (!file) return;

            const fd = new FormData();
            fd.append('action', 'ep_empresas_upload_image');
            fd.append('security', nonce);
            fd.append('image', file);

            // Mostrar estado de carga (opcional: podrías añadir un spinner)
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function(res) {
                    if (res.success) {
                        $(hiddenInputId).val(res.data.url);
                        $(previewId).attr('src', res.data.url).show();
                    } else {
                        Swal.fire('Error', res.data, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error al subir imagen', 'error');
                }
            });
        }

        $('#ep_emp_logo_file').on('change', () => handleImageUpload('#ep_emp_logo_file', '#emp_logo_url', '#ep_emp_logo_preview'));
        $('#ep_emp_foto_file').on('change', () => handleImageUpload('#ep_emp_foto_file', '#emp_foto_url', '#ep_emp_foto_preview'));

        // --- Modal Importación ---
        const $importModal = $('#ep_emp_import_modal');
        const $importFile  = $('#ep_emp_import_file');
        const $importBtn   = $('#ep_emp_btn_do_import');

        $('#ep_emp_btn_import_trigger').on('click', function(e) {
            e.preventDefault();
            $('#ep_emp_import_modal').addClass('active');
            $('#ep_emp_import_status').hide().html('');
            $importBtn.prop('disabled', true);
            $importFile.val('');
        });

        $('#ep_emp_import_modal_close, #ep_emp_import_modal_cancel').on('click', function() {
            $importModal.removeClass('active');
        });

        $importFile.on('change', function() {
            if (this.files && this.files[0]) {
                $importBtn.prop('disabled', false);
            } else {
                $importBtn.prop('disabled', true);
            }
        });

        $importBtn.on('click', function() {
            const file = $importFile[0].files[0];
            if (!file) return;

            const originalText = $(this).html();
            $(this).html('<i class="fa-solid fa-spinner fa-spin"></i> Procesando...').prop('disabled', true);
            $('#ep_emp_import_status').show().html('<p><i class="fa-solid fa-sync fa-spin"></i> Leyendo archivo...</p>');

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[firstSheetName];
                    
                    // Obtener filas como array de arrays
                    const rows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
                    
                    if (rows.length <= 1) {
                        throw new Error('El archivo está vacío o solo contiene la cabecera.');
                    }

                    // Quitar cabecera
                    rows.shift();

                    $('#ep_emp_import_status').html('<p><i class="fa-solid fa-cloud-upload"></i> Enviando datos al servidor...</p>');

                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'ep_empresas_action',
                            empresa_action: 'import_json',
                            security: nonce,
                            rows: JSON.stringify(rows)
                        },
                        success: function(res) {
                            if (res.success) {
                                Swal.fire('¡Importado!', res.data.message, 'success');
                                $importModal.removeClass('active');
                                loadEmpresas();
                            } else {
                                Swal.fire('Error', res.data, 'error');
                                $('#ep_emp_import_status').html('<p style="color:red;">Error: ' + res.data + '</p>');
                            }
                        },
                        error: function() {
                            Swal.fire('Error', 'Error de red al importar.', 'error');
                        },
                        complete: function() {
                            $importBtn.html(originalText).prop('disabled', false);
                        }
                    });

                } catch (err) {
                    Swal.fire('Error', err.message || 'Error al procesar el archivo.', 'error');
                    $('#ep_emp_import_status').html('<p style="color:red;">Error: ' + err.message + '</p>');
                    $importBtn.html(originalText).prop('disabled', false);
                }
            };
            reader.readAsArrayBuffer(file);
        });
    }

    // --- Modal Ajustes (Solo si isAdmin) ---
    $(document).on('click', '#ep_emp_btn_settings', function() {
        $('#ep_emp_settings_modal').addClass('active');
    });

    $(document).on('click', '#ep_emp_settings_close, #ep_emp_settings_cancel', function() {
        $('#ep_emp_settings_modal').removeClass('active');
    });

    // Subida de logo en ajustes
    $(document).on('change', '#ep_emp_set_logo_file', function() {
        const file = this.files[0];
        if (!file) return;

        const fd = new FormData();
        fd.append('action', 'ep_empresas_upload_image');
        fd.append('security', nonce);
        fd.append('image', file);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    $('#set_app_logo').val(res.data.url);
                    $('#ep_emp_set_logo_preview').attr('src', res.data.url).show();
                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            }
        });
    });

    // Guardar Ajustes
    $(document).on('submit', '#ep_emp_settings_form', function(e) {
        e.preventDefault();
        const $btn = $('#ep_emp_btn_save_settings');
        const originalText = $btn.html();
        $btn.html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...').prop('disabled', true);

        const formData = {
            action: 'ep_empresas_action',
            empresa_action: 'save_settings',
            security: nonce,
            app_name: $('#set_app_name').val(),
            app_logo: $('#set_app_logo').val()
        };

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(res) {
                if (res.success) {
                    Swal.fire({ 
                        title: '¡Guardado!', 
                        text: 'Los ajustes se han guardado. Recargando...', 
                        icon: 'success', 
                        timer: 2000, 
                        showConfirmButton: false 
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            },
            complete: function() {
                $btn.html(originalText).prop('disabled', false);
            }
        });
    });

    // Inicializar
    loadEmpresas();
});
