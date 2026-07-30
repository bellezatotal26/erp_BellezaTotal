<?php

/**
 * Punto de entrada principal de la aplicación
 * Seri ERP - ERP SaaS
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('PUBLIC_PATH', __DIR__);
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Si aún no se instaló el sistema, guiar al instalador web.
$installedLock = STORAGE_PATH . '/installed.lock';
$installer = ROOT_PATH . '/install.php';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isInstallerRequest = str_ends_with($requestPath, '/install.php') || str_contains($requestPath, '/install.php');
if (!is_file($installedLock) && is_file($installer) && !$isInstallerRequest) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . ($base === '' ? '' : $base) . '/install.php');
    exit;
}

// Cargar variables de entorno desde .env (si existe)
$envFile = ROOT_PATH . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \t\"'");
        if ($name !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Cargar autoloader de Composer
require_once ROOT_PATH . '/vendor/autoload.php';

// Cargar helpers (antes de cualquier otra cosa)
require_once CORE_PATH . '/helpers.php';

// Configurar sesión segura antes de iniciarla
$sessionConfig = \SoftNova\Core\config('session', []);
if (!empty($sessionConfig['name'])) {
    session_name($sessionConfig['name']);
}
session_set_cookie_params([
    'lifetime' => $sessionConfig['lifetime'] ?? 7200,
    'path' => $sessionConfig['path'] ?? '/',
    'domain' => $sessionConfig['domain'] ?? '',
    'secure' => $sessionConfig['secure'] ?? false,
    'httponly' => $sessionConfig['httponly'] ?? true,
    'samesite' => $sessionConfig['samesite'] ?? 'Strict',
]);

// Iniciar sesión
session_start();

// Iniciar aplicación
try {
    $app = new SoftNova\Core\App();
    $app->run();
} catch (Exception $e) {
    \SoftNova\Core\Logger::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo '<h1>Error interno del servidor</h1>';
    if (\SoftNova\Core\config('app.debug', false)) {
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
} catch (Error $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo '<h1>Error interno del servidor</h1>';
    if (\SoftNova\Core\config('app.debug', false)) {
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
}
