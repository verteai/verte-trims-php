<?php
// ─────────────────────────────────────────────
//  TRIMS Inspection System – Chatbot Backend
//  PHP 5.3 compatible | mssql native
//  Intent-driven analytics over TRIMS_TBL_INSPECTION
// ─────────────────────────────────────────────
session_start();

if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(array('error' => 'Not authenticated'));
    exit;
}

require_once dirname(__FILE__) . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── Read message ──────────────────────────────
$raw = isset($_POST['message']) ? $_POST['message'] : (isset($_GET['message']) ? $_GET['message'] : '');
$msg = trim((string)$raw);
if ($msg === '') {
    echo json_encode(array('error' => 'Empty message'));
    exit;
}

$lc = strtolower($msg);

// ─────────────────────────────────────────────
//  Time period detection
// ─────────────────────────────────────────────
function chat_period($lc) {
    $today = date('Y-m-d');

    // Custom "last N days"
    if (preg_match('/last\s+(\d{1,3})\s*(day|days)/', $lc, $m)) {
        $n = max(1, (int)$m[1]);
        $from = date('Y-m-d', strtotime('-' . ($n - 1) . ' days'));
        return array('from' => $from, 'to' => $today, 'label' => 'last ' . $n . ' days');
    }
    if (preg_match('/(?:past|previous)\s+(\d{1,3})\s*(day|days)/', $lc, $m)) {
        $n = max(1, (int)$m[1]);
        $from = date('Y-m-d', strtotime('-' . ($n - 1) . ' days'));
        return array('from' => $from, 'to' => $today, 'label' => 'past ' . $n . ' days');
    }

    if (strpos($lc, 'today') !== false) {
        return array('from' => $today, 'to' => $today, 'label' => 'today');
    }
    if (strpos($lc, 'yesterday') !== false) {
        $y = date('Y-m-d', strtotime('-1 day'));
        return array('from' => $y, 'to' => $y, 'label' => 'yesterday');
    }
    if (strpos($lc, 'this week') !== false || strpos($lc, 'current week') !== false) {
        // Mon–Sun week
        $monday = date('Y-m-d', strtotime('monday this week'));
        return array('from' => $monday, 'to' => $today, 'label' => 'this week');
    }
    if (strpos($lc, 'last week') !== false || strpos($lc, 'previous week') !== false) {
        $monday = date('Y-m-d', strtotime('monday last week'));
        $sunday = date('Y-m-d', strtotime('sunday last week'));
        return array('from' => $monday, 'to' => $sunday, 'label' => 'last week');
    }
    if (strpos($lc, 'this month') !== false || strpos($lc, 'current month') !== false || strpos($lc, 'mtd') !== false) {
        $start = date('Y-m-01');
        return array('from' => $start, 'to' => $today, 'label' => 'this month');
    }
    if (strpos($lc, 'last month') !== false || strpos($lc, 'previous month') !== false) {
        $start = date('Y-m-01', strtotime('first day of last month'));
        $end   = date('Y-m-d',  strtotime('last day of last month'));
        return array('from' => $start, 'to' => $end, 'label' => 'last month');
    }
    if (strpos($lc, 'this year') !== false || strpos($lc, 'ytd') !== false) {
        $start = date('Y-01-01');
        return array('from' => $start, 'to' => $today, 'label' => 'this year');
    }
    if (strpos($lc, 'last year') !== false) {
        $start = date('Y-01-01', strtotime('-1 year'));
        $end   = date('Y-12-31', strtotime('-1 year'));
        return array('from' => $start, 'to' => $end, 'label' => 'last year');
    }

    // YYYY-MM (single month, e.g. "2026-04")
    if (preg_match('/(\d{4})-(\d{2})(?!-)/', $lc, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2];
        if ($mo >= 1 && $mo <= 12) {
            $start = sprintf('%04d-%02d-01', $y, $mo);
            $end   = date('Y-m-d', strtotime($start . ' +1 month -1 day'));
            return array('from' => $start, 'to' => $end, 'label' => date('F Y', strtotime($start)));
        }
    }

    // Date range: "from YYYY-MM-DD to YYYY-MM-DD"
    if (preg_match('/(\d{4}-\d{2}-\d{2}).{1,15}?(\d{4}-\d{2}-\d{2})/', $lc, $m)) {
        return array('from' => $m[1], 'to' => $m[2], 'label' => $m[1] . ' to ' . $m[2]);
    }

    // Default: this month
    $start = date('Y-m-01');
    return array('from' => $start, 'to' => $today, 'label' => 'this month (default)');
}

// ─────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────
function chat_int($v)   { return (int)$v; }
function chat_num($v, $dec) { return number_format((float)$v, $dec); }
function chat_pct($num, $den) {
    if ((float)$den <= 0) return '0.0%';
    return number_format(100.0 * (float)$num / (float)$den, 2) . '%';
}
function chat_html($s)  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Non-empty cell text for tables; matches empty DB fields to a dash for readability. */
function chat_dash($s) {
    $t = trim((string)$s);
    return $t !== '' ? $t : '—';
}

function chat_extract_top_n($lc, $default) {
    if (preg_match('/top\s+(\d{1,3})/', $lc, $m)) { return max(1, min(50, (int)$m[1])); }
    if (preg_match('/(\d{1,3})\s+(suppliers|brands|trims|defects|vendors)/', $lc, $m)) {
        return max(1, min(50, (int)$m[1]));
    }
    return $default;
}

// Calendar year for annual monthly reports (from message text)
function chat_calendar_year_from_message($lc) {
    $cy = (int)date('Y');
    if (strpos($lc, 'last year') !== false) {
        return $cy - 1;
    }
    if (strpos($lc, 'this year') !== false || strpos($lc, 'ytd') !== false) {
        return $cy;
    }
    if (preg_match('/\b(20[0-2]\d)\b/', $lc, $m)) {
        $y = (int)$m[1];
        if ($y >= 2000 && $y <= 2099) {
            return $y;
        }
    }
    return $cy;
}

