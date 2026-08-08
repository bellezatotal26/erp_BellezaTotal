<?php
/**
 * Web App Manifest dinámico (ruta base correcta en subdirectorios / cPanel).
 */
declare(strict_types=1);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
}

// manifest.php vive en /public → el prefijo de assets es $scriptDir.
$publicPrefix = $scriptDir;

// Alcance de la app: un nivel arriba si el script está en /public.
$base = $publicPrefix;
if (str_ends_with($base, '/public')) {
    $base = substr($base, 0, -7);
}
if ($base === '/' || $base === false) {
    $base = '';
}

$startPath = ($base === '' ? '' : $base) . '/app/caja';
$scope = ($base === '' ? '/' : $base . '/');

echo json_encode([
    'name' => 'Seri ERP - Caja',
    'short_name' => 'Seri Caja',
    'description' => 'Punto de venta Seri ERP con continuidad offline',
    'start_url' => $startPath,
    'scope' => $scope,
    'display' => 'standalone',
    'background_color' => '#F8FAFC',
    'theme_color' => '#0F766E',
    'lang' => 'es',
    'orientation' => 'any',
    'icons' => [
        [
            'src' => $publicPrefix . '/assets/img/pwa-icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $publicPrefix . '/assets/img/pwa-icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
