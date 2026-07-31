<?php

$installer = dirname(__DIR__) . '/install.php';
if (!is_file($installer)) {
    http_response_code(404);
    exit('Instalador eliminado.');
}
require $installer;
