<?php
/**
 * Vista principal de la app Registro de Contratos Menores.
 */
defined('ABSPATH') || exit;

global $ep_app_manager;
$current_user = wp_get_current_user();
$can_create   = EP_Contratos::current_user_can_read();
$can_write    = EP_Contratos::current_user_can_write();
$is_admin     = current_user_can('administrator');
$stats        = EP_Contratos::get_stats();
$nonce        = wp_create_nonce('ep_contratos_nonce');
$ajax_url     = admin_url('admin-ajax.php');
?>

<!-- Toast de copia al portapapeles -->
<div id="epContratosCopyToast" class="ep-copy-toast">
    <i class="fa-solid fa-check"></i> Texto copiado al portapapeles
</div>

<div class="ep-content-grid">

    <!-- CABECERA -->
    <section style="padding-bottom: 0;">
        <div class="ep-contratos-header">
            <div class="ep-contratos-title">
                <div class="ep-contratos-icon">
                    <i class="fa-solid fa-file-contract"></i>
                </div>
                <div>
                    <h2>Registro de Contratos Menores</h2>
                    <p>Cámara Oficial de Comercio de Cáceres · Año <span id="epContratosYearText"><?php echo date('Y'); ?></span></p>
                </div>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <select id="epContratosYearFilter" class="ep-btn" style="background:#fff; color:#333; border:1px solid #e2e8f0; font-weight:600; padding:10px;">
                    <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                </select>
                <?php if ($can_create): ?>
                <button class="ep-btn ep-btn-primary" id="btnNuevoContrato">
                    <i class="fa-solid fa-plus"></i> Nuevo Contrato
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats bar -->
        <div class="ep-contratos-stats">
            <div class="ep-contratos-stat-item">
                <i class="fa-solid fa-file-contract" style="color: var(--ep-primary);"></i>
                <span>Contratos <?php echo date('Y'); ?>:</span>
                <strong id="epContratosStatTotal"><?php echo $stats['total_anio']; ?></strong>
            </div>
            <div class="ep-contratos-stat-item">
                <i class="fa-solid fa-lock" style="color: #16a34a;"></i>
                <span>Con firma:</span>
                <strong id="epContratosStatFirma"><?php echo $stats['total_firma']; ?></strong>
            </div>
            <div class="ep-contratos-stat-item">
                <i class="fa-solid fa-lock-open" style="color: #ea580c;"></i>
                <span>Pendientes de firma:</span>
                <strong id="epContratosStatPendiente"><?php echo $stats['pendientes']; ?></strong>
            </div>
        </div>
    </section>

    <!-- GRID DE TARJETAS -->
    <section>
        <div id="epContratosGrid" class="ep-contratos-grid">
            <div class="ep-contratos-empty">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                <p>Cargando contratos...</p>
            </div>
        </div>

        <!-- Paginación -->
        <div id="epContratosPagination" class="ep-contratos-pagination" style="display:none;"></div>
    </section>

</div>

<!-- =============================================
     MODAL: DETALLE DEL CONTRATO
     ============================================= -->
