<?php
defined('ABSPATH') || exit;

$current_user = wp_get_current_user();
$is_admin = current_user_can('administrator');

// Global App Manager context
global $ep_app_manager;
$apps = $ep_app_manager->get_apps();
$config = $ep_app_manager->get_config();

// User notification preferences
$notifications_email = get_user_meta($current_user->ID, 'ep_notifications_email', true);
if ($notifications_email === '')
    $notifications_email = 1;

$notifications_app = get_user_meta($current_user->ID, 'ep_notifications_app', true);
if ($notifications_app === '')
    $notifications_app = 1;

$notifications_teams = get_user_meta($current_user->ID, 'ep_notifications_teams', true);
if ($notifications_teams === '')
    $notifications_teams = 1;

// Briefing matinal del bot de Teams: opt-in, apagado hasta que el empleado lo active.
$bot_briefing = (int) get_user_meta($current_user->ID, 'ep_bot_briefing', true);

// El canal de Teams es un modulo contratable (plan PRO MAX). Si este portal no
// lo tiene, no se ensena el interruptor: seria un ajuste que no hace nada y el
// empleado creeria que sus avisos de Teams no funcionan. Los avisos siguen
// llegando a la campana del portal y al correo.
$teams_channel = !function_exists('ep_teams_channel_enabled') || ep_teams_channel_enabled();

// Global signature template data
$sig_template = get_option('ep_global_signature_template', array());
$logo_main = isset($sig_template['logo_main']) ? $sig_template['logo_main'] : plugin_dir_url(dirname(__FILE__, 2)) . 'public/images/logo-placeholder.png';
$disclaimer = isset($sig_template['disclaimer']) ? $sig_template['disclaimer'] : 'Responsable: CÁMARA OFICIAL DE COMERCIO...';
$custom_sig_html = get_option('ep_custom_signature_html', '');

// Portal Customization
$custom = get_option('ep_portal_customization', array());

// User overrides (Administradores)
$user_overrides = array();
if ($is_admin) {
    $users = get_users(array(
        'meta_key' => 'ep_app_permissions',
        'fields' => array('ID', 'display_name')
    ));
    foreach ($users as $u) {
        $overrides = get_user_meta($u->ID, 'ep_app_permissions', true);
        if (!empty($overrides)) {
            $user_overrides[$u->ID] = array(
                'name' => $u->display_name,
                'perms' => $overrides
            );
        }
    }
}

// Ensure media library scripts are loaded
if ($is_admin) {
    wp_enqueue_media();
}
?>

<div class="ep-settings-header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="?view=dashboard" class="ep-btn-back"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        <h2><i class="fa-solid fa-gear"></i> Configuración del Portal <small style="font-size: 0.5em; color: #888;">(v2.1-IT)</small></h2>
    </div>
</div>

<!-- Tab Navigation -->
<div class="ep-tabs-nav">
    <div class="ep-tab-item active" data-tab="personal"><i class="fa-solid fa-user"></i> Mis Preferencias</div>
    <?php if ($is_admin): ?>
        <div class="ep-tab-item" data-tab="portal"><i class="fa-solid fa-gears"></i> Configuración del Portal</div>
        <div class="ep-tab-item" data-tab="roles"><i class="fa-solid fa-users-gear"></i> Gestión de Roles</div>
    <?php endif; ?>
</div>

