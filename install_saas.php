<?php
/**
 * Instalador web inicial para despliegues por FTP.
 *
 * Suba el proyecto, visite /install.php una sola vez y elimine este archivo
 * después de finalizar. Al completar se crea storage/installed.lock.
 */
declare(strict_types=1);

$root = __DIR__;
$lockFile = $root . '/storage/installed.lock';
$errors = [];
$success = false;

if (is_file($lockFile)) {
    http_response_code(403);
    exit('Seri ERP ya fue instalado. Elimine install.php del servidor.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['db_host'] ?? 'localhost'));
    $port = max(1, (int)($_POST['db_port'] ?? 3306));
    $database = trim((string)($_POST['db_name'] ?? 'softnova_master'));
    $username = trim((string)($_POST['db_user'] ?? ''));
    $password = (string)($_POST['db_password'] ?? '');
    $appUrl = rtrim(trim((string)($_POST['app_url'] ?? '')), '/');
    $adminName = trim((string)($_POST['admin_name'] ?? 'Super Admin'));
    $adminEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
        $errors[] = 'El nombre de la base de datos solo puede contener letras, números y guion bajo.';
    }
    if ($host === '' || $username === '') {
        $errors[] = 'Host y usuario de base de datos son obligatorios.';
    }
    if (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'La URL pública no es válida (use https://dominio.com o https://dominio.com/public).';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo del superadministrador no es válido.';
    }
    if (strlen($adminPassword) < 10) {
        $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
    }
    if (!extension_loaded('pdo_mysql')) {
        $errors[] = 'El servidor no tiene habilitada la extensión pdo_mysql.';
    }
    if (!is_writable($root . '/config')) {
        $errors[] = 'La carpeta config/ no tiene permisos de escritura durante la instalación.';
    }
    if (!is_dir($root . '/storage') || !is_writable($root . '/storage')) {
        $errors[] = 'La carpeta storage/ debe existir y tener permisos de escritura.';
    }

    if (!$errors) {
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ];
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
            }
            $pdo = new PDO($dsn, $username, $password, $options);

            $sqlFile = $root . '/database/install_master.sql';
            $sql = is_file($sqlFile) ? file_get_contents($sqlFile) : false;
            if (!is_string($sql) || $sql === '') {
                throw new RuntimeException('No se encontró database/install_master.sql.');
            }
            $sql = str_replace('softnova_master', $database, $sql);
            $pdo->exec($sql);
            $pdo->exec("USE `{$database}`");

            $hash = password_hash($adminPassword, PASSWORD_ARGON2ID);
            if (!is_string($hash)) {
                throw new RuntimeException('No se pudo proteger la contraseña.');
            }
            $stmt = $pdo->prepare(
                "INSERT INTO super_admin_users (name, email, password, status)
                 VALUES (?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password), status = 'active'"
            );
            $stmt->execute([$adminName, $adminEmail, $hash]);

            $databaseConfig = [
                'database' => [
                    'default' => [
                        'host' => $host,
                        'port' => $port,
                        'database' => $database,
                        'username' => $username,
                        'password' => $password,
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'prefix' => '',
                    ],
                ],
            ];
            $appConfig = [
                'app' => [
                    'name' => 'Seri ERP',
                    'version' => '1.0.0',
                    'env' => 'production',
                    'debug' => false,
                    'url' => $appUrl,
                    'timezone' => 'America/Bogota',
                ],
                'session' => [
                    'name' => 'SERI_ERP_SESSION',
                    'lifetime' => 7200,
                    'path' => '/',
                    'domain' => '',
                    'secure' => str_starts_with($appUrl, 'https://'),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ],
                'security' => [
                    'csrf_token_name' => 'csrf_token',
                    'csrf_header_name' => 'X-CSRF-TOKEN',
                    'password_algorithm' => PASSWORD_ARGON2ID,
                    'password_options' => [
                        'memory_cost' => 65536,
                        'time_cost' => 4,
                        'threads' => 2,
                    ],
                ],
            ];

            $writeConfig = static function (string $path, array $config): void {
                $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
                if (file_put_contents($path, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('No se pudo escribir ' . basename($path));
                }
            };
            $writeConfig($root . '/config/database.php', $databaseConfig);
            $writeConfig($root . '/config/app.php', $appConfig);

            $lockContents = json_encode([
                'installed_at' => date(DATE_ATOM),
                'database' => $database,
                'url' => $appUrl,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($lockFile, $lockContents, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo bloquear el instalador.');
            }
            $success = true;
        } catch (Throwable $e) {
            $errors[] = 'Instalación fallida: ' . $e->getMessage();
        }
    }
}

