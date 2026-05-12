<?php
/**
 * Plugin Name: Employee Portal - GDPR & Cookie Management
 * Description: Gestión dinámica de cookies y cumplimiento RGPD para el Portal del Empleado.
 * Version: 1.1
 * Author: Antigravity
 */

if (!defined('ABSPATH'))
    exit;

class EP_GDPR
{
    private $option_name = 'ep_gdpr_settings';

    public function __construct()
    {
        add_action('wp_footer', array($this, 'inject_banner'));
        add_action('login_footer', array($this, 'inject_banner'));
        add_action('wp_head', array($this, 'inject_scripts_blocker'), 1);
        add_action('login_head', array($this, 'inject_scripts_blocker'), 1);

        // Cargar estilos mínimos necesarios para que el banner se vea bien en wp-login.php
        add_action('login_enqueue_scripts', function () {
            $portal = $this->get_portal_data();
            echo "<style>
                #ep-cookie-banner { font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif !important; }
                .ep-ms-login-btn { z-index: 1 !important; }
            </style>";
        });

        add_action('wp_ajax_ep_gdpr_log_view', array($this, 'ajax_log_view'));
        add_action('wp_ajax_nopriv_ep_gdpr_log_view', array($this, 'ajax_log_view'));
    }

    private function get_settings()
    {
        $defaults = array(
            'policy_url' => '',
            'cookies_url' => '',
            'legal_notice_url' => '',
            'entity_name' => 'Cámara Oficial de Comercio, Industria y Servicios de Cáceres',
            'entity_cif' => 'Q1073001F',
            'entity_address' => 'Calle Santa Gertrudis, 1, 10003 Cáceres',
            'entity_registration' => 'Inscrita en el Registro de Cámaras de Comercio de la Comunidad Autónoma correspondiente.',
            'banner_text' => 'Utilizamos cookies técnicas y de funcionalidad necesarias para el uso del Portal del Empleado y para gestionar su conexión con Microsoft 365.',
            'show_on_login' => 1,
            'consent_version' => 1
        );
        $saved = get_option($this->option_name, array());
        return array_merge($defaults, is_array($saved) ? $saved : array());
    }

    private function get_portal_data()
    {
        $custom = get_option('ep_portal_customization', array());
        return array(
            'name' => $custom['portal_name'] ?? get_bloginfo('name'),
            'color' => $custom['primary_color'] ?? '#a81c24',
            'logo' => $custom['logo_url'] ?? ''
        );
    }

