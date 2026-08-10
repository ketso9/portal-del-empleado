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

    <!-- Executive KPI Cards -->
    <div class="ep-kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 25px;">
        <div class="ep-card ep-kpi-card" style="padding: 16px; display: flex; align-items: center; gap: 14px; border-left: 4px solid #0078d4;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(0, 120, 212, 0.1); color: #0078d4; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Usuarios Activos</div>
                <div id="kpi-active-users" style="font-size: 1.4rem; font-weight: 800; color: var(--ep-text, #1e293b); margin-top: 2px;"><?php echo intval($kpis['active_users'] ?? 0); ?></div>
            </div>
        </div>

        <div class="ep-card ep-kpi-card" style="padding: 16px; display: flex; align-items: center; gap: 14px; border-left: 4px solid #10b981;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fas fa-clock"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Tiempo Portal</div>
                <div id="kpi-total-hours" style="font-size: 1.4rem; font-weight: 800; color: var(--ep-text, #1e293b); margin-top: 2px;"><?php echo floatval($kpis['total_hours'] ?? 0); ?>h</div>
            </div>
        </div>

        <div class="ep-card ep-kpi-card" style="padding: 16px; display: flex; align-items: center; gap: 14px; border-left: 4px solid #8b5cf6;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(139, 92, 246, 0.1); color: #8b5cf6; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fas fa-file-signature"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Firmas Digitales</div>
                <div id="kpi-signed-docs" style="font-size: 1.4rem; font-weight: 800; color: var(--ep-text, #1e293b); margin-top: 2px;"><?php echo intval($kpis['signed_docs'] ?? 0); ?></div>
            </div>
        </div>

        <div class="ep-card ep-kpi-card" style="padding: 16px; display: flex; align-items: center; gap: 14px; border-left: 4px solid #f59e0b;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fas fa-ticket-alt"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Tickets Gestionados</div>
                <div id="kpi-resolved-tickets" style="font-size: 1.4rem; font-weight: 800; color: var(--ep-text, #1e293b); margin-top: 2px;"><?php echo intval($kpis['resolved_tickets'] ?? 0); ?></div>
            </div>
        </div>

        <div class="ep-card ep-kpi-card" style="padding: 16px; display: flex; align-items: center; gap: 14px; border-left: 4px solid #ef4444;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(239, 68, 68, 0.1); color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Variación %</div>
                <div id="kpi-growth-pct" style="font-size: 1.4rem; font-weight: 800; color: <?php echo (($kpis['growth_pct'] ?? 0) >= 0) ? '#10b981' : '#ef4444'; ?>; margin-top: 2px;">
                    <?php echo (($kpis['growth_pct'] ?? 0) >= 0 ? '+' : '') . floatval($kpis['growth_pct'] ?? 0); ?>%
                </div>
            </div>
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
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                    <div class="ep-form-group">
                        <label>Aplicación</label>
                        <select name="app_id" class="ep-input">
                            <option value="">Todas</option>
                            <option value="auth" <?php selected($filters['app_id'], 'auth'); ?>>Autenticación</option>
                            <option value="tickets" <?php selected($filters['app_id'], 'tickets'); ?>>Tickets</option>
                            <option value="signature" <?php selected($filters['app_id'], 'signature'); ?>>Firmas</option>
                            <option value="inventory" <?php selected($filters['app_id'], 'inventory'); ?>>Inventario</option>
                            <option value="avisos" <?php selected($filters['app_id'], 'avisos'); ?>>Avisos</option>
                            <option value="downloads" <?php selected($filters['app_id'], 'downloads'); ?>>Descargas</option>
                            <option value="censo" <?php selected($filters['app_id'], 'censo'); ?>>Censo</option>
                            <option value="directory" <?php selected($filters['app_id'], 'directory'); ?>>Directorio</option>
                            <option value="calendar" <?php selected($filters['app_id'], 'calendar'); ?>>Agenda</option>
                            <option value="empresas" <?php selected($filters['app_id'], 'empresas'); ?>>Empresas</option>
                            <option value="links" <?php selected($filters['app_id'], 'links'); ?>>Enlaces</option>
                            <option value="buzon" <?php selected($filters['app_id'], 'buzon'); ?>>Buzón</option>
                            <option value="contratos" <?php selected($filters['app_id'], 'contratos'); ?>>Contratos</option>
                            <option value="gdpr" <?php selected($filters['app_id'], 'gdpr'); ?>>GDPR</option>
                            <option value="o365" <?php selected($filters['app_id'], 'o365'); ?>>Office 365</option>
                            <option value="system" <?php selected($filters['app_id'], 'system'); ?>>Sistema</option>
                        </select>
                    </div>
                    <div class="ep-form-group">
                        <label>Evento</label>
                        <select name="event_type" class="ep-input">
                            <option value="">Todos</option>
                            <option value="login" <?php selected($filters['event_type'], 'login'); ?>>Inicio de Sesión</option>
                            <option value="document_signed" <?php selected($filters['event_type'], 'document_signed'); ?>>Firmas</option>
                            <option value="security_alert" <?php selected($filters['event_type'], 'security_alert'); ?>>Alertas de Seguridad</option>
                            <option value="post_deleted" <?php selected($filters['event_type'], 'post_deleted'); ?>>Borrados</option>
                        </select>
                    </div>
                    <div class="ep-form-group">
                        <label>Departamento</label>
                        <select name="department" class="ep-input">
                            <option value="">Todos los dptos.</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo esc_attr($dept); ?>" <?php selected($filters['department'], $dept); ?>>
                                    <?php echo esc_html($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ep-form-group">
                        <label>Desde</label>
                        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" class="ep-input">
                    </div>
                    <div class="ep-form-group">
                        <label>Hasta</label>
                        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" class="ep-input">
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
                    style="margin-top:0; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fas fa-chart-line"></i> Tendencia de Actividad</span>
                    <span style="font-size: 11px; font-weight: normal; color: #888;">(<?php echo !empty($filters['date_from']) ? 'Personalizado' : 'Últimos 30 días'; ?>)</span>
                </h4>
                <div style="height: 250px;">
                    <canvas id="activityChart"></canvas>
                </div>
            </div>
            <div class="ep-card">
                <h4
                    style="margin-top:0; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fas fa-chart-pie"></i> Uso por Aplicación</span>
                    <span style="font-size: 11px; font-weight: normal; color: #888;">(<?php echo !empty($filters['date_from']) ? 'Personalizado' : 'Últimos 30 días'; ?>)</span>
                </h4>
                <div style="height: 250px; display: flex; justify-content: center;">
                    <canvas id="appsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Distribución por Franja Horaria (Horas Punta) -->
        <div class="ep-card" style="margin-bottom: 30px;">
            <h4 style="margin-top:0; border-bottom: 1px solid var(--ep-border); padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                <span><i class="fas fa-clock"></i> Distribución de Actividad por Franja Horaria (Horas Punta)</span>
                <span style="font-size: 11px; font-weight: normal; color: #888;">(Registros entre las 00h y 23h)</span>
            </h4>
            <div style="height: 220px;">
                <canvas id="hourlyChart"></canvas>
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
            <!-- Header Bar -->
            <div style="border-bottom: 1px solid var(--ep-border); padding-bottom: 16px; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
                    <h4 style="margin:0; font-size:1.15rem; font-weight:700; color:var(--ep-text); display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-users" style="color:#0078d4;"></i> Resumen de Actividad por Empleado
                    </h4>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <button id="ep-load-all-calendars" class="ep-btn ep-btn-secondary ep-btn-sm" style="padding:5px 12px; font-size:12px; border-radius:6px;">
                            <i class="fas fa-calendar-check" style="color:#0078d4;"></i> Cargar Calendarios
                        </button>
                        <button id="ep-sync-m365" class="ep-btn ep-btn-secondary ep-btn-sm" style="padding:5px 12px; font-size:12px; border-radius:6px; background:#f0f4f9; color:#0078d4; border:1px solid #0078d4;">
                            <i class="fas fa-sync-alt"></i> Sincronizar M365
                        </button>
                        <button id="ep-export-users" class="ep-btn ep-btn-secondary ep-btn-sm" style="padding:5px 12px; font-size:12px; border-radius:6px;">
                            <i class="fas fa-file-export"></i> Exportar CSV
                        </button>
                        <span id="ep-sync-status" style="font-size:11px; color:#666; font-weight:500;"></span>
                    </div>
                </div>

                <!-- Filter Toolbar -->
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; background:var(--ep-surface-hover, #f8fafc); padding:10px 14px; border-radius:8px; border:1px solid var(--ep-border, #e2e8f0);">
                    <div style="position:relative; flex:1; min-width:200px;">
                        <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px;"></i>
                        <input type="text" id="ep-users-search-input" placeholder="Buscar empleado por nombre o email..." class="ep-input" style="padding-left:34px; font-size:13px; height:36px; width:100%; border-radius:6px;">
                    </div>
                    
                    <select id="ep-users-dept-select" class="ep-input" style="font-size:13px; height:36px; min-width:170px; border-radius:6px;">
                        <option value="">Todos los departamentos</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo esc_attr($dept); ?>"><?php echo esc_html($dept); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="ep-period-selector" style="display:flex; gap:0; border-radius:6px; overflow:hidden; border:1px solid #0078d4; background:#fff;">
                        <button class="ep-period-btn-users" data-period="1" style="padding:6px 14px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#0078d4; color:#fff; cursor:pointer;">Hoy</button>
                        <button class="ep-period-btn-users" data-period="7" style="padding:6px 14px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#fff; color:#0078d4; cursor:pointer;">7d</button>
                        <button class="ep-period-btn-users" data-period="30" style="padding:6px 14px; font-size:12px; font-weight:600; border:none; border-right:1px solid #0078d4; background:#fff; color:#0078d4; cursor:pointer;">30d</button>
                        <button class="ep-period-btn-users" data-period="365" style="padding:6px 14px; font-size:12px; font-weight:600; border:none; background:#fff; color:#0078d4; cursor:pointer;">365d</button>
                    </div>
                </div>
            </div>
            <div class="ep-table-container">
                <table class="ep-table">
                    <thead>
                        <tr id="ep-users-table-headers">
                            <th data-sort-key="name" style="cursor:pointer; user-select:none;" title="Ordenar por Nombre (A-Z / Z-A)">
                                Empleado <i class="fas fa-sort ep-sort-icon" style="margin-left:4px; opacity:0.4;"></i>
                            </th>
                            <th data-sort-key="portal_time" style="cursor:pointer; user-select:none;" title="Ordenar por Tiempo en Portal (Mayor/Menor)">
                                Tiempo Portal <i class="fas fa-sort ep-sort-icon" style="margin-left:4px; opacity:0.4;"></i>
                            </th>
                            <th data-sort-key="calendar" style="text-align:center; cursor:pointer; user-select:none;" title="Ordenar por Eventos de Agenda">
                                Calendario <i class="fas fa-sort ep-sort-icon" style="margin-left:4px; opacity:0.4;"></i>
                            </th>
                            <th data-sort-key="teams" style="text-align:center; cursor:pointer; user-select:none;" title="Ordenar por Tiempo en Teams">
                                Teams <i class="fas fa-sort ep-sort-icon" style="margin-left:4px; opacity:0.4;"></i>
                            </th>
                            <th data-sort-key="m365" style="text-align:center; cursor:pointer; user-select:none;" title="Ordenar por Actividad M365">
                                Actividad M365 <i class="fas fa-sort ep-sort-icon" style="margin-left:4px; opacity:0.4;"></i>
                            </th>
                            <th data-sort-key="apps" style="text-align:center; cursor:pointer; user-select:none;" title="Ordenar por Total de Actividad en Apps">
                                Apps Portal <i class="fas fa-sort ep-sort-icon" style="margin-left:4px; opacity:0.4;"></i>
                            </th>
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
        let cachedUsersList = [];
        let currentSortColumn = 'name';
        let currentSortDir = 'asc';

        // Period selector for Users tab
        document.querySelectorAll('.ep-period-btn-users').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.ep-period-btn-users').forEach(b => {
                    b.style.background = '#fff';
                    b.style.color = '#0078d4';
                });
                this.style.background = '#0078d4';
                this.style.color = '#fff';
                usersPeriod = parseInt(this.dataset.period);
                loadUsersSummary(1);
            });
        });

        // Header click listeners for column sorting
        document.querySelectorAll('#ep-users-table-headers th[data-sort-key]').forEach(th => {
            th.addEventListener('click', function () {
                const key = this.dataset.sortKey;
                if (currentSortColumn === key) {
                    currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
                } else {
                    currentSortColumn = key;
                    currentSortDir = (key === 'name') ? 'asc' : 'desc'; // Default DESC for numeric metrics (Mayor a Menor)
                }
                renderSortedUsers();
            });
        });

        function getAppTotalCount(u) {
            return (parseInt(u.tickets_count)||0) + (parseInt(u.signatures_count)||0) + 
                   (parseInt(u.inventory_count)||0) + (parseInt(u.censo_count)||0) + 
                   (parseInt(u.downloads_count)||0) + (parseInt(u.directory_count)||0) + 
                   (parseInt(u.empresas_count)||0) + (parseInt(u.links_count)||0) + 
                   (parseInt(u.buzon_count)||0) + (parseInt(u.contratos_count)||0) + 
                   (parseInt(u.gdpr_count)||0) + (parseInt(u.calendar_count)||0) + 
                   (parseInt(u.avisos_count)||0) + (parseInt(u.expenses_count)||0);
        }

        function getM365TotalCount(u) {
            if (u.m365_chats === null) return -1;
            return (parseInt(u.m365_chats)||0) + (parseInt(u.m365_calls)||0) + (parseInt(u.m365_meetings)||0);
        }

        function updateSortHeaderIcons() {
            document.querySelectorAll('#ep-users-table-headers th[data-sort-key]').forEach(th => {
                const key = th.dataset.sortKey;
                const icon = th.querySelector('.ep-sort-icon');
                if (!icon) return;

                if (key === currentSortColumn) {
                    if (key === 'name') {
                        icon.className = currentSortDir === 'asc' ? 'fas fa-sort-alpha-down' : 'fas fa-sort-alpha-down-alt';
                    } else {
                        icon.className = currentSortDir === 'asc' ? 'fas fa-sort-amount-up-alt' : 'fas fa-sort-amount-down';
                    }
                    icon.style.color = '#0078d4';
                    icon.style.opacity = '1';
                } else {
                    icon.className = 'fas fa-sort';
                    icon.style.color = '';
                    icon.style.opacity = '0.4';
                }
            });
        }

        function renderSortedUsers() {
            const body = document.getElementById('users-summary-tbody');
            if (!body || !cachedUsersList || cachedUsersList.length === 0) return;

            updateSortHeaderIcons();

            const sorted = [...cachedUsersList].sort((a, b) => {
                let valA, valB;
                switch (currentSortColumn) {
                    case 'name':
                        valA = (a.display_name || '').toLowerCase();
                        valB = (b.display_name || '').toLowerCase();
                        return currentSortDir === 'asc' 
                            ? valA.localeCompare(valB, 'es', { sensitivity: 'base' })
                            : valB.localeCompare(valA, 'es', { sensitivity: 'base' });
                    case 'portal_time':
                        valA = parseInt(a.total_duration) || 0;
                        valB = parseInt(b.total_duration) || 0;
                        break;
                    case 'calendar':
                        valA = parseInt(a.calendar_count) || 0;
                        valB = parseInt(b.calendar_count) || 0;
                        break;
                    case 'teams':
                        valA = parseInt(a.teams_seconds) || 0;
                        valB = parseInt(b.teams_seconds) || 0;
                        break;
                    case 'm365':
                        valA = getM365TotalCount(a);
                        valB = getM365TotalCount(b);
                        break;
                    case 'apps':
                        valA = getAppTotalCount(a);
                        valB = getAppTotalCount(b);
                        break;
                    default:
                        valA = 0;
                        valB = 0;
                }

                if (currentSortDir === 'asc') {
                    return valA > valB ? 1 : (valA < valB ? -1 : 0);
                } else {
                    return valA < valB ? 1 : (valA > valB ? -1 : 0);
                }
            });

            body.innerHTML = sorted.map(u => {
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
                    ${u.empresas_count > 0 ? `<span class="ep-stats-badge" style="background:#fff0f2; color:#d32f2f;" title="Empresas"><i class="fas fa-building"></i> ${u.empresas_count}</span>` : ''}
                    ${u.links_count > 0 ? `<span class="ep-stats-badge" style="background:#e8f5e9; color:#2e7d32;" title="Enlaces"><i class="fas fa-link"></i> ${u.links_count}</span>` : ''}
                    ${u.buzon_count > 0 ? `<span class="ep-stats-badge" style="background:#fffde7; color:#fbc02d;" title="Buzón"><i class="fas fa-envelope-open-text"></i> ${u.buzon_count}</span>` : ''}
                    ${u.contratos_count > 0 ? `<span class="ep-stats-badge" style="background:#fce4ec; color:#c2185b;" title="Contratos"><i class="fas fa-file-contract"></i> ${u.contratos_count}</span>` : ''}
                    ${u.gdpr_count > 0 ? `<span class="ep-stats-badge" style="background:#e8eaf6; color:#3f51b5;" title="GDPR"><i class="fas fa-user-shield"></i> ${u.gdpr_count}</span>` : ''}
                    ${u.calendar_count > 0 ? `<span class="ep-stats-badge" style="background:#e0f2f1; color:#00796b;" title="Agenda"><i class="fas fa-calendar-check"></i> ${u.calendar_count}</span>` : ''}
                    ${u.avisos_count > 0 ? `<span class="ep-stats-badge" style="background:#fff9c4; color:#fbc02d;" title="Avisos"><i class="fas fa-bullhorn"></i> ${u.avisos_count}</span>` : ''}
                    ${u.expenses_count > 0 ? `<span class="ep-stats-badge" style="background:#e0f2fe; color:#0284c7;" title="Gastos y Dietas"><i class="fas fa-file-invoice-dollar"></i> ${u.expenses_count}</span>` : ''}
                </div>
            </td>
        </tr>
    `}).join('');

            // Re-bind calendar load buttons
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

        function loadUsersSummary(page = 1) {
            const body = document.getElementById('users-summary-tbody');
            if (!body) return;
            body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Cargando resumen...</td></tr>';

            const searchVal = document.getElementById('ep-users-search-input')?.value || '';
            const deptVal = document.getElementById('ep-users-dept-select')?.value || '';

            const fd = new FormData();
            fd.append('action', 'ep_stats_get_users_summary');
            fd.append('security', '<?php echo wp_create_nonce("ep_stats_nonce"); ?>');
            fd.append('paged', page);
            fd.append('period', usersPeriod);
            fd.append('search', searchVal);
            fd.append('department', deptVal);
            fd.append('orderby', currentSortColumn);
            fd.append('order', currentSortDir);

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(res => {
                    if (res.success && res.data.length > 0) {
                        cachedUsersList = res.data;
                        renderSortedUsers();
                        setTimeout(loadAllCalendars, 300);
                    } else {
                        cachedUsersList = [];
                        body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#888;">No se encontraron empleados.</td></tr>';
                    }
                });
        }

        // Batch load calendars for all visible employees
        function loadAllCalendars() {
            const btns = Array.from(document.querySelectorAll('.load-calendar-btn'));
            if (btns.length === 0) return;

            const batchBtn = document.getElementById('ep-load-all-calendars');
            if (batchBtn) {
                batchBtn.disabled = true;
                batchBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#0078d4;"></i> Cargando...';
            }

            let index = 0;
            function processNext() {
                if (index >= btns.length) {
                    if (batchBtn) {
                        batchBtn.disabled = false;
                        batchBtn.innerHTML = '<i class="fas fa-check" style="color:#10b981;"></i> Calendarios cargados';
                        setTimeout(() => {
                            batchBtn.innerHTML = '<i class="fas fa-calendar-check" style="color:#0078d4;"></i> Cargar Calendarios';
                        }, 2500);
                    }
                    return;
                }

                const btn = btns[index];
                index++;
                if (!btn || !btn.parentNode || !btn.dataset.user) {
                    processNext();
                    return;
                }

                const uid = btn.dataset.user;
                const parent = btn.parentNode;
                parent.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#0078d4; font-size:11px;"></i>';

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
                            </div>`;
                        } else {
                            parent.innerHTML = '<span style="color:#94a3b8; font-size:11px;">-</span>';
                        }
                        setTimeout(processNext, 120);
                    })
                    .catch(() => {
                        parent.innerHTML = '<span style="color:#94a3b8; font-size:11px;">-</span>';
                        setTimeout(processNext, 120);
                    });
            }

            processNext();
        }

        document.getElementById('ep-load-all-calendars')?.addEventListener('click', loadAllCalendars);

        // Search & Department filter listeners for Tab Users
        let searchTimer = null;
        document.getElementById('ep-users-search-input')?.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                loadUsersSummary(1);
            }, 300);
        });

        document.getElementById('ep-users-dept-select')?.addEventListener('change', function() {
            loadUsersSummary(1);
        });

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
                        borderColor: '#0078d4', backgroundColor: 'rgba(0, 120, 212, 0.1)',
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
                        backgroundColor: [
                            '#0078d4', '#10b981', '#8b5cf6', '#f59e0b', '#ec4899', 
                            '#3b82f6', '#06b6d4', '#84cc16', '#f97316', '#64748b',
                            '#c2185b', '#7b1fa2', '#303f9f', '#00796b', '#689f38'
                        ]
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        const hourCtx = document.getElementById('hourlyChart')?.getContext('2d');
        if (hourCtx) {
            new Chart(hourCtx, {
                type: 'bar',
                data: {
                    labels: ['00h','01h','02h','03h','04h','05h','06h','07h','08h','09h','10h','11h','12h','13h','14h','15h','16h','17h','18h','19h','20h','21h','22h','23h'],
                    datasets: [{
                        label: 'Eventos por hora',
                        data: <?php echo json_encode(array_values($hourly_distribution)); ?>,
                        backgroundColor: 'rgba(0, 120, 212, 0.8)',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
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