// ─────────────────────────────────────────────
//  Intent detection
// ─────────────────────────────────────────────
function chat_intent($lc) {
    if (strpos($lc, 'help') !== false || strpos($lc, 'what can you') !== false ||
        strpos($lc, 'capabilities') !== false || $lc === '?' || strpos($lc, 'commands') !== false) {
        return 'help';
    }

    // Insights / recommendations / suggestions
    if (strpos($lc, 'insight') !== false || strpos($lc, 'recommend') !== false ||
        strpos($lc, 'suggestion') !== false || strpos($lc, 'suggest') !== false ||
        strpos($lc, 'advice') !== false || strpos($lc, 'advise') !== false ||
        strpos($lc, 'should i focus') !== false || strpos($lc, 'should i look') !== false ||
        strpos($lc, 'what should i') !== false || strpos($lc, 'action item') !== false ||
        strpos($lc, 'what to do') !== false || strpos($lc, 'priorit') !== false ||
        strpos($lc, 'red flag') !== false || strpos($lc, 'risk') !== false ||
        strpos($lc, 'what stands out') !== false || strpos($lc, 'anything concern') !== false ||
        strpos($lc, 'how to fix') !== false || strpos($lc, 'how to improve') !== false ||
        strpos($lc, 'improve quality') !== false || strpos($lc, 'improve pass') !== false ||
        strpos($lc, 'low pass') !== false || strpos($lc, 'low percentage') !== false ||
        strpos($lc, 'poor result') !== false || strpos($lc, 'remediation') !== false) {
        return 'insights';
    }

    if (strpos($lc, 'hello') !== false || strpos($lc, 'hi ') === 0 || $lc === 'hi' ||
        strpos($lc, 'hey') !== false || strpos($lc, 'good morning') !== false ||
        strpos($lc, 'good afternoon') !== false || strpos($lc, 'good evening') !== false) {
        return 'greet';
    }

    // Specific top-N intents (most specific first)
    if (preg_match('/(top|worst|best|highest|lowest)\b.*?(supplier|vendor)/', $lc) ||
        preg_match('/supplier.*?(rank|leader|performance)/', $lc)) {
        return 'top_suppliers';
    }
    if (preg_match('/(top|worst|best|highest|lowest)\b.*?(brand|customer)/', $lc) ||
        preg_match('/brand.*?(rank|leader|performance)/', $lc)) {
        return 'top_brands';
    }
    if (preg_match('/(top|worst|best|highest|lowest)\b.*?(trim)/', $lc) ||
        preg_match('/trim.*?(rank|leader|performance|breakdown)/', $lc) ||
        strpos($lc, 'by trim') !== false) {
        return 'top_trims';
    }
    if (preg_match('/(top|worst|most|common|frequent)\b.*?defect/', $lc) ||
        strpos($lc, 'defect type') !== false || strpos($lc, 'defect breakdown') !== false ||
        strpos($lc, 'types of defects') !== false || strpos($lc, 'defect category') !== false) {
        return 'top_defects';
    }

    // Result status queries
    if (strpos($lc, 'failed') !== false || strpos($lc, 'failures') !== false ||
        strpos($lc, 'rejected') !== false) {
        return 'failed';
    }
    if (strpos($lc, 'passed') !== false || strpos($lc, 'pass rate') !== false) {
        return 'passed';
    }
    if (strpos($lc, 'pending') !== false || strpos($lc, 'not yet inspected') !== false) {
        return 'pending';
    }
    if (strpos($lc, 'hold') !== false) {
        return 'hold';
    }
    if (strpos($lc, 'replacement') !== false) {
        return 'replacement';
    }

    // Defect rate
    if (strpos($lc, 'defect rate') !== false || strpos($lc, 'defect %') !== false ||
        strpos($lc, 'defect percent') !== false || strpos($lc, 'reject rate') !== false ||
        strpos($lc, 'quality rate') !== false) {
        return 'defect_rate';
    }

    // Counts / volume
    if (strpos($lc, 'how many inspection') !== false || strpos($lc, 'number of inspection') !== false ||
        strpos($lc, 'inspection count') !== false || strpos($lc, 'inspections done') !== false ||
        strpos($lc, 'total inspection') !== false) {
        return 'count_inspections';
    }
    if (strpos($lc, 'qty inspected') !== false || strpos($lc, 'quantity inspected') !== false ||
        strpos($lc, 'how much inspected') !== false || strpos($lc, 'units inspected') !== false) {
        return 'qty_inspected';
    }
    if (strpos($lc, 'total qty') !== false || strpos($lc, 'total quantity') !== false ||
        strpos($lc, 'volume') !== false) {
        return 'total_qty';
    }
    if (strpos($lc, 'qty defect') !== false || strpos($lc, 'defect qty') !== false ||
        strpos($lc, 'defect quantity') !== false || strpos($lc, 'units defect') !== false) {
        return 'qty_defects';
    }

    // Compare periods
    if (strpos($lc, 'compare') !== false || strpos($lc, 'vs ') !== false ||
        strpos($lc, 'versus') !== false || strpos($lc, 'difference between') !== false) {
        return 'compare';
    }

    // Annual / multi-year performance
    if (strpos($lc, 'year over year') !== false || strpos($lc, 'yoy') !== false ||
        strpos($lc, 'compare years') !== false ||
        (strpos($lc, 'by year') !== false && strpos($lc, 'month') === false)) {
        return 'annual_yoy';
    }
    if (strpos($lc, 'annual') !== false || strpos($lc, 'yearly performance') !== false ||
        strpos($lc, 'full year') !== false || strpos($lc, 'month by month') !== false ||
        strpos($lc, 'monthly breakdown') !== false || strpos($lc, 'performance by month') !== false) {
        return 'annual_monthly';
    }

    // List recent
    if (strpos($lc, 'recent') !== false || strpos($lc, 'latest inspection') !== false ||
        strpos($lc, 'last inspection') !== false) {
        return 'recent';
    }

    // Per-entity: supplier / brand / IO lookup
    if (preg_match('/(?:about|for|of)\s+(?:supplier|vendor)\s+([\w\-\.\s&\/]+?)(?:\?|$|\s+in\s|\s+on\s|\s+for\s)/i', $lc) ||
        preg_match('/(?:supplier|vendor)\s+([\w\-\.&\/]+)/i', $lc)) {
        return 'supplier_detail';
    }
    if (preg_match('/(?:about|for|of)\s+(?:brand|customer)\s+([\w\-\.\s&\/]+?)(?:\?|$|\s+in\s|\s+on\s|\s+for\s)/i', $lc) ||
        preg_match('/(?:brand|customer)\s+([\w\-\.&\/]+)/i', $lc)) {
        return 'brand_detail';
    }
    if (preg_match('/\bio\s*(?:#|number|num|no\.?)?\s*([\w\-]+)/i', $lc)) {
        return 'io_detail';
    }

    // Summary / overview default
    if (strpos($lc, 'summary') !== false || strpos($lc, 'overview') !== false ||
        strpos($lc, 'snapshot') !== false || strpos($lc, 'kpi') !== false ||
        strpos($lc, 'dashboard') !== false || strpos($lc, 'status') !== false ||
        strpos($lc, 'report') !== false || strpos($lc, 'analyze') !== false ||
        strpos($lc, 'analyse') !== false) {
        return 'summary';
    }

    return 'summary'; // default
}

// ─────────────────────────────────────────────
//  Core data fetch (totals)
// ─────────────────────────────────────────────
function chat_totals($from, $to, $extraSql, $extraParams) {
    $sql = "SELECT
                COUNT(*)                              AS line_count,
                ISNULL(SUM(a.Total_Qty), 0)           AS total_qty,
                ISNULL(SUM(a.Qty_Inspected), 0)       AS qty_inspected,
                ISNULL(SUM(a.Qty_Defects), 0)         AS qty_defects,
                SUM(CASE WHEN a.Result = 'PASSED'      THEN 1 ELSE 0 END) AS passed_lines,
                SUM(CASE WHEN a.Result = 'FAILED'      THEN 1 ELSE 0 END) AS failed_lines,
                SUM(CASE WHEN a.Result = 'HOLD'        THEN 1 ELSE 0 END) AS hold_lines,
                SUM(CASE WHEN a.Result = 'REPLACEMENT' THEN 1 ELSE 0 END) AS replacement_lines,
                SUM(CASE WHEN a.Result IS NULL OR a.Result = '' THEN 1 ELSE 0 END) AS pending_lines,
                COUNT(DISTINCT a.IO_num)              AS distinct_ios,
                COUNT(DISTINCT a.Vendor_Name)         AS distinct_suppliers,
                COUNT(DISTINCT a.Custome_Name)        AS distinct_brands
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql";
    $params = array_merge(array($from, $to), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) {
        return null;
    }
    return $rows[0];
}

// ─────────────────────────────────────────────
//  Renderers (return HTML strings)
// ─────────────────────────────────────────────
function render_help() {
    $items = array(
        '<b>Insights &amp; recommendations</b> &mdash; "give me insights" / "what should I focus on?" / "how to fix low pass rate"',
        '<b>Annual performance</b> &mdash; "annual performance", "monthly breakdown 2025", "year over year"',
        'Summary / overview for any period',
        'Defect rate this month / last week / today',
        'Top 5 suppliers by defect rate',
        'Top 10 brands by volume',
        'Top defects this month',
        'Worst trim types last week',
        'Failed / pending / hold / replacement inspections',
        'Compare this month vs last month',
        'Show recent inspections',
        'Supplier &lt;name&gt; this month',
        'Brand &lt;name&gt; last week',
        'IO &lt;number&gt; (shipment detail: PO, GR, vessel, voyage, container, HBL &mdash; same as Module 1 Encoding)',
    );
    $html = '<div class="cb-msg-title">Here&rsquo;s what I can analyze:</div><ul class="cb-help-list">';
    foreach ($items as $it) { $html .= '<li>' . $it . '</li>'; }
    $html .= '</ul><div class="cb-msg-foot">Try one of the suggestions below, or ask in your own words.</div>';
    return $html;
}

function render_greet() {
    return '<div class="cb-msg-title">Hi! I&rsquo;m the TRIMS analytics assistant.</div>'
         . '<div>Ask me about inspection volume, defect rates, top suppliers, failures, monthly or yearly performance, or any period (today, this week, this month, etc.).</div>';
}

function render_no_data($period) {
    return '<div class="cb-msg-title">No inspection data found for ' . chat_html($period['label']) . '.</div>'
         . '<div>Try a different date range or check back later.</div>';
}