    private function get_default_legal_text($type)
    {
        $settings = $this->get_settings();
        $portal = $this->get_portal_data();
        $entity_name = !empty($settings['entity_name']) ? esc_html($settings['entity_name']) : esc_html($portal['name']);
        $cif = esc_html($settings['entity_cif'] ?? '');
        $address = esc_html($settings['entity_address'] ?? '');
        $registration = esc_html($settings['entity_registration'] ?? '');

        if ($type === 'privacy') {
            return "<h2>Política de Privacidad - $entity_name</h2>
            <p><strong>Responsable del Tratamiento:</strong> $entity_name.</p>
            <p><strong>CIF:</strong> $cif</p>
            <p><strong>Dirección:</strong> $address</p>
            <p><strong>Finalidad:</strong> Los datos personales procesados con la aplicación $entity_name tienen como única finalidad la gestión de la relación laboral, la comunicación interna y el acceso a herramientas de gestión de recursos humanos (nóminas, firma electrónica, tickets de mantenimiento).</p>
            <p><strong>Legitimación:</strong> El tratamiento es necesario para la ejecución del contrato de trabajo en el que el interesado es parte y para el cumplimiento de obligaciones legales del empleador.</p>
            <p><strong>Sincronización M365:</strong> Este portal se integra con Microsoft 365 mediante Microsoft Graph API para facilitar el Single Sign-On y la sincronización de datos de perfil laboral (nombre, cargo, departamento, email). Estos datos se tratan de forma segura y no se comparten con terceros fuera del entorno corporativo de Microsoft.</p>
            <p><strong>Derechos:</strong> Podrá ejercer sus derechos de acceso, rectificación, supresión y oposición contactando con el Departamento de RRHH o el Delegado de Protección de Datos de la entidad.</p>";
        } elseif ($type === 'legal') {
            return "<h2>Aviso Legal - $entity_name</h2>
            <p>En cumplimiento de la Ley 34/2002, de 11 de julio, de servicios de la sociedad de la información y de comercio electrónico (LSSI-CE), se informa de los siguientes datos:</p>
            <ul>
                <li><strong>Titular:</strong> $entity_name</li>
                <li><strong>CIF / NIF:</strong> $cif</li>
                <li><strong>Domicilio Social:</strong> $address</li>
                <li><strong>Datos Registrales:</strong> $registration</li>
            </ul>
            <p>El Portal del Empleado es una herramienta interna de uso exclusivo para el personal autorizado de $entity_name. Queda prohibido el acceso por parte de personas ajenas a la organización.</p>";
        } else {
            return "<h2>Política de Cookies - $entity_name</h2>
            <p>Este portal utiliza cookies técnicas y tecnologías de almacenamiento similares (LocalStorage) estrictamente necesarias para su funcionamiento y para gestionar la conexión con Microsoft 365.</p>
            <table style='width:100%; border-collapse: collapse; margin-top: 15px; font-size: 13px;'>
                <thead>
                    <tr style='background: #f8f9fa; border-bottom: 2px solid #eee;'>
                        <th style='padding: 10px; text-align: left;'>Nombre</th>
                        <th style='padding: 10px; text-align: left;'>Tipo</th>
                        <th style='padding: 10px; text-align: left;'>Finalidad</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'>ep_cookie_consent</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>LocalStorage</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>Almacena sus preferencias de privacidad.</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'>wordpress_logged_in_*</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>Cookie</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>Mantiene su sesión activa de forma segura.</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'>MSAccess / MSAuth</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>Terceros (Microsoft)</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>Cookies de autenticación y sesión de Microsoft 365 para la API Graph.</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'>ep_last_notif_*</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>LocalStorage</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>Optimiza la gestión de notificaciones.</td></tr>
                </tbody>
            </table>
            <p style='margin-top:15px;'>Las cookies técnicas son obligatorias para el uso del Portal y no requieren consentimiento explícito según la normativa de la AEPD.</p>";
        }
    }

