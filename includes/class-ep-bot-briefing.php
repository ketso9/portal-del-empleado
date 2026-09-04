<?php
defined('ABSPATH') || exit;

/**
 * EP_Bot_Briefing
 *
 * Briefing matinal proactivo por Teams: a las 8:00 (hora de Madrid), de lunes a
 * viernes, envía a cada empleado que lo tenga activado en Ajustes la misma
 * tarjeta que recibe al saludar al bot (firmas, agenda de hoy, tareas, tickets).
 *
 * No molesta a quien no está: se salta al usuario si tiene activas las
 * respuestas automáticas de Outlook (EP_OOF_Sync) o si en su calendario de hoy
 * hay un evento de día completo que parezca vacaciones, baja o permiso.
 *
 * El cron corre cada 15 minutos y comprueba la hora, en vez de programarse a
 * una hora fija: así no se descoloca con los cambios de horario de verano y,
 * si el cron de WordPress no se disparó exactamente a las 8:00, el envío sale
 * en la siguiente pasada dentro de la ventana (hasta las 11:00).
 *
 * Requisito: el usuario tiene que haber escrito al bot al menos una vez, para
 * que exista la referencia de conversación (ep_bot_conversation_id).
 */
class EP_Bot_Briefing
{
    const CRON_HOOK     = 'ep_bot_briefing_check';
    const SCHEDULE_NAME = 'ep_bot_briefing_quarter';
    const META_OPT_IN   = 'ep_bot_briefing';
    const OPTION_LAST   = 'ep_bot_briefing_last_date';
    const HORA_INICIO   = 8;
    const HORA_LIMITE   = 11;
    const TZ            = 'Europe/Madrid';

    /** Palabras que, en un evento de día completo de hoy, indican que el usuario no trabaja. */
    const PALABRAS_AUSENCIA = [
        'vacaciones', 'vacacion', 'vacation', 'holiday', 'holidays',
        'ausencia', 'ausente', 'baja', 'permiso', 'libre', 'festivo',
        'out of office', 'ooo', 'fuera de la oficina',
    ];

    public function __construct()
    {
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);
        add_action('init', [$this, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [$this, 'comprobar_y_enviar']);
    }

    public function add_cron_schedule($schedules)
    {
        if (!isset($schedules[self::SCHEDULE_NAME])) {
            $schedules[self::SCHEDULE_NAME] = [
                'interval' => 900,
                'display'  => 'Cada 15 minutos (Briefing del bot de Teams)',
            ];
        }
        return $schedules;
    }

    public function maybe_schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, self::SCHEDULE_NAME, self::CRON_HOOK);
        }
    }

    public static function ahora(): DateTime
    {
        return new DateTime('now', new DateTimeZone(self::TZ));
    }

    /**
     * Pasada del cron: decide si toca enviar hoy y, si toca, envía.
     */
    public function comprobar_y_enviar(): void
    {
        if (function_exists('ep_is_staging') && ep_is_staging()) return;
        if (function_exists('ep_teams_channel_enabled') && !ep_teams_channel_enabled()) return;

        $ahora = self::ahora();
        $hoy   = $ahora->format('Y-m-d');
        $hora  = (int)$ahora->format('G');

        if ((int)$ahora->format('N') >= 6) return; // sábado y domingo
        if ($hora < self::HORA_INICIO || $hora >= self::HORA_LIMITE) return;
        if (get_option(self::OPTION_LAST) === $hoy) return;

        // Se marca ANTES de enviar: si dos pasadas del cron coinciden, solo una envía.
        update_option(self::OPTION_LAST, $hoy, false);

        $this->enviar_a_todos($hoy);
    }

    /**
     * Envía el briefing a todos los usuarios con el interruptor activado.
     * Devuelve un resumen con contadores (también se escribe en el log).
     */
    public function enviar_a_todos(string $hoy): array
    {
        $stats = ['enviados' => 0, 'ausentes' => 0, 'sin_conversacion' => 0, 'errores' => 0];

        $bot = class_exists('EP_Bot_Mensajeria') ? EP_Bot_Mensajeria::instance() : null;
        if (!$bot) {
            ep_error_log("EP Briefing {$hoy}: el bot de mensajería no está cargado, no se envía nada.", true);
            return $stats;
        }

        $usuarios = get_users([
            'meta_key'   => self::META_OPT_IN,
            'meta_value' => '1',
            'fields'     => ['ID', 'display_name'],
        ]);

        foreach ($usuarios as $u) {
            $user_id = (int)$u->ID;

            $motivo = self::motivo_ausencia($user_id);
            if ($motivo !== '') {
                $stats['ausentes']++;
                ep_error_log("EP Briefing: omitido {$u->display_name} ({$user_id}): {$motivo}.");
                continue;
            }

            $tarjeta = $bot->tarjeta_briefing($user_id);
            if (!$tarjeta) {
                $stats['errores']++;
                continue;
            }

            if ($bot->enviar_tarjeta_a_usuario($user_id, $tarjeta)) {
                $stats['enviados']++;
            } else {
                $stats['sin_conversacion']++;
                ep_error_log("EP Briefing: no enviado a {$u->display_name} ({$user_id}): sin conversación guardada o error de envío.");
            }
        }

        ep_error_log("EP Briefing {$hoy}: " . wp_json_encode($stats), true);
        return $stats;
    }

    /**
     * Motivo por el que NO hay que molestar hoy al usuario, o cadena vacía si
     * está trabajando. Mira las respuestas automáticas de Outlook y los eventos
     * de día completo de hoy en su calendario.
     */
    public static function motivo_ausencia(int $user_id): string
    {
        if (class_exists('EP_OOF_Sync')) {
            $oof = EP_OOF_Sync::get_user_oof_data($user_id);
            if (!empty($oof['is_oof'])) {
                return 'respuestas automáticas de Outlook activas';
            }
        }

        if (class_exists('EP_Graph_Service')) {
            $eventos = EP_Graph_Service::get_instance()->get_today_events($user_id, true);
            if (!is_wp_error($eventos)) {
                $patron = '/\b(' . implode('|', array_map('preg_quote', self::PALABRAS_AUSENCIA)) . ')\b/u';
                foreach ($eventos as $e) {
                    if (empty($e['isAllDay'])) continue;
                    $asunto = mb_strtolower((string)($e['subject'] ?? ''));
                    $asunto = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $asunto);
                    if (preg_match($patron, $asunto)) {
                        return "evento de día completo '" . (string)($e['subject'] ?? '') . "' en su calendario";
                    }
                }
            }
        }

        return '';
    }
}
