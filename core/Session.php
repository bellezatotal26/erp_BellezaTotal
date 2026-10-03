<?php

namespace SoftNova\Core;

/**
 * Manejar sesiones de forma segura
 */

class Session
{
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Obtener valor de sesión
     */
    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Establecer valor en sesión
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Verificar si existe clave en sesión
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Eliminar valor de sesión
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }
    
    /**
     * Obtener y eliminar valor (flash)
     */
    public function flash(string $key, $default = null)
    {
        if (isset($_SESSION[$key])) {
            $value = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $value;
        }
        return $default;
    }
    
    /**
     * Regenerar ID de sesión (seguridad)
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Rota el ID sin perder peticiones concurrentes: la sesión vieja queda marcada
     * y apunta a la nueva durante un periodo de gracia (ver followRotation()).
     */
    public static function rotateId(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        $newId = session_create_id();
        if (!is_string($newId) || $newId === '') {
            return;
        }
        $data = $_SESSION;
        $_SESSION['_seri_obsolete_at'] = time();
        $_SESSION['_seri_new_sid'] = $newId;
        session_write_close();

        self::startWithId($newId);
        unset($data['_seri_obsolete_at'], $data['_seri_new_sid']);
        $_SESSION = $data;
        $_SESSION['_seri_last_regen'] = time();
    }

    /**
     * Si la petición llegó con un ID ya rotado, continúa en la sesión nueva
     * (dentro de la gracia) o descarta el ID viejo si expiró.
     */
    public static function followRotation(int $graceSeconds): void
    {
        for ($hop = 0; $hop < 3; $hop++) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                return;
            }
            $obsoleteAt = (int)($_SESSION['_seri_obsolete_at'] ?? 0);
            if ($obsoleteAt <= 0) {
                return;
            }
            $newId = (string)($_SESSION['_seri_new_sid'] ?? '');
            if ($newId === '' || (time() - $obsoleteAt) > $graceSeconds || headers_sent()) {
                $_SESSION = [];
                if (!headers_sent()) {
                    @session_regenerate_id(true);
                }
                return;
            }
            session_write_close();
            self::startWithId($newId);
        }
    }

    /** El ID nuevo aún puede no existir en disco; strict mode lo rechazaría. */
    private static function startWithId(string $id): void
    {
        ini_set('session.use_strict_mode', '0');
        session_id($id);
        session_start();
    }
    
    /**
     * Destruir sesión
     */
    public function destroy(): void
    {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }
    
    /**
     * Establecer mensaje flash
     */
    public function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }
    
    /**
     * Obtener mensaje flash
     */
    public function getFlash(string $type): ?string
    {
        $message = $_SESSION['flash'][$type] ?? null;
        unset($_SESSION['flash'][$type]);
        return $message;
    }
}
