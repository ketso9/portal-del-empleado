<?php
defined('ABSPATH') || exit;
global $wpdb;

// Fetch Filters
$search = isset($_GET['search_query']) ? sanitize_text_field($_GET['search_query']) : '';
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$assigned_filter = isset($_GET['assigned_to']) ? intval($_GET['assigned_to']) : '';
$itinerant_filter = $_GET['itinerant_filter'] ?? '';

$paged = isset($_GET['ep_paged']) ? max(1, intval($_GET['ep_paged'])) : 1;

// Detect active tab (default: items)
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'items';

// Basic filter arguments
$args = array(
    'post_type' => 'ep_inventory_item',
    'posts_per_page' => 20,
    'paged' => $paged,
    'post_status' => 'publish',
    's' => $search,
    'meta_query' => array('relation' => 'AND')
);

// Restriction for Itinerant Manager
global $ep_app_manager;
$perm = $ep_app_manager->get_user_permission('inventory');

if ($perm === 'manage_itinerant' && !current_user_can('manage_options')) {
    $active_tab = 'loans'; // Force loans tab for partial managers
    $args['meta_query'][] = array(
        'key' => '_ep_item_is_itinerant',
        'value' => '1',
        'compare' => '='
    );
} else {
    // Para la pestaña 'items' (Inventario Fijo) no aplicamos filtro, para que salga todo.
    if ($active_tab === 'loans') {
        $args['meta_query'][] = array(
            'key' => '_ep_item_is_itinerant',
            'value' => '1',
            'compare' => '='
        );
    }
}

if ($type_filter) {
    $args['meta_query'][] = array(
        'key' => '_ep_item_type',
        'value' => $type_filter,
        'compare' => '='
    );
}

if ($assigned_filter) {
    if ($assigned_filter === -1) {
        // Not assigned
        $args['meta_query'][] = array(
            'key' => '_ep_item_assigned_to',
            'value' => '0', // Assuming 0 or empty for unassigned, need to verify saving logic
            'compare' => '<=',
            'type' => 'NUMERIC'
        );
    } else {
        $args['meta_query'][] = array(
            'key' => '_ep_item_assigned_to',
            'value' => $assigned_filter,
            'compare' => '='
        );
    }
}

// Remove meta_query if it's empty (only contains 'relation' => 'AND')
if (count($args['meta_query']) === 1 && isset($args['meta_query']['relation'])) {
    unset($args['meta_query']);
}

$inventory = new WP_Query($args);

// Stats Calculation
$total_items = wp_count_posts('ep_inventory_item')->publish;
$assigned_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'ep_inventory_item' AND p.post_status = 'publish' AND pm.meta_key = '_ep_item_assigned_to' AND CAST(pm.meta_value AS UNSIGNED) > 0");
$unassigned_count = $total_items - $assigned_count;
$assigned_perc = $total_items > 0 ? round(($assigned_count / $total_items) * 100) : 0;
?>

