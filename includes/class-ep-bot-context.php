<?php

defined('ABSPATH') || exit;

/**
 * EP_Bot_Context
 * 
 * Gestiona la memoria de corto plazo (10-15 min) para cada usuario en Teams.
 * Permite que la IA sepa de qué estábamos hablando (Contextual Awareness).
 */
class EP_Bot_Context
{
    private static $expiry = 600; // 10 minutos por defecto

    /**
     * Guarda el contexto actual de un usuario.
     */
    public static function set_context(string $user_id, array $data): void
    {
        if (empty($user_id)) return;
        
        $key = 'ep_bot_ctx_' . md5($user_id);
        
        // Limpiamos datos sensibles si los hubiera antes de persistir
        $safe_data = [
            'last_intent' => $data['intent'] ?? '',
            'last_params' => $data['params'] ?? [],
            'last_results' => $data['results'] ?? [], // Ej: IDs de empresas o personas buscadas
            'timestamp' => time()
        ];

        set_transient($key, $safe_data, self::$expiry);
    }

    /**
     * Recupera el contexto de un usuario.
     */
    public static function get_context(string $user_id): array
    {
        if (empty($user_id)) return [];
        
        $key = 'ep_bot_ctx_' . md5($user_id);
        $data = get_transient($key);
        
        return is_array($data) ? $data : [];
    }

    /**
     * Genera un resumen del contexto para inyectar en el prompt de la IA.
     */
    public static function get_prompt_context(string $user_id): string
    {
        $ctx = self::get_context($user_id);
        if (empty($ctx)) return "No hay contexto previo de conversación reciente.";

        $summary = "Historial reciente (hace menos de 10 min):\n";
        $summary .= "- Última acción: " . ($ctx['last_intent'] ?? 'Ninguna') . "\n";
        
        if (!empty($ctx['last_params']['search_term'])) {
            $summary .= "- Seguíamos hablando de: \"" . $ctx['last_params']['search_term'] . "\"\n";
        }

        if (!empty($ctx['last_results'])) {
            // Si el resultado fue una empresa o persona, lo mencionamos
            if (isset($ctx['last_results']['RAZON'])) {
                $summary .= "- Empresa mencionada: " . $ctx['last_results']['RAZON'] . " (NIF: " . $ctx['last_results']['NIF'] . ")\n";
            }
            if (isset($ctx['last_results']['display_name'])) {
                $summary .= "- Persona mencionada: " . $ctx['last_results']['display_name'] . " (" . ($ctx['last_results']['job_title'] ?? '') . ")\n";
            }
        }

        return $summary;
    }

    /**
     * Limpia el contexto (ej: si el usuario dice "olvida esto" o similar).
     */
    public static function clear_context(string $user_id): void
    {
        if (empty($user_id)) return;
        $key = 'ep_bot_ctx_' . md5($user_id);
        delete_transient($key);
    }
}
