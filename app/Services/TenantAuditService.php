<?php

namespace SoftNova\Services;

/**
 * Auditoría local (tabla audit_logs en BD de la empresa).
 *
 * Cubre ventas, caja, usuarios y también inventario / compras.
 * En el detalle se cruzan entidades relacionadas (venta ↔ stock,
 * factura ↔ entrada de inventario) y se detectan anomalías operativas.
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
                    `severity` VARCHAR(20) NULL,
                    `meta_json` TEXT NULL,
                    `description` TEXT NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `user_agent` VARCHAR(255) NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_audit_module` (`module`),
                    KEY `idx_audit_user` (`user_id`),
                    KEY `idx_audit_created` (`created_at`),
                    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
                    KEY `idx_audit_severity` (`severity`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->migrateLegacyColumns();
        } catch (\Throwable $e) {
            // ignore
        }
        $done = true;
    }

    /** Añade columnas faltantes en instalaciones legacy. */
    private function migrateLegacyColumns(): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }
        $migrated = true;

        $cols = [];
        try {
            foreach ($this->db->query('SHOW COLUMNS FROM audit_logs')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string)$row['Field'])] = true;
            }
        } catch (\Throwable $e) {
            return;
        }

        $alters = [];
        if (!isset($cols['entity_type'])) {
            $alters[] = 'ADD COLUMN `entity_type` VARCHAR(60) NULL AFTER `module`';
        }
        if (!isset($cols['entity_id'])) {
            $alters[] = 'ADD COLUMN `entity_id` INT UNSIGNED NULL AFTER `entity_type`';
        }
        if (!isset($cols['severity'])) {
            $alters[] = 'ADD COLUMN `severity` VARCHAR(20) NULL AFTER `entity_id`';
        }
        if (!isset($cols['meta_json'])) {
            $alters[] = 'ADD COLUMN `meta_json` TEXT NULL AFTER `severity`';
        }
        if ($alters === []) {
            return;
        }

        try {
            $this->db->exec('ALTER TABLE audit_logs ' . implode(', ', $alters));
        } catch (\Throwable $e) {
            error_log('TenantAudit migrate: ' . $e->getMessage());
        }

        try {
            $idx = $this->db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_audit_entity'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE audit_logs ADD INDEX idx_audit_entity (entity_type, entity_id)');
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function log(
        string $action,
        string $module,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        array $meta = [],
        ?string $severity = null
    ): void {
        try {
            $userId = (int)($_SESSION['tenant_user_id'] ?? 0) ?: null;
            $userName = (string)($_SESSION['tenant_user_name'] ?? 'Sistema');
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
            $severity = $severity !== null && $severity !== '' ? substr($severity, 0, 20) : null;
            $metaJson = $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

            if ($this->hasMetaColumns()) {
                $stmt = $this->db->prepare(
                    "INSERT INTO audit_logs
                        (user_id, user_name, action, module, entity_type, entity_id, severity, meta_json,
                         description, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $userId, $userName, $action, $module, $entityType, $entityId,
                    $severity, $metaJson, $description, $ip, $ua,
                ]);
                return;
            }

            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs
                    (user_id, user_name, action, module, entity_type, entity_id, description, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $userName, $action, $module, $entityType, $entityId, $description, $ip, $ua]);
        } catch (\Throwable $e) {
            try {
                $userId = (int)($_SESSION['tenant_user_id'] ?? 0) ?: null;
                $userName = (string)($_SESSION['tenant_user_name'] ?? 'Sistema');
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
                $stmt = $this->db->prepare(
                    "INSERT INTO audit_logs
                        (user_id, user_name, action, module, description, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $desc = $description;
                if ($entityType !== null && $entityId !== null) {
                    $desc = trim($description . ' [' . $entityType . ' #' . $entityId . ']');
                }
                $stmt->execute([$userId, $userName, $action, $module, $desc, $ip, $ua]);
            } catch (\Throwable $e2) {
                error_log('TenantAudit: ' . $e2->getMessage());
            }
        }
    }

    /** Atajo seguro: nunca lanza. */
    public static function safeLog(
        \PDO $db,
        string $action,
        string $module,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        array $meta = [],
        ?string $severity = null
    ): void {
        try {
            (new self($db))->log($action, $module, $description, $entityType, $entityId, $meta, $severity);
        } catch (\Throwable $e) {
            error_log('TenantAudit safeLog: ' . $e->getMessage());
        }
    }

    /** @return array<string,string> */
    public static function moduleLabels(): array
    {
        return [
            'ventas' => 'Ventas',
            'caja' => 'Caja',
            'gastos' => 'Gastos',
            'compras' => 'Compras',
            'inventario' => 'Inventario',
            'prestamos' => 'Préstamos',
            'contabilidad' => 'Contabilidad',
            'nomina' => 'Nómina',
            'configuracion' => 'Configuración',
            'usuarios' => 'Usuarios',
            'clientes' => 'Clientes',
            'proveedores' => 'Proveedores',
        ];
    }

    /** @return array<string,string> */
    public static function actionLabels(): array
    {
        return [
            'create' => 'Creación',
            'update' => 'Actualización',
            'delete' => 'Eliminación',
            'payment' => 'Pago / abono',
            'open' => 'Apertura',
            'close' => 'Cierre',
            'movement' => 'Movimiento',
            'receive' => 'Recepción / factura',
            'sync' => 'Sincronización',
            'export' => 'Exportación',
            'import' => 'Importación',
            'cancel' => 'Anulación',
            'pay' => 'Pago / contabilización',
            'edit' => 'Edición',
            'adjust' => 'Ajuste',
            'anomaly' => 'Anomalía',
            'note' => 'Observación',
        ];
    }

    public static function moduleLabel(string $module): string
    {
        $labels = self::moduleLabels();
        return $labels[$module] ?? ucfirst(str_replace('_', ' ', $module));
    }

    public static function actionLabel(string $action): string
    {
        $labels = self::actionLabels();
        return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    public static function entityUrl(?string $entityType, ?int $entityId): ?string
    {
        if ($entityType === null || $entityType === '' || ($entityId ?? 0) <= 0) {
            return null;
        }

        $path = match ($entityType) {
            'expense' => '/app/gastos',
            'sale', 'sale_payment' => '/app/ventas',
            'sale_credit_note' => '/app/ventas?action=notas_credito',
            'cash_session', 'cash_movement' => '/app/caja',
            'user' => '/app/configuracion#usuarios',
            'product', 'stock_movement' => '/app/inventario',
            'purchase_order', 'purchase_invoice', 'purchase_credit_note', 'supplier_payment' => '/app/compras?action=facturas',
            'accounting_entry' => '/app/contabilidad?tab=entries',
            'payroll_run' => '/app/nomina?tab=runs&action=run_detail&id=',
            default => null,
        };
        if ($path === null) {
            return null;
        }

        if ($entityType === 'payroll_run') {
            $path .= $entityId;
        }

        return function_exists('\\SoftNova\\Core\\base_url')
            ? \SoftNova\Core\base_url($path)
            : $path;
    }

    /**
     * @param array{module?:string,action?:string,user_id?:int,q?:string,from?:string,to?:string,severity?:string} $filters
     * @return array{items:list<array<string,mixed>>,pagination:array<string,int>,filters:array<string,mixed>}
     */
    public function listLogs(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];

        $module = trim((string)($filters['module'] ?? ''));
        if ($module !== '') {
            $where[] = 'module = ?';
            $params[] = $module;
        }

        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'action = ?';
            $params[] = $action;
        }

        $userId = (int)($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }

        $severity = trim((string)($filters['severity'] ?? ''));
        if ($severity !== '' && $this->hasMetaColumns()) {
            $where[] = 'severity = ?';
            $params[] = $severity;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(description LIKE ? OR user_name LIKE ? OR ip_address LIKE ? OR entity_type LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $from = trim((string)($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }

        $to = trim((string)($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        try {
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'pagination' => ['page' => 1, 'perPage' => $perPage, 'total' => 0, 'totalPages' => 1],
                'filters' => $filters,
            ];
        }

        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $select = 'id, user_id, user_name, action, module, description, ip_address, user_agent, created_at';
        if ($this->hasEntityColumns()) {
            $select = 'id, user_id, user_name, action, module, entity_type, entity_id, description, ip_address, user_agent, created_at';
        }
        if ($this->hasMetaColumns()) {
            $select = 'id, user_id, user_name, action, module, entity_type, entity_id, severity, meta_json, description, ip_address, user_agent, created_at';
        }

        $stmt = $this->db->prepare(
            "SELECT {$select}
             FROM audit_logs
             WHERE {$whereSql}
             ORDER BY id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (!$this->hasEntityColumns()) {
            foreach ($items as &$item) {
                $item['entity_type'] = null;
                $item['entity_id'] = null;
                $desc = (string)($item['description'] ?? '');
                if (preg_match('/\[([^\]]+) #(\d+)\]\s*$/u', $desc, $m)) {
                    $item['entity_type'] = $m[1];
                    $item['entity_id'] = (int)$m[2];
                }
            }
            unset($item);
        }

        foreach ($items as &$item) {
            if (!isset($item['severity'])) {
                $item['severity'] = null;
            }
            if (!isset($item['meta_json'])) {
                $item['meta_json'] = null;
            }
        }
        unset($item);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
            'filters' => $filters,
        ];
    }

    /**
     * Detalle enriquecido: log + cadena relacionada (stock, OC, auditoría cruzada).
     *
     * @return array{log:array<string,mixed>,related:list<array<string,mixed>>,anomalies:list<array<string,mixed>>}|null
     */
    public function getDetail(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $row = $this->db->prepare('SELECT * FROM audit_logs WHERE id = ? LIMIT 1');
            $row->execute([$id]);
            $log = $row->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$log) {
            return null;
        }

        $entityType = isset($log['entity_type']) && $log['entity_type'] !== ''
            ? (string)$log['entity_type']
            : null;
        $entityId = isset($log['entity_id']) && $log['entity_id'] !== null && $log['entity_id'] !== ''
            ? (int)$log['entity_id']
            : null;
        if (($entityType === null || $entityType === '') && preg_match('/\[([^\]]+) #(\d+)\]\s*$/u', (string)($log['description'] ?? ''), $m)) {
            $entityType = $m[1];
            $entityId = (int)$m[2];
        }

        $module = (string)($log['module'] ?? '');
        $action = (string)($log['action'] ?? '');
        $meta = [];
        if (!empty($log['meta_json'])) {
            $decoded = json_decode((string)$log['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $enriched = [
            'id' => (int)$log['id'],
            'created_at' => (string)$log['created_at'],
            'user_name' => (string)($log['user_name'] ?? 'Sistema'),
            'user_id' => isset($log['user_id']) && $log['user_id'] !== null ? (int)$log['user_id'] : null,
            'module' => $module,
            'module_label' => self::moduleLabel($module),
            'action' => $action,
            'action_label' => self::actionLabel($action),
            'description' => (string)($log['description'] ?? ''),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_url' => self::entityUrl($entityType, $entityId),
            'severity' => isset($log['severity']) ? (string)$log['severity'] : null,
            'meta' => $meta,
            'ip_address' => (string)($log['ip_address'] ?? ''),
            'user_agent' => (string)($log['user_agent'] ?? ''),
        ];

        $related = $this->relatedEvents($entityType, $entityId, (int)$log['id']);
        $anomalies = $this->anomaliesForEntity($entityType, $entityId);

        return [
            'log' => $enriched,
            'related' => $related,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * @return list<array{source:string,at:string,label:string,detail:string,module?:string,url?:?string}>
     */
    public function relatedEvents(?string $entityType, ?int $entityId, int $excludeAuditId = 0): array
    {
        if ($entityType === null || $entityType === '' || ($entityId ?? 0) <= 0) {
            return [];
        }
        $out = [];
        $entityId = (int)$entityId;

        // Otros eventos de auditoría de la misma entidad.
        try {
            $stmt = $this->db->prepare(
                "SELECT id, created_at, module, action, description, severity
                 FROM audit_logs
                 WHERE entity_type = ? AND entity_id = ? AND id <> ?
                 ORDER BY id DESC
                 LIMIT 12"
            );
            $stmt->execute([$entityType, $entityId, $excludeAuditId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[] = [
                    'source' => 'audit',
                    'at' => (string)$row['created_at'],
                    'label' => self::actionLabel((string)$row['action']) . ' · ' . self::moduleLabel((string)$row['module']),
                    'detail' => (string)($row['description'] ?? ''),
                    'module' => (string)$row['module'],
                    'url' => null,
                    'severity' => (string)($row['severity'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $stockRefs = match ($entityType) {
            'sale' => [['sale', $entityId], ['return', $entityId], ['sale_credit_note', $entityId]],
            'purchase_order', 'purchase_invoice' => [['purchase_order', $entityId], ['purchase', $entityId]],
            'purchase_credit_note' => [['purchase_credit_note', $entityId]],
            'product' => [],
            'stock_movement' => [],
            default => [],
        };

        if ($entityType === 'product') {
            try {
                $stmt = $this->db->prepare(
                    "SELECT sm.id, sm.created_at, sm.type, sm.quantity, sm.reference_type, sm.reference_id, sm.notes, sm.stock_after,
                            p.name AS product_name
                     FROM stock_movements sm
                     LEFT JOIN products p ON p.id = sm.product_id
                     WHERE sm.product_id = ?
                     ORDER BY sm.id DESC
                     LIMIT 10"
                );
                $stmt->execute([$entityId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $out[] = $this->formatStockRelated($row);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        } elseif ($entityType === 'stock_movement') {
            try {
                $stmt = $this->db->prepare(
                    "SELECT sm.id, sm.created_at, sm.type, sm.quantity, sm.reference_type, sm.reference_id, sm.notes, sm.stock_after,
                            p.name AS product_name, sm.product_id
                     FROM stock_movements sm
                     LEFT JOIN products p ON p.id = sm.product_id
                     WHERE sm.id = ?
                     LIMIT 1"
                );
                $stmt->execute([$entityId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $out[] = $this->formatStockRelated($row);
                    $ref = (string)($row['reference_type'] ?? '');
                    $refId = (int)($row['reference_id'] ?? 0);
                    if ($ref !== '' && $refId > 0) {
                        foreach ($this->relatedEvents($this->mapStockRefToEntity($ref), $refId, $excludeAuditId) as $extra) {
                            $out[] = $extra;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        } else {
            foreach ($stockRefs as [$refType, $refId]) {
                try {
                    $stmt = $this->db->prepare(
                        "SELECT sm.id, sm.created_at, sm.type, sm.quantity, sm.reference_type, sm.reference_id, sm.notes, sm.stock_after,
                                p.name AS product_name
                         FROM stock_movements sm
                         LEFT JOIN products p ON p.id = sm.product_id
                         WHERE sm.reference_type = ? AND sm.reference_id = ?
                         ORDER BY sm.id ASC
                         LIMIT 30"
                    );
                    $stmt->execute([$refType, $refId]);
                    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                        $out[] = $this->formatStockRelated($row);
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        if (in_array($entityType, ['purchase_order', 'purchase_invoice'], true)) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT id, payment_date, amount, payment_method, status, created_at
                     FROM supplier_payments
                     WHERE purchase_order_id = ?
                     ORDER BY id DESC LIMIT 8"
                );
                $stmt->execute([$entityId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $out[] = [
                        'source' => 'payment',
                        'at' => (string)($row['created_at'] ?? $row['payment_date'] ?? ''),
                        'label' => 'Abono proveedor',
                        'detail' => 'Pago #' . (int)$row['id'] . ' · ' . number_format((float)$row['amount'], 0, ',', '.')
                            . ' · ' . (string)($row['payment_method'] ?? '') . ' · ' . (string)($row['status'] ?? ''),
                        'module' => 'compras',
                        'url' => self::entityUrl('purchase_order', $entityId),
                    ];
                }
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                $stmt = $this->db->prepare(
                    "SELECT id, credit_number, total, credit_date, created_at, status
                     FROM purchase_credit_notes
                     WHERE purchase_order_id = ?
                     ORDER BY id DESC LIMIT 8"
                );
                $stmt->execute([$entityId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $out[] = [
                        'source' => 'credit_note',
                        'at' => (string)($row['created_at'] ?? $row['credit_date'] ?? ''),
                        'label' => 'Nota de crédito ' . (string)($row['credit_number'] ?? ''),
                        'detail' => 'Total ' . number_format((float)$row['total'], 0, ',', '.') . ' · ' . (string)($row['status'] ?? ''),
                        'module' => 'compras',
                        'url' => self::entityUrl('purchase_credit_note', (int)$row['id']),
                    ];
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        usort($out, static function ($a, $b) {
            return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? ''));
        });

        // Deduplicar por label+detail+at
        $seen = [];
        $deduped = [];
        foreach ($out as $ev) {
            $key = ($ev['source'] ?? '') . '|' . ($ev['at'] ?? '') . '|' . ($ev['label'] ?? '') . '|' . substr((string)($ev['detail'] ?? ''), 0, 80);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $ev;
            if (count($deduped) >= 40) {
                break;
            }
        }
        return $deduped;
    }

    /**
     * Alertas cruzadas ventas ↔ inventario ↔ facturas.
     *
     * @return list<array<string,mixed>>
     */
    public function detectAnomalies(?string $from = null, ?string $to = null, int $limit = 25): array
    {
        $limit = max(5, min(50, $limit));
        if ($from === null || $from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-30 days'));
        }
        if ($to === null || $to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';
        $alerts = [];

        // 1) Ventas con productos sin salida de stock.
        try {
            $stmt = $this->db->prepare(
                "SELECT s.id, s.invoice_number, s.created_at, s.total
                 FROM sales s
                 WHERE s.created_at BETWEEN ? AND ?
                   AND COALESCE(s.status, '') <> 'cancelled'
                   AND EXISTS (
                        SELECT 1 FROM sale_items si
                        INNER JOIN products p ON p.id = si.product_id
                        WHERE si.sale_id = s.id
                          AND COALESCE(p.product_type, 'product') = 'product'
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM stock_movements sm
                        WHERE sm.reference_type = 'sale' AND sm.reference_id = s.id AND sm.type = 'out'
                   )
                 ORDER BY s.id DESC
                 LIMIT " . (int)$limit
            );
            $stmt->execute([$fromTs, $toTs]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $alerts[] = [
                    'code' => 'sale_without_stock',
                    'severity' => 'warning',
                    'module' => 'ventas',
                    'title' => 'Venta sin salida de inventario',
                    'detail' => 'La factura ' . (string)($row['invoice_number'] ?? ('#' . $row['id']))
                        . ' tiene productos pero no hay movimiento de stock asociado.',
                    'entity_type' => 'sale',
                    'entity_id' => (int)$row['id'],
                    'at' => (string)$row['created_at'],
                    'url' => self::entityUrl('sale', (int)$row['id']),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 2) Facturas/OC recibidas sin entrada de stock.
        try {
            $stmt = $this->db->prepare(
                "SELECT po.id, po.order_number, po.invoice_number, po.received_at, po.total
                 FROM purchase_orders po
                 WHERE po.status = 'received'
                   AND COALESCE(po.received_at, po.warehouse_date, po.created_at) BETWEEN ? AND ?
                   AND EXISTS (
                        SELECT 1 FROM purchase_order_items poi
                        INNER JOIN products p ON p.id = poi.product_id
                        WHERE poi.purchase_order_id = po.id
                          AND COALESCE(p.product_type, 'product') = 'product'
                          AND poi.quantity > 0
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM stock_movements sm
                        WHERE sm.reference_type IN ('purchase_order', 'purchase')
                          AND sm.reference_id = po.id
                          AND sm.type = 'in'
                   )
                 ORDER BY po.id DESC
                 LIMIT " . (int)$limit
            );
            $stmt->execute([$fromTs, $toTs]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $label = (string)($row['invoice_number'] ?: $row['order_number'] ?: ('#' . $row['id']));
                $alerts[] = [
                    'code' => 'purchase_without_stock',
                    'severity' => 'danger',
                    'module' => 'compras',
                    'title' => 'Factura recibida sin entrada de inventario',
                    'detail' => 'OC/Factura ' . $label . ' está recibida pero no hay movimiento de entrada en stock.',
                    'entity_type' => 'purchase_order',
                    'entity_id' => (int)$row['id'],
                    'at' => (string)($row['received_at'] ?? ''),
                    'url' => self::entityUrl('purchase_order', (int)$row['id']),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 3) Productos con stock negativo actual.
        try {
            $stmt = $this->db->query(
                "SELECT id, code, name, stock FROM products
                 WHERE status = 'active' AND product_type = 'product' AND stock < 0
                 ORDER BY stock ASC
                 LIMIT " . (int)min(15, $limit)
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $alerts[] = [
                    'code' => 'negative_stock',
                    'severity' => 'danger',
                    'module' => 'inventario',
                    'title' => 'Stock negativo',
                    'detail' => (string)$row['name'] . ' (' . (string)$row['code'] . ') · stock=' . (int)$row['stock'],
                    'entity_type' => 'product',
                    'entity_id' => (int)$row['id'],
                    'at' => date('Y-m-d H:i:s'),
                    'url' => self::entityUrl('product', (int)$row['id']),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 4) Salidas de stock sin referencia (huérfanas) en el periodo.
        try {
            $stmt = $this->db->prepare(
                "SELECT sm.id, sm.created_at, sm.quantity, sm.notes, p.name AS product_name, p.code
                 FROM stock_movements sm
                 LEFT JOIN products p ON p.id = sm.product_id
                 WHERE sm.type = 'out'
                   AND sm.created_at BETWEEN ? AND ?
                   AND (sm.reference_type IS NULL OR sm.reference_type = '')
                   AND (sm.reference_id IS NULL OR sm.reference_id = 0)
                 ORDER BY sm.id DESC
                 LIMIT " . (int)min(15, $limit)
            );
            $stmt->execute([$fromTs, $toTs]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $alerts[] = [
                    'code' => 'orphan_stock_out',
                    'severity' => 'warning',
                    'module' => 'inventario',
                    'title' => 'Salida de stock sin documento',
                    'detail' => trim((string)($row['product_name'] ?? 'Producto') . ' · qty ' . (int)$row['quantity']
                        . (!empty($row['notes']) ? (' — ' . $row['notes']) : '')),
                    'entity_type' => 'stock_movement',
                    'entity_id' => (int)$row['id'],
                    'at' => (string)$row['created_at'],
                    'url' => self::entityUrl('stock_movement', (int)$row['id']),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 5) Descuadre cantidad vendida vs salida (muestra reducida).
        try {
            $stmt = $this->db->prepare(
                "SELECT x.id, x.invoice_number, x.created_at, x.sold_qty, x.stock_qty
                 FROM (
                    SELECT s.id, s.invoice_number, s.created_at,
                        COALESCE((
                            SELECT SUM(si.quantity) FROM sale_items si
                            INNER JOIN products p ON p.id = si.product_id
                            WHERE si.sale_id = s.id AND COALESCE(p.product_type,'product') = 'product'
                        ), 0) AS sold_qty,
                        COALESCE((
                            SELECT SUM(ABS(sm.quantity)) FROM stock_movements sm
                            WHERE sm.reference_type = 'sale' AND sm.reference_id = s.id AND sm.type = 'out'
                        ), 0) AS stock_qty
                    FROM sales s
                    WHERE s.created_at BETWEEN ? AND ?
                      AND COALESCE(s.status, '') <> 'cancelled'
                    ORDER BY s.id DESC
                    LIMIT 80
                 ) x
                 WHERE x.sold_qty > 0 AND x.stock_qty > 0 AND ABS(x.sold_qty - x.stock_qty) > 0.009
                 LIMIT " . (int)min(10, $limit)
            );
            $stmt->execute([$fromTs, $toTs]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $alerts[] = [
                    'code' => 'sale_qty_mismatch',
                    'severity' => 'warning',
                    'module' => 'ventas',
                    'title' => 'Cantidad venta ≠ salida de stock',
                    'detail' => (string)($row['invoice_number'] ?? ('#' . $row['id']))
                        . ': vendido ' . (int)$row['sold_qty'] . ' vs stock out ' . (int)$row['stock_qty'],
                    'entity_type' => 'sale',
                    'entity_id' => (int)$row['id'],
                    'at' => (string)$row['created_at'],
                    'url' => self::entityUrl('sale', (int)$row['id']),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        usort($alerts, static function ($a, $b) {
            $sev = ['danger' => 0, 'warning' => 1, 'info' => 2];
            $sa = $sev[$a['severity'] ?? 'info'] ?? 9;
            $sb = $sev[$b['severity'] ?? 'info'] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? ''));
        });

        return array_slice($alerts, 0, $limit);
    }

    /**
     * @return array{modules:list<string>,actions:list<string>,users:list<array<string,mixed>>}
     */
    public function filterOptions(): array
    {
        $knownModules = array_keys(self::moduleLabels());
        try {
            $modules = $this->db->query(
                "SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL AND module <> '' ORDER BY module"
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $actions = $this->db->query(
                "SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action"
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $users = $this->db->query(
                "SELECT DISTINCT user_id, user_name
                 FROM audit_logs
                 WHERE user_id IS NOT NULL
                 ORDER BY user_name"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [
                'modules' => $knownModules,
                'actions' => array_keys(self::actionLabels()),
                'users' => [],
            ];
        }

        $modules = array_values(array_unique(array_merge($knownModules, array_map('strval', $modules))));
        sort($modules);

        $knownActions = array_keys(self::actionLabels());
        $actions = array_values(array_unique(array_merge($knownActions, array_map('strval', $actions))));
        sort($actions);

        return [
            'modules' => $modules,
            'actions' => $actions,
            'users' => $users,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function anomaliesForEntity(?string $entityType, ?int $entityId): array
    {
        if ($entityType === null || ($entityId ?? 0) <= 0) {
            return [];
        }
        $all = $this->detectAnomalies(date('Y-m-d', strtotime('-90 days')), date('Y-m-d'), 40);
        return array_values(array_filter(
            $all,
            static fn(array $a): bool => ($a['entity_type'] ?? '') === $entityType && (int)($a['entity_id'] ?? 0) === (int)$entityId
        ));
    }

    /** @param array<string,mixed> $row */
    private function formatStockRelated(array $row): array
    {
        $type = (string)($row['type'] ?? '');
        $qty = (int)($row['quantity'] ?? 0);
        $dir = $type === 'in' ? 'Entrada' : ($type === 'out' ? 'Salida' : 'Mov.');
        $name = (string)($row['product_name'] ?? 'Producto');
        $ref = trim((string)($row['reference_type'] ?? '') . (!empty($row['reference_id']) ? (' #' . $row['reference_id']) : ''));
        return [
            'source' => 'stock',
            'at' => (string)($row['created_at'] ?? ''),
            'label' => $dir . ' inventario · ' . $name,
            'detail' => 'qty ' . $qty
                . ($ref !== '' ? (' · ' . $ref) : '')
                . (isset($row['stock_after']) && $row['stock_after'] !== null ? (' · stock=' . (int)$row['stock_after']) : '')
                . (!empty($row['notes']) ? (' — ' . $row['notes']) : ''),
            'module' => 'inventario',
            'url' => self::entityUrl('stock_movement', (int)($row['id'] ?? 0)),
        ];
    }

    private function mapStockRefToEntity(string $ref): ?string
    {
        return match ($ref) {
            'sale', 'return' => 'sale',
            'sale_credit_note' => 'sale_credit_note',
            'purchase_order', 'purchase' => 'purchase_order',
            'purchase_credit_note' => 'purchase_credit_note',
            default => null,
        };
    }

    private function hasEntityColumns(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $has = false;
        try {
            foreach ($this->db->query('SHOW COLUMNS FROM audit_logs')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (strtolower((string)($row['Field'] ?? '')) === 'entity_type') {
                    $has = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $has = false;
        }
        return $has;
    }

    private function hasMetaColumns(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $has = false;
        try {
            foreach ($this->db->query('SHOW COLUMNS FROM audit_logs')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (strtolower((string)($row['Field'] ?? '')) === 'meta_json') {
                    $has = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $has = false;
        }
        return $has;
    }
}
