<?php
/* Long requests (grid load / refresh) — avoid PHP cutting off long-running SP */
set_time_limit(0);
ini_set('max_execution_time', 0);

require_once dirname(__FILE__) . '/config.php';

/* Allowed server-side sort columns (security whitelist; matches TRIMS_TBL_RAWDATA) */
$ALLOWED_SORT = array(
    'IO_Num','PO_Num','GMC_Description','Addl_Description',
    'Vendor_Name','Customer_Name','GR_Num','Ship_Mode','Vessel','Voyage','Container_Num','Uom','HBL','MBL','PO_Qty'
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
                  OR Addl_Description LIKE ? OR Vendor_Name LIKE ? OR Customer_Name LIKE ?
                  OR ISNULL(CAST(GR_Num AS VARCHAR(64)),'') LIKE ?
                  OR ISNULL(CAST(Ship_Mode AS VARCHAR(128)),'') LIKE ?
                  OR ISNULL(CAST(Vessel AS VARCHAR(128)),'') LIKE ?
                  OR ISNULL(CAST(Voyage AS VARCHAR(128)),'') LIKE ?
                  OR ISNULL(CAST(Container_Num AS VARCHAR(128)),'') LIKE ?
                  OR ISNULL(CAST(Uom AS VARCHAR(64)),'') LIKE ?
                  OR ISNULL(CAST(HBL AS VARCHAR(128)),'') LIKE ?
                  OR ISNULL(CAST(MBL AS VARCHAR(128)),'') LIKE ?";
        $params = array($like,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like);
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
                       Vendor_Name, Customer_Name, GR_Num, Ship_Mode, Vessel, Voyage,
                       Container_Num, Uom, HBL, MBL, PO_Qty
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
   AJAX: refresh raw data — INSERT from linked-server view (may run a long time)
   ═══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'refresh_rawdata' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    set_time_limit(0);
    ini_set('max_execution_time', '0');
    @ini_set('default_socket_timeout', '7200');
    ignore_user_abort(true);

    $conn = getDB();

    @mssql_query('SET NOCOUNT ON', $conn);
    @mssql_query('SET ANSI_NULLS ON', $conn);
    @mssql_query('SET ANSI_WARNINGS ON', $conn);
    @mssql_query('SET LOCK_TIMEOUT -1', $conn);
    @mssql_query('SET QUERY_GOVERNOR_COST_LIMIT 0', $conn);

    $refreshSql = <<<'SQL'
    SET ANSI_NULLS ON;
    SET ANSI_WARNINGS ON;
    exec TRIMS_USP_GETRAWDATA
SQL;

    $res = @mssql_query($refreshSql, $conn);

    if (!$res) {
        $detail = '';
        if (function_exists('mssql_get_last_message')) {
            $detail = trim((string)mssql_get_last_message());
        }
        echo json_encode(array(
            'error'  => 'Refresh INSERT failed.',
            'detail' => $detail !== '' ? $detail : 'No SQL message returned. Check linked server, view name, and column list vs TRIMS_TBL_RAWDATA.'
        ));
        exit;
    }

    do {
        while (@mssql_fetch_row($res)) {}
    } while (@mssql_next_result($res));
    @mssql_free_result($res);

    echo json_encode(array('success' => true));
    exit;
}
?>
<style>
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

.refresh-toolbar{margin-top:10px;}
#refreshRawStatus{font-size:.82rem;color:#555;margin-left:12px;}

.tbl-container{overflow-x:auto;}
#rawTable{min-width:1680px;}
#rawTable td,#rawTable th{white-space:nowrap;}

th.sortable{cursor:pointer;}
th.sortable.asc:after{content:" ▲";font-size:.7em;}
th.sortable.desc:after{content:" ▼";font-size:.7em;}

/* ── Mobile responsive ── */
@media (max-width: 768px){
    .toolbar .left,
    .toolbar .right{
        float:none;
        width:100%;
    }
    .toolbar .left{ margin-bottom:8px; }
    .search-box{ width:100%; }
    .toolbar .right{
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        gap:6px;
    }
    .toolbar .right select{ flex:0 0 auto; }
    #totalCount{ line-height:normal !important; margin-left:auto; }

    .pager button{
        padding:6px 9px;
        font-size:.78rem;
        margin:1px;
    }
    #rawTable{ font-size:.78rem; min-width:1100px; }
    #rawTable td, #rawTable th{ padding:7px 8px; }
}
</style>

<!-- Refresh raw data -->
<div class="card">
    <div class="card-title">Raw Data Feed</div>
    <div class="refresh-toolbar">
        <button type="button" class="btn btn-primary" id="refreshRawBtn">Refresh Raw Data</button>
        <span id="refreshRawStatus"></span>
    </div>
