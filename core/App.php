<?php

namespace SoftNova\Core;

/**
 * Clase principal de la aplicación
 * Maneja el arranque y routing
 */

class App
{
    private Router $router;
    private Request $request;
    
    public function __construct()
    {
        $this->request = new Request();
        $this->router = new Router($this->request);
        
        // Generar token CSRF para la sesion
        Security::generateCsrfToken();
        
        // Configurar timezone
        date_default_timezone_set(config('app.timezone', 'UTC'));
        
        // Configurar error reporting
        if (config('app.debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
    }
    
    public function run(): void
    {
        $this->setSecurityHeaders();
        
        try {
            $this->router->dispatch();
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Establece headers de seguridad HTTP
     */
    private function setSecurityHeaders(): void
    {
        $headers = config('security.headers', []);
        
        foreach ($headers as $name => $value) {
            if (!empty($name) && !empty($value)) {
                header("{$name}: {$value}");
            }
        }
    }
    
    private function handleException(\Exception $e): void
    {
        error_log($e->getMessage());
        
        if (config('app.debug', false)) {
            echo '<h1>Error</h1>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            http_response_code(500);
            echo 'Error interno del servidor';
        }
    }
}
