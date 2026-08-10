<?php
/**
 * EP_License — Validación criptográfica de licencias del Portal del Empleado.
 *
 * ┌─ MODO LEGACY (comportamiento actual por defecto) ────────────────────────┐
 * │  Si no hay secret configurado (ni en wp_options ni en wp-config.php),   │
 * │  delega en get_option("ep_authorized_apps"). Sin cambios funcionales.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ MODO SEGURO (activado desde el panel de administración) ────────────────┐
 * │  El administrador configura el "Secret de Licencia" directamente en      │
 * │  wp-admin → Portal → Red. Se almacena cifrado con AES-256 (EP_Security). │
 * │  Los tokens HMAC-SHA256 del maestro se verifican antes de cargar módulos.│
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @since 2.0.28
 */

defined('ABSPATH') || exit;

class EP_License
{
    /** Nombre de la opción donde se guarda el secret cifrado. */
    private const SECRET_OPTION = 'ep_license_secret_enc';

    /** Período de gracia tras expiración del token: 72 horas. */
    private const GRACE_PERIOD  = 259200;

    // ── API PÚBLICA ────────────────────────────────────────────────────────

    /**
     * Devuelve la lista de módulos autorizados para este sitio.
     *
     * Prioridad de fuentes:
     *  1. EP_IS_MASTER_PORTAL === true  → ['*'] (todos los módulos)
     *  2. Secret configurado + token HMAC válido → lista del token
     *  3. Cualquier fallo / sin secret             → legacy (ep_authorized_apps)
     *
     * @return string[]  IDs de apps, o ['*'] para el maestro.
     */
    public static function get_authorized_apps(): array
    {
        // Maestro: acceso total siempre
        if (defined('EP_IS_MASTER_PORTAL') && EP_IS_MASTER_PORTAL === true) {
            return ['*'];
        }

        // Sin secret configurado → modo legacy idéntico al comportamiento anterior
        $secret = self::get_secret();
        if (empty($secret)) {
            return self::get_legacy_apps();
        }

        // ── Validación con token HMAC ─────────────────────────────────────
        $token_b64  = (string) get_option('ep_license_token', '');
        $stored_sig = (string) get_option('ep_license_sig', '');

        if (empty($token_b64) || empty($stored_sig)) {
            return self::get_legacy_apps(); // Sin token aún, esperar primera sincronización
        }

        if (!self::verify_signature($token_b64, $stored_sig, $secret)) {
            ep_error_log('EP_License: FIRMA INVALIDA — posible manipulacion del token en wp_options.', true);
            return self::get_legacy_apps();
        }

        $decoded = base64_decode($token_b64, true);
        if ($decoded === false) {
            return self::get_legacy_apps();
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || !isset($payload['exp'], $payload['sub'], $payload['apps'])) {
            ep_error_log('EP_License: Payload del token malformado.', true);
            return self::get_legacy_apps();
        }

        // Verificar que el token es para ESTE sitio
        $current_site = untrailingslashit(home_url());
        if ($payload['sub'] !== $current_site) {
            ep_error_log("EP_License: SITE MISMATCH — token para [{$payload['sub']}], actual [$current_site].", true);
            return self::get_legacy_apps();
        }

        // Verificar caducidad con período de gracia
        $now = time();
        if ($now > (int) $payload['exp']) {
            $last_checked = (int) get_option('ep_license_checked', 0);
            if ($last_checked > 0 && ($now - $last_checked) < self::GRACE_PERIOD) {
                ep_error_log('EP_License: Token expirado — en periodo de gracia. Programando refresco.');
                self::schedule_refresh();
                return is_array($payload['apps']) ? $payload['apps'] : self::get_legacy_apps();
            }
            ep_error_log('EP_License: Token expirado sin periodo de gracia. Usando legacy.', true);
            return self::get_legacy_apps();
        }

        // Refresco preventivo si expira en menos de 12h
        if (((int) $payload['exp'] - $now) < 43200) {
            self::schedule_refresh();
        }

        return is_array($payload['apps']) ? $payload['apps'] : self::get_legacy_apps();
    }

