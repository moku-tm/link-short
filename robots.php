<?php

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $scheme = 'https';
}
$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = $host !== '' ? $scheme . '://' . $host : rtrim($config['site_url'] ?? '', '/');
$baseUrl = rtrim($baseUrl, '/');

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Disallow: /app/\n";
echo "Disallow: /installer/\n";
echo "Disallow: /uploads/\n";
echo "Disallow: /tmp/\n";
echo "Disallow: /api\n";
echo "Allow: /\n";
echo "Sitemap: " . $baseUrl . "/sitemap.xml\n";