<div id="epContratoModal" class="ep-modal-unified">
    <div class="ep-modal-content" style="max-width: 680px;">
        <span class="ep-close ep-close-modal-trigger" data-modal="epContratoModal">&times;</span>

        <div class="ep-contrato-modal-numero" id="epCMNumero">Contrato ---</div>
        <div style="display:flex; gap:10px; align-items:center; margin-bottom: 16px; flex-wrap:wrap;">
            <span id="epCMBadge" class="ep-contrato-badge editable"><i class="fa-solid fa-lock-open"></i> Editable</span>
            <span id="epCMFecha" style="font-size:0.88rem; color:var(--ep-text-muted);"><i class="fa-regular fa-calendar"></i> </span>
        </div>

        <!-- Texto completo formateado -->
        <div id="epCMTexto" class="ep-contrato-texto-completo"></div>

        <!-- Link al PDF -->
        <div id="epCMPdfWrap" style="display:none; margin-bottom:12px;">
            <a id="epCMPdfLink" href="#" target="_blank" rel="noopener" class="ep-modal-pdf-link">
                <i class="fa-solid fa-file-pdf"></i> Ver contrato firmado (PDF)
            </a>
        </div>

        <!-- Aviso de bloqueado -->
        <div id="epCMLockedNotice" class="ep-modal-locked-notice" style="display:none;">
            <i class="fa-solid fa-shield-check"></i>
            Este contrato ha sido firmado y está bloqueado. No se puede modificar.
        </div>

        <!-- Zona de subida de PDF -->
        <div id="epCMUploadWrap" style="display:none; margin-top:16px;">
            <p style="font-weight:700; margin-bottom:8px; font-size:0.9rem; color:var(--ep-text-muted);">
                <i class="fa-solid fa-upload"></i> Adjuntar contrato firmado (PDF)
            </p>
            <div class="ep-contratos-upload-zone" id="epCMUploadZone">
                <input type="file" id="epCMUploadInput" accept="application/pdf,.pdf">
                <i class="fa-solid fa-file-pdf"></i>
                <p>Arrastra el PDF aquí o haz clic para seleccionar</p>
                <small>Solo archivos PDF · Esto bloqueará la tarjeta definitivamente</small>
            </div>
            <button id="btnCMSubirPdf" class="ep-btn ep-btn-success" style="margin-top:12px; width:100%; display:none;">
                <i class="fa-solid fa-upload"></i> Subir y bloquear contrato
            </button>
        </div>

        <!-- Acciones del modal -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:20px; padding-top:16px; border-top:1px solid var(--ep-border);">
            <button id="btnCMCopiar" class="ep-btn ep-btn-secondary" style="flex:1;">
                <i class="fa-solid fa-copy"></i> Copiar texto
            </button>
            <button id="btnCMEditar" class="ep-btn ep-btn-primary" style="display:none;">
                <i class="fa-solid fa-pen-to-square"></i> Editar
            </button>
            <button id="btnCMEliminar" class="ep-btn ep-btn-danger ep-btn-sm" style="display:none;">
                <i class="fa-solid fa-trash"></i>
            </button>
            <button class="ep-btn ep-btn-secondary ep-close-modal-trigger" data-modal="epContratoModal">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- =============================================
     MODAL: FORMULARIO NUEVO/EDITAR
     ============================================= -->
<div id="epContratoFormModal" class="ep-modal-unified">
    <div class="ep-modal-content" style="max-width: 720px;">
        <span class="ep-close ep-close-modal-trigger" data-modal="epContratoFormModal">&times;</span>
        <h2 id="epFormModalTitle" style="margin:0 0 8px 0;">Nuevo Contrato</h2>
        <p style="margin:0 0 24px 0; font-size:0.88rem; color:var(--ep-text-muted);">
            El número de contrato se propone automáticamente pero se puede editar.
        </p>
        <input type="hidden" id="epFormContratoId" value="0">

        <div class="ep-contratos-form-grid">
            <!-- Número de contrato -->
            <div class="form-group">
                <label for="epFormNumero"><i class="fa-solid fa-hashtag"></i> Número de contrato <span style="color:var(--ep-primary)">*</span></label>
                <input type="text" id="epFormNumero" name="numero" placeholder="001/26" required>
            </div>
            <!-- Fecha -->
            <div class="form-group">
                <label for="epFormFecha"><i class="fa-regular fa-calendar"></i> Fecha <span style="color:var(--ep-primary)">*</span></label>
                <input type="date" id="epFormFecha" name="fecha" required>
            </div>
            <!-- Siglas -->
            <div class="form-group">
                <label for="epFormSiglas"><i class="fa-solid fa-tag"></i> Siglas (Ej: EE)</label>
                <input type="text" id="epFormSiglas" name="siglas" placeholder="EE" maxlength="20">
            </div>
            <!-- Objeto (full width) -->
            <div class="form-group full-width">
                <label for="epFormObjeto"><i class="fa-solid fa-align-left"></i> Objeto del contrato <span style="color:var(--ep-primary)">*</span></label>
                <textarea id="epFormObjeto" name="objeto" rows="4" required placeholder="Prestación de servicios de formación..."></textarea>
            </div>
            <!-- Código y título del curso -->
            <div class="form-group full-width">
                <label for="epFormCodigo"><i class="fa-solid fa-graduation-cap"></i> Código de curso y título</label>
                <input type="text" id="epFormCodigo" name="codigo_curso" placeholder="AF 42 – 105110476 Curso de NUTRICIÓN PROFESIONAL...">
            </div>
            <!-- Duración -->
            <div class="form-group">
                <label for="epFormDuracion"><i class="fa-solid fa-clock"></i> Duración</label>
                <input type="text" id="epFormDuracion" name="duracion" placeholder="27/01/2025 al 28/02/2025">
            </div>
            <!-- Importe -->
            <div class="form-group">
                <label for="epFormImporte"><i class="fa-solid fa-euro-sign"></i> Importe de adjudicación</label>
                <input type="text" id="epFormImporte" name="importe" placeholder="4.500 euros">
            </div>
            <!-- Identidad -->
            <div class="form-group full-width">
                <label for="epFormIdentidad"><i class="fa-solid fa-id-card"></i> Identidad del adjudicatario <span style="color:var(--ep-primary)">*</span></label>
                <textarea id="epFormIdentidad" name="identidad" rows="3" required placeholder="Empresa individual: ACADEMIA LOGOS (Francisco José Moreno Moreno) – D.N.I.: 04196031A"></textarea>
            </div>
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end; padding-top:16px; border-top:1px solid var(--ep-border);">
            <button class="ep-btn ep-btn-secondary ep-close-modal-trigger" data-modal="epContratoFormModal">Cancelar</button>
            <button id="btnFormGuardar" class="ep-btn ep-btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Guardar Contrato
            </button>
        </div>
    </div>
