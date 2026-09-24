<?php

namespace SoftNova\Core;

/**
 * Clase para manejar conexiones a base de datos
 */

class Database
{
    private static ?Database $instance = null;
    private ?\PDO $connection = null;
    
    private function __construct()
    {
        $this->connect();
    }
    
    /**
     * Obtener instancia singleton
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Conectar a base de datos
     */
    private function connect(): void
    {
        $config = config('database.default', []);
        
        if (empty($config['database'])) {
            return;
        }

        $dbName = $this->resolveDatabaseName($config);
        if ($dbName === '') {
            return;
        }
        
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $dbName,
            $config['charset']
        );
        
        try {
            $this->connection = new \PDO($dsn, $config['username'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => BusinessTimezone::mysqlInitCommand(),
            ]);
        } catch (\PDOException $e) {
            error_log('Error de conexión a base de datos (' . $dbName . '): ' . $e->getMessage());
            // Si falló el espejo de sandbox, volver a producción para no dejar el panel caído.
            if (
                !empty($_SESSION['sandbox_mode'])
                && isset($config['database'])
                && strcasecmp($dbName, (string)$config['database']) !== 0
            ) {
                unset($_SESSION['sandbox_mode'], $_SESSION['sandbox_db_name']);
                try {
                    $fallback = sprintf(
                        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                        $config['host'],
                        $config['port'],
                        $config['database'],
                        $config['charset']
                    );
                    $this->connection = new \PDO($fallback, $config['username'], $config['password'], [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_EMULATE_PREPARES => false,
                        \PDO::MYSQL_ATTR_INIT_COMMAND => BusinessTimezone::mysqlInitCommand(),
                    ]);
                    error_log('Sandbox caído: reconectado a producción');
                } catch (\PDOException $e2) {
                    error_log('Error de conexión a producción tras fallo sandbox: ' . $e2->getMessage());
                }
            }
        }
    }

    /**
     * Nombre activo: producción o espejo de caja de arena (sesión).
     *
     * @param array<string,mixed> $config
     */
    private function resolveDatabaseName(array $config): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($config['database'] ?? '')) ?: '';
        if (
            !empty($_SESSION['sandbox_mode'])
            && !empty($_SESSION['sandbox_db_name'])
            && class_exists(\SoftNova\Services\SandboxService::class)
        ) {
            $sb = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_SESSION['sandbox_db_name']);
            if ($sb !== '') {
                return $sb;
            }
        }
        return $base;
    }
    
    /**
     * Ejecutar query
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        if ($this->connection === null) {
            throw new \Exception('No hay conexión a base de datos');
        }
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Obtener conexión PDO
     */
    public function getConnection(): ?\PDO
    {
        return $this->connection;
    }

    /** Cierra y abre de nuevo (p. ej. tras llamadas HTTP largas). */
    public function reconnect(): void
    {
        $this->connection = null;
        $this->connect();
    }
    
    /**
     * Iniciar transacción
     */
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Confirmar transacción
     */
    public function commit(): bool
    {
        return $this->connection->commit();
    }
    
    /**
     * Revertir transacción
     */
    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }
    
    /**
     * Obtener último ID insertado
     */
    public function lastInsertId(): string|false
    {
        return $this->connection->lastInsertId();
    }
}
