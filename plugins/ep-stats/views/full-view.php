<?php defined('ABSPATH') || exit; ?>

<div class="ep-stats-container">
    <div class="ep-section-header"
        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <h2 class="ep-section-title" style="margin: 0;"><i class="fas fa-chart-line"></i> Centro de Estadísticas y
            Auditoría</h2>
        <div class="ep-actions">
            <?php if ($this->has_write_access()): ?>
                <button id="ep-export-stats" class="ep-btn ep-btn-secondary">
                    <i class="fas fa-file-export"></i> Exportar CSV
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navegación por Tabs -->
    <div class="ep-tabs"
        style="margin-bottom: 25px; display: flex; gap: 10px; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px;">
        <button class="ep-tab-btn active" data-tab="activity"
            style="background:none; border:none; padding: 10px 20px; cursor:pointer; font-weight:600; color: var(--ep-primary-color); border-bottom: 2px solid var(--ep-primary-color);">
            <i class="fas fa-list"></i> Actividad General
        </button>
        <?php if ($this->has_write_access()): ?>
            <button class="ep-tab-btn" data-tab="connections"
                style="background:none; border:none; padding: 10px 20px; cursor:pointer; font-weight:600; color: #666;">
                <i class="fas fa-users-cog"></i> Tiempos de Conexión
            </button>
            <button class="ep-tab-btn" data-tab="users"
                style="background:none; border:none; padding: 10px 20px; cursor:pointer; font-weight:600; color: #666;">
                <i class="fas fa-users"></i> Usuarios
            </button>
        <?php endif; ?>
    </div>

    <!-- Contenido Tab Actividad -->
    <div id="tab-activity" class="ep-tab-pane active">
        <!-- Filtros -->
        <div class="ep-card ep-filters-card" style="margin-bottom: 25px;">
            <form method="get" class="ep-stats-filters">
                <input type="hidden" name="view" value="stats">
                <div class="ep-form-row"
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <div class="ep-form-group">
                        <label>Aplicación</label>
                        <select name="app_id" class="ep-input">
                            <option value="">Todas</option>
                            <option value="auth" <?php selected($filters['app_id'], 'auth'); ?>>Autenticación</option>
                            <option value="tickets" <?php selected($filters['app_id'], 'tickets'); ?>>Tickets</option>
                            <option value="signature" <?php selected($filters['app_id'], 'signature'); ?>>Firmas
                            </option>
                            <option value="inventory" <?php selected($filters['app_id'], 'inventory'); ?>>Inventario
                            </option>
                            <option value="avisos" <?php selected($filters['app_id'], 'avisos'); ?>>Avisos</option>
                            <option value="downloads" <?php selected($filters['app_id'], 'downloads'); ?>>Descargas
                            </option>
                            <option value="censo" <?php selected($filters['app_id'], 'censo'); ?>>Censo</option>
                            <option value="directory" <?php selected($filters['app_id'], 'directory'); ?>>Directorio
                            </option>
                            <option value="calendar" <?php selected($filters['app_id'], 'calendar'); ?>>Agenda</option>
                            <option value="o365" <?php selected($filters['app_id'], 'o365'); ?>>Office 365</option>
                            <option value="system" <?php selected($filters['app_id'], 'system'); ?>>Sistema</option>
                        </select>
                    </div>
                    <div class="ep-form-group">
                        <label>Evento</label>
                        <select name="event_type" class="ep-input">
                            <option value="">Todos</option>
                            <option value="login" <?php selected($filters['event_type'], 'login'); ?>>Inicio de Sesión
                            </option>
                            <option value="document_signed" <?php selected($filters['event_type'], 'document_signed'); ?>>Firmas</option>
                            <option value="security_alert" <?php selected($filters['event_type'], 'security_alert'); ?>>
                                Alertas de Seguridad</option>
                            <option value="post_deleted" <?php selected($filters['event_type'], 'post_deleted'); ?>>
                                Borrados</option>
                        </select>
                    </div>
                    <div class="ep-form-group">
                        <label>Desde</label>
                        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>"
                            class="ep-input">
                    </div>
                    <div class="ep-form-group">
                        <label>Hasta</label>
                        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>"
                            class="ep-input">
                    </div>
                    <div class="ep-form-group">
                        <label>Usuario</label>
                        <select name="user_id" class="ep-input">
                            <option value="">Todos</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u->ID; ?>" <?php selected($filters['user_id'], $u->ID); ?>>
                                    <?php echo esc_html($u->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ep-form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="ep-btn ep-btn-primary" style="width: 100%;">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Dashboards -->
        <div class="ep-grid-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 30px;">
            <div class="ep-card">
                <h4
                    style="margin-top:0; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px; margin-bottom: 20px;">
                    <i class="fas fa-chart-line"></i> Tendencia de Actividad
                </h4>
                <div style="height: 250px;">
                    <canvas id="activityChart"></canvas>
                </div>
            </div>
            <div class="ep-card">
                <h4
                    style="margin-top:0; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px; margin-bottom: 20px;">
                    <i class="fas fa-chart-pie"></i> Uso por Aplicación
                </h4>
                <div style="height: 250px; display: flex; justify-content: center;">
                    <canvas id="appsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- M365 Activity Insights -->
        <div id="m365-insights-container" class="ep-card" style="margin-bottom: 30px; display:none;">
            <div
                style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px; margin-bottom: 20px;">
                <h4 style="margin:0;">
                    <i class="fa-brands fa-microsoft"></i> Insights de Actividad M365
                </h4>
                <div class="m365-period-selector"
                    style="display:flex; gap:0; border-radius:6px; overflow:hidden; border:1px solid #0078d4;">
                    <button class="ep-period-btn" data-period="1"
                        style="padding:6px 14px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#fff; color:#0078d4; cursor:pointer;">Hoy</button>
                    <button class="ep-period-btn" data-period="7"
                        style="padding:6px 14px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#fff; color:#0078d4; cursor:pointer;">7d</button>
                    <button class="ep-period-btn" data-period="30"
                        style="padding:6px 14px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#0078d4; color:#fff; cursor:pointer;">30d</button>
                    <button class="ep-period-btn" data-period="365"
                        style="padding:6px 14px; font-size:12px; font-weight:600; border:none; background:#fff; color:#0078d4; cursor:pointer;">365d</button>
                </div>
            </div>
            <div id="m365-no-user-message" style="text-align: center; padding: 20px; color: #666; font-style: italic;">
                <i class="fas fa-info-circle"></i> Selecciona un usuario para ver su actividad en Office 365.
            </div>
            <div id="m365-insights-content" class="ep-grid-row"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; display: none;">
                <div class="ep-stat-item">
                    <span class="stat-label">Eventos Calendario</span>
                    <span id="m365-total-meetings" class="stat-value">-</span>
                </div>
                <div class="ep-stat-item">
                    <span class="stat-label">Horas Bloqueadas</span>
                    <span id="m365-total-hours" class="stat-value">-</span>
                </div>
                <div class="ep-stat-item" style="border: 1px solid #0078d422; background: #0078d408;">
                    <span class="stat-label" style="color:#0078d4;">Estado Teams</span>
                    <span id="m365-teams-meetings" class="stat-value">-</span>
                </div>
                <div class="ep-stat-item" style="border: 1px solid #0078d422; background: #0078d408;">
                    <span class="stat-label" style="color:#0078d4;">Actividad Teams</span>
                    <span id="m365-teams-hours" class="stat-value">-</span>
                </div>
                <div class="ep-stat-item">
                    <span class="stat-label">Día más concurrido</span>
                    <span id="m365-busy-day" class="stat-value" style="font-size: 1.1rem;">-</span>
                </div>
            </div>
            <div id="m365-error" class="m365-error"></div>
        </div>

        <!-- Registro de Auditoría -->
        <div class="ep-card audit-log-card">
            <h4>Registro de Auditoría</h4>
            <div class="ep-table-responsive">
                <table class="ep-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>App</th>
                            <th>Evento</th>
                            <th>Detalles</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($events)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">No hay eventos registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($events as $event):
                                $user = get_userdata($event->user_id);
                                $metadata = maybe_unserialize($event->metadata);
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($event->event_time)); ?></td>
                                    <td><?php echo $user ? esc_html($user->display_name) : 'Sistema'; ?></td>
                                    <td><span
                                            class="ep-badge ep-badge-info"><?php echo EP_App_Stats::format_app($event->app_id); ?></span>
                                    </td>
                                    <td><?php echo EP_App_Stats::format_event($event->event_type); ?></td>
                                    <td><small
                                            class="ep-details-small"><?php echo EP_App_Stats::format_details($metadata, $event->event_type); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Contenido Tab Conexiones -->
    <div id="tab-connections" class="ep-tab-pane">
        <div class="ep-card">
            <div class="connections-header"
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h4 style="margin:0;">Historial de Tiempos de Conexión</h4>
                <div class="connections-actions" style="display: flex; gap: 10px;">
                    <button id="ep-export-connections" class="ep-btn ep-btn-secondary ep-btn-sm">
                        <i class="fas fa-file-export"></i> CSV
                    </button>
                    <button id="ep-refresh-connections" class="ep-btn ep-btn-secondary ep-btn-sm">
                        <i class="fas fa-sync-alt"></i> Actualizar
                    </button>
                </div>
            </div>
            <div class="ep-table-responsive">
                <table class="ep-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Inicio de Sesión</th>
                            <th>Última Actividad</th>
                            <th>Tiempo Activo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="connections-body">
                        <tr>
                            <td colspan="5" style="text-align:center;"><i class="fas fa-spinner fa-spin"></i>
                                Cargando...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Contenido Tab Usuarios -->
    <div id="tab-users" class="ep-tab-pane">
        <div class="ep-card">
            <div
                style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--ep-border); padding-bottom: 15px; margin-bottom: 20px;">
                <h4 style="margin:0; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-users"></i> Resumen de Actividad por Empleado
                    <button id="ep-export-users" class="ep-btn ep-btn-secondary ep-btn-sm"
                        style="margin-left:auto; padding:4px 8px; font-size:11px;">
                        <i class="fas fa-file-export"></i> CSV
                    </button>
                    <button id="ep-sync-m365" class="ep-stats-btn"
                        style="padding:4px 8px; font-size:11px; background:#f0f4f9; color:#0078d4; border:1px solid #0078d4;">
                        <i class="fas fa-sync-alt"></i> Sincronizar M365
                    </button>
                    <span id="ep-sync-status" style="font-size:10px; color:#666; font-weight:normal;"></span>
                </h4>
                <div class="ep-period-selector"
                    style="display:flex; gap:0; border-radius:6px; overflow:hidden; border:1px solid #0078d4;">
                    <button class="ep-period-btn-users" data-period="1"
                        style="padding:5px 12px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#0078d4; color:#fff; cursor:pointer;">Hoy</button>
                    <button class="ep-period-btn-users" data-period="7"
                        style="padding:5px 12px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#fff; color:#0078d4; cursor:pointer;">7d</button>
                    <button class="ep-period-btn-users" data-period="30"
                        style="padding:5px 12px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#fff; color:#0078d4; cursor:pointer;">30d</button>
                    <button class="ep-period-btn-users" data-period="365"
                        style="padding:5px 12px; font-size:12px; font-weight:600; border:none; background:#fff; color:#0078d4; cursor:pointer;">365d</button>
                </div>
            </div>
            <div class="ep-table-container">
                <table class="ep-table">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Tiempo Portal</th>
                            <th style="text-align:center;">Calendario</th>
                            <th style="text-align:center;">Teams</th>
                            <th style="text-align:center;">Actividad M365</th>
                            <th style="text-align:center;">Apps Portal</th>
                        </tr>
                    </thead>
                    <tbody id="users-summary-tbody">
                        <tr>
                            <td colspan="6" style="text-align:center;">Cargando resumen de usuarios...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="users-summary-pagination" style="margin-top: 20px; text-align: center;"></div>
        </div>
    </div>

