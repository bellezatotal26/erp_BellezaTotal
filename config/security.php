<?php

/**
 * Configuración de seguridad
 */

return [
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_number' => true,
        'require_special' => true,
    ],
    
    'session_security' => [
        'regenerate_id' => true,
        'regenerate_interval' => 300,
    ],
    
    'rate_limiting' => [
        'enabled' => true,
        'max_requests' => 100,
        'per_minutes' => 60,
    ],
    
    'csrf' => [
        'enabled' => true,
        'token_lifetime' => 3600,
    ],
    
    'headers' => [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';",
    ],
];
