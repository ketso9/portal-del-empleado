<?php
/**
 *    SimpleXLSX php class
 *    MS Excel 2007+ workbooks reader
 *
 * Copyright (c) 2012 - 2022 SimpleXLSX
 *
 * @category   SimpleXLSX
 * @package    SimpleXLSX
 * @copyright  Copyright (c) 2012 - 2022 SimpleXLSX (https://github.com/shuchkin/simplexlsx/)
 * @license    MIT
 */

/**
 * Simplified version without namespace for easy integration
 */
class SimpleXLSX
{
    public static $CF = [
        0 => 'General',
        1 => '0',
        2 => '0.00',
        3 => '#,##0',
        4 => '#,##0.00',
        9 => '0%',
        10 => '0.00%',
        11 => '0.00E+00',
        12 => '# ?/?',
        13 => '# ??/??',
        14 => 'mm-dd-yy',
        15 => 'd-mmm-yy',
        16 => 'd-mmm',
        17 => 'mmm-yy',
        18 => 'h:mm AM/PM',
        19 => 'h:mm:ss AM/PM',
        20 => 'h:mm',
        21 => 'h:mm:ss',
        22 => 'm/d/yy h:mm',
        37 => '#,##0 ;(#,##0)',
        38 => '#,##0 ;[Red](#,##0)',
        39 => '#,##0.00;(#,##0.00)',
        40 => '#,##0.00;[Red](#,##0.00)',
        44 => '_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)',
        45 => 'mm:ss',
        46 => '[h]:mm:ss',
        47 => 'mmss.0',
        48 => '##0.0E+0',
        49 => '@',
        27 => '[$-404]e/m/d',
        30 => 'm/d/yy',
        36 => '[$-404]e/m/d',
        50 => '[$-404]e/m/d',
        57 => '[$-404]e/m/d',
        59 => 't0',
        60 => 't0.00',
        61 => 't#,##0',
        62 => 't#,##0.00',
        67 => 't0%',
        68 => 't0.00%',
        69 => 't# ?/?',
        70 => 't# ??/??',
    ];
    public $nf = [];
    public $cellFormats = [];
    public $datetimeFormat = 'Y-m-d H:i:s';
    public $debug;
    public $activeSheet = 0;
    public $sheets;
    public $sheetFiles = [];
    public $sheetMetaData = [];
    public $sheetRels = [];
    public $styles;
    public $package;
    public $sharedstrings;
    public $date1904 = 0;
    public $errno = 0;
    public $error = false;
    public $theme;

    public function __construct($filename = null, $is_data = null, $debug = null)
    {
        if ($debug !== null) {
            $this->debug = $debug;
        }
        $this->package = ['filename' => '', 'mtime' => 0, 'size' => 0, 'comment' => '', 'entries' => []];
        if ($filename && $this->unzip($filename, $is_data)) {
            $this->parseEntries();
        }
    }

