<?php

defined('ABSPATH') || exit;

/**
 * EP_Deployer - Clase encargada de la limpieza y exportación del portal.
 */
class EP_Deployer
{
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
     * Genera un paquete ZIP con los plugins del portal (Blueprint)
     */
    public static function create_blueprint_zip($selected_apps = [])
    {
        $upload_dir = wp_upload_dir();

        if (!is_writable($upload_dir['basedir'])) {
            error_log("EP_Deployer: El directorio de uploads no tiene permisos de escritura.");
            return false;
        }

        $zip = new ZipArchive();
        $zip_filename = 'ep-blueprint-' . date('Ymd-His') . '.zip';
        $zip_path = $upload_dir['basedir'] . '/' . $zip_filename;

        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return false;
        }

        $base_path = EMPLOYEE_PORTAL_PATH;
        $base_len = strlen($base_path);

        // 1. Añadir el núcleo (archivos en el raíz y carpetas excepto 'plugins')
        $root_items = scandir($base_path);
        $exclude_root = ['.', '..', '.git', '.github', 'plugins', 'node_modules', 'vendor'];

        foreach ($root_items as $item) {
            if (in_array($item, $exclude_root))
                continue;

            $full_path = $base_path . $item;
            if (is_file($full_path)) {
                $zip->addFile($full_path, $item);
            } elseif (is_dir($full_path)) {
                $zip->addEmptyDir($item);
                self::add_dir_to_zip($full_path, $zip, $base_len);
            }
        }

        // 2. Añadir todas las apps dentro de 'plugins/'
        $plugins_path = $base_path . 'plugins/';
        if (is_dir($plugins_path)) {
            $zip->addEmptyDir('plugins');
            $plugin_items = scandir($plugins_path);
            foreach ($plugin_items as $app_id) {
                if ($app_id === '.' || $app_id === '..')
                    continue;

                $app_path = $plugins_path . $app_id;
                if (is_dir($app_path)) {
                    $zip->addEmptyDir('plugins/' . $app_id);
                    self::add_dir_to_zip($app_path, $zip, $base_len);
                }
            }
        }

        $zip->close();

        return $upload_dir['baseurl'] . '/' . $zip_filename;
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

    private static function add_dir_to_zip($dir, $zip, $exclusiveLength)
    {
        // Lista de carpetas/archivos a excluir
        $exclude = ['.git', '.github', '.DS_Store', 'node_modules', '.gitignore'];

        $handle = opendir($dir);
        while (false !== ($file = readdir($handle))) {
            if ($file != '.' && $file != '..' && !in_array($file, $exclude)) {
                $filePath = "$dir/$file";
                $localPath = str_replace('\\', '/', substr($filePath, $exclusiveLength));
                if (is_file($filePath)) {
                    $zip->addFile($filePath, $localPath);
                } elseif (is_dir($filePath)) {
                    $zip->addEmptyDir($localPath);
                    self::add_dir_to_zip($filePath, $zip, $exclusiveLength);
                }
            }
        }
        closedir($handle);
    }
}
