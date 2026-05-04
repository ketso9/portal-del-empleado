<?php
defined('ABSPATH') || exit;

$current_user_id = get_current_user_id();

// Get Assigned Items
$args = array(
    'post_type' => 'ep_inventory_item',
    'posts_per_page' => -1,
    'meta_query' => array(
        array(
            'key' => '_ep_item_assigned_to',
            'value' => $current_user_id,
            'compare' => '='
        )
    )
);
$my_items = get_posts($args);
?>

<div class="ep-app-container">
    <div class="ep-app-header">
        <h1>Inventario</h1>
    </div>

    <div class="ep-tabs">
        <button class="ep-tab-btn active" data-tab="my-inventory">
            <i class="fa-solid fa-box-open"></i> Mi Inventario
        </button>
        <button class="ep-tab-btn" data-tab="itinerant-inventory">
            <i class="fa-solid fa-laptop-medical"></i> Material Itinerante
        </button>
    </div>

    <!-- Tab: My Inventory -->
    <div id="my-inventory" class="ep-tab-content active">
        <div class="ep-inventory-grid">
            <?php if ($my_items):
                foreach ($my_items as $item):
                    $type = get_post_meta($item->ID, '_ep_item_type', true);
                    $serial = get_post_meta($item->ID, '_ep_item_serial', true);
                    $warranty = get_post_meta($item->ID, '_ep_item_warranty_date', true);
                    ?>
                    <div class="ep-card">
                        <div class="ep-card-icon <?php echo $type; ?>">
                            <i class="fa-solid <?php echo $type === 'hardware' ? 'fa-laptop' : 'fa-compact-disc'; ?>"></i>
                        </div>
                        <div class="ep-card-body">
                            <h3><?php echo esc_html($item->post_title); ?></h3>
                            <p><strong>Nº Serie:</strong> <?php echo esc_html($serial); ?></p>
                            <?php if ($warranty): ?>
                                <p class="text-xs">Garantía hasta: <?php echo date_i18n('d/m/Y', strtotime($warranty)); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="ep-card-qr">
                            <div id="qr-<?php echo $item->ID; ?>" class="ep-qr-code" data-id="<?php echo $item->ID; ?>"
                                data-serial="<?php echo esc_attr($serial); ?>"></div>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                <p>No tienes material asignado actualmente.</p>
            <?php endif; ?>
        </div>

        <hr class="ep-divider">

        <div class="ep-requests-section" style="margin-bottom: 30px;">
            <h3>Mis Solicitudes Recientes</h3>
            <?php
            $request_args = array(
                'post_type' => 'ep_material_request',
                'author' => $current_user_id,
                'posts_per_page' => 10
            );
            $my_requests = get_posts($request_args);
            ?>
            <?php if ($my_requests): ?>
                <div class="ep-list-group">
                    <?php foreach ($my_requests as $req):
                        $status = get_post_meta($req->ID, '_ep_request_status', true);
                        ?>
                        <div class="ep-list-item">
                            <div class="ep-list-info">
                                <div class="ep-list-date"><?php echo get_the_date('d/m/Y', $req->ID); ?></div>
                                <div class="ep-list-text"><?php echo wp_trim_words($req->post_content, 15); ?></div>
                            </div>
                            <div class="ep-list-actions" style="display: flex; gap: 8px; align-items: center;">
                                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="ep_inventory_download_request_commitment">
                                    <input type="hidden" name="request_id" value="<?php echo $req->ID; ?>">
                                    <input type="hidden" name="nonce"
                                        value="<?php echo wp_create_nonce('ep_inventory_download_request_' . $req->ID); ?>">
                                    <button type="submit" class="ep-btn-icon" title="Descargar Compromiso para Firmar">
                                        <i class="fa-solid fa-file-pdf"></i>
                                    </button>
                                </form>
                                <span class="ep-badge-pill status-<?php echo $status; ?>">
                                    <?php echo ($status === 'accepted' ? 'Aceptada' : ($status === 'declined' ? 'Rechazada' : 'Pendiente')); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">No has realizado ninguna solicitud.</p>
            <?php endif; ?>
        </div>

        <div class="ep-request-section">
            <h2>Solicitar Material General</h2>
            <form id="ep-request-material-form" class="ep-form">
                <div class="ep-form-group">
                    <label>¿Qué necesitas?</label>
                    <textarea name="request_details" rows="3"
                        placeholder="Describe el hardware o software que necesitas..." required></textarea>
                </div>
                <div class="ep-form-actions">
                    <button type="submit" class="ep-btn ep-btn-primary">Enviar Solicitud</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab: Itinerant Inventory -->
    <div id="itinerant-inventory" class="ep-tab-content">

        <?php
        // Notification for assigned itinerant material
        $my_it_args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_item_is_itinerant',
                    'value' => '1',
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_item_assigned_to',
                    'value' => $current_user_id,
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_item_itinerant_status',
                    'value' => 'loaned',
                    'compare' => '='
                )
            )
        );
        $my_it_items = get_posts($my_it_args);

        if ($my_it_items): ?>
            <div class="ep-alert ep-alert-info">
                <div class="ep-alert-icon"><i class="fa-solid fa-circle-info"></i></div>
                <div class="ep-alert-content">
                    <b>Tienes material itinerante asignado</b>
                    <?php foreach ($my_it_items as $it):
                        $ret_date = get_post_meta($it->ID, '_ep_item_estimated_return', true);
                        echo esc_html($it->post_title) . ($ret_date ? " (Devolver el " . date('d/m/Y', strtotime($ret_date)) . ")" : "") . "<br>";
                    endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="ep-section-intro">
            <p>Material disponible para reserva temporal (cursos, salidas, etc.). Selecciona uno o varios equipos para
                solicitar una reserva conjunta.</p>
        </div>

        <?php
        $it_args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_ep_item_is_itinerant',
                    'value' => '1',
                    'compare' => '='
                )
            )
        );
        $it_items = get_posts($it_args);
        ?>

        <div class="ep-inventory-grid">
            <?php if ($it_items):
                foreach ($it_items as $item):
                    $it_status = get_post_meta($item->ID, '_ep_item_itinerant_status', true) ?: 'available';
                    $type = get_post_meta($item->ID, '_ep_item_type', true);
                    $assigned_user = (int) get_post_meta($item->ID, '_ep_item_assigned_to', true);

                    $is_mine = ($assigned_user === $current_user_id);
                    $is_available = ($it_status === 'available');
                    ?>
                    <div class="ep-card itinerant-card status-<?php echo $it_status; ?> <?php echo (!$is_available && !$is_mine) ? 'disabled' : ''; ?>"
                        data-id="<?php echo $item->ID; ?>" data-title="<?php echo esc_attr($item->post_title); ?>">

                        <?php if ($is_available): ?>
                            <div class="ep-card-select">
                                <input type="checkbox" class="itinerant-checkbox" value="<?php echo $item->ID; ?>"
                                    data-title="<?php echo esc_attr($item->post_title); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="ep-card-icon <?php echo $type; ?>">
                            <i class="fa-solid <?php echo $type === 'hardware' ? 'fa-laptop' : 'fa-compact-disc'; ?>"></i>
                        </div>
                        <div class="ep-card-body">
                            <h3><?php echo esc_html($item->post_title); ?></h3>
                            <div class="ep-status-badge status-<?php echo $it_status; ?> <?php echo $is_mine ? 'mine' : ''; ?>">
                                <span class="dot"></span>
                                <?php
                                if ($is_mine) {
                                    echo 'Asignado a ti';
                                } else {
                                    echo ($it_status === 'available' ? 'Disponible' : ($it_status === 'loaned' ? 'En préstamo' : 'Mantenimiento'));
                                }
                                ?>
                            </div>
                        </div>
                        <div class="ep-card-footer">
                            <?php if ($is_available): ?>
                                <button class="ep-btn ep-btn-sm ep-btn-outline"
                                    onclick="openSingleRequest(<?php echo $item->ID; ?>, '<?php echo esc_attr($item->post_title); ?>')">
                                    Solicitar ahora
                                </button>
                            <?php elseif ($is_mine): ?>
                                <span class="ep-text-success text-xs font-bold">Ya lo tienes</span>
                            <?php else: ?>
                                <span class="ep-text-muted text-xs">No disponible</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                <p>No hay material itinerante registrado.</p>
            <?php endif; ?>
        </div>

        <?php
        // Section: Material currently in loan
        $loaned_args = array(
            'post_type' => 'ep_inventory_item',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_item_is_itinerant',
                    'value' => '1',
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_item_itinerant_status',
                    'value' => 'loaned',
                    'compare' => '='
                )
            )
        );
        $loaned_items = get_posts($loaned_args);

        // Let's also find items with 'accepted' requests that might not have been synced (legacy support)
        if (!$loaned_items) {
            $acc_args = array(
                'post_type' => 'ep_material_request',
                'meta_query' => array(
                    array('key' => '_ep_request_status', 'value' => 'accepted'),
                    array('key' => '_ep_request_is_itinerant', 'value' => '1')
                )
            );
            $acc_requests = get_posts($acc_args);
            $loaned_item_ids = [];
            foreach ($acc_requests as $ar) {
                $ids = explode(',', get_post_meta($ar->ID, '_ep_request_item_ids', true));
                $loaned_item_ids = array_merge($loaned_item_ids, $ids);
            }
            if (!empty($loaned_item_ids)) {
                $loaned_items = get_posts(array(
                    'post_type' => 'ep_inventory_item',
                    'post__in' => array_unique($loaned_item_ids)
                ));
            }
        }
        ?>

        <?php if ($loaned_items): ?>
            <div class="ep-availability-section" style="margin-top: 40px;">
                <div class="ep-section-header" style="margin-bottom: 15px;">
                    <h3><i class="fa-solid fa-calendar-check"></i> Equipos actualmente en préstamo</h3>
                    <p class="text-muted text-xs">Consulta quién tiene el material y su fecha prevista de retorno.</p>
                </div>
                <div class="ep-list-group">
                    <?php foreach ($loaned_items as $li):
                        $li_assigned_to = get_post_meta($li->ID, '_ep_item_assigned_to', true);
                        $li_external = get_post_meta($li->ID, '_ep_item_external_borrower', true);
                        $li_return = get_post_meta($li->ID, '_ep_item_estimated_return', true);

                        $borrower_li = 'Privado';
                        if ($li_assigned_to) {
                            $li_user = get_userdata($li_assigned_to);
                            $borrower_li = $li_user ? $li_user->display_name : 'Usuario';
                        } elseif ($li_external) {
                            $borrower_li = $li_external;
                        }
                        ?>
                        <div class="ep-list-item availability-item">
                            <div class="ep-list-info">
                                <div class="ep-list-text"><strong><?php echo esc_html($li->post_title); ?></strong></div>
                                <div class="ep-list-subtext">En posesión de: <?php echo esc_html($borrower_li); ?></div>
                            </div>
                            <div class="ep-list-date-badge">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                <span>Devolución:
                                    <?php echo $li_return ? date('d/m/Y', strtotime($li_return)) : 'Sin fecha'; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Barra de Acción Flotante (Selección Múltiple) -->
