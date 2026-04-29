<?php
// ─────────────────────────────────────────────
//  Module 5 – Performance Monitoring Summary
//  PHP 5.3 | mssql native
// ─────────────────────────────────────────────
require_once dirname(__FILE__) . '/config.php';

// ── Helper: trim rows (category=1, exclude SURCHARGE) ──
function getTrimRows() {
    $rows = dbQuery(
        "SELECT id, description FROM TRIMS_TBL_DROPDOWN
         WHERE category = 1 AND description <> 'SURCHARGE'
         ORDER BY description",
        array()
    );
    if (isset($rows['__error'])) { return array(); }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('id' => (int)$r['id'], 'description' => $r['description']);
    }
    return $out;
}

// ── Helper: defect columns (category=2, exclude NONE) ──
function getDefectCols() {
    $rows = dbQuery(
        "SELECT id, description FROM TRIMS_TBL_DROPDOWN
         WHERE category = 2 AND description <> 'NONE'
         ORDER BY description",
        array()
    );
    if (isset($rows['__error'])) { return array(); }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('id' => (int)$r['id'], 'description' => $r['description']);
    }
    return $out;
}

// ── Shared pivot builder ──────────────────────────────────
function buildPivot($from, $to, $supplier, $brand, $io) {
    $trimDefs   = getTrimRows();
    $defectDefs = getDefectCols();
    if (empty($trimDefs) || empty($defectDefs)) {
        return array('error' => 'No trim/defect types found in TRIMS_TBL_DROPDOWN.');
    }

    $trimById   = array();
    foreach ($trimDefs   as $t) { $trimById[$t['id']]   = $t['description']; }
    $defectById = array();
    foreach ($defectDefs as $d) { $defectById[$d['id']] = $d['description']; }

    $params = array($from, $to);
    $where  = "WHERE a.Inspection_Date >= ? AND a.Inspection_Date < DATEADD(DAY,1,?)";
    if ($supplier !== '' && $supplier !== 'ALL') {
        $where   .= " AND a.Vendor_Name = ?";
        $params[] = $supplier;
    }
    if ($brand !== '' && $brand !== 'ALL') {
        $where   .= " AND a.Custome_Name = ?";
        $params[] = $brand;
    }
    if ($io !== '') {
        $where   .= " AND a.IO_num = ?";
        $params[] = $io;
    }

    $sql = "
        SELECT a.System_Trim_Type AS trim_id,
               a.Defect_Type      AS defect_id,
               SUM(a.Total_Qty)       AS ttl_qty,
               SUM(a.Qty_Inspected)   AS qty_inspected,
               SUM(a.Qty_Defects)     AS qty_defects
        FROM TRIMS_TBL_INSPECTION a
        $where
        GROUP BY a.System_Trim_Type, a.Defect_Type
    ";
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error'])) { return array('error' => $rows['__error']); }

    // Init pivot
    $pivot = array();
    foreach ($trimDefs as $t) {
        $d = $t['description'];
        $pivot[$d] = array('ttl_qty' => 0, 'qty_inspected' => 0, 'qty_defects' => 0);
        foreach ($defectDefs as $def) { $pivot[$d][$def['description']] = 0; }
    }

    foreach ($rows as $r) {
        $trimId   = (int)$r['trim_id'];
        $defectId = (int)$r['defect_id'];
        if (!isset($trimById[$trimId])) { continue; }
        $trimDesc = $trimById[$trimId];

        $pivot[$trimDesc]['ttl_qty']       += (int)$r['ttl_qty'];
        $pivot[$trimDesc]['qty_inspected'] += (int)$r['qty_inspected'];
        $pivot[$trimDesc]['qty_defects']   += (int)$r['qty_defects'];

        if (isset($defectById[$defectId])) {
            $dd = $defectById[$defectId];
            if (isset($pivot[$trimDesc][$dd])) {
                $pivot[$trimDesc][$dd] += (int)$r['qty_defects'];
            }
        }
    }

    $defectLabels = array();
    foreach ($defectDefs as $d) { $defectLabels[] = $d['description']; }

    $result = array();
    foreach ($trimDefs as $t) {
        $desc = $t['description'];
        $row  = $pivot[$desc];
        $pct  = ($row['qty_inspected'] > 0)
            ? round($row['qty_defects'] / $row['qty_inspected'] * 100, 1)
            : null;
        $out = array(
            'trim'          => $desc,
            'ttl_qty'       => $row['ttl_qty'],
            'qty_inspected' => $row['qty_inspected'],
            'qty_defects'   => $row['qty_defects'],
            'pct'           => $pct,
        );
        foreach ($defectLabels as $dl) { $out[$dl] = isset($row[$dl]) ? $row[$dl] : 0; }
        $result[] = $out;
    }

    return array('rows' => $result, 'defect_cols' => $defectLabels, 'trim_defs' => $trimDefs, 'defect_defs' => $defectDefs);
}

