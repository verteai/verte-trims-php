<?php
// ─────────────────────────────────────────────────────────────
//  simple_xlsx.php  —  Minimal XLSX writer (PHP 5.3+, ZipArchive)
//  Produces a real .xlsx file (Office Open XML) Excel can open
//  natively without any "format mismatch" warning.
//
//  Usage:
//    require_once 'Library/simple_xlsx.php';
//    $xlsx = new SimpleXlsx('Sheet1');
//    $xlsx->setColWidth(0, 18);
//    $xlsx->addRow(array('A','B','C'), SimpleXlsx::S_HEADER);
//    $xlsx->addRow(array(1, 2, 'foo'));            // S_DATA default
//    $xlsx->mergeRange(0, 5, 0, 8);                // r1,c1,r2,c2 (0-based)
//    $xlsx->download('report.xlsx');
//
//  Predefined style indices:
//    S_DEFAULT      = 0  → no style
//    S_TITLE        = 1  → bold, large font, centered (no fill)
//    S_SUBTITLE     = 2  → italic, centered (no fill)
//    S_HEADER       = 3  → dark-blue fill, white bold, centered, border, wrap
//    S_HEADER_PURPLE= 4  → purple fill, white bold, centered, border, wrap
//    S_DATA_LEFT    = 5  → border, left, wrap
//    S_DATA_CENTER  = 6  → border, center
//    S_DATA_NUM     = 7  → border, right (numeric)
//    S_TOTAL_LABEL  = 8  → dark-blue fill, white bold, right
//    S_TOTAL_NUM    = 9  → dark-blue fill, white bold, right (numeric totals)
// ─────────────────────────────────────────────────────────────

class SimpleXlsx {
    const S_DEFAULT       = 0;
    const S_TITLE         = 1;
    const S_SUBTITLE      = 2;
    const S_HEADER        = 3;
    const S_HEADER_PURPLE = 4;
    const S_DATA_LEFT     = 5;
    const S_DATA_CENTER   = 6;
    const S_DATA_NUM      = 7;
    const S_TOTAL_LABEL   = 8;
    const S_TOTAL_NUM     = 9;

    private $rows      = array();
    private $merges    = array();
    private $colWidths = array();
    private $sheetTitle;
    private $rowHeights = array();

    public function __construct($sheetTitle = 'Sheet1') {
        $this->sheetTitle = $sheetTitle;
    }

    public function setColWidth($colIdx0, $widthChars) {
        $this->colWidths[(int)$colIdx0] = (float)$widthChars;
    }

    public function setRowHeight($rowIdx0, $heightPt) {
        $this->rowHeights[(int)$rowIdx0] = (float)$heightPt;
    }

    // ── Add one row ──────────────────────────────────────────
    // $values     : array of scalars
    // $defaultS   : style index applied to every cell unless overridden
    // $cellStyles : optional associative array colIdx0 => style index
    public function addRow($values, $defaultS = self::S_DATA_LEFT, $cellStyles = array()) {
        $row = array();
        for ($c = 0; $c < count($values); $c++) {
            $s = isset($cellStyles[$c]) ? (int)$cellStyles[$c] : (int)$defaultS;
            $row[] = array('v' => $values[$c], 's' => $s);
        }
        $this->rows[] = $row;
    }

    // Add an empty/blank row (no cells, no styling)
    public function addBlankRow() {
        $this->rows[] = array();
    }

    public function mergeRange($r1, $c1, $r2, $c2) {
        $this->merges[] = $this->cellRef($r1, $c1) . ':' . $this->cellRef($r2, $c2);
    }

    public function cellRef($r0, $c0) {
        return $this->colLetter($c0) . ($r0 + 1);
    }

