<?php

namespace SoftNova\Services;

/**
 * Cola de jobs simple (tabla jobs) para standalone.
 */
class JobQueue
{
    public function __construct(private \PDO $db)
    {
        $this->ensureSchema();
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->db->exec(
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
        } catch (\Throwable $e) {
            // ignore
        }
        $done = true;
    }

    public function push(string $jobType, array $payload = [], string $queue = 'default', ?int $delaySeconds = 0): int
    {
        $available = date('Y-m-d H:i:s', time() + max(0, (int)$delaySeconds));
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0) ?: null;
        $this->query(
            "INSERT INTO jobs (queue, job_type, payload, status, available_at, created_by)
             VALUES (?, ?, ?, 'pending', ?, ?)",
            [$queue, $jobType, json_encode($payload, JSON_UNESCAPED_UNICODE), $available, $userId]
        );
        return (int)$this->db->lastInsertId();
    }

    public function pendingCount(string $queue = 'default'): int
    {
        return (int)$this->query(
            "SELECT COUNT(*) FROM jobs WHERE queue = ? AND status = 'pending' AND available_at <= NOW()",
            [$queue]
        )->fetchColumn();
    }

    /**
     * @return array{processed:int,failed:int,done:list<string>}
     */
    public function process(int $limit = 10, string $queue = 'default'): array
    {
        $processed = 0;
        $failed = 0;
        $done = [];

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->claimNext($queue);
            if (!$job) {
                break;
            }
            try {
                $this->runJob($job);
                $this->query(
                    "UPDATE jobs SET status = 'done', finished_at = NOW(), last_error = NULL WHERE id = ?",
                    [(int)$job['id']]
                );
                $processed++;
                $done[] = $job['job_type'] . '#' . $job['id'];
            } catch (\Throwable $e) {
                $attempts = (int)$job['attempts'];
                $max = (int)$job['max_attempts'];
                $status = $attempts >= $max ? 'failed' : 'pending';
                $avail = $status === 'pending'
                    ? date('Y-m-d H:i:s', time() + min(300, 10 * $attempts))
                    : date('Y-m-d H:i:s');
                $this->query(
                    "UPDATE jobs SET status = ?, last_error = ?, available_at = ?, reserved_at = NULL WHERE id = ?",
                    [$status, mb_substr($e->getMessage(), 0, 2000), $avail, (int)$job['id']]
                );
                $failed++;
                error_log('JobQueue #' . $job['id'] . ': ' . $e->getMessage());
            }
        }

        return compact('processed', 'failed', 'done');
    }

    private function claimNext(string $queue): ?array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM jobs
                 WHERE queue = ? AND status = 'pending' AND available_at <= NOW()
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$queue]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$job) {
                $this->db->commit();
                return null;
            }
            $this->query(
                "UPDATE jobs SET status = 'processing', attempts = attempts + 1, reserved_at = NOW() WHERE id = ?",
                [(int)$job['id']]
            );
            $this->db->commit();
            $job['attempts'] = (int)$job['attempts'] + 1;
            return $job;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function runJob(array $job): void
    {
        $type = (string)$job['job_type'];
        $payload = json_decode((string)($job['payload'] ?? '{}'), true) ?: [];
        $handler = new JobHandlers($this->db);
        match ($type) {
            'backup' => $handler->backup($payload),
            'import_csv' => $handler->importCsv($payload),
            'webhook' => $handler->webhook($payload),
            default => throw new \RuntimeException('Tipo de job desconocido: ' . $type),
        };
    }
}
