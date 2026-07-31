<?php

namespace SoftNova\Controllers;

use SoftNova\Core\Controller;
use SoftNova\Core\Database;
use SoftNova\Services\AuditService;
use function SoftNova\Core\redirect;

/**
 * Controlador de autenticación
 */
class AuthController extends Controller
{
    private Database $db;
    
    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance();
    }
    
    public function login(): void
    {
        if ($this->isAuthenticated()) {
            redirect('/superadmin');
            return;
        }
        
        $this->view('auth.login');
    }
    
    public function authenticate(): void
    {
        // Rate limiting: máximo 5 intentos por IP en 15 minutos
        if (!$this->checkRateLimit()) {
            $_SESSION['error'] = 'Demasiados intentos. Intente de nuevo en 15 minutos.';
            redirect('/login');
            return;
        }
        
        if (!$this->validateCsrf()) {
            $_SESSION['error'] = 'Token de seguridad invalido o expirado';
            redirect('/login');
            return;
        }
        
        $email = $this->request->post('email');
        $password = $this->request->post('password');
        
        if (empty($email) || empty($password)) {
            $_SESSION['error'] = 'Por favor ingrese email y contraseña';
            redirect('/login');
            return;
        }
        
        $stmt = $this->db->query(
            "SELECT * FROM super_admin_users WHERE email = ? AND status = 'active' LIMIT 1",
            [$email]
        );
        
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Limpiar rate limit en login exitoso
            $this->clearRateLimit();
            
            $_SESSION['super_admin_id'] = $user['id'];
            $_SESSION['super_admin_name'] = $user['name'];
            $_SESSION['super_admin_email'] = $user['email'];
            $_SESSION['authenticated'] = true;
            $_SESSION['user_type'] = 'super_admin';
            
            // Regenerar ID de sesión para prevenir session fixation
            session_regenerate_id(true);
            
            AuditService::log(
                'login',
                'auth',
                "Inicio de sesion exitoso: {$user['email']}"
            );
            
            redirect('/superadmin');
        } else {
            $this->incrementRateLimit();
            $_SESSION['error'] = 'Credenciales incorrectas';
            redirect('/login');
        }
    }
    
    public function logout(): void
    {
        AuditService::log(
            'logout',
            'auth',
            'Cierre de sesion'
        );
        
        session_destroy();
        redirect('/login');
    }
    
    /**
     * Verifica rate limiting por IP
     */
    private function checkRateLimit(): bool
    {
        $ip = $this->request->ip();
        $key = 'rate_limit_' . md5($ip);
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];
        
        // Resetear si pasaron 15 minutos
        if (time() - $attempts['first_attempt'] > 900) {
            unset($_SESSION[$key]);
            return true;
        }
        
        return $attempts['count'] < 5;
    }
    
    /**
     * Incrementa el contador de rate limiting
     */
    private function incrementRateLimit(): void
    {
        $ip = $this->request->ip();
        $key = 'rate_limit_' . md5($ip);
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];
        $attempts['count']++;
        $_SESSION[$key] = $attempts;
    }
    
    /**
     * Limpia el rate limit después de login exitoso
     */
    private function clearRateLimit(): void
    {
        $ip = $this->request->ip();
        $key = 'rate_limit_' . md5($ip);
        unset($_SESSION[$key]);
    }
    
    protected function isAuthenticated(): bool
    {
        return isset($_SESSION['authenticated']) &&
               $_SESSION['authenticated'] === true &&
               $_SESSION['user_type'] === 'super_admin';
    }
}
