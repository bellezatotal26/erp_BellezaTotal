<?php

namespace SoftNova\Core;

/**
 * Clase base para todos los controladores
 */

abstract class Controller
{
    protected Request $request;
    protected Response $response;
    protected Session $session;
    protected View $view;
    
    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->view = new View();
    }
    
    /**
     * Renderizar una vista
     */
    protected function view(string $view, array $data = []): void
    {
        $this->view->render($view, $data);
    }
    
    /**
     * Redireccionar a una URL
     */
    protected function redirect(string $url): void
    {
        $this->response->redirect($url);
    }
    
    /**
     * Retornar respuesta JSON
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        $this->response->json($data, $statusCode);
    }
    
    /**
     * Verificar si el usuario está autenticado
     */
    protected function isAuthenticated(): bool
    {
        return $this->session->has('user_id');
    }
    
    /**
     * Verificar si la peticion espera una respuesta JSON
     */
    protected function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        
        return str_contains($accept, 'application/json') || $requestedWith === 'XMLHttpRequest';
    }
    
    /**
     * Validar token CSRF (POST, header X-CSRF-TOKEN o _token)
     */
    protected function validateCsrf(): bool
    {
        $token = (string)(
            $_POST['csrf_token']
            ?? $_POST['_token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
        );
        return Security::verifyCsrfToken($token);
    }
    
    /**
     * Obtener usuario autenticado
     */
    protected function authUser(): ?array
    {
        return $this->session->get('user');
    }
    
    /**
     * Responde con JSON en peticiones AJAX o redirige en peticiones normales
     *
     * @param array<string, mixed> $extra Campos adicionales en JSON (ej. pdf_url)
     */
    protected function respond(bool $success, string $message, string $redirect = '', array $extra = []): void
    {
        if ($this->wantsJson()) {
            $payload = array_merge([
                'success' => $success,
                'message' => $message,
                'redirect' => !empty($redirect) ? base_url($redirect) : '',
            ], $extra);
            $this->json($payload);
            return;
        }
        
        $_SESSION[$success ? 'success' : 'error'] = $message;
        
        if (!empty($redirect)) {
            redirect($redirect);
        }
    }
    
    /**
     * Valida el token CSRF o responde con error
     */
    protected function validateCsrfOrFail(string $redirect): bool
    {
        if (!$this->validateCsrf()) {
            $this->respond(false, 'Token de seguridad invalido o expirado. Reintente; el token se renovó automáticamente.', $redirect, [
                'code' => 'csrf',
                'csrf_token' => Security::generateCsrfToken(),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Mensaje seguro para el cliente: en prod oculta SQL/paths; RuntimeException de negocio se permiten.
     */
    protected function clientErrorMessage(\Throwable $e, string $fallback = 'No se pudo completar la operación'): string
    {
        if ((bool)config('app.debug', false)) {
            $dbg = trim($e->getMessage());
            return $dbg !== '' ? $dbg : $fallback;
        }

        if ($e instanceof \PDOException) {
            return $fallback;
        }

        $msg = trim($e->getMessage());
        if ($msg === '') {
            return $fallback;
        }
        if (preg_match('/SQLSTATE|Unknown column|Table [\'"`].*[\'"`]| synta|\/home\/|\\\\xampp|on line \d|Stack trace|PDOException/i', $msg)) {
            return $fallback;
        }
        if (mb_strlen($msg) > 240) {
            return $fallback;
        }

        return $msg;
    }
}