function render_kpis($t, $period, $titlePrefix) {
    $rate = chat_pct($t['qty_defects'], $t['qty_inspected']);
    $passRate = '0.0%';
    $totalDecided = (int)$t['passed_lines'] + (int)$t['failed_lines'];
    if ($totalDecided > 0) {
        $passRate = number_format(100.0 * (int)$t['passed_lines'] / $totalDecided, 1) . '%';
    }
    $html  = '<div class="cb-msg-title">' . chat_html($titlePrefix) . ' <span class="cb-period">' . chat_html($period['label']) . '</span></div>';
    $html .= '<div class="cb-kpi-grid">'
          .  '<div class="cb-kpi cb-k-blue"><div class="lbl">Lines</div><div class="val">' . chat_num($t['line_count'], 0) . '</div></div>'
          .  '<div class="cb-kpi cb-k-cyan"><div class="lbl">Total qty</div><div class="val">' . chat_num($t['total_qty'], 0) . '</div></div>'
          .  '<div class="cb-kpi cb-k-indigo"><div class="lbl">Inspected</div><div class="val">' . chat_num($t['qty_inspected'], 0) . '</div></div>'
          .  '<div class="cb-kpi cb-k-amber"><div class="lbl">Defect rate</div><div class="val">' . $rate . '</div></div>'
          .  '<div class="cb-kpi cb-k-green"><div class="lbl">Pass rate</div><div class="val">' . $passRate . '</div></div>'
          .  '<div class="cb-kpi cb-k-red"><div class="lbl">Failed lines</div><div class="val">' . chat_num($t['failed_lines'], 0) . '</div></div>'
          .  '</div>';
    $html .= '<div class="cb-meta">'
          .  '<span>IOs: <b>' . chat_num($t['distinct_ios'], 0) . '</b></span>'
          .  '<span>Suppliers: <b>' . chat_num($t['distinct_suppliers'], 0) . '</b></span>'
          .  '<span>Brands: <b>' . chat_num($t['distinct_brands'], 0) . '</b></span>'
          .  '<span>Defect units: <b>' . chat_num($t['qty_defects'], 0) . '</b></span>'
          .  '<span>Pending: <b>' . chat_num($t['pending_lines'], 0) . '</b></span>'
          .  '<span>Hold: <b>' . chat_num($t['hold_lines'], 0) . '</b></span>'
          .  '</div>';
    return $html;
}

function render_table($title, $headers, $rows, $period) {
    $html = '<div class="cb-msg-title">' . chat_html($title);
    if ($period) { $html .= ' <span class="cb-period">' . chat_html($period['label']) . '</span>'; }
    $html .= '</div>';
    if (empty($rows)) {
        $html .= '<div class="cb-empty">No matching data.</div>';
        return $html;
    }
    $html .= '<div class="cb-table-wrap"><table class="cb-table"><thead><tr>';
    foreach ($headers as $h) { $html .= '<th>' . chat_html($h) . '</th>'; }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($r as $cell) {
            $align = is_numeric(str_replace(array(',', '.', '%'), '', (string)$cell)) ? ' style="text-align:right;"' : '';
            $html .= '<td' . $align . '>' . chat_html((string)$cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

// ─────────────────────────────────────────────
//  Intent handlers
// ─────────────────────────────────────────────
function handle_summary($period, $extraSql, $extraParams) {
    $t = chat_totals($period['from'], $period['to'], $extraSql, $extraParams);
    if (!$t || (int)$t['line_count'] === 0) { return render_no_data($period); }

    $html = render_kpis($t, $period, 'Inspection summary');

    // Add a top-3 suppliers mini list
    $sql = "SELECT TOP 3 a.Vendor_Name AS supplier,
                   ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                   ISNULL(SUM(a.Qty_Defects), 0)   AS qd
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql
              AND a.Vendor_Name IS NOT NULL AND LTRIM(RTRIM(a.Vendor_Name)) <> ''
            GROUP BY a.Vendor_Name
            ORDER BY SUM(a.Qty_Inspected) DESC";
    $params = array_merge(array($period['from'], $period['to']), $extraParams);
    $top = dbQuery($sql, $params);
    if (!isset($top['__error']) && !empty($top)) {
        $rows = array();
        foreach ($top as $r) {
            $rows[] = array(
                $r['supplier'] !== '' ? $r['supplier'] : '(blank)',
                chat_num($r['qi'], 0),
                chat_num($r['qd'], 0),
                chat_pct($r['qd'], $r['qi']),
            );
        }
        $html .= render_table('Top suppliers by inspected volume', array('Supplier', 'Inspected', 'Defects', 'Defect %'), $rows, null);
    }
    return $html;
}

function handle_top_suppliers($period, $extraSql, $extraParams, $topN, $orderBy) {
    $orderSql = ($orderBy === 'rate') ? 'CASE WHEN SUM(a.Qty_Inspected) > 0 THEN 1.0 * SUM(a.Qty_Defects) / SUM(a.Qty_Inspected) ELSE 0 END DESC'
                                       : 'SUM(a.Qty_Inspected) DESC';
    $sql = "SELECT TOP $topN a.Vendor_Name AS supplier,
                   ISNULL(SUM(a.Total_Qty), 0)     AS tq,
                   ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                   ISNULL(SUM(a.Qty_Defects), 0)   AS qd
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql
              AND a.Vendor_Name IS NOT NULL AND LTRIM(RTRIM(a.Vendor_Name)) <> ''
            GROUP BY a.Vendor_Name
            HAVING SUM(a.Qty_Inspected) > 0
            ORDER BY $orderSql";
    $params = array_merge(array($period['from'], $period['to']), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) { return render_no_data($period); }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            $r['supplier'],
            chat_num($r['tq'], 0),
            chat_num($r['qi'], 0),
            chat_num($r['qd'], 0),
            chat_pct($r['qd'], $r['qi']),
        );
    }
    $title = 'Top ' . $topN . ' suppliers by ' . ($orderBy === 'rate' ? 'defect rate' : 'inspected volume');
    return render_table($title, array('Supplier', 'Total qty', 'Inspected', 'Defects', 'Defect %'), $out, $period);
}

function handle_top_brands($period, $extraSql, $extraParams, $topN, $orderBy) {
    $orderSql = ($orderBy === 'rate') ? 'CASE WHEN SUM(a.Qty_Inspected) > 0 THEN 1.0 * SUM(a.Qty_Defects) / SUM(a.Qty_Inspected) ELSE 0 END DESC'
                                       : 'SUM(a.Qty_Inspected) DESC';
    $sql = "SELECT TOP $topN a.Custome_Name AS brand,
                   ISNULL(SUM(a.Total_Qty), 0)     AS tq,
                   ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                   ISNULL(SUM(a.Qty_Defects), 0)   AS qd
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql
              AND a.Custome_Name IS NOT NULL AND LTRIM(RTRIM(a.Custome_Name)) <> ''
            GROUP BY a.Custome_Name
            HAVING SUM(a.Qty_Inspected) > 0
            ORDER BY $orderSql";
    $params = array_merge(array($period['from'], $period['to']), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) { return render_no_data($period); }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            $r['brand'],
            chat_num($r['tq'], 0),
            chat_num($r['qi'], 0),
            chat_num($r['qd'], 0),
            chat_pct($r['qd'], $r['qi']),
        );
    }
    $title = 'Top ' . $topN . ' brands by ' . ($orderBy === 'rate' ? 'defect rate' : 'inspected volume');
    return render_table($title, array('Brand', 'Total qty', 'Inspected', 'Defects', 'Defect %'), $out, $period);
}

function handle_top_trims($period, $extraSql, $extraParams, $topN, $orderBy) {
    $orderSql = ($orderBy === 'rate') ? 'CASE WHEN SUM(a.Qty_Inspected) > 0 THEN 1.0 * SUM(a.Qty_Defects) / SUM(a.Qty_Inspected) ELSE 0 END DESC'
                                       : 'SUM(a.Qty_Inspected) DESC';
    $sql = "SELECT TOP $topN ISNULL(c.description, '(Unknown)') AS trim_type,
                   ISNULL(SUM(a.Total_Qty), 0)     AS tq,
                   ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                   ISNULL(SUM(a.Qty_Defects), 0)   AS qd
            FROM TRIMS_TBL_INSPECTION a
            LEFT JOIN TRIMS_TBL_DROPDOWN c ON a.System_Trim_Type = c.id AND c.category = 1
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql
            GROUP BY c.description
            HAVING SUM(a.Qty_Inspected) > 0
            ORDER BY $orderSql";
    $params = array_merge(array($period['from'], $period['to']), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) { return render_no_data($period); }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            $r['trim_type'],
            chat_num($r['tq'], 0),
            chat_num($r['qi'], 0),
            chat_num($r['qd'], 0),
            chat_pct($r['qd'], $r['qi']),
        );
    }
    $title = 'Top ' . $topN . ' trim types by ' . ($orderBy === 'rate' ? 'defect rate' : 'inspected volume');
    return render_table($title, array('Trim type', 'Total qty', 'Inspected', 'Defects', 'Defect %'), $out, $period);
}

