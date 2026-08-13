<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app.php';

$allowed = ['SchoolSync.bat', 'SchoolSync.ps1', 'version.txt'];
$name = basename((string) ($_GET['file'] ?? ''));

if (!in_array($name, $allowed, true)) {
    http_response_code(404);
    exit('File aplikasi tidak ditemukan.');
}

$path = dirname(__DIR__) . '/tools/' . $name;
if (!is_file($path)) {
    http_response_code(503);
    exit('File aplikasi belum tersedia di panel.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($path);
