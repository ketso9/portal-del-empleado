<?php

if (!defined('ABSPATH')) {
    exit;
}

class EP_Setup_Wizard
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_wizard_page'));
        add_action('admin_init', array($this, 'redirect_to_wizard'));
        add_action('wp_ajax_ep_save_setup_wizard', array($this, 'ajax_save_wizard'));
    }

    public function register_wizard_page()
    {
        add_submenu_page(
            null, // Oculto del menú lateral
            'Asistente de Configuración - Portal del Empleado',
            'Wizard',
            'manage_options',
            'ep-setup-wizard',
            array($this, 'render_wizard_page')
        );
    }

    public function redirect_to_wizard()
    {
        if (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL)
            return;

        // Solo redirigimos si no se ha completado el wizard y estamos en el admin
        $wiz_complete = ep_get_option('ep_setup_wizard_complete');
        $current_page = (string) ($_GET['page'] ?? '');

        if ($wiz_complete !== '1' && (empty($current_page) || $current_page !== 'ep-setup-wizard')) {
            if (is_admin() && !wp_doing_ajax() && current_user_can('manage_options')) {
                // Evitamos bucle si ya estamos en la página del plugin pero no en el wizard
                if (!empty($current_page) && strpos($current_page, 'ep-') === 0 && $current_page !== 'ep-setup-wizard') {
                    wp_redirect(admin_url('admin.php?page=ep-setup-wizard'));
                    exit;
                }
            }
        }
    }

    public function render_wizard_page()
    {
        ?>
        <style>
            .ep-wizard-body {
                background: #f1f5f9;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Inter', system-ui, sans-serif;
            }

            .ep-wizard-card {
                background: white;
                padding: 40px;
                border-radius: 16px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
                max-width: 600px;
                width: 100%;
                border: 1px solid #e2e8f0;
            }

            .ep-wizard-header {
                text-align: center;
                margin-bottom: 30px;
            }

            .ep-wizard-header img {
                max-height: 60px;
                margin-bottom: 20px;
            }

            .ep-wizard-step {
                display: none;
            }

            .ep-wizard-step.active {
                display: block;
                animation: fadeIn 0.4s ease-out;
            }

            .ep-btn-next {
                background: #3b82f6;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }

            .ep-btn-next:hover {
                background: #2563eb;
            }

            .ep-progress {
                height: 6px;
                background: #e2e8f0;
                border-radius: 3px;
                margin-bottom: 30px;
                overflow: hidden;
            }

            .ep-progress-bar {
                height: 100%;
                background: #3b82f6;
                transition: width 0.3s;
            }

            input[type="text"],
            input[type="password"],
            input[type="url"] {
                width: 100%;
                padding: 12px;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                margin-top: 5px;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>

        <div class="ep-wizard-body">
            <div class="ep-wizard-card">
                <div class="ep-wizard-header">
                    <img src="<?php echo EMPLOYEE_PORTAL_URL . 'assets/images/logo-placeholder.png'; ?>" alt="Portal Logo">
                    <h2 style="margin:0; color: #1e293b;">Bienvenido a tu Portal</h2>
                    <p id="wiz-subtitle" style="color: #64748b;">Vamos a configurar tu nuevo entorno de trabajo.</p>
                </div>

                <div class="ep-progress">
                    <div class="ep-progress-bar" id="ep-wiz-progress" style="width: 25%;"></div>
                </div>

                <!-- Paso 1: Identidad -->
                <div class="ep-wizard-step active" id="step-1">
                    <h3>1. Identidad del Portal</h3>
                    <p class="description">¿Cómo se llamará este portal? (Ej: Portal Empleado - Cámara Cáceres)</p>
                    <input type="text" id="wiz_site_title" placeholder="Título del Sitio"
                        value="<?php echo get_bloginfo('name'); ?>">
                    <div style="margin-top:25px; text-align:right;">
                        <button class="ep-btn-next" onclick="nextStep(2)">Siguiente Paso</button>
                    </div>
                </div>

                <!-- Paso 2: Conexión -->
                <div class="ep-wizard-step" id="step-2">
                    <h3>2. Conexión y Autenticación</h3>
                    <p class="description">Vincula este portal con la red maestra y configura el acceso con Office 365.</p>

                    <div style="margin-bottom:15px;">
                        <label>Llave de Cliente (Master Key)</label>
                        <input type="password" id="wiz_master_key" placeholder="Tu llave secreta"
                            value="<?php echo ep_get_option('ep_site_master_key'); ?>">
                        <input type="hidden" id="wiz_remote_url"
                            value="<?php echo ep_get_option('ep_auth_remote_url', 'https://maestro.portalempleado.com'); ?>">
                    </div>

                    <div style="border-top: 1px solid #eee; margin-top:20px; padding-top:20px;">
                        <h4>Configuración Office 365 (Opcional por ahora)</h4>
                        <div style="margin-bottom:10px;">
                            <label>Application (client) ID</label>
                            <input type="text" id="wiz_o365_client_id" placeholder="00000000-0000-0000-0000-000000000000"
                                value="<?php echo ep_get_option('ep_o365_client_id'); ?>">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label>Directory (tenant) ID</label>
                            <input type="text" id="wiz_o365_tenant_id" placeholder="00000000-0000-0000-0000-000000000000"
                                value="<?php echo ep_get_option('ep_o365_tenant_id'); ?>">
                        </div>
                        <div>
                            <label>Client Secret</label>
                            <input type="password" id="wiz_o365_client_secret" placeholder="••••••••••••••••"
                                value="<?php echo ep_get_option('ep_o365_client_secret'); ?>">
                        </div>
                    </div>

                    <div style="margin-top:25px; display:flex; justify-content: space-between;">
                        <button class="button" onclick="nextStep(1)">Atrás</button>
                        <button class="ep-btn-next" onclick="nextStep(3)">Siguiente Paso</button>
                    </div>
                </div>

                <!-- Paso 3: Optimización -->
                <div class="ep-wizard-step" id="step-3">
                    <h3>3. Optimización del Sistema</h3>
                    <p>El asistente realizará las siguientes acciones automáticamente:</p>
                    <ul class="ep-wiz-checklist" style="color: #475569; font-size: 0.9rem; list-style: none; padding: 0;">
                        <li id="check-permalinks" style="margin-bottom: 10px;">⏳ Ajustar Permalinks (SEO Friendly)</li>
                        <li id="check-apps" style="margin-bottom: 10px;">⏳ Sincronizar aplicaciones con el Maestro</li>
                        <li id="check-db" style="margin-bottom: 10px;">⏳ Configurar base de datos final</li>
                    </ul>
                    <div style="margin-top:25px; display:flex; justify-content: space-between;">
                        <button class="button" id="wiz_back_btn" onclick="nextStep(2)">Atrás</button>
                        <button class="ep-btn-next" id="wiz_finish_btn" onclick="finishWizard()">Finalizar
                            Configuración</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function nextStep(n) {
                jQuery('.ep-wizard-step').removeClass('active');
                jQuery('#step-' + n).addClass('active');
                jQuery('#ep-wiz-progress').css('width', (n * 33) + '%');
            }

            function finishWizard() {
                const btn = jQuery('#wiz_finish_btn');
                const backBtn = jQuery('#wiz_back_btn');
                btn.prop('disabled', true).text('Procesando...');
                backBtn.hide();

                const updateCheck = (id, status) => {
                    const icon = status === 'ok' ? '✅' : (status === 'fail' ? '❌' : '⏳');
                    jQuery('#' + id).html(icon + jQuery('#' + id).text().substring(1));
                };

                // Simulamos el progreso visual para dar feedback real mientras corre el AJAX
                setTimeout(() => updateCheck('check-permalinks', 'ok'), 800);
                setTimeout(() => updateCheck('check-apps', 'pending'), 1500);

                const data = {
                    action: 'ep_save_setup_wizard',
                    title: jQuery('#wiz_site_title').val(),
                    remote_url: jQuery('#wiz_remote_url').val(),
                    master_key: jQuery('#wiz_master_key').val(),
                    o365_client_id: jQuery('#wiz_o365_client_id').val(),
                    o365_tenant_id: jQuery('#wiz_o365_tenant_id').val(),
                    o365_client_secret: jQuery('#wiz_o365_client_secret').val(),
                    security: '<?php echo wp_create_nonce("ep_wizard_nonce"); ?>'
                };

                jQuery.post(ajaxurl, data, function (response) {
                    if (response.success) {
                        updateCheck('check-apps', 'ok');
                        updateCheck('check-db', 'ok');
                        jQuery('#wiz-subtitle').text('¡Configuración completada con éxito!');
                        setTimeout(() => {
                            window.location.href = response.data.redirect;
                        }, 1000);
                    } else {
                        updateCheck('check-apps', 'fail');
                        alert('Error: ' + response.data);
                        btn.prop('disabled', false).text('Reintentar');
                        backBtn.show();
                    }
                }).fail(function () { alert('Error crítico del servidor (500). Revisa los logs.'); btn.prop('disabled', false).text('Reintentar'); backBtn.show(); });
            }
        </script>
        <?php
    }

    public function ajax_save_wizard()
    {
        check_ajax_referer('ep_wizard_nonce', 'security');

        if (!current_user_can('manage_options'))
            wp_send_json_error('No tienes permisos.');

        update_option('blogname', sanitize_text_field($_POST['title']));
        update_option('ep_auth_remote_url', esc_url_raw($_POST['remote_url']));
        ep_update_secret_option('ep_site_master_key', sanitize_text_field($_POST['master_key']));

        if (!empty($_POST['o365_client_id']))
            update_option('ep_o365_client_id', sanitize_text_field($_POST['o365_client_id']));
        if (!empty($_POST['o365_tenant_id']))
            update_option('ep_o365_tenant_id', sanitize_text_field($_POST['o365_tenant_id']));
        if (!empty($_POST['o365_client_secret']))
            ep_update_secret_option('ep_o365_client_secret', sanitize_text_field($_POST['o365_client_secret']));

        // Forzar Permalinks a Post Name
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
        flush_rewrite_rules();

        // Asegurar que la página del portal existe
        require_once EMPLOYEE_PORTAL_PATH . 'includes/class-ep-activator.php';
        EP_Activator::create_portal_page();

        // Intentar validación inmediata para cachear apps
        $admin = new EP_Admin('employee-portal', '1.0.0');
        $admin->is_key_valid(sanitize_text_field($_POST['master_key']));

        update_option('ep_setup_wizard_complete', '1');

        // Obtener la URL de la página del portal para la redirección
        $portal_page = get_page_by_title('Portal del Empleado');
        $redirect_url = $portal_page ? get_permalink($portal_page->ID) : home_url();

        wp_send_json_success(['redirect' => $redirect_url]);
    }
}
