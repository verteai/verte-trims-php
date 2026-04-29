<?php
// Prevent timeout on large imports
set_time_limit(0);
ini_set('max_execution_time', 0);

require_once dirname(__FILE__) . '/config.php';

/* ── Column index map (0-based) ────────────────
   Excel columns: A=0, N=13, X=23, Y=24, AA=26, AF=31, AJ=35, AK=36
*/
define('COL_IO_NUM',        31); // AF
define('COL_PO_NUM',        13); // N
define('COL_GMC_DESC',      23); // X
define('COL_ADDL_DESC',     24); // Y
define('COL_VENDOR_NAME',   26); // AA
define('COL_CUSTOMER_NAME', 35); // AJ
define('COL_PO_QTY',        36); // AK

// Header row is Row 1; data starts Row 2
define('HEADER_ROW', 1); // 1-based header row

/* Allowed server-side sort columns (security whitelist) */
$ALLOWED_SORT = array(
    'IO_Num','PO_Num','GMC_Description','Addl_Description',
    'Vendor_Name','Customer_Name','PO_Qty'
);

/* ═══════════════════════════════════════════
   AJAX: list records (search + pagination + sorting + page size)
   ═══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 5;
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $offset = ($page - 1) * $perPage;

    $sortCol = isset($_GET['sort']) ? $_GET['sort'] : 'IO_Num';
    if (!in_array($sortCol, $ALLOWED_SORT)) $sortCol = 'IO_Num';

    $dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'DESC' : 'ASC';

    if ($search !== '') {
        $like  = '%' . $search . '%';
        $where = "WHERE IO_Num LIKE ? OR PO_Num LIKE ? OR GMC_Description LIKE ?
                  OR Addl_Description LIKE ? OR Vendor_Name LIKE ? OR Customer_Name LIKE ?";
        $params = array($like,$like,$like,$like,$like,$like);
    } else {
        $where  = '';
        $params = array();
    }

    $cntRes = dbQuery("SELECT COUNT(*) AS cnt FROM TRIMS_TBL_RAWDATA $where", $params);
    $total  = (!empty($cntRes) && !isset($cntRes['__error'])) ? (int)$cntRes[0]['cnt'] : 0;

    // MSSQL paging via ROW_NUMBER with dynamic ORDER BY
    $sql = "SELECT * FROM (
                SELECT ROW_NUMBER() OVER (ORDER BY $sortCol $dir) AS rn,
                       id, IO_Num, PO_Num, GMC_Description, Addl_Description,
                       Vendor_Name, Customer_Name, PO_Qty
                FROM TRIMS_TBL_RAWDATA $where
            ) AS paged
            WHERE rn > ? AND rn <= ?";

    $pParams = array_merge($params, array($offset, $offset + $perPage));
    $rows = dbQuery($sql, $pParams);
    if (isset($rows['__error'])) { $rows = array(); }

    echo json_encode(array(
        'rows'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'perPage'    => $perPage,
        'totalPages' => (int)ceil($total / max(1,$perPage)),
        'sort'       => $sortCol,
        'dir'        => strtolower($dir)
    ));
    exit;
}

/* ═══════════════════════════════════════════
   AJAX: delete one record
   ═══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $id   = isset($data['id']) ? (int)$data['id'] : 0;

    if ($id < 1) { echo json_encode(array('error' => 'Invalid id')); exit; }

    $ok = dbExec("DELETE FROM TRIMS_TBL_RAWDATA WHERE id=?", array($id));
    echo ($ok !== false)
        ? json_encode(array('success' => true))
        : json_encode(array('error'   => 'Delete failed'));
    exit;
}

/* ═══════════════════════════════════════════
   Handle file upload (POST)
   ═══════════════════════════════════════════ */
$uploadResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['rawfile'])) {
    $file = $_FILES['rawfile'];
    $origName = isset($file['name']) ? $file['name'] : '';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadResult = array('error' => 'Upload failed (PHP error code ' . $file['error'] . ').');
    } elseif (!in_array($ext, array('xlsx','xls'))) {
        $uploadResult = array('error' => 'Only .xlsx or .xls files are accepted.');
    } else {
        $safePath = makeSafeCopy($file['tmp_name'], $ext);
        if ($safePath === false) {
            $uploadResult = array('error' => 'Failed to prepare uploaded file (temp copy).');
        } else {
            $res = importExcelFile($safePath, $ext);
            @unlink($safePath);
            if (isset($res['error'])) {
                $uploadResult = $res;
            } else {
                $uploadResult = array(
                    'success'  => true,
                    'inserted' => $res['inserted'],
                    'skipped'  => $res['skipped'],
                    'errors'   => $res['errors'],
                    'read'     => $res['read'],
                    'filename' => htmlspecialchars($origName, ENT_QUOTES, 'UTF-8')
                );
            }
        }
    }
}

/* ─────────────────────────────────────────────
   Import logic (UNCHANGED except header is row 1)
   ───────────────────────────────────────────── */
function importExcelFile($path, $ext) {
    if ($ext === 'xlsx') {
        $rows = readXlsxSimple($path);
    } else {
        $rows = readXlsComSimple($path);
    }
    if (isset($rows['error'])) return $rows;

    $inserted = 0;
    $skipped  = 0;
    $errors   = 0;
    $read     = 0;

    // Data starts row 2 => index 1
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        $io     = trimVal(getCol($row, COL_IO_NUM));
        $po     = trimVal(getCol($row, COL_PO_NUM));
        $gmc    = trimVal(getCol($row, COL_GMC_DESC));
        $addl   = trimVal(getCol($row, COL_ADDL_DESC));
        $vendor = trimVal(getCol($row, COL_VENDOR_NAME));
        $cust   = trimVal(getCol($row, COL_CUSTOMER_NAME));
        $qtyRaw = trimVal(getCol($row, COL_PO_QTY));
        $qty    = (int)preg_replace('/[^0-9]/', '', $qtyRaw);

        if ($io === '' && $po === '' && $vendor === '') { continue; }
        $read++;

        $chk = dbQuery(
            "SELECT 1 FROM TRIMS_TBL_RAWDATA
             WHERE IO_Num=? AND PO_Num=? AND GMC_Description=?
               AND Addl_Description=? AND Vendor_Name=? AND Customer_Name=?",
            array($io, $po, $gmc, $addl, $vendor, $cust)
        );
        if (!empty($chk) && !isset($chk['__error'])) { $skipped++; continue; }

        $ok = dbExec(
            "INSERT INTO TRIMS_TBL_RAWDATA
             (IO_Num, PO_Num, GMC_Description, Addl_Description,
              Vendor_Name, Customer_Name, PO_Qty)
             VALUES (?,?,?,?,?,?,?)",
            array($io, $po, $gmc, $addl, $vendor, $cust, $qty)
        );

        if ($ok !== false) { $inserted++; } else { $errors++; }
    }

    return array('inserted'=>$inserted,'skipped'=>$skipped,'errors'=>$errors,'read'=>$read);
}

/* ─────────────────────────────────────────────
   XLSX reader (as in your main code)
   ───────────────────────────────────────────── */