    public function unzip($filename, $is_data = false)
    {
        if ($is_data) {
            $this->package['filename'] = 'default.xlsx';
            $this->package['mtime'] = time();
            $this->package['size'] = self::strlen($filename);
            $vZ = $filename;
        } else {
            if (!is_readable($filename)) {
                $this->error(1, 'File not found ' . $filename);
                return false;
            }
            $this->package['filename'] = $filename;
            $this->package['mtime'] = filemtime($filename);
            $this->package['size'] = filesize($filename);
            $vZ = file_get_contents($filename);
        }
        $aE = explode("\x50\x4b\x03\x04", $vZ);
        array_shift($aE);
        $aEL = count($aE);
        if ($aEL === 0) {
            $this->error(2, 'Unknown archive format');
            return false;
        }
        $last = $aE[$aEL - 1];
        $last = explode("\x50\x4b\x05\x06", $last);
        if (count($last) !== 2) {
            $this->error(2, 'Unknown archive format');
            return false;
        }
        $last = explode("\x50\x4b\x01\x02", $last[0]);
        if (count($last) < 2) {
            $this->error(2, 'Unknown archive format');
            return false;
        }
        $aE[$aEL - 1] = $last[0];
        foreach ($aE as $vZ) {
            $aI = [];
            $aI['E'] = 0;
            $aI['EM'] = '';
            $aP = unpack('v1VN/v1GPF/v1CM/v1FT/v1FD/V1CRC/V1CS/V1UCS/v1FNL/v1EFL', $vZ);
            $nF = $aP['FNL'];
            $mF = $aP['EFL'];
            if ($aP['GPF'] & 0x0008) {
                $aP1 = unpack('V1CRC/V1CS/V1UCS', self::substr($vZ, -12));
                $aP['CRC'] = $aP1['CRC'];
                $aP['CS'] = $aP1['CS'];
                $aP['UCS'] = $aP1['UCS'];
                $vZ = self::substr($vZ, 0, -12);
                if (self::substr($vZ, -4) === "\x50\x4b\x07\x08") {
                    $vZ = self::substr($vZ, 0, -4);
                }
            }
            $aI['N'] = self::substr($vZ, 26, $nF);
            $aI['N'] = str_replace('\\', '/', $aI['N']);
            if (self::substr($aI['N'], -1) === '/') {
                continue;
            }
            $aI['P'] = dirname($aI['N']);
            $aI['P'] = ($aI['P'] === '.') ? '' : $aI['P'];
            $aI['N'] = basename($aI['N']);
            $vZ = self::substr($vZ, 26 + $nF + $mF);
            $aI['T'] = mktime(($aP['FT'] & 0xf800) >> 11, ($aP['FT'] & 0x07e0) >> 5, ($aP['FT'] & 0x001f) << 1, ($aP['FD'] & 0x01e0) >> 5, $aP['FD'] & 0x001f, (($aP['FD'] & 0xfe00) >> 9) + 1980);
            $this->package['entries'][] = ['data' => $vZ, 'ucs' => (int) $aP['UCS'], 'cm' => $aP['CM'], 'cs' => isset($aP['CS']) ? (int) $aP['CS'] : 0, 'crc' => $aP['CRC'], 'error' => $aI['E'], 'error_msg' => $aI['EM'], 'name' => $aI['N'], 'path' => $aI['P'], 'time' => $aI['T']];
        }
        return true;
    }

    public function error($num = null, $str = null)
    {
        if ($num) {
            $this->errno = $num;
            $this->error = $str;
            if ($this->debug) {
                trigger_error(__CLASS__ . ': ' . $this->error, E_USER_WARNING);
            }
        }
        return $this->error;
    }

    public function parseEntries()
    {
        $this->sharedstrings = [];
        $this->sheets = [];
        if ($relations = $this->getEntryXML('_rels/.rels')) {
            foreach ($relations->Relationship as $rel) {
                $rel_type = basename(trim((string) $rel['Type']));
                $rel_target = self::getTarget('', (string) $rel['Target']);
                if ($rel_type === 'officeDocument' && $workbook = $this->getEntryXML($rel_target)) {
                    $index_rId = [];
                    $index = 0;
                    foreach ($workbook->sheets->sheet as $s) {
                        $a = [];
                        foreach ($s->attributes() as $k => $v) {
                            $a[(string) $k] = (string) $v;
                        }
                        $this->sheetMetaData[$index] = $a;
                        $index_rId[$index] = (string) $s['id'];
                        $index++;
                    }
                    if ((int) $workbook->workbookPr['date1904'] === 1) {
                        $this->date1904 = 1;
                    }
                    if ($workbookRelations = $this->getEntryXML(dirname($rel_target) . '/_rels/workbook.xml.rels')) {
                        foreach ($workbookRelations->Relationship as $workbookRelation) {
                            $wrel_type = basename(trim((string) $workbookRelation['Type']));
                            $wrel_target = self::getTarget(dirname($rel_target), (string) $workbookRelation['Target']);
                            if (!$this->entryExists($wrel_target)) {
                                continue;
                            }
                            if ($wrel_type === 'worksheet') {
                                if ($sheet = $this->getEntryXML($wrel_target)) {
                                    $index = array_search((string) $workbookRelation['Id'], $index_rId, true);
                                    $this->sheets[$index] = $sheet;
                                    $this->sheetFiles[$index] = $wrel_target;
                                }
                            } elseif ($wrel_type === 'sharedStrings') {
                                if ($sharedStrings = $this->getEntryXML($wrel_target)) {
                                    foreach ($sharedStrings->si as $val) {
                                        if (isset($val->t)) {
                                            $this->sharedstrings[] = (string) $val->t;
                                        } elseif (isset($val->r)) {
                                            $this->sharedstrings[] = self::parseRichText($val);
                                        }
                                    }
                                }
                            } elseif ($wrel_type === 'styles') {
                                $this->styles = $this->getEntryXML($wrel_target);
                                if (isset($this->styles->numFmts->numFmt)) {
                                    foreach ($this->styles->numFmts->numFmt as $v) {
                                        $this->nf[(int) $v['numFmtId']] = (string) $v['formatCode'];
                                    }
                                }
                                if (isset($this->styles->cellXfs->xf)) {
                                    foreach ($this->styles->cellXfs->xf as $v) {
                                        $x = ['format' => null];
                                        foreach ($v->attributes() as $k1 => $v1) {
                                            $x[$k1] = (int) $v1;
                                        }
                                        if (isset($x['numFmtId'])) {
                                            if (isset($this->nf[$x['numFmtId']])) {
                                                $x['format'] = $this->nf[$x['numFmtId']];
                                            } elseif (isset(self::$CF[$x['numFmtId']])) {
                                                $x['format'] = self::$CF[$x['numFmtId']];
                                            }
                                        }
                                        $this->cellFormats[] = $x;
                                    }
                                }
                            }
                        }
                    }
                    if ($workbook->bookViews->workbookView) {
                        foreach ($workbook->bookViews->workbookView as $v) {
                            if (!empty($v['activeTab'])) {
                                $this->activeSheet = (int) $v['activeTab'];
                            }
                        }
                    }
                    break;
                }
            }
        }
        if (count($this->sheets)) {
            ksort($this->sheets);
            return true;
        }
        return false;
    }

