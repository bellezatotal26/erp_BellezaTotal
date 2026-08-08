<?php

namespace SoftNova\Core;

/**
 * Funciones helper globales
 */

/**
 * Obtener valor de configuración
 */
function config(string $key, $default = null)
{
    static $config = null;
    
    if ($config === null) {
        $config = [];
        
        // Cargar archivos de configuración
        $configFiles = [
            CONFIG_PATH . '/app.php',
            CONFIG_PATH . '/database.php',
            CONFIG_PATH . '/security.php',
            CONFIG_PATH . '/ai.php',
            CONFIG_PATH . '/ai_personality.php',
            CONFIG_PATH . '/alegra.php',
            CONFIG_PATH . '/integrations.php',
            CONFIG_PATH . '/catalog.php',
        ];
        
        foreach ($configFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $loaded = require $file;
            // Algunos configs (ej. personalidad IA) pueden devolver un Closure.
            if ($loaded instanceof \Closure) {
                $loaded = $loaded([]);
            }
            if (!is_array($loaded)) {
                continue;
            }
            $config = array_merge($config, $loaded);
        }
    }
    
    $keys = explode('.', $key);
    $value = $config;
    
    foreach ($keys as $k) {
        if (!is_array($value) || !array_key_exists($k, $value)) {
            return $default;
        }
        $value = $value[$k];
    }
    
    return $value;
}

/**
 * Instalación de un solo cliente (sin panel SuperAdmin / multi-tenant SaaS).
 */
function is_standalone(): bool
{
    return (string)config('app.deployment', 'saas') === 'standalone';
}

/**
 * Personalidad de Seri filtrada por módulos activos del plan.
 */
function ai_personality(array $activeModules = []): array
{
    static $factory = null;
    if ($factory === null) {
        $file = CONFIG_PATH . '/ai_personality.php';
        $factory = is_file($file) ? require $file : [];
    }

    if ($factory instanceof \Closure) {
        $resolved = $factory($activeModules);
        return is_array($resolved['ai_personality'] ?? null) ? $resolved['ai_personality'] : [];
    }

    if (is_array($factory)) {
        return is_array($factory['ai_personality'] ?? null) ? $factory['ai_personality'] : $factory;
    }

    return [];
}

/**
 * Genera variables CSS de tema a partir de un color primario hex (#RRGGBB).
 * Ajusta el color de texto sobre el primario para mantener contraste (WCAG).
 * Devuelve un bloque <style> listo para inyectar, o '' si el color es inválido.
 */
function theme_color_style(?string $hex): string
{
    $hex = trim((string)$hex);
    if ($hex === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
        return '';
    }

    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));

    // Luminancia relativa (WCAG) para decidir texto claro u oscuro.
    $toLinear = static function (int $c): float {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $luminance = 0.2126 * $toLinear($r) + 0.7152 * $toLinear($g) + 0.0722 * $toLinear($b);
    $textOnPrimary = $luminance > 0.4 ? '#1A1A2E' : '#FFFFFF';

    // Variante más oscura para hover (mezcla con negro ~22%).
    $darken = static function (int $c): int {
        return max(0, (int)round($c * 0.78));
    };
    $hover = sprintf('#%02X%02X%02X', $darken($r), $darken($g), $darken($b));

    // Fondo sutil (tinte translúcido del primario).
    $tint = sprintf('rgba(%d, %d, %d, 0.06)', $r, $g, $b);

    return "<style id=\"tenant-theme\">:root{"
        . "--color-primary: {$hex};"
        . "--bg-btn-primary: {$hex};"
        . "--bg-btn-primary-hover: {$hover};"
        . "--color-btn-primary-text: {$textOnPrimary};"
        . "--color-datetime-icon: {$hex};"
        . "--color-datetime-time: {$hex};"
        . "--bg-table-hover: {$tint};"
        . "}"
        . "body.dark-mode{"
        . "--color-primary: {$hex};"
        . "--bg-btn-primary: {$hex};"
        . "--bg-btn-primary-hover: {$hover};"
        . "--color-btn-primary-text: {$textOnPrimary};"
        . "}</style>";
}

