<?php
// ─────────────────────────────────────────────
//  Module 2 – Dashboard 
// ─────────────────────────────────────────────
if (!function_exists('trims_dash_where')) {
    function trims_dash_where($from, $to, $supplier, $brand, $io, &$params) {
        $params = array($from, $to);
        $where  = "WHERE a.Inspection_Date >= ? AND a.Inspection_Date < DATEADD(DAY, 1, ?)";
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
        return $where;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'dashboard_inspection_rows') {
    require_once dirname(__FILE__) . '/config.php';
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
    $params = array();
    $where  = trims_dash_where($from, $to, $supplier, $brand, $io, $params);
    $sql = "
        SELECT TOP 500
            a.id,
            a.IO_num       AS IO_num,
            a.PO_num       AS PO_num,
            a.Vendor_Name  AS Vendor_Name,
            a.Custome_Name AS Brand,
            CONVERT(VARCHAR(10), a.Inspection_Date, 120) AS Inspection_Date,
            a.Qty_Inspected AS Qty_Inspected,
            a.Qty_Defects   AS Qty_Defects,
            a.Result        AS Result
        FROM TRIMS_TBL_INSPECTION a
        $where
        ORDER BY a.Inspection_Date DESC, a.id DESC
    ";
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error'])) {
        echo json_encode(array('error' => $rows['__error'])); exit;
    }
    echo json_encode($rows); exit;
}

