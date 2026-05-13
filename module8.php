<?php
require_once dirname(__FILE__) . '/config.php';

define('TRIMS_USER_TABLE', 'user_management.dbo.TBL_USER_MANAGEMENT');
define('TRIMS_ACCESS_TABLE', 'TRIMS_TBL_USERACCESS');

function trimsAccessDefinitions() {
    return array(
        1 => 'Inspection',
        2 => 'Dashboard',
        3 => 'Inspection Report',
        4 => 'Download Raw Data',
        5 => 'Performance Summary/Brand',
        6 => 'Dropdown Menu',
        7 => 'Week/Month',
        8 => 'User Maintenance',
    );
}

function trimsParseAccessCodes($raw) {
    $defs = trimsAccessDefinitions();
    $out = array();
    if ($raw === null || $raw === '') {
        return $out;
    }
    $parts = preg_split('/\s*,\s*/', trim($raw));
    if (!is_array($parts)) {
        return $out;
    }
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $c = (int)$p;
        if (isset($defs[$c])) {
            $out[$c] = 1;
        }
    }
    return array_keys($out);
}

function trimsSortAccessCodes($codes) {
    sort($codes, SORT_NUMERIC);
    return $codes;
}

function trimsAccessSummaryFromRows($rows) {
    $byUser = array();
    foreach ($rows as $r) {
        $u = isset($r['username']) ? trim($r['username']) : '';
        if ($u === '') {
            continue;
        }
        if (!isset($byUser[$u])) {
            $byUser[$u] = array();
        }
        $byUser[$u][] = (int)$r['access_code'];
    }
    $summ = array();
    foreach ($byUser as $u => $codes) {
        $codes = trimsSortAccessCodes(array_unique($codes));
        $summ[$u] = implode(', ', $codes);
    }
    return $summ;
}

