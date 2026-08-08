<?php
/**
 * Sirve el Service Worker con alcance ampliado (/).
 * Necesario cuando el DocumentRoot no es public/ o la app vive en un subdirectorio.
 */
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$path = __DIR__ . '/sw.js';
if (!is_readable($path)) {
    http_response_code(404);
    echo '/* sw.js missing */';
    exit;
}

readfile($path);
