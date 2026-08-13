<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app.php';

$template = dirname(__DIR__) . '/tools/Install.bat';
if (!is_file($template)) {
    http_response_code(503);
    exit('Template installer belum tersedia.');
}

$panelUrl = base_url();
$content = str_replace('__SCHOOLSYNC_PANEL_URL__', $panelUrl, (string) file_get_contents($template));

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="Install-SchoolSync.bat"');
header('Content-Length: ' . (string) strlen($content));
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $content;
