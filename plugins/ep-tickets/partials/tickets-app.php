<?php
$current_user = wp_get_current_user();
$tickets = EP_Tickets::get_user_tickets();
global $ep_app_manager;
$manageable_tickets = EP_Tickets::get_manageable_tickets_for_user($current_user->ID, 'open');
$closed_manageable_tickets = EP_Tickets::get_manageable_tickets_for_user($current_user->ID, 'closed');
$permission = $ep_app_manager->get_user_permission('tickets');
$department = (string) ($current_user->ep_department ?? '');
$is_manager = ($permission === 'write') || !empty($manageable_tickets) || !empty($closed_manageable_tickets) || (strpos($department, 'TRANSFORMACI') !== false || strpos($department, 'Comunicaci') !== false);
$queues = EP_Tickets::get_department_queues();
$stats = EP_Tickets::get_stats();

$js_queue_counts = [
    'IT' => 0,
    'Communication' => 0,
    'Web' => 0
];
foreach ($queues as $q) {
    if ($q['label'] === 'Soporte Informático') $js_queue_counts['IT'] = $q['count'];
    if ($q['label'] === 'Comunicación') $js_queue_counts['Communication'] = $q['count'];
    if ($q['label'] === 'Desarrollo/Soporte Web') $js_queue_counts['Web'] = $q['count'];
}
?>

