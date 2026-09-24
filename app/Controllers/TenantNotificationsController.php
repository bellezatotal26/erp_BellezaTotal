<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Core\Database;
use SoftNova\Services\TenantBackupService;

/**
 * Notificaciones del header tenant (JSON)
 */
class TenantNotificationsController extends TenantController
{
    public function index(): void
    {
        TenantMiddleware::auth();
        
        $action = $this->request->get('action') ?? $this->request->post('action');
        
        if ($this->request->method() === 'POST') {
            if ($action === 'dismiss') {
                $this->dismissOne();
                return;
            }
            if ($action === 'clear') {
                $this->clearAll();
                return;
            }
        }

        // Keep-alive ligero para Caja abierta todo el día (sin recargar).
        if ($action === 'ping') {
            $this->pingSession();
            return;
        }
        
        $this->maybeTriggerAutoBackup();
        $this->listNotifications();
    }

    /** Renueva sesión + CSRF sin carga de notificaciones. */
    private function pingSession(): void
    {
        $authed = !empty($_SESSION['tenant_authenticated']);
        if (!$authed) {
            $this->json([
                'success' => false,
                'authenticated' => false,
                'message' => 'Sesión no autenticada',
                'csrf_token' => \SoftNova\Core\csrf_token(),
            ], 401);
            return;
        }
        $this->json([
            'success' => true,
            'authenticated' => true,
            'csrf_token' => \SoftNova\Core\csrf_token(),
            'server_time' => date('c'),
        ]);
    }
    
    private function listNotifications(): void
    {
        $items = $this->collectItems();
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $dismissed = $this->getDismissedKeys($userId);
        
        $visible = [];
        $popups = [];
        
        foreach ($items as $item) {
            $key = $item['id'] ?? '';
            if ($key !== '' && isset($dismissed[$key])) {
                continue;
            }
            
            if (($item['type'] ?? '') === 'news' && !empty($item['popup'])) {
                $popups[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'message' => $item['full_message'] ?? $item['message'],
                    'meta' => $item['meta'] ?? '',
                    'priority' => $item['priority'] ?? 'normal',
                ];
            }
            
            unset($item['full_message'], $item['popup'], $item['priority']);
            $visible[] = $item;
        }
        
        $visible = array_slice($visible, 0, 12);
        
        $badge = 0;
        foreach ($visible as $item) {
            if (!empty($item['urgent']) || in_array($item['type'], ['ticket', 'news'], true)) {
                $badge++;
            }
        }
        
        $this->json([
            'success' => true,
            'count' => count($visible),
            'badge' => $badge,
            'csrf_token' => \SoftNova\Core\csrf_token(),
            'popups' => array_slice($popups, 0, 1),
            'items' => array_map(function ($item) {
                $item['time_label'] = $this->relativeTime($item['time'] ?? null);
                $item['url'] = \SoftNova\Core\base_url(ltrim($item['url'], '/'));
                return $item;
            }, $visible),
        ]);
    }
    
    private function dismissOne(): void
    {
        if (!$this->validateCsrf()) {
            $this->json(['success' => false, 'message' => 'Token CSRF invalido'], 403);
            return;
        }
        
        $key = trim((string)($this->request->post('item_key') ?? ''));
        if ($key === '' || !preg_match('/^[a-z]+:[a-z0-9:_-]+$/i', $key)) {
            $this->json(['success' => false, 'message' => 'Notificacion invalida']);
            return;
        }
        
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        if ($userId <= 0) {
            $this->json(['success' => false, 'message' => 'Sesion invalida'], 401);
            return;
        }
        
        $this->ensureDismissalsTable();
        $this->query(
            "INSERT IGNORE INTO notification_dismissals (user_id, item_key) VALUES (?, ?)",
            [$userId, $key]
        );
        
        $this->json(['success' => true, 'message' => 'Notificacion eliminada']);
    }
    
    private function clearAll(): void
    {
        if (!$this->validateCsrf()) {
            $this->json(['success' => false, 'message' => 'Token CSRF invalido'], 403);
            return;
        }
        
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        if ($userId <= 0) {
            $this->json(['success' => false, 'message' => 'Sesion invalida'], 401);
            return;
        }
        
        $this->ensureDismissalsTable();
        $items = $this->collectItems();
        
        foreach ($items as $item) {
            $key = $item['id'] ?? '';
            if ($key === '') {
                continue;
            }
            $this->query(
                "INSERT IGNORE INTO notification_dismissals (user_id, item_key) VALUES (?, ?)",
                [$userId, $key]
            );
        }
        
        $this->json(['success' => true, 'message' => 'Notificaciones limpiadas']);
    }
    
