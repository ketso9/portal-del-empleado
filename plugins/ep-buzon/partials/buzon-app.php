<?php

defined('ABSPATH') || exit;

global $ep_app_manager;
$current_user_id = get_current_user_id();
$permission  = $ep_app_manager->get_user_permission('buzon', $current_user_id);

// Jerarquía: Matriz/Overrides mandan. Solo administradores técnicos retienen bypass.
$is_manager  = ($permission === 'write') || current_user_can('administrator');

// Bloqueo total si no tiene ni lectura
if ($permission === 'none' && !current_user_can('administrator')) {
    echo '<div class="ep-alert error"><i class="fa-solid fa-lock"></i> No tienes permiso para acceder al Buzón del Empleado.</div>';
    return;
}

$types = array(
    'suggestion'   => array('label' => '💡 Sugerencia',           'desc' => 'Ideas para mejorar el trabajo o el ambiente'),
    'incident'     => array('label' => '🛠️ Incidencia',           'desc' => 'Algo técnico o físico que no funciona'),
    'complaint'    => array('label' => '⚠️ Queja',                'desc' => 'Descontento sobre normas o situaciones'),
    'recognition'  => array('label' => '🌟 Reconocimiento',       'desc' => 'Felicitar a un compañero o equipo'),
    'confidencial' => array('label' => '🚨 Reporte Confidencial', 'desc' => 'Temas graves (acoso, fraude, ética)'),
    'medios'       => array('label' => '💻 Necesidad de Medios',  'desc' => 'Adquirir o actualizar hardware, programas y formación'),
);

// Obtener mensajes para manager
$active_messages   = $is_manager ? EP_Buzon::get_messages_for_manager(false) : [];
$archived_messages = $is_manager ? EP_Buzon::get_messages_for_manager(true)  : [];
?>

