<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase Frontend para integrar la APP en el Grid del Portal.
 */
class EP_App_Censo implements EP_App_Interface
{
    private $manager;

    public function __construct()
    {
        // Integración con IA Bot
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_censo', array($this, 'responder_intent_bot'), 10, 5);
    }

    public function get_id()
    {
        return 'censo';
    }

    public function get_name()
    {
        return 'Censo IAE';
    }

    public function get_icon()
    {
        // Icono FontAwesome (Edificio o Base de datos)
        return 'fa-solid fa-building-columns';
    }

    public function get_menu_label()
    {
        return 'Censo IAE';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=censo'">
            <div class="app-icon-container color-red"> <!-- Usamos rojo corporativo -->
                <i class="fa-solid fa-list-ol"></i>
            </div>
            <h3>Censo IAE</h3>
            <p>Consulta y gestión del censo fiscal</p>
        </div>
        <?php
    }

    /**
     * Renderiza la vista completa cuando entras a la App.
     */
    public function render_full_view()
    {
        // Reutilizamos el shortcode existente o llamamos al método del manager.
        // Como no tenemos una instancia global fácil de CensoManager, usamos el shortcode que ya registra.
        echo do_shortcode('[portal_censo_manager]');
    }

    public function handle_ajax()
    {
        // La lógica AJAX ya la maneja CensoManager en sus propios hooks
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intent_bot($intents)
    {
        $intents['CENSO'] = "El usuario pregunta por empresas del censo corporativo, datos fiscales o número de empresas en general. Ej: 'busca la empresa X', 'cuántas empresas hay'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $params = $intent_data['params'] ?? [];
        return $this->tarjeta_censo($params, $bot_instance);
    }

    private function tarjeta_censo(array $params, $bot_instance): array
    {
        if (!class_exists('CensoDB')) {
            return $bot_instance->tarjeta_simple('🏢 Censo', "La App del Censo no está instalada o activa.", '');
        }

        $query = trim($params['search_term'] ?? '');
        $mode  = strtoupper($params['mode'] ?? 'INFO');

        if (empty($query)) {
            return $bot_instance->tarjeta_simple('🏢 Censo', "Dime el nombre o NIF de la empresa que buscas.", '');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'censo_iae';

        // MODO CONTEO: "¿Cuántas empresas hay en...?"
        if ($mode === 'COUNT') {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE RAZON LIKE %s OR MUNICIPIOFISC LIKE %s OR EPIGRAFE LIKE %s",
                '%' . $query . '%', '%' . $query . '%', '%' . $query . '%'
            ));
            
            return $bot_instance->tarjeta_simple('📊 Resultados Censo', "He encontrado **{$count}** empresas relacionadas con '{$query}' en el censo cameral.", home_url('/?view=censo&term=' . urlencode($query)));
        }

        // MODO INFO: "Dime los datos de..."
        $db = new CensoDB();
        $empresa = null;

        // 1. Buscar por NIF exacto
        if (preg_match('/^[A-Z0-9]{8,15}$/i', $query)) {
            $empresa = $db->get_by_nif(strtoupper($query));
        }
        
        // 2. Buscar por Razón exacto
        if (!$empresa) {
             $empresa = $db->get_by_razon(strtoupper($query));
        }

        // 3. Búsqueda parcial (LIKE)
        if (!$empresa) {
             $empresa = $wpdb->get_row($wpdb->prepare(
                 "SELECT * FROM $table WHERE RAZON LIKE %s OR NIF = %s LIMIT 1", 
                 '%' . $query . '%', $query
             ), ARRAY_A);
        }

        if (!$empresa) {
            return $bot_instance->tarjeta_simple('🏢 Censo', "No he encontrado ninguna empresa que coincida con '{$query}' en el censo.", '');
        }

        // Preparar campos de contacto (preferir enriquecidos)
        $tel = !empty($empresa['TELEFONO_ENRICH']) ? $empresa['TELEFONO_ENRICH'] : ($empresa['TELEFONO'] ?: 'N/A');
        $email = $empresa['EMAIL_ENRICH'] ?? '';
        $web = $empresa['WEB_ENRICH'] ?? '';

        $facts = [
            ['title' => '🆔 NIF', 'value' => $empresa['NIF']],
            ['title' => '📍 Municipio', 'value' => $empresa['MUNICIPIOFISC'] ?? 'N/A'],
            ['title' => '📊 Epígrafe', 'value' => $empresa['EPIGRAFE'] . ' - ' . mb_substr($empresa['DESCRIPCION_EPIGRAFE'] ?? '', 0, 40) . '...'],
            ['title' => '📞 Teléfono', 'value' => (string)$tel]
        ];

        if (!empty($email)) $facts[] = ['title' => '📧 Email', 'value' => $email];
        if (!empty($web))   $facts[] = ['title' => '🌐 Web', 'value' => $web];

        $card = $bot_instance->adaptive_card([
            ['type' => 'TextBlock', 'text' => "🏢 Datos de Empresa", 'weight' => 'Bolder', 'size' => 'Large', 'color' => 'Accent'],
            ['type' => 'TextBlock', 'text' => "**{$empresa['RAZON']}**", 'wrap' => true, 'size' => 'Medium', 'weight' => 'Bolder'],
            ['type' => 'FactSet', 'facts' => $facts]
        ], [
            ['type' => 'Action.OpenUrl', 'title' => '🔍 Ver en el Censo', 'url' => home_url('/?view=censo&term=' . urlencode($empresa['NIF']))]
        ]);

        $card['_meta_data'] = [
            'RAZON' => $empresa['RAZON'],
            'NIF'   => $empresa['NIF']
        ];

        return $card;
    }
}