    public function getEntryXML($name)
    {
        if ($entry_xml = $this->getEntryData($name)) {
            $this->deleteEntry($name);
            $entry_xml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $entry_xml); // Remove xmlns
            $entry_xml = preg_replace('/<(\/?)[\w\d]+:([\w\d]+)/i', '<$1$2', $entry_xml); // Remove prefix from <tag> and </tag>
            $entry_xml = preg_replace('/ ([\w\d]+):([\w\d]+=")/i', ' $1$2', $entry_xml); // Remove prefix from attributes
            $entry_xmlobj = @simplexml_load_string(trim($entry_xml), 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_PARSEHUGE);
            if ($entry_xmlobj) {
                return $entry_xmlobj;
            } else {
                ep_error_log("SimpleXLSX: Error parseando XML para entrada: " . $name);
                ep_error_log("SimpleXLSX: XML contenido (recortado): " . substr($entry_xml, 0, 1000));
            }
        }
        return false;
    }

    public function getEntryData($name)
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $dir = self::strtoupper(dirname($name));
        $name = self::strtoupper(basename($name));
        foreach ($this->package['entries'] as &$entry) {
            if (self::strtoupper($entry['path']) === $dir && self::strtoupper($entry['name']) === $name) {
                if ($entry['error']) {
                    return false;
                }
                switch ($entry['cm']) {
                    case 8:
                        $entry['data'] = gzinflate($entry['data']);
                        break;
                    case 12:
                        if (extension_loaded('bz2')) {
                            $entry['data'] = bzdecompress($entry['data']);
                        }
                        break;
                }
                if ($entry['cm'] > -1) {
                    $entry['cm'] = -1;
                }
                return $entry['data'];
            }
        }
        return false;
    }

    public function deleteEntry($name)
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $dir = self::strtoupper(dirname($name));
        $name = self::strtoupper(basename($name));
        foreach ($this->package['entries'] as $k => $entry) {
            if (self::strtoupper($entry['path']) === $dir && self::strtoupper($entry['name']) === $name) {
                unset($this->package['entries'][$k]);
                return true;
            }
        }
        return false;
    }

    public static function strtoupper($str)
    {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strtoupper($str, '8bit') : strtoupper($str);
    }
    public function entryExists($name)
    {
        $dir = self::strtoupper(dirname($name));
        $name = self::strtoupper(basename($name));
        foreach ($this->package['entries'] as $entry) {
            if (self::strtoupper($entry['path']) === $dir && self::strtoupper($entry['name']) === $name) {
                return true;
            }
        }
        return false;
    }
    public static function parse($filename, $is_data = false, $debug = false)
    {
        $xlsx = new self();
        if ($xlsx->unzip($filename, $is_data)) {
            $xlsx->parseEntries();
        }
        if ($xlsx->success()) {
            return $xlsx;
        }
        return false;
    }
    public function success()
    {
        return !$this->error;
    }

    public function worksheet($worksheetIndex = 0)
    {
        return isset($this->sheets[$worksheetIndex]) ? $this->sheets[$worksheetIndex] : false;
    }

    public function dimension($worksheetIndex = 0)
    {
        if (($ws = $this->worksheet($worksheetIndex)) === false) {
            return [0, 0];
        }
        $ref = (string) $ws->dimension['ref'];
        if (self::strpos($ref, ':') !== false) {
            $d = explode(':', $ref);
            $idx = $this->getIndex($d[1]);
            return [$idx[0] + 1, $idx[1] + 1];
        }
        $maxC = $maxR = 0;
        foreach ($ws->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $idx = $this->getIndex((string) $c['r']);
                if ($idx[0] > $maxC)
                    $maxC = $idx[0];
                if ($idx[1] > $maxR)
                    $maxR = $idx[1];
            }
        }
        return [$maxC + 1, $maxR + 1];
    }

    public function getIndex($cell = 'A1')
    {
        if (preg_match('/([A-Z]+)(\d+)/', $cell, $m)) {
            $col = $m[1];
            $row = $m[2];
            $colLen = self::strlen($col);
            $index = 0;
            for ($i = $colLen - 1; $i >= 0; $i--) {
                $index += (ord($col[$i]) - 64) * pow(26, $colLen - $i - 1);
            }
            return [$index - 1, $row - 1];
        }
        return [-1, -1];
    }

    public function value($cell)
    {
        $dataType = (string) $cell['t'];
        if ($dataType === '' || $dataType === 'n') {
            $s = (int) $cell['s'];
            if ($s > 0 && isset($this->cellFormats[$s]) && isset($this->cellFormats[$s]['format'])) {
                if (preg_match('/[mM]/', $this->cellFormats[$s]['format']))
                    $dataType = 'D';
            }
        }
        switch ($dataType) {
            case 's':
                return ((string) $cell->v !== '') ? $this->sharedstrings[(int) $cell->v] : '';
            case 'D':
                return !empty($cell->v) ? gmdate($this->datetimeFormat, $this->unixstamp((float) $cell->v)) : '';
            default:
                return (string) $cell->v;
        }
    }

    public function unixstamp($excelDateTime)
    {
        $d = floor($excelDateTime);
        $t = $excelDateTime - $d;
        if ($this->date1904)
            $d += 1462;
        $t = (abs($d) > 0) ? ($d - 25569) * 86400 + round($t * 86400) : round($t * 86400);
        return (int) $t;
    }

    public function readRows($worksheetIndex = 0, $limit = 0)
    {
        if (($ws = $this->worksheet($worksheetIndex)) === false)
            return;
        $dim = $this->dimension($worksheetIndex);
        $numCols = $dim[0];
        $emptyRow = array_fill(0, $numCols, '');
        $curR = 0;
        foreach ($ws->sheetData->row as $row) {
            $r = $emptyRow;
            foreach ($row->c as $c) {
                $idx = $this->getIndex((string) $c['r']);
                if ($idx[0] > -1)
                    $r[$idx[0]] = $this->value($c);
            }
            yield $r;
            if ($limit > 0 && ++$curR >= $limit)
                return;
        }
    }

    public function sheetNames()
    {
        $a = [];
        foreach ($this->sheetMetaData as $k => $v) {
            $a[$k] = $v['name'];
        }
        return $a;
    }
    public static function getTarget($base, $target)
    {
        $target = trim($target);
        if (strpos($target, '/') === 0)
            return self::substr($target, 1);
        $target = ($base ? $base . '/' : '') . $target;
        $abs = [];
        foreach (explode('/', $target) as $p) {
            if ('.' === $p)
                continue;
            if ('..' === $p)
                array_pop($abs);
            else
                $abs[] = $p;
        }
        return implode('/', $abs);
    }
    public static function parseRichText($is = null)
    {
        $v = [];
        if (isset($is->t)) {
            $v[] = (string) $is->t;
        } elseif (isset($is->r)) {
            foreach ($is->r as $run) {
                $v[] = (string) $run->t;
            }
        }
        return implode('', $v);
    }
    public static function strlen($str)
    {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strlen($str, '8bit') : strlen($str);
    }
    public static function substr($str, $start, $length = null)
    {
        return (ini_get('mbstring.func_overload') & 2) ? mb_substr($str, $start, ($length === null) ? mb_strlen($str, '8bit') : $length, '8bit') : substr($str, $start, ($length === null) ? strlen($str) : $length);
    }
    public static function strpos($haystack, $needle, $offset = 0)
    {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strpos($haystack, $needle, $offset, '8bit') : strpos($haystack, $needle, $offset);
    }
}
