<?php
defined('ABSPATH') || exit;

$is_manager = EP_Avisos::can_manage_avisos();
$nonce = wp_create_nonce('ep_avisos_nonce');
?>

<div class="ep-avisos-container">
    <div class="ep-section-header">
        <div class="header-content">
            <h1><i class="fa-solid fa-bullhorn"></i> Avisos Generales</h1>
            <p>Comunicaciones y noticias relevantes para toda la plantilla.</p>
        </div>
        <div class="header-actions">
            <?php if ($is_manager): ?>
                <button class="ep-btn ep-btn-secondary" id="btn-history">
                    <i class="fa-solid fa-clock-rotate-left"></i> Ver Historial
                </button>
                <button class="ep-btn ep-btn-primary" id="btn-new-aviso">
                    <i class="fa-solid fa-plus"></i> Nuevo Aviso
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Announcements List -->
    <div id="avisos-list" class="ep-grid ep-grid-3">
        <div class="loading-spinner">Cargando avisos...</div>
    </div>

    <!-- Modal for Detailed View -->
    <div id="aviso-modal" class="ep-modal">
        <div class="ep-modal-content">
            <span class="close-modal">&times;</span>
            <div id="modal-body">
                <!-- Content populated via JS -->
            </div>
        </div>
    </div>

    <!-- Modal for Creation Form -->
    <?php if ($is_manager): ?>
        <div id="create-aviso-modal" class="ep-modal">
            <div class="ep-modal-content">
                <span class="close-modal">&times;</span>
                <h2>Crear Nuevo Aviso</h2>
                <form id="form-create-aviso" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="ep_create_aviso">
                    <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">

                    <div class="ep-form-grid-two-cols">
                        <div class="ep-form-col">
                            <div class="ep-form-group">
                                <label for="title">Título del Aviso</label>
                                <input type="text" name="title" id="title" class="ep-input" required
                                    placeholder="Ej: Nueva política de vacaciones">
                            </div>

                            <div class="ep-form-group">
                                <label for="content">Contenido</label>
                                <textarea name="content" id="content" class="ep-input" rows="8" required
                                    placeholder="Describe el aviso detalladamente..."></textarea>
                            </div>
                        </div>

                        <div class="ep-form-col">
                            <div class="ep-form-group">
                                <label for="expiry_date">Fecha de Caducidad (Obligatoria)</label>
                                <input type="date" name="expiry_date" id="expiry_date" class="ep-input" required
                                    min="<?php echo date('Y-m-d'); ?>">
                                <small>El aviso dejará de ser visible tras esta fecha.</small>
                            </div>

                            <div class="ep-form-group">
                                <label>Documentos Adjuntos (Máximo 3)</label>
                                <div id="drop-zone" class="ep-drop-zone">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                    <p>Arrastra o haz clic</p>
                                    <input type="file" name="files[]" id="file-input" multiple
                                        accept=".pdf,.doc,.docx,.jpg,.png" style="display:none">
                                </div>
                                <div id="file-list" class="ep-file-list"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions"
                        style="margin-top: 20px; text-align: right; border-top: 1px solid #eee; padding-top: 15px;">
                        <button type="submit" class="ep-btn ep-btn-primary" style="width: auto; padding: 12px 30px;">
                            <i class="fa-solid fa-paper-plane"></i> Publicar Aviso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .ep-avisos-container {
        padding: 20px;
    }

    .ep-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }

    .ep-grid {
        display: grid;
        gap: 20px;
    }

    .ep-grid-3 {
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    }

    .aviso-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        border-left: 4px solid #f39c12;
        transition: transform 0.2s;
    }

    .aviso-card:hover {
        transform: translateY(-5px);
    }

    .aviso-card h3 {
        margin-top: 0;
        color: #2c3e50;
        font-size: 1.2rem;
    }

    .aviso-card .excerpt {
        color: #7f8c8d;
        font-size: 0.9rem;
        margin-bottom: 15px;
        flex-grow: 1;
    }

    .aviso-card .meta {
        font-size: 0.8rem;
        color: #bdc3c7;
        margin-bottom: 10px;
    }

    /* Modal Styles */
    .ep-modal {
        display: none;
        position: fixed;
        z-index: 2000000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
    }

    .ep-modal-content {
        background-color: white;
        margin: 20px auto;
        padding: 30px;
        border-radius: 15px;
        width: 95%;
        max-width: 800px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        position: relative;
        max-height: 85vh;
        overflow-y: auto;
    }

    .ep-form-grid-two-cols {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 25px;
    }

    .ep-form-col {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    @media screen and (max-width: 768px) {
        .ep-form-grid-two-cols {
            grid-template-columns: 1fr;
        }
    }

    .close-modal {
        position: absolute;
        right: 20px;
        top: 15px;
        font-size: 28px;
        cursor: pointer;
        color: #7f8c8d;
    }

    /* Drop Zone */
    .ep-drop-zone {
        border: 2px dashed #3498db;
        padding: 20px;
        text-align: center;
        border-radius: 10px;
        background: #f7fbff;
        cursor: pointer;
        transition: background 0.3s;
    }

    .ep-drop-zone:hover {
        background: #ebf5ff;
    }

    .ep-drop-zone i {
        font-size: 2rem;
        color: #3498db;
        margin-bottom: 10px;
    }

    .ep-file-list {
        margin-top: 10px;
    }

    .file-item {
        display: flex;
        justify-content: space-between;
        background: #f8f9fa;
        padding: 8px 12px;
        border-radius: 5px;
        margin-bottom: 5px;
        font-size: 0.9rem;
    }

    .remove-file {
        color: #e74c3c;
        cursor: pointer;
    }

    .aviso-attachments {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #eee;
    }

    .attachment-link {
        display: inline-block;
        padding: 5px 10px;
        background: #f1f8ff;
        border-radius: 4px;
        margin-right: 10px;
        margin-bottom: 5px;
        text-decoration: none;
        font-size: 0.85rem;
        color: #0366d6;
    }

    .expired-badge {
        background: #e74c3c;
        color: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.7rem;
        text-transform: uppercase;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const avisosList = document.getElementById('avisos-list');
        const avisoModal = document.getElementById('aviso-modal');
        const createModal = document.getElementById('create-aviso-modal');
        const modalBody = document.getElementById('modal-body');
        const btnNew = document.getElementById('btn-new-aviso');
        const btnHistory = document.getElementById('btn-history');
        const form = document.getElementById('form-create-aviso');
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const fileListDisplay = document.getElementById('file-list');

        let selectedFiles = [];

        // Load Avisos
        function loadAvisos(type = 'active') {
            avisosList.innerHTML = '<div class="loading-spinner">Cargando avisos...</div>';
            fetch(`<?php echo admin_url('admin-ajax.php'); ?>?action=ep_get_avisos&security=<?php echo $nonce; ?>&type=${type}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        renderAvisos(data.data);
                    }
                });
        }

        function renderAvisos(avisos) {
            if (avisos.length === 0) {
                avisosList.innerHTML = '<div class="no-data">No hay avisos registrados.</div>';
                return;
            }
            avisosList.innerHTML = '';
            avisos.forEach(aviso => {
                const isExpired = new Date(aviso.expiry_date) < new Date().setHours(0, 0, 0, 0);
                const card = document.createElement('div');
                card.className = 'aviso-card';
                card.innerHTML = `
                <div class="meta">
                    ${aviso.date} ${isExpired ? '<span class="expired-badge">Caducado</span>' : ''}
                </div>
                <h3>${aviso.title}</h3>
                <div class="excerpt">${aviso.excerpt}</div>
                <button class="ep-btn ep-btn-outline ep-btn-sm btn-view-more" data-id="${aviso.id}">Ver más</button>
            `;
                card.querySelector('.btn-view-more').addEventListener('click', () => showAvisoDetails(aviso));
                avisosList.appendChild(card);
            });
        }

        function showAvisoDetails(aviso) {
            let attachmentsHtml = '';
            if (aviso.attachments && aviso.attachments.length > 0) {
                attachmentsHtml = '<div class="aviso-attachments"><strong>Adjuntos:</strong><br>';
                aviso.attachments.forEach(att => {
                    attachmentsHtml += `<a href="${att.url}" target="_blank" class="attachment-link"><i class="fa-solid fa-file-lines"></i> ${att.name}</a>`;
                });
                attachmentsHtml += '</div>';
            }

            modalBody.innerHTML = `
            <h2>${aviso.title}</h2>
            <div class="meta" style="color:#7f8c8d; margin-bottom:20px;">
                Publicado el ${aviso.date} por ${aviso.author} | Caduca el ${aviso.expiry_date}
            </div>
            <div class="full-content">${aviso.content.replace(/\n/g, '<br>')}</div>
            ${attachmentsHtml}
        `;
            avisoModal.style.display = 'block';
        }

        // Modal Control
        if (btnNew) {
            btnNew.onclick = () => createModal.style.display = 'block';
        }

        if (btnHistory) {
            btnHistory.onclick = () => {
                const type = btnHistory.getAttribute('data-type') || 'history';
                if (type === 'history') {
                    loadAvisos('history');
                    btnHistory.innerHTML = '<i class="fa-solid fa-bullhorn"></i> Ver Activos';
                    btnHistory.setAttribute('data-type', 'active');
                } else {
                    loadAvisos('active');
                    btnHistory.innerHTML = '<i class="fa-solid fa-clock-rotate-left"></i> Ver Historial';
                    btnHistory.setAttribute('data-type', 'history');
                }
            };
        }

        document.querySelectorAll('.close-modal').forEach(btn => {
            btn.onclick = function () {
                avisoModal.style.display = 'none';
                if (createModal) createModal.style.display = 'none';
            }
        });

        window.onclick = function (event) {
            if (event.target == avisoModal) avisoModal.style.display = 'none';
            if (event.target == createModal) createModal.style.display = 'none';
        }

        // Drag & Drop
        if (dropZone) {
            dropZone.onclick = () => fileInput.click();

            dropZone.ondragover = (e) => {
                e.preventDefault();
                dropZone.style.background = '#ebf5ff';
            };

            dropZone.ondragleave = () => {
                dropZone.style.background = '#f7fbff';
            };

            dropZone.ondrop = (e) => {
                e.preventDefault();
                addFiles(e.dataTransfer.files);
            };

            fileInput.onchange = (e) => {
                addFiles(e.target.files);
            };
        }

        function addFiles(files) {
            for (let file of files) {
                if (selectedFiles.length >= 3) {
                    alert('Máximo 3 archivos permitidos');
                    break;
                }
                selectedFiles.push(file);
            }
            updateFileList();
        }

        function updateFileList() {
            fileListDisplay.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const div = document.createElement('div');
                div.className = 'file-item';
                div.innerHTML = `
                <span>${file.name}</span>
                <span class="remove-file" data-index="${index}">&times;</span>
            `;
                div.querySelector('.remove-file').onclick = () => {
                    selectedFiles.splice(index, 1);
                    updateFileList();
                };
                fileListDisplay.appendChild(div);
            });
        }

        // Form Submit
        if (form) {
            form.onsubmit = function (e) {
                e.preventDefault();
                const formData = new FormData(form);

                // Add custom selected files
                formData.delete('files[]');
                selectedFiles.forEach(file => {
                    formData.append('files[]', file);
                });

                const btn = form.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.innerText = 'Publicando...';

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.data);
                            createModal.style.display = 'none';
                            form.reset();
                            selectedFiles = [];
                            updateFileList();
                            loadAvisos();
                        } else {
                            alert('Error: ' + data.data);
                        }
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerText = 'Publicar Aviso';
                    });
            };
        }

        // Initial Load
        loadAvisos();
    });
</script>