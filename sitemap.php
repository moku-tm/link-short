<?php

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $scheme = 'https';
}
$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = $host !== '' ? $scheme . '://' . $host : rtrim($config['site_url'] ?? '', '/');
$baseUrl = rtrim($baseUrl, '/');
$homeUrl = htmlspecialchars($baseUrl . '/', ENT_XML1, 'UTF-8');
$lastmod = date('c');

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
echo '<url><loc>' . $homeUrl . '</loc><lastmod>' . $lastmod . '</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>';
echo '</urlset>';