function handle_top_defects($period, $extraSql, $extraParams, $topN) {
    $sql = "SELECT TOP $topN ISNULL(b.description, '(Unknown)') AS defect_type,
                   ISNULL(SUM(a.Qty_Defects), 0)   AS qd,
                   COUNT(*)                        AS occurrences,
                   ISNULL(SUM(a.Qty_Inspected), 0) AS qi
            FROM TRIMS_TBL_INSPECTION a
            LEFT JOIN TRIMS_TBL_DROPDOWN b ON a.Defect_Type = b.id AND b.category = 2
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql
              AND ISNULL(b.description, '') NOT IN ('NONE', '')
            GROUP BY b.description
            HAVING SUM(a.Qty_Defects) > 0
            ORDER BY SUM(a.Qty_Defects) DESC";
    $params = array_merge(array($period['from'], $period['to']), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) { return render_no_data($period); }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            $r['defect_type'],
            chat_num($r['qd'], 0),
            chat_num($r['occurrences'], 0),
            chat_pct($r['qd'], $r['qi']),
        );
    }
    return render_table('Top ' . $topN . ' defects by quantity', array('Defect type', 'Defect qty', 'Occurrences', '% of inspected'), $out, $period);
}

function handle_result_status($period, $extraSql, $extraParams, $status) {
    // status in PASSED|FAILED|HOLD|REPLACEMENT|PENDING
    if ($status === 'PENDING') {
        $cond = "(a.Result IS NULL OR a.Result = '')";
        $extraParamsLocal = $extraParams;
    } else {
        $cond = "a.Result = ?";
        $extraParamsLocal = array_merge($extraParams, array($status));
    }
    $sql = "SELECT TOP 25
                CONVERT(VARCHAR(10), a.Inspection_Date, 120) AS dt,
                a.IO_num, a.PO_num, a.GR_Num, a.Vessel, a.Voyage, a.Container_Num, a.HBL,
                a.Vendor_Name, a.Custome_Name,
                ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                ISNULL(SUM(a.Qty_Defects),   0) AS qd
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date <  DATEADD(DAY, 1, ?)
              $extraSql
              AND $cond
            GROUP BY CONVERT(VARCHAR(10), a.Inspection_Date, 120),
                     a.IO_num, a.PO_num, a.GR_Num, a.Vessel, a.Voyage, a.Container_Num, a.HBL,
                     a.Vendor_Name, a.Custome_Name
            ORDER BY CONVERT(VARCHAR(10), a.Inspection_Date, 120) DESC";
    $params = array_merge(array($period['from'], $period['to']), $extraParamsLocal);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) { return render_no_data($period); }

    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            $r['dt'],
            $r['IO_num'],
            chat_dash($r['PO_num']),
            chat_dash($r['GR_Num']),
            chat_dash($r['Vessel']),
            chat_dash($r['Voyage']),
            chat_dash($r['Container_Num']),
            chat_dash($r['HBL']),
            $r['Vendor_Name'],
            $r['Custome_Name'],
            chat_num($r['qi'], 0),
            chat_num($r['qd'], 0),
        );
    }
    $titleMap = array(
        'PASSED' => 'Passed inspections',
        'FAILED' => 'Failed inspections',
        'HOLD' => 'Inspections on hold',
        'REPLACEMENT' => 'Replacement inspections',
        'PENDING' => 'Pending (no result yet)',
    );
    return render_table($titleMap[$status], array('Date', 'IO', 'PO', 'GR', 'Vessel', 'Voyage', 'Container', 'HBL', 'Supplier', 'Brand', 'Inspected', 'Defects'), $out, $period);
}

function handle_defect_rate($period, $extraSql, $extraParams) {
    $t = chat_totals($period['from'], $period['to'], $extraSql, $extraParams);
    if (!$t || (int)$t['line_count'] === 0) { return render_no_data($period); }
    $rate = chat_pct($t['qty_defects'], $t['qty_inspected']);

    $html  = '<div class="cb-msg-title">Defect rate <span class="cb-period">' . chat_html($period['label']) . '</span></div>';
    $html .= '<div class="cb-bigstat">' . $rate . '</div>';
    $html .= '<div class="cb-meta">'
          .  '<span>Defects: <b>' . chat_num($t['qty_defects'], 0) . '</b></span>'
          .  '<span>Inspected: <b>' . chat_num($t['qty_inspected'], 0) . '</b></span>'
          .  '<span>Lines: <b>' . chat_num($t['line_count'], 0) . '</b></span>'
          .  '</div>';
    return $html;
}

function handle_count_kind($period, $extraSql, $extraParams, $kind) {
    $t = chat_totals($period['from'], $period['to'], $extraSql, $extraParams);
    if (!$t || (int)$t['line_count'] === 0) { return render_no_data($period); }
    $map = array(
        'count_inspections' => array('label' => 'Inspection lines', 'val' => $t['line_count']),
        'qty_inspected'     => array('label' => 'Quantity inspected', 'val' => $t['qty_inspected']),
        'total_qty'         => array('label' => 'Total quantity', 'val' => $t['total_qty']),
        'qty_defects'       => array('label' => 'Defect quantity', 'val' => $t['qty_defects']),
    );
    $info = $map[$kind];
    $html  = '<div class="cb-msg-title">' . $info['label'] . ' <span class="cb-period">' . chat_html($period['label']) . '</span></div>';
    $html .= '<div class="cb-bigstat">' . chat_num($info['val'], 0) . '</div>';
    $html .= '<div class="cb-meta">'
          .  '<span>Inspected: <b>' . chat_num($t['qty_inspected'], 0) . '</b></span>'
          .  '<span>Defects: <b>' . chat_num($t['qty_defects'], 0) . '</b></span>'
          .  '<span>Defect rate: <b>' . chat_pct($t['qty_defects'], $t['qty_inspected']) . '</b></span>'
          .  '</div>';
    return $html;
}

function handle_compare($extraSql, $extraParams) {
    $today = date('Y-m-d');
    $thisStart = date('Y-m-01');
    $lastStart = date('Y-m-01', strtotime('first day of last month'));
    $lastEnd   = date('Y-m-d',  strtotime('last day of last month'));

    $a = chat_totals($thisStart, $today, $extraSql, $extraParams);
    $b = chat_totals($lastStart, $lastEnd, $extraSql, $extraParams);
    if (!$a && !$b) { return '<div class="cb-msg-title">No data for either period.</div>'; }
    if (!$a) { $a = array('line_count'=>0,'total_qty'=>0,'qty_inspected'=>0,'qty_defects'=>0,'failed_lines'=>0); }
    if (!$b) { $b = array('line_count'=>0,'total_qty'=>0,'qty_inspected'=>0,'qty_defects'=>0,'failed_lines'=>0); }

    $rateA = $a['qty_inspected'] > 0 ? (100.0 * $a['qty_defects'] / $a['qty_inspected']) : 0;
    $rateB = $b['qty_inspected'] > 0 ? (100.0 * $b['qty_defects'] / $b['qty_inspected']) : 0;

    $rows = array(
        array('Inspection lines',  chat_num($a['line_count'], 0),     chat_num($b['line_count'], 0)),
        array('Total qty',         chat_num($a['total_qty'], 0),      chat_num($b['total_qty'], 0)),
        array('Qty inspected',     chat_num($a['qty_inspected'], 0),  chat_num($b['qty_inspected'], 0)),
        array('Qty defects',       chat_num($a['qty_defects'], 0),    chat_num($b['qty_defects'], 0)),
        array('Defect rate',       number_format($rateA, 2) . '%',    number_format($rateB, 2) . '%'),
        array('Failed lines',      chat_num($a['failed_lines'], 0),   chat_num($b['failed_lines'], 0)),
    );
    return render_table('This month vs last month', array('Metric', 'This month', 'Last month'), $rows, null);
}

function handle_annual_monthly($lc, $extraSql, $extraParams) {
    $year = chat_calendar_year_from_message($lc);
    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-12-31', $year);
    $period = array('from' => $from, 'to' => $to, 'label' => 'calendar year ' . $year);

    $sql = "SELECT MONTH(a.Inspection_Date) AS mo,
                   COUNT(*)                              AS line_count,
                   ISNULL(SUM(a.Total_Qty), 0)           AS total_qty,
                   ISNULL(SUM(a.Qty_Inspected), 0)       AS qty_inspected,
                   ISNULL(SUM(a.Qty_Defects), 0)         AS qty_defects,
                   SUM(CASE WHEN a.Result = 'PASSED' THEN 1 ELSE 0 END) AS passed_lines,
                   SUM(CASE WHEN a.Result = 'FAILED' THEN 1 ELSE 0 END) AS failed_lines
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date < DATEADD(DAY, 1, ?)
              $extraSql
            GROUP BY MONTH(a.Inspection_Date)
            ORDER BY MONTH(a.Inspection_Date)";
    $params = array_merge(array($from, $to), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) {
        return render_no_data($period);
    }
    $out = array();
    foreach ($rows as $r) {
        $mo = (int)$r['mo'];
        $mname = date('M', mktime(0, 0, 0, $mo, 1, $year));
        $decided = (int)$r['passed_lines'] + (int)$r['failed_lines'];
        $passPct = ($decided > 0) ? number_format(100.0 * (int)$r['passed_lines'] / $decided, 1) . '%' : '—';
        $out[] = array(
            $mname,
            chat_num($r['line_count'], 0),
            chat_num($r['qty_inspected'], 0),
            chat_num($r['qty_defects'], 0),
            chat_pct($r['qty_defects'], $r['qty_inspected']),
            $passPct,
        );
    }
    $html  = '<div class="cb-msg-title">Monthly performance <span class="cb-period">' . chat_html((string)$year) . '</span></div>';
    $html .= '<div class="cb-meta"><span>Calendar year <b>' . $year . '</b></span></div>';
    $html .= render_table('By month', array('Month', 'Lines', 'Inspected', 'Defects', 'Defect %', 'Pass rate'), $out, null);
    $html .= '<div class="cb-msg-foot">Pass rate uses PASSED vs FAILED lines only. Months with no inspections are omitted.</div>';
    return $html;
}

