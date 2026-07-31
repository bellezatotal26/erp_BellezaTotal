<?php
/**
 * Script de backup automático de la BD maestra
 * Uso: php database/backup.php
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/core/helpers.php';

$config = \SoftNova\Core\config('database.default');
$host = $config['host'] ?? 'localhost';
$port = $config['port'] ?? 3306;
$user = $config['username'] ?? 'root';
$pass = $config['password'] ?? '';
$db = $config['database'] ?? 'softnova_master';

$backupDir = ROOT_PATH . '/storage/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

$filename = $db . '_' . date('Y-m-d_H-i-s') . '.sql';
$filepath = $backupDir . $filename;

// Buscar mysqldump
$mysqldump = 'mysqldump';
$paths = ['c:\\xampp\\mysql\\bin\\mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'];
foreach ($paths as $p) { if (file_exists($p)) { $mysqldump = $p; break; } }

$command = sprintf(
    '"%s" -h %s -P %s -u %s %s %s > "%s" 2>&1',
    $mysqldump, escapeshellarg($host), escapeshellarg((string)$port),
    escapeshellarg($user), !empty($pass) ? '-p' . escapeshellarg($pass) : '',
    escapeshellarg($db), $filepath
);

exec($command, $output, $returnCode);

if ($returnCode === 0 && file_exists($filepath)) {
    echo "✅ Backup creado: {$filename} (" . round(filesize($filepath)/1024, 1) . " KB)\n";
    
    // Eliminar backups antiguos (>30 días)
    $cutoff = time() - (30 * 86400);
    foreach (glob($backupDir . '*.sql') as $old) {
        if (filemtime($old) < $cutoff) {
            unlink($old);
            echo "🗑️ Eliminado backup antiguo: " . basename($old) . "\n";
        }
    }
} else {
    echo "❌ Error al crear backup\n";
    echo implode("\n", $output);
}
