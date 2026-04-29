<?php
// ─────────────────────────────────────────────
//  Module 1 – Encoding
//  PHP 5.3  |  mssql native
// ─────────────────────────────────────────────
require_once dirname(__FILE__) . '/config.php';

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = isset($_GET['ajax']) ? $_GET['ajax'] : '';

    if ($action === 'search_io') {
        $io = isset($_GET['io']) ? trim($_GET['io']) : '';
        if ($io === '') { echo json_encode(array('error' => 'IO Number required')); exit; }
        $rows = dbQuery("SELECT TOP 1 IO_Num, Customer_Name FROM TRIMS_TBL_RAWDATA WHERE IO_Num = ?", array($io));
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        if (empty($rows))            { echo json_encode(array('error' => 'IO Number not found')); exit; }

        $poRows = dbQuery(
            "SELECT DISTINCT PO_Num FROM TRIMS_TBL_RAWDATA WHERE IO_Num = ? AND PO_Num IS NOT NULL AND LTRIM(RTRIM(PO_Num)) <> '' ORDER BY PO_Num",
            array($io)
        );
        if (isset($poRows['__error'])) { echo json_encode(array('error' => $poRows['__error'])); exit; }

        $poNums = array();
        for ($i = 0; $i < count($poRows); $i++) {
            $poNums[] = $poRows[$i]['PO_Num'];
        }
        $rows[0]['PO_Nums'] = $poNums;

        echo json_encode(array('success' => true, 'io' => $rows[0]));
        exit;
    }

    if ($action === 'get_suppliers') {
        $io = isset($_GET['io']) ? trim($_GET['io']) : '';
        $rows = dbQuery(
            "SELECT DISTINCT Vendor_Name, GMC_Description, Addl_Description, PO_Qty, Customer_Name, PO_Num
             FROM TRIMS_TBL_RAWDATA WHERE IO_Num = ? ORDER BY Vendor_Name, PO_Num, GMC_Description",
            array($io)
        );
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        echo json_encode($rows); exit;
    }

    if ($action === 'get_brands') {
        $rows = dbQuery("SELECT id, description FROM TRIMS_TBL_DROPDOWN WHERE category = 3 ORDER BY description", array());
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        echo json_encode($rows); exit;
    }

    if ($action === 'get_defects') {
        $rows = dbQuery("SELECT id, description FROM TRIMS_TBL_DROPDOWN WHERE category = 2 ORDER BY description", array());
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        echo json_encode($rows); exit;
    }

    if ($action === 'get_months') {
        $rows = dbQuery("SELECT id, description FROM TRIMS_TBL_WEEKMONTH WHERE category = 2 ORDER BY seq", array());
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        echo json_encode($rows); exit;
    }

    if ($action === 'get_weeks') {
        $rows = dbQuery("SELECT id, description FROM TRIMS_TBL_WEEKMONTH WHERE category = 1 ORDER BY seq", array());
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        echo json_encode($rows); exit;
    }

    if ($action === 'get_system_trims') {
        $rows = dbQuery("SELECT id, description FROM TRIMS_TBL_DROPDOWN WHERE category = 1 ORDER BY description", array());
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        echo json_encode($rows); exit;
    }

    if ($action === 'get_rows') {
        $io = isset($_GET['io']) ? trim($_GET['io']) : '';
        $rows = dbQuery(
            "SELECT id, Vendor_Name, IO_num, PO_num, GMC_Description, Addl_Description,
                    Custome_Name, Defect_Type, System_Trim_Type, Month, Week, Inspection_Date,
                    Total_Qty, Qty_Inspected, Qty_Defects, Result
             FROM TRIMS_TBL_INSPECTION WHERE IO_num = ? ORDER BY id",
            array($io)
        );
        if (isset($rows['__error'])) { echo json_encode(array('error' => $rows['__error'])); exit; }
        for ($i = 0; $i < count($rows); $i++) {
            if (!empty($rows[$i]['Inspection_Date'])) {
                $rows[$i]['Inspection_Date'] = substr($rows[$i]['Inspection_Date'], 0, 10);
            }
        }
        echo json_encode($rows); exit;
    }

    if ($action === 'save_row' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) { echo json_encode(array('error' => 'Invalid JSON payload')); exit; }

        $required = array(
            'IO_num', 'PO_num', 'Vendor_Name', 'GMC_Description', 'Addl_Description',
            'Custome_Name', 'Defect_Type', 'System_Trim_Type', 'Total_Qty',
            'Qty_Inspected', 'Qty_Defects', 'Result', 'Month', 'Week', 'Inspection_Date'
        );
        foreach ($required as $f) {
            if (!isset($data[$f]) || $data[$f] === '') {
                echo json_encode(array('error' => 'Missing field: ' . $f)); exit;
            }
        }

        // Validate Result is one of the allowed values
        $allowedResults = array('PASSED', 'FAILED', 'HOLD', 'REPLACEMENT');
        if (!in_array($data['Result'], $allowedResults)) {
            echo json_encode(array('error' => 'Invalid Result value')); exit;
        }

        $qtyDef       = (int)$data['Qty_Defects'];
        $qtyInspected = (int)$data['Qty_Inspected'];
        $result       = $data['Result'];
        $rowId        = isset($data['row_id']) ? (int)$data['row_id'] : 0;

        if ($rowId > 0) {
            $ok = dbExec(
                "UPDATE TRIMS_TBL_INSPECTION
                 SET PO_num=?, Vendor_Name=?, GMC_Description=?, Addl_Description=?,
                     Custome_Name=?, Defect_Type=?, System_Trim_Type=?,
                     Month=?, Week=?, Inspection_Date=?,
                     Total_Qty=?, Qty_Inspected=?, Qty_Defects=?, Result=?
                 WHERE id=?",
                array(
                    $data['PO_num'], $data['Vendor_Name'], $data['GMC_Description'], $data['Addl_Description'],
                    $data['Custome_Name'], $data['Defect_Type'], $data['System_Trim_Type'],
                    $data['Month'], $data['Week'], $data['Inspection_Date'],
                    (int)$data['Total_Qty'], $qtyInspected, $qtyDef, $result, $rowId
                )
            );
            if ($ok === false) { echo json_encode(array('error' => 'Update failed')); exit; }
            echo json_encode(array('success' => true, 'id' => $rowId, 'result' => $result, 'action' => 'updated'));
        } else {
            $chk = dbQuery(
                "SELECT id FROM TRIMS_TBL_INSPECTION
                 WHERE IO_num=? AND PO_num=? AND Vendor_Name=? AND GMC_Description=? AND Addl_Description=? AND Defect_Type=?",
                array($data['IO_num'], $data['PO_num'], $data['Vendor_Name'], $data['GMC_Description'],
                      $data['Addl_Description'], $data['Defect_Type'])
            );
            if (isset($chk['__error'])) { echo json_encode(array('error' => $chk['__error'])); exit; }

            if (!empty($chk)) {
                $existingId = $chk[0]['id'];
                $ok = dbExec(
                    "UPDATE TRIMS_TBL_INSPECTION
                     SET PO_num=?, Custome_Name=?, Defect_Type=?, System_Trim_Type=?,
                         Month=?, Week=?, Inspection_Date=?,
                         Total_Qty=?, Qty_Inspected=?, Qty_Defects=?, Result=?
                     WHERE id=?",
                    array(
                        $data['PO_num'], $data['Custome_Name'], $data['Defect_Type'], $data['System_Trim_Type'],
                        $data['Month'], $data['Week'], $data['Inspection_Date'],
                        (int)$data['Total_Qty'], $qtyInspected, $qtyDef, $result, $existingId
                    )
                );
                if ($ok === false) { echo json_encode(array('error' => 'Update failed')); exit; }
                echo json_encode(array('success' => true, 'id' => $existingId, 'result' => $result, 'action' => 'updated'));
            } else {
                $ok = dbExec(
                    "INSERT INTO TRIMS_TBL_INSPECTION
                     (IO_num, PO_num, Vendor_Name, GMC_Description, Addl_Description,
                      Custome_Name, Defect_Type, System_Trim_Type, Month, Week, Inspection_Date,
                      Total_Qty, Qty_Inspected, Qty_Defects, Result)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    array(
                        $data['IO_num'], $data['PO_num'], $data['Vendor_Name'], $data['GMC_Description'],
                        $data['Addl_Description'], $data['Custome_Name'], $data['Defect_Type'],
                        $data['System_Trim_Type'], $data['Month'], $data['Week'], $data['Inspection_Date'],
                        (int)$data['Total_Qty'], $qtyInspected, $qtyDef, $result
                    )
                );
                if ($ok === false) { echo json_encode(array('error' => 'Insert failed')); exit; }
                $newId = dbLastId();
                echo json_encode(array('success' => true, 'id' => $newId, 'result' => $result, 'action' => 'inserted'));
            }
        }
        exit;
    }

    if ($action === 'delete_row' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $id   = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id < 1) { echo json_encode(array('error' => 'Invalid id')); exit; }
        $ok = dbExec("DELETE FROM TRIMS_TBL_INSPECTION WHERE id=?", array($id));
        if ($ok === false) { echo json_encode(array('error' => 'Delete failed')); exit; }
        echo json_encode(array('success' => true)); exit;
    }

    echo json_encode(array('error' => 'Unknown action')); exit;
}
?>
<style>
    input[type=date] { width:100%; padding:7px 9px; border:1px solid #c8d0da; border-radius:5px; font-size:.85rem; background:#fff; }
    .tbl-wrap table  { min-width:1700px; }
    #inspectionTable tbody td { vertical-align:middle; }

    select.result-sel { width:100%; padding:5px 4px; border:1px solid #c8d0da; border-radius:4px; font-size:.82rem; }
</style>

<!-- Search Card -->
<div class="card">
    <div class="card-title">Search by IO Number</div>
    <div class="search-row">
        <div class="sf">
            <label for="ioInput">IO Number</label>
            <input type="text" id="ioInput" placeholder="e.g. IO-2024-001" maxlength="50"
                   onkeydown="if(event.keyCode===13){ searchIO(); }">
        </div>
        <div class="sb">
            <button class="btn btn-primary" onclick="searchIO()">Search</button>
        </div>
        <div style="clear:both;"></div>
    </div>
    <div id="ioInfo" style="margin-top:12px;"></div>
</div>

<!-- Inspection Table Card -->
<div class="card" id="inspectionCard" style="display:none;">
    <div class="card-title-row">
        <span>Inspection Details</span>
        <span class="right">
            <button class="btn btn-success" onclick="addRow()">+ Add Row</button>
        </span>
    </div>
    <div id="saveStatus"></div>
    <div class="tbl-wrap">
        <table id="inspectionTable">
            <thead>
                <tr>
                    <th style="width:32px;">#</th>
                    <th style="min-width:200px;">SUPPLIER / TRIM</th>
                    <th style="min-width:120px;">PO NO.</th>
                    <th style="min-width:120px;">BRAND</th>
                    <th style="min-width:140px;">TRIM TYPE</th>
                    <th style="min-width:130px;">SYSTEM TRIM</th>
                    <th style="width:75px;">TOTAL QTY</th>
                    <th style="min-width:140px;">DEFECT TYPE</th>
                    <th style="width:130px;">INSPECTION DATE</th>
                    <th style="width:110px;">MONTH</th>
                    <th style="width:90px;">WEEK</th>
                    <th style="width:85px;">QTY INSPECTED</th>
                    <th style="width:85px;">QTY DEFECTS</th>
                    <th style="width:120px;">RESULT</th>
                    <th style="width:60px;">ACTION</th>
                </tr>
            </thead>
            <tbody id="inspectionBody"></tbody>
        </table>
    </div>
</div>

<script>
// ── State ──────────────────────────────────────
var currentIO   = '';
var suppliers   = [];
var brands      = [];
var defects     = [];
var systemTrims = [];
var months      = [];
var weeks       = [];
var rowCounter  = 0;
var saveTimers         = {};
var BASE               = 'module1.php';

// ── Result options (static) ────────────────────
var RESULT_OPTIONS = ['PASSED', 'FAILED', 'HOLD', 'REPLACEMENT'];

// ── QTY INSPECTED table ────────────────────────
var QTY_TABLE = [
    [2,      8,      2,   0],
    [9,      15,     3,   0],
    [16,     25,     5,   0],
    [26,     50,     8,   0],
    [51,     90,     13,  0],
    [91,     150,    20,  0],
    [151,    280,    32,  0],
    [281,    500,    50,  1],
    [501,    1200,   80,  2],
    [1201,   3200,   125, 3],
    [3201,   10000,  200, 5],
    [10001,  35000,  315, 7],
    [35001,  150000, 500, 10],
    [150001, 500000, 800, 14]
];

function getQtyInspected(totalQty) {
    var q = parseInt(totalQty, 10);
    if (isNaN(q) || q < 2) { return 0; }
    for (var i = 0; i < QTY_TABLE.length; i++) {
        if (q >= QTY_TABLE[i][0] && q <= QTY_TABLE[i][1]) { return QTY_TABLE[i][2]; }
    }
    return 0;
}

function getMaxDefects(totalQty) {
    var q = parseInt(totalQty, 10);
    if (isNaN(q) || q < 2) { return 0; }
    for (var i = 0; i < QTY_TABLE.length; i++) {
        if (q >= QTY_TABLE[i][0] && q <= QTY_TABLE[i][1]) { return QTY_TABLE[i][3]; }
    }
    return 0;
}

// ── Date helpers ───────────────────────────────
var MONTH_NAMES = ['January','February','March','April','May','June',
                   'July','August','September','October','November','December'];

function getMonthFromDate(dateStr) {
    if (!dateStr || dateStr.length < 7) { return ''; }
    var m = parseInt(dateStr.split('-')[1], 10) - 1;
    return isNaN(m) ? '' : MONTH_NAMES[m];
}

function getWeekFromDate(dateStr) {
    if (!dateStr || dateStr.length < 10) { return 0; }
    var d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) { return 0; }
    var jan4 = new Date(d.getFullYear(), 0, 4);
    var w1   = new Date(jan4);
    w1.setDate(jan4.getDate() - ((jan4.getDay() + 6) % 7));
    var wn = Math.floor((d - w1) / (7 * 24 * 3600 * 1000)) + 1;
    return Math.min(Math.max(wn, 1), 52);
}

function getWeekDescription(n) {
    return 'Week ' + (n < 10 ? '0' + n : '' + n);
}

// ── Brand keyword matcher ──────────────────────
function findBrandId(customerName) {
    if (!customerName || brands.length === 0) { return ''; }
    var name = customerName.toLowerCase().replace(/^\s+|\s+$/g, '');
    for (var i = 0; i < brands.length; i++) {
        if (brands[i].description.toLowerCase() === name) { return brands[i].id; }
    }
    for (var i = 0; i < brands.length; i++) {
        if (name.indexOf(brands[i].description.toLowerCase()) !== -1) { return brands[i].id; }
    }
    for (var i = 0; i < brands.length; i++) {
        if (brands[i].description.toLowerCase().indexOf(name) !== -1) { return brands[i].id; }
    }
    var words = name.split(/\s+/), bestId = '', bestScore = 0;
    for (var i = 0; i < brands.length; i++) {
        var bw = brands[i].description.toLowerCase().split(/\s+/), score = 0;
        for (var j = 0; j < words.length; j++) {
            if (words[j].length < 2) { continue; }
            for (var k = 0; k < bw.length; k++) {
                if (bw[k] === words[j])             { score += 2; break; }
                if (bw[k].indexOf(words[j]) !== -1) { score += 1; break; }
            }
        }
        if (score > bestScore) { bestScore = score; bestId = brands[i].id; }
    }
    return bestScore > 0 ? bestId : '';
}

// ── System Trim tokenized matcher ─────────────
function tokenize(text) {
    var parts = text.toLowerCase().split(/[^a-z0-9]+/), r = [];
    for (var i = 0; i < parts.length; i++) { if (parts[i].length >= 2) { r.push(parts[i]); } }
    return r;
}

function findSystemTrimId(trimTypeText) {
    if (!trimTypeText || systemTrims.length === 0) { return ''; }
    var text = trimTypeText.toLowerCase();
    var tokens = tokenize(text);
    var bestId = '', bestScore = 0;

    for (var i = 0; i < systemTrims.length; i++) {
        var desc = systemTrims[i].description.toLowerCase();
        var dt   = tokenize(desc);
        var score = 0;

        if (text.indexOf(desc) !== -1) { score += 10; }

        var matched = 0;
        for (var j = 0; j < dt.length; j++) {
            var exact = false, partial = false;
            for (var k = 0; k < tokens.length; k++) {
                if (tokens[k] === dt[j])             { exact   = true; break; }
                if (tokens[k].indexOf(dt[j]) !== -1) { partial = true; }
            }
            if (exact)        { score += 3; matched++; }
            else if (partial) { score += 1; }
        }
        if (dt.length > 1 && matched === dt.length) { score += 5; }

        if (score > bestScore) { bestScore = score; bestId = systemTrims[i].id; }
    }
    return bestScore >= 3 ? bestId : '';
}

// ── Dropdown id finders ────────────────────────
function findMonthId(description) {
    for (var i = 0; i < months.length; i++) {
        if (months[i].description === description) { return months[i].id; }
    }
    return '';
}

function findWeekId(weekNum) {
    var desc = getWeekDescription(weekNum);
    for (var i = 0; i < weeks.length; i++) {
        if (weeks[i].description === desc) { return weeks[i].id; }
    }
    for (var i = 0; i < weeks.length; i++) {
        if (parseInt(weeks[i].description.replace(/[^0-9]/g, ''), 10) === weekNum) { return weeks[i].id; }
    }
    return '';
}

// ── Status ─────────────────────────────────────
function showStatus(msg, type) {
    if (!type) { type = 'info'; }
    var el = document.getElementById('saveStatus');
    el.innerHTML = '<div class="alert alert-' + type + '">' + msg + '</div>';
    if (type !== 'err') { setTimeout(function() { el.innerHTML = ''; }, 3000); }
}

// ── XHR ────────────────────────────────────────
function ajax(method, url, body, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    if (body) { xhr.setRequestHeader('Content-Type', 'application/json'); }
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            try   { callback(null, JSON.parse(xhr.responseText)); }
            catch (e) { callback('Parse error: ' + xhr.responseText, null); }
        }
    };
    xhr.send(body ? JSON.stringify(body) : null);
}

