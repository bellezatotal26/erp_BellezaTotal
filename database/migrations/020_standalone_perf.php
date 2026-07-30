<?php
/**
 * Migración idempotente STANDALONE:
 * - Índices sales.sale_date, products.code, cash_movements.cash_session_id
 * - Tablas jobs, audit_logs (tenant), tenant_notifications
 *
 * Uso (desde la raíz del proyecto):
 *   php database/migrations/020_standalone_perf.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

// Cargar .env mínimo
$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '';
$user = getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '';

if ($name === '') {
    // Fallback config/database.php
    $cfg = require $root . '/config/database.php';
    $def = $cfg['default'] ?? $cfg;
    $host = $def['host'] ?? $host;
    $port = (string)($def['port'] ?? $port);
    $name = $def['database'] ?? $name;
    $user = $def['username'] ?? $user;
    $pass = $def['password'] ?? $pass;
}

if ($name === '') {
    fwrite(STDERR, "No se pudo determinar el nombre de la BD.\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function ensureIndex(PDO $pdo, string $table, string $index, string $columns): void
{
    if (indexExists($pdo, $table, $index)) {
        echo "OK índice {$table}.{$index}\n";
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        echo "CREADO índice {$table}.{$index}\n";
    } catch (Throwable $e) {
        echo "SKIP {$table}.{$index}: " . $e->getMessage() . "\n";
    }
}

echo "=== Migración 020 standalone perf ({$name}) ===\n";

ensureIndex($pdo, 'sales', 'idx_sale_date', '`sale_date`');
ensureIndex($pdo, 'sales', 'idx_sales_date', '`sale_date`'); // alias legacy
ensureIndex($pdo, 'products', 'idx_code', '`code`');
ensureIndex($pdo, 'cash_movements', 'idx_session', '`cash_session_id`');
ensureIndex($pdo, 'cash_movements', 'idx_cash_session_id', '`cash_session_id`');

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `jobs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `queue` VARCHAR(60) NOT NULL DEFAULT 'default',
        `job_type` VARCHAR(80) NOT NULL,
        `payload` LONGTEXT NULL,
        `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
        `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
        `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
        `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reserved_at` DATETIME NULL,
        `finished_at` DATETIME NULL,
        `last_error` TEXT NULL,
        `created_by` INT UNSIGNED NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_jobs_status_avail` (`status`,`available_at`),
        KEY `idx_jobs_type` (`job_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "OK tabla jobs\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NULL,
        `user_name` VARCHAR(120) NULL,
        `action` VARCHAR(40) NOT NULL,
        `module` VARCHAR(60) NOT NULL,
        `entity_type` VARCHAR(60) NULL,
        `entity_id` INT UNSIGNED NULL,
        `description` TEXT NULL,
        `ip_address` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_audit_module` (`module`),
        KEY `idx_audit_user` (`user_id`),
        KEY `idx_audit_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "OK tabla audit_logs\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `tenant_notifications` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `notif_key` VARCHAR(80) NOT NULL,
        `type` VARCHAR(40) NOT NULL DEFAULT 'system',
        `title` VARCHAR(160) NOT NULL,
        `message` VARCHAR(500) NOT NULL,
        `url` VARCHAR(255) NULL,
        `urgent` TINYINT(1) NOT NULL DEFAULT 0,
        `meta` VARCHAR(120) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_notif_key` (`notif_key`),
        KEY `idx_notif_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "OK tabla tenant_notifications\n";

echo "=== Listo ===\n";