    private function collectItems(): array
    {
        $items = [];
        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        
        try {
            $master = Database::getInstance();
            $this->ensureAnnouncementsTable($master);
            
            if ($tenantId > 0) {
                $tickets = $master->query(
                    "SELECT id, ticket_code, subject, status, priority, updated_at, created_at
                     FROM tickets
                     WHERE tenant_id = ? AND status IN ('open', 'in_progress')
                     ORDER BY updated_at DESC
                     LIMIT 5",
                    [$tenantId]
                )->fetchAll();
                
                foreach ($tickets as $t) {
                    $items[] = [
                        'id' => 'ticket:' . (int)$t['id'],
                        'type' => 'ticket',
                        'title' => 'Ticket: ' . ($t['ticket_code'] ?? '#' . $t['id']),
                        'message' => $t['subject'] ?? 'Soporte',
                        'meta' => strtoupper($t['status'] ?? '') . ' · ' . strtoupper($t['priority'] ?? ''),
                        'url' => '/app/soporte',
                        'time' => $t['updated_at'] ?? $t['created_at'],
                        'urgent' => in_array($t['priority'] ?? '', ['high', 'urgent'], true),
                    ];
                }
            }
            
            $news = $master->query(
                "SELECT id, title, body, priority, published_at
                 FROM announcements
                 WHERE status = 'active'
                 ORDER BY published_at DESC
                 LIMIT 5"
            )->fetchAll();
            
            foreach ($news as $n) {
                $body = (string)($n['body'] ?? '');
                $items[] = [
                    'id' => 'news:' . (int)$n['id'],
                    'type' => 'news',
                    'title' => $n['title'] ?? 'Aviso',
                    'message' => mb_substr(strip_tags($body), 0, 120),
                    'full_message' => $body,
                    'meta' => ($n['priority'] ?? '') === 'important' ? 'Importante' : 'Noticia',
                    'url' => '/app/dashboard',
                    'time' => $n['published_at'],
                    'urgent' => ($n['priority'] ?? '') === 'important',
                    'popup' => $this->isRecentAnnouncement($n['published_at'] ?? null),
                    'priority' => $n['priority'] ?? 'normal',
                ];
            }
        } catch (\Throwable $e) {
            error_log('notifications master: ' . $e->getMessage());
        }
        
        try {
            $sales = $this->query(
                "SELECT id, invoice_number, total, sale_date, status
                 FROM sales
                 WHERE status IN ('completed', 'pending')
                 ORDER BY sale_date DESC
                 LIMIT 5"
            )->fetchAll();
            
            foreach ($sales as $s) {
                $items[] = [
                    'id' => 'sale:' . (int)$s['id'],
                    'type' => 'sale',
                    'title' => 'Venta ' . ($s['invoice_number'] ?? '#' . $s['id']),
                    'message' => 'Total: ' . $this->formatMoney((float)$s['total']),
                    'meta' => $s['status'] === 'completed' ? 'Completada' : 'Pendiente',
                    'url' => '/app/ventas',
                    'time' => $s['sale_date'],
                    'urgent' => false,
                ];
            }
        } catch (\Throwable $e) {
            error_log('notifications sales: ' . $e->getMessage());
        }
        
        try {
            $movements = $this->query(
                "SELECT sm.id, sm.type, sm.quantity, sm.notes, sm.created_at, p.name as product_name
                 FROM stock_movements sm
                 LEFT JOIN products p ON sm.product_id = p.id
                 ORDER BY sm.created_at DESC
                 LIMIT 5"
            )->fetchAll();
            
            foreach ($movements as $m) {
                $typeLabel = match ($m['type'] ?? '') {
                    'in' => 'Entrada',
                    'out' => 'Salida',
                    default => 'Ajuste',
                };
                $items[] = [
                    'id' => 'inventory:mov:' . (int)$m['id'],
                    'type' => 'inventory',
                    'title' => 'Inventario: ' . ($m['product_name'] ?? 'Producto'),
                    'message' => $typeLabel . ' de ' . (int)$m['quantity'] . ' uds'
                        . (!empty($m['notes']) ? ' — ' . mb_substr($m['notes'], 0, 60) : ''),
                    'meta' => 'Movimiento',
                    'url' => '/app/inventario',
                    'time' => $m['created_at'],
                    'urgent' => false,
                ];
            }
            
            $lowStock = $this->query(
                "SELECT id, name, stock, min_stock
                 FROM products
                 WHERE status = 'active' AND product_type = 'product' AND stock <= min_stock
                 ORDER BY (min_stock - stock) DESC
                 LIMIT 3"
            )->fetchAll();
            
            foreach ($lowStock as $p) {
                $items[] = [
                    'id' => 'inventory:low:' . (int)$p['id'],
                    'type' => 'inventory',
                    'title' => 'Stock bajo: ' . ($p['name'] ?? 'Producto'),
                    'message' => 'Disponible: ' . (int)$p['stock'] . ' (minimo: ' . (int)$p['min_stock'] . ')',
                    'meta' => 'Alerta',
                    'url' => '/app/inventario',
                    'time' => date('Y-m-d H:i:s'),
                    'urgent' => true,
                ];
            }
        } catch (\Throwable $e) {
            error_log('notifications inventory: ' . $e->getMessage());
        }
        
        try {
            foreach (\SoftNova\Services\TenantNotifyService::recent($this->db, 8) as $n) {
                $items[] = $n;
            }
        } catch (\Throwable $e) {
            error_log('notifications tenant_notify: ' . $e->getMessage());
        }

        usort($items, static function ($a, $b) {
            return strtotime((string)($b['time'] ?? 'now')) <=> strtotime((string)($a['time'] ?? 'now'));
        });
        
        return $items;
    }
    