    /**
     * Almacena un token HMAC recibido del maestro.
     * Verifica la firma antes de persistir.
     *
     * @param  array $license_data  ['token' => string, 'sig' => string]
     * @return bool
     */
    public static function store_token(array $license_data): bool
    {
        if (empty($license_data['token']) || empty($license_data['sig'])) {
            return false;
        }
        $secret = self::get_secret();
        if (empty($secret)) {
            ep_error_log('EP_License: store_token ignorado — secret no configurado.', true);
            return false;
        }
        if (!self::verify_signature((string) $license_data['token'], (string) $license_data['sig'], $secret)) {
            ep_error_log('EP_License: store_token rechazado — firma no valida.', true);
            return false;
        }
        update_option('ep_license_token',   sanitize_text_field($license_data['token']));
        update_option('ep_license_sig',     sanitize_text_field($license_data['sig']));
        update_option('ep_license_checked', time());
        ep_error_log('EP_License: Token HMAC almacenado correctamente.');
        return true;
    }

    /**
     * Guarda el secret cifrado en wp_options usando EP_Security (AES-256-CBC).
     * Llamar desde el handler AJAX del panel de administración.
     *
     * @param  string $plain_secret  El secret en texto plano introducido por el admin.
     * @return bool
     */
    public static function save_secret(string $plain_secret): bool
    {
        if (empty($plain_secret)) {
            return false;
        }
        if (!class_exists('EP_Security')) {
            require_once plugin_dir_path(__FILE__) . 'class-ep-security.php';
        }
        $encrypted = EP_Security::encrypt($plain_secret);
        if (empty($encrypted)) {
            return false;
        }
        update_option(self::SECRET_OPTION, $encrypted);
        ep_error_log('EP_License: Secret de licencia actualizado y cifrado.');
        return true;
    }

    /**
     * Indica si el secret de licencia está configurado (sin revelarlo).
     *
     * @return bool
     */
    public static function has_secret(): bool
    {
        return !empty(self::get_secret());
    }

    /**
     * Elimina el token almacenado (p.ej. al revocar una licencia).
     */
    public static function clear_token(): void
    {
        delete_option('ep_license_token');
        delete_option('ep_license_sig');
        delete_option('ep_license_checked');
    }

    // ── PRIVADOS ──────────────────────────────────────────────────────────

    /**
     * Obtiene el secret en texto plano.
     *
     * Prioridad:
     *  1. Constante EP_LICENSE_SHARED_SECRET en wp-config.php (override manual)
     *  2. Opción cifrada en wp_options (gestionable desde el panel de admin)
     *
     * @return string  El secret, o cadena vacía si no está configurado.
     */
    private static function get_secret(): string
    {
        // Prioridad 1: constante en wp-config.php (override de emergencia)
        if (defined('EP_LICENSE_SHARED_SECRET') && !empty(EP_LICENSE_SHARED_SECRET)) {
            return (string) EP_LICENSE_SHARED_SECRET;
        }

        // Prioridad 2: opción cifrada gestionada desde el panel de admin
        $encrypted = (string) get_option(self::SECRET_OPTION, '');
        if (empty($encrypted)) {
            return '';
        }

        if (!class_exists('EP_Security')) {
            require_once plugin_dir_path(__FILE__) . 'class-ep-security.php';
        }

        $decrypted = EP_Security::decrypt($encrypted);
        return is_string($decrypted) ? $decrypted : '';
    }

    /**
     * Modo legacy: lee directamente de wp_options, idéntico al comportamiento anterior.
     */
    private static function get_legacy_apps(): array
    {
        $apps = get_option('ep_authorized_apps', []);
        if (!is_array($apps)) {
            $apps = json_decode((string) $apps, true);
            if (!is_array($apps)) {
                $apps = [];
            }
        }
        return $apps;
    }

    /**
     * Verifica firma HMAC-SHA256 con hash_equals() (previene timing attacks).
     */
    private static function verify_signature(string $payload_b64, string $stored_sig, string $secret): bool
    {
        if (empty($secret)) {
            return false;
        }
        $expected = hash_hmac('sha256', $payload_b64, $secret);
        return hash_equals($expected, $stored_sig);
    }

    /**
     * Programa un refresco de licencia en background (WP-Cron).
     */
    private static function schedule_refresh(): void
    {
        if (!wp_next_scheduled('ep_license_refresh')) {
            wp_schedule_single_event(time() + 60, 'ep_license_refresh');
        }
    }
}
