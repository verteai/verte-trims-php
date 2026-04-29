<?php
// ─────────────────────────────────────────────
//  TRIMS Inspection System  –  Main Entry
//  PHP 5.3 compatible
// ─────────────────────────────────────────────
session_start();

// ── Session guard ─────────────────────────────
// Redirect to login if not authenticated
if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ── Handle logout ─────────────────────────────
if (isset($_GET['logout'])) {
    // Clear all session data and destroy the session cookie
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: index.php');
    exit;
}


$module = isset($_GET['module']) ? (int)$_GET['module'] : 1;
$validModules = array(1, 2, 3, 4, 5, 6, 7);
if (!in_array($module, $validModules)) { $module = 1; }

$moduleNames = array(
    1 => 'Encoding',
    2 => 'Dashboard',
    4 => 'Raw Data Upload',

);

// Submenu items grouped under Reports
$reportSubmenus = array(
    3 => 'Inspection Report',
    5 => 'Performance Summary/Brand',
);

$fileSubmenus = array(
    6 => 'Dropdown Menu',
    7 => 'Week/Month',
);

// All modules (for page header lookup)
$allModuleNames = array(
    1 => 'Encoding',
    2 => 'Dashboard',
    3 => 'Inspection Report',
    4 => 'Raw Data Upload',
    5 => 'Performance Summary',
	6 => 'Dropdown Menu',
	7 => 'Week/Month',

);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRIMS Inspection System</title>
    <style>
        /* ── Reset & Base ── */
        * { -webkit-box-sizing: border-box; box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #333; }

        /* ── Top Bar ── */
        .topbar {
            background: #1a3a5c;
            color: #fff;
            padding: 0 24px;
            height: 54px;
            display: -webkit-box;
            display: -ms-flexbox;
            display: flex;
            -webkit-box-align: center;
            -ms-flex-align: center;
            align-items: center;
            -webkit-box-pack: justify;
            -ms-flex-pack: justify;
            justify-content: space-between;
            box-shadow: 0 2px 6px rgba(0,0,0,.35);
        }
        .topbar-title  { font-weight: 700; font-size: 1.05rem; }
        .topbar-sub    { font-size: .78rem; opacity: .7; margin-top: 2px; }
        .topbar-date   { font-size: .8rem; opacity: .7; }

        /* ── Layout ── */
        .layout {
            display: -webkit-box;
            display: -ms-flexbox;
            display: flex;
            min-height: calc(100vh - 54px);
        }

        /* ── Sidebar ── */
        .sidebar {
            width: 215px;
            min-width: 215px;
            background: #21304a;
            padding: 18px 0;
        }
        .sidebar a {
            display: block;
            padding: 12px 22px;
            color: #b0bec5;
            text-decoration: none;
            font-size: .9rem;
            border-left: 3px solid transparent;
        }
        .sidebar a:hover  { background: #2c3e55; color: #fff; }
        .sidebar a.active { background: #2c3e55; color: #4db6f5; border-left-color: #4db6f5; font-weight: 600; }
        .sidebar .nav-badge {
            display: inline-block;
            background: #4db6f5;
            color: #1a3a5c;
            border-radius: 3px;
            font-size: .7rem;
            font-weight: 700;
            padding: 1px 6px;
            margin-right: 6px;
        }
        .sidebar .active .nav-badge { background: #4db6f5; }
        .sidebar a:hover .nav-badge { background: #fff; }
        .section-label {
            padding: 14px 22px 6px;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #546e7a;
        }

        /* ── Main ── */
        .main { -webkit-box-flex: 1; -ms-flex: 1; flex: 1; padding: 28px 32px; overflow-y: auto; }
        .page-header { margin-bottom: 22px; padding-bottom: 14px; border-bottom: 2px solid #dde3ea; }
        .page-header h2 { font-size: 1.3rem; color: #1a3a5c; }
        .page-header p  { font-size: .85rem; color: #666; margin-top: 4px; }

        /* ── Cards ── */
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: .95rem;
            font-weight: 600;
            color: #1a3a5c;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eef1f4;
        }
        .card-title-row {
            font-size: .95rem;
            font-weight: 600;
            color: #1a3a5c;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eef1f4;
            overflow: hidden;
        }
        .card-title-row .right { float: right; }

        /* ── Form ── */
        label { display: block; font-size: .82rem; font-weight: 600; color: #555; margin-bottom: 4px; }
        input[type=text], input[type=number], select {
            width: 100%; padding: 8px 11px;
            border: 1px solid #c8d0da; border-radius: 5px;
            font-size: .88rem; background: #fff;
        }
        input[readonly] { background: #f7f9fb; color: #555; }

        /* ── Buttons ── */
        .btn {
            display: inline-block;
            padding: 8px 18px; border: none; border-radius: 5px;
            font-size: .88rem; font-weight: 600; cursor: pointer;
            text-decoration: none;
        }
        .btn-primary   { background: #1a73e8; color: #fff; }
        .btn-success   { background: #27ae60; color: #fff; }
        .btn-danger    { background: #e53935; color: #fff; }
        .btn-secondary { background: #607d8b; color: #fff; }
        .btn:hover     { opacity: .85; }
        .btn-sm        { padding: 5px 10px; font-size: .8rem; }

        /* ── Table ── */
        .tbl-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        thead tr { background: #1a3a5c; color: #fff; }
        thead th { padding: 10px 12px; text-align: left; font-weight: 600; white-space: nowrap; }
        tbody tr:nth-child(even) { background: #f7f9fb; }
        tbody tr:hover           { background: #eaf4ff; }
        tbody td { padding: 9px 12px; border-bottom: 1px solid #eef1f4; vertical-align: middle; }
        tbody td select, tbody td input { width: 100%; min-width: 110px; }

        /* ── Badges ── */
        .badge      { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: .78rem; font-weight: 700; }
        .badge-pass { background: #e8f5e9; color: #2e7d32; }
        .badge-fail { background: #ffebee; color: #c62828; }
        .badge-none { background: #eee;    color: #888; }

        /* ── Alerts ── */
        .alert        { padding: 10px 16px; border-radius: 5px; margin-bottom: 14px; font-size: .88rem; }
        .alert-info   { background: #e3f2fd; color: #1565c0; border-left: 4px solid #1565c0; }
        .alert-ok     { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .alert-err    { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }

        /* ── Search row ── */
        .search-row { overflow: hidden; }
        .search-row .sf { float: left; width: 260px; margin-right: 10px; }
        .search-row .sb { float: left; padding-top: 20px; }

        /* ── Placeholder ── */
        .placeholder-box { text-align: center; padding: 60px 20px; color: #90a4ae; }
        .placeholder-box p { font-size: 1rem; }

        /* ── Reports group / submenu ── */
        .nav-group-label {
            display: block;
            padding: 11px 22px;
            color: #b0bec5;
            font-size: .9rem;
            border-left: 3px solid transparent;
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
        }
        .nav-group-label:hover { background: #2c3e55; color: #fff; }
        .nav-group-label.open  { background: #2c3e55; color: #fff; }
        .nav-group-label .caret { float: right; font-size: .7rem; margin-top: 3px; transition: transform .2s; }
        .nav-group-label.open .caret { -webkit-transform: rotate(180deg); transform: rotate(180deg); }
        .nav-submenu { display: none; background: #192840; }
        .nav-submenu.open { display: block; }
        .nav-submenu a {
            display: block;
            padding: 9px 22px 9px 34px;
            color: #90a4ae;
            text-decoration: none;
            font-size: .85rem;
            border-left: 3px solid transparent;
        }
        .nav-submenu a:hover  { background: #1f3550; color: #fff; }
        .nav-submenu a.active { background: #1f3550; color: #4db6f5; border-left-color: #4db6f5; font-weight: 600; }
        .nav-group-label.has-active { color: #4db6f5; }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="topbar">
    <div>
        <div class="topbar-title">TRIMS Inspection System</div>
        <div class="topbar-sub">Quality Control &amp; Inspection Management</div>
    </div>

	
	<div style="display:-webkit-box;display:-ms-flexbox;display:flex;align-items:center;gap:16px;">
		<span style="font-size:.8rem;opacity:.7;"><?php echo date('F d, Y'); ?></span>
		<span style="
			display:inline-flex;align-items:center;gap:8px;
			background:rgba(255,255,255,.1);
			padding:5px 12px;border-radius:20px;
			font-size:.82rem;">
			<span style="opacity:.7;">&#128100;</span>
			<strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
		</span>
		<a href="?logout"
		   onclick="return confirm('Are you sure you want to logout?');"
		   style="
			   display:inline-flex;align-items:center;gap:5px;
			   padding:6px 14px;
			   background:#e53935;
			   color:#fff;
			   text-decoration:none;
			   border-radius:4px;
			   font-weight:600;
			   font-size:.82rem;
		   ">
			&#9099; Logout
		</a>
	</div>


</div>

<!-- Layout -->
<div class="layout">

    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="section-label">Main Modules</div>

        <?php
        // Determine if active module is a report submenu item
        $reportsActive = in_array($module, array_keys($reportSubmenus));
		$fileActive = in_array($module, array_keys($fileSubmenus));
        // Render main nav items
        foreach ($moduleNames as $num => $name):
            $activeClass = ($module === $num) ? 'active' : '';
        ?>
        <a href="?module=<?php echo $num; ?>" class="<?php echo $activeClass; ?>">
            <span class="nav-badge"><?php echo $num; ?></span>
            <?php echo htmlspecialchars($name); ?>
        </a>
        <?php endforeach; ?>

        <!-- Reports group with submenu -->
        <span class="nav-group-label <?php echo $reportsActive ? 'open has-active' : ''; ?>"
              id="reportsGroupLabel"
              onclick="toggleReportsMenu()">
            <span class="nav-badge" style="background:<?php echo $reportsActive ? '#4db6f5' : '#607d8b'; ?>;">R</span>
            Reports
            <span class="caret">&#9660;</span>
        </span>
        <div class="nav-submenu <?php echo $reportsActive ? 'open' : ''; ?>" id="reportsSubmenu">
            <?php foreach ($reportSubmenus as $num => $name):
                $activeClass = ($module === $num) ? 'active' : '';
            ?>
            <a href="?module=<?php echo $num; ?>" class="<?php echo $activeClass; ?>">
                <?php echo htmlspecialchars($name); ?>
            </a>
            <?php endforeach; ?>
        </div>
		
		
		
		<!-- File Maintenance group with submenu -->
        <span class="nav-group-label <?php echo $fileActive ? 'open has-active' : ''; ?>"
              id="fileGroupLabel"
              onclick="toggleFileMenu()">
            <span class="nav-badge" style="background:<?php echo $fileActive ? '#4db6f5' : '#607d8b'; ?>;">F</span>
            File Maintenance
            <span class="caret">&#9660;</span>
        </span>
        <div class="nav-submenu <?php echo $fileActive ? 'open' : ''; ?>" id="fileSubmenu">
            <?php foreach ($fileSubmenus as $num => $name):
                $activeClass = ($module === $num) ? 'active' : '';
            ?>
            <a href="?module=<?php echo $num; ?>" class="<?php echo $activeClass; ?>">
                <?php echo htmlspecialchars($name); ?>
            </a>
            <?php endforeach; ?>
        </div>

    </nav>

    <!-- Main Content -->
    <main class="main">
        <div class="page-header">
            <h2><?php echo htmlspecialchars(isset($allModuleNames[$module]) ? $allModuleNames[$module] : 'Module ' . $module); ?></h2>
            <p>TRIMS Inspection System &rsaquo; <?php echo htmlspecialchars(isset($allModuleNames[$module]) ? $allModuleNames[$module] : 'Module ' . $module); ?></p>
        </div>

        <?php
        $moduleFile = dirname(__FILE__) . '/module' . $module . '.php';
        if (file_exists($moduleFile)) {
            include $moduleFile;
        } else {
            echo '<div class="card"><div class="placeholder-box"><p>Module ' . $module . ' is under construction.</p></div></div>';
        }
        ?>
    </main>
</div>

<script>
function toggleReportsMenu() {
    var lbl  = document.getElementById('reportsGroupLabel');
    var menu = document.getElementById('reportsSubmenu');
    if (!lbl || !menu) { return; }
    var isOpen = menu.className.indexOf('open') !== -1;
    if (isOpen) {
        menu.className  = 'nav-submenu';
        lbl.className   = lbl.className.replace(' open', '').replace('open', '').replace(/^\s+|\s+$/g,'');
        lbl.className   = ('nav-group-label' + (lbl.className.indexOf('has-active') !== -1 ? ' has-active' : '')).replace(/\s+/g,' ').replace(/^\s+|\s+$/g,'');
    } else {
        menu.className  = 'nav-submenu open';
        lbl.className   = 'nav-group-label open' + (lbl.className.indexOf('has-active') !== -1 ? ' has-active' : '');
    }
}


function toggleFileMenu() {
    var lbl  = document.getElementById('fileGroupLabel');
    var menu = document.getElementById('fileSubmenu');
    if (!lbl || !menu) { return; }
    var isOpen = menu.className.indexOf('open') !== -1;
    if (isOpen) {
        menu.className  = 'nav-submenu';
        lbl.className   = lbl.className.replace(' open', '').replace('open', '').replace(/^\s+|\s+$/g,'');
        lbl.className   = ('nav-group-label' + (lbl.className.indexOf('has-active') !== -1 ? ' has-active' : '')).replace(/\s+/g,' ').replace(/^\s+|\s+$/g,'');
    } else {
        menu.className  = 'nav-submenu open';
        lbl.className   = 'nav-group-label open' + (lbl.className.indexOf('has-active') !== -1 ? ' has-active' : '');
    }
}

</script>
</body>
</html>