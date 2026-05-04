<?php
if (!defined('ABSPATH')) {
    exit;
}

class CensoConfig
{

    // Constantes de configuración
    const TABLE_NAME = 'censo_iae';
    const BATCH_SIZE = 5000;
    const GEMINI_MODEL = 'gemini-3-flash-preview';

    // Ajustes de IA (Opciones de WordPress)
    const OPTION_SERPER_KEY = 'ep_censo_serper_key';
    const OPTION_GEMINI_KEY = 'ep_censo_gemini_key';
    const OPTION_MAPS_KEY = 'ep_censo_maps_key';
    const OPTION_MAPS_DAILY_LIMIT = 'ep_censo_maps_daily_limit';
    const OPTION_WORKER_STATUS = 'ep_censo_worker_status';
    const OPTION_SERPER_USAGE = 'ep_censo_serper_usage';
    const OPTION_GEMINI_USAGE = 'ep_censo_gemini_usage';
    const OPTION_MAX_BUDGET = 'ep_censo_max_budget';

    /**
     * Definición de campos basada en la aplicación Python original.
     * Formato: [start, end, type, args...]
     * Nota: En PHP substr usa (start, length), así que calcularemos length dinámicamente.
     */
    public static function get_field_definitions()
    {
        return [
            ['name' => 'Numdateko', 'start' => 0, 'end' => 4, 'type' => 'str'],
            ['name' => 'EJERCICIO', 'start' => 0, 'end' => 4, 'type' => 'int'],
            ['name' => 'ESTADO', 'start' => 0, 'end' => 1, 'type' => 'str'],
            ['name' => 'REFERENCIA', 'start' => 1, 'end' => 14, 'type' => 'str'],
            ['name' => 'NIF', 'start' => 14, 'end' => 23, 'type' => 'str'],
            ['name' => 'RAZON', 'start' => 23, 'end' => 148, 'type' => 'str', 'strip' => true],
            ['name' => 'ANAGRAMA', 'start' => 148, 'end' => 158, 'type' => 'str'],
            ['name' => 'SRSRA', 'start' => 158, 'end' => 159, 'type' => 'str'],
            ['name' => 'VIAFISC', 'start' => 159, 'end' => 164, 'type' => 'str'],
            ['name' => 'CODVIAFISC', 'start' => 164, 'end' => 169, 'type' => 'str'],
            ['name' => 'CALLEFISC', 'start' => 169, 'end' => 219, 'type' => 'str', 'strip' => true],
            ['name' => 'TIPNUMFISC', 'start' => 219, 'end' => 222, 'type' => 'str'],
            ['name' => 'NUMEROFISC', 'start' => 222, 'end' => 227, 'type' => 'str'],
            ['name' => 'KMFISC', 'start' => 227, 'end' => 230, 'type' => 'str'],
            ['name' => 'BLOQUEFISC', 'start' => 230, 'end' => 233, 'type' => 'str'],
            ['name' => 'PORTALFISC', 'start' => 233, 'end' => 236, 'type' => 'str'],
            ['name' => 'ESCALERAFISC', 'start' => 236, 'end' => 239, 'type' => 'str'],
            ['name' => 'PISOFISC', 'start' => 239, 'end' => 242, 'type' => 'str'],
            ['name' => 'PUERTAFISC', 'start' => 242, 'end' => 245, 'type' => 'str'],
            ['name' => 'COMPLFISC', 'start' => 245, 'end' => 285, 'type' => 'str', 'strip' => true],
            ['name' => 'POBLFISC', 'start' => 285, 'end' => 315, 'type' => 'str', 'strip' => true],
            ['name' => 'CPOSTALFISC', 'start' => 315, 'end' => 320, 'type' => 'str'],
            ['name' => 'CODMUNFISC', 'start' => 320, 'end' => 325, 'type' => 'str'],
            ['name' => 'MUNICIPIOFISC', 'start' => 325, 'end' => 355, 'type' => 'str', 'strip' => true],
            ['name' => 'CODPROFISC', 'start' => 355, 'end' => 357, 'type' => 'str'],
            ['name' => 'PROVINCIAFISC', 'start' => 357, 'end' => 377, 'type' => 'str', 'strip' => true],
            ['name' => 'VIAACT', 'start' => 459, 'end' => 464, 'type' => 'str'],
            ['name' => 'CODVIAACT', 'start' => 464, 'end' => 469, 'type' => 'str'],
            ['name' => 'CALLEACT', 'start' => 469, 'end' => 519, 'type' => 'str', 'strip' => true],
            ['name' => 'TIPNUMACT', 'start' => 519, 'end' => 522, 'type' => 'str'],
            ['name' => 'NUMEROACT', 'start' => 522, 'end' => 527, 'type' => 'str'],
            ['name' => 'KMACT', 'start' => 527, 'end' => 530, 'type' => 'str'],
            ['name' => 'BLOQUEACT', 'start' => 530, 'end' => 533, 'type' => 'str'],
            ['name' => 'PORTALACT', 'start' => 533, 'end' => 536, 'type' => 'str'],
            ['name' => 'ESCALERAACT', 'start' => 536, 'end' => 539, 'type' => 'str'],
            ['name' => 'PISOACT', 'start' => 539, 'end' => 542, 'type' => 'str'],
            ['name' => 'PUERTAACT', 'start' => 542, 'end' => 545, 'type' => 'str'],
            ['name' => 'COMPLACT', 'start' => 545, 'end' => 585, 'type' => 'str', 'strip' => true],
            ['name' => 'POBLACT', 'start' => 585, 'end' => 615, 'type' => 'str', 'strip' => true],
            ['name' => 'CPOSTALACT', 'start' => 615, 'end' => 620, 'type' => 'str'],
            ['name' => 'CODMUNACT', 'start' => 620, 'end' => 625, 'type' => 'str'],
            ['name' => 'MUNICIPIOACT', 'start' => 625, 'end' => 655, 'type' => 'str', 'strip' => true],
            ['name' => 'CODPROACT', 'start' => 655, 'end' => 657, 'type' => 'str'],
            ['name' => 'PROVINCIAACT', 'start' => 657, 'end' => 677, 'type' => 'str', 'strip' => true],
            ['name' => 'ESTADOCONT', 'start' => 759, 'end' => 760, 'type' => 'str'],
            ['name' => 'MOTIVOBAJA', 'start' => 760, 'end' => 761, 'type' => 'str'],
            ['name' => 'FECHAESTADO', 'start' => 761, 'end' => 769, 'type' => 'date'],
            ['name' => 'EPIGRAFE', 'start' => 769, 'end' => 774, 'type' => 'str'],
            ['name' => 'TIPOCUOTA', 'start' => 774, 'end' => 775, 'type' => 'str'],
            ['name' => 'FECHAINICIO', 'start' => 775, 'end' => 783, 'type' => 'date'],
            ['name' => 'NOTASAGRUP', 'start' => 783, 'end' => 786, 'type' => 'str'],
            ['name' => 'NOTASGRUPO', 'start' => 786, 'end' => 789, 'type' => 'str'],
            ['name' => 'NOTASEPIG', 'start' => 789, 'end' => 792, 'type' => 'str'],
            ['name' => 'REGLAAPLIC', 'start' => 792, 'end' => 793, 'type' => 'str'],
            ['name' => 'CODACTPRIN', 'start' => 793, 'end' => 797, 'type' => 'str'],
            ['name' => 'CLAVEEXEN', 'start' => 797, 'end' => 798, 'type' => 'str'],
            ['name' => 'LITERALBEN', 'start' => 798, 'end' => 813, 'type' => 'str', 'strip' => true],
            ['name' => 'PORBEN', 'start' => 813, 'end' => 816, 'type' => 'str'],
            ['name' => 'FECHALIMBEN', 'start' => 816, 'end' => 824, 'type' => 'date'],
            ['name' => 'YEARINICIOPROF', 'start' => 824, 'end' => 828, 'type' => 'str'],
            ['name' => 'INFOADI', 'start' => 828, 'end' => 837, 'type' => 'str'],
            ['name' => 'FECHACESE', 'start' => 837, 'end' => 845, 'type' => 'date'],
            ['name' => 'CAUSACESE', 'start' => 845, 'end' => 860, 'type' => 'str', 'strip' => true],
            ['name' => 'REFALTAORIG', 'start' => 860, 'end' => 873, 'type' => 'str'],
            ['name' => 'TABLACODS', 'start' => 873, 'end' => 915, 'type' => 'str'],
            ['name' => 'TABLACANES', 'start' => 915, 'end' => 1062, 'type' => 'str'],
            ['name' => 'CODVIA', 'start' => 1062, 'end' => 1067, 'type' => 'str'],
            ['name' => 'SG', 'start' => 1067, 'end' => 1069, 'type' => 'str'],
            ['name' => 'CALLE', 'start' => 1069, 'end' => 1094, 'type' => 'str', 'strip' => true],
            ['name' => 'NUMVIA', 'start' => 1094, 'end' => 1099, 'type' => 'str'],
            ['name' => 'ESCALERA', 'start' => 1099, 'end' => 1101, 'type' => 'str'],
            ['name' => 'PISO', 'start' => 1101, 'end' => 1103, 'type' => 'str'],
            ['name' => 'PUERTA', 'start' => 1103, 'end' => 1105, 'type' => 'str'],
            ['name' => 'KM', 'start' => 1105, 'end' => 1110, 'type' => 'str'],
            ['name' => 'PTO', 'start' => 1110, 'end' => 1114, 'type' => 'str'],
            ['name' => 'CODMUN', 'start' => 1114, 'end' => 1119, 'type' => 'str'],
            ['name' => 'MUNICIPIO', 'start' => 1119, 'end' => 1144, 'type' => 'str', 'strip' => true],
            ['name' => 'CP', 'start' => 1144, 'end' => 1149, 'type' => 'str'],
            ['name' => 'TELEFONO', 'start' => 1149, 'end' => 1156, 'type' => 'str'],
            ['name' => 'CODPRO', 'start' => 1156, 'end' => 1158, 'type' => 'str'],
            ['name' => 'PROVINCIA', 'start' => 1158, 'end' => 1178, 'type' => 'str', 'strip' => true],
            ['name' => 'TIPOLOCAL', 'start' => 1178, 'end' => 1179, 'type' => 'str'],
            ['name' => 'USODESTINO', 'start' => 1179, 'end' => 1181, 'type' => 'str'],
            ['name' => 'SUPTOTAL', 'start' => 1181, 'end' => 1188, 'type' => 'int'],
            ['name' => 'CUOTATARIFA', 'start' => 1188, 'end' => 1200, 'type' => 'float'],
            ['name' => 'CUOTASUP', 'start' => 1200, 'end' => 1210, 'type' => 'float'],
            ['name' => 'RESEXENCION', 'start' => 1210, 'end' => 1211, 'type' => 'str'],
            ['name' => 'MARCAORIGEN', 'start' => 1211, 'end' => 1213, 'type' => 'str'],
            ['name' => 'NIFCABGRUPO', 'start' => 1213, 'end' => 1222, 'type' => 'str'],
            ['name' => 'SINUSO', 'start' => 1222, 'end' => 1224, 'type' => 'str'],
            ['name' => 'CODCAMARA', 'start' => 1224, 'end' => 1227, 'type' => 'str'],
            ['name' => 'NOMBRECAMARA', 'start' => 1227, 'end' => 1239, 'type' => 'str', 'strip' => true],
            ['name' => 'FECHAEXTRACCION', 'start' => 1239, 'end' => 1247, 'type' => 'date'],
            ['name' => 'INCN', 'start' => 1254, 'end' => 1267, 'type' => 'float'],
            // Campos adicionales para importación desde Excel/CSV (coincidentes con columnas de enriquecimiento)
            // Usamos un rango ficticio grande para asegurar que se creen como TEXT o VARCHAR suficientemente grandes
            ['name' => 'EMAIL_ENRICH', 'start' => 0, 'end' => 200, 'type' => 'str'],
            ['name' => 'TELEFONO_ENRICH', 'start' => 0, 'end' => 50, 'type' => 'str'],
            ['name' => 'WEB_ENRICH', 'start' => 0, 'end' => 255, 'type' => 'str']
        ];
    }
}