// ── Search IO ──────────────────────────────────
function searchIO() {
    var io = document.getElementById('ioInput').value.replace(/^\s+|\s+$/g, '');
    if (!io) { alert('Please enter an IO Number.'); return; }

    ajax('GET', BASE + '?ajax=search_io&io=' + encodeURIComponent(io), null, function(err, data) {
        var infoEl = document.getElementById('ioInfo');
        if (err || !data || data.error) {
            infoEl.innerHTML = '<div class="alert alert-err">Not found: ' + (data ? data.error : err) + '</div>';
            document.getElementById('inspectionCard').style.display = 'none';
            return;
        }
        currentIO = io;
        var poNums = [];
        if (data.io && data.io.PO_Nums && data.io.PO_Nums.length) {
            poNums = data.io.PO_Nums;
        } else if (data.io && data.io.PO_Num) {
            poNums = [data.io.PO_Num];
        }
        var poText = poNums.length ? poNums.join(', ') : '-';

        infoEl.innerHTML =
            '<div class="alert alert-ok">Found &mdash; IO: <strong>' + io + '</strong>' +
            ' &nbsp;| PO: <strong>' + poText + '</strong>' +
            ' &nbsp;| Customer: <strong>' + data.io.Customer_Name + '</strong></div>';

        ajax('GET', BASE + '?ajax=get_suppliers&io=' + encodeURIComponent(io), null, function(e2, d2) {
            suppliers = (e2 || !d2) ? [] : d2;
        ajax('GET', BASE + '?ajax=get_brands', null, function(e3, d3) {
            brands = (e3 || !d3) ? [] : d3;
        ajax('GET', BASE + '?ajax=get_defects', null, function(e4, d4) {
            defects = (e4 || !d4) ? [] : d4;
        ajax('GET', BASE + '?ajax=get_system_trims', null, function(e5, d5) {
            systemTrims = (e5 || !d5) ? [] : d5;
        ajax('GET', BASE + '?ajax=get_months', null, function(e6, d6) {
            months = (e6 || !d6) ? [] : d6;
        ajax('GET', BASE + '?ajax=get_weeks', null, function(e7, d7) {
            weeks = (e7 || !d7) ? [] : d7;
        ajax('GET', BASE + '?ajax=get_rows&io=' + encodeURIComponent(io), null, function(e8, d8) {
            var rows = (e8 || !d8) ? [] : d8;
            document.getElementById('inspectionBody').innerHTML = '';
            rowCounter = 0;
            document.getElementById('inspectionCard').style.display = 'block';
            if (rows.length === 0) { addRow(null); }
            else { for (var i = 0; i < rows.length; i++) { addRow(rows[i]); } }
        });
        });
        });
        });
        });
        });
        });
    });
}