function handle_annual_yoy($extraSql, $extraParams) {
    $today = date('Y-m-d');
    $startYear = (int)date('Y') - 4;
    $from = sprintf('%04d-01-01', $startYear);

    $sql = "SELECT YEAR(a.Inspection_Date) AS yr,
                   COUNT(*)                              AS line_count,
                   ISNULL(SUM(a.Total_Qty), 0)           AS total_qty,
                   ISNULL(SUM(a.Qty_Inspected), 0)       AS qty_inspected,
                   ISNULL(SUM(a.Qty_Defects), 0)         AS qty_defects,
                   SUM(CASE WHEN a.Result = 'PASSED' THEN 1 ELSE 0 END) AS passed_lines,
                   SUM(CASE WHEN a.Result = 'FAILED' THEN 1 ELSE 0 END) AS failed_lines
            FROM TRIMS_TBL_INSPECTION a
            WHERE a.Inspection_Date >= ?
              AND a.Inspection_Date < DATEADD(DAY, 1, ?)
              $extraSql
            GROUP BY YEAR(a.Inspection_Date)
            ORDER BY YEAR(a.Inspection_Date)";
    $params = array_merge(array($from, $today), $extraParams);
    $rows = dbQuery($sql, $params);
    if (isset($rows['__error']) || empty($rows)) {
        return '<div class="cb-msg-title">No data for yearly comparison.</div><div>Try monthly breakdown for a specific year.</div>';
    }
    $out = array();
    foreach ($rows as $r) {
        $yr = (int)$r['yr'];
        $decided = (int)$r['passed_lines'] + (int)$r['failed_lines'];
        $passPct = ($decided > 0) ? number_format(100.0 * (int)$r['passed_lines'] / $decided, 1) . '%' : '—';
        $out[] = array(
            (string)$yr,
            chat_num($r['line_count'], 0),
            chat_num($r['qty_inspected'], 0),
            chat_num($r['qty_defects'], 0),
            chat_pct($r['qty_defects'], $r['qty_inspected']),
            $passPct,
        );
    }
    $html  = '<div class="cb-msg-title">Year-over-year performance</div>';
    $html .= '<div class="cb-meta"><span>Shows <b>' . $startYear . '</b> through <b>' . date('Y') . '</b> (up to today)</span></div>';
    $html .= render_table('By calendar year', array('Year', 'Lines', 'Inspected', 'Defects', 'Defect %', 'Pass rate'), $out, null);
    $html .= '<div class="cb-msg-foot">Recent years may be partial (YTD). Pass rate excludes non-PASS/FAIL results.</div>';
    return $html;
}

function handle_recent($extraSql, $extraParams) {
    $sql = "SELECT TOP 15
                CONVERT(VARCHAR(10), a.Inspection_Date, 120) AS dt,
                a.IO_num, a.PO_num, a.GR_Num, a.Vessel, a.Voyage, a.Container_Num, a.HBL,
                a.Vendor_Name, a.Custome_Name,
                a.Qty_Inspected, a.Qty_Defects, a.Result
            FROM TRIMS_TBL_INSPECTION a
            WHERE 1=1
              $extraSql
            ORDER BY a.Inspection_Date DESC, a.id DESC";
    $rows = dbQuery($sql, $extraParams);
    if (isset($rows['__error']) || empty($rows)) {
        return '<div class="cb-msg-title">No recent inspections found.</div>';
    }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            $r['dt'], $r['IO_num'],
            chat_dash($r['PO_num']),
            chat_dash($r['GR_Num']),
            chat_dash($r['Vessel']),
            chat_dash($r['Voyage']),
            chat_dash($r['Container_Num']),
            chat_dash($r['HBL']),
            $r['Vendor_Name'], $r['Custome_Name'],
            chat_num($r['Qty_Inspected'], 0), chat_num($r['Qty_Defects'], 0),
            $r['Result'] !== '' && $r['Result'] !== null ? $r['Result'] : 'PENDING',
        );
    }
    return render_table('Recent inspections', array('Date', 'IO', 'PO', 'GR', 'Vessel', 'Voyage', 'Container', 'HBL', 'Supplier', 'Brand', 'Inspected', 'Defects', 'Result'), $out, null);
}

function handle_supplier_detail($period, $msg) {
    // Try to parse supplier name
    $name = '';
    if (preg_match('/(?:supplier|vendor)\s+([\w\-\.&\/][\w\-\.&\/\s]{0,80})/i', $msg, $m)) {
        $name = trim($m[1]);
        // strip trailing trigger words
        $name = preg_replace('/\s+(this|last|today|yesterday|in|on|for|from|to)\s+.*$/i', '', $name);
        $name = trim($name, " ?.,");
    }
    if ($name === '') {
        return '<div class="cb-msg-title">Which supplier?</div><div>Try: "supplier ABC this month"</div>';
    }
    // Fuzzy match
    $like = '%' . $name . '%';
    $look = dbQuery("SELECT DISTINCT TOP 1 Vendor_Name FROM TRIMS_TBL_INSPECTION WHERE Vendor_Name LIKE ? ORDER BY Vendor_Name", array($like));
    if (isset($look['__error']) || empty($look)) {
        return '<div class="cb-msg-title">No supplier matched "' . chat_html($name) . '".</div>';
    }
    $matched = $look[0]['Vendor_Name'];
    $extraSql    = ' AND a.Vendor_Name = ?';
    $extraParams = array($matched);
    $t = chat_totals($period['from'], $period['to'], $extraSql, $extraParams);
    if (!$t || (int)$t['line_count'] === 0) {
        return render_no_data($period) . '<div class="cb-meta"><span>Matched supplier: <b>' . chat_html($matched) . '</b></span></div>';
    }
    $html = render_kpis($t, $period, 'Supplier: ' . $matched);

    // Trim breakdown
    $sql = "SELECT TOP 10 ISNULL(c.description, '(Unknown)') AS trim_type,
                   ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                   ISNULL(SUM(a.Qty_Defects), 0)   AS qd
            FROM TRIMS_TBL_INSPECTION a
            LEFT JOIN TRIMS_TBL_DROPDOWN c ON a.System_Trim_Type = c.id AND c.category = 1
            WHERE a.Inspection_Date >= ? AND a.Inspection_Date < DATEADD(DAY, 1, ?)
              AND a.Vendor_Name = ?
            GROUP BY c.description
            HAVING SUM(a.Qty_Inspected) > 0
            ORDER BY SUM(a.Qty_Defects) DESC";
    $rows = dbQuery($sql, array($period['from'], $period['to'], $matched));
    if (!isset($rows['__error']) && !empty($rows)) {
        $out = array();
        foreach ($rows as $r) {
            $out[] = array($r['trim_type'], chat_num($r['qi'], 0), chat_num($r['qd'], 0), chat_pct($r['qd'], $r['qi']));
        }
        $html .= render_table('Trim breakdown', array('Trim type', 'Inspected', 'Defects', 'Defect %'), $out, null);
    }
    return $html;
}

function handle_brand_detail($period, $msg) {
    $name = '';
    if (preg_match('/(?:brand|customer)\s+([\w\-\.&\/][\w\-\.&\/\s]{0,80})/i', $msg, $m)) {
        $name = trim($m[1]);
        $name = preg_replace('/\s+(this|last|today|yesterday|in|on|for|from|to)\s+.*$/i', '', $name);
        $name = trim($name, " ?.,");
    }
    if ($name === '') {
        return '<div class="cb-msg-title">Which brand?</div><div>Try: "brand ACME this month"</div>';
    }
    $like = '%' . $name . '%';
    $look = dbQuery("SELECT DISTINCT TOP 1 Custome_Name FROM TRIMS_TBL_INSPECTION WHERE Custome_Name LIKE ? ORDER BY Custome_Name", array($like));
    if (isset($look['__error']) || empty($look)) {
        return '<div class="cb-msg-title">No brand matched "' . chat_html($name) . '".</div>';
    }
    $matched = $look[0]['Custome_Name'];
    $t = chat_totals($period['from'], $period['to'], ' AND a.Custome_Name = ?', array($matched));
    if (!$t || (int)$t['line_count'] === 0) {
        return render_no_data($period) . '<div class="cb-meta"><span>Matched brand: <b>' . chat_html($matched) . '</b></span></div>';
    }
    return render_kpis($t, $period, 'Brand: ' . $matched);
}

