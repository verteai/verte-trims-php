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

require_once dirname(__FILE__) . '/config.php';

/**
 * Modules the signed-in user may open (TRIMS_TBL_USERACCESS.access_code equals module number).
 */
function trimsMainLoadAllowedModules($username) {
    $rows = dbQuery(
        'SELECT access_code FROM TRIMS_TBL_USERACCESS WHERE username = ? ORDER BY access_code',
        array($username)
    );
    $mods = array();
    if (isset($rows['__error']) || !is_array($rows)) {
        return $mods;
    }
    foreach ($rows as $r) {
        if (!isset($r['access_code'])) {
            continue;
        }
        $c = (int)$r['access_code'];
        if ($c >= 1 && $c <= 8) {
            $mods[$c] = 1;
        }
    }
    $out = array_keys($mods);
    sort($out, SORT_NUMERIC);
    return $out;
}

function trimsMainFilterNavMap($map, $allowedFlip) {
    $out = array();
    foreach ($map as $num => $label) {
        if (isset($allowedFlip[$num])) {
            $out[$num] = $label;
        }
    }
    return $out;
}

$validModules = array(1, 2, 3, 4, 5, 6, 7, 8);
$requestedModule = isset($_GET['module']) ? (int)$_GET['module'] : 1;
if (!in_array($requestedModule, $validModules)) {
    $requestedModule = 1;
}

$allowedModules = trimsMainLoadAllowedModules($_SESSION['username']);
$allowedFlip = array();
foreach ($allowedModules as $am) {
    $allowedFlip[$am] = 1;
}

$embed = isset($_GET['embed']) && $_GET['embed'] == '1';

if ($embed) {
    if (count($allowedModules) === 0 || !in_array($requestedModule, $allowedModules)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>TRIMS</title></head><body><p>You do not have access to this module.</p></body></html>';
        exit;
    }
    $module = $requestedModule;
} else {
    if (count($allowedModules) === 0) {
        $module = 0;
    } elseif (!in_array($requestedModule, $allowedModules)) {
        $module = $allowedModules[0];
    } else {
        $module = $requestedModule;
    }
}

// Full HTML document modules (iframe embed must not wrap in main.php shell)
$fullDocEmbedModules = array(3, 6, 7, 8);
if ($embed && in_array($module, $fullDocEmbedModules, true)) {
    $moduleFile = dirname(__FILE__) . '/module' . $module . '.php';
    if (file_exists($moduleFile)) {
        include $moduleFile;
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>TRIMS</title></head><body><p>Module not found.</p></body></html>';
    }
    exit;
}

// Tie module-tab sessionStorage to this PHP session so logout/login does not restore old tabs.
if (empty($_SESSION['trims_tab_sid'])) {
    $_SESSION['trims_tab_sid'] = uniqid('tt', true);
}

$moduleNames = array(
    1 => 'Inspection',
    2 => 'Dashboard',
    4 => 'Download Raw Data',

);

// Submenu items grouped under Reports
$reportSubmenus = array(
    3 => 'Inspection Report',
    5 => 'Performance Summary/Brand',
);

$fileSubmenus = array(
    6 => 'Dropdown Menu',
    7 => 'Week/Month',
    8 => 'User Maintenance',
);

$navMainModules = trimsMainFilterNavMap($moduleNames, $allowedFlip);
$navReportSubmenus = trimsMainFilterNavMap($reportSubmenus, $allowedFlip);
$navFileSubmenus = trimsMainFilterNavMap($fileSubmenus, $allowedFlip);