// ── Option builders ────────────────────────────
function buildSupplierOptions(selIdx) {
    var html = '<option value="">-- Select --</option>';
    for (var i = 0; i < suppliers.length; i++) {
        if (!isSupplierAvailableForRow(i, selIdx)) { continue; }
        var s = suppliers[i];
        var lbl = s.Vendor_Name + ' | ' + s.PO_Num + ' | ' + s.GMC_Description +
                  (s.Addl_Description ? ' - ' + s.Addl_Description : '');
        var sel = (i === parseInt(selIdx, 10)) ? ' selected' : '';
        html += '<option value="' + i + '"' + sel + ' title="' + lbl.replace(/"/g,'&quot;') + '">' + lbl + '</option>';
    }
    return html;
}

function getSupplierKey(supplier) {
    if (!supplier) { return ''; }
    return [
        currentIO,
        supplier.PO_Num || '',
        supplier.Vendor_Name || '',
        supplier.GMC_Description || '',
        supplier.Addl_Description || ''
    ].join('||');
}

function getCurrentRowSupplierKey(rid) {
    var supEl = document.getElementById('sup-' + rid);
    if (!supEl || supEl.value === '') { return ''; }
    var idx = parseInt(supEl.value, 10);
    return isNaN(idx) ? '' : getSupplierKey(suppliers[idx]);
}

function isSupplierAvailableForRow(idx, selIdx) {
    if (idx === parseInt(selIdx, 10)) { return true; }
    var key = getSupplierKey(suppliers[idx]);
    if (!key) { return false; }

    var rows = document.getElementById('inspectionBody').getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var rid = rows[i].id.replace('row-', '');
        if (getCurrentRowSupplierKey(rid) === key) { return false; }
    }
    return true;
}