function handle_io_detail($msg) {
    if (!preg_match('/\bio\s*(?:#|number|num|no\.?)?\s*([\w\-]+)/i', $msg, $m)) {
        return '<div class="cb-msg-title">Which IO number?</div><div>Try: "IO 123456"</div>';
    }
    $io = trim($m[1]);
    $sql = "SELECT TOP 50
                CONVERT(VARCHAR(10), a.Inspection_Date, 120) AS dt,
                a.IO_num, a.PO_num, a.GR_Num, a.Vessel, a.Voyage, a.Container_Num, a.HBL,
                a.Vendor_Name, a.Custome_Name,
                ISNULL(c.description, '(Unknown)') AS trim_type,
                a.Qty_Inspected, a.Qty_Defects, a.Result
            FROM TRIMS_TBL_INSPECTION a
            LEFT JOIN TRIMS_TBL_DROPDOWN c ON a.System_Trim_Type = c.id AND c.category = 1
            WHERE a.IO_num = ?
            ORDER BY a.Inspection_Date DESC, a.id DESC";
    $rows = dbQuery($sql, array($io));
    if (isset($rows['__error']) || empty($rows)) {
        return '<div class="cb-msg-title">No inspections found for IO ' . chat_html($io) . '.</div>';
    }
    $out = array();
    $totQi = 0; $totQd = 0;
    foreach ($rows as $r) {
        $totQi += (int)$r['Qty_Inspected'];
        $totQd += (int)$r['Qty_Defects'];
        $out[] = array(
            $r['dt'],
            chat_dash($r['PO_num']),
            chat_dash($r['GR_Num']),
            chat_dash($r['Vessel']),
            chat_dash($r['Voyage']),
            chat_dash($r['Container_Num']),
            chat_dash($r['HBL']),
            $r['Vendor_Name'], $r['Custome_Name'], $r['trim_type'],
            chat_num($r['Qty_Inspected'], 0), chat_num($r['Qty_Defects'], 0),
            $r['Result'] !== '' && $r['Result'] !== null ? $r['Result'] : 'PENDING',
        );
    }
    $html  = '<div class="cb-msg-title">IO ' . chat_html($io) . '</div>';
    $html .= '<div class="cb-meta">'
          .  '<span>Lines: <b>' . count($rows) . '</b></span>'
          .  '<span>Inspected: <b>' . chat_num($totQi, 0) . '</b></span>'
          .  '<span>Defects: <b>' . chat_num($totQd, 0) . '</b></span>'
          .  '<span>Defect rate: <b>' . chat_pct($totQd, $totQi) . '</b></span>'
          .  '</div>';
    $html .= render_table('Inspection history', array('Date', 'PO', 'GR', 'Vessel', 'Voyage', 'Container', 'HBL', 'Supplier', 'Brand', 'Trim type', 'Inspected', 'Defects', 'Result'), $out, null);
    return $html;
}

// ─────────────────────────────────────────────
//  Insights / recommendations engine
// ─────────────────────────────────────────────

// Severity thresholds (defect rate %)
function chat_sev_thresholds() {
    return array(
        'critical' => 5.0,   // >= 5% is critical
        'warning'  => 2.0,   // >= 2% is warning
        'good_max' => 1.0,   // <= 1% is good
    );
}

function chat_sev_for_rate($pct) {
    $t = chat_sev_thresholds();
    if ($pct >= $t['critical']) { return 'critical'; }
    if ($pct >= $t['warning'])  { return 'warning'; }
    if ($pct <= $t['good_max']) { return 'good'; }
    return 'info';
}

function chat_sev_label($sev) {
    $map = array(
        'critical' => 'CRITICAL',
        'warning'  => 'WARNING',
        'info'     => 'INFO',
        'good'     => 'GOOD',
    );
    return isset($map[$sev]) ? $map[$sev] : 'INFO';
}