</div> <!-- Fin .ep-stats-container -->

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // --- Gestión de Tabs ---
        const tabBtns = document.querySelectorAll('.ep-tab-btn');
        const tabPanes = document.querySelectorAll('.ep-tab-pane');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.tab;
                tabBtns.forEach(b => {
                    b.classList.remove('active');
                    b.style.borderBottom = 'none';
                    b.style.color = '#666';
                });
                btn.classList.add('active');
                btn.style.borderBottom = '2px solid var(--ep-primary-color)';
                btn.style.color = 'var(--ep-primary-color)';

                tabPanes.forEach(p => p.classList.remove('active'));
                const tp = document.getElementById('tab-' + target);
                if (tp) tp.classList.add('active');

                if (target === 'connections') loadConnections();
                if (target === 'users') loadUsersSummary();
            });
        });

        // --- Carga de Conexiones ---
        function loadConnections() {
            const body = document.getElementById('connections-body');
            if (!body) return;
            body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Cargando historial...</td></tr>';

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ep_stats_get_connections',
                    security: '<?php echo wp_create_nonce('ep_stats_nonce'); ?>'
                })
            })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (res.data.length === 0) {
                            body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px;">No hay registros.</td></tr>';
                            return;
                        }
                        body.innerHTML = res.data.map(s => `
                    <tr>
                        <td><strong>${s.display_name || 'ID: ' + s.user_id}</strong></td>
                        <td>${s.login_time_formatted}</td>
                        <td>${s.last_activity_formatted}</td>
                        <td><span class="ep-badge ep-badge-info">${s.duration_human}</span></td>
                        <td>
                            <span class="ep-badge ${s.status === 'active' ? 'ep-badge-success' : 'ep-badge-secondary'}">
                                ${s.status === 'active' ? 'Conectado' : 'Finalizada'}
                            </span>
                        </td>
                    </tr>
                `).join('');
                    }
                });
        }

        document.getElementById('ep-refresh-connections')?.addEventListener('click', loadConnections);

        // --- Resumen de Usuarios ---
        let usersPeriod = 1;

        // Period selector for Users tab
        document.querySelectorAll('.ep-period-btn-users').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.ep-period-btn-users').forEach(b => {
                    b.style.background = '#fff';
                    b.style.color = '#0078d4';
                });
                this.style.background = '#0078d4';
                this.style.color = '#fff';
                usersPeriod = parseInt(this.dataset.period);                                // Reload summary with new period
                loadUsersSummary(1);
            });
        });

        function loadUsersSummary(page = 1) {
            const body = document.getElementById('users-summary-tbody');
            if (!body) return;
            body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Cargando resumen...</td></tr>';

            const fd = new FormData();
            fd.append('action', 'ep_stats_get_users_summary');
            fd.append('security', '<?php echo wp_create_nonce("ep_stats_nonce"); ?>');
            fd.append('paged', page);
            fd.append('period', usersPeriod);

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(res => {
                    if (res.success && res.data.length > 0) {
                        body.innerHTML = res.data.map(u => {
                            const teamsHours = Math.round((parseInt(u.teams_seconds) || 0) / 3600 * 10) / 10;
                            return `
                    <tr>
                        <td><strong>${u.display_name}</strong></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${u.session_status === 'active' ? '#4caf50' : '#ccc'};" title="${u.session_status === 'active' ? 'En línea' : 'Desconectado'}"></span>
                                <div style="line-height:1.3;">
                                    <div style="font-weight:700; color:var(--ep-primary-color); font-size:1.1rem;">${u.duration_human}</div>
                                    ${u.last_login ? `<div style="font-size:11px; color:#888;">Última vez: ${new Date(u.last_login).toLocaleDateString('es-ES', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</div>` : '<div style="font-size:11px; color:#ccc;">Sin sesiones</div>'}
                                </div>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <button class="load-calendar-btn ep-stats-btn" data-user="${u.user_id}">
                                <i class="fas fa-calendar-alt" style="color:#0078d4"></i> Cargar
                            </button>
                        </td>
                        <td style="text-align:center;">
                            <div style="font-size:12px; color:#464eb8; font-weight:600;">
                                <i class="fa-brands fa-microsoft"></i> ${u.teams_human}
                            </div>
                            ${(usersPeriod == 1 && parseInt(u.teams_seconds || 0) > 0 && parseInt(u.total_duration || 0) == 0)
                                    ? '<div style="font-size:10px; color:#e65100; font-weight:600;"><i class="fas fa-exclamation-triangle"></i> Sin portal</div>'
                                    : (usersPeriod == 1 && parseInt(u.teams_seconds || 0) > 0 ? '<div style="font-size:10px; color:#888;">en el periodo</div>' : '<div style="font-size:10px; color:#888;">en el periodo</div>')
                                }
                        </td>
                        <td style="text-align:center;">
                            ${u.m365_chats !== null ? `
                                <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                    <span style="font-size:11px; color:#1565c0;" title="Mensajes Teams"><i class="fas fa-comment"></i> ${u.m365_chats}</span>
                                    <span style="font-size:11px; color:#2e7d32;" title="Llamadas"><i class="fas fa-phone"></i> ${u.m365_calls}</span>
                                    <span style="font-size:11px; color:#e65100;" title="Reuniones"><i class="fas fa-video"></i> ${u.m365_meetings}</span>
                                </div>
                                ${(parseInt(u.teams_seconds) > 3600 && u.m365_chats == 0 && u.m365_calls == 0 && u.m365_meetings == 0)
                                        ? '<div style="font-size:10px; color:#d32f2f; font-weight:700; margin-top:2px;"><i class="fas fa-flag"></i> Sospechoso</div>'
                                        : (u.m365_synced ? '<div style="font-size:9px; color:#aaa;">Sync: ' + new Date(u.m365_synced).toLocaleDateString('es-ES') + '</div>' : '')
                                    }
                            ` : '<div style="font-size:11px; color:#ccc;">-</div>'}
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap; justify-content: center;">
                                ${u.tickets_count > 0 ? `<span class="ep-stats-badge" title="Tickets"><i class="fas fa-ticket-alt"></i> ${u.tickets_count}</span>` : ''}
                                ${u.signatures_count > 0 ? `<span class="ep-stats-badge" style="background:#e3f2fd; color:#1e88e5;" title="Firmas"><i class="fas fa-file-signature"></i> ${u.signatures_count}</span>` : ''}
                                ${u.inventory_count > 0 ? `<span class="ep-stats-badge" style="background:#f1f8e9; color:#43a047;" title="Inventario"><i class="fas fa-boxes"></i> ${u.inventory_count}</span>` : ''}
                                ${u.censo_count > 0 ? `<span class="ep-stats-badge" style="background:#fff3e0; color:#fb8c00;" title="Censo"><i class="fas fa-id-card"></i> ${u.censo_count}</span>` : ''}
                                ${u.downloads_count > 0 ? `<span class="ep-stats-badge" style="background:#f3e5f5; color:#8e24aa;" title="Descargas"><i class="fas fa-download"></i> ${u.downloads_count}</span>` : ''}
                                ${u.directory_count > 0 ? `<span class="ep-stats-badge" style="background:#e0f7fa; color:#006064;" title="Directorio"><i class="fas fa-address-book"></i> ${u.directory_count}</span>` : ''}
                            </div>
                        </td>
                    </tr>
                `}).join('');

                        // Calendar load buttons
                        body.querySelectorAll('.load-calendar-btn').forEach(btn => {
                            btn.onclick = function () {
                                const uid = this.dataset.user;
                                const parent = this.parentNode;
                                parent.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#0078d4"></i>';

                                const fd2 = new FormData();
                                fd2.append('action', 'ep_stats_get_m365_activity');
                                fd2.append('security', '<?php echo wp_create_nonce("ep_stats_nonce"); ?>');
                                fd2.append('user_id', uid);
                                fd2.append('period', usersPeriod);

                                fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd2 })
                                    .then(r => r.json())
                                    .then(r => {
                                        if (r.success) {
                                            parent.innerHTML = `<div style="font-size: 12px; color:#0078d4; font-weight:600; line-height: 1.4;">
                                    <div title="Eventos calendario"><i class="fas fa-calendar-check"></i> ${r.data.total_meetings || 0} eventos</div>
                                    <div style="font-size:11px; color:#666;">${Math.round(r.data.total_hours || 0)}h bloqueadas</div>
                                    ${r.data.most_busy_day ? `<div style="font-size:10px; color:#999;">Pico: ${r.data.most_busy_day}</div>` : ''}
                                </div>`;
                                        } else {
                                            parent.innerHTML = '<span style="color:red; font-size:11px;">Error</span>';
                                        }
                                    });
                            };
                        });
                    }
                });
        }

        // --- Gráficos ---
        const actCtx = document.getElementById('activityChart')?.getContext('2d');
        if (actCtx) {
            new Chart(actCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(wp_list_pluck($summary['events_per_day'], 'date')); ?>,
                    datasets: [{
                        label: 'Eventos',
                        data: <?php echo json_encode(wp_list_pluck($summary['events_per_day'], 'count')); ?>,
                        borderColor: '#9e1c2e', backgroundColor: 'rgba(158, 28, 46, 0.1)',
                        fill: true, tension: 0.4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }

        const appCtx = document.getElementById('appsChart')?.getContext('2d');
        if (appCtx) {
            new Chart(appCtx, {
                type: 'doughnut',
                data: {
                    labels: [<?php echo "'" . implode("','", array_map(['EP_App_Stats', 'format_app'], wp_list_pluck($summary['top_apps'], 'app_id'))) . "'"; ?>],
                    datasets: [{
                        data: [<?php echo implode(',', wp_list_pluck($summary['top_apps'], 'count')); ?>],
                        backgroundColor: ['#9e1c2e', '#2e7d32', '#1565c0', '#f9a825', '#6a1b9a', '#37474f']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        // --- M365 Insights Inicial ---
        let currentM365User = '<?php echo $filters['user_id']; ?>';
        let currentM365Period = 30;

        if (currentM365User) loadM365Insights(currentM365User, currentM365Period);

        document.querySelectorAll('.ep-period-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.ep-period-btn').forEach(b => {
                    b.style.background = '#fff';
                    b.style.color = '#0078d4';
                });
                this.style.background = '#0078d4';
                this.style.color = '#fff';
                currentM365Period = this.dataset.period;
                if (currentM365User) loadM365Insights(currentM365User, currentM365Period);
            });
        });

        function loadM365Insights(uid, period = 30) {
            const container = document.getElementById('m365-insights-container');
            if (!container) return;
            container.style.display = 'block';

            // Mostrar cargando en los valores
            document.querySelectorAll('#m365-insights-content .stat-value').forEach(el => el.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size: 0.8em;"></i>');

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ep_stats_get_m365_activity',
                    security: '<?php echo wp_create_nonce('ep_stats_nonce'); ?>',
                    user_id: uid,
                    period: period
                })
            })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        document.getElementById('m365-no-user-message').style.display = 'none';
                        document.getElementById('m365-insights-content').style.display = 'grid';
                        document.getElementById('m365-total-meetings').textContent = res.data.total_meetings || 0;
                        document.getElementById('m365-total-hours').textContent = Math.round(res.data.total_hours || 0) + 'h';
                        // Teams presence status - translate to Spanish
                        const teamsTranslations = {
                            'Available': 'Disponible', 'Busy': 'Ocupado',
                            'DoNotDisturb': 'No molestar',
                            'BeRightBack': 'Vuelvo enseguida',
                            'Away': 'Ausente',
                            'Offline': 'Desconectado',
                            'PresenceUnknown': 'Desconocido',
                            'InACall': 'En llamada',
                            'InAConferenceCall': 'En conferencia',
                            'InAMeeting': 'En reunión',
                            'Presenting': 'Presentando',
                            'UrgentInterruptionsOnly': 'Solo urgentes',
                            'OutOfOffice': 'Fuera de oficina',
                            'unknown': 'Desconocido'
                        };
                        const teamsStatusEl = document.getElementById('m365-teams-meetings');
                        const teamsHoursEl = document.getElementById('m365-teams-hours');
                        const statusRaw = res.data.teams_status || 'unknown';
                        const activityRaw = res.data.teams_activity || 'unknown';
                        if (teamsStatusEl) teamsStatusEl.textContent = teamsTranslations[statusRaw] || statusRaw;
                        if (teamsHoursEl) teamsHoursEl.textContent = teamsTranslations[activityRaw] || activityRaw;
                        document.getElementById('m365-busy-day').textContent = res.data.most_busy_day || 'N/A';
                    }
                });
        }

        // --- Sincronización Manual M365 ---
        document.getElementById('ep-sync-m365')?.addEventListener('click', function () {
            const btn = this;
            const status = document.getElementById('ep-sync-status');
            const originalHtml = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
            status.textContent = 'Llamando a Microsoft Graph (Reports API)...';

            const fd = new FormData();
            fd.append('action', 'ep_stats_sync_m365');
            fd.append('security', '<?php echo wp_create_nonce("ep_stats_nonce"); ?>');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        btn.style.borderColor = '#4caf50';
                        btn.style.color = '#4caf50';
                        btn.innerHTML = '<i class="fas fa-check"></i> Éxito';
                        status.textContent = 'Reportes actualizados. Recargando tabla...';
                        setTimeout(() => {
                            btn.disabled = false;
                            btn.innerHTML = originalHtml;
                            btn.style.borderColor = '#0078d4';
                            btn.style.color = '#0078d4';
                            status.textContent = '';
                            loadUsersSummary(1); // Reload current view
                        }, 2000);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                        status.textContent = 'Error: ' + (res.data || 'desconocido');
                        status.style.color = 'red';
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    status.textContent = 'Error de conexión.';
                    status.style.color = 'red';
                });
        });

        // --- Exportación CSV ---
        function downloadCSV(action, extraParams = {}) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo admin_url('admin-ajax.php'); ?>';
            form.target = '_blank';

            const addField = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            };

            addField('action', action);
            addField('security', '<?php echo wp_create_nonce("ep_stats_nonce"); ?>');

            for (const [key, value] of Object.entries(extraParams)) {
                addField(key, value);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        document.getElementById('ep-export-stats')?.addEventListener('click', function (e) {
            e.preventDefault();
            const filterForm = document.querySelector('.ep-stats-filters');
            let params = {};
            if (filterForm) {
                const formData = new FormData(filterForm);
                for (let [key, value] of formData.entries()) {
                    if (value && key !== 'view') params['filters[' + key + ']'] = value;
                }
            }
            downloadCSV('ep_stats_export', params);
        });

        document.getElementById('ep-export-connections')?.addEventListener('click', function (e) {
            e.preventDefault();
            downloadCSV('ep_stats_export_connections');
        });

        document.getElementById('ep-export-users')?.addEventListener('click', function (e) {
            e.preventDefault();
            downloadCSV('ep_stats_export_users', { period: usersPeriod });
        });

    });
