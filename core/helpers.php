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
function normalize_theme_hex(?string $hex, string $fallback = '#000000'): string
{
    $hex = trim((string)$hex);
    if ($hex !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
        return strtoupper($hex);
    }
    return $fallback;
}

function theme_color_style(?string $hex): string
{
    $hex = normalize_theme_hex($hex, '');
    if ($hex === '') {
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

    // Modo oscuro: un primario casi negro se confunde con las tarjetas (#1E293B); se aclara solo para superficies.
    $darkSurface = '';
    if ($luminance < 0.08) {
        $lighten = static function (int $c): int {
            return (int)round($c + (255 - $c) * 0.28);
        };
        $darkSurface = sprintf('--dm-primary-surface: #%02X%02X%02X;', $lighten($r), $lighten($g), $lighten($b));
    }

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
        . $darkSurface
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
    $baseUrl = trim((string)config('app.url', ''));
    // Si app.url está vacío o apunta a otro host, usar el host actual (evita CSRF en migraciones).
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    if ($host !== '') {
        $current = ($https ? 'https' : 'http') . '://' . $host;
        if ($baseUrl === '' || !str_contains(strtolower($baseUrl), strtolower($host))) {
            $baseUrl = $current;
        }
    }
    if ($baseUrl === '') {
        $baseUrl = 'http://localhost';
    }
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
    $rel = ltrim($path, '/');
    $url = base_url(ltrim(public_web_prefix() . '/assets/' . $rel, '/'));
    // Cache-bust por mtime: evita JS/CSS viejos tras subir ZIP (y ayuda al SW).
    if (defined('PUBLIC_PATH')) {
        $file = PUBLIC_PATH . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($file)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
        }
    }
    return $url;
}

/**
 * Redireccionar
 */