<div class="ep-app-container">
    <div class="ep-app-header">
        <h1>Gestión de Inventario</h1>
        <?php if ($perm !== 'manage_itinerant'): ?>
        <button class="ep-btn ep-btn-primary" id="ep-add-item-btn">
            <i class="fa-solid fa-plus"></i> Nuevo Item
        </button>
        <?php endif; ?>
    </div>

    <!-- Inventory Stats Bar -->
    <div class="ep-inventory-stats">
        <div class="stat-card">
            <span class="stat-label">Total</span>
            <span class="stat-value"><?php echo $total_items; ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Asignados</span>
            <span class="stat-value"><?php echo $assigned_count; ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Libres</span>
            <span class="stat-value"><?php echo $unassigned_count; ?></span>
        </div>
        <div class="stat-progress-container">
            <div class="stat-progress-bar" style="width: <?php echo $assigned_perc; ?>%;"></div>
            <span class="stat-progress-text"><?php echo $assigned_perc; ?>% Asignado</span>
        </div>
    </div>

    <div class="ep-filters">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <form method="GET" class="ep-filter-form">
                <!-- <input type="hidden" name="page" value="employee-portal"> -->
                <input type="hidden" name="view" value="inventory">
                <input type="text" name="search_query" placeholder="Buscar..." value="<?php echo esc_attr($search); ?>">
                <select name="type">
                    <option value="">Todos los tipos</option>
                    <option value="hardware" <?php selected($type_filter, 'hardware'); ?>>Hardware</option>
                    <option value="software" <?php selected($type_filter, 'software'); ?>>Software</option>
                </select>

                <select name="itinerant_filter" id="itinerant_filter">
                    <option value="">— Todo el material —</option>
                    <option value="1" <?php selected($_GET['itinerant_filter'] ?? '', '1'); ?>>Solo Itinerante</option>
                    <option value="0" <?php selected($_GET['itinerant_filter'] ?? '', '0'); ?>>Solo General (No
                        itinerante)</option>
                </select>

                <select name="assigned_to">
                    <option value="">Filtrar por Usuario</option>
                    <option value="-1" <?php selected($assigned_filter, -1); ?>>— Sin Asignar —</option>
                    <?php
                    $users = get_users();
                    foreach ($users as $user) {
                        echo '<option value="' . esc_attr($user->ID) . '" ' . selected($assigned_filter, $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
                    }
                    ?>
                </select>
                <button type="submit" class="ep-btn"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </form>

            <div class="ep-bulk-actions"
                style="display: flex; gap: 10px; align-items: center; border-left: 1px solid var(--ep-border); padding-left: 15px;">
                <?php if ($perm !== 'manage_itinerant'): ?>
                <select id="ep-bulk-user-assign" class="ep-select-sm" style="width: auto;">
                    <option value="">— Asignar a... —</option>
                    <option value="0">Libre (Quitar)</option>
                    <?php
                    foreach ($users as $user) {
                        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . '</option>';
                    }
                    ?>
                </select>
                <button class="ep-btn ep-btn-secondary" onclick="bulkAssignItems()"
                    title="Asignar seleccionados a este usuario">
                    Asignar
                </button>
                <button class="ep-btn ep-btn-sm ep-btn-danger" onclick="unassignSelected()"
                    title="Liberar material seleccionado">
                    <i class="fa-solid fa-user-slash"></i> Liberar
                </button>
                <?php endif; ?>
                <button class="ep-btn ep-btn-sm ep-btn-primary" onclick="itinerantBulkCheckOut()"
                    title="Registrar salida de todos los seleccionados">
                    <i class="fa-solid fa-right-from-bracket"></i> Salida Lote
                </button>
            </div>

            <div class="ep-header-actions" style="margin-left: auto; display: flex; gap: 10px;">
                <a href="<?php echo admin_url('admin-post.php?action=ep_inventory_export'); ?>"
                    class="ep-btn ep-btn-secondary">
                    <i class="fa-solid fa-file-export"></i> Exportar CSV
                </a>
                <?php if ($search || $type_filter || $assigned_filter): ?>
                    <a href="?view=inventory" class="ep-btn ep-btn-secondary"><i class="fa-solid fa-times"></i> Limpiar
                        Filtros</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="ep-tabs" style="margin-bottom: 25px;">
        <?php if ($perm !== 'manage_itinerant'): ?>
        <a href="?view=inventory&tab=items"
            class="ep-tab <?php echo ($active_tab === 'items') ? 'active' : ''; ?>">
            <i class="fa-solid fa-box"></i> Inventario Fijo
        </a>
        <?php endif; ?>
        <a href="?view=inventory&tab=loans"
            class="ep-tab <?php echo ($active_tab === 'loans') ? 'active' : ''; ?>">
            <i class="fa-solid fa-handshake"></i> Gestión de Préstamos
        </a>
        <?php if ($perm !== 'manage_itinerant'): ?>
        <a href="?view=inventory&tab=users"
            class="ep-tab <?php echo ($active_tab === 'users') ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i> Usuarios
        </a>
        <a href="?view=inventory&tab=requests"
            class="ep-tab <?php echo ($active_tab === 'requests') ? 'active' : ''; ?>">
            <i class="fa-solid fa-paper-plane"></i> Solicitudes
        </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['tab']) && $_GET['tab'] === 'users'): 
        // Lógica para la pestaña de Usuarios
        $users_with_items = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'ep_inventory_item' AND p.post_status = 'publish' AND pm.meta_key = '_ep_item_assigned_to' AND CAST(pm.meta_value AS UNSIGNED) > 0");
        ?>
        <div class="ep-table-responsive">
            <table class="ep-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Material Asignado (Fijo)</th>
                        <th>Estado Compromiso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users_with_items)):
                        foreach ($users_with_items as $user_id):
                            $user = get_userdata($user_id);
                            if (!$user) continue;

                            // List items assigned to this user
                            $user_items = get_posts(array(
                                'post_type' => 'ep_inventory_item',
                                'posts_per_page' => -1,
                                'meta_query' => array(
                                    array('key' => '_ep_item_assigned_to', 'value' => $user_id, 'compare' => '='),
                                    array('relation' => 'OR',
                                        array('key' => '_ep_item_is_itinerant', 'compare' => 'NOT EXISTS'),
                                        array('key' => '_ep_item_is_itinerant', 'value' => '1', 'compare' => '!=')
                                    )
                                )
                            ));

                            if (empty($user_items)) continue; // Si solo tiene itinerante, no aparece aquí

                            // Check commitment doc
                            $docs = get_posts(array(
                                'post_type' => 'ep_document',
                                'meta_query' => array(
                                    array('key' => '_ep_document_target_user', 'value' => $user_id),
                                    array('key' => '_ep_document_source_tag', 'value' => 'inventory_commitment'),
                                    array('key' => '_ep_document_type', 'value' => 'private')
                                ),
                                'posts_per_page' => 1,
                                'orderby' => 'ID',
                                'order' => 'DESC'
                            ));

                            $is_signed = false;
                            if (!empty($docs)) {
                                $is_signed = get_post_meta($docs[0]->ID, '_ep_document_is_signed', true) === '1';
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="ep-user-mini">
                                        <?php echo get_avatar($user_id, 32); ?>
                                        <div style="display:flex; flex-direction:column;">
                                            <span style="font-weight:600;"><?php echo esc_html($user->display_name); ?></span>
                                            <span style="font-size:11px; opacity:0.7;"><?php echo esc_html($user->user_email); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; flex-wrap:wrap; gap:5px;">
                                        <?php foreach ($user_items as $u_item): ?>
                                            <span class="ep-badge badge-gray" title="<?php echo esc_attr(get_post_meta($u_item->ID, '_ep_item_serial', true)); ?>">
                                                <i class="fa-solid fa-tag" style="font-size:10px;"></i> <?php echo esc_html($u_item->post_title); ?>
                                                <i class="fa-solid fa-times" style="margin-left:5px; cursor:pointer;" onclick="unassignSingleItem(<?php echo $u_item->ID; ?>)" title="Liberar este item"></i>
                                            </span>
                                        <?php endforeach; ?>
                                        <button class="ep-btn-icon" style="background:var(--ep-surface); border:1px dashed var(--ep-border); border-radius:4px;" onclick="openAssignModal(<?php echo $user_id; ?>, '<?php echo esc_js($user->display_name); ?>')" title="Asignar material">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if ($is_signed): ?>
                                            <span class="ep-badge-pill" style="background: rgba(var(--ep-success-rgb, 40, 167, 69), 0.1); color: var(--ep-success, #28a745);">
                                                <i class="fa-solid fa-signature"></i> Firmado
                                            </span>
                                        <?php else: ?>
                                            <span class="ep-badge-pill" style="background: rgba(var(--ep-warning-rgb, 255, 193, 7), 0.1); color: var(--ep-warning, #ffc107);">
                                                <i class="fa-solid fa-clock"></i> Pendiente
                                            </span>
                                        <?php endif; ?>
                                        
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" target="_blank" style="margin:0;">
                                            <input type="hidden" name="action" value="ep_inventory_download_commitment">
                                            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ep_inventory_download_commitment'); ?>">
                                            <button type="submit" class="ep-btn ep-btn-secondary" style="padding: 8px 12px;" title="Descargar Compromiso">
                                                <i class="fa-solid fa-file-pdf fa-lg"></i> 
                                            </button>
                                        </form>

                                        <button class="ep-btn ep-btn-secondary" style="padding: 8px 12px;" title="Subir Compromiso Firmado" onclick="document.getElementById('user-upload-<?php echo $user_id; ?>').click()">
                                            <i class="fa-solid fa-file-upload fa-lg"></i>
                                        </button>
                                        <input type="file" id="user-upload-<?php echo $user_id; ?>" style="display:none;" onchange="uploadSignedInventoryDoc(<?php echo $user_id; ?>, this)">

                                        <!-- Botón Imprimir Etiquetas Usuario -->
                                        <form method="get" action="<?php echo admin_url('admin-post.php'); ?>" target="_blank" style="margin:0;">
                                            <input type="hidden" name="action" value="ep_inventory_download_labels">
                                            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                            <button type="submit" class="ep-btn" style="background-color: #e67e22; color: white; padding: 8px 12px;" title="Imprimir todas las etiquetas (A4)">
                                                <i class="fa-solid fa-qrcode fa-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <button class="ep-btn ep-btn-danger ep-btn-sm" onclick="unassignUser(<?php echo $user_id; ?>)">
                                        <i class="fa-solid fa-user-xmark"></i> Liberar Todo
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="4">No hay usuarios con material fijo asignado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Assign Material Modal -->
        <div id="epAssignModal" class="ep-modal">
            <div class="ep-modal-content" style="max-width: 500px;">
                <div class="ep-modal-header">
                    <h3>Asignar Material a <span id="assign-user-name"></span></h3>
                    <button class="ep-modal-close" onclick="closeAssignModal()">&times;</button>
                </div>
                <div class="ep-modal-body">
                    <p style="margin-bottom:15px; font-size:14px; color:var(--ep-text-muted);">Selecciona los items disponibles para asignar a este usuario.</p>
                    <div id="available-items-list" style="max-height: 300px; overflow-y: auto;">
                        <!-- JS populated -->
                    </div>
                </div>
                <div class="ep-modal-footer">
                    <button class="ep-btn ep-btn-secondary" onclick="closeAssignModal()">Cancelar</button>
                </div>
            </div>
        </div>

    <?php elseif (isset($_GET['tab']) && $_GET['tab'] === 'requests'): 
        $req_args = array(
            'post_type' => 'ep_material_request',
            'posts_per_page' => 20,
            'paged' => get_query_var('paged') ? get_query_var('paged') : 1
        );
        $requests = new WP_Query($req_args);
        ?>
        <div class="ep-table-responsive">
            <table class="ep-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Solicitud</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests->have_posts()):
                        while ($requests->have_posts()):
                            $requests->the_post();
                            $req_user_id = get_post_meta(get_the_ID(), '_ep_request_user_id', true);
                            $req_user = get_userdata($req_user_id);
                            $status = get_post_meta(get_the_ID(), '_ep_request_status', true);
                            ?>
                            <tr>
                                <td><?php echo get_the_date('d/m/Y H:i'); ?></td>
                                <td>
                                    <?php if ($req_user): ?>
                                        <div class="ep-user-mini">
                                            <?php echo get_avatar($req_user_id, 24); ?>
                                            <span><?php echo esc_html($req_user->display_name); ?></span>
                                        </div>
                                    <?php else: ?>
                                        Usuario Desconocido
                                    <?php endif; ?>
                                </td>
                                <td><?php echo nl2br(get_the_content()); ?></td>
                                <td>
                                    <span class="ep-badge badge-gray"
                                        id="status-<?php echo get_the_ID(); ?>"><?php echo ucfirst($status ? $status : 'Pendiente'); ?></span>
                                </td>
                                <td>
                                    <?php 
                                    $signed_id = get_post_meta(get_the_ID(), '_ep_request_signed_doc_id', true);
                                    $signed_url = $signed_id ? wp_get_attachment_url($signed_id) : '';
                                    ?>
                                    <?php if (!$status || $status === 'pending'): ?>
                                        <div id="actions-<?php echo get_the_ID(); ?>" class="ep-actions-flex" style="gap:10px;">
                                            <!-- Download Commitment -->
                                            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                                <input type="hidden" name="action" value="ep_inventory_download_request_commitment">
                                                <input type="hidden" name="request_id" value="<?php echo get_the_ID(); ?>">
                                                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ep_inventory_download_request_' . get_the_ID()); ?>">
                                                <button type="submit" class="ep-btn ep-btn-secondary" style="padding: 8px 12px;" title="Descargar Compromiso">
                                                    <i class="fa-solid fa-file-pdf fa-lg"></i>
                                                </button>
                                            </form>

                                            <!-- Upload Signed -->
                                            <button class="ep-btn ep-btn-secondary" style="padding: 8px 12px;" title="Subir Compromiso Firmado" onclick="document.getElementById('upload-<?php echo get_the_ID(); ?>').click()">
                                                <i class="fa-solid fa-file-upload fa-lg"></i>
                                            </button>
                                            <input type="file" id="upload-<?php echo get_the_ID(); ?>" style="display:none;" onchange="uploadSignedDoc(<?php echo get_the_ID(); ?>, this)">

                                            <!-- Accept/Decline -->
                                            <button class="ep-btn ep-btn-success" style="padding: 8px 15px;" id="btn-accept-<?php echo get_the_ID(); ?>"
                                                onclick="updateRequestStatus(<?php echo get_the_ID(); ?>, 'accepted')"
                                                <?php echo !$signed_id ? 'disabled title="Se requiere subir el compromiso firmado"' : ''; ?>>
                                                <i class="fa-solid fa-check"></i> Aceptar
                                            </button>
                                            <button class="ep-btn ep-btn-danger" style="padding: 8px 15px;"
                                                onclick="updateRequestStatus(<?php echo get_the_ID(); ?>, 'declined')">
                                                <i class="fa-solid fa-times"></i> Rechazar
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($signed_url): ?>
                                        <a href="<?php echo esc_url($signed_url); ?>" target="_blank" class="ep-btn-icon" title="Ver Documento Firmado" style="margin-left: 10px;">
                                            <i class="fa-solid fa-file-circle-check" style="color: var(--ep-success);"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="5">No hay solicitudes pendientes.</td>
                        </tr>
                    <?php endif;
                    wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>
        <script>
            function uploadSignedDoc(requestId, input) {
                if (!input.files || !input.files[0]) return;
                
                const formData = new FormData();
                formData.append('action', 'ep_inventory_upload_signed_request');
                formData.append('request_id', requestId);
                formData.append('signed_doc', input.files[0]);
                formData.append('security', ep_inventory_vars.nonce);

                input.disabled = true;

                fetch(ep_inventory_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Documento subido correctamente. Ya puedes aceptar la solicitud.');
                        // Enable accept button
                        const acceptBtn = document.getElementById('btn-accept-' + requestId);
                        if (acceptBtn) {
                            acceptBtn.disabled = false;
                            acceptBtn.title = '';
                        }
                        location.reload(); // Quickest way to update UI with PDF icon
                    } else {
                        alert('Error: ' + data.data);
                        input.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error de conexión');
                    input.disabled = false;
                });
            }

            function updateRequestStatus(requestId, newStatus) {
                if (!confirm('¿Estás seguro de cambiar el estado a ' + newStatus + '?')) return;

                const formData = new FormData();
                formData.append('action', 'ep_update_request_status');
                formData.append('request_id', requestId);
                formData.append('status', newStatus);
                formData.append('security', ep_inventory_vars.nonce);

                fetch(ep_inventory_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Estado actualizado correctamente');
                            // Update UI
                            const badge = document.getElementById('status-' + requestId);
                            const actions = document.getElementById('actions-' + requestId);

                            if (badge) {
                                badge.innerText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                                badge.className = 'ep-badge ' + (newStatus === 'accepted' ? 'badge-green' : 'badge-red');
                            }
                            if (actions) actions.remove();
                        } else {
                            alert('Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error de conexión');
                    });
            }
        </script>
    <?php elseif ($active_tab === 'loans' || $active_tab === 'items'): ?>
        <table class="ep-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>Item</th>
                    <th>Tipo</th>
                    <th>Nº Serie</th>
                    <th>Responsable Interno</th>
                    <?php if ($active_tab === 'loans'): ?>
                        <th>Prestatario / Situación</th>
                    <?php endif; ?>
                    <th>Estado</th>
                    <th style="min-width:160px;">Ubicación / Notas</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($inventory->have_posts()):
                    while ($inventory->have_posts()):
                        $inventory->the_post();
                        $type = get_post_meta(get_the_ID(), '_ep_item_type', true);
                        $serial = get_post_meta(get_the_ID(), '_ep_item_serial', true);
                        $assigned_id = get_post_meta(get_the_ID(), '_ep_item_assigned_to', true);
                        $assigned_user = $assigned_id ? get_userdata($assigned_id) : null;
                        
                        $is_itinerant = get_post_meta(get_the_ID(), '_ep_item_is_itinerant', true);
                        $itinerant_status = get_post_meta(get_the_ID(), '_ep_item_itinerant_status', true) ?: 'available';
                        
                        // Nuevos campos de préstamo
                        $loaned_to_id = get_post_meta(get_the_ID(), '_ep_item_loaned_to', true);
                        $external_borrower = get_post_meta(get_the_ID(), '_ep_item_external_borrower', true);
                        $loaned_user = $loaned_to_id ? get_userdata($loaned_to_id) : null;

                        // Campos de info rápida (inline editables)
                        $item_location = get_post_meta(get_the_ID(), '_ep_item_location', true);
                        $item_notes    = get_post_meta(get_the_ID(), '_ep_item_notes', true);
                        ?>
                        <tr>
                            <td><input type="checkbox" class="item-checkbox" value="<?php echo get_the_ID(); ?>"></td>
                            <td>
                                <strong><?php the_title(); ?></strong>
                                <?php if ($is_itinerant): ?>
                                    <span class="ep-badge-pill"
                                        style="background: #e0f2fe; color: #0284c7; font-size: 10px; padding: 1px 5px;"
                                        title="Material Itinerante">
                                        <i class="fa-solid fa-laptop-medical"></i> IT
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ep-badge <?php echo $type === 'hardware' ? 'badge-blue' : 'badge-purple'; ?>">
                                    <?php echo ucfirst($type); ?>
                                </span>
                            </td>
                            <td><code style="font-size: 11px;"><?php echo esc_html($serial); ?></code></td>
                            <td>
                                <div class="ep-user-mini">
                                    <?php if ($assigned_id):
                                        if ($assigned_user): ?>
                                            <span><?php echo esc_html($assigned_user->display_name); ?></span>
                                        <?php else: ?>
                                            <span class="text-error">Usuario borrado</span>
                                        <?php endif;
                                    else: ?>
                                        <span>Sin asignar</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if ($active_tab === 'loans'): ?>
                                <td>
                                    <?php if ($loaned_user): ?>
                                        <span class="ep-badge badge-blue"><?php echo esc_html($loaned_user->display_name); ?></span>
                                    <?php elseif ($external_borrower): ?>
                                        <span class="ep-badge badge-gray"><?php echo esc_html($external_borrower); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 0.85em;">Disponible</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <div class="ep-row-actions">
                                    <?php if ($is_itinerant): ?>
                                        <?php if ($itinerant_status === 'available' || !$itinerant_status): ?>
                                            <button class="ep-btn ep-btn-sm ep-btn-success"
                                                onclick="itinerantCheckOut(<?php echo get_the_ID(); ?>)" title="Dar Salida (Préstamo)">
                                                <i class="fa-solid fa-sign-out-alt"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="ep-btn ep-btn-sm ep-btn-warning"
                                                onclick="itinerantCheckIn(<?php echo get_the_ID(); ?>)" title="Dar Entrada (Devolución)">
                                                <i class="fa-solid fa-sign-in-alt"></i>
                                            </button>
                                            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                                <input type="hidden" name="action" value="ep_inventory_download_itinerant_loan">
                                                <input type="hidden" name="item_id" value="<?php echo get_the_ID(); ?>">
                                                <input type="hidden" name="nonce"
                                                    value="<?php echo wp_create_nonce('ep_inventory_download_loan_' . get_the_ID()); ?>">
                                                <button type="submit" class="ep-btn ep-btn-sm"
                                                    style="background-color: #17a2b8; color: white;"
                                                    title="Descargar Justificante de Préstamo">
                                                    <i class="fa-solid fa-file-pdf"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- Botón QR Individual -->
                                    <form method="get" action="<?php echo admin_url('admin-post.php'); ?>" target="_blank" style="display:inline;">
                                        <input type="hidden" name="action" value="ep_inventory_download_labels">
                                        <input type="hidden" name="item_id" value="<?php echo get_the_ID(); ?>">
                                        <button type="submit" class="ep-btn" style="background-color: #e67e22; color: white; padding: 5px 10px;" title="Imprimir Etiqueta QR (70x35mm)">
                                            <i class="fa-solid fa-qrcode"></i>
                                        </button>
                                    </form>

                                    <?php if ($perm !== 'manage_itinerant'): ?>
                                    <button class="ep-btn ep-btn-sm" onclick="editItem(<?php echo get_the_ID(); ?>)" title="Editar">
                                        <i class="fa-solid fa-edit"></i>
                                    </button>
                                    <button class="ep-btn ep-btn-sm ep-btn-secondary"
                                        onclick="cloneItem(<?php echo get_the_ID(); ?>)" title="Clonar">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                    <button class="ep-btn ep-btn-sm ep-btn-danger" onclick="deleteItem(<?php echo get_the_ID(); ?>)"
                                        title="Eliminar">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- ===== COLUMNA INFO INLINE EDITABLE ===== -->
                            <td class="ep-info-cell">
                                <div class="ep-info-chips" data-id="<?php echo get_the_ID(); ?>">
                                    <!-- Ubicación -->
                                    <div class="ep-chip-wrap" title="Ubicación física del equipo">
                                        <i class="fa-solid fa-location-dot ep-chip-icon" style="color:#6366f1;"></i>
                                        <span class="ep-chip-display ep-chip-location"
                                              data-field="location"
                                              data-id="<?php echo get_the_ID(); ?>"
                                              onclick="epChipEdit(this)">
                                            <?php echo $item_location ? esc_html($item_location) : '<span class="ep-chip-placeholder">Ubicación...</span>'; ?>
                                        </span>
                                    </div>
                                    <!-- Observaciones -->
                                    <div class="ep-chip-wrap" title="Observaciones del item">
                                        <i class="fa-solid fa-note-sticky ep-chip-icon" style="color:#f59e0b;"></i>
                                        <span class="ep-chip-display ep-chip-notes"
                                              data-field="notes"
                                              data-id="<?php echo get_the_ID(); ?>"
                                              onclick="epChipEdit(this)">
                                            <?php echo $item_notes ? esc_html($item_notes) : '<span class="ep-chip-placeholder">Observaciones...</span>'; ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <!-- ===== FIN COLUMNA INFO ===== -->

                        </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7">No se encontraron items.</td>
                    </tr>
                <?php endif;
                wp_reset_postdata(); ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($inventory->max_num_pages > 1): ?>
    <div class="ep-pagination">
        <?php
        echo paginate_links(array(
            'base'      => add_query_arg('ep_paged', '%#%'),
            'format'    => '',
            'total'     => $inventory->max_num_pages,
            'current'   => $paged,
            'prev_text' => '<i class="fa-solid fa-chevron-left"></i> Anterior',
            'next_text' => 'Siguiente <i class="fa-solid fa-chevron-right"></i>'
        ));
        ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Modal Form -->
