<?php
/**
 * Procesa jobs pendientes (backup, import CSV, webhooks).
 *
 * Uso:
 *   php database/process_jobs.php
 *   php database/process_jobs.php --limit=20
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
    define('APP_PATH', $root . '/app');
    define('CORE_PATH', $root . '/core');
    define('CONFIG_PATH', $root . '/config');
    define('PUBLIC_PATH', $root . '/public');
    define('STORAGE_PATH', $root . '/storage');
}

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
    $cfg = require $root . '/config/database.php';
    $def = $cfg['default'] ?? $cfg;
    $host = $def['host'] ?? $host;
    $port = (string)($def['port'] ?? $port);
    $name = $def['database'] ?? '';
    $user = $def['username'] ?? $user;
    $pass = $def['password'] ?? $pass;
}

$limit = 10;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$queue = new SoftNova\Services\JobQueue($pdo);
$result = $queue->process($limit);
echo json_encode([
    'success' => true,
    'processed' => $result['processed'],
    'failed' => $result['failed'],
    'done' => $result['done'],
    'pending' => $queue->pendingCount(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
