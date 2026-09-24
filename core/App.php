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
        
        // Timezone de negocio (luego el tenant puede sobrescribir vía settings).
        BusinessTimezone::applyPhp(BusinessTimezone::fromConfig());
        
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
        } catch (\Throwable $e) {
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

        // HSTS solo en producción HTTPS (evita romper entornos locales http).
        $appUrl = (string)config('app.url', '');
        $isHttps = str_starts_with(strtolower($appUrl), 'https://')
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        if ($isHttps && !headers_sent()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    private function handleException(\Throwable $e): void
    {
        error_log('App exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $wantsJson = $isAjax
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

        $debug = (bool)config('app.debug', false);
        $publicMessage = $debug
            ? ('Error: ' . $e->getMessage())
            : 'Error interno del servidor. Si el problema continúa, contacte soporte.';

        if ($wantsJson) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $publicMessage,
                'redirect' => '',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        http_response_code(500);
        if ($debug) {
            echo '<h1>Error</h1>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            echo 'Error interno del servidor';
        }
    }
}