</script>

<style>
    .ep-stats-container {
        padding: 20px;
    }

    .ep-card {
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        margin-bottom: 25px;
    }

    .ep-tab-pane {
        display: none;
    }

    .ep-tab-pane.active {
        display: block;
    }

    .ep-stats-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        background: #f5f5f5;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        color: #555;
    }

    .ep-stats-btn {
        padding: 5px 12px;
        font-size: 11px;
        border-radius: 4px;
        border: 1px solid #ddd;
        background: #f9f9f9;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 600;
    }

    .ep-stats-btn:hover {
        background: #eee;
        border-color: #ccc;
    }

    .ep-stats-btn.active {
        background: #0078d4;
        color: #fff;
        border-color: #0078d4;
    }

    /* Period selector buttons - M365 insights */
    .ep-period-btn {
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        border: none;
        border-right: 1px solid #0078d4;
        background: #fff;
        color: #0078d4;
        cursor: pointer;
        transition: all 0.2s;
    }

    .ep-period-btn:last-child {
        border-right: none;
    }

    .ep-period-btn:hover {
        background: #e3f2fd;
    }

    .ep-period-btn.active {
        background: #0078d4;
        color: #fff;
    }

    /* Period selector buttons - Users tab */
    .ep-period-btn-users {
        padding: 5px 12px;
        font-size: 12px;
        font-weight: 600;
        border: none;
        border-right: 1px solid #0078d4;
        background: #fff;
        color: #0078d4;
        cursor: pointer;
        transition: all 0.2s;
    }

    .ep-period-btn-users:last-child {
        border-right: none;
    }

    .ep-period-btn-users:hover {
        background: #e3f2fd;
    }

    .ep-period-btn-users.active {
        background: #0078d4;
        color: #fff;
    }

    .stat-label {
        display: block;
        font-size: 0.75rem;
        color: #666;
        margin-bottom: 5px;
        text-transform: uppercase;
    }

    .stat-value {
        display: block;
        font-size: 1.5rem;
        font-weight: 700;
        color: #9e1c2e;
    }

    .ep-stat-item {
        background: rgba(0, 0, 0, 0.03);
        padding: 15px;
        border-radius: 8px;
        text-align: center;
    }

    @media (max-width: 768px) {
        .ep-grid-row {
            grid-template-columns: 1fr !important;
        }

        .connections-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }
</style>