    private function getDismissedKeys(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $this->ensureDismissalsTable();
            $rows = $this->query(
                "SELECT item_key FROM notification_dismissals WHERE user_id = ?",
                [$userId]
            )->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $map[$row['item_key']] = true;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    private function ensureDismissalsTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->query("SELECT 1 FROM notification_dismissals LIMIT 0");
        } catch (\Throwable $e) {
            $pdo = TenantMiddleware::getDb();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS notification_dismissals (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    item_key VARCHAR(120) NOT NULL,
                    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_user_item (user_id, item_key),
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    }
    
    private function maybeTriggerAutoBackup(): void
    {
        try {
            $dbName = (string)($_SESSION['tenant_db_name'] ?? '');
            if ($dbName === '') {
                return;
            }
            $settings = [];
            $rows = $this->query(
                "SELECT setting_key, setting_value FROM settings
                 WHERE setting_key IN ('backup_enabled','backup_time','backup_last_run')"
            )->fetchAll();
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            $svc = new TenantBackupService();
            $result = $svc->maybeAutoBackup($dbName, $settings);
            $hints = TenantBackupService::consumeSettingHints();
            foreach ($hints as $k => $v) {
                $this->upsertSetting((string)$k, (string)$v);
            }
            if ($result && !empty($result['success'])) {
                $this->upsertSetting('backup_last_run', date('Y-m-d H:i:s'));
            }
        } catch (\Throwable $e) {
            error_log('auto-backup: ' . $e->getMessage());
        }
    }
    
    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->query("SELECT id FROM settings WHERE setting_key = ?", [$key])->fetch();
        if ($existing) {
            $this->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        } else {
            $this->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
        }
    }
    
    private function ensureAnnouncementsTable(Database $master): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $master->query("SELECT 1 FROM announcements LIMIT 0");
        } catch (\Throwable $e) {
            $master->getConnection()->exec(
                "CREATE TABLE IF NOT EXISTS announcements (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    body TEXT NOT NULL,
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    priority ENUM('normal', 'important') DEFAULT 'normal',
                    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    created_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_published (published_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    }
    
    private function isRecentAnnouncement(?string $publishedAt): bool
    {
        if (empty($publishedAt)) {
            return false;
        }
        $ts = strtotime($publishedAt);
        if (!$ts) {
            return false;
        }
        // Ventana emergente solo para anuncios de los ultimos 7 dias
        return (time() - $ts) <= (7 * 86400);
    }
    
    private function relativeTime(?string $datetime): string
    {
        if (empty($datetime)) {
            return '';
        }
        $ts = strtotime($datetime);
        if (!$ts) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Ahora';
        }
        if ($diff < 3600) {
            return (int)floor($diff / 60) . ' min';
        }
        if ($diff < 86400) {
            return (int)floor($diff / 3600) . ' h';
        }
        if ($diff < 604800) {
            return (int)floor($diff / 86400) . ' d';
        }
        return date('d/m/Y H:i', $ts);
    }
}
