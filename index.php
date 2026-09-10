<?php

session_start();

if (!file_exists(__DIR__ . '/config.php') && is_dir(__DIR__ . '/installer') && !preg_match('#installer#', $_SERVER['REQUEST_URI'] ?? '')) {
    header('Location: installer/');
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo 'فایل پیکربندی یافت نشد.';
    exit;
}

$config = require $configFile;
if (empty($config['installed'])) {
    header('Location: installer/');
    exit;
}

require_once __DIR__ . '/app/Controllers/LinkController.php';

function getClientIP()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function generateCSRFToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function checkRateLimit($ip, $max = 30, $window = 30)
{
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0755);

    $file = $dir . '/rl.json';
    $lock = $dir . '/rl.lock';
    $now = time();

    $fp = fopen($lock, 'r');
    if (!$fp) $fp = fopen($lock, 'w');
    flock($fp, LOCK_EX);

    $data = [];
    if (file_exists($file) && filesize($file) > 0) {
        $raw = file_get_contents($file);
        $data = json_decode($raw, true) ?: [];
    }

    $key = md5($ip);
    if (!isset($data[$key]) || !is_array($data[$key])) {
        $data[$key] = [];
    }

    $data[$key] = array_values(array_filter($data[$key], fn($t) => $t > $now - $window));

    if (count($data[$key]) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $data[$key][] = $now;

    if (count($data) > 500) {
        $cutoff = $now - $window * 3;
        foreach ($data as $k => $v) {
            $data[$k] = array_values(array_filter($v, fn($t) => $t > $cutoff));
            if (empty($data[$k])) unset($data[$k]);
        }
    }

    @file_put_contents($file, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

$route = $_GET['route'] ?? '';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if ($route === '' && preg_match('#/api/?$#', $requestPath)) {
    $route = 'api';
}
$ip = getClientIP();

if ($route === 'api') {
    header('Content-Type: application/json; charset=utf-8');

    if (!checkRateLimit('api_' . $ip, 30, 30)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'درخواست بیش از حد مجاز است. لطفاً چند لحظه صبر کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $code = $_GET['short'] ?? '';
    if (strlen($code) !== 7 || !preg_match('/^[A-Za-z0-9]{7}$/', $code)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'کد لینک نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $controller = new LinkController();
    $controller->handleApi($code);
    exit;
}

if ($route === 'shorten' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!checkRateLimit($ip, 30, 30)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'درخواست بیش از حد مجاز است. لطفاً چند لحظه صبر کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $url = $input['url'] ?? '';
    $token = $input['csrf_token'] ?? '';

    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'توکن امنیتی نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $controller = new LinkController();
    $result = $controller->shorten($url, $ip);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($route === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $controller = new LinkController();
    echo json_encode($controller->getStats(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($route !== '' && $route !== 'shorten' && $route !== 'stats') {
    if (preg_match('/^[A-Za-z0-9]{7}$/', $route)) {
        $controller = new LinkController();
        $controller->redirect($route);
        exit;
    }
}

$csrfToken = generateCSRFToken();
$siteNameText = $config['site_name'] ?? 'کوتاه‌کننده لینک';
$siteName = htmlspecialchars($siteNameText, ENT_QUOTES, 'UTF-8');
$metaDescription = 'کوتاه‌کننده لینک سریع، ساده و رایگان برای تبدیل لینک‌های طولانی به لینک کوتاه و آماده اشتراک‌گذاری.';
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$forwardedScheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
if ($forwardedScheme === 'https') {
    $requestScheme = 'https';
}
$requestHost = $_SERVER['HTTP_HOST'] ?? '';
$siteUrl = $requestHost !== ''
    ? htmlspecialchars($requestScheme . '://' . $requestHost, ENT_QUOTES, 'UTF-8')
    : htmlspecialchars(rtrim($config['site_url'] ?? '', '/'), ENT_QUOTES, 'UTF-8');
$canonicalUrl = $siteUrl . '/';
$structuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'WebApplication',
    'name' => $siteNameText,
    'url' => $canonicalUrl,
    'description' => $metaDescription,
    'applicationCategory' => 'UtilitiesApplication',
    'operatingSystem' => 'All',
    'offers' => [
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'USD',
    ],
];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="csrf-token" content="<?php echo $csrfToken; ?>">
    <meta name="site-url" content="<?php echo $siteUrl; ?>">
    <meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="کوتاه کننده لینک, کوتاه کننده URL, لینک کوتاه, لینک کوتاه رایگان">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="author" content="<?php echo $siteName; ?>">
    <meta name="theme-color" content="#1b5e20">
    <link rel="canonical" href="<?php echo $canonicalUrl; ?>">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $siteName; ?> - کوتاه‌کننده لینک">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo $canonicalUrl; ?>">
    <meta property="og:site_name" content="<?php echo $siteName; ?>">
    <meta property="og:image" content="<?php echo $siteUrl; ?>/assets/img/logo.webp">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo $siteName; ?> - کوتاه‌کننده لینک">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?php echo $siteUrl; ?>/assets/img/logo.webp">
    <title><?php echo $siteName; ?> - کوتاه‌کننده لینک</title>
    <script type="application/ld+json"><?php echo json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <link rel="icon" href="assets/img/logo.webp" type="image/webp">
    <link rel="stylesheet" href="https://cdn.moku.ir/icon/FontAwesome.Pro.7.3.1/Web/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/dark.css">
</head>
<body>
    <script>
        (function () {
            var savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark');
            }
            document.body.classList.add('theme-ready');
        }());
    </script>
    <section class="page" dir="rtl">
        <div class="glow g1"></div>
        <div class="glow g2"></div>

        <div class="brand">
            <img class="brand-logo" src="assets/img/logo.webp" alt="لوگوی <?php echo $siteName; ?>">
            <span><?php echo $siteName; ?></span>
        </div>

        <nav>
            <a href="#" class="active">کوتاه‌کننده لینک</a>
        </nav>

        <div class="actions">
            <span class="action theme-toggle" id="themeToggle" title="تغییر تم">
                <i class="fa-regular fa-moon" id="themeIcon"></i>
            </span>
        </div>

        <div class="content">
            <div class="three-cols">

                <div class="col col-right">
                    <div class="copy-block">
                        <div class="eyebrow">سریع · ساده · حرفه‌ای</div>
                        <h1>لینکت رو<br>کوتاه کن</h1>
                        <p>لینک‌های طولانی و پیچیده را در چند ثانیه به یک لینک کوتاه و مرتب تبدیل کن. سریع، ساده و آماده برای اشتراک‌گذاری.</p>
                        <div class="url-area">
                            <div class="hint">لینک خود را وارد کنید و روی «کوتاه کن» بزنید.</div>
                        </div>
                    </div>
                </div>

                <div class="col col-center">
                    <div class="center-stack">
                        <div class="center-input-wrapper">
                            <input id="urlInput" class="center-input" type="url" dir="ltr" placeholder="https://example.com/your-long-link" autocomplete="off" spellcheck="false">
                            <button id="executeBtn" class="execute-btn" type="button" title="کوتاه کن">
                                <i class="fa-regular fa-arrow-right"></i>
                            </button>
                        </div>

                        <div id="messageArea" class="message-area hidden"></div>

                        <div class="card" id="card">
                            <div class="card-glow"></div>
                            <div class="card-top">
                                <span class="tiny-logo">L</span>
                                <span id="cardTitle">لینک کوتاه شما</span>
                                <span class="verified"><i class="fa-regular fa-check"></i></span>
                            </div>
                            <div class="card-link" id="previewLink"><?php echo $siteUrl; ?></div>
                            <div class="card-line"></div>
                            <div class="card-bottom">
                                <span id="cardStatus">آماده اشتراک‌گذاری</span>
                                <button id="copyBtn" type="button"><i class="fa-regular fa-copy"></i> کپی لینک</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col col-left">
                    <div class="stats-block" id="statsBlock">
                        <div class="stats-top-row">
                            <div class="stat-item">
                                <div class="stat-number" id="statPercent">100٪</div>
                                <div class="stat-label">ساده و سریع</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">24/7</div>
                                <div class="stat-label">در دسترس</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">رایگان</div>
                                <div class="stat-label">بدون هزینه</div>
                            </div>
                        </div>
                        <div class="stat-row">
                            <span>تعداد کلیک</span>
                            <b id="statClicks">---</b>
                        </div>
                        <div class="stat-row">
                            <span>وضعیت</span>
                            <b class="green">فعال</b>
                        </div>
                        <div class="stat-row">
                            <span>لینک‌ها</span>
                            <b id="statLinks">---</b>
                        </div>
                    </div>
                    <div class="mini-row" id="exportRow" aria-label="خروجی تصویری کارت">
                        <button class="mini-card" type="button" data-format="webp" title="دانلود خروجی WEBP">
                            <span><i class="fa-regular fa-image"></i></span>
                            <small>خروجی WEBP</small>
                        </button>
                        <button class="mini-card" type="button" data-format="png" title="دانلود خروجی PNG">
                            <span><i class="fa-regular fa-image"></i></span>
                            <small>خروجی PNG</small>
                        </button>
                        <button class="mini-card" type="button" data-format="jpeg" title="دانلود خروجی JPG">
                            <span><i class="fa-regular fa-image"></i></span>
                            <small>خروجی JPG</small>
                        </button>
                    </div>
                </div>

            </div>
        </div>

    </section>

    <script src="assets/js/main.js"></script>
</body>
</html>
