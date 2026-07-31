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
        
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );
        
        try {
            $this->connection = new \PDO($dsn, $config['username'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            error_log('Error de conexión a base de datos: ' . $e->getMessage());
        }
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