// All modules (for page header lookup)
$allModuleNames = array(
    1 => 'Inspection',
    2 => 'Dashboard',
    3 => 'Inspection Report',
    4 => 'Download Raw Data',
    5 => 'Performance Summary',
	6 => 'Dropdown Menu',
	7 => 'Week/Month',
	8 => 'User Maintenance',

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

        /* ── Module tabs (multi-module workspace) ── */
        .module-tab-strip {
            display: -webkit-box;
            display: -ms-flexbox;
            display: flex;
            -ms-flex-wrap: nowrap;
            flex-wrap: nowrap;
            gap: 4px;
            overflow-x: auto;
            margin: 0 0 18px 0;
            padding: 0 2px 10px 0;
            border-bottom: 2px solid #dde3ea;
            -webkit-overflow-scrolling: touch;
        }
        .module-tab {
            -webkit-box-flex: 0;
            -ms-flex: 0 0 auto;
            flex: 0 0 auto;
            display: -webkit-inline-box;
            display: -ms-inline-flexbox;
            display: inline-flex;
            -webkit-box-align: center;
            -ms-flex-align: center;
            align-items: center;
            gap: 6px;
            padding: 9px 12px;
            max-width: 220px;
            background: #e8ecf1;
            border: 1px solid #c8d0da;
            border-radius: 6px 6px 0 0;
            font-size: .81rem;
            color: #455a64;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        .module-tab:hover { background: #dfe6ee; }
        .module-tab.active {
            background: #fff;
            border-color: #1a73e8;
            border-bottom-color: #fff;
            color: #1a3a5c;
            font-weight: 600;
            margin-bottom: -2px;
            padding-bottom: 11px;
            position: relative;
            z-index: 1;
        }
        .module-tab-label {
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .module-tab-close {
            display: inline-block;
            padding: 0 4px;
            margin: 0 -2px 0 0;
            font-size: 1.1rem;
            line-height: 1;
            opacity: .55;
            color: inherit;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 400;
        }
        .module-tab-close:hover { opacity: 1; color: #c62828; }
        .module-frame-host {
            position: relative;
            background: transparent;
        }
        .module-frame-host iframe {
            display: none;
            width: 100%;
            min-height: calc(100vh - 220px);
            border: none;
            vertical-align: top;
            background: #f0f2f5;
        }
        .module-frame-host iframe.active {
            display: block;
        }
        .module-tab-strip:empty {
            display: none;
            margin: 0;
            padding: 0;
            border-bottom: none;
        }
        .module-empty-state {
            display: none;
            -webkit-box-orient: vertical;
            -webkit-box-direction: normal;
            -ms-flex-direction: column;
            flex-direction: column;
            -webkit-box-align: center;
            -ms-flex-align: center;
            align-items: center;
            -webkit-box-pack: center;
            -ms-flex-pack: center;
            justify-content: center;
            min-height: calc(100vh - 220px);
            padding: 28px 20px 40px;
            text-align: center;
            background: #f0f2f5;
        }
        .main.module-idle .module-empty-state {
            display: -webkit-box;
            display: -ms-flexbox;
            display: flex;
        }
        .main.module-idle .module-frame-host {
            display: none !important;
        }
        .no-access-panel {
            max-width: 520px;
            margin: 0 auto;
            padding: 28px 24px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(26, 58, 92, 0.12);
            text-align: left;
        }
        .no-access-panel h3 { color: #1a3a5c; margin-bottom: 10px; font-size: 1.1rem; }
        .no-access-panel p { color: #555; font-size: .9rem; line-height: 1.5; margin: 0; }

        .module-empty-hero {
            max-width: 1100px;
            width: 100%;
            height: auto;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(26, 58, 92, 0.12);
            vertical-align: top;
        }
        body.embed-body {
            background: #f0f2f5;
            margin: 0;
        }
        .main.embed-only {
            padding: 20px 24px 40px;
            min-height: 100vh;
            overflow-x: hidden;
        }

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

        /* ── Hamburger toggle (mobile only) ── */
        .menu-toggle {
            display: none;
            background: transparent;
            border: 1px solid rgba(255,255,255,.25);
            color: #fff;
            border-radius: 6px;
            padding: 6px 10px;
            margin-right: 12px;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
        }
        .menu-toggle:hover { background: rgba(255,255,255,.08); }

        /* ── Sidebar backdrop (mobile only) ── */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 998;
        }
        .sidebar-backdrop.open { display: block; }

        /* ═══════════════════════════════════════
           RESPONSIVE BREAKPOINTS
           ═══════════════════════════════════════ */

        /* Tablet (≤1024px) — tighter content padding */
        @media (max-width: 1024px) {
            .main { padding: 22px 20px; }
            .sidebar { width: 195px; min-width: 195px; }
            .topbar { padding: 0 16px; }
        }

        /* Mobile (≤768px) — sidebar becomes slide-in drawer */
        @media (max-width: 768px) {
            .topbar {
                padding: 0 12px;
                height: 52px;
            }
            .topbar-title { font-size: .95rem; }
            .topbar-sub   { display: none; }
            .topbar-date  { display: none; }
            .menu-toggle  { display: inline-block; }

            .topbar > div:last-child { gap: 8px !important; }
            .topbar > div:last-child > span:first-child { display: none !important; }

            .layout {
                display: block;
                min-height: calc(100vh - 52px);
            }

            .sidebar {
                position: fixed;
                top: 52px;
                left: 0;
                bottom: 0;
                width: 240px;
                min-width: 240px;
                z-index: 999;
                -webkit-transform: translateX(-100%);
                transform: translateX(-100%);
                transition: transform .25s ease;
                overflow-y: auto;
                box-shadow: 4px 0 18px rgba(0,0,0,.35);
            }
            .sidebar.open {
                -webkit-transform: translateX(0);
                transform: translateX(0);
            }

            .main {
                padding: 16px 14px;
                width: 100%;
            }
            .module-tab-strip { margin-bottom: 12px; padding-bottom: 8px; }
            .module-tab { max-width: 150px; font-size: .76rem; padding: 8px 8px; }
            .module-frame-host iframe { min-height: calc(100vh - 160px); }
            .module-empty-state { min-height: calc(100vh - 140px); padding: 16px 12px 28px; }
            .page-header { margin-bottom: 16px; padding-bottom: 10px; }
            .page-header h2 { font-size: 1.1rem; }
            .page-header p  { font-size: .78rem; }

            .card { padding: 14px 14px; margin-bottom: 14px; border-radius: 6px; }
            .card-title, .card-title-row { font-size: .9rem; }

            /* Ensure card-title-row stacks on mobile */
            .card-title-row .right {
                float: none;
                display: block;
                margin-top: 8px;
            }
        }

        /* Small phones (≤480px) — extra tightening */
        @media (max-width: 480px) {
            .topbar { padding: 0 8px; height: 48px; }
            .topbar-title { font-size: .85rem; }
            .layout { min-height: calc(100vh - 48px); }
            .sidebar { top: 48px; width: 84vw; max-width: 280px; }
            .menu-toggle { padding: 5px 9px; margin-right: 8px; font-size: 1rem; }

            .topbar > div:last-child {
                gap: 6px !important;
            }
            .topbar > div:last-child > span:nth-of-type(2) {
                padding: 4px 8px !important;
                font-size: .75rem !important;
            }
            .topbar > div:last-child > a {
                padding: 5px 9px !important;
                font-size: .75rem !important;
            }

            .main { padding: 12px 10px; }
            .page-header h2 { font-size: 1rem; }
            .card { padding: 12px 12px; }

            .btn { padding: 7px 14px; font-size: .82rem; }
            .btn-sm { padding: 4px 8px; font-size: .75rem; }
        }

        /* ════════════════════════════════════════════════
           CHATBOT  (floating analytics assistant)
           ════════════════════════════════════════════════ */
        .cb-trigger {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
            color: #fff;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            font-size: .82rem;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,.25);
            transition: transform .15s ease, box-shadow .15s ease;
            font-family: inherit;
        }
        .cb-trigger:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,.3);
            opacity: 1;
        }
        .cb-trigger .cb-trigger-icon {
            font-size: 1rem;
            line-height: 1;
        }
        .cb-trigger .cb-trigger-dot {
            width: 7px; height: 7px;
            background: #76ff03;
            border-radius: 50%;
            box-shadow: 0 0 6px #76ff03;
            display: inline-block;
            margin-left: 4px;
        }

        .cb-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.35);
            z-index: 1099;
        }
        .cb-backdrop.open { display: block; }

        .cb-panel {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 440px;
            max-width: 95vw;
            background: #f7f9fc;
            box-shadow: -8px 0 24px rgba(0,0,0,.25);
            z-index: 1100;
            transform: translateX(100%);
            transition: transform .28s ease;
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .cb-panel.open { transform: translateX(0); }

        .cb-header {
            background: linear-gradient(135deg, #0d2137 0%, #1a3a5c 50%, #0277bd 100%);
            color: #fff;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid #00bcd4;
            flex-shrink: 0;
        }
        .cb-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cb-header-title .cb-avatar {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 800;
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .cb-header-title .cb-name { font-weight: 700; font-size: .98rem; line-height: 1.2; }
        .cb-header-title .cb-sub  { font-size: .72rem; opacity: .75; margin-top: 2px; }
        .cb-header .cb-close {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.2);
            color: #fff;
            width: 30px; height: 30px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: inherit;
        }
        .cb-header .cb-close:hover { background: rgba(255,255,255,.2); }

        .cb-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            background: #f7f9fc;
        }

        .cb-msg {
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            max-width: 100%;
        }
        .cb-msg.user { align-items: flex-end; }
        .cb-msg.bot  { align-items: flex-start; }

        .cb-bubble {
            padding: 10px 14px;
            border-radius: 14px;
            font-size: .87rem;
            line-height: 1.45;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
            word-wrap: break-word;
            max-width: 92%;
        }
        .cb-msg.user .cb-bubble {
            background: linear-gradient(135deg, #1a73e8 0%, #1557b0 100%);
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .cb-msg.bot .cb-bubble {
            background: #fff;
            color: #263238;
            border: 1px solid #e0e6ed;
            border-bottom-left-radius: 4px;
            max-width: 100%;
            width: 100%;
        }
        .cb-time {
            font-size: .68rem;
            color: #90a4ae;
            margin-top: 3px;
            padding: 0 4px;
        }

        .cb-msg-title {
            font-weight: 800;
            color: #0d2137;
            font-size: .92rem;
            margin-bottom: 8px;
            border-bottom: 2px solid #e0f7fa;
            padding-bottom: 6px;
        }
        .cb-msg-title .cb-period {
            display: inline-block;
            background: #e1f5fe;
            color: #0277bd;
            font-weight: 700;
            font-size: .72rem;
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 6px;
            vertical-align: middle;
        }
        .cb-msg-foot {
            color: #607d8b;
            font-size: .78rem;
            margin-top: 8px;
            font-style: italic;
        }

        .cb-help-list {
            margin: 6px 0 4px 16px;
            padding: 0;
            font-size: .82rem;
            color: #37474f;
        }
        .cb-help-list li { margin-bottom: 4px; }

        .cb-empty {
            color: #78909c;
            text-align: center;
            padding: 14px;
            background: #eceff1;
            border-radius: 6px;
        }

        .cb-bigstat {
            font-size: 2.2rem;
            font-weight: 900;
            color: #0d47a1;
            text-align: center;
            padding: 12px 0 8px;
            letter-spacing: -0.02em;
        }

        .cb-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            font-size: .76rem;
            color: #455a64;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #cfd8dc;
        }
        .cb-meta b { color: #0d2137; }

        .cb-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin: 6px 0 4px;
        }
        .cb-kpi {
            border-radius: 8px;
            padding: 10px 12px;
            color: #fff;
            position: relative;
            box-shadow: 0 2px 6px rgba(0,0,0,.1);
        }
        .cb-kpi .lbl {
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            opacity: .9;
            margin-bottom: 3px;
        }
        .cb-kpi .val {
            font-size: 1.1rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.01em;
        }
        .cb-k-blue   { background: linear-gradient(135deg, #1976d2 0%, #0d47a1 100%); }
        .cb-k-green  { background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%); }
        .cb-k-red    { background: linear-gradient(135deg, #e53935 0%, #b71c1c 100%); }
        .cb-k-amber  { background: linear-gradient(135deg, #ff9800 0%, #e65100 100%); }
        .cb-k-cyan   { background: linear-gradient(135deg, #00acc1 0%, #006064 100%); }
        .cb-k-indigo { background: linear-gradient(135deg, #5c6bc0 0%, #283593 100%); }

        .cb-table-wrap {
            overflow-x: auto;
            margin-top: 8px;
            border: 1px solid #cfd8dc;
            border-radius: 6px;
        }
        .cb-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .76rem;
            background: #fff;
        }
        .cb-table thead th {
            background: linear-gradient(180deg, #37474f 0%, #263238 100%);
            color: #fff;
            font-weight: 700;
            text-align: left;
            padding: 7px 8px;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            position: sticky;
            top: 0;
        }
        .cb-table tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #eceff1;
            color: #37474f;
        }
        .cb-table tbody tr:nth-child(even) { background: #f5f7fa; }
        .cb-table tbody tr:hover { background: #e1f5fe; }

        .cb-suggest {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 10px 14px 0;
            background: #f7f9fc;
            border-top: 1px solid #e0e6ed;
            flex-shrink: 0;
        }
        .cb-chip {
            background: #fff;
            border: 1px solid #b0bec5;
            color: #1a3a5c;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: .74rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, border-color .15s;
            font-family: inherit;
        }
        .cb-chip:hover {
            background: #e1f5fe;
            border-color: #0277bd;
            color: #0277bd;
        }

        .cb-input-row {
            display: flex;
            gap: 8px;
            padding: 12px 14px 14px;
            background: #fff;
            border-top: 1px solid #e0e6ed;
            flex-shrink: 0;
        }
        .cb-input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid #cfd8dc;
            border-radius: 22px;
            font-size: .88rem;
            outline: none;
            font-family: inherit;
            background: #f7f9fc;
            transition: border-color .15s, background .15s;
        }
        .cb-input:focus {
            border-color: #00bcd4;
            background: #fff;
        }
        .cb-send {
            background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 42px; height: 42px;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
            font-family: inherit;
            transition: transform .15s, box-shadow .15s;
        }
        .cb-send:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(0,0,0,.25); }
        .cb-send:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        /* ── Insights cards ── */
        .cb-insight-intro {
            font-size: .82rem;
            color: #455a64;
            margin: 4px 0 10px;
        }
        .cb-insight-intro b { color: #0d2137; }
        .cb-insight-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .cb-insight {
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid #cfd8dc;
            border-left-width: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .cb-insight.cb-sev-critical { border-left-color: #c62828; background: #fff5f5; }
        .cb-insight.cb-sev-warning  { border-left-color: #ef6c00; background: #fff8e1; }
        .cb-insight.cb-sev-info     { border-left-color: #1976d2; background: #f1f8ff; }
        .cb-insight.cb-sev-good     { border-left-color: #2e7d32; background: #f1f8e9; }
        .cb-insight-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }
        .cb-sev-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 800;
            letter-spacing: .08em;
            color: #fff;
            line-height: 1.3;
        }
        .cb-sev-badge.cb-sev-critical { background: #c62828; }
        .cb-sev-badge.cb-sev-warning  { background: #ef6c00; }
        .cb-sev-badge.cb-sev-info     { background: #1976d2; }
        .cb-sev-badge.cb-sev-good     { background: #2e7d32; }
        .cb-insight-title {
            font-weight: 800;
            color: #0d2137;
            font-size: .86rem;
            line-height: 1.3;
        }
        .cb-insight-body {
            font-size: .81rem;
            color: #37474f;
            line-height: 1.45;
        }
        .cb-insight-body b { color: #0d2137; }
        .cb-insight-action {
            margin-top: 8px;
            padding: 7px 10px;
            background: rgba(13, 33, 55, .05);
            border-radius: 6px;
            font-size: .78rem;
            color: #263238;
            border: 1px dashed rgba(13, 33, 55, .2);
        }
        .cb-insight-action b { color: #0d2137; }

        /* ── Inline follow-up chips inside bot replies ── */
        .cb-followups {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px dashed #cfd8dc;
        }
        .cb-followups-label {
            font-size: .7rem;
            color: #607d8b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 6px;
        }
        .cb-followups-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .cb-followup-chip {
            background: #f5f9ff;
            border: 1px solid #b3d4fc;
            color: #0d47a1;
            border-radius: 999px;
            padding: 5px 11px;
            font-size: .73rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background .15s, border-color .15s, transform .1s;
        }
        .cb-followup-chip:hover {
            background: #1976d2;
            border-color: #1976d2;
            color: #fff;
            transform: translateY(-1px);
        }

        .cb-typing {
            display: inline-flex;
            gap: 4px;
            padding: 12px 16px;
        }
        .cb-typing span {
            width: 7px; height: 7px;
            background: #90a4ae;
            border-radius: 50%;
            animation: cb-bounce 1.2s infinite ease-in-out;
        }
        .cb-typing span:nth-child(2) { animation-delay: .2s; }
        .cb-typing span:nth-child(3) { animation-delay: .4s; }
        @keyframes cb-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: .4; }
            30%           { transform: translateY(-6px); opacity: 1; }
        }

        @media (max-width: 768px) {
            .cb-panel { width: 100%; }
            .cb-trigger { padding: 5px 10px; font-size: .76rem; }
            .cb-trigger .cb-trigger-text { display: none; }
        }
        @media (max-width: 480px) {
            .cb-trigger { padding: 4px 8px; }
            .cb-bigstat { font-size: 1.7rem; }
            .cb-kpi .val { font-size: 1rem; }
        }
    </style>
</head>
<body<?php echo $embed ? ' class="embed-body"' : ''; ?>>
<?php if ($embed): ?>
<main class="main embed-only">
<?php
$moduleFile = dirname(__FILE__) . '/module' . $module . '.php';
if (file_exists($moduleFile)) {
    include $moduleFile;
} else {
    echo '<div class="card"><div class="placeholder-box"><p>Module ' . (int)$module . ' is under construction.</p></div></div>';
}
?>
</main>

</body>
</html>
<?php exit; endif; ?>

<!-- Top Bar -->
<div class="topbar">
    <div style="display:-webkit-box;display:-ms-flexbox;display:flex;align-items:center;">
        <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle menu" onclick="toggleSidebar()">&#9776;</button>
        <div>
            <div class="topbar-title">TRIMS Inspection System</div>
            <div class="topbar-sub">Quality Control &amp; Inspection Management</div>
        </div>
    </div>

	
	<div style="display:-webkit-box;display:-ms-flexbox;display:flex;align-items:center;gap:16px;">
		<span style="font-size:.8rem;opacity:.7;"><?php echo date('F d, Y'); ?></span>
		<button type="button" class="cb-trigger" id="cbTrigger" onclick="cbOpen()" title="Open analytics assistant">
			<span class="cb-trigger-icon">&#129302;</span>
			<span class="cb-trigger-text">Ask AI</span>
			<span class="cb-trigger-dot"></span>
		</button>
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

<!-- Sidebar Backdrop (mobile) -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

<!-- ═══ Chatbot Panel ═══ -->
<div class="cb-backdrop" id="cbBackdrop" onclick="cbClose()"></div>
<aside class="cb-panel" id="cbPanel" aria-hidden="true">
	<div class="cb-header">
		<div class="cb-header-title">
			<div class="cb-avatar">AI</div>
			<div>
				<div class="cb-name">TRIMS Analytics Assistant</div>
				<div class="cb-sub">Ask about reports, defects, suppliers &amp; more</div>
			</div>
		</div>
		<button type="button" class="cb-close" onclick="cbClose()" title="Close">&times;</button>
	</div>

	<div class="cb-body" id="cbBody">
		<div class="cb-msg bot">
			<div class="cb-bubble">
				<div class="cb-msg-title">Hi <?php echo htmlspecialchars($_SESSION['username']); ?>! I'm your TRIMS analytics assistant.</div>
				<div>I can analyze inspection reports, defect rates, supplier &amp; brand performance, and more. Pick a suggestion below or ask me anything.</div>
			</div>
			<div class="cb-time">Now</div>
		</div>
	</div>

	<div class="cb-suggest" id="cbSuggest">
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Give me insights</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Summary this month</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Defect rate today</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Top 5 suppliers by defect rate</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Top 5 brands</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Top defects this month</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Failed inspections this week</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Compare this month vs last month</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Recent inspections</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Worst trim types last week</button>
		<button type="button" class="cb-chip" onclick="cbAsk(this.innerText)">Help</button>
	</div>

	<form class="cb-input-row" id="cbForm" onsubmit="return cbSubmit(event);">
		<input type="text" class="cb-input" id="cbInput"
			   placeholder="Ask about reports, defects, suppliers..."
			   autocomplete="off" maxlength="300" />
		<button type="submit" class="cb-send" id="cbSend" title="Send">&#10148;</button>
	</form>
</aside>

<!-- Layout -->
<div class="layout">

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <?php if (count($navMainModules) > 0): ?>
        <div class="section-label">Main Modules</div>
        <?php endif; ?>

        <?php
        $reportsActive = ($module > 0 && in_array($module, array_keys($navReportSubmenus)));
        $fileActive = ($module > 0 && in_array($module, array_keys($navFileSubmenus)));
        foreach ($navMainModules as $num => $name):
            $activeClass = ($module === $num) ? 'active' : '';
        ?>
        <a href="?module=<?php echo $num; ?>" data-module="<?php echo (int)$num; ?>" class="<?php echo $activeClass; ?>">
            <span class="nav-badge"><?php echo $num; ?></span>
            <?php echo htmlspecialchars($name); ?>
        </a>
        <?php endforeach; ?>

        <?php if (count($navReportSubmenus) > 0): ?>
        <!-- Reports group with submenu -->
        <span class="nav-group-label <?php echo $reportsActive ? 'open has-active' : ''; ?>"
              id="reportsGroupLabel"
              onclick="toggleReportsMenu()">
            <span class="nav-badge" style="background:<?php echo $reportsActive ? '#4db6f5' : '#607d8b'; ?>;">R</span>
            Reports
            <span class="caret">&#9660;</span>
        </span>
        <div class="nav-submenu <?php echo $reportsActive ? 'open' : ''; ?>" id="reportsSubmenu">
            <?php foreach ($navReportSubmenus as $num => $name):
                $activeClass = ($module === $num) ? 'active' : '';
            ?>
            <a href="?module=<?php echo $num; ?>" data-module="<?php echo (int)$num; ?>" class="<?php echo $activeClass; ?>">
                <?php echo htmlspecialchars($name); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>


        <?php if (count($navFileSubmenus) > 0): ?>
		<!-- File Maintenance group with submenu -->
        <span class="nav-group-label <?php echo $fileActive ? 'open has-active' : ''; ?>"
              id="fileGroupLabel"
              onclick="toggleFileMenu()">
            <span class="nav-badge" style="background:<?php echo $fileActive ? '#4db6f5' : '#607d8b'; ?>;">F</span>
            File Maintenance
            <span class="caret">&#9660;</span>
        </span>
        <div class="nav-submenu <?php echo $fileActive ? 'open' : ''; ?>" id="fileSubmenu">
            <?php foreach ($navFileSubmenus as $num => $name):
                $activeClass = ($module === $num) ? 'active' : '';
            ?>
            <a href="?module=<?php echo $num; ?>" data-module="<?php echo (int)$num; ?>" class="<?php echo $activeClass; ?>">
                <?php echo htmlspecialchars($name); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </nav>

    <!-- Main Content: tabbed module workspaces -->
    <main class="main<?php echo (count($allowedModules) === 0) ? ' module-idle' : ''; ?>" id="mainWorkspace">
        <div class="module-tab-strip" id="moduleTabStrip" role="tablist" aria-label="Open modules"></div>
        <div class="module-frame-host" id="moduleFrameHost"></div>
        <div class="module-empty-state" id="moduleEmptyState">
            <?php if (count($allowedModules) === 0): ?>
            <div class="no-access-panel">
                <h3>No module access</h3>
                <p>Your account is not assigned to any modules in User Maintenance. Contact an administrator to update your access in <strong>TRIMS_TBL_USERACCESS</strong>.</p>
            </div>
            <?php else: ?>
            <img class="module-empty-hero"
                 src="assets/trims-inspection-hero.png"
                 width="1200"
                 height="675"
                 alt="TRIMS Inspection System — Quality Control &amp; Inspection Management">
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
function toggleSidebar() {
    var sb = document.getElementById('sidebar');
    var bd = document.getElementById('sidebarBackdrop');
    if (!sb || !bd) { return; }
    var isOpen = sb.className.indexOf('open') !== -1;
    if (isOpen) {
        sb.className = sb.className.replace(' open', '').replace('open', '').replace(/^\s+|\s+$/g, '');
        if (sb.className === '') { sb.className = 'sidebar'; }
        bd.className = 'sidebar-backdrop';
    } else {
        sb.className = (sb.className + ' open').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        bd.className = 'sidebar-backdrop open';
    }
}

function closeSidebar() {
    var sb = document.getElementById('sidebar');
    var bd = document.getElementById('sidebarBackdrop');
    if (sb) { sb.className = (sb.className || '').replace(/\bopen\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '') || 'sidebar'; }
    if (bd) { bd.className = 'sidebar-backdrop'; }
}

(function bindSidebarAutoClose() {
    var sb = document.getElementById('sidebar');
    if (!sb) { return; }
    var links = sb.getElementsByTagName('a');
    for (var i = 0; i < links.length; i++) {
        links[i].addEventListener('click', function() {
            if (window.innerWidth <= 768) { closeSidebar(); }
        }, false);
    }
})();

/* Multi-module tabs (iframes preserve each module's state) */
(function trimsModuleTabs() {
    var STRIP_ID = 'moduleTabStrip';
    var HOST_ID = 'moduleFrameHost';
    var MAIN_ID = 'mainWorkspace';
    var STORAGE_KEY = 'trims_module_tabs_v1';
    var ModuleNames = <?php echo json_encode($allModuleNames); ?>;
    var validMods = [1, 2, 3, 4, 5, 6, 7, 8];
    var allowedMods = <?php echo json_encode(array_values($allowedModules)); ?>;
    var initialMod = <?php echo (int)$module; ?>;
    var tabSessionId = <?php echo json_encode(isset($_SESSION['trims_tab_sid']) ? $_SESSION['trims_tab_sid'] : ''); ?>;

    function modTitle(m) {
        var k = String(m);
        return (ModuleNames && ModuleNames[k]) ? ModuleNames[k] : ('Module ' + m);
    }

    function saveState(order, active) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                order: order || [],
                active: active,
                sid: tabSessionId
            }));
        } catch (e) { /* ignore */ }
    }

    function loadState() {
        try {
            var s = sessionStorage.getItem(STORAGE_KEY);
            if (!s) { return null; }
            var o = JSON.parse(s);
            if (!o || Object.prototype.toString.call(o.order) !== '[object Array]') { return null; }
            if (!tabSessionId || !o.sid || o.sid !== tabSessionId) { return null; }
            return o;
        } catch (e) { return null; }
    }

    function filterValidOrder(order) {
        var out = [];
        var seen = {};
        for (var i = 0; i < order.length; i++) {
            var m = parseInt(order[i], 10);
            if (validMods.indexOf(m) === -1) { continue; }
            if (allowedMods.indexOf(m) === -1) { continue; }
            if (seen[m]) { continue; }
            seen[m] = 1;
            out.push(m);
        }
        return out;
    }

    var strip = document.getElementById(STRIP_ID);
    var host = document.getElementById(HOST_ID);
    var mainWorkspace = document.getElementById(MAIN_ID);
    if (!strip || !host) { return; }

    var tabOrder = [];
    var activeMod = null;
    var iframesByMod = {};

    function setIdle(on) {
        if (!mainWorkspace) { return; }
        var c = mainWorkspace.className || '';
        if (on) {
            if (c.indexOf('module-idle') === -1) {
                mainWorkspace.className = (c + ' module-idle').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
            }
        } else {
            mainWorkspace.className = c.replace(/\bmodule-idle\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        }
    }

    function setUrlForModule(mod) {
        try {
            var base = 'main.php';
            var path = window.location.pathname || '';
            var parts = path.split('/');
            var last = parts[parts.length - 1] || '';
            if (last && last.indexOf('.php') !== -1) { base = last.split('?')[0]; }
            if (mod === null || typeof mod === 'undefined') {
                history.replaceState(null, '', base);
            } else {
                history.replaceState(null, '', base + '?module=' + mod);
            }
        } catch (e2) { /* ignore */ }
    }

    function clearSidebarActive() {
        var sb = document.getElementById('sidebar');
        if (!sb) { return; }
        var links = sb.getElementsByTagName('a');
        var i;
        for (i = 0; i < links.length; i++) {
            var a = links[i];
            if (!a.getAttribute('data-module')) { continue; }
            var c = a.className || '';
            a.className = c.replace(/\bactive\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        }
        var rl = document.getElementById('reportsGroupLabel');
        var fl = document.getElementById('fileGroupLabel');
        if (rl) {
            rl.className = (rl.className || '').replace(/\bhas-active\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        }
        if (fl) {
            fl.className = (fl.className || '').replace(/\bhas-active\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        }
    }

    function showEmptyState() {
        setIdle(true);
        activeMod = null;
        clearSidebarActive();
        setUrlForModule(null);
        saveState([], null);
    }

    function syncSidebar(mod) {
        var sb = document.getElementById('sidebar');
        if (!sb) { return; }
        var links = sb.getElementsByTagName('a');
        var i;
        for (i = 0; i < links.length; i++) {
            var a = links[i];
            var dm = a.getAttribute('data-module');
            if (!dm) { continue; }
            var c = a.className || '';
            if (parseInt(dm, 10) === mod) {
                if (c.indexOf('active') === -1) {
                    a.className = (c + ' active').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
                }
            } else {
                a.className = c.replace(/\bactive\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
            }
        }
        var reportMods = { 3: 1, 5: 1 };
        var fileMods = { 6: 1, 7: 1, 8: 1 };
        var rl = document.getElementById('reportsGroupLabel');
        var fl = document.getElementById('fileGroupLabel');
        if (rl) {
            var ra = reportMods[mod];
            var rlc = rl.className || '';
            rlc = rlc.replace(/\bhas-active\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
            if (ra) { rlc = (rlc + ' has-active').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, ''); }
            rl.className = rlc;
        }
        if (fl) {
            var fa = fileMods[mod];
            var flc = fl.className || '';
            flc = flc.replace(/\bhas-active\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
            if (fa) { flc = (flc + ' has-active').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, ''); }
            fl.className = flc;
        }
    }

    function showFrame(mod) {
        setIdle(false);
        var ids = host.getElementsByTagName('iframe');
        var i;
        for (i = 0; i < ids.length; i++) {
            var f = ids[i];
            var mm = parseInt(f.getAttribute('data-module'), 10);
            if (mm === mod) {
                f.className = 'active';
            } else {
                f.className = '';
            }
        }
        var tabs = strip.getElementsByClassName('module-tab');
        for (i = 0; i < tabs.length; i++) {
            var t = tabs[i];
            var tm = parseInt(t.getAttribute('data-module'), 10);
            if (tm === mod) {
                t.className = 'module-tab active';
            } else {
                t.className = 'module-tab';
            }
        }
        activeMod = mod;
        syncSidebar(mod);
        setUrlForModule(mod);
        saveState(tabOrder, activeMod);
    }

    function removeTab(mod) {
        var idx = tabOrder.indexOf(mod);
        if (idx === -1) { return; }
        tabOrder.splice(idx, 1);
        var fr = iframesByMod[mod];
        if (fr && fr.parentNode) { fr.parentNode.removeChild(fr); }
        delete iframesByMod[mod];
        var te = null;
        var tabs = strip.getElementsByClassName('module-tab');
        var i;
        for (i = 0; i < tabs.length; i++) {
            if (parseInt(tabs[i].getAttribute('data-module'), 10) === mod) {
                te = tabs[i];
                break;
            }
        }
        if (te && te.parentNode) { te.parentNode.removeChild(te); }

        if (tabOrder.length === 0) {
            showEmptyState();
            return;
        }
        var next = activeMod;
        if (activeMod === mod) {
            next = tabOrder[Math.max(0, idx - 1)] || tabOrder[0];
        }
        showFrame(next);
    }

    function addTab(mod, makeActive) {
        if (tabOrder.indexOf(mod) !== -1) {
            if (makeActive) { showFrame(mod); }
            return;
        }
        setIdle(false);
        tabOrder.push(mod);

        var tab = document.createElement('div');
        tab.className = 'module-tab' + (makeActive ? ' active' : '');
        tab.setAttribute('data-module', mod);
        tab.setAttribute('role', 'tab');
        tab.title = modTitle(mod);

        var lab = document.createElement('span');
        lab.className = 'module-tab-label';
        lab.appendChild(document.createTextNode(modTitle(mod)));
        tab.appendChild(lab);

        var cls = document.createElement('button');
        cls.type = 'button';
        cls.className = 'module-tab-close';
        cls.innerHTML = '\u00D7';
        cls.title = 'Close tab';
        cls.setAttribute('aria-label', 'Close tab');
        cls.onclick = function (e) {
            if (e.stopPropagation) { e.stopPropagation(); }
            removeTab(mod);
        };
        tab.appendChild(cls);

        tab.onclick = function () { showFrame(mod); };
        strip.appendChild(tab);

        var iframe = document.createElement('iframe');
        iframe.setAttribute('data-module', mod);
        iframe.setAttribute('title', modTitle(mod));
        iframe.src = 'main.php?embed=1&module=' + mod;
        if (makeActive) {
            iframe.className = 'active';
        }
        host.appendChild(iframe);
        iframesByMod[mod] = iframe;

        if (makeActive) {
            showFrame(mod);
        } else {
            saveState(tabOrder, activeMod);
        }
    }

    function openOrFocus(mod) {
        if (validMods.indexOf(mod) === -1) { return; }
        if (allowedMods.indexOf(mod) === -1) { return; }
        if (tabOrder.indexOf(mod) !== -1) {
            showFrame(mod);
        } else {
            addTab(mod, true);
        }
    }

    var st = loadState();
    var startOrder;
    if (allowedMods.length === 0) {
        startOrder = [];
    } else if (st && Object.prototype.toString.call(st.order) === '[object Array]') {
        if (st.order.length === 0) {
            startOrder = [];
        } else {
            startOrder = filterValidOrder(st.order);
            if (initialMod > 0 && startOrder.indexOf(initialMod) === -1 && allowedMods.indexOf(initialMod) !== -1) {
                startOrder.push(initialMod);
            }
        }
    } else {
        startOrder = (initialMod > 0 && allowedMods.indexOf(initialMod) !== -1) ? [initialMod] : [allowedMods[0]];
    }

    if (allowedMods.length > 0 && startOrder.length === 0) {
        startOrder = [allowedMods[0]];
    }

    tabOrder = [];
    if (allowedMods.length === 0 || startOrder.length === 0) {
        showEmptyState();
    } else {
        for (var si = 0; si < startOrder.length; si++) {
            addTab(startOrder[si], false);
        }
        var focusMod = initialMod;
        if (st && st.active !== null && typeof st.active !== 'undefined' && st.active !== '') {
            var am = parseInt(st.active, 10);
            if (!isNaN(am) && tabOrder.indexOf(am) !== -1) {
                focusMod = am;
            }
        } else if (startOrder.length > 0) {
            focusMod = startOrder[0];
        }
        showFrame(focusMod);
    }

    var sbel = document.getElementById('sidebar');
    if (sbel) {
        sbel.addEventListener('click', function (e) {
            var t = e.target;
            while (t && t !== sbel) {
                if (t.tagName === 'A' && t.getAttribute('data-module')) {
                    if (e.ctrlKey || e.metaKey || e.shiftKey) { return; }
                    if (typeof e.button === 'number' && e.button !== 0) { return; }
                    if (e.preventDefault) { e.preventDefault(); }
                    var dm = parseInt(t.getAttribute('data-module'), 10);
                    openOrFocus(dm);
                    if (window.innerWidth <= 768 && typeof closeSidebar === 'function') {
                        closeSidebar();
                    }
                    return;
                }
                t = t.parentNode;
            }
        }, false);
    }

    window.trimsOpenModuleTab = openOrFocus;
})();

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

/* ════════════════════════════════════════════════
   CHATBOT  (TRIMS analytics assistant)
   ════════════════════════════════════════════════ */
function cbOpen() {
    var p = document.getElementById('cbPanel');
    var b = document.getElementById('cbBackdrop');
    if (!p || !b) return;
    p.className = 'cb-panel open';
    b.className = 'cb-backdrop open';
    p.setAttribute('aria-hidden', 'false');
    setTimeout(function () {
        var inp = document.getElementById('cbInput');
        if (inp) inp.focus();
    }, 300);
}

function cbClose() {
    var p = document.getElementById('cbPanel');
    var b = document.getElementById('cbBackdrop');
    if (!p || !b) return;
    p.className = 'cb-panel';
    b.className = 'cb-backdrop';
    p.setAttribute('aria-hidden', 'true');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var p = document.getElementById('cbPanel');
        if (p && p.className.indexOf('open') !== -1) cbClose();
    }
});

document.addEventListener('click', function (e) {
    var t = e.target;
    while (t && t !== document) {
        if (t.getAttribute && t.getAttribute('data-cb-ask')) {
            var q = t.getAttribute('data-cb-ask');
            if (q) { cbAsk(q); }
            e.preventDefault();
            return;
        }
        t = t.parentNode;
    }
});

function cbTimeNow() {
    var d = new Date();
    var hh = d.getHours();
    var mm = d.getMinutes();
    var ampm = hh >= 12 ? 'PM' : 'AM';
    hh = hh % 12; if (hh === 0) hh = 12;
    if (mm < 10) mm = '0' + mm;
    return hh + ':' + mm + ' ' + ampm;
}

function cbEscape(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function cbAppendMessage(who, htmlContent, isHtml) {
    var body = document.getElementById('cbBody');
    if (!body) return;
    var wrap = document.createElement('div');
    wrap.className = 'cb-msg ' + who;
    var bub = document.createElement('div');
    bub.className = 'cb-bubble';
    bub.innerHTML = isHtml ? htmlContent : cbEscape(htmlContent);
    var t = document.createElement('div');
    t.className = 'cb-time';
    t.textContent = cbTimeNow();
    wrap.appendChild(bub);
    wrap.appendChild(t);
    body.appendChild(wrap);
    body.scrollTop = body.scrollHeight;
    return wrap;
}

function cbAppendTyping() {
    var body = document.getElementById('cbBody');
    if (!body) return null;
    var wrap = document.createElement('div');
    wrap.className = 'cb-msg bot';
    wrap.id = 'cbTypingMsg';
    var bub = document.createElement('div');
    bub.className = 'cb-bubble';
    bub.innerHTML = '<div class="cb-typing"><span></span><span></span><span></span></div>';
    wrap.appendChild(bub);
    body.appendChild(wrap);
    body.scrollTop = body.scrollHeight;
    return wrap;
}

function cbRemoveTyping() {
    var t = document.getElementById('cbTypingMsg');
    if (t && t.parentNode) t.parentNode.removeChild(t);
}

function cbAsk(text) {
    if (!text) return;
    var inp = document.getElementById('cbInput');
    if (inp) inp.value = text;
    cbSendMessage(text);
}

function cbSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();
    var inp = document.getElementById('cbInput');
    if (!inp) return false;
    var v = (inp.value || '').replace(/^\s+|\s+$/g, '');
    if (v === '') return false;
    cbSendMessage(v);
    return false;
}

function cbSendMessage(message) {
    var inp = document.getElementById('cbInput');
    var btn = document.getElementById('cbSend');
    cbAppendMessage('user', message, false);
    if (inp) inp.value = '';
    if (btn) btn.disabled = true;
    cbAppendTyping();

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'chatbot.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        cbRemoveTyping();
        if (btn) btn.disabled = false;
        if (xhr.status >= 200 && xhr.status < 300) {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            if (data && data.html) {
                cbAppendMessage('bot', data.html, true);
            } else if (data && data.error) {
                cbAppendMessage('bot', '<div class="cb-empty">' + cbEscape(data.error) + '</div>', true);
            } else {
                cbAppendMessage('bot', '<div class="cb-empty">Unexpected response from server.</div>', true);
            }
        } else if (xhr.status === 401) {
            cbAppendMessage('bot', '<div class="cb-empty">Your session has expired. Please refresh the page and log in again.</div>', true);
        } else {
            cbAppendMessage('bot', '<div class="cb-empty">Couldn\'t reach the analytics service (HTTP ' + xhr.status + ').</div>', true);
        }
        if (inp) inp.focus();
    };
    xhr.send('message=' + encodeURIComponent(message));
}

</script>
</body>
</html>