    public function colLetter($c0) {
        $letter = '';
        $n = (int)$c0 + 1;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $n = (int)(($n - 1) / 26);
        }
        return $letter;
    }

    public function rowCount() {
        return count($this->rows);
    }

    // ── Build & stream XLSX ─────────────────────────────────
    public function download($filename) {
        if (!class_exists('ZipArchive')) {
            die('ZipArchive is not enabled. Enable ZIP in php.ini to export Excel.');
        }
        $zipPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            die('Cannot create XLSX archive.');
        }

        $zip->addFromString('[Content_Types].xml',        $this->contentTypesXml());
        $zip->addFromString('_rels/.rels',                $this->relsXml());
        $zip->addFromString('xl/workbook.xml',            $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml',              $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml',   $this->sheetXml());
        $zip->close();

        while (ob_get_level() > 0) { @ob_end_clean(); }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($zipPath);
        @unlink($zipPath);
    }

    // ── XML Generators ───────────────────────────────────────
    private function contentTypesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
             . '</Types>';
    }

    private function relsXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
             . '</Relationships>';
    }

    private function workbookXml() {
        $name = htmlspecialchars(substr($this->sheetTitle, 0, 31), ENT_QUOTES, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
             . '</workbook>';
    }

    private function workbookRelsXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
             . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
             . '</Relationships>';
    }

    // Predefined styles: 4 fonts, 4 fills, 2 borders, 10 cellXfs
    private function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<fonts count="4">'
             .   '<font><sz val="10"/><name val="Calibri"/></font>'                                                  // 0 normal
             .   '<font><sz val="10"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                       // 1 white bold
             .   '<font><sz val="14"/><b/><color rgb="FF1A3A5C"/><name val="Calibri"/></font>'                       // 2 title
             .   '<font><sz val="10"/><i/><color rgb="FF555555"/><name val="Calibri"/></font>'                       // 3 subtitle
             . '</fonts>'
             . '<fills count="4">'
             .   '<fill><patternFill patternType="none"/></fill>'                                                    // 0
             .   '<fill><patternFill patternType="gray125"/></fill>'                                                 // 1
             .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1A3A5C"/><bgColor indexed="64"/></patternFill></fill>' // 2 dark blue
             .   '<fill><patternFill patternType="solid"><fgColor rgb="FF6A3A7A"/><bgColor indexed="64"/></patternFill></fill>' // 3 purple
             . '</fills>'
             . '<borders count="2">'
             .   '<border><left/><right/><top/><bottom/><diagonal/></border>'                                        // 0 none
             .   '<border>'                                                                                          // 1 thin grey
             .     '<left style="thin"><color rgb="FFC8D0DA"/></left>'
             .     '<right style="thin"><color rgb="FFC8D0DA"/></right>'
             .     '<top style="thin"><color rgb="FFC8D0DA"/></top>'
             .     '<bottom style="thin"><color rgb="FFC8D0DA"/></bottom>'
             .     '<diagonal/>'
             .   '</border>'
             . '</borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="10">'
             // 0 default
             .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
             // 1 title
             .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
             // 2 subtitle
             .   '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
             // 3 header (dark blue)
             .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
             // 4 header purple
             .   '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
             // 5 data left
             .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
             // 6 data center
             .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
             // 7 data num right
             .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
             // 8 total label (dark blue, white bold, right)
             .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
             // 9 total num (dark blue, white bold, right)
             .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
             . '</cellXfs>'
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '<dxfs count="0"/>'
             . '<tableStyles count="0"/>'
             . '</styleSheet>';
    }

    private function sheetXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if (!empty($this->colWidths)) {
            $xml .= '<cols>';
            ksort($this->colWidths);
            foreach ($this->colWidths as $c0 => $w) {
                $col1 = (int)$c0 + 1;
                $xml .= '<col min="' . $col1 . '" max="' . $col1 . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        for ($r = 0; $r < count($this->rows); $r++) {
            $row = $this->rows[$r];
            $rowAttrs = ' r="' . ($r + 1) . '"';
            if (isset($this->rowHeights[$r])) {
                $rowAttrs .= ' ht="' . $this->rowHeights[$r] . '" customHeight="1"';
            }
            $xml .= '<row' . $rowAttrs . '>';
            for ($c = 0; $c < count($row); $c++) {
                $cell = $row[$c];
                $ref  = $this->cellRef($r, $c);
                $val  = $cell['v'];
                $s    = (int)$cell['s'];

                // Treat as numeric only if it's a real number (not a numeric string with leading zeros, dashes, etc.)
                $isNum = (is_int($val) || is_float($val))
                      || (is_string($val) && $val !== '' && preg_match('/^-?\d+(\.\d+)?$/', $val) && !preg_match('/^0\d/', $val));

                if ($val === '' || $val === null) {
                    $xml .= '<c r="' . $ref . '" s="' . $s . '"/>';
                } elseif ($isNum) {
                    $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $val . '</v></c>';
                } else {
                    $escaped = htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
                    $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (!empty($this->merges)) {
            $xml .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $m) {
                $xml .= '<mergeCell ref="' . $m . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }
}