    public function inject_banner()
    {
        $settings = $this->get_settings();

        // No mostrar en login si está desactivado por opción
        $is_login_page = did_action('login_head') || (isset($_GET['view']) && $_GET['view'] === 'login');
        if ($is_login_page && empty($settings['show_on_login'])) {
            return;
        }

        $portal = $this->get_portal_data();
        $policy_url = !empty($settings['policy_url']) ? $settings['policy_url'] : '#ep-legal-privacy';
        $cookies_url = !empty($settings['cookies_url']) ? $settings['cookies_url'] : '#ep-legal-cookies';
        $legal_notice_url = !empty($settings['legal_notice_url']) ? $settings['legal_notice_url'] : '#ep-legal-legal';

        ?>
        <div id="ep-cookie-banner"
            style="display:none; position: fixed; bottom: 30px; left: 30px; right: 30px; background: #fff; border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.18); z-index: 9999999; padding: 30px; border: 1px solid rgba(0,0,0,0.05); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <div style="max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px;">
                <div style="display: flex; gap: 20px; align-items: center;">
                    <?php if (!empty($portal['logo'])): ?>
                        <div style="flex-shrink: 0; max-width: 120px;">
                            <img src="<?php echo esc_url($portal['logo']); ?>" alt="<?php echo esc_attr($portal['name']); ?>"
                                style="max-width: 100%; height: auto; display: block;">
                        </div>
                    <?php else: ?>
                        <div
                            style="background: <?php echo esc_attr($portal['color']); ?>10; color: <?php echo esc_attr($portal['color']); ?>; padding: 15px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-10-5" />
                                <circle cx="8.5" cy="8.5" r=".5" fill="currentColor" />
                                <circle cx="16" cy="15.5" r=".5" fill="currentColor" />
                                <circle cx="12" cy="12" r=".5" fill="currentColor" />
                                <circle cx="11" cy="17" r=".5" fill="currentColor" />
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 5px 0; color: #1a1a1a; font-size: 18px; font-weight: 700;">Gestión de Privacidad
                        </h4>
                        <p style="margin: 0; color: #4a4a4a; font-size: 14px; line-height: 1.6;">
                            <?php echo esc_html($settings['banner_text']); ?>
                            <br>
                            Consulta nuestra
                            <a href="<?php echo esc_url($policy_url); ?>" class="ep-legal-link" data-type="privacy"
                                style="color: <?php echo esc_attr($portal['color']); ?>; text-decoration: none; font-weight: 600; border-bottom: 1px solid <?php echo esc_attr($portal['color']); ?>30;">Política
                                de Privacidad</a>,
                            <a href="<?php echo esc_url($cookies_url); ?>" class="ep-legal-link" data-type="cookies"
                                style="color: <?php echo esc_attr($portal['color']); ?>; text-decoration: none; font-weight: 600; border-bottom: 1px solid <?php echo esc_attr($portal['color']); ?>30;">Política
                                de Cookies</a> y
                            <a href="<?php echo esc_url($legal_notice_url); ?>" class="ep-legal-link" data-type="legal"
                                style="color: <?php echo esc_attr($portal['color']); ?>; text-decoration: none; font-weight: 600; border-bottom: 1px solid <?php echo esc_attr($portal['color']); ?>30;">Aviso
                                Legal</a>.
                        </p>
                    </div>
                </div>

                <div id="ep-cookie-settings" style="display:none; padding-top: 20px; border-top: 1px solid #f2f2f2;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                        <label
                            style="display: flex; align-items: center; gap: 12px; cursor: default; opacity: 0.6; background: #f8f9fa; padding: 12px; border-radius: 10px;"
                            title="Indispensables para el login y seguridad">
                            <input type="checkbox" checked disabled>
                            <span style="font-size: 14px; font-weight: 500; color: #333;">Técnicas (Necesarias)</span>
                        </label>
                        <label
                            style="display: flex; align-items: center; gap: 12px; cursor: pointer; background: #f8f9fa; padding: 12px; border-radius: 10px; transition: background 0.2s;">
                            <input type="checkbox" id="ep-cookie-stats" checked
                                style="accent-color: <?php echo esc_attr($portal['color']); ?>;">
                            <span style="font-size: 14px; font-weight: 500; color: #333;">Estadísticas / Análisis</span>
                        </label>
                        <label
                            style="display: flex; align-items: center; gap: 12px; cursor: pointer; background: #f8f9fa; padding: 12px; border-radius: 10px; transition: background 0.2s;">
                            <input type="checkbox" id="ep-cookie-usage" checked
                                style="accent-color: <?php echo esc_attr($portal['color']); ?>;">
                            <span style="font-size: 14px; font-weight: 500; color: #333;">Experiencia de Usuario</span>
                        </label>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap; align-items: center;">
                    <button id="ep-cookie-configure"
                        style="background: #fff; border: 1px solid #e0e0e0; padding: 12px 24px; border-radius: 10px; cursor: pointer; font-size: 14px; color: #555; font-weight: 600; transition: all 0.2s;">Preferencias</button>
                    <button id="ep-cookie-accept-all"
                        style="background: <?php echo esc_attr($portal['color']); ?>; border: none; padding: 12px 30px; border-radius: 10px; cursor: pointer; font-size: 14px; color: #fff; font-weight: 700; box-shadow: 0 4px 12px <?php echo esc_attr($portal['color']); ?>40; transition: all 0.2s;">Aceptar
                        Todo</button>
                    <button id="ep-cookie-save-settings"
                        style="display:none; background: #1a1a1a; border: none; padding: 12px 30px; border-radius: 10px; cursor: pointer; font-size: 14px; color: #fff; font-weight: 700; transition: all 0.2s;">Guardar
                        Configuración</button>
                </div>
            </div>
        </div>

        <div id="ep-legal-modal"
            style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 10000000; align-items: center; justify-content: center; padding: 30px;">
            <div
                style="background: #fff; border-radius: 20px; max-width: 850px; width: 100%; max-height: 85vh; overflow-y: auto; padding: 50px; position: relative; font-family: 'Inter', sans-serif; line-height: 1.7; box-shadow: 0 25px 80px rgba(0,0,0,0.25);">
                <button onclick="document.getElementById('ep-legal-modal').style.display='none'"
                    style="position: absolute; top: 25px; right: 25px; border: none; background: #f5f5f5; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; cursor: pointer; color: #666; display: flex; align-items: center; justify-content: center;">&times;</button>
                <div id="ep-legal-content" style="color: #333;"></div>
            </div>
        </div>

        <script>
            (function () {
                const banner = document.getElementById('ep-cookie-banner');
                if (!banner) {
                    console.error('EP GDPR: No se encontró el elemento del banner.');
                    return;
                }

                let consent = null;
                const currentVersion = <?php echo (int) ($settings['consent_version'] ?? 1); ?>;
                try {
                    const saved = localStorage.getItem('ep_cookie_consent');
                    if (saved) {
                        const parsed = JSON.parse(saved);
                        // Si la versión guardada no coincide con la actual, invalidamos
                        if (parsed.version === currentVersion) {
                            consent = parsed;
                        }
                    }
                } catch (e) {
                    console.warn('EP GDPR: localStorage no accesible:', e);
                }

                if (!consent) {
                    banner.style.display = 'block';
                }

                const legalTexts = {
                    privacy: <?php echo json_encode($this->get_default_legal_text('privacy')); ?>,
                    cookies: <?php echo json_encode($this->get_default_legal_text('cookies')); ?>,
                    legal: <?php echo json_encode($this->get_default_legal_text('legal')); ?>
                };

                document.querySelectorAll('.ep-legal-link').forEach(link => {
                    link.onclick = function (e) {
                        const href = this.getAttribute('href');
                        if (href.startsWith('#ep-legal')) {
                            e.preventDefault();
                            const type = this.getAttribute('data-type');
                            document.getElementById('ep-legal-content').innerHTML = legalTexts[type];
                            document.getElementById('ep-legal-modal').style.display = 'flex';
                            
                            // Log event
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'ep_gdpr_log_view',
                                    type: type
                                })
                            });
                        }
                    };
                });

                document.getElementById('ep-cookie-configure').onclick = function () {
                    document.getElementById('ep-cookie-settings').style.display = 'block';
                    document.getElementById('ep-cookie-save-settings').style.display = 'inline-block';
                    this.style.display = 'none';
                };

                const setConsent = (data) => {
                    try {
                        localStorage.setItem('ep_cookie_consent', JSON.stringify({
                            date: new Date().toISOString(),
                            version: <?php echo (int) ($settings['consent_version'] ?? 1); ?>,
                            choices: data
                        }));
                    } catch (e) {
                        console.error('EP GDPR: Error guardando consentimiento en localStorage:', e);
                    }
                    banner.style.display = 'none';
                    location.reload();
                };

                document.getElementById('ep-cookie-accept-all').onclick = function () {
                    setConsent({ stats: true, usage: true });
                };

                document.getElementById('ep-cookie-save-settings').onclick = function () {
                    setConsent({
                        stats: document.getElementById('ep-cookie-stats').checked,
                        usage: document.getElementById('ep-cookie-usage').checked
                    });
                };
            })();
        </script>
        <?php
    }

    public function inject_scripts_blocker()
    {
        ?>
        <script>
            (function () {
                let choices = { stats: false, usage: false };
                const currentVersion = <?php echo (int)($settings['consent_version'] ?? 1); ?>;
                try {
                    const saved = localStorage.getItem('ep_cookie_consent');
                    if (saved) {
                        const parsed = JSON.parse(saved);
                        if (parsed.version === currentVersion) {
                            choices = parsed.choices;
                        }
                    }
                } catch (e) {
                    console.warn('EP GDPR Blocker: localStorage error:', e);
                }
                window.epCookieConsent = { choices: choices };

                window.loadEpScript = function (category, callback) {
                    if (window.epCookieConsent.choices[category]) {
                        callback();
                    }
                };
            })();
        </script>
        <?php
    }
    public function ajax_log_view()
    {
        $type = sanitize_text_field($_POST['type'] ?? 'unknown');
        if (function_exists('ep_stats_log')) {
            ep_stats_log('gdpr', 'legal_view', get_current_user_id(), ['document' => $type]);
        }
        wp_send_json_success();
    }
}

new EP_GDPR();
