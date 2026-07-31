<?php

namespace SoftNova\Core;

/**
 * Clase de seguridad
 */

class Security
{
    /**
     * Generar token CSRF
     */
    public static function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verificar token CSRF
     */
    public static function verifyCsrfToken(string $token): bool
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Hashear contraseña con Argon2ID
     */
    public static function hashPassword(string $password): string
    {
        $options = config('security.password_options', [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3,
        ]);
        
        return password_hash($password, PASSWORD_ARGON2ID, $options);
    }
    
    /**
     * Verificar contraseña
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Escapar salida HTML
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitizar entrada
     */
    public static function sanitize(string $value): string
    {
        return strip_tags(trim($value));
    }
    
    /**
     * Generar cadena aleatoria
     */
    public static function randomString(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Clave de aplicación para cifrado (storage/app.key o APP_KEY).
     */
    public static function appKey(): string
    {
        $fromEnv = trim((string)(getenv('APP_KEY') ?: ''));
        if ($fromEnv !== '') {
            return hash('sha256', $fromEnv, true);
        }

        $path = defined('STORAGE_PATH') ? STORAGE_PATH . '/app.key' : (dirname(__DIR__) . '/storage/app.key');
        if (!is_file($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($path, bin2hex(random_bytes(32)));
            @chmod($path, 0600);
        }
        $raw = trim((string)@file_get_contents($path));
        return hash('sha256', $raw !== '' ? $raw : 'softnova-fallback-key', true);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::appKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar el secreto');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::appKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
