<?php

namespace SoftNova\Services;

/**
 * Lotes con fecha de vencimiento y consumo FEFO (First-Expired, First-Out).
 *
 * Los lotes son un submayor del stock: `products.stock` sigue siendo la fuente
 * de verdad para la venta, y los lotes explican de qué remesa salió cada unidad.
 * Por eso el consumo tolera faltantes de lote sin bloquear la venta.
 */
class LotService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        SupplyChainSchema::ensure($db);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** ¿El producto maneja lotes con vencimiento? */
    public function tracksLots(int $productId): bool
    {
        try {
            return (bool)$this->query(
                "SELECT track_lots FROM products WHERE id = ?",
                [$productId]
            )->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Activa o desactiva el control de lotes y su umbral de alerta. */
    public function configureProduct(int $productId, bool $trackLots, int $alertDays = 30): void
    {
        $this->query(
            "UPDATE products SET track_lots = ?, expiry_alert_days = ? WHERE id = ?",
            [$trackLots ? 1 : 0, max(0, min(3650, $alertDays)), $productId]
        );
        $this->refreshNextExpiry($productId);
    }

    /**
     * Registra una entrada en lote. Si el código ya existe para el producto,
     * acumula cantidad y recalcula el costo promedio del lote.
     *
     * @param array{lot_code?:string,expiry_date?:?string,supplier_id?:?int,purchase_order_id?:?int,received_date?:?string,notes?:string,user_id?:?int} $meta
     * @return int ID del lote
     */
    public function registerEntry(int $productId, int $quantity, float $unitCost, array $meta = []): int
    {
        $quantity = (int)$quantity;
        if ($quantity <= 0) {
            return 0;
        }

        $expiry = $this->normalizeDate($meta['expiry_date'] ?? null);
        $lotCode = trim((string)($meta['lot_code'] ?? ''));
        if ($lotCode === '') {
            $lotCode = $this->autoLotCode($expiry);
        }
        $unitCost = round(max(0, $unitCost), 2);

        $existing = $this->query(
            "SELECT id, quantity, initial_quantity, unit_cost FROM product_lots
             WHERE product_id = ? AND lot_code = ?",
            [$productId, $lotCode]
        )->fetch();

        if ($existing) {
            $lotId = (int)$existing['id'];
            $blendedCost = $this->blendCost(
                (int)$existing['quantity'],
                (float)$existing['unit_cost'],
                $quantity,
                $unitCost
            );
            $this->query(
                "UPDATE product_lots
                 SET quantity = quantity + ?,
                     initial_quantity = initial_quantity + ?,
                     unit_cost = ?,
                     expiry_date = COALESCE(?, expiry_date),
                     status = 'active'
                 WHERE id = ?",
                [$quantity, $quantity, $blendedCost, $expiry, $lotId]
            );
        } else {
            $this->query(
                "INSERT INTO product_lots
                    (product_id, lot_code, expiry_date, quantity, initial_quantity, unit_cost,
                     supplier_id, purchase_order_id, received_date, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $productId,
                    $lotCode,
                    $expiry,
                    $quantity,
                    $quantity,
                    $unitCost,
                    $meta['supplier_id'] ?? null,
                    $meta['purchase_order_id'] ?? null,
                    $this->normalizeDate($meta['received_date'] ?? null) ?? date('Y-m-d'),
                    ($meta['notes'] ?? '') !== '' ? substr((string)$meta['notes'], 0, 255) : null,
                    $meta['user_id'] ?? ($_SESSION['tenant_user_id'] ?? null),
                ]
            );
            $lotId = (int)$this->db->lastInsertId();
        }

        $this->refreshNextExpiry($productId);
        return $lotId;
    }

    /**
     * Consume unidades en orden FEFO: primero lo que vence antes; los lotes sin
     * fecha quedan al final para no retener mercancía perecedera.
     *
     * @return array{consumed:int,pending:int,lines:list<array{lot_id:int,lot_code:string,expiry_date:?string,quantity:int,unit_cost:float}>}
     */
    public function consumeFefo(int $productId, int $quantity, ?int $movementId = null): array
    {
        $result = ['consumed' => 0, 'pending' => max(0, (int)$quantity), 'lines' => []];
        if ($quantity <= 0) {
            $result['pending'] = 0;
            return $result;
        }

        $lots = $this->query(
            "SELECT id, lot_code, expiry_date, quantity, unit_cost
             FROM product_lots
             WHERE product_id = ? AND status IN ('active', 'expired') AND quantity > 0
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC",
            [$productId]
        )->fetchAll();

        $remaining = (int)$quantity;
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int)$lot['quantity']);
            if ($take <= 0) {
                continue;
            }

            // El estado se evalúa antes de restar: MySQL aplica los SET en orden.
            $this->query(
                "UPDATE product_lots
                 SET status = CASE WHEN quantity - ? <= 0 THEN 'depleted' ELSE status END,
                     quantity = quantity - ?
                 WHERE id = ?",
                [$take, $take, (int)$lot['id']]
            );

            $line = [
                'lot_id' => (int)$lot['id'],
                'lot_code' => (string)$lot['lot_code'],
                'expiry_date' => $lot['expiry_date'] ?: null,
                'quantity' => $take,
                'unit_cost' => round((float)$lot['unit_cost'], 2),
            ];
            $result['lines'][] = $line;
            $result['consumed'] += $take;
            $remaining -= $take;

            if ($movementId !== null) {
                $this->query(
                    "INSERT INTO stock_movement_lots
                        (movement_id, lot_id, lot_code, expiry_date, quantity, unit_cost)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $movementId,
                        $line['lot_id'],
                        $line['lot_code'],
                        $line['expiry_date'],
                        $take,
                        $line['unit_cost'],
                    ]
                );
            }
        }

        $result['pending'] = $remaining;
        $this->refreshNextExpiry($productId);
        return $result;
    }

    /**
     * Devuelve unidades a sus lotes originales (anulación de venta o devolución).
     * Sin trazas de lote no hay a dónde devolver, así que informa el faltante.
     */
    public function restoreFromMovement(int $movementId): int
    {
        // `restored_at` evita devolver dos veces las mismas unidades al lote.
        $lines = $this->query(
            "SELECT id, lot_id, quantity FROM stock_movement_lots
             WHERE movement_id = ? AND restored_at IS NULL",
            [$movementId]
        )->fetchAll();

        $restored = 0;
        foreach ($lines as $line) {
            $this->query(
                "UPDATE product_lots
                 SET status = CASE WHEN status = 'depleted' THEN 'active' ELSE status END,
                     quantity = quantity + ?
                 WHERE id = ?",
                [(int)$line['quantity'], (int)$line['lot_id']]
            );
            $this->query(
                "UPDATE stock_movement_lots SET restored_at = NOW() WHERE id = ?",
                [(int)$line['id']]
            );
            $restored += (int)$line['quantity'];
        }

        if ($restored > 0) {
            $productId = (int)$this->query(
                "SELECT product_id FROM stock_movements WHERE id = ?",
                [$movementId]
            )->fetchColumn();
            if ($productId > 0) {
                $this->refreshNextExpiry($productId);
            }
        }

        return $restored;
    }

    /**
     * Ajusta manualmente la cantidad de un lote (mermas, roturas, reconteo).
     * No toca `products.stock`: eso lo hace el kardex.
     */
    public function adjustLot(int $lotId, int $newQuantity, string $notes = ''): bool
    {
        $lot = $this->query("SELECT product_id, initial_quantity FROM product_lots WHERE id = ?", [$lotId])->fetch();
        if (!$lot) {
            return false;
        }
        $newQuantity = max(0, $newQuantity);

        $this->query(
            "UPDATE product_lots
             SET quantity = ?,
                 status = CASE WHEN ? <= 0 THEN 'depleted' ELSE 'active' END,
                 notes = COALESCE(NULLIF(?, ''), notes)
             WHERE id = ?",
            [$newQuantity, $newQuantity, substr($notes, 0, 255), $lotId]
        );

        $this->refreshNextExpiry((int)$lot['product_id']);
        return true;
    }

    /** Bloquea un lote para que FEFO no lo consuma (cuarentena, retiro del proveedor). */
    public function blockLot(int $lotId, string $reason = ''): bool
    {
        $lot = $this->query("SELECT product_id FROM product_lots WHERE id = ?", [$lotId])->fetch();
        if (!$lot) {
            return false;
        }
        $this->query(
            "UPDATE product_lots SET status = 'blocked', notes = COALESCE(NULLIF(?, ''), notes) WHERE id = ?",
            [substr($reason, 0, 255), $lotId]
        );
        $this->refreshNextExpiry((int)$lot['product_id']);
        return true;
    }

    /**
     * Marca como vencidos los lotes cuya fecha ya pasó.
     * Se ejecuta de forma perezosa al abrir los paneles de inventario.
     */
    public function markExpiredLots(): int
    {
        try {
            $stmt = $this->query(
                "UPDATE product_lots
                 SET status = 'expired'
                 WHERE status = 'active' AND quantity > 0
                   AND expiry_date IS NOT NULL AND expiry_date < CURDATE()"
            );
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return list<array<string,mixed>> */
    public function lotsForProduct(int $productId, bool $onlyAvailable = false): array
    {
        $sql = "SELECT l.*, s.name supplier_name,
                       DATEDIFF(l.expiry_date, CURDATE()) days_left
                FROM product_lots l
                LEFT JOIN suppliers s ON s.id = l.supplier_id
                WHERE l.product_id = ?";
        if ($onlyAvailable) {
            $sql .= " AND l.quantity > 0 AND l.status = 'active'";
        }
        $sql .= " ORDER BY (l.expiry_date IS NULL), l.expiry_date ASC, l.id ASC";

        return $this->query($sql, [$productId])->fetchAll();
    }

    /**
     * Lotes vencidos o por vencer según el umbral de cada producto.
     *
     * @param int|null $days Umbral fijo; si es null usa `products.expiry_alert_days`
     * @return list<array<string,mixed>>
     */
    public function expiringLots(?int $days = null, int $limit = 100): array
    {
        $this->markExpiredLots();

        $threshold = $days !== null
            ? '?'
            : 'COALESCE(p.expiry_alert_days, 30)';
        $params = $days !== null ? [max(0, $days)] : [];

        $sql = "SELECT l.id, l.lot_code, l.expiry_date, l.quantity, l.unit_cost, l.status,
                       l.product_id, p.code product_code, p.name product_name, p.sale_price,
                       s.name supplier_name,
                       DATEDIFF(l.expiry_date, CURDATE()) days_left,
                       ROUND(l.quantity * l.unit_cost, 2) value_at_risk
                FROM product_lots l
                JOIN products p ON p.id = l.product_id
                LEFT JOIN suppliers s ON s.id = l.supplier_id
                WHERE l.quantity > 0
                  AND l.status IN ('active', 'expired')
                  AND l.expiry_date IS NOT NULL
                  AND l.expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$threshold} DAY)
                ORDER BY l.expiry_date ASC
                LIMIT " . max(1, min(500, $limit));

        try {
            return $this->query($sql, $params)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resumen para tarjetas: vencidos, por vencer y valor en riesgo.
     *
     * @return array{expired_lots:int,expired_units:int,expired_value:float,expiring_lots:int,expiring_units:int,expiring_value:float}
     */
    public function expirySummary(): array
    {
        $empty = [
            'expired_lots' => 0, 'expired_units' => 0, 'expired_value' => 0.0,
            'expiring_lots' => 0, 'expiring_units' => 0, 'expiring_value' => 0.0,
        ];

        $this->markExpiredLots();

        try {
            $row = $this->query(
                "SELECT
                    SUM(CASE WHEN l.expiry_date < CURDATE() THEN 1 ELSE 0 END) expired_lots,
                    SUM(CASE WHEN l.expiry_date < CURDATE() THEN l.quantity ELSE 0 END) expired_units,
                    SUM(CASE WHEN l.expiry_date < CURDATE() THEN l.quantity * l.unit_cost ELSE 0 END) expired_value,
                    SUM(CASE WHEN l.expiry_date >= CURDATE() THEN 1 ELSE 0 END) expiring_lots,
                    SUM(CASE WHEN l.expiry_date >= CURDATE() THEN l.quantity ELSE 0 END) expiring_units,
                    SUM(CASE WHEN l.expiry_date >= CURDATE() THEN l.quantity * l.unit_cost ELSE 0 END) expiring_value
                 FROM product_lots l
                 JOIN products p ON p.id = l.product_id
                 WHERE l.quantity > 0
                   AND l.status IN ('active', 'expired')
                   AND l.expiry_date IS NOT NULL
                   AND l.expiry_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(p.expiry_alert_days, 30) DAY)"
            )->fetch();
        } catch (\Throwable $e) {
            return $empty;
        }

        if (!$row) {
            return $empty;
        }

        return [
            'expired_lots' => (int)($row['expired_lots'] ?? 0),
            'expired_units' => (int)($row['expired_units'] ?? 0),
            'expired_value' => round((float)($row['expired_value'] ?? 0), 2),
            'expiring_lots' => (int)($row['expiring_lots'] ?? 0),
            'expiring_units' => (int)($row['expiring_units'] ?? 0),
            'expiring_value' => round((float)($row['expiring_value'] ?? 0), 2),
        ];
    }

    /**
     * Diferencia entre el stock del producto y la suma de sus lotes.
     * Sirve para detectar productos que empezaron a usar lotes con saldo previo.
     *
     * @return list<array<string,mixed>>
     */
    public function lotStockMismatches(int $limit = 50): array
    {
        try {
            return $this->query(
                "SELECT p.id, p.code, p.name, p.stock,
                        COALESCE(SUM(CASE WHEN l.status IN ('active','expired') THEN l.quantity ELSE 0 END), 0) lot_stock
                 FROM products p
                 LEFT JOIN product_lots l ON l.product_id = p.id
                 WHERE p.track_lots = 1 AND p.status = 'active'
                 GROUP BY p.id, p.code, p.name, p.stock
                 HAVING lot_stock <> p.stock
                 ORDER BY ABS(p.stock - lot_stock) DESC
                 LIMIT " . max(1, min(200, $limit))
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Recalcula `products.next_expiry_date` con el lote activo más próximo. */
    public function refreshNextExpiry(int $productId): void
    {
        try {
            $this->query(
                "UPDATE products p
                 SET p.next_expiry_date = (
                    SELECT MIN(l.expiry_date) FROM product_lots l
                    WHERE l.product_id = p.id AND l.quantity > 0
                      AND l.status = 'active' AND l.expiry_date IS NOT NULL
                 )
                 WHERE p.id = ?",
                [$productId]
            );
        } catch (\Throwable $e) {
            // Columna ausente en instalaciones sin migrar.
        }
    }

    private function blendCost(int $oldQty, float $oldCost, int $newQty, float $newCost): float
    {
        if ($newCost <= 0) {
            return round(max(0, $oldCost), 2);
        }
        $total = max(0, $oldQty) + max(0, $newQty);
        if ($total <= 0 || $oldQty <= 0) {
            return round($newCost, 2);
        }
        return round((($oldQty * $oldCost) + ($newQty * $newCost)) / $total, 2);
    }

    private function autoLotCode(?string $expiry): string
    {
        $base = $expiry ? 'V' . str_replace('-', '', $expiry) : 'L' . date('Ymd');
        return $base . '-' . strtoupper(substr(uniqid('', false), -4));
    }

    private function normalizeDate(?string $date): ?string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
    }
}