function readXlsxSimple($path) {
    if (!class_exists('ZipArchive')) {
        return array('error' => 'ZipArchive is not enabled. Enable ZIP in php.ini.');
    }

    $tmpCopy = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';
    if (!@copy($path, $tmpCopy)) {
        return array('error' => 'Cannot copy uploaded file to temp directory.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($tmpCopy);
    if ($opened !== true) {
        @unlink($tmpCopy);
        return array('error' => 'Cannot open XLSX (ZipArchive open failed).');
    }

    $shared = array();
    $ssRaw  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        $ssRaw = str_replace(' xmlns=', ' ns_ignored=', $ssRaw);
        $ssDoc = @simplexml_load_string($ssRaw);
        if ($ssDoc) {
            foreach ($ssDoc->si as $si) {
                $str = '';
                foreach ($si->xpath('.//t') as $t) { $str .= (string)$t; }
                $shared[] = $str;
            }
        }
    }

    $sheetRaw = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmpCopy);

    if ($sheetRaw === false) return array('error' => 'Cannot read sheet1.xml from XLSX.');

    $sheetRaw = str_replace(' xmlns=', ' ns_ignored=', $sheetRaw);
    $doc = @simplexml_load_string($sheetRaw);
    if (!$doc || !isset($doc->sheetData)) return array('error' => 'No sheet data found.');

    $rows = array();
    foreach ($doc->sheetData->row as $row) {
        $rowData = array();
        foreach ($row->c as $cell) {
            $ref    = (string)$cell['r'];
            $colStr = preg_replace('/[0-9]+/', '', $ref);
            $colIdx = colToIndex($colStr);
            $type   = (string)$cell['t'];

            if ($type === 's') {
                $idx = (int)(string)$cell->v;
                $rowData[$colIdx] = isset($shared[$idx]) ? $shared[$idx] : '';
            } elseif ($type === 'inlineStr') {
                $rowData[$colIdx] = (isset($cell->is->t)) ? (string)$cell->is->t : '';
            } else {
                $rowData[$colIdx] = ($cell->v !== null) ? (string)$cell->v : '';
            }
        }
        $rows[] = $rowData;
    }

    return $rows;
}

/* ─────────────────────────────────────────────
   XLS reader via COM (optional)
   ───────────────────────────────────────────── */
function readXlsComSimple($path) {
    if (!class_exists('COM')) {
        return array('error' => 'Reading .xls requires COM (Windows). Please convert to .xlsx.');
    }
    try {
        $xl = new COM('Excel.Application');
        $xl->Visible = false;
        $xl->DisplayAlerts = false;

        $wb = $xl->Workbooks->Open(realpath($path));
        $ws = $wb->Worksheets(1);

        $last = (int)$ws->UsedRange->Rows->Count;
        $rows = array();

        $cols = array(
            COL_IO_NUM => COL_IO_NUM + 1,
            COL_PO_NUM => COL_PO_NUM + 1,
            COL_GMC_DESC => COL_GMC_DESC + 1,
            COL_ADDL_DESC => COL_ADDL_DESC + 1,
            COL_VENDOR_NAME => COL_VENDOR_NAME + 1,
            COL_CUSTOMER_NAME => COL_CUSTOMER_NAME + 1,
            COL_PO_QTY => COL_PO_QTY + 1
        );

        for ($r = 1; $r <= $last; $r++) {
            $rowData = array();
            foreach ($cols as $zeroIdx => $oneIdx) {
                $rowData[$zeroIdx] = (string)$ws->Cells($r, $oneIdx)->Value;
            }
            $rows[] = $rowData;
        }

        $wb->Close(false);
        $xl->Quit();
        $xl = null;
        return $rows;

    } catch (Exception $e) {
        return array('error' => 'COM error: ' . $e->getMessage());
    }
}

function makeSafeCopy($tmp, $ext) {
    $dir = sys_get_temp_dir();
    $dest = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'trims_' . uniqid() . '.' . $ext;
    if (!@copy($tmp, $dest)) return false;
    return $dest;
}

function colToIndex($col) {
    $col = strtoupper(trim($col));
    $n   = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n - 1;
}

function getCol($row, $idx) {
    return isset($row[$idx]) ? $row[$idx] : '';
}

