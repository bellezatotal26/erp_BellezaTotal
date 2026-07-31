<?php

namespace SoftNova\Core;

/**
 * Middleware de autenticación
 */
class Middleware
{
    /**
     * Verificar si el usuario está autenticado como Super Admin
     */
    public static function auth(): bool
    {
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            redirect('/login');
            return false;
        }
        
        if ($_SESSION['user_type'] !== 'super_admin') {
            http_response_code(403);
            echo 'Acceso denegado';
            exit;
        }
        
        return true;
    }
    
    /**
     * Verificar si el usuario está autenticado como tenant
     */
    public static function tenantAuth(): bool
    {
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            redirect('/login');
            return false;
        }
        
        if ($_SESSION['user_type'] !== 'tenant') {
            http_response_code(403);
            echo 'Acceso denegado';
            exit;
        }
        
        return true;
    }
    
    /**
     * Verificar si el usuario tiene un módulo específico
     */
    public static function hasModule(string $module): bool
    {
        if ($_SESSION['user_type'] === 'super_admin') {
            return true;
        }
        
        if ($_SESSION['user_type'] === 'tenant') {
            $modules = $_SESSION['tenant_modules'] ?? [];
            return in_array($module, $modules);
        }
        
        return false;
    }
}
