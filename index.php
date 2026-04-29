<?php
// TRIMS Inspection System - Login Page
session_start();

// If already logged in, go straight to main
if (!empty($_SESSION['username'])) {
    header('Location: main.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_once dirname(__FILE__) . '/config.php';

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {

        // Authenticate against user_management database on same server
        $sql = "
            SELECT username
            FROM user_management.dbo.TBL_USER_MANAGEMENT
            WHERE username = ? AND password = ?
        ";

        $rows = dbQuery($sql, array($username, $password));

        if (!isset($rows['__error']) && count($rows) === 1) {
            // Regenerate session ID on login to prevent session fixation
            session_regenerate_id(true);
            $_SESSION['username']   = $rows[0]['username'];
            $_SESSION['logged_in']  = true;
            $_SESSION['login_time'] = time();
            header('Location: main.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>TRIMS INSPECTION SYSTEM</title>

<style>
/* ===== Base ===== */
* { box-sizing: border-box; margin:0; padding:0; }

body{
    font-family: 'Segoe UI', Arial, sans-serif;
    background: linear-gradient(135deg, #1a3a5c, #243b55);
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    color:#333;
}

/* ===== Card ===== */
.login-wrapper{
    background:#fff;
    width:380px;
    border-radius:8px;
    box-shadow:0 10px 30px rgba(0,0,0,.35);
    overflow:hidden;
}

.login-header{
    background:#1a3a5c;
    color:#fff;
    padding:22px 20px;
    text-align:center;
}

.login-header h1{
    font-size:1.25rem;
    font-weight:700;
    letter-spacing:.5px;
}

.login-header p{
    font-size:.8rem;
    opacity:.85;
    margin-top:4px;
}

/* ===== Body ===== */
.login-body{
    padding:26px 28px 30px;
}

label{
    font-size:.8rem;
    font-weight:600;
    color:#555;
    display:block;
    margin-bottom:5px;
}

input{
    width:100%;
    padding:10px 12px;
    border-radius:5px;
    border:1px solid #c8d0da;
    font-size:.9rem;
    margin-bottom:16px;
}

input:focus{
    outline:none;
    border-color:#1a73e8;
}

/* ===== Button ===== */
button{
    width:100%;
    padding:10px;
    background:#1a73e8;
    color:#fff;
    border:none;
    border-radius:5px;
    font-size:.9rem;
    font-weight:600;
    cursor:pointer;
}

button:hover{ opacity:.9; }

/* ===== Error ===== */
.error{
    background:#ffebee;
    color:#c62828;
    padding:8px 12px;
    border-radius:4px;
    font-size:.8rem;
    margin-bottom:14px;
    text-align:center;
    border-left:4px solid #c62828;
}

/* ===== Footer ===== */
.login-footer{
    text-align:center;
    padding:12px;
    font-size:.75rem;
    color:#888;
    background:#f7f9fb;
}
</style>
</head>

<body>

<div class="login-wrapper">

    <div class="login-header">
        <h1>TRIMS INSPECTION SYSTEM</h1>
        <p>Quality Control & Inspection Management</p>
    </div>

    <div class="login-body">

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <label>Username</label>
            <input type="text" name="username" autofocus>

            <label>Password</label>
            <input type="password" name="password">

            <button type="submit">Login</button>
        </form>
    </div>

    <div class="login-footer">
        © <?php echo date('Y'); ?> TRIMS Inspection System
    </div>

</div>

</body>
</html>