function trimVal($v) {
    return trim((string)$v);
}
?>
<style>
/* Keep your existing styling */
.upload-zone{
    border:2px dashed #4db6f5;border-radius:8px;padding:28px 20px;text-align:center;
    background:#f0f8ff;cursor:pointer;transition:background .2s,border-color .2s;
}
.upload-zone:hover,.upload-zone.drag-over{background:#ddf0ff;border-color:#1a73e8;}
.upload-zone .uz-icon{font-size:2.2rem;margin-bottom:8px;color:#4db6f5;}
.upload-zone .uz-label{font-size:.95rem;color:#555;}
.upload-zone .uz-sub{font-size:.78rem;color:#999;margin-top:4px;}
#fileInput{display:none;}
#fileChosen{font-size:.82rem;color:#1a73e8;margin-top:6px;font-weight:600;min-height:18px;}

.prog-wrap{background:#e0e0e0;border-radius:4px;height:8px;margin-top:10px;display:none;overflow:hidden;}
.prog-bar{background:#1a73e8;height:8px;border-radius:4px;width:0;transition:width .3s;}

.toolbar{overflow:hidden;margin-bottom:12px;}
.toolbar .left{float:left;}
.toolbar .right{float:right;}
.search-box{padding:7px 11px;border:1px solid #c8d0da;border-radius:5px;font-size:.88rem;width:220px;}
.pager{margin-top:12px;text-align:center;}
.pager button{
    background:#fff;border:1px solid #c8d0da;border-radius:4px;padding:5px 11px;margin:0 2px;
    cursor:pointer;font-size:.83rem;color:#1a3a5c;
}
.pager button.active{background:#1a3a5c;color:#fff;border-color:#1a3a5c;font-weight:700;}
.pager button:disabled{opacity:.4;cursor:default;}
.pager .pager-info{font-size:.8rem;color:#777;margin-top:6px;}

.upload-result{margin-top:14px;}

.tbl-container{overflow-x:auto;}
#rawTable{min-width:900px;}
#rawTable td,#rawTable th{white-space:nowrap;}

th.sortable{cursor:pointer;}
th.sortable.asc:after{content:" ▲";font-size:.7em;}
th.sortable.desc:after{content:" ▼";font-size:.7em;}
</style>

<?php if ($uploadResult): ?>
<div class="upload-result">
    <?php if (isset($uploadResult['error'])): ?>
        <div class="alert alert-err"><?php echo htmlspecialchars($uploadResult['error'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <div class="alert alert-ok">
            <strong><?php echo $uploadResult['filename']; ?></strong> processed —
            <strong><?php echo (int)$uploadResult['inserted']; ?></strong> inserted,
            <strong><?php echo (int)$uploadResult['skipped']; ?></strong> skipped (duplicate),
            <strong><?php echo (int)$uploadResult['errors']; ?></strong> errors.
            <span style="color:#666;">(<?php echo (int)$uploadResult['read']; ?> rows read)</span>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Upload Card -->
<div class="card">
    <div class="card-title">Upload Raw Data (XLS / XLSX)</div>

    <form id="uploadForm" method="POST" enctype="multipart/form-data">
        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click();">
            <div class="uz-icon">📄</div>
            <div class="uz-label">Click to browse or drag &amp; drop your file here</div>
            <div class="uz-sub">Accepted: .xls, .xlsx • Header row: Row 1 • Data starts: Row 2</div>
            <input type="file" id="fileInput" name="rawfile" accept=".xls,.xlsx">
        </div>

        <div id="fileChosen"></div>
        <div class="prog-wrap" id="progWrap"><div class="prog-bar" id="progBar"></div></div>

        <div style="margin-top:14px;">
            <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>Upload &amp; Import</button>
            <span style="font-size:.8rem;color:#888;margin-left:10px;">
                Duplicates (same IO + PO + GMC + Addl + Vendor + Customer) will be skipped.
            </span>
        </div>

        <div style="margin-top:12px;background:#f7f9fb;border:1px solid #dde3ea;border-radius:6px;padding:10px 14px;font-size:.8rem;color:#555;">
            <strong>Column mapping (Header Row 1):</strong><br>
            IO Num = AF | PO Num = N | GMC Desc = X | Addl Desc = Y<br>
            Vendor = AA | Customer = AJ | PO Qty = AK
        </div>
    </form>
</div>

<!-- Raw Data Table Card -->
<div class="card">
    <div class="card-title">Raw Data Records</div>

    <div class="toolbar">
        <div class="left">
            <input type="text" id="searchBox" class="search-box" placeholder="Search IO, PO, Vendor, GMC...">
        </div>
        <div class="right">
            <select id="pageSize" style="margin-right:8px;">
                <option value="5" selected>5</option>
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <span id="totalCount" style="font-size:.83rem;color:#777;line-height:34px;"></span>
        </div>
        <div style="clear:both;"></div>
    </div>

    <div class="tbl-container">
        <table id="rawTable">
            <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th class="sortable" data-col="IO_Num">IO Num</th>
                <th class="sortable" data-col="PO_Num">PO Num</th>
                <th class="sortable" data-col="GMC_Description">GMC Description</th>
                <th class="sortable" data-col="Addl_Description">Addl Description</th>
                <th class="sortable" data-col="Vendor_Name">Vendor Name</th>
                <th class="sortable" data-col="Customer_Name">Customer Name</th>
                <th class="sortable" data-col="PO_Qty" style="width:80px;">PO Qty</th>
                <th style="width:60px;">Action</th>
            </tr>
            </thead>
            <tbody id="rawBody">
            <tr><td colspan="9" style="text-align:center;color:#999;padding:20px;">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="pager" id="pagerArea"></div>
</div>

<script>
var currentPage = 1;
var totalPages = 1;
var searchTimer = null;
var BASE4 = 'module4.php';

var sortCol = 'IO_Num';
var sortDir = 'asc';
var pageSize = 5;

function trimStr(s){ return String(s || '').replace(/^\s+|\s+$/g,''); }

function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

document.getElementById('fileInput').onchange = function() {
    var f = this.files[0];
    if (f) {
        document.getElementById('fileChosen').innerHTML = 'Selected: <strong>' + esc(f.name) + '</strong>';
        document.getElementById('uploadBtn').disabled = false;
    }
};

var zone = document.getElementById('uploadZone');
zone.ondragover = function(e){ e.preventDefault(); zone.className = 'upload-zone drag-over'; };
zone.ondragleave = function(){ zone.className = 'upload-zone'; };
zone.ondrop = function(e){
    e.preventDefault();
    zone.className = 'upload-zone';
    var f = e.dataTransfer.files[0];
    if (f) {
        document.getElementById('fileChosen').innerHTML =
            'Dropped: <strong>' + esc(f.name) + '</strong> (use Browse to select for upload)';
    }
};

document.getElementById('uploadForm').onsubmit = function() {
    var pw = document.getElementById('progWrap');
    var pb = document.getElementById('progBar');
    pw.style.display = 'block';
    pb.style.width = '0%';
    var pct = 0;
    var iv = setInterval(function(){
        pct += 8;
        if (pct >= 90) { pct = 90; clearInterval(iv); }
        pb.style.width = pct + '%';
    }, 200);
};

document.getElementById('searchBox').onkeyup = function() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(function(){
        currentPage = 1;
        loadTable();
    }, 350);
};

document.getElementById('pageSize').onchange = function(){
    pageSize = this.value;
    currentPage = 1;
    loadTable();
};

function ajaxJSON(method, url, body, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    if (body) xhr.setRequestHeader('Content-Type','application/json');
    xhr.onreadystatechange = function(){
        if (xhr.readyState === 4) {
            try { cb(null, JSON.parse(xhr.responseText)); }
            catch(e){ cb('Parse error', null); }
        }
    };
    xhr.send(body ? JSON.stringify(body) : null);
}

function loadTable() {
    var q = trimStr(document.getElementById('searchBox').value);
    var url = BASE4
        + '?ajax=list&page=' + currentPage
        + '&limit=' + pageSize
        + '&sort=' + encodeURIComponent(sortCol)
        + '&dir=' + encodeURIComponent(sortDir)
        + '&q=' + encodeURIComponent(q);

    document.getElementById('rawBody').innerHTML =
        '<tr><td colspan="9" style="text-align:center;color:#999;padding:16px;">Loading...</td></tr>';

    ajaxJSON('GET', url, null, function(err, data){
        if (err || !data) {
            document.getElementById('rawBody').innerHTML =
                '<tr><td colspan="9" style="text-align:center;color:#c62828;">Error loading data.</td></tr>';
            return;
        }

        totalPages = data.totalPages || 1;
        renderTable(data);
        renderPager(data);
    });
}

function renderTable(data) {
    var tbody = document.getElementById('rawBody');
    var rows = data.rows;

    document.getElementById('totalCount').innerHTML =
        'Total: <strong>' + data.total + '</strong>';

    if (!rows || rows.length === 0) {
        tbody.innerHTML =
            '<tr><td colspan="9" style="text-align:center;color:#999;padding:20px;">No records found.</td></tr>';
        return;
    }

    var offset = (data.page - 1) * data.perPage;
    var html = '';
    for (var i=0; i<rows.length; i++) {
        var r = rows[i];
        var num = offset + i + 1;
        html += '<tr>' +
            '<td style="text-align:center;font-weight:700;color:#1a3a5c;">' + num + '</td>' +
            '<td>' + esc(r.IO_Num) + '</td>' +
            '<td>' + esc(r.PO_Num) + '</td>' +
            '<td>' + esc(r.GMC_Description) + '</td>' +
            '<td>' + esc(r.Addl_Description) + '</td>' +
            '<td>' + esc(r.Vendor_Name) + '</td>' +
            '<td>' + esc(r.Customer_Name) + '</td>' +
            '<td style="text-align:right;">' + esc(r.PO_Qty) + '</td>' +
            '<td><button class="btn btn-danger btn-sm" onclick="deleteRow(' + r.id + ', this)">Del</button></td>' +
            '</tr>';
    }
    tbody.innerHTML = html;

    // mark sorted column
    var ths = document.querySelectorAll('#rawTable th.sortable');
    for (var k=0; k<ths.length; k++) {
        ths[k].classList.remove('asc');
        ths[k].classList.remove('desc');
        if (ths[k].getAttribute('data-col') === sortCol) {
            ths[k].classList.add(sortDir);
        }
    }
}

function renderPager(data) {
    var area = document.getElementById('pagerArea');
    if (data.totalPages <= 1) { area.innerHTML = ''; return; }

    var html = '';
    html += '<button onclick="goPage(' + (data.page - 1) + ')"' + (data.page <= 1 ? ' disabled' : '') + '>« Prev</button>';

    var start = Math.max(1, data.page - 3);
    var end   = Math.min(data.totalPages, start + 6);
    start     = Math.max(1, end - 6);

    if (start > 1) {
        html += '<button onclick="goPage(1)">1</button>';
        if (start > 2) html += '<button disabled>...</button>';
    }

    for (var p=start; p<=end; p++) {
        html += '<button onclick="goPage(' + p + ')"' + (p === data.page ? ' class="active"' : '') + '>' + p + '</button>';
    }

    if (end < data.totalPages) {
        if (end < data.totalPages - 1) html += '<button disabled>...</button>';
        html += '<button onclick="goPage(' + data.totalPages + ')">' + data.totalPages + '</button>';
    }

    html += '<button onclick="goPage(' + (data.page + 1) + ')"' + (data.page >= data.totalPages ? ' disabled' : '') + '>Next »</button>';
    html += '<div class="pager-info">Page ' + data.page + ' of ' + data.totalPages + '</div>';

    area.innerHTML = html;
}

function goPage(p) {
    if (p < 1 || p > totalPages) return;
    currentPage = p;
    loadTable();
}

function deleteRow(id, btn) {
    if (!confirm('Delete this record? This cannot be undone.')) return;
    btn.disabled = true;
    ajaxJSON('POST', BASE4 + '?ajax=delete', {id:id}, function(err, data){
        if (err || (data && data.error)) {
            alert('Delete failed: ' + (data ? data.error : err));
            btn.disabled = false;
            return;
        }
        loadTable();
    });
}

// Sorting click handlers
(function bindSort(){
    var ths = document.querySelectorAll('#rawTable th.sortable');
    for (var i=0; i<ths.length; i++) {
        ths[i].onclick = function(){
            var col = this.getAttribute('data-col');
            if (sortCol === col) {
                sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
            } else {
                sortCol = col;
                sortDir = 'asc';
            }
            currentPage = 1;
            loadTable();
        };
    }
})();

loadTable();
</script>