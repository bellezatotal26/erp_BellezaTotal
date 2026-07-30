<?php

namespace SoftNova\Core;

/**
 * Servicio para gestionar bases de datos de tenants
 * Creación, conexión y migración de bases de datos individuales
 */
class TenantDatabase
{
    private static ?TenantDatabase $instance = null;
    private \PDO $masterConnection;
    private array $tenantConnections = [];
    
    private function __construct()
    {
        $db = Database::getInstance();
        $this->masterConnection = $db->getConnection();
    }
    
    public static function getInstance(): TenantDatabase
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Crear la base de datos del tenant y ejecutar la migración
     * Usa las credenciales maestras para todo (evita problemas con mysql.db corrupto)
     */
    public function createTenantDatabase(string $databaseName, string $databaseUser = '', string $databasePassword = ''): bool
    {
        try {
            // Crear base de datos
            $this->masterConnection->exec(
                "CREATE DATABASE IF NOT EXISTS `{$databaseName}`
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            
            // Ejecutar migración en la nueva base de datos
            $this->runMigration($databaseName);
            
            error_log("Tenant database created: {$databaseName}");
            return true;
        } catch (\PDOException $e) {
            error_log("Error creating tenant database {$databaseName}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Instalar esquema tenant actual (database/install_tenant.sql)
     */
    private function runMigration(string $databaseName): void
    {
        $installFile = ROOT_PATH . '/database/install_tenant.sql';
        if (!is_file($installFile)) {
            error_log('install_tenant.sql no encontrado');
            return;
        }
        $this->executeMigrationFile($databaseName, $installFile);
    }
    
    /**
     * Ejecutar un archivo SQL de migración en la BD del tenant
     */
    private function executeMigrationFile(string $databaseName, string $migrationFile): void
    {
        if (!file_exists($migrationFile)) {
            error_log("Migration file not found: {$migrationFile}");
            return;
        }
        
        $config = config('database.default');
        $user = $config['username'] ?? 'root';
        $pass = $config['password'] ?? '';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        
        $mysqlBin = $this->findMysqlBin();
        
        if (!$mysqlBin) {
            $this->runMigrationViaPdo($databaseName, $migrationFile);
            return;
        }
        
        $command = sprintf(
            '"%s" -u %s -h %s -P %s -D %s',
            $mysqlBin,
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg((string)$port),
            escapeshellarg($databaseName)
        );
        
        if (!empty($pass)) {
            $command .= ' -p' . escapeshellarg($pass);
        }
        
        $command .= ' < ' . escapeshellarg($migrationFile) . ' 2>&1';
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            error_log("Migration failed for {$databaseName} (" . basename($migrationFile) . "): " . implode("\n", $output));
            $this->runMigrationViaPdo($databaseName, $migrationFile);
        } else {
            error_log("Migration executed for tenant {$databaseName}: " . basename($migrationFile));
        }
    }
    
    /**
     * Buscar mysql.exe en ubicaciones comunes
     */
    private function findMysqlBin(): ?string
    {
        $paths = [
            'c:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // Intentar con 'where mysql' en Windows
        $output = [];
        exec('where mysql 2>nul', $output);
        if (!empty($output[0]) && file_exists($output[0])) {
            return $output[0];
        }
        
        return null;
    }
    
    /**
     * Fallback: ejecutar migración via PDO (splitting SQL)
     */
    private function runMigrationViaPdo(string $databaseName, string $migrationFile): void
    {
        $sql = file_get_contents($migrationFile);
        
        // Remover comentarios y líneas vacías
        $lines = explode("\n", $sql);
        $cleanSql = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) || str_starts_with($trimmed, '--')) {
                continue;
            }
            $cleanSql .= $line . "\n";
        }
        
        $this->masterConnection->exec("USE `{$databaseName}`");
        
        // Dividir por ; respetando strings
        $statements = preg_split('/;\s*\n/', $cleanSql);
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $this->masterConnection->exec($statement);
                } catch (\PDOException $e) {
                    error_log("Migration PDO warning: " . $e->getMessage());
                }
            }
        }
        
        // Volver a la BD maestra
        $masterConfig = config('database.default');
        if (!empty($masterConfig['database'])) {
            $this->masterConnection->exec("USE `{$masterConfig['database']}`");
        }
        
        error_log("Migration (PDO fallback) executed for tenant: {$databaseName}");
    }
    
    /**
     * Obtener conexión PDO para un tenant específico
     * Usa las credenciales maestras en lugar de usuarios individuales
     */
    public function getTenantConnection(string $databaseName, string $databaseUser = '', string $databasePassword = ''): \PDO
    {
        $key = $databaseName;
        
        if (isset($this->tenantConnections[$key])) {
            return $this->tenantConnections[$key];
        }
        
        $config = config('database.default');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $databaseName,
            $config['charset'] ?? 'utf8mb4'
        );
        
        // Usar credenciales maestras para conectar a la BD del tenant
        $this->tenantConnections[$key] = new \PDO(
            $dsn,
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        
        return $this->tenantConnections[$key];
    }
    
    /**
     * Remover base de datos del tenant (cuando se elimina)
     */
    public function dropTenantDatabase(string $databaseName, string $databaseUser = ''): bool
    {
        try {
            $this->masterConnection->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
            
            unset($this->tenantConnections[$databaseName]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error dropping tenant database: " . $e->getMessage());
            return false;
        }
    }
}
