<?php

namespace SoftNova\Services;

/**
 * Inventario / kardex valorizado para BD tenant.
 * Cada movimiento guarda costo, saldo y vínculo opcional al asiento contable.
 */
class StockService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
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

        $columns = [
            'unit_cost' => "ALTER TABLE stock_movements ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity",
            'total_cost' => "ALTER TABLE stock_movements ADD COLUMN total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost",
            'stock_after' => "ALTER TABLE stock_movements ADD COLUMN stock_after INT NULL AFTER total_cost",
            'supplier_id' => "ALTER TABLE stock_movements ADD COLUMN supplier_id INT UNSIGNED NULL AFTER stock_after",
            'payment_mode' => "ALTER TABLE stock_movements ADD COLUMN payment_mode VARCHAR(20) NULL AFTER supplier_id",
            'accounting_entry_id' => "ALTER TABLE stock_movements ADD COLUMN accounting_entry_id BIGINT UNSIGNED NULL AFTER payment_mode",
            'movement_date' => "ALTER TABLE stock_movements ADD COLUMN movement_date DATE NULL AFTER created_at",
        ];

        foreach ($columns as $name => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM stock_movements LIKE " . $this->db->quote($name))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // Tabla puede no existir aún en installs parciales.
            }
        }

        try {
            // Rellenar fechas antiguas con la fecha de creación.
            $this->db->exec(
                "UPDATE stock_movements
                 SET movement_date = DATE(created_at)
                 WHERE movement_date IS NULL"
            );
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $idx = $this->db->query("SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_sm_accounting'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE stock_movements ADD INDEX idx_sm_accounting (accounting_entry_id)');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $idx = $this->db->query("SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_sm_movement_date'")->fetch();
            if (!$idx) {
                $this->db->exec('ALTER TABLE stock_movements ADD INDEX idx_sm_movement_date (movement_date)');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $done = true;
    }

    public function normalizeDate(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return date('Y-m-d');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Fecha de ingreso a bodega inválida');
        }
        return $date;
    }

    /**
     * @return int ID del movimiento creado
     */
    public function addMovement(
        int $productId,
        string $type,
        int $quantity,
        string $referenceType = '',
        ?int $referenceId = null,
        string $notes = '',
        ?int $userId = null,
        float $unitCost = 0.0,
        ?int $stockAfter = null,
        ?int $supplierId = null,
        ?string $paymentMode = null,
        ?string $movementDate = null
    ): int {
        $userId = $userId ?? ($_SESSION['tenant_user_id'] ?? null);
        $unitCost = round(max(0, $unitCost), 2);
        $totalCost = round($unitCost * abs($quantity), 2);
        $movementDate = $this->normalizeDate($movementDate);

        if ($stockAfter === null) {
            $stockAfter = (int)$this->query(
                "SELECT stock FROM products WHERE id = ?",
                [$productId]
            )->fetchColumn();
        }

        $this->query(
            "INSERT INTO stock_movements
                (product_id, type, quantity, unit_cost, total_cost, stock_after,
                 supplier_id, payment_mode, reference_type, reference_id, notes, user_id, movement_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $productId,
                $type,
                $quantity,
                $unitCost,
                $totalCost,
                $stockAfter,
                $supplierId,
                $paymentMode,
                $referenceType !== '' ? $referenceType : null,
                $referenceId,
                $notes !== '' ? $notes : null,
                $userId,
                $movementDate,
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    public function updateMovementDate(int $movementId, string $movementDate): bool
    {
        $movementDate = $this->normalizeDate($movementDate);
        $movement = $this->query(
            "SELECT id, reference_type, accounting_entry_id FROM stock_movements WHERE id = ?",
            [$movementId]
        )->fetch();
        if (!$movement) {
            throw new \RuntimeException('Movimiento no encontrado');
        }
        $ref = (string)($movement['reference_type'] ?? '');
        if (in_array($ref, ['sale', 'return'], true)) {
            throw new \RuntimeException('La fecha de salidas por venta se toma de la factura; no se edita aquí');
        }

        $this->query(
            "UPDATE stock_movements SET movement_date = ? WHERE id = ?",
            [$movementDate, $movementId]
        );

        if (!empty($movement['accounting_entry_id'])) {
            $this->query(
                "UPDATE accounting_entries SET entry_date = ? WHERE id = ? AND status = 'posted'",
                [$movementDate, (int)$movement['accounting_entry_id']]
            );
        }

        return true;
    }

    public function linkAccountingEntry(int $movementId, int $entryId): void
    {
        $this->query(
            "UPDATE stock_movements SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $movementId]
        );
    }

    /**
     * Descontar stock de forma atómica. Lanza excepción si no hay suficiente.
     * @return int ID movimiento
     */
    public function decrease(
        int $productId,
        int $quantity,
        string $referenceType,
        ?int $referenceId,
        string $notes = '',
        ?float $unitCost = null
    ): int {
        if ($quantity <= 0) {
            return 0;
        }

        $product = $this->query(
            "SELECT id, name, stock, product_type, status, purchase_price FROM products WHERE id = ?",
            [$productId]
        )->fetch();

        if (!$product) {
            throw new \RuntimeException('Producto no encontrado');
        }

        $cost = $unitCost !== null
            ? round(max(0, $unitCost), 2)
            : round((float)($product['purchase_price'] ?? 0), 2);

        if (($product['product_type'] ?? 'product') === 'service') {
            return $this->addMovement(
                $productId,
                'out',
                $quantity,
                $referenceType,
                $referenceId,
                $notes,
                null,
                $cost,
                (int)$product['stock']
            );
        }

        $stmt = $this->query(
            "UPDATE products SET stock = stock - ?, last_sale_date = NOW()
             WHERE id = ? AND stock >= ? AND status = 'active'",
            [$quantity, $productId, $quantity]
        );

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException(
                'Stock insuficiente para: ' . ($product['name'] ?? ('#' . $productId))
                . ' (disponible: ' . (int)$product['stock'] . ', solicitado: ' . $quantity . ')'
            );
        }

        $after = (int)$product['stock'] - $quantity;
        return $this->addMovement(
            $productId,
            'out',
            $quantity,
            $referenceType,
            $referenceId,
            $notes,
            null,
            $cost,
            $after
        );
    }

    /**
     * Incrementar stock (compras, devoluciones, ajustes positivos).
     * @return int ID movimiento
     */
    public function increase(
        int $productId,
        int $quantity,
        string $referenceType,
        ?int $referenceId,
        string $notes = '',
        string $movementType = 'in',
        ?float $unitCost = null,
        ?int $supplierId = null,
        ?string $paymentMode = null,
        bool $updatePurchasePrice = false,
        ?string $movementDate = null
    ): int {
        if ($quantity <= 0) {
            return 0;
        }

        $product = $this->query(
            "SELECT id, stock, purchase_price, product_type FROM products WHERE id = ?",
            [$productId]
        )->fetch();
        if (!$product) {
            throw new \RuntimeException('Producto no encontrado');
        }

        if (($product['product_type'] ?? 'product') === 'service') {
            return 0;
        }

        $cost = $unitCost !== null
            ? round(max(0, $unitCost), 2)
            : round((float)($product['purchase_price'] ?? 0), 2);

        $oldStock = (int)$product['stock'];
        $oldCost = round((float)($product['purchase_price'] ?? 0), 2);

        // Promedio ponderado (WAC): (stock×costo_actual + qty×costo_entrada) / nuevo_stock
        if ($updatePurchasePrice && $cost > 0) {
            $newWac = $this->weightedAverageCost($oldStock, $oldCost, $quantity, $cost);
            $this->query(
                "UPDATE products SET stock = stock + ?, purchase_price = ? WHERE id = ?",
                [$quantity, $newWac, $productId]
            );
        } else {
            $this->query("UPDATE products SET stock = stock + ? WHERE id = ?", [$quantity, $productId]);
        }

        $after = $oldStock + $quantity;
        return $this->addMovement(
            $productId,
            $movementType,
            $quantity,
            $referenceType,
            $referenceId,
            $notes,
            null,
            $cost,
            $after,
            $supplierId,
            $paymentMode,
            $movementDate
        );
    }

    /**
     * Costo promedio ponderado. Si no hay stock previo, el nuevo costo es el de la entrada.
     */
    public function weightedAverageCost(int $oldStock, float $oldCost, int $qtyIn, float $costIn): float
    {
        $qtyIn = max(0, $qtyIn);
        $costIn = max(0, $costIn);
        if ($qtyIn <= 0) {
            return round(max(0, $oldCost), 2);
        }
        if ($oldStock <= 0) {
            return round($costIn, 2);
        }
        $totalUnits = $oldStock + $qtyIn;
        if ($totalUnits <= 0) {
            return round($costIn, 2);
        }
        return round((($oldStock * $oldCost) + ($qtyIn * $costIn)) / $totalUnits, 2);
    }

    /**
     * Variación de costo de entrada: promedio mes actual vs mes anterior.
     * @return list<array<string,mixed>>
     */
    public function costVariationAlerts(float $thresholdPct = 5.0, int $limit = 25): array
    {
        $thresholdPct = max(0.5, min(100, $thresholdPct));
        $rows = $this->query(
            "SELECT p.id, p.code, p.name, p.purchase_price, p.stock,
                    COALESCE(cur.avg_cost, 0) avg_current,
                    COALESCE(prev.avg_cost, 0) avg_previous,
                    COALESCE(cur.entries, 0) entries_current,
                    COALESCE(prev.entries, 0) entries_previous
             FROM products p
             LEFT JOIN (
                SELECT product_id,
                       AVG(unit_cost) avg_cost,
                       COUNT(*) entries
                FROM stock_movements
                WHERE type = 'in'
                  AND COALESCE(unit_cost, 0) > 0
                  AND COALESCE(reference_type, '') IN ('purchase', 'purchase_order', 'opening', '')
                  AND COALESCE(movement_date, DATE(created_at)) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                  AND COALESCE(movement_date, DATE(created_at)) < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
                GROUP BY product_id
             ) cur ON cur.product_id = p.id
             LEFT JOIN (
                SELECT product_id,
                       AVG(unit_cost) avg_cost,
                       COUNT(*) entries
                FROM stock_movements
                WHERE type = 'in'
                  AND COALESCE(unit_cost, 0) > 0
                  AND COALESCE(reference_type, '') IN ('purchase', 'purchase_order', 'opening', '')
                  AND COALESCE(movement_date, DATE(created_at)) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                  AND COALESCE(movement_date, DATE(created_at)) < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                GROUP BY product_id
             ) prev ON prev.product_id = p.id
             WHERE p.status = 'active'
               AND COALESCE(p.product_type, 'product') = 'product'
               AND COALESCE(prev.avg_cost, 0) > 0
               AND COALESCE(cur.avg_cost, 0) > 0
               AND ABS((COALESCE(cur.avg_cost, 0) - COALESCE(prev.avg_cost, 0)) / COALESCE(prev.avg_cost, 0) * 100) >= ?
             ORDER BY ABS((COALESCE(cur.avg_cost, 0) - COALESCE(prev.avg_cost, 0)) / COALESCE(prev.avg_cost, 0) * 100) DESC
             LIMIT " . (int)$limit,
            [$thresholdPct]
        )->fetchAll();

        foreach ($rows as &$row) {
            $prev = (float)$row['avg_previous'];
            $cur = (float)$row['avg_current'];
            $pct = $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : 0.0;
            $row['change_pct'] = $pct;
            $row['direction'] = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
            $row['avg_current'] = round($cur, 2);
            $row['avg_previous'] = round($prev, 2);
            $row['wac'] = round((float)$row['purchase_price'], 2);
        }
        unset($row);
        return $rows;
    }

    /**
     * Proyección de compras según rotación (últimos 30 días) y stock actual.
     * @return list<array<string,mixed>>
     */
    public function purchaseProjection(int $horizonDays = 30, int $limit = 40): array
    {
        $horizonDays = max(7, min(90, $horizonDays));
        $rows = $this->query(
            "SELECT p.id, p.code, p.name, p.stock, p.min_stock, p.purchase_price, p.sale_price,
                    COALESCE(v.qty_30, 0) sold_30,
                    COALESCE(v.qty_30, 0) / 30 daily_avg
             FROM products p
             LEFT JOIN (
                SELECT si.product_id, SUM(si.quantity) qty_30
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                WHERE s.status = 'completed'
                  AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY si.product_id
             ) v ON v.product_id = p.id
             WHERE p.status = 'active'
               AND COALESCE(p.product_type, 'product') = 'product'
               AND (COALESCE(v.qty_30, 0) > 0 OR p.stock <= p.min_stock)
             ORDER BY (COALESCE(v.qty_30, 0) / 30) DESC, p.stock ASC
             LIMIT " . (int)$limit
        )->fetchAll();

        foreach ($rows as &$row) {
            $stock = (int)$row['stock'];
            $min = (int)$row['min_stock'];
            $daily = (float)$row['daily_avg'];
            $needed = (int)ceil($daily * $horizonDays);
            // Buffer: cubrir horizonte + reponer hasta mínimo
            $suggested = max(0, $needed - $stock);
            if ($stock < $min) {
                $suggested = max($suggested, $min - $stock);
            }
            $daysCover = $daily > 0.0001 ? round($stock / $daily, 1) : ($stock > 0 ? 999 : 0);
            $row['daily_avg'] = round($daily, 2);
            $row['sold_30'] = (int)$row['sold_30'];
            $row['days_cover'] = $daysCover;
            $row['suggested_qty'] = $suggested;
            $row['suggested_cost'] = round($suggested * (float)$row['purchase_price'], 2);
            $row['urgency'] = $daysCover <= 7 || $stock <= $min ? 'high' : ($daysCover <= 15 ? 'medium' : 'low');
        }
        unset($row);

        // Priorizar los que realmente sugieren comprar
        usort($rows, static function ($a, $b) {
            $ua = $a['urgency'] === 'high' ? 0 : ($a['urgency'] === 'medium' ? 1 : 2);
            $ub = $b['urgency'] === 'high' ? 0 : ($b['urgency'] === 'medium' ? 1 : 2);
            if ($ua !== $ub) {
                return $ua <=> $ub;
            }
            return ($b['suggested_qty'] <=> $a['suggested_qty']);
        });

        return array_values(array_filter($rows, static fn($r) => (int)$r['suggested_qty'] > 0 || ($r['urgency'] ?? '') === 'high'));
    }

    /**
     * Ajuste absoluto de stock (edición manual). Devuelve ID movimiento o 0 si no hay cambio.
     */
    public function adjustTo(
        int $productId,
        int $newStock,
        string $notes = 'Ajuste manual de stock',
        ?float $unitCost = null,
        ?string $movementDate = null
    ): int {
        $product = $this->query(
            "SELECT id, stock, purchase_price, product_type FROM products WHERE id = ?",
            [$productId]
        )->fetch();
        if (!$product || ($product['product_type'] ?? 'product') === 'service') {
            return 0;
        }

        $old = (int)$product['stock'];
        $diff = $newStock - $old;
        if ($diff === 0) {
            return 0;
        }

        $cost = $unitCost !== null
            ? round(max(0, $unitCost), 2)
            : round((float)($product['purchase_price'] ?? 0), 2);

        $this->query("UPDATE products SET stock = ? WHERE id = ?", [$newStock, $productId]);

        return $this->addMovement(
            $productId,
            'adjustment',
            abs($diff),
            'adjustment',
            null,
            ($diff > 0 ? 'Ajuste (+): ' : 'Ajuste (−): ') . $notes,
            null,
            $cost,
            $newStock,
            null,
            $diff > 0 ? 'equity' : 'loss',
            $movementDate
        );
    }

    public function valuedKardex(?int $productId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $where = '';
        $params = [];
        if ($productId) {
            $where = 'WHERE sm.product_id = ?';
            $params[] = $productId;
        }

        return $this->query(
            "SELECT sm.*, p.code product_code, p.name product_name,
                    u.name user_name, s.name supplier_name,
                    e.entry_number accounting_entry_number,
                    COALESCE(sm.movement_date, DATE(sm.created_at)) AS warehouse_date
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id
             LEFT JOIN users u ON u.id = sm.user_id
             LEFT JOIN suppliers s ON s.id = sm.supplier_id
             LEFT JOIN accounting_entries e ON e.id = sm.accounting_entry_id
             {$where}
             ORDER BY COALESCE(sm.movement_date, DATE(sm.created_at)) DESC, sm.id DESC
             LIMIT {$limit}",
            $params
        )->fetchAll();
    }

    /** Movimientos de compra/ajuste aún sin asiento (excluye ventas/devoluciones). */
    public function pendingAccountingMovements(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->query(
            "SELECT sm.id
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id AND COALESCE(p.product_type, 'product') = 'product'
             WHERE sm.accounting_entry_id IS NULL
               AND COALESCE(sm.total_cost, 0) > 0
               AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'purchase_order')
               AND (
                    sm.type = 'in'
                    OR sm.type = 'adjustment'
                    OR sm.reference_type IN ('purchase', 'opening', 'adjustment')
               )
             ORDER BY sm.id
             LIMIT {$limit}"
        )->fetchAll();
    }

    public function pendingAccountingCount(): int
    {
        return (int)$this->query(
            "SELECT COUNT(*)
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id AND COALESCE(p.product_type, 'product') = 'product'
             WHERE sm.accounting_entry_id IS NULL
               AND COALESCE(sm.total_cost, 0) > 0
               AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'purchase_order')
               AND (
                    sm.type = 'in'
                    OR sm.type = 'adjustment'
                    OR sm.reference_type IN ('purchase', 'opening', 'adjustment')
               )"
        )->fetchColumn();
    }

    /**
     * Historial de movimientos (trazabilidad) con filtros.
     *
     * @return array{rows:array,total:int}
     */
    public function listMovements(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->ensureSchema();
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['product_id'])) {
            $where[] = 'sm.product_id = ?';
            $params[] = (int)$filters['product_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'sm.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['reference_type'])) {
            $where[] = 'sm.reference_type = ?';
            $params[] = $filters['reference_type'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR sm.notes LIKE ? OR sm.reference_type LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['from'])) {
            $where[] = 'DATE(COALESCE(sm.movement_date, sm.created_at)) >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'DATE(COALESCE(sm.movement_date, sm.created_at)) <= ?';
            $params[] = $filters['to'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)$this->query(
            "SELECT COUNT(*) FROM stock_movements sm
             LEFT JOIN products p ON p.id = sm.product_id
             WHERE {$whereSql}",
            $params
        )->fetchColumn();

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $rows = $this->query(
            "SELECT sm.*, p.name product_name, p.code product_code, p.source_channel,
                    u.name user_name
             FROM stock_movements sm
             LEFT JOIN products p ON p.id = sm.product_id
             LEFT JOIN users u ON u.id = sm.user_id
             WHERE {$whereSql}
             ORDER BY COALESCE(sm.movement_date, sm.created_at) DESC, sm.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }
}