function redirect(string $url): void
{
    if (!str_starts_with($url, 'http')) {
        $url = base_url(ltrim($url, '/'));
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
        // Branding: URL pública con prefijo /public si el DocumentRoot no es public/.
        // Si el acceso directo falla (htaccess), el preview autenticado usa /app/media.
        if (str_starts_with($rel, 'uploads/branding/')) {
            $webPublic = public_web_prefix(); // '' o '/public'
            return base_url(ltrim($webPublic . '/' . $rel, '/'));
        }
        return base_url('/app/media?f=' . rawurlencode($rel));
    }

/**
 * Repara texto UTF-8 mal interpretado (mojibake), p.ej. "UÃ'A" → "UÑA".
 * Seguro: si no detecta corrupción, devuelve el original.
 */
function utf8_fix(string $text): string
{
    if ($text === '') {
        return $text;
    }
    // Señales típicas de UTF-8 leído como Latin-1/Windows-1252
    if (!preg_match('/Ã.|Â.|Ð.|Ã|Â/u', $text)) {
        return $text;
    }

    foreach (['Windows-1252', 'ISO-8859-1'] as $from) {
        $candidate = @mb_convert_encoding($text, $from, 'UTF-8');
        if ($candidate === false || $candidate === '' || $candidate === $text) {
            continue;
        }
        if (!mb_check_encoding($candidate, 'UTF-8')) {
            continue;
        }
        // Aceptar si recupera letras latinas o reduce marcadores de mojibake
        if (
            preg_match('/[ÑñÁÉÍÓÚáéíóúÜü¿¡]/u', $candidate)
            || substr_count($candidate, 'Ã') < substr_count($text, 'Ã')
        ) {
            return $candidate;
        }
    }

    return $text;
}

/** Escape HTML con UTF-8 explícito + reparación de mojibake. */
function e(?string $text): string
{
    return htmlspecialchars(utf8_fix((string)$text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Búsqueda multi-término.
 *
 * - Separadores `,` o `;`: OR entre grupos (pegar varios SKU/EAN).
 * - Espacios dentro de un grupo: AND (todas las palabras deben aparecer
 *   en alguna de las columnas). Así "Esmalte revo" encuentra
 *   "MASGLO ESMALTE - REVOLUCIONARIA".
 *
 * @param list<string> $columns Expresiones SQL seguras (ej. p.name, COALESCE(p.barcode,''))
 * @return array{sql:string,params:list<string>}
 */
function multi_term_like_clause(string $rawQuery, array $columns, int $maxTerms = 40): array
{
    $rawQuery = trim($rawQuery);
    if ($rawQuery === '' || $columns === []) {
        return ['sql' => '1=0', 'params' => []];
    }

    $groups = preg_split('/[,;]+/u', $rawQuery) ?: [];
    $groupClauses = [];
    $params = [];
    $termBudget = max(1, $maxTerms);

    foreach ($groups as $group) {
        $group = trim((string)$group);
        if ($group === '' || $termBudget <= 0) {
            continue;
        }

        $words = preg_split('/\s+/u', $group) ?: [];
        $ands = [];
        foreach ($words as $word) {
            $word = trim((string)$word);
            if ($word === '' || $termBudget <= 0) {
                continue;
            }
            $termBudget--;

            $colOrs = [];
            $like = '%' . $word . '%';
            foreach ($columns as $col) {
                $col = trim((string)$col);
                if ($col === '') {
                    continue;
                }
                $colOrs[] = $col . ' LIKE ?';
                $params[] = $like;
            }
            if ($colOrs !== []) {
                $ands[] = '(' . implode(' OR ', $colOrs) . ')';
            }
        }

        if ($ands !== []) {
            $groupClauses[] = '(' . implode(' AND ', $ands) . ')';
        }
    }

    if ($groupClauses === []) {
        return ['sql' => '1=0', 'params' => []];
    }

    return ['sql' => '(' . implode(' OR ', $groupClauses) . ')', 'params' => $params];
}

/**
 * Interpreta montos digitados con formato local (1.234.567,50 o 1,234,567.50).
 * No rompe valores ya normalizados (1234.56).
 */
function parse_money(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) {
        return (float)$raw;
    }
    $s = trim((string)$raw);
    if ($s === '') {
        return 0.0;
    }
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    $s = preg_replace('/[^\d,.\-]/u', '', $s) ?? '';
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') {
        return 0.0;
    }

    $neg = str_starts_with($s, '-');
    $s = ltrim($s, '+-');

    if (str_contains($s, ',') && str_contains($s, '.')) {
        if (strrpos($s, ',') > strrpos($s, '.')) {
            // 1.234.567,50
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // 1,234,567.50
            $s = str_replace(',', '', $s);
        }
    } elseif (str_contains($s, ',')) {
        $bits = explode(',', $s);
        if (count($bits) === 2 && strlen($bits[1]) <= 2) {
            $s = str_replace('.', '', $bits[0]) . '.' . $bits[1];
        } else {
            $s = str_replace(',', '', $s);
        }
    } else {
        // Solo puntos: varios = miles; uno solo con 1–2 decimales = decimal.
        $parts = explode('.', $s);
        if (count($parts) > 2) {
            $s = str_replace('.', '', $s);
        } elseif (count($parts) === 2) {
            $dec = $parts[1];
            $decLen = strlen($dec);
            // Excel suele exportar 15500.000 / 12.00 → NO son miles; quitar ceros de relleno.
            if ($decLen > 0 && ctype_digit($dec) && (int)$dec === 0) {
                $s = $parts[0];
            } elseif ($decLen === 3 && strlen($parts[0]) <= 3) {
                // Ambiguo 1.234 → miles (COP típico)
                $s = str_replace('.', '', $s);
            } elseif ($decLen > 2) {
                // 15500.1234 (raro en COP): conservar como decimal, no concatenar miles.
                // Antes: borrar puntos → 155001234 (inflaba costos ×1000+).
                if (strlen($parts[0]) >= 4) {
                    $s = $parts[0] . '.' . substr($dec, 0, 2);
                } else {
                    $s = str_replace('.', '', $s);
                }
            }
            // si decLen 1–2 se deja como decimal
        }
    }

    $n = (float)$s;
    return $neg ? -$n : $n;
}

/**
 * Cantidades (unidades), no dinero.
 * "7.000" / "7,000" con un solo separador y 3 decimales = 7 unidades,
 * no 7000 (eso es el formato de miles del peso).
 */
function parse_quantity(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) {
        return max(0.0, (float)$raw);
    }
    $s = trim((string)$raw);
    if ($s === '') {
        return 0.0;
    }
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    $s = preg_replace('/[^\d,.\-]/u', '', $s) ?? '';
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') {
        return 0.0;
    }
    $s = ltrim($s, '+-');

    $comma = strrpos($s, ',');
    $dot = strrpos($s, '.');
    if ($comma !== false && $dot !== false) {
        if ($comma > $dot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($comma !== false) {
        $parts = explode(',', $s);
        if (count($parts) === 2) {
            $s = $parts[0] . '.' . $parts[1];
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($dot !== false) {
        $parts = explode('.', $s);
        if (count($parts) > 2) {
            $last = array_pop($parts);
            if (strlen($last) <= 3) {
                $s = implode('', $parts) . '.' . $last;
            } else {
                $s = implode('', $parts) . $last;
            }
        }
        // Un solo punto: siempre decimal (7.000 → 7, no 7000).
    }

    $n = (float)$s;
    return $n > 0 ? $n : 0.0;
}

/**
 * Vueltas / cambio. Si el valor guardado es nulo, 0, mayor al recibido
 * o overflow DECIMAL (p. ej. 9999999999.99), se recalcula recibido − debido.
 */
function cash_change_amount(float $received, float $due, mixed $storedChange = null): float
{
    $received = max(0.0, $received);
    $due = max(0.0, $due);
    $computed = max(0.0, round($received - $due, 2));
    if ($received <= 0.009) {
        return 0.0;
    }
    if ($storedChange === null || $storedChange === '') {
        return $computed;
    }
    $stored = is_int($storedChange) || is_float($storedChange)
        ? (float)$storedChange
        : parse_money($storedChange);
    if ($stored <= 0.009 || $stored > ($received + 0.05) || $stored >= 1000000000.0) {
        return $computed;
    }
    return round(max(0.0, $stored), 2);
}