<div id="ep-inventory-modal" class="ep-modal-unified">
    <div class="ep-modal-content">
        <div class="ep-modal-header">
            <h2 id="modalTitle">Nuevo Item</h2>
            <span class="ep-close" onclick="closeInventoryModal()">&times;</span>
        </div>
        <div class="ep-modal-body">
            <form id="ep-inventory-form">
                <input type="hidden" id="item_id" name="id" value="0">
                <div class="ep-form-group">
                    <label>Nombre del Item</label>
                    <input type="text" name="title" id="item_title" required>
                </div>
                <div class="ep-row">
                    <div class="ep-col">
                        <label>Tipo</label>
                        <select name="type" id="item_type">
                            <option value="hardware">Hardware</option>
                            <option value="software">Software</option>
                        </select>
                    </div>
                    <div class="ep-col">
                        <label>Nº Serie / Licencia</label>
                        <input type="text" name="serial" id="item_serial">
                    </div>
                </div>
                <div class="ep-row">
                    <div class="ep-col">
                        <label>Fecha Compra</label>
                        <input type="date" name="purchase_date" id="item_purchase_date">
                    </div>
                    <div class="ep-col">
                        <label>Fin Garantía</label>
                        <input type="date" name="warranty_date" id="item_warranty_date">
                    </div>
                </div>
                <div class="ep-form-group">
                    <label>Proveedor</label>
                    <input type="text" name="provider" id="item_provider">
                </div>

                <div class="ep-row">
                    <div class="ep-col">
                        <label>
                            <input type="checkbox" name="is_itinerant" id="item_is_itinerant" value="1">
                            ¿Es Material Itinerante?
                        </label>
                    </div>
                    <div class="ep-col" id="itinerant_status_container" style="display: none;">
                        <label>Estado Préstamo</label>
                        <select name="itinerant_status" id="item_itinerant_status">
                            <option value="available">Disponible</option>
                            <option value="loaned">En Préstamo</option>
                            <option value="maintenance">En Mantenimiento</option>
                        </select>
                    </div>
                </div>
                <div class="ep-form-group">
                    <label>Asignar a Usuario</label>
                    <?php
                    wp_dropdown_users(array(
                        'name' => 'assigned_to',
                        'id' => 'item_assigned_to',
                        'show_option_none' => '— Sin Asignar —',
                        'class' => 'ep-select-full'
                    ));
                    ?>
                </div>
        </div>
        <div class="ep-modal-actions">
            <button type="button" class="ep-btn ep-btn-secondary" onclick="closeInventoryModal()">Cancelar</button>
            <button type="submit" class="ep-btn ep-btn-primary">Guardar</button>
        </div>
        </form>
    </div>
