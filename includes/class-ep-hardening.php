<?php
defined('ABSPATH') || exit;

/**
 * Endurecimiento transversal del portal.
 *
 * 1. Los documentos subidos (nóminas, contratos, justificantes, inventario...)
 *    quedan detrás de una comprobación de sesión. Las URLs públicas de la
 *    mediateca de WordPress permitían descargarlos sin autenticar y listarlos
 *    por la API REST.
 * 2. Se cierra la enumeración de usuarios y de ficheros por la API REST para
 *    visitantes anónimos.
 */
class EP_Hardening
{
    /** Subir la versión reescribe el .htaccess de subidas. */
    const RULES_VERSION = '1.1.1';

    /** Extensiones que nunca deben servirse sin sesión iniciada. */
    const PROTECTED_EXTS = 'pdf|docx?|xlsx?|pptx?|odt|ods|odp|csv|txt|rtf|zip|rar|7z|eml|msg';

    public function __construct()
    {
        // Entrega de documentos protegidos (ver reglas de wp-content/uploads)
        add_action('init', array($this, 'maybe_serve_protected_upload'), 0);

        add_action('init', array($this, 'maybe_write_uploads_rules'));

        // API REST: no exponer usuarios ni ficheros a quien no ha iniciado sesión.
        add_filter('rest_authentication_errors', array($this, 'restrict_rest_endpoints'), 20);

        add_filter('rest_prepare_user', array($this, 'strip_user_fields'), 10, 3);
    }

    /**
     * Punto de entrada al que Apache redirige los documentos protegidos.
     *
     * Se usa index.php de WordPress y no un script propio dentro del plugin
     * porque la carpeta del plugin contiene espacios ("portal del empleado") y
     * mod_rewrite no resuelve bien una sustitución con espacios, ni codificados
     * como %20 ni entrecomillados.
     */
    private static function guard_url_path()
    {
        $path = parse_url(home_url('/'), PHP_URL_PATH);
        if (!$path) {
            $path = '/';
        }
        return trailingslashit($path) . 'index.php';
    }

    /**
     * Escribe (una sola vez por versión) las reglas de wp-content/uploads.
     */
    public function maybe_write_uploads_rules()
    {
        if (get_option('ep_uploads_rules_version') === self::RULES_VERSION) {
            return;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return;
        }

        $basedir = trailingslashit($upload_dir['basedir']);
        if (!is_dir($basedir) || !is_writable($basedir)) {
            return;
        }

        $guard = self::guard_url_path();
        $exts  = self::PROTECTED_EXTS;

        $lines = array(
            '# Employee Portal - generado automáticamente. No editar a mano.',
            '# Los documentos del portal solo se sirven a usuarios con sesión iniciada.',
            'Options -Indexes',
            '',
            '# Nunca ejecutar código en el directorio de subidas',
            '<FilesMatch "\.(php|phtml|phps|php[0-9]|phar|cgi|pl|py|sh)$">',
            '    <IfModule mod_authz_core.c>',
            '        Require all denied',
            '    </IfModule>',
            '    <IfModule !mod_authz_core.c>',
            '        Order deny,allow',
            '        Deny from all',
            '    </IfModule>',
            '</FilesMatch>',
            '',
            '<IfModule mod_rewrite.c>',
            '    RewriteEngine On',
            '    # Evitar bucle si la petición ya viene del guardián.',
            '    # Se usa redirección explícita porque la reescritura interna pierde',
            '    # la cadena de consulta en este alojamiento.',
            '    RewriteCond %{QUERY_STRING} !(^|&)ep_serve_file=',
            '    # ep-expenses tiene su propio proxy, que además comprueba la propiedad',
            '    # del justificante: ahí no basta con tener la sesión iniciada.',
            '    RewriteCond %{REQUEST_URI} !/ep-expenses/ [NC]',
            '    RewriteRule ^(.+\.(' . $exts . '))$ ' . $guard . '?ep_serve_file=$1 [R=302,L,NC,QSA]',
            '</IfModule>',
            '',
        );

        $htaccess = $basedir . '.htaccess';

        // Conservar copia de lo que hubiera antes, por si el hosting tenía reglas propias.
        if (file_exists($htaccess) && !file_exists($basedir . '.htaccess.ep-backup')) {
            @copy($htaccess, $basedir . '.htaccess.ep-backup');
        }

        if (@file_put_contents($htaccess, implode("\n", $lines)) !== false) {
            update_option('ep_uploads_rules_version', self::RULES_VERSION);
        }
    }

    /**
     * Entrega un documento de wp-content/uploads exigiendo sesión iniciada.
     */
    public function maybe_serve_protected_upload()
    {
        if (!isset($_GET['ep_serve_file'])) {
            return;
        }

        $requested = (string) wp_unslash($_GET['ep_serve_file']);
        $requested = str_replace(chr(92), '/', $requested);

        $invalido = ($requested === '')
            || (strpos($requested, chr(0)) !== false)
            || ($requested[0] === '/')
            || (strpos($requested, '..') !== false)
            || preg_match('#^[a-zA-Z]:#', $requested);

        if ($invalido) {
            status_header(400);
            exit('Petición no válida.');
        }

        if (!is_user_logged_in()) {
            status_header(403);
            nocache_headers();
            exit('Acceso denegado. Debes iniciar sesión en el portal para consultar este documento.');
        }

        $upload_dir = wp_upload_dir();
        $basedir    = realpath($upload_dir['basedir']);
        $filepath   = realpath($upload_dir['basedir'] . '/' . $requested);

        if (!$basedir || !$filepath || strpos($filepath, $basedir) !== 0 || !is_file($filepath)) {
            status_header(404);
            exit('Documento no encontrado.');
        }

        $filetype = wp_check_filetype(basename($filepath));
        if (empty($filetype['type'])) {
            status_header(403);
            exit('Tipo de documento no permitido.');
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: ' . $filetype['type']);
        header('Content-Length: ' . filesize($filepath));
        header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
        header('X-Content-Type-Options: nosniff');

        readfile($filepath);
        exit;
    }

    /**
     * Bloquea los endpoints REST que permiten enumerar usuarios y documentos
     * sin autenticación. El resto de la API sigue funcionando igual.
     */
    public function restrict_rest_endpoints($result)
    {
        // Respetar un error de autenticación previo.
        if (!empty($result)) {
            return $result;
        }

        if (is_user_logged_in()) {
            return $result;
        }

        $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
        if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
            $route = (string) $_SERVER['REQUEST_URI'];
        }

        $bloqueados = array('/wp/v2/users', '/wp/v2/media');
        foreach ($bloqueados as $prefijo) {
            if (strpos($route, $prefijo) !== false) {
                return new WP_Error(
                    'ep_rest_forbidden',
                    'Necesitas iniciar sesión para consultar este recurso.',
                    array('status' => 401)
                );
            }
        }

        return $result;
    }

    /**
     * No exponer el identificador de acceso (slug) a quien no gestiona usuarios:
     * revelarlo facilita los ataques de credenciales.
     */
    public function strip_user_fields($response, $user, $request)
    {
        if (current_user_can('list_users')) {
            return $response;
        }
        if (isset($response->data['slug'])) {
            unset($response->data['slug']);
        }
        return $response;
    }
}
