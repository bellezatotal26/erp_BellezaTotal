<?php
/**
 * Web App Manifest dinámico — PWA Caja (instalable en escritorio Chrome/Edge).
 * También responde a /manifest.webmanifest vía rewrite en .htaccess.
 */
declare(strict_types=1);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}

$publicPrefix = $scriptDir;

$base = $publicPrefix;
if (str_ends_with($base, '/public')) {
    $base = substr($base, 0, -7);
}
if ($base === '/' || $base === false) {
    $base = '';
}

$startPath = ($base === '' ? '' : $base) . '/app/caja';
$scope = ($base === '' ? '/' : $base . '/');

$imgDir = __DIR__ . '/assets/img';
$iconVersion = static function (string $file) use ($imgDir): string {
    $path = $imgDir . '/' . $file;
    return is_file($path) ? (string)filemtime($path) : (string)time();
};

$icon192 = $publicPrefix . '/assets/img/pwa-icon-192.png?v=' . $iconVersion('pwa-icon-192.png');
$icon512 = $publicPrefix . '/assets/img/pwa-icon-512.png?v=' . $iconVersion('pwa-icon-512.png');
$iconMaskable = $publicPrefix . '/assets/img/pwa-icon-maskable-512.png?v=' . $iconVersion('pwa-icon-maskable-512.png');

// Splash del logo (negro). Barra PWA: color primario del tenant si existe.
$backgroundColor = '#000000';
$themeColor = '#000000';
try {
    $root = dirname(__DIR__);
    $envFile = $root . '/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\"'");
            if ($name !== '' && getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }
    if (is_file($root . '/vendor/autoload.php')) {
        require_once $root . '/vendor/autoload.php';
        require_once $root . '/core/helpers.php';
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', $root);
            define('CONFIG_PATH', $root . '/config');
            define('PUBLIC_PATH', __DIR__);
            define('STORAGE_PATH', $root . '/storage');
        }
        $pdo = \SoftNova\Core\Database::getInstance()->getConnection();
        if ($pdo) {
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'primary_color' LIMIT 1");
            $hex = trim((string)($stmt ? $stmt->fetchColumn() : ''));
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
                $themeColor = strtoupper($hex);
            }
        }
    }
} catch (\Throwable $e) {
    // Mantener negro de marca
}

echo json_encode([
    'id' => $startPath,
    'name' => 'Belleza Total - ERP',
    'short_name' => 'BT Caja',
    'description' => 'Punto de venta con modo offline: vende sin internet y sincroniza al recuperar la conexión.',
    'start_url' => $startPath . (str_contains($startPath, '?') ? '&' : '?') . 'source=pwa',
    'scope' => $scope,
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'background_color' => $backgroundColor,
    'theme_color' => $themeColor,
    'lang' => 'es-CO',
    'dir' => 'ltr',
    'orientation' => 'any',
    'categories' => ['business', 'finance', 'productivity'],
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => $icon192,
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $icon512,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $iconMaskable,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name' => 'Abrir Caja',
            'short_name' => 'Caja',
            'description' => 'Ir al punto de venta',
            'url' => $startPath,
            'icons' => [
                ['src' => $icon192, 'sizes' => '192x192'],
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