</div>

<!-- Modal: Salida de Material Itinerante -->
<div id="itinerant-checkout-modal" class="ep-modal">
    <div class="ep-modal-content">
        <div class="ep-modal-header">
            <h2><i class="fa-solid fa-right-from-bracket"></i> Registrar Salida de Material</h2>
            <span class="ep-close" onclick="closeItinerantModal()">&times;</span>
        </div>
        <div class="ep-modal-body">
            <form id="ep-itinerant-checkout-form">
                <input type="hidden" name="item_id" id="checkout_item_id">

                <div class="ep-form-group">
                    <label>Prestatario (Personal Interno)</label>
                    <?php
                    wp_dropdown_users(array(
                        'name' => 'user_id',
                        'id' => 'checkout_user_id',
                        'show_option_none' => '— Seleccionar Usuario del Portal —',
                        'class' => 'ep-select-full'
                    ));
                    ?>
                    <p class="description" style="font-size: 0.75em; margin-top: 5px;">Selecciona al compañero que se lleva el material.</p>
                </div>

                <div
                    style="text-align: center; margin: 10px 0; color: var(--ep-text-muted); font-size: 0.8em; font-weight: bold;">
                    — O —</div>

                <div class="ep-form-group">
                    <label>Persona Externa</label>
                    <input type="text" name="external_name" id="checkout_external_name"
                        placeholder="Ej: Juan Pérez (Empresa X)">
                    <p class="description" style="font-size: 0.75em; margin-top: 5px;">Usa este campo si el material se
                        presta a alguien ajeno a la organización.</p>
                </div>

                <div class="ep-row">
                    <div class="ep-col">
                        <label>Ubicación del Trabajo</label>
                        <input type="text" name="loan_location" id="checkout_loan_location" placeholder="Ej: Miajadas, Cáceres...">
                    </div>
                </div>

                <div class="ep-row">
                    <div class="ep-col">
                        <label>Fecha de Salida</label>
                        <input type="date" name="loan_date" id="checkout_loan_date"
                            value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="ep-col">
                        <label>Devolución Prevista</label>
                        <input type="date" name="return_date" id="checkout_return_date">
                    </div>
                </div>

                <div class="ep-form-group">
                    <label>Cargo del Prestatario</label>
                    <input type="text" name="borrower_cargo" id="checkout_borrower_cargo" placeholder="Ej: Técnico de Proyecto, Formador...">
                    <p class="description" style="font-size: 0.75em; margin-top: 5px;">Si es personal interno y se deja vacío, se intentará obtener de su perfil.</p>
                </div>

                <div class="ep-form-group">
                    <label>NIF / DNI del Prestatario</label>
                    <input type="text" name="borrower_nif" id="checkout_borrower_nif" placeholder="Ej: 12345678X">
                    <p class="description" style="font-size: 0.75em; margin-top: 5px;">Obligatorio para el documento de cesión. No se guarda permanentemente.</p>
                </div>

                <div class="ep-form-actions">
                    <button type="button" class="ep-btn ep-btn-secondary"
                        onclick="closeItinerantModal()">Cancelar</button>
                    <button type="submit" class="ep-btn ep-btn-primary">Registrar Salida</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
</div>
</div>