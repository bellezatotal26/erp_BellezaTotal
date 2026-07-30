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
     * Validar token CSRF
     */
    protected function validateCsrf(): bool
    {
        $token = $_POST['csrf_token'] ?? '';
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
     */
    protected function respond(bool $success, string $message, string $redirect = ''): void
    {
        if ($this->wantsJson()) {
            $this->json([
                'success' => $success,
                'message' => $message,
                'redirect' => !empty($redirect) ? base_url($redirect) : ''
            ]);
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
            $this->respond(false, 'Token de seguridad invalido o expirado', $redirect);
            return false;
        }
        return true;
    }
}
