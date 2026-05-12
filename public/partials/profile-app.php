<?php

defined('ABSPATH') || exit;

// Determine Target User
$current_logged_in_user = wp_get_current_user();
$target_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_logged_in_user->ID;

$target_user = get_userdata($target_user_id);
if (!$target_user) {
    echo '<div class="ep-alert error">Usuario no encontrado.</div>';
    return;
}

$is_editable = ($current_logged_in_user->ID === $target_user->ID) || current_user_can('manage_options');

// Fetch User Meta
$phone = get_user_meta($target_user->ID, 'ep_mobile_phone', true);
$office_phone = get_user_meta($target_user->ID, 'ep_business_phone', true);
$extension = get_user_meta($target_user->ID, 'ep_office_location', true);
$department = get_user_meta($target_user->ID, 'ep_department', true);
$job_title = get_user_meta($target_user->ID, 'ep_job_title', true);
$photo_url = get_user_meta($target_user->ID, 'ep_user_photo_url', true);

// Header Avatar (Logged in User)
$header_photo = get_user_meta($current_logged_in_user->ID, 'ep_user_photo_url', true);
$small_avatar_html = $header_photo ? '<img src="' . esc_url($header_photo) . '" alt="Profile Photo">' : get_avatar($current_logged_in_user->ID, 32);

// Profile Avatar (Target User)
$avatar_html = $photo_url ? '<img src="' . esc_url($photo_url) . '" alt="Profile Photo">' : get_avatar($target_user->ID, 96);

// Fetch Assigned Inventory
$args = array(
    'post_type' => 'ep_inventory_item',
    'posts_per_page' => -1,
    'meta_query' => array(
        array(
            'key' => '_ep_item_assigned_to',
            'value' => $target_user->ID,
            'compare' => '='
        )
    )
);
$inventory_items = get_posts($args);

// Phase 4: M365 Integration (Only for self or if admin?)
$oof_settings = null;
$activity_insights = null;

if ($is_editable) {
    $auth = EP_Auth_O365::get_instance();
    $oof_settings = $auth->get_mailbox_settings($target_user->ID);
    $activity_insights = $auth->get_activity_insights($target_user->ID);
}
?>