</div>

<!-- =============================================
     JavaScript de la App
     ============================================= -->
<script>
(function() {
    'use strict';

    const AJAX_URL  = <?php echo json_encode($ajax_url); ?>;
    const NONCE     = <?php echo json_encode($nonce); ?>;
    const CAN_WRITE = <?php echo json_encode($can_write); ?>;
    const IS_ADMIN  = <?php echo json_encode($is_admin); ?>;

    let currentPage  = 1;
    let totalPages   = 1;
    let allContratos = []; // cache local
    let currentContrato = null;

    // ── Utilidades ──────────────────────────────────────────
    function formatContratoText(c) {
        const siglasPart = c.siglas ? '. ' + c.siglas : '';
        const codigoPart = c.codigo_curso ? '\nConcretamente la impartición del curso: ' + c.codigo_curso : '';

        let text = `Contrato ${c.numero} Fecha: ${c.fecha_fmt}${siglasPart}.\n\n`;
        text += `Fecha: ${c.fecha_fmt}${c.siglas ? ' ' + c.siglas : ''}\n`;
        if (c.objeto) text += `Objeto: ${c.objeto}${codigoPart}\n`;
        if (c.duracion) text += `Duración: ${c.duracion}\n`;
        if (c.importe) text += `Importe Adjudicación: ${c.importe}\n`;
        if (c.identidad) text += `Identidad Adjudicatario: ${c.identidad}`;
        return text.trim();
    }

    function showToast(msg) {
        const toast = document.getElementById('epContratosCopyToast');
        if (!toast) return;
        toast.querySelector('i').className = 'fa-solid fa-check';
        toast.childNodes[toast.childNodes.length - 1].textContent = ' ' + msg;
        toast.innerHTML = '<i class="fa-solid fa-check"></i> ' + msg;
        toast.classList.add('visible');
        setTimeout(() => toast.classList.remove('visible'), 2500);
    }

    function openModal(modalId) {
        const m = document.getElementById(modalId);
        if (m) m.classList.add('is-visible');
    }

    function closeModal(modalId) {
        const m = document.getElementById(modalId);
        if (m) m.classList.remove('is-visible');
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn._origHTML = btn.innerHTML;
            btn.innerHTML = '<span class="ep-spinner"></span> Procesando...';
        } else {
            btn.disabled = false;
            btn.innerHTML = btn._origHTML || btn.innerHTML;
        }
    }

    // ── Render grid ─────────────────────────────────────────
    function renderGrid(contratos) {
        const grid = document.getElementById('epContratosGrid');
        if (!grid) return;

        if (!contratos || contratos.length === 0) {
            grid.innerHTML = `<div class="ep-contratos-empty">
                <i class="fa-solid fa-file-contract"></i>
                <p>No hay contratos registrados todavía.</p>
            </div>`;
            return;
        }

        grid.innerHTML = contratos.map(c => {
            const isLocked = parseInt(c.locked) === 1;
            const badge = isLocked
                ? `<span class="ep-contrato-badge locked"><i class="fa-solid fa-lock"></i> Firmado</span>`
                : `<span class="ep-contrato-badge editable"><i class="fa-solid fa-lock-open"></i> Editable</span>`;
            const objetoPreview = c.objeto ? c.objeto.substring(0, 90) + (c.objeto.length > 90 ? '…' : '') : '';
            const importeStr = c.importe ? `<i class="fa-solid fa-euro-sign"></i> ${c.importe}` : '';

            return `<div class="ep-contrato-card ${isLocked ? 'locked' : ''}"
                        data-id="${c.id}"
                        onclick="epContratoOpenDetail(${c.id})"
                        title="Ver detalle del contrato ${c.numero}">
                <div class="ep-contrato-card-header">
                    <span class="ep-contrato-numero">
                        <i class="fa-solid fa-file-contract" style="font-size:0.9em; opacity:0.7;"></i>
                        Contrato ${c.numero}
                    </span>
                    ${badge}
                </div>
                <div class="ep-contrato-fecha">
                    <i class="fa-regular fa-calendar"></i> ${c.fecha_fmt}
                    ${c.siglas ? `<span style="opacity:0.6">· ${c.siglas}</span>` : ''}
                </div>
                ${objetoPreview ? `<div class="ep-contrato-objeto-preview">${objetoPreview}</div>` : ''}
                <div class="ep-contrato-card-footer">
                    <span class="ep-contrato-importe-badge">${importeStr}</span>
                    <span style="font-size:0.78rem; color:var(--ep-text-muted);">
                        <i class="fa-solid fa-hand-pointer"></i> Ver detalle
                    </span>
                </div>
            </div>`;
        }).join('');
    }

    function renderPagination(current, total) {
        const container = document.getElementById('epContratosPagination');
        if (!container) return;

        if (total <= 1) {
            container.style.display = 'none';
            return;
        }

        container.style.display = 'flex';
        let html = '';

        html += `<button onclick="epContratoChangePage(${current - 1})" ${current === 1 ? 'disabled' : ''}>
            <i class="fa-solid fa-chevron-left"></i>
        </button>`;

        for (let i = 1; i <= total; i++) {
            html += `<button class="${i === current ? 'active' : ''}" onclick="epContratoChangePage(${i})">${i}</button>`;
        }

        html += `<button onclick="epContratoChangePage(${current + 1})" ${current === total ? 'disabled' : ''}>
            <i class="fa-solid fa-chevron-right"></i>
        </button>`;

        container.innerHTML = html;
    }

    // ── Cargar datos del servidor ────────────────────────────
    function loadContratos(page) {
        page = page || 1;

        const grid = document.getElementById('epContratosGrid');
        grid.innerHTML = `<div class="ep-contratos-empty">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size:2rem;"></i>
            <p>Cargando...</p>
        </div>`;

        const fd = new FormData();
        fd.append('action', 'ep_contratos_list');
        fd.append('nonce', NONCE);
        fd.append('page', page);
        
        const yearFilter = document.getElementById('epContratosYearFilter');
        if (yearFilter) {
            fd.append('anio', yearFilter.value);
        }

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(resp => {
                if (resp.success) {
                    allContratos = resp.data.contratos;
                    currentPage  = resp.data.current;
                    totalPages   = resp.data.pages;

                    renderGrid(allContratos);
                    renderPagination(currentPage, totalPages);

                    if (resp.data.available_years) {
                        const sel = document.getElementById('epContratosYearFilter');
                        if (sel) {
                            sel.innerHTML = '<option value="todos">Todos los años</option>' + 
                                resp.data.available_years.map(y => `<option value="${y}">${y}</option>`).join('');
                            sel.value = resp.data.filter_year || 'todos';
                        }
                        const yearText = document.getElementById('epContratosYearText');
                        if (yearText) {
                            yearText.textContent = resp.data.filter_year === 'todos' ? 'Histórico' : resp.data.filter_year;
                        }
                    }

                    // Actualizar stats
                    // (se recargan al crear, no en list para no sobrecargar)
                } else {
                    grid.innerHTML = `<div class="ep-contratos-empty">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
                        <p>Error al cargar: ${resp.data.message ?? 'Error desconocido'}</p>
                    </div>`;
                }
            })
            .catch(err => {
                grid.innerHTML = `<div class="ep-contratos-empty">
                    <i class="fa-solid fa-wifi" style="color:#ef4444;"></i>
                    <p>Error de conexión.</p>
                </div>`;
                console.error('EP Contratos:', err);
            });
    }

    // Exponer función de cambio de página globalmente
    window.epContratoChangePage = function(page) {
        if (page < 1 || page > totalPages) return;
        loadContratos(page);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // ── Abrir detalle de contrato ────────────────────────────
    window.epContratoOpenDetail = function(id) {
        const c = allContratos.find(x => parseInt(x.id) === parseInt(id));
        if (!c) return;

        currentContrato = c;
        const isLocked  = parseInt(c.locked) === 1;
        const canEdit   = c.can_edit && !isLocked;
        const canUpload = c.can_upload && !isLocked;
        const canDelete = c.can_delete && !isLocked;

        // Número
        document.getElementById('epCMNumero').textContent = 'Contrato ' + c.numero;

        // Badge estado
        const badge = document.getElementById('epCMBadge');
        if (isLocked) {
            badge.className = 'ep-contrato-badge locked';
            badge.innerHTML = '<i class="fa-solid fa-lock"></i> Firmado y bloqueado';
        } else {
            badge.className = 'ep-contrato-badge editable';
            badge.innerHTML = '<i class="fa-solid fa-lock-open"></i> Editable';
        }

        // Fecha
        document.getElementById('epCMFecha').innerHTML =
            `<i class="fa-regular fa-calendar"></i> ${c.fecha_fmt}${c.siglas ? ' · ' + c.siglas : ''}`;

        // Texto completo
        document.getElementById('epCMTexto').textContent = formatContratoText(c);

        // PDF link
        const pdfWrap = document.getElementById('epCMPdfWrap');
        if (c.contrato_url && c.contrato_url.trim()) {
            document.getElementById('epCMPdfLink').href = c.contrato_url;
            pdfWrap.style.display = 'block';
        } else {
            pdfWrap.style.display = 'none';
        }

        // Aviso bloqueado
        document.getElementById('epCMLockedNotice').style.display = isLocked ? 'flex' : 'none';

        // Zona de subida
        const uploadWrap = document.getElementById('epCMUploadWrap');
        uploadWrap.style.display = canUpload ? 'block' : 'none';
        document.getElementById('epCMUploadInput').value = '';
        document.getElementById('btnCMSubirPdf').style.display = 'none';

        // Botones de acción
        document.getElementById('btnCMEditar').style.display  = canEdit  ? 'inline-flex' : 'none';
        document.getElementById('btnCMEliminar').style.display = canDelete ? 'inline-flex' : 'none';

        openModal('epContratoModal');
    };

    // ── Formulario: Nuevo contrato ───────────────────────────
    function openFormModal(contrato) {
        const isEdit = !!contrato;
        document.getElementById('epFormModalTitle').textContent = isEdit ? 'Editar Contrato ' + contrato.numero : 'Nuevo Contrato';
        document.getElementById('epFormContratoId').value = isEdit ? contrato.id : 0;
        document.getElementById('epFormFecha').value      = isEdit ? contrato.fecha : '';
        document.getElementById('epFormSiglas').value     = isEdit ? (contrato.siglas || '') : '';
        document.getElementById('epFormObjeto').value     = isEdit ? contrato.objeto : '';
        document.getElementById('epFormCodigo').value     = isEdit ? (contrato.codigo_curso || '') : '';
        document.getElementById('epFormDuracion').value   = isEdit ? (contrato.duracion || '') : '';
        document.getElementById('epFormImporte').value    = isEdit ? (contrato.importe || '') : '';
        document.getElementById('epFormIdentidad').value  = isEdit ? contrato.identidad : '';

        const numInput = document.getElementById('epFormNumero');

        if (!isEdit) {
            document.getElementById('epFormFecha').value = new Date().toISOString().split('T')[0];
            
            numInput.value = '';
            numInput.disabled = true;
            numInput.placeholder = 'Cargando número sugerido...';

            const fd = new FormData();
            fd.append('action', 'ep_contratos_get_next_number_options');
            fd.append('nonce', NONCE);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(resp => {
                    numInput.disabled = false;
                    numInput.placeholder = '001/26';
                    if (resp.success) {
                        const { suggested_gap, next_correlative } = resp.data;
                        if (suggested_gap) {
                            if (confirm(`Se ha detectado un hueco libre en la numeración: ${suggested_gap}.\n\n¿Es este el número de tu contrato?\n\n(Si seleccionas 'Cancelar', se asignará el siguiente correlativo: ${next_correlative})`)) {
                                numInput.value = suggested_gap;
                            } else {
                                numInput.value = next_correlative;
                            }
                        } else {
                            numInput.value = next_correlative;
                        }
                    } else {
                        console.error('Error al obtener la numeración sugerida:', resp.data.message);
                    }
                })
                .catch(err => {
                    numInput.disabled = false;
                    numInput.placeholder = '001/26';
                    console.error('Error de red al obtener la numeración:', err);
                });
        } else {
            numInput.value = contrato.numero;
            numInput.disabled = false;
            numInput.placeholder = '001/26';
        }

        openModal('epContratoFormModal');
    }

    // ── Guardar formulario ───────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {

        const btnNuevo = document.getElementById('btnNuevoContrato');
        if (btnNuevo) {
            btnNuevo.addEventListener('click', () => openFormModal(null));
        }

        const btnFormGuardar = document.getElementById('btnFormGuardar');
        if (btnFormGuardar) {
            btnFormGuardar.addEventListener('click', function () {
                const id    = parseInt(document.getElementById('epFormContratoId').value);
                const isNew = id === 0;

                const numero    = document.getElementById('epFormNumero').value.trim();
                const fecha     = document.getElementById('epFormFecha').value.trim();
                const objeto    = document.getElementById('epFormObjeto').value.trim();
                const identidad = document.getElementById('epFormIdentidad').value.trim();

                if (!numero || !fecha || !objeto || !identidad) {
                    alert('Por favor completa los campos obligatorios: Número de contrato, Fecha, Objeto e Identidad.');
                    return;
                }

                if (!/^\d{3,4}\/\d{2}$/.test(numero)) {
                    alert('El formato del número de contrato no es válido. Debe ser del tipo 001/26 o 0001/26.');
                    return;
                }

                function enviarContrato(confirmSkip = false) {
                    setLoading(btnFormGuardar, true);

                    const fd = new FormData();
                    fd.append('action', isNew ? 'ep_contratos_create' : 'ep_contratos_edit');
                    fd.append('nonce', NONCE);
                    if (!isNew) fd.append('id', id);
                    fd.append('numero',      numero);
                    fd.append('fecha',       fecha);
                    fd.append('siglas',      document.getElementById('epFormSiglas').value.trim());
                    fd.append('objeto',      objeto);
                    fd.append('codigo_curso', document.getElementById('epFormCodigo').value.trim());
                    fd.append('duracion',    document.getElementById('epFormDuracion').value.trim());
                    fd.append('importe',     document.getElementById('epFormImporte').value.trim());
                    fd.append('identidad',   identidad);
                    if (confirmSkip) {
                        fd.append('confirm_skip', '1');
                    }

                    fetch(AJAX_URL, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(resp => {
                            setLoading(btnFormGuardar, false);
                            if (resp.success) {
                                closeModal('epContratoFormModal');
                                showToast(resp.data.message);
                                loadContratos(isNew ? 1 : currentPage);
                            } else if (resp.data && resp.data.code === 'skip_warning') {
                                if (confirm(resp.data.message)) {
                                    enviarContrato(true);
                                }
                            } else {
                                alert('Error: ' + (resp.data.message ?? 'Error desconocido'));
                            }
                        })
                        .catch(() => {
                            setLoading(btnFormGuardar, false);
                            alert('Error de conexión. Inténtalo de nuevo.');
                        });
                }

                enviarContrato();
            });
        }

        // ── Botón editar (desde modal detalle) ───────────────
        const btnEditar = document.getElementById('btnCMEditar');
        if (btnEditar) {
            btnEditar.addEventListener('click', () => {
                if (!currentContrato) return;
                closeModal('epContratoModal');
                openFormModal(currentContrato);
            });
        }

        // ── Botón eliminar ────────────────────────────────────
        const btnEliminar = document.getElementById('btnCMEliminar');
        if (btnEliminar) {
            btnEliminar.addEventListener('click', () => {
                if (!currentContrato) return;
                if (!confirm('¿Seguro que quieres eliminar el contrato ' + currentContrato.numero + '? Esta acción no se puede deshacer.')) return;

                setLoading(btnEliminar, true);
                const fd = new FormData();
                fd.append('action', 'ep_contratos_delete');
                fd.append('nonce', NONCE);
                fd.append('id', currentContrato.id);

                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(resp => {
                        setLoading(btnEliminar, false);
                        if (resp.success) {
                            closeModal('epContratoModal');
                            showToast('Contrato eliminado.');
                            loadContratos(currentPage);
                        } else {
                            alert('Error: ' + (resp.data.message ?? 'No se pudo eliminar.'));
                        }
                    })
                    .catch(() => {
                        setLoading(btnEliminar, false);
                        alert('Error de conexión.');
                    });
            });
        }

        // ── Botón copiar ──────────────────────────────────────
        const btnCopiar = document.getElementById('btnCMCopiar');
        if (btnCopiar) {
            btnCopiar.addEventListener('click', () => {
                const texto = document.getElementById('epCMTexto').textContent;
                if (!texto) return;

                navigator.clipboard.writeText(texto).then(() => {
                    showToast('Texto copiado al portapapeles');
                }).catch(() => {
                    // Fallback para navegadores sin clipboard API
                    const ta = document.createElement('textarea');
                    ta.value = texto;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showToast('Texto copiado al portapapeles');
                });
            });
        }

        // ── Subida de PDF ─────────────────────────────────────
        const uploadInput = document.getElementById('epCMUploadInput');
        const btnSubirPdf = document.getElementById('btnCMSubirPdf');
        const uploadZone  = document.getElementById('epCMUploadZone');

        if (uploadInput) {
            uploadInput.addEventListener('change', () => {
                if (uploadInput.files.length > 0) {
                    const fname = uploadInput.files[0].name;
                    btnSubirPdf.style.display = 'inline-flex';
                    btnSubirPdf.innerHTML = `<i class="fa-solid fa-upload"></i> Subir: ${fname}`;

                    // Cambiar zona de upload
                    uploadZone.querySelector('p').textContent = fname;
                    uploadZone.querySelector('small').textContent = 'Archivo seleccionado · haz clic en "Subir" para confirmar';
                } else {
                    btnSubirPdf.style.display = 'none';
                    uploadZone.querySelector('p').textContent = 'Arrastra el PDF aquí o haz clic para seleccionar';
                    uploadZone.querySelector('small').textContent = 'Solo archivos PDF · Esto bloqueará la tarjeta definitivamente';
                }
            });
        }

        if (uploadZone) {
            uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
            uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
            uploadZone.addEventListener('drop', e => {
                e.preventDefault();
                uploadZone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    uploadInput.files = e.dataTransfer.files;
                    uploadInput.dispatchEvent(new Event('change'));
                }
            });
        }

        if (btnSubirPdf) {
            btnSubirPdf.addEventListener('click', () => {
                if (!currentContrato || !uploadInput || !uploadInput.files.length) return;
                if (!confirm('¿Confirmas subir el contrato firmado? El contrato quedará BLOQUEADO permanentemente y no podrá editarse.')) return;

                setLoading(btnSubirPdf, true);

                const fd = new FormData();
                fd.append('action', 'ep_contratos_upload');
                fd.append('nonce', NONCE);
                fd.append('id', currentContrato.id);
                fd.append('contrato_firmado', uploadInput.files[0]);

                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(resp => {
                        setLoading(btnSubirPdf, false);
                        if (resp.success) {
                            closeModal('epContratoModal');
                            showToast(resp.data.message);
                            loadContratos(currentPage);
                        } else {
                            alert('Error: ' + (resp.data.message ?? 'No se pudo subir.'));
                        }
                    })
                    .catch(() => {
                        setLoading(btnSubirPdf, false);
                        alert('Error de conexión.');
                    });
            });
        }

        // ── Cerrar modales ────────────────────────────────────
        document.addEventListener('click', function(e) {
            // Close button triggers
            const closeTrigger = e.target.closest('.ep-close-modal-trigger');
            if (closeTrigger) {
                const modalId = closeTrigger.getAttribute('data-modal');
                if (modalId) closeModal(modalId);
                else {
                    // Cerrar todos los modales de contratos
                    ['epContratoModal', 'epContratoFormModal'].forEach(closeModal);
                }
            }
            // Click on backdrop
            if (e.target.id === 'epContratoModal' || e.target.id === 'epContratoFormModal') {
                closeModal(e.target.id);
            }
        });

        // ── Filtro de año ─────────────────────────────────────
        const yearFilter = document.getElementById('epContratosYearFilter');
        if (yearFilter) {
            yearFilter.addEventListener('change', () => loadContratos(1));
        }

        // ── Carga inicial ─────────────────────────────────────
        loadContratos(1);
    });

})();
</script>
