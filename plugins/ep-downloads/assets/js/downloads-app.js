
        jQuery(document).ready(function ($) {
            // Datos de usuarios para el buscador
            const allUsers = <?php echo json_encode($users); ?>;
            ep_vars.users = allUsers.map(user => ({ id: user.ID, display_name: user.display_name }));


            // --- Nueva Lógica Estilo Google Drive ---

            // Abrir/Cerrar Menú Nuevo
            $('#epBtnNewDrive').on('click', function (e) {
                e.stopPropagation();
                $('#epNewMenu').toggle();
            });

            $(document).on('click', function () {
                $('#epNewMenu').hide();
            });

            // Shortcuts desde el menú Nuevo
            $('.ep-create-folder-shortcut').on('click', function () {
                const activeTab = $('.ep-drive-nav-btn.active').data('tab');
                const type = activeTab === 'public-resources' ? 'global' : 'personal';
                $(`.ep-create-folder-btn[data-type="${type}"]`).first().trigger('click');
            });

            // Al cargar, ocultar sync si estamos en pública
            if ($('.ep-drive-nav-btn.active').data('tab') === 'public-resources') {
                $('#epForceSyncBtn').parent().hide(); // Ocultar el contenedor de 20%
                $('#epLinkOneDriveWrapper').show(); // Mostrar el de vincular
                $('.ep-drive-search-bar').css('width', '60%');
            }

            // Navegación Refinada entre secciones
            $('.ep-drive-nav-btn').on('click', function () {
                const tabId = $(this).data('tab');

                $('.ep-drive-nav-btn').removeClass('active');
                $(this).addClass('active');

                $('.ep-tab-content').hide().removeClass('active');
                $('#' + tabId).show().addClass('active');

                // Lógica de visibilidad del botón de Sincronizar y botón Nuevo
                if (tabId === 'private-management') {
                    $('#epForceSyncBtn').parent().show(); // Mostrar el contenedor de 20%
                    $('#epLinkOneDriveWrapper').hide(); // Ocultar Vincular en privado
                    $('.ep-drive-search-bar').css('width', '60%');
                    $('#epMainNewBtnWrapper').show(); // Todos pueden subir en private-management
                } else {
                    $('#epForceSyncBtn').parent().hide(); // Ocultar el contenedor de 20%
                    $('#epLinkOneDriveWrapper').show(); // Mostrar Vincular en Empresa
                    $('.ep-drive-search-bar').css('width', '60%');
                    if (!ep_vars.can_write) {
                        $('#epMainNewBtnWrapper').hide(); // Ocultar Nuevo si no puede escribir
                    } else {
                        $('#epMainNewBtnWrapper').show();
                    }
                }

                setTimeout(() => {
                    initFolders();
                }, 50);
            });

            // Drag & Drop Global
            let dragCounter = 0;
            $(window).on('dragenter', function (e) {
                e.preventDefault();
                dragCounter++;
                $('#epGlobalDropOverlay').addClass('active');
            });

            $(window).on('dragleave', function (e) {
                e.preventDefault();
                dragCounter--;
                if (dragCounter === 0) {
                    $('#epGlobalDropOverlay').removeClass('active');
                }
            });

            $(window).on('dragover', function (e) {
                e.preventDefault();
            });

            $(window).on('drop', function (e) {
                e.preventDefault();
                dragCounter = 0;
                $('#epGlobalDropOverlay').removeClass('active');

                const files = e.originalEvent.dataTransfer.files;
                if (files && files.length > 0) {
                    const activeTab = $('.ep-drive-nav-btn.active').data('tab');
                    const categoryId = currentFolder[activeTab] || '0';
                    const privacy = activeTab === 'public-resources' ? 'public' : 'private';
                    prepareUploadDrive(files, privacy, categoryId);
                }
            });

            // Buscador Global Reactivo
            $('#epDriveGlobalSearch').on('input', function () {
                const term = $(this).val().toLowerCase();
                const activeTab = $('.ep-drive-nav-btn.active').data('tab');
                const $tab = $('#' + activeTab);

                if (!term) {
                    $tab.find('.ep-document-card, .ep-folder-card').show();
                    return;
                }

                $tab.find('.ep-document-card').each(function () {
                    const name = $(this).find('.ep-document-name').text().toLowerCase();
                    $(this).toggle(name.includes(term));
                });

                $tab.find('.ep-folder-card').each(function () {
                    const name = $(this).find('h3').text().toLowerCase();
                    $(this).toggle(name.includes(term));
                });
            });

            function prepareUploadDrive(files, privacy, categoryId) {
                pendingFiles = files;
                const file = files[0];
                const targetId = privacy === 'private' ? ($('#target_user_id').val() || '0') : '0';

                let targetText = privacy === 'public' ? 'Empresa (Público)' : 'Mi Unidad (Privado)';
                if (privacy === 'private' && targetId !== '0') {
                    targetText = 'Empleado (Privado)';
                }

                $('#confirmFileName').text(file.name);
                $('#confirmFileSize').text(formatBytes(file.size));
                $('#confirmTargetName').html(`<b>Destino:</b> ${targetText}`);
                const catName = $(`#${$('.ep-drive-nav-btn.active').data('tab')} .ep-filter-category option[value="${categoryId}"]`).text() || 'Raíz';
                $('#confirmFolderName').text(`Carpeta: ${catName}`);

                // Resetear estados del modal
                $('#epUploadProgressModal').hide();
                $('#epUploadStatusFinal').hide();
                $('#epUploadProgressModal .ep-progress-fill').css('width', '0%');
                $('#epUploadProgressModal .ep-progress-percent').text('0%');
                $('#epFinalConfirm').prop('disabled', false).show();
                $('#epBtnModifyUpload').show();

                $('#epConfirmModal').fadeIn(200).css('display', 'flex');

                // Botón Cambiar / Cancelar
                $('#epBtnModifyUpload').off('click').on('click', function () {
                    $('#epConfirmModal').fadeOut(200);
                    $('#ep_document_file').val('');
                    pendingFiles = null;
                });

                $('#epFinalConfirm').off('click').on('click', function () {
                    $(this).prop('disabled', true).hide();
                    $('#epBtnModifyUpload').hide();
                    executeUploadDrive(files, privacy, categoryId, targetId);
                });
            }

            function executeUploadDrive(files, privacy, categoryId, targetId) {
                const file = files[0];
                const formData = new FormData();
                formData.append('action', 'ep_upload_document');
                formData.append('security', ep_vars.nonce);
                formData.append('document_type', privacy);
                formData.append('target_user_id', targetId);
                formData.append('category_id', categoryId);
                formData.append('ep_document_file', file);

                const activeTab = $('.ep-drive-nav-btn.active').data('tab');

                // Mostrar barra de progreso interna del modal
                $('#epUploadProgressModal').fadeIn(200);

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function () {
                        const xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener("progress", function (evt) {
                            if (evt.lengthComputable) {
                                const percent = Math.round((evt.loaded / evt.total) * 100);
                                $('#epUploadProgressModal .ep-progress-fill').css('width', percent + '%');
                                $('#epUploadProgressModal .ep-progress-percent').text(percent + '%');
                            }
                        }, false);
                        return xhr;
                    },
                    success: function (res) {
                        if (res.success) {
                            $('#epUploadProgressModal').hide();
                            $('#epUploadStatusFinal').fadeIn(200);

                            setTimeout(() => {
                                $('#epConfirmModal').fadeOut(200);
                                fetchLiveFolderContents(activeTab, categoryId);
                            }, 1500);
                        } else {
                            $('#epUploadProgressModal').hide();
                            $('#epFinalConfirm').prop('disabled', false).show();
                            $('#epBtnModifyUpload').show();
                            Swal.fire('Error', res.data || 'No se pudo subir el archivo', 'error');
                        }
                    },
                    error: function () {
                        $('#epUploadProgressModal').hide();
                        $('#epFinalConfirm').prop('disabled', false).show();
                        $('#epBtnModifyUpload').show();
                        Swal.fire('Error', 'Error de red al subir el archivo', 'error');
                    }
                });
            }

            // Lógica para vincular OneDrive personal
            $('#epBtnLinkOneDrive').on('click', function() {
                const btn = $(this);
                const originalHtml = btn.html();

                Swal.fire({
                    title: '¿Vincular con tu OneDrive?',
                    text: 'Esto creará un acceso directo en tu OneDrive personal que apunta a la carpeta compartida del Portal. ¡Así podrás verlo desde tu PC!',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, vincular ahora',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#1a73e8'
                }).then((result) => {
                    if (result.isConfirmed) {
                        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Vinculando...');
                        
                        $.ajax({
                            url: ep_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'ep_link_to_my_onedrive',
                                security: ep_vars.nonce
                            },
                            success: function(res) {
                                btn.prop('disabled', false).html(originalHtml);
                                if (res.success) {
                                    Swal.fire({
                                        title: '¡Hecho!',
                                        text: res.data.message,
                                        icon: 'success'
                                    });
                                } else {
                                    Swal.fire('Error', res.data || 'No se pudo vincular la carpeta', 'error');
                                }
                            },
                            error: function() {
                                btn.prop('disabled', false).html(originalHtml);
                                Swal.fire('Error', 'Error de red. Inténtalo de nuevo más tarde.', 'error');
                            }
                        });
                    }
                });
            });


            // Lógica del Buscador Reactivo
            const $searchInput = $('#epEmployeeSearchInput');
            const $results = $('#epSearchResults');
            const $targetId = $('#target_user_id');
            const $selectedBox = $('#epSelectedEmployee');
            const $selectedName = $('#epSelectedName');

            $searchInput.on('input', function () {
                const term = $(this).val().toLowerCase();
                if (term.length < 2) {
                    $results.hide();
                    return;
                }

                const filtered = allUsers.filter(u => u.display_name.toLowerCase().includes(term));

                if (filtered.length > 0) {
                    let html = '';
                    filtered.forEach(u => {
                        html += `<div class="ep-search-item" data-id="${u.ID}" data-name="${u.display_name}">
                        <i class="fa-solid fa-user"></i> ${u.display_name}
                    </div>`;
                    });
                    $results.html(html).show();
                } else {
                    $results.html('<div class="ep-search-item">No se encontraron empleados</div>').show();
                }
            });

            // Seleccionar Empleado
            $(document).on('click', '.ep-search-item', function () {
                const id = $(this).data('id');
                const name = $(this).data('name');
                if (!id) return;

                $targetId.val(id);
                $selectedName.text(name);
                $selectedBox.show();
                $results.hide();
                $searchInput.val('').hide();
                $('.ep-input-group i').hide();
            });

            // Limpiar Selección
            $('#epClearSelected').on('click', function () {
                resetEmployeeSearch();
            });

            function resetEmployeeSearch() {
                $targetId.val('0');
                $selectedBox.hide();
                $searchInput.val('').show();
                $('.ep-input-group i').show();
            }

            // Manejo de Drag & Drop
            $(document).on('dragover', '#epDropZone', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('drag-over');
            });

            $(document).on('dragleave', '#epDropZone', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            });

            $(document).on('drop', '#epDropZone', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');

                const files = e.originalEvent.dataTransfer.files;
                if (files && files.length > 0) {
                    prepareUpload(files);
                }
            });

            // Manejo de Selección de Archivo
            // Manejo de Selección de Archivo (Unificado)
            $('#ep_document_file').on('change', function (e) {
                const activeTab = $('.ep-drive-nav-btn.active').data('tab');
                const categoryId = currentFolder[activeTab] || '0';
                const privacy = activeTab === 'public-resources' ? 'public' : 'private';
                prepareUploadDrive(this.files, privacy, categoryId);
            });

            let pendingFiles = null;

            function prepareUpload(files) {
                if (!files || files.length === 0) return;
                pendingFiles = files;

                const file = files[0];
                const privacy = $('input[name="upload_privacy"]:checked').val() || 'public';
                const targetId = $('#target_user_id').val() || '0';
                const categoryName = $('#upload_category option:selected').text();
                const maxSizeMb = <?php echo (int) $max_size_mb; ?>;
                const size = file.size;
                const mime = file.type;
                // Allow office mime types and open document
                const allowedMimes = [
                    'application/pdf', 'image/jpeg', 'image/png', 'image/jpg',
                    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'text/csv', 'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.presentation'
                ];

                if (size > maxSizeMb * 1024 * 1024) {
                    alert(`El archivo ${file.name} excede el tamaño máximo de ${maxSizeMb}MB.`);
                    return;
                }

                // Check extension as fallback for some mime types behaving weirdly
                const ext = file.name.split('.').pop().toLowerCase();
                const allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'odt', 'ods', 'odp'];

                if (!allowedMimes.includes(mime) && !allowedExts.includes(ext)) {
                    alert(`Formato no permitido: ${file.name}`);
                    return;
                }

                let targetName = '<b>Público</b> (Disponible para todos)';

                if (privacy === 'private') {
                    const employeeName = $('#epSelectedName').text();
                    if (targetId !== '0' && employeeName) {
                        targetName = `<b>Privado</b> (Enviado a: ${employeeName})`;
                    } else {
                        targetName = '<b>Personal</b> (Solo para ti)';
                    }
                }

                // Actualizar Modal
                $('#confirmFileName').text(file.name);
                $('#confirmFileSize').text(formatBytes(file.size));
                $('#confirmTargetName').html(targetName);
                $('#confirmFolderName').text('Carpeta: ' + categoryName);

                // Icono según tipo
                let icon = 'fa-file';
                if (ext === 'pdf') icon = 'fa-file-pdf';
                else if (['doc', 'docx'].includes(ext)) icon = 'fa-file-word';
                else if (['xls', 'xlsx'].includes(ext)) icon = 'fa-file-excel';
                else if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) icon = 'fa-file-image';
                else if (['zip', 'rar', '7z'].includes(ext)) icon = 'fa-file-zipper';

                $('#confirmFileIcon').attr('class', 'fa-solid ' + icon);

                $('#epConfirmModal').fadeIn(200).css('display', 'flex');
            }

            // Cancelar subida
            $('#epCancelUpload').on('click', function () {
                $('#epConfirmModal').fadeOut(200);
                $('#ep_document_file').val('');
                pendingFiles = null;
            });

            // Confirmar subida final
            $('#epFinalConfirm').on('click', function () {
                $('#epConfirmModal').hide();
                if (pendingFiles) {
                    executeUpload(pendingFiles);
                }
            });



            function executeUpload(files) {
                const file = files[0];
                const privacy = $('input[name="upload_privacy"]:checked').val() || 'public';
                const targetUser = $('#target_user_id').val() || '0';
                const category = $('#upload_category').val() || '0';

                const formData = new FormData();
                formData.append('action', 'ep_upload_document');
                formData.append('security', ep_vars.nonce);
                formData.append('document_type', privacy);
                formData.append('target_user_id', targetUser);
                formData.append('category_id', category);
                formData.append('ep_document_file', file);

                $('#epUploadProgress').show();
                $('#epUploadMessage').html('');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function () {
                        const xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener("progress", function (evt) {
                            if (evt.lengthComputable) {
                                const percentComplete = Math.round((evt.loaded / evt.total) * 100);
                                $('.ep-progress-fill').css('width', percentComplete + '%');
                                $('.ep-progress-percent').text(percentComplete + '%');
                                $('.ep-progress-text').text('Subiendo: ' + file.name);
                            }
                        }, false);
                        return xhr;
                    },
                    success: function (res) {
                        $('#epUploadProgress').hide();
                        if (res.success) {
                            $('#epUploadMessage').html('<p class="ep-text-success"><i class="fa-solid fa-circle-check"></i> ¡Archivo subido con éxito!</p>');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            $('#epUploadMessage').html('<p class="ep-text-danger">Error: ' + (res.data || 'Archivo no permitido') + '</p>');
                        }
                    },
                    error: function () {
                        $('#epUploadProgress').hide();
                        $('#epUploadMessage').html('<p class="ep-text-danger">Error de conexión al subir el archivo.</p>');
                    }
                });
            }

            // Mover Documento (Abrir Modal) - Delegado
            $(document).on('click', '.ep-move-doc', function () {
                const id = $(this).data('id');
                const tabId = $(this).closest('.ep-documents-grid').data('tab');
                const currentItems = currentItemsCache[tabId] || [];

                $('#move_doc_id').val(id);
                $('#move_doc_tab').val(tabId);

                const $select = $('#move_doc_category');
                $select.empty();
                $select.append('<option value="0">Raíz</option>');

                // Llenar con las carpetas de la vista actual
                currentItems.forEach(item => {
                    if (item.type === 'folder' && item.id !== id) {
                        $select.append(`<option value="${item.id}">${item.name}</option>`);
                    }
                });

                $('#epSaveMove').prop('disabled', false).text('Guardar Cambios');
                $('#epMoveDocModal').fadeIn(200);
            });

            $('#epCancelMove').on('click', function () {
                $('#epMoveDocModal').fadeOut(200);
            });

            $('#epSaveMove').on('click', function () {
                const id = $('#move_doc_id').val();
                const newCat = $('#move_doc_category').val();
                const tabId = $('#move_doc_tab').val();
                const type = tabId === 'public-resources' ? 'global' : 'personal';

                $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');
                updateDocumentCategory(id, newCat, type, tabId);
            });

            // Ver Feedback - Delegado (Corregido Selector)
            $(document).on('click', '.ep-view-feedback', function (e) {
                e.stopPropagation();
                const docId = $(this).data('id');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_get_document_feedback',
                        security: ep_vars.nonce,
                        document_id: docId
                    },
                    success: function (res) {
                        if (res.success) {
                            $('#viewFeedbackText').text(res.data || 'Sin comentarios adicionales.');
                            $('#epViewFeedbackModal').fadeIn(200).css('display', 'flex');
                        }
                    }
                });
            });

            // Firma - Delegado
            $(document).on('click', '.ep-sign-doc', function (e) {
                e.stopPropagation();
                const docId = $(this).data('id');
                // Redirigir a la app de firma o abrir modal de firma
                window.location.href = '?view=signature&post_id=' + docId;
            });

            // Ajax Delete - Cambiado a delegación para mantener interactividad tras cambios dinámicos
            $(document).on('click', '.ep-delete-doc', function () {
                if (!confirm('¿Estás seguro de borrar este documento?')) return;

                const id = $(this).data('id');
                const tabId = $(this).closest('.ep-documents-grid').data('tab');
                const type = tabId === 'public-resources' ? 'global' : 'personal';

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_delete_document',
                        security: ep_vars.nonce,
                        document_id: id,
                        type: type
                    },
                    success: function (res) {
                        if (res.success) {
                            fetchLiveFolderContents(tabId, currentFolder[tabId]);
                        } else {
                            alert('Error: ' + res.data);
                        }
                    }
                });
            });

            // Cerrar resultados al hacer clic fuera
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#epEmployeeSearchWrapper').length) {
                    $results.hide();
                }
            });


            // (Handlers de revisión legacy eliminados - usa los handlers de líneas 1710+ con SweetAlert2)

            // Botón Enviar a Firma (RRHH) - Abrir Modal
            $(document).on('click', '.ep-send-signature', function (e) {
                e.preventDefault();
                const $btn = $(this);
                const docId = $btn.data('id');

                $('#epSignatureModal').data('doc-id', docId).fadeIn();

                // Limpiar selección previa para forzar "Elegir firmante"
                $('#signer_user_id').val(0);
                $('#epSignerSearchInput').show().val('').focus();
                $('#epSelectedSigner').hide();
                $('#epSignerResults').hide();
            });

            // Cerrar modales (Genérico y Robusto) - Excluye feedback/move que tienen handlers propios
            $(document).on('click', '.ep-close-modal, .ep-btn-secondary[id*="Cancel"]:not(#epCancelFeedback):not(#epCancelMove)', function () {
                $(this).closest('.ep-confirm-modal, .ep-signature-modal').fadeOut(200);
            });

            // Limpiar firmante seleccionado
            $('#epClearSigner').on('click', function () {
                $('#signer_user_id').val(0);
                $('#epSelectedSigner').hide();
                $('#epSignerSearchInput').show().val('').focus();
            });

            // Confirmar Envío a Firma desde Modal
            $('#epConfirmSendSignature').on('click', function () {
                const $btnConfirm = $(this);
                const docId = $('#epSignatureModal').data('doc-id');
                const signerId = $('#signer_user_id').val();

                if (!signerId || signerId == '0') {
                    alert('Por favor, selecciona un firmante.');
                    return;
                }

                $btnConfirm.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Enviando...');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_send_to_signature',
                        security: ep_vars.nonce,
                        post_id: docId,
                        signer_id: signerId
                    },
                    success: function (res) {
                        $btnConfirm.prop('disabled', false).html('<i class="fa-solid fa-paper-plane"></i> Enviar a Firma');
                        if (res.success) {
                            alert(res.data);
                            $('#epSignatureModal').fadeOut();
                            location.reload();
                        } else {
                            alert('Error: ' + res.data);
                        }
                    },
                    error: function () {
                        $btnConfirm.prop('disabled', false).html('<i class="fa-solid fa-paper-plane"></i> Enviar a Firma');
                        alert('Error de conexión.');
                    }
                });
            });

            // Búsqueda de Destinatarios en Modal de Firma
            $('#epSignerSearchInput').on('keyup', function () {
                const query = $(this).val();
                if (query.length < 3) {
                    $('#epSignerResults').hide();
                    return;
                }

                const results = ep_vars.users.filter(u =>
                    u.display_name.toLowerCase().includes(query.toLowerCase())
                );

                let html = '';
                results.slice(0, 5).forEach(u => {
                    html += `<div class="ep-search-item signer-match" data-id="${u.id}" data-name="${u.display_name}">
                    <i class="fa-solid fa-user"></i> ${u.display_name}
                </div>`;
                });

                if (html) {
                    $('#epSignerResults').html(html).show();
                } else {
                    $('#epSignerResults').hide();
                }
            });

            $(document).on('click', '.signer-match', function () {
                const id = $(this).data('id');
                const name = $(this).data('name');
                $('#signer_user_id').val(id);
                $('#epSignerName').text(name);
                $('#epSignerSearchInput').hide();
                $('#epSelectedSigner').show();
                $('#epSignerResults').hide();
            });

            // --- Lógica de Carpetas y Búsqueda Interactiva (Live API View) ---
            let currentFolder = {
                'public-resources': '0',
                'private-management': '0'
            };
            let folderHistory = {
                'public-resources': [],
                'private-management': []
            };

            // Mantendremos una caché en memoria de los items mostrados para la búsqueda local
            let currentItemsCache = {
                'public-resources': [],
                'private-management': []
            };

            // Función auxiliar para formatear tamaños de archivo
            function formatBytes(bytes, decimals = 2) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const dm = decimals < 0 ? 0 : decimals;
                const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
            }

            function fetchLiveFolderContents(tabId, folderId = '0') {
                const $grid = $(`.ep-documents-grid[data-tab="${tabId}"]`);
                const $foldersWrapper = $grid.find('.ep-folders-wrapper');
                const $filesWrapper = $grid.find('.ep-files-wrapper');
                const type = tabId === 'public-resources' ? 'public' : 'personal';

                $filesWrapper.html(`
                <div class="ep-loading-state" style="text-align: center; padding: 2rem;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 2rem; color: var(--ep-primary);"></i>
                    <p>Cargando documentos...</p>
                </div>
            `);
                $foldersWrapper.empty();

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_get_live_folder_contents',
                        security: ep_vars.nonce,
                        folder_id: folderId,
                        type: type
                    },
                    success: function (res) {
                        if (res.success) {
                            // Si la llamada fue con '0', la respuesta nos da el folder_id raíz real
                            if (folderId === '0' || !folderId) {
                                currentFolder[tabId] = res.data.folder_id;
                                folderHistory[tabId] = []; // Reset history si estamos en raíz
                            } else {
                                currentFolder[tabId] = folderId; // O el solicitado
                            }

                            console.log(`EP_Downloads: [${tabId}] Ítems recibidos:`, res.data.items?.length || 0);
                            currentItemsCache[tabId] = res.data.items || [];
                            renderLiveContents(tabId);
                        } else {
                            console.error(`EP_Downloads: [${tabId}] Error en respuesta AJAX:`, res.data);
                            $filesWrapper.html('<div class="ep-empty-state"><p class="ep-text-danger">Error: ' + res.data + '</p></div>');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error(`EP_Downloads: [${tabId}] Error AJAX:`, status, error);
                        $filesWrapper.html('<div class="ep-empty-state"><p class="ep-text-danger">Error de conexión con OneDrive.</p></div>');
                    }
                });
            }

            function renderLiveContents(tabId) {
                const $grid = $(`.ep-documents-grid[data-tab="${tabId}"]`);
                const $foldersWrapper = $grid.find('.ep-folders-wrapper');
                const $filesWrapper = $grid.find('.ep-files-wrapper');
                const items = currentItemsCache[tabId];
                const searchQuery = ($(`.ep-tab-content#${tabId} .ep-search-files`).val() || '').toLowerCase();

                $foldersWrapper.empty();
                $filesWrapper.empty();

                // 1. Mostrar/Ocultar botón de volver
                let $backBtn = $grid.find('.ep-back-to-folders');
                if ($backBtn.length === 0) {
                    $backBtn = $('<div class="ep-back-to-folders" style="display:none; cursor:pointer; margin-bottom: 1rem;"><i class="fa-solid fa-arrow-left"></i> Volver a la carpeta anterior</div>');
                    $grid.prepend($backBtn);
                }

                if (folderHistory[tabId].length > 0 && !searchQuery) {
                    $backBtn.show().css('display', 'flex');
                } else {
                    $backBtn.hide();
                }

                let fileCount = 0;
                let folderCount = 0;

                items.forEach(item => {

                    const matchesSearch = item.name.toLowerCase().includes(searchQuery);
                    if (searchQuery && !matchesSearch) return;

                    if (item.type === 'folder') {
                        folderCount++;
                        // Render folder
                        const folderHtml = `
                        <div class="ep-folder-card" data-id="${item.id}" data-name="${item.name}">
                            <div class="ep-folder-icon">
                                <i class="fa-solid ${tabId === 'public-resources' ? 'fa-globe' : 'fa-folder'}"></i>
                                </div>
                            <div class="ep-folder-info">
                                <h3>${item.name}</h3>
                            </div>
                            <div class="ep-folder-actions">
                                <button class="ep-delete-category-btn" data-id="${item.id}" title="Eliminar Carpeta">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>`;
                        $foldersWrapper.append(folderHtml);
                    } else if (item.type === 'file') {
                        fileCount++;
                        // Determine Icon
                        let ext = item.name.split('.').pop().toLowerCase();
                        let iconClass = 'fa-file';
                        if (ext === 'pdf') iconClass = 'fa-file-pdf color-red';
                        else if (['doc', 'docx'].includes(ext)) iconClass = 'fa-file-word color-blue';
                        else if (['xls', 'xlsx'].includes(ext)) iconClass = 'fa-file-excel color-green';
                        else if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) iconClass = 'fa-file-image color-purple';

                        const dateStr = item.lastModified ? new Date(item.lastModified).toLocaleDateString() : '';

                        const isOfficeDoc = ['pdf', 'doc', 'docx', 'xls', 'xlsx'].includes(ext);
                        // Extra Actions based on WP Metadata (SIGNATURE, OKI, FEEDBACK)
                        let actionButtonsHtml = '';
                        if (item.wp_id) {
                            // 1. Lógica de Firma
                            if (item.requires_signature && !item.is_signed) {
                                actionButtonsHtml += `<button class="ep-btn-icon sign ep-sign-doc" data-id="${item.wp_id}" title="Firmar Documento"><i class="fa-solid fa-pen-nib"></i></button>`;
                            } else if (item.is_signed) {
                                actionButtonsHtml += `<span class="ep-badge success" title="Firmado"><i class="fa-solid fa-check-double"></i></span>`;
                            }

                            // Permitir solicitar firma si no está firmado
                            if (!item.is_signed && (ep_vars.user_role === 'administrator' || ep_vars.user_role === 'hr' || tabId === 'private-management')) {
                                actionButtonsHtml += `<button class="ep-btn-icon signature ep-send-signature" data-id="${item.wp_id}" title="Solicitar Firma"><i class="fa-solid fa-signature"></i></button>`;
                            }

                            // Badges y Feedback logic
                            let badgesHtml = '';
                            if (item.review_status === 'ok') {
                                badgesHtml += `<span class="ep-status-pill oki"><i class="fa-solid fa-check-circle"></i> OK</span>`;
                            } else if (item.review_status === 'feedback' || (item.feedback && item.feedback.trim() !== '')) {
                                badgesHtml += `<div class="ep-doc-feedback-badge"><i class="fa-solid fa-comment-dots"></i> ${item.feedback || 'Feedback pendiente'}</div>`;
                            }

                            if (item.onedrive_id) {
                                badgesHtml += `<span class="ep-status-pill onedrive"><i class="fa-brands fa-microsoft"></i> OneDrive</span>`;
                            }
                            if (item.is_signed) {
                                badgesHtml += `<span class="ep-status-pill signed"><i class="fa-solid fa-signature"></i> Firmado</span>`;
                            }

                            // Badge de compartición
                            if (item.is_shared) {
                                const sharedTooltip = item.shared_with.length ? `Compartido con: ${item.shared_with.join(', ')}` : 'Compartido';
                                badgesHtml += `<span class="ep-status-pill ep-shared-badge" title="${sharedTooltip}"><i class="fa-solid fa-share-nodes"></i> Compartido</span>`;
                            }
                            if (item.is_shared_with_me) {
                                badgesHtml += `<span class="ep-status-pill ep-shared-me-badge" title="Compartido por ${item.owner_name}"><i class="fa-solid fa-user-check"></i> Compartido conmigo</span>`;
                            }

                            const footerHtml = badgesHtml ? `<div class="ep-doc-footer-status">${badgesHtml}</div>` : '';

                            // Nombre del propietario (solo si no soy yo)
                            const ownerHtml = (item.owner_name && item.is_shared_with_me)
                                ? `<div class="ep-doc-owner"><i class="fa-solid fa-circle-user"></i> ${item.owner_name}</div>`
                                : '';

                            // Render File con Menú de Tres Puntos
                            const fileHtml = `
                                <div class="ep-document-card" id="doc-${item.id}" data-id="${item.id}" draggable="true">
                                    <div class="ep-doc-actions-menu">
                                        <button class="ep-actions-trigger"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                        <div class="ep-actions-dropdown">
                                            <div class="ep-menu-group">
                                                <button class="ep-action-item ep-view-doc-live" data-id="${item.id}"><i class="fa-solid fa-eye"></i><span>Vista Previa</span></button>
                                                <button class="ep-action-item ep-download-doc" data-id="${item.id}" data-url="${item.downloadUrl}"><i class="fa-solid fa-download"></i><span>Descargar</span></button>
                                            </div>
                                            ${(tabId !== 'public-resources' || ep_vars.can_write) ? `<div class="ep-menu-divider"></div>
                                            <div class="ep-menu-group">
                                                <button class="ep-action-item ep-share-doc" data-id="${item.id}"><i class="fa-solid fa-share-nodes"></i><span>Compartir...</span></button>
                                                <button class="ep-action-item ep-send-to-signature" data-id="${item.id}"><i class="fa-solid fa-signature"></i><span>Enviar a firma</span></button>
                                                ${(tabId !== 'public-resources' && !item.onedrive_id) ? `<button class="ep-action-item ep-backup-onedrive" data-id="${item.id}"><i class="fa-brands fa-microsoft"></i><span>Backup OneDrive</span></button>` : ''}
                                                <button class="ep-action-item ep-review-doc" data-id="${item.id}"><i class="fa-solid fa-check-double"></i><span>Revisar</span></button>
                                            </div>` : ''}
                                            <div class="ep-menu-divider"></div>
                                            <div class="ep-menu-group">
                                                <button class="ep-action-item ep-move-doc" data-id="${item.id}"><i class="fa-solid fa-folder-tree"></i><span>Mover</span></button>
                                                <button class="ep-action-item danger ep-delete-doc" data-id="${item.id}"><i class="fa-solid fa-trash"></i><span>Eliminar</span></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ep-doc-main">
                                        <div class="ep-doc-icon"><i class="fa-solid ${iconClass}"></i></div>
                                        <div class="ep-doc-info">
                                            <div class="ep-doc-title-wrapper">
                                                <h3 class="ep-doc-title truncated" title="${item.name}">${item.name}</h3>
                                            </div>
                                            ${ownerHtml}
                                            <div class="ep-doc-meta">${dateStr} • ${formatBytes(item.size, 1)}</div>
                                        </div>
                                    </div>
                                    ${footerHtml}
                                </div>`;
                            $filesWrapper.append(fileHtml);
                        }
                    }
                });

                if (fileCount === 0 && folderCount === 0) {
                    let msg = searchQuery ? 'No se encontraron resultados.' : 'Esta carpeta está vacía.';
                    $filesWrapper.html(`
                    <div class="ep-empty-state">
                        <i class="fa-solid ${tabId === 'public-resources' ? 'fa-globe' : 'fa-folder-open'}"></i>
                        <p>${msg}</p>
                    </div>`);
                }

                // Lógica de Scroll para títulos largos
                setTimeout(() => {
                    $filesWrapper.find('.ep-doc-title').each(function () {
                        const $title = $(this);
                        const $wrapper = $title.parent();
                        if (this.scrollWidth > $wrapper.width()) {
                            $title.addClass('scrolling');
                        }
                    });
                }, 100);
            }

            function initFolders() {
                // Carga inicial
                fetchLiveFolderContents('public-resources', '0');
                fetchLiveFolderContents('private-management', '0');
            }

            // Navegación de carpetas
            $(document).on('click', '.ep-folder-card', function (e) {
                // Evitar si han hecho click en el botón de borrar
                if ($(e.target).closest('.ep-delete-category-btn').length > 0) return;

                const targetId = $(this).data('id').toString();
                const tabId = $(this).closest('.ep-documents-grid').data('tab');

                folderHistory[tabId].push(currentFolder[tabId]);
                fetchLiveFolderContents(tabId, targetId);
            });

            $(document).on('click', '.ep-back-to-folders', function () {
                const tabId = $(this).closest('.ep-documents-grid').data('tab');
                if (folderHistory[tabId].length > 0) {
                    const prevFolder = folderHistory[tabId].pop();
                    fetchLiveFolderContents(tabId, prevFolder);
                } else {
                    fetchLiveFolderContents(tabId, '0');
                }
            });

            // Buscador reactivo local
            $('.ep-search-files').on('input', function () {
                const tabId = $(this).closest('.ep-tab-content').attr('id');
                renderLiveContents(tabId);
            });

            // --- Drag & Drop ---
            let draggedDocId = null;

            $(document).on('dragstart', '.ep-document-card', function (e) {
                draggedDocId = $(this).attr('id').replace('doc-', '');
                $(this).addClass('dragging');
                e.originalEvent.dataTransfer.setData('text/plain', draggedDocId);
            });

            $(document).on('dragend', '.ep-document-card', function () {
                $(this).removeClass('dragging');
            });

            $(document).on('dragover', '.ep-folder-card', function (e) {
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'move';
                $(this).addClass('drag-over');
            });

            $(document).on('dragleave', '.ep-folder-card', function () {
                $(this).removeClass('drag-over');
            });

            $(document).on('drop', '.ep-folder-card', function (e) {
                e.preventDefault();
                $(this).removeClass('drag-over');
                const categoryId = $(this).data('id');
                const docId = draggedDocId;

                if (docId && categoryId) {
                    const tabId = $(this).closest('.ep-documents-grid').data('tab');
                    const type = tabId === 'public-resources' ? 'global' : 'personal';
                    updateDocumentCategory(docId, categoryId, type, tabId);
                }
            });

            // Lógica de Privacidad y Filtrado de Categorías en Subida
            $('input[name="upload_privacy"]').on('change', function () {
                const val = $(this).val();
                const $categorySelect = $('#upload_category');
                const currentUserId = ep_vars.user_id || 0;

                if (val === 'public') {
                    $('#epEmployeeSearchWrapper').hide();
                    $categorySelect.find('option').each(function () {
                        const owner = $(this).data('owner');
                        $(this).toggle(owner === 0 || $(this).val() == 0);
                    });
                } else {
                    $('#epEmployeeSearchWrapper').show();
                    $categorySelect.find('option').each(function () {
                        const owner = $(this).data('owner');
                        $(this).toggle(owner == currentUserId || $(this).val() == 0);
                    });
                }
                $categorySelect.val('0'); // Reset a sin carpeta
            });

            // Trigger inicial
            $('input[name="upload_privacy"]:checked').trigger('change');

            function updateDocumentCategory(docId, categoryId, type, tabId) {
                const $card = $(`#doc-${docId}`);

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_update_document_category',
                        security: ep_vars.nonce,
                        document_id: docId,
                        category_id: categoryId,
                        type: type // 'global' o 'personal'
                    },
                    beforeSend: function () {
                        $card.css('opacity', '0.5');
                    },
                    success: function (res) {
                        $('#epMoveDocModal').fadeOut(200);
                        if (res.success) {
                            fetchLiveFolderContents(tabId, currentFolder[tabId]);
                        } else {
                            alert('Error: ' + res.data);
                            $card.css('opacity', '1');
                        }
                    },
                    error: function () {
                        $('#epMoveDocModal').fadeOut(200);
                        alert('Error de conexión al mover el archivo.');
                        $card.css('opacity', '1');
                    }
                });
            }


            // --- Fin Lógica de Carpetas ---

            // Añadir Categoría / Carpeta
            $(document).on('click', '.ep-create-folder-btn, #epAddCategoryBtn', function () {
                const $btn = $(this);
                const explicitType = $btn.data('type');
                const tabId = $btn.closest('.ep-tab-content').attr('id') || $btn.closest('.ep-documents-grid').data('tab') || 'public-resources';

                const name = prompt('Nombre de la nueva carpeta:');
                if (name && name.trim()) {
                    $btn.prop('disabled', true);
                    const originalIcon = $btn.find('i').attr('class');
                    $btn.find('i').attr('class', 'fa-solid fa-spinner fa-spin');

                    let type = explicitType;
                    if (!type) {
                        const privacy = $('input[name="upload_privacy"]:checked').val();
                        type = (privacy === 'public') ? 'global' : 'personal';
                    }

                    const currentTabId = type === 'global' ? 'public-resources' : 'private-management';
                    const parentId = currentFolder[currentTabId] || '0';

                    $.ajax({
                        url: ep_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ep_create_category',
                            security: ep_vars.nonce,
                            name: name,
                            type: type,
                            parent_folder_id: parentId
                        },
                        success: function (res) {
                            $btn.prop('disabled', false).find('i').attr('class', originalIcon);
                            if (res.success) {
                                fetchLiveFolderContents(currentTabId, parentId);
                            } else {
                                alert('Error: ' + res.data);
                            }
                        },
                        error: function () {
                            $btn.prop('disabled', false).find('i').attr('class', originalIcon);
                            alert('Error de conexión.');
                        }
                    });
                }
            });

            // --- Backup a OneDrive ---
            $(document).on('click', '.ep-backup-onedrive', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const docId = $(this).data('id');
                const $btn = $(this);
                const originalHtml = $btn.html();
                const tabId = $btn.closest('.ep-documents-grid').data('tab') || 'private-management';

                Swal.fire({
                    title: '¿Respaldar en OneDrive?',
                    text: 'Esto hará una copia del archivo en tu carpeta personal de OneDrive.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: 'var(--ep-primary)',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Sí, respaldar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i>');

                        $.ajax({
                            url: ep_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'ep_backup_to_onedrive',
                                security: ep_vars.nonce,
                                document_id: docId
                            },
                            success: function (res) {
                                if (res.success) {
                                    Swal.fire('¡Éxito!', 'Archivo respaldado en OneDrive.', 'success');
                                    fetchLiveFolderContents(tabId, currentFolder[tabId]);
                                } else {
                                    Swal.fire('Error', res.data || 'Error al respaldar el archivo.', 'error');
                                    $btn.prop('disabled', false).html(originalHtml);
                                }
                            },
                            error: function () {
                                Swal.fire('Error', 'Error de conexión con el servidor.', 'error');
                                $btn.prop('disabled', false).html(originalHtml);
                            }
                        });
                    }
                });
            });


            // --- Document Preview Logic ---
            $(document).on('click', '.ep-document-card', function (e) {
                // Prevent opening if clicking on actions, menu or checkbox
                if ($(e.target).closest('.ep-actions-menu, .ep-doc-actions-menu, .ep-actions, .ep-document-actions, input[type="checkbox"]').length) {
                    return;
                }

                e.preventDefault();
                const docId = $(this).data('id');
                const fileName = $(this).find('.ep-doc-title').text().trim();
                const $modal = $('#epDocViewerModal');
                const $iframe = $('#epViewerIframe');
                const $loader = $('#epViewerLoader');
                const $downloadBtn = $('#epViewerDownload');

                $modal.fadeIn(200);
                $('#epViewerFileName').text(fileName);
                $loader.show();
                $iframe.hide().attr('src', '');

                // Get Preview URL via AJAX
                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_get_document_preview_url',
                        security: ep_vars.nonce,
                        document_id: docId
                    },
                    success: function (res) {
                        if (res.success) {
                            $iframe.attr('src', res.data.url);
                            $downloadBtn.attr('href', res.data.url); // Or use download specific URL if needed

                            $iframe.on('load', function () {
                                $loader.hide();
                                $iframe.fadeIn();
                            });
                            // Fallback if load event doesn't fire (e.g. cached or weird iframe behavior)
                            setTimeout(function () { $loader.hide(); $iframe.fadeIn(); }, 2000);
                        } else {
                            alert(res.data);
                            $modal.fadeOut();
                        }
                    },
                    error: function () {
                        alert('Error al cargar la vista previa.');
                        $modal.fadeOut();
                    }
                });
            });

            // Borrar Categoría

            // --- Force Sync Logic ---

            $('#epForceSyncBtn').on('click', function (e) {
                e.preventDefault();
                const $btn = $(this);

                if (!confirm('¿Estás seguro de forzar la sincronización bidireccional global con OneDrive ahora? Esto puede tardar unos momentos.')) {
                    return;
                }

                $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Sincronizando...');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_force_sync_onedrive',
                        security: ep_vars.nonce
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Sincronizar');
                        if (res.success) {
                            let msg = 'Sincronización completada.\n\n';
                            msg += 'Archivos sincronizados: ' + (res.data.stats ? res.data.stats.synced : 0) + '\n';
                            msg += 'Ignorados/Sin cambios: ' + (res.data.stats ? res.data.stats.skipped : 0) + '\n';
                            if (res.data.stats && res.data.stats.errors && res.data.stats.errors.length > 0) {
                                msg += 'Errores: ' + res.data.stats.errors.length;
                            }
                            alert(msg);

                            // Recargar vista actual
                            const activeTab = $('.ep-drive-nav-btn.active').data('tab');
                            fetchLiveFolderContents(activeTab, currentFolder[activeTab]);
                        } else {
                            alert('Error durante la sincronización: ' + (res.data || 'Error desconocido'));
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).html('<i class="fa-solid fa-rotate"></i> Sincronizar');
                        alert('Error de conexión al intentar sincronizar.');
                    }
                });
            });

            $(document).on('click', '.ep-delete-category-btn', function (e) {
                e.preventDefault();
                e.stopPropagation(); // No abrir la carpeta
                e.stopImmediatePropagation();
                const catId = $(this).data('id');
                const catName = $(this).closest('.ep-folder-card').data('name');
                const tabId = $(this).closest('.ep-documents-grid').data('tab');
                const type = tabId === 'public-resources' ? 'global' : 'personal';

                if (confirm(`¿Estás seguro de que quieres borrar la carpeta "${catName}"? Los documentos de su interior no se borrarán de OneDrive, pero podrían dejar de aparecer en esta organización.`)) {
                    const $btn = $(this);
                    $btn.prop('disabled', true).find('i').attr('class', 'fa-solid fa-spinner fa-spin');

                    $.ajax({
                        url: ep_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'ep_delete_category',
                            security: ep_vars.nonce,
                            folder_id: catId,
                            type: type
                        },
                        success: function (res) {
                            if (res.success) {
                                fetchLiveFolderContents(tabId, currentFolder[tabId]);
                            } else {
                                $btn.prop('disabled', false).find('i').attr('class', 'fa-solid fa-trash-can');
                                alert('Error: ' + res.data);
                            }
                        },
                        error: function () {
                            $btn.prop('disabled', false).find('i').attr('class', 'fa-solid fa-trash-can');
                            alert('Error de conexión.');
                        }
                    });
                }
            });

            // Inicializar al cargar
            setTimeout(initFolders, 200);



            // --- Lógica de Descarga Fresca ---
            $(document).on('click', '.ep-download-doc', function (e) {
                e.preventDefault();
                const $btn = $(this);
                const itemId = $btn.data('id');
                const directUrl = $btn.data('url');
                const $grid = $btn.closest('.ep-documents-grid');
                const tabId = $grid.data('tab');
                const type = (tabId === 'public-resources') ? 'public' : 'private';

                // Si tenemos URL directa y es válida (no vacía ni placeholder)
                if (directUrl && directUrl.startsWith('http')) {
                    window.open(directUrl, '_blank');
                    return;
                }

                // Si no hay URL o ha fallado, pedimos una fresca
                $btn.addClass('loading').prop('disabled', true);
                const $icon = $btn.find('i');
                const originalClass = $icon.attr('class');
                $icon.attr('class', 'fa-solid fa-spinner fa-spin');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_get_onedrive_download_url',
                        security: ep_vars.nonce,
                        item_id: itemId,
                        type: type
                    },
                    success: function (res) {
                        if (res.success && res.data.url) {
                            window.open(res.data.url, '_blank');
                        } else {
                            Swal.fire({
                                title: 'Error de Descarga',
                                text: res.data || 'No se pudo generar el enlace de descarga.',
                                icon: 'error'
                            });
                        }
                    },
                    error: function () {
                        Swal.fire('Error', 'Error de conexión con el servidor.', 'error');
                    },
                    complete: function () {
                        $btn.removeClass('loading').prop('disabled', false);
                        $icon.attr('class', originalClass);
                    }
                });
            });

            // Handler Vista Previa
            $(document).on('click', '.ep-view-doc-live', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const docId = $(this).data('id');
                const $card = $(this).closest('.ep-document-card');
                const docName = $card.find('.ep-doc-title').text() || 'Documento';

                // Cerrar dropdown
                $('.ep-actions-dropdown').removeClass('active');

                // Mostrar modal con loader
                $('#epViewerTitle').text(docName);
                $('#epViewerIframe').attr('src', '').hide();
                $('#epViewerLoader').show();
                $('#epDocViewerModal').fadeIn(300);

                // Pedir URL de preview al backend
                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_get_document_preview_url',
                        security: ep_vars.nonce,
                        document_id: docId
                    },
                    success: function (res) {
                        if (res.success && res.data.url) {
                            $('#epViewerIframe').attr('src', res.data.url).show();
                            $('#epViewerLoader').hide();
                            // Sincronizar botón de descarga de la modal
                            $('#epViewerDownloadTop').attr('href', res.data.url);
                        } else {
                            $('#epViewerLoader').html('<i class="fa-solid fa-exclamation-triangle" style="color:#ef4444;"></i><p>' + (res.data || 'No se pudo cargar la vista previa.') + '</p>');
                        }
                    },
                    error: function () {
                        $('#epViewerLoader').html('<i class="fa-solid fa-exclamation-triangle" style="color:#ef4444;"></i><p>Error de conexión al cargar la vista previa.</p>');
                    }
                });
            });

            $(document).on('click', '.ep-close-viewer', function () {
                $('#epDocViewerModal').fadeOut(300, function () {
                    $('#epViewerIframe').attr('src', '');
                    $('#epViewerLoader').html('<i class="fa-solid fa-spinner fa-spin"></i><p>Cargando vista previa...</p>').show();
                });
            });

            // Close on ESC
            $(document).keyup(function (e) {
                if (e.key === "Escape") {
                    $('.ep-viewer-modal').fadeOut(300, function () {
                        $('#epViewerIframe').attr('src', '');
                    });
                }
            });


            // --- Gestión de Menú de Acciones (Dropdown) ---
            $(document).on('click', '.ep-actions-trigger', function (e) {
                e.stopPropagation();
                const $dropdown = $(this).next('.ep-actions-dropdown');
                const $card = $(this).closest('.ep-document-card');
                $('.ep-actions-dropdown').not($dropdown).removeClass('active');
                $('.ep-document-card').not($card).removeClass('ep-menu-open');
                $dropdown.toggleClass('active');
                $card.toggleClass('ep-menu-open', $dropdown.hasClass('active'));
            });

            $(document).on('click', function () {
                $('.ep-actions-dropdown').removeClass('active');
                $('.ep-document-card').removeClass('ep-menu-open');
            });

            // --- Handler Revisar OK/Feedback ---
            $(document).on('click', '.ep-review-doc', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const docId = $(this).data('id');
                const $card = $(this).closest('.ep-document-card');
                const docName = $card.find('.ep-doc-title').text() || 'Documento';
                $('.ep-actions-dropdown').removeClass('active');

                Swal.fire({
                    title: '<i class="fa-solid fa-clipboard-check" style="color:var(--ep-primary);margin-right:8px;"></i> Revisar documento',
                    html: `<p style="margin-bottom:15px;color:#64748b;">Selecciona una acción para <strong>${docName}</strong>:</p>`,
                    showDenyButton: true,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-check"></i> Marcar como OKI',
                    denyButtonText: '<i class="fa-solid fa-comment-dots"></i> Dar Feedback',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#16a34a',
                    denyButtonColor: '#f59e0b',
                    customClass: {
                        popup: 'ep-swal-premium',
                        title: 'ep-swal-title',
                        htmlContainer: 'ep-swal-html'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Marcar OKI
                        $.ajax({
                            url: ep_vars.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'ep_update_review_status',
                                security: ep_vars.nonce,
                                document_id: docId,
                                status: 'ok'
                            },
                            success: function (res) {
                                if (res.success) {
                                    Swal.fire({ title: '¡Documento revisado!', text: 'Se ha marcado como OKI correctamente.', icon: 'success', confirmButtonColor: 'var(--ep-primary)' });
                                    const tabId = $card.closest('.ep-documents-grid').data('tab');
                                    fetchLiveFolderContents(tabId, currentFolder[tabId]);
                                } else {
                                    Swal.fire('Error', res.data || 'No se pudo marcar como OKI.', 'error');
                                }
                            },
                            error: function () {
                                Swal.fire('Error', 'Error de conexión.', 'error');
                            }
                        });
                    } else if (result.isDenied) {
                        // Abrir modal de feedback (esperamos a que Swal cierre completamente)
                        setTimeout(function () {
                            $('#epFeedbackDocId').val(docId);
                            $('#epFeedbackText').val('').focus();
                            $('#epFeedbackModal').hide().css('display', 'flex').hide().fadeIn(250);
                        }, 500);
                    }
                });
            });

            // --- Handler Feedback Modal ---
            $('#epCancelFeedback').on('click', function () {
                $('#epFeedbackModal').fadeOut(200);
            });

            $('#epSaveFeedback').on('click', function () {
                const docId = $('#epFeedbackDocId').val();
                const text = $('#epFeedbackText').val().trim();
                if (!text) {
                    Swal.fire('Atención', 'Escribe un comentario antes de enviar.', 'warning');
                    return;
                }
                const $btn = $(this);
                $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Enviando...');

                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_update_review_status',
                        security: ep_vars.nonce,
                        document_id: docId,
                        status: 'feedback',
                        feedback: text
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).html('Enviar Feedback');
                        $('#epFeedbackModal').fadeOut(200);
                        if (res.success) {
                            Swal.fire({ title: '¡Feedback enviado!', text: 'Se ha registrado tu comentario.', icon: 'success', confirmButtonColor: 'var(--ep-primary)' });
                        } else {
                            Swal.fire('Error', res.data || 'No se pudo enviar el feedback.', 'error');
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).html('Enviar Feedback');
                        Swal.fire('Error', 'Error de conexión.', 'error');
                    }
                });
            });

            // --- Handler Mover Documento ---
            $(document).on('click', '.ep-move-doc', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const docId = $(this).data('id');
                const $card = $(this).closest('.ep-document-card');
                const tabId = $card.closest('.ep-documents-grid').data('tab');
                const type = tabId === 'public-resources' ? 'global' : 'personal';
                $('.ep-actions-dropdown').removeClass('active');

                // Populate categories
                const $select = $('#move_doc_category');
                $select.html('<option value="0">Raíz (sin carpeta)</option>');
                $('#move_doc_id').val(docId);
                $('#move_doc_tab').val(tabId);

                // Load categories dynamically
                $.ajax({
                    url: ep_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_get_live_folder_contents',
                        security: ep_vars.nonce,
                        type: type,
                        folder_id: '0'
                    },
                    success: function (res) {
                        if (res.success && res.data.items) {
                            res.data.items.forEach(function (item) {
                                if (item.type === 'folder') {
                                    $select.append(`<option value="${item.id}">${item.name}</option>`);
                                }
                            });
                        }
                        $('#epSaveMove').prop('disabled', false).html('<i class="fa-solid fa-folder-open"></i> Mover aquí');
                    },
                    error: function () {
                        $('#epSaveMove').prop('disabled', false).html('Mover');
                    }
                });

                $('#epMoveDocModal').css('display', 'flex').hide().fadeIn(200);
            });

            // Cancelar mover
            $('#epCancelMove').on('click', function () {
                $('#epMoveDocModal').fadeOut(200);
            });

            // Guardar mover
            $('#epSaveMove').on('click', function () {
                const docId = $('#move_doc_id').val();
                const catId = $('#move_doc_category').val();
                const tabId = $('#move_doc_tab').val();
                const type = tabId === 'public-resources' ? 'global' : 'personal';

                $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Moviendo...');
                updateDocumentCategory(docId, catId, type, tabId);
            });

            // Handler Send to User (Nuevo)
            $(document).on('click', '.ep-send-to-user', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const docId = $(this).data('id');
                const $btn = $(this);

                // Generar opciones de empleados (pueden venir de ep_vars o una búsqueda rápida)
                let userOptions = {};
                <?php
                $users = get_users(array('fields' => array('ID', 'display_name')));
                foreach ($users as $user) {
                    echo "userOptions['{$user->ID}'] = '" . addslashes($user->display_name) . "';\n";
                }
                ?>

                Swal.fire({
                    title: '<i class="fa-solid fa-paper-plane" style="color:var(--ep-primary,#4f46e5);margin-right:8px;"></i> Enviar documento',
                    html: '<p style="color:#64748b;margin-bottom:12px;font-size:0.95rem;">Selecciona el empleado destinatario:</p>',
                    input: 'select',
                    inputOptions: userOptions,
                    inputPlaceholder: 'Selecciona un empleado...',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-paper-plane"></i> Enviar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: 'var(--ep-primary, #4f46e5)',
                    cancelButtonColor: '#94a3b8',
                    customClass: {
                        popup: 'ep-swal-premium',
                        title: 'ep-swal-title',
                        htmlContainer: 'ep-swal-html',
                        input: 'ep-swal-select'
                    },
                    inputValidator: (value) => {
                        return new Promise((resolve) => {
                            if (value) {
                                resolve();
                            } else {
                                resolve('Debes seleccionar un usuario');
                            }
                        });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        $btn.prop('disabled', true);
                        $.ajax({
                            url: ep_vars.ajax_url,
                            method: 'POST',
                            data: {
                                action: 'ep_send_document_to_user',
                                document_id: docId,
                                target_user_id: result.value,
                                security: ep_vars.nonce
                            },
                            success: function (res) {
                                if (res.success) {
                                    Swal.fire('Enviado', res.data, 'success');
                                    fetchLiveFolderContents('private-management');
                                } else {
                                    Swal.fire('Error', res.data, 'error');
                                    $btn.prop('disabled', false);
                                }
                            }
                        });
                    }
                });
            });

            // Handler Enviar a Firma (Nuevo)
            $(document).on('click', '.ep-send-to-signature', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const docId = $(this).data('id');
                const $btn = $(this);

                // Reutilizamos userOptions del bloque anterior
                let userOptions = {};
                <?php
                $users = get_users(array('fields' => array('ID', 'display_name')));
                foreach ($users as $user) {
                    echo "userOptions['{$user->ID}'] = '" . addslashes($user->display_name) . "';\n";
                }
                ?>

                Swal.fire({
                    title: '<i class="fa-solid fa-signature" style="color:var(--ep-primary,#4f46e5);margin-right:8px;"></i> Enviar a firma',
                    html: '<p style="color:#64748b;margin-bottom:12px;font-size:0.95rem;">Selecciona el empleado que debe firmar el documento:</p>',
                    input: 'select',
                    inputOptions: userOptions,
                    inputPlaceholder: 'Selecciona un firmante...',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-paper-plane"></i> Enviar al buzón',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: 'var(--ep-primary, #4f46e5)',
                    cancelButtonColor: '#94a3b8',
                    customClass: {
                        popup: 'ep-swal-premium',
                        title: 'ep-swal-title',
                        htmlContainer: 'ep-swal-html',
                        input: 'ep-swal-select'
                    },
                    inputValidator: (value) => {
                        return new Promise((resolve) => {
                            if (value) {
                                resolve();
                            } else {
                                resolve('Debes seleccionar un usuario');
                            }
                        });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        $btn.prop('disabled', true);
                        $.ajax({
                            url: ep_vars.ajax_url,
                            method: 'POST',
                            data: {
                                action: 'ep_send_to_signature', // Acción backend corregida
                                post_id: docId,
                                signer_id: result.value,
                                security: ep_vars.nonce
                            },
                            success: function (res) {
                                if (res.success) {
                                    Swal.fire('¡Enviado!', res.data, 'success');
                                    const activeTab = $('.ep-drive-nav-btn.active').data('tab');
                                    fetchLiveFolderContents(activeTab, currentFolder[activeTab]);
                                } else {
                                    Swal.fire('Error', res.data, 'error');
                                    $btn.prop('disabled', false);
                                }
                            },
                            error: function () {
                                Swal.fire('Error', 'Error de conexión con el servidor.', 'error');
                                $btn.prop('disabled', false);
                            }
                        });
                    }
                });
            });

        });
    </script>

    <style>
        .ep-tab-content {
            display: none;
            opacity: 0;
        }

        .ep-tab-content.active {
            display: block !important;
            opacity: 1 !important;
            animation: epFadeIn 0.4s ease-out forwards;
        }

        @keyframes epFadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Visor de Documentos OneDrive */
        .ep-viewer-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(8px);
            z-index: 2000000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .ep-viewer-content {
            background: #fff;
            width: 100%;
            max-width: 1200px;
            height: 85vh;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .ep-viewer-header {
            padding: 16px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .ep-viewer-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ep-viewer-title i {
            color: var(--ep-primary);
            font-size: 1.2rem;
        }

        .ep-viewer-title h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #1e293b;
            font-weight: 600;
        }

        .ep-viewer-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ep-close-viewer {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: #64748b;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }

        .ep-close-viewer:hover {
            color: #ef4444;
        }

        .ep-viewer-body {
            flex: 1;
            position: relative;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ep-viewer-body iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .ep-viewer-loader {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            color: #64748b;
        }

        .ep-viewer-loader i {
            font-size: 2.5rem;
            color: var(--ep-primary);
        }

        .ep-viewer-loader p {
            margin: 0;
            font-weight: 500;
        }

        .ep-help-tip {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 0.9rem;
            color: #64748b;
            margin: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            width: 100%;
            max-width: fit-content;
        }

        .ep-help-tip i {
            color: #1a73e8;
            font-size: 1.1rem;
        }

        /* Estilos Premium - Recursos y Gestión */
        .ep-app-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            font-family: var(--ep-font-sans);
        }

        /* Pestañas Modernas */
        .ep-tabs {
            display: flex;
            gap: 15px;
            margin-bottom: 35px;
            background: rgba(var(--ep-primary-rgb), 0.03);
            padding: 8px;
            border-radius: 12px;
            border: 1px solid var(--ep-border);
        }

        .ep-tab-btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            background: transparent;
            color: var(--ep-text-muted);
            font-weight: 600;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.95rem;
        }

        .ep-tab-btn i {
            font-size: 1.1rem;
            transition: transform 0.3s;
        }

        .ep-tab-btn:hover {
            color: var(--ep-primary);
            background: var(--ep-surface);
            box-shadow: var(--ep-shadow-sm);
        }

        .ep-tab-btn.active {
            color: white;
            background: var(--ep-primary);
            box-shadow: 0 4px 15px rgba(var(--ep-primary-rgb), 0.3);
        }

        .ep-tab-btn.active i {
            transform: scale(1.1);
        }

        /* Zona de Carga Premium */
        .ep-upload-card {
            background: var(--ep-surface);
            border-radius: 20px;
            border: 2px dashed var(--ep-border);
            padding: 40px;
            margin-bottom: 40px;
            transition: all 0.3s;
            position: relative;
            box-shadow: var(--ep-shadow-sm);
        }

        .ep-upload-card.highlight {
            border-color: var(--ep-primary);
            background: rgba(var(--ep-primary-rgb), 0.02);
            transform: translateY(-2px);
        }

        .ep-upload-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .ep-upload-icon-main {
            width: 64px;
            height: 64px;
            background: rgba(var(--ep-primary-rgb), 0.1);
            color: var(--ep-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 15px;
        }

        .ep-badge-category {
            background: rgba(var(--ep-primary-rgb), 0.1);
            color: var(--ep-primary);
            border: 1px solid rgba(var(--ep-primary-rgb), 0.2);
        }

        .ep-badge-success {
            background: rgba(72, 187, 120, 0.1);
            color: #2f855a;
            border: 1px solid rgba(72, 187, 120, 0.2);
        }

        .ep-badge-warning {
            background: rgba(237, 137, 54, 0.1);
            color: #c05621;
            border: 1px solid rgba(237, 137, 54, 0.2);
        }

        .ep-badge-info {
            background: rgba(66, 153, 225, 0.1);
            color: #2b6cb0;
            border: 1px solid rgba(66, 153, 225, 0.2);
        }

        /* Modal de Firma */
        .ep-signature-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            /* Centrado vertical */
            justify-content: center;
            /* Centrado horizontal */
            z-index: 2000000;
            padding: 20px;
        }

        /* Forzar flexbox cuando jQuery muestra el modal */
        .ep-signature-modal[style*="display: block"],
        .ep-signature-modal[style*="display: flex"] {
            display: flex !important;
        }

        .ep-signature-content {
            background: white;
            width: 100%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            border-radius: 12px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            position: relative;
            animation: epModalSlideUp 0.3s ease-out;
        }

        @keyframes epModalSlideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .ep-signature-header {
            background: #f8f9fa;
            padding: 20px 25px;
            border-bottom: 1px solid var(--ep-border);
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            /* Importante para posicionar la X */
        }

        .ep-signature-header i {
            color: var(--ep-primary);
            font-size: 20px;
        }

        .ep-signature-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--ep-text);
            flex: 1;
        }

        /* Localización exacta de la X en la esquina superior */
        .ep-signature-header .ep-close-modal {
            position: absolute;
            right: 15px;
            top: 15px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--ep-text-muted);
            line-height: 1;
            padding: 5px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }

        .ep-signature-header .ep-close-modal:hover {
            background: var(--ep-surface-hover);
            color: var(--ep-primary);
        }

        .ep-close-modal:hover {
            color: var(--ep-primary);
        }

        .ep-signature-body {
            padding: 25px;
        }

        .ep-signature-body p {
            margin-top: 0;
            margin-bottom: 15px;
            color: var(--ep-text-muted);
            font-size: 14px;
        }

        /* Botones y Acciones en Modales */
        .ep-btn-text-icon {
            background: none;
            border: none;
            color: var(--ep-text-muted);
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        .ep-btn-text-icon:hover {
            background: rgba(var(--ep-primary-rgb), 0.1);
            color: var(--ep-primary);
        }

        .ep-confirm-footer {
            padding: 20px 25px;
            background: var(--ep-surface-hover);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid var(--ep-border);
        }

        .ep-btn-primary,
        .ep-btn-confirm {
            background: var(--ep-primary);
            color: white;
            border: none;
            padding: 12px 32px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(var(--ep-primary-rgb), 0.2);
        }

        .ep-btn-primary:hover,
        .ep-btn-confirm:hover {
            background: var(--ep-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 15px rgba(var(--ep-primary-rgb), 0.3);
        }

        .ep-btn-secondary {
            background: white;
            color: var(--ep-text);
            border: 1px solid var(--ep-border);
            padding: 12px 32px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .ep-btn-secondary:hover {
            background: var(--ep-surface-hover);
            border-color: var(--ep-text-muted);
        }

        /* Employee selection styles */
        .ep-selected-employee {
            background: #eef6ff;
            border: 1px solid #bae0ff;
            padding: 12px 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #1677ff;
        }

        .ep-selected-employee strong {
            margin-left: 5px;
        }

        #epClearSigner {
            background: none;
            border: none;
            color: #ff4d4f;
            cursor: pointer;
            font-size: 18px;
            padding: 0;
            display: flex;
            align-items: center;
        }

        #epClearSigner:hover {
            opacity: 0.8;
        }

        .ep-document-feedback {
            margin-top: 10px;
            padding: 10px;
            background: rgba(var(--ep-primary-rgb), 0.03);
            border-left: 3px solid var(--ep-primary);
            border-radius: 4px;
            font-size: 0.85rem;
            color: var(--ep-text-muted);
            font-style: italic;
        }

        .ep-document-actions .ep-btn-icon.ok:hover {
            color: #48bb78;
            background: rgba(72, 187, 120, 0.1);
        }

        .ep-document-actions .ep-btn-icon.feedback:hover {
            color: #ed8936;
            background: rgba(237, 137, 54, 0.1);
        }

        .ep-document-actions .ep-btn-icon.signature:hover {
            color: #4299e1;
            background: rgba(66, 153, 225, 0.1);
        }

        .ep-document-actions .ep-btn-icon.move:hover {
            color: var(--ep-primary);
            background: rgba(var(--ep-primary-rgb), 0.1);
        }

        .ep-document-actions .ep-btn-icon.ep-backup-onedrive:hover {
            color: #805ad5;
            background: rgba(128, 90, 213, 0.1);
        }

        /* Estilos Modal Feedback */
        .ep-feedback-quote {
            background: var(--ep-surface-hover);
            padding: 25px;
            border-radius: 12px;
            border-left: 5px solid var(--ep-warning);
            position: relative;
            margin: 20px 0;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .ep-feedback-quote i {
            font-size: 24px;
            color: var(--ep-warning);
            margin-bottom: 10px;
            display: block;
        }

        .ep-feedback-quote p {
            font-style: italic;
            color: var(--ep-text);
            font-size: 1.1em;
            line-height: 1.6;
            margin: 0;
        }

        .ep-badge {
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
        }

        .ep-badge:hover {
            transform: scale(1.05);
            opacity: 0.9;
        }

        .ep-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .ep-section-title h2 {
            margin: 0;
        }

        .ep-section-title p {
            margin: 5px 0 0 0;
            color: var(--ep-text-muted);
        }

        .ep-category-selector {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .ep-select {
            padding: 10px 15px;
            border: 2px solid var(--ep-border);
            border-radius: 10px;
            background: var(--ep-surface);
            color: var(--ep-text);
            outline: none;
            font-size: 0.9rem;
        }

        .ep-btn-icon-alt {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            border: 2px solid var(--ep-border);
            background: var(--ep-surface);
            color: var(--ep-text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .ep-btn-icon-alt:hover {
            border-color: var(--ep-primary);
            color: var(--ep-primary);
            background: rgba(var(--ep-primary-rgb), 0.05);
        }

        .ep-textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 2px solid var(--ep-border);
            border-radius: 12px;
            background: var(--ep-surface);
            color: var(--ep-text);
            margin-top: 10px;
            font-family: inherit;
            outline: none;
        }

        .ep-textarea:focus {
            border-color: var(--ep-primary);
        }

        .ep-upload-title-group h3 {
            margin: 0 0 5px 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--ep-text);
        }

        .ep-upload-title-group p {
            margin: 0;
            color: var(--ep-text-muted);
            font-size: 0.95rem;
        }

        .ep-upload-controls {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .ep-privacy-selector {
            display: flex;
            gap: 20px;
            justify-content: center;
        }

        .ep-radio-box {
            position: relative;
            cursor: pointer;
            display: block;
        }

        .ep-radio-box input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .ep-radio-label {
            padding: 10px 25px;
            border: 2px solid var(--ep-border);
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--ep-text-muted);
            transition: all 0.2s;
        }

        .ep-radio-box input:checked~.ep-radio-label {
            border-color: var(--ep-primary);
            background: rgba(var(--ep-primary-rgb), 0.05);
            color: var(--ep-primary);
        }

        /* Buscador de Empleados Reactivo */
        .ep-search-wrapper {
            position: relative;
            animation: epIn 0.3s ease;
        }

        .ep-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .ep-input-group i {
            position: absolute;
            left: 15px;
            color: var(--ep-text-muted);
        }

        .ep-input-group input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid var(--ep-border);
            border-radius: 12px;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
            background: var(--ep-surface);
            color: var(--ep-text);
        }

        .ep-input-group input:focus {
            border-color: var(--ep-primary);
            box-shadow: 0 0 0 3px rgba(var(--ep-primary-rgb), 0.1);
        }

        .ep-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--ep-surface);
            border-radius: 12px;
            box-shadow: var(--ep-shadow-lg);
            border: 1px solid var(--ep-border);
            margin-top: 5px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .ep-search-item {
            padding: 12px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--ep-border);
            transition: background 0.2s;
            color: var(--ep-text);
        }

        .ep-search-item:last-child {
            border: none;
        }

        .ep-search-item:hover {
            background: var(--ep-surface-hover);
        }

        .ep-selected-employee {
            background: rgba(var(--ep-primary-rgb), 0.05);
            border: 1px solid var(--ep-primary);
            padding: 10px 15px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--ep-primary);
            font-weight: 500;
            margin-top: 10px;
        }

        .ep-selected-employee button {
            background: none;
            border: none;
            color: #e53e3e;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0;
            display: flex;
        }

        /* Botón de Selección Sublimado */
        .ep-upload-actions {
            text-align: center;
        }

        .ep-btn-primary {
            background: var(--ep-primary);
            color: white;
            padding: 12px 24px !important;
            border-radius: 20px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(var(--ep-primary-rgb), 0.2);
            border: none;
            font-size: 0.95rem;
        }

        .ep-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(var(--ep-primary-rgb), 0.3);
            background: var(--ep-primary-hover);
        }

        .ep-upload-meta-info {
            text-align: center;
            font-size: 0.85rem;
            color: var(--ep-text-muted);
            margin: 10px 0 0 0;
        }

        /* Barra de Progreso Mejorada */
        .ep-upload-progress {
            margin-top: 30px;
        }

        .ep-progress-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--ep-text);
        }

        .ep-progress-bar {
            height: 8px;
            background: var(--ep-border);
            border-radius: 10px;
            overflow: hidden;
        }

        .ep-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--ep-primary), #ff4d4d);
            border-radius: 10px;
            transition: width 0.3s;
        }

        /* Cards de Documentos - Contenedor Principal Mejorado */
        .ep-documents-grid {
            display: block !important;
            /* Evita conflictos con sub-grids de carpetas y archivos */
            width: 100%;
        }

        .ep-document-card {
            background: var(--ep-surface);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid var(--ep-border);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
        }

        .ep-document-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--ep-shadow);
            border-color: var(--ep-primary);
        }

        .ep-document-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--ep-surface-hover);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .ep-document-icon .color-red {
            color: #e53e3e;
        }

        .ep-document-icon .color-blue {
            color: #3182ce;
        }

        .ep-document-icon .color-green {
            color: #38a169;
        }

        .ep-document-icon .color-purple {
            color: #805ad5;
        }

        .ep-document-info h3 {
            margin: 0 0 5px 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--ep-text);
        }

        .ep-document-meta {
            font-size: 0.8rem;
            color: var(--ep-text-muted);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .ep-badge {
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-transform: uppercase;
        }

        .ep-badge-private {
            background: #fee2e2;
            color: #991b1b;
        }

        .ep-badge-public {
            background: #dcfce7;
            color: #166534;
        }

        .ep-document-actions {
            margin-left: auto;
            display: flex;
            gap: 8px;
        }

        .ep-btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: var(--ep-surface-hover);
            color: var(--ep-text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .ep-btn-icon:hover {
            background: var(--ep-primary);
            color: white;
        }

        .ep-btn-icon-premium {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 1px solid var(--ep-border);
            background: var(--ep-surface);
            color: var(--ep-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1.1rem;
            box-shadow: var(--ep-shadow-sm);
        }

        .ep-btn-icon-premium:hover {
            background: var(--ep-primary);
            color: white;
            border-color: var(--ep-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(var(--ep-primary-rgb), 0.2);
        }

        /* --- Fin estilos legacy --- */

        /* Animaciones */
        @keyframes epIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes epFadeUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Segmentación de Carpetas */
        .ep-folder-card[data-owner="0"] .ep-folder-icon {
            background: rgba(0, 120, 215, 0.1);
            color: #0078d7;
            /* Azul Corporativo / Global */
        }

        .ep-folder-card:not([data-owner="0"]) .ep-folder-icon {
            background: rgba(232, 17, 35, 0.1);
            color: #e81123;
            /* Rojo / Personal */
        }

        .ep-toggle-option {
            flex: 1;
            cursor: pointer;
            position: relative;
        }

        .ep-toggle-option input {
            position: absolute;
            opacity: 0;
        }

        .ep-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--ep-text-muted);
            transition: all 0.2s;
        }

        .ep-toggle-option input:checked+.ep-toggle-btn {
            background: var(--ep-primary);
            color: white;
            box-shadow: 0 4px 12px rgba(var(--ep-primary-rgb), 0.2);
        }

        /* Input Group Premium */
        .ep-input-group-premium,
        .ep-search-container-premium {
            position: relative;
            background: var(--ep-border);
            border: 1px solid var(--ep-border);
            border-radius: 12px;
            padding: 2px 6px;
            display: flex;
            align-items: center;
            transition: all 0.2s;
            height: 48px;
        }

        .ep-input-group-premium:focus-within,
        .ep-search-container-premium:focus-within {
            border-color: var(--ep-primary);
            background: var(--ep-bg);
            box-shadow: 0 0 0 3px rgba(var(--ep-primary-rgb), 0.05);
        }

        .ep-select-premium,
        .ep-search-container-premium input {
            border: none !important;
            background: transparent !important;
            font-size: 0.9rem !important;
            color: var(--ep-text);
            padding: 8px !important;
            width: 100%;
            outline: none !important;
        }

        .ep-add-folder-btn {
            background: var(--ep-primary);
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .ep-add-folder-btn:hover {
            transform: rotate(90deg);
            background: var(--ep-primary-hover);
        }

        /* --- Rediseño Estilo Google Drive --- */
        :root {
            --ep-drive-shadow: 0 1px 2px 0 rgba(60, 64, 67, 0.302), 0 1px 3px 1px rgba(60, 64, 67, 0.149);
            --ep-drive-bg: #fff;
            --ep-drive-accent: #1a73e8;
            --ep-drive-hover: #f1f3f4;
        }

        .ep-drive-header {
            display: flex;
            flex-direction: column;
            padding: 10px 0;
            margin-bottom: 20px;
            gap: 15px;
            border-bottom: 1px solid var(--ep-border);
            padding-bottom: 20px;
        }

        .ep-drive-main-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* --- Reorganización de Cabecera (Doble Fila) --- */
        .ep-drive-header {
            display: flex !important;
            flex-direction: column !important;
            gap: 20px !important;
            padding: 15px 0 !important;
            border-bottom: 1px solid #e0e0e0 !important;
            margin-bottom: 25px !important;
        }

        .ep-drive-actions-row {
            display: flex !important;
            align-items: center !important;
            gap: 15px !important;
            width: 100% !important;
        }

        .ep-drive-actions-row .ep-drive-action-wrapper {
            width: 20% !important;
            display: flex;
            /* Quitamos !important para que jQuery pueda ocultarlo */
            justify-content: center !important;
        }

        .ep-drive-actions-row .ep-drive-search-bar {
            width: 60%;
            /* Quitamos !important para que JS pueda cambiarlo a 80% */
        }

        /* --- Botones de Acción Estilo Pill (IDÉNTICOS) --- */
        .ep-drive-btn-pill {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 12px !important;
            background: white !important;
            border: 1px solid #d1d5db !important;
            padding: 0 24px !important;
            border-radius: 40px !important;
            font-weight: 600 !important;
            font-size: 0.95rem !important;
            color: #374151 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            cursor: pointer !important;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            width: 100% !important;
            height: 50px !important;
            /* Altura generosa y fija */
            outline: none !important;
            white-space: nowrap !important;
        }

        .ep-drive-btn-pill:hover {
            background-color: #fff5f5 !important;
            color: #a81c24 !important;
            border-color: #a81c24 !important;
            box-shadow: 0 6px 15px rgba(168, 28, 36, 0.15) !important;
            transform: translateY(-1px) !important;
        }

        .ep-drive-btn-pill i,
        .ep-drive-btn-pill svg {
            font-size: 1.3rem !important;
            color: inherit !important;
            transition: all 0.3s ease !important;
            flex-shrink: 0 !important;
        }

        .ep-btn-sync-premium:hover i {
            transform: rotate(180deg) !important;
        }

        /* Menú Nuevo - Estilo Premium */
        .ep-new-dropdown {
            position: relative;
        }

        .ep-new-menu {
            position: absolute;
            top: calc(100% + 12px);
            left: 0;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(0, 0, 0, 0.05);
            width: 240px;
            display: none;
            flex-direction: column;
            padding: 10px;
            z-index: 1000;
            border: none;
            overflow: hidden;
            animation: epMenuAppear 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes epMenuAppear {
            from {
                opacity: 0;
                transform: translateY(-10px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .ep-menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: #3c4043;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            border-radius: 10px;
            transition: all 0.2s ease;
            margin-bottom: 2px;
        }

        .ep-menu-item:last-child {
            margin-bottom: 0;
        }

        .ep-menu-item:hover {
            background-color: #fff5f5 !important;
            color: #a81c24 !important;
        }

        .ep-menu-item i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
            color: #5f6368;
            transition: color 0.2s ease;
        }

        .ep-menu-item:hover i {
            color: #a81c24 !important;
        }

        /* Separador sutil opcional */
        .ep-menu-divider {
            height: 1px;
            background: #f1f3f4;
            margin: 6px 12px;
        }

        /* --- Navegación de Pestañas (Cabecera) --- */
        .ep-drive-nav {
            display: flex !important;
            background: #f1f3f4 !important;
            padding: 6px !important;
            border-radius: 40px !important;
            gap: 8px !important;
            border: 2px solid #e0e0e0 !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05) !important;
            width: 100% !important;
        }

        .ep-drive-nav-btn {
            flex: 1 !important;
            padding: 12px 20px !important;
            border-radius: 32px !important;
            border: none !important;
            background: transparent !important;
            color: #5f6368 !important;
            font-size: 0.95rem !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 12px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        .ep-drive-nav-btn.active {
            background: #a81c24 !important;
            color: #ffffff !important;
            box-shadow: 0 8px 20px rgba(168, 28, 36, 0.25) !important;
        }

        .ep-drive-nav-btn i {
            font-size: 1.2rem !important;
        }

        .ep-drive-nav-btn:hover:not(.active) {
            background: rgba(0, 0, 0, 0.04) !important;
        }

        /* El botón Sincronizar ahora hereda todo de .ep-drive-btn-pill */
        .ep-btn-sync-premium i {
            transition: all 0.5s ease !important;
        }

        .ep-btn-sync-premium:hover i {
            transform: rotate(180deg) !important;
        }

        /* Buscador Moderno */
        .ep-drive-search-bar {
            flex: 1;
        }

        .ep-search-wrapper-modern {
            background: #f1f3f4;
            border-radius: 8px;
            padding: 0 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            height: 48px;
            transition: background 0.2s, box-shadow 0.2s;
        }

        .ep-search-wrapper-modern:focus-within {
            background: white;
            box-shadow: 0 1px 1px 0 rgba(65, 69, 73, 0.3), 0 1px 3px 1px rgba(65, 69, 73, 0.15);
        }

        .ep-search-wrapper-modern input {
            border: none;
            background: transparent;
            width: 100%;
            height: 100%;
            outline: none;
            font-size: 1rem;
            color: #3c4043;
        }

        .ep-search-wrapper-modern i {
            color: #5f6368;
        }

        /* Overlay Drag & Drop Global */
        .ep-global-drop-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(var(--ep-primary-rgb), 0.1);
            backdrop-filter: blur(2px);
            z-index: 2000000;
            display: none;
            align-items: center;
            justify-content: center;
            border: 4px dashed var(--ep-primary);
            pointer-events: none;
        }

        .ep-drop-content {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 24px;
            box-shadow: var(--ep-shadow-lg);
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .ep-global-drop-overlay.active {
            display: flex;
        }

        .ep-global-drop-overlay.active .ep-drop-content {
            transform: scale(1);
        }

        .ep-drop-content i {
            font-size: 5rem;
            color: var(--ep-primary);
            margin-bottom: 20px;
            animation: float 2s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        /* Ocultar secciones redundantes */
        .ep-upload-card {
            display: none !important;
        }

        .ep-tabs {
            display: none !important;
        }

        /* Estética de Documentos Grid */
        .ep-tab-content {
            animation: epFadeIn 0.3s ease;
        }

        @keyframes epFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Botón de Subida Premium */
        .ep-upload-button-premium {
            background: var(--ep-text);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            width: 100%;
            height: 48px;
        }

        /* Botón de Subida Premium */
        .ep-btn-confirm {
            background: var(--ep-primary);
            color: white;
            padding: 12px 24px !important;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .ep-btn-secondary {
            background: var(--ep-surface-hover);
            color: var(--ep-text);
            padding: 12px 24px !important;
            border-radius: 12px;
            font-weight: 600;
            border: 1px solid var(--ep-border);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .ep-upload-button-premium:hover {
            background: #000;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .ep-upload-footer-info {
            margin-top: 25px;
            display: flex;
            gap: 20px;
            font-size: 0.8rem;
            color: var(--ep-text-muted);
            border-top: 1px solid var(--ep-border);
            padding-top: 15px;
        }

        .ep-upload-footer-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ep-search-dropdown {
            position: absolute;
            top: 105%;
            left: 0;
            right: 0;
            background: var(--ep-surface);
            border: 1px solid var(--ep-border);
            border-radius: 12px;
            z-index: 100;
            box-shadow: var(--ep-shadow-lg);
            display: none;
            max-height: 200px;
            overflow-y: auto;
        }

        .ep-selected-badge {
            position: absolute;
            inset: 4px;
            background: var(--ep-primary);
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            padding: 0 12px;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            z-index: 5;
        }

        .ep-selected-badge button {
            background: transparent;
            border: none;
            color: white;
            cursor: pointer;
            margin-left: auto;
            font-size: 1rem;
            opacity: 0.8;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Modal de Confirmación Premium */
        .ep-confirm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            /* Centrado vertical */
            justify-content: center;
            /* Centrado horizontal */
            z-index: 2000000;
            padding: 20px;
            animation: epIn 0.2s ease;
        }

        .ep-confirm-modal[style*="display: block"],
        .ep-confirm-modal[style*="display: flex"] {
            display: flex !important;
        }

        .ep-confirm-content {
            background: var(--ep-surface);
            width: 100%;
            max-width: 450px;
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--ep-shadow-lg);
            border: 1px solid var(--ep-border);
            animation: epFadeUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .ep-confirm-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .ep-confirm-header i {
            font-size: 3rem;
            color: var(--ep-primary);
            margin-bottom: 15px;
        }

        .ep-confirm-header h3 {
            margin: 0;
            font-size: 1.3rem;
            color: var(--ep-text);
            font-weight: 700;
        }

        .ep-delete-category-btn {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #a81c24 !important;
            /* Rojo corporativo forzado */
            color: #ffffff !important;
            border: 2px solid #ffffff !important;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex !important;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(168, 28, 36, 0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 1 !important;
            visibility: visible !important;
            z-index: 100;
        }

        .ep-folder-card:hover .ep-delete-category-btn {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 12px rgba(239, 68, 68, 0.3);
        }

        .ep-delete-category-btn:hover {
            background: #ff1a1a;
            transform: scale(1.1);
        }

        .ep-file-preview {
            background: var(--ep-bg);
            padding: 15px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--ep-border);
        }

        .ep-file-preview i {
            font-size: 2.5rem;
            color: var(--ep-primary);
        }

        .ep-file-details {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .ep-file-details .name {
            font-weight: 600;
            color: var(--ep-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.95rem;
        }

        .ep-file-details .size {
            font-size: 0.8rem;
            color: var(--ep-text-muted);
        }

        .ep-target-alert {
            background: rgba(var(--ep-primary-rgb), 0.03);
            padding: 15px;
            border-radius: 12px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 30px;
            border: 1px dashed var(--ep-primary);
        }

        .ep-target-alert i {
            color: var(--ep-primary);
            font-size: 1.2rem;
            margin-top: 2px;
        }

        .ep-target-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .ep-target-info label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--ep-text-muted);
            font-weight: 700;
        }

        .ep-target-info .target {
            font-weight: 600;
            color: var(--ep-text);
            font-size: 0.9rem;
        }

        .ep-confirm-footer {
            display: flex;
            gap: 12px;
            justify-content: stretch;
        }

        .ep-confirm-footer button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .ep-btn-secondary {
            background: var(--ep-bg);
            color: var(--ep-text);
            border: 1px solid var(--ep-border) !important;
        }

        .ep-btn-secondary:hover {
            background: var(--ep-surface-hover);
            border-color: var(--ep-text-muted) !important;
        }

        .ep-btn-confirm {
            background-color: var(--ep-primary, #9e1c2e) !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 4px 10px rgba(var(--ep-primary-rgb, 158, 28, 46), 0.2);
        }

        .ep-btn-confirm:hover {
            background-color: var(--ep-primary-hover, #7d1625) !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 15px rgba(var(--ep-primary-rgb, 158, 28, 46), 0.3);
            color: #ffffff !important;
        }

        /* Carpetas */
        .ep-folder-card {
            background: var(--ep-surface);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid var(--ep-border);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .ep-folder-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--ep-shadow);
            border-color: var(--ep-primary);
            background: rgba(var(--ep-primary-rgb), 0.02);
        }

        .ep-folder-card.drag-over {
            border: 2px dashed var(--ep-primary);
            background: rgba(var(--ep-primary-rgb), 0.1);
            transform: scale(1.02);
        }

        .ep-folder-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: rgba(var(--ep-primary-rgb), 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--ep-primary);
        }

        .ep-folder-info h3 {
            margin: 0 0 2px 0;
            font-size: 1rem;
            font-weight: 700;
            color: var(--ep-text);
        }

        .ep-folder-count {
            font-size: 0.8rem;
            color: var(--ep-text-muted);
        }

        /* Buscador */
        .ep-filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .ep-search-box {
            position: relative;
            display: flex;
            align-items: center;
            min-width: 250px;
        }

        .ep-search-box i {
            position: absolute;
            left: 15px;
            color: var(--ep-text-muted);
        }

        .ep-search-files {
            width: 100%;
            padding-left: 45px !important;
        }

        /* Layout grid con carpetas */
        .ep-folders-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
        }

        .ep-files-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            width: 100%;
            border-top: 1px solid var(--ep-border);
            padding-top: 30px;
        }


        .ep-back-to-folders {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: var(--ep-surface-hover);
            border: 1px solid var(--ep-border);
            border-radius: 10px;
            color: var(--ep-text);
            cursor: pointer;
            margin-bottom: 20px;
        }

        .ep-back-to-folders:hover {
            background: var(--ep-primary);
            color: white;
            border-color: var(--ep-primary);
        }

        /* Drag & Drop Ghost */
        .ep-document-card.dragging {
            opacity: 0.5;
            transform: scale(0.95);
        }

        /* --- NUEVOS ESTILOS TARJETAS DRIVE PREMIUM --- */
        .ep-document-card {
            background: var(--ep-surface);
            border-radius: 12px;
            border: 1px solid var(--ep-border);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 120px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: visible;
            z-index: 1;
        }

        .ep-document-card.ep-menu-open {
            z-index: 100;
        }

        .ep-document-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            border-color: var(--ep-primary);
            transform: translateY(-4px);
        }

        .ep-doc-actions-menu {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 50;
        }

        /* --- Trigger Button (Material Design 3 State Layer) --- */
        .ep-actions-trigger {
            background: rgba(255, 255, 255, 0.85);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            font-size: 1rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            position: relative;
            overflow: hidden;
        }

        .ep-actions-trigger::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: currentColor;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .ep-actions-trigger:hover {
            background: white;
            color: var(--ep-primary);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }

        .ep-actions-trigger:hover::before {
            opacity: 0.06;
        }

        .ep-actions-trigger:active::before {
            opacity: 0.12;
        }

        /* --- Dropdown (Material Design 3 Surface) --- */
        .ep-actions-dropdown {
            position: absolute;
            top: 42px;
            right: 0;
            background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
            border-radius: 12px;
            box-shadow:
                0 1px 3px rgba(0, 0, 0, 0.04),
                0 4px 12px rgba(0, 0, 0, 0.08),
                0 12px 32px rgba(0, 0, 0, 0.06);
            min-width: 220px;
            display: none;
            flex-direction: column;
            padding: 6px 0;
            z-index: 1001;
            transform-origin: top right;
            animation: epMenuReveal 0.2s cubic-bezier(0.2, 0, 0, 1);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }

        @keyframes epMenuReveal {
            from {
                transform: scale(0.95) translateY(-4px);
                opacity: 0;
            }

            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        .ep-actions-dropdown.active {
            display: flex;
        }

        /* --- Menu Groups & Dividers --- */
        .ep-menu-group {
            display: flex;
            flex-direction: column;
            padding: 2px 0;
        }

        .ep-menu-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent 8%, #e2e8f0 30%, #e2e8f0 70%, transparent 92%);
            margin: 2px 0;
        }

        /* --- Action Items (Material Design 3) --- */
        .ep-action-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 9px 16px;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            border-radius: 0;
            color: #1e293b;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            letter-spacing: 0.01em;
            position: relative;
            overflow: hidden;
            line-height: 1.3;
        }

        .ep-action-item::after {
            content: '';
            position: absolute;
            inset: 0;
            background: currentColor;
            opacity: 0;
            transition: opacity 0.15s;
            pointer-events: none;
        }

        .ep-action-item:hover {
            background: #f1f5f9;
            color: var(--ep-primary, #4f46e5);
        }

        .ep-action-item:hover::after {
            opacity: 0.02;
        }

        .ep-action-item:active {
            background: #e2e8f0;
        }

        .ep-action-item:active::after {
            opacity: 0.06;
        }

        /* --- Iconos con fondos circulares coloreados --- */
        .ep-action-item i {
            width: 32px;
            height: 32px;
            min-width: 32px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .ep-action-item span {
            flex: 1;
            white-space: nowrap;
        }

        /* Colores temáticos por acción - Material You tones */
        .ep-action-item.ep-view-doc-live i {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .ep-action-item.ep-download-doc i {
            color: #059669;
            background: #d1fae5;
        }

        .ep-action-item.ep-send-to-user i {
            color: #4f46e5;
            background: #e0e7ff;
        }

        .ep-action-item.ep-send-to-signature i {
            color: #8b5cf6;
            background: #f5f3ff;
        }

        .ep-action-item.ep-backup-onedrive i {
            color: #0078d4;
            background: #e1f3fd;
        }

        .ep-action-item.ep-review-doc i {
            color: #d97706;
            background: #fef3c7;
        }

        .ep-action-item.ep-move-doc i {
            color: #7c3aed;
            background: #ede9fe;
        }

        /* Hover elevates icon */
        .ep-action-item:hover i {
            transform: scale(1.08);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        /* --- Danger Action (Eliminar) --- */
        .ep-action-item.danger i {
            color: #dc2626;
            background: #fee2e2;
        }

        .ep-action-item.danger:hover {
            background: rgba(239, 68, 68, 0.06);
            color: #dc2626;
        }

        .ep-action-item.danger:hover i {
            color: #dc2626;
            background: #fecaca;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.15);
        }

        /* SweetAlert2 Premium Override */
        .ep-swal-premium {
            border-radius: 20px !important;
            padding: 2rem !important;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.15) !important;
        }

        .ep-swal-title {
            font-size: 1.3rem !important;
            font-weight: 700 !important;
            color: #1e293b !important;
        }

        .ep-swal-html {
            font-size: 0.95rem !important;
        }

        .ep-swal-select,
        .swal2-popup .swal2-select {
            width: 100% !important;
            padding: 12px 16px !important;
            border: 2px solid #e2e8f0 !important;
            border-radius: 12px !important;
            font-size: 0.95rem !important;
            font-weight: 500 !important;
            color: #1e293b !important;
            background: #f8fafc !important;
            transition: border-color 0.2s, box-shadow 0.2s !important;
            cursor: pointer !important;
            appearance: auto !important;
        }

        .ep-swal-select:focus,
        .swal2-popup .swal2-select:focus {
            border-color: var(--ep-primary, #4f46e5) !important;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1) !important;
            outline: none !important;
            background: white !important;
        }

        .swal2-popup .swal2-styled {
            border-radius: 10px !important;
            font-weight: 600 !important;
            padding: 10px 24px !important;
            font-size: 0.9rem !important;
        }

        /* Confirm Modal Premium Styling */
        .ep-confirm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(6px);
            z-index: 2000000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ep-confirm-content {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 460px;
            max-height: 85vh;
            overflow-y: auto;
            width: 90%;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
            animation: epMenuPop 0.3s ease;
            overflow: hidden;
        }

        .ep-confirm-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 24px 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .ep-confirm-header i {
            font-size: 1.4rem;
            color: var(--ep-primary);
            background: linear-gradient(135deg, #eff6ff, #e0f2fe);
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            flex-shrink: 0;
        }

        .ep-confirm-header h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .ep-confirm-body {
            padding: 20px 24px;
        }

        .ep-confirm-body p {
            color: #64748b;
            font-size: 0.95rem;
            margin: 0 0 14px;
        }

        .ep-confirm-body select,
        .ep-confirm-body textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #334155;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
            font-family: inherit;
        }

        .ep-confirm-body select:focus,
        .ep-confirm-body textarea:focus {
            border-color: var(--ep-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .ep-confirm-body textarea {
            min-height: 100px;
            resize: vertical;
        }

        .ep-confirm-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 24px 20px;
            border-top: 1px solid #f1f5f9;
        }

        .ep-confirm-footer button {
            border-radius: 10px;
            padding: 10px 20px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .ep-btn-secondary {
            background: #f1f5f9;
            color: #64748b;
        }

        .ep-btn-secondary:hover {
            background: #e2e8f0;
            color: #475569;
        }

        .ep-btn-confirm,
        .ep-confirm-footer .ep-btn-primary {
            background: var(--ep-primary);
            color: white;
        }

        .ep-btn-confirm:hover,
        .ep-confirm-footer .ep-btn-primary:hover {
            filter: brightness(1.1);
            box-shadow: 0 4px 12px rgba(var(--ep-primary-rgb, 59, 130, 246), 0.3);
        }

        /* Botón descarga en el visor */
        .ep-btn-icon-alt {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 1.1rem;
        }

        .ep-btn-icon-alt:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.08);
        }

        .ep-doc-main {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            cursor: pointer;
            min-width: 0;
            overflow: hidden;
            width: 100%;
        }

        .ep-doc-icon {
            width: 48px;
            height: 48px;
            background: rgba(var(--ep-primary-rgb), 0.05);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: var(--ep-primary);
            flex-shrink: 0;
        }

        .ep-doc-info {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            padding-right: 35px;
            /* Evitar solape con menú de tres puntos */
        }

        .ep-doc-title-wrapper {
            overflow: hidden;
            white-space: nowrap;
            position: relative;
        }

        .ep-doc-title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--ep-text);
            display: inline-block;
            transition: transform 0.3s ease;
        }

        .ep-doc-title.truncated {
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        /* Títulos largos: truncar por defecto, scroll solo al hover */
        .ep-doc-title.scrolling {
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        /* Estilo Scroll al Hover - solo en títulos marcados como scrolling */
        .ep-document-card:hover .ep-doc-title.scrolling {
            text-overflow: clip;
            overflow: hidden;
            display: inline-block;
            width: auto;
            max-width: none;
            animation: epTitleScroll 8s linear infinite;
        }

        @keyframes epTitleScroll {
            0% {
                transform: translateX(0);
            }

            15% {
                transform: translateX(0);
            }

            85% {
                transform: translateX(calc(-100% + 150px));
            }

            100% {
                transform: translateX(calc(-100% + 150px));
            }
        }

        .ep-doc-meta {
            font-size: 0.75rem;
            color: var(--ep-text-muted);
            margin-top: 2px;
        }

        /* Badge Footer Status */
        .ep-doc-footer-status {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ep-status-pill {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .ep-status-pill.oki {
            background: #dcfce7;
            color: #166534;
        }

        .ep-status-pill.onedrive {
            background: #e0f2fe;
            color: #075985;
        }

        .ep-status-pill.signed {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .ep-status-pill.ep-shared-badge {
            background: #ffedd5;
            color: #9a3412;
        }

        .ep-status-pill.ep-shared-me-badge {
            background: #ccfbf1;
            color: #115e59;
        }

        .ep-doc-owner {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .ep-doc-owner i {
            color: #94a3b8;
        }


        .ep-doc-feedback-badge {
            padding: 6px 10px;
            background: #fef9c3;
            color: #854d0e;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 500;
            width: 100%;
            border-left: 3px solid #eab308;
        }
    </style>
    <?php
    // Fin downloads-app.php
    ?>