function render_insight($sev, $title, $body, $action) {
    $html  = '<div class="cb-insight cb-sev-' . chat_html($sev) . '">';
    $html .= '<div class="cb-insight-head">';
    $html .= '<span class="cb-sev-badge cb-sev-' . chat_html($sev) . '">' . chat_sev_label($sev) . '</span>';
    $html .= '<span class="cb-insight-title">' . chat_html($title) . '</span>';
    $html .= '</div>';
    if ($body !== '') {
        $html .= '<div class="cb-insight-body">' . $body . '</div>';
    }
    if ($action !== '') {
        $html .= '<div class="cb-insight-action"><b>Suggested action:</b> ' . chat_html($action) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function handle_insights($period, $extraSql, $extraParams) {
    $t = chat_totals($period['from'], $period['to'], $extraSql, $extraParams);
    if (!$t || (int)$t['line_count'] === 0) { return render_no_data($period); }

    $insights = array();
    $rate = $t['qty_inspected'] > 0 ? (100.0 * $t['qty_defects'] / $t['qty_inspected']) : 0;

    // ── 1. Overall defect rate
    $sev = chat_sev_for_rate($rate);
    $rateBody = '<div>Overall defect rate is <b>' . number_format($rate, 2) . '%</b> across '
              . chat_num($t['qty_inspected'], 0) . ' inspected units (' . chat_num($t['qty_defects'], 0) . ' defects).</div>';
    $rateAction = '';
    if ($sev === 'critical') { $rateAction = 'Escalate to QA leadership and tighten incoming inspection criteria immediately.'; }
    elseif ($sev === 'warning') { $rateAction = 'Investigate the top defective suppliers and trim types listed below.'; }
    elseif ($sev === 'good') { $rateAction = 'Quality is healthy — keep monitoring trend lines.'; }
    else { $rateAction = 'Continue routine monitoring and benchmark against last period.'; }
    $insights[] = render_insight($sev, 'Overall quality trend (' . $period['label'] . ')', $rateBody, $rateAction);

    // ── 1b. Pass rate (PASSED vs FAILED lines) and remediation for low %
    $decidedLines = (int)$t['passed_lines'] + (int)$t['failed_lines'];
    if ($decidedLines >= 10) {
        $passPct = 100.0 * (int)$t['passed_lines'] / $decidedLines;
        if ($passPct < 90.0) {
            if ($passPct < 75.0) {
                $sevPR = 'critical';
            } elseif ($passPct < 85.0) {
                $sevPR = 'warning';
            } else {
                $sevPR = 'info';
            }
            $bodyPR = '<div>Line-level pass rate (PASSED vs FAILED) is <b>' . number_format($passPct, 1) . '%</b> — '
                    . chat_num($t['passed_lines'], 0) . ' passed, ' . chat_num($t['failed_lines'], 0) . ' failed '
                    . 'out of ' . chat_num($decidedLines, 0) . ' decided lines.</div>'
                    . '<div style="margin-top:6px;">This is separate from defect % (defects per inspected unit). Both matter: you can have many tiny defects on lines that still pass, or few lines that fail outright.</div>';
            $actPR  = 'Triage: (1) Run "Top defects" and "Worst suppliers by defect rate" for this period. (2) For the dominant defect type, add or tighten a focused incoming check (sample size, photos, gauges). (3) Open CAPA with the worst suppliers; hold or sort higher risk until verification. (4) Re-train inspectors on the failure criteria that drove FAILED lines. (5) Clear pending results so pass rate reflects reality.';
            $insights[] = render_insight($sevPR, 'Low pass rate — how to improve', $bodyPR, $actPR);
        }
    }

    // ── 2. Period-over-period trend (vs previous equal-length period)
    $fromTs = strtotime($period['from']);
    $toTs   = strtotime($period['to']);
    $days   = max(1, (int)(($toTs - $fromTs) / 86400) + 1);
    $prevTo   = date('Y-m-d', strtotime($period['from'] . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    $prev = chat_totals($prevFrom, $prevTo, $extraSql, $extraParams);
    if ($prev && (int)$prev['line_count'] > 0) {
        $prevRate = $prev['qty_inspected'] > 0 ? (100.0 * $prev['qty_defects'] / $prev['qty_inspected']) : 0;
        $delta    = $rate - $prevRate;
        $absDelta = abs($delta);
        if ($absDelta >= 0.5) {
            if ($delta > 0) {
                $sevT = ($absDelta >= 2.0) ? 'critical' : 'warning';
                $bodyT = '<div>Defect rate climbed from <b>' . number_format($prevRate, 2) . '%</b> to <b>'
                       . number_format($rate, 2) . '%</b> (<b>+' . number_format($absDelta, 2) . ' pts</b>) versus the previous '
                       . $days . '-day period.</div>';
                $actT  = 'Open a comparison report and identify which supplier or trim drove the regression.';
                $insights[] = render_insight($sevT, 'Defect rate is rising', $bodyT, $actT);
            } else {
                $bodyT = '<div>Defect rate improved from <b>' . number_format($prevRate, 2) . '%</b> to <b>'
                       . number_format($rate, 2) . '%</b> (<b>-' . number_format($absDelta, 2) . ' pts</b>) versus the previous '
                       . $days . '-day period.</div>';
                $actT  = 'Document what changed and reinforce the practice with the suppliers responsible.';
                $insights[] = render_insight('good', 'Defect rate is improving', $bodyT, $actT);
            }
        }
    }

    // ── 3. Worst supplier
    $sqlWS = "SELECT TOP 3 a.Vendor_Name AS supplier,
                     ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                     ISNULL(SUM(a.Qty_Defects),   0) AS qd
              FROM TRIMS_TBL_INSPECTION a
              WHERE a.Inspection_Date >= ? AND a.Inspection_Date < DATEADD(DAY, 1, ?)
                $extraSql
                AND a.Vendor_Name IS NOT NULL AND LTRIM(RTRIM(a.Vendor_Name)) <> ''
              GROUP BY a.Vendor_Name
              HAVING SUM(a.Qty_Inspected) >= 30
              ORDER BY CASE WHEN SUM(a.Qty_Inspected) > 0
                            THEN 1.0 * SUM(a.Qty_Defects) / SUM(a.Qty_Inspected)
                            ELSE 0 END DESC";
    $params = array_merge(array($period['from'], $period['to']), $extraParams);
    $worstSup = dbQuery($sqlWS, $params);
    if (!isset($worstSup['__error']) && !empty($worstSup)) {
        $top = $worstSup[0];
        $supRate = $top['qi'] > 0 ? (100.0 * $top['qd'] / $top['qi']) : 0;
        $sevS = chat_sev_for_rate($supRate);
        if ($sevS === 'critical' || $sevS === 'warning') {
            $bodyS = '<div><b>' . chat_html($top['supplier']) . '</b> is at <b>'
                   . number_format($supRate, 2) . '%</b> defect rate ('
                   . chat_num($top['qd'], 0) . ' / ' . chat_num($top['qi'], 0) . ' units).</div>';
            if (count($worstSup) > 1) {
                $others = array();
                for ($i = 1; $i < count($worstSup); $i++) {
                    $r2 = $worstSup[$i];
                    $rt2 = $r2['qi'] > 0 ? (100.0 * $r2['qd'] / $r2['qi']) : 0;
                    if ($rt2 >= 1.5) {
                        $others[] = chat_html($r2['supplier']) . ' (' . number_format($rt2, 1) . '%)';
                    }
                }
                if (!empty($others)) {
                    $bodyS .= '<div style="margin-top:4px;">Also watch: ' . implode(', ', $others) . '.</div>';
                }
            }
            $actS = ($sevS === 'critical')
                ? 'Schedule a supplier audit and consider holding shipments until corrective action is verified.'
                : 'Request a corrective-action plan and increase inspection frequency.';
            $insights[] = render_insight($sevS, 'Worst-performing supplier', $bodyS, $actS);
        }
    }

    // ── 4. Worst trim type
    $sqlWT = "SELECT TOP 1 ISNULL(c.description, '(Unknown)') AS trim_type,
                     ISNULL(SUM(a.Qty_Inspected), 0) AS qi,
                     ISNULL(SUM(a.Qty_Defects),   0) AS qd
              FROM TRIMS_TBL_INSPECTION a
              LEFT JOIN TRIMS_TBL_DROPDOWN c ON a.System_Trim_Type = c.id AND c.category = 1
              WHERE a.Inspection_Date >= ? AND a.Inspection_Date < DATEADD(DAY, 1, ?)
                $extraSql
              GROUP BY c.description
              HAVING SUM(a.Qty_Inspected) >= 30
              ORDER BY CASE WHEN SUM(a.Qty_Inspected) > 0
                            THEN 1.0 * SUM(a.Qty_Defects) / SUM(a.Qty_Inspected)
                            ELSE 0 END DESC";
    $worstTrim = dbQuery($sqlWT, $params);
    if (!isset($worstTrim['__error']) && !empty($worstTrim)) {
        $tw = $worstTrim[0];
        $trimRate = $tw['qi'] > 0 ? (100.0 * $tw['qd'] / $tw['qi']) : 0;
        $sevT = chat_sev_for_rate($trimRate);
        if ($sevT === 'critical' || $sevT === 'warning') {
            $bodyT = '<div><b>' . chat_html($tw['trim_type']) . '</b> trim is at <b>'
                   . number_format($trimRate, 2) . '%</b> defect rate (' . chat_num($tw['qd'], 0)
                   . ' / ' . chat_num($tw['qi'], 0) . ' units).</div>';
            $actT  = 'Drill into Module 5 to see which defect categories dominate this trim type.';
            $insights[] = render_insight($sevT, 'Trim type with highest defect rate', $bodyT, $actT);
        }
    }

    // ── 5. Top defect category
    $sqlD = "SELECT TOP 1 ISNULL(b.description, '(Unknown)') AS defect_type,
                    ISNULL(SUM(a.Qty_Defects), 0) AS qd
             FROM TRIMS_TBL_INSPECTION a
             LEFT JOIN TRIMS_TBL_DROPDOWN b ON a.Defect_Type = b.id AND b.category = 2
             WHERE a.Inspection_Date >= ? AND a.Inspection_Date < DATEADD(DAY, 1, ?)
               $extraSql
               AND ISNULL(b.description, '') NOT IN ('NONE', '')
             GROUP BY b.description
             HAVING SUM(a.Qty_Defects) > 0
             ORDER BY SUM(a.Qty_Defects) DESC";
    $topDef = dbQuery($sqlD, $params);
    if (!isset($topDef['__error']) && !empty($topDef) && (int)$t['qty_defects'] > 0) {
        $td = $topDef[0];
        $share = 100.0 * (float)$td['qd'] / (float)$t['qty_defects'];
        if ($share >= 25.0) {
            $sevD = ($share >= 50.0) ? 'critical' : 'warning';
            $bodyD = '<div><b>' . chat_html($td['defect_type']) . '</b> accounts for <b>'
                   . number_format($share, 1) . '%</b> of all defect units ('
                   . chat_num($td['qd'], 0) . ' of ' . chat_num($t['qty_defects'], 0) . ').</div>';
            $actD  = 'Run a focused root-cause analysis on this defect category — fixing it would move the needle most.';
            $insights[] = render_insight($sevD, 'One defect type dominates', $bodyD, $actD);
        }
    }

    // ── 6. Pending backlog
    if ((int)$t['pending_lines'] > 0) {
        $pendShare = 100.0 * (int)$t['pending_lines'] / max(1, (int)$t['line_count']);
        if ((int)$t['pending_lines'] >= 5 || $pendShare >= 10) {
            $sevP = ($pendShare >= 25 || (int)$t['pending_lines'] >= 25) ? 'warning' : 'info';
            $bodyP = '<div><b>' . chat_num($t['pending_lines'], 0) . '</b> inspection lines are still pending ('
                   . number_format($pendShare, 1) . '% of all lines).</div>';
            $actP  = 'Ask the QA team to clear pending lines so the dashboard reflects current quality.';
            $insights[] = render_insight($sevP, 'Pending inspection backlog', $bodyP, $actP);
        }
    }

    // ── 7. Hold / Replacement signal
    $holdRepl = (int)$t['hold_lines'] + (int)$t['replacement_lines'];
    if ($holdRepl > 0) {
        $shareHR = 100.0 * $holdRepl / max(1, (int)$t['line_count']);
        if ($shareHR >= 5) {
            $sevH = ($shareHR >= 15) ? 'warning' : 'info';
            $bodyH = '<div><b>' . chat_num($t['hold_lines'], 0) . '</b> on hold and <b>'
                   . chat_num($t['replacement_lines'], 0) . '</b> replacements ('
                   . number_format($shareHR, 1) . '% of lines combined).</div>';
            $actH  = 'Review held/replacement IOs to unblock production and document supplier accountability.';
            $insights[] = render_insight($sevH, 'Material on hold or replacement', $bodyH, $actH);
        }
    }

    // ── 8. Inspection coverage (inspected / total qty)
    if ((int)$t['total_qty'] > 0) {
        $coverage = 100.0 * (int)$t['qty_inspected'] / (int)$t['total_qty'];
        if ($coverage < 60) {
            $sevC = ($coverage < 30) ? 'warning' : 'info';
            $bodyC = '<div>Only <b>' . number_format($coverage, 1) . '%</b> of total quantity ('
                   . chat_num($t['qty_inspected'], 0) . ' of ' . chat_num($t['total_qty'], 0)
                   . ' units) has been inspected.</div>';
            $actC  = 'Verify with QA that sampling levels match plan, or schedule remaining inspections.';
            $insights[] = render_insight($sevC, 'Low inspection coverage', $bodyC, $actC);
        }
    }

    // ── Compose response
    $countAll = count($insights);
    if ($countAll === 0) {
        $insights[] = render_insight('good', 'Nothing concerning right now', '<div>All checks within healthy thresholds for ' . chat_html($period['label']) . '.</div>', 'Keep monitoring weekly.');
    }

    $html  = '<div class="cb-msg-title">Insights &amp; recommendations <span class="cb-period">' . chat_html($period['label']) . '</span></div>';
    $html .= '<div class="cb-insight-intro">Found <b>' . $countAll . '</b> ' . ($countAll === 1 ? 'item' : 'items') . ' to review, ranked by impact.</div>';
    $html .= '<div class="cb-insight-list">' . implode('', $insights) . '</div>';
    return $html;
}

// ─────────────────────────────────────────────
//  Follow-up question chips (contextual)
// ─────────────────────────────────────────────
function build_followups($intent, $period, $msg) {
    $f = array();
    switch ($intent) {
        case 'summary':
            $f = array('Give me insights', 'Annual performance', 'Top 5 suppliers by defect rate', 'Compare this month vs last month');
            break;
        case 'top_suppliers':
            $f = array('Top suppliers by volume', 'Top 10 suppliers by defect rate', 'Worst trim types ' . $period['label'], 'Give me insights');
            break;
        case 'top_brands':
            $f = array('Top brands by volume', 'Top 5 suppliers by defect rate', 'Top defects ' . $period['label'], 'Give me insights');
            break;
        case 'top_trims':
            $f = array('Top defects ' . $period['label'], 'Worst trim types last week', 'Defect rate ' . $period['label'], 'Give me insights');
            break;
        case 'top_defects':
            $f = array('Top 5 suppliers by defect rate', 'Worst trim types ' . $period['label'], 'Failed inspections ' . $period['label'], 'Give me insights');
            break;
        case 'failed':
            $f = array('Top 5 suppliers by defect rate', 'Pending inspections', 'Give me insights', 'Defect rate ' . $period['label']);
            break;
        case 'pending':
            $f = array('Failed inspections ' . $period['label'], 'Recent inspections', 'Summary ' . $period['label'], 'Give me insights');
            break;
        case 'hold':
        case 'replacement':
            $f = array('Failed inspections ' . $period['label'], 'Top 5 suppliers by defect rate', 'Give me insights', 'Recent inspections');
            break;
        case 'defect_rate':
            $f = array('Compare this month vs last month', 'Top 5 suppliers by defect rate', 'Top defects ' . $period['label'], 'Give me insights');
            break;
        case 'count_inspections':
        case 'qty_inspected':
        case 'total_qty':
        case 'qty_defects':
            $f = array('Defect rate ' . $period['label'], 'Top 5 suppliers by defect rate', 'Compare this month vs last month', 'Give me insights');
            break;
        case 'compare':
            $f = array('Give me insights', 'Year over year', 'Monthly breakdown this year', 'Top 5 suppliers by defect rate this month');
            break;
        case 'annual_monthly':
            $f = array('Year over year', 'Give me insights', 'Summary this year', 'Top defects this year');
            break;
        case 'annual_yoy':
            $f = array('Monthly breakdown ' . date('Y'), 'Give me insights', 'Compare this month vs last month', 'Top 5 suppliers by defect rate');
            break;
        case 'recent':
            $f = array('Failed inspections this week', 'Pending inspections', 'Summary this week', 'Give me insights');
            break;
        case 'supplier_detail':
            $f = array('Top defects ' . $period['label'], 'Compare this month vs last month', 'Failed inspections ' . $period['label'], 'Give me insights');
            break;
        case 'brand_detail':
            $f = array('Top 5 suppliers by defect rate', 'Top defects ' . $period['label'], 'Give me insights', 'Compare this month vs last month');
            break;
        case 'io_detail':
            $f = array('Recent inspections', 'Failed inspections this week', 'Give me insights', 'Top defects this month');
            break;
        case 'insights':
            $f = array('Top 5 suppliers by defect rate', 'Top defects ' . $period['label'], 'Compare this month vs last month', 'Failed inspections ' . $period['label']);
            break;
        case 'help':
        case 'greet':
            $f = array('Summary this month', 'Annual performance', 'Year over year', 'Give me insights');
            break;
        default:
            $f = array('Give me insights', 'Annual performance', 'Summary this month', 'Top 5 suppliers by defect rate');
            break;
    }
    // De-duplicate while preserving order
    $seen = array(); $clean = array();
    foreach ($f as $q) {
        $k = strtolower(trim($q));
        if ($k === '' || isset($seen[$k])) { continue; }
        $seen[$k] = true; $clean[] = $q;
    }
    return array_slice($clean, 0, 4);
}

function render_followups($items) {
    if (empty($items)) { return ''; }
    $html = '<div class="cb-followups"><div class="cb-followups-label">Try next:</div><div class="cb-followups-row">';
    foreach ($items as $q) {
        $html .= '<button type="button" class="cb-followup-chip" data-cb-ask="' . chat_html($q) . '">' . chat_html($q) . '</button>';
    }
    $html .= '</div></div>';
    return $html;
}

// ─────────────────────────────────────────────
//  Dispatch
// ─────────────────────────────────────────────
$intent = chat_intent($lc);
$period = chat_period($lc);
$orderBy = (strpos($lc, 'rate') !== false || strpos($lc, 'worst') !== false || strpos($lc, 'highest defect') !== false) ? 'rate' : 'volume';
$topN    = chat_extract_top_n($lc, 5);

$extraSql = '';
$extraParams = array();

$html = '';
switch ($intent) {
    case 'help':            $html = render_help(); break;
    case 'greet':           $html = render_greet(); break;
    case 'insights':        $html = handle_insights($period, $extraSql, $extraParams); break;
    case 'top_suppliers':   $html = handle_top_suppliers($period, $extraSql, $extraParams, $topN, $orderBy); break;
    case 'top_brands':      $html = handle_top_brands($period, $extraSql, $extraParams, $topN, $orderBy); break;
    case 'top_trims':       $html = handle_top_trims($period, $extraSql, $extraParams, $topN, $orderBy); break;
    case 'top_defects':     $html = handle_top_defects($period, $extraSql, $extraParams, $topN); break;
    case 'failed':          $html = handle_result_status($period, $extraSql, $extraParams, 'FAILED'); break;
    case 'passed':          $html = handle_result_status($period, $extraSql, $extraParams, 'PASSED'); break;
    case 'pending':         $html = handle_result_status($period, $extraSql, $extraParams, 'PENDING'); break;
    case 'hold':            $html = handle_result_status($period, $extraSql, $extraParams, 'HOLD'); break;
    case 'replacement':     $html = handle_result_status($period, $extraSql, $extraParams, 'REPLACEMENT'); break;
    case 'defect_rate':     $html = handle_defect_rate($period, $extraSql, $extraParams); break;
    case 'count_inspections':
    case 'qty_inspected':
    case 'total_qty':
    case 'qty_defects':     $html = handle_count_kind($period, $extraSql, $extraParams, $intent); break;
    case 'compare':         $html = handle_compare($extraSql, $extraParams); break;
    case 'annual_monthly':  $html = handle_annual_monthly($lc, $extraSql, $extraParams); break;
    case 'annual_yoy':      $html = handle_annual_yoy($extraSql, $extraParams); break;
    case 'recent':          $html = handle_recent($extraSql, $extraParams); break;
    case 'supplier_detail': $html = handle_supplier_detail($period, $msg); break;
    case 'brand_detail':    $html = handle_brand_detail($period, $msg); break;
    case 'io_detail':       $html = handle_io_detail($msg); break;
    default:                $html = handle_summary($period, $extraSql, $extraParams); break;
}

// Append contextual follow-up suggestions to every response
$html .= render_followups(build_followups($intent, $period, $msg));

echo json_encode(array(
    'intent' => $intent,
    'period' => $period,
    'html'   => $html,
));
exit;
?>