<div class="ep-profile-app">
    <div class="ep-profile-header-card ep-card">
        <div class="ep-profile-cover"></div>
        <div class="ep-profile-info-main">
            <div class="ep-profile-avatar-large">
                <?php echo $avatar_html; ?>
            </div>
            <div class="ep-profile-text">
                <h2><?php echo esc_html($target_user->display_name); ?></h2>
                <p class="ep-role-badge">
                    <?php echo !empty($department) ? esc_html($department) : 'Empleado'; ?>
                </p>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="ep-profile-tabs">
            <button class="ep-tab-btn active" data-tab="tab-general">
                <i class="fa-solid fa-user"></i> General
            </button>
            <?php if ($is_editable): ?>
                <button class="ep-tab-btn" data-tab="tab-ausencias">
                    <i class="fa-solid fa-plane-departure"></i> Ausencias
                </button>
                <button class="ep-tab-btn" data-tab="tab-actividad">
                    <i class="fa-solid fa-chart-line"></i> Actividad
                </button>
            <?php endif; ?>
            <button class="ep-tab-btn" data-tab="tab-material">
                <i class="fa-solid fa-laptop"></i> Material
            </button>
        </div>
    </div>

    <div class="ep-profile-content">
        <!-- Tab: General -->
        <div id="tab-general" class="ep-tab-content active">
            <div class="ep-card">
                <h3>Información Personal</h3>
                <form class="ep-profile-form" id="<?php echo $is_editable ? 'epProfileForm' : ''; ?>">
                    <div class="ep-form-grid">
                        <div class="form-group">
                            <label>Nombre</label>
                            <input type="text" value="<?php echo esc_attr($target_user->first_name); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Apellidos</label>
                            <input type="text" value="<?php echo esc_attr($target_user->last_name); ?>" readonly>
                        </div>
                        <div class="form-group full-width-input">
                            <label>Nombre para mostrar</label>
                            <input type="text" value="<?php echo esc_attr($target_user->display_name); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Puesto</label>
                            <input type="text" name="job_title" id="epJobTitle"
                                value="<?php echo esc_attr($job_title); ?>" placeholder="Ej: Técnico" <?php echo $is_editable ? '' : 'readonly'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Departamento</label>
                            <input type="text" name="department" id="epDepartment"
                                value="<?php echo esc_attr($department); ?>" placeholder="Ej: IT" <?php echo $is_editable ? '' : 'readonly'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Oficina</label>
                            <input type="text" name="extension" id="epExtension"
                                value="<?php echo esc_attr($extension); ?>" placeholder="Ej: Madrid" <?php echo $is_editable ? '' : 'readonly'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Teléfono de la oficina (extensión)</label>
                            <input type="text" name="office_phone" id="epOfficePhone"
                                value="<?php echo esc_attr($office_phone); ?>" placeholder="Ej: 910000000" <?php echo $is_editable ? '' : 'readonly'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Teléfono móvil</label>
                            <input type="text" name="phone" id="epPhone" value="<?php echo esc_attr($phone); ?>"
                                placeholder="Ej: +34 600 000 000" <?php echo $is_editable ? '' : 'readonly'; ?>>
                        </div>
                        <div class="form-group full-width-input">
                            <label>Correo Electrónico</label>
                            <input type="email" value="<?php echo esc_attr($target_user->user_email); ?>" readonly>
                        </div>
                    </div>
                    <?php if ($is_editable): ?>
                        <div class="ep-form-actions" style="display: flex; gap: 10px; align-items: center;">
                            <button type="submit" class="ep-btn" id="epSaveProfileBtn">Guardar Cambios</button>
                            <button type="button" class="ep-btn ep-btn-secondary" id="epSyncFromM365Btn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
                                <i class="fa-solid fa-sync"></i> Sincronizar desde M365
                            </button>
                            <span id="epProfileMessage" style="margin-left: 10px; font-size: 0.9rem;"></span>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($is_editable): ?>
            <!-- Tab: Ausencias (OOF) -->
            <div id="tab-ausencias" class="ep-tab-content">
                <div class="ep-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3>Respuestas Automáticas (Outlook)</h3>
                        <?php if (is_wp_error($oof_settings)): ?>
                            <span class="ep-badge badge-red">Error de conexión M365</span>
                        <?php endif; ?>
                    </div>

                    <?php if (is_wp_error($oof_settings)): ?>
                        <div class="ep-alert warning">
                            No se pudo conectar con Microsoft 365 para gestionar tus ausencias.
                            <br><small><?php echo $oof_settings->get_error_message(); ?></small>
                        </div>
                    <?php else:
                        $oof = $oof_settings['automaticRepliesSetting'] ?? [];
                        $status = $oof['status'] ?? 'disabled';
                        ?>
                        <form id="epOofForm" class="ep-form">
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="switch-label">
                                    <span>Activar respuestas automáticas</span>
                                    <input type="checkbox" id="oofStatus" <?php checked($status !== 'disabled'); ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div id="oofDetails" style="<?php echo $status === 'disabled' ? 'display:none;' : ''; ?>">
                                <div class="form-group">
                                    <label>Mensaje para compañeros (Interno)</label>
                                    <textarea id="internalReply" rows="4"
                                        placeholder="Ej: Hola, estaré fuera hasta el lunes..."><?php echo esc_textarea($oof['internalReplyMessage'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Mensaje para clientes (Externo)</label>
                                    <textarea id="externalReply" rows="4"
                                        placeholder="Ej: Gracias por su mensaje, le atenderemos a la vuelta..."><?php echo esc_textarea($oof['externalReplyMessage'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="ep-form-actions">
                                <button type="submit" class="ep-btn ep-btn-primary" id="saveOofBtn">
                                    <i class="fa-solid fa-save"></i> Guardar en Outlook
                                </button>
                                <span id="oofMessage" style="margin-left: 10px;"></span>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: Actividad (Insights) -->
            <div id="tab-actividad" class="ep-tab-content">
                <div class="ep-card">
                    <h3>Resumen de Actividad (Últimos 30 días)</h3>

                    <?php if (is_wp_error($activity_insights)): ?>
                        <div class="ep-alert warning">No se pudieron cargar las analíticas de actividad.</div>
                    <?php else: ?>
                        <div class="ep-stats-grid">
                            <div class="ep-stat-card">
                                <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                                <div class="stat-value"><?php echo $activity_insights['total_meetings']; ?></div>
                                <div class="stat-label">Reuniones</div>
                            </div>
                            <div class="ep-stat-card">
                                <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                                <div class="stat-value"><?php echo round($activity_insights['total_hours'], 1); ?>h</div>
                                <div class="stat-label">Tiempo en reuniones</div>
                            </div>
                            <div class="ep-stat-card">
                                <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
                                <div class="stat-value">
                                    <?php echo !empty($activity_insights['most_busy_day']) ? date_i18n('d M', strtotime($activity_insights['most_busy_day'])) : '-'; ?>
                                </div>
                                <div class="stat-label">Día de mayor carga</div>
                            </div>
                        </div>

                        <div
                            style="margin-top: 30px; padding: 20px; background: var(--ep-bg); border-radius: var(--ep-radius-sm);">
                            <h4><i class="fa-solid fa-lightbulb" style="color: #fbbf24;"></i> Insights de IA</h4>
                            <p style="margin-top: 10px; color: var(--ep-text-muted);">
                                <?php if ($activity_insights['total_hours'] > 40): ?>
                                    Has dedicado mucho tiempo a reuniones este mes. Considera bloquear "Focus Time" en tu calendario
                                    para tareas críticas.
                                <?php else: ?>
                                    Tu balance de reuniones es saludable. Tienes buen espacio para el trabajo individual.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tab: Material -->
        <div id="tab-material" class="ep-tab-content <?php echo !$is_editable ? 'active' : ''; ?>">
            <div class="ep-card">
                <h3><i class="fa-solid fa-laptop"></i> Material Asignado</h3>
                <?php if (!empty($inventory_items)): ?>
                    <div class="ep-table-responsive">
                        <table class="ep-table">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Item</th>
                                    <th>Serie</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory_items as $item):
                                    $type = get_post_meta($item->ID, '_ep_item_type', true);
                                    $serial = get_post_meta($item->ID, '_ep_item_serial', true);
                                    ?>
                                    <tr>
                                        <td>
                                            <span
                                                class="ep-badge <?php echo $type === 'hardware' ? 'badge-blue' : 'badge-purple'; ?>">
                                                <?php echo ucfirst($type); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo esc_html($item->post_title); ?></strong></td>
                                        <td><small><?php echo esc_html($serial); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No hay material asignado actualmente.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .ep-profile-app {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }

    .ep-profile-header-card {
        padding: 0;
        overflow: hidden;
        margin-bottom: 20px;
    }

    .ep-profile-cover {
        height: 120px;
        background: linear-gradient(135deg, var(--ep-primary), #4a0d15);
    }

    .ep-profile-info-main {
        display: flex;
        align-items: flex-start;
        gap: 20px;
        padding: 0 30px;
        margin-bottom: 20px;
    }

    .ep-profile-avatar-large {
        margin-top: -60px;
        position: relative;
        z-index: 2;
    }

    .ep-profile-avatar-large img {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 5px solid #fff;
        box-shadow: var(--ep-shadow);
        background: #fff;
    }

    .ep-profile-text {
        padding-top: 80px;
    }

    .ep-profile-text h2 {
        margin: 0 0 5px 0;
        font-size: 1.8rem;
        color: var(--ep-text);
        font-weight: 800;
    }

    .ep-role-badge {
        display: inline-block;
        background: rgba(var(--ep-primary-rgb), 0.1);
        color: var(--ep-primary);
        padding: 4px 12px;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Tabs Styling */
    .ep-profile-tabs {
        display: flex;
        padding: 0 30px;
        gap: 20px;
        border-top: 1px solid var(--ep-border);
        background: #fafafa;
    }

    .ep-tab-btn {
        background: transparent;
        border: none;
        padding: 15px 5px;
        cursor: pointer;
        font-weight: 600;
        color: var(--ep-text-muted);
        border-bottom: 3px solid transparent;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .ep-tab-btn i {
        font-size: 0.9rem;
    }

    .ep-tab-btn:hover {
        color: var(--ep-primary);
    }

    .ep-tab-btn.active {
        color: var(--ep-primary);
        border-bottom-color: var(--ep-primary);
    }

    .ep-tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }

    .ep-tab-content.active {
        display: block;
    }

    /* Activity Stats Cards */
    .ep-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .ep-stat-card {
        background: #fff;
        padding: 20px;
        border-radius: var(--ep-radius-sm);
        border: 1px solid var(--ep-border);
        text-align: center;
    }

    .stat-icon {
        font-size: 1.5rem;
        color: var(--ep-primary);
        margin-bottom: 10px;
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--ep-text);
    }

    .stat-label {
        color: var(--ep-text-muted);
        font-size: 0.9rem;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

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

        // OOF Status toggle
        $('#oofStatus').on('change', function () {
            if ($(this).is(':checked')) {
                $('#oofDetails').slideDown();
            } else {
                $('#oofDetails').slideUp();
            }
        });

        // Save OOF
        $('#epOofForm').on('submit', function (e) {
            e.preventDefault();
            const $btn = $('#saveOofBtn');
            const $msg = $('#oofMessage');

            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');

            const data = {
                action: 'ep_update_oof_settings',
                security: ep_vars.nonce,
                status: $('#oofStatus').is(':checked') ? 'scheduled' : 'disabled',
                internal_reply: $('#internalReply').val(),
                external_reply: $('#externalReply').val()
            };

            $.post(ep_vars.ajax_url, data, function (res) {
                if (res.success) {
                    $msg.html('<span style="color: green;"><i class="fa-solid fa-check"></i> Actualizado en Outlook</span>');
                } else {
                    $msg.html('<span style="color: red;"><i class="fa-solid fa-times"></i> Error: ' + res.data + '</span>');
                }
                $btn.prop('disabled', false).html('<i class="fa-solid fa-save"></i> Guardar en Outlook');
            });
        });
        // Save Profile (General)
        $('#epProfileForm').on('submit', function (e) {
            e.preventDefault();
            const $btn = $('#epSaveProfileBtn');
            const $msg = $('#epProfileMessage');

            $btn.prop('disabled', true).text('Guardando...');

            const formData = new FormData(this);
            formData.append('action', 'ep_update_profile');
            formData.append('user_id', '<?php echo $target_user_id; ?>');
            formData.append('security', ep_vars.nonce);

            $.ajax({
                url: ep_vars.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        $msg.html('<span style="color: green;"><i class="fa-solid fa-check"></i> ' + res.data + '</span>');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        $msg.html('<span style="color: red;"><i class="fa-solid fa-times"></i> Error: ' + res.data + '</span>');
                    }
                    $btn.prop('disabled', false).text('Guardar Cambios');
                }
            });
        });

        // Sync from M365
        $('#epSyncFromM365Btn').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $msg = $('#epProfileMessage');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Sincronizando...');
            $msg.text('Obteniendo datos de Microsoft...');

            $.post(ep_vars.ajax_url, {
                action: 'ep_sync_from_m365',
                security: ep_vars.nonce,
                user_id: '<?php echo $target_user_id; ?>'
            }, function (res) {
                if (res.success) {
                    $msg.html('<span style="color: green;"><i class="fa-solid fa-check"></i> ' + res.data + '</span>');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    $msg.html('<span style="color: red;"><i class="fa-solid fa-times"></i> Error: ' + res.data + '</span>');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });
    });
</script>