<?php
// Evita listado de directorio si el servidor ignora .htaccess.
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo 'Forbidden';
