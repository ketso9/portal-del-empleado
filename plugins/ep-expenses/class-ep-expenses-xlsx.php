<?php
defined('ABSPATH') || exit;

/**
 * Generador de ficheros XLSX sin dependencias externas.
 *
 * Un .xlsx no es más que un ZIP con varios XML dentro, así que se construye a
 * mano con ZipArchive en lugar de arrastrar una librería completa. Cubre lo que
 * necesita la exportación de gastos: cabecera fija, filtros automáticos, fechas
 * e importes con formato y anchos de columna.
 */
if (class_exists('EP_Expenses_XLSX', false)) {
    return;
}

class EP_Expenses_XLSX
{
    /** Tipos de celda admitidos en la definición de columnas. */
    const TIPO_TEXTO  = 'texto';
    const TIPO_NUMERO = 'numero';
    const TIPO_EURO   = 'euro';
    const TIPO_FECHA  = 'fecha';

    /**
     * @param array  $columnas  [ ['titulo' => 'Fecha', 'tipo' => 'fecha', 'ancho' => 12], ... ]
     * @param array  $filas     Array de arrays con los valores en el mismo orden.
     * @param string $hoja      Nombre de la pestaña.
     *
     * @return string|WP_Error Ruta del fichero generado.
     */
    public static function generar($columnas, $filas, $hoja = 'Gastos')
    {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('sin_zip', 'El servidor no dispone de ZipArchive para generar el Excel.');
        }

        $tmp = wp_tempnam('ep-gastos-xlsx');
        if (!$tmp) {
            return new WP_Error('sin_temporal', 'No se pudo crear el fichero temporal.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            return new WP_Error('zip_error', 'No se pudo crear el libro de Excel.');
        }

        $zip->addFromString('[Content_Types].xml', self::content_types());
        $zip->addFromString('_rels/.rels', self::rels_raiz());
        $zip->addFromString('xl/workbook.xml', self::workbook($hoja));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::rels_workbook());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::hoja($columnas, $filas));

        $zip->close();

        return $tmp;
    }

    /**
     * Convierte un índice de columna (0) en su letra (A, B ... AA).
     */
    public static function letra_columna($indice)
    {
        $letra = '';
        $indice++;
        while ($indice > 0) {
            $resto  = ($indice - 1) % 26;
            $letra  = chr(65 + $resto) . $letra;
            $indice = intdiv($indice - 1, 26);
        }
        return $letra;
    }

    private static function esc($texto)
    {
        $texto = (string) $texto;
        // Excel rechaza los caracteres de control salvo tabulador y saltos de línea.
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto);
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Fecha a número de serie de Excel (días desde 1899-12-30).
     */
    private static function serie_fecha($fecha)
    {
        $ts = strtotime((string) $fecha);
        if (!$ts) {
            return null;
        }
        return (int) floor($ts / 86400) + 25569;
    }

    private static function hoja($columnas, $filas)
    {
        $total_col = count($columnas);
        $ultima    = self::letra_columna($total_col - 1);
        $total_fil = count($filas) + 1;

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Cabecera siempre visible al desplazarse
        $xml .= '<sheetViews><sheetView workbookViewId="0">'
              . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
              . '</sheetView></sheetViews>';

        $xml .= '<cols>';
        foreach ($columnas as $i => $col) {
            $ancho = !empty($col['ancho']) ? floatval($col['ancho']) : 18;
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $ancho . '" customWidth="1"/>';
        }
        $xml .= '</cols>';

        $xml .= '<sheetData>';

        // Fila de cabecera
        $xml .= '<row r="1">';
        foreach ($columnas as $i => $col) {
            $ref = self::letra_columna($i) . '1';
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . self::esc($col['titulo']) . '</t></is></c>';
        }
        $xml .= '</row>';

        // Datos
        $n = 1;
        foreach ($filas as $fila) {
            $n++;
            $xml .= '<row r="' . $n . '">';
            foreach ($columnas as $i => $col) {
                $valor = isset($fila[$i]) ? $fila[$i] : '';
                $ref   = self::letra_columna($i) . $n;
                $tipo  = isset($col['tipo']) ? $col['tipo'] : self::TIPO_TEXTO;

                if ($valor === '' || $valor === null) {
                    continue; // celda vacía: no hace falta escribirla
                }

                if ($tipo === self::TIPO_EURO) {
                    $xml .= '<c r="' . $ref . '" s="2"><v>' . round(floatval($valor), 2) . '</v></c>';
                } elseif ($tipo === self::TIPO_NUMERO) {
                    $xml .= '<c r="' . $ref . '" s="3"><v>' . floatval($valor) . '</v></c>';
                } elseif ($tipo === self::TIPO_FECHA) {
                    $serie = self::serie_fecha($valor);
                    if ($serie === null) {
                        $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::esc($valor) . '</t></is></c>';
                    } else {
                        $xml .= '<c r="' . $ref . '" s="4"><v>' . $serie . '</v></c>';
                    }
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::esc($valor) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        $xml .= '<autoFilter ref="A1:' . $ultima . $total_fil . '"/>';
        $xml .= '</worksheet>';

        return $xml;
    }

    private static function styles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/>'
            . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private static function workbook($hoja)
    {
        $nombre = self::esc(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', '', $hoja), 0, 31));
        if ($nombre === '') {
            $nombre = 'Hoja1';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $nombre . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function rels_workbook()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function rels_raiz()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function content_types()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }
}