// ═══════════════════════════════════════════
// AJAX: get_suppliers
// ═══════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_suppliers') {
    header('Content-Type: application/json');
    $rows = dbQuery("SELECT DISTINCT Vendor_Name FROM TRIMS_TBL_RAWDATA ORDER BY Vendor_Name", array());
    if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
    $list = array();
    foreach ($rows as $r) { $list[] = $r['Vendor_Name']; }
    echo json_encode($list);
    exit;
}

// ═══════════════════════════════════════════
// AJAX: get_brands (category=3)
// ═══════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_brands') {
    header('Content-Type: application/json');
    $rows = dbQuery(
        "SELECT id, description FROM TRIMS_TBL_DROPDOWN WHERE category = 3 ORDER BY description",
        array()
    );
    if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
    $list = array();
    foreach ($rows as $r) { $list[] = $r['description']; }
    echo json_encode($list);
    exit;
}

// ═══════════════════════════════════════════
// AJAX: load_summary → JSON
// ═══════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_summary') {
    header('Content-Type: application/json');
    $from     = isset($_GET['from'])     ? trim($_GET['from'])     : '';
    $to       = isset($_GET['to'])       ? trim($_GET['to'])       : '';
    $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $brand    = isset($_GET['brand'])    ? trim($_GET['brand'])    : '';
    $io       = isset($_GET['io'])       ? trim($_GET['io'])       : '';
    if ($from === '' || $to === '') { echo json_encode(array('error' => 'Date range is required.')); exit; }

    $data = buildPivot($from, $to, $supplier, $brand, $io);
    if (isset($data['error'])) { echo json_encode(array('error' => $data['error'])); exit; }
    echo json_encode(array('rows' => $data['rows'], 'defect_cols' => $data['defect_cols']));
    exit;
}