/**
 * Prefijo web hacia la carpeta public/ cuando el DocumentRoot es la raíz del proyecto
 * (típico en cPanel: dominio → /home/.../dominio.com y no .../dominio.com/public).
 */
function public_web_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }

    $prefix = '';
    if (!defined('PUBLIC_PATH')) {
        return $prefix;
    }

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (realpath((string)$_SERVER['DOCUMENT_ROOT']) ?: '') : '';
    $public = realpath(PUBLIC_PATH) ?: '';
    if ($docRoot !== '' && $public !== '' && str_replace('\\', '/', $docRoot) === str_replace('\\', '/', $public)) {
        // DocumentRoot = public/ → assets en /assets/...
        return $prefix;
    }

    // DocumentRoot = raíz del proyecto → assets en /public/assets/...
    $prefix = '/public';
    return $prefix;
}

/**
 * Obtener URL base
 */
function base_url(string $path = ''): string
{
    $baseUrl = config('app.url', 'http://localhost/SoftNova');
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

/**
 * Obtener URL de ruta (app.url es el dominio; las rutas pasan por rewrite a public/index.php)
 */
function route(string $path): string
{
    return base_url(ltrim($path, '/'));
}

/**
 * Obtener URL de assets (ajusta /public según DocumentRoot)
 */
function asset(string $path): string
{
    return base_url(ltrim(public_web_prefix() . '/assets/' . ltrim($path, '/'), '/'));
}

/**
 * Redireccionar
 */
function redirect(string $url): void
{
    $baseUrl = config('app.url', 'http://localhost/SoftNova/public');
    if (!str_starts_with($url, 'http')) {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
    header("Location: {$url}");
    exit;
}

/**
 * Obtener valor de sesión
 */
function session(string $key, $default = null)
{
    return $_SESSION[$key] ?? $default;
}

/**
 * Establecer valor de sesión
 */
function set_session(string $key, $value): void
{
    $_SESSION[$key] = $value;
}

/**
 * Verificar si hay sesión activa
 */
function has_session(string $key): bool
{
    return isset($_SESSION[$key]);
}

/**
 * Obtener token CSRF
 */
function csrf_token(): string
{
    return Security::generateCsrfToken();
}

/**
 * Generar campo input hidden con token CSRF
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . Security::generateCsrfToken() . '">';
}

require_once __DIR__ . '/catalog_helpers.php';

/**
 * Leer un setting de la BD maestra (system_settings).
 */
function system_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $db = Database::getInstance();
        $row = $db->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1",
            [$key]
        )->fetch();
        $cache[$key] = $row ? (string)$row['setting_value'] : $default;
    } catch (\Throwable $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * Meta tags Open Graph / Twitter Card para miniatura al compartir el URL.
 * Imagen configurable en Superadmin → Configuración.
 */
function og_meta_tags(?string $pageTitle = null): string
{
    $appName = system_setting('app_name', config('app.name', 'Seri ERP'));
    $title = trim((string)($pageTitle ?: system_setting('og_title', $appName)));
    if ($title === '') {
        $title = $appName;
    }
    $description = trim(system_setting(
        'og_description',
        'Sistema de gestión empresarial Seri ERP — ventas, inventario, caja y contabilidad.'
    ));
    $imageRel = trim(system_setting('og_image', ''));
    $siteUrl = rtrim((string)config('app.url', ''), '/');
    $canonical = $siteUrl !== '' ? $siteUrl . '/' : '';

    $imageUrl = '';
    $webPublic = public_web_prefix(); // '' o '/public' según DocumentRoot
    if ($imageRel !== '') {
        if (preg_match('#^https?://#i', $imageRel)) {
            $imageUrl = $imageRel;
        } else {
            $rel = ltrim($imageRel, '/');
            // Si el setting apunta a uploads/assets sin prefijo public/, lo añadimos cuando hace falta.
            if ($webPublic !== '' && !str_starts_with($rel, 'public/')) {
                $rel = ltrim($webPublic, '/') . '/' . $rel;
            }
            $imageUrl = $siteUrl . '/' . $rel;
        }
    } elseif (is_file(PUBLIC_PATH . '/assets/img/og-image.jpg')) {
        $imageUrl = $siteUrl . $webPublic . '/assets/img/og-image.jpg';
    } elseif (is_file(PUBLIC_PATH . '/assets/img/og-image.png')) {
        $imageUrl = $siteUrl . $webPublic . '/assets/img/og-image.png';
    } elseif (is_file(PUBLIC_PATH . '/assets/img/og-image.webp')) {
        $imageUrl = $siteUrl . $webPublic . '/assets/img/og-image.webp';
    }

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $html = "\n"
        . '<meta name="description" content="' . $esc($description) . '">' . "\n"
        . '<meta property="og:type" content="website">' . "\n"
        . '<meta property="og:site_name" content="' . $esc($appName) . '">' . "\n"
        . '<meta property="og:title" content="' . $esc($title) . '">' . "\n"
        . '<meta property="og:description" content="' . $esc($description) . '">' . "\n"
        . '<meta name="twitter:card" content="' . ($imageUrl !== '' ? 'summary_large_image' : 'summary') . '">' . "\n"
        . '<meta name="twitter:title" content="' . $esc($title) . '">' . "\n"
        . '<meta name="twitter:description" content="' . $esc($description) . '">' . "\n";

    if ($canonical !== '') {
        $html .= '<meta property="og:url" content="' . $esc($canonical) . '">' . "\n"
            . '<link rel="canonical" href="' . $esc($canonical) . '">' . "\n";
    }
    if ($imageUrl !== '') {
        $html .= '<meta property="og:image" content="' . $esc($imageUrl) . '">' . "\n"
            . '<meta property="og:image:width" content="1200">' . "\n"
            . '<meta property="og:image:height" content="630">' . "\n"
            . '<meta name="twitter:image" content="' . $esc($imageUrl) . '">' . "\n";
    }

    return $html;
}

/**
 * Normaliza una ruta relativa bajo public/uploads/ (sin path traversal).
 */
function normalize_upload_rel(string $pathOrUrl): ?string
{
    $raw = trim($pathOrUrl);
    if ($raw === '') {
        return null;
    }
    // URL absoluta → quedarnos con el path
    if (preg_match('#^https?://#i', $raw)) {
        $parts = parse_url($raw);
        $raw = (string)($parts['path'] ?? '');
    }
    $raw = str_replace('\\', '/', $raw);
    $raw = rawurldecode($raw);
    // Quitar prefijos conocidos
    $raw = preg_replace('#^.*?/public/#', '', $raw) ?? $raw;
    $raw = ltrim($raw, '/');
    if (str_starts_with($raw, 'app/media')) {
        return null;
    }
    // Si viene solo el nombre tras uploads/
    if (!str_starts_with($raw, 'uploads/')) {
        if (preg_match('#(?:^|/)(uploads/.+)$#', $raw, $m)) {
            $raw = $m[1];
        } else {
            return null;
        }
    }
    // Path traversal
    if (str_contains($raw, '..') || str_contains($raw, "\0")) {
        return null;
    }
    // Solo uploads/ (excepto que branding pueda seguir siendo público vía disco)
    if (!preg_match('#^uploads/[a-zA-Z0-9_\-./]+$#', $raw)) {
        return null;
    }
    return $raw;
}

/**
 * URL autenticada para un archivo en public/uploads/.
 */
function media_url(?string $pathOrUrl): string
{
    $pathOrUrl = trim((string)$pathOrUrl);
    if ($pathOrUrl === '') {
        return '';
    }
    // Ya es endpoint protegido
    if (str_contains($pathOrUrl, '/app/media')) {
        return $pathOrUrl;
    }
    $rel = normalize_upload_rel($pathOrUrl);
    if ($rel === null) {
        // No es un upload conocido: devolver tal cual (assets, externos)
        return $pathOrUrl;
    }
    // Branding puede seguir siendo público
    if (str_starts_with($rel, 'uploads/branding/')) {
        return base_url($rel);
    }
    return base_url('/app/media?f=' . rawurlencode($rel));
}

