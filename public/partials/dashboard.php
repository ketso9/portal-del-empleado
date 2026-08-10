<?php

defined('ABSPATH') || exit;
$current_user = wp_get_current_user();
$photo_url = get_user_meta($current_user->ID, 'ep_user_photo_url', true);
$small_avatar_html = $photo_url ? '<img src="' . esc_url($photo_url) . '" alt="Profile Photo">' : get_avatar($current_user->ID, 32);

// Fetch real announcements (guard against module not being loaded on client sites)
$announcements = class_exists('EP_Avisos') ? EP_Avisos::get_active_avisos(5) : [];
?>

<div class="ep-content-grid">
    <div class="ep-dashboard-main-row">
        <!-- Widget: Anuncios -->
        <section class="ep-announcements-section">
            <div class="ep-card glass announcement-widget">
                <div class="widget-header">
                    <h3><i class="fa-solid fa-bullhorn"></i> Anuncios y Comunicados</h3>
                    <span class="widget-link">Últimas novedades</span>
                </div>
                <div class="widget-content">
                    <?php if (!empty($announcements)): ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="ep-announcement-mini-item">
                                <div class="announcement-title"><?php echo esc_html($announcement['title']); ?></div>
                                <a href="javascript:void(0)" class="ep-link view-announcement"
                                    data-id="<?php echo $announcement['id']; ?>">Ver detalle</a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="ep-widget-loading">No hay anuncios recientes.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Widget: Calendario -->
        <section class="ep-calendar-section">
            <div class="ep-card glass calendar-card clickable-card" onclick="window.location.href='?view=calendar'">
                <div class="widget-header">
                    <h3><i class="fa-solid fa-calendar-days"></i> Calendario</h3>
                    <span class="widget-link">Ver agenda <i class="fa-solid fa-arrow-right"></i></span>
                </div>
                <div class="widget-content">
                    <div class="calendar-mockup" id="dashboard-calendar-widget">
                        <div class="calendar-header-mini"><?php echo date_i18n('F Y'); ?></div>
                        <div class="calendar-grid-mini">
                            <span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span>
                            <?php
                            $year = date('Y');
                            $month = date('m');
                            $today = (int) date('j');
                            $days_in_month = (int) date('t');
                            $first_day = (int) date('N', strtotime(date('Y-m-01')));

                            for ($i = 1; $i < $first_day; $i++) {
                                echo '<span class="faded">.</span>';
                            }

                            for ($d = 1; $d <= $days_in_month; $d++) {
                                $date_str = sprintf('%s-%s-%02d', $year, $month, $d);
                                $class = ($d === $today) ? 'today' : '';
                                echo '<span class="' . $class . ' calendar-day-interactive" data-date="' . $date_str . '">' . $d . '<div class="event-indicator"></div></span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Widget: Microsoft To Do -->
        <section class="ep-todo-section">
            <div class="ep-card glass todo-widget">
                <div class="widget-header">
                    <h3><i class="fa-solid fa-check-double"></i> Mis Tareas (To Do)</h3>
                    <a href="https://to-do.office.com" target="_blank" class="widget-link">Gestionar <i
                            class="fa-solid fa-external-link"></i></a>
                </div>
                <div class="widget-content" id="ep-todo-list">
                    <div class="ep-widget-loading">Cargando tareas...</div>
                </div>
            </div>
        </section>

        <!-- Widget: Outlook Recent Mail -->
        <section class="ep-mail-section">
            <div class="ep-card glass mail-widget">
                <div class="widget-header">
                    <h3><i class="fa-solid fa-envelope"></i> Correo Reciente</h3>
                    <a href="https://outlook.office.com" target="_blank" class="widget-link">Abrir Outlook <i
                            class="fa-solid fa-external-link"></i></a>
                </div>
                <div class="widget-content" id="ep-mail-list">
                    <div class="ep-widget-loading">Cargando correos...</div>
                </div>
            </div>
        </section>
    </div>

    <!-- Apps Grid Section -->
    <section class="ep-apps-section full-width">
        <div class="ep-apps-header">
            <h2>Aplicaciones</h2>
            <div class="ep-apps-header-toolbar">
                <span class="ep-apps-drag-hint">
                    <i class="fa-solid fa-arrows-up-down-left-right"></i> Organiza tus apps
                </span>
                <button type="button" class="ep-btn ep-btn-sm ep-btn-secondary" id="epSortAZBtn" title="Ordenar de A a la Z">
                    <i class="fa-solid fa-arrow-down-a-z"></i> Ordenar A-Z
                </button>
                <button type="button" class="ep-btn ep-btn-sm ep-btn-outline" id="epResetOrderBtn" title="Restablecer orden por defecto">
                    <i class="fa-solid fa-rotate-left"></i> Restablecer
                </button>
            </div>
        </div>
        <div class="ep-apps-grid" id="epAppsGrid">
            <?php
            global $ep_app_manager;
            $apps = $ep_app_manager->get_apps_for_user(get_current_user_id());

            foreach ($apps as $app_id => $app) {
                // Check permission
                if ($ep_app_manager->get_user_permission($app_id) !== 'none') {
                    ob_start();
                    $app->render_dashboard_card();
                    $card_html = ob_get_clean();

                    // Inject draggable and data-app-id into outer ep-app-card div
                    $card_html = preg_replace(
                        '/class=["\']([^"\']*ep-app-card[^"\']*)["\']/',
                        'class="$1" draggable="true" data-app-id="' . esc_attr($app_id) . '"',
                        $card_html,
                        1
                    );
                    echo $card_html;
                }
            }
            ?>
        </div>
    </section>

    <!-- Event Tooltip Container (Placed at root to avoid stacking context issues) -->
    <div id="calendar-event-tooltip" class="ep-calendar-tooltip">
        <div class="tooltip-header">Eventos del día</div>
        <div class="tooltip-body" id="tooltip-events-list">
            <!-- Events populated via JS -->
        </div>
    </div>

    <!-- Announcement Modal for Dashboard -->
    <div id="dashboard-aviso-modal" class="ep-modal" style="align-items: center; justify-content: center;">
        <div class="ep-modal-content" style="position: relative; margin: 0 auto; max-height: 80vh; overflow-y: auto;">
            <span class="close-modal" id="close-dashboard-aviso-modal"
                style="position: absolute; right: 20px; top: 15px; font-size: 28px; cursor: pointer; color: #7f8c8d;">&times;</span>
            <div id="dashboard-modal-body">
                <!-- Content populated via JS -->
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const dashboardAnuncios = <?php echo json_encode($announcements); ?>;
        const avisoModal = document.getElementById('dashboard-aviso-modal');
        const modalBody = document.getElementById('dashboard-modal-body');
        const closeBtn = document.getElementById('close-dashboard-aviso-modal');

        // Attach click events to the "Ver detalle" buttons in the dashboard
        document.querySelectorAll('.ep-announcements-section .view-announcement').forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                const id = parseInt(this.getAttribute('data-id'), 10);
                const aviso = dashboardAnuncios.find(a => parseInt(a.id, 10) === id);

                if (aviso) {
                    let attachmentsHtml = '';
                    if (aviso.attachments && aviso.attachments.length > 0) {
                        attachmentsHtml = '<div class="aviso-attachments" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;"><strong>Adjuntos:</strong><br>';
                        aviso.attachments.forEach(att => {
                            attachmentsHtml += `<a href="${att.url}" target="_blank" class="attachment-link" style="display: inline-block; padding: 5px 10px; background: #f1f8ff; border-radius: 4px; margin-right: 10px; margin-bottom: 5px; text-decoration: none; font-size: 0.85rem; color: #0366d6;"><i class="fa-solid fa-file-lines"></i> ${att.name}</a>`;
                        });
                        attachmentsHtml += '</div>';
                    }

                    modalBody.innerHTML = `
                    <h2 style="margin-top:0; color: #2c3e50;">${aviso.title}</h2>
                    <div class="meta" style="color:#7f8c8d; margin-bottom:20px; font-size: 0.9rem;">
                        Publicado el ${aviso.date} por ${aviso.author}
                    </div>
                    <div class="full-content" style="line-height: 1.6; color: #34495e;">${aviso.content.replace(/\n/g, '<br>')}</div>
                    ${attachmentsHtml}
                `;
                    avisoModal.style.display = 'flex';
                }
            });
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                avisoModal.style.display = 'none';
            });
        }

        window.addEventListener('click', function (e) {
            if (e.target === avisoModal) {
                avisoModal.style.display = 'none';
            }
        });
    });
</script>