$requirements = [
    'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'Mbstring' => extension_loaded('mbstring'),
    'JSON' => extension_loaded('json'),
    'OpenSSL' => extension_loaded('openssl'),
    'config/ escribible' => is_writable($root . '/config'),
    'storage/ escribible' => is_dir($root . '/storage') && is_writable($root . '/storage'),
    'vendor/autoload.php' => is_file($root . '/vendor/autoload.php'),
];
$defaultUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Instalar Seri ERP</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f2f5f7;color:#1e293b;font-family:system-ui,-apple-system,sans-serif}
        .wrap{width:min(900px,94%);margin:35px auto}.card{background:#fff;border-radius:16px;padding:25px;box-shadow:0 12px 35px rgba(15,23,42,.1);margin-bottom:18px}
        h1{color:#0d7c4a;margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}
        label{font-weight:650;font-size:13px;display:block;margin-bottom:5px}input{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:8px}
        button{background:#0d7c4a;color:#fff;border:0;border-radius:9px;padding:12px 20px;font-weight:700;cursor:pointer}
        .ok{color:#047857}.bad{color:#b91c1c}.alert{padding:13px;border-radius:8px;margin:10px 0}.error{background:#fee2e2;color:#991b1b}.success{background:#d1fae5;color:#065f46}
        ul{line-height:1.8}@media(max-width:650px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
    </style>
</head>
<body><main class="wrap">
    <div class="card">
        <h1>Instalación de Seri ERP</h1>
        <p>Este asistente configura la base maestra, crea el primer superadministrador y prepara el entorno de producción.</p>
        <ul>
            <?php foreach ($requirements as $label => $passed): ?>
                <li class="<?php echo $passed ? 'ok' : 'bad'; ?>"><?php echo $passed ? '✓' : '✗'; ?> <?php echo htmlspecialchars($label); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($success): ?>
        <div class="card success">
            <h2>Instalación completada</h2>
            <p>Ya puede ingresar al panel. Por seguridad, elimine <strong>install.php</strong> del servidor mediante FTP.</p>
            <p><a href="<?php echo htmlspecialchars(rtrim((string)($_POST['app_url'] ?? ''), '/') . '/login'); ?>">Abrir Seri ERP</a></p>
        </div>
    <?php else: ?>
        <?php foreach ($errors as $error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
        <form method="post" class="card" autocomplete="off">
            <div class="grid">
                <div><label>Host de BD</label><input name="db_host" value="<?php echo htmlspecialchars((string)($_POST['db_host'] ?? 'localhost')); ?>" required></div>
                <div><label>Puerto</label><input type="number" name="db_port" value="<?php echo htmlspecialchars((string)($_POST['db_port'] ?? '3306')); ?>" required></div>
                <div><label>Base maestra</label><input name="db_name" value="<?php echo htmlspecialchars((string)($_POST['db_name'] ?? 'softnova_master')); ?>" required></div>
                <div><label>Usuario de BD</label><input name="db_user" value="<?php echo htmlspecialchars((string)($_POST['db_user'] ?? '')); ?>" required></div>
                <div class="full"><label>Contraseña de BD</label><input type="password" name="db_password"></div>
                <div class="full"><label>URL pública (incluya /public si aplica)</label><input type="url" name="app_url" value="<?php echo htmlspecialchars((string)($_POST['app_url'] ?? $defaultUrl)); ?>" required></div>
                <div><label>Nombre superadministrador</label><input name="admin_name" value="<?php echo htmlspecialchars((string)($_POST['admin_name'] ?? 'Super Admin')); ?>" required></div>
                <div><label>Correo superadministrador</label><input type="email" name="admin_email" value="<?php echo htmlspecialchars((string)($_POST['admin_email'] ?? '')); ?>" required></div>
                <div class="full"><label>Contraseña superadministrador (mín. 10)</label><input type="password" name="admin_password" minlength="10" required></div>
                <div class="full"><button type="submit">Instalar Seri ERP</button></div>
            </div>
        </form>
    <?php endif; ?>
</main></body>
</html>
