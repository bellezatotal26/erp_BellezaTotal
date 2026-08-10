<?php

namespace SoftNova\Services;

/**
 * Handlers de jobs (backup, import CSV, webhook).
 */
class JobHandlers
{
    public function __construct(private \PDO $db)
    {
    }

    public function backup(array $payload): void
    {
        $dbName = (string)($payload['database'] ?? ($_SESSION['tenant_db_name'] ?? ''));
        if ($dbName === '') {
            $dbName = (string)(\SoftNova\Core\config('database.default.database', ''));
        }
        if ($dbName === '') {
            throw new \RuntimeException('BD no configurada para backup');
        }
        $svc = new TenantBackupService();
        $result = $svc->createBackup($dbName, (string)($payload['type'] ?? 'manual'));
        if (empty($result['success'])) {
            throw new \RuntimeException($result['message'] ?? 'Backup falló');
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('backup_last_run', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([date('Y-m-d H:i:s')]);
            SettingsCache::invalidate();
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function importCsv(array $payload): void
    {
        $path = (string)($payload['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Archivo CSV no encontrado');
        }
        $importer = new ProductCsvImporter($this->db);
        $result = $importer->importFromFile($path, (int)($payload['user_id'] ?? 0));
        @unlink($path);
        TenantNotifyService::push(
            $this->db,
            'import:' . time(),
            'Inventario',
            sprintf(
                'Importación CSV: %d creados, %d actualizados, %d remesas, %d movimientos, %d omitidos',
                $result['created'],
                $result['updated'],
                (int)($result['remesas'] ?? 0),
                (int)($result['movements'] ?? 0),
                $result['skipped']
            ),
            '/app/inventario',
            false,
            'import'
        );
    }

    public function webhook(array $payload): void
    {
        $url = trim((string)($payload['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('URL de webhook inválida');
        }
        $body = json_encode($payload['body'] ?? [], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            throw new \RuntimeException('Webhook HTTP ' . $code . ($err ? (': ' . $err) : ''));
        }
    }
}
