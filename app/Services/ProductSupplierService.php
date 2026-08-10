<?php

namespace SoftNova\Services;

/**
 * Relación producto-proveedor: un producto puede comprarse a varios proveedores,
 * cada uno con su propio código (SKU), costo, tiempo de entrega y mínimo de pedido.
 * El proveedor marcado como principal es el que se sugiere al reponer.
 */
class ProductSupplierService
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

    /**
     * Crea o actualiza el vínculo. Solo sobreescribe los campos enviados,
     * para que una compra no borre datos capturados a mano.
     *
     * @param array{supplier_sku?:string,supplier_product_name?:string,last_cost?:float,last_purchase_date?:?string,lead_time_days?:int,min_order_qty?:int,is_primary?:bool,notes?:string} $data
     */
    public function link(int $productId, int $supplierId, array $data = []): int
    {
        if ($productId <= 0 || $supplierId <= 0) {
            return 0;
        }

        $existing = $this->query(
            "SELECT id FROM product_suppliers WHERE product_id = ? AND supplier_id = ?",
            [$productId, $supplierId]
        )->fetch();

        $sku = isset($data['supplier_sku']) ? substr(trim((string)$data['supplier_sku']), 0, 120) : null;
        $supplierName = isset($data['supplier_product_name'])
            ? substr(trim((string)$data['supplier_product_name']), 0, 255)
            : null;
        $cost = isset($data['last_cost']) ? round(max(0, (float)$data['last_cost']), 2) : null;
        $purchaseDate = $this->normalizeDate($data['last_purchase_date'] ?? null);
        $leadTime = isset($data['lead_time_days']) ? max(0, min(365, (int)$data['lead_time_days'])) : null;
        $minOrder = isset($data['min_order_qty']) ? max(1, (int)$data['min_order_qty']) : null;
        $notes = isset($data['notes']) ? substr(trim((string)$data['notes']), 0, 255) : null;

        if ($existing) {
            $id = (int)$existing['id'];
            $this->query(
                "UPDATE product_suppliers SET
                    supplier_sku = COALESCE(NULLIF(?, ''), supplier_sku),
                    supplier_product_name = COALESCE(NULLIF(?, ''), supplier_product_name),
                    last_cost = COALESCE(?, last_cost),
                    last_purchase_date = COALESCE(?, last_purchase_date),
                    lead_time_days = COALESCE(?, lead_time_days),
                    min_order_qty = COALESCE(?, min_order_qty),
                    notes = COALESCE(NULLIF(?, ''), notes),
                    status = 'active'
                 WHERE id = ?",
                [$sku, $supplierName, $cost, $purchaseDate, $leadTime, $minOrder, $notes, $id]
            );
        } else {
            $this->query(
                "INSERT INTO product_suppliers
                    (product_id, supplier_id, supplier_sku, supplier_product_name, last_cost,
                     last_purchase_date, lead_time_days, min_order_qty, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $productId,
                    $supplierId,
                    $sku,
                    $supplierName,
                    $cost ?? 0.0,
                    $purchaseDate,
                    $leadTime ?? 0,
                    $minOrder ?? 1,
                    $notes,
                ]
            );
            $id = (int)$this->db->lastInsertId();
        }

        // El primer proveedor de un producto queda como principal por defecto.
        $shouldBePrimary = !empty($data['is_primary']) || $this->primaryCount($productId) === 0;
        if ($shouldBePrimary) {
            $this->setPrimary($productId, $supplierId);
        }

        return $id;
    }

    public function unlink(int $productId, int $supplierId): bool
    {
        $wasPrimary = (bool)$this->query(
            "SELECT is_primary FROM product_suppliers WHERE product_id = ? AND supplier_id = ?",
            [$productId, $supplierId]
        )->fetchColumn();

        $this->query(
            "DELETE FROM product_suppliers WHERE product_id = ? AND supplier_id = ?",
            [$productId, $supplierId]
        );

        // Sin principal no hay a quién pedir: se promueve el proveedor más barato.
        if ($wasPrimary) {
            $next = $this->query(
                "SELECT supplier_id FROM product_suppliers
                 WHERE product_id = ? AND status = 'active'
                 ORDER BY last_cost ASC, id ASC LIMIT 1",
                [$productId]
            )->fetchColumn();
            if ($next) {
                $this->setPrimary($productId, (int)$next);
            }
        }

        return true;
    }

    public function setPrimary(int $productId, int $supplierId): void
    {
        $this->query(
            "UPDATE product_suppliers SET is_primary = IF(supplier_id = ?, 1, 0) WHERE product_id = ?",
            [$supplierId, $productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function suppliersFor(int $productId): array
    {
        return $this->query(
            "SELECT ps.*, s.name supplier_name, s.email supplier_email, s.phone supplier_phone
             FROM product_suppliers ps
             JOIN suppliers s ON s.id = ps.supplier_id
             WHERE ps.product_id = ?
             ORDER BY ps.is_primary DESC, ps.last_cost ASC, s.name ASC",
            [$productId]
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function primaryFor(int $productId): ?array
    {
        $row = $this->query(
            "SELECT ps.*, s.name supplier_name, s.email supplier_email, s.phone supplier_phone
             FROM product_suppliers ps
             JOIN suppliers s ON s.id = ps.supplier_id
             WHERE ps.product_id = ? AND ps.status = 'active'
             ORDER BY ps.is_primary DESC, ps.last_cost ASC, ps.id ASC
             LIMIT 1",
            [$productId]
        )->fetch();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function productsFor(int $supplierId, int $limit = 200): array
    {
        return $this->query(
            "SELECT ps.*, p.code, p.name, p.stock, p.min_stock, p.purchase_price, p.sale_price
             FROM product_suppliers ps
             JOIN products p ON p.id = ps.product_id
             WHERE ps.supplier_id = ? AND p.status = 'active'
             ORDER BY p.name ASC
             LIMIT " . max(1, min(1000, $limit)),
            [$supplierId]
        )->fetchAll();
    }

    /**
     * Busca el producto que corresponde a una línea de factura del proveedor.
     * Prioridad: EAN/barcode → SKU proveedor → código interno → nombre exacto.
     *
     * @return array{product_id:int,match_type:string,confidence:int}|null
     */
    public function matchInvoiceLine(
        ?int $supplierId,
        string $supplierSku,
        string $description,
        string $barcode = ''
    ): ?array {
        $sku = trim($supplierSku);
        $description = trim($description);
        $barcode = $this->normalizeBarcode($barcode);

        if ($barcode !== '') {
            $row = $this->query(
                "SELECT id FROM products
                 WHERE barcode = ? OR code = ?
                 LIMIT 1",
                [$barcode, $barcode]
            )->fetch();
            if ($row) {
                return ['product_id' => (int)$row['id'], 'match_type' => 'ean', 'confidence' => 100];
            }
        }

        if ($supplierId && $sku !== '') {
            $row = $this->query(
                "SELECT product_id FROM product_suppliers
                 WHERE supplier_id = ? AND supplier_sku = ? AND status = 'active'
                 LIMIT 1",
                [$supplierId, $sku]
            )->fetch();
            if ($row) {
                return ['product_id' => (int)$row['product_id'], 'match_type' => 'supplier_sku', 'confidence' => 100];
            }
        }

        if ($sku !== '') {
            $row = $this->query(
                "SELECT id FROM products WHERE code = ? OR barcode = ? LIMIT 1",
                [$sku, $sku]
            )->fetch();
            if ($row) {
                return ['product_id' => (int)$row['id'], 'match_type' => 'code', 'confidence' => 95];
            }
        }

        if ($description !== '') {
            $row = $this->query(
                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$description]
            )->fetch();
            if ($row) {
                return ['product_id' => (int)$row['id'], 'match_type' => 'name', 'confidence' => 80];
            }
        }

        return null;
    }

    private function normalizeBarcode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $compact = preg_replace('/[\s\-]/', '', $value) ?? $value;
        return substr($compact, 0, 64);
    }

    /**
     * Registra el vínculo tras recibir una orden de compra, guardando el costo real.
     */
    public function syncFromPurchaseOrder(int $orderId): int
    {
        $rows = $this->query(
            "SELECT o.supplier_id, o.order_date, i.product_id, i.unit_cost
             FROM purchase_orders o
             JOIN purchase_order_items i ON i.purchase_order_id = o.id
             WHERE o.id = ? AND o.supplier_id IS NOT NULL AND i.product_id IS NOT NULL",
            [$orderId]
        )->fetchAll();

        $linked = 0;
        foreach ($rows as $row) {
            $this->link((int)$row['product_id'], (int)$row['supplier_id'], [
                'last_cost' => (float)$row['unit_cost'],
                'last_purchase_date' => substr((string)$row['order_date'], 0, 10),
            ]);
            $linked++;
        }

        return $linked;
    }

    /**
     * Deriva vínculos a partir del histórico de entradas de inventario.
     * Sirve para poblar la relación en instalaciones que ya venían operando.
     *
     * @return array{linked:int}
     */
    public function backfillFromHistory(int $limit = 500): array
    {
        $linked = 0;
        try {
            $rows = $this->query(
                "SELECT sm.product_id, sm.supplier_id,
                        MAX(COALESCE(sm.movement_date, DATE(sm.created_at))) last_date,
                        AVG(NULLIF(sm.unit_cost, 0)) avg_cost
                 FROM stock_movements sm
                 WHERE sm.supplier_id IS NOT NULL AND sm.type = 'in'
                 GROUP BY sm.product_id, sm.supplier_id
                 ORDER BY last_date DESC
                 LIMIT " . max(1, min(2000, $limit))
            )->fetchAll();
        } catch (\Throwable $e) {
            return ['linked' => 0];
        }

        foreach ($rows as $row) {
            $this->link((int)$row['product_id'], (int)$row['supplier_id'], [
                'last_cost' => (float)($row['avg_cost'] ?? 0),
                'last_purchase_date' => (string)$row['last_date'],
            ]);
            $linked++;
        }

        return ['linked' => $linked];
    }

    private function primaryCount(int $productId): int
    {
        return (int)$this->query(
            "SELECT COUNT(*) FROM product_suppliers WHERE product_id = ? AND is_primary = 1",
            [$productId]
        )->fetchColumn();
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
