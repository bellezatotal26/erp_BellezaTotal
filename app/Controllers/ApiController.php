<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;

/**
 * API v1 para integraciones externas
 */
class ApiController extends Controller
{
    /**
     * Health check
     */
    public function ping(): void
    {
        $this->json([
            'app' => config('app.name', 'Seri ERP'),
            'version' => config('app.version', '1.0.0'),
            'timestamp' => date('c'),
            'status' => 'ok',
        ]);
    }
}
