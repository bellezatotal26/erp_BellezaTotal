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
// Caja/almacén: jornada larga. Default 16h (antes 2h provocaba “token expirado”).
$sessionLifetime = (int)($sessionConfig['lifetime'] ?? 57600);
if ($sessionLifetime < 28800) {
    // Mínimo 8h en runtime aunque el config viejo diga 7200.
    $sessionLifetime = max($sessionLifetime, 57600);
}
$sessionPath = $sessionConfig['path'] ?? '/';
$sessionDomain = $sessionConfig['domain'] ?? '';
$sessionSecure = (bool)($sessionConfig['secure'] ?? false);
$sessionHttpOnly = (bool)($sessionConfig['httponly'] ?? true);
$sessionSameSite = (string)($sessionConfig['samesite'] ?? 'Lax');

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => $sessionPath,
    'domain' => $sessionDomain,
    'secure' => $sessionSecure,
    'httponly' => $sessionHttpOnly,
    'samesite' => $sessionSameSite,
]);
// Sesiones propias en storage/sessions: el limpiador de cPanel borra
// /var/cpanel/php/sessions según su php.ini (24 min), ignorando gc_maxlifetime en runtime.
$sessionSavePath = STORAGE_PATH . '/sessions';
if (!is_dir($sessionSavePath)) {
    @mkdir($sessionSavePath, 0700, true);
}
if (is_dir($sessionSavePath) && is_writable($sessionSavePath)) {
    $sessionHtaccess = $sessionSavePath . '/.htaccess';
    if (!is_file($sessionHtaccess)) {
        @file_put_contents(
            $sessionHtaccess,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
        );
    }
    session_save_path($sessionSavePath);
    // Carpeta propia: el GC de PHP debe correr (cPanel suele traer probability=0).
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
}
// Alinear GC del servidor con la cookie (evita sesión muerta con pestaña abierta).
ini_set('session.gc_maxlifetime', (string)max($sessionLifetime, 57600));

// Iniciar sesión
session_start();

// Petición que llegó con un ID ya rotado (p. ej. AJAX en paralelo al cambiar de módulo).
\SoftNova\Core\Session::followRotation(1800);

// Rotación periódica del ID (mitiga robo de cookie). Solo en navegación GET de página
// para no competir con AJAX/POST concurrentes.
$regenEnabled = (bool)\SoftNova\Core\config('session_security.regenerate_id', true);
$isPageNavigation = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate') === 'navigate')
    && stripos((string)($_SERVER['HTTP_ACCEPT'] ?? 'text/html'), 'text/html') !== false;
if ($regenEnabled && $isPageNavigation && session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
    $regenInterval = max(900, (int)\SoftNova\Core\config('session_security.regenerate_interval', 1800));
    $lastRegen = (int)($_SESSION['_seri_last_regen'] ?? 0);
    if ($lastRegen <= 0) {
        $_SESSION['_seri_last_regen'] = time();
    } elseif ((time() - $lastRegen) >= $regenInterval) {
        \SoftNova\Core\Session::rotateId();
    }
}

// Ventana deslizante: cada request renueva la cookie mientras haya actividad
// (empleado en Caja todo el día sin F5).
if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
    $params = [
        'expires' => time() + $sessionLifetime,
        'path' => $sessionPath,
        'secure' => $sessionSecure,
        'httponly' => $sessionHttpOnly,
        'samesite' => $sessionSameSite,
    ];
    if ($sessionDomain !== '') {
        $params['domain'] = $sessionDomain;
    }
    setcookie(session_name(), session_id(), $params);
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

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
