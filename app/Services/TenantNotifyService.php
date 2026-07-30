<?php

namespace SoftNova\Services;

/**
 * Notificaciones locales (campana) + webhook de venta cancelada/fallida.
 */
class TenantNotifyService
{
    public static function ensureSchema(\PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `tenant_notifications` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `notif_key` VARCHAR(80) NOT NULL,
                    `type` VARCHAR(40) NOT NULL DEFAULT 'system',
                    `title` VARCHAR(160) NOT NULL,
                    `message` VARCHAR(500) NOT NULL,
                    `url` VARCHAR(255) NULL,
                    `urgent` TINYINT(1) NOT NULL DEFAULT 0,
                    `meta` VARCHAR(120) NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_notif_key` (`notif_key`),
                    KEY `idx_notif_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            // ignore
        }
        $done = true;
    }

    public static function push(
        \PDO $db,
        string $key,
        string $title,
        string $message,
        string $url = '/app/ventas',
        bool $urgent = false,
        string $type = 'sale_alert',
        ?string $meta = null
    ): void {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare(
                "INSERT INTO tenant_notifications (notif_key, type, title, message, url, urgent, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    message = VALUES(message),
                    url = VALUES(url),
                    urgent = VALUES(urgent),
                    meta = VALUES(meta),
                    created_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                mb_substr($key, 0, 80),
                $type,
                mb_substr($title, 0, 160),
                mb_substr($message, 0, 500),
                $url,
                $urgent ? 1 : 0,
                $meta,
            ]);
        } catch (\Throwable $e) {
            error_log('TenantNotify push: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function recent(\PDO $db, int $limit = 8): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare(
                "SELECT notif_key, type, title, message, url, urgent, meta, created_at
                 FROM tenant_notifications
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (string)$r['notif_key'],
                    'type' => $r['type'] ?: 'system',
                    'title' => $r['title'],
                    'message' => $r['message'],
                    'meta' => $r['meta'] ?? '',
                    'url' => $r['url'] ?: '/app/dashboard',
                    'time' => $r['created_at'],
                    'urgent' => !empty($r['urgent']),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function saleFailedOrCancelled(
        \PDO $db,
        string $event,
        string $invoiceOrMsg,
        ?int $saleId = null,
        array $extra = []
    ): void {
        $title = $event === 'cancelled' ? 'Venta cancelada' : 'Venta fallida';
        $message = $invoiceOrMsg;
        $key = ($event === 'cancelled' ? 'sale_cancel:' : 'sale_fail:') . ($saleId ?: time());
        self::push($db, $key, $title, $message, '/app/ventas', true, 'sale_alert', strtoupper($event));

        $webhook = SettingsCache::get($db, 'webhook_sale_url', '');
        if ($webhook !== '' && filter_var($webhook, FILTER_VALIDATE_URL)) {
            try {
                (new JobQueue($db))->push('webhook', [
                    'url' => $webhook,
                    'body' => array_merge([
                        'event' => $event === 'cancelled' ? 'sale.cancelled' : 'sale.failed',
                        'sale_id' => $saleId,
                        'message' => $invoiceOrMsg,
                        'at' => date('c'),
                        'company' => SettingsCache::get($db, 'company_name', ''),
                    ], $extra),
                ]);
            } catch (\Throwable $e) {
                error_log('webhook enqueue: ' . $e->getMessage());
            }
        }
    }
}
