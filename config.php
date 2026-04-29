<?php
// ─────────────────────────────────────────────
//  Database Configuration  –  MSSQL native
//  Uses mssql_connect() built into PHP 5.3
//  No extra drivers or ODBC needed
// ─────────────────────────────────────────────

/*
define('DB_SERVER',   ''); // or e.g. 'DESKTOP-ABC\SQLEXPRESS'
define('DB_NAME',     '');   // your MSSQL database name
define('DB_USER',     '');   // SQL Server login
define('DB_PASS',     '');   // SQL Server password
*/

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = mssql_connect(DB_SERVER, DB_USER, DB_PASS);
        if (!$conn) {
            die('DB Connection failed. Check DB_SERVER, DB_USER, DB_PASS in config.php');
        }
        if (!mssql_select_db(DB_NAME, $conn)) {
            die('Cannot select database: ' . DB_NAME);
        }
    }
    return $conn;
}

// Run SELECT — returns array of assoc rows
function dbQuery($sql, $params) {
    $conn = getDB();
    $sql  = buildSql($sql, $params);
    $res  = mssql_query($sql, $conn);
    if (!$res) {
        return array('__error' => 'Query failed');
    }
    $rows = array();
    while ($row = mssql_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mssql_free_result($res);
    return $rows;
}

// Run INSERT / UPDATE / DELETE — returns affected count or false
function dbExec($sql, $params) {
    $conn = getDB();
    $sql  = buildSql($sql, $params);
    $res  = mssql_query($sql, $conn);
    if (!$res) { return false; }
    return mssql_rows_affected($conn);
}

// Get last inserted IDENTITY value
function dbLastId() {
    $conn = getDB();
    $res  = mssql_query("SELECT SCOPE_IDENTITY() AS id", $conn);
    if (!$res) { return 0; }
    $row  = mssql_fetch_assoc($res);
    mssql_free_result($res);
    return isset($row['id']) ? (int)$row['id'] : 0;
}

// Inline params into SQL safely (mssql has no native bind params)
function buildSql($sql, $params) {
    if (empty($params)) { return $sql; }
    foreach ($params as $p) {
        $pos = strpos($sql, '?');
        if ($pos !== false) {
            $sql = substr($sql, 0, $pos) . escapeMssql($p) . substr($sql, $pos + 1);
        }
    }
    return $sql;
}

function escapeMssql($val) {
    if (is_null($val))  { return 'NULL'; }
    if (is_int($val))   { return (string)$val; }
    if (is_float($val)) { return (string)$val; }
    if (is_bool($val))  { return $val ? '1' : '0'; }
    return "'" . str_replace("'", "''", $val) . "'";
}
?>
