<?php
/**
 * Centralized Layout for Employee Portal
 * Variables available: $content, $view
 */
$dark_mode = get_user_meta(get_current_user_id(), 'ep_dark_mode', true);
$dark_mode_class = ($dark_mode === 'on') ? 'dark-mode' : '';

// Fetch Customization
$custom = get_option('ep_portal_customization', array());
$logo_url = $custom['logo_url'] ?? plugin_dir_url(dirname(__FILE__, 2)) . 'public/images/logo-portal.jpg'; // Reemplazado dominio estático por fallback local
$portal_name = $custom['portal_name'] ?? 'Portal del Empleado';
$primary_color = $custom['primary_color'] ?? '#a81c24';
$main_font = $custom['main_font'] ?? 'Inter';

// Font Mapping for Google Fonts
$font_imports = array(
    'Inter' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
    'Roboto' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap',
    'Montserrat' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap',
    'Outfit' => 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap',
);
?>
<style>
    <?php if (isset($font_imports[$main_font])): ?>
        @import url('<?php echo $font_imports[$main_font]; ?>');
    <?php endif; ?>

    :root {
        --ep-primary:
            <?php echo $primary_color; ?>
        ;
        --ep-primary-rgb:
            <?php echo implode(',', sscanf($primary_color, "#%02x%02x%02x")); ?>
        ;
        --ep-font-sans: '<?php echo $main_font; ?>', sans-serif;
    }
</style>

<div class="ep-dashboard-container <?php echo $dark_mode_class; ?>" id="ep-app-root">
    <!-- Sidebar -->
    <aside class="ep-sidebar" id="epSidebar">
        <div class="ep-logo">
            <div class="ep-logo-brand">
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($portal_name); ?>">
                <span><?php echo nl2br(esc_html($portal_name)); ?></span>
            </div>
            <button class="ep-sidebar-close" id="epSidebarClose" title="Cerrar menú">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <nav class="ep-nav">
            <ul>
                <li class="<?php echo ($view === 'dashboard' || empty($view)) ? 'active' : ''; ?>">
                    <a href="?view=dashboard"><i class="fa-solid fa-house-chimney"></i> Inicio</a>
                </li>
                <?php
                global $ep_app_manager;
                $apps = $ep_app_manager->get_apps();
                foreach ($apps as $app_id => $app) {
                    if ($app_id === 'settings')
                        continue; // Handled in user menu/footer or separate
                    if ($ep_app_manager->get_user_permission($app_id) !== 'none') {
                        $active_class = ($view === $app_id) ? 'active' : '';
                        echo '<li class="' . esc_attr($active_class) . '">';
                        echo '<a href="?view=' . esc_attr($app_id) . '">';
                        echo '<i class="' . esc_attr($app->get_icon()) . '"></i> ';
                        echo esc_html($app->get_menu_label());
                        echo '</a></li>';
                    }
                }
                ?>
            </ul>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="ep-main">
        <!-- Header -->
        <header class="ep-header">
            <div class="ep-header-left">
                <button class="ep-menu-toggle" id="epMenuToggle" title="Mostrar/Ocultar menú">
                    <i class="fa-solid fa-bars-staggered"></i>
                </button>
            </div>

            <!-- Teleprompter de Eventos -->
            <div class="ep-teleprompter" id="epTeleprompter">
                <div class="ep-tp-label">PRÓXIMO EVENTO</div>
                <div class="ep-tp-content" id="epTeleprompterContent">
                    <span class="ep-tp-scroll">Cargando próximos eventos...</span>
                </div>
            </div>

            <div class="ep-user-menu">
                <!-- Presence Widget -->
                <div class="ep-presence-container" id="epPresenceContainer">
                    <button class="ep-presence-trigger" id="epPresenceTrigger" title="Compañeros conectados">
                        <i class="fa-solid fa-users"></i>
                        <span class="ep-presence-active-dot"></span>
                    </button>
                    <div class="ep-presence-dropdown" id="epPresenceDropdown">
                        <div class="ep-presence-header">
                            <span>Compañeros Conectados</span>
                        </div>
                        <div class="ep-presence-list" id="epPresenceList">
                            <div class="ep-presence-loading">Consultando disponibilidad...</div>
                        </div>
                        <div class="ep-presence-footer">
                            <a href="?view=directory">Ver Directorio Completo</a>
                        </div>
                    </div>
                </div>

                <!-- Dark Mode Toggle -->
                <button class="ep-dark-mode-toggle" id="epDarkModeToggle" title="Cambiar modo claro/oscuro">
                    <i class="fa-solid <?php echo ($dark_mode === 'on') ? 'fa-sun' : 'fa-moon'; ?>"></i>
                </button>

                <!-- Notifications Bell -->
                <div class="ep-notifications-container" id="epNotificationsTrigger">
                    <div class="notification-icon">
                        <i class="fa-solid fa-bell"></i>
                        <?php
                        $unread_count = EP_Notifications::count_unread(get_current_user_id());
                        if ($unread_count > 0):
                            ?>
                            <span class="badge" id="epNotificationsBadge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Notifications Dropdown -->
                    <div class="ep-notifications-dropdown" id="epNotificationsDropdown">
                        <div class="dropdown-header">
                            <span>Notificaciones</span>
                            <a href="#" id="epMarkAllRead">Marcar todas como leídas</a>
                        </div>
                        <div class="notifications-list" id="epNotificationsList">
                            <div class="loading-notifications">Cargando...</div>
                        </div>
                        <div class="dropdown-footer">
                            <a href="?view=notifications">Ver todas las notificaciones</a>
                        </div>
                    </div>
                </div>

                <div class="user-avatar-container" id="epUserMenuTrigger">
                    <div class="user-avatar">
                        <?php echo get_avatar(get_current_user_id(), 32); ?>
                    </div>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <!-- User Dropdown -->
                <div class="ep-user-dropdown" id="epUserDropdown">
                    <a href="?view=profile"><i class="fa-solid fa-user-circle"></i> Mi Perfil</a>
                    <a href="?view=settings"><i class="fa-solid fa-cog"></i> Configuración</a>
                    <hr>
                    <a href="<?php echo wp_logout_url(home_url('/')); ?>"><i class="fa-solid fa-sign-out-alt"></i>
                        Cerrar Sesión</a>
                </div>
            </div>
        </header>

        <!-- Dynamic Content Area -->
        <div class="ep-content-area">
            <?php echo $content; ?>
        </div>
    </main>
</div>