// ═══════════════════════════════════════════
// AJAX: export_pdf → download PDF (FPDF)
// ═══════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'export_pdf') {

    $fpdfPath = dirname(__FILE__) . '/Library/fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) { die('FPDF library not found.'); }
    require_once $fpdfPath;

    $from     = isset($_GET['from'])     ? trim($_GET['from'])     : '';
    $to       = isset($_GET['to'])       ? trim($_GET['to'])       : '';
    $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $brand    = isset($_GET['brand'])    ? trim($_GET['brand'])    : '';
    $io       = isset($_GET['io'])       ? trim($_GET['io'])       : '';
    if ($from === '' || $to === '') { die('Date range is required'); }

    $data = buildPivot($from, $to, $supplier, $brand, $io);
    if (isset($data['error'])) { die($data['error']); }

    $rows         = $data['rows'];
    $defectLabels = $data['defect_cols'];
    $supLabel     = ($supplier === '' || $supplier === 'ALL') ? 'All Suppliers' : $supplier;
    $brandLabel   = ($brand    === '' || $brand    === 'ALL') ? ''              : $brand;

    // ── Column widths ──────────────────────────────────────────────────────
    // A4 Landscape = 297mm, margins 8mm each → 281mm usable
    // Fixed cols: TRIMS(35) + TTL QTY(14) + QTY INS(16) + QTY DEF(16) + %(12) = 93mm
    // Remaining 188mm split across defect columns
    $fixedW   = 93;
    $usableW  = 281;
    $defCount = count($defectLabels);
    $defW     = ($defCount > 0) ? (int)(($usableW - $fixedW) / $defCount) : 20;
    // Cap so very few defect cols don't get absurdly wide
    if ($defW > 30) { $defW = 30; }

    $colWidths = array(35, 14, 16, 16, 12);   // TRIMS, TTL QTY, QTY INS, QTY DEF, %
    for ($i = 0; $i < $defCount; $i++) { $colWidths[] = $defW; }

    $colAligns = array('L','R','R','R','R');
    for ($i = 0; $i < $defCount; $i++) { $colAligns[] = 'R'; }

    // ── FPDF class ─────────────────────────────────────────────────────────
    class SummaryPDF extends FPDF {
        public $colWidths   = array();
        public $colAligns   = array();
        public $dateFrom    = '';
        public $dateTo      = '';
        public $supplier    = '';
        public $brand       = '';
        public $defectLabels = array();
        public $lineH       = 4;

        function Header() {
			
			
		// ===== TOP LABEL (keep exactly like your screenshot) =====
		
			$this->SetTextColor(0,0,0);
			$this->SetFont('Arial', 'B', 7);
			$this->Cell(0, 6, 'TRIMS PERFORMANCE MONITORING SUMMARY', 0, 1, 'C');

			$this->SetFont('Arial', '', 7);
			$subLine = 'Period: ' . $this->dateFrom . '  to  ' . $this->dateTo;
			if ($this->supplier !== '' && $this->supplier !== 'All Suppliers') {
				$subLine .= '   Supplier: ' . $this->supplier;
			}
			if (isset($this->brand) && $this->brand !== '') {
				$subLine .= '   Brand: ' . $this->brand;
			}
			$this->Cell(0, 3, $subLine, 0, 1, 'C');
			$this->Ln(1);
		

			
			
            // ── Wrapped two-tier header ───────────────────────────────
			$this->SetFont('Arial', 'B', 6);
			$this->SetLineWidth(0.2);

			$defs = $this->defectLabels;
			$defCount = count($defs);

			// ---- Measure wrapped heights ----
			$lineH = 4;
			$maxGrpLines = 1;
			$maxSubLines = 1;

			// TRIMS + stat headers
			$grpLabels = array('TRIMS','TTL QTY','QTY INS','QTY DEF','%');
			for ($i=0; $i<count($grpLabels); $i++) {
				$lines = $this->wrapText($grpLabels[$i], $this->colWidths[$i]-1.5);
				$maxGrpLines = max($maxGrpLines, count($lines));
			}

			// TYPE OF DEFECTS (group)
			$defSpan = 0;
			for ($i=5; $i<count($this->colWidths); $i++) {
				$defSpan += $this->colWidths[$i];
			}
			$defGrpLines = $this->wrapText('TYPE OF DEFECTS', $defSpan-1.5);
			$maxGrpLines = max($maxGrpLines, count($defGrpLines));

			// Defect sub headers
			for ($i=0; $i<$defCount; $i++) {
				$lines = $this->wrapText($defs[$i], $this->colWidths[$i+5]-1.5);
				$maxSubLines = max($maxSubLines, count($lines));
			}

			// Heights
			$hdrH1 = $lineH * $maxGrpLines + 1;
			$hdrH2 = $lineH * $maxSubLines + 1;
			$hdrHTot = $hdrH1 + $hdrH2;

			$x0 = $this->lMargin;
			$y0 = $this->GetY();

			// ---- TRIMS (rowspan 2) ----
			$this->SetFillColor(26,58,92);
			$this->SetTextColor(255,255,255);
			$this->Rect($x0,$y0,$this->colWidths[0],$hdrHTot,'DF');
			$lines = $this->wrapText('TRIMS',$this->colWidths[0]-1.5);
			for ($i=0;$i<count($lines);$i++){
				$this->SetXY($x0+0.8,$y0+0.8+$i*$lineH);
				$this->Cell($this->colWidths[0]-1.6,$lineH,$lines[$i],0,0,'C');
			}

			// ---- Stat headers ----
			$cx = $x0 + $this->colWidths[0];
			for ($i=0;$i<4;$i++){
				$this->Rect($cx,$y0,$this->colWidths[$i+1],$hdrHTot,'DF');
				$lines=$this->wrapText($grpLabels[$i+1],$this->colWidths[$i+1]-1.5);
				for($l=0;$l<count($lines);$l++){
					$this->SetXY($cx+0.8,$y0+0.8+$l*$lineH);
					$this->Cell($this->colWidths[$i+1]-1.6,$lineH,$lines[$l],0,0,'C');
				}
				$cx+=$this->colWidths[$i+1];
			}

			// ---- TYPE OF DEFECTS (group header) ----
			if ($defCount>0){
				$this->SetFillColor(74,35,90);
				$this->Rect($cx,$y0,$defSpan,$hdrH1,'DF');
				$lines=$this->wrapText('TYPE OF DEFECTS',$defSpan-1.5);
				for($l=0;$l<count($lines);$l++){
					$this->SetXY($cx+1,$y0+0.8+$l*$lineH);
					$this->Cell($defSpan-2,$lineH,$lines[$l],0,0,'C');
				}

				// ---- defect sub headers ----
				$this->SetFillColor(106,58,122);
				$dcx=$cx;
				for($i=0;$i<$defCount;$i++){
					$this->Rect($dcx,$y0+$hdrH1,$this->colWidths[$i+5],$hdrH2,'DF');
					$lines=$this->wrapText($defs[$i],$this->colWidths[$i+5]-1.5);
					for($l=0;$l<count($lines);$l++){
						$this->SetXY($dcx+0.8,$y0+$hdrH1+0.8+$l*$lineH);
						$this->Cell($this->colWidths[$i+5]-1.6,$lineH,$lines[$l],0,0,'C');
					}
					$dcx += $this->colWidths[$i+5];
				}
			}

			$this->SetXY($this->lMargin,$y0+$hdrHTot);
			$this->SetTextColor(0,0,0);
        }

        function Footer() {
            $this->SetY(-10);
            $this->SetFont('Arial', 'I', 6.5);
            $this->SetTextColor(130,130,130);
            $this->Cell(0, 5,
                'Generated: ' . date('Y-m-d H:i') . '   |   Page ' . $this->PageNo() . '/{nb}',
                0, 0, 'C');
        }

        // Draw one data row — wraps text, all cells same height
        function DataRow($data, $fill) {
            $widths = $this->colWidths;
            $aligns = $this->colAligns;
            $lh     = $this->lineH;

            $this->SetFont('Arial', '', 6.5);

            // Pre-wrap all cells
            $cellLines = array();
            $maxLines  = 1;
            for ($i = 0; $i < count($data); $i++) {
                $lines = $this->wrapText((string)$data[$i], $widths[$i] - 1.5);
                $cellLines[$i] = $lines;
                if (count($lines) > $maxLines) { $maxLines = count($lines); }
            }
            $rowH = $maxLines * $lh + 1.5;

            if ($this->GetY() + $rowH > $this->GetPageHeight() - 14) { $this->AddPage(); }

            $rowY  = $this->GetY();
            $startX = $this->lMargin;

            for ($i = 0; $i < count($data); $i++) {
                $cellX = $startX;
                for ($j = 0; $j < $i; $j++) { $cellX += $widths[$j]; }

                if ($fill) { $this->SetFillColor(245,249,252); }
                else       { $this->SetFillColor(255,255,255); }

                $this->Rect($cellX, $rowY, $widths[$i], $rowH, $fill ? 'DF' : 'D');

                $align = isset($aligns[$i]) ? $aligns[$i] : 'L';
                $lines = $cellLines[$i];
                for ($ln = 0; $ln < count($lines); $ln++) {
                    $this->SetXY($cellX + 0.8, $rowY + 0.8 + $ln * $lh);
                    $this->Cell($widths[$i] - 1.6, $lh, $lines[$ln], 0, 0, $align);
                }
            }
            $this->SetXY($this->lMargin, $rowY + $rowH);
        }

        // Public getter so external code can read the protected left margin
        function getLeftMargin() { return $this->lMargin; }

        // Word-wrap helper
        function wrapText($text, $maxW) {
            $text = trim($text);
            if ($text === '') { return array(''); }
            $words = explode(' ', $text);
            $lines = array(); $line = '';
            foreach ($words as $word) {
                $test = ($line === '') ? $word : $line . ' ' . $word;
                if ($this->GetStringWidth($test) <= $maxW) {
                    $line = $test;
                } else {
                    if ($line !== '') { $lines[] = $line; }
                    if ($this->GetStringWidth($word) > $maxW) {
                        $part = '';
                        for ($ci = 0; $ci < strlen($word); $ci++) {
                            $try = $part . $word[$ci];
                            if ($this->GetStringWidth($try) > $maxW) { $lines[] = $part; $part = $word[$ci]; }
                            else { $part = $try; }
                        }
                        $line = $part;
                    } else { $line = $word; }
                }
            }
            if ($line !== '') { $lines[] = $line; }
            return $lines;
        }
    }

    // ── Build PDF ──────────────────────────────────────────────────────────
    $pdf = new SummaryPDF('L', 'mm', 'A4');
    $pdf->colWidths    = $colWidths;
    $pdf->colAligns    = $colAligns;
    $pdf->dateFrom     = $from;
    $pdf->dateTo       = $to;
    $pdf->supplier     = $supLabel;
    $pdf->brand        = $brandLabel;
    $pdf->defectLabels = $defectLabels;
    $pdf->AliasNbPages();
    $pdf->SetMargins(8, 18, 8);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 6.5);

    // Totals accumulators
    $totQty = 0; $totIns = 0; $totDef = 0;
    $totDefects = array();
    foreach ($defectLabels as $dl) { $totDefects[$dl] = 0; }

    $fill = false;
    foreach ($rows as $r) {
        $rowData = array(
            $r['trim'],
            $r['ttl_qty']       > 0 ? $r['ttl_qty']       : '0',
            $r['qty_inspected'] > 0 ? $r['qty_inspected'] : '0',
            $r['qty_defects']   > 0 ? $r['qty_defects']   : '0',
            $r['pct'] !== null ? $r['pct'] . '%' : '-',
        );
        foreach ($defectLabels as $dl) {
            $cnt = isset($r[$dl]) ? (int)$r[$dl] : 0;
            $rowData[] = $cnt > 0 ? (string)$cnt : '0';
            $totDefects[$dl] += $cnt;
        }

        $totQty += $r['ttl_qty'];
        $totIns += $r['qty_inspected'];
        $totDef += $r['qty_defects'];

        $pdf->DataRow($rowData, $fill);
        $fill = !$fill;
    }

    // ── Totals row ─────────────────────────────────────────────────────────
    $totH  = 5.5;
    if ($pdf->GetY() + $totH > $pdf->GetPageHeight() - 14) { $pdf->AddPage(); }
    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetFillColor(26, 58, 92);
    $pdf->SetTextColor(255, 255, 255);

    $totPct = $totIns > 0 ? round($totDef / $totIns * 100, 1) . '%' : '-';
    $totData = array('TOTAL', (string)$totQty, (string)$totIns, (string)$totDef, $totPct);
    foreach ($defectLabels as $dl) { $totData[] = (string)($totDefects[$dl] ?: '0'); }

    $cx = $pdf->getLeftMargin(); $ty = $pdf->GetY();
    foreach ($totData as $ti => $tv) {
        $w = $colWidths[$ti];
        $pdf->Rect($cx, $ty, $w, $totH, 'DF');
        $pdf->SetXY($cx + 0.8, $ty + ($totH - 3.5) / 2);
        $align = ($ti === 0) ? 'R' : (isset($colAligns[$ti]) ? $colAligns[$ti] : 'R');
        $pdf->Cell($w - 1.6, 3.5, $tv, 0, 0, $align);
        $cx += $w;
    }
    $pdf->SetTextColor(0, 0, 0);

    $filename = 'TRIMS_Performance_Summary_' . $from . '_to_' . $to . '.pdf';
    $pdf->Output('D', $filename);
    exit;
}
?>
<style>
    .filter-row { overflow:hidden; margin-bottom:14px; }
    .filter-row label { font-size:.83rem; font-weight:600; color:#555; margin-right:5px; }
    .filter-row input[type=date], .filter-row select {
        padding:7px 10px; border:1px solid #c8d0da; border-radius:5px;
        font-size:.88rem; margin-right:12px; background:#fff;
    }
    .filter-row select { min-width:220px; }
    .sum-scroll { overflow-x:auto; }
    #sumTable { border-collapse:collapse; font-size:.78rem; min-width:900px; }
    #sumTable thead tr.hdr-group th {
        background:#1a3a5c; color:#fff; text-align:center;
        padding:5px 6px; border:1px solid #2c4f70; white-space:nowrap;
    }
    #sumTable thead tr.hdr-group th.grp-defects { background:#4a235a; }
    #sumTable thead tr.hdr-sub th {
        background:#21304a; color:#cfd8dc; text-align:center;
        padding:4px 5px; border:1px solid #2c4f70; white-space:nowrap; font-size:.72rem;
    }
    #sumTable thead tr.hdr-sub th.grp-defects { background:#6a3a7a; }
    #sumTable tbody td {
        padding:5px 7px; border:1px solid #dde3ea;
        text-align:center; white-space:nowrap;
    }
    #sumTable tbody td:first-child { text-align:left; font-weight:600; color:#1a3a5c; min-width:130px; }
    #sumTable tbody tr:nth-child(even) { background:#f7f9fb; }
    #sumTable tbody tr:hover           { background:#eaf4ff; }
    .zero { color:#ccc; }
    .pct-ok   { color:#2e7d32; font-weight:700; }
    .pct-warn { color:#e65100; font-weight:700; }
    .pct-bad  { color:#c62828; font-weight:700; }
    #sumTable tfoot td {
        background:#1a3a5c; color:#fff; font-weight:700;
        padding:6px 7px; border:1px solid #2c4f70; text-align:center;
    }
    #sumTable tfoot td:first-child { text-align:right; }
    .info-bar { font-size:.82rem; color:#555; margin-bottom:10px; overflow:hidden; }
    .info-bar span { margin-right:16px; }
    .info-bar strong { color:#1a3a5c; }
</style>

<!-- Filter Card -->
<div class="card">
    <div class="card-title">Module 5 &mdash; TRIMS PERFORMANCE MONITORING SUMMARY</div>
    <div class="filter-row">
        <label>Date From:</label>
        <input type="date" id="m5From">
        <label>To:</label>
        <input type="date" id="m5To">
        <label>Supplier:</label>
        <select id="m5Supplier">
            <option value="ALL">-- All Suppliers --</option>
        </select>
        <label>Brand:</label>
        <select id="m5Brand">
            <option value="ALL">-- All Brands --</option>
        </select>
        <button class="btn btn-primary" onclick="loadSummary()">&#9654; Generate</button>
        <button class="btn btn-secondary" style="margin-left:6px;" onclick="exportPDF5()">&#8659; Export PDF</button>
    </div>
    <div class="info-bar" id="m5Info"></div>
</div>

<!-- Summary Table Card -->
<div class="card">
    <div class="card-title" id="m5TableTitle">Summary Report</div>
    <div class="sum-scroll">
        <table id="sumTable">
            <thead>
                <tr class="hdr-group">
                    <th rowspan="2" style="min-width:130px;">TRIMS</th>
                    <th rowspan="2">TTL QTY</th>
                    <th rowspan="2">QTY<br>INSPECTED</th>
                    <th rowspan="2">QTY<br>DEFECTS</th>
                    <th rowspan="2">%</th>
                    <th id="defectGroupHdr" colspan="9" class="grp-defects">TYPE OF DEFECTS</th>
                </tr>
                <tr class="hdr-sub" id="defectSubHdr"></tr>
            </thead>
            <tbody id="sumBody">
                <tr><td colspan="14" style="text-align:center;color:#999;padding:20px;">
                    Select filters and click Generate.
                </td></tr>
            </tbody>
            <tfoot id="sumFoot"></tfoot>
        </table>
    </div>
</div>

<script>
var BASE5      = 'module5.php';
var defectCols = [];

// Load suppliers and brands on init
(function() {
    function loadDropdown(url, selId) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                try {
                    var list = JSON.parse(xhr.responseText);
                    var sel  = document.getElementById(selId);
                    for (var i = 0; i < list.length; i++) {
                        var opt = document.createElement('option');
                        opt.value = list[i]; opt.textContent = list[i];
                        sel.appendChild(opt);
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }
    loadDropdown(BASE5 + '?ajax=get_suppliers', 'm5Supplier');
    loadDropdown(BASE5 + '?ajax=get_brands',    'm5Brand');
})();

function esc(v) {
    if (v === null || v === undefined) { return ''; }
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function getFilters() {
    return {
        from:     document.getElementById('m5From').value,
        to:       document.getElementById('m5To').value,
        supplier: document.getElementById('m5Supplier').value,
        brand:    document.getElementById('m5Brand').value
    };
}

function loadSummary() {
    var f = getFilters();
    if (!f.from || !f.to) { alert('Please select both Date From and To.'); return; }
    if (f.from > f.to)    { alert('Date From cannot be later than Date To.'); return; }

    var totalCols = 5 + defectCols.length;
    document.getElementById('sumBody').innerHTML =
        '<tr><td colspan="' + (totalCols + 1) + '" style="text-align:center;color:#999;padding:20px;">Loading&#8230;</td></tr>';
    document.getElementById('sumFoot').innerHTML = '';
    document.getElementById('m5Info').innerHTML  = '';

    var url = BASE5 + '?ajax=load_summary' +
              '&from='     + encodeURIComponent(f.from)     +
              '&to='       + encodeURIComponent(f.to)       +
              '&supplier=' + encodeURIComponent(f.supplier) +
              '&brand='    + encodeURIComponent(f.brand);

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        var data;
        try { data = JSON.parse(xhr.responseText); }
        catch(e) {
            document.getElementById('sumBody').innerHTML =
                '<tr><td colspan="14" style="color:#c62828;text-align:center;">Failed to parse response.</td></tr>';
            return;
        }
        if (data.error) {
            document.getElementById('sumBody').innerHTML =
                '<tr><td colspan="14" style="color:#c62828;text-align:center;">' + esc(data.error) + '</td></tr>';
            return;
        }
        defectCols = data.defect_cols;
        buildSubHeader(defectCols);
        renderSummary(data.rows, f.from, f.to, f.supplier, f.brand);
    };
    xhr.send();
}

function buildSubHeader(cols) {
    var grpHdr = document.getElementById('defectGroupHdr');
    if (grpHdr) { grpHdr.setAttribute('colspan', cols.length || 1); }
    var html = '';
    for (var i = 0; i < cols.length; i++) {
        html += '<th class="grp-defects">' + esc(cols[i]) + '</th>';
    }
    document.getElementById('defectSubHdr').innerHTML = html;
}

function renderSummary(rows, from, to, supplier, brand) {
    var supLabel   = (supplier === 'ALL' || supplier === '') ? 'All Suppliers' : supplier;
    var brandLabel = (brand    === 'ALL' || brand    === '') ? ''             : brand;
    var titleStr   = 'Summary &mdash; ' + esc(supLabel) + ' &nbsp;|&nbsp; ' + esc(from) + ' to ' + esc(to);
    if (brandLabel) { titleStr += ' &nbsp;|&nbsp; Brand: ' + esc(brandLabel); }
    document.getElementById('m5TableTitle').innerHTML = titleStr;

    var hasData = false;
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].ttl_qty > 0 || rows[i].qty_defects > 0) { hasData = true; break; }
    }
    if (!hasData) {
        document.getElementById('sumBody').innerHTML =
            '<tr><td colspan="' + (5 + defectCols.length + 1) + '" style="text-align:center;color:#999;padding:20px;">' +
            'No records found for the selected filters.</td></tr>';
        document.getElementById('sumFoot').innerHTML = '';
        document.getElementById('m5Info').innerHTML  = '';
        return;
    }

    var html = '';
    var totQty = 0, totIns = 0, totDef = 0;
    var totDefects = {};
    for (var d = 0; d < defectCols.length; d++) { totDefects[defectCols[d]] = 0; }

    for (var i = 0; i < rows.length; i++) {
        var r   = rows[i];
        var qty = r.ttl_qty, ins = r.qty_inspected, def = r.qty_defects, pct = r.pct;
        totQty += qty; totIns += ins; totDef += def;

        var pctHtml = '<span class="zero">-</span>';
        if (pct !== null) {
            var cls = pct === 0 ? 'pct-ok' : (pct <= 5 ? 'pct-warn' : 'pct-bad');
            pctHtml = '<span class="' + cls + '">' + pct + '%</span>';
        }
        html += '<tr>' +
            '<td>' + esc(r.trim) + '</td>' +
            '<td>' + (qty > 0 ? qty : '<span class="zero">0</span>') + '</td>' +
            '<td>' + (ins > 0 ? ins : '<span class="zero">0</span>') + '</td>' +
            '<td>' + (def > 0 ? def : '<span class="zero">0</span>') + '</td>' +
            '<td>' + pctHtml + '</td>';
        for (var d = 0; d < defectCols.length; d++) {
            var dc = defectCols[d], cnt = r[dc] || 0;
            totDefects[dc] += cnt;
            html += '<td>' + (cnt > 0 ? '<strong style="color:#c62828;">' + cnt + '</strong>' : '<span class="zero">0</span>') + '</td>';
        }
        html += '</tr>';
    }
    document.getElementById('sumBody').innerHTML = html;

    var totPct = totIns > 0 ? (Math.round(totDef / totIns * 1000) / 10) + '%' : '-';
    var ftr = '<tr><td style="text-align:right;">TOTAL</td>' +
        '<td>' + totQty + '</td><td>' + totIns + '</td><td>' + totDef + '</td><td>' + totPct + '</td>';
    for (var d = 0; d < defectCols.length; d++) {
        ftr += '<td>' + (totDefects[defectCols[d]] || 0) + '</td>';
    }
    document.getElementById('sumFoot').innerHTML = ftr + '</tr>';

    document.getElementById('m5Info').innerHTML =
        '<span>Trim types with data: <strong>' +
        rows.filter(function(r){ return r.ttl_qty > 0; }).length + ' / ' + rows.length + '</strong></span>' +
        '<span>Total Qty: <strong>' + totQty + '</strong></span>' +
        '<span>Total Inspected: <strong>' + totIns + '</strong></span>' +
        '<span>Total Defects: <strong>' + totDef + '</strong></span>' +
        '<span>Overall Defect Rate: <strong>' + totPct + '</strong></span>';
}

function exportPDF5() {
    var f = getFilters();
    if (!f.from || !f.to) { alert('Please select both Date From and To.'); return; }
    window.open(
        BASE5 + '?ajax=export_pdf' +
        '&from='     + encodeURIComponent(f.from)     +
        '&to='       + encodeURIComponent(f.to)       +
        '&supplier=' + encodeURIComponent(f.supplier) +
        '&brand='    + encodeURIComponent(f.brand),
        '_blank'
    );
}
</script>