<div class="ep-buzon-container">
    <div class="ep-view-header">
        <div class="header-content">
            <h1>Buzón Ético y de Sugerencias</h1>
            <p>Tu opinión es fundamental para seguir mejorando juntos.</p>
        </div>
        <?php if ($is_manager): ?>
            <div class="header-actions buzon-nav-actions">
                <button class="ep-btn ep-btn-secondary buzon-nav-btn active" id="btnNavForm" onclick="switchBuzonView('form')">
                    <i class="fa-solid fa-pen-to-square"></i> Nuevo Mensaje
                </button>
                <button class="ep-btn ep-btn-secondary buzon-nav-btn" id="btnNavMessages" onclick="switchBuzonView('messages')">
                    <i class="fa-solid fa-list-check"></i> Ver Mensajes
                    <?php if (count($active_messages) > 0): ?>
                        <span class="buzon-badge"><?php echo count($active_messages); ?></span>
                    <?php endif; ?>
                </button>
                <button class="ep-btn ep-btn-secondary buzon-nav-btn" id="btnNavArchive" onclick="switchBuzonView('archive')">
                    <i class="fa-solid fa-box-archive"></i> Archivo
                    <?php if (count($archived_messages) > 0): ?>
                        <span class="buzon-badge buzon-badge-muted"><?php echo count($archived_messages); ?></span>
                    <?php endif; ?>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===================== MODO FORMULARIO ===================== -->
    <div id="buzonFormSection" class="ep-section buzon-view-section" style="display:block;">
        <div class="ep-card buzon-intro-card">
            <div class="ep-alert info">
                <i class="fa-solid fa-shield-halved"></i>
                <div>
                    <strong>Compromiso de Confidencialidad</strong>
                    <p>Este mensaje es estrictamente confidencial y solo será recibido por Dirección y RRHH. Puedes elegir enviar el mensaje de forma totalmente anónima.</p>
                </div>
            </div>

            <form id="epBuzonForm" class="ep-form mt-4">
                <?php wp_nonce_field('ep_buzon_nonce', 'nonce'); ?>

                <div class="form-group mb-5">
                    <label>1. Tipo de comunicación (Selecciona una):</label>
                    <div class="buzon-type-grid">
                        <?php foreach ($types as $key => $data): ?>
                            <label class="buzon-type-card">
                                <input type="radio" name="buzon_type" value="<?php echo $key; ?>" required <?php echo ($key === 'suggestion') ? 'checked' : ''; ?>>
                                <div class="type-content">
                                    <span class="type-label"><?php echo $data['label']; ?></span>
                                    <span class="type-desc"><?php echo $data['desc']; ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group mb-5">
                    <label for="buzon_title">2. Título breve del asunto:</label>
                    <input type="text" id="buzon_title" name="buzon_title" placeholder="Ej: Mejora en el proceso de facturación" required>
                </div>

                <div class="form-group mb-5">
                    <label for="buzon_description">3. Descripción detallada:</label>
                    <textarea id="buzon_description" name="buzon_description" rows="5" placeholder="Explica los detalles de forma clara..." required></textarea>
                </div>

                <div class="form-group mb-5">
                    <label for="buzon_proposal">4. Propuesta de solución (Opcional pero recomendado):</label>
                    <textarea id="buzon_proposal" name="buzon_proposal" rows="3" placeholder="¿Cómo crees que se podría arreglar o mejorar?"></textarea>
                </div>

                <div class="form-group mb-5">
                    <div class="ep-checkbox-toggle">
                        <input type="checkbox" id="buzon_include_name" name="buzon_include_name" value="1">
                        <label for="buzon_include_name">Deseo incluir mi nombre en este mensaje (Default: Anónimo)</label>
                    </div>
                    <p class="text-muted small">Si marcas esta casilla, el sistema asociará automáticamente tu nombre de perfil a este mensaje.</p>
                </div>

                <div class="form-actions mt-4">
                    <button type="submit" class="ep-btn ep-btn-primary" id="btnSubmitBuzon">
                        <i class="fa-solid fa-paper-plane"></i> Enviar al Buzón
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODO VER MENSAJES (MANAGERS) ===================== -->
    <?php if ($is_manager): ?>
    <div id="buzonManagerSection" class="ep-section buzon-view-section" style="display:none;">

        <!-- Barra de filtros -->
        <div class="buzon-filter-bar">
            <span class="filter-label"><i class="fa-solid fa-filter"></i> Filtrar por tipo:</span>
            <div class="filter-chips">
                <button class="filter-chip active" data-filter="all" onclick="filterMessages('messages', 'all', this)">Todos</button>
                <?php foreach ($types as $key => $data): ?>
                    <button class="filter-chip" data-filter="<?php echo $key; ?>" onclick="filterMessages('messages', '<?php echo $key; ?>', this)">
                        <?php echo $data['label']; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="messages-grid" id="messagesGrid">
            <?php if ($active_messages):
                foreach ($active_messages as $msg):
                    $user_name = $msg->user_id ? get_userdata($msg->user_id)->display_name : 'Anónimo';
                    $type_data = isset($types[$msg->type]) ? $types[$msg->type] : array('label' => $msg->type, 'desc' => '');
                    ?>
                    <div class="ep-card message-card"
                        data-id="<?php echo $msg->id; ?>"
                        data-type="<?php echo esc_attr($msg->type); ?>"
                        data-msg='<?php echo esc_attr(json_encode($msg)); ?>'
                        data-author="<?php echo esc_attr($user_name); ?>">
                        <div class="message-header">
                            <span class="type-badge <?php echo $msg->type; ?>"><?php echo $type_data['label']; ?></span>
                            <span class="date"><?php echo date('d/m/Y', strtotime($msg->created_at)); ?></span>
                        </div>
                        <h3><?php echo esc_html($msg->title); ?></h3>
                        <p class="excerpt"><?php echo wp_trim_words($msg->description, 15); ?></p>
                        <div class="message-footer">
                            <span class="author"><i class="fa-solid fa-user-circle"></i> <?php echo $user_name; ?></span>
                            <div class="card-actions">
                                <button class="ep-btn ep-btn-sm ep-btn-outline-primary" onclick='openBuzonModal(<?php echo json_encode($msg); ?>, "<?php echo esc_js($user_name); ?>", false)'>
                                    <i class="fa-solid fa-eye"></i> Leer
                                </button>
                                <button class="ep-btn ep-btn-sm ep-btn-archive" title="Archivar mensaje" onclick="archiveMessage(<?php echo $msg->id; ?>, this)">
                                    <i class="fa-solid fa-box-archive"></i> Archivar
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach;
            else: ?>
                <div class="ep-empty-state" id="messagesEmptyState">
                    <i class="fa-solid fa-inbox"></i>
                    <p>No hay mensajes en el buzón actualmente.</p>
                </div>
            <?php endif; ?>
        </div>
        <!-- Empty state dinámico (cuando el filtro no devuelve resultados) -->
        <div class="ep-empty-state buzon-filter-empty" id="messagesFilterEmpty" style="display:none;">
            <i class="fa-solid fa-filter-circle-xmark"></i>
            <p>No hay mensajes de este tipo.</p>
        </div>
    </div>

    <!-- ===================== MODO ARCHIVO (MANAGERS) ===================== -->
    <div id="buzonArchiveSection" class="ep-section buzon-view-section" style="display:none;">

        <!-- Barra de filtros del archivo -->
        <div class="buzon-filter-bar">
            <span class="filter-label"><i class="fa-solid fa-filter"></i> Filtrar por tipo:</span>
            <div class="filter-chips">
                <button class="filter-chip active" data-filter="all" onclick="filterMessages('archive', 'all', this)">Todos</button>
                <?php foreach ($types as $key => $data): ?>
                    <button class="filter-chip" data-filter="<?php echo $key; ?>" onclick="filterMessages('archive', '<?php echo $key; ?>', this)">
                        <?php echo $data['label']; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="messages-grid" id="archiveGrid">
            <?php if ($archived_messages):
                foreach ($archived_messages as $msg):
                    $user_name = $msg->user_id ? get_userdata($msg->user_id)->display_name : 'Anónimo';
                    $type_data = isset($types[$msg->type]) ? $types[$msg->type] : array('label' => $msg->type, 'desc' => '');
                    ?>
                    <div class="ep-card message-card archived-card" data-id="<?php echo $msg->id; ?>" data-type="<?php echo esc_attr($msg->type); ?>">
                        <div class="message-header">
                            <span class="type-badge <?php echo $msg->type; ?>"><?php echo $type_data['label']; ?></span>
                            <span class="date"><?php echo date('d/m/Y', strtotime($msg->created_at)); ?></span>
                        </div>
                        <h3><?php echo esc_html($msg->title); ?></h3>
                        <p class="excerpt"><?php echo wp_trim_words($msg->description, 15); ?></p>
                        <div class="message-footer">
                            <span class="author"><i class="fa-solid fa-user-circle"></i> <?php echo $user_name; ?></span>
                            <div class="card-actions">
                                <button class="ep-btn ep-btn-sm ep-btn-outline-primary" onclick='openBuzonModal(<?php echo json_encode($msg); ?>, "<?php echo esc_js($user_name); ?>", true)'>
                                    <i class="fa-solid fa-eye"></i> Leer
                                </button>
                                <button class="ep-btn ep-btn-sm ep-btn-delete-archived" title="Eliminar permanentemente" onclick="deleteArchivedMessage(<?php echo $msg->id; ?>, this)">
                                    <i class="fa-solid fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach;
            else: ?>
                <div class="ep-empty-state" id="archiveEmptyState">
                    <i class="fa-solid fa-box-archive"></i>
                    <p>No hay mensajes archivados.</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="ep-empty-state buzon-filter-empty" id="archiveFilterEmpty" style="display:none;">
            <i class="fa-solid fa-filter-circle-xmark"></i>
            <p>No hay mensajes archivados de este tipo.</p>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ===================== MODAL DETALLE ===================== -->
