<?php
/**
 * Module Name: Notificaciones y Asistente IA en Teams
 * Description: Habilita el canal de Microsoft Teams del portal: notificaciones proactivas del bot y consultas en lenguaje natural con IA. Sin este módulo, los avisos siguen llegando al portal y al correo.
 * Version: 1.0.0
 * Author: Jorge Polo - Cámara de Comercio de Cáceres
 * Package: pro_max
 *
 * ── POR QUÉ ESTE MÓDULO NO TIENE PANTALLA ─────────────────────────────────
 * El resto de mini-apps son pantallas con icono en el escritorio. Esta no:
 * el bot es un canal de salida (y de entrada) hacia Teams, no una vista del
 * portal. Existe como módulo únicamente para que la licencia pueda decidir
 * si un cliente lo tiene o no, igual que decide sobre el Censo IAE.
 *
 * Por eso NO se registra en ep_register_apps: el usuario no verá ningún icono
 * nuevo. El cargador (ep_load_local_modules) ya no incluye este archivo si la
 * licencia del cliente no lo autoriza, y entonces:
 *
 *   - EP_Teams_Bot::send_proactive_message() no envía nada.
 *   - EP_Bot_Mensajeria no se instancia, así que el bot no responde ni
 *     consulta a la IA.
 *   - Todo lo demás sigue igual: los avisos aparecen en el portal y salen por
 *     correo, y el directorio conserva la disponibilidad leída de Outlook,
 *     que va por otra vía (EP_Teams_Webhook) y se vende en el plan PRO.
 *
 * La configuración del bot (credenciales, modelo de IA, límites) no cambia de
 * sitio: sigue en wp-admin → Portal → IA & Bot.
 */

defined('ABSPATH') || exit;

if (defined('EP_TEAMS_CHANNEL_LICENSED')) {
    return;
}

/**
 * Señal para ep_teams_channel_enabled(). Su sola presencia significa que la
 * licencia autoriza el canal de Teams, porque el cargador solo llega hasta
 * aquí en los portales que lo tienen contratado.
 */
define('EP_TEAMS_CHANNEL_LICENSED', true);
