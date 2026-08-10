<?php
if (!defined('ABSPATH')) {
    exit;
}

class CensoDB
{

    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . CensoConfig::TABLE_NAME;
    }

    /**
     * Crea la tabla en la base de datos si no existe.
     * Basado en los campos de CensoConfig.
     */
    public function create_table()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $fields = CensoConfig::get_field_definitions();

        $sql = "CREATE TABLE $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            DESCRIPCION_EPIGRAFE text,
            EPIGRAFE_LIMPIO varchar(20),
            MAPS_LINK text,
            SEARCH_DATA LONGTEXT,
            AGRUPACION_ELECTORAL text,
            ENRICH_STATUS varchar(20) DEFAULT 'Pending',
            ENRICH_LOG text,
            ESTADO_INTERNO varchar(20) DEFAULT 'Activo',
            FUENTE_IMPORTACION varchar(255) DEFAULT NULL,
            ULTIMA_IMPORTACION datetime,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        ";

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];

            // Definir tipo SQL según tipo PHP/Python
            $length = $field['end'] - $field['start'];

            $sql_type = 'text'; // Default safer for row size limit

            if ($type === 'int') {
                $sql_type = 'bigint(20)';
            } elseif ($type === 'float') {
                $sql_type = 'decimal(15,2)';
            } elseif ($type === 'date') {
                $sql_type = 'date';
            } elseif ($type === 'str') {
                // Campos que necesitan índices o son muy cortos
                $indexed_fields = ['REFERENCIA', 'NIF', 'RAZON', 'MUNICIPIOFISC', 'EPIGRAFE', 'TELEFONO', 'ENRICH_STATUS', 'ESTADO_INTERNO'];

                if (in_array($name, $indexed_fields)) {
                    // Para campos indexados usamos varchar con longitud segura
                    if ($name === 'RAZON') {
                        $sql_type = "varchar(255)";
                    } else {
                        $sql_type = "varchar(100)";
                    }
                } else {
                    // El resto TEXT para evitar el límite de 65KB de fila (MySQL row size limit)
                    $sql_type = 'text';
                }
            }

            $sql .= "`$name` $sql_type NULL,\n";
        }

        $sql .= "PRIMARY KEY  (id),
            UNIQUE KEY referencia (REFERENCIA),
            INDEX nif (NIF),
            INDEX razon (RAZON(50)),
            INDEX municipio (MUNICIPIOFISC),
            INDEX epigrafe (EPIGRAFE),
            FULLTEXT INDEX search_idx (SEARCH_DATA)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        if (!empty($result)) {
            ep_error_log("Censo: dbDelta ejecutado. Cambios: " . print_r($result, true));
        }
    }

    /**
     * Realiza un UPSERT (Insert or Update) masivo.
     * En MySQL usamos INSERT ... ON DUPLICATE KEY UPDATE.
     * 
     * @param array $data_batch Array de arrays asociativos con los datos.
     * @return int Número de filas afectadas.
     */
    public function batch_upsert($data_batch)
    {
        if (empty($data_batch))
            return 0;

        global $wpdb;

        // Preparar columnas
        $first_row = reset($data_batch);
        $columns = array_keys($first_row);
        $columns_sql = '`' . implode('`, `', $columns) . '`';

        // Preparar placeholders
        $placeholders = [];
        $values = [];

        foreach ($data_batch as $row) {
            $row_placeholders = [];
            foreach ($row as $val) {
                $row_placeholders[] = '%s';
                $values[] = $val;
            }
            $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
        }

        $values_sql = implode(', ', $placeholders);

        // Preparar parte UPDATE
        $update_parts = [];
        foreach ($columns as $col) {
            if ($col === 'REFERENCIA')
                continue;
            $update_parts[] = "`$col` = VALUES(`$col`)";
        }
        $update_sql = implode(', ', $update_parts);

        $query = "INSERT INTO $this->table_name ($columns_sql) VALUES $values_sql 
                  ON DUPLICATE KEY UPDATE $update_sql";

        return $wpdb->query($wpdb->prepare($query, $values));
    }

    public function truncate_iae_table()
    {
        global $wpdb;
        $res = $wpdb->query("TRUNCATE TABLE $this->table_name");
        if ($res === false) {
            ep_error_log("Censo: TRUNCATE falló para $this->table_name. Intentando DELETE...");
            $res = $wpdb->query("DELETE FROM $this->table_name");
            if ($res === false) {
                ep_error_log("Censo: FATAL - No se pudo limpiar la tabla $this->table_name: " . $wpdb->last_error);
            }
        }
        return $res;
    }

    public function get_by_referencia($referencia)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE REFERENCIA = %s", $referencia), ARRAY_A);
    }

    public function get_by_nif($nif)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE NIF = %s LIMIT 1", $nif), ARRAY_A);
    }

    public function get_by_razon($razon)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE RAZON = %s LIMIT 1", $razon), ARRAY_A);
    }

    public function get_by_nif_epigrafe($nif, $epigrafe)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE NIF = %s AND EPIGRAFE = %s", $nif, $epigrafe), ARRAY_A);
    }

    public function update_iae($id, $data)
    {
        global $wpdb;
        // Recuperar registro completo para no perder datos en el índice de búsqueda
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE id = %d", $id), ARRAY_A);
        $full_row = $existing ? array_merge($existing, $data) : $data;

        $data['SEARCH_DATA'] = $this->generate_search_data($full_row);
        $res = $wpdb->update($this->table_name, $data, ['id' => $id]);
        if ($res === false) {
            ep_error_log("Censo: Error SQL en update_iae: " . $wpdb->last_error);
            ep_error_log("Censo: Datos Fallidos Update: " . json_encode($data));
        }
        return $res;
    }

    public function insert_iae($data)
    {
        global $wpdb;
        $data['SEARCH_DATA'] = $this->generate_search_data($data);
        $res = $wpdb->insert($this->table_name, $data);
        if ($res === false) {
            ep_error_log("Censo: Error SQL en insert_iae: " . $wpdb->last_error);
            ep_error_log("Censo: Datos Fallidos Insert: " . json_encode($data));
        }
        return $res;
    }

    private function generate_search_data($row)
    {
        $fields = [
            $row['NIF'] ?? '',
            $row['RAZON'] ?? '',
            $row['ANAGRAMA'] ?? '',
            $row['MUNICIPIOFISC'] ?? '',
            $row['EMAIL_ENRICH'] ?? '',
            $row['TELEFONO_ENRICH'] ?? '',
            $row['REFERENCIA'] ?? '',
            $row['EPIGRAFE'] ?? '',
            $row['EPIGRAFE_LIMPIO'] ?? '',
            $row['DESCRIPCION_EPIGRAFE'] ?? ''
        ];
        return mb_strtolower(implode(' ', array_filter($fields)));
    }

    public function delete_multiple_iae($ids)
    {
        global $wpdb;
        if (empty($ids))
            return 0;

        $ids_str = implode(',', array_map('intval', $ids));
        return $wpdb->query("DELETE FROM $this->table_name WHERE id IN ($ids_str)");
    }

    public function reindex_all_search_data($batch_size = 500, $offset = 0)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ), ARRAY_A);

        if (!$rows)
            return 0;

        foreach ($rows as $row) {
            $search_data = $this->generate_search_data($row);
            $wpdb->update($this->table_name, ['SEARCH_DATA' => $search_data], ['id' => $row['id']]);
        }

        return count($rows);
    }
}
