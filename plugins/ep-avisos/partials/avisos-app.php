<?php
defined('ABSPATH') || exit;

$is_manager = EP_Avisos::can_manage_avisos();
$nonce = wp_create_nonce('ep_avisos_nonce');

$current_user = wp_get_current_user();
$user_roles = (array) $current_user->roles;
$user_dept = (string) ($current_user->ep_department ?? '');
$can_view_reads = current_user_can('administrator')
    || in_array('ep_hr', $user_roles)
    || in_array('ep_direction', $user_roles)
    || strpos($user_dept, 'Direcci') !== false
    || strpos($user_dept, 'RRHH') !== false
    || strpos($user_dept, 'Recursos Humanos') !== false
    || $is_manager;

// Extract users list for granular recipient targeting
$all_users = get_users(array(
    'orderby' => 'display_name',
    'order'   => 'ASC'
));

$departments = array();
$users_data  = array();

foreach ($all_users as $u) {
    $dept = get_user_meta($u->ID, 'ep_department', true);
    if (empty($dept)) $dept = 'General';

    if (!in_array($dept, $departments)) {
        $departments[] = $dept;
    }

    $photo = get_user_meta($u->ID, 'ep_user_photo_url', true);
    if (empty($photo)) {
        $photo = get_avatar_url($u->ID);
    }

    $users_data[] = array(
        'id'         => $u->ID,
        'name'       => $u->display_name,
        'email'      => $u->user_email,
        'department' => $dept,
        'photo'      => $photo
    );
}
sort($departments);
?>

