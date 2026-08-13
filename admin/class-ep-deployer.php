<?php

defined('ABSPATH') || exit;

/**
 * EP_Deployer - Clase encargada de la limpieza y exportación del portal.
 */
class EP_Deployer
{
    /** Subcarpeta de uploads donde se guardan los Blueprint (blindada por .htaccess). */
    private const BLUEPRINT_DIR = 'ep-blueprints';

    /** Cuántos Blueprint se conservan en disco antes de ir borrando los antiguos. */
    private const BLUEPRINT_KEEP = 3;

    /**
     * Carpetas que NUNCA viajan en el paquete de despliegue.
     *
     * Motivo: entorno de desarrollo, documentos de clientes o material de pruebas.
     * 'plugins' se trata aparte porque sí se empaqueta, pero recorriéndolo app por app.
     */
    private const EXCLUDED_DIRS = [
        '.git',
        '.github',
        '.vscode',
        '.agent',
        '.idea',
        'node_modules',
        'vendor',
        'scratch',
        'load-tests',
        'public/fds-documents',
        'public/uploads',
    ];

    /**
     * Nombres exactos de archivo que nunca se empaquetan.
     *
     * Los scripts de despliegue contienen usuario SSH, IP, puerto y la ruta de la
     * clave privada: no pueden acabar en un ZIP que se entrega a un cliente.
     */
    private const EXCLUDED_FILES = [
        '.DS_Store',
        '.gitignore',
        '.gitattributes',
        '.env',
        'ep_debug.log',
    ];

    /**
     * Patrones glob (sobre el nombre del archivo) que nunca se empaquetan.
     */
    private const EXCLUDED_PATTERNS = [
        '*.ps1',
        '*.bat',
        '*.cmd',
        '*.sh',
        '*.tar.gz',
        '*.tgz',
        '*.zip',
        '*.log',
        '*.sql',
        '*.bak',
        '*.pem',
        '*.key',
        'id_rsa*',
    ];

