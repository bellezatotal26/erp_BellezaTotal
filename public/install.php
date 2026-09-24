<?php

$lock = dirname(__DIR__) . '/storage/installed.lock';
$installer = dirname(__DIR__) . '/install.php';

// Si el sistema ya está instalado, no exponer el instalador.
if (is_file($lock)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found\n");
}

if (!is_file($installer)) {
    http_response_code(404);
    exit('Instalador eliminado.');
}
require $installer;
