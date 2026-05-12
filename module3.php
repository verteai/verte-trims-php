<?php
require_once dirname(__FILE__) . '/config.php';

// ── Shared SQL (optional supplier, brand, IO — same rules as Module 5 / dashboard) ──
function reportSqlAndParams($from, $to, $supplier, $brand, $io) {
    $params = array($from, $to);
    $extra  = '';
    if ($supplier !== '' && $supplier !== 'ALL') {
        $extra   .= ' AND a.Vendor_Name = ?';
        $params[] = $supplier;
    }
    if ($brand !== '' && $brand !== 'ALL') {
        $extra   .= ' AND a.Custome_Name = ?';
        $params[] = $brand;
    }
    if ($io !== '') {
        $extra   .= ' AND a.IO_num = ?';
        $params[] = $io;
    }
    $sql = "
        SELECT
            d.description                               AS [MONTH],
            e.description                               AS [WEEK],
            CONVERT(VARCHAR(10), a.Inspection_Date,101) AS [DATE],
            a.Vendor_Name                               AS [SUPPLIER NAME],
            a.IO_Num                                    AS [IO NUM],
            a.PO_Num                                    AS [PO NUM],
            a.GR_Num                                    AS [GR NUM],
            a.Vessel                                    AS [VESSEL],
            a.Voyage                                    AS [VOYAGE],
            a.Container_Num                             AS CONTAINER_NUM,
            a.HBL                                       AS [HBL],
            a.Custome_Name                              AS [BRAND],
            c.description                               AS [TYPE OF TRIMS],
            SUM(a.Total_Qty)                            AS [TTL QTY],
            SUM(a.Qty_Inspected)                        AS [QTY INSPECTED],
            SUM(a.Qty_Defects)                          AS [QTY DEFECTS],
            b.description                               AS [TYPE OF DEFECTS],
            a.Result                                    AS [RESULT]
        FROM TRIMS_TBL_INSPECTION a
        LEFT JOIN TRIMS_TBL_DROPDOWN   b ON a.Defect_Type      = b.id
        LEFT JOIN TRIMS_TBL_DROPDOWN   c ON a.System_Trim_Type = c.id
        LEFT JOIN TRIMS_TBL_WEEKMONTH  d ON a.Month            = d.id
        LEFT JOIN TRIMS_TBL_WEEKMONTH  e ON a.Week             = e.id
        WHERE a.Inspection_Date >= ?
          AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
          $extra
        GROUP BY
            d.description, e.description,
            CONVERT(VARCHAR(10), a.Inspection_Date,101),
            a.Vendor_Name, a.IO_Num, a.PO_Num, a.GR_Num, a.Vessel, a.Voyage, a.Container_Num, a.HBL,
            a.Custome_Name, c.description,
            b.description, a.Result
        ORDER BY
            CONVERT(VARCHAR(10), a.Inspection_Date,101),
            a.Vendor_Name, a.IO_Num, a.PO_Num, a.Vessel, a.Voyage, a.Container_Num, a.HBL, c.description
    ";
    return array($sql, $params);
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: load_report → JSON
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_report') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $from     = isset($_GET['from'])     ? trim($_GET['from'])     : '';
    $to       = isset($_GET['to'])       ? trim($_GET['to'])       : '';
    $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $brand    = isset($_GET['brand'])    ? trim($_GET['brand'])    : '';
    $io       = isset($_GET['io'])       ? trim($_GET['io'])       : '';
    if ($from === '' || $to === '') {
        echo json_encode(array('error' => 'Date range is required.')); exit;
    }
    $qp   = reportSqlAndParams($from, $to, $supplier, $brand, $io);
    $rows = dbQuery($qp[0], $qp[1]);
    if (isset($rows['__error'])) {
        echo json_encode(array('error' => $rows['__error'])); exit;
    }
    echo json_encode($rows);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: export_excel → download XLSX
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'export_excel') {

    require_once dirname(__FILE__) . '/Library/simple_xlsx.php';

    $from     = isset($_GET['from'])     ? trim($_GET['from'])     : '';
    $to       = isset($_GET['to'])       ? trim($_GET['to'])       : '';
    $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $brand    = isset($_GET['brand'])    ? trim($_GET['brand'])    : '';
    $io       = isset($_GET['io'])       ? trim($_GET['io'])       : '';
    if ($from === '' || $to === '') { die('Date range is required'); }

    $qp   = reportSqlAndParams($from, $to, $supplier, $brand, $io);
    $rows = dbQuery($qp[0], $qp[1]);
    if (isset($rows['__error'])) { die($rows['__error']); }

    $headers = array(
        'MONTH','WEEK','DATE','SUPPLIER NAME','IO NUM','PO NUM',
        'GR NUM','VESSEL','VOYAGE','CONTAINER #','HBL','BRAND','TYPE OF TRIMS','TTL QTY','QTY INSPECTED',
        'QTY DEFECTS','TYPE OF DEFECTS','RESULT'
    );
    $widths = array(10, 8, 12, 26, 12, 12, 14, 18, 10, 10, 12, 16, 20, 11, 14, 12, 20, 12);
    // Cell style per column for data rows
    $colStyles = array(
        SimpleXlsx::S_DATA_CENTER, // MONTH
        SimpleXlsx::S_DATA_CENTER, // WEEK
        SimpleXlsx::S_DATA_CENTER, // DATE
        SimpleXlsx::S_DATA_LEFT,   // SUPPLIER NAME
        SimpleXlsx::S_DATA_LEFT,   // IO NUM
        SimpleXlsx::S_DATA_LEFT,   // PO NUM
        SimpleXlsx::S_DATA_LEFT,   // GR NUM
        SimpleXlsx::S_DATA_LEFT,   // VESSEL
        SimpleXlsx::S_DATA_LEFT,   // VOYAGE
        SimpleXlsx::S_DATA_LEFT,   // CONTAINER #
        SimpleXlsx::S_DATA_LEFT,   // HBL
        SimpleXlsx::S_DATA_LEFT,   // BRAND
        SimpleXlsx::S_DATA_LEFT,   // TYPE OF TRIMS
        SimpleXlsx::S_DATA_NUM,    // TTL QTY
        SimpleXlsx::S_DATA_NUM,    // QTY INSPECTED
        SimpleXlsx::S_DATA_NUM,    // QTY DEFECTS
        SimpleXlsx::S_DATA_LEFT,   // TYPE OF DEFECTS
        SimpleXlsx::S_DATA_CENTER  // RESULT
    );

    $xlsx = new SimpleXlsx('Trims Report');
    for ($i = 0; $i < count($widths); $i++) { $xlsx->setColWidth($i, $widths[$i]); }

    // Title + period (rows 0 & 1, merged across all columns)
    $lastCol = count($headers) - 1;
    $titleRow = array_fill(0, count($headers), '');
    $titleRow[0] = 'TRIMS INSPECTION REPORT';
    $xlsx->addRow($titleRow, SimpleXlsx::S_TITLE);
    $xlsx->mergeRange(0, 0, 0, $lastCol);
    $xlsx->setRowHeight(0, 22);

    $subRow = array_fill(0, count($headers), '');
    $subRow[0] = 'Period: ' . $from . '  to  ' . $to;
    $xlsx->addRow($subRow, SimpleXlsx::S_SUBTITLE);
    $xlsx->mergeRange(1, 0, 1, $lastCol);
    $xlsx->addBlankRow();

    // Column header row
    $xlsx->addRow($headers, SimpleXlsx::S_HEADER);
    $xlsx->setRowHeight($xlsx->rowCount() - 1, 28);

    // Data rows
    $totQty = 0; $totIns = 0; $totDef = 0;
    foreach ($rows as $r) {
        $rowData = array(
            isset($r['MONTH'])           ? $r['MONTH']           : '',
            isset($r['WEEK'])            ? $r['WEEK']            : '',
            isset($r['DATE'])            ? $r['DATE']            : '',
            isset($r['SUPPLIER NAME'])   ? $r['SUPPLIER NAME']   : '',
            isset($r['IO NUM'])          ? $r['IO NUM']          : '',
            isset($r['PO NUM'])          ? $r['PO NUM']          : '',
            isset($r['GR NUM'])          ? $r['GR NUM']          : '',
            isset($r['VESSEL'])          ? $r['VESSEL']          : '',
            isset($r['VOYAGE'])          ? $r['VOYAGE']          : '',
            isset($r['CONTAINER_NUM'])   ? $r['CONTAINER_NUM']   : '',
            isset($r['HBL'])             ? $r['HBL']             : '',
            isset($r['BRAND'])           ? $r['BRAND']           : '',
            isset($r['TYPE OF TRIMS'])   ? $r['TYPE OF TRIMS']   : '',
            isset($r['TTL QTY'])         ? (int)$r['TTL QTY']    : 0,
            isset($r['QTY INSPECTED'])   ? (int)$r['QTY INSPECTED'] : 0,
            isset($r['QTY DEFECTS'])     ? (int)$r['QTY DEFECTS']   : 0,
            isset($r['TYPE OF DEFECTS']) ? $r['TYPE OF DEFECTS'] : '',
            isset($r['RESULT'])          ? $r['RESULT']          : '',
        );
        $totQty += $rowData[13];
        $totIns += $rowData[14];
        $totDef += $rowData[15];
        $xlsx->addRow($rowData, SimpleXlsx::S_DATA_LEFT, $colStyles);
    }

    // Grand total row
    if (count($rows) > 0) {
        $totalRow = array('GRAND TOTAL','','','','','','','','','','','','', $totQty, $totIns, $totDef, '', '');
        $totalStyles = array(
            0=>SimpleXlsx::S_TOTAL_LABEL, 1=>SimpleXlsx::S_TOTAL_LABEL, 2=>SimpleXlsx::S_TOTAL_LABEL,
            3=>SimpleXlsx::S_TOTAL_LABEL, 4=>SimpleXlsx::S_TOTAL_LABEL, 5=>SimpleXlsx::S_TOTAL_LABEL,
            6=>SimpleXlsx::S_TOTAL_LABEL, 7=>SimpleXlsx::S_TOTAL_LABEL, 8=>SimpleXlsx::S_TOTAL_LABEL,
            9=>SimpleXlsx::S_TOTAL_LABEL,10=>SimpleXlsx::S_TOTAL_LABEL,11=>SimpleXlsx::S_TOTAL_LABEL,12=>SimpleXlsx::S_TOTAL_LABEL,
            13=>SimpleXlsx::S_TOTAL_NUM,  14=>SimpleXlsx::S_TOTAL_NUM, 15=>SimpleXlsx::S_TOTAL_NUM,
            16=>SimpleXlsx::S_TOTAL_LABEL,17=>SimpleXlsx::S_TOTAL_LABEL
        );
        $xlsx->addRow($totalRow, SimpleXlsx::S_TOTAL_LABEL, $totalStyles);
        // Merge label across cols 0..12
        $xlsx->mergeRange($xlsx->rowCount() - 1, 0, $xlsx->rowCount() - 1, 12);
    }

    $filename = 'TRIMS_Report_' . $from . '_to_' . $to . '.xlsx';
    $xlsx->download($filename);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: export_pdf → download PDF (FPDF)
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'export_pdf') {

    $fpdfPath = dirname(__FILE__) . '/Library/fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        die('FPDF library not found. Place fpdf.php in ' . dirname(__FILE__) . '/Library/fpdf/');
    }
    require_once $fpdfPath;

    $from     = isset($_GET['from'])     ? trim($_GET['from'])     : '';
    $to       = isset($_GET['to'])       ? trim($_GET['to'])       : '';
    $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $brand    = isset($_GET['brand'])    ? trim($_GET['brand'])    : '';
    $io       = isset($_GET['io'])       ? trim($_GET['io'])       : '';
    if ($from === '' || $to === '') { die('Date range is required'); }

    $qp   = reportSqlAndParams($from, $to, $supplier, $brand, $io);
    $rows = dbQuery($qp[0], $qp[1]);
    if (isset($rows['__error'])) { die($rows['__error']); }

    // ── Column definitions ─────────────────────────────────────────────────
    // A4 Landscape: 297mm wide, margins 10mm each side → 277mm usable
    $headers = array(
        'MONTH','WEEK','DATE','SUPPLIER NAME','IO NUM','PO NUM',
        'GR NUM','VESSEL','VOYAGE','CONT.#','HBL','BRAND','TYPE OF TRIMS','TTL QTY','QTY INSPECTED',
        'QTY DEFECTS','TYPE OF DEFECTS','RESULT'
    );
    $widths = array(
        14,   // MONTH
        12,   // WEEK
        16,   // DATE
        24,   // SUPPLIER NAME
        14,   // IO NUM
        13,   // PO NUM
        14,   // GR NUM
        18,   // VESSEL
        10,   // VOYAGE
        12,   // CONTAINER
        12,   // HBL
        14,   // BRAND
        21,   // TYPE OF TRIMS
        14,   // TTL QTY
        16,   // QTY INSPECTED
        14,   // QTY DEFECTS
        19,   // TYPE OF DEFECTS
        14    // RESULT
    );
    $aligns = array('C','C','C','L','L','L','L','L','L','L','L','L','L','R','R','R','L','C');

    // ── FPDF custom class ──────────────────────────────────────────────────
    class TrimsPDF extends FPDF {
        public $colHeaders = array();
        public $colWidths  = array();
        public $dateFrom   = '';
        public $dateTo     = '';
        public $lineH      = 4.5;   // base line height per text line

        function Header() {
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(0, 7, 'TRIMS INSPECTION REPORT', 0, 1, 'C');
            $this->SetFont('Arial', '', 8);
            $this->Cell(0, 5, 'Period: ' . $this->dateFrom . '  to  ' . $this->dateTo, 0, 1, 'C');
            $this->Ln(2);

            // Column headers — wrap long labels so nothing overlaps
            $this->SetFont('Arial', 'B', 6.5);
            $this->SetFillColor(26, 58, 92);
            $this->SetTextColor(255, 255, 255);
            $this->SetLineWidth(0.2);

            $hdrLineH = 4;   // line height per header line
            $hdrPad   = 1;   // top padding inside header cell

            // Pre-calculate wrapped lines for each header
            $hdrLines = array();
            $maxHdrLines = 1;
            for ($i = 0; $i < count($this->colHeaders); $i++) {
                $wrapped = $this->wrapText($this->colHeaders[$i], $this->colWidths[$i] - 2);
                $hdrLines[$i] = $wrapped;
                if (count($wrapped) > $maxHdrLines) { $maxHdrLines = count($wrapped); }
            }
            $hdrH = $maxHdrLines * $hdrLineH + $hdrPad * 2;

            $hdrY    = $this->GetY();
            $hdrX    = $this->lMargin;

            for ($i = 0; $i < count($this->colHeaders); $i++) {
                $cellX = $hdrX;
                for ($j = 0; $j < $i; $j++) { $cellX += $this->colWidths[$j]; }

                // Draw filled border box
                $this->Rect($cellX, $hdrY, $this->colWidths[$i], $hdrH, 'DF');

                // Vertically centre the text block inside the header cell
                $totalTextH = count($hdrLines[$i]) * $hdrLineH;
                $textStartY = $hdrY + ($hdrH - $totalTextH) / 2;

                for ($ln = 0; $ln < count($hdrLines[$i]); $ln++) {
                    $this->SetXY($cellX + 1, $textStartY + $ln * $hdrLineH);
                    $this->Cell($this->colWidths[$i] - 2, $hdrLineH, $hdrLines[$i][$ln], 0, 0, 'C');
                }
            }
            $this->SetXY($this->lMargin, $hdrY + $hdrH);
            $this->SetTextColor(0, 0, 0);
            $this->SetLineWidth(0.2);
        }

        function Footer() {
            $this->SetY(-10);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(130, 130, 130);
            $this->Cell(0, 5,
                'Generated: ' . date('Y-m-d H:i') .
                '   |   Page ' . $this->PageNo() . '/{nb}',
                0, 0, 'C');
        }

        // ── WrapRow: correct side-by-side wrapping ─────────────────────────
        // Strategy:
        //   1. For each cell, split text into wrapped lines (word-wrap to fit width).
        //   2. Find the tallest cell (most lines) → that defines the row height.
        //   3. Draw every cell at a FIXED x position (accumulated from left margin)
        //      using a temporary single-page trick: save Y, draw MultiCell, restore Y+x.
        //   4. Advance Y by rowHeight after all cells are drawn.
        function WrapRow($data, $aligns, $fill) {
            $widths = $this->colWidths;
            $lh     = $this->lineH;

            $this->SetFont('Arial', '', 7);

            // ── Step 1: word-wrap each cell, count lines ────────────────────
            $cellLines = array();  // array of arrays of strings
            for ($i = 0; $i < count($data); $i++) {
                $cellLines[$i] = $this->wrapText((string)$data[$i], $widths[$i] - 2);
            }

            // ── Step 2: max lines → row height ─────────────────────────────
            $maxLines = 1;
            for ($i = 0; $i < count($cellLines); $i++) {
                if (count($cellLines[$i]) > $maxLines) {
                    $maxLines = count($cellLines[$i]);
                }
            }
            $rowH = $maxLines * $lh + 2;   // +2mm vertical padding

            // ── Step 3: page break check ────────────────────────────────────
            if ($this->GetY() + $rowH > $this->GetPageHeight() - 14) {
                $this->AddPage();
            }

            $rowY  = $this->GetY();
            $startX = $this->lMargin;

            // ── Step 4: draw each cell box at correct X ────────────────────
            for ($i = 0; $i < count($data); $i++) {
                $cellX = $startX;
                for ($j = 0; $j < $i; $j++) { $cellX += $widths[$j]; }

                // Fill background
                if ($fill) {
                    $this->SetFillColor(245, 249, 252);
                } else {
                    $this->SetFillColor(255, 255, 255);
                }

                // Draw the outer border rectangle for the full row height
                $this->Rect($cellX, $rowY, $widths[$i], $rowH, $fill ? 'DF' : 'D');

                // Draw each wrapped line of text inside the cell
                $lines = $cellLines[$i];
                $align = isset($aligns[$i]) ? $aligns[$i] : 'L';
                for ($ln = 0; $ln < count($lines); $ln++) {
                    $textY = $rowY + 1 + $ln * $lh;
                    $this->SetXY($cellX + 1, $textY);
                    $this->Cell($widths[$i] - 2, $lh, $lines[$ln], 0, 0, $align);
                }
            }

            // ── Step 5: advance Y to next row ──────────────────────────────
            $this->SetXY($this->lMargin, $rowY + $rowH);
        }

        // Word-wrap a string to fit within $maxW mm, return array of line strings
        function wrapText($text, $maxW) {
            $text  = trim($text);
            if ($text === '') { return array(''); }

            $words = explode(' ', $text);
            $lines = array();
            $line  = '';

            foreach ($words as $word) {
                $test = ($line === '') ? $word : $line . ' ' . $word;
                if ($this->GetStringWidth($test) <= $maxW) {
                    $line = $test;
                } else {
                    if ($line !== '') { $lines[] = $line; }
                    // If single word is wider than cell, break it character by character
                    if ($this->GetStringWidth($word) > $maxW) {
                        $part = '';
                        for ($ci = 0; $ci < strlen($word); $ci++) {
                            $try = $part . $word[$ci];
                            if ($this->GetStringWidth($try) > $maxW) {
                                $lines[] = $part;
                                $part = $word[$ci];
                            } else {
                                $part = $try;
                            }
                        }
                        $line = $part;
                    } else {
                        $line = $word;
                    }
                }
            }
            if ($line !== '') { $lines[] = $line; }
            return $lines;
        }
    }

    // ── Build PDF ──────────────────────────────────────────────────────────
    $pdf = new TrimsPDF('L', 'mm', 'A4');
    $pdf->colHeaders = $headers;
    $pdf->colWidths  = $widths;
    $pdf->dateFrom   = $from;
    $pdf->dateTo     = $to;
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 16, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 7);

    $fill = false;
    foreach ($rows as $r) {
        $rowData = array(
            isset($r['MONTH'])           ? $r['MONTH']           : '',
            isset($r['WEEK'])            ? $r['WEEK']            : '',
            isset($r['DATE'])            ? $r['DATE']            : '',
            isset($r['SUPPLIER NAME'])   ? $r['SUPPLIER NAME']   : '',
            isset($r['IO NUM'])          ? $r['IO NUM']          : '',
            isset($r['PO NUM'])          ? $r['PO NUM']          : '',
            isset($r['GR NUM'])          ? $r['GR NUM']          : '',
            isset($r['VESSEL'])          ? $r['VESSEL']          : '',
            isset($r['VOYAGE'])          ? $r['VOYAGE']          : '',
            isset($r['CONTAINER_NUM'])   ? $r['CONTAINER_NUM']   : '',
            isset($r['HBL'])             ? $r['HBL']             : '',
            isset($r['BRAND'])           ? $r['BRAND']           : '',
            isset($r['TYPE OF TRIMS'])   ? $r['TYPE OF TRIMS']   : '',
            isset($r['TTL QTY'])         ? $r['TTL QTY']         : '',
            isset($r['QTY INSPECTED'])   ? $r['QTY INSPECTED']   : '',
            isset($r['QTY DEFECTS'])     ? $r['QTY DEFECTS']     : '',
            isset($r['TYPE OF DEFECTS']) ? $r['TYPE OF DEFECTS'] : '',
            isset($r['RESULT'])          ? $r['RESULT']          : '',
        );
        $pdf->WrapRow($rowData, $aligns, $fill);
        $fill = !$fill;
    }

    // ── Grand total row ────────────────────────────────────────────────────
    if (count($rows) > 0) {
        $totQty = 0; $totIns = 0; $totDef = 0;
        foreach ($rows as $r) {
            $totQty += isset($r['TTL QTY'])       ? (int)$r['TTL QTY']       : 0;
            $totIns += isset($r['QTY INSPECTED']) ? (int)$r['QTY INSPECTED'] : 0;
            $totDef += isset($r['QTY DEFECTS'])   ? (int)$r['QTY DEFECTS']   : 0;
        }
        $totH = 6;
        if ($pdf->GetY() + $totH > $pdf->GetPageHeight() - 14) { $pdf->AddPage(); }

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetFillColor(26, 58, 92);
        $pdf->SetTextColor(255, 255, 255);

        $labelW = $widths[0]+$widths[1]+$widths[2]+$widths[3]+$widths[4]+$widths[5]
                + $widths[6]+$widths[7]+$widths[8]+$widths[9]+$widths[10]+$widths[11]+$widths[12];
        $pdf->Cell($labelW,           $totH, 'GRAND TOTAL',    1, 0, 'R', true);
        $pdf->Cell($widths[13],       $totH, (string)$totQty,  1, 0, 'R', true);
        $pdf->Cell($widths[14],       $totH, (string)$totIns,  1, 0, 'R', true);
        $pdf->Cell($widths[15],       $totH, (string)$totDef,  1, 0, 'R', true);
        $pdf->Cell($widths[16]+$widths[17], $totH, '', 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
    }

    $filename = 'TRIMS_Report_' . $from . '_to_' . $to . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Module 3 – Reports</title>
<style>
.date-row { overflow:hidden; margin-bottom:14px; }
.date-row label { font-size:.85rem; font-weight:600; color:#555; margin-right:5px; }
.date-row input[type=date] {
    padding:7px 10px; border:1px solid #c8d0da; border-radius:5px;
    font-size:.88rem; margin-right:12px;
}
.tbl-container { overflow-x:auto; }
#reportTable { min-width:1700px; border-collapse:collapse; font-size:.82rem; width:100%; }
#reportTable th {
    background:#1a3a5c; color:#fff; padding:9px 8px;
    text-align:center; white-space:nowrap; border:1px solid #c8d0da;
}
#reportTable td {
    padding:7px 8px; border:1px solid #eef1f4; vertical-align:top;
    word-break:break-word; max-width:200px;
}
#reportTable tbody tr:nth-child(even) { background:#f5f9fc; }
#reportTable tbody tr:hover           { background:#eaf4ff; }
.td-num { text-align:right; }
.td-ctr { text-align:center; }
.badge-pass { background:#e8f5e9; color:#2e7d32; padding:2px 8px; border-radius:10px; font-weight:700; font-size:.75rem; }
.badge-fail { background:#ffebee; color:#c62828; padding:2px 8px; border-radius:10px; font-weight:700; font-size:.75rem; }
.badge-hold { background:#fff8e1; color:#f57f17; padding:2px 8px; border-radius:10px; font-weight:700; font-size:.75rem; }
.badge-repl { background:#e8eaf6; color:#283593; padding:2px 8px; border-radius:10px; font-weight:700; font-size:.75rem; }
.summary-bar { overflow:hidden; margin-bottom:10px; font-size:.82rem; color:#555; }
.summary-bar span { margin-right:18px; display:inline-block; }
.summary-bar strong { color:#1a3a5c; }

/* ── Mobile responsive ── */
@media (max-width: 768px){
    .date-row { display:flex; flex-wrap:wrap; gap:8px; }
    .date-row label { width:100%; margin:0; }
    .date-row input[type=date]{
        flex:1 1 140px;
        margin-right:0;
        width:100%;
        min-width:0;
    }
    .date-row .btn{
        flex:1 1 100%;
        margin-left:0 !important;
        margin-top:4px;
    }
    .summary-bar span { margin-right:12px; margin-bottom:4px; }
    #reportTable { font-size:.76rem; min-width:1180px; }
    #reportTable th { padding:8px 6px; }
    #reportTable td { padding:6px 6px; }
}
</style>
</head>
<body>

<!-- Filter Card -->
<div class="card">
    <div class="card-title">Module 3 &mdash; TRIMS INSPECTION REPORT</div>
    <div class="date-row">
        <label>Date From:</label>
        <input type="date" id="dateFrom">
        <label>To:</label>
        <input type="date" id="dateTo">
        <button class="btn btn-primary" onclick="loadReport()">&#9654; Generate Report</button>
        <button class="btn btn-secondary" style="margin-left:6px;" onclick="exportPDF()">&#8659; Export PDF</button>
        <button class="btn btn-secondary" style="margin-left:6px;background:#2e7d32;color:#fff;border-color:#2e7d32;" onclick="exportExcel()">&#8659; Export Excel</button>
    </div>
    <div class="summary-bar" id="summaryBar"></div>
</div>

<!-- Report Table Card -->
<div class="card">
    <div class="card-title">Report Result</div>
    <div class="tbl-container">
        <table id="reportTable">
            <thead>
                <tr>
                    <th>MONTH</th><th>WEEK</th><th>DATE</th>
                    <th>SUPPLIER NAME</th><th>IO NUM</th><th>PO NUM</th>
                    <th>GR NUM</th><th>VESSEL</th><th>VOYAGE</th><th>CONTAINER #</th><th>HBL</th>
                    <th>BRAND</th><th>TYPE OF TRIMS</th><th>TTL QTY</th>
                    <th>QTY INSPECTED</th><th>QTY DEFECTS</th><th>TYPE OF DEFECTS</th><th>RESULT</th>
                </tr>
            </thead>
            <tbody id="reportBody">
                <tr><td colspan="18" style="text-align:center;color:#999;padding:20px;">
                    Select a date range and click Generate Report.
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
var BASE3 = 'module3.php';

function esc(v) {
    if (v === null || v === undefined) { return ''; }
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function ajax3(url, cb) {
    var sep = url.indexOf('?') !== -1 ? '&' : '?';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url + sep + '_=' + Date.now(), true);
    xhr.setRequestHeader('Cache-Control', 'no-cache, no-store');
    xhr.setRequestHeader('Pragma', 'no-cache');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            try   { cb(null, JSON.parse(xhr.responseText)); }
            catch (e) { cb('Invalid response', null); }
        }
    };
    xhr.send();
}

function resultBadge(res) {
    res = esc(res || '');
    if (res === 'PASSED')      { return '<span class="badge-pass">PASSED</span>'; }
    if (res === 'FAILED')      { return '<span class="badge-fail">FAILED</span>'; }
    if (res === 'HOLD')        { return '<span class="badge-hold">HOLD</span>'; }
    if (res === 'REPLACEMENT') { return '<span class="badge-repl">REPLACEMENT</span>'; }
    return res;
}

function loadReport() {
    var from = document.getElementById('dateFrom').value;
    var to   = document.getElementById('dateTo').value;
    if (!from || !to) { alert('Please select both date From and To.'); return; }
    if (from > to)    { alert('Date From cannot be later than Date To.'); return; }

    document.getElementById('reportBody').innerHTML =
        '<tr><td colspan="18" style="text-align:center;color:#999;padding:20px;">Loading&#8230;</td></tr>';
    document.getElementById('summaryBar').innerHTML = '';

    ajax3(
        BASE3 + '?ajax=load_report&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to),
        function(err, rows) {
            if (err || !rows) {
                document.getElementById('reportBody').innerHTML =
                    '<tr><td colspan="18" style="color:#c62828;text-align:center;">Failed to load report.</td></tr>';
                return;
            }
            if (rows.error) {
                document.getElementById('reportBody').innerHTML =
                    '<tr><td colspan="18" style="color:#c62828;text-align:center;">' + esc(rows.error) + '</td></tr>';
                return;
            }
            renderRows(rows);
        }
    );
}

function renderRows(rows) {
    if (!rows || rows.length === 0) {
        document.getElementById('reportBody').innerHTML =
            '<tr><td colspan="18" style="text-align:center;color:#999;padding:20px;">No records found.</td></tr>';
        document.getElementById('summaryBar').innerHTML = '';
        return;
    }

    var html = '';
    var totQty = 0, totIns = 0, totDef = 0, pass = 0, fail = 0, hold = 0, repl = 0;

    for (var i = 0; i < rows.length; i++) {
        var r   = rows[i];
        var res = r['RESULT'] || '';
        totQty += parseInt(r['TTL QTY']       || 0, 10);
        totIns += parseInt(r['QTY INSPECTED'] || 0, 10);
        totDef += parseInt(r['QTY DEFECTS']   || 0, 10);
        if (res === 'PASSED')      { pass++; }
        else if (res === 'FAILED') { fail++; }
        else if (res === 'HOLD')   { hold++; }
        else if (res === 'REPLACEMENT') { repl++; }

        html += '<tr>' +
            '<td class="td-ctr">' + esc(r['MONTH'])           + '</td>' +
            '<td class="td-ctr">' + esc(r['WEEK'])            + '</td>' +
            '<td class="td-ctr">' + esc(r['DATE'])            + '</td>' +
            '<td>'                + esc(r['SUPPLIER NAME'])   + '</td>' +
            '<td class="td-ctr">' + esc(r['IO NUM'])          + '</td>' +
            '<td class="td-ctr">' + esc(r['PO NUM'])          + '</td>' +
            '<td class="td-ctr">' + esc(r['GR NUM'])          + '</td>' +
            '<td>'                + esc(r['VESSEL'])          + '</td>' +
            '<td class="td-ctr">' + esc(r['VOYAGE'])          + '</td>' +
            '<td>'                + esc(r['CONTAINER_NUM'])     + '</td>' +
            '<td class="td-ctr">' + esc(r['HBL'])               + '</td>' +
            '<td>'                + esc(r['BRAND'])           + '</td>' +
            '<td>'                + esc(r['TYPE OF TRIMS'])   + '</td>' +
            '<td class="td-num">' + esc(r['TTL QTY'])         + '</td>' +
            '<td class="td-num">' + esc(r['QTY INSPECTED'])   + '</td>' +
            '<td class="td-num">' + esc(r['QTY DEFECTS'])     + '</td>' +
            '<td>'                + esc(r['TYPE OF DEFECTS']) + '</td>' +
            '<td class="td-ctr">' + resultBadge(res)          + '</td>' +
            '</tr>';
    }

    html += '<tr style="background:#1a3a5c;color:#fff;font-weight:700;">' +
        '<td colspan="13" style="text-align:right;padding:7px 8px;">GRAND TOTAL</td>' +
        '<td class="td-num" style="padding:7px 8px;">' + totQty + '</td>' +
        '<td class="td-num" style="padding:7px 8px;">' + totIns + '</td>' +
        '<td class="td-num" style="padding:7px 8px;">' + totDef + '</td>' +
        '<td colspan="2"></td></tr>';

    document.getElementById('reportBody').innerHTML = html;

    var bar = '<span>Records: <strong>' + rows.length + '</strong></span>' +
        '<span>Total Qty: <strong>' + totQty + '</strong></span>' +
        '<span>Qty Inspected: <strong>' + totIns + '</strong></span>' +
        '<span>Qty Defects: <strong>' + totDef + '</strong></span>' +
        '<span style="color:#2e7d32;">Passed: <strong>' + pass + '</strong></span>' +
        '<span style="color:#c62828;">Failed: <strong>' + fail + '</strong></span>';
    if (hold > 0) { bar += '<span style="color:#f57f17;">Hold: <strong>' + hold + '</strong></span>'; }
    if (repl > 0) { bar += '<span style="color:#283593;">Replacement: <strong>' + repl + '</strong></span>'; }
    document.getElementById('summaryBar').innerHTML = bar;
}

function exportPDF() {
    var from = document.getElementById('dateFrom').value;
    var to   = document.getElementById('dateTo').value;
    if (!from || !to) { alert('Please select both date From and To.'); return; }
    window.open(
        BASE3 + '?ajax=export_pdf&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to),
        '_blank'
    );
}

function exportExcel() {
    var from = document.getElementById('dateFrom').value;
    var to   = document.getElementById('dateTo').value;
    if (!from || !to) { alert('Please select both date From and To.'); return; }
    window.location.href =
        BASE3 + '?ajax=export_excel&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
}
</script>
</body>
</html>