<div class="ep-settings-container">
    <!-- TAB 1: PERSONAL PREFERENCES -->
    <div class="ep-tab-content active" id="tab-personal">
        <div class="ep-settings-grid">
            <!-- Notifications Card -->
            <div class="ep-card" id="notifications">
                <h3><i class="fa-solid fa-bell"></i> Notificaciones</h3>
                <p class="description">Controla cómo quieres recibir los avisos del portal.</p>
                <form id="ep-user-settings-form" class="ep-form">
                    <?php if ($teams_channel): ?>
                        <div class="form-group">
                            <label class="switch-label">
                                <span style="display: flex; flex-direction: column;">
                                    <span>Recibir por Microsoft Teams</span>
                                    <small style="color: #666; font-weight: normal; font-size: 0.8em;">Canal prioritario del portal</small>
                                </span>
                                <input type="checkbox" name="notifications_teams" <?php checked($notifications_teams, 1); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="switch-label">
                                <span style="display: flex; flex-direction: column;">
                                    <span>Briefing matinal en Teams</span>
                                    <small style="color: #666; font-weight: normal; font-size: 0.8em;">A las 8:00, de lunes a viernes: firmas, agenda del día, tareas y tickets, más un aviso 15 minutos antes de cada reunión. No se envía si estás de vacaciones o con respuestas automáticas activas. Escribe antes «hola» al bot en Teams.</small>
                                </span>
                                <input type="checkbox" name="bot_briefing" <?php checked($bot_briefing, 1); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="switch-label">
                            <span style="display: flex; flex-direction: column;">
                                <span>Recibir por Email</span>
                                <small style="color: #666; font-weight: normal; font-size: 0.8em;"><?php echo $teams_channel ? 'Copia de los avisos en tu correo' : 'Aviso en tu correo cada vez que recibas una notificación'; ?></small>
                            </span>
                            <input type="checkbox" name="notifications_email" <?php checked($notifications_email, 1); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="switch-label">
                            <span>Alertas en Plataforma (Campana)</span>
                            <input type="checkbox" name="notifications_app" <?php checked($notifications_app, 1); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <button type="submit" class="ep-btn ep-btn-primary">Guardar Preferencias</button>
                </form>
            </div>

            <!-- Email Signature Generator -->
            <div class="ep-card" id="signature">
                <h3><i class="fa-solid fa-signature"></i> Mi Firma de Email</h3>
                <p class="description">Datos de contacto para tu firma corporativa.</p>
                <form id="ep-user-signature-fields" class="ep-form" style="margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <input type="text" id="sig-phone" placeholder="Teléfono"
                            value="<?php echo esc_attr(get_user_meta($current_user->ID, 'ep_phone', true) ?: '927 42 60 49'); ?>">
                        <input type="text" id="sig-mobile" placeholder="Móvil"
                            value="<?php echo esc_attr(get_user_meta($current_user->ID, 'ep_mobile', true) ?: '673 58 06 30'); ?>">
                    </div>
                </form>

                <div class="ep-signature-preview" id="ep-sig-preview">
                    <?php if (!empty($custom_sig_html)):
                        $assets_url = plugin_dir_url(dirname(dirname(__FILE__))) . 'public/assets/';
                        $rendered_sig = str_replace(
                            array('{{nombre}}', '{{puesto}}', '{{email}}', '{{telefono}}', '{{movil}}', '{{empresa}}', '{{departamento}}', '{{logo_main}}', '{{web}}', '{{disclaimer}}', '{{assets_url}}'),
                            array($current_user->display_name, get_user_meta($current_user->ID, 'ep_position', true), $current_user->user_email, get_user_meta($current_user->ID, 'ep_phone', true), get_user_meta($current_user->ID, 'ep_mobile', true), 'Cámara de Cáceres', get_user_meta($current_user->ID, 'ep_department', true), $logo_main, $sig_template['web'] ?? '', $disclaimer, $assets_url),
                            $custom_sig_html
                        );
                        echo $rendered_sig;
                    else: ?>
                        <div style="font-family: Arial; border-left: 3px solid #a81c24; padding-left: 15px;">
                            <p style="margin:0; font-weight:bold; color:#a81c24; font-size:18px;">
                                <?php echo esc_html($current_user->display_name); ?>
                            </p>
                            <p style="margin:2px 0; color:#666; font-size:14px;">
                                <?php echo esc_html(get_user_meta($current_user->ID, 'ep_department', true) ?: 'Empleado Portal'); ?>
                            </p>
                            <img src="<?php echo esc_url($logo_main); ?>" style="width:100px; margin-top:10px;">
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button onclick="saveSignatureDetails()" class="ep-btn ep-btn-primary">Guardar Datos</button>
                    <button onclick="copySignature()" class="ep-btn ep-btn-secondary">Copiar Firma</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
        <!-- TAB 2: PORTAL CONFIGURATION (ADMIN) -->
        <div class="ep-tab-content" id="tab-portal">
            <div class="ep-settings-grid">
                <!-- Maintenance Card -->
                <div class="ep-card" id="maintenance">
                    <h3><i class="fa-solid fa-screwdriver-wrench"></i> Modo Mantenimiento</h3>
                    <?php
                    $m_mode = get_option('ep_maintenance_mode', 0);
                    $g_notif = get_option('ep_global_notifications_disabled', 0);
                    ?>
                    <form id="ep-admin-maintenance-form" class="ep-form">
                        <div class="form-group">
                            <label class="switch-label">
                                <span>Portal en Mantenimiento</span>
                                <input type="checkbox" name="maintenance_mode" <?php checked($m_mode, 1); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="switch-label">
                                <span>Desactivar avisos sistema global</span>
                                <input type="checkbox" name="global_notifications_disabled" <?php checked($g_notif, 1); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <button type="submit" class="ep-btn ep-btn-danger">Actualizar Estado</button>
                    </form>
                </div>

                <!-- Customization Card -->
                <div class="ep-card" id="customization">
                    <h3><i class="fa-solid fa-palette"></i> Identidad Visual</h3>
                    <form id="ep-admin-customization-form" class="ep-form">
                        <div class="form-group">
                            <label>Nombre del Portal</label>
                            <input type="text" name="portal_customization[portal_name]"
                                value="<?php echo esc_attr($custom['portal_name'] ?? 'Portal del Empleado'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Color Corporativo</label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="color" name="portal_customization[primary_color]"
                                    value="<?php echo esc_attr($custom['primary_color'] ?? '#a81c24'); ?>"
                                    style="width: 50px; height: 40px; padding: 0; border: none; cursor:pointer;">
                                <span
                                    style="font-family: monospace;"><?php echo esc_html($custom['primary_color'] ?? '#a81c24'); ?></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Logo del Portal (Menú/Inicio)</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" name="portal_customization[logo_url]" id="ep-portal-logo-url"
                                    value="<?php echo esc_attr($custom['logo_url'] ?? ''); ?>"
                                    placeholder="URL del logo...">
                                <button type="button" class="ep-btn ep-btn-secondary ep-media-btn"
                                    data-target="#ep-portal-logo-url">Subir</button>
                            </div>
                        </div>
                        <button type="submit" class="ep-btn ep-btn-primary">Guardar Estilo</button>
                    </form>
                </div>

                <!-- App Management Card -->
                <div class="ep-card" id="app-management">
                    <h3><i class="fa-solid fa-swatchbook"></i> Módulos Activos</h3>
                    <form id="ep-admin-apps-form" class="ep-form">
                        <?php foreach ($apps as $aid => $app):
                            if ($aid === 'settings')
                                continue; ?>
                            <label class="switch-label">
                                <span><?php echo esc_html($app->get_name()); ?></span>
                                <input type="checkbox" name="app_status[<?php echo esc_attr($aid); ?>]" <?php checked($config[$aid]['active'] ?? true); ?>>
                                <span class="slider"></span>
                            </label>
                        <?php endforeach; ?>
                        <button type="submit" class="ep-btn ep-btn-warning">Actualizar Módulos</button>
                    </form>
                </div>

                <!-- Global Signature Template Card -->
                <div class="ep-card full-width" id="global-signature">
                    <h3><i class="fa-solid fa-pen-nib"></i> Plantilla Global de Firma</h3>
                    <form id="ep-admin-sig-template-form" class="ep-form">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label>Logo Firma (600px recomendado)</label>
                                <div style="display: flex; gap: 10px;">
                                    <input type="text" name="signature_template[logo_main]" id="ep-sig-logo-url"
                                        value="<?php echo esc_attr($logo_main); ?>">
                                    <button type="button" class="ep-btn ep-btn-secondary ep-media-btn"
                                        data-target="#ep-sig-logo-url">Subir</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Aviso Legal (Disclaimer)</label>
                                <textarea name="signature_template[disclaimer]"
                                    rows="3"><?php echo esc_textarea($disclaimer); ?></textarea>
                            </div>
                        </div>
                        <button type="submit" class="ep-btn ep-btn-warning">Guardar Configuración Global</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- TAB 3: ROLES & PERMISSIONS (ADMIN) -->
        <div class="ep-tab-content" id="tab-roles">
            <div class="ep-settings-grid">
                <!-- Permission Matrix -->
                <div class="ep-card full-width" id="permission-matrix">
                    <h3><i class="fa-solid fa-shield-halved"></i> Matriz de Permisos</h3>
                    <form id="ep-admin-perms-form" class="ep-form">
                        <div class="table-responsive">
                            <table class="ep-table">
                                <thead>
                                    <tr>
                                        <th>Aplicación</th><?php foreach (EP_Roles::get_roles_list() as $rn)
                                            echo "<th>$rn</th>"; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apps as $app_id => $app): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($app->get_name()); ?></strong></td>
                                            <?php foreach (EP_Roles::get_roles_list() as $role_id => $role_name):
                                                $p = $config[$app_id]['permissions'][$role_id] ?? 'write'; ?>
                                                <td>
                                                    <select
                                                        name="perms[<?php echo esc_attr($app_id); ?>][<?php echo esc_attr($role_id); ?>]">
                                                        <option value="none" <?php selected($p, 'none'); ?>>❌</option>
                                                        <option value="read" <?php selected($p, 'read'); ?>>👁️</option>
                                                        <?php if ($app_id === 'inventory'): ?>
                                                            <option value="manage_itinerant" <?php selected($p, 'manage_itinerant'); ?>>📦 IT</option>
                                                        <?php endif; ?>
                                                        <option value="write" <?php selected($p, 'write'); ?>>✏️</option>
                                                    </select>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="ep-btn ep-btn-danger">Guardar Cambios de Seguridad</button>
                    </form>
                </div>

                <!-- User Overrides -->
                <div class="ep-card full-width" id="user-overrides">
                    <h3><i class="fa-solid fa-user-shield"></i> Overrides de Usuario (Permisos Especiales)</h3>
                    <p class="description">Asigna permisos específicos a usuarios individuales, independientemente de su
                        rol.</p>

                    <form id="ep-admin-user-override-form" class="ep-form"
                        style="display: flex; gap: 10px; margin-bottom: 25px; align-items: flex-end;">
                        <div class="form-group" style="flex:1;">
                            <label>Usuario</label>
                            <select name="target_user_id" required>
                                <option value="">Seleccionar usuario...</option>
                                <?php foreach (get_users(array('number' => 200)) as $u)
                                    echo "<option value='{$u->ID}'>{$u->display_name}</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Módulo</label>
                            <select name="app_id" required>
                                <option value="">Seleccionar aplicación...</option>
                                <?php foreach ($apps as $id => $app)
                                    echo "<option value='$id'>{$app->get_name()}</option>"; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Permiso</label>
                            <select name="perm" id="ep-override-perm-select" required>
                                <option value="read">Lectura (👁️)</option>
                                <option value="write" selected>Escritura (✏️)</option>
                                <option value="manage_itinerant" class="inventory-only">Gestor Itinerante (📦 IT)</option>
                                <option value="none">Ninguno (🚫)</option>
                                <option value="default">Por Defecto (Restaurar)</option>
                            </select>
                        </div>
                        <button type="submit" class="ep-btn ep-btn-primary"
                            style="height: 40px; margin-bottom: 5px;">Asignar Permiso</button>
                    </form>

                    <?php if (!empty($user_overrides)): ?>
                        <div class="table-responsive">
                            <table class="ep-table">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Módulo</th>
                                        <th>Permiso</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_overrides as $uid => $data):
                                        foreach ($data['perms'] as $aid => $p): ?>
                                            <tr>
                                                <td><strong><?php echo esc_html($data['name']); ?></strong></td>
                                                <td><?php echo esc_html($apps[$aid]->get_name() ?? $aid); ?></td>
                                                <td>
                                                    <?php
                                                    if ($p === 'write')
                                                        echo '<span class="ep-badge ep-badge-success">Escritura</span>';
                                                    elseif ($p === 'read')
                                                        echo '<span class="ep-badge ep-badge-info">Lectura</span>';
                                                    elseif ($p === 'manage_itinerant')
                                                        echo '<span class="ep-badge ep-badge-warning" style="background: #fff7ed; color: #c2410c;">Gestor IT</span>';
                                                    else
                                                        echo '<span class="ep-badge ep-badge-danger">Ninguno</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <button class="ep-btn-icon ep-remove-override" data-user="<?php echo $uid; ?>"
                                                        data-app="<?php echo $aid; ?>"><i class="fa-solid fa-trash"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #888; padding: 20px;">No hay overrides de permisos activos.</p>
                    <?php endif; ?>
                </div>

                <!-- Role Assignment -->
                <div class="ep-card" id="role-assignment">
                    <h3><i class="fa-solid fa-user-tag"></i> Asignar Rol</h3>
                    <div class="ep-form">
                        <select id="ep-role-user-select" style="width:100%; margin-bottom:10px;">
                            <?php foreach (get_users(array('number' => 100)) as $u)
                                echo "<option value='{$u->ID}' data-role='" . implode(',', $u->roles) . "'>{$u->display_name}</option>"; ?>
                        </select>
                        <p id="ep-current-role-display"
                            style="font-size: 0.9em; color: #666; margin-bottom: 15px; background: #f8f9fa; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #a81c24;">
                            <i class="fa-solid fa-id-card"></i> Rol actual: <span
                                style="font-weight: 600; color: #a81c24;">Cargando...</span>
                        </p>
                        <select id="ep-role-new-role-select" style="width:100%; margin-bottom:10px;">
                            <?php foreach (EP_Roles::get_roles_list() as $rid => $rname)
                                echo "<option value='$rid'>$rname</option>"; ?>
                        </select>
                        <button class="ep-btn ep-btn-primary" id="ep-assign-role-btn">Confirmar Cambio</button>
                    </div>
                </div>

                <!-- Custom Signature HTML -->
                <div class="ep-card full-width" id="custom-signature-html">
                    <h3><i class="fa-solid fa-code"></i> HTML Firma Avanzado</h3>
                    <form id="ep-custom-sig-html-form" class="ep-form">
                        <textarea id="ep-custom-sig-textarea" rows="8"
                            style="width:100%; font-family:monospace; background:#1e1e1e; color:#ccc; padding:10px; border-radius:8px;"><?php echo esc_textarea($custom_sig_html); ?></textarea>
                        <div style="margin-top:10px; display:flex; gap:10px;">
                            <button type="submit" class="ep-btn ep-btn-warning">Guardar Plantilla</button>
                            <button type="button" class="ep-btn" id="ep-preview-sig-html-btn">Previsualizar</button>
                        </div>
                    </form>
                    <div id="ep-sig-html-preview"
                        style="display:none; margin-top:15px; border:1px solid #ddd; padding:15px; background:white; border-radius:8px;">
                        <div id="ep-sig-html-preview-content"></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .ep-tabs-nav {
        display: flex;
        gap: 5px;
        margin-bottom: 30px;
        border-bottom: 2px solid #f0f0f0;
        background: #fff;
        padding: 10px 10px 0;
        border-radius: 12px 12px 0 0;
    }

    .ep-tab-item {
        padding: 12px 25px;
        cursor: pointer;
        font-weight: 600;
        color: #666;
        border-radius: 8px 8px 0 0;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ep-tab-item i {
        font-size: 1.1em;
    }

    .ep-tab-item:hover {
        color: #a81c24;
        background: #fdf2f2;
    }

    .ep-tab-item.active {
        color: #a81c24;
        background: white;
        border: 2px solid #f0f0f0;
        border-bottom: 2px solid white;
        margin-bottom: -2px;
    }

    .ep-tab-content {
        display: none;
    }

    .ep-tab-content.active {
        display: block;
        animation: epFadeIn 0.3s ease;
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

    .ep-settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 25px;
    }

    .ep-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        border: 1px solid #f0f0f0;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .ep-card.full-width {
        grid-column: 1 / -1;
    }

    .ep-card h3 {
        color: #a81c24;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .description {
        color: #888;
        font-size: 0.9em;
        margin-bottom: 20px;
    }

    .ep-btn {
        padding: 10px 20px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: opacity 0.2s;
    }

    .ep-btn:hover {
        opacity: 0.9;
    }

    .ep-btn-primary {
        background: #a81c24;
        color: white;
    }

    .ep-btn-secondary {
        background: #666;
        color: white;
    }

    .ep-btn-warning {
        background: #f59e0b;
        color: white;
    }

    .ep-btn-danger {
        background: #dc2626;
        color: white;
    }

    .switch-label {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        padding: 12px 0;
        border-bottom: 1px solid #f8f9fa;
        gap: 15px;
    }

    .switch-label input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }

    .slider {
        position: relative;
        display: inline-block;
        width: 50px;
        min-width: 50px;
        height: 26px;
        background: #cbd5e1;
        border-radius: 34px;
        transition: 0.4s;
        border: 1px solid #94a3b8;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 2px;
        bottom: 2px;
        background: white;
        border-radius: 50%;
        transition: 0.4s;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        z-index: 2;
    }

    input:checked+.slider {
        background: #a81c24;
        border-color: #7d1625;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    input:checked+.slider:before {
        transform: translateX(24px);
    }

    .switch-label:hover .slider {
        border-color: #a81c24;
        box-shadow: 0 0 0 4px rgba(168, 28, 36, 0.1), inset 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .ep-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }

    .ep-table th,
    .ep-table td {
        padding: 12px;
        text-align: center;
        border: 1px solid #eee;
    }

    .ep-table th {
        background: #fdf2f2;
        font-weight: 700;
        color: #a81c24;
    }

    .ep-signature-preview {
        border: 2px dashed #eee;
        padding: 20px;
        border-radius: 8px;
        background: #fafafa;
        overflow: auto;
    }

    /* Fix switches */
    .switch-label span:first-child {
        flex: 1;
        font-weight: 500;
        color: #334155;
    }

    /* Badges and icons */
    .ep-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8em;
        font-weight: bold;
    }

    .ep-badge-success {
        background: #dcfce7;
        color: #166534;
    }

    .ep-badge-info {
        background: #dbeafe;
        color: #1e40af;
    }

    .ep-badge-danger {
        background: #fee2e2;
        color: #991b1b;
    }

    .ep-btn-icon {
        background: none;
        border: none;
        color: #dc2626;
        cursor: pointer;
        font-size: 1.1em;
        transition: color 0.2s;
    }

    .ep-btn-icon:hover {
        color: #991b1b;
    }

    /* Form improvements */
    .form-group input[type="text"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-family: inherit;
    }
