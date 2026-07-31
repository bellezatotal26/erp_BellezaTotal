<?php

/**
 * Ejemplo de config/app.php para producción (seri.heraconsultores.com).
 * Copia a config/app.php en el servidor y ajusta. NO subir secretos a Git.
 */

return [
    'app' => [
        'name' => 'Seri ERP',
        'version' => '1.0.0',
        'env' => 'production',
        'debug' => false,
        'url' => 'https://seri.heraconsultores.com',
        'timezone' => 'America/Bogota',
    ],

    'session' => [
        'name' => 'SOFTNOVA_SESSION',
        'lifetime' => 7200,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'security' => [
        'csrf_token_name' => 'csrf_token',
        'csrf_header_name' => 'X-CSRF-TOKEN',
        'password_algorithm' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
        'password_options' => [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3,
        ],
    ],
];
