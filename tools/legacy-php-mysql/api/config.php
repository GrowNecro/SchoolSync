<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    $config = current_config();
    unset($config['updated_at']);
    echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Konfigurasi SchoolSync belum tersedia.']);
}