<div id="buzonDetailModal" class="buzon-modal-overlay portal-app-modal" style="display:none;">
    <div class="buzon-modal-box">
        <div class="modal-header">
            <h2 id="modalTitle">Detalle del Mensaje</h2>
            <button class="close-modal" onclick="closeBuzonModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-row">
                <strong><i class="fa-solid fa-user"></i> Remitente:</strong> <span id="modalSender"></span>
            </div>
            <div class="detail-row">
                <strong><i class="fa-solid fa-tag"></i> Tipo:</strong> <span id="modalType" class="type-badge"></span>
            </div>
            <div class="detail-row mb-4">
                <strong><i class="fa-solid fa-calendar-days"></i> Fecha:</strong> <span id="modalDate"></span>
            </div>
            <div class="detail-row mb-2" id="modalArchivedRow" style="display:none;">
                <span class="archived-pill"><i class="fa-solid fa-box-archive"></i> Archivado</span>
            </div>
            <hr>
            <div class="content-block">
                <strong>Descripción:</strong>
                <p id="modalDescription" class="mt-2 text-pre"></p>
            </div>
            <div class="content-block mt-4" id="modalProposalBlock">
                <strong>Propuesta de Solución:</strong>
                <p id="modalProposal" class="mt-2 text-pre"></p>
            </div>
        </div>
        <div class="modal-footer" style="justify-content: center; gap: 15px; border-top: 1px solid #f0f0f0; padding: 20px;">
            <button class="ep-btn ep-btn-outline-primary" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Imprimir / PDF
            </button>
            <button class="ep-btn ep-btn-secondary" onclick="closeBuzonModal()">Cerrar</button>
        </div>
    </div>
</div>

