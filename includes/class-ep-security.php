<?php

defined('ABSPATH') || exit;

/**
 * EP_Security - Manejador de cifrado y seguridad avanzada.
 */
class EP_Security
{
    // Pepper fijo (Parte 1 de la llave) - Cambiar esto en cada instalación si es posible
    private static $pepper = 'ep_portal_shield_v1_2024';

    /**
     * Cifra un dato usando AES-256-CBC con llave dividida.
     */
    public static function encrypt($data)
    {
        if (empty($data)) return $data;

        $key = self::get_encryption_key();
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($iv_length);

        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);

        // Devolvemos IV + Dato cifrado (concatenado con un separador seguro)
        return base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Descifra un dato cifrado por este sistema.
     */
    public static function decrypt($data)
    {
        if (empty($data)) return $data;

        // Si no parece estar cifrado (no es base64 o no tiene el separador), lo devolvemos tal cual (compatibilidad)
        $decoded = base64_decode($data, true);
        if ($decoded === false || strpos($decoded, '::') === false) {
            return $data;
        }

        $parts = explode('::', $decoded, 2);
        if (count($parts) !== 2) return $data;

        $iv = $parts[0];
        $encrypted_data = $parts[1];
        $key = self::get_encryption_key();

        $decrypted = openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);

        return $decrypted === false ? $data : $decrypted;
    }

    /**
     * Construye la llave de cifrado de 32 bytes (256 bits) combinando 3 fuentes.
     */
    private static function get_encryption_key()
    {
        // 1. El Pepper (en el código)
        $pepper = self::$pepper;

        // 2. El Secret (en wp-config.php o fallback a LOGGED_IN_SALT)
        $secret = defined('EP_ENCRYPTION_SECRET') ? EP_ENCRYPTION_SECRET : (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : 'default_secret_fallback');

        // 3. El Salt único del sitio (en la DB)
        $db_salt = get_option('ep_security_db_salt');
        if (!$db_salt) {
            $db_salt = wp_generate_password(64, true, true);
            update_option('ep_security_db_salt', $db_salt);
        }

        // Combinamos y generamos un hash de 256 bits
        return hash('sha256', $pepper . $secret . $db_salt, true);
    }

    /**
     * Verifica si un string parece estar cifrado por nuestro sistema.
     */
    public static function is_encrypted($data)
    {
        if (empty($data)) return false;
        $decoded = base64_decode($data, true);
        return ($decoded !== false && strpos($decoded, '::') !== false);
    }
}
