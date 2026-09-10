<?php

require_once __DIR__ . '/../Models/LinkModel.php';

class LinkController
{
    private $model;

    public function __construct()
    {
        $this->model = new LinkModel();
    }

    public function shorten($url, $ip)
    {
        $url = $this->sanitizeUrl($url);
        if (!$url) {
            return ['success' => false, 'message' => 'آدرس وارد شده معتبر نیست'];
        }

        $code = $this->model->generateCode();
        $this->model->create($url, $code, $ip);

        $config = require __DIR__ . '/../../config.php';
        $shortUrl = rtrim($config['site_url'], '/') . '/' . $code;

        return [
            'success' => true,
            'short_url' => $shortUrl,
            'code' => $code,
            'original_url' => $url,
        ];
    }

    public function redirect($code)
    {
        $this->model->deleteExpired();
        $link = $this->model->findByCode($code);

        if (!$link) {
            $this->show404();
            return;
        }

        $this->model->incrementClicks($code);
        header('Location: ' . $link['original_url'], true, 302);
        exit;
    }

    public function handleApi($code)
    {
        $config = require __DIR__ . '/../../config.php';
        if (empty($config['api_enabled'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'API غير فعال است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $this->model->deleteExpired();
        $link = $this->model->findByCode($code);

        if (!$link) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'لینک یافت نشد'], JSON_UNESCAPED_UNICODE);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'short_url' => rtrim($config['site_url'], '/') . '/' . $link['code'],
                'original_url' => $link['original_url'],
                'clicks' => (int)$link['clicks'],
                'created_at' => $link['created_at'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getStats()
    {
        return [
            'total_links' => $this->model->getTotalLinks(),
            'total_clicks' => $this->model->getTotalClicks(),
            'recent' => $this->model->getRecentLinks(5),
        ];
    }

    private function sanitizeUrl($url)
    {
        $url = trim($url);
        if (empty($url)) return false;

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        $blocked = ['localhost', '127.0.0.1', '0.0.0.0'];
        if (in_array($parsed['host'], $blocked)) {
            return false;
        }

        return $url;
    }

    private function show404()
    {
        http_response_code(404);
        $config = require __DIR__ . '/../../config.php';
        $siteName = htmlspecialchars($config['site_name'] ?? 'لینک‌کوتاه');
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>404 - لینک یافت نشد</title>';
        echo '<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/dark.css"></head>';
        echo '<body><div class="error-page"><div class="error-box"><h1>404</h1><p>لینک مورد نظر یافت نشد یا منقضی شده است.</p>';
        echo '<a href="/">بازگشت به صفحه اصلی</a></div></div></body></html>';
        exit;
    }
}
