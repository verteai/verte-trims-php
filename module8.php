<?php
session_start();
require_once dirname(__FILE__) . '/config.php';

// If already logged in
if (isset($_SESSION['username'])) {
    header('Location: main.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($username !== '' && $password !== '') {

        // ⚠ connect to USER MANAGEMENT database
        $sql = "
            SELECT username
            FROM user_management.dbo.TBL_USER_MANAGEMENT
            WHERE username = ? AND password = ?
        ";

        $rows = dbQuery($sql, array($username, $password));

        if (!isset($rows['__error']) && count($rows) === 1) {
            $_SESSION['username'] = $rows[0]['username'];
            header('Location: main.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Please enter username and password';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>System Login</title>
<style>
body{
    background:#f5f7fb;
    font-family:Arial;
}
.login-box{
    width:360px;
    margin:120px auto;
    background:#fff;
    padding:30px;
    border-radius:6px;
    box-shadow:0 2px 6px rgba(0,0,0,.15);
}
h2{text-align:center;margin-bottom:20px}
input{
    width:100%;
    padding:10px;
    margin-bottom:12px;
    border:1px solid #ccc;
    border-radius:4px;
}
button{
    width:100%;
    padding:10px;
    background:#1976d2;
    color:#fff;
    border:none;
    border-radius:4px;
    cursor:pointer;
    font-size:15px;
}
.error{
    color:#c62828;
    text-align:center;
    margin-bottom:10px;
}
</style>
</head>
<body>

<div class="login-box">
    <h2>System Login</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="text" name="username" placeholder="Username">
        <input type="password" name="password" placeholder="Password">
        <button type="submit">Login</button>
    </form>
</div>

</body>
</html>
