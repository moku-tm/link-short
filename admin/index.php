<?php
session_start();

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Models/LinkModel.php';

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    header('Location: ../installer/');
    exit;
}
$config = require $configFile;
if (empty($config['installed'])) {
    header('Location: ../installer/');
    exit;
}

$action = $_GET['action'] ?? '';
$error = '';
$settingsError = '';
$settingsSaved = isset($_GET['saved']);

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if ($user === ($config['admin_user'] ?? 'admin') && password_verify($pass, $config['admin_pass'] ?? '')) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $user;
        header('Location: ../admin/');
        exit;
    }
    $error = 'نام کاربری یا رمز عبور اشتباه است';
}

if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        exit('دسترسی غیرمجاز');
    }

    $newSiteName = trim($_POST['site_name'] ?? '');
    $newAdminUser = trim($_POST['admin_user'] ?? '');
    $newAdminPass = $_POST['admin_pass'] ?? '';

    if ($newSiteName === '' || $newAdminUser === '') {
        $settingsError = 'نام سایت و نام کاربری مدیر الزامی است.';
    } elseif ($newAdminPass !== '' && strlen($newAdminPass) < 6) {
        $settingsError = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
    } else {
        $config['site_name'] = $newSiteName;
        $config['admin_user'] = $newAdminUser;
        if ($newAdminPass !== '') {
            $config['admin_pass'] = password_hash($newAdminPass, PASSWORD_DEFAULT);
        }

        $configContent = "<?php\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configFile, $configContent) === false) {
            $settingsError = 'ذخیره تنظیمات انجام نشد. دسترسی نوشتن config.php را بررسی کنید.';
        } else {
            $_SESSION['admin_user'] = $newAdminUser;
            header('Location: ../admin/?saved=1');
            exit;
        }
    }
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ../admin/');
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    $code = $_POST['code'] ?? '';
    if (preg_match('/^[A-Za-z0-9]{7}$/', $code)) {
        $model = new LinkModel();
        $model->deleteByCode($code);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($action === 'data' && ($_GET['format'] ?? '') === 'json') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    $model = new LinkModel();
    $page = max(1, intval($_GET['page'] ?? 1));
    $per = 15;
    $offset = ($page - 1) * $per;
    $q = trim($_GET['q'] ?? '');
    $db = Database::getInstance();

    if ($q !== '') {
        $total = $db->fetch("SELECT COUNT(*) as c FROM links WHERE code LIKE ? OR original_url LIKE ?", ["%{$q}%", "%{$q}%"])['c'];
        $links = $db->fetchAll("SELECT * FROM links WHERE code LIKE ? OR original_url LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?", ["%{$q}%", "%{$q}%", $per, $offset]);
    } else {
        $total = $db->fetch("SELECT COUNT(*) as c FROM links")['c'];
        $links = $db->fetchAll("SELECT * FROM links ORDER BY created_at DESC LIMIT ? OFFSET ?", [$per, $offset]);
    }

    $totalClicks = $db->fetch("SELECT COALESCE(SUM(clicks),0) as t FROM links")['t'];
    $todayLinks = $db->fetch("SELECT COUNT(*) as c FROM links WHERE DATE(created_at) = CURDATE()")['c'];
    $todayClicks = $db->fetch("SELECT COALESCE(SUM(clicks),0) as t FROM links WHERE DATE(created_at) = CURDATE()")['t'];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'links' => $links,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, ceil($total / $per)),
        'stats' => [
            'total_links' => (int)$total,
            'total_clicks' => (int)$totalClicks,
            'today_links' => (int)$todayLinks,
            'today_clicks' => (int)$todayClicks,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!empty($_SESSION['admin_logged_in'])) {
    $model = new LinkModel();
    $db = Database::getInstance();
    $totalLinks = $model->getTotalLinks();
    $totalClicks = $model->getTotalClicks();
    $todayLinks = $db->fetch("SELECT COUNT(*) as c FROM links WHERE DATE(created_at) = CURDATE()")['c'];
    $todayClicks = $db->fetch("SELECT COALESCE(SUM(clicks),0) as t FROM links WHERE DATE(created_at) = CURDATE()")['t'];
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت - <?php echo htmlspecialchars($config['site_name'] ?? ''); ?></title>
    <link rel="icon" href="../assets/img/logo.webp" type="image/webp">
    <link rel="stylesheet" href="https://cdn.moku.ir/icon/FontAwesome.Pro.7.3.1/Web/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/dark.css">
    <style>
        @font-face {
            font-family: 'Arad';
            src: url('https://cdn.moku.ir/fonts/Arad/Arad-RegularDots4.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #080c18;
            --bg2: #0c1222;
            --bg3: #111827;
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.06);
            --glass-hover: rgba(255, 255, 255, 0.06);
            --accent: #00d4aa;
            --accent2: #20c997;
            --accent3: #63e6be;
            --danger: #ff4d6a;
            --danger-hover: #ff6b84;
            --text: #ffffff;
            --text2: rgba(255, 255, 255, 0.6);
            --text3: rgba(255, 255, 255, 0.35);
            --radius: 16px;
            --radius-sm: 10px;
            --font: 'Arad', sans-serif;
        }

        html, body {
            width: 100%;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: 'Arad';
            overflow-x: hidden;
        }

        ::-webkit-scrollbar {
            width: 7px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.03);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #00d4aa, #20c997);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #20c997, #00d4aa);
        }

        .bg-effects {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
        }

        .bg-orb-1 {
            width: 500px;
            height: 500px;
            background: var(--accent);
            top: -150px;
            right: -100px;
            animation: orbFloat 20s ease-in-out infinite;
        }

        .bg-orb-2 {
            width: 400px;
            height: 400px;
            background: var(--accent2);
            bottom: -100px;
            left: -80px;
            animation: orbFloat 25s ease-in-out infinite reverse;
        }

        .bg-orb-3 {
            width: 300px;
            height: 300px;
            background: var(--accent3);
            top: 40%;
            left: 50%;
            animation: orbFloat 18s ease-in-out infinite 5s;
        }

        @keyframes orbFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(30px, -20px) scale(1.05); }
            50% { transform: translate(-20px, 30px) scale(0.95); }
            75% { transform: translate(20px, 10px) scale(1.02); }
        }

        .bg-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.015) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.015) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        .login-wrap {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 24px;
            padding: 40px 32px;
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent), var(--accent2), var(--accent3));
        }

        .login-logo {
            width: 56px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: inline-grid;
            place-items: center;
            font-weight: 900;
            font-size: 24px;
            font-family: 'Arad';
            color: #fff;
            margin-bottom: 20px;
        }

        .login-card h2 {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .login-card > p {
            font-size: 12px;
            color: var(--text2);
            margin-bottom: 28px;
        }

        .field {
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text2);
            margin-bottom: 6px;
        }

        .field input {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--text);
            font: 500 13px var(--font);
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }

        .field input:focus {
            border-color: rgba(0, 212, 170, 0.4);
            background: rgba(255, 255, 255, 0.06);
        }

        .field input::placeholder {
            color: var(--text3);
        }

        .login-btn {
            width: 100%;
            height: 48px;
            border: none;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--accent), #00b894);
            color: #fff;
            font: 800 14px var(--font);
            cursor: pointer;
            margin-top: 8px;
            position: relative;
            overflow: hidden;
            transition: transform 0.15s, box-shadow 0.2s;
        }

        .login-btn:hover {
            box-shadow: 0 8px 30px rgba(0, 212, 170, 0.3);
        }

        .login-btn:active {
            transform: scale(0.98);
        }

        .login-btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent, rgba(255,255,255,0.15), transparent);
            transform: translateX(-100%);
            transition: transform 0.5s;
        }

        .login-btn:hover::after {
            transform: translateX(100%);
        }

        .alert {
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
            background: rgba(255, 77, 106, 0.12);
            border: 1px solid rgba(255, 77, 106, 0.2);
            color: var(--danger);
            text-align: center;
        }

        .admin-layout {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 28px;
            background: rgba(8, 12, 24, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
        }

        .topbar-logo {
            width: 32px;
            height: 28px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: grid;
            place-items: center;
            font-weight: 900;
            font-size: 14px;
            font-family: 'Arad';
            color: #fff;
            flex-shrink: 0;
        }

        .topbar-title {
            font-size: 14px;
            font-weight: 800;
            white-space: nowrap;
        }

        .topbar-sub {
            font-size: 11px;
            color: var(--text2);
            white-space: nowrap;
        }

        .topbar-spacer {
            flex: 1;
        }

        .topbar-link {
            font-size: 12px;
            color: var(--text2);
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 8px;
            transition: color 0.15s, background 0.15s;
            white-space: nowrap;
        }

        .topbar-link:hover {
            color: var(--text);
            background: rgba(255, 255, 255, 0.06);
        }

        .topbar-link.danger:hover {
            color: var(--danger);
            background: rgba(255, 77, 106, 0.1);
        }

        .main-content {
            flex: 1;
            padding: 28px;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 20px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            filter: blur(40px);
            opacity: 0.15;
            pointer-events: none;
        }

        .stat-card:nth-child(1)::before { background: var(--accent); }
        .stat-card:nth-child(2)::before { background: var(--accent2); }
        .stat-card:nth-child(3)::before { background: var(--accent3); }
        .stat-card:nth-child(4)::before { background: var(--danger); }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 15px;
            margin-bottom: 14px;
        }

        .stat-card:nth-child(1) .stat-icon { background: rgba(0, 212, 170, 0.12); color: var(--accent); }
        .stat-card:nth-child(2) .stat-icon { background: rgba(32, 201, 151, 0.12); color: var(--accent2); }
        .stat-card:nth-child(3) .stat-icon { background: rgba(99, 230, 190, 0.12); color: var(--accent3); }
        .stat-card:nth-child(4) .stat-icon { background: rgba(255, 77, 106, 0.12); color: var(--danger); }

        .stat-number {
            font-size: 26px;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text2);
        }

        .table-section {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .settings-section {
            margin-bottom: 28px;
        }

        .settings-form {
            display: grid;
            grid-template-columns: repeat(3, 1fr) auto;
            gap: 16px;
            align-items: end;
            padding: 20px;
        }

        .settings-form .field {
            margin-bottom: 0;
        }

        .settings-submit {
            width: auto;
            height: 46px;
            padding: 0 18px;
            white-space: nowrap;
        }

        .settings-success,
        .settings-alert {
            margin-bottom: 18px;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 12px;
        }

        .settings-success {
            color: #b7f7d8;
            background: rgba(0, 212, 170, .12);
            border: 1px solid rgba(0, 212, 170, .25);
        }

        .settings-alert {
            color: #ffd0d6;
        }

        .table-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--glass-border);
        }

        .table-header h3 {
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        .table-header .count {
            font-size: 11px;
            color: var(--text2);
            background: rgba(255, 255, 255, 0.06);
            padding: 3px 10px;
            border-radius: 20px;
        }

        .table-spacer {
            flex: 1;
        }

        .search-box {
            position: relative;
            width: 260px;
        }

        .search-box input {
            width: 100%;
            height: 38px;
            padding: 0 14px 0 36px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            color: var(--text);
            font: 400 12px var(--font);
            outline: none;
            transition: border-color 0.2s;
            direction: rtl;
        }

        .search-box input:focus {
            border-color: rgba(0, 212, 170, 0.3);
        }

        .search-box input::placeholder {
            color: var(--text3);
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: var(--text3);
        }

        .links-table {
            width: 100%;
            border-collapse: collapse;
        }

        .links-table th {
            padding: 12px 20px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text3);
            text-align: right;
            border-bottom: 1px solid var(--glass-border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .links-table td {
            padding: 14px 20px;
            font-size: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            vertical-align: middle;
        }

        .links-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .links-table tr:last-child td {
            border-bottom: none;
        }

        .code-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            background: rgba(0, 212, 170, 0.08);
            color: var(--accent);
            font: 700 12px 'Arad';
            direction: ltr;
            cursor: pointer;
            transition: background 0.15s;
        }

        .code-badge:hover {
            background: rgba(0, 212, 170, 0.15);
        }

        .code-badge i {
            font-size: 10px;
            opacity: 0.6;
        }

        .url-cell {
            max-width: 280px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text2);
            direction: ltr;
            text-align: right;
        }

        .clicks-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(32, 201, 151, 0.08);
            color: var(--accent2);
            font-weight: 700;
            font-size: 12px;
        }

        .date-cell {
            color: var(--text3);
            font-size: 11px;
            white-space: nowrap;
        }

        .ip-cell {
            color: var(--text3);
            font-size: 10px;
            font-family: 'Arad';
            direction: ltr;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--text3);
            font-size: 13px;
            cursor: pointer;
            display: inline-grid;
            place-items: center;
            transition: color 0.15s, background 0.15s;
        }

        .action-btn:hover {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
        }

        .action-btn.delete:hover {
            background: rgba(255, 77, 106, 0.1);
            color: var(--danger);
        }

        .table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-top: 1px solid var(--glass-border);
            font-size: 11px;
            color: var(--text3);
        }

        .pagination {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .page-btn {
            min-width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--text2);
            font: 600 12px var(--font);
            cursor: pointer;
            display: grid;
            place-items: center;
            transition: background 0.15s, color 0.15s;
        }

        .page-btn:hover {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
        }

        .page-btn.active {
            background: var(--accent);
            color: #fff;
        }

        .page-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 40px;
            color: var(--text3);
            margin-bottom: 16px;
        }

        .empty-state h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 12px;
            color: var(--text3);
        }

        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            pointer-events: none;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .toast.success {
            background: rgba(0, 212, 170, 0.15);
            border: 1px solid rgba(0, 212, 170, 0.25);
            color: var(--accent);
        }

        .toast.error {
            background: rgba(255, 77, 106, 0.15);
            border: 1px solid rgba(255, 77, 106, 0.25);
            color: var(--danger);
        }

        .toast.info {
            background: rgba(99, 230, 190, 0.15);
            border: 1px solid rgba(99, 230, 190, 0.25);
            color: var(--accent3);
        }

        .confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
        }

        .confirm-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }

        .confirm-box {
            width: 100%;
            max-width: 380px;
            background: var(--bg2);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 28px;
            text-align: center;
            transform: scale(0.95);
            transition: transform 0.2s;
        }

        .confirm-overlay.show .confirm-box {
            transform: scale(1);
        }

        .confirm-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: rgba(255, 77, 106, 0.1);
            display: inline-grid;
            place-items: center;
            font-size: 22px;
            color: var(--danger);
            margin-bottom: 16px;
        }

        .confirm-box h4 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .confirm-box p {
            font-size: 12px;
            color: var(--text2);
            margin-bottom: 22px;
            line-height: 1.8;
        }

        .confirm-actions {
            display: flex;
            gap: 10px;
        }

        .confirm-actions button {
            flex: 1;
            height: 42px;
            border: none;
            border-radius: var(--radius-sm);
            font: 700 12px var(--font);
            cursor: pointer;
            transition: transform 0.1s;
        }

        .confirm-actions button:active {
            transform: scale(0.97);
        }

        .confirm-cancel {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text2);
        }

        .confirm-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text);
        }

        .confirm-delete {
            background: var(--danger);
            color: #fff;
        }

        .confirm-delete:hover {
            background: var(--danger-hover);
        }

        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .topbar {
                padding: 12px 16px;
                gap: 10px;
            }
            .topbar-sub {
                display: none;
            }
            .main-content {
                padding: 16px;
            }
            .table-header {
                flex-wrap: wrap;
            }
            .search-box {
                width: 100%;
                order: 10;
            }
            .links-table {
                display: block;
                overflow-x: auto;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat-card {
                padding: 16px;
            }
            .stat-number {
                font-size: 20px;
            }
            .table-footer {
                flex-direction: column;
                gap: 12px;
            }
            .settings-form {
                grid-template-columns: 1fr;
            }
            .settings-submit {
                width: 100%;
            }
        }

        @media (max-width: 380px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .login-card {
                padding: 28px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-effects">
        <div class="bg-orb bg-orb-1"></div>
        <div class="bg-orb bg-orb-2"></div>
        <div class="bg-orb bg-orb-3"></div>
        <div class="bg-grid"></div>
    </div>

    <?php if (empty($_SESSION['admin_logged_in'])): ?>
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-logo">L</div>
            <h2>ورود به پنل مدیریت</h2>
            <p>اطلاعات حساب خود را وارد کنید</p>

            <?php if ($error): ?>
                <div class="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="../admin/?action=login">
                <div class="field">
                    <label>نام کاربری</label>
                    <input type="text" name="user" placeholder="admin" value="<?php echo htmlspecialchars($config['admin_user'] ?? 'admin'); ?>" autocomplete="username" required>
                </div>
                <div class="field">
                    <label>رمز عبور</label>
                    <input type="password" name="pass" placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password" required>
                </div>
                <button type="submit" class="login-btn">
                    <i class="fa-regular fa-arrow-right-to-bracket"></i> ورود
                </button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <div class="admin-layout">
        <div class="topbar">
            <div class="topbar-logo">L</div>
            <div>
                <div class="topbar-title">پنل مدیریت</div>
                <div class="topbar-sub"><?php echo htmlspecialchars($config['site_name'] ?? ''); ?></div>
            </div>
            <div class="topbar-spacer"></div>
            <a href="../" class="topbar-link"><i class="fa-regular fa-arrow-left"></i> مشاهده سایت</a>
            <a href="../admin/?action=logout" class="topbar-link danger"><i class="fa-regular fa-right-from-bracket"></i> خروج</a>
        </div>

        <div class="main-content">
            <?php if ($settingsError): ?>
                <div class="alert settings-alert"><?php echo htmlspecialchars($settingsError); ?></div>
            <?php elseif ($settingsSaved): ?>
                <div class="settings-success">تنظیمات با موفقیت ذخیره شد.</div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-regular fa-link"></i></div>
                    <div class="stat-number" id="sLinks"><?php echo number_format($totalLinks); ?></div>
                    <div class="stat-label">کل لینک‌ها</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-regular fa-chart-simple"></i></div>
                    <div class="stat-number" id="sClicks"><?php echo number_format($totalClicks); ?></div>
                    <div class="stat-label">کل کلیک‌ها</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-regular fa-calendar-plus"></i></div>
                    <div class="stat-number" id="sTodayLinks"><?php echo number_format($todayLinks); ?></div>
                    <div class="stat-label">لینک امروز</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-regular fa-fire"></i></div>
                    <div class="stat-number" id="sTodayClicks"><?php echo number_format($todayClicks); ?></div>
                    <div class="stat-label">کلیک امروز</div>
                </div>
            </div>

            <div class="table-section settings-section">
                <div class="table-header">
                    <h3><i class="fa-regular fa-gear"></i> تنظیمات سایت</h3>
                </div>
                <form method="POST" action="../admin/?action=save_settings" class="settings-form">
                    <div class="field">
                        <label for="settingsSiteName">نام سایت</label>
                        <input id="settingsSiteName" type="text" name="site_name" value="<?php echo htmlspecialchars($config['site_name'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="settingsAdminUser">نام کاربری مدیر</label>
                        <input id="settingsAdminUser" type="text" name="admin_user" value="<?php echo htmlspecialchars($config['admin_user'] ?? 'admin'); ?>" autocomplete="username" required>
                    </div>
                    <div class="field">
                        <label for="settingsAdminPass">رمز عبور جدید</label>
                        <input id="settingsAdminPass" type="password" name="admin_pass" placeholder="برای حفظ رمز فعلی خالی بگذارید" autocomplete="new-password" minlength="6">
                    </div>
                    <button type="submit" class="login-btn settings-submit"><i class="fa-regular fa-floppy-disk"></i> ذخیره تنظیمات</button>
                </form>
            </div>

            <div class="table-section">
                <div class="table-header">
                    <h3><i class="fa-regular fa-list"></i> لینک‌ها</h3>
                    <span class="count" id="totalCount"><?php echo $totalLinks; ?> لینک</span>
                    <div class="table-spacer"></div>
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="جستجو در کد یا آدرس...">
                        <i class="fa-regular fa-magnifying-glass"></i>
                    </div>
                </div>

                <div id="tableBody">
                    <div class="empty-state" style="padding:40px">
                        <i class="fa-regular fa-spinner fa-spin"></i>
                        <p>در حال بارگذاری...</p>
                    </div>
                </div>

                <div class="table-footer">
                    <span id="pageInfo"></span>
                    <div class="pagination" id="pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="confirm-overlay" id="confirmOverlay">
        <div class="confirm-box">
            <div class="confirm-icon"><i class="fa-regular fa-trash"></i></div>
            <h4>حذف لینک</h4>
            <p>آیا از حذف این لینک اطمینان دارید؟<br>این عمل غیرقابل بازگشت است.</p>
            <div class="confirm-actions">
                <button class="confirm-cancel" onclick="closeConfirm()">انصراف</button>
                <button class="confirm-delete" id="confirmDeleteBtn">حذف</button>
            </div>
        </div>
    </div>

    <script>
        var currentPage = 1;
        var searchQuery = '';
        var searchTimeout = null;
        var pendingDeleteCode = '';

        var searchInput = document.getElementById('searchInput');
        var tableBody = document.getElementById('tableBody');
        var pageInfo = document.getElementById('pageInfo');
        var pagination = document.getElementById('pagination');
        var confirmOverlay = document.getElementById('confirmOverlay');
        var confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                searchQuery = searchInput.value.trim();
                currentPage = 1;
                loadData();
            }, 300);
        });

        function loadData() {
            var url = '../admin/?action=data&format=json&page=' + currentPage;
            if (searchQuery) url += '&q=' + encodeURIComponent(searchQuery);

            fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                document.getElementById('sLinks').textContent = formatNum(data.stats.total_links);
                document.getElementById('sClicks').textContent = formatNum(data.stats.total_clicks);
                document.getElementById('sTodayLinks').textContent = formatNum(data.stats.today_links);
                document.getElementById('sTodayClicks').textContent = formatNum(data.stats.today_clicks);
                document.getElementById('totalCount').textContent = data.total + ' لینک';

                if (data.links.length === 0) {
                    tableBody.innerHTML = '<div class="empty-state">' +
                        '<i class="fa-regular fa-link-slash"></i>' +
                        '<h4>لینکی یافت نشد</h4>' +
                        '<p>' + (searchQuery ? 'نتیجه‌ای برای جستجوی شما پیدا نشد' : 'هنوز لینکی کوتاه نشده است') + '</p>' +
                        '</div>';
                } else {
                    var html = '<table class="links-table"><thead><tr>' +
                        '<th>کد</th><th>آدرس اصلی</th><th>کلیک</th><th>تاریخ</th><th>IP</th><th></th>' +
                        '</tr></thead><tbody>';

                    for (var i = 0; i < data.links.length; i++) {
                        var l = data.links[i];
                        var d = new Date(l.created_at);
                        var dateStr = d.getFullYear() + '/' + pad(d.getMonth()+1) + '/' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                        html += '<tr>' +
                            '<td><span class="code-badge" onclick="copyCode(\'' + l.code + '\')" title="کپی کد"><i class="fa-regular fa-copy"></i> ' + l.code + '</span></td>' +
                            '<td class="url-cell" title="' + escapeHtml(l.original_url) + '">' + escapeHtml(l.original_url) + '</td>' +
                            '<td><span class="clicks-badge"><i class="fa-regular fa-arrow-pointer"></i> ' + formatNum(l.clicks) + '</span></td>' +
                            '<td class="date-cell">' + dateStr + '</td>' +
                            '<td class="ip-cell">' + escapeHtml(l.ip_address) + '</td>' +
                            '<td>' +
                                '<button class="action-btn" onclick="copyFull(\'' + l.code + '\')" title="کپی لینک کامل"><i class="fa-regular fa-arrow-up-right-from-square"></i></button>' +
                                '<button class="action-btn delete" onclick="askDelete(\'' + l.code + '\')" title="حذف"><i class="fa-regular fa-trash"></i></button>' +
                            '</td>' +
                            '</tr>';
                    }
                    html += '</tbody></table>';
                    tableBody.innerHTML = html;
                }

                pageInfo.textContent = 'صفحه ' + data.page + ' از ' + data.pages;
                renderPagination(data.page, data.pages);
            });
        }

        function renderPagination(current, total) {
            var html = '';
            html += '<button class="page-btn" ' + (current <= 1 ? 'disabled' : '') + ' onclick="goPage(' + (current-1) + ')"><i class="fa-regular fa-chevron-right"></i></button>';

            var start = Math.max(1, current - 2);
            var end = Math.min(total, current + 2);
            for (var i = start; i <= end; i++) {
                html += '<button class="page-btn ' + (i === current ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
            }

            html += '<button class="page-btn" ' + (current >= total ? 'disabled' : '') + ' onclick="goPage(' + (current+1) + ')"><i class="fa-regular fa-chevron-left"></i></button>';
            pagination.innerHTML = html;
        }

        function goPage(p) {
            currentPage = p;
            loadData();
        }

        function copyCode(code) {
            var siteUrl = '<?php echo addslashes(rtrim($config["site_url"] ?? "", "/")); ?>';
            var full = siteUrl + '/' + code;
            copyToClipboard(full);
            toast('لینک کپی شد', 'success');
        }

        function copyFull(code) {
            copyCode(code);
        }

        function askDelete(code) {
            pendingDeleteCode = code;
            confirmOverlay.classList.add('show');
        }

        function closeConfirm() {
            confirmOverlay.classList.remove('show');
            pendingDeleteCode = '';
        }

        confirmDeleteBtn.addEventListener('click', function() {
            if (!pendingDeleteCode) return;
            var fd = new FormData();
            fd.append('code', pendingDeleteCode);
            fetch('../admin/?action=delete', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                closeConfirm();
                if (data.ok) {
                    toast('لینک حذف شد', 'success');
                    loadData();
                } else {
                    toast('خطا در حذف لینک', 'error');
                }
            });
        });

        confirmOverlay.addEventListener('click', function(e) {
            if (e.target === confirmOverlay) closeConfirm();
        });

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;left:-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        }

        function toast(msg, type) {
            var el = document.createElement('div');
            el.className = 'toast ' + type;
            el.textContent = msg;
            document.body.appendChild(el);
            requestAnimationFrame(function() {
                el.classList.add('show');
            });
            setTimeout(function() {
                el.classList.remove('show');
                setTimeout(function() { el.remove(); }, 300);
            }, 2500);
        }

        function formatNum(n) {
            return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function pad(n) {
            return n < 10 ? '0' + n : String(n);
        }

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        loadData();
    </script>
    <?php endif; ?>
</body>
</html>