    /**
     * Verifica que el entorno actual permite operaciones destructivas.
     *
     * Requisitos SIMULTÁNEOS para devolver true:
     *  1. Constante EP_ALLOW_NUCLEAR_RESET === true (debe definirse en wp-config.php SOLO en staging/dev)
     *  2. WP_DEBUG === true (entorno de desarrollo)
     *
     * En producción NINGUNA de estas constantes debe estar definida.
     */
    private static function is_destructive_env_allowed(): bool
    {
        if (!defined('EP_ALLOW_NUCLEAR_RESET') || EP_ALLOW_NUCLEAR_RESET !== true) {
            return false;
        }
        if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
            return false;
        }
        return true;
    }

    /**
     * Limpia datos sensibles (Nuclear Reset).
     *
     * BLOQUEADO en producción. Solo se ejecuta si:
     *  - EP_ALLOW_NUCLEAR_RESET=true y WP_DEBUG=true están definidos en wp-config.php
     *  - El usuario actual tiene el capability 'manage_options'
     *
     * @return bool  true si se completó, false si fue bloqueado.
     */
    public static function nuclear_reset(): bool
    {
        // GUARDIA 1: Entorno
        if (!self::is_destructive_env_allowed()) {
            error_log('EP_Deployer: nuclear_reset DENEGADO — entorno no permitido (producción o constante ausente).');
            return false;
        }

        // GUARDIA 2: Rol de WordPress (independiente de la master key)
        if (!current_user_can('manage_options')) {
            error_log('EP_Deployer: nuclear_reset DENEGADO — usuario sin capability manage_options.');
            return false;
        }

        // Log de auditoría antes de ejecutar
        $user = wp_get_current_user();
        error_log(sprintf(
            'EP_Deployer: NUCLEAR RESET iniciado por usuario ID=%d (%s) a las %s',
            $user->ID,
            $user->user_login,
            current_time('mysql')
        ));

        global $wpdb;

        $tables = [
            $wpdb->prefix . 'fds_documentos',
            $wpdb->prefix . 'ep_stats_events',
            $wpdb->prefix . 'ep_stats_sessions',
            $wpdb->prefix . 'ep_notifications',
        ];

        foreach ($tables as $table) {
            // Verificar que la tabla existe antes de truncar
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $table
            ));
            if ($exists) {
                $wpdb->query("TRUNCATE TABLE `{$table}`");
                error_log("EP_Deployer: TRUNCATE ejecutado en {$table}");
            }
        }

        // 2. Limpiar archivos físicos en uploads
        self::delete_directory_contents(wp_upload_dir()['basedir'] . '/fds-documents');
        self::delete_directory_contents(wp_upload_dir()['basedir'] . '/ep-downloads');

        return true;
    }

    /**
     * Ruta absoluta del directorio de Blueprints, creándolo y blindándolo si hace falta.
     *
     * El directorio se sirve SOLO a través de admin-ajax con comprobación de rol
     * (ver EP_Admin::ajax_download_blueprint), nunca por URL directa.
     *
     * @return string|false  Ruta con barra final, o false si no se pudo preparar.
     */
    public static function get_blueprint_dir()
    {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            error_log('EP_Deployer: wp_upload_dir devolvió error: ' . $upload_dir['error']);
            return false;
        }

        $dir = trailingslashit($upload_dir['basedir']) . self::BLUEPRINT_DIR . '/';

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            error_log("EP_Deployer: no se pudo crear el directorio de blueprints: {$dir}");
            return false;
        }

        if (!is_writable($dir)) {
            error_log("EP_Deployer: el directorio de blueprints no tiene permisos de escritura: {$dir}");
            return false;
        }

        // Blindaje: nada de este directorio se sirve por HTTP directo.
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "# Employee Portal - generado automáticamente. No editar a mano.\n"
                . "# Los paquetes de despliegue solo se entregan vía admin-ajax con rol verificado.\n"
                . "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order deny,allow\n"
                . "    Deny from all\n"
                . "</IfModule>\n";
            @file_put_contents($htaccess, $rules);
        }

        if (!file_exists($dir . 'index.html')) {
            @file_put_contents($dir . 'index.html', '');
        }

        return $dir;
    }

    /**
     * Decide si una ruta relativa al plugin debe quedar fuera del paquete.
     *
     * @param string $relative_path  Ruta con barras '/', relativa a la raíz del plugin.
     * @param bool   $is_dir
     */
    private static function is_excluded(string $relative_path, bool $is_dir): bool
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        if ($relative_path === '') {
            return true;
        }

        $name = basename($relative_path);

        // Cualquier cosa oculta (.git, .env, .htpasswd…) salvo el .htaccess de seguridad
        if ($name !== '' && $name[0] === '.' && $name !== '.htaccess') {
            return true;
        }

        if ($is_dir) {
            foreach (self::EXCLUDED_DIRS as $excluded) {
                if ($relative_path === $excluded || strpos($relative_path . '/', $excluded . '/') === 0) {
                    return true;
                }
            }
            return false;
        }

        // Archivo dentro de una carpeta excluida
        foreach (self::EXCLUDED_DIRS as $excluded) {
            if (strpos($relative_path, $excluded . '/') === 0) {
                return true;
            }
        }

        if (in_array($name, self::EXCLUDED_FILES, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (self::matches_pattern($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coincidencia glob simple. fnmatch() no está disponible en todas las
     * compilaciones de PHP (notablemente en Windows), así que se traduce a regex.
     */
    private static function matches_pattern(string $pattern, string $name): bool
    {
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $name);
        }

        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $name);
    }

    /**
     * Genera un paquete ZIP con el portal completo (Blueprint) listo para instalar
     * en un cliente.
     *
     * El contenido se autodescubre: el núcleo más TODAS las apps presentes en
     * plugins/. No hay lista que mantener; una app nueva entra sola en el paquete.
     * Se excluyen scripts de despliegue, logs, documentos y material de desarrollo.
     *
     * @param  array $selected_apps  Reservado. Vacío = todas las apps detectadas.
     * @return string|false  Ruta ABSOLUTA del ZIP generado, o false si falló.
     */
    public static function create_blueprint_zip($selected_apps = [])
    {
        if (!class_exists('ZipArchive')) {
            error_log('EP_Deployer: la extensión ZipArchive no está disponible en este servidor.');
            return false;
        }

        $target_dir = self::get_blueprint_dir();
        if ($target_dir === false) {
            return false;
        }

        // El paquete ronda los 30 MB y ~850 archivos: con el max_execution_time
        // por defecto (30 s) el ZIP se queda a medias y el navegador solo ve un
        // error genérico. Se amplía el margen y se termina aunque el usuario
        // cierre la pestaña, para no dejar archivos corruptos en disco.
        @set_time_limit(300);
        @ignore_user_abort(true);

        // Nombre impredecible: aunque el directorio esté blindado, no se adivina.
        $zip_filename = 'ep-blueprint-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false) . '.zip';
        $zip_path     = $target_dir . $zip_filename;

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error_log("EP_Deployer: no se pudo abrir el ZIP para escritura: {$zip_path}");
            return false;
        }

        $base_path = trailingslashit(EMPLOYEE_PORTAL_PATH);
        $added     = self::add_dir_to_zip($base_path, $zip, strlen($base_path));

        // Manifiesto: qué se ha empaquetado y con qué versión. Facilita el soporte.
        $zip->addFromString('ep-blueprint.json', wp_json_encode([
            'generated_at'   => current_time('mysql'),
            'portal_version' => defined('EMPLOYEE_PORTAL_VERSION') ? EMPLOYEE_PORTAL_VERSION : 'unknown',
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'source_site'    => untrailingslashit(home_url()),
            'apps'           => array_keys(function_exists('ep_discover_local_modules') ? ep_discover_local_modules() : []),
            'files'          => $added,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (!$zip->close()) {
            error_log('EP_Deployer: fallo al cerrar el ZIP.');
            return false;
        }

        self::prune_old_blueprints($target_dir);

        error_log("EP_Deployer: Blueprint generado ({$added} archivos): {$zip_filename}");

        return $zip_path;
    }

    /**
     * Borra los Blueprint antiguos y deja solo los más recientes.
     * Evita que uploads/ se llene de paquetes de decenas de MB.
     */
    private static function prune_old_blueprints(string $dir): void
    {
        $files = glob($dir . 'ep-blueprint-*.zip');
        if (!is_array($files) || count($files) <= self::BLUEPRINT_KEEP) {
            return;
        }

        // Más recientes primero
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        foreach (array_slice($files, self::BLUEPRINT_KEEP) as $old) {
            if (@unlink($old)) {
                error_log('EP_Deployer: Blueprint antiguo eliminado: ' . basename($old));
            }
        }
    }

    private static function delete_directory_contents(string $dir): void
    {
        // Protección anti-path-traversal: el directorio debe estar dentro de uploads
        $upload_basedir = wp_upload_dir()['basedir'];
        $real_dir       = realpath($dir);
        $real_base      = realpath($upload_basedir);

        if ($real_dir === false || $real_base === false || strpos($real_dir, $real_base) !== 0) {
            error_log("EP_Deployer: delete_directory_contents BLOQUEADO — path fuera de uploads: {$dir}");
            return;
        }

        if (!is_dir($real_dir)) {
            return;
        }

        $files = glob($real_dir . '/*');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Añade recursivamente un directorio al ZIP aplicando las reglas de exclusión.
     *
     * @return int  Número de archivos añadidos.
     */
    private static function add_dir_to_zip($dir, $zip, $exclusiveLength): int
    {
        $count  = 0;
        $handle = opendir($dir);
        if ($handle === false) {
            return 0;
        }

        while (false !== ($file = readdir($handle))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath  = rtrim($dir, '/\\') . '/' . $file;
            $localPath = str_replace('\\', '/', substr($filePath, $exclusiveLength));
            $is_dir    = is_dir($filePath);

            if (self::is_excluded($localPath, $is_dir)) {
                continue;
            }

            if ($is_dir) {
                $zip->addEmptyDir($localPath);
                $count += self::add_dir_to_zip($filePath, $zip, $exclusiveLength);
            } elseif (is_file($filePath) && is_readable($filePath)) {
                if ($zip->addFile($filePath, $localPath)) {
                    $count++;
                }
            }
        }

        closedir($handle);

        return $count;
    }
}
