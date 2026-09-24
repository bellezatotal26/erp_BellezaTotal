<?php

namespace SoftNova\Services;

/**
 * Borrado general de la BD del tenant: limpia datos operativos y deja
 * solo el administrador actual, preferencias y el catálogo contable (PUC).
 */
class DatabaseWipeService
{
    /** Tablas que NUNCA se vacían. */
    private const PRESERVE_TABLES = [
        'settings',
        'accounting_accounts',
        'accounting_settings',
    ];

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{truncated:int,users_deleted:int,kept_user_id:int,kept_user_email:string}
     */
    public function wipeKeepAdmin(int $keepUserId): array
    {
        if ($keepUserId <= 0) {
            throw new \RuntimeException('Usuario administrador no válido');
        }

        $admin = $this->query(
            "SELECT id, email, role, status FROM users WHERE id = ? LIMIT 1",
            [$keepUserId]
        )->fetch();
        if (!$admin) {
            throw new \RuntimeException('No se encontró el usuario administrador actual');
        }
        if (($admin['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Solo un administrador puede ejecutar la limpieza');
        }

        $tables = $this->listTables();
        $truncated = 0;

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $key = strtolower($table);
                if ($key === 'users' || in_array($key, self::PRESERVE_TABLES, true)) {
                    continue;
                }
                if ($this->truncateTable($table)) {
                    $truncated++;
                }
            }

            // Conserva solo el admin que ejecuta la acción (misma contraseña / sesión).
            $del = $this->query(
                "DELETE FROM users WHERE id <> ?",
                [$keepUserId]
            );
            $usersDeleted = $del->rowCount();

            $this->query(
                "UPDATE users
                 SET role = 'admin', status = 'active', updated_at = NOW()
                 WHERE id = ?",
                [$keepUserId]
            );

            $this->resetSequenceSettings();
        } finally {
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        SettingsCache::invalidate();

        return [
            'truncated' => $truncated,
            'users_deleted' => $usersDeleted,
            'kept_user_id' => $keepUserId,
            'kept_user_email' => (string)($admin['email'] ?? ''),
        ];
    }

    /** @return list<string> */
    private function listTables(): array
    {
        $rows = $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM);
        $out = [];
        foreach ($rows as $row) {
            $name = (string)($row[0] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function truncateTable(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        try {
            $this->db->exec('TRUNCATE TABLE `' . $table . '`');
            return true;
        } catch (\Throwable $e) {
            // Fallback por si TRUNCATE falla por FKs residuales.
            try {
                $this->db->exec('DELETE FROM `' . $table . '`');
                return true;
            } catch (\Throwable $e2) {
                error_log('DatabaseWipeService: no se pudo vaciar ' . $table . ': ' . $e2->getMessage());
                return false;
            }
        }
    }

    private function resetSequenceSettings(): void
    {
        $resets = [
            'ean_next' => '1',
            'barcode_next' => '1',
            'sku_next' => '1',
            'invoice_next' => '1',
            'quote_next' => '1',
            'purchase_order_next' => '1',
            'sale_next' => '1',
        ];
        foreach ($resets as $key => $value) {
            $exists = $this->query(
                "SELECT id FROM settings WHERE setting_key = ? LIMIT 1",
                [$key]
            )->fetch();
            if ($exists) {
                $this->query(
                    "UPDATE settings SET setting_value = ? WHERE setting_key = ?",
                    [$value, $key]
                );
            } else {
                $this->query(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)",
                    [$key, $value]
                );
            }
        }

        // Reinicia AUTO_INCREMENT en tablas vaciadas (IDs desde 1).
        try {
            $rows = $this->db->query(
                "SELECT TABLE_NAME
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_TYPE = 'BASE TABLE'
                   AND AUTO_INCREMENT IS NOT NULL"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $table) {
                $table = (string)$table;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    continue;
                }
                if (strtolower($table) === 'users') {
                    continue; // conserva secuencia del admin
                }
                try {
                    $this->db->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
                } catch (\Throwable $e) {
                    // ignora tablas no alterables
                }
            }
        } catch (\Throwable $e) {
            error_log('DatabaseWipeService AUTO_INCREMENT: ' . $e->getMessage());
        }
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