<!-- ===================== MODAL CONFIRMACIÓN ELIMINAR ===================== -->
<div id="buzonDeleteConfirmModal" class="buzon-modal-overlay" style="display:none;">
    <div class="buzon-modal-box buzon-confirm-box">
        <div class="modal-header modal-header-danger">
            <h2><i class="fa-solid fa-triangle-exclamation"></i> Eliminar mensaje</h2>
            <button class="close-modal" onclick="closeDeleteConfirm()">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center; padding: 30px 25px;">
            <div class="confirm-icon">
                <i class="fa-solid fa-trash-can"></i>
            </div>
            <p class="confirm-text">¿Estás seguro de que deseas <strong>eliminar permanentemente</strong> este mensaje?</p>
            <p class="confirm-subtext">Esta acción no se puede deshacer. El mensaje será borrado de forma definitiva.</p>
        </div>
        <div class="modal-footer" style="justify-content: center; gap: 15px; border-top: 1px solid #f0f0f0; padding: 20px;">
            <button class="ep-btn ep-btn-danger" id="btnConfirmDelete" onclick="confirmDelete()">
                <i class="fa-solid fa-trash"></i> Sí, eliminar
            </button>
            <button class="ep-btn ep-btn-secondary" onclick="closeDeleteConfirm()">Cancelar</button>
        </div>
    </div>
</div>

<!-- Nonce para acciones de gestor -->
<?php if ($is_manager): ?>
<input type="hidden" id="buzonActionNonce" value="<?php echo wp_create_nonce('ep_buzon_action_nonce'); ?>">
<?php endif; ?>

<style>
/* ── CONTENEDOR ── */
.ep-buzon-container { max-width: 1100px; margin: 0 auto; width: 100%; padding: 20px; }
@media (min-width:1200px) { .ep-buzon-container { width: 75%; } }

