<?php
require_once dirname(__FILE__) . '/config.php';

/* ======================================================
   AJAX: Load datagrid (MSSQL 2008 paging + sorting)
   ====================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load') {
    header('Content-Type: application/json');

    $page     = max(1, (int)$_GET['page']);
    $pageSize = (int)$_GET['pageSize'];
    $sortCol  = $_GET['sortCol'];
    $sortDir  = ($_GET['sortDir'] === 'DESC') ? 'DESC' : 'ASC';

    if (!in_array($pageSize, array(5,10,20,50,100))) $pageSize = 5;

    $allowedSort = array('id','category','cat_desc','description','IsForGrading');
    if (!in_array($sortCol, $allowedSort)) $sortCol = 'id';

    $start = (($page - 1) * $pageSize) + 1;
    $end   = $page * $pageSize;

    // total rows
    $cnt = dbQuery("SELECT COUNT(*) AS cnt FROM TRIMS_TBL_DROPDOWN", array());
    $total = (int)$cnt[0]['cnt'];

    // MSSQL 2008 paging
    $sql = "
        SELECT *
        FROM (
            SELECT
                id, category, cat_desc, description, IsForGrading,
                ROW_NUMBER() OVER (ORDER BY $sortCol $sortDir) AS rn
            FROM TRIMS_TBL_DROPDOWN
        ) t
        WHERE rn BETWEEN $start AND $end
        ORDER BY rn
    ";
    $rows = dbQuery($sql, array());

    echo json_encode(array(
        'rows' => $rows,
        'total' => $total
    ));
    exit;
}

/* ======================================================
   AJAX: Save (Add / Edit)
   ====================================================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save') {

    $id           = trim($_POST['id']);
    $category     = trim($_POST['category']);
    $cat_desc     = trim($_POST['cat_desc']);
    $description  = trim($_POST['description']);
    $isForGrading = trim($_POST['IsForGrading']);

    if ($category==='' || $cat_desc==='' || $description==='') {
        echo json_encode(array('error'=>'All fields are required.'));
        exit;
    }

    if ($id === '') {
        dbQuery(
            "INSERT INTO TRIMS_TBL_DROPDOWN
             (category, cat_desc, description, IsForGrading)
             VALUES (?,?,?,?)",
            array($category,$cat_desc,$description,$isForGrading)
        );
    } else {
        dbQuery(
            "UPDATE TRIMS_TBL_DROPDOWN
             SET category=?, cat_desc=?, description=?, IsForGrading=?
             WHERE id=?",
            array($category,$cat_desc,$description,$isForGrading,$id)
        );
    }
    echo json_encode(array('success'=>1));
    exit;
}

/* ======================================================
   AJAX: Delete
   ====================================================== */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete') {
    dbQuery("DELETE FROM TRIMS_TBL_DROPDOWN WHERE id = ?", array((int)$_POST['id']));
    echo json_encode(array('success'=>1));
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Module 6 – Dropdown List</title>
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

input[type=text], select{
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
</style>

</head>

<body>

<!-- ===== INPUT SECTION (ALWAYS ABOVE) ===== -->
<div class="card">
<b>Add / Edit Record</b><br><br>
<input type="hidden" id="id">

Category:
<select id="category">
<option value="">--</option>
<option>1</option><option>2</option><option>3</option>
<option>4</option><option>5</option>
</select>

Cat Desc:
<select id="cat_desc">
<option>TRIMS</option>
<option>DEFECTS</option>
<option>BRAND</option>
</select>

Description:
<input type="text" id="description">

For Grading:
<select id="IsForGrading">
<option value="0">No</option>
<option value="1">Yes</option>
</select>

<button onclick="save()">Save</button>
<button onclick="clearForm()">Clear</button>
</div>

<!-- ===== PAGINATION CONTROLS ===== -->
<div class="card">
Show
<select id="pageSize" onchange="loadGrid(1)">
<option>5</option><option>10</option><option>20</option>
<option>50</option><option>100</option>
</select>
entries
<span id="pager"></span>
</div>

<!-- ===== DATAGRID (BELOW INPUT) ===== -->
<div class="card">
<table>
<thead>
<tr>
<th onclick="sortBy('id')">ID</th>
<th onclick="sortBy('category')">Category</th>
<th onclick="sortBy('cat_desc')">Cat Desc</th>
<th onclick="sortBy('description')">Description</th>
<th onclick="sortBy('IsForGrading')">For Grading</th>
<th>Action</th>
</tr>
</thead>
<tbody id="gridBody"></tbody>
</table>
</div>

<script>
var page=1, sortCol='id', sortDir='ASC';

function loadGrid(p){
    page=p||page;
    var ps=document.getElementById('pageSize').value;
    var x=new XMLHttpRequest();
    x.open('GET','module6.php?ajax=load&page='+page+'&pageSize='+ps+
        '&sortCol='+sortCol+'&sortDir='+sortDir,true);
    x.onreadystatechange=function(){
        if(x.readyState!==4)return;
        var r=JSON.parse(x.responseText), h='';
        for(var i=0;i<r.rows.length;i++){
            var d=r.rows[i];
            h+='<tr>'+
            '<td>'+d.id+'</td>'+
            '<td>'+d.category+'</td>'+
            '<td>'+d.cat_desc+'</td>'+
            '<td>'+d.description+'</td>'+
            '<td>'+(d.IsForGrading=='1'?'Yes':'No')+'</td>'+
            '<td><button onclick=\'edit('+JSON.stringify(d)+')\'>Edit</button> '+
            '<button class="danger" onclick="del('+d.id+')">Delete</button></td></tr>';
        }
        gridBody.innerHTML=h||'<tr><td colspan="6">No data</td></tr>';
        buildPager(r.total,ps);
    };
    x.send();
}

function buildPager(total,ps){
    var pages=Math.ceil(total/ps), h=' Page: ';
    for(var i=1;i<=pages;i++){
        h+='<button '+(i==page?'style="font-weight:bold"':'')+
        ' onclick="loadGrid('+i+')">'+i+'</button> ';
    }
    pager.innerHTML=h;
}

function sortBy(c){
    sortDir=(sortCol==c&&sortDir=='ASC')?'DESC':'ASC';
    sortCol=c; loadGrid(1);
}

function save(){
    var p='ajax=save&id='+id.value+
    '&category='+category.value+
    '&cat_desc='+cat_desc.value+
    '&description='+encodeURIComponent(description.value)+
    '&IsForGrading='+IsForGrading.value;
    var x=new XMLHttpRequest();
    x.open('POST','module6.php',true);
    x.setRequestHeader('Content-type','application/x-www-form-urlencoded');
    x.onreadystatechange=function(){if(x.readyState===4){clearForm();loadGrid(1);}};
    x.send(p);
}

function edit(d){
    id.value=d.id;
    category.value=d.category;
    cat_desc.value=d.cat_desc;
    description.value=d.description;
    IsForGrading.value=d.IsForGrading;
}

function del(id){
    if(!confirm('Delete this record?'))return;
    var x=new XMLHttpRequest();
    x.open('POST','module6.php',true);
    x.setRequestHeader('Content-type','application/x-www-form-urlencoded');
    x.onreadystatechange=function(){if(x.readyState===4)loadGrid(1);};
    x.send('ajax=delete&id='+id);
}

function clearForm(){
    id.value=''; category.value=''; cat_desc.value='TRIMS';
    description.value=''; IsForGrading.value='0';
}

loadGrid(1);
</script>

</body>
</html>
