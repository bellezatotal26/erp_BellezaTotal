<?php

namespace SoftNova\Services;

/**
 * Historial del precio de costo (products.purchase_price) por producto:
 * cada compra, entrada valorizada o edición manual deja costo anterior → nuevo.
 */
final class ProductCostHistory
{
    private static bool $ready = false;

    /** Crea la tabla si falta. No ejecuta DDL dentro de una transacción (commit implícito en MySQL). */
    public static function ensure(\PDO $db): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            $st = $db->query("SHOW TABLES LIKE 'product_cost_history'");
            if ($st && $st->fetchColumn()) {
                self::$ready = true;
                return true;
            }
            if ($db->inTransaction()) {
                return false;
            }
            $db->exec(
                "CREATE TABLE IF NOT EXISTS product_cost_history (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    old_cost DECIMAL(12,2) NULL,
                    new_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    qty INT NULL,
                    unit_cost_in DECIMAL(12,2) NULL,
                    stock_before INT NULL,
                    stock_after INT NULL,
                    source VARCHAR(20) NOT NULL DEFAULT 'purchase',
                    reference_type VARCHAR(40) NULL,
                    reference_id INT UNSIGNED NULL,
                    movement_id INT UNSIGNED NULL,
                    note VARCHAR(255) NULL,
                    user_id INT UNSIGNED NULL,
                    effective_date DATE NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_pch_product (product_id, id),
                    INDEX idx_pch_ref (reference_type, reference_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$ready = true;
        } catch (\Throwable $e) {
            error_log('product_cost_history ensure: ' . $e->getMessage());
        }
        return self::$ready;
    }

    /**
     * @param array{qty?:int|null,unit_cost_in?:float|null,stock_before?:int|null,stock_after?:int|null,
     *              source?:string,reference_type?:string|null,reference_id?:int|null,movement_id?:int|null,
     *              note?:string|null,effective_date?:string|null} $ctx
     */
    public static function record(\PDO $db, int $productId, ?float $oldCost, float $newCost, array $ctx = []): void
    {
        if ($productId <= 0 || !self::ensure($db)) {
            return;
        }
        $note = trim((string)($ctx['note'] ?? ''));
        if ($note !== '') {
            $note = function_exists('mb_substr') ? mb_substr($note, 0, 255) : substr($note, 0, 255);
        }
        $date = trim((string)($ctx['effective_date'] ?? ''));
        try {
            $db->prepare(
                "INSERT INTO product_cost_history
                    (product_id, old_cost, new_cost, qty, unit_cost_in, stock_before, stock_after, source,
                     reference_type, reference_id, movement_id, note, user_id, effective_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $productId,
                $oldCost !== null ? round($oldCost, 2) : null,
                round(max(0, $newCost), 2),
                isset($ctx['qty']) ? (int)$ctx['qty'] : null,
                isset($ctx['unit_cost_in']) ? round((float)$ctx['unit_cost_in'], 2) : null,
                isset($ctx['stock_before']) ? (int)$ctx['stock_before'] : null,
                isset($ctx['stock_after']) ? (int)$ctx['stock_after'] : null,
                substr((string)($ctx['source'] ?? 'purchase'), 0, 20),
                !empty($ctx['reference_type']) ? substr((string)$ctx['reference_type'], 0, 40) : null,
                !empty($ctx['reference_id']) ? (int)$ctx['reference_id'] : null,
                !empty($ctx['movement_id']) ? (int)$ctx['movement_id'] : null,
                $note !== '' ? $note : null,
                $_SESSION['tenant_user_id'] ?? null,
                $date !== '' ? substr($date, 0, 10) : date('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            error_log('product_cost_history record: ' . $e->getMessage());
        }
    }

    /**
     * Ajusta el costo final de la última fila de una OC (p.ej. factura importada que fija el precio
     * de costo tras el ingreso). Si no hay fila, la crea.
     */
    public static function setPurchaseResult(\PDO $db, int $productId, int $orderId, ?float $oldCost, float $newCost): void
    {
        if ($productId <= 0 || !self::ensure($db)) {
            return;
        }
        try {
            $st = $db->prepare(
                "UPDATE product_cost_history SET new_cost = ?
                 WHERE product_id = ? AND reference_type = 'purchase_order' AND reference_id = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([round(max(0, $newCost), 2), $productId, $orderId]);
            if ($st->rowCount() > 0) {
                return;
            }
            $hit = $db->prepare(
                "SELECT 1 FROM product_cost_history
                 WHERE product_id = ? AND reference_type = 'purchase_order' AND reference_id = ? LIMIT 1"
            );
            $hit->execute([$productId, $orderId]);
            if ($hit->fetchColumn()) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        self::record($db, $productId, $oldCost, $newCost, [
            'source' => 'purchase',
            'reference_type' => 'purchase_order',
            'reference_id' => $orderId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forProduct(\PDO $db, int $productId, int $limit = 100): array
    {
        if ($productId <= 0 || !self::ensure($db)) {
            return [];
        }
        try {
            $st = $db->prepare(
                "SELECT h.*, u.name AS user_name, po.order_number, po.invoice_number, su.name AS supplier_name
                 FROM product_cost_history h
                 LEFT JOIN users u ON u.id = h.user_id
                 LEFT JOIN purchase_orders po ON po.id = h.reference_id AND h.reference_type = 'purchase_order'
                 LEFT JOIN suppliers su ON su.id = po.supplier_id
                 WHERE h.product_id = ?
                 ORDER BY h.id DESC
                 LIMIT " . max(1, min(500, $limit))
            );
            $st->execute([$productId]);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
