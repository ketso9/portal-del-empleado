<?php
if (!defined('ABSPATH')) {
    exit;
}

class CensoParser
{

    private $epigrafes_dict = [];

    public function __construct()
    {
        // En una implementación real, cargaríamos esto de un JSON o BBDD.
        // Por ahora lo inicializamos vacío o con básicos, se puede extender luego.
        // Si tienes el archivo epigrafes.json, deberíamos importarlo también.
        $this->load_epigrafes();
    }

    private function load_epigrafes()
    {
        $json_path = plugin_dir_path(__FILE__) . 'epigrades.json';
        if (file_exists($json_path)) {
            $json_content = file_get_contents($json_path);
            $data = json_decode($json_content, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    $epi = trim($item['EPIGRAFE']);
                    $desc = trim($item['DESCRIPCION']);
                    if (!empty($epi) && !isset($this->epigrafes_dict[$epi])) {
                        $this->epigrafes_dict[$epi] = $desc;
                    }
                }
            }
        }
    }

    /**
     * Procesa una línea del archivo y devuelve un array asociativo.
     */
    public function parse_line($line, $year, $import_type = 'enrichment')
    {
        // 1. Detección y normalización de encoding a UTF-8
        $encoding = mb_detect_encoding($line, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== 'UTF-8') {
            $line = mb_convert_encoding($line, 'UTF-8', $encoding ?: 'ISO-8859-1');
        }

        $fields_def = CensoConfig::get_field_definitions();
        $data = [];

        // 2. Detección de formato (Delimitado o Ancho Fijo)
        $delimiter = null;
        if (strpos($line, "\t") !== false)
            $delimiter = "\t";
        elseif (strpos($line, ";") !== false)
            $delimiter = ";";
        elseif (strpos($line, ",") !== false)
            $delimiter = ",";

        // Si hay un delimitador y la línea parece ser corta o tener muchas particiones, es delimitado.
        // Las líneas de ancho fijo de la AEAT suelen ser de +250 caracteres.
        $is_delimited = ($delimiter !== null && (strlen($line) < 200 || count(explode($delimiter, $line)) > 5));

        // Si el usuario marcó explícitamente "Standard Import", FORZAMOS Ancho Fijo
        if ($import_type === 'standard') {
            $is_delimited = false;
        }

        if ($is_delimited) {
            // Usamos str_getcsv para manejar correctamente las comillas
            $parts = str_getcsv($line, $delimiter);
            $i = 0;
            foreach ($fields_def as $field) {
                if (isset($parts[$i])) {
                    $val = trim($parts[$i], " \t\n\r\0\x0B\"");
                    $data[$field['name']] = $this->convert_value($val, $field['type']);
                } else {
                    $data[$field['name']] = null;
                }
                $i++;
            }
        } else {

            // Formato Ancho Fijo (Legacy)
            // Usamos mb_substr porque los offsets de la AEAT suelen referirse a caracteres/posiciones,
            // y al estar ya en UTF-8, evitamos desplazamientos por caracteres multi-byte (como la Ñ).
            foreach ($fields_def as $field) {
                $name = $field['name'];
                $start = (int) $field['start'];
                $end = (int) $field['end'];
                $type = $field['type'];

                $length = $end - $start;

                // PROTECCIÓN: Ignorar campos de enriquecimiento que no existen en el formato plano AEAT
                // Estos campos tienen start=0 en CensoConfig para propósitos de DB, pero ensuciarían el parseo fijo.
                $enrichment_fields = ['EMAIL_ENRICH', 'TELEFONO_ENRICH', 'WEB_ENRICH'];
                if (in_array($name, $enrichment_fields) && $start === 0 && $end > 0) {
                    $data[$name] = null;
                    continue;
                }

                // mb_substr es seguro para UTF-8 y mantiene la alineación de columnas
                $raw_val = mb_substr($line, $start, $length, 'UTF-8');

                if ($raw_val !== false) {
                    $raw_val = trim($raw_val, " \t\n\r\0\x0B\"");
                } else {
                    $raw_val = '';
                }

                $data[$name] = $this->convert_value($raw_val, $type);
            }
        }

        // Campos calculados / sobreescritos
        $data['Numdateko'] = (string) $year;
        $data['EJERCICIO'] = (int) $year;

        // Lógica de epígrafe limpio
        $epigrafe_original = isset($data['EPIGRAFE']) ? $data['EPIGRAFE'] : '';
        $data['EPIGRAFE_LIMPIO'] = $this->clean_epigrafe($epigrafe_original);

        // Descripción (Calculada)
        $data['DESCRIPCION_EPIGRAFE'] = $this->get_epigrafe_description($data['EPIGRAFE'], $data['EPIGRAFE_LIMPIO']);

        return $data;
    }

    public function get_epigrafe_description($epigrafe, $epigrafe_limpio = '')
    {
        $epigrafe = trim($epigrafe);
        if (empty($epigrafe))
            return '';

        // 1. Intento por EPIGRAFE tal cual (ej: "11")
        if (isset($this->epigrafes_dict[$epigrafe])) {
            return $this->epigrafes_dict[$epigrafe];
        }

        // 2. Intento por EPIGRAFE_LIMPIO (ej: "611.1")
        $epigrafe_limpio = trim($epigrafe_limpio);
        if (!empty($epigrafe_limpio)) {
            if (isset($this->epigrafes_dict[$epigrafe_limpio])) {
                return $this->epigrafes_dict[$epigrafe_limpio];
            }

            // 3. Si termina en .0, intentar sin el .0 (ej: "611.0" -> "611")
            if (substr($epigrafe_limpio, -2) === '.0') {
                $base = substr($epigrafe_limpio, 0, -2);
                if (isset($this->epigrafes_dict[$base])) {
                    return $this->epigrafes_dict[$base];
                }
            }

            // 4. Intentar quitando el punto (ej: "611.1" -> "6111")
            $no_dots = str_replace('.', '', $epigrafe_limpio);
            if (isset($this->epigrafes_dict[$no_dots])) {
                return $this->epigrafes_dict[$no_dots];
            }
        }

        return '';
    }

    private function convert_value($val, $type)
    {
        $val = trim($val);
        if ($type === 'int') {
            return intval($val);
        } elseif ($type === 'float') {
            $val = str_replace(',', '.', $val);
            return floatval($val);
        } elseif ($type === 'date') {
            if (strlen($val) === 8 && is_numeric($val)) {
                $y = substr($val, 0, 4);
                $m = substr($val, 4, 2);
                $d = substr($val, 6, 2);
                if (checkdate($m, $d, $y)) {
                    return "$y-$m-$d";
                }
            }
            // Soporte para formato YYYY-MM-DD que ya venga bien
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                return $val;
            }
            return null;
        }
        return $val;
    }

    /**
     * Replica `CensoIAEApp.clean_epigrafe`
     */
    private function clean_epigrafe($epigrafe)
    {
        $epigrafe = trim($epigrafe);
        if (empty($epigrafe))
            return '';

        // if epigrafe[0] in ['1', '2']:
        $first_char = substr($epigrafe, 0, 1);
        if ($first_char === '1' || $first_char === '2') {
            // epigrafe = epigrafe[1:]
            $epigrafe = substr($epigrafe, 1);

            // if len(epigrafe) > 1: epigrafe = f"{epigrafe[:-1]}.{epigrafe[-1]}"
            if (strlen($epigrafe) > 1) {
                $last_char = substr($epigrafe, -1);
                $rest = substr($epigrafe, 0, -1);
                $epigrafe = "$rest.$last_char";
            }
        }
        return $epigrafe;
    }
}