</div>
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
                <th class="sortable" data-col="GR_Num">GR Num</th>
                <th class="sortable" data-col="Ship_Mode">Ship Mode</th>
                <th class="sortable" data-col="Vessel">Vessel</th>
                <th class="sortable" data-col="Voyage">Voyage</th>
                <th class="sortable" data-col="Container_Num">Container Num</th>
                <th class="sortable" data-col="Uom">Uom</th>
                <th class="sortable" data-col="HBL">HBL</th>
                <th class="sortable" data-col="MBL">MBL</th>
                <th class="sortable" data-col="PO_Qty" style="width:72px;">Rec Qty</th>
                <th style="width:60px;">Action</th>
            </tr>
            </thead>
            <tbody id="rawBody">
            <tr><td colspan="17" style="text-align:center;color:#999;padding:20px;">Loading...</td></tr>
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
var RAW_COL_SPAN = 17;

/** MSSQL driver may return mixed-case keys */
function rowCol(r, name) {
    if (!r) return '';
    if (r[name] !== undefined && r[name] !== null) return r[name];
    var low = name.toLowerCase();
    for (var k in r) {
        if (r.hasOwnProperty(k) && k.toLowerCase() === low) return r[k];
    }
    return '';
}

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

document.getElementById('refreshRawBtn').onclick = function() {
    if (!confirm('Refresh raw data now? This loads from the linked server and may take a long time.')) return;

    var btn = document.getElementById('refreshRawBtn');
    var st  = document.getElementById('refreshRawStatus');
    btn.disabled = true;
    st.innerHTML = '<span style="color:#1565c0;">Running… please wait.</span>';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BASE4 + '?ajax=refresh_rawdata', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.timeout = 0;

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        if (xhr.status < 200 || xhr.status >= 300) {
            st.innerHTML = '<span style="color:#c62828;">HTTP ' + xhr.status + '</span>';
            return;
        }
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.success) {
                st.innerHTML = '<span style="color:#2e7d32;">Finished successfully.</span>';
                loadTable();
            } else {
                var msg = esc(data.error || 'Failed.');
                if (data.detail) {
                    msg += '<br><span style="font-size:.78rem;">' + esc(data.detail) + '</span>';
                }
                st.innerHTML = '<span style="color:#c62828;">' + msg + '</span>';
            }
        } catch (e) {
            st.innerHTML = '<span style="color:#c62828;">Invalid server response.</span>';
        }
    };

    xhr.send('{}');
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
        '<tr><td colspan="' + RAW_COL_SPAN + '" style="text-align:center;color:#999;padding:16px;">Loading...</td></tr>';

    ajaxJSON('GET', url, null, function(err, data){
        if (err || !data) {
            document.getElementById('rawBody').innerHTML =
                '<tr><td colspan="' + RAW_COL_SPAN + '" style="text-align:center;color:#c62828;">Error loading data.</td></tr>';
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
            '<tr><td colspan="' + RAW_COL_SPAN + '" style="text-align:center;color:#999;padding:20px;">No records found.</td></tr>';
        return;
    }

    var offset = (data.page - 1) * data.perPage;
    var html = '';
    for (var i=0; i<rows.length; i++) {
        var r = rows[i];
        var num = offset + i + 1;
        html += '<tr>' +
            '<td style="text-align:center;font-weight:700;color:#1a3a5c;">' + num + '</td>' +
            '<td>' + esc(rowCol(r,'IO_Num')) + '</td>' +
            '<td>' + esc(rowCol(r,'PO_Num')) + '</td>' +
            '<td>' + esc(rowCol(r,'GMC_Description')) + '</td>' +
            '<td>' + esc(rowCol(r,'Addl_Description')) + '</td>' +
            '<td>' + esc(rowCol(r,'Vendor_Name')) + '</td>' +
            '<td>' + esc(rowCol(r,'Customer_Name')) + '</td>' +
            '<td>' + esc(rowCol(r,'GR_Num')) + '</td>' +
            '<td>' + esc(rowCol(r,'Ship_Mode')) + '</td>' +
            '<td>' + esc(rowCol(r,'Vessel')) + '</td>' +
            '<td>' + esc(rowCol(r,'Voyage')) + '</td>' +
            '<td>' + esc(rowCol(r,'Container_Num')) + '</td>' +
            '<td>' + esc(rowCol(r,'Uom')) + '</td>' +
            '<td>' + esc(rowCol(r,'HBL')) + '</td>' +
            '<td>' + esc(rowCol(r,'MBL')) + '</td>' +
            '<td style="text-align:right;">' + esc(rowCol(r,'PO_Qty')) + '</td>' +
            '<td><button class="btn btn-danger btn-sm" onclick="deleteRow(' + rowCol(r,'id') + ', this)">Del</button></td>' +
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