/* ======================================================
   AJAX: Load datagrid
   ====================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load') {
    header('Content-Type: application/json');

    $page     = max(1, (int)$_GET['page']);
    $pageSize = (int)$_GET['pageSize'];
    $sortCol  = $_GET['sortCol'];
    $sortDir  = ($_GET['sortDir'] === 'DESC') ? 'DESC' : 'ASC';

    if (!in_array($pageSize, array(5, 10, 20, 50, 100))) {
        $pageSize = 5;
    }

    $allowedSort = array('id', 'username');
    if (!in_array($sortCol, $allowedSort)) {
        $sortCol = 'id';
    }

    $start = (($page - 1) * $pageSize) + 1;
    $end   = $page * $pageSize;

    $cnt = dbQuery('SELECT COUNT(*) AS cnt FROM ' . TRIMS_USER_TABLE, array());
    $total = (isset($cnt[0]['cnt'])) ? (int)$cnt[0]['cnt'] : 0;

    $sql = "
        SELECT *
        FROM (
            SELECT
                id, username,
                ROW_NUMBER() OVER (ORDER BY $sortCol $sortDir) AS rn
            FROM " . TRIMS_USER_TABLE . "
        ) t
        WHERE rn BETWEEN $start AND $end
        ORDER BY rn
    ";
    $rows = dbQuery($sql, array());
    if (isset($rows['__error'])) {
        echo json_encode(array('rows' => array(), 'total' => 0, 'error' => 'Query failed'));
        exit;
    }

    $usernames = array();
    for ($i = 0; $i < count($rows); $i++) {
        if (isset($rows[$i]['rn'])) {
            unset($rows[$i]['rn']);
        }
        $u = isset($rows[$i]['username']) ? trim($rows[$i]['username']) : '';
        if ($u !== '') {
            $usernames[] = $u;
        }
    }

    $summary = array();
    if (count($usernames) > 0) {
        $place = array();
        $params = array();
        for ($j = 0; $j < count($usernames); $j++) {
            $place[] = '?';
            $params[] = $usernames[$j];
        }
        $inList = implode(',', $place);
        $arows = dbQuery(
            "SELECT username, access_code FROM " . TRIMS_ACCESS_TABLE . " WHERE username IN ($inList) ORDER BY username, access_code",
            $params
        );
        if (!isset($arows['__error'])) {
            $summary = trimsAccessSummaryFromRows($arows);
        }
    }

    for ($k = 0; $k < count($rows); $k++) {
        $uk = isset($rows[$k]['username']) ? trim($rows[$k]['username']) : '';
        $rows[$k]['access_summary'] = ($uk !== '' && isset($summary[$uk])) ? $summary[$uk] : '';
    }

    echo json_encode(array(
        'rows'  => $rows,
        'total' => $total,
    ));
    exit;
}

/* ======================================================
   AJAX: Detail (checkboxes)
   ====================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    if ($id < 1) {
        echo json_encode(array('error' => 'Invalid id'));
        exit;
    }
    $urows = dbQuery(
        'SELECT id, username FROM ' . TRIMS_USER_TABLE . ' WHERE id = ?',
        array($id)
    );
    if (isset($urows['__error']) || count($urows) !== 1) {
        echo json_encode(array('error' => 'User not found'));
        exit;
    }
    $username = trim($urows[0]['username']);
    $arows = dbQuery(
        'SELECT access_code FROM ' . TRIMS_ACCESS_TABLE . ' WHERE username = ? ORDER BY access_code',
        array($username)
    );
    $codes = array();
    if (!isset($arows['__error'])) {
        foreach ($arows as $a) {
            $codes[] = (int)$a['access_code'];
        }
    }
    echo json_encode(array(
        'id'       => (int)$urows[0]['id'],
        'username' => $username,
        'access_codes' => $codes,
    ));
    exit;
}

/* ======================================================
   AJAX: Save (Add / Edit)
   ====================================================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save') {
    header('Content-Type: application/json');

    $defs = trimsAccessDefinitions();

    $id       = isset($_POST['id']) ? trim($_POST['id']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $accessRaw = isset($_POST['access']) ? trim($_POST['access']) : '';

    $codes = trimsSortAccessCodes(trimsParseAccessCodes($accessRaw));

    if ($username === '') {
        echo json_encode(array('error' => 'Username is required.'));
        exit;
    }

    $isNew = ($id === '');

    if ($isNew && $password === '') {
        echo json_encode(array('error' => 'Password is required for new users.'));
        exit;
    }

    $oldUsername = '';
    if (!$isNew) {
        $idval = (int)$id;
        if ($idval < 1) {
            echo json_encode(array('error' => 'Invalid record.'));
            exit;
        }
        $ex = dbQuery(
            'SELECT username FROM ' . TRIMS_USER_TABLE . ' WHERE id = ?',
            array($idval)
        );
        if (isset($ex['__error']) || count($ex) !== 1) {
            echo json_encode(array('error' => 'User not found.'));
            exit;
        }
        $oldUsername = trim($ex[0]['username']);
    }

    if ($isNew) {
        $ok = dbExec(
            'INSERT INTO ' . TRIMS_USER_TABLE . ' (username, password) VALUES (?,?)',
            array($username, $password)
        );
        if ($ok === false) {
            echo json_encode(array('error' => 'Could not save user (duplicate username?).'));
            exit;
        }
        $newIdRows = dbQuery('SELECT SCOPE_IDENTITY() AS id', array());
        $newId = (isset($newIdRows[0]['id'])) ? (int)$newIdRows[0]['id'] : 0;
    } else {
        if ($password !== '') {
            $ok = dbExec(
                'UPDATE ' . TRIMS_USER_TABLE . ' SET username = ?, password = ? WHERE id = ?',
                array($username, $password, (int)$id)
            );
        } else {
            $ok = dbExec(
                'UPDATE ' . TRIMS_USER_TABLE . ' SET username = ? WHERE id = ?',
                array($username, (int)$id)
            );
        }
        if ($ok === false) {
            echo json_encode(array('error' => 'Could not update user.'));
            exit;
        }
        $newId = (int)$id;
    }

    $accessUsername = $username;
    if (!$isNew && $oldUsername !== '' && $oldUsername !== $username) {
        dbExec('DELETE FROM ' . TRIMS_ACCESS_TABLE . ' WHERE username = ?', array($oldUsername));
    } else {
        dbExec('DELETE FROM ' . TRIMS_ACCESS_TABLE . ' WHERE username = ?', array($accessUsername));
    }

    if (count($codes) > 0) {
        for ($i = 0; $i < count($codes); $i++) {
            $ac = $codes[$i];
            $des = $defs[$ac];
            dbExec(
                'INSERT INTO ' . TRIMS_ACCESS_TABLE . ' (username, access_code, description) VALUES (?,?,?)',
                array($accessUsername, $ac, $des)
            );
        }
    }

    echo json_encode(array('success' => 1, 'id' => $newId));
    exit;
}

/* ======================================================
   AJAX: Delete
   ====================================================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete') {
    header('Content-Type: application/json');
    $delId = (int)$_POST['id'];
    if ($delId < 1) {
        echo json_encode(array('error' => 'Invalid id'));
        exit;
    }
    $ex = dbQuery(
        'SELECT username FROM ' . TRIMS_USER_TABLE . ' WHERE id = ?',
        array($delId)
    );
    if (isset($ex['__error']) || count($ex) !== 1) {
        echo json_encode(array('error' => 'User not found'));
        exit;
    }
    $un = trim($ex[0]['username']);
    dbExec('DELETE FROM ' . TRIMS_ACCESS_TABLE . ' WHERE username = ?', array($un));
    dbExec('DELETE FROM ' . TRIMS_USER_TABLE . ' WHERE id = ?', array($delId));
    echo json_encode(array('success' => 1));
    exit;
}

$accessDefs = trimsAccessDefinitions();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Module 8 – User Maintenance</title>
<style>
body{
    font-family: Arial, sans-serif;
    background:#f5f7fb;
    font-size:13px;
}

.card{
    background:#fff;
    border-radius:6px;
    padding:14px;
    margin-bottom:16px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
}

.card-title{
    font-weight:bold;
    font-size:15px;
    margin-bottom:12px;
}

input[type=text], input[type=password]{
    padding:7px 10px;
    border:1px solid #cfd8dc;
    border-radius:5px;
    font-size:13px;
}

button{
    padding:6px 12px;
    border-radius:5px;
    border:1px solid #999;
    background:#eee;
    cursor:pointer;
}

button.primary{
    background:#1976d2;
    color:#fff;
    border-color:#1976d2;
}

button.danger{
    background:#d84343;
    color:#fff;
    border-color:#d84343;
}

table{
    border-collapse:collapse;
    width:100%;
}

th{
    background:#1f3556;
    color:#fff;
    padding:10px;
    cursor:pointer;
    text-align:left;
    font-weight:600;
}

td{
    padding:9px 10px;
    border-bottom:1px solid #eceff1;
}

tbody tr:nth-child(even){background:#fafbfd;}
tbody tr:hover{background:#eef4ff;}

.table-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}

.search-box{
    width:260px;
}

.pagination{
    text-align:center;
    margin-top:14px;
}

.pagination button{
    margin:0 2px;
    padding:6px 10px;
}

.pagination .active{
    background:#1f3556;
    color:#fff;
}

.small-note{
    color:#666;
    font-size:12px;
}

.tbl-scroll{
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}
.tbl-scroll table{ min-width:680px; }

.form-row{
    display:flex;
    flex-wrap:wrap;
    align-items:flex-end;
    gap:10px;
}
.form-row .field{
    display:flex;
    flex-direction:column;
    flex:1 1 160px;
    min-width:0;
}
.form-row .field > label{
    font-size:12px;
    font-weight:600;
    color:#555;
    margin-bottom:3px;
}
.form-row .field input[type=text],
.form-row .field input[type=password]{
    width:100%;
}
.form-row .actions{
    display:flex;
    gap:6px;
    flex:0 0 auto;
}

.access-grid{
    display:flex;
    flex-wrap:wrap;
    gap:8px 20px;
    margin-top:8px;
}
.access-grid label{
    font-weight:normal;
    display:flex;
    align-items:center;
    gap:6px;
    cursor:pointer;
    min-width:220px;
}

@media (max-width: 768px){
    .card{ padding:12px; }
    .table-top{
        flex-direction:column;
        align-items:stretch;
        gap:8px;
    }
    .search-box{ width:100% !important; }

    .form-row .actions{ flex:1 1 100%; }
    .form-row .actions button{ flex:1; }

    .pagination button{
        margin:1px;
        padding:6px 9px;
        font-size:12px;
    }
}
</style>

</head>

<body>

<div class="card">
    <div class="card-title">Add / Edit User</div>

    <input type="hidden" id="id">

    <div class="form-row">
        <div class="field">
            <label>Username</label>
            <input type="text" id="username" autocomplete="username">
        </div>

        <div class="field">
            <label>Password <span class="small-note">(leave blank when editing to keep current)</span></label>
            <input type="password" id="password" autocomplete="new-password">
        </div>

        <div class="actions">
            <button type="button" class="primary" onclick="save()">Save</button>
            <button type="button" onclick="clearForm()">Clear</button>
        </div>
    </div>

    <div style="margin-top:14px;">
        <div class="small-note" style="font-weight:600; margin-bottom:6px;">Module access</div>
        <div class="access-grid" id="accessChecks">
            <?php foreach ($accessDefs as $code => $desc): ?>
            <label>
                <input type="checkbox" class="acc-cb" value="<?php echo (int)$code; ?>">
                <?php echo htmlspecialchars($code . ' – ' . $desc); ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-title">Users</div>

    <div class="table-top">
        <input type="text" id="searchBox" class="search-box"
               placeholder="Search ID, Username, Access"
               onkeyup="filterGrid()">

        <div>
            Show
            <select id="pageSize" onchange="loadGrid(1)">
                <option>5</option><option>10</option>
                <option>20</option><option>50</option>
                <option>100</option>
            </select>
            <span class="small-note">Total: <b id="totalCount">0</b></span>
        </div>
    </div>

    <div class="tbl-scroll">
    <table>
        <thead>
        <tr>
            <th onclick="sortBy('id')">ID</th>
            <th onclick="sortBy('username')">Username</th>
            <th>Access (codes)</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody id="gridBody"></tbody>
    </table>
    </div>

    <div class="pagination" id="pager"></div>
</div>

<script>
var page=1, sortCol='id', sortDir='ASC';

function getAccessCsv(){
    var boxes=document.getElementsByClassName('acc-cb');
    var a=[], i;
    for(i=0;i<boxes.length;i++){
        if(boxes[i].checked)a.push(boxes[i].value);
    }
    return a.join(',');
}

function setAccessFromCsv(csv){
    var boxes=document.getElementsByClassName('acc-cb');
    var map={}, parts, i, j;
    if(csv){
        parts=csv.split(',');
        for(i=0;i<parts.length;i++){
            map[parts[i].replace(/^\s+|\s+$/g,'')]=1;
        }
    }
    for(j=0;j<boxes.length;j++){
        boxes[j].checked = !!map[String(boxes[j].value)];
    }
}

function loadGrid(p){
    page=p||page;
    var ps=pageSize.value;
    var x=new XMLHttpRequest();
    x.open('GET',
        'module8.php?ajax=load&page='+page+
        '&pageSize='+ps+
        '&sortCol='+sortCol+'&sortDir='+sortDir,true);

    x.onreadystatechange=function(){
        if(x.readyState!==4)return;
        var r=JSON.parse(x.responseText);

        totalCount.innerText=r.total;

        var h='';
        for(var i=0;i<r.rows.length;i++){
            var d=r.rows[i];
            var sum=d.access_summary||'';
            h+='<tr>'+
            '<td>'+d.id+'</td>'+
            '<td>'+escapeHtml(d.username)+'</td>'+
            '<td>'+escapeHtml(sum)+(sum===''?' <span class="small-note">none</span>':'')+'</td>'+
            '<td>'+
            '<button type="button" onclick="editUser('+d.id+')">Edit</button> '+
            '<button type="button" class="danger" onclick="del('+d.id+')">Del</button>'+
            '</td></tr>';
        }
        gridBody.innerHTML=h||'<tr><td colspan="4" style="text-align:center;">No data</td></tr>';
        buildPager(r.total,ps);
        filterGrid();
    };
    x.send();
}

function escapeHtml(s){
    if(s===null||typeof s==='undefined')return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildPager(total,ps){
    var pages=Math.ceil(total/ps), h='', i;
    for(i=1;i<=pages;i++){
        h+='<button type="button" class="'+(i==page?'active':'')+
        '" onclick="loadGrid('+i+')">'+i+'</button>';
    }
    pager.innerHTML=h;
}

function sortBy(c){
    sortDir=(sortCol==c&&sortDir=='ASC')?'DESC':'ASC';
    sortCol=c; loadGrid(1);
}

function filterGrid(){
    var q=searchBox.value.toLowerCase();
    var rows=document.querySelectorAll('#gridBody tr');
    for(var i=0;i<rows.length;i++){
        rows[i].style.display =
        rows[i].innerText.toLowerCase().indexOf(q)>-1?'':'none';
    }
}

function save(){
    var p='ajax=save&id='+encodeURIComponent(id.value)+
    '&username='+encodeURIComponent(username.value)+
    '&password='+encodeURIComponent(password.value)+
    '&access='+encodeURIComponent(getAccessCsv());

    var x=new XMLHttpRequest();
    x.open('POST','module8.php',true);
    x.setRequestHeader('Content-type','application/x-www-form-urlencoded');
    x.onreadystatechange=function(){
        if(x.readyState!==4)return;
        var r;
        try{ r=JSON.parse(x.responseText);}catch(e){
            alert('Save failed.');
            return;
        }
        if(r.error){ alert(r.error); return; }
        clearForm();
        loadGrid(1);
    };
    x.send(p);
}

function editUser(uid){
    var x=new XMLHttpRequest();
    x.open('GET','module8.php?ajax=detail&id='+encodeURIComponent(uid),true);
    x.onreadystatechange=function(){
        if(x.readyState!==4)return;
        var r;
        try{ r=JSON.parse(x.responseText);}catch(e){ return; }
        if(r.error){ alert(r.error); return; }
        id.value=r.id;
        username.value=r.username;
        password.value='';
        var csv='';
        if(r.access_codes&&r.access_codes.length){
            csv=r.access_codes.join(',');
        }
        setAccessFromCsv(csv);
    };
    x.send();
}

function del(i){
    if(!confirm('Delete this user and all module access for them?'))return;
    var x=new XMLHttpRequest();
    x.open('POST','module8.php',true);
    x.setRequestHeader('Content-type','application/x-www-form-urlencoded');
    x.onreadystatechange=function(){if(x.readyState===4)loadGrid(1);};
    x.send('ajax=delete&id='+encodeURIComponent(i));
}

function clearForm(){
    id.value='';
    username.value='';
    password.value='';
    setAccessFromCsv('');
}

loadGrid(1);
</script>

</body>
</html>
