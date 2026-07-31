<?php

/**
 * @deprecated Use Contabilidad → Integraciones (credenciales por tenant).
 * Conservado solo por compatibilidad de config().
 */

return [
    'alegra' => [
        'enabled' => false,
        'base_url' => 'https://api.alegra.com/api/v1',
        'email' => '',
        'token' => '',
        'timeout' => 30,
        'maps' => [],
        'sync' => [
            'sales' => false,
            'payments' => false,
            'expenses' => false,
            'contacts' => false,
            'items' => false,
        ],
    ],
];
