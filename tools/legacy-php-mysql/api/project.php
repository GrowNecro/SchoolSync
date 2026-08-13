<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app.php';

try {
    $name = safe_project_name((string) ($_GET['file'] ?? ''));
    $path = SCHOOLSYNC_UPLOADS . '/' . $name;
    if (!project_exists($name) || !is_file($path)) {
        http_response_code(404);
        exit('Proyek tidak ditemukan.');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $error) {
    http_response_code(400);
    exit('Permintaan tidak valid.');
}