<div class="ep-content-grid">

    <!-- Stats & Workload Container -->
    <section class="ep-tickets-section full-width">
        <div class="ep-stats-dashboard">
            <!-- Workload Card -->
            <div class="ep-card ep-stat-card">
                <div class="ep-stat-header">
                    <div class="ep-stat-icon workload"><i class="fa-solid fa-layer-group"></i></div>
                    <div class="ep-stat-info">
                        <h3>Carga de Trabajo</h3>
                        <p>Tickets abiertos por departamento</p>
                    </div>
                </div>
                <div class="ep-stat-content workload-grid">
                    <?php foreach ($queues as $q): ?>
                        <div class="queue-stat-item ep-queue-trigger" 
                            data-label="<?php echo esc_attr($q['label']); ?>"
                            data-high="<?php echo intval($q['breakdown']['high']); ?>"
                            data-normal="<?php echo intval($q['breakdown']['normal']); ?>"
                            data-low="<?php echo intval($q['breakdown']['low']); ?>">
                            <span class="queue-label"><?php echo esc_html($q['label']); ?></span>
                            <span class="ep-badge-unified <?php echo $q['count'] > 5 ? 'high' : 'normal'; ?>">
                                <?php echo intval($q['count']); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Time-based Stats Card -->
            <div class="ep-card ep-stat-card">
                <div class="ep-stat-header">
                    <div class="ep-stat-icon analytics"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="ep-stat-info">
                        <h3>Rendimiento</h3>
                        <p>Histórico de resolución</p>
                    </div>
                </div>
                <div class="ep-stat-content stats-flex">
                    <div class="stat-period-box">
                        <span class="period-label">Este Mes</span>
                        <div class="period-values">
                            <div class="val-item" title="Creados">
                                <i class="fa-solid fa-plus-circle text-blue"></i>
                                <strong><?php echo $stats['month']['created']; ?></strong>
                            </div>
                            <div class="val-item" title="Resueltos">
                                <i class="fa-solid fa-check-circle text-green"></i>
                                <strong><?php echo $stats['month']['resolved']; ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-period-box">
                        <span class="period-label">Este Año</span>
                        <div class="period-values">
                            <div class="val-item" title="Creados">
                                <i class="fa-solid fa-plus-circle text-blue"></i>
                                <strong><?php echo $stats['year']['created']; ?></strong>
                            </div>
                            <div class="val-item" title="Resueltos">
                                <i class="fa-solid fa-check-circle text-green"></i>
                                <strong><?php echo $stats['year']['resolved']; ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <style>
        .ep-stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .ep-stat-card {
            padding: 20px !important;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 180px;
        }
        .ep-stat-header {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
        }
        .ep-stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .ep-stat-icon.workload { background: rgba(var(--ep-primary-rgb), 0.1); color: var(--ep-primary); }
        .ep-stat-icon.analytics { background: rgba(40, 167, 69, 0.1); color: #28a745; }
        
        .ep-stat-info h3 { margin: 0; font-size: 1.1rem; color: var(--ep-text-main); }
        .ep-stat-info p { margin: 0; font-size: 0.85rem; color: var(--ep-text-muted); }

        .workload-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
        }
        .queue-stat-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            transition: transform 0.2s;
            cursor: pointer;
            text-align: center;
        }
        .queue-stat-item:hover { transform: translateY(-2px); background: #f0f2f5; }
        .queue-label { font-size: 0.75rem; font-weight: 600; color: var(--ep-text-muted); line-height: 1.2; }

        .stats-flex {
            display: flex;
            align-items: center;
            justify-content: space-around;
            padding: 10px 0;
        }
        .stat-period-box {
            text-align: center;
            flex: 1;
        }
        .period-label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            color: var(--ep-text-muted);
            margin-bottom: 10px;
        }
        .period-values {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .val-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .val-item i { font-size: 1.1rem; }
        .val-item strong { font-size: 1.3rem; color: var(--ep-text-main); }
        .text-blue { color: var(--ep-primary); }
        .text-green { color: #28a745; }
        .stat-divider {
            width: 1px;
            height: 40px;
            background: #eee;
            margin: 0 15px;
        }

        @media (max-width: 768px) {
            .ep-stats-dashboard { grid-template-columns: 1fr; }
            .stats-flex { flex-direction: column; gap: 20px; }
            .stat-divider { display: none; }
        }
    </style>

    <!-- Management Section -->
    <?php if ($is_manager): ?>
        <section class="ep-tickets-section full-width">
            <div class="ep-card ticket-list-card">
                <h3><i class="fa-solid fa-briefcase"></i> Gestión de Tickets (<?php echo count($manageable_tickets); ?>)
                </h3>
                <table class="ep-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Asunto</th>
                            <th>Categoría</th>
                            <th>Prio</th>
                            <th>Solicitante</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th><i class="fa-solid fa-paperclip"></i></th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($manageable_tickets)): ?>
                            <tr>
                                <td colspan="9">No hay tickets pendientes para tu departamento.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($manageable_tickets as $ticket):
                                $priority = get_post_meta($ticket->ID, '_ep_ticket_priority', true) ?: 'Normal';
                                $ticket_type = get_post_meta($ticket->ID, '_ep_ticket_type', true) ?: 'IT';
                                $type_labels = ['IT' => 'Soporte Informático', 'Communication' => 'Comunicación', 'Web' => 'Desarrollo/Soporte Web'];
                                $type_label = isset($type_labels[$ticket_type]) ? $type_labels[$ticket_type] : $ticket_type;
                                $type_css = strtolower($ticket_type);
                                $attach_id = get_post_meta($ticket->ID, '_ep_ticket_attachment_id', true);
                                if (!$attach_id) {
                                    $attach_id = get_post_meta($ticket->ID, '_ep_ticket_attachment', true);
                                }
                                $attach_url = $attach_id ? (is_numeric($attach_id) ? wp_get_attachment_url($attach_id) : $attach_id) : '';
                                $status = get_post_meta($ticket->ID, '_ep_ticket_status', true);
                                ?>
                                <tr class="ticket-row" data-id="<?php echo $ticket->ID; ?>">
                                    <td>#<?php echo $ticket->ID; ?></td>
                                    <td><?php echo esc_html($ticket->post_title); ?></td>
                                    <td><span class="ep-badge-unified <?php echo $type_css; ?>"><?php echo esc_html($type_label); ?></span></td>
                                    <td><span
                                            class="ep-badge-unified <?php echo strtolower($priority); ?>"><?php echo $priority; ?></span>
                                    </td>
                                    <td><?php echo get_the_author_meta('display_name', $ticket->post_author); ?></td>
                                    <td><?php echo get_the_date('d/m/Y', $ticket->ID); ?></td>
                                    <td><span
                                            class="ep-badge-unified <?php echo strtolower($status); ?>"><?php echo $status; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($attach_url): ?>
                                            <a href="<?php echo esc_url($attach_url); ?>" target="_blank" title="Ver Adjunto">
                                                <i class="fa-solid fa-paperclip" style="color: var(--ep-primary);"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="ep-btn ep-btn-sm ep-btn-primary ep-open-ticket-modal"
                                            data-id="<?php echo $ticket->ID; ?>"
                                            data-title="<?php echo esc_attr($ticket->post_title); ?>"
                                            data-user="<?php echo esc_attr(get_the_author_meta('display_name', $ticket->post_author)); ?>"
                                            data-content="<?php echo esc_attr($ticket->post_content); ?>"
                                            data-priority="<?php echo esc_attr($priority); ?>"
                                            data-attachment="<?php echo esc_url($attach_url); ?>"
                                            data-status="<?php echo esc_attr($status); ?>" data-view="manager"
                                            data-nonce="<?php echo wp_create_nonce('ep_ticket_action_' . $ticket->ID); ?>">
                                            Ver / Gestionar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- History Section for Managers -->
        <?php if (!empty($closed_manageable_tickets)): ?>
        <section class="ep-tickets-section full-width">
            <div class="ep-card ticket-list-card" style="border-top: 3px solid var(--ep-success);">
                <h3><i class="fa-solid fa-history"></i> Historial de Tickets Cerrados (<?php echo count($closed_manageable_tickets); ?>)</h3>
                <div style="max-height: 300px; overflow-y: auto;">
                    <table class="ep-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Asunto</th>
                                <th>Categoría</th>
                                <th>Solicitante</th>
                                <th class="ep-sortable" data-type="date" style="cursor:pointer;" title="Haz clic para ordenar">Cerrado el <i class="fa-solid fa-sort"></i></th>
                                <th><i class="fa-solid fa-paperclip"></i></th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="epClosedTicketsBody">
                            <?php foreach ($closed_manageable_tickets as $ticket):
                                $ticket_type = get_post_meta($ticket->ID, '_ep_ticket_type', true) ?: 'IT';
                                $closed_date = get_post_meta($ticket->ID, '_ep_ticket_closed_date', true);
                                $priority = get_post_meta($ticket->ID, '_ep_ticket_priority', true) ?: 'Normal';
                                $attach_id = get_post_meta($ticket->ID, '_ep_ticket_attachment_id', true);
                                $attach_url = $attach_id ? wp_get_attachment_url($attach_id) : '';
                                ?>
                                <tr class="ticket-row" data-timestamp="<?php echo $closed_date ? strtotime($closed_date) : 0; ?>">
                                    <td>#<?php echo $ticket->ID; ?></td>
                                    <td><?php echo esc_html($ticket->post_title); ?></td>
                                    <td><span class="ep-badge-unified <?php echo strtolower($ticket_type); ?>"><?php echo $ticket_type; ?></span></td>
                                    <td><?php echo get_the_author_meta('display_name', $ticket->post_author); ?></td>
                                    <td><?php echo $closed_date ? date('d/m/Y H:i', strtotime($closed_date)) : 'N/A'; ?></td>
                                    <td>
                                        <?php if ($attach_url): ?>
                                            <a href="<?php echo esc_url($attach_url); ?>" target="_blank" title="Ver Adjunto">
                                                <i class="fa-solid fa-paperclip" style="color: var(--ep-primary);"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="ep-btn ep-btn-sm ep-open-ticket-modal"
                                            data-id="<?php echo $ticket->ID; ?>"
                                            data-title="<?php echo esc_attr($ticket->post_title); ?>"
                                            data-user="<?php echo esc_attr(get_the_author_meta('display_name', $ticket->post_author)); ?>"
                                            data-content="<?php echo esc_attr($ticket->post_content); ?>"
                                            data-priority="<?php echo esc_attr($priority); ?>"
                                            data-attachment="<?php echo esc_url($attach_url); ?>"
                                            data-status="closed" data-view="user">
                                            Ver Detalle
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>
    <?php endif; ?>

    <!-- User Section -->
    <section class="ep-tickets-section full-width">
        <div class="ep-cards-row">
            <!-- Form -->
            <div class="ep-card ticket-form-card">
                <h3>Crear Nuevo Ticket</h3>
                <form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerText = 'Enviando...';">
                    <?php wp_nonce_field('ep_new_ticket', 'ep_ticket_nonce'); ?>

                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:15px;">
                        <div>
                            <label>Asunto</label>
                            <input type="text" name="ticket_subject" required style="width:100%; margin-bottom: 1rem;">
                        </div>
                        <div>
                            <label>Prioridad</label>
                            <select name="ticket_priority" style="width:100%; margin-bottom: 1rem;">
                                <option value="Baja">Baja</option>
                                <option value="Normal" selected>Normal</option>
                                <option value="Alta">Alta</option>
                            </select>
                        </div>
                    </div>

                    <label>Tipo de Incidencia</label>
                    <select name="ticket_type" id="epTicketTypeSelect" style="width:100%; margin-bottom: 1rem;">
                        <option value="IT">Soporte Informático</option>
                        <option value="Web">Desarrollo/Soporte Web</option>
                        <option value="Communication">Comunicación</option>
                    </select>

                    <?php
                    $my_equipment = get_posts(array(
                        'post_type' => 'ep_inventory_item',
                        'posts_per_page' => -1,
                        'meta_query' => array(
                            'relation' => 'OR',
                            array('key' => '_ep_item_assigned_to', 'value' => $current_user->ID),
                            array('key' => '_ep_item_loaned_to', 'value' => $current_user->ID),
                            array('key' => '_ep_assigned_user_id', 'value' => $current_user->ID)
                        )
                    ));
                    if (empty($my_equipment)) {
                        $my_equipment = get_posts(array(
                            'post_type' => 'ep_inventory_item',
                            'posts_per_page' => 100,
                            'orderby' => 'title',
                            'order' => 'ASC'
                        ));
                    }
                    ?>
                    <div id="epEquipmentGroup" style="display: block;">
                        <label><i class="fa-solid fa-laptop"></i> Equipo o Periférico Afectado (Opcional)</label>
                        <select name="ticket_asset" style="width:100%; margin-bottom: 1rem;">
                            <option value="">-- Ninguno / Consulta General --</option>
                            <?php foreach ($my_equipment as $eq): 
                                $serial = get_post_meta($eq->ID, '_ep_item_serial', true) ?: get_post_meta($eq->ID, '_ep_inventory_serial', true);
                            ?>
                                <option value="<?php echo $eq->ID; ?>">
                                    <?php echo esc_html($eq->post_title . ($serial ? ' (SN: ' . $serial . ')' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const typeSelect = document.getElementById('epTicketTypeSelect');
                            const eqGroup = document.getElementById('epEquipmentGroup');
                            if (typeSelect && eqGroup) {
                                typeSelect.addEventListener('change', function() {
                                    eqGroup.style.display = (this.value === 'IT') ? 'block' : 'none';
                                });
                            }
                        });
                    </script>

                    <!-- Aviso dinámico de carga de trabajo por departamento -->
                    <div id="epTicketWorkloadNotice" style="display: none; margin-bottom: 1.2rem; padding: 12px 15px; border-radius: 8px; border-left: 4px solid var(--ep-primary); background: #f0f7ff; color: #1e3a8a; font-size: 0.85rem; transition: opacity 0.3s ease;">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <div class="notice-icon" style="font-size: 1.1rem;"><i class="fa-solid fa-circle-info"></i></div>
                            <div class="notice-text">
                                <span id="epWorkloadText"></span>
                            </div>
                        </div>
                    </div>

                    <label>Mensaje / Descripción</label>
                    <textarea name="ticket_message" required rows="5"
                        style="width:100%; margin-bottom: 1rem;"></textarea>

                    <label>Adjuntar Archivo (Captura/Log)</label>
                    <input type="file" name="ticket_file" style="margin-bottom: 1rem;">

                    <button type="submit" id="epSubmitTicketBtn" name="ep_submit_ticket" class="ep-btn ep-btn-primary">Enviar Ticket</button>
                    <!-- Campos ocultos requeridos para que PHP reciba 'ep_submit_ticket' aunque el boton esté deshabilitado -->
                    <input type="hidden" name="ep_submit_ticket" value="1">
                </form>
            </div>
        </div>

        <!-- My Tickets -->
        <div class="ep-card ticket-list-card" style="margin-top: 2rem;">
            <h3>Mis Tickets Recientes</h3>
            <ul class="ep-ticket-list">
                <?php if (!empty($tickets)): ?>
                    <?php foreach ($tickets as $ticket):
                        $status = get_post_meta($ticket->ID, '_ep_ticket_status', true);
                        $priority = get_post_meta($ticket->ID, '_ep_ticket_priority', true) ?: 'Normal';
                        $handler_id = get_post_meta($ticket->ID, '_ep_ticket_handler_id', true);
                        $handler_name = $handler_id ? get_the_author_meta('display_name', $handler_id) : 'Sin asignar';
                        $attach_id = get_post_meta($ticket->ID, '_ep_ticket_attachment_id', true);
                        if (!$attach_id) {
                            $attach_id = get_post_meta($ticket->ID, '_ep_ticket_attachment', true);
                        }
                        $attach_url = $attach_id ? (is_numeric($attach_id) ? wp_get_attachment_url($attach_id) : $attach_id) : '';
                        ?>
                        <li class="ticket-item">
                            <div class="ticket-info">
                                <strong>#<?php echo $ticket->ID; ?>         <?php echo esc_html($ticket->post_title); ?>
                                    <span class="ep-badge-unified <?php echo strtolower($priority); ?>"
                                        style="margin-left:5px;"><?php echo $priority; ?></span>
                                </strong>
                                <span class="ticket-meta">
                                    <?php
                                    $t_type = get_post_meta($ticket->ID, '_ep_ticket_type', true) ?: 'IT';
                                    $t_type_labels = ['IT' => 'Soporte Informático', 'Communication' => 'Comunicación', 'Web' => 'Desarrollo/Soporte Web'];
                                    $t_type_label = isset($t_type_labels[$t_type]) ? $t_type_labels[$t_type] : $t_type;
                                    ?>
                                    <small><?php echo get_the_date('d/m/Y', $ticket->ID); ?></small> |
                                    <small><?php echo esc_html($t_type_label); ?></small> |
                                    <small>Gestionado por: <?php echo $handler_name; ?></small>
                                </span>
                            </div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <span
                                    class="ep-badge-unified <?php echo strtolower($status); ?>"><?php echo ucfirst($status); ?></span>
                                <button type="button" class="ep-btn ep-btn-sm ep-open-ticket-modal"
                                    data-id="<?php echo $ticket->ID; ?>"
                                    data-title="<?php echo esc_attr($ticket->post_title); ?>"
                                    data-user="Mí (<?php echo $current_user->display_name; ?>)"
                                    data-content="<?php echo esc_attr($ticket->post_content); ?>"
                                    data-priority="<?php echo esc_attr($priority); ?>"
                                    data-attachment="<?php echo esc_url($attach_url); ?>"
                                    data-status="<?php echo esc_attr($status); ?>"
                                    data-view="<?php echo ($handler_id == $current_user->ID) ? 'handler' : 'user'; ?>"
                                    data-nonce="<?php echo wp_create_nonce('ep_ticket_action_' . $ticket->ID); ?>">
                                    Ver Detalle
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li>No tienes tickets abiertos.</li>
                <?php endif; ?>
            </ul>
        </div>
    </section>
</div>

<!-- TICKET MODAL -->
<div id="epTicketModal" class="ep-modal-unified">
    <div class="ep-modal-content">
        <span class="ep-close ep-close-modal-trigger">&times;</span>
        <h2>Ticket #<span id="modalTicketID"></span> <br><small id="modalTicketSubject"
                style="font-size:0.8em; color:var(--ep-text-muted);"></small></h2>

        <div class="ep-modal-body">
            <p><strong>Solicitante:</strong> <span id="modalTicketUser"></span></p>
            <p>
                <strong>Prioridad:</strong> <span id="modalTicketPriority"></span>
                <?php if (EP_Tickets::is_staff($current_user->ID)): ?>
                    <button type="button" id="btnEditPriority" class="ep-btn ep-btn-sm ep-btn-secondary" style="margin-left: 10px; padding: 2px 8px; font-size: 0.8rem;">
                        <i class="fa-solid fa-pen"></i> Cambiar
                    </button>
                <?php endif; ?>
            </p>
            <?php if (EP_Tickets::is_staff($current_user->ID)): ?>
                <div id="editPriorityContainer" style="display: none; background: #f8f9fa; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
                    <div style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                        <label for="newPrioritySelect" style="margin: 0; font-weight: 600; font-size: 0.9rem;">Nueva Prioridad:</label>
                        <select id="newPrioritySelect" style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.9rem;">
                            <option value="Baja">Baja</option>
                            <option value="Normal">Normal</option>
                            <option value="Alta">Alta</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label for="priorityReasonText" style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.9rem;">Motivo del cambio (Opcional):</label>
                        <textarea id="priorityReasonText" placeholder="Escribe el motivo del cambio de prioridad..." rows="2" style="width: 100%; border-radius: 6px; border: 1px solid #cbd5e1; padding: 8px; font-size: 0.9rem; resize: vertical;"></textarea>
                    </div>
                    <div style="text-align: right; display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" id="btnCancelPriorityChange" class="ep-btn ep-btn-sm ep-btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Cancelar</button>
                        <button type="button" id="btnSavePriorityChange" class="ep-btn ep-btn-sm ep-btn-primary" style="padding: 4px 10px; font-size: 0.8rem;">Guardar</button>
                    </div>
                </div>
            <?php endif; ?>
            <p><strong>Estado:</strong> <span id="modalTicketStatus"></span></p>
            <hr>
            <p><strong>Descripción:</strong></p>
            <div id="modalTicketContent" class="ep-content-box-unified"></div>

            <div id="modalTicketAttachment" style="display:none; margin-bottom:15px; padding: 10px; background: #f0f7ff; border-radius: 8px; border: 1px dashed var(--ep-primary);">
                <strong>Adjunto:</strong> <a href="#" target="_blank" id="modalTicketAttachmentLink">Ver Archivo / Descargar</a>
                <div id="modalTicketImagePreview" style="margin-top:10px; display:none;">
                    <img src="" style="max-width:100%; border-radius:5px; border:1px solid #ddd;">
                </div>
            </div>

            <hr>
            <div id="modalTicketReplies" style="margin-top:15px;">
                <h3 style="font-size: 1rem; margin-bottom: 10px;"><i class="fa-solid fa-comments"></i> Conversación</h3>
                <div id="repliesContainer" style="max-height: 300px; overflow-y: auto; padding-right: 5px; margin-bottom: 15px;">
                    <!-- Comments loaded via AJAX -->
                    <p style="color: #999; font-style: italic;">Cargando conversación...</p>
                </div>
                
                <div id="replyFormContainer" style="display:none; border-top: 1px solid #eee; padding-top: 15px;">
                    <textarea id="replyText" rows="3" placeholder="Escribe una respuesta..." style="width: 100%; border-radius: 8px; border: 1px solid #ddd; padding: 10px; margin-bottom: 10px;"></textarea>
                    <button type="button" id="btnSendReply" class="ep-btn ep-btn-sm ep-btn-primary">Enviar Respuesta</button>
                </div>
            </div>

            <div class="ep-modal-actions" style="border-top:1px solid #eee; padding-top:15px; text-align:right; margin-top: 15px;">
                <!-- Dynamically populated buttons -->
                <a id="btnTakeTicket" href="#" class="ep-btn ep-btn-primary" style="display:none;">Asignarme Ticket</a>
                <a id="btnCloseTicket" href="#" class="ep-btn ep-btn-danger" style="display:none;">Cerrar Ticket</a>
                <button type="button" class="ep-btn ep-close-modal-trigger">Cerrar Ventana</button>
            </div>
        </div>
    </div>
</div>

<!-- QUEUE MODAL -->
<div id="epQueueModal" class="ep-modal-unified">
    <div class="ep-modal-content">
        <span class="ep-close ep-close-modal-trigger">&times;</span>
        <h2>Detalle de Cola: <span id="epQModalTitle"></span></h2>

        <div class="ep-modal-body">
            <p><strong>Prioridad Alta:</strong> <span id="epQValHigh"></span> Tickets</p>
            <p><strong>Prioridad Normal:</strong> <span id="epQValNormal"></span> Tickets</p>
            <p><strong>Prioridad Baja:</strong> <span id="epQValLow"></span> Tickets</p>
            <div class="ep-modal-actions" style="border-top:1px solid #eee; padding-top:15px; text-align:right;">
                <button type="button" class="ep-btn ep-close-modal-trigger">Cerrar Ventana</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        // 1. Sorting logic
        const sortHeader = document.querySelector('.ep-sortable');
        if (sortHeader) {
            sortHeader.addEventListener('click', function() {
                const tbody = document.getElementById('epClosedTicketsBody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const isAsc = this.classList.contains('sort-asc');
                
                rows.sort((a, b) => {
                    const valA = parseInt(a.getAttribute('data-timestamp'));
                    const valB = parseInt(b.getAttribute('data-timestamp'));
                    return isAsc ? valA - valB : valB - valA;
                });

                this.classList.toggle('sort-asc', !isAsc);
                rows.forEach(row => tbody.appendChild(row));
                
                const icon = this.querySelector('i');
                if (icon) {
                    icon.className = isAsc ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';
                }
            });
        }

        // 2. Send Reply logic
        const btnSendReply = document.getElementById('btnSendReply');
        if (btnSendReply) {
            btnSendReply.addEventListener('click', function() {
                const text = document.getElementById('replyText').value.trim();
                const id = document.getElementById('modalTicketID').textContent;
                if (!text) return;

                this.disabled = true;
                this.textContent = 'Enviando...';

                const formData = new FormData();
                formData.append('action', 'ep_app_ajax');
                formData.append('app', 'tickets');
                formData.append('ep_action', 'add_ticket_comment');
                formData.append('ticket_id', id);
                formData.append('comment_content', text);

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        location.reload(); 
                    } else {
                        alert('Error: ' + response.data);
                        this.disabled = false;
                        this.textContent = 'Enviar Respuesta';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error de servidor.');
                    this.disabled = false;
                    this.textContent = 'Enviar Respuesta';
                });
            });
        }

        // 2b. Priority Change Logic (Staff Only)
        const btnEditPriority = document.getElementById('btnEditPriority');
        const editPriorityContainer = document.getElementById('editPriorityContainer');
        const btnCancelPriorityChange = document.getElementById('btnCancelPriorityChange');
        const btnSavePriorityChange = document.getElementById('btnSavePriorityChange');

        if (btnEditPriority && editPriorityContainer) {
            btnEditPriority.addEventListener('click', function(e) {
                e.preventDefault();
                const currentPriority = document.getElementById('modalTicketPriority').textContent.trim();
                const select = document.getElementById('newPrioritySelect');
                if (select) {
                    select.value = currentPriority;
                }
                const reasonText = document.getElementById('priorityReasonText');
                if (reasonText) {
                    reasonText.value = '';
                }
                editPriorityContainer.style.display = 'block';
            });
        }

        if (btnCancelPriorityChange && editPriorityContainer) {
            btnCancelPriorityChange.addEventListener('click', function(e) {
                e.preventDefault();
                editPriorityContainer.style.display = 'none';
            });
        }

        if (btnSavePriorityChange && editPriorityContainer) {
            btnSavePriorityChange.addEventListener('click', function(e) {
                e.preventDefault();
                const id = document.getElementById('modalTicketID').textContent;
                const newPriority = document.getElementById('newPrioritySelect').value;
                const reason = document.getElementById('priorityReasonText').value.trim();

                this.disabled = true;
                this.textContent = 'Guardando...';

                const fd = new FormData();
                fd.append('action', 'ep_app_ajax');
                fd.append('app', 'tickets');
                fd.append('ep_action', 'change_ticket_priority');
                fd.append('ticket_id', id);
                fd.append('priority', newPriority);
                fd.append('reason', reason);

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        this.disabled = false;
                        this.textContent = 'Guardar';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error de servidor.');
                    this.disabled = false;
                    this.textContent = 'Guardar';
                });
            });
        }

        // 3. Delegation for Modal Interactions
        document.addEventListener('click', function (e) {

            // OPEN MODAL
            const openBtn = e.target.closest('.ep-open-ticket-modal');
            if (openBtn) {
                e.preventDefault();
                e.stopPropagation();

                const modal = document.getElementById('epTicketModal');
                if (!modal) return;

                const id = openBtn.getAttribute('data-id');
                const status = openBtn.getAttribute('data-status');
                const view = openBtn.getAttribute('data-view');
                const nonce = openBtn.getAttribute('data-nonce');
                const baseUrl = '?view=tickets&ticket_id=' + id + '&nonce=' + nonce;

                document.getElementById('modalTicketID').textContent = id;
                document.getElementById('modalTicketSubject').textContent = openBtn.getAttribute('data-title');
                document.getElementById('modalTicketUser').textContent = openBtn.getAttribute('data-user');
                document.getElementById('modalTicketPriority').textContent = openBtn.getAttribute('data-priority');
                document.getElementById('modalTicketStatus').textContent = status;
                document.getElementById('modalTicketContent').textContent = openBtn.getAttribute('data-content');

                const attach = openBtn.getAttribute('data-attachment');
                const attDiv = document.getElementById('modalTicketAttachment');
                const attLink = document.getElementById('modalTicketAttachmentLink');
                const attPreview = document.getElementById('modalTicketImagePreview');
                const attImg = attPreview.querySelector('img');

                if (attach && attach.trim() !== '') {
                    attDiv.style.display = 'block';
                    attLink.href = attach;
                    const isImg = /\.(jpg|jpeg|png|gif|webp)$/i.test(attach);
                    if (isImg) {
                        attPreview.style.display = 'block';
                        attImg.src = attach;
                    } else {
                        attPreview.style.display = 'none';
                    }
                } else {
                    attDiv.style.display = 'none';
                }

                // Load Comments
                const repliesContainer = document.getElementById('repliesContainer');
                repliesContainer.innerHTML = '<p style="color: #999; font-style: italic;">Cargando conversación...</p>';
                
                const fd = new FormData();
                fd.append('action', 'ep_app_ajax');
                fd.append('app', 'tickets');
                fd.append('ep_action', 'get_ticket_comments');
                fd.append('ticket_id', id);

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success && response.data.length > 0) {
                        repliesContainer.innerHTML = '';
                        response.data.forEach(comment => {
                            const div = document.createElement('div');
                            div.style.marginBottom = '10px';
                            div.style.padding = '10px';
                            div.style.borderRadius = '8px';
                            div.style.background = comment.is_staff ? '#fef2f2' : '#f8f9fa';
                            div.style.borderLeft = comment.is_staff ? '3px solid var(--ep-primary)' : '3px solid #ddd';
                            div.innerHTML = `
                                <div style="display:flex; justify-content:space-between; margin-bottom:5px; font-size:0.8rem;">
                                    <strong>${comment.author} ${comment.is_staff ? '<span class="ep-badge-unified it" style="font-size:0.6rem; padding:1px 4px;">SOPORTE</span>' : ''}</strong>
                                    <span style="color:#999;">${comment.date}</span>
                                </div>
                                <div style="font-size:0.9rem;">${comment.content}</div>
                            `;
                            repliesContainer.appendChild(div);
                        });
                        repliesContainer.scrollTop = repliesContainer.scrollHeight;
                    } else {
                        repliesContainer.innerHTML = '<p style="color: #999; font-style: italic; font-size: 0.9rem;">No hay respuestas aún.</p>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    repliesContainer.innerHTML = '<p style="color: #dc3545;">Error al cargar la conversación.</p>';
                });

                document.getElementById('replyFormContainer').style.display = (status !== 'closed') ? 'block' : 'none';

                // Resetear panel y botón de edición de prioridad
                const editPrioContainer = document.getElementById('editPriorityContainer');
                if (editPrioContainer) {
                    editPrioContainer.style.display = 'none';
                }
                const btnEditPrio = document.getElementById('btnEditPriority');
                if (btnEditPrio) {
                    btnEditPrio.style.display = (status !== 'closed') ? 'inline-block' : 'none';
                }

                const btnTake = document.getElementById('btnTakeTicket');
                const btnClose = document.getElementById('btnCloseTicket');
                btnTake.style.display = 'none';
                btnClose.style.display = 'none';

                if (view === 'manager' && status !== 'closed') {
                    if (status === 'open') {
                        btnTake.style.display = 'inline-block';
                        btnTake.href = baseUrl + '&ep_action=take_ticket';
                    }
                    if (status === 'in_progress') {
                        btnClose.style.display = 'inline-block';
                        btnClose.href = baseUrl + '&ep_action=close_ticket';
                    }
                }

                if ((view === 'handler' || view === 'user') && status !== 'closed' && status !== 'open') {
                    btnClose.style.display = 'inline-block';
                    btnClose.href = baseUrl + '&ep_action=close_ticket';
                }

                modal.classList.add('is-visible');
            }

            // CLOSE MODAL
            const closeTrigger = e.target.closest('.ep-close-modal-trigger');
            if (closeTrigger || e.target.classList.contains('ep-modal-unified')) {
                const modal = document.getElementById('epTicketModal');
                if (modal) modal.classList.remove('is-visible');
                const qModal = document.getElementById('epQueueModal');
                if (qModal) qModal.classList.remove('is-visible');
            }

            // QUEUE MODAL
            const queueBtn = e.target.closest('.ep-queue-trigger');
            if (queueBtn) {
                const qModal = document.getElementById('epQueueModal');
                if (qModal) {
                    document.getElementById('epQModalTitle').textContent = queueBtn.getAttribute('data-label');
                    document.getElementById('epQValHigh').textContent = queueBtn.getAttribute('data-high');
                    document.getElementById('epQValNormal').textContent = queueBtn.getAttribute('data-normal');
                    document.getElementById('epQValLow').textContent = queueBtn.getAttribute('data-low');
                    qModal.classList.add('is-visible');
                }
            }
        });

        // 4. Dynamic Workload Notice in Ticket Creation Form
        const epQueueCounts = <?php echo json_encode($js_queue_counts); ?>;
        const ticketTypeSelect = document.querySelector('select[name="ticket_type"]');
        const workloadNotice = document.getElementById('epTicketWorkloadNotice');
        const workloadText = document.getElementById('epWorkloadText');

        function updateWorkloadNotice() {
            if (!ticketTypeSelect || !workloadNotice || !workloadText) return;
            const selectedType = ticketTypeSelect.value;
            const count = epQueueCounts[selectedType] || 0;

            let msg = '';
            if (count === 0) {
                msg = 'No hay tickets delante del tuyo en cola. Una vez recibido, se podrá determinar la prioridad de este ticket.';
                workloadNotice.style.borderLeftColor = 'var(--ep-success, #28a745)';
                workloadNotice.style.background = '#f0fff4';
                workloadNotice.style.color = '#155724';
                workloadNotice.querySelector('.notice-icon').innerHTML = '<i class="fa-solid fa-circle-check" style="color:#28a745;"></i>';
            } else {
                msg = `Hay <strong>${count}</strong> ${count === 1 ? 'ticket' : 'tickets'} delante del tuyo en cola. Una vez recibido, se podrá determinar la prioridad de este ticket.`;
                workloadNotice.style.borderLeftColor = 'var(--ep-warning, #ffc107)';
                workloadNotice.style.background = '#fffbeb';
                workloadNotice.style.color = '#856404';
                workloadNotice.querySelector('.notice-icon').innerHTML = '<i class="fa-solid fa-clock" style="color:#ffc107;"></i>';
            }

            workloadText.innerHTML = msg;
            workloadNotice.style.display = 'block';
            workloadNotice.style.opacity = '0';
            setTimeout(() => {
                workloadNotice.style.opacity = '1';
            }, 50);
        }

        if (ticketTypeSelect) {
            ticketTypeSelect.addEventListener('change', updateWorkloadNotice);
            updateWorkloadNotice();
        }
    });
</script>