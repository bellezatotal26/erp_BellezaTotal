<?php

/**
 * Plantilla de conexión. Copiar a database.php y completar credenciales.
 * NO subir database.php con contraseñas reales al repositorio.
 */
return [
    'database' => [
        'default' => [
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'nombre_bd',
            'username' => 'usuario_bd',
            'password' => 'cambiar_password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
    ],
];
