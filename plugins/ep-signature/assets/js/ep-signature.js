/**
 * EP Signature App Logic
 */
jQuery(document).ready(function ($) {
    if (!$('.ep-signature-app').length) return;

    // Inicializar AutoScript para asegurar conectividad
    if (typeof AutoScript !== 'undefined') {
        try {
            AutoScript.cargarAppAfirma();
            console.log('EP_App_Signature: AutoScript initialized');
        } catch(e) {
            console.error('EP_App_Signature: Error initializing AutoScript:', e);
        }
    }


    // --- State Variables ---
    let fileQueue = [];
    let currentFileIndex = -1;
    let pdfDocument = null;
    let currentPageNum = 1;
    let isSigning = false;
    let currentRenderTask = null;
    let pageRendering = false;

    let signatureStamps = []; // Array of stamps for CURRENT file: {x, y, page, type, data}
    let visibleSignatureImageBase64 = null;
    let savedSignatureBase64 = null;

    // Nuevas variables para el modo desbloqueado
    let currentPageWidthPoints = 595.276; // A4 por defecto
    let currentPageHeightPoints = 841.89;
    let detailsLogoBase64 = null;
    let savedLogoBase64 = null;
    let unlockedStampFooter = true; // Por defecto Sí

    // Check for saved signature on load
    function checkSavedSignature() {
        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature_get_user_signature',
                nonce: ep_signature_vars.nonce
            },
            success: (response) => {
                if (response.success && response.data.signature_base64) {
                    savedSignatureBase64 = response.data.signature_base64;
                    $('#fds-btn-use-saved-signature').fadeIn();
                }
            }
        });
    }
    checkSavedSignature();

    // Check for saved logo on load
    function checkSavedLogo() {
        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature_get_user_logo',
                nonce: ep_signature_vars.nonce
            },
            success: (response) => {
                if (response.success && response.data.logo_base64) {
                    savedLogoBase64 = response.data.logo_base64;
                    $('#fds-btn-use-saved-logo').fadeIn();
                }
            }
        });
    }
    checkSavedLogo();

    // --- Selectors ---
    const $canvas = $('#fds-pdf-canvas');
    const canvasElement = $canvas[0];
    const canvasContext = canvasElement.getContext('2d');
    const $dropZone = $('#fds-drop-zone');
    const $fileInput = $('#fds-pdf-file');
    const $editorArea = $('#fds-editor-area');
    const $signButton = $('#fds-sign-button');
    const $resultArea = $('#fds-sign-result');

    // --- Tab Switching ---
    $('.ep-tab-btn').on('click', function () {
        const tabId = $(this).data('tab');
        $('.ep-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.ep-tab-content').removeClass('active');
        $('#' + tabId).addClass('active');

        if (tabId === 'tab-my-docs') {
            loadMyDocuments();
        } else if (tabId === 'tab-inbox') {
            loadInbox();
        } else if (tabId === 'tab-admin') {
            loadAdminDocuments();
        }
    });

    // Sub-tabs handling for Inbox
    $('.ep-sub-tab-btn').on('click', function () {
        const subTabId = $(this).data('sub-tab');
        $('.ep-sub-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.ep-sub-tab-content').removeClass('active');
        $('#' + subTabId).addClass('active');

        if (subTabId === 'inbox-received') {
            loadInbox();
        } else if (subTabId === 'inbox-sent') {
            loadSentRequests();
        }
    });

    // --- File Handling ---
    $dropZone.on('click', (e) => {
        // Only trigger if we didn't click the input itself (though it's hidden, it could bubble)
        if (e.target.id !== 'fds-pdf-file') {
            $fileInput.click();
        }
    });

    $fileInput.on('click', (e) => {
        e.stopPropagation();
    });

    $fileInput.on('change', function (e) {
        if (e.target.files.length) handleFiles(e.target.files);
    });

    $dropZone.on('dragover', (e) => { e.preventDefault(); $dropZone.addClass('is-dragover'); });
    $dropZone.on('dragleave', () => $dropZone.removeClass('is-dragover'));
    $dropZone.on('drop', (e) => {
        e.preventDefault();
        $dropZone.removeClass('is-dragover');
        if (e.originalEvent.dataTransfer.files.length) handleFiles(e.originalEvent.dataTransfer.files);
    });

    async function handleFiles(files) {
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            if (file.type !== 'application/pdf') continue;

            const reader = new FileReader();
            const fileData = await new Promise(resolve => {
                reader.onload = (e) => resolve(e.target.result);
                reader.readAsArrayBuffer(file);
            });

            const hash = await calculateHash(fileData);

            fileQueue.push({
                file: file,
                buffer: fileData,
                hash: hash,
                stamps: [],
                signed: false
            });
        }

        renderQueue();
        if (currentFileIndex === -1 && fileQueue.length > 0) {
            loadQueueFile(0);
        }

        $dropZone.hide();
        $('#fds-queue-area').show();
        $editorArea.fadeIn();
    }

    function renderQueue() {
        const $list = $('#fds-queue-list');
        $list.empty();
        $('#fds-queue-count').text(fileQueue.length);

        fileQueue.forEach((item, index) => {
            let icon = '<i class="fa-regular fa-clock status-icon"></i>';
            let statusClass = '';

            if (item.signed) {
                icon = '<i class="fa-solid fa-circle-check status-icon"></i>';
                statusClass = 'signed';
            } else if (index === currentFileIndex) {
                icon = '<i class="fa-solid fa-arrow-right-long status-icon"></i>';
                statusClass = 'active';
            }

            const $item = $(`
                <div class="queue-item ${statusClass}" data-index="${index}">
                    ${icon}
                    <div class="file-info">${item.file.name}</div>
                    <div class="marks-count">${item.stamps.length} marcas</div>
                </div>
            `);
            $item.on('click', () => loadQueueFile(index));
            $list.append($item);
        });
    }

    async function loadQueueFile(index, force = false) {
        if (!force && (isSigning || index === currentFileIndex)) return;

        console.log('EP_App_Signature: Loading queue file index:', index);

        // Save current stamps before switching
        if (currentFileIndex !== -1) {
            fileQueue[currentFileIndex].stamps = [...signatureStamps];
        }

        currentFileIndex = index;
        const item = fileQueue[index];

        $('#fds-file-name').text(item.file.name);
        signatureStamps = [...item.stamps];

        renderQueue();
        await initPDF(item.buffer);
        updateCoordsDisplay();

        // Mostrar opción de enviar al remitente solo si es una solicitud recibida (Buzón)
        console.log('EP_App_Signature: [DEBUG] item.requestId is:', item.requestId);
        if (item.requestId) {
            $('#fds-post-sign-options').fadeIn();
        } else {
            $('#fds-post-sign-options').hide();
        }
    }

    async function calculateHash(buffer) {
        const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    // --- PDF.js Integration ---
    async function initPDF(buffer) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = ep_signature_vars.pdf_worker_src;
        const loadingTask = pdfjsLib.getDocument({ data: new Uint8Array(buffer) });
        const pdf = await loadingTask.promise;
        pdfDocument = pdf;
        currentPageNum = 1;
        $('#fds-total-pages').text(pdf.numPages);
        return renderPage(1);
    }

    function renderPage(num) {
        return new Promise((resolve) => {
            pageRendering = true;
            pdfDocument.getPage(num).then(page => {
                const scale = 1.1;
                const viewport = page.getViewport({ scale: scale });
                canvasElement.height = viewport.height;
                canvasElement.width = viewport.width;

                // Guardar tamaño original en puntos PDF
                if (page.view) {
                    currentPageWidthPoints = page.view[2] - page.view[0];
                    currentPageHeightPoints = page.view[3] - page.view[1];
                } else {
                    currentPageWidthPoints = page.width || (viewport.width / scale);
                    currentPageHeightPoints = page.height || (viewport.height / scale);
                }

                const renderContext = {
                    canvasContext: canvasContext,
                    viewport: viewport
                };

                if (currentRenderTask) currentRenderTask.cancel();
                currentRenderTask = page.render(renderContext);
                currentRenderTask.promise.then(() => {
                    pageRendering = false;
                    $('#fds-current-page').text(num);
                    renderMarkers();
                    resolve();
                });
            });
        });
    }

    const $markerTemplate = $('#fds-signature-marker');
    function renderMarkers() {
        // Remove old markers except the hidden template
        $('.fds-active-marker').remove();

        const rect = canvasElement.getBoundingClientRect();
        const wrapperRect = $('.canvas-wrapper')[0].getBoundingClientRect();

        signatureStamps.forEach((stamp, index) => {
            if (stamp.page !== currentPageNum) return;

            const $m = $('<div class="fds-active-marker fds-marker"></div>');

            const left = (rect.left - wrapperRect.left) + (stamp.x_ratio * rect.width);
            const top = (rect.top - wrapperRect.top) + (stamp.y_ratio * rect.height);

            $m.css({
                top: top + 'px',
                left: left + 'px',
                display: 'block',
                position: 'absolute',
                border: '2px dashed #007bff',
                background: 'rgba(0, 123, 255, 0.1)',
                zIndex: 10
            });

            if (stamp.type === 'image') {
                $m.css({ width: '160px', height: '80px', transform: 'translate(-50%, -50%)' });
                $m.html('<i class="fa-solid fa-image"></i>');
            } else {
                $m.css({ width: '180px', height: '70px', transform: 'translate(-50%, -50%)' });
                $m.html('<i class="fa-solid fa-signature"></i>');
            }

            // Remove button
            const $del = $('<div class="fds-marker-delete"><i class="fa-solid fa-times"></i></div>');
            $del.on('click', (e) => {
                e.stopPropagation();
                signatureStamps.splice(index, 1);
                renderMarkers();
                updateCoordsDisplay();
            });
            $m.append($del);

            $('.canvas-wrapper').append($m);
        });
    }

    function updateCoordsDisplay() {
        if (currentFileIndex !== -1) {
            fileQueue[currentFileIndex].stamps = [...signatureStamps];
            console.log('EP_App_Signature: Updated stamps for index', currentFileIndex, 'Count:', signatureStamps.length);
        }

        if (signatureStamps.length > 0) {
            const last = signatureStamps[signatureStamps.length - 1];
            if (last) {
                $('#fds-coords-x').text(last.x_ratio.toFixed(3));
                $('#fds-coords-y').text(last.y_ratio.toFixed(3));
                $('#fds-visible-signature-positioning-area').fadeIn();
            }
        } else {
            $('#fds-visible-signature-positioning-area').fadeOut();
        }

        $('#fds-signature-count').text(signatureStamps.length);
        renderQueue();
    }

    $('#fds-prev-page').on('click', () => {
        if (currentPageNum <= 1 || pageRendering) return;
        currentPageNum--;
        renderPage(currentPageNum);
    });

    $('#fds-next-page').on('click', () => {
        if (currentPageNum >= pdfDocument.numPages || pageRendering) return;
        currentPageNum++;
        renderPage(currentPageNum);
    });

    // --- Positioning ---
    $canvas.on('click', function (e) {
        if (!ep_signature_vars.user_info.can_manage) {
            console.log('Interaction blocked: User cannot place markers.');
            return;
        }
        const type = $('input[name="fds_visible_signature_type"]:checked').val();
        if (type === 'none') return;

        const rect = canvasElement.getBoundingClientRect();
        const x_ratio = (e.clientX - rect.left) / rect.width;
        const y_ratio = (e.clientY - rect.top) / rect.height;

        let stampData = null;
        if (type === 'image') {
            if (!visibleSignatureImageBase64) {
                alert('Por favor, selecciona o sube una imagen de rúbrica primero.');
                return;
            }
            stampData = visibleSignatureImageBase64;
        } else if (type === 'text') {
            const userName = $('#fds-user-display-name').val();
            const userDni = $('#fds-user-dni').val();
            stampData = JSON.stringify({
                name: userName,
                dni: userDni
            });
        } else if (type === 'details') {
            const userName = $('#fds-user-display-name').val();
            const userDni = $('#fds-user-dni').val();
            stampData = JSON.stringify({
                name: userName,
                dni: userDni,
                logo: detailsLogoBase64
            });
        }

        const newStamp = {
            x_ratio: x_ratio,
            y_ratio: y_ratio,
            page: currentPageNum,
            type: type,
            data: stampData
        };

        signatureStamps.push(newStamp);
        renderMarkers();
        updateCoordsDisplay();
    });

    // --- Options Toggle ---
    $('input[name="fds_target_user_type"]').on('change', function () {
        const val = $(this).val();
        console.log('Target user type changed to:', val);
        if (val === 'other') {
            $('#fds-recipient-selector-area').show();
            $('#fds-instructions-area').show();
        } else {
            $('#fds-recipient-selector-area').hide();
            $('#fds-instructions-area').hide();
        }
        if (val === 'me') {
            $('#fds-recipient-id').val('');
            $('#fds-selected-recipient-badge').hide();
        }
        updateSignButtonText();
    });

    // Initialize visibility on load
    $('input[name="fds_target_user_type"]:checked').trigger('change');

    function updateSignButtonText() {
        const targetType = $('input[name="fds_target_user_type"]:checked').val();
        const $textContainer = $signButton.find('.fds-button-text');
        const icon = (targetType === 'other') ? '<i class="fa-solid fa-paper-plane"></i> ' : '<i class="fa-solid fa-pen-nib"></i> ';
        const label = (targetType === 'other') ? 'Enviar a Firmar' : 'Firmar Documentos';

        if ($textContainer.length) {
            $textContainer.html(icon + label);
        } else {
            // Fallback: update button html directly if structure is different
            $signButton.html(icon + '<span class="fds-button-text">' + label + '</span> <span class="spinner" style="display:none;"><i class="fa-solid fa-circle-notch fa-spin"></i></span>');
        }

        // Ensure button is enabled when something is loaded
        if (fileQueue.length > 0) {
            $signButton.prop('disabled', false);
        }
    }

    // User Search Logic
    let searchTimer = null;
    $('#fds-recipient-search').on('input', function () {
        const search = $(this).val().trim();
        clearTimeout(searchTimer);
        $('#fds-recipient-results').hide();

        if (search.length < 2) return;

        searchTimer = setTimeout(() => {
            $.ajax({
                url: ep_signature_vars.ajax_url,
                method: 'POST',
                data: {
                    action: 'ep_app_signature',
                    nonce: ep_signature_vars.nonce,
                    sub_action: 'get_users',
                    search: search
                },
                success: (response) => {
                    if (response.success && response.data.length > 0) {
                        const $res = $('#fds-recipient-results');
                        $res.empty().show();
                        response.data.forEach(user => {
                            const $item = $(`<div class="search-result-item" data-id="${user.id}" data-name="${user.name}">
                                <strong>${user.name}</strong>
                                <span class="user-email">${user.email}</span>
                            </div>`);
                            $item.on('click', () => selectRecipient(user));
                            $res.append($item);
                        });
                    }
                }
            });
        }, 300);
    });

    function selectRecipient(user) {
        $('#fds-recipient-id').val(user.id);
        $('#fds-selected-recipient-name').text(user.name);
        $('#fds-selected-recipient-badge').fadeIn();
        $('#fds-recipient-results').hide();
        $('#fds-recipient-search').val('');
    }

    $('#fds-remove-recipient').on('click', () => {
        $('#fds-recipient-id').val('');
        $('#fds-selected-recipient-badge').fadeOut();
    });

    $('input[name="fds_visible_signature_type"]').on('change', function () {
        const val = $(this).val();
        $('#fds-visible-signature-user-data-area').toggle(val === 'text' || val === 'details');
        $('#fds-details-logo-area').toggle(val === 'details');
        $('#fds-btn-config-unlocked').toggle(val === 'details');
        $('#fds-visible-signature-image-upload-area').toggle(val === 'image');
        $('#fds-visible-signature-positioning-area').toggle(val !== 'none');
        
        if (val === 'details') {
            $('#fds-unlocked-modal').fadeIn();
        }

        if (val === 'none') {
            signatureStamps = [];
            renderMarkers();
            updateCoordsDisplay();
        }
    });

    // Eventos del Modal de Firma Desbloqueada
    $('#fds-btn-config-unlocked').on('click', function (e) {
        e.preventDefault();
        $('#fds-unlocked-modal').fadeIn();
    });

    $('#fds-close-unlocked-modal').on('click', function () {
        $('#fds-unlocked-modal').fadeOut();
    });

    $('#fds-confirm-unlocked').on('click', function () {
        unlockedStampFooter = $('input[name="fds_unlocked_stamp_footer"]:checked').val() === 'yes';
        $('#fds-unlocked-modal').fadeOut();
    });

    // Gestión del Logo de Sello
    $('#fds-details-logo-file').on('change', function (e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                detailsLogoBase64 = ev.target.result;
                $('#fds-details-logo-preview').attr('src', detailsLogoBase64).fadeIn();
                $('#fds-save-logo-container').fadeIn();
            };
            reader.readAsDataURL(file);
        }
    });

    $('#fds-save-logo-checkbox').on('change', function() {
        if (this.checked && detailsLogoBase64) {
            saveLogoToServer(detailsLogoBase64);
        }
    });

    $('#fds-btn-use-saved-logo').on('click', function() {
        if (savedLogoBase64) {
            detailsLogoBase64 = savedLogoBase64;
            $('#fds-details-logo-preview').attr('src', detailsLogoBase64).fadeIn();
            $('#fds-save-logo-container').hide();
            
            // Feedback visual
            $(this).html('<i class="fa-solid fa-check"></i> Cargado').addClass('ep-btn-success').delay(2000).queue(function(next){
                $(this).html('<i class="fa-solid fa-bookmark"></i> Usar guardado').removeClass('ep-btn-success');
                next();
            });
        }
    });

    function saveLogoToServer(base64) {
        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature_save_user_logo',
                nonce: ep_signature_vars.nonce,
                logo_base64: base64
            },
            success: (response) => {
                if (response.success) {
                    savedLogoBase64 = base64;
                    $('#fds-btn-use-saved-logo').fadeIn();
                    console.log('Logo guardado con éxito en el servidor');
                }
            }
        });
    }

    $('#fds-visible-signature-image-file').on('change', function (e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                visibleSignatureImageBase64 = ev.target.result;
                $('#fds-visible-signature-image-preview').attr('src', visibleSignatureImageBase64).fadeIn();
                $('#fds-save-signature-container').fadeIn();
                
                // If checkbox is already checked (or user checks it now), we should probably wait for the sign action or save it immediately?
                // Let's save it immediately if they check it.
            };
            reader.readAsDataURL(file);
        }
    });

    $('#fds-save-signature-checkbox').on('change', function() {
        if (this.checked && visibleSignatureImageBase64) {
            saveSignatureToServer(visibleSignatureImageBase64);
        }
    });

    $('#fds-btn-use-saved-signature').on('click', function() {
        if (savedSignatureBase64) {
            visibleSignatureImageBase64 = savedSignatureBase64;
            $('#fds-visible-signature-image-preview').attr('src', visibleSignatureImageBase64).fadeIn();
            $('#fds-save-signature-container').hide(); // Already saved
            
            // Trigger radio button if not selected
            $('input[name="fds_visible_signature_type"][value="image"]').prop('checked', true).trigger('change');
            
            // Visual feedback
            $(this).html('<i class="fa-solid fa-check"></i> Cargada').addClass('ep-btn-success').delay(2000).queue(function(next){
                $(this).html('<i class="fa-solid fa-bookmark"></i> Usar guardada').removeClass('ep-btn-success');
                next();
            });
        }
    });

    function saveSignatureToServer(base64) {
        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature_save_user_signature',
                nonce: ep_signature_vars.nonce,
                signature_base64: base64
            },
            success: (response) => {
                if (response.success) {
                    savedSignatureBase64 = base64;
                    $('#fds-btn-use-saved-signature').fadeIn();
                    // Optional: show a small toast or success msg
                }
            }
        });
    }

    // --- Signature Action ---
    $signButton.on('click', async function () {
        if (isSigning || fileQueue.length === 0) return;

        // Actualizar datos de marcas dinámicamente con los valores actuales antes de firmar
        signatureStamps.forEach(stamp => {
            if (stamp.type === 'text') {
                stamp.data = JSON.stringify({
                    name: $('#fds-user-display-name').val(),
                    dni: $('#fds-user-dni').val()
                });
            } else if (stamp.type === 'details') {
                stamp.data = JSON.stringify({
                    name: $('#fds-user-display-name').val(),
                    dni: $('#fds-user-dni').val(),
                    logo: detailsLogoBase64
                });
            }
        });

        // Ensure current stamps are saved
        fileQueue[currentFileIndex].stamps = [...signatureStamps];

        // Find next unsigned file
        let nextToSign = fileQueue.find(f => !f.signed);
        if (!nextToSign) {
            alert('Todos los documentos de la cola ya han sido firmados.');
            return;
        }

        // Set state
        isSigning = true;
        updateSignBtnState(true);

        const targetUserType = $('input[name="fds_target_user_type"]:checked').val();
        const recipientId = $('#fds-recipient-id').val();
 
        if (targetUserType === 'other') {
            if (!recipientId) {
                alert('Por favor, busca y selecciona un destinatario de la lista (haz clic sobre su nombre en los resultados de búsqueda).');
                isSigning = false;
                updateSignBtnState(false);
                return;
            }
            requestSignatureBulk(recipientId);
        } else {
            processQueueSign();
        }
    });

    async function requestSignatureBulk(recipientId) {
        updateStatus('Enviando solicitudes...', 20);
        let successCount = 0;

        for (const item of fileQueue) {
            if (item.signed) continue;

            const formData = new FormData();
            formData.append('action', 'ep_app_signature');
            formData.append('nonce', ep_signature_vars.nonce);
            formData.append('sub_action', 'request_signature');
            formData.append('recipient_id', recipientId);
            formData.append('file', item.file);
            formData.append('instructions', $('#fds-instructions').val());

            try {
                const response = await $.ajax({
                    url: ep_signature_vars.ajax_url,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false
                });

                if (response.success) {
                    item.signed = true;
                    successCount++;
                    renderQueue();
                    updateStatus(`Enviado ${successCount}/${fileQueue.length}`, (successCount / fileQueue.length) * 100);
                }
            } catch (e) {
                console.error('Error requesting signature:', e);
            }
        }

        isSigning = false;
        updateSignBtnState(false);
        updateStatus('Solicitudes enviadas con éxito', 100);

        // Show a more permanent feedback before reload
        const $msg = $(`<div style="position:fixed; top:20px; right:20px; background:#4CAF50; color:white; padding:15px 25px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.2); z-index:9999; display:none;">
            <i class="fa-solid fa-check-circle"></i> ¡Solicitud enviada correctamente!
        </div>`);
        $('body').append($msg);
        $msg.fadeIn().delay(2000).fadeOut(() => {
            location.reload();
        });
    }

    function updateSignBtnState(busy) {
        if (busy) {
            $signButton.find('.spinner').show();
            const targetType = $('input[name="fds_target_user_type"]:checked').val();
            const busyText = (targetType === 'other') ? 'Enviando solicitud...' : 'Procesando firma...';
            $signButton.find('.fds-button-text').text(busyText);
        } else {
            $signButton.find('.spinner').hide();
            updateSignButtonText();
        }
    }

    async function processQueueSign() {
        const item = fileQueue.find(f => !f.signed);
        if (!item) {
            isSigning = false;
            updateSignBtnState(false);
            updateStatus('¡Todo firmado!', 100);
            setTimeout(() => hideStatus(), 2000);

            $editorArea.hide();
            $('#fds-queue-area').hide();
            $resultArea.fadeIn();

            // Manejar descarga final (Un solo archivo vs Lote)
            const signedFiles = fileQueue.filter(f => f.signed);

            // Si la app de origen ha declarado un cierre propio (ver el filtro
            // ep_signature_after_sign), se cuenta lo que ha pasado de verdad y se
            // ofrece la vuelta a esa app. Solo cuando se ha firmado un único
            // documento: en un lote el cierre sería el de uno cualquiera.
            const cierre = (signedFiles.length === 1) ? signedFiles[0].after_sign : null;
            if (cierre) {
                $('#fds-result-title').text(cierre.title || '¡Documento Firmado!');
                $('#fds-result-text').text(cierre.message || '');
                if (cierre.button && cierre.button.url) {
                    // Botón propio en lugar de reaprovechar "Firmar otro documento":
                    // ese lleva un manejador delegado en document que recarga la
                    // página, y .off() no lo quita, así que competiría con la
                    // redirección.
                    $('#fds-sign-another-button').hide();
                    $('#fds-back-to-app').remove();
                    $('<button/>', {
                        id: 'fds-back-to-app',
                        class: 'ep-btn ep-btn-primary',
                        text: cierre.button.label || 'Volver'
                    }).on('click', () => {
                        window.location.href = cierre.button.url;
                    }).appendTo($resultArea.find('.ep-actions-row'));
                }
            } else {
                $('#fds-sign-another-button').show();
                $('#fds-back-to-app').remove();
                $('#fds-result-title').text('¡Documento Firmado!');
                $('#fds-result-text').text('Se han procesado todos los documentos de la cola.');
            }
            const $downloadLink = $('#fds-download-link');
            const $emailBtn = $('#fds-email-button');

            if (signedFiles.length === 1) {
                const downloadUrl = signedFiles[0].download_url;
                $downloadLink.attr('href', downloadUrl).off('click');
                $emailBtn.attr('data-urls', JSON.stringify([downloadUrl])).prop('disabled', false);
            } else if (signedFiles.length > 1) {
                // Generar ZIP para el lote
                $downloadLink.addClass('ep-btn-loading').html('<i class="fa-solid fa-spinner fa-spin"></i> Generando ZIP...').attr('href', '#');
                $emailBtn.prop('disabled', true);

                $.ajax({
                    url: ep_signature_vars.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'ep_app_signature',
                        nonce: ep_signature_vars.nonce,
                        sub_action: 'generate_zip',
                        urls: signedFiles.map(f => f.download_url)
                    },
                    success: (response) => {
                        $downloadLink.removeClass('ep-btn-loading').html('<i class="fa-solid fa-download"></i> Descargar Todo (ZIP)');
                        if (response.success) {
                            $downloadLink.attr('href', response.data.url).off('click');
                            // Para el email, enviamos los archivos individuales en lugar del ZIP para mayor fiabilidad
                            $emailBtn.attr('data-urls', JSON.stringify(signedFiles.map(f => f.download_url))).prop('disabled', false); 
                        } else {
                            $downloadLink.attr('href', '#').on('click', (e) => { e.preventDefault(); alert('Error al generar ZIP: ' + response.data.message); });
                        }
                    },
                    error: () => {
                        $downloadLink.removeClass('ep-btn-loading').html('<i class="fa-solid fa-download"></i> Reintentar ZIP').on('click', (e) => { e.preventDefault(); processQueueSign(); });
                    }
                });
            }
            return;
        }

        // Load item being signed
        if (fileQueue.indexOf(item) !== currentFileIndex) {
            await loadQueueFile(fileQueue.indexOf(item), true);
        }

        if (item.stamps.length === 0) {
            if (!confirm(`El documento "${item.file.name}" no tiene marcas de firma. ¿Firmar de todos modos (firma invisible)?`)) {
                isSigning = false;
                updateSignBtnState(false);
                return;
            }
        }

        const stampsToSend = JSON.stringify(item.stamps);
        console.log('EP_App_Signature: [STEP] Preparing PDF for:', item.file.name);
        console.log('EP_App_Signature: [DATA] Stamps:', stampsToSend);

        // 1. Prepare PDF on Server
        const formData = new FormData();
        formData.append('action', 'ep_app_signature');
        formData.append('nonce', ep_signature_vars.nonce);
        formData.append('sub_action', 'prepare_pdf');
        formData.append('original_pdf', item.file);
        formData.append('pdf_hash_original', item.hash);
        formData.append('stamps', stampsToSend);
        if (item.requestId) {
            formData.append('request_id', item.requestId);
        }
        const visibleType = $('input[name="fds_visible_signature_type"]:checked').val();
        const stampFooter = (visibleType === 'details') ? (unlockedStampFooter ? '1' : '0') : '1';
        formData.append('stamp_footer', stampFooter);

        formData.append('user_name_for_stamp', $('#fds-user-display-name').val());
        formData.append('user_dni_for_stamp', $('#fds-user-dni').val());
        formData.append('pdf_canvas_width', canvasElement.width);
        formData.append('pdf_canvas_height', canvasElement.height);

        // Backward compatibility for single stamp (backend fallbacks)
        if (item.stamps.length > 0) {
            const first = item.stamps[0];
            formData.append('visible_signature_type', first.type);
            formData.append('visible_signature_x_ratio', first.x_ratio);
            formData.append('visible_signature_y_ratio', first.y_ratio);
            formData.append('visible_signature_page', first.page);
            formData.append('visible_signature_data', first.data);
        }

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: async function (response) {
                console.log('EP_App_Signature: [RESPONSE] Prepare PDF:', response);
                if (response.success) {
                    try {
                        if (response.data.skipped_prep) {
                            updateStatus('Documento protegido. Firmando original...', 40);
                            console.warn('EP_App_Signature: [WARN] ' + response.data.message);
                        } else {
                            updateStatus('Esperando respuesta de AutoFirma...', 50);
                        }
                        const signResults = await signWithAutoFirma(
                            response.data.pdf_data_to_sign_base64,
                            item,
                            !!response.data.skipped_prep
                        );
                        saveSignedDocument(signResults.signature, item, signResults.certificate);
                    } catch (err) {
                        let errorMsg = err;
                        if (typeof err === 'string' && (err.includes('Conexión') || err.includes('Socket') || err.includes('AutoFirma'))) {
                            errorMsg = 'No se ha podido conectar con la aplicación AutoFirma. Por favor, asegúrate de tener instalada y abierta la aplicación de escritorio AutoFirma en tu ordenador antes de intentar firmar.';
                        }
                        console.error('EP_App_Signature: [ERR] AutoFirma error:', err);
                        alert('Error en la firma de ' + item.file.name + ': ' + errorMsg);
                        resetSigningState();
                    }
                } else {
                    console.error('EP_App_Signature: [ERR] Server prep error:', response.data.message);
                    alert('Error preparando ' + item.file.name + ': ' + response.data.message);
                    resetSigningState();
                }
            },
            error: (xhr) => {
                console.error('EP_App_Signature: [ERR] AJAX Error:', xhr);
                alert('Error de conexión al servidor.');
                resetSigningState();
            }
        });
    }

    function saveSignedDocument(signatureB64, item, certificateB64 = null) {
        updateStatus('Guardando documento firmado...', 80);
        console.log('EP_App_Signature: [STEP] Saving signed PDF:', item.file.name);
        const formData = new FormData();
        formData.append('action', 'ep_app_signature');
        formData.append('nonce', ep_signature_vars.nonce);
        formData.append('sub_action', 'save_signed_pdf');
        formData.append('signature', signatureB64);
        formData.append('filename', item.file.name);
        formData.append('pdf_hash', item.hash);
        
        if (certificateB64) {
            const certInfo = { certificateBase64: certificateB64 };
            formData.append('cert_info', JSON.stringify(certInfo));
        }

        if (item.requestId) {
            formData.append('request_id', item.requestId);
            formData.append('send_to_sender', $('#fds-send-to-sender').is(':checked') ? '1' : '0');
        }

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                console.log('EP_App_Signature: [RESPONSE] Save PDF:', response);
                if (response.success) {
                    item.signed = true;
                    item.download_url = response.data.download_url;
                    item.after_sign = response.data.after_sign || null;
                    renderQueue();

                    $('#fds-result-info-container').append(`
                        <div class="result-file-item">
                            <span><i class="fa-solid fa-check-circle"></i> ${item.file.name}</span>
                            <a href="${item.download_url}" target="_blank" class="ep-btn ep-btn-mini ep-btn-secondary">Descargar</a>
                        </div>
                    `);

                    // Continuar con el siguiente tras un breve retardo para actualizar la interfaz
                    setTimeout(() => processQueueSign(), 500);
                } else {
                    alert('Error al guardar ' + item.file.name + ': ' + response.data.message);
                    resetSigningState();
                }
            },
            error: () => {
                alert('Error de conexión al guardar el archivo.');
                resetSigningState();
            }
        });
    }

    function generateStampImage(type, userName, userDni, logoBase64) {
        return new Promise((resolve) => {
            const canvas = document.createElement('canvas');
            canvas.width = 400;
            canvas.height = 120;
            const ctx = canvas.getContext('2d');

            // Sin fondo ni borde (transparente estilo Acrobat)

            // Si es details y tiene logo
            if (type === 'details' && logoBase64) {
                const img = new Image();
                img.onload = function() {
                    // Dibujar logo a la izquierda (escalado para caber)
                    ctx.drawImage(img, 10, 20, 80, 80);
                    
                    // Escribir texto a la derecha
                    ctx.fillStyle = '#000000';
                    ctx.font = 'bold 12px Arial';
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'top';
                    
                    const dateStr = new Date().toLocaleString('es-ES');
                    ctx.fillText('Firmado digitalmente por:', 100, 15);
                    ctx.font = '11px Arial';
                    ctx.fillText(userName, 100, 32);
                    ctx.fillText('NIF/CIF: ' + userDni, 100, 49);
                    
                    ctx.font = '7.5px Arial';
                    ctx.fillStyle = '#444';
                    const dnText = `DN: cn=${userName}, serialNumber=IDCES-${userDni}, o=Cámara Oficial de Comercio, c=ES`;
                    ctx.fillText(dnText, 100, 66);
                    
                    ctx.font = '10px Arial';
                    ctx.fillStyle = '#000';
                    ctx.fillText('Fecha: ' + dateStr, 100, 83);
                    
                    ctx.font = 'bold 9px Arial';
                    ctx.fillStyle = '#666';
                    ctx.fillText('Portal del Empleado - Cámara de Cáceres', 100, 100);
                    
                    resolve(canvas.toDataURL('image/png'));
                };
                img.src = logoBase64;
            } else {
                // Dibujar solo texto (ocupando todo el ancho)
                ctx.fillStyle = '#000000';
                ctx.font = 'bold 12px Arial';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'top';
                
                if (type === 'details') {
                    const dateStr = new Date().toLocaleString('es-ES');
                    ctx.fillText('Firmado digitalmente por:', 15, 15);
                    ctx.font = '11px Arial';
                    ctx.fillText(userName, 15, 32);
                    ctx.fillText('NIF/CIF: ' + userDni, 15, 49);
                    
                    ctx.font = '7.5px Arial';
                    ctx.fillStyle = '#444';
                    const dnText = `DN: cn=${userName}, serialNumber=IDCES-${userDni}, o=Cámara Oficial de Comercio, c=ES`;
                    ctx.fillText(dnText, 15, 66);
                    
                    ctx.font = '10px Arial';
                    ctx.fillStyle = '#000';
                    ctx.fillText('Fecha: ' + dateStr, 15, 83);
                    
                    ctx.font = 'bold 9px Arial';
                    ctx.fillStyle = '#666';
                    ctx.fillText('Portal del Empleado - Cámara de Cáceres', 15, 100);
                } else {
                    ctx.fillText('Firmado por:', 15, 20);
                    ctx.font = '11px Arial';
                    ctx.fillText(userName, 15, 40);
                    ctx.fillText('DNI/CIF: ' + userDni, 15, 60);
                }
                
                resolve(canvas.toDataURL('image/png'));
            }
        });
    }

    async function signWithAutoFirma(base64Data, item = null, skippedPrep = false) {
        return new Promise(async (resolve, reject) => {
            try {
                let params = "";
                const visibleType = $('input[name="fds_visible_signature_type"]:checked').val();
                
                // Evitamos duplicidad: Solo configuramos la firma visible en el cliente (AutoFirma)
                // si el servidor se saltó la preparación (skippedPrep === true) por estar el documento firmado/protegido.
                if (item && item.stamps && item.stamps.length > 0 && skippedPrep) {
                    const stamp = item.stamps[0];
                    if (stamp.type !== 'none') {
                        const w = currentPageWidthPoints;
                        const h = currentPageHeightPoints;
                        
                        const clickX = stamp.x_ratio * w;
                        const clickY = h - (stamp.y_ratio * h);
                        
                        // Hacemos el stamp un poco más grande (200x60 en puntos PDF) para que se lea el DN
                        const stampW = 200;
                        const stampH = 60;
                        const xMin = Math.round(clickX - (stampW / 2));
                        const xMax = Math.round(clickX + (stampW / 2));
                        const yMin = Math.round(clickY - (stampH / 2));
                        const yMax = Math.round(clickY + (stampH / 2));
                        const pageNum = stamp.page;
                        
                        params = `signaturePage=${pageNum}` +
                                 `\nsignaturePositionOnPageLowerLeftX=${xMin}` +
                                 `\nsignaturePositionOnPageLowerLeftY=${yMin}` +
                                 `\nsignaturePositionOnPageUpperRightX=${xMax}` +
                                 `\nsignaturePositionOnPageUpperRightY=${yMax}`;
                        
                        if (stamp.type === 'image' && visibleSignatureImageBase64) {
                            const cleanB64 = visibleSignatureImageBase64.replace(/^data:image\/(png|jpeg|jpg);base64,/i, "");
                            params += `\nsignatureRubricImage=${cleanB64}`;
                        } else if (stamp.type === 'text' || stamp.type === 'details') {
                            const name = $('#fds-user-display-name').val() || ep_signature_vars.user_info.display_name;
                            const dni = $('#fds-user-dni').val() || ep_signature_vars.user_info.dni || '';
                            
                            // Generar dinámicamente la imagen de firma
                            const dataUrl = await generateStampImage(stamp.type, name, dni, detailsLogoBase64);
                            const cleanB64 = dataUrl.replace(/^data:image\/(png|jpeg|jpg);base64,/i, "");
                            params += `\nsignatureRubricImage=${cleanB64}`;
                        }
                    }
                }
                
                console.log('EP_App_Signature: [AutoFirma] Calling sign with params:', params);
                
                AutoScript.sign(
                    base64Data,
                    "SHA256withRSA",
                    "PAdES",
                    params,
                    (signature, certificate) => {
                        if (signature) {
                            resolve({
                                signature: signature,
                                certificate: certificate
                            });
                        } else {
                            reject('No se recibió la firma del cliente.');
                        }
                    },
                    (type, message) => {
                        reject(message || type || 'Error desconocido en AutoFirma');
                    }
                );
            } catch (e) {
                reject(e.message || e);
            }
        });
    }

    function resetSigningState() {
        isSigning = false;
        updateSignBtnState(false);
    }


    // --- Loader Functions ---
    function loadMyDocuments() {
        const $list = $('#fds-my-documents-list');
        $list.html('<p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando...</p>');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'get_my_docs'
            },
            success: (html) => $list.html(html),
            error: () => $list.html('<p class="error-msg">Error al cargar documentos.</p>')
        });
    }

    function loadAdminDocuments() {
        const $list = $('#fds-admin-documents-list');
        if (!$list.length) return;
        $list.html('<p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando todos los documentos...</p>');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'get_admin_docs'
            },
            success: (html) => $list.html(html),
            error: () => $list.html('<p class="error-msg">Error al cargar el panel de gestión.</p>')
        });
    }

    function loadInbox() {
        const $list = $('#fds-inbox-list');
        $list.html('<p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando...</p>');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'get_inbox'
            },
            success: (html) => {
                $list.html(html);
                checkAutoSign();
            },
            error: () => $list.html('<p class="error-msg">Error al cargar el buzón.</p>')
        });
    }

    function loadSentRequests() {
        const $list = $('#fds-sent-list');
        $list.html('<p class="loading-msg"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando...</p>');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'get_sent_requests'
            },
            success: (html) => $list.html(html),
            error: () => $list.html('<p class="error-msg">Error al cargar las solicitudes enviadas.</p>')
        });
    }

    // Direct Signature from Inbox
    $(document).on('click', '.fds-btn-sign-now', async function () {
        const $btn = $(this);
        const fileUrl = $btn.data('url');
        const fileName = $btn.data('name');
        const requestId = $btn.data('id'); // Get the DB ID of the request

        // Prepare a virtual file item for the queue
        updateStatus('Cargando documento...', 20);

        try {
            const response = await fetch(fileUrl);
            if (!response.ok) throw new Error('Error al descargar el archivo del servidor.');

            const blob = await response.blob();
            if (blob.type !== 'application/pdf' && !fileName.toLowerCase().endsWith('.pdf')) {
                throw new Error('El archivo seleccionado no es un PDF válido. La firma electrónica solo está disponible para documentos PDF.');
            }

            const arrayBuffer = await blob.arrayBuffer();
            const file = new File([blob], fileName, { type: 'application/pdf' });
            const hash = await calculateHash(arrayBuffer);

            // ... resto del código ...
            fileQueue = [{
                file: file,
                buffer: arrayBuffer,
                hash: hash,
                requestId: requestId, // Save the request ID
                stamps: [],
                signed: false
            }];

            $dropZone.hide();
            $('#fds-queue-area').show();
            await loadQueueFile(0, true);
            $editorArea.fadeIn();
            $('.ep-tab-btn[data-tab="tab-sign"]').click();
            hideStatus();

        } catch (e) {
            console.error('Error loading file for signature:', e);
            alert('Error: ' + e.message);
            hideStatus();
        }
    });

    // Select all for Inbox
    $(document).on('change', '.select-all-inbox', function () {
        const target = $(this).closest('table').find('.inbox-doc-checkbox');
        target.prop('checked', $(this).prop('checked'));
    });

    // Bulk Signature from Inbox
    $(document).on('click', '#fds-btn-sign-bulk', async function () {
        const selected = $('#fds-inbox-docs-table .inbox-doc-checkbox:checked');
        if (selected.length === 0) {
            alert('Por favor, selecciona al menos un documento para firmar.');
            return;
        }

        updateStatus('Cargando documentos...', 10);
        const newQueue = [];
        let loadedCount = 0;

        for (let i = 0; i < selected.length; i++) {
            const $chk = $(selected[i]);
            const fileUrl = $chk.data('url');
            const fileName = $chk.data('name');
            const requestId = $chk.val();

            try {
                updateStatus(`Cargando documento ${i + 1} de ${selected.length}...`, 10 + (80 * (i / selected.length)));
                const response = await fetch(fileUrl);
                if (!response.ok) throw new Error(`Error al descargar ${fileName}`);

                const blob = await response.blob();
                if (blob.type !== 'application/pdf' && !fileName.toLowerCase().endsWith('.pdf')) {
                    throw new Error(`El archivo ${fileName} no es un PDF válido.`);
                }

                const arrayBuffer = await blob.arrayBuffer();
                const file = new File([blob], fileName, { type: 'application/pdf' });
                const hash = await calculateHash(arrayBuffer);

                newQueue.push({
                    file: file,
                    buffer: arrayBuffer,
                    hash: hash,
                    requestId: requestId,
                    stamps: [],
                    signed: false
                });
                loadedCount++;
            } catch (e) {
                console.error('Error loading file for bulk signature:', e);
                alert(`Error al cargar ${fileName}: ` + e.message);
            }
        }

        if (loadedCount > 0) {
            fileQueue = newQueue;
            $dropZone.hide();
            $('#fds-queue-area').show();
            await loadQueueFile(0, true);
            $editorArea.fadeIn();
            $('.ep-tab-btn[data-tab="tab-sign"]').click();
        }
        
        hideStatus();
    });

    // Delete Request (Inbox/Sent)
    $(document).on('click', '.fds-btn-delete-request', function () {
        if (!confirm('¿Eliminar esta solicitud de firma?')) return;
        const $btn = $(this);
        const id = $btn.data('id');
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i>');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'delete_doc',
                id: id
            },
            success: (response) => {
                if (response.success) {
                    $btn.closest('tr').fadeOut();
                } else {
                    alert('Error: ' + response.data.message);
                    $btn.prop('disabled', false).html('<i class="fa-solid fa-trash-can"></i>');
                }
            },
            error: () => {
                alert('Error de conexión al intentar eliminar.');
                $btn.prop('disabled', false).html('<i class="fa-solid fa-trash-can"></i>');
            }
        });
    });

    // Delete Action
    $(document).on('click', '.delete-doc', function () {
        if (!confirm('¿Estás seguro de que quieres eliminar este documento? Esta acción es irreversible.')) return;

        const $btn = $(this);
        const id = $btn.data('id');
        $btn.prop('disabled', true).html('<i class="fa-solid fa-circle-notch fa-spin"></i>');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'delete_doc',
                id: id
            },
            success: (response) => {
                if (response.success) {
                    $btn.closest('tr').fadeOut();
                } else {
                    alert('Error: ' + response.data.message);
                    $btn.prop('disabled', false).html('<i class="fa-solid fa-trash"></i>');
                }
            }
        });
    });

    // --- Email Action (MODAL REDIRECT) ---
    $(document).on('click', '#fds-email-button, .send-email, .bulk-email', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const id = $btn.data('id');
        let urls = $btn.attr('data-urls');
        const target = $btn.data('target');

        $('#fds-email-context-ids').val('');
        $('#fds-email-context-urls').val('');

        if ($btn.hasClass('bulk-email')) {
            const tableId = target === 'admin-docs' ? '#fds-admin-docs-table' : '#fds-my-docs-table';
            const selected = $(tableId + ' .doc-checkbox:checked').map(function () { return $(this).val(); }).get();
            if (!selected.length) {
                alert('Por favor, selecciona al menos un documento.');
                return;
            }
            $('#fds-email-context-ids').val(JSON.stringify(selected));
        } else {
            if (id) $('#fds-email-context-ids').val(JSON.stringify([id]));
            if (urls) $('#fds-email-context-urls').val(urls);
        }

        $('#fds-email-modal').fadeIn();
    });

    // Modal Close
    $(document).on('click', '.fds-modal-close, #fds-email-modal-cancel', function () {
        $('#fds-email-modal').fadeOut();
    });

    // Modal Send Action
    $('#fds-email-modal-send').on('click', function () {
        const $btn = $(this);
        const to = $('#fds-email-to').val().trim();
        const subject = $('#fds-email-subject').val().trim();
        const body = $('#fds-email-body').val().trim();

        let ids = $('#fds-email-context-ids').val();
        let urls = $('#fds-email-context-urls').val();

        if (ids && ids.trim() !== '') {
            try { ids = JSON.parse(ids); } catch (e) { ids = null; }
        } else {
            ids = null;
        }
        if (urls && typeof urls === 'string' && urls.startsWith('[')) {
            try { urls = JSON.parse(urls); } catch (e) { }
        } else if (urls && !Array.isArray(urls)) {
            urls = urls ? [urls] : [];
        }

        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Enviando...');

        console.log('EP_App_Signature: [DEBUG] Sending email request', { ids, urls, to, subject });

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'send_by_email',
                ids: ids,
                urls: urls,
                custom_to: to,
                custom_subject: subject,
                custom_body: body
            },
            success: (response) => {
                console.log('EP_App_Signature: [DEBUG] Email response received', response);
                if (response.success) {
                    alert('¡Éxito! ' + response.data.message);
                    $('#fds-email-modal').fadeOut();
                } else {
                    alert('Error: ' + response.data.message);
                }
                $btn.prop('disabled', false).html(originalHtml);
            },
            error: (xhr, status, error) => {
                console.error('EP_App_Signature: [ERR] Email AJAX Error', { status, error, response: xhr.responseText });
                alert('Error de servidor al enviar el email. Por favor, contacta con soporte.');
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // --- Bulk Actions ---
    $(document).on('change', '.select-all', function () {
        const target = $(this).closest('table').find('.doc-checkbox');
        target.prop('checked', $(this).prop('checked'));
    });

    $(document).on('click', '.bulk-delete', function () {
        const tableId = $(this).data('target') === 'admin-docs' ? '#fds-admin-docs-table' : '#fds-my-docs-table';
        const selected = $(tableId + ' .doc-checkbox:checked').map(function () { return $(this).val(); }).get();

        if (!selected.length) {
            alert('Por favor, selecciona al menos un documento.');
            return;
        }

        if (!confirm('¿Estás seguro de que quieres eliminar ' + selected.length + ' documentos?')) return;

        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Borrando...');

        $.ajax({
            url: ep_signature_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'ep_app_signature',
                nonce: ep_signature_vars.nonce,
                sub_action: 'bulk_delete',
                ids: selected
            },
            success: (response) => {
                if (response.success) {
                    alert(response.data.message);
                    $(tableId + ' .doc-checkbox:checked').closest('tr').fadeOut();
                } else {
                    alert('Error: ' + response.data.message);
                }
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    $(document).on('click', '#fds-cancel-button, #fds-sign-another-button', () => location.reload());

    // --- Status Functions ---
    function updateStatus(msg, progress = null) {
        const $bar = $('#fds-status-bar');
        const $msg = $('#fds-status-message');
        const $fill = $('#fds-progress-fill');

        $msg.text(msg);
        if (progress !== null) {
            $fill.css('width', progress + '%');
        }
        $bar.fadeIn();
    }

    function hideStatus() {
        $('#fds-status-bar').fadeOut();
    }
    // --- Final Initialization for Permissions ---
    const userPermission = ep_signature_vars.user_info.permission;
    if (userPermission === 'read') {
        // Force 'other' user type and show recipient area
        $('input[name="fds_target_user_type"][value="other"]').prop('checked', true).trigger('change');
        if (typeof updateSignButtonText === 'function') {
            updateSignButtonText();
        }
    }

    // --- Auto-trigger signature from URL parameter ---
    function checkAutoSign() {
        const urlParams = new URLSearchParams(window.location.search);
        const reqId = urlParams.get('request_id');
        if (reqId) {
            const $btn = $('.fds-btn-sign-now[data-id="' + reqId + '"]');
            if ($btn.length) {
                console.log('EP_App_Signature: Auto-clicking sign now for request ID:', reqId);
                
                // Clear request_id from URL so it doesn't trigger again on reload/navigation
                const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + window.location.search.replace(/&?request_id=[^&]*/, '');
                window.history.replaceState({ path: newUrl }, '', newUrl);

                $btn.click();
            }
        }
    }

    const urlParams = new URLSearchParams(window.location.search);
    const reqId = urlParams.get('request_id');
    if (reqId) {
        console.log('EP_App_Signature: URL request_id found:', reqId);
        // Switch to the inbox tab automatically which will trigger loadInbox()
        $('.ep-tab-btn[data-tab="tab-inbox"]').click();
    }
});
