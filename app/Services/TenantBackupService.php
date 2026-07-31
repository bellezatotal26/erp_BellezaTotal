<?php

namespace SoftNova\Services;

/**
 * Backup / restore SQL de la base de datos del tenant
 */
class TenantBackupService
{
    private string $backupDir;
    private array $dbConfig;
    
    public function __construct(?int $tenantId = null)
    {
        $tenantId = $tenantId ?? (int)($_SESSION['tenant_id'] ?? 0);
        $this->backupDir = ROOT_PATH . '/storage/backups/tenants/' . max(0, $tenantId) . '/';
        $this->dbConfig = \SoftNova\Core\config('database.default') ?? [];
        
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        
        // Evitar listado publico
        $htaccess = dirname($this->backupDir, 2) . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
    }
    
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }
    
    /**
     * Crear dump SQL de la BD del tenant
     * @return array{success:bool,message:string,filename?:string,path?:string,size?:int}
     */
    public function createBackup(string $dbName, string $label = 'manual'): array
    {
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        if ($dbName === '') {
            return ['success' => false, 'message' => 'Nombre de base de datos invalido'];
        }
        
        $safeLabel = preg_replace('/[^a-zA-Z0-9_-]/', '', $label) ?: 'manual';
        $filename = $dbName . '_' . $safeLabel . '_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $this->backupDir . $filename;
        
        $mysqldump = $this->findBin('mysqldump');
        if (!$mysqldump) {
            return ['success' => false, 'message' => 'No se encontro mysqldump en el servidor'];
        }
        
        $host = $this->dbConfig['host'] ?? 'localhost';
        $port = (int)($this->dbConfig['port'] ?? 3306);
        $user = $this->dbConfig['username'] ?? 'root';
        $pass = $this->dbConfig['password'] ?? '';
        
        $command = sprintf(
            '"%s" --single-transaction --routines --triggers -h %s -P %s -u %s %s %s > %s 2>&1',
            $mysqldump,
            escapeshellarg($host),
            escapeshellarg((string)$port),
            escapeshellarg($user),
            $pass !== '' ? '-p' . escapeshellarg($pass) : '',
            escapeshellarg($dbName),
            escapeshellarg($filepath)
        );
        
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        
        if ($code !== 0 || !is_file($filepath) || filesize($filepath) < 50) {
            @unlink($filepath);
            return [
                'success' => false,
                'message' => 'Error al generar el backup: ' . implode(' ', $output),
            ];
        }
        
        $this->pruneOld(7);
        
        return [
            'success' => true,
            'message' => 'Backup creado correctamente',
            'filename' => $filename,
            'path' => $filepath,
            'size' => filesize($filepath),
        ];
    }
    
    /**
     * Restaurar dump SQL en la BD del tenant (sobrescribe datos)
     */
    public function restoreBackup(string $dbName, string $filepath): array
    {
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        if ($dbName === '' || !is_file($filepath)) {
            return ['success' => false, 'message' => 'Archivo de backup no valido'];
        }
        
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            return ['success' => false, 'message' => 'Solo se aceptan archivos .sql'];
        }
        
        // Seguridad: el archivo debe estar en el directorio de backups del tenant o ser upload temporal
        $real = realpath($filepath);
        $dirReal = realpath($this->backupDir);
        $tmpReal = realpath(sys_get_temp_dir());
        if (!$real || (
            ($dirReal && strpos($real, $dirReal) !== 0) &&
            ($tmpReal && strpos($real, $tmpReal) !== 0)
        )) {
            return ['success' => false, 'message' => 'Ruta de backup no permitida'];
        }
        
        $mysql = $this->findBin('mysql');
        if (!$mysql) {
            return ['success' => false, 'message' => 'No se encontro el cliente mysql en el servidor'];
        }
        
        $host = $this->dbConfig['host'] ?? 'localhost';
        $port = (int)($this->dbConfig['port'] ?? 3306);
        $user = $this->dbConfig['username'] ?? 'root';
        $pass = $this->dbConfig['password'] ?? '';
        
        $command = sprintf(
            '"%s" -h %s -P %s -u %s %s %s < %s 2>&1',
            $mysql,
            escapeshellarg($host),
            escapeshellarg((string)$port),
            escapeshellarg($user),
            $pass !== '' ? '-p' . escapeshellarg($pass) : '',
            escapeshellarg($dbName),
            escapeshellarg($real)
        );
        
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        
        if ($code !== 0) {
            return [
                'success' => false,
                'message' => 'Error al restaurar: ' . implode(' ', $output),
            ];
        }
        
        return ['success' => true, 'message' => 'Base de datos restaurada correctamente'];
    }
    
    /**
     * Listar backups locales del tenant (con paginación)
     *
     * @return array{items: array, all: array, pagination: array}
     */
    public function listBackups(int $page = 1, int $perPage = 7): array
    {
        $this->pruneOld(7);
        
        $files = glob($this->backupDir . '*.sql') ?: [];
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        
        $mapFile = function (string $file): array {
            return [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_label' => $this->formatBytes((int)filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        };
        
        $all = array_map($mapFile, $files);
        $total = count($all);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $items = array_slice($all, $offset, $perPage);
        
        return [
            'items' => $items,
            'all' => $all,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }
    
    /**
     * Eliminar un backup del servidor
     */
    public function deleteBackup(string $filename): array
    {
        $path = $this->resolvePath($filename);
        if (!$path) {
            return ['success' => false, 'message' => 'Archivo no encontrado'];
        }
        
        if (!@unlink($path)) {
            return ['success' => false, 'message' => 'No se pudo eliminar el archivo'];
        }
        
        return ['success' => true, 'message' => 'Copia eliminada'];
    }
    
    public function resolvePath(string $filename): ?string
    {
        $filename = basename($filename);
        if (!preg_match('/^[a-zA-Z0-9._-]+\.sql$/', $filename)) {
            return null;
        }
        $path = $this->backupDir . $filename;
        return is_file($path) ? $path : null;
    }
    
    /**
     * Ejecutar backup automatico si corresponde segun settings
     */
    public function maybeAutoBackup(string $dbName, array $settings): ?array
    {
        if (($settings['backup_enabled'] ?? '0') !== '1') {
            return null;
        }
        
        $time = $settings['backup_time'] ?? '02:00';
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return null;
        }
        
        $now = new \DateTimeImmutable('now');
        $target = $now->setTime((int)$m[1], (int)$m[2], 0);
        $last = $settings['backup_last_run'] ?? '';
        
        // Solo correr si ya paso la hora programada de hoy y no se corrio hoy
        if ($now < $target) {
            return null;
        }
        
        $today = $now->format('Y-m-d');
        if ($last !== '' && str_starts_with($last, $today)) {
            return null;
        }
        
        // Evitar carrera: marcar intento antes
        $this->writeSettingHint('backup_last_run', $now->format('Y-m-d H:i:s'));
        
        return $this->createBackup($dbName, 'auto');
    }
    
    /**
     * Hint en sesion; el controlador debe persistir en settings
     */
    private function writeSettingHint(string $key, string $value): void
    {
        $_SESSION['_backup_setting_hint'][$key] = $value;
    }
    
    public static function consumeSettingHints(): array
    {
        $hints = $_SESSION['_backup_setting_hint'] ?? [];
        unset($_SESSION['_backup_setting_hint']);
        return is_array($hints) ? $hints : [];
    }
    
    private function pruneOld(int $keepDays): void
    {
        $cutoff = time() - ($keepDays * 86400);
        foreach (glob($this->backupDir . '*.sql') ?: [] as $old) {
            if (filemtime($old) < $cutoff) {
                @unlink($old);
            }
        }
    }
    
    private function findBin(string $name): ?string
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $paths = $isWin
            ? [
                'c:\\xampp\\mysql\\bin\\' . $name . '.exe',
                'C:\\xampp\\mysql\\bin\\' . $name . '.exe',
            ]
            : [
                '/usr/bin/' . $name,
                '/usr/local/bin/' . $name,
            ];
        
        foreach ($paths as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        
        if ($isWin) {
            exec('where ' . $name . ' 2>nul', $out);
            if (!empty($out[0]) && is_file($out[0])) {
                return $out[0];
            }
        } else {
            exec('which ' . escapeshellarg($name) . ' 2>/dev/null', $out);
            if (!empty($out[0]) && is_file($out[0])) {
                return $out[0];
            }
        }
        
        return null;
    }
    
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
