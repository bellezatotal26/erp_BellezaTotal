<?php

namespace SoftNova\Services;

use SoftNova\Core\Database;

/**
 * Servicio para registrar acciones de auditoria en el sistema
 */
class AuditService
{
    /**
     * Registra una accion en el log de auditoria
     *
     * @param string $action Accion realizada (create, update, delete, login, logout, etc.)
     * @param string $module Modulo afectado (tenants, plans, tenant_users, auth, etc.)
     * @param string|null $description Descripcion detallada de la accion
     * @param int|null $tenant_id ID del tenant relacionado
     * @param int|null $user_id ID del usuario relacionado
     * @return void
     */
    public static function log(
        string $action,
        string $module,
        ?string $description = null,
        ?int $tenant_id = null,
        ?int $user_id = null
    ): void {
        $db = Database::getInstance();
        
        $userName = self::getCurrentUserName();
        $ipAddress = self::getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        if ($user_id === null) {
            $user_id = self::getCurrentUserId();
        }
        
        try {
            $db->query(
                "INSERT INTO audit_logs (tenant_id, user_id, user_name, action, module, description, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$tenant_id, $user_id, $userName, $action, $module, $description, $ipAddress, $userAgent]
            );
        } catch (\Exception $e) {
            error_log('Error al registrar auditoria: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtiene el nombre del usuario actual desde la sesion
     */
    private static function getCurrentUserName(): ?string
    {
        if (!empty($_SESSION['super_admin_name'])) {
            return $_SESSION['super_admin_name'];
        }
        
        if (!empty($_SESSION['user']['name'])) {
            return $_SESSION['user']['name'];
        }
        
        return null;
    }
    
    /**
     * Obtiene el ID del usuario actual desde la sesion
     */
    private static function getCurrentUserId(): ?int
    {
        if (!empty($_SESSION['super_admin_id'])) {
            return (int) $_SESSION['super_admin_id'];
        }
        
        if (!empty($_SESSION['user']['id'])) {
            return (int) $_SESSION['user']['id'];
        }
        
        return null;
    }
    
    /**
     * Obtiene la direccion IP del cliente
     */
    private static function getClientIp(): ?string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return null;
    }
}