<div id="ep-selection-bar" class="ep-selection-bar">
    <div class="selection-info">
        <span id="selection-count">0</span> ítems seleccionados
    </div>
    <div class="selection-actions">
        <button class="ep-btn ep-btn-primary" onclick="openBulkRequest()">
            <i class="fa-solid fa-calendar-check"></i> Solicitar Reserva Conjunta
        </button>
        <button class="ep-btn ep-btn-secondary" onclick="clearSelection()">Cancelar</button>
    </div>
</div>

<!-- Modal: Solicitud de Reserva de Material -->
<div id="ep-user-request-modal" class="ep-modal">
    <div class="ep-modal-content">
        <div class="ep-modal-header">
            <h2><i class="fa-solid fa-calendar-plus"></i> Nueva Solicitud de Reserva</h2>
            <span class="ep-close" onclick="closeUserRequestModal()">&times;</span>
        </div>
        <div class="ep-modal-body">
            <form id="ep-user-itinerant-request-form">
                <input type="hidden" name="item_ids" id="req_item_ids">

                <div class="ep-form-group">
                    <label>Materiales Solicitados</label>
                    <div id="req_items_display" class="ep-selected-items-list"></div>
                </div>

                <div class="ep-row">
                    <div class="ep-col">
                        <label>Fecha Inicio</label>
                        <input type="date" name="start_date" id="req_start_date" value="<?php echo date('Y-m-d'); ?>"
                            required>
                    </div>
                    <div class="ep-col">
                        <label>Fecha Fin (Estimada)</label>
                        <input type="date" name="end_date" id="req_end_date" required>
                    </div>
                </div>

                <div class="ep-form-group">
                    <label>Motivo o Proyecto / Curso</label>
                    <textarea name="reason" id="req_reason" rows="3"
                        placeholder="Ej: Curso de programación en el aula B, Proyecto Auditoría..." required></textarea>
                </div>

                <div class="ep-modal-actions">
                    <button type="button" class="ep-btn ep-btn-secondary"
                        onclick="closeUserRequestModal()">Cancelar</button>
                    <button type="submit" class="ep-btn ep-btn-primary">Enviar Solicitud</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function ($) {
        // Tab switching
        $('.ep-tab-btn').on('click', function () {
            const tabId = $(this).data('tab');
            $('.ep-tab-btn').removeClass('active');
            $('.ep-tab-content').removeClass('active');
            $(this).addClass('active');
            $('#' + tabId).addClass('active');
        });

        // Multi-selection logic
        $(document).on('change', '.itinerant-checkbox', function () {
            updateSelectionBar();
        });
    });

    function updateSelectionBar() {
        const count = jQuery('.itinerant-checkbox:checked').length;
        const bar = jQuery('#ep-selection-bar');
        if (count > 0) {
            bar.addClass('show');
            jQuery('#selection-count').text(count);
        } else {
            bar.removeClass('show');
        }
    }

    function clearSelection() {
        jQuery('.itinerant-checkbox').prop('checked', false);
        updateSelectionBar();
    }

    function openSingleRequest(id, title) {
        clearSelection();
        updateRequestModalItems([{ id: id, title: title }]);
        document.getElementById('ep-user-request-modal').classList.add('show');
    }

    function openBulkRequest() {
        const selected = [];
        const titles = [];
        jQuery('.itinerant-checkbox:checked').each(function () {
            selected.push(jQuery(this).val());
            titles.push({ id: jQuery(this).val(), title: jQuery(this).data('title') });
        });

        updateRequestModalItems(titles);
        document.getElementById('ep-user-request-modal').classList.add('show');
    }

    function updateRequestModalItems(items) {
        const ids = items.map(it => it.id);
        jQuery('#req_item_ids').val(ids.join(','));

        let html = '';
        items.forEach(it => {
            html += '<span class="ep-tag" data-id="' + it.id + '">' +
                it.title +
                ' <i class="fa-solid fa-times tag-remove" onclick="removeRequestItem(' + it.id + ')"></i>' +
                '</span>';
        });
        jQuery('#req_items_display').html(html);

        // Update checkboxes in main view to stay in sync
        jQuery('.itinerant-checkbox').prop('checked', false);
        ids.forEach(id => {
            jQuery('.itinerant-checkbox[value="' + id + '"]').prop('checked', true);
        });
        updateSelectionBar();
    }

    function removeRequestItem(id) {
        let idsRaw = jQuery('#req_item_ids').val();
        let ids = idsRaw.split(',').filter(x => x != id);

        if (ids.length === 0) {
            closeUserRequestModal();
            return;
        }

        // Rebuild titles from existing tags
        const remainingItems = [];
        jQuery('#req_items_display .ep-tag').each(function () {
            const tagId = jQuery(this).data('id');
            if (tagId != id) {
                remainingItems.push({
                    id: tagId,
                    title: jQuery(this).contents().get(0).nodeValue.trim()
                });
            }
        });

        updateRequestModalItems(remainingItems);
    }

    function closeUserRequestModal() {
        document.getElementById('ep-user-request-modal').classList.remove('show');
    }

    jQuery('#ep-user-itinerant-request-form').on('submit', function (e) {
        e.preventDefault();
        const formData = {
            action: 'ep_inventory_request_material',
            security: ep_inventory_vars.nonce,
            item_ids: jQuery('#req_item_ids').val(),
            start_date: jQuery('#req_start_date').val(),
            end_date: jQuery('#req_end_date').val(),
            reason: jQuery('#req_reason').val(),
            is_itinerant: true
        };

        jQuery.ajax({
            url: ep_inventory_vars.ajax_url,
            type: 'POST',
            data: formData,
            success: function (response) {
                if (response.success) {
                    alert('Solicitud enviada correctamente. El gestor se pondrá en contacto contigo.');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    });
</script>