// ═══ Pro-style summary tables (same filters as dashboard; inspection data only) ═══
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dashboard_pro_summaries') {
    require_once dirname(__FILE__) . '/config.php';
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

    $params = array();
    $where  = trims_dash_where($from, $to, $supplier, $brand, $io, $params);

    $sqlTrim = "
        SELECT ISNULL(c.description, '(Unknown)') AS trim_type,
               SUM(a.Total_Qty)       AS ttl_qty,
               SUM(a.Qty_Inspected)   AS qty_inspected,
               SUM(a.Qty_Defects)     AS qty_defects,
               CASE WHEN SUM(a.Qty_Inspected) > 0
                    THEN ROUND(100.0 * SUM(a.Qty_Defects) / NULLIF(SUM(a.Qty_Inspected), 0), 1)
                    ELSE NULL END AS defect_pct
        FROM TRIMS_TBL_INSPECTION a
        LEFT JOIN TRIMS_TBL_DROPDOWN c ON a.System_Trim_Type = c.id AND c.category = 1
        $where
        GROUP BY c.description
        ORDER BY SUM(a.Total_Qty) DESC
    ";
    $byTrim = dbQuery($sqlTrim, $params);
    if (isset($byTrim['__error'])) {
        echo json_encode(array('error' => $byTrim['__error'])); exit;
    }

    $sqlSup = "
        SELECT a.Vendor_Name AS supplier,
               SUM(a.Total_Qty)       AS ttl_qty,
               SUM(a.Qty_Inspected)   AS qty_inspected,
               SUM(a.Qty_Defects)     AS qty_defects,
               CASE WHEN SUM(a.Qty_Inspected) > 0
                    THEN ROUND(100.0 * SUM(a.Qty_Defects) / NULLIF(SUM(a.Qty_Inspected), 0), 1)
                    ELSE NULL END AS defect_pct
        FROM TRIMS_TBL_INSPECTION a
        $where
        GROUP BY a.Vendor_Name
        ORDER BY SUM(a.Total_Qty) DESC
    ";
    $bySupplier = dbQuery($sqlSup, $params);
    if (isset($bySupplier['__error'])) {
        echo json_encode(array('error' => $bySupplier['__error'])); exit;
    }

    $sqlQa = "
        SELECT a.Vendor_Name AS supplier,
               ISNULL(c.description, '(Unknown)') AS trim_type,
               SUM(a.Total_Qty)       AS ttl_qty,
               SUM(a.Qty_Inspected)   AS qty_inspected,
               SUM(a.Qty_Defects)     AS qty_defects,
               CASE WHEN SUM(a.Qty_Inspected) > 0
                    THEN ROUND(100.0 * SUM(a.Qty_Defects) / NULLIF(SUM(a.Qty_Inspected), 0), 1)
                    ELSE NULL END AS defect_pct,
               CASE
                    WHEN SUM(a.Qty_Inspected) = 0 THEN 'PENDING'
                    WHEN MAX(CASE WHEN a.Result = 'FAILED' THEN 1 ELSE 0 END) = 1 THEN 'FAILED'
                    WHEN MAX(CASE WHEN a.Result = 'HOLD' THEN 1 ELSE 0 END) = 1 THEN 'HOLD'
                    WHEN MAX(CASE WHEN a.Result = 'REPLACEMENT' THEN 1 ELSE 0 END) = 1 THEN 'REPLACEMENT'
                    ELSE 'PASSED'
               END AS rollup_result
        FROM TRIMS_TBL_INSPECTION a
        LEFT JOIN TRIMS_TBL_DROPDOWN c ON a.System_Trim_Type = c.id AND c.category = 1
        $where
        GROUP BY a.Vendor_Name, c.description
        ORDER BY a.Vendor_Name, c.description
    ";
    $qaPairs = dbQuery($sqlQa, $params);
    if (isset($qaPairs['__error'])) {
        echo json_encode(array('error' => $qaPairs['__error'])); exit;
    }

    echo json_encode(array(
        'by_trim'      => $byTrim,
        'by_supplier'  => $bySupplier,
        'qa_pairs'     => $qaPairs,
    ));
    exit;
}
?>
<style>
    .dash-wrap {
        max-width: 1400px;
        margin: 0 auto;
        --dash-ok: #1b5e20;
        --dash-warn: #e65100;
        --dash-bad: #b71c1c;
        --dash-brand: #0d47a1;
    }
    .dash-hero {
        background: linear-gradient(125deg, #0d2137 0%, #1a3a5c 35%, #1565c0 78%, #0277bd 100%);
        color: #fff;
        border-radius: 12px;
        padding: 24px 28px;
        margin-bottom: 22px;
        box-shadow: 0 12px 32px rgba(13, 33, 55, 0.45);
        border: 1px solid rgba(255,255,255,0.12);
    }
    .dash-hero h3 {
        font-size: 1.25rem;
        font-weight: 800;
        margin: 0 0 8px 0;
        letter-spacing: 0.03em;
        text-shadow: 0 2px 8px rgba(0,0,0,0.25);
    }
    .dash-hero p {
        font-size: .86rem;
        opacity: 0.92;
        margin: 0 0 20px 0;
        line-height: 1.5;
        max-width: 920px;
    }
    .dash-filters {
        display: -webkit-box;
        display: -ms-flexbox;
        display: flex;
        -ms-flex-wrap: wrap;
        flex-wrap: wrap;
        -webkit-box-align: end;
        -ms-flex-align: end;
        align-items: flex-end;
        gap: 14px 18px;
    }
    .dash-filters .fg label {
        color: #fff;
        font-size: .74rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 6px;
        font-weight: 700;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }
    .dash-filters .fg input[type=date],
    .dash-filters .fg select {
        min-width: 150px;
        padding: 10px 12px;
        border: 2px solid rgba(255,255,255,0.35);
        border-radius: 8px;
        font-size: .88rem;
        font-weight: 600;
        background: #fff;
        color: #1a3a5c;
        box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    }
    .dash-filters .fg select option { color: #1a3a5c; background: #fff; }
    .dash-filters .fg input[type=date]::-webkit-calendar-picker-indicator {
        cursor: pointer;
    }
    .dash-io-filter {
        min-width: 168px !important;
        color: #1a3a5c !important;
        background: #fff !important;
        border: 2px solid rgba(255,255,255,0.5) !important;
    }
    .dash-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-left: auto; }
    .dash-btn-refresh {
        background: linear-gradient(180deg, #00c853 0%, #00a040 100%) !important;
        color: #fff !important;
        font-weight: 800 !important;
        letter-spacing: 0.04em;
        padding: 11px 22px !important;
        border-radius: 8px !important;
        border: none !important;
        box-shadow: 0 4px 14px rgba(0, 200, 83, 0.45), 0 2px 0 rgba(255,255,255,0.2) inset !important;
        text-transform: uppercase;
        font-size: .8rem !important;
    }
    .dash-btn-refresh:hover { filter: brightness(1.08); opacity: 1 !important; }

    .dash-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }
    .dash-kpi {
        border-radius: 12px;
        padding: 20px 22px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 6px 18px rgba(0,0,0,0.12);
        border: none;
    }
    .dash-kpi::after {
        content: '';
        position: absolute;
        top: 0; right: 0;
        width: 120px; height: 120px;
        border-radius: 50%;
        background: rgba(255,255,255,0.08);
        transform: translate(35%, -35%);
        pointer-events: none;
    }
    .dash-kpi .lbl {
        font-size: .74rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        margin-bottom: 8px;
        position: relative;
        z-index: 1;
    }
    .dash-kpi .val {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1.05;
        position: relative;
        z-index: 1;
        letter-spacing: -0.02em;
    }
    .dash-kpi.kpi-blue {
        background: linear-gradient(145deg, #1976d2 0%, #0d47a1 55%, #01579b 100%);
        color: #fff;
        border-left: 6px solid #64b5f6;
    }
    .dash-kpi.kpi-blue .lbl { color: rgba(255,255,255,0.88); }
    .dash-kpi.kpi-blue .val { color: #fff; text-shadow: 0 2px 12px rgba(0,0,0,0.25); }

    .dash-kpi.kpi-green {
        background: linear-gradient(145deg, #2e7d32 0%, #1b5e20 100%);
        color: #fff;
        border-left: 6px solid #a5d6a7;
    }
    .dash-kpi.kpi-green .lbl { color: rgba(255,255,255,0.88); }
    .dash-kpi.kpi-green .val { color: #fff; text-shadow: 0 2px 12px rgba(0,0,0,0.2); }

    .dash-kpi.kpi-red {
        background: linear-gradient(145deg, #e53935 0%, #b71c1c 100%);
        color: #fff;
        border-left: 6px solid #ffcdd2;
    }
    .dash-kpi.kpi-red .lbl { color: rgba(255,255,255,0.9); }
    .dash-kpi.kpi-red .val { color: #fff; text-shadow: 0 2px 12px rgba(0,0,0,0.25); }

    .dash-kpi.kpi-amber {
        background: linear-gradient(145deg, #ff9800 0%, #e65100 100%);
        color: #fff;
        border-left: 6px solid #ffe082;
    }
    .dash-kpi.kpi-amber .lbl { color: rgba(255,255,255,0.95); }
    .dash-kpi.kpi-amber .val { color: #fff; text-shadow: 0 2px 10px rgba(0,0,0,0.2); }
    .dash-kpi.kpi-amber .val.kpi-rate-good { color: #e8f5e9; }
    .dash-kpi.kpi-amber .val.kpi-rate-warn { color: #fffde7; text-shadow: 0 0 12px rgba(0,0,0,0.35); }
    .dash-kpi.kpi-amber .val.kpi-rate-bad { color: #ffebee; }

    .dash-kpi.kpi-purple {
        background: linear-gradient(145deg, #6a1b9a 0%, #4a148c 100%);
        color: #fff;
        border-left: 6px solid #ce93d8;
    }
    .dash-kpi.kpi-purple .lbl { color: rgba(255,255,255,0.88); }
    .dash-kpi.kpi-purple .val { color: #fff; text-shadow: 0 2px 12px rgba(0,0,0,0.25); }

    .dash-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        margin-bottom: 20px;
    }
    @media (max-width: 1024px) {
        .dash-grid-2 { grid-template-columns: 1fr; }
    }
    .dash-chart-card {
        background: #fff;
        border-radius: 12px;
        padding: 0 0 18px;
        border: 1px solid #dde3ea;
        box-shadow: 0 4px 16px rgba(26, 58, 92, 0.08);
        overflow: hidden;
    }
    .dash-chart-card .hd {
        font-size: .95rem;
        font-weight: 800;
        color: #0d2137;
        margin: 0;
        padding: 14px 18px 12px;
        border-bottom: none;
    }
    .dash-chart-card .dash-chart-inner { padding: 0 14px 8px; }
    .dash-chart-card.dash-ac-results { border-top: 5px solid #2e7d32; }
    .dash-chart-card.dash-ac-results .hd {
        background: linear-gradient(90deg, rgba(46, 125, 50, 0.14) 0%, rgba(46, 125, 50, 0.02) 100%);
    }
    .dash-chart-card.dash-ac-volume { border-top: 5px solid #1565c0; }
    .dash-chart-card.dash-ac-volume .hd {
        background: linear-gradient(90deg, rgba(21, 101, 192, 0.14) 0%, rgba(21, 101, 192, 0.02) 100%);
    }
    .dash-chart-card.dash-ac-trim { border-top: 5px solid #e65100; }
    .dash-chart-card.dash-ac-trim .hd {
        background: linear-gradient(90deg, rgba(230, 81, 0, 0.14) 0%, rgba(230, 81, 0, 0.02) 100%);
    }
    .dash-chart-card.dash-ac-defect { border-top: 5px solid #6a1b9a; }
    .dash-chart-card.dash-ac-defect .hd {
        background: linear-gradient(90deg, rgba(106, 27, 154, 0.14) 0%, rgba(106, 27, 154, 0.02) 100%);
    }
    .dash-chart-box {
        position: relative;
        height: 280px;
        margin: 0 8px;
        background: linear-gradient(180deg, #f8fafc 0%, #fff 45%);
        border-radius: 10px;
        border: 1px solid #eceff1;
    }
    .dash-chart-box.tall { height: 320px; }

    .dash-io-row {
        display: -webkit-box;
        display: -ms-flexbox;
        display: flex;
        -ms-flex-wrap: wrap;
        flex-wrap: wrap;
        gap: 12px;
        -webkit-box-align: end;
        -ms-flex-align: end;
        align-items: flex-end;
        margin-bottom: 14px;
    }
    .dash-io-row .sf { width: 220px; max-width: 100%; }
    .dash-io-row label { color: #555; }
    .dash-io-row input[type=text] {
        background: #fff;
        color: #333;
    }
    .dash-lines-panel {
        border-radius: 12px;
        border: 1px solid #cfd8dc;
        box-shadow: 0 4px 18px rgba(13, 33, 55, 0.08);
        overflow: hidden;
    }
    .dash-lines-panel .card-title {
        background: linear-gradient(90deg, #37474f 0%, #546e7a 100%);
        color: #fff !important;
        margin: 0 !important;
        padding: 16px 22px !important;
        border-bottom: 3px solid #00bcd4 !important;
        font-size: 1rem !important;
        letter-spacing: 0.02em;
    }
    .dash-mini-table-wrap {
        overflow-x: auto;
        max-height: 280px;
        overflow-y: auto;
        margin: 0 16px 16px;
        border-radius: 10px;
        border: 2px solid #b0bec5;
        box-shadow: inset 0 0 0 1px #fff;
    }
    .dash-mini-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .8rem;
    }
    .dash-mini-table th {
        background: linear-gradient(180deg, #263238 0%, #37474f 100%);
        color: #fff;
        padding: 11px 10px;
        text-align: left;
        font-weight: 800;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 3px solid #00bcd4;
        position: sticky;
        top: 0;
        z-index: 2;
    }
    .dash-mini-table td {
        padding: 9px 10px;
        border-bottom: 1px solid #eceff1;
        vertical-align: middle;
        font-weight: 500;
    }
    .dash-mini-table tbody tr:nth-child(even) { background: #f5f5f5; }
    .dash-mini-table tbody tr:hover { background: #e1f5fe !important; }
    .dash-mini-table tbody tr.dash-tr-pass { background: #e8f5e9 !important; box-shadow: inset 4px 0 0 #2e7d32; }
    .dash-mini-table tbody tr.dash-tr-fail { background: #ffcdd2 !important; box-shadow: inset 4px 0 0 #b71c1c; }
    .dash-mini-table tbody tr.dash-tr-hold { background: #fff9c4 !important; box-shadow: inset 4px 0 0 #f57c00; }
    .dash-mini-table tbody tr.dash-tr-repl { background: #e8eaf6 !important; box-shadow: inset 4px 0 0 #3949ab; }

    .dash-pill {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: .72rem;
        font-weight: 800;
        letter-spacing: 0.03em;
        border: 2px solid transparent;
    }
    .dash-pill.pass { background: #c8e6c9; color: #1b5e20; border-color: #43a047; }
    .dash-pill.fail { background: #ffcdd2; color: #b71c1c; border-color: #e53935; }
    .dash-pill.hold { background: #ffecb3; color: #e65100; border-color: #ff9800; }
    .dash-pill.repl { background: #c5cae9; color: #1a237e; border-color: #5c6bc0; }

    .dash-status {
        font-size: .86rem;
        font-weight: 700;
        min-height: 24px;
        margin-top: 12px;
    }
    .dash-status.ok {
        display: inline-block;
        color: #1b5e20;
        background: #c8e6c9;
        padding: 8px 18px;
        border-radius: 999px;
        border: 2px solid #66bb6a;
    }
    .dash-status.err {
        display: inline-block;
        color: #b71c1c;
        background: #ffcdd2;
        padding: 8px 18px;
        border-radius: 999px;
        border: 2px solid #ef5350;
    }

    /* ── Pro summary tables ───────── */
    .dash-pro-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        margin-bottom: 18px;
    }
    @media (max-width: 1100px) {
        .dash-pro-grid { grid-template-columns: 1fr; }
    }
    .dash-pro-wrap {
        border-radius: 4px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(30, 58, 95, 0.12);
        border: 1px solid #b0bec5;
    }
    .dash-pro-title {
        background: linear-gradient(180deg, #1e3a5f 0%, #152a45 100%);
        color: #fff;
        font-size: .78rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        padding: 12px 16px;
        text-align: center;
        border-bottom: 2px solid #0d2137;
    }
    .dash-pro-scroll { overflow-x: auto; max-width: 100%; }
    .dash-pro-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
        background: #fff;
    }
    .dash-pro-table thead th {
        background: linear-gradient(180deg, #5c7cfa 0%, #4263c9 100%);
        color: #fff;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-size: .68rem;
        padding: 10px 8px;
        border: 1px solid #3b52b4;
        text-align: center;
    }
    .dash-pro-table thead th:first-child { text-align: left; padding-left: 12px; }
    .dash-pro-table tbody td {
        padding: 9px 8px;
        border: 1px solid #cfd8dc;
        vertical-align: middle;
    }
    .dash-pro-table tbody td:first-child {
        text-align: left;
        font-weight: 700;
        color: #1a3a5c;
        padding-left: 12px;
    }
    .dash-pro-table tbody tr:nth-child(odd)  { background: #fff; }
    .dash-pro-table tbody tr:nth-child(even) { background: #e8f1fc; }
    .dash-pro-table tbody tr:hover { background: #d6e8ff !important; }
    .dash-pro-num { text-align: center; font-weight: 700; font-variant-numeric: tabular-nums; }
    .dash-pro-col-ttl { background: #fff3e0 !important; }
    .dash-pro-col-qa { background: #e8f5e9 !important; }
    .dash-pro-col-qa-strong { background: #c8e6c9 !important; color: #1b5e20; font-weight: 800; }
    .dash-pro-highlight-ttl { background: #ffe0b2 !important; }
    .dash-pro-highlight-def { background: #ffccbc !important; }
    .dash-pro-pct {
        text-align: center;
        font-weight: 800;
        position: relative;
        min-width: 64px;
    }
    .dash-pro-pct-flag::before {
        content: '';
        position: absolute;
        top: 3px;
        left: 3px;
        width: 0;
        height: 0;
        border-style: solid;
        border-width: 0 9px 9px 0;
        border-color: transparent #2e7d32 transparent transparent;
    }
    .dash-pro-tfoot td {
        background: linear-gradient(180deg, #1e3a5f 0%, #152a45 100%) !important;
        color: #fff !important;
        font-weight: 800;
        padding: 11px 8px;
        border: 1px solid #0d2137;
        text-align: center;
    }
    .dash-pro-tfoot td:first-child {
        text-align: right;
        padding-right: 14px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .dash-pro-full { margin-bottom: 18px; }
    .dash-pill.pending {
        background: #eceff1;
        color: #455a64;
        border-color: #90a4ae;
    }
    .dash-pro-table tbody tr.dash-pro-tr-pending { background: #eceff1 !important; }
    .dash-pro-table tbody tr.dash-tr-pass { background: #e8f5e9 !important; }
    .dash-pro-table tbody tr.dash-tr-fail { background: #ffcdd2 !important; }
    .dash-pro-table tbody tr.dash-tr-hold { background: #fff9c4 !important; }
    .dash-pro-table tbody tr.dash-tr-repl { background: #e8eaf6 !important; }

    /* ── Mobile responsive ── */
    @media (max-width: 768px) {
        .dash-hero {
            padding: 16px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .dash-hero h3 { font-size: 1.05rem; }
        .dash-hero p  { font-size: .78rem; margin-bottom: 14px; }

        .dash-filters { gap: 10px; }
        .dash-filters .fg { width: 100%; }
        .dash-filters .fg input[type=date],
        .dash-filters .fg select,
        .dash-io-filter {
            min-width: 0 !important;
            width: 100%;
        }
        .dash-actions {
            margin-left: 0;
            width: 100%;
        }
        .dash-btn-refresh { width: 100%; }

        .dash-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        .dash-kpi { padding: 14px 14px; }
        .dash-kpi .lbl { font-size: .68rem; }
        .dash-kpi .val { font-size: 1.4rem; }

        .dash-grid-2,
        .dash-pro-grid {
            grid-template-columns: 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .dash-chart-box       { height: 240px; }
        .dash-chart-box.tall  { height: 280px; }

        .dash-pro-title { font-size: .72rem; padding: 10px 12px; }
        .dash-pro-table { font-size: .76rem; }
        .dash-pro-table thead th { font-size: .62rem; padding: 8px 6px; }
        .dash-pro-table tbody td { padding: 7px 6px; }

        .dash-mini-table-wrap { margin: 0 8px 12px; }
        .dash-mini-table { font-size: .75rem; }
        .dash-mini-table th { font-size: .66rem; padding: 9px 7px; }
        .dash-mini-table td { padding: 7px 7px; }

        .dash-io-row .sf { width: 100%; }
    }

    @media (max-width: 480px) {
        .dash-kpi-grid { grid-template-columns: 1fr; }
        .dash-kpi .val { font-size: 1.6rem; }
        .dash-chart-box       { height: 220px; }
        .dash-chart-box.tall  { height: 260px; }
    }
</style>

<div class="dash-wrap">
    <div class="dash-hero">
        <h3>Operations dashboard</h3>
        <div class="dash-filters">
            <div class="fg">
                <label for="dashFrom">From</label>
                <input type="date" id="dashFrom" />
            </div>
            <div class="fg">
                <label for="dashTo">To</label>
                <input type="date" id="dashTo" />
            </div>
            <div class="fg">
                <label for="dashSupplier">Supplier</label>
                <select id="dashSupplier"><option value="ALL">All suppliers</option></select>
            </div>
            <div class="fg">
                <label for="dashBrand">Brand</label>
                <select id="dashBrand"><option value="ALL">All brands</option></select>
            </div>
            <div class="fg">
                <label for="dashIoInput">IO (optional)</label>
                <input type="text" id="dashIoInput" class="dash-io-filter" placeholder="All IOs" maxlength="50" />
            </div>
            <div class="dash-actions">
                <button type="button" class="btn btn-primary dash-btn-refresh" id="dashRefreshBtn">Refresh dashboard</button>
            </div>
        </div>
        <div class="dash-status" id="dashGlobalStatus"></div>
    </div>

    <div class="dash-kpi-grid" id="dashKpiRow">
        <div class="dash-kpi kpi-blue">
            <div class="lbl">Report line groups</div>
            <div class="val" id="kpiLines">—</div>
        </div>
        <div class="dash-kpi kpi-green">
            <div class="lbl">Passed</div>
            <div class="val" id="kpiPass">—</div>
        </div>
        <div class="dash-kpi kpi-red">
            <div class="lbl">Failed</div>
            <div class="val" id="kpiFail">—</div>
        </div>
        <div class="dash-kpi kpi-amber">
            <div class="lbl">Defect rate</div>
            <div class="val" id="kpiRate">—</div>
        </div>
        <div class="dash-kpi kpi-purple">
            <div class="lbl">Active trim types</div>
            <div class="val" id="kpiTrims">—</div>
        </div>
    </div>

    <div class="dash-grid-2">
        <div class="dash-chart-card dash-ac-results">
            <div class="hd">Inspection results (Module 3)</div>
            <div class="dash-chart-inner"><div class="dash-chart-box"><canvas id="chartResults"></canvas></div></div>
        </div>
        <div class="dash-chart-card dash-ac-volume">
            <div class="hd">Volume by inspection date</div>
            <div class="dash-chart-inner"><div class="dash-chart-box"><canvas id="chartByDate"></canvas></div></div>
        </div>
    </div>

    <div class="dash-grid-2">
        <div class="dash-chart-card dash-ac-trim">
            <div class="hd">Defect rate by system trim (Module 5)</div>
            <div class="dash-chart-inner"><div class="dash-chart-box tall"><canvas id="chartTrimPct"></canvas></div></div>
        </div>
        <div class="dash-chart-card dash-ac-defect">
            <div class="hd">Defect quantities by type (Module 5)</div>
            <div class="dash-chart-inner"><div class="dash-chart-box tall"><canvas id="chartDefects"></canvas></div></div>
        </div>
    </div>

    <div class="dash-pro-grid">
        <div class="dash-pro-wrap">
            <div class="dash-pro-title">Summary by trim type</div>
            <div class="dash-pro-scroll">
                <table class="dash-pro-table" id="dashProTrimTable">
                    <thead>
                        <tr>
                            <th>Trim type</th>
                            <th>TTL qty</th>
                            <th>Qty inspected</th>
                            <th>Qty defects</th>
                            <th>Defect %</th>
                        </tr>
                    </thead>
                    <tbody id="dashProTrimBody">
                        <tr><td colspan="5" class="dash-pro-num" style="padding:20px;color:#78909c;">—</td></tr>
                    </tbody>
                    <tfoot id="dashProTrimFoot"></tfoot>
                </table>
            </div>
        </div>
        <div class="dash-pro-wrap">
            <div class="dash-pro-title">Summary by supplier</div>
            <div class="dash-pro-scroll">
                <table class="dash-pro-table" id="dashProSupTable">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>TTL qty</th>
                            <th>Qty inspected</th>
                            <th>Qty defects</th>
                            <th>Defect %</th>
                        </tr>
                    </thead>
                    <tbody id="dashProSupBody">
                        <tr><td colspan="5" class="dash-pro-num" style="padding:20px;color:#78909c;">—</td></tr>
                    </tbody>
                    <tfoot id="dashProSupFoot"></tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="dash-pro-wrap dash-pro-full">
        <div class="dash-pro-title">Live QA status</div>
        <div class="dash-pro-scroll">
            <table class="dash-pro-table" id="dashProQaTable">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Trim type</th>
                        <th>TTL qty</th>
                        <th>Qty inspected</th>
                        <th>Qty defects</th>
                        <th>Defect %</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody id="dashProQaBody">
                    <tr><td colspan="7" class="dash-pro-num" style="padding:20px;color:#78909c;">—</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card dash-lines-panel">
        <div style="margin:0 16px 16px;">
            <div class="dash-chart-card dash-ac-volume">
                <div class="hd">Inspection</div>
                <div class="dash-mini-table-wrap">
                    <table class="dash-mini-table" id="dashIoTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>IO</th>
                                <th>Brand</th>
                                <th>Supplier</th>
                                <th>PO</th>
                                <th>Insp.</th>
                                <th>Def.</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody id="dashIoTbody">
                            <tr><td colspan="8" style="text-align:center;color:#999;padding:20px;">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
    var BASE2 = 'module2.php';
    var BASE3 = 'module3.php';
    var BASE5 = 'module5.php';

    var chartResults = null;
    var chartByDate  = null;
    var chartTrimPct = null;
    var chartDefects = null;

    var M3_ROWS = [];
    var M5_DATA = { rows: [], defect_cols: [] };
    var DASH_LINE_ROWS = [];
    var PRO_SUMMARY = null;

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }
    function monthStartStr() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-01';
    }

    function esc(s) {
        if (s === null || s === undefined) { return ''; }
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function getEl(id) { return document.getElementById(id); }

    function xhrGet(url, cb) {
        var sep = url.indexOf('?') !== -1 ? '&' : '?';
        var x = new XMLHttpRequest();
        x.open('GET', url + sep + '_=' + Date.now(), true);
        x.setRequestHeader('Cache-Control', 'no-cache, no-store');
        x.setRequestHeader('Pragma', 'no-cache');
        x.onreadystatechange = function() {
            if (x.readyState !== 4) { return; }
            try {
                cb(null, JSON.parse(x.responseText));
            } catch (e) {
                cb('Invalid JSON', null);
            }
        };
        x.send();
    }

    function destroyChart(ch) {
        if (ch) { try { ch.destroy(); } catch (e) {} }
    }

    function loadDropdowns() {
        xhrGet(BASE5 + '?ajax=get_suppliers', function(err, list) {
            if (err || !list || list.error) { return; }
            var sel = getEl('dashSupplier');
            for (var i = 0; i < list.length; i++) {
                var o = document.createElement('option');
                o.value = list[i];
                o.textContent = list[i];
                sel.appendChild(o);
            }
        });
        xhrGet(BASE5 + '?ajax=get_brands', function(err, list) {
            if (err || !list || list.error) { return; }
            var sel = getEl('dashBrand');
            for (var i = 0; i < list.length; i++) {
                var o = document.createElement('option');
                o.value = list[i];
                o.textContent = list[i];
                sel.appendChild(o);
            }
        });
    }

    function aggregateM3(rows) {
        var pass = 0, fail = 0, hold = 0, repl = 0, other = 0;
        var byDate = {};
        var totIns = 0, totDef = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var res = (r['RESULT'] || '').toString().toUpperCase();
            if (res === 'PASSED') { pass++; }
            else if (res === 'FAILED') { fail++; }
            else if (res === 'HOLD') { hold++; }
            else if (res === 'REPLACEMENT') { repl++; }
            else { other++; }

            var ds = r['DATE'] || '';
            if (ds) {
                byDate[ds] = (byDate[ds] || 0) + 1;
            }
            totIns += parseInt(r['QTY INSPECTED'] || 0, 10);
            totDef += parseInt(r['QTY DEFECTS'] || 0, 10);
        }
        var dates = Object.keys(byDate).sort();
        var dateCounts = [];
        for (var j = 0; j < dates.length; j++) { dateCounts.push(byDate[dates[j]]); }

        return {
            pass: pass, fail: fail, hold: hold, repl: repl, other: other,
            dates: dates, dateCounts: dateCounts,
            totIns: totIns, totDef: totDef, n: rows.length
        };
    }

    function renderKpis(a) {
        getEl('kpiLines').textContent = a.n;
        getEl('kpiPass').textContent = a.pass;
        getEl('kpiFail').textContent = a.fail;
        var rateEl = getEl('kpiRate');
        rateEl.className = 'val';
        var rate = '—';
        if (a.totIns > 0) {
            var pctNum = (a.totDef / a.totIns) * 100;
            rate = pctNum.toFixed(2) + '%';
            if (pctNum <= 0) { rateEl.classList.add('kpi-rate-good'); }
            else if (pctNum <= 5) { rateEl.classList.add('kpi-rate-warn'); }
            else { rateEl.classList.add('kpi-rate-bad'); }
        } else if (a.n === 0) {
            rate = '—';
        } else {
            rate = '0.00%';
            rateEl.classList.add('kpi-rate-good');
        }
        rateEl.textContent = rate;

        var active = 0;
        for (var i = 0; i < M5_DATA.rows.length; i++) {
            if (M5_DATA.rows[i].ttl_qty > 0) { active++; }
        }
        getEl('kpiTrims').textContent = active;
    }

    function filterQueryString(f) {
        return '&supplier=' + encodeURIComponent(f.supplier) +
            '&brand=' + encodeURIComponent(f.brand) +
            '&io=' + encodeURIComponent(f.io);
    }

    function renderChartsM3(rows) {
        var a = aggregateM3(rows || []);
        renderKpis(a);

        destroyChart(chartResults);
        destroyChart(chartByDate);
        if (!rows || rows.length === 0) {
            return;
        }

        var ctx1 = document.getElementById('chartResults').getContext('2d');
        var labels1 = ['Passed', 'Failed', 'Hold', 'Replacement'];
        var data1 = [a.pass, a.fail, a.hold, a.repl];
        if (a.other > 0) {
            labels1.push('Other');
            data1.push(a.other);
        }
        chartResults = new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: labels1,
                datasets: [{
                    data: data1,
                    backgroundColor: ['#1b5e20', '#b71c1c', '#ff8f00', '#283593', '#607d8b'],
                    borderWidth: 4,
                    borderColor: '#fff',
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '52%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 16,
                            padding: 14,
                            font: { size: 12, weight: '700' },
                            color: '#263238'
                        }
                    }
                }
            }
        });

        var ctx2 = document.getElementById('chartByDate').getContext('2d');
        chartByDate = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: a.dates,
                datasets: [{
                    label: 'Line groups',
                    data: a.dateCounts,
                    backgroundColor: '#039be5',
                    borderColor: '#01579b',
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { maxRotation: 45, minRotation: 0, font: { size: 11, weight: '600' }, color: '#37474f' },
                        grid: { color: 'rgba(55, 71, 79, 0.08)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11, weight: '600' }, color: '#37474f' },
                        grid: { color: 'rgba(2, 119, 189, 0.12)' }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#263238', titleFont: { size: 13, weight: '700' }, bodyFont: { size: 12 } }
                }
            }
        });
    }

    function renderChartsM5() {
        var rows = M5_DATA.rows || [];
        var cols = M5_DATA.defect_cols || [];

        destroyChart(chartTrimPct);
        destroyChart(chartDefects);
        if (rows.length === 0 && cols.length === 0) {
            return;
        }

        var trimLabels = [];
        var pctData = [];
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (r.qty_inspected <= 0 && r.qty_defects <= 0) { continue; }
            trimLabels.push(r.trim);
            pctData.push(r.pct === null ? 0 : r.pct);
        }
        if (trimLabels.length > 12) {
            trimLabels = trimLabels.slice(0, 12);
            pctData = pctData.slice(0, 12);
        }
        if (trimLabels.length === 0) {
            trimLabels = ['(no activity)'];
            pctData = [0];
        }

        var ctxT = document.getElementById('chartTrimPct').getContext('2d');
        chartTrimPct = new Chart(ctxT, {
            type: 'bar',
            data: {
                labels: trimLabels,
                datasets: [{
                    label: 'Defect %',
                    data: pctData,
                    backgroundColor: pctData.map(function(p) {
                        if (p <= 0) { return '#2e7d32'; }
                        if (p <= 5) { return '#f57c00'; }
                        return '#c62828';
                    }),
                    borderColor: pctData.map(function(p) {
                        if (p <= 0) { return '#1b5e20'; }
                        if (p <= 5) { return '#e65100'; }
                        return '#b71c1c';
                    }),
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#263238', titleFont: { size: 13, weight: '700' } }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) { return v + '%'; },
                            font: { size: 11, weight: '600' },
                            color: '#37474f'
                        },
                        grid: { color: 'rgba(230, 81, 0, 0.15)' }
                    },
                    y: {
                        ticks: { font: { size: 11, weight: '600' }, color: '#37474f' },
                        grid: { color: 'rgba(55, 71, 79, 0.08)' }
                    }
                }
            }
        });

        var defectTotals = [];
        for (var c = 0; c < cols.length; c++) {
            var sum = 0;
            var key = cols[c];
            for (var j = 0; j < rows.length; j++) {
                sum += parseInt(rows[j][key] || 0, 10);
            }
            defectTotals.push(sum);
        }

        if (cols.length === 0) {
            return;
        }

        var ctxD = document.getElementById('chartDefects').getContext('2d');
        chartDefects = new Chart(ctxD, {
            type: 'bar',
            data: {
                labels: cols,
                datasets: [{
                    label: 'Qty defects',
                    data: defectTotals,
                    backgroundColor: defectTotals.map(function(v, idx) {
                        var t = ['#7b1fa2', '#6a1b9a', '#8e24aa', '#5e35b1', '#4527a0', '#4a148c'];
                        return t[idx % t.length];
                    }),
                    borderColor: '#311b92',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { font: { size: 10, weight: '600' }, maxRotation: 55, color: '#37474f' },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11, weight: '600' }, color: '#37474f' },
                        grid: { color: 'rgba(106, 27, 154, 0.12)' }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#4a148c', titleFont: { size: 13, weight: '700' } }
                }
            }
        });
    }

    function fmtNum(n) {
        var v = parseInt(n, 10);
        if (isNaN(v)) { v = 0; }
        return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function fmtPct(v) {
        if (v === null || v === undefined || v === '') { return '—'; }
        return v + '%';
    }

    function pillClass(res) {
        res = (res || '').toUpperCase();
        if (res === 'PASSED') { return 'dash-pill pass'; }
        if (res === 'FAILED') { return 'dash-pill fail'; }
        if (res === 'HOLD') { return 'dash-pill hold'; }
        if (res === 'REPLACEMENT') { return 'dash-pill repl'; }
        if (res === 'PENDING') { return 'dash-pill pending'; }
        return 'dash-pill';
    }

    function rowToneClass(res) {
        res = (res || '').toString().toUpperCase();
        if (res === 'PASSED') { return 'dash-tr-pass'; }
        if (res === 'FAILED') { return 'dash-tr-fail'; }
        if (res === 'HOLD') { return 'dash-tr-hold'; }
        if (res === 'REPLACEMENT') { return 'dash-tr-repl'; }
        return '';
    }

    function qaRowClass(res) {
        res = (res || '').toString().toUpperCase();
        if (res === 'PENDING') { return 'dash-pro-tr-pending'; }
        return rowToneClass(res);
    }

    function pctCellClass(pct) {
        var c = 'dash-pro-num dash-pro-pct dash-pro-col-qa';
        if (pct !== null && pct !== undefined && parseFloat(pct) > 0) {
            c += ' dash-pro-pct-flag';
        }
        return c;
    }

    function renderProFoot(totTtl, totIns, totDef) {
        var pct = null;
        if (totIns > 0) {
            pct = Math.round((totDef / totIns) * 1000) / 10;
        }
        return '<tr class="dash-pro-tfoot">' +
            '<td>Total</td>' +
            '<td class="dash-pro-num">' + fmtNum(totTtl) + '</td>' +
            '<td class="dash-pro-num dash-pro-col-qa">' + fmtNum(totIns) + '</td>' +
            '<td class="dash-pro-num dash-pro-col-qa">' + fmtNum(totDef) + '</td>' +
            '<td class="' + pctCellClass(pct) + '">' + fmtPct(pct) + '</td>' +
            '</tr>';
    }

    function renderProSummaries(data) {
        var tbTrim = getEl('dashProTrimBody');
        var ftTrim = getEl('dashProTrimFoot');
        var tbSup  = getEl('dashProSupBody');
        var ftSup  = getEl('dashProSupFoot');
        var tbQa   = getEl('dashProQaBody');
        if (!data || data.error) {
            var errTxt = esc(data && data.error ? data.error : 'Could not load summaries.');
            var msg5 = '<tr><td colspan="5" class="dash-pro-num" style="color:#c62828;padding:16px;">' + errTxt + '</td></tr>';
            tbTrim.innerHTML = msg5;
            tbSup.innerHTML = msg5;
            tbQa.innerHTML = '<tr><td colspan="7" class="dash-pro-num" style="color:#c62828;padding:16px;">' + errTxt + '</td></tr>';
            ftTrim.innerHTML = '';
            ftSup.innerHTML = '';
            return;
        }

        var trims = data.by_trim || [];
        var sups  = data.by_supplier || [];
        var qas   = data.qa_pairs || [];

        function sumCols(rows) {
            var t = 0, i = 0, d = 0;
            for (var k = 0; k < rows.length; k++) {
                t += parseInt(rows[k].ttl_qty || 0, 10);
                i += parseInt(rows[k].qty_inspected || 0, 10);
                d += parseInt(rows[k].qty_defects || 0, 10);
            }
            return { t: t, i: i, d: d };
        }

        if (trims.length === 0) {
            tbTrim.innerHTML = '<tr><td colspan="5" class="dash-pro-num" style="padding:16px;color:#78909c;">No rows for this filter.</td></tr>';
            ftTrim.innerHTML = '';
        } else {
            var htmlT = '';
            var st = sumCols(trims);
            for (var a = 0; a < trims.length; a++) {
                var r = trims[a];
                var ttlH = (a < 5) ? ' dash-pro-highlight-ttl' : '';
                var def = parseInt(r.qty_defects || 0, 10);
                var defCls = def > 0 ? ' dash-pro-col-qa-strong' : ' dash-pro-col-qa';
                htmlT += '<tr>' +
                    '<td>' + esc(r.trim_type || '') + '</td>' +
                    '<td class="dash-pro-num dash-pro-col-ttl' + ttlH + '">' + fmtNum(r.ttl_qty) + '</td>' +
                    '<td class="dash-pro-num dash-pro-col-qa">' + fmtNum(r.qty_inspected) + '</td>' +
                    '<td class="dash-pro-num ' + defCls + '">' + fmtNum(r.qty_defects) + '</td>' +
                    '<td class="' + pctCellClass(r.defect_pct) + '">' + fmtPct(r.defect_pct) + '</td>' +
                    '</tr>';
            }
            tbTrim.innerHTML = htmlT;
            ftTrim.innerHTML = renderProFoot(st.t, st.i, st.d);
        }

        if (sups.length === 0) {
            tbSup.innerHTML = '<tr><td colspan="5" class="dash-pro-num" style="padding:16px;color:#78909c;">No rows for this filter.</td></tr>';
            ftSup.innerHTML = '';
        } else {
            var htmlS = '';
            var ss = sumCols(sups);
            for (var b = 0; b < sups.length; b++) {
                var rs = sups[b];
                var defS = parseInt(rs.qty_defects || 0, 10);
                var insH = defS > 0 ? ' dash-pro-highlight-ttl' : '';
                var defCls2 = defS > 0 ? ' dash-pro-col-qa-strong dash-pro-highlight-def' : ' dash-pro-col-qa';
                htmlS += '<tr>' +
                    '<td>' + esc(rs.supplier || '') + '</td>' +
                    '<td class="dash-pro-num dash-pro-col-ttl">' + fmtNum(rs.ttl_qty) + '</td>' +
                    '<td class="dash-pro-num dash-pro-col-qa' + insH + '">' + fmtNum(rs.qty_inspected) + '</td>' +
                    '<td class="dash-pro-num ' + defCls2 + '">' + fmtNum(rs.qty_defects) + '</td>' +
                    '<td class="' + pctCellClass(rs.defect_pct) + '">' + fmtPct(rs.defect_pct) + '</td>' +
                    '</tr>';
            }
            tbSup.innerHTML = htmlS;
            ftSup.innerHTML = renderProFoot(ss.t, ss.i, ss.d);
        }

        if (qas.length === 0) {
            tbQa.innerHTML = '<tr><td colspan="7" class="dash-pro-num" style="padding:16px;color:#78909c;">No rows for this filter.</td></tr>';
        } else {
            var htmlQ = '';
            for (var c = 0; c < qas.length; c++) {
                var rq = qas[c];
                var rr = rq.rollup_result || '';
                var defQ = parseInt(rq.qty_defects || 0, 10);
                var insHq = defQ > 0 ? ' dash-pro-highlight-ttl' : '';
                var defClsQ = defQ > 0 ? ' dash-pro-col-qa-strong' : ' dash-pro-col-qa';
                htmlQ += '<tr class="' + qaRowClass(rr) + '">' +
                    '<td>' + esc(rq.supplier || '') + '</td>' +
                    '<td>' + esc(rq.trim_type || '') + '</td>' +
                    '<td class="dash-pro-num dash-pro-col-ttl">' + fmtNum(rq.ttl_qty) + '</td>' +
                    '<td class="dash-pro-num dash-pro-col-qa' + insHq + '">' + fmtNum(rq.qty_inspected) + '</td>' +
                    '<td class="dash-pro-num ' + defClsQ + '">' + fmtNum(rq.qty_defects) + '</td>' +
                    '<td class="' + pctCellClass(rq.defect_pct) + '">' + fmtPct(rq.defect_pct) + '</td>' +
                    '<td class="dash-pro-num"><span class="' + pillClass(rr) + '">' + esc(rr) + '</span></td>' +
                    '</tr>';
            }
            tbQa.innerHTML = htmlQ;
        }
    }

    function renderSpotlight(rows) {
        var tbody = getEl('dashIoTbody');

        if (!rows || rows.error) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#c62828;">' +
                esc(rows && rows.error ? rows.error : 'Error') + '</td></tr>';
            return;
        }
        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#999;">No data</td></tr>';
            return;
        }

        var html = '';
        for (var j = 0; j < rows.length; j++) {
            var row = rows[j];
            var res2 = row.Result || '';
            html += '<tr class="' + rowToneClass(res2) + '">' +
                '<td>' + esc(row.Inspection_Date || '') + '</td>' +
                '<td>' + esc(row.IO_num || '') + '</td>' +
                '<td>' + esc(row.Brand || '') + '</td>' +
                '<td>' + esc(row.Vendor_Name || '') + '</td>' +
                '<td>' + esc(row.PO_num || '') + '</td>' +
                '<td style="text-align:right;">' + esc(String(row.Qty_Inspected != null ? row.Qty_Inspected : '')) + '</td>' +
                '<td style="text-align:right;">' + esc(String(row.Qty_Defects != null ? row.Qty_Defects : '')) + '</td>' +
                '<td><span class="' + pillClass(res2) + '">' + esc(res2) + '</span></td>' +
                '</tr>';
        }
        tbody.innerHTML = html;
    }

    function refreshDashboard() {
        var from = getEl('dashFrom').value;
        var to = getEl('dashTo').value;
        var st = getEl('dashGlobalStatus');
        st.className = 'dash-status';
        st.textContent = '';

        if (!from || !to) {
            st.className = 'dash-status err';
            st.textContent = 'Please set both date from and to.';
            return;
        }
        if (from > to) {
            st.className = 'dash-status err';
            st.textContent = 'Date from cannot be after date to.';
            return;
        }

        st.textContent = 'Loading…';
        M3_ROWS = [];
        M5_DATA = { rows: [], defect_cols: [] };
        DASH_LINE_ROWS = [];
        getEl('dashIoTbody').innerHTML = '<tr><td colspan="8" style="text-align:center;color:#999;">Loading…</td></tr>';
        var loadRow5 = '<tr><td colspan="5" class="dash-pro-num" style="padding:16px;color:#78909c;">Loading…</td></tr>';
        getEl('dashProTrimBody').innerHTML = loadRow5;
        getEl('dashProSupBody').innerHTML = loadRow5;
        getEl('dashProTrimFoot').innerHTML = '';
        getEl('dashProSupFoot').innerHTML = '';
        getEl('dashProQaBody').innerHTML = '<tr><td colspan="7" class="dash-pro-num" style="padding:16px;color:#78909c;">Loading…</td></tr>';

        var f = {
            supplier: getEl('dashSupplier').value,
            brand:    getEl('dashBrand').value,
            io:       getEl('dashIoInput').value.replace(/^\s+|\s+$/g, '')
        };
        var fq = filterQueryString(f);

        var url3 = BASE3 + '?ajax=load_report&from=' + encodeURIComponent(from) +
            '&to=' + encodeURIComponent(to) + fq;
        var url5 = BASE5 + '?ajax=load_summary' +
            '&from=' + encodeURIComponent(from) +
            '&to=' + encodeURIComponent(to) + fq;
        var url2 = BASE2 + '?ajax=dashboard_inspection_rows' +
            '&from=' + encodeURIComponent(from) +
            '&to=' + encodeURIComponent(to) + fq;
        var urlPro = BASE2 + '?ajax=dashboard_pro_summaries' +
            '&from=' + encodeURIComponent(from) +
            '&to=' + encodeURIComponent(to) + fq;

        PRO_SUMMARY = null;
        var pending = 4;
        var msgs = [];

        function doneOne() {
            pending--;
            if (pending > 0) { return; }
            renderChartsM3(M3_ROWS);
            renderChartsM5();
            renderSpotlight(DASH_LINE_ROWS);
            renderProSummaries(PRO_SUMMARY);
            if (msgs.length) {
                st.className = 'dash-status err';
                st.textContent = msgs.join(' ');
            } else {
                st.className = 'dash-status ok';
                st.textContent = 'Updated ' + new Date().toLocaleTimeString() + '.';
            }
        }

        xhrGet(url3, function(err, rows) {
            if (err || !rows) {
                M3_ROWS = [];
                msgs.push('Module 3: failed to load.');
            } else if (rows.error) {
                M3_ROWS = [];
                msgs.push('Module 3: ' + rows.error);
            } else {
                M3_ROWS = rows;
            }
            doneOne();
        });

        xhrGet(url5, function(err, data) {
            if (err || !data) {
                M5_DATA = { rows: [], defect_cols: [] };
                msgs.push('Module 5: failed to load.');
            } else if (data.error) {
                M5_DATA = { rows: [], defect_cols: [] };
                msgs.push('Module 5: ' + data.error);
            } else {
                M5_DATA = { rows: data.rows || [], defect_cols: data.defect_cols || [] };
            }
            doneOne();
        });

        xhrGet(url2, function(err, rows) {
            if (err || !rows) {
                DASH_LINE_ROWS = { error: 'Failed to load inspection lines.' };
                msgs.push('Line list: failed to load.');
            } else if (rows.error) {
                DASH_LINE_ROWS = rows;
                msgs.push('Line list: ' + rows.error);
            } else {
                DASH_LINE_ROWS = rows;
            }
            doneOne();
        });

        xhrGet(urlPro, function(err, data) {
            if (err || !data) {
                PRO_SUMMARY = { error: 'Summary tables failed to load.' };
                msgs.push('Summaries: failed to load.');
            } else if (data.error) {
                PRO_SUMMARY = data;
                msgs.push('Summaries: ' + data.error);
            } else {
                PRO_SUMMARY = data;
            }
            doneOne();
        });
    }

    function init() {
        getEl('dashFrom').value = monthStartStr();
        getEl('dashTo').value = todayStr();
        loadDropdowns();
        getEl('dashRefreshBtn').onclick = refreshDashboard;
        getEl('dashFrom').onchange     = refreshDashboard;
        getEl('dashTo').onchange       = refreshDashboard;
        getEl('dashSupplier').onchange = refreshDashboard;
        getEl('dashBrand').onchange    = refreshDashboard;
        getEl('dashIoInput').onkeydown = function(ev) {
            if (ev.keyCode === 13) { refreshDashboard(); }
        };
        if (typeof Chart === 'undefined') {
            getEl('dashGlobalStatus').className = 'dash-status err';
            getEl('dashGlobalStatus').textContent = 'Chart.js failed to load. Check network or CDN access.';
            return;
        }
        refreshDashboard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