function refreshSupplierOptions() {
    var rows = document.getElementById('inspectionBody').getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var rid = rows[i].id.replace('row-', '');
        var supEl = document.getElementById('sup-' + rid);
        if (!supEl) { continue; }
        var selectedValue = supEl.value;
        supEl.innerHTML = buildSupplierOptions(selectedValue);
        if (selectedValue !== '') { supEl.value = selectedValue; }
        applyControlTooltip(supEl);
    }
}

function buildBrandOptions(selId) {
    var html = '<option value="">-- Brand --</option>';
    for (var i = 0; i < brands.length; i++) {
        var sel = (String(brands[i].id) === String(selId)) ? ' selected' : '';
        html += '<option value="' + brands[i].id + '"' + sel + ' title="' + brands[i].description.replace(/"/g,'&quot;') + '">' + brands[i].description + '</option>';
    }
    return html;
}

function buildSystemTrimOptions(selId) {
    var html = '<option value="">-- System Trim --</option>';
    for (var i = 0; i < systemTrims.length; i++) {
        var sel = (String(systemTrims[i].id) === String(selId)) ? ' selected' : '';
        html += '<option value="' + systemTrims[i].id + '"' + sel + ' title="' + systemTrims[i].description.replace(/"/g,'&quot;') + '">' + systemTrims[i].description + '</option>';
    }
    return html;
}

function buildDefectOptions(selId) {
    var html = '<option value="">-- Defect Type --</option>';
    for (var i = 0; i < defects.length; i++) {
        var sel = (String(defects[i].id) === String(selId)) ? ' selected' : '';
        html += '<option value="' + defects[i].id + '"' + sel + ' title="' + defects[i].description.replace(/"/g,'&quot;') + '">' + defects[i].description + '</option>';
    }
    return html;
}

function buildMonthOptions(selId) {
    var html = '<option value="">-- Month --</option>';
    for (var i = 0; i < months.length; i++) {
        var sel = (String(months[i].id) === String(selId)) ? ' selected' : '';
        html += '<option value="' + months[i].id + '"' + sel + ' title="' + months[i].description.replace(/"/g,'&quot;') + '">' + months[i].description + '</option>';
    }
    return html;
}

function buildWeekOptions(selId) {
    var html = '<option value="">-- Week --</option>';
    for (var i = 0; i < weeks.length; i++) {
        var sel = (String(weeks[i].id) === String(selId)) ? ' selected' : '';
        html += '<option value="' + weeks[i].id + '"' + sel + ' title="' + weeks[i].description.replace(/"/g,'&quot;') + '">' + weeks[i].description + '</option>';
    }
    return html;
}

// ── Result dropdown: static options, colour-coded ─
function buildResultOptions(selVal) {
    var html = '<option value="">-- Result --</option>';
    for (var i = 0; i < RESULT_OPTIONS.length; i++) {
        var r   = RESULT_OPTIONS[i];
        var sel = (r === selVal) ? ' selected' : '';
        html += '<option value="' + r + '"' + sel + ' title="' + r + '">' + r + '</option>';
    }
    return html;
}

function escapeHtml(value) {
    value = String(value === null || value === undefined ? '' : value);
    return value.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
}

function applyControlTooltip(el) {
    if (!el) { return; }
    var title = '';
    if (el.tagName && el.tagName.toLowerCase() === 'select') {
        if (el.selectedIndex >= 0 && el.options[el.selectedIndex]) {
            title = el.options[el.selectedIndex].text;
        }
    } else {
        title = el.value || '';
    }
    el.title = title;
}

function applyRowTooltips(rid) {
    var tr = document.getElementById('row-' + rid);
    if (!tr) { return; }

    var controls = tr.getElementsByTagName('input');
    var i;
    for (i = 0; i < controls.length; i++) { applyControlTooltip(controls[i]); }

    controls = tr.getElementsByTagName('select');
    for (i = 0; i < controls.length; i++) { applyControlTooltip(controls[i]); }

    var cells = tr.getElementsByTagName('td');
    for (i = 0; i < cells.length; i++) {
        var cell = cells[i];
        if (cell.getAttribute('data-tooltip') === 'skip') { continue; }
        cell.title = cell.textContent || cell.innerText || '';
    }
}

function refreshResultColour(rid) {
    var el = document.getElementById('result-' + rid);
    if (!el) { return; }
    el.setAttribute('data-val', el.value);
}

// ── Add a row ──────────────────────────────────
function addRow(saved) {
    rowCounter++;
    var rid = rowCounter;

    var supIdx = '';
    if (saved) {
        for (var i = 0; i < suppliers.length; i++) {
            if (suppliers[i].Vendor_Name     === saved.Vendor_Name &&
                suppliers[i].PO_Num          === saved.PO_num &&
                suppliers[i].GMC_Description  === saved.GMC_Description &&
                suppliers[i].Addl_Description === saved.Addl_Description) {
                supIdx = i; break;
            }
        }
    }

    var brandId    = (saved && saved.Custome_Name)     ? findBrandId(saved.Custome_Name)  : '';
    var sysTypeVal = (saved && saved.System_Trim_Type) ? saved.System_Trim_Type           : '';
    var defTypeVal = saved ? (saved.Defect_Type      || '') : '';
    var dateVal    = saved ? (saved.Inspection_Date   || '') : '';
    var monthVal   = saved ? (saved.Month             || '') : '';
    var weekVal    = saved ? (saved.Week              || '') : '';
    var totalQty   = saved ? (saved.Total_Qty         || 0)  : 0;
    var qtyInspVal = saved ? (saved.Qty_Inspected     || 0)  : 0;
    var qtyDefVal  = saved ? (saved.Qty_Defects       || 0)  : 0;
    var poNum      = saved ? (saved.PO_num            || '') : '';
    var dbId       = saved ? (saved.id                || '') : '';
    // Saved result (may be PASSED/FAILED/HOLD/REPLACEMENT)
    var savedResult = saved ? (saved.Result || '') : '';

    var tr = document.createElement('tr');
    tr.id  = 'row-' + rid;
    tr.setAttribute('data-db-id', dbId);
    tr.setAttribute('data-po-num', poNum);

    tr.innerHTML =
        '<td style="font-weight:700;color:#1a3a5c;text-align:center;">' + rid + '</td>' +
        '<td><select id="sup-'     + rid + '">' + buildSupplierOptions(supIdx)       + '</select></td>' +
        '<td><input type="text" id="po-' + rid + '" readonly value="' + escapeHtml(poNum) + '"></td>' +
        '<td><select id="brand-'   + rid + '">' + buildBrandOptions(brandId)         + '</select></td>' +
        '<td><input type="text" id="trimtype-' + rid + '" readonly title=""></td>' +
        '<td><select id="systrim-' + rid + '">' + buildSystemTrimOptions(sysTypeVal) + '</select></td>' +
        '<td><input type="number" id="qty-' + rid + '" readonly value="' + totalQty + '" min="0" style="width:65px;"></td>' +
        '<td><select id="deftype-' + rid + '">' + buildDefectOptions(defTypeVal)     + '</select></td>' +
        '<td><input type="date" id="date-' + rid + '" value="' + dateVal + '"></td>' +
        '<td><select id="month-' + rid + '">' + buildMonthOptions(monthVal)          + '</select></td>' +
        '<td><select id="week-'  + rid + '">' + buildWeekOptions(weekVal)            + '</select></td>' +
        '<td><input type="number" id="qtyins-' + rid + '" readonly value="' + qtyInspVal + '" min="0" style="width:70px;"></td>' +
        '<td><input type="number" id="qtydef-' + rid + '" min="0" value="' + qtyDefVal   + '" style="width:70px;"></td>' +
        // RESULT: dropdown with static options; hint span shows auto-computed suggestion
        '<td>' +
        '<select id="result-' + rid + '" class="result-sel">' + buildResultOptions(savedResult) + '</select>' +
        '</td>' +
        '<td><button class="btn btn-danger btn-sm" onclick="deleteRow(' + rid + ')" title="Delete this inspection row">Del</button></td>';

    document.getElementById('inspectionBody').appendChild(tr);
    // Wire events
    document.getElementById('sup-'    + rid).onchange = function() { onSupplierChange(rid); };
    document.getElementById('brand-'  + rid).onchange = function() { debounceSave(rid); };
    document.getElementById('systrim-'+ rid).onchange = function() { debounceSave(rid); };
    document.getElementById('deftype-'+ rid).onchange = function() { debounceSave(rid); };
    document.getElementById('date-'   + rid).onchange = function() { onDateChange(rid); };
    document.getElementById('month-'  + rid).onchange = function() { debounceSave(rid); };
    document.getElementById('week-'   + rid).onchange = function() { debounceSave(rid); };
    document.getElementById('result-'  + rid).onchange = function() { debounceSave(rid); };
    document.getElementById('qtydef-' + rid).oninput  = function() { calcResultSuggestion(rid); debounceSave(rid); };

    // Autofill on load
    if (saved && supIdx !== '') {
        fillTrimType(rid, supIdx, false);
    } else if (saved) {
        var t = document.getElementById('trimtype-' + rid);
        if (t) {
            t.value = (saved.GMC_Description + ' ' + (saved.Addl_Description || '')).replace(/^\s+|\s+$/g,'');
            t.title = t.value;
        }
    }
    if (saved) { calcResultSuggestion(rid); }
    applyRowTooltips(rid);
    refreshSupplierOptions();
}

// ── Supplier change ────────────────────────────
function onSupplierChange(rid) {
    var idx = parseInt(document.getElementById('sup-' + rid).value, 10);
    if (isNaN(idx) || !suppliers[idx]) {
        document.getElementById('po-'       + rid).value = '';
        document.getElementById('trimtype-' + rid).value = '';
        document.getElementById('qty-'      + rid).value = '';
        document.getElementById('qtyins-'   + rid).value = '';
        applyRowTooltips(rid);
        refreshSupplierOptions();
        return;
    }
    var matchedBrandId = findBrandId(suppliers[idx].Customer_Name);
    if (matchedBrandId !== '') { document.getElementById('brand-' + rid).value = matchedBrandId; }
    fillTrimType(rid, idx, true);
    refreshSupplierOptions();
}

function fillTrimType(rid, idx, triggerSave) {
    if (triggerSave === undefined) { triggerSave = true; }
    var s        = suppliers[idx];
    var fullText = s.GMC_Description + (s.Addl_Description ? ' - ' + s.Addl_Description : '');

    var trimEl = document.getElementById('trimtype-' + rid);
    trimEl.value = fullText;
    trimEl.title = fullText;

    document.getElementById('po-' + rid).value = s.PO_Num;
    document.getElementById('qty-' + rid).value = s.PO_Qty;
    var tr = document.getElementById('row-' + rid);
    if (tr) { tr.setAttribute('data-po-num', s.PO_Num); }

    // Auto-match System Trim — always re-run on supplier change
    var stEl = document.getElementById('systrim-' + rid);
    if (stEl) { stEl.value = findSystemTrimId(fullText); }

    calcResultSuggestion(rid);
    applyRowTooltips(rid);
    if (triggerSave) { debounceSave(rid); }
}

// ── Date change ────────────────────────────────
function onDateChange(rid) {
    var dateVal = document.getElementById('date-' + rid).value;
    if (dateVal) {
        var monthId = findMonthId(getMonthFromDate(dateVal));
        var weekNum = getWeekFromDate(dateVal);
        var weekId  = weekNum > 0 ? findWeekId(weekNum) : '';
        if (monthId !== '') { document.getElementById('month-' + rid).value = monthId; }
        if (weekId  !== '') { document.getElementById('week-'  + rid).value = weekId;  }
    }
    applyRowTooltips(rid);
    debounceSave(rid);
}

// ── Compute QTY INSPECTED and update RESULT dropdown ─
// Always updates QTY INSPECTED (readonly, follows table).
// Always updates RESULT to PASSED/FAILED based on computation —
// UNLESS the user has manually set it to HOLD or REPLACEMENT.
function calcResultSuggestion(rid) {
    var totalQty = parseInt((document.getElementById('qty-'    + rid) || {}).value, 10) || 0;
    var qtyDef   = parseInt((document.getElementById('qtydef-' + rid) || {}).value, 10) || 0;
    var qtyIns   = getQtyInspected(totalQty);
    var autoResult = qtyIns > 0
        ? ((qtyDef > getMaxDefects(totalQty)) ? 'FAILED' : 'PASSED')
        : '';

    // Always update QTY INSPECTED
    var insEl = document.getElementById('qtyins-' + rid);
    if (insEl) { insEl.value = qtyIns > 0 ? qtyIns : ''; }

    // Update RESULT: follow computation for PASSED/FAILED reactively.
    // If user chose HOLD or REPLACEMENT, leave it untouched.
    var resEl = document.getElementById('result-' + rid);
    if (!resEl || autoResult === '') { return; }
    var cur = resEl.value;
    if (cur !== 'HOLD' && cur !== 'REPLACEMENT') {
        resEl.value = autoResult;
    }
    applyRowTooltips(rid);
}

// ── Debounce save ──────────────────────────────
function debounceSave(rid) {
    if (saveTimers[rid]) { clearTimeout(saveTimers[rid]); }
    saveTimers[rid] = setTimeout(function() { saveRow(rid); }, 900);
}

// ── Save row ───────────────────────────────────
function saveRow(rid) {
    var supIdx  = parseInt(document.getElementById('sup-'     + rid).value, 10);
    var brandEl = document.getElementById('brand-'    + rid);
    var stEl    = document.getElementById('systrim-'  + rid);
    var defVal  = document.getElementById('deftype-'  + rid).value;
    var dtVal   = document.getElementById('date-'     + rid).value;
    var monVal  = document.getElementById('month-'    + rid).value;
    var wkVal   = document.getElementById('week-'     + rid).value;
    var qdVal   = parseInt(document.getElementById('qtydef-'  + rid).value, 10) || 0;
    var qtyVal  = parseInt(document.getElementById('qty-'     + rid).value, 10) || 0;
    var insVal  = parseInt(document.getElementById('qtyins-'  + rid).value, 10) || 0;
    var stVal   = stEl ? stEl.value : '';
    var resEl   = document.getElementById('result-'   + rid);
    var resVal  = resEl ? resEl.value : '';
    var tr      = document.getElementById('row-' + rid);
    var dbId    = tr ? tr.getAttribute('data-db-id') : '';

    if (isNaN(supIdx) || !suppliers[supIdx]) { return; }
    if (!stVal || !defVal || !dtVal || !monVal || !wkVal) { return; }
    if (!resVal) { return; }   // Result must be selected

    var s = suppliers[supIdx];
    var brandDesc = '';
    if (brandEl && brandEl.options[brandEl.selectedIndex] && brandEl.options[brandEl.selectedIndex].value !== '') {
        brandDesc = brandEl.options[brandEl.selectedIndex].text;
    }
    if (!brandDesc) { brandDesc = s.Customer_Name; }

    var payload = {
        row_id          : dbId ? parseInt(dbId, 10) : 0,
        IO_num          : currentIO,
        PO_num          : s.PO_Num,
        Vendor_Name     : s.Vendor_Name,
        GMC_Description : s.GMC_Description,
        Addl_Description: s.Addl_Description,
        Custome_Name    : brandDesc,
        Defect_Type     : defVal,
        System_Trim_Type: stVal,
        Month           : monVal,
        Week            : wkVal,
        Inspection_Date : dtVal,
        Total_Qty       : qtyVal,
        Qty_Inspected   : insVal,
        Qty_Defects     : qdVal,
        Result          : resVal
    };

    showStatus('Saving...', 'info');
    ajax('POST', BASE + '?ajax=save_row', payload, function(err, data) {
        if (err || !data || data.error) {
            showStatus('Error: ' + (data ? data.error : err), 'err');
            return;
        }
        if (tr) { tr.setAttribute('data-db-id', data.id); }
        refreshSupplierOptions();
        applyRowTooltips(rid);
        showStatus('Row ' + rid + ' ' + data.action + '.', 'ok');
    });
}

// ── Delete row ─────────────────────────────────
function deleteRow(rid) {
    var tr   = document.getElementById('row-' + rid);
    var dbId = tr ? tr.getAttribute('data-db-id') : '';
    if (dbId && dbId !== '') {
        if (!confirm('Delete this inspection row?')) { return; }
        ajax('POST', BASE + '?ajax=delete_row', {id: parseInt(dbId, 10)}, function(err, data) {
            if (err || (data && data.error)) {
                alert('Delete failed: ' + (data ? data.error : err));
                return;
            }
            if (tr) { tr.parentNode.removeChild(tr); }
            renumber();
            refreshSupplierOptions();
            showStatus('Row deleted.', 'info');
        });
    } else {
        if (tr) { tr.parentNode.removeChild(tr); }
        renumber();
        refreshSupplierOptions();
    }
}

// ── Renumber ───────────────────────────────────
function renumber() {
    var rows = document.getElementById('inspectionBody').getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        rows[i].getElementsByTagName('td')[0].innerHTML = (i + 1);
        applyRowTooltips(rows[i].id.replace('row-', ''));
    }
}
</script>