/* ── NAVEGACIÓN ── */
.buzon-nav-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.buzon-nav-btn { position: relative; transition: all 0.2s ease; }
.buzon-nav-btn.active {
    background: #a81c24 !important;
    color: #fff !important;
    border-color: #a81c24 !important;
}
.buzon-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: #dc3545; color: #fff; font-size: 0.7rem; font-weight: 700;
    border-radius: 50px; min-width: 20px; height: 20px; padding: 0 5px;
    margin-left: 6px; line-height: 1;
}
.buzon-badge-muted { background: #6c757d; }

/* ── VISTAS ── */
.buzon-view-section { display: none; }

/* ── FILTROS ── */
.buzon-filter-bar {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--card-bg, #fff); border: 1px solid var(--border-color, #eee);
    border-radius: 14px; padding: 14px 18px; margin-bottom: 22px;
}
.filter-label { font-size: 0.85rem; font-weight: 600; color: #6c757d; white-space: nowrap; }
.filter-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.filter-chip { background: transparent; border: 1.5px solid #d0d5dd; border-radius: 50px; padding: 5px 14px; font-size: 0.8rem; font-weight: 500; cursor: pointer; color: #555; transition: all 0.18s ease; }
.filter-chip:hover { border-color: #a81c24; color: #a81c24; font-weight: 700; background: transparent; }
.filter-chip.active { border-color: #a81c24; color: #a81c24; font-weight: 700; background: rgba(168,28,36,0.06); }
.dark-mode .buzon-filter-bar { background: #1a202c; border-color: #2d3748; }
.dark-mode .filter-chip { background: transparent; border-color: #4a5568; color: #a0aec0; }
.dark-mode .filter-chip:hover { border-color: #a81c24; color: #a81c24; font-weight: 700; }
.dark-mode .filter-chip.active { border-color: #a81c24; color: #a81c24; background: rgba(168,28,36,0.12); }

/* ── GRID TARJETAS ── */
.messages-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
.message-card { display: flex; flex-direction: column; height: 100%; border-left: 4px solid #ddd; transition: all 0.2s ease; }
.message-card.hidden-card { display: none !important; }
.archived-card { border-left-color: #6c757d; opacity: 0.92; }
.message-header { display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 0.85rem; }
.message-footer { margin-top: auto; padding-top: 15px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }

/* ── ACCIONES TARJETA ── */
.card-actions { display: flex; gap: 7px; flex-wrap: wrap; }
.ep-btn-sm { padding: 6px 12px; font-size: 0.82rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 5px; }
.ep-btn-outline-primary { border: 1.5px solid #a81c24; color: #a81c24; background: transparent; font-weight: 500; }
.ep-btn-outline-primary:hover { background: #a81c24; color: #fff; }
.ep-btn-archive {
    border: 1.5px solid #6c757d; color: #6c757d; background: transparent; font-weight: 500;
}
.ep-btn-archive:hover { background: #6c757d; color: #fff; }
.ep-btn-delete-archived {
    border: 1.5px solid #dc3545; color: #dc3545; background: transparent; font-weight: 500;
}
.ep-btn-delete-archived:hover { background: #dc3545; color: #fff; }

/* ── BADGES TIPO ── */
.type-badge { font-size: 0.82rem; font-weight: 600; }
.type-badge.suggestion    { color: #b8860b; }
.type-badge.incident      { color: #e67e22; }
.type-badge.complaint     { color: #c0392b; }
.type-badge.recognition   { color: #1abc9c; }
.type-badge.confidencial  { color: #dc3545; font-weight: bold; }
.type-badge.medios        { color: #3498db; }

/* ── EMPTY STATE ── */
.ep-empty-state { text-align: center; padding: 50px 20px; color: #aaa; font-size: 1rem; grid-column: 1 / -1; }
.ep-empty-state i { font-size: 2.5rem; margin-bottom: 15px; display: block; }
.buzon-filter-empty { border: 2px dashed #e0e0e0; border-radius: 14px; margin-top: 10px; }

/* ── PILL ARCHIVADO EN MODAL ── */
.archived-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #f0f0f0; color: #6c757d; font-size: 0.82rem; font-weight: 600;
    border-radius: 50px; padding: 4px 12px;
}
.dark-mode .archived-pill { background: #2d3748; color: #a0aec0; }

/* ── FORMULARIO ── */
.buzon-type-grid { display: grid; grid-template-columns: 1fr; gap: 15px; margin-top: 15px; }
@media (min-width:600px)  { .buzon-type-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width:1024px) { .buzon-type-grid { grid-template-columns: repeat(3, 1fr); } }
.buzon-type-card { border: 2px solid var(--border-color, #eee); border-radius: 12px; padding: 12px; cursor: pointer; transition: all 0.2s ease; display: flex; flex-direction: column; height: 100%; position: relative; }
.buzon-type-card input { position: absolute; opacity: 0; }
.buzon-type-card:hover { border-color: #a81c24; background: var(--hover-bg, #f8f9fa); }
.buzon-type-card:has(input:checked) { border-color: #a81c24; background: var(--active-bg, #e7f1ff); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.type-label { display: block; font-weight: 700; margin-bottom: 5px; font-size: 0.95rem; }
.type-desc { display: block; font-size: 0.75rem; color: #666; line-height: 1.2; }
.ep-checkbox-toggle { display: flex !important; align-items: flex-start !important; gap: 10px !important; cursor: pointer; justify-content: flex-start !important; text-align: left !important; width: 100% !important; margin: 15px 0 !important; }
.ep-checkbox-toggle input { width: 1.4rem !important; height: 1.4rem !important; cursor: pointer; flex-shrink: 0; margin: 2px 0 0 0 !important; padding: 0 !important; }
.ep-checkbox-toggle label { cursor: pointer; font-weight: 500; font-size: 1rem; line-height: 1.4; margin: 0 0 0 8px !important; white-space: normal !important; width: auto !important; display: block !important; }
.form-group { margin-bottom: 45px !important; text-align: left !important; }

/* ── MODAL DETALLE ── */
.buzon-modal-overlay { position: fixed !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; background: rgba(0,0,0,0.6) !important; backdrop-filter: blur(8px) !important; -webkit-backdrop-filter: blur(8px) !important; z-index: 999999 !important; display: none; align-items: center; justify-content: center; }
.buzon-modal-box { background: #fff !important; width: 95%; max-width: 600px; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); overflow: hidden; animation: buzonModalSlideUp 0.3s ease-out; position: relative; z-index: 1000000 !important; }
@keyframes buzonModalSlideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 25px; border-bottom: 1px solid #f0f0f0; background: #fafafa; }
.modal-header h2 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1a202c; }
.close-modal { border: none; background: transparent; font-size: 1.5rem; cursor: pointer; color: #a0aec0; transition: color 0.2s; }
.close-modal:hover { color: #e53e3e; }
.modal-body { padding: 25px; }
.detail-row { display: flex; align-items: center; margin-bottom: 15px; font-size: 0.95rem; }
.detail-row strong { width: 130px; display: flex; align-items: center; gap: 8px; color: #4a5568; }
.detail-row strong i { color: #a81c24; width: 16px; }
.content-block { background: #fdfdfd; padding: 18px; border-radius: 12px; border: 1px solid #edf2f7; margin-top: 15px; }
.content-block strong { display: block; margin-bottom: 10px; color: #1a202c; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.025em; }
.text-pre { white-space: pre-wrap; font-family: inherit; line-height: 1.6; color: #2d3748; margin: 0; }

/* ── MODAL CONFIRMACIÓN ELIMINAR ── */
.buzon-confirm-box { max-width: 460px; }
.modal-header-danger { background: #fff5f5; border-bottom-color: #fed7d7; }
.modal-header-danger h2 { color: #c53030; font-size: 1.1rem; }
.confirm-icon { font-size: 3rem; color: #e53e3e; margin-bottom: 18px; }
.confirm-text { font-size: 1rem; font-weight: 600; color: #2d3748; margin-bottom: 8px; }
.confirm-subtext { font-size: 0.9rem; color: #718096; }
.ep-btn-danger { background: #e53e3e !important; color: #fff !important; border-color: #e53e3e !important; }
.ep-btn-danger:hover { background: #c53030 !important; }

/* ── DARK MODE ── */
.dark-mode .buzon-modal-box { background: #1a202c !important; color: #e2e8f0; }
.dark-mode .modal-header { background: #171923; border-bottom-color: #2d3748; }
.dark-mode .modal-header h2 { color: #f7fafc; }
.dark-mode .content-block { background: #171923; border-color: #2d3748; }
.dark-mode .text-pre { color: #edf2f7; }
.dark-mode .detail-row strong { color: #a0aec0; }
.dark-mode .type-desc { color: #a0aec0; }
.dark-mode .modal-header-danger { background: #2d1a1a; border-bottom-color: #742a2a; }
.dark-mode .modal-header-danger h2 { color: #fc8181; }

/* ── IMPRESIÓN ── */
@media print {
    body * { visibility: hidden; }
    #buzonDetailModal, #buzonDetailModal * { visibility: visible; }
    #buzonDetailModal { position: absolute; left: 0; top: 0; width: 100%; height: auto; display: block !important; background: white !important; backdrop-filter: none !important; }
    .buzon-modal-overlay { background: white !important; }
    .buzon-modal-box { box-shadow: none !important; border: none !important; width: 100% !important; max-width: 100% !important; transform: none !important; margin: 0 !important; }
    .modal-footer, .close-modal { display: none !important; }
}
</style>

<script>
/* ── NAVEGACIÓN ENTRE VISTAS ── */
var _buzonSections = { form: 'buzonFormSection', messages: 'buzonManagerSection', archive: 'buzonArchiveSection' };
var _buzonNavBtns  = { form: 'btnNavForm', messages: 'btnNavMessages', archive: 'btnNavArchive' };

function switchBuzonView(view) {
    Object.keys(_buzonSections).forEach(function(k) {
        var el = document.getElementById(_buzonSections[k]);
        if (el) {
            el.style.setProperty('display', (k === view) ? 'block' : 'none', 'important');
        }
        var btn = document.getElementById(_buzonNavBtns[k]);
        if (btn) btn.classList.toggle('active', k === view);
    });
}

/* ── FILTROS POR TIPO ── */
function filterMessages(scope, type, clickedBtn) {
    const gridId  = (scope === 'messages') ? 'messagesGrid'    : 'archiveGrid';
    const emptyId = (scope === 'messages') ? 'messagesFilterEmpty' : 'archiveFilterEmpty';

    // Actualizar chips activos del mismo contexto
    const bar = clickedBtn.closest('.buzon-filter-bar');
    bar.querySelectorAll('.filter-chip').forEach(function(c) { c.classList.remove('active'); });
    clickedBtn.classList.add('active');

    const grid = document.getElementById(gridId);
    if (!grid) return;
    const cards = grid.querySelectorAll('.message-card');
    let visible = 0;

    cards.forEach(function(card) {
        if (type === 'all' || card.dataset.type === type) {
            card.classList.remove('hidden-card');
            visible++;
        } else {
            card.classList.add('hidden-card');
        }
    });

    const filterEmpty = document.getElementById(emptyId);
    if (filterEmpty) filterEmpty.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
}

/* ── MODAL DETALLE ── */
function openBuzonModal(msg, authorName, isArchived) {
    const typeLabels = {
        'suggestion'  : '💡 Sugerencia',
        'incident'    : '🛠️ Incidencia',
        'complaint'   : '⚠️ Queja',
        'recognition' : '🌟 Reconocimiento',
        'confidencial': '🚨 Reporte Confidencial',
        'medios'      : '💻 Necesidad de Medios'
    };

    document.getElementById('modalTitle').innerText       = 'Detalle: ' + msg.title;
    document.getElementById('modalSender').innerText      = authorName;
    document.getElementById('modalDate').innerText        = new Date(msg.created_at).toLocaleDateString('es-ES');
    document.getElementById('modalType').innerText        = typeLabels[msg.type] || msg.type.toUpperCase();

    const archivedRow = document.getElementById('modalArchivedRow');
    if (archivedRow) archivedRow.style.display = isArchived ? 'flex' : 'none';

    if (msg.proposal) {
        document.getElementById('modalProposalBlock').style.display = 'block';
        document.getElementById('modalProposal').innerText = msg.proposal;
    } else {
        document.getElementById('modalProposalBlock').style.display = 'none';
    }

    document.getElementById('modalDescription').innerText = msg.description;
    document.getElementById('buzonDetailModal').style.display = 'flex';
}

function closeBuzonModal() {
    document.getElementById('buzonDetailModal').style.display = 'none';
}

/* ── ARCHIVAR MENSAJE ── */
var _buzonTypeLabels = {
    'suggestion'  : '💡 Sugerencia',
    'incident'    : '🛠️ Incidencia',
    'complaint'   : '⚠️ Queja',
    'recognition' : '🌟 Reconocimiento',
    'confidencial': '🚨 Reporte Confidencial',
    'medios'      : '💻 Necesidad de Medios'
};

function archiveMessage(msgId, btnEl) {
    var nonce  = document.getElementById('buzonActionNonce') ? document.getElementById('buzonActionNonce').value : '';
    var card   = btnEl.closest('.message-card');
    var msgRaw = card.dataset.msg    || '{}';
    var author = card.dataset.author || 'Anónimo';
    var msg    = {};
    try { msg = JSON.parse(msgRaw); } catch(e) {}

    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    jQuery.ajax({
        url: ep_vars.ajax_url,
        type: 'POST',
        data: { action: 'ep_archive_buzon', nonce: nonce, msg_id: msgId },
        success: function(res) {
            if (res.success) {
                // 1) Animar salida de la tarjeta activa
                card.style.transition = 'opacity 0.4s, transform 0.4s';
                card.style.opacity    = '0';
                card.style.transform  = 'scale(0.95)';

                setTimeout(function() {
                    card.remove();
                    updateBadgeCount('btnNavMessages', -1);
                    updateBadgeCount('btnNavArchive', 1);

                    // 2) Crear tarjeta en el grid del Archivo
                    var archiveGrid = document.getElementById('archiveGrid');
                    if (archiveGrid) {
                        // Quitar el empty state si existe
                        var emptyState = document.getElementById('archiveEmptyState');
                        if (emptyState) emptyState.remove();

                        var typeLabel = _buzonTypeLabels[msg.type] || msg.type;
                        var dateStr   = msg.created_at ? new Date(msg.created_at).toLocaleDateString('es-ES') : '';
                        // Recortar descripción a ~15 palabras
                        var descWords = (msg.description || '').split(/\s+/).slice(0, 15).join(' ');
                        var descExcerpt = descWords + ((msg.description || '').split(/\s+/).length > 15 ? '&hellip;' : '');

                        var newCard = document.createElement('div');
                        newCard.className = 'ep-card message-card archived-card';
                        newCard.dataset.id   = msg.id;
                        newCard.dataset.type = msg.type;
                        newCard.innerHTML =
                            '<div class="message-header">' +
                                '<span class="type-badge ' + (msg.type || '') + '">' + typeLabel + '</span>' +
                                '<span class="date">' + dateStr + '</span>' +
                            '</div>' +
                            '<h3>' + (msg.title || '') + '</h3>' +
                            '<p class="excerpt">' + descExcerpt + '</p>' +
                            '<div class="message-footer">' +
                                '<span class="author"><i class="fa-solid fa-user-circle"></i> ' + author + '</span>' +
                                '<div class="card-actions">' +
                                    '<button class="ep-btn ep-btn-sm ep-btn-outline-primary" onclick=\'openBuzonModal(' + JSON.stringify(msg) + ', "' + author.replace(/"/g, '\\"') + '", true)\'>' +
                                        '<i class="fa-solid fa-eye"></i> Leer' +
                                    '</button>' +
                                    '<button class="ep-btn ep-btn-sm ep-btn-delete-archived" onclick="deleteArchivedMessage(' + msg.id + ', this)">' +
                                        '<i class="fa-solid fa-trash"></i> Eliminar' +
                                    '</button>' +
                                '</div>' +
                            '</div>';

                        // Insertar al principio del grid
                        archiveGrid.insertBefore(newCard, archiveGrid.firstChild);

                        // Animación de entrada
                        newCard.style.opacity   = '0';
                        newCard.style.transform = 'scale(0.95)';
                        newCard.style.transition = 'opacity 0.4s, transform 0.4s';
                        requestAnimationFrame(function() {
                            newCard.style.opacity   = '1';
                            newCard.style.transform = 'scale(1)';
                        });
                    }
                }, 400);
            } else {
                alert('Error: ' + res.data);
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fa-solid fa-box-archive"></i> Archivar';
            }
        },
        error: function() {
            alert('Error de conexión. Inténtalo de nuevo.');
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="fa-solid fa-box-archive"></i> Archivar';
        }
    });
}

/* ── ELIMINAR ARCHIVADO (CON CONFIRMACIÓN) ── */
var _pendingDeleteId   = null;
var _pendingDeleteCard = null;

function deleteArchivedMessage(msgId, btnEl) {
    _pendingDeleteId   = msgId;
    _pendingDeleteCard = btnEl.closest('.message-card');
    document.getElementById('buzonDeleteConfirmModal').style.display = 'flex';
}

function confirmDelete() {
    if (!_pendingDeleteId) return;
    const nonce = document.getElementById('buzonActionNonce') ? document.getElementById('buzonActionNonce').value : '';
    const confirmBtn = document.getElementById('btnConfirmDelete');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Eliminando...';

    jQuery.ajax({
        url: ep_vars.ajax_url,
        type: 'POST',
        data: { action: 'ep_delete_buzon', nonce: nonce, msg_id: _pendingDeleteId },
        success: function(res) {
            closeDeleteConfirm();
            if (res.success) {
                if (_pendingDeleteCard) {
                    _pendingDeleteCard.style.transition = 'opacity 0.4s, transform 0.4s';
                    _pendingDeleteCard.style.opacity    = '0';
                    _pendingDeleteCard.style.transform  = 'scale(0.95)';
                    setTimeout(function() {
                        _pendingDeleteCard.remove();
                        updateBadgeCount('btnNavArchive', -1);
                    }, 400);
                }
            } else {
                alert('Error: ' + res.data);
            }
            _pendingDeleteId   = null;
            _pendingDeleteCard = null;
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Sí, eliminar';
        },
        error: function() {
            closeDeleteConfirm();
            alert('Error de conexión. Inténtalo de nuevo.');
            _pendingDeleteId   = null;
            _pendingDeleteCard = null;
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Sí, eliminar';
        }
    });
}

function closeDeleteConfirm() {
    document.getElementById('buzonDeleteConfirmModal').style.display = 'none';
}

/* ── ACTUALIZAR CONTADOR EN BOTONES ── */
function updateBadgeCount(btnId, delta) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    var badge = btn.querySelector('.buzon-badge');
    var current = badge ? parseInt(badge.innerText) : 0;
    var newVal = current + delta;
    if (newVal <= 0) {
        if (badge) badge.remove();
    } else {
        if (badge) {
            badge.innerText = newVal;
        } else {
            var b = document.createElement('span');
            b.className = 'buzon-badge' + (btnId === 'btnNavArchive' ? ' buzon-badge-muted' : '');
            b.innerText = newVal;
            btn.appendChild(b);
        }
    }
}

/* ── ENVÍO FORMULARIO ── */
jQuery(document).ready(function($) {
    $('#epBuzonForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#btnSubmitBuzon');
        const originalText = $btn.html();

        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Enviando...');

        $.ajax({
            url: ep_vars.ajax_url,
            type: 'POST',
            data: {
                action          : 'ep_submit_buzon',
                nonce           : $('input[name="nonce"]').val(),
                buzon_type      : $('input[name="buzon_type"]:checked').val(),
                buzon_title     : $('#buzon_title').val(),
                buzon_description: $('#buzon_description').val(),
                buzon_proposal  : $('#buzon_proposal').val(),
                buzon_include_name: $('#buzon_include_name').is(':checked') ? '1' : '0'
            },
            success: function(res) {
                if (res.success) {
                    alert(res.data);
                    location.reload();
                } else {
                    alert('Error: ' + res.data);
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function(xhr) {
                console.error("EP Buzón AJAX Error:", xhr);
                alert('Error de conexión con el servidor. Por favor, revisa tu conexión o intenta más tarde.');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });
});
</script>