</style>

<script>
    const ep_role_names = <?php echo json_encode(EP_Roles::get_roles_list()); ?>;
    jQuery(document).ready(function ($) {
        // === TAB SWITCHING ===
        $('.ep-tab-item').on('click', function () {
            var tabId = $(this).data('tab');
            $('.ep-tab-item').removeClass('active');
            $(this).addClass('active');
            $('.ep-tab-content').removeClass('active');
            $('#tab-' + tabId).addClass('active');
        });

        // === SAVING PREFERENCES ===
        $('#ep-user-settings-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_user_settings',
                nonce: ep_vars.nonce,
                formData: $(this).serialize()
            }, function (res) {
                alert(res.success ? '¡Configuración guardada!' : 'Error: ' + res.data);
            });
        });

        // === ADMIN: APP STATUS ===
        $('#ep-admin-apps-form').on('submit', function (e) {
            e.preventDefault();
            var data = { action: 'ep_settings_action', ep_action: 'save_admin_apps', nonce: ep_vars.nonce, app_status: {} };
            $(this).find('input[type="checkbox"]').each(function () {
                var name = $(this).attr('name').match(/\[(.*?)\]/)[1];
                data.app_status[name] = $(this).is(':checked') ? 1 : 0;
            });
            $.post(ep_vars.ajax_url, data, function (res) { if (res.success) alert('Módulos actualizados'); });
        });

        // === ADMIN: PERMISSIONS ===
        $('#ep-admin-perms-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_admin_perms',
                nonce: ep_vars.nonce,
                formData: $(this).serialize()
            }, function (res) { if (res.success) alert('Permisos globales guardados'); });
        });

        // === SIGNATURE ACTIONS ===
        window.saveSignatureDetails = function () {
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_user_signature_details',
                nonce: ep_vars.nonce,
                phone: $('#sig-phone').val(),
                mobile: $('#sig-mobile').val()
            }, function (res) { alert(res.success ? '¡Firma actualizada!' : 'Error'); });
        };

        window.copySignature = function () {
            var range = document.createRange();
            range.selectNode(document.getElementById('ep-sig-preview'));
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            document.execCommand('copy');
            alert('Copiado. Pégalo en Outlook.');
            window.getSelection().removeAllRanges();
        };

        // === ROLE ASSIGNMENT ===
        $('#ep-assign-role-btn').on('click', function () {
            var userId = $('#ep-role-user-select').val();
            var newRole = $('#ep-role-new-role-select').val();
            if (!confirm('¿Cambiar rol?')) return;
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'assign_user_role',
                nonce: ep_vars.nonce,
                target_user_id: userId,
                new_role: newRole
            }, function (res) { if (res.success) location.reload(); else alert('Error'); });
        });

        // === CUSTOM HTML SIGNATURE ===
        $('#ep-custom-sig-html-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_custom_signature_html',
                nonce: ep_vars.nonce,
                custom_html: $('#ep-custom-sig-textarea').val()
            }, function (res) { if (res.success) alert('HTML Guardado'); });
        });

        $('#ep-preview-sig-html-btn').on('click', function () {
            var html = $('#ep-custom-sig-textarea').val();
            $('#ep-sig-html-preview-content').html(html);
            $('#ep-sig-html-preview').slideDown();
        });

        // === MEDIA LIBRARY PICKER ===
        $('.ep-media-btn').on('click', function (e) {
            e.preventDefault();
            var button = $(this);
            var target = $(button.data('target'));
            var frame = wp.media({
                title: 'Seleccionar Imagen',
                button: { text: 'Usar esta imagen' },
                multiple: false
            });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                target.val(attachment.url);
            });
            frame.open();
        });

        // === ADMIN: MAINTENANCE & NOTIFICATIONS ===
        $('#ep-admin-maintenance-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_maintenance_mode',
                nonce: ep_vars.nonce,
                maintenance_mode: $(this).find('input[name="maintenance_mode"]').is(':checked') ? 1 : 0,
                global_notifications_disabled: $(this).find('input[name="global_notifications_disabled"]').is(':checked') ? 1 : 0
            }, function (res) { if (res.success) alert('Estado actualizado'); });
        });

        // === ADMIN: CUSTOMIZATION ===
        $('#ep-admin-customization-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_portal_customization',
                nonce: ep_vars.nonce,
                formData: $(this).serialize()
            }, function (res) { if (res.success) alert('Estilo guardado'); });
        });

        // === ADMIN: GLOBAL SIGNATURE ===
        $('#ep-admin-sig-template-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_global_signature_template',
                nonce: ep_vars.nonce,
                formData: $(this).serialize()
            }, function (res) { if (res.success) alert('Firma global guardada'); });
        });

        // === ADMIN: USER OVERRIDES ===
        $('#ep-admin-user-override-form').on('submit', function (e) {
            e.preventDefault();
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_user_override',
                nonce: ep_vars.nonce,
                target_user_id: $(this).find('select[name="target_user_id"]').val(),
                app_id: $(this).find('select[name="app_id"]').val(),
                perm: $(this).find('select[name="perm"]').val()
            }, function (res) { if (res.success) alert(res.data); });
        });

        $('.ep-remove-override').on('click', function () {
            if (!confirm('¿Eliminar este permiso específico?')) return;
            $.post(ep_vars.ajax_url, {
                action: 'ep_settings_action',
                ep_action: 'save_user_override',
                nonce: ep_vars.nonce,
                target_user_id: $(this).data('user'),
                app_id: $(this).data('app'),
                perm: 'default'
            }, function (res) { if (res.success) location.reload(); });
        });

        // === ROLE DISPLAY LOGIC ===
        function updateCurrentRoleDisplay() {
            const selected = $('#ep-role-user-select option:selected');
            const roleIds = selected.data('role') ? selected.data('role').split(',') : [];
            const roleNames = roleIds.map(id => ep_role_names[id] || id).join(', ');
            $('#ep-current-role-display span').text(roleNames || 'Sin rol');
        }

        $('#ep-role-user-select').on('change', updateCurrentRoleDisplay);
        updateCurrentRoleDisplay(); // Initial call
    });
</script>