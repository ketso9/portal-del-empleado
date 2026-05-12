<?php
defined('ABSPATH') || exit;

$user_id = get_current_user_id();
$nonce = wp_create_nonce('ep_calendar_nonce');
?>

<div class="ep-calendar-app" id="ep-app-root">
    <div class="calendar-header-actions">
        <h2 class="ep-section-title"><i class="fa-solid fa-calendar-days"></i> Mi Agenda Personal</h2>
        <div class="calendar-views-filter">
            <button class="ep-btn btn-view active" data-view="dayGridMonth">Mes</button>
            <button class="ep-btn btn-view" data-view="timeGridWeek">Semana</button>
            <button class="ep-btn btn-view" data-view="timeGridDay">Día</button>
        </div>
    </div>

    <div class="ep-calendar-layout">
        <!-- Sidebar -->
        <div class="ep-calendar-sidebar">
            <div class="sidebar-section">
                <h3><i class="fa-regular fa-calendar-check"></i> Mis Calendarios</h3>
                <div id="ep-calendar-list-loading">
                    <i class="fa-solid fa-spinner fa-spin"></i> Cargando...
                </div>
                <div id="ep-calendar-list" style="display:none;">
                    <!-- Checkboxes will be injected here -->
                </div>
                
                <button id="btn-add-calendar" class="ep-btn-small" style="margin-top: 15px; width: 100%;">
                    <i class="fa-solid fa-user-plus"></i> Añadir Compañero
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="ep-calendar-main">
            <div class="ep-card calendar-main-card">
                <div id="ep-fullcalendar"></div>

                <!-- Debug Console (Hidden by default, can be toggled via console with EP_DEBUG=true) -->
                <div id="ep-calendar-debug"
                    style="display:none; margin-top:20px; padding:15px; background:#f8d7da; border:1px solid #f5c6cb; border-radius:8px; font-family:monospace; font-size:12px; max-height:200px; overflow-y:auto;">
                    <strong>Debug Console:</strong>
                    <ul id="debug-log-list" style="margin:5px 0; padding-left:15px;"></ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* New Layout Styles */
    .ep-calendar-layout {
        display: grid;
        grid-template-columns: 250px 1fr;
        gap: 20px;
        align-items: start;
    }

    .ep-calendar-sidebar {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    .sidebar-section h3 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 15px;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .calendar-checkbox-item {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
        padding: 8px;
        border-radius: 6px;
        transition: background 0.2s;
        cursor: pointer;
    }

    .calendar-checkbox-item:hover {
        background: #f1f5f9;
    }

    .calendar-checkbox-item input[type="checkbox"] {
        accent-color: var(--ep-primary);
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    .calendar-checkbox-item label {
        font-size: 0.9rem;
        color: #475569;
        cursor: pointer;
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .calendar-color-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
    }

    /* Estilo para botones pequeños tipo Portal */
    .ep-btn-small {
        background: #ffffff !important;
        color: #1e293b !important;
        border: 1px solid #e2e8f0 !important;
        padding: 8px 16px !important;
        border-radius: 20px !important;
        font-size: 0.85rem !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: all 0.2s ease !important;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
        text-decoration: none !important;
    }

    .ep-btn-small:hover {
        background: #f8fafc !important;
        border-color: var(--ep-primary) !important;
        color: var(--ep-primary) !important;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        transform: translateY(-1px);
    }

    .ep-btn-small i {
        font-size: 0.9rem !important;
    }

    /* Responsive */
    @media (max-width: 900px) {
        .ep-calendar-layout {
            grid-template-columns: 1fr;
        }

        .ep-calendar-sidebar {
            margin-bottom: 20px;
        }
    }
</style>

<!-- Event Detail Modal -->
<div id="event-detail-modal" class="ep-modal">
    <div class="ep-modal-content">
        <span class="ep-close close-calendar-modal">&times;</span>
        <div class="modal-event-header">
            <div class="event-icon color-blue">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
            <h2 id="modal-event-title">Detalles del Evento</h2>
        </div>

        <div class="modal-event-body">
            <div class="info-row">
                <i class="fa-regular fa-clock"></i>
                <div>
                    <strong>Horario:</strong>
                    <p id="modal-event-time"></p>
                </div>
            </div>

            <div class="info-row" id="modal-location-row">
                <i class="fa-solid fa-location-dot"></i>
                <div>
                    <strong>Ubicación:</strong>
                    <p id="modal-event-location"></p>
                </div>
            </div>

            <div class="info-row">
                <i class="fa-solid fa-align-left"></i>
                <div>
                    <strong>Descripción:</strong>
                    <div id="modal-event-description" class="event-description-text"></div>
                </div>
            </div>
        </div>

        <div class="modal-event-footer">
            <button class="ep-btn ep-btn-secondary close-calendar-modal">Cerrar</button>
            <a href="https://outlook.office.com/calendar/" target="_blank" class="ep-btn ep-btn-primary">Ver en
                Outlook</a>
        </div>
    </div>
</div>

<!-- Add Shared Calendar Modal (NEW) -->
<div id="add-calendar-modal" class="ep-modal">
    <div class="ep-modal-content" style="max-width: 450px;">
        <span class="ep-close close-add-modal" style="float:right; cursor:pointer; font-size:1.5rem;">&times;</span>
        <div class="modal-event-header" style="border-bottom:none; padding-bottom:0;">
            <h2 style="margin:0;">Añadir Agenda</h2>
        </div>
        <p style="color:#64748b; margin-bottom:20px;">Busca un compañero para ver su disponibilidad.</p>
        
        <div style="display:flex; gap:10px; margin-bottom:15px;">
            <input type="text" id="user-search-input" placeholder="Nombre (ej: Marta)..." class="ep-form-control" style="flex:1; padding:8px;">
            <button id="btn-search-user" class="ep-btn ep-btn-primary">Buscar</button>
        </div>

        <div id="user-search-results" class="user-results-list" style="max-height:200px; overflow-y:auto; border:1px solid #eee; border-radius:6px; display:none;">
            <!-- Results injected here -->
        </div>
        <div id="user-search-loading" style="display:none; text-align:center; padding:10px; color:#64748b;">
            <i class="fa-solid fa-spinner fa-spin"></i> Buscando...
        </div>
        <div id="user-search-error" style="display:none; color:red; margin-top:10px; font-size:0.9rem;"></div>
    </div>
</div>

<script>
    window.epCalendarData = {
        nonce: '<?php echo $nonce; ?>',
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>'
    };
</script>

<style>
    .calendar-grid span.today.has-events::after {
        content: '';
        position: absolute;
        bottom: 4px;
        left: 50%;
        transform: translateX(-50%);
        width: 4px;
        height: 4px;
        background: white;
        border-radius: 50%;
    }

    .calendar-grid span:not(.today).has-events::after {
        content: '';
        position: absolute;
        bottom: 4px;
        left: 50%;
        transform: translateX(-50%);
        width: 4px;
        height: 4px;
        background: var(--ep-primary);
        border-radius: 50%;
    }

    .calendar-grid span {
        position: relative;
    }

    /* Inline styles for quick layout, we will also use assets/css/calendar.css */
    .calendar-header-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .calendar-views-filter {
        display: flex;
        gap: 10px;
        background: #f8fafc;
        padding: 5px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
    }

    .btn-view {
        padding: 8px 16px;
        font-size: 0.9rem;
        background: transparent;
        color: #64748b;
        border: none;
        box-shadow: none;
    }

    .btn-view.active {
        background: white;
        color: var(--ep-primary);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border-radius: 8px;
    }

    .calendar-main-card {
        padding: 20px;
        min-height: 600px;
    }

    /* Modal Styles */
    .modal-event-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 25px;
        border-bottom: 1px solid #eee;
        padding-bottom: 15px;
    }

    .modal-event-body .info-row {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
    }

    .modal-event-body .info-row i {
        color: var(--ep-primary);
        font-size: 1.2rem;
        width: 24px;
        text-align: center;
        margin-top: 3px;
    }

    .event-description-text {
        color: #64748b;
        font-size: 0.95rem;
        line-height: 1.5;
        max-height: 200px;
        overflow-y: auto;
        white-space: pre-wrap;
    }

    .modal-event-footer {
        margin-top: 30px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 20px;
        border-top: 1px solid #eee;
    }
    
    .user-result-item {
        padding: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #f1f5f9;
        cursor: default;
    }
    .user-result-item:hover {
        background: #f8fafc;
    }
    .user-result-item button {
        padding: 4px 8px;
        font-size: 0.8rem;
    }
</style>