<?php

namespace SoftNova\Services;

/**
 * Auditoría local (tabla audit_logs en BD de la empresa).
 */
class TenantAuditService
{
    public function __construct(private \PDO $db)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->db->exec(
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
        } catch (\Throwable $e) {
            // ignore
        }
        $done = true;
    }

    public function log(
        string $action,
        string $module,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null
    ): void {
        try {
            $userId = (int)($_SESSION['tenant_user_id'] ?? 0) ?: null;
            $userName = (string)($_SESSION['tenant_user_name'] ?? 'Sistema');
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs
                    (user_id, user_name, action, module, entity_type, entity_id, description, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $userName, $action, $module, $entityType, $entityId, $description, $ip, $ua]);
        } catch (\Throwable $e) {
            error_log('TenantAudit: ' . $e->getMessage());
        }
    }
}