<script>
window.ep_avisos_vars = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo $nonce; ?>',
    can_view_reads: <?php echo $can_view_reads ? 'true' : 'false'; ?>,
    current_user_id: <?php echo get_current_user_id(); ?>
};
window.ep_avisos_users = <?php echo json_encode($users_data); ?>;
</script>

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

    <!-- Premium Modal for Creation / Editing Form -->
    <?php if ($is_manager): ?>
        <div id="create-aviso-modal" class="ep-modal">
            <div class="ep-modal-content ep-modal-premium">
                <div class="ep-modal-header">
                    <div class="ep-modal-title-group">
                        <div class="ep-modal-icon-badge">
                            <i class="fa-solid fa-paper-plane" id="modal-icon"></i>
                        </div>
                        <div>
                            <h2 id="modal-form-title">Crear Nuevo Aviso</h2>
                            <p class="ep-modal-subtitle" id="modal-form-subtitle">Publica comunicados internos segmentados o para toda la plantilla</p>
                        </div>
                    </div>
                    <span class="close-modal">&times;</span>
                </div>

                <form id="form-create-aviso" enctype="multipart/form-data" class="ep-modal-form">
                    <input type="hidden" name="action" value="ep_create_aviso">
                    <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                    <input type="hidden" name="aviso_id" id="aviso_id" value="">

                    <div class="ep-form-grid-two-cols" style="gap: 16px;">
                        <div class="ep-form-group">
                            <label for="title"><i class="fa-solid fa-heading"></i> Título del Aviso</label>
                            <input type="text" name="title" id="title" class="ep-input" required
                                placeholder="Ej: Nueva política de vacaciones o comunicado interno...">
                        </div>
                        <div class="ep-form-group">
                            <label for="expiry_date"><i class="fa-solid fa-calendar-days"></i> Fecha de Caducidad (Obligatoria)</label>
                            <input type="date" name="expiry_date" id="expiry_date" class="ep-input" required
                                min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="ep-form-group">
                        <label for="content"><i class="fa-solid fa-align-left"></i> Contenido del Aviso</label>
                        <textarea name="content" id="content" class="ep-input" rows="5" required
                            placeholder="Redacta el aviso detalladamente con toda la información necesaria..."></textarea>
                    </div>

                    <!-- Granular Recipient Selector Section -->
                    <div class="ep-recipient-section">
                        <div class="ep-recipient-header">
                            <div>
                                <h3 class="ep-recipient-title"><i class="fa-solid fa-users-gear"></i> Destinatarios y Alcance del Aviso</h3>
                                <p class="ep-recipient-desc">Filtra por departamento o busca y selecciona empleados individualmente</p>
                            </div>
                            <div class="ep-recipient-summary-pill" id="recipient-summary-badge">
                                📢 <span id="recipient-summary-text">Toda la plantilla (<?php echo count($users_data); ?> empleados)</span>
                            </div>
                        </div>

                        <div class="ep-recipient-controls">
                            <div class="ep-dept-filter-wrap">
                                <label for="target_department"><i class="fa-solid fa-building"></i> Departamento:</label>
                                <select name="target_department" id="target_department" class="ep-input ep-select-compact">
                                    <option value="">Todos los departamentos</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ep-user-search-wrap">
                                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                                <input type="text" id="ep-user-search-input" class="ep-input ep-search-compact" placeholder="Buscar empleado por nombre o email...">
                            </div>

                            <div class="ep-quick-actions">
                                <button type="button" id="btn-select-all-users" class="ep-btn-pill-sm"><i class="fa-solid fa-check-double"></i> Marcar todos</button>
                                <button type="button" id="btn-unselect-all-users" class="ep-btn-pill-sm danger"><i class="fa-solid fa-xmark"></i> Desmarcar</button>
                            </div>
                        </div>

                        <!-- Scrollable Employee Selection Cards Grid -->
                        <div class="ep-recipient-grid-wrap">
                            <div id="ep-recipient-grid" class="ep-recipient-grid">
                                <?php foreach ($users_data as $u): ?>
                                    <label class="ep-recipient-card selected" data-user-id="<?php echo $u['id']; ?>" data-dept="<?php echo esc_attr($u['department']); ?>" data-search="<?php echo esc_attr(strtolower($u['name'] . ' ' . $u['email'] . ' ' . $u['department'])); ?>">
                                        <input type="checkbox" name="target_users[]" value="<?php echo $u['id']; ?>" class="ep-user-checkbox" checked>
                                        <img src="<?php echo esc_url($u['photo']); ?>" class="ep-recipient-avatar" alt="<?php echo esc_attr($u['name']); ?>">
                                        <div class="ep-recipient-info">
                                            <span class="ep-recipient-name"><?php echo esc_html($u['name']); ?></span>
                                            <span class="ep-recipient-dept"><?php echo esc_html($u['department']); ?></span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="ep-form-group" style="margin-top:14px;">
                        <label><i class="fa-solid fa-paperclip"></i> Documentos Adjuntos (Máximo 3)</label>
                        <div id="drop-zone" class="ep-drop-zone">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p><strong>Arrastra archivos aquí</strong> o haz clic para examinar</p>
                            <span class="ep-drop-hint">PDF, DOCX, JPG, PNG (Máx. 3 archivos)</span>
                            <input type="file" name="files[]" id="file-input" multiple accept=".pdf,.doc,.docx,.jpg,.png" style="display:none">
                        </div>
                        <div id="file-list" class="ep-file-list"></div>
                    </div>

                    <div class="ep-modal-footer">
                        <button type="button" class="ep-btn ep-btn-secondary close-modal">Cancelar</button>
                        <button type="submit" id="btn-submit-aviso" class="ep-btn ep-btn-primary" style="padding: 12px 28px; border-radius: 12px; font-weight: 600;">
                            <i class="fa-solid fa-paper-plane" id="btn-submit-icon"></i> <span id="btn-submit-text">Publicar Aviso</span>
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
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        border-left: 4px solid #0078d4;
        transition: transform 0.22s ease, box-shadow 0.22s ease;
    }

    .aviso-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 120, 212, 0.12);
    }

    .aviso-card h3 {
        margin-top: 0;
        color: #1e293b;
        font-size: 1.15rem;
        font-weight: 700;
    }

    .aviso-card .excerpt {
        color: #64748b;
        font-size: 0.9rem;
        margin-bottom: 15px;
        flex-grow: 1;
        line-height: 1.5;
    }

    .aviso-card .meta {
        font-size: 0.8rem;
        color: #94a3b8;
        margin-bottom: 10px;
    }

    /* Modal Styling */
    .ep-modal {
        display: none;
        position: fixed;
        z-index: 2000000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .ep-modal-content {
        background-color: #ffffff;
        margin: 30px auto;
        padding: 28px;
        border-radius: 20px;
        width: 95%;
        max-width: 840px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        position: relative;
        max-height: 90vh;
        overflow-y: auto;
        border: 1px solid rgba(226, 232, 240, 0.8);
    }

    /* Premium Creation Modal Specifics */
    .ep-modal-premium {
        padding: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        max-height: 90vh;
    }

    .ep-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 22px 28px;
        background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
        color: #ffffff;
    }

    .ep-modal-title-group {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .ep-modal-icon-badge {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: #ffffff;
    }

    .ep-modal-header h2 {
        margin: 0;
        font-size: 1.35rem;
        font-weight: 700;
        color: #ffffff;
    }

    .ep-modal-subtitle {
        margin: 2px 0 0 0;
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.85);
    }

    .ep-modal-header .close-modal {
        position: static;
        font-size: 28px;
        color: rgba(255, 255, 255, 0.8);
        transition: color 0.2s;
        cursor: pointer;
        line-height: 1;
    }

    .ep-modal-header .close-modal:hover {
        color: #ffffff;
    }

    .ep-modal-form {
        padding: 24px 28px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .ep-form-grid-two-cols {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 16px;
    }

    .ep-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .ep-form-group label {
        font-size: 0.88rem;
        font-weight: 600;
        color: #334155;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .ep-form-group label i {
        color: #0078d4;
    }

    .ep-input {
        width: 100%;
        padding: 10px 14px;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        font-size: 0.92rem;
        transition: border-color 0.2s, box-shadow 0.2s;
        box-sizing: border-box;
        outline: none;
        background: #ffffff;
        color: #1e293b;
    }

    .ep-input:focus {
        border-color: #0078d4;
        box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.15);
    }

    /* Recipient Selector Section */
    .ep-recipient-section {
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px 18px;
        margin-top: 4px;
    }

    .ep-recipient-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }

    .ep-recipient-title {
        margin: 0;
        font-size: 0.98rem;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ep-recipient-title i {
        color: #0078d4;
    }

    .ep-recipient-desc {
        margin: 2px 0 0 0;
        font-size: 0.8rem;
        color: #64748b;
    }

    .ep-recipient-summary-pill {
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.82rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .ep-recipient-controls {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }

    .ep-dept-filter-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        color: #475569;
        font-weight: 600;
    }

    .ep-select-compact {
        padding: 6px 10px !important;
        font-size: 0.82rem !important;
        border-radius: 8px !important;
    }

    .ep-user-search-wrap {
        position: relative;
        flex: 1;
        min-width: 200px;
    }

    .ep-user-search-wrap .search-icon {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.82rem;
    }

    .ep-search-compact {
        padding-left: 30px !important;
        padding-top: 6px !important;
        padding-bottom: 6px !important;
        font-size: 0.82rem !important;
        border-radius: 8px !important;
    }

    .ep-quick-actions {
        display: flex;
        gap: 6px;
    }

    .ep-btn-pill-sm {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        color: #334155;
        padding: 5px 10px;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .ep-btn-pill-sm:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .ep-btn-pill-sm.danger:hover {
        background: #fee2e2;
        color: #991b1b;
        border-color: #fca5a5;
    }

    .ep-recipient-grid-wrap {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 8px;
        background: #ffffff;
    }

    .ep-recipient-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 8px;
    }

    .ep-recipient-card {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        padding: 7px 10px;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
    }

    .ep-recipient-card:hover {
        border-color: #cbd5e1;
        background: #f1f5f9;
    }

    .ep-recipient-card.selected {
        border-color: #0078d4;
        background: rgba(0, 120, 212, 0.05);
    }

    .ep-recipient-card input[type="checkbox"] {
        accent-color: #0078d4;
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    .ep-recipient-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        border: 1.5px solid #0078d4;
    }

    .ep-recipient-info {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .ep-recipient-name {
        font-size: 0.83rem;
        font-weight: 600;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ep-recipient-dept {
        font-size: 0.73rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Drop Zone Redesign */
    .ep-drop-zone {
        border: 2px dashed #0078d4;
        padding: 16px 20px;
        text-align: center;
        border-radius: 12px;
        background: rgba(0, 120, 212, 0.03);
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .ep-drop-zone:hover {
        background: rgba(0, 120, 212, 0.08);
        border-color: #005a9e;
    }

    .ep-drop-zone i {
        font-size: 1.8rem;
        color: #0078d4;
        margin-bottom: 4px;
    }

    .ep-drop-zone p {
        margin: 0;
        font-size: 0.88rem;
        color: #334155;
    }

    .ep-drop-hint {
        font-size: 0.76rem;
        color: #64748b;
        display: block;
        margin-top: 2px;
    }

    .ep-file-list {
        margin-top: 8px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .file-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f1f5f9;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    .remove-file {
        color: #ef4444;
        cursor: pointer;
        font-size: 16px;
        font-weight: 700;
        padding: 0 4px;
    }

    .ep-modal-footer {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        padding-top: 14px;
        border-top: 1px solid #e2e8f0;
        margin-top: 10px;
    }

    /* Dark Mode Adjustments */
    #ep-app-root.dark-mode .ep-modal-content {
        background-color: #0f172a;
        color: #f8fafc;
        border-color: #334155;
    }

    #ep-app-root.dark-mode .ep-recipient-section {
        background: #1e293b;
        border-color: #334155;
    }

    #ep-app-root.dark-mode .ep-recipient-title {
        color: #f8fafc;
    }

    #ep-app-root.dark-mode .ep-recipient-card {
        background: #0f172a;
        border-color: #334155;
    }

    #ep-app-root.dark-mode .ep-recipient-card:hover {
        background: #1e293b;
    }

    #ep-app-root.dark-mode .ep-recipient-name {
        color: #f8fafc;
    }

    #ep-app-root.dark-mode .ep-input {
        background: #1e293b;
        border-color: #475569;
        color: #f8fafc;
    }

    #ep-app-root.dark-mode .ep-recipient-grid-wrap {
        background: #0f172a;
        border-color: #334155;
    }

    #ep-app-root.dark-mode .ep-form-group label {
        color: #cbd5e1;
    }

    @media screen and (max-width: 768px) {
        .ep-form-grid-two-cols {
            grid-template-columns: 1fr;
        }
        .ep-recipient-controls {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>

<script>
    function initAvisosApp() {
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
        let currentLoadedAvisos = [];

        // Recipient Selection Logic
        const deptSelect = document.getElementById('target_department');
        const searchInput = document.getElementById('ep-user-search-input');
        const btnSelectAll = document.getElementById('btn-select-all-users');
        const btnUnselectAll = document.getElementById('btn-unselect-all-users');
        const recipientSummaryText = document.getElementById('recipient-summary-text');
        const recipientCards = document.querySelectorAll('#ep-recipient-grid .ep-recipient-card');
        const totalUsersCount = recipientCards.length;

        function updateRecipientSummary() {
            const checkedCount = document.querySelectorAll('#ep-recipient-grid .ep-user-checkbox:checked').length;
            if (checkedCount === totalUsersCount) {
                recipientSummaryText.textContent = `Toda la plantilla (${totalUsersCount} empleados)`;
            } else if (checkedCount === 0) {
                recipientSummaryText.textContent = `Ningún destinatario (Aviso privado)`;
            } else {
                recipientSummaryText.textContent = `${checkedCount} de ${totalUsersCount} empleados seleccionados`;
            }
        }

        // Toggle card selected class when checkbox changes
        recipientCards.forEach(card => {
            const cb = card.querySelector('.ep-user-checkbox');
            cb.addEventListener('change', function () {
                card.classList.toggle('selected', this.checked);
                updateRecipientSummary();
            });
        });

        // Department dropdown sync
        if (deptSelect) {
            deptSelect.addEventListener('change', function () {
                const selectedDept = this.value.trim().toLowerCase();
                recipientCards.forEach(card => {
                    const cardDept = (card.dataset.dept || '').toLowerCase();
                    const cb = card.querySelector('.ep-user-checkbox');
                    if (!selectedDept || cardDept === selectedDept) {
                        cb.checked = true;
                        card.classList.add('selected');
                    } else {
                        cb.checked = false;
                        card.classList.remove('selected');
                    }
                });
                updateRecipientSummary();
            });
        }

        // Live search filtering of cards
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const q = this.value.toLowerCase().trim();
                recipientCards.forEach(card => {
                    const searchData = card.dataset.search || '';
                    if (!q || searchData.includes(q)) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }

        // Select all / Unselect all
        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', function () {
                recipientCards.forEach(card => {
                    if (card.style.display !== 'none') {
                        const cb = card.querySelector('.ep-user-checkbox');
                        cb.checked = true;
                        card.classList.add('selected');
                    }
                });
                updateRecipientSummary();
            });
        }

        if (btnUnselectAll) {
            btnUnselectAll.addEventListener('click', function () {
                recipientCards.forEach(card => {
                    if (card.style.display !== 'none') {
                        const cb = card.querySelector('.ep-user-checkbox');
                        cb.checked = false;
                        card.classList.remove('selected');
                    }
                });
                updateRecipientSummary();
            });
        }

        function resetFormState() {
            if (!form) return;
            form.reset();
            const avisoIdInput = document.getElementById('aviso_id');
            if (avisoIdInput) avisoIdInput.value = '';
            
            const formTitle = document.getElementById('modal-form-title');
            if (formTitle) formTitle.textContent = 'Crear Nuevo Aviso';

            const formSub = document.getElementById('modal-form-subtitle');
            if (formSub) formSub.textContent = 'Publica comunicados internos segmentados o para toda la plantilla';

            const submitText = document.getElementById('btn-submit-text');
            if (submitText) submitText.textContent = 'Publicar Aviso';

            selectedFiles = [];
            updateFileList();

            recipientCards.forEach(card => {
                const cb = card.querySelector('.ep-user-checkbox');
                cb.checked = true;
                card.classList.add('selected');
                card.style.display = 'flex';
            });
            if (searchInput) searchInput.value = '';
            if (deptSelect) deptSelect.value = '';
            updateRecipientSummary();
        }

        // Open Edit Modal for a specific notice
        function openEditAvisoModal(aviso) {
            if (!createModal || !form) return;
            avisoModal.style.display = 'none';

            document.getElementById('aviso_id').value = aviso.id;
            document.getElementById('modal-form-title').textContent = 'Editar Aviso';
            document.getElementById('modal-form-subtitle').textContent = 'Modifica el contenido, fecha o destinatarios de tu aviso';
            document.getElementById('btn-submit-text').textContent = 'Guardar Cambios';

            document.getElementById('title').value = aviso.title || '';
            document.getElementById('content').value = aviso.content || '';
            document.getElementById('expiry_date').value = aviso.expiry_date || '';

            if (deptSelect) {
                deptSelect.value = aviso.target_department || '';
            }

            const tUsers = (aviso.target_users || []).map(id => String(id));
            const tDept = (aviso.target_department || '').toLowerCase().trim();

            recipientCards.forEach(card => {
                const cb = card.querySelector('.ep-user-checkbox');
                const userId = String(card.dataset.userId || cb.value);
                const cardDept = (card.dataset.dept || '').toLowerCase().trim();

                if (tUsers.length > 0) {
                    cb.checked = tUsers.includes(userId);
                } else if (tDept) {
                    cb.checked = (cardDept === tDept);
                } else {
                    cb.checked = true;
                }
                card.classList.toggle('selected', cb.checked);
                card.style.display = 'flex';
            });

            if (searchInput) searchInput.value = '';
            updateRecipientSummary();

            selectedFiles = [];
            updateFileList();
            createModal.style.display = 'block';
        }

        // Load Avisos
        function loadAvisos(type = 'active') {
            avisosList.innerHTML = '<div class="loading-spinner"><i class="fa-solid fa-spinner fa-spin"></i> Cargando avisos...</div>';
            fetch(`<?php echo admin_url('admin-ajax.php'); ?>?action=ep_get_avisos&security=<?php echo $nonce; ?>&type=${type}`)
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`Error en la solicitud al servidor (${res.status})`);
                    }
                    return res.text();
                })
                .then(text => {
                    let data;
                    try {
                        data = JSON.parse(text.trim());
                    } catch (e) {
                        throw new Error('Respuesta inválida del servidor');
                    }
                    if (data && data.success) {
                        currentLoadedAvisos = data.data || [];
                        renderAvisos(currentLoadedAvisos);
                    } else {
                        const errMsg = (data && data.data) ? data.data : 'Error desconocido al cargar avisos.';
                        avisosList.innerHTML = `<div class="no-data" style="color:#ef4444; padding:24px; text-align:center;"><i class="fa-solid fa-triangle-exclamation" style="font-size:1.5rem; margin-bottom:8px; display:block;"></i> ${errMsg}</div>`;
                    }
                })
                .catch(err => {
                    console.error('[EP_Avisos] Error cargando avisos:', err);
                    avisosList.innerHTML = `
                        <div class="no-data" style="color:#ef4444; padding:24px; text-align:center;">
                            <i class="fa-solid fa-circle-exclamation" style="font-size:1.8rem; margin-bottom:8px; display:block;"></i>
                            <strong>No se pudieron cargar los avisos.</strong><br>
                            <span style="font-size:13px; color:#64748b;">Comprueba tu conexión o vuelve a intentarlo.</span><br>
                            <button type="button" class="ep-btn ep-btn-secondary ep-btn-sm" style="margin-top:12px; cursor:pointer;" onclick="location.reload();">
                                <i class="fa-solid fa-rotate-right"></i> Recargar página
                            </button>
                        </div>
                    `;
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
                
                const readsBtnHeader = window.ep_avisos_vars.can_view_reads 
                    ? `<button type="button" class="ep-btn ep-btn-secondary ep-btn-sm ep-view-aviso-reads-btn" data-id="${aviso.id}" style="font-size:11px; padding:3px 8px; border-radius:12px; background:#e0f2fe; color:#0369a1; border:none; font-weight:600; cursor:pointer;" title="Ver qué empleados han leído este aviso"><i class="fa-solid fa-users"></i> Lecturas (${aviso.read_count || 0})</button>` 
                    : '';

                const editBtnCard = aviso.can_edit 
                    ? `<button type="button" class="ep-btn ep-btn-secondary ep-btn-sm ep-edit-aviso-btn" data-id="${aviso.id}" style="font-size:12px; padding:4px 10px; background:#fef3c7; color:#b45309; border:none; font-weight:600; cursor:pointer; border-radius:8px;" title="Editar mi aviso"><i class="fa-solid fa-pen-to-square"></i> Editar</button>` 
                    : '';

                card.innerHTML = `
                <div class="meta" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <span>${aviso.date} ${isExpired ? '<span class="expired-badge">Caducado</span>' : ''}</span>
                    ${readsBtnHeader}
                </div>
                <h3>${aviso.title}</h3>
                <div class="excerpt">${aviso.excerpt}</div>
                <div style="display:flex; gap:8px; margin-top:14px; align-items:center;">
                    <button class="ep-btn ep-btn-outline ep-btn-sm btn-view-more" data-id="${aviso.id}" style="flex:1;"><i class="fa-solid fa-eye"></i> Ver detalle</button>
                    ${editBtnCard}
                    ${window.ep_avisos_vars.can_view_reads ? `<button class="ep-btn ep-btn-secondary ep-btn-sm ep-view-aviso-reads-btn" data-id="${aviso.id}" style="font-size:12px; padding:4px 10px;" title="Ver lecturas"><i class="fa-solid fa-users"></i> Lecturas</button>` : ''}
                </div>
            `;
                card.querySelector('.btn-view-more').addEventListener('click', () => showAvisoDetails(aviso));
                
                const cardEditBtn = card.querySelector('.ep-edit-aviso-btn');
                if (cardEditBtn) {
                    cardEditBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openEditAvisoModal(aviso);
                    });
                }

                avisosList.appendChild(card);
            });
        }

        function showAvisoDetails(aviso) {
            // Auto-registrar la lectura del empleado al abrir el detalle del aviso
            if (aviso.id) {
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'ep_avisos_mark_read',
                        aviso_id: aviso.id,
                        nonce: window.ep_avisos_vars.nonce
                    })
                }).catch(e => console.error('[EP_Avisos] Error marcando lectura:', e));
            }

            let attachmentsHtml = '';
            if (aviso.attachments && aviso.attachments.length > 0) {
                attachmentsHtml = '<div class="aviso-attachments" style="margin-top:15px; background:#f8fafc; padding:12px; border-radius:8px;"><strong><i class="fa-solid fa-paperclip"></i> Adjuntos:</strong><br>';
                aviso.attachments.forEach(att => {
                    attachmentsHtml += `<a href="${att.url}" target="_blank" class="attachment-link" style="display:inline-flex; align-items:center; gap:6px; margin-top:6px; background:#fff; padding:6px 12px; border:1px solid #e2e8f0; border-radius:6px;"><i class="fa-solid fa-file-lines" style="color:#0078d4;"></i> ${att.name}</a> `;
                });
                attachmentsHtml += '</div>';
            }

            let readsBtnDetail = '';
            if (window.ep_avisos_vars.can_view_reads) {
                readsBtnDetail = `<button type="button" class="ep-btn ep-btn-sm ep-view-aviso-reads-btn" data-id="${aviso.id}" style="background:#0284c7; color:#fff; border:none; border-radius:6px; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;"><i class="fa-solid fa-users"></i> Ver Lecturas (${aviso.read_count || 0})</button>`;
            }

            let editBtnDetail = '';
            if (aviso.can_edit) {
                editBtnDetail = `<button type="button" class="ep-btn ep-btn-sm ep-edit-aviso-btn-detail" data-id="${aviso.id}" style="background:#f59e0b; color:#fff; border:none; border-radius:6px; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; margin-right:8px;"><i class="fa-solid fa-pen-to-square"></i> Editar Aviso</button>`;
            }

            modalBody.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <h2 style="margin:0; font-size:1.3rem; color:#1e293b;"><i class="fa-solid fa-bullhorn" style="color:#f59e0b;"></i> ${aviso.title}</h2>
                <div>
                    ${editBtnDetail}
                    ${readsBtnDetail}
                </div>
            </div>
            <div class="meta" style="color:#7f8c8d; margin-bottom:20px; font-size:13px;">
                <i class="fa-solid fa-calendar-days"></i> Publicado el ${aviso.date} por <strong>${aviso.author}</strong> | Caduca el ${aviso.expiry_date}
            </div>
            <div class="full-content" style="line-height:1.6; color:#334155; font-size:14px; margin-bottom:20px;">${aviso.content.replace(/\n/g, '<br>')}</div>
            ${attachmentsHtml}
        `;

            const detailEditBtn = modalBody.querySelector('.ep-edit-aviso-btn-detail');
            if (detailEditBtn) {
                detailEditBtn.addEventListener('click', () => openEditAvisoModal(aviso));
            }

            avisoModal.style.display = 'block';
        }

        // Modal Control
        if (btnNew) {
            btnNew.onclick = () => {
                resetFormState();
                createModal.style.display = 'block';
            };
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
                dropZone.style.background = 'rgba(0, 120, 212, 0.12)';
            };

            dropZone.ondragleave = () => {
                dropZone.style.background = 'rgba(0, 120, 212, 0.03)';
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
                <span><i class="fa-solid fa-file" style="color:#0078d4; margin-right:6px;"></i> ${file.name}</span>
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

                const isEdit = !!document.getElementById('aviso_id').value;
                const btn = form.querySelector('button[type="submit"]');
                const btnText = document.getElementById('btn-submit-text');
                btn.disabled = true;
                if (btnText) btnText.textContent = isEdit ? 'Guardando...' : 'Publicando...';

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.text().then(text => {
                        // WordPress responde "-1"/"0" en texto plano cuando el nonce ha
                        // caducado o cuando PHP descarta el POST por superar post_max_size
                        // (adjuntos demasiado grandes). Sin esto el fallo era silencioso.
                        const trimmed = text.trim();
                        if (trimmed === '-1' || trimmed === '0' || trimmed === '') {
                            throw new Error('SESSION_OR_SIZE');
                        }
                        if (!res.ok && trimmed.length === 0) {
                            throw new Error('SESSION_OR_SIZE');
                        }
                        try {
                            return JSON.parse(trimmed);
                        } catch (e) {
                            throw new Error('BAD_RESPONSE');
                        }
                    }))
                    .then(data => {
                        if (data.success) {
                            alert(data.data);
                            createModal.style.display = 'none';
                            resetFormState();
                            loadAvisos();
                        } else {
                            alert('Error: ' + data.data);
                        }
                    })
                    .catch(err => {
                        console.error('[EP_Avisos] Error publicando aviso:', err);
                        if (err && err.message === 'SESSION_OR_SIZE') {
                            alert('No se ha podido publicar el aviso.\n\nPosibles causas:\n· Tu sesión ha caducado: recarga la página (F5) y vuelve a intentarlo.\n· Los archivos adjuntos superan el tamaño máximo permitido por el servidor: prueba a publicar el aviso sin adjuntos o con ficheros más pequeños.');
                        } else {
                            alert('No se ha podido publicar el aviso. El servidor ha devuelto una respuesta inesperada. Recarga la página e inténtalo de nuevo; si persiste, avisa a Sistemas.');
                        }
                    })
                    .finally(() => {
                        btn.disabled = false;
                        if (btnText) btnText.textContent = isEdit ? 'Guardar Cambios' : 'Publicar Aviso';
                    });
            };
        }

        // Initial Load
        loadAvisos();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAvisosApp);
    } else {
        initAvisosApp();
    }

    function epShowAvisoReads(avisoId) {
        if (!avisoId) {
            alert('No se ha podido identificar el ID del aviso.');
            return;
        }

        // Registrar lectura actual
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ep_avisos_mark_read',
                aviso_id: avisoId,
                nonce: window.ep_avisos_vars.nonce
            })
        }).catch(e => console.error('[EP_Avisos] Error marcando lectura:', e));

        const openSwalModal = () => {
            Swal.fire({
                title: '<i class="fa-solid fa-bullhorn" style="color:#0078d4;"></i> Registro de Lecturas del Comunicado',
                html: '<div id="epSwalAvisoReadsContent" style="padding:10px; text-align:left;"><p style="text-align:center; color:#64748b;"><i class="fa-solid fa-spinner fa-spin"></i> Consultando registros de lectura...</p></div>',
                width: 700,
                showCloseButton: true,
                confirmButtonText: 'Cerrar',
                confirmButtonColor: '#0078d4',
                customClass: {
                    container: 'ep-swal-top-container'
                },
                didOpen: () => {
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'ep_avisos_get_reads',
                            aviso_id: avisoId,
                            nonce: window.ep_avisos_vars.nonce
                        })
                    })
                    .then(r => r.json())
                    .then(res => {
                        const container = document.getElementById('epSwalAvisoReadsContent');
                        if (!container) return;
                        if (res.success && res.data && res.data.length > 0) {
                            let tableHtml = `
                                <p style="font-size:13px; color:#64748b; margin-bottom:12px;"><strong>Total de lecturas registradas:</strong> ${res.data.length} empleados</p>
                                <div style="max-height:350px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;">
                                    <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:left;">
                                        <thead>
                                            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                                <th style="padding:10px;">Empleado</th>
                                                <th style="padding:10px;">Departamento</th>
                                                <th style="padding:10px;">Fecha y Hora</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${res.data.map(r => `
                                                <tr style="border-bottom:1px solid #f1f5f9;">
                                                    <td style="padding:10px;"><strong>${r.name}</strong><br><small style="color:#64748b;">${r.email}</small></td>
                                                    <td style="padding:10px;"><span style="background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600;">${r.dept}</span></td>
                                                    <td style="padding:10px; color:#10b981; font-weight:600;"><i class="fa-solid fa-check-double"></i> ${r.read_at}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            `;
                            container.innerHTML = tableHtml;
                        } else if (res.success) {
                            container.innerHTML = `
                                <div style="text-align:center; padding:30px 10px; color:#94a3b8;">
                                    <i class="fa-solid fa-eye-slash" style="font-size:2.5rem; margin-bottom:10px; color:#cbd5e1;"></i>
                                    <p style="font-size:14px; margin:0; color:#334155; font-weight:600;">Sin lecturas registradas aún</p>
                                    <small style="color:#94a3b8;">Los accesos se registrarán automáticamente cuando los empleados abran o consulten este comunicado.</small>
                                </div>
                            `;
                        } else {
                            container.innerHTML = `<p style="text-align:center; color:#ef4444; padding:20px;">${res.data || 'Error al obtener lecturas'}</p>`;
                        }
                    })
                    .catch(err => {
                        const container = document.getElementById('epSwalAvisoReadsContent');
                        if (container) container.innerHTML = '<p style="text-align:center; color:#ef4444; padding:20px;">Error de conexión con el servidor.</p>';
                    });
                }
            });
        };

        if (typeof Swal === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
            script.onload = openSwalModal;
            document.head.appendChild(script);
        } else {
            openSwalModal();
        }
    }

    // Event delegation for view reads buttons
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.ep-view-aviso-reads-btn');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const avisoId = btn.getAttribute('data-id');
            if (avisoId) {
                epShowAvisoReads(avisoId);
            }
        }
    }, true);
</script>