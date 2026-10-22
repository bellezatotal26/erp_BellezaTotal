<?php

namespace SoftNova\Services;

/**
 * Compras formales: Orden de Compra → recepción/factura → inventario + contabilidad.
 */
class PurchasingService
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

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(40) NOT NULL UNIQUE,
                supplier_id INT UNSIGNED NULL,
                status ENUM('draft','ordered','received','cancelled') NOT NULL DEFAULT 'draft',
                order_date DATE NOT NULL,
                warehouse_date DATE NULL,
                expected_date DATE NULL,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                invoice_number VARCHAR(80) NULL,
                payment_mode VARCHAR(20) NULL DEFAULT 'payable',
                notes VARCHAR(255) NULL,
                accounting_entry_id BIGINT UNSIGNED NULL,
                received_at DATETIME NULL,
                received_by INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_po_status (status),
                INDEX idx_po_supplier (supplier_id),
                INDEX idx_po_order_date (order_date),
                INDEX idx_po_warehouse_date (warehouse_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_order_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_order_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                received_qty INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_poi_po (purchase_order_id),
                INDEX idx_poi_product (product_id),
                CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id)
                    REFERENCES purchase_orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Columnas avanzadas (descuento pronto pago + fechas)
        try {
            $cols = [];
            foreach ($this->db->query('SHOW COLUMNS FROM purchase_orders')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            $alters = [];
            if (!isset($cols['early_payment_discount_pct'])) {
                $alters[] = 'ADD COLUMN early_payment_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER total';
            }
            if (!isset($cols['early_payment_discount_amount'])) {
                $alters[] = 'ADD COLUMN early_payment_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER early_payment_discount_pct';
            }
            if (!isset($cols['commercial_discount_pct'])) {
                $alters[] = 'ADD COLUMN commercial_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER early_payment_discount_amount';
            }
            if (!isset($cols['commercial_discount_amount'])) {
                $alters[] = 'ADD COLUMN commercial_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER commercial_discount_pct';
            }
            if (!isset($cols['supplier_invoice_value'])) {
                $alters[] = 'ADD COLUMN supplier_invoice_value DECIMAL(14,2) NULL AFTER total';
            }
            if (!isset($cols['due_date'])) {
                $alters[] = 'ADD COLUMN due_date DATE NULL AFTER expected_date';
            }
            if (!isset($cols['invoice_date'])) {
                $alters[] = 'ADD COLUMN invoice_date DATE NULL AFTER invoice_number';
            }
            if (!isset($cols['invoice_path'])) {
                $alters[] = 'ADD COLUMN invoice_path VARCHAR(255) NULL AFTER invoice_date';
            }
            if (!isset($cols['invoice_original_name'])) {
                $alters[] = 'ADD COLUMN invoice_original_name VARCHAR(255) NULL AFTER invoice_path';
            }
            if (!isset($cols['invoice_mime'])) {
                $alters[] = 'ADD COLUMN invoice_mime VARCHAR(100) NULL AFTER invoice_original_name';
            }
            if ($alters) {
                $this->db->exec('ALTER TABLE purchase_orders ' . implode(', ', $alters));
            }
        } catch (\Throwable $e) {
        }

        // Proveedor aliado
        try {
            $cols = [];
            foreach ($this->db->query('SHOW COLUMNS FROM suppliers')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[strtolower((string)$c['Field'])] = true;
            }
            $alters = [];
            if (!isset($cols['is_ally'])) {
                $alters[] = 'ADD COLUMN is_ally TINYINT(1) NOT NULL DEFAULT 0 AFTER status';
            }
            if (!isset($cols['discount_percent'])) {
                $alters[] = 'ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER is_ally';
            }
            if ($alters) {
                $this->db->exec('ALTER TABLE suppliers ' . implode(', ', $alters));
            }
        } catch (\Throwable $e) {
        }

        $done = true;

        // Columnas de lote y pagos: dependen de que las tablas ya existan.
        SupplyChainSchema::ensure($this->db);
    }

    public function nextOrderNumber(?string $date = null): string
    {
        $date = $date ?: date('Y-m-d');
        $prefix = 'OC-' . date('Ym', strtotime($date)) . '-';
        $last = $this->query(
            "SELECT order_number FROM purchase_orders
             WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1",
            [$prefix . '%']
        )->fetchColumn();
        $seq = $last ? ((int)substr((string)$last, -4) + 1) : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Totales de OC/factura: bruto − desc. comercial (siempre) − desc. pronto pago (si aplica %).
     * El comercial acepta % o monto en moneda (si monto > 0, manda el monto).
     *
     * @return array{
     *   gross:float,commercial_pct:float,commercial_amount:float,
     *   early_pct:float,early_amount:float,total:float
     * }
     */
    public static function resolvePurchaseDiscounts(
        float $subtotal,
        float $tax,
        float $earlyPct = 0.0,
        float $commercialPct = 0.0,
        float $commercialAmountInput = 0.0
    ): array {
        $subtotal = round(max(0, $subtotal), 2);
        $tax = round(max(0, $tax), 2);
        $gross = round($subtotal + $tax, 2);
        $earlyPct = max(0, min(100, round($earlyPct, 2)));
        // % comercial siempre entero (0–100).
        $commercialPct = max(0, min(100, (float)(int)round($commercialPct)));
        $commercialAmountInput = max(0, round($commercialAmountInput, 2));

        // Desc. comercial sobre el subtotal (antes de IVA): Total bruto − dcto. líneas − comercial = Sub total → + IVA = Neto.
        // Si hay monto en $, prevalece; el % guardado queda en 0 para no recalcular mal después.
        $amountWins = $commercialAmountInput > 0.009;
        $commercialAmt = $amountWins
            ? min($commercialAmountInput, $subtotal)
            : round($subtotal * ($commercialPct / 100), 2);
        $storeCommercialPct = $amountWins ? 0.0 : $commercialPct;

        $netSubtotal = max(0, round($subtotal - $commercialAmt, 2));
        $afterCommercial = round($netSubtotal + $tax, 2);
        $earlyAmt = round($afterCommercial * ($earlyPct / 100), 2);
        $total = max(0, round($afterCommercial - $earlyAmt, 2));

        return [
            'gross' => $gross,
            'commercial_pct' => $storeCommercialPct,
            'commercial_amount' => $commercialAmt,
            'early_pct' => $earlyPct,
            'early_amount' => $earlyAmt,
            'total' => $total,
        ];
    }

    /**
     * @param array<int,array{product_id?:int,product_name?:string,quantity:int,unit_cost:float,tax_rate?:float}> $items
     */
    public function createOrder(
        ?int $supplierId,
        string $orderDate,
        ?string $warehouseDate,
        ?string $expectedDate,
        string $notes,
        array $items,
        string $status = 'ordered',
        float $earlyPaymentDiscountPct = 0.0,
        ?string $dueDate = null,
        ?string $invoiceDate = null,
        ?string $invoiceNumber = null,
        bool $autoApplyAllyDiscount = true,
        float $commercialDiscountPct = 0.0,
        float $commercialDiscountAmount = 0.0
    ): int {
        if (!in_array($status, ['draft', 'ordered'], true)) {
            $status = 'ordered';
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('Agregue al menos un producto a la orden');
        }

        // Descuento aliado solo si se permite auto y no se envió un % explícito (>0).
        // Con 0.0 y autoApplyAllyDiscount=false (importaciones) se respeta el cero.
        if ($autoApplyAllyDiscount && $earlyPaymentDiscountPct <= 0 && $supplierId) {
            $ally = $this->query(
                "SELECT is_ally, discount_percent FROM suppliers WHERE id = ?",
                [$supplierId]
            )->fetch();
            if ($ally && (int)$ally['is_ally'] === 1 && (float)$ally['discount_percent'] > 0) {
                $earlyPaymentDiscountPct = (float)$ally['discount_percent'];
            }
        }

        $normalized = [];
        $subtotal = 0.0;
        $tax = 0.0;
        $productIds = [];
        foreach ($items as $item) {
            $pid = !empty($item['product_id']) ? (int)$item['product_id'] : 0;
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        $productMeta = $this->loadProductCodeMeta(array_keys($productIds));

        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $cost = round((float)($item['unit_cost'] ?? 0), 2);
            $productId = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            if ($qty <= 0 || $cost < 0) {
                continue;
            }
            $lineSub = round($qty * $cost, 2);
            [$rate, $lineTax] = $this->resolveItemTax($item, $lineSub, $productId, $productMeta);
            $name = trim((string)($item['product_name'] ?? ''));
            if ($name === '' && $productId) {
                $name = (string)($productMeta[$productId]['name']
                    ?? $this->query("SELECT name FROM products WHERE id = ?", [$productId])->fetchColumn());
            }
            if ($name === '') {
                continue;
            }
            [$snapBarcode, $snapCode] = $this->productCodeSnapshot($productId, $item, $productMeta);
            $normalized[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'inventory_cost' => isset($item['inventory_cost']) && (float)$item['inventory_cost'] > 0.009
                    ? round((float)$item['inventory_cost'], 2)
                    : null,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'subtotal' => $lineSub,
                'line_total' => round($lineSub + $lineTax, 2),
                'lot_code' => substr(trim((string)($item['lot_code'] ?? '')), 0, 60) ?: null,
                'expiry_date' => $this->validExpiry($item['expiry_date'] ?? null),
                'barcode' => $snapBarcode,
                'product_code' => $snapCode,
            ];
            $subtotal += $lineSub;
            $tax += $lineTax;
        }
        if (empty($normalized)) {
            throw new \InvalidArgumentException('Los ítems de la orden no son válidos');
        }

        $gross = round($subtotal + $tax, 2);
        $earlyPaymentDiscountPct = max(0, min(100, round($earlyPaymentDiscountPct, 2)));
        $commercialDiscountPct = max(0, min(100, round($commercialDiscountPct, 2)));
        $commercialDiscountAmount = max(0, round($commercialDiscountAmount, 2));
        $disc = self::resolvePurchaseDiscounts(
            $subtotal,
            $tax,
            $earlyPaymentDiscountPct,
            $commercialDiscountPct,
            $commercialDiscountAmount
        );
        $discountAmt = $disc['early_amount'];
        $commercialAmt = $disc['commercial_amount'];
        $commercialDiscountPct = $disc['commercial_pct'];
        $total = $disc['total'];

        $orderNumber = $this->nextOrderNumber($orderDate);
        try {
            $this->query(
                "INSERT INTO purchase_orders
                    (order_number, supplier_id, status, order_date, warehouse_date, expected_date, due_date,
                     subtotal, tax, total, early_payment_discount_pct, early_payment_discount_amount,
                     commercial_discount_pct, commercial_discount_amount,
                     invoice_number, invoice_date, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $orderNumber,
                    $supplierId,
                    $status,
                    $orderDate,
                    $warehouseDate,
                    $expectedDate,
                    $dueDate,
                    round($subtotal, 2),
                    round($tax, 2),
                    $total,
                    $earlyPaymentDiscountPct,
                    $discountAmt,
                    $commercialDiscountPct,
                    $commercialAmt,
                    $invoiceNumber,
                    $invoiceDate,
                    $notes !== '' ? $notes : null,
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            // Instala sin columnas comerciales aún.
            $this->query(
                "INSERT INTO purchase_orders
                    (order_number, supplier_id, status, order_date, warehouse_date, expected_date, due_date,
                     subtotal, tax, total, early_payment_discount_pct, early_payment_discount_amount,
                     invoice_number, invoice_date, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $orderNumber,
                    $supplierId,
                    $status,
                    $orderDate,
                    $warehouseDate,
                    $expectedDate,
                    $dueDate,
                    round($subtotal, 2),
                    round($tax, 2),
                    $total,
                    $earlyPaymentDiscountPct,
                    $discountAmt,
                    $invoiceNumber,
                    $invoiceDate,
                    $notes !== '' ? $notes : null,
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
        }
        $orderId = (int)$this->db->lastInsertId();

        $this->insertOrderItemRows($orderId, $normalized);

        return $orderId;
    }

    /**
     * @param list<array<string,mixed>> $rows líneas ya normalizadas
     */
    private function insertOrderItemRows(int $orderId, array $rows): void
    {
        $withInv = false;
        foreach ($rows as $row) {
            if (($row['inventory_cost'] ?? null) !== null) {
                $withInv = true;
                break;
            }
        }
        if ($withInv) {
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO purchase_order_items
                        (purchase_order_id, product_id, product_name, barcode, product_code, quantity, unit_cost,
                         inventory_cost, tax_rate, tax_amount, subtotal, line_total, lot_code, expiry_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($rows as $row) {
                    $stmt->execute([
                        $orderId,
                        $row['product_id'],
                        $row['product_name'],
                        $row['barcode'],
                        $row['product_code'],
                        $row['quantity'],
                        $row['unit_cost'],
                        $row['inventory_cost'] ?? null,
                        $row['tax_rate'],
                        $row['tax_amount'],
                        $row['subtotal'],
                        $row['line_total'],
                        $row['lot_code'],
                        $row['expiry_date'],
                    ]);
                }
                return;
            } catch (\PDOException $e) {
                // Columna inventory_cost aún no migrada: insertar sin ella.
                $this->query("DELETE FROM purchase_order_items WHERE purchase_order_id = ?", [$orderId]);
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO purchase_order_items
                (purchase_order_id, product_id, product_name, barcode, product_code, quantity, unit_cost,
                 tax_rate, tax_amount, subtotal, line_total, lot_code, expiry_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($rows as $row) {
            $stmt->execute([
                $orderId,
                $row['product_id'],
                $row['product_name'],
                $row['barcode'],
                $row['product_code'],
                $row['quantity'],
                $row['unit_cost'],
                $row['tax_rate'],
                $row['tax_amount'],
                $row['subtotal'],
                $row['line_total'],
                $row['lot_code'],
                $row['expiry_date'],
            ]);
        }
    }

    /**
     * Resuelve tasa e IVA de línea. Si viene tax_amount (p.ej. prorrateo de IVA personalizado),
     * ese monto manda y la tasa se deriva para trazabilidad.
     *
     * @return array{0: float, 1: float} [tax_rate, tax_amount]
     */
    private function resolveItemTax(array $item, float $lineSub, ?int $productId, array $productMeta): array
    {
        if (array_key_exists('tax_amount', $item) && $item['tax_amount'] !== '' && $item['tax_amount'] !== null) {
            $lineTax = round(max(0, (float)$item['tax_amount']), 2);
            $rate = $lineSub > 0.009 ? round(($lineTax / $lineSub) * 100, 4) : 0.0;
            return [$rate, $lineTax];
        }
        if (array_key_exists('tax_rate', $item) && $item['tax_rate'] !== '' && $item['tax_rate'] !== null) {
            $rate = round(max(0, (float)$item['tax_rate']), 2);
        } else {
            $rate = round(max(0, (float)($productMeta[$productId]['tax_rate'] ?? 0)), 2);
        }
        return [$rate, round($lineSub * ($rate / 100), 2)];
    }

    /**
     * Prorratea un IVA de documento entre líneas por peso de subtotal (último renglón absorbe centavos).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function applyTaxOverrideToLines(array $items, float $taxOverride): array
    {
        $taxOverride = round(max(0, $taxOverride), 2);
        $bases = [];
        $sumBase = 0.0;
        foreach ($items as $i => $item) {
            $qty = max(0, (float)($item['quantity'] ?? 0));
            $cost = max(0, (float)($item['unit_cost'] ?? 0));
            $base = round($qty * $cost, 2);
            $bases[$i] = $base;
            $sumBase += $base;
        }
        $assigned = 0.0;
        $keys = array_keys($bases);
        $lastIdx = $keys !== [] ? (int)end($keys) : -1;
        foreach ($items as $i => &$item) {
            $base = $bases[$i] ?? 0.0;
            if ($taxOverride <= 0 || $sumBase <= 0.009) {
                $share = 0.0;
            } elseif ($i === $lastIdx) {
                $share = round($taxOverride - $assigned, 2);
            } else {
                $remaining = round($taxOverride - $assigned, 2);
                $share = round($taxOverride * ($base / $sumBase), 2);
                if ($share > $remaining) {
                    $share = $remaining;
                }
                $share = max(0.0, $share);
                $assigned = round($assigned + $share, 2);
            }
            $item['tax_amount'] = round(max(0, $share), 2);
            $item['tax_rate'] = $base > 0.009 ? round(($share / $base) * 100, 4) : 0.0;
        }
        unset($item);
        return $items;
    }

    private function validExpiry(?string $date): ?string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
    }

    public function getOrder(int $id): ?array
    {
        $order = $this->query(
            "SELECT po.*, s.name supplier_name, s.document_number supplier_document,
                    u.name created_by_name, e.entry_number accounting_entry_number
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             LEFT JOIN users u ON u.id = po.created_by
             LEFT JOIN accounting_entries e ON e.id = po.accounting_entry_id
             WHERE po.id = ?",
            [$id]
        )->fetch();
        if (!$order) {
            return null;
        }
        $order['items'] = $this->query(
            "SELECT poi.*,
                    COALESCE(NULLIF(poi.barcode, ''), p.barcode) AS barcode,
                    COALESCE(NULLIF(poi.product_code, ''), p.code) AS product_code,
                    COALESCE((
                        SELECT SUM(ci.quantity)
                        FROM purchase_credit_note_items ci
                        JOIN purchase_credit_notes cn ON cn.id = ci.credit_note_id
                        WHERE ci.purchase_order_item_id = poi.id AND cn.status = 'posted'
                    ), 0) credited_qty
             FROM purchase_order_items poi
             LEFT JOIN products p ON p.id = poi.product_id
             WHERE poi.purchase_order_id = ?
             ORDER BY poi.id",
            [$id]
        )->fetchAll();
        return $order;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,array{code:?string,barcode:?string,name?:string}> $metaCache
     * @return array{0:?string,1:?string} [barcode, product_code]
     */
    private function productCodeSnapshot(?int $productId, array $item, array $metaCache = []): array
    {
        $barcode = !empty($item['barcode']) ? substr(trim((string)$item['barcode']), 0, 64) : null;
        $code = !empty($item['product_code']) ? substr(trim((string)$item['product_code']), 0, 255) : null;
        if ($productId && ($barcode === null || $code === null)) {
            $prod = $metaCache[$productId] ?? null;
            if ($prod === null) {
                $prod = $this->query(
                    "SELECT code, barcode, name FROM products WHERE id = ? LIMIT 1",
                    [$productId]
                )->fetch() ?: [];
            }
            if ($barcode === null) {
                $barcode = trim((string)($prod['barcode'] ?? '')) ?: null;
            }
            if ($code === null) {
                $code = trim((string)($prod['code'] ?? '')) ?: null;
            }
        }
        return [$barcode, $code];
    }

    /**
     * @param list<int> $productIds
     * @return array<int,array{code:?string,barcode:?string,name:?string}>
     */
    private function loadProductCodeMeta(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->query(
            "SELECT id, code, barcode, name, COALESCE(tax_rate, 0) AS tax_rate FROM products WHERE id IN ($placeholders)",
            $productIds
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = [
                'code' => $row['code'] ?? null,
                'barcode' => $row['barcode'] ?? null,
                'name' => $row['name'] ?? null,
                'tax_rate' => (float)($row['tax_rate'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Adjunta foto/PDF de factura proveedor a la OC.
     * @param array{path:string,original:string,mime:string} $file
     */
    public function attachInvoiceFile(int $orderId, array $file): void
    {
        $this->ensureSchema();
        $this->query(
            "UPDATE purchase_orders
             SET invoice_path = ?, invoice_original_name = ?, invoice_mime = ?
             WHERE id = ?",
            [
                $file['path'] ?? null,
                $file['original'] ?? null,
                $file['mime'] ?? null,
                $orderId,
            ]
        );
    }

    /**
     * @param string $dueFilter overdue|due_today|due_soon|no_due|''
     * @return list<array<string,mixed>>
     */
    public function listOrders(int $limit = 50, int $offset = 0, string $status = '', string $dueFilter = '', array $filters = []): array
    {
        [$whereSql, $params] = $this->orderFilters($status, $dueFilter, $filters);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $customOrder = trim((string)($filters['orderSql'] ?? ''));
        if ($customOrder !== '') {
            $orderBy = 'ORDER BY ' . $customOrder . ', po.id DESC';
        } else {
            $orderBy = $dueFilter !== ''
                ? 'ORDER BY po.due_date IS NULL, po.due_date ASC, po.id DESC'
                : 'ORDER BY po.order_date DESC, po.id DESC';
        }
        return $this->query(
            "SELECT po.*, s.name supplier_name,
                    DATEDIFF(CURDATE(), po.due_date) AS days_overdue
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             {$whereSql}
             {$orderBy}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    public function countOrders(string $status = '', string $dueFilter = '', array $filters = []): int
    {
        [$whereSql, $params] = $this->orderFilters($status, $dueFilter, $filters);
        return (int)$this->query(
            "SELECT COUNT(*) FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             {$whereSql}",
            $params
        )->fetchColumn();
    }

    /**
     * @param array{q?:string,supplier_id?:int,from?:string,to?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function orderFilters(string $status, string $dueFilter, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if ($status === 'open') {
            // Pedidos aún no facturados/recibidos
            $where[] = "po.status IN ('draft','ordered')";
        } elseif ($status === 'invoices') {
            $where[] = "po.status = 'received'";
        } elseif ($status !== '' && in_array($status, ['draft', 'ordered', 'received', 'cancelled'], true)) {
            $where[] = 'po.status = ?';
            $params[] = $status;
        }
        $dueFilter = strtolower(trim($dueFilter));
        if ($dueFilter === 'overdue') {
            $where[] = 'po.due_date IS NOT NULL AND po.due_date < CURDATE()';
            $where[] = "po.status = 'received'";
            $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
            $where[] = $this->balancePendingSql();
        } elseif ($dueFilter === 'due_today') {
            $where[] = 'po.due_date = CURDATE()';
            $where[] = "po.status = 'received'";
            $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
            $where[] = $this->balancePendingSql();
        } elseif ($dueFilter === 'due_soon') {
            $where[] = 'po.due_date IS NOT NULL AND po.due_date >= CURDATE() AND po.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
            $where[] = "po.status = 'received'";
            $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
            $where[] = $this->balancePendingSql();
        } elseif ($dueFilter === 'no_due') {
            $where[] = 'po.due_date IS NULL';
        }
        $this->appendListSearchFilters($where, $params, $filters, 'order_date');
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function balancePendingSql(): string
    {
        return "po.total - COALESCE(po.paid_total, 0) - COALESCE(po.credit_total, 0) > 0.009";
    }

    /**
     * Filtros comunes de búsqueda / proveedor / fechas para OC y facturas.
     *
     * @param list<string> $where
     * @param list<mixed> $params
     * @param array{q?:string,supplier_id?:int,from?:string,to?:string} $filters
     */
    private function appendListSearchFilters(array &$where, array &$params, array $filters, string $dateColumn): void
    {
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(po.order_number LIKE ? OR po.invoice_number LIKE ? OR s.name LIKE ?
                OR po.notes LIKE ? OR CAST(po.id AS CHAR) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $supplierId = (int)($filters['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $where[] = 'po.supplier_id = ?';
            $params[] = $supplierId;
        }
        $from = trim((string)($filters['from'] ?? ''));
        $to = trim((string)($filters['to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = "COALESCE(po.{$dateColumn}, po.order_date) >= ?";
            $params[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = "COALESCE(po.{$dateColumn}, po.order_date) <= ?";
            $params[] = $to;
        }
    }

    /**
     * OC abiertas (borrador/ordenada) para casar con una factura de proveedor.
     *
     * @return list<array<string,mixed>>
     */
    public function openOrdersForMatch(?int $supplierId = null, int $limit = 80): array
    {
        $where = ["po.status IN ('draft','ordered')"];
        $params = [];
        if ($supplierId !== null && $supplierId > 0) {
            $where[] = '(po.supplier_id = ? OR po.supplier_id IS NULL)';
            $params[] = $supplierId;
        }
        $limit = max(1, min(200, $limit));
        return $this->query(
            "SELECT po.id, po.order_number, po.order_date, po.expected_date, po.total,
                    po.supplier_id, s.name AS supplier_name
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY po.order_date DESC, po.id DESC
             LIMIT {$limit}",
            $params
        )->fetchAll();
    }

    /**
     * Sustituye los ítems de una OC pendiente (p. ej. al casar factura real con el pedido).
     *
     * @param list<array{product_id?:int,product_name?:string,quantity:int,unit_cost:float,tax_rate?:float,lot_code?:?string,expiry_date?:?string}> $items
     */
    public function replaceOrderItems(int $orderId, array $items): void
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new \RuntimeException('Solo se pueden actualizar ítems en OC pendientes de recibir');
        }

        $normalized = [];
        $subtotal = 0.0;
        $tax = 0.0;
        $productIds = [];
        foreach ($items as $item) {
            $pid = !empty($item['product_id']) ? (int)$item['product_id'] : 0;
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        $productMeta = $this->loadProductCodeMeta(array_keys($productIds));

        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $cost = round((float)($item['unit_cost'] ?? 0), 2);
            $productId = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            if ($qty <= 0 || $cost < 0) {
                continue;
            }
            $lineSub = round($qty * $cost, 2);
            [$rate, $lineTax] = $this->resolveItemTax($item, $lineSub, $productId, $productMeta);
            $name = trim((string)($item['product_name'] ?? ''));
            if ($name === '' && $productId) {
                $name = (string)($productMeta[$productId]['name']
                    ?? $this->query("SELECT name FROM products WHERE id = ?", [$productId])->fetchColumn());
            }
            if ($name === '') {
                continue;
            }
            [$snapBarcode, $snapCode] = $this->productCodeSnapshot($productId, $item, $productMeta);
            $normalized[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'inventory_cost' => isset($item['inventory_cost']) && (float)$item['inventory_cost'] > 0.009
                    ? round((float)$item['inventory_cost'], 2)
                    : null,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'subtotal' => $lineSub,
                'line_total' => round($lineSub + $lineTax, 2),
                'lot_code' => substr(trim((string)($item['lot_code'] ?? '')), 0, 60) ?: null,
                'expiry_date' => $this->validExpiry($item['expiry_date'] ?? null),
                'barcode' => $snapBarcode,
                'product_code' => $snapCode,
            ];
            $subtotal += $lineSub;
            $tax += $lineTax;
        }
        if (empty($normalized)) {
            throw new \InvalidArgumentException('Los ítems de la factura no son válidos');
        }

        $earlyPct = (float)($order['early_payment_discount_pct'] ?? 0);
        $commercialPct = (float)($order['commercial_discount_pct'] ?? 0);
        $commercialAmtIn = (float)($order['commercial_discount_amount'] ?? 0);
        // Si ya hay monto comercial guardado sin %, úsalo como entrada fija.
        $disc = self::resolvePurchaseDiscounts(
            $subtotal,
            $tax,
            $earlyPct,
            $commercialPct,
            $commercialPct > 0.009 ? 0.0 : $commercialAmtIn
        );
        $discountAmt = $disc['early_amount'];
        $commercialAmt = $disc['commercial_amount'];
        $total = $disc['total'];

        $this->query("DELETE FROM purchase_order_items WHERE purchase_order_id = ?", [$orderId]);
        $this->insertOrderItemRows($orderId, $normalized);

        try {
            $this->query(
                "UPDATE purchase_orders SET subtotal = ?, tax = ?, total = ?,
                    early_payment_discount_amount = ?,
                    commercial_discount_amount = ?
                 WHERE id = ?",
                [round($subtotal, 2), round($tax, 2), $total, $discountAmt, $commercialAmt, $orderId]
            );
        } catch (\Throwable $e) {
            $this->query(
                "UPDATE purchase_orders SET subtotal = ?, tax = ?, total = ?,
                    early_payment_discount_amount = ?
                 WHERE id = ?",
                [round($subtotal, 2), round($tax, 2), $total, $discountAmt, $orderId]
            );
        }
    }

    /**
     * Actualiza cabecera e ítems de una OC pendiente (borrador u ordenada).
     *
     * @param list<array{product_id?:int,product_name?:string,quantity:int,unit_cost:float,tax_rate?:float}> $items
     */
    public function updatePendingOrder(
        int $orderId,
        ?int $supplierId,
        string $orderDate,
        ?string $expectedDate,
        string $notes,
        array $items,
        string $status = 'ordered'
    ): void {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new \RuntimeException('Solo se editan OC pendientes de recibir');
        }
        if (!in_array($status, ['draft', 'ordered'], true)) {
            $status = (string)$order['status'];
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('Agregue al menos un producto a la orden');
        }
        $this->db->beginTransaction();
        try {
            $this->replaceOrderItems($orderId, $items);
            $this->query(
                "UPDATE purchase_orders SET supplier_id = ?, order_date = ?, expected_date = ?, notes = ?, status = ? WHERE id = ?",
                [
                    $supplierId ?: null,
                    $orderDate !== '' ? $orderDate : date('Y-m-d'),
                    $expectedDate !== '' ? $expectedDate : null,
                    $notes !== '' ? $notes : null,
                    $status,
                    $orderId,
                ]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Listado de facturas = OC recibidas, con filtros de CxP.
     *
     * @return list<array<string,mixed>>
     */
    public function listInvoices(int $limit = 50, int $offset = 0, string $paymentFilter = '', string $dueFilter = '', array $filters = []): array
    {
        [$whereSql, $params] = $this->invoiceFilters($paymentFilter, $dueFilter, $filters);
        $limit = max(1, min(20000, $limit));
        $offset = max(0, $offset);
        $customOrder = trim((string)($filters['orderSql'] ?? ''));
        if ($customOrder !== '') {
            $orderBy = $customOrder . ', po.id DESC';
        } else {
            $byDue = strtolower(trim($paymentFilter)) === 'unpaid'
                || in_array(strtolower(trim($dueFilter)), ['overdue', 'due_today', 'due_soon'], true);
            $orderBy = $byDue
                ? 'po.due_date IS NULL, po.due_date ASC, po.id ASC'
                : 'COALESCE(po.invoice_date, po.warehouse_date, po.order_date) DESC, po.id DESC';
        }
        return $this->query(
            "SELECT po.*, s.name supplier_name,
                    DATEDIFF(CURDATE(), po.due_date) AS days_overdue,
                    COALESCE((
                        SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) AS payment_discount_total
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    /**
     * Conteo y saldo pendiente (solo facturas a crédito) para un filtro de facturas.
     *
     * @return array{count:int,balance:float}
     */
    public function invoiceSummary(string $paymentFilter = '', string $dueFilter = '', array $filters = []): array
    {
        [$whereSql, $params] = $this->invoiceFilters($paymentFilter, $dueFilter, $filters);
        try {
            $row = $this->query(
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(CASE
                            WHEN po.status = 'received' AND COALESCE(po.payment_mode, 'payable') = 'payable'
                            THEN GREATEST(COALESCE(NULLIF(po.supplier_invoice_value, 0), po.total) - COALESCE(po.paid_total, 0) - COALESCE(po.credit_total, 0) - COALESCE((
                                SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                                WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                            ), 0), 0)
                            ELSE 0 END), 0) AS balance
                 FROM purchase_orders po
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 {$whereSql}",
                $params
            )->fetch() ?: [];
        } catch (\Throwable $e) {
            $row = [];
        }
        return [
            'count' => (int)($row['cnt'] ?? 0),
            'balance' => round((float)($row['balance'] ?? 0), 2),
        ];
    }

    /**
     * Resumen por filtro rápido de facturas (respeta búsqueda, proveedor y fechas).
     *
     * @return array<string,array{count:int,balance:float}>
     */
    public function invoiceQuickSummary(array $filters = []): array
    {
        $map = [
            'all' => ['', ''],
            'unpaid' => ['unpaid', ''],
            'overdue' => ['', 'overdue'],
            'due_soon' => ['', 'due_soon'],
            'paid_only' => ['paid_only', ''],
            'voided' => ['voided', ''],
        ];
        $out = [];
        foreach ($map as $key => [$payment, $due]) {
            $out[$key] = $this->invoiceSummary($payment, $due, $filters);
        }
        return $out;
    }

    public function countInvoices(string $paymentFilter = '', string $dueFilter = '', array $filters = []): int
    {
        [$whereSql, $params] = $this->invoiceFilters($paymentFilter, $dueFilter, $filters);
        return (int)$this->query(
            "SELECT COUNT(*) FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             {$whereSql}",
            $params
        )->fetchColumn();
    }

    /**
     * @param array{q?:string,supplier_id?:int,from?:string,to?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function invoiceFilters(string $paymentFilter, string $dueFilter, array $filters = []): array
    {
        $paymentFilter = strtolower(trim($paymentFilter));
        $params = [];

        // Facturas activas + anuladas post-recepción (trazabilidad). Cancelaciones de OC
        // abiertas (nunca recibidas) siguen fuera de este panel.
        if ($paymentFilter === 'voided') {
            $where = [
                "po.status = 'cancelled'",
                $this->postReceiveVoidSql('po'),
            ];
        } else {
            $where = [
                "(po.status = 'received' OR (po.status = 'cancelled' AND " . $this->postReceiveVoidSql('po') . '))',
            ];
            if ($paymentFilter === 'pending' || $paymentFilter === 'partial' || $paymentFilter === 'paid') {
                $where[] = "po.status = 'received'";
                $where[] = 'po.payment_status = ?';
                $params[] = $paymentFilter;
            } elseif ($paymentFilter === 'unpaid') {
                $where[] = "po.status = 'received'";
                $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
                $where[] = $this->balancePendingSql();
            } elseif ($paymentFilter === 'paid_only') {
                $where[] = "po.status = 'received'";
                $where[] = 'po.total - COALESCE(po.paid_total, 0) - COALESCE(po.credit_total, 0) <= 0.009';
            } elseif ($paymentFilter === 'active') {
                $where = ["po.status = 'received'"];
            }
        }

        $dueFilter = strtolower(trim($dueFilter));
        if ($dueFilter === 'overdue') {
            $where[] = "po.status = 'received'";
            $where[] = 'po.due_date IS NOT NULL AND po.due_date < CURDATE()';
            $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
            $where[] = $this->balancePendingSql();
        } elseif ($dueFilter === 'due_today') {
            $where[] = "po.status = 'received'";
            $where[] = 'po.due_date = CURDATE()';
            $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
            $where[] = $this->balancePendingSql();
        } elseif ($dueFilter === 'due_soon') {
            $where[] = "po.status = 'received'";
            $where[] = 'po.due_date IS NOT NULL AND po.due_date >= CURDATE() AND po.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
            $where[] = "COALESCE(po.payment_mode, 'payable') = 'payable'";
            $where[] = $this->balancePendingSql();
        } elseif ($dueFilter === 'no_due') {
            $where[] = 'po.due_date IS NULL';
        }

        $this->appendListSearchFilters($where, $params, $filters, 'invoice_date');
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /** Condición SQL: OC cancelada después de haber sido factura/recibida. */
    private function postReceiveVoidSql(string $alias = 'po'): string
    {
        return "(
            {$alias}.voided_at IS NOT NULL
            OR COALESCE({$alias}.notes,'') LIKE '%ANULADA POST-RECEPCIÓN%'
            OR COALESCE({$alias}.notes,'') LIKE '%[[NC_FULL%'
            OR COALESCE({$alias}.credit_total,0) > 0.009
        )";
    }

    /**
     * Recibe mercancía: inventaría, actualiza costos y deja lista para asiento.
     */
    /**
     * Actualiza metadatos de factura/pago sobre una OC aún no recibida
     * (vencimiento, fecha factura, descuento pronto pago).
     *
     * @param array{
     *   due_date?:?string,invoice_date?:?string,invoice_number?:?string,
     *   early_payment_discount_pct?:float|string|null,
     *   commercial_discount_pct?:float|string|null,
     *   commercial_discount_amount?:float|string|null
     * } $meta
     */
    public function applyInvoiceMeta(int $orderId, array $meta): void
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new \RuntimeException('Solo se puede editar factura en OC pendientes de recibir');
        }

        $dueDate = array_key_exists('due_date', $meta)
            ? $this->validExpiry($meta['due_date'] ?? null)
            : ($order['due_date'] ?? null);
        $invoiceDate = array_key_exists('invoice_date', $meta)
            ? $this->validExpiry($meta['invoice_date'] ?? null)
            : ($order['invoice_date'] ?? null);
        $invoiceNumber = array_key_exists('invoice_number', $meta)
            ? (trim((string)($meta['invoice_number'] ?? '')) ?: null)
            : ($order['invoice_number'] ?? null);

        $earlyPct = array_key_exists('early_payment_discount_pct', $meta)
            && $meta['early_payment_discount_pct'] !== ''
            && $meta['early_payment_discount_pct'] !== null
            ? max(0, min(100, round((float)$meta['early_payment_discount_pct'], 2)))
            : (float)($order['early_payment_discount_pct'] ?? 0);

        $commercialPct = array_key_exists('commercial_discount_pct', $meta)
            && $meta['commercial_discount_pct'] !== ''
            && $meta['commercial_discount_pct'] !== null
            ? max(0, min(100, round((float)$meta['commercial_discount_pct'], 2)))
            : (float)($order['commercial_discount_pct'] ?? 0);

        // Monto $: si no viene en meta, conservar el de la OC cuando no hay % (monto fijo).
        if (array_key_exists('commercial_discount_amount', $meta)
            && $meta['commercial_discount_amount'] !== ''
            && $meta['commercial_discount_amount'] !== null
        ) {
            $commercialAmtIn = max(0, round((float)$meta['commercial_discount_amount'], 2));
        } elseif ($commercialPct > 0.009) {
            $commercialAmtIn = 0.0;
        } else {
            $commercialAmtIn = max(0, round((float)($order['commercial_discount_amount'] ?? 0), 2));
        }

        $subtotal = (float)($order['subtotal'] ?? 0);
        $tax = (float)($order['tax'] ?? 0);
        $disc = self::resolvePurchaseDiscounts(
            $subtotal,
            $tax,
            $earlyPct,
            $commercialPct,
            $commercialAmtIn
        );

        try {
            $this->query(
                "UPDATE purchase_orders SET
                    due_date = ?,
                    invoice_date = ?,
                    invoice_number = COALESCE(?, invoice_number),
                    early_payment_discount_pct = ?,
                    early_payment_discount_amount = ?,
                    commercial_discount_pct = ?,
                    commercial_discount_amount = ?,
                    total = ?
                 WHERE id = ?",
                [
                    $dueDate, $invoiceDate, $invoiceNumber,
                    $earlyPct, $disc['early_amount'],
                    $disc['commercial_pct'], $disc['commercial_amount'],
                    $disc['total'], $orderId,
                ]
            );
        } catch (\Throwable $e) {
            $this->query(
                "UPDATE purchase_orders SET
                    due_date = ?,
                    invoice_date = ?,
                    invoice_number = COALESCE(?, invoice_number),
                    early_payment_discount_pct = ?,
                    early_payment_discount_amount = ?,
                    total = ?
                 WHERE id = ?",
                [$dueDate, $invoiceDate, $invoiceNumber, $earlyPct, $disc['early_amount'], $disc['total'], $orderId]
            );
        }
    }

    public function receiveOrder(
        int $orderId,
        string $warehouseDate,
        string $invoiceNumber,
        string $paymentMode,
        string $notes = ''
    ): array {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new \RuntimeException('Solo se pueden recibir órdenes en borrador u ordenadas');
        }
        if (!in_array($paymentMode, ['payable', 'cash', 'transfer', 'card'], true)) {
            $paymentMode = 'payable';
        }

        $stock = new StockService($this->db);
        $warehouseDate = $stock->normalizeDate($warehouseDate);
        $movementIds = [];
        $debtAlerts = [];

        $this->db->beginTransaction();
        try {
            // Candado: evita doble recepción concurrente (stock duplicado).
            $locked = $this->query(
                "SELECT id, status FROM purchase_orders WHERE id = ? FOR UPDATE",
                [$orderId]
            )->fetch();
            if (!$locked || !in_array((string)($locked['status'] ?? ''), ['draft', 'ordered'], true)) {
                throw new \RuntimeException('Solo se pueden recibir órdenes en borrador u ordenadas');
            }

            foreach ($order['items'] as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $qty = (int)$item['quantity'];
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }
                $product = $this->query(
                    "SELECT id, product_type FROM products WHERE id = ?",
                    [$productId]
                )->fetch();
                if (!$product || ($product['product_type'] ?? 'product') === 'service') {
                    continue;
                }

                $debtCover = null;
                $invoiceUnitCost = round((float)($item['unit_cost'] ?? 0), 2);
                $inventoryOverride = round(max(0, (float)($item['inventory_cost'] ?? 0)), 2);
                $isBonus = $invoiceUnitCost <= 0.009;
                // Factura: bonificación a $0. Inventario: conservar costo (no diluir WAC).
                // Costo inventario manual (factura importada) manda sobre ambos.
                if ($inventoryOverride > 0.009) {
                    $unitCost = $inventoryOverride;
                } else {
                    $unitCost = $isBonus
                        ? $this->resolveBonusInventoryCost($order, $item, $productId)
                        : $invoiceUnitCost;
                }
                $note = 'OC ' . $order['order_number'] . ($invoiceNumber !== '' ? (' / Factura ' . $invoiceNumber) : '');
                if ($isBonus) {
                    $note .= ' · Bonificación (factura $0';
                    if ($unitCost > 0.009) {
                        $note .= ' · inventario valorizado a ' . number_format($unitCost, 2, '.', '');
                    }
                } elseif ($inventoryOverride > 0.009 && abs($inventoryOverride - $invoiceUnitCost) > 0.009) {
                    $note .= ' · Costo inventario ' . number_format($inventoryOverride, 2, '.', '')
                        . ' (factura ' . number_format($invoiceUnitCost, 2, '.', '') . ')';
                }
                $movementId = $stock->increase(
                    $productId,
                    $qty,
                    'purchase_order',
                    $orderId,
                    $note,
                    'in',
                    $unitCost,
                    $order['supplier_id'] ? (int)$order['supplier_id'] : null,
                    $paymentMode,
                    true,
                    $warehouseDate,
                    [
                        'lot_code' => (string)($item['lot_code'] ?? ''),
                        'expiry_date' => $item['expiry_date'] ?? null,
                        'supplier_id' => $order['supplier_id'] ? (int)$order['supplier_id'] : null,
                        'purchase_order_id' => $orderId,
                    ],
                    $debtCover
                );
                if ($movementId > 0) {
                    $movementIds[] = $movementId;
                }
                if ($debtCover && (int)($debtCover['covered'] ?? 0) > 0) {
                    $debtAlerts[] = $stock->formatDebtCoverAlert($qty, $debtCover);
                }
                $loanRecvQty = $stock->openReceivedLoanQty($productId);
                if ($loanRecvQty > 0) {
                    $stockNow = (int)$this->query(
                        "SELECT stock FROM products WHERE id = ? LIMIT 1",
                        [$productId]
                    )->fetchColumn();
                    $pname = trim((string)($item['product_name'] ?? $item['name'] ?? ''));
                    if ($pname === '') {
                        $pname = (string)$this->query(
                            "SELECT name FROM products WHERE id = ? LIMIT 1",
                            [$productId]
                        )->fetchColumn();
                    }
                    if ($pname === '') {
                        $pname = '#' . $productId;
                    }
                    $debtAlerts[] = $pname . ': tiene ' . $loanRecvQty
                        . ' ud(s) de préstamo recibido pendiente. Stock ahora ' . $stockNow
                        . '. Al devolver ese préstamo el stock bajará a '
                        . max(0, $stockNow - $loanRecvQty)
                        . ' (préstamo + compra no se cancelan solos).';
                }
                $this->query(
                    "UPDATE purchase_order_items SET received_qty = quantity WHERE id = ?",
                    [(int)$item['id']]
                );
            }

            $upd = $this->query(
                "UPDATE purchase_orders
                 SET status = 'received',
                     warehouse_date = ?,
                     invoice_number = ?,
                     payment_mode = ?,
                     notes = CASE WHEN ? = '' THEN notes ELSE CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), ?) END,
                     received_at = NOW(),
                     received_by = ?
                 WHERE id = ? AND status IN ('draft', 'ordered')",
                [
                    $warehouseDate,
                    $invoiceNumber !== '' ? $invoiceNumber : null,
                    $paymentMode,
                    $notes,
                    $notes,
                    $_SESSION['tenant_user_id'] ?? null,
                    $orderId,
                ]
            );
            if ($upd->rowCount() < 1) {
                throw new \RuntimeException('La orden ya fue recibida por otro proceso');
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Contado queda pagado; a crédito queda pendiente de abonos.
        try {
            $this->refreshPaymentStatus($orderId);
        } catch (\Throwable $e) {
            error_log('refreshPaymentStatus OC ' . $orderId . ': ' . $e->getMessage());
        }

        // Guarda el costo real por proveedor para futuras reposiciones.
        try {
            (new ProductSupplierService($this->db))->syncFromPurchaseOrder($orderId);
        } catch (\Throwable $e) {
            error_log('Vínculo producto-proveedor OC ' . $orderId . ': ' . $e->getMessage());
        }

        TenantAuditService::safeLog(
            $this->db,
            'receive',
            'compras',
            'Factura/OC recibida: ' . (string)($order['order_number'] ?? '')
                . ($invoiceNumber !== '' ? (' / Factura ' . $invoiceNumber) : '')
                . ' · ' . count($movementIds) . ' mov. stock'
                . ' · total=' . number_format((float)($order['total'] ?? 0), 0, '.', ''),
            'purchase_order',
            $orderId,
            [
                'invoice_number' => $invoiceNumber,
                'warehouse_date' => $warehouseDate,
                'payment_mode' => $paymentMode,
                'movement_ids' => $movementIds,
            ]
        );

        return [
            'order_id' => $orderId,
            'movement_ids' => $movementIds,
            'debt_alerts' => $debtAlerts,
        ];
    }

    /**
     * Costo de inventario para una línea bonificada (factura $0).
     * Prioridad: línea pagada del mismo producto en la OC → costo actual del catálogo → último costo proveedor.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $item
     */
    private function resolveBonusInventoryCost(array $order, array $item, int $productId): float
    {
        $itemId = (int)($item['id'] ?? 0);
        foreach ($order['items'] ?? [] as $other) {
            if ((int)($other['product_id'] ?? 0) !== $productId) {
                continue;
            }
            if ($itemId > 0 && (int)($other['id'] ?? 0) === $itemId) {
                continue;
            }
            $sibling = round((float)($other['inventory_cost'] ?? 0), 2);
            if ($sibling <= 0.009) {
                $sibling = round((float)($other['unit_cost'] ?? 0), 2);
            }
            if ($sibling > 0.009) {
                return $sibling;
            }
        }

        try {
            $row = $this->query(
                "SELECT purchase_price FROM products WHERE id = ? LIMIT 1",
                [$productId]
            )->fetch();
            $catalog = round((float)($row['purchase_price'] ?? 0), 2);
            if ($catalog > 0.009) {
                return $catalog;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $supplierId = (int)($order['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            try {
                $last = $this->query(
                    "SELECT last_cost FROM product_suppliers
                     WHERE product_id = ? AND supplier_id = ? AND COALESCE(last_cost, 0) > 0
                     LIMIT 1",
                    [$productId, $supplierId]
                )->fetchColumn();
                $lastCost = round((float)$last, 2);
                if ($lastCost > 0.009) {
                    return $lastCost;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return 0.0;
    }

    /**
     * Resta del pedido abierto las cantidades ya facturadas en otra OC (recepción parcial).
     *
     * @param list<array{product_id?:int|null,quantity?:int|float,product_name?:string,barcode?:string}> $receivedLines
     * @return array{remaining_qty:int,closed:bool,lines_updated:int}
     */
    public function deductOpenOrderQuantities(int $orderId, array $receivedLines, string $note = ''): array
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra origen no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new \RuntimeException('La OC origen no está abierta para recepción parcial');
        }

        $deductByProduct = [];
        $deductByBarcode = [];
        $deductByName = [];
        foreach ($receivedLines as $line) {
            $qty = (int)round((float)($line['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $pid = (int)($line['product_id'] ?? 0);
            $barcode = trim((string)($line['barcode'] ?? ''));
            $name = mb_strtolower(trim((string)($line['product_name'] ?? $line['description'] ?? '')));
            if ($pid > 0) {
                $deductByProduct[$pid] = ($deductByProduct[$pid] ?? 0) + $qty;
            } elseif ($barcode !== '') {
                $deductByBarcode[$barcode] = ($deductByBarcode[$barcode] ?? 0) + $qty;
            } elseif ($name !== '') {
                $deductByName[$name] = ($deductByName[$name] ?? 0) + $qty;
            }
        }

        $linesUpdated = 0;
        $remainingQty = 0;
        $subtotal = 0.0;
        $tax = 0.0;
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($order['items'] as $item) {
                $itemId = (int)$item['id'];
                $have = (int)$item['quantity'];
                $pid = (int)($item['product_id'] ?? 0);
                $barcode = trim((string)($item['barcode'] ?? ''));
                $name = mb_strtolower(trim((string)($item['product_name'] ?? '')));
                $take = 0;

                if ($pid > 0 && ($deductByProduct[$pid] ?? 0) > 0) {
                    $take = min($have, $deductByProduct[$pid]);
                    $deductByProduct[$pid] -= $take;
                } elseif ($barcode !== '' && ($deductByBarcode[$barcode] ?? 0) > 0) {
                    $take = min($have, $deductByBarcode[$barcode]);
                    $deductByBarcode[$barcode] -= $take;
                } elseif ($name !== '' && ($deductByName[$name] ?? 0) > 0) {
                    $take = min($have, $deductByName[$name]);
                    $deductByName[$name] -= $take;
                }

                $newQty = $have - $take;
                if ($take > 0) {
                    $linesUpdated++;
                }

                if ($newQty <= 0) {
                    $this->query("DELETE FROM purchase_order_items WHERE id = ?", [$itemId]);
                    continue;
                }

                $cost = round((float)$item['unit_cost'], 2);
                $rate = round((float)($item['tax_rate'] ?? 0), 2);
                $lineSub = round($newQty * $cost, 2);
                $lineTax = round($lineSub * ($rate / 100), 2);
                $this->query(
                    "UPDATE purchase_order_items
                     SET quantity = ?, subtotal = ?, tax_amount = ?, line_total = ?
                     WHERE id = ?",
                    [$newQty, $lineSub, $lineTax, round($lineSub + $lineTax, 2), $itemId]
                );
                $remainingQty += $newQty;
                $subtotal += $lineSub;
                $tax += $lineTax;
            }

            $closed = false;
            if ($remainingQty <= 0) {
                $this->cancelOrder(
                    $orderId,
                    $note !== '' ? $note : 'Cerrada: cantidades cubiertas por factura parcial'
                );
                $closed = true;
            } else {
                $earlyPct = (float)($order['early_payment_discount_pct'] ?? 0);
                $commercialPct = (float)($order['commercial_discount_pct'] ?? 0);
                $commercialAmtIn = (float)($order['commercial_discount_amount'] ?? 0);
                $disc = self::resolvePurchaseDiscounts(
                    $subtotal,
                    $tax,
                    $earlyPct,
                    $commercialPct,
                    $commercialPct > 0.009 ? 0.0 : $commercialAmtIn
                );
                try {
                    $this->query(
                        "UPDATE purchase_orders SET subtotal = ?, tax = ?, total = ?,
                            early_payment_discount_amount = ?,
                            commercial_discount_amount = ?,
                            notes = CONCAT(COALESCE(notes,''),
                                CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE ' | ' END, ?)
                         WHERE id = ?",
                        [
                            round($subtotal, 2),
                            round($tax, 2),
                            $disc['total'],
                            $disc['early_amount'],
                            $disc['commercial_amount'],
                            $note !== '' ? $note : 'Recepción parcial aplicada',
                            $orderId,
                        ]
                    );
                } catch (\Throwable $e) {
                    $this->query(
                        "UPDATE purchase_orders SET subtotal = ?, tax = ?, total = ?,
                            early_payment_discount_amount = ?,
                            notes = CONCAT(COALESCE(notes,''),
                                CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE ' | ' END, ?)
                         WHERE id = ?",
                        [
                            round($subtotal, 2),
                            round($tax, 2),
                            $disc['total'],
                            $disc['early_amount'],
                            $note !== '' ? $note : 'Recepción parcial aplicada',
                            $orderId,
                        ]
                    );
                }
            }

            // Cantidades de la factura que no alcanzaron a cruzarse contra la OC origen.
            $excess = [];
            foreach ($deductByProduct as $pid => $left) {
                if ($left > 0) {
                    $excess[] = ['product_id' => (int)$pid, 'qty' => (int)$left];
                }
            }
            foreach ($deductByBarcode as $bc => $left) {
                if ($left > 0) {
                    $excess[] = ['barcode' => (string)$bc, 'qty' => (int)$left];
                }
            }
            foreach ($deductByName as $nm => $left) {
                if ($left > 0) {
                    $excess[] = ['name' => (string)$nm, 'qty' => (int)$left];
                }
            }

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'remaining_qty' => $remainingQty,
                'closed' => $closed,
                'lines_updated' => $linesUpdated,
                'order_number' => (string)($order['order_number'] ?? ''),
                'excess' => $excess,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function cancelOrder(int $orderId, string $reason = ''): void
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden no encontrada');
        }
        if ($order['status'] === 'received') {
            throw new \RuntimeException('No se puede cancelar una orden ya recibida. Use ajustes de inventario.');
        }
        if ($order['status'] === 'cancelled') {
            return;
        }
        $this->query(
            "UPDATE purchase_orders
             SET status = 'cancelled',
                 notes = CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), 'CANCELADA: ', ?)
             WHERE id = ?",
            [$reason !== '' ? $reason : date('Y-m-d H:i'), $orderId]
        );
    }

    public function pendingAccountingOrders(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return $this->query(
            "SELECT id FROM purchase_orders
             WHERE status = 'received' AND accounting_entry_id IS NULL
             ORDER BY id LIMIT {$limit}"
        )->fetchAll();
    }

    public function pendingAccountingCount(): int
    {
        return (int)$this->query(
            "SELECT COUNT(*) FROM purchase_orders
             WHERE status = 'received' AND accounting_entry_id IS NULL"
        )->fetchColumn();
    }

    /**
     * Egreso de caja física al recibir OC pagada en efectivo.
     * @return string Nota UI (vacía si no aplica)
     */
    public function syncCashForReceivedOrder(int $orderId): string
    {
        $order = $this->getOrder($orderId);
        if (!$order || ($order['status'] ?? '') !== 'received') {
            return '';
        }
        if ((string)($order['payment_mode'] ?? '') !== 'cash') {
            return '';
        }
        $total = round((float)($order['total'] ?? 0), 2);
        if ($total <= 0.009) {
            return '';
        }
        $label = 'Compra OC ' . ($order['order_number'] ?? ('#' . $orderId))
            . (!empty($order['supplier_name']) ? ' · ' . $order['supplier_name'] : '');
        return $this->postCashMovement($total, $label, 'expense', 'purchase_order', $orderId);
    }

    /**
     * Egreso de caja física por abono a proveedor en efectivo.
     * @return string Nota UI
     */
    public function syncCashForSupplierPayment(int $paymentId): string
    {
        try {
            $payment = $this->query(
                "SELECT sp.id, sp.amount, sp.payment_method, sp.status,
                        sp.cash_from_drawer, po.order_number, s.name supplier_name
                 FROM supplier_payments sp
                 LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
                 LEFT JOIN suppliers s ON s.id = sp.supplier_id
                 WHERE sp.id = ?",
                [$paymentId]
            )->fetch();
        } catch (\Throwable $e) {
            $payment = $this->query(
                "SELECT sp.id, sp.amount, sp.payment_method, sp.status,
                        po.order_number, s.name supplier_name
                 FROM supplier_payments sp
                 LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
                 LEFT JOIN suppliers s ON s.id = sp.supplier_id
                 WHERE sp.id = ?",
                [$paymentId]
            )->fetch();
        }
        if (!$payment || ($payment['status'] ?? 'applied') === 'cancelled') {
            return '';
        }
        if (!PaymentMethodCatalog::affectsCash((string)($payment['payment_method'] ?? ''))) {
            return '';
        }
        // Efectivo fuera del cajón: no descuenta la caja abierta.
        if (array_key_exists('cash_from_drawer', $payment) && (int)$payment['cash_from_drawer'] === 0) {
            return 'Efectivo fuera del cajón: no se descontó de la caja abierta.';
        }
        $amount = round((float)$payment['amount'], 2);
        $label = 'Abono proveedor'
            . (!empty($payment['order_number']) ? ' OC ' . $payment['order_number'] : '')
            . (!empty($payment['supplier_name']) ? ' · ' . $payment['supplier_name'] : '');
        return $this->postCashMovement($amount, $label, 'expense', 'supplier_payment', $paymentId);
    }

    /**
     * Ingreso a caja cuando una NC revierte una compra pagada en efectivo.
     * @return string Nota UI
     */
    public function syncCashForCreditNote(int $creditNoteId): string
    {
        $note = $this->query(
            "SELECT cn.id, cn.total, cn.credit_number, cn.status, cn.purchase_order_id,
                    po.payment_mode, po.order_number
             FROM purchase_credit_notes cn
             JOIN purchase_orders po ON po.id = cn.purchase_order_id
             WHERE cn.id = ?",
            [$creditNoteId]
        )->fetch();
        if (!$note || ($note['status'] ?? '') !== 'posted') {
            return '';
        }
        if ((string)($note['payment_mode'] ?? '') !== 'cash') {
            return '';
        }
        $orderId = (int)($note['purchase_order_id'] ?? 0);
        // Solo reingresa a cajón si la compra efectivamente egresó del cajón.
        if ($orderId > 0 && !$this->hasCashMovementRef('purchase_order', $orderId)) {
            return 'Aviso: la compra en efectivo no tuvo egreso de cajón; la NC no ingresó efectivo a caja.';
        }
        $total = round((float)$note['total'], 2);
        if ($total <= 0.009) {
            return '';
        }
        $label = 'Reverso anulación compra ' . ($note['credit_number'] ?? ('#' . $creditNoteId))
            . (!empty($note['order_number']) ? ' / OC ' . $note['order_number'] : '');
        return $this->postCashMovement($total, $label, 'income', 'purchase_credit_note', $creditNoteId);
    }

    /**
     * Anulación (sin NC) de una OC pagada con efectivo del cajón: devuelve al cajón lo que salió.
     * Si la caja del egreso sigue abierta, vuelve a esa misma caja (neto $0);
     * si ya se cerró, entra como ingreso en la caja abierta actual.
     */
    public function syncCashForVoidedOrder(int $orderId): string
    {
        $order = $this->query(
            "SELECT po.order_number, po.payment_mode, s.name supplier_name
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = ?",
            [$orderId]
        )->fetch();
        if (!$order || (string)($order['payment_mode'] ?? '') !== 'cash') {
            return '';
        }
        try {
            $out = $this->query(
                "SELECT COALESCE(SUM(amount), 0) AS total, MAX(cash_session_id) AS session_id
                 FROM cash_movements
                 WHERE reference_type = 'purchase_order' AND reference_id = ? AND type = 'expense'",
                [$orderId]
            )->fetch() ?: [];
        } catch (\Throwable $e) {
            return '';
        }
        $amount = round((float)($out['total'] ?? 0), 2);
        if ($amount <= 0.009) {
            return 'La compra no tuvo egreso de cajón; no se movió la caja.';
        }
        $sessionId = null;
        $originSession = (int)($out['session_id'] ?? 0);
        if ($originSession > 0) {
            $open = $this->query(
                "SELECT id FROM cash_sessions WHERE id = ? AND status = 'open' LIMIT 1",
                [$originSession]
            )->fetchColumn();
            if ($open) {
                $sessionId = $originSession;
            }
        }
        $label = 'Devolución anulación OC ' . ($order['order_number'] ?? ('#' . $orderId))
            . (!empty($order['supplier_name']) ? ' · ' . $order['supplier_name'] : '');
        $note = $this->postCashMovement($amount, $label, 'income', 'purchase_order_cancel', $orderId, $sessionId);
        if ($note === 'Ingreso a caja registrado.') {
            return $sessionId !== null
                ? 'El dinero volvió a la caja del día.'
                : 'El dinero entró como ingreso en la caja abierta.';
        }
        return $note;
    }

    /**
     * Si la compra salió del cajón, reversarla (anulación o NC) exige una caja abierta
     * donde reingresar el efectivo; si no, el cajón y la contabilidad quedan descuadrados.
     */
    public function assertCashDrawerForReversal(int $orderId): void
    {
        try {
            $mode = (string)$this->query(
                "SELECT payment_mode FROM purchase_orders WHERE id = ?",
                [$orderId]
            )->fetchColumn();
        } catch (\Throwable $e) {
            return;
        }
        if ($mode !== 'cash' || !$this->hasCashMovementRef('purchase_order', $orderId)) {
            return;
        }
        if ((new CashService($this->db))->getOpenSession()) {
            return;
        }
        throw new \RuntimeException(
            'Esta compra se pagó con efectivo del cajón. Abra la caja antes de anularla o hacerle nota crédito, '
            . 'para que el dinero se reintegre al cajón.'
        );
    }

    /**
     * Corrección de backend: el abono se registró como efectivo DEL CAJÓN pero
     * en realidad fue efectivo de otro origen. Devuelve el dinero al cajón
     * (movimiento compensatorio en la misma sesión si sigue abierta), marca el
     * abono como fuera del cajón y deja la contabilidad lista para re-emitir el
     * asiento con crédito a bancos (lo hace el controlador).
     *
     * @return array{payment_id:int,amount:float,order_number:string,accounting_entry_id:int,cash_note:string}
     */
    public function correctSupplierPaymentCashSource(int $paymentId, string $sourceNote): array
    {
        SupplyChainSchema::ensure($this->db);
        $sourceNote = substr(trim($sourceNote), 0, 255);
        if (mb_strlen($sourceNote) < 5) {
            throw new \InvalidArgumentException('Indique de dónde salió el efectivo (mín. 5 caracteres).');
        }
        $payment = $this->query(
            "SELECT sp.*, po.order_number, s.name supplier_name
             FROM supplier_payments sp
             LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
             LEFT JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.id = ?",
            [$paymentId]
        )->fetch();
        if (!$payment) {
            throw new \RuntimeException('Abono a proveedor no encontrado');
        }
        if (($payment['status'] ?? 'applied') === 'cancelled') {
            throw new \RuntimeException('El abono está cancelado; no se puede corregir.');
        }
        if (!PaymentMethodCatalog::affectsCash((string)($payment['payment_method'] ?? ''))) {
            throw new \RuntimeException('Solo aplica a abonos en efectivo.');
        }
        if (!array_key_exists('cash_from_drawer', $payment)) {
            throw new \RuntimeException('Esta instalación aún no registra el origen del efectivo del abono.');
        }
        if ((int)$payment['cash_from_drawer'] === 0) {
            throw new \RuntimeException('Este abono ya figura como efectivo fuera del cajón.');
        }
        $amount = round((float)$payment['amount'], 2);
        $orderNumber = (string)($payment['order_number'] ?? '');

        // Egreso original en el cajón (si lo hubo).
        $movement = null;
        try {
            $movement = $this->query(
                "SELECT cm.id, cm.cash_session_id, cs.status session_status
                 FROM cash_movements cm
                 JOIN cash_sessions cs ON cs.id = cm.cash_session_id
                 WHERE cm.reference_type = 'supplier_payment' AND cm.reference_id = ? AND cm.type = 'expense'
                 ORDER BY cm.id DESC LIMIT 1",
                [$paymentId]
            )->fetch() ?: null;
        } catch (\Throwable $e) {
            $movement = null;
        }
        $cashNote = 'El abono no tenía egreso registrado en el cajón.';
        if ($movement) {
            if ((string)($movement['session_status'] ?? '') !== 'open') {
                throw new \RuntimeException(
                    'La caja de ese movimiento ya está cerrada. Por seguridad no se corrige automáticamente; '
                    . 'haga el ajuste con un movimiento manual de caja y el reverso contable, o contacte al administrador.'
                );
            }
            if ($this->hasCashMovementRef('supplier_payment_cashfix', $paymentId)) {
                throw new \RuntimeException('Este abono ya tiene corrección de caja.');
            }
            $cash = new CashService($this->db);
            $ok = $cash->registerMovement(
                $amount,
                'Corrección abono proveedor' . ($orderNumber !== '' ? ' OC ' . $orderNumber : '')
                    . ' · el efectivo NO salió del cajón',
                'income',
                'supplier_payment_cashfix',
                $paymentId,
                (int)$movement['cash_session_id']
            );
            if (!$ok) {
                throw new \RuntimeException('No se pudo registrar el reintegro en la caja abierta.');
            }
            $cashNote = 'El dinero se reintegró al cajón con un ingreso de corrección.';
        }

        $notes = trim((string)($payment['notes'] ?? ''));
        $tag = 'Corrección: efectivo fuera del cajón (' . $sourceNote . ')';
        $notes = substr($notes !== '' ? ($notes . ' · ' . $tag) : $tag, 0, 255);
        $this->query(
            "UPDATE supplier_payments SET cash_from_drawer = 0, cash_source_note = ?, notes = ? WHERE id = ?",
            [$sourceNote, $notes, $paymentId]
        );

        TenantAuditService::safeLog(
            $this->db,
            'payment',
            'compras',
            'Corrección origen efectivo abono #' . $paymentId
                . ($orderNumber !== '' ? ' OC ' . $orderNumber : '')
                . ' · monto=' . number_format($amount, 0, '.', '') . ' · origen: ' . $sourceNote,
            'supplier_payment',
            $paymentId,
            ['cash_from_drawer' => 0, 'cash_source_note' => $sourceNote]
        );

        return [
            'payment_id' => $paymentId,
            'amount' => $amount,
            'order_number' => $orderNumber,
            'accounting_entry_id' => (int)($payment['accounting_entry_id'] ?? 0),
            'cash_note' => $cashNote,
        ];
    }

    /**
     * Corrección de backend: el dcto. pronto pago de un abono se registró mal.
     * Actualiza descuento (% y monto) del abono, recalcula el estado de pago de
     * la orden y deja la contabilidad lista para reversar y re-emitir el asiento
     * (lo hace el controlador, igual que la corrección de origen del efectivo).
     * El monto pagado NO cambia: la caja no se toca.
     *
     * @return array{payment_id:int,order_id:int,order_number:string,amount:float,old_discount:float,new_discount:float,discount_pct:float,invoice_value:float,balance:float,accounting_entry_id:int}
     */
    public function correctSupplierPaymentDiscount(
        int $paymentId,
        ?float $newDiscountAmount,
        ?float $newDiscountPct,
        string $reason
    ): array {
        SupplyChainSchema::ensure($this->db);
        $reason = substr(trim($reason), 0, 255);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('Indique el motivo de la corrección (mín. 5 caracteres).');
        }
        $payment = $this->query(
            "SELECT sp.*, po.order_number, po.total order_total, po.supplier_invoice_value order_invoice_value
             FROM supplier_payments sp
             LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
             WHERE sp.id = ?",
            [$paymentId]
        )->fetch();
        if (!$payment) {
            throw new \RuntimeException('Abono a proveedor no encontrado');
        }
        if (($payment['status'] ?? 'applied') === 'cancelled') {
            throw new \RuntimeException('El abono está cancelado; no se puede corregir.');
        }
        if (!array_key_exists('discount_amount', $payment) || !array_key_exists('discount_pct', $payment)) {
            throw new \RuntimeException('Esta instalación aún no registra el descuento pronto pago del abono.');
        }
        $orderId = (int)($payment['purchase_order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \RuntimeException('El abono no está asociado a una orden de compra.');
        }

        $oldDiscount = round((float)($payment['discount_amount'] ?? 0), 2);
        $amount = round((float)($payment['amount'] ?? 0), 2);
        $orderNumber = (string)($payment['order_number'] ?? '');

        // Base autoritativa de la CxP y tope del descuento: lo ya pagado + NC +
        // descuentos de OTROS abonos no puede superar la base; este descuento
        // solo puede ocupar el espacio restante.
        $row = $this->query(
            "SELECT COALESCE(NULLIF(po.supplier_invoice_value, 0), po.total) base_total,
                    COALESCE((
                        SELECT SUM(sp2.amount) FROM supplier_payments sp2
                        WHERE sp2.purchase_order_id = po.id AND sp2.status = 'applied'
                    ), 0) paid,
                    COALESCE((
                        SELECT SUM(sp2.discount_amount) FROM supplier_payments sp2
                        WHERE sp2.purchase_order_id = po.id AND sp2.status = 'applied' AND sp2.id <> ?
                    ), 0) other_discounted,
                    COALESCE((
                        SELECT SUM(cn.total) FROM purchase_credit_notes cn
                        WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                    ), 0) credited
             FROM purchase_orders po
             WHERE po.id = ?",
            [$paymentId, $orderId]
        )->fetch();
        $base = $row ? round((float)$row['base_total'], 2) : 0.0;
        if ($base <= 0.009) {
            $base = round((float)($payment['invoice_value'] ?? 0), 2);
        }
        if ($base <= 0.009) {
            $base = round($amount + $oldDiscount, 2);
        }

        if ($newDiscountAmount !== null) {
            $newDiscount = round(max(0.0, $newDiscountAmount), 2);
            $discountPct = $base > 0 ? round($newDiscount / $base * 100, 2) : 0.0;
        } elseif ($newDiscountPct !== null) {
            $discountPct = max(0.0, min(100.0, round($newDiscountPct, 2)));
            $newDiscount = round($base * ($discountPct / 100), 2);
        } else {
            throw new \InvalidArgumentException('Indique el nuevo descuento pronto pago (porcentaje o monto).');
        }

        if (abs($newDiscount - $oldDiscount) <= 0.009) {
            throw new \RuntimeException(
                'El nuevo descuento es igual al actual (' . number_format($oldDiscount, 2, ',', '.') . '). No hay nada que corregir.'
            );
        }

        if ($row) {
            $maxDiscount = round(
                (float)$row['base_total'] - (float)$row['paid'] - (float)$row['credited'] - (float)$row['other_discounted'],
                2
            );
            if ($newDiscount - $maxDiscount > 0.009) {
                throw new \InvalidArgumentException(
                    'El descuento supera lo permitido para esta factura. Máximo posible: '
                    . number_format(max(0.0, $maxDiscount), 2, ',', '.')
                    . ' (Valor de Factura − abonos − notas de crédito − otros descuentos).'
                );
            }
        }

        $notes = trim((string)($payment['notes'] ?? ''));
        $tag = 'Corrección dcto. pronto pago: $' . number_format($oldDiscount, 2, '.', '')
            . ' → $' . number_format($newDiscount, 2, '.', '') . ' (' . $reason . ')';
        $notes = substr($notes !== '' ? ($notes . ' · ' . $tag) : $tag, 0, 255);
        $this->query(
            "UPDATE supplier_payments SET discount_amount = ?, discount_pct = ?, notes = ?
             WHERE id = ? AND status = 'applied'",
            [$newDiscount, $discountPct, $notes, $paymentId]
        );

        $status = $this->refreshPaymentStatus($orderId);

        TenantAuditService::safeLog(
            $this->db,
            'payment',
            'compras',
            'Corrección dcto. pronto pago abono #' . $paymentId
                . ($orderNumber !== '' ? ' OC ' . $orderNumber : '')
                . ' · dcto=' . number_format($oldDiscount, 0, '.', '')
                . '→' . number_format($newDiscount, 0, '.', '')
                . ' · motivo: ' . $reason,
            'supplier_payment',
            $paymentId,
            [
                'old_discount' => $oldDiscount,
                'new_discount' => $newDiscount,
                'discount_pct' => $discountPct,
                'balance' => $status['balance'],
            ]
        );

        return [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'amount' => $amount,
            'old_discount' => $oldDiscount,
            'new_discount' => $newDiscount,
            'discount_pct' => $discountPct,
            'invoice_value' => $base,
            'balance' => $status['balance'],
            'accounting_entry_id' => (int)($payment['accounting_entry_id'] ?? 0),
        ];
    }

    /** Limpia el asiento asociado para que Contabilidad pueda re-emitir el abono corregido. */
    public function clearSupplierPaymentAccountingEntry(int $paymentId): void
    {
        $this->query(
            "UPDATE supplier_payments SET accounting_entry_id = NULL WHERE id = ?",
            [$paymentId]
        );
    }

    /**
     * Devuelve a caja el egreso de un abono a proveedor (tras cancelarlo en anulación).
     */
    public function syncCashReverseForSupplierPayment(int $paymentId): string
    {
        $payment = $this->query(
            "SELECT sp.id, sp.amount, sp.payment_method, sp.status, sp.cash_from_drawer,
                    po.order_number, s.name supplier_name
             FROM supplier_payments sp
             LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
             LEFT JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.id = ?",
            [$paymentId]
        )->fetch();
        if (!$payment || ($payment['status'] ?? '') !== 'cancelled') {
            return '';
        }
        // Si el efectivo no salió del cajón (o ya se corrigió), no hay nada que reintegrar.
        if (array_key_exists('cash_from_drawer', $payment) && (int)$payment['cash_from_drawer'] === 0) {
            return '';
        }
        if (!PaymentMethodCatalog::affectsCash((string)($payment['payment_method'] ?? ''))) {
            return '';
        }
        if (!$this->hasCashMovementRef('supplier_payment', $paymentId)) {
            return '';
        }
        if ($this->hasCashMovementRef('supplier_payment_cancel', $paymentId)) {
            return '';
        }
        $amount = round((float)$payment['amount'], 2);
        if ($amount <= 0.009) {
            return '';
        }
        $label = 'Reverso abono proveedor'
            . (!empty($payment['order_number']) ? ' OC ' . $payment['order_number'] : '')
            . (!empty($payment['supplier_name']) ? ' · ' . $payment['supplier_name'] : '');
        return $this->postCashMovement($amount, $label, 'income', 'supplier_payment_cancel', $paymentId);
    }

    /**
     * Cancela abonos applied de una OC (para anulación). Contabilidad/caja las revierte el controller.
     *
     * @return list<array{id:int,amount:float,payment_method:string}>
     */
    private function cancelAppliedPaymentsForVoid(int $orderId, string $reason): array
    {
        $rows = [];
        try {
            $rows = $this->query(
                "SELECT id, amount, payment_method FROM supplier_payments
                 WHERE purchase_order_id = ? AND status = 'applied'
                 ORDER BY id",
                [$orderId]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        $note = 'ANULACIÓN OC: ' . ($reason !== '' ? $reason : date('Y-m-d H:i'));
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $this->query(
                "UPDATE supplier_payments
                 SET status = 'cancelled',
                     notes = CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), ?)
                 WHERE id = ? AND status = 'applied'",
                [$note, $id]
            );
            $out[] = [
                'id' => $id,
                'amount' => round((float)($row['amount'] ?? 0), 2),
                'payment_method' => (string)($row['payment_method'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return string Nota para el usuario
     */
    private function postCashMovement(
        float $amount,
        string $description,
        string $type,
        string $referenceType,
        int $referenceId,
        ?int $sessionId = null
    ): string {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0.009 || $referenceId <= 0) {
            return '';
        }
        if ($this->hasCashMovementRef($referenceType, $referenceId)) {
            return '';
        }
        $cash = new CashService($this->db);
        if (!$cash->getOpenSession()) {
            return 'Aviso: no hay caja abierta; el movimiento de efectivo no se registró en el cajón (sí en contabilidad).';
        }
        try {
            $ok = $cash->registerMovement($amount, $description, $type, $referenceType, $referenceId, $sessionId);
            if ($ok === false) {
                return 'Aviso: no se pudo registrar el movimiento en caja física.';
            }
            return $type === 'expense'
                ? 'Egreso de caja registrado.'
                : 'Ingreso a caja registrado.';
        } catch (\Throwable $e) {
            error_log('Purchasing→Caja ' . $referenceType . ' #' . $referenceId . ': ' . $e->getMessage());
            return 'Aviso: no se pudo registrar el movimiento en caja física.';
        }
    }

    private function hasCashMovementRef(string $referenceType, int $referenceId): bool
    {
        try {
            $n = (int)$this->query(
                "SELECT COUNT(*) FROM cash_movements
                 WHERE reference_type = ? AND reference_id = ?",
                [$referenceType, $referenceId]
            )->fetchColumn();
            return $n > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Registra un abono al proveedor y actualiza el saldo de la orden.
     * Solo aplica a órdenes recibidas que quedaron como cuenta por pagar.
     *
     * @param array{path?:string,mime?:string,original?:string}|null $receipt Adjunto del comprobante
     * @param array{cash_from_drawer?:bool,cash_source_note?:string} $options
     * @return array{payment_id:int,paid_total:float,balance:float,payment_status:string}
     */
    public function registerPayment(
        int $orderId,
        float $amount,
        string $paymentDate,
        string $paymentMethod = 'cash',
        string $reference = '',
        string $notes = '',
        ?array $receipt = null,
        array $options = []
    ): array {
        SupplyChainSchema::ensure($this->db);

        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if ($order['status'] !== 'received') {
            throw new \RuntimeException('Solo se pueden abonar órdenes ya recibidas');
        }
        if ((string)($order['payment_mode'] ?? 'payable') !== 'payable') {
            throw new \RuntimeException('Esta orden se pagó de contado; no admite abonos adicionales');
        }
        if (empty($order['supplier_id'])) {
            throw new \RuntimeException('La orden no tiene proveedor asignado');
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('El abono debe ser mayor a cero');
        }

        $stock = new StockService($this->db);
        $paymentDate = $stock->normalizeDate($paymentDate);

        $receiptPath = isset($receipt['path']) ? substr(trim((string)$receipt['path']), 0, 255) : '';
        $receiptMime = isset($receipt['mime']) ? substr(trim((string)$receipt['mime']), 0, 80) : '';
        $receiptName = isset($receipt['original']) ? substr(trim((string)$receipt['original']), 0, 255) : '';

        $isCash = PaymentMethodCatalog::affectsCash($paymentMethod !== '' ? $paymentMethod : 'cash');
        $cashFromDrawer = $isCash
            ? (array_key_exists('cash_from_drawer', $options) ? (bool)$options['cash_from_drawer'] : true)
            : true;
        $cashSourceNote = substr(trim((string)($options['cash_source_note'] ?? '')), 0, 255);
        if ($isCash && !$cashFromDrawer && mb_strlen($cashSourceNote) < 5) {
            throw new \InvalidArgumentException(
                'Indique de dónde salió el efectivo (mín. 5 caracteres). No se descontará de la caja.'
            );
        }
        if ($isCash && !$cashFromDrawer) {
            $extra = 'Efectivo fuera del cajón: ' . $cashSourceNote;
            $notes = trim($notes !== '' ? ($notes . ' · ' . $extra) : $extra);
            $notes = substr($notes, 0, 255);
        }

        // Valor neto de la factura del proveedor + dcto. pronto pago (modal de abono).
        $invoiceValueOpt = isset($options['invoice_value'])
            ? round((float)$options['invoice_value'], 2)
            : 0.0;
        $discountPct = isset($options['discount_pct'])
            ? max(0.0, min(100.0, round((float)$options['discount_pct'], 2)))
            : 0.0;
        $discountAmountOpt = isset($options['discount_amount'])
            ? round((float)$options['discount_amount'], 2)
            : 0.0;
        $discountAmount = 0.0;
        $payableBase = self::effectivePayableBase($order);

        $this->db->beginTransaction();
        try {
            // Bloqueo + revalidación de saldo dentro de la TX (evita sobre-abono por doble clic).
            $this->query(
                "SELECT id FROM purchase_orders WHERE id = ? FOR UPDATE",
                [$orderId]
            )->fetch();

            // La factura física manda: si el usuario confirma otro valor neto,
            // ese queda como base de la CxP y el saldo se recalcula sobre él.
            if ($invoiceValueOpt > 0.009) {
                $payableBase = $invoiceValueOpt;
                try {
                    $this->query(
                        "UPDATE purchase_orders SET supplier_invoice_value = ? WHERE id = ?",
                        [$payableBase, $orderId]
                    );
                } catch (\Throwable $e) {
                    // Columna aún no migrada: se sigue con el total del sistema.
                    $payableBase = self::effectivePayableBase($order);
                }
            }

            $balance = $this->orderBalance($orderId);
            if ($balance <= 0.009) {
                throw new \RuntimeException('Esta orden ya está pagada en su totalidad');
            }

            // Dcto. pronto pago sobre el valor de factura; nunca supera el saldo vigente.
            // Si el usuario escribió el monto, ese manda (y el % se deriva); si no, manda el %.
            if ($discountAmountOpt > 0.009) {
                $discountAmount = min($discountAmountOpt, $balance);
                $discountPct = $payableBase > 0
                    ? round($discountAmount / $payableBase * 100, 2)
                    : 0.0;
            } else {
                $discountAmount = $discountPct > 0
                    ? min(round($payableBase * ($discountPct / 100), 2), $balance)
                    : 0.0;
            }
            $balanceAfterDiscount = round($balance - $discountAmount, 2);
            if ($amount - $balanceAfterDiscount > 0.009) {
                throw new \InvalidArgumentException(
                    'El abono supera el saldo pendiente (' . number_format($balanceAfterDiscount, 2, ',', '.') . ')'
                );
            }

            try {
                $this->query(
                    "INSERT INTO supplier_payments
                        (supplier_id, purchase_order_id, payment_date, amount,
                         payment_method, cash_from_drawer, cash_source_note, reference, notes,
                         receipt_path, receipt_mime, receipt_original_name, created_by,
                         invoice_value, discount_pct, discount_amount)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        (int)$order['supplier_id'],
                        $orderId,
                        $paymentDate,
                        $amount,
                        $paymentMethod !== '' ? $paymentMethod : 'cash',
                        $cashFromDrawer ? 1 : 0,
                        (!$cashFromDrawer && $cashSourceNote !== '') ? $cashSourceNote : null,
                        $reference !== '' ? $reference : null,
                        $notes !== '' ? $notes : null,
                        $receiptPath !== '' ? $receiptPath : null,
                        $receiptMime !== '' ? $receiptMime : null,
                        $receiptName !== '' ? $receiptName : null,
                        $_SESSION['tenant_user_id'] ?? null,
                        $payableBase > 0 ? $payableBase : null,
                        $discountPct,
                        $discountAmount,
                    ]
                );
            } catch (\Throwable $e) {
                // Installs sin columnas nuevas: insert clásico.
                if (!str_contains($e->getMessage(), 'cash_from_drawer')
                    && !str_contains($e->getMessage(), 'cash_source_note')
                    && !str_contains($e->getMessage(), 'invoice_value')
                    && !str_contains($e->getMessage(), 'discount_pct')
                    && !str_contains($e->getMessage(), 'discount_amount')
                ) {
                    throw $e;
                }
                $discountAmount = 0.0;
                $this->query(
                    "INSERT INTO supplier_payments
                        (supplier_id, purchase_order_id, payment_date, amount,
                         payment_method, reference, notes,
                         receipt_path, receipt_mime, receipt_original_name, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        (int)$order['supplier_id'],
                        $orderId,
                        $paymentDate,
                        $amount,
                        $paymentMethod !== '' ? $paymentMethod : 'cash',
                        $reference !== '' ? $reference : null,
                        $notes !== '' ? $notes : null,
                        $receiptPath !== '' ? $receiptPath : null,
                        $receiptMime !== '' ? $receiptMime : null,
                        $receiptName !== '' ? $receiptName : null,
                        $_SESSION['tenant_user_id'] ?? null,
                    ]
                );
            }
            $paymentId = (int)$this->db->lastInsertId();
            $status = $this->refreshPaymentStatus($orderId);
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Compatibilidad si la columna aún no migró: reintentar sin adjunto.
            if ($receiptPath !== '' && str_contains($e->getMessage(), 'receipt_path')) {
                return $this->registerPayment(
                    $orderId,
                    $amount,
                    $paymentDate,
                    $paymentMethod,
                    $reference,
                    $notes,
                    null,
                    $options
                );
            }
            throw $e;
        }

        TenantAuditService::safeLog(
            $this->db,
            'payment',
            'compras',
            'Abono a proveedor OC #' . $orderId . ' · monto=' . number_format($amount, 0, '.', '')
                . ' · ' . $paymentMethod,
            'purchase_order',
            $orderId,
            [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'invoice_value' => $payableBase,
                'discount_pct' => $discountPct,
                'discount_amount' => $discountAmount,
                'balance' => $status['balance'],
            ]
        );

        return [
            'payment_id' => $paymentId,
            'paid_total' => $status['paid_total'],
            'balance' => $status['balance'],
            'payment_status' => $status['payment_status'],
            'cash_from_drawer' => $cashFromDrawer,
            'invoice_value' => $payableBase,
            'discount_pct' => $discountPct,
            'discount_amount' => $discountAmount,
        ];
    }

    /**
     * Base efectiva de la cuenta por pagar: valor neto de la factura entregada
     * por el proveedor (confirmado en el modal de abono) si existe; si no, el
     * total neto calculado por el sistema.
     */
    public static function effectivePayableBase(array $order): float
    {
        $inv = round((float)($order['supplier_invoice_value'] ?? 0), 2);
        return $inv > 0.009 ? $inv : round((float)($order['total'] ?? 0), 2);
    }

    /** Saldo pendiente: base efectiva − abonos − notas de crédito − dctos. pronto pago aplicados al pagar. */
    public function orderBalance(int $orderId): float
    {
        $row = $this->query(
            "SELECT COALESCE(NULLIF(po.supplier_invoice_value, 0), po.total) base_total,
                    po.total order_total, po.payment_mode,
                    COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) paid,
                    COALESCE((
                        SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) discounted,
                    COALESCE((
                        SELECT SUM(cn.total) FROM purchase_credit_notes cn
                        WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                    ), 0) credited
             FROM purchase_orders po
             WHERE po.id = ?",
            [$orderId]
        )->fetch();
        if (!$row) {
            return 0.0;
        }
        // Contado (efectivo/transferencia/tarjeta): se pagó completo al recibir,
        // sin abonos. Misma fórmula que refreshPaymentStatus() para que el
        // detalle y el listado de facturas muestren el mismo saldo.
        if ((string)($row['payment_mode'] ?? 'payable') !== 'payable') {
            $total = round((float)($row['order_total'] ?? $row['base_total']), 2);
            $credited = round((float)$row['credited'], 2);
            $paidEffective = max(0.0, round($total - $credited, 2));
            return round($total - $paidEffective - $credited, 2);
        }
        return round(
            (float)$row['base_total'] - (float)$row['paid'] - (float)$row['discounted'] - (float)$row['credited'],
            2
        );
    }

    /**
     * Recalcula paid_total, credit_total y payment_status.
     *
     * @return array{paid_total:float,credit_total:float,balance:float,payment_status:string}
     */
    public function refreshPaymentStatus(int $orderId): array
    {
        $row = $this->query(
            "SELECT po.total, COALESCE(NULLIF(po.supplier_invoice_value, 0), po.total) base_total, po.payment_mode,
                    COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) paid,
                    COALESCE((
                        SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) discounted,
                    COALESCE((
                        SELECT SUM(cn.total) FROM purchase_credit_notes cn
                        WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                    ), 0) credited
             FROM purchase_orders po
             WHERE po.id = ?",
            [$orderId]
        )->fetch();
        if (!$row) {
            return ['paid_total' => 0.0, 'credit_total' => 0.0, 'balance' => 0.0, 'payment_status' => 'pending'];
        }

        $total = round((float)$row['total'], 2);
        $base = round((float)$row['base_total'], 2);
        $paid = round((float)$row['paid'], 2);
        $discounted = round((float)($row['discounted'] ?? 0), 2);
        $credited = round((float)$row['credited'], 2);

        // Los modos distintos de 'payable' se pagaron de contado al recibir.
        // Tras una NC, el "pagado" efectivo es el bruto menos lo acreditado (evita saldo negativo ficticio).
        if ((string)($row['payment_mode'] ?? 'payable') !== 'payable') {
            $paid = max(0.0, round($total - $credited, 2));
            $discounted = 0.0;
            $base = $total;
        }

        $balance = round($base - $paid - $discounted - $credited, 2);
        $status = match (true) {
            $balance <= 0.009 => 'paid',
            ($paid + $credited + $discounted) > 0.009 => 'partial',
            default => 'pending',
        };

        try {
            $this->query(
                "UPDATE purchase_orders SET paid_total = ?, credit_total = ?, payment_status = ? WHERE id = ?",
                [$paid, $credited, $status, $orderId]
            );
        } catch (\Throwable $e) {
            $this->query(
                "UPDATE purchase_orders SET paid_total = ?, payment_status = ? WHERE id = ?",
                [$paid, $status, $orderId]
            );
        }

        return [
            'paid_total' => $paid,
            'credit_total' => $credited,
            'discount_total' => $discounted,
            'base_total' => $base,
            'balance' => $balance,
            'payment_status' => $status,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function orderPayments(int $orderId): array
    {
        try {
            $rows = $this->query(
                "SELECT sp.*, u.name created_by_name, e.entry_number accounting_entry_number
                 FROM supplier_payments sp
                 LEFT JOIN users u ON u.id = sp.created_by
                 LEFT JOIN accounting_entries e ON e.id = sp.accounting_entry_id
                 WHERE sp.purchase_order_id = ?
                 ORDER BY sp.payment_date, sp.id",
                [$orderId]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        // Etiquetas legibles para la UI: medio elegido y, si fue efectivo,
        // si salió del cajón o de otro fondo. El origen SOLO es 'drawer' con
        // cash_from_drawer = 1 explícito; vacío/sin dato queda 'unknown'.
        foreach ($rows as &$p) {
            $method = (string)($p['payment_method'] ?? '');
            $p['payment_method_label'] = PaymentMethodCatalog::label($method, $this->db);
            $p['cash_origin'] = '';
            $p['cash_origin_label'] = '';
            if (PaymentMethodCatalog::affectsCash($method)) {
                if (array_key_exists('cash_from_drawer', $p) && $p['cash_from_drawer'] !== null && $p['cash_from_drawer'] !== '') {
                    $p['cash_origin'] = (int)$p['cash_from_drawer'] === 1 ? 'drawer' : 'other';
                } else {
                    $p['cash_origin'] = 'unknown';
                }
                $p['cash_origin_label'] = match ($p['cash_origin']) {
                    'drawer' => 'Del cajón',
                    'other' => 'Otro fondo',
                    default => 'Origen no registrado',
                };
            }
        }
        unset($p);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function getSupplierPayment(int $paymentId): ?array
    {
        if ($paymentId <= 0) {
            return null;
        }
        try {
            $row = $this->query(
                "SELECT * FROM supplier_payments WHERE id = ? LIMIT 1",
                [$paymentId]
            )->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public function orderCreditNotes(int $orderId): array
    {
        try {
            $notes = $this->query(
                "SELECT cn.*, u.name created_by_name, e.entry_number accounting_entry_number
                 FROM purchase_credit_notes cn
                 LEFT JOIN users u ON u.id = cn.created_by
                 LEFT JOIN accounting_entries e ON e.id = cn.accounting_entry_id
                 WHERE cn.purchase_order_id = ?
                 ORDER BY cn.credit_date, cn.id",
                [$orderId]
            )->fetchAll();
            foreach ($notes as &$note) {
                $note['items'] = $this->query(
                    "SELECT * FROM purchase_credit_note_items WHERE credit_note_id = ? ORDER BY id",
                    [(int)$note['id']]
                )->fetchAll();
            }
            unset($note);
            return $notes;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function nextCreditNumber(?string $date = null): string
    {
        $date = $date ?: date('Y-m-d');
        $prefix = 'NCC-' . date('Ym', strtotime($date)) . '-';
        $last = $this->query(
            "SELECT credit_number FROM purchase_credit_notes
             WHERE credit_number LIKE ? ORDER BY id DESC LIMIT 1",
            [$prefix . '%']
        )->fetchColumn();
        $seq = $last ? ((int)substr((string)$last, -4) + 1) : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Emite una nota de crédito sobre una OC recibida.
     * Reduce el saldo por pagar y, si aplica, saca mercancía del inventario.
     *
     * @param list<array{purchase_order_item_id:int,quantity:int}> $lines
     * @return array{credit_note_id:int,credit_number:string,total:float,balance:float,payment_status:string}
     */
    public function createCreditNote(
        int $orderId,
        array $lines,
        string $reason = '',
        bool $affectsStock = true,
        string $creditDate = '',
        string $externalNumber = ''
    ): array {
        SupplyChainSchema::ensure($this->db);

        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if ($order['status'] !== 'received') {
            throw new \RuntimeException('Solo se emiten notas de crédito sobre órdenes ya recibidas');
        }

        $stock = new StockService($this->db);
        $creditDate = $stock->normalizeDate($creditDate !== '' ? $creditDate : date('Y-m-d'));

        $itemsById = [];
        foreach ($order['items'] as $item) {
            $itemsById[(int)$item['id']] = $item;
        }

        $normalized = [];
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $poiId = (int)($line['purchase_order_item_id'] ?? 0);
            $qty = (int)($line['quantity'] ?? 0);
            if ($poiId <= 0 || $qty <= 0) {
                continue;
            }
            $item = $itemsById[$poiId] ?? null;
            if (!$item) {
                throw new \InvalidArgumentException('Línea de OC inválida en la nota de crédito');
            }
            $received = (int)($item['received_qty'] ?? $item['quantity'] ?? 0);
            $already = (int)($item['credited_qty'] ?? 0);
            $available = $received - $already;
            if ($qty > $available) {
                throw new \InvalidArgumentException(
                    'Cantidad a acreditar supera lo disponible en «' . ($item['product_name'] ?? '') . '»'
                    . ' (máx. ' . max(0, $available) . ')'
                );
            }
            $unitCost = round((float)$item['unit_cost'], 2);
            $rate = round((float)$item['tax_rate'], 2);
            $lineSub = round($qty * $unitCost, 2);
            // Preferir tax_amount de la OC (IVA personalizado / prorrateo) sobre % recalculado.
            $origQty = max(0, (int)($item['received_qty'] ?? $item['quantity'] ?? 0));
            $origTax = round((float)($item['tax_amount'] ?? 0), 2);
            if ($origTax > 0.009 && $origQty > 0) {
                $lineTax = round($origTax * ($qty / $origQty), 2);
                if ($lineSub > 0.009) {
                    $rate = round(($lineTax / $lineSub) * 100, 4);
                }
            } else {
                $lineTax = round($lineSub * ($rate / 100), 2);
            }
            $normalized[] = [
                'purchase_order_item_id' => $poiId,
                'product_id' => !empty($item['product_id']) ? (int)$item['product_id'] : null,
                'product_name' => (string)$item['product_name'],
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'subtotal' => $lineSub,
                'line_total' => round($lineSub + $lineTax, 2),
            ];
            $subtotal += $lineSub;
            $tax += $lineTax;
        }

        if (empty($normalized)) {
            throw new \InvalidArgumentException('Indique al menos un producto y cantidad para la nota de crédito');
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);
        $gross = round($subtotal + $tax, 2);
        // Prorratear descuentos de la OC (comercial + pronto pago) sobre el tramo acreditado.
        $orderSub = round((float)($order['subtotal'] ?? 0), 2);
        $orderTax = round((float)($order['tax'] ?? 0), 2);
        $orderGross = round($orderSub + $orderTax, 2);
        $share = $orderGross > 0.009 ? min(1.0, $gross / $orderGross) : 1.0;
        $commercialPct = (float)($order['commercial_discount_pct'] ?? 0);
        $commercialAmtIn = $commercialPct > 0.009
            ? 0.0
            : round((float)($order['commercial_discount_amount'] ?? 0) * $share, 2);
        $disc = self::resolvePurchaseDiscounts(
            $subtotal,
            $tax,
            (float)($order['early_payment_discount_pct'] ?? 0),
            $commercialPct,
            $commercialAmtIn
        );
        $total = $disc['total'];
        $discountAmt = round($disc['commercial_amount'] + $disc['early_amount'], 2);
        if ($discountAmt > 0.009) {
            $reason = trim($reason);
            $parts = [];
            if ($disc['commercial_amount'] > 0.009) {
                $parts[] = 'Desc. comercial';
            }
            if ($disc['early_amount'] > 0.009) {
                $parts[] = 'Desc. pronto pago '
                    . rtrim(rtrim(number_format($disc['early_pct'], 2, '.', ''), '0'), '.') . '%';
            }
            $discNote = implode(' + ', $parts);
            $reason = $reason !== '' ? ($reason . ' · ' . $discNote) : $discNote;
        }
        $creditNumber = $this->nextCreditNumber($creditDate);

        $this->db->beginTransaction();
        try {
            $locked = $this->query(
                "SELECT id, status FROM purchase_orders WHERE id = ? FOR UPDATE",
                [$orderId]
            )->fetch();
            if (!$locked || (string)($locked['status'] ?? '') !== 'received') {
                throw new \RuntimeException('Solo se emiten notas de crédito sobre órdenes ya recibidas');
            }

            $this->query(
                "INSERT INTO purchase_credit_notes
                    (credit_number, purchase_order_id, supplier_id, credit_date, reason,
                     external_number, affects_stock, subtotal, tax, total, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $creditNumber,
                    $orderId,
                    $order['supplier_id'] ?: null,
                    $creditDate,
                    $reason !== '' ? $reason : null,
                    $externalNumber !== '' ? $externalNumber : null,
                    $affectsStock ? 1 : 0,
                    $subtotal,
                    $tax,
                    $total,
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
            $creditNoteId = (int)$this->db->lastInsertId();

            foreach ($normalized as $row) {
                $this->query(
                    "INSERT INTO purchase_credit_note_items
                        (credit_note_id, purchase_order_item_id, product_id, product_name,
                         quantity, unit_cost, tax_rate, tax_amount, subtotal, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $creditNoteId,
                        $row['purchase_order_item_id'],
                        $row['product_id'],
                        $row['product_name'],
                        $row['quantity'],
                        $row['unit_cost'],
                        $row['tax_rate'],
                        $row['tax_amount'],
                        $row['subtotal'],
                        $row['line_total'],
                    ]
                );

                if ($affectsStock && !empty($row['product_id'])) {
                    $stock->decrease(
                        (int)$row['product_id'],
                        (int)$row['quantity'],
                        'purchase_credit_note',
                        $creditNoteId,
                        'Nota de crédito ' . $creditNumber . ' / OC ' . $order['order_number'],
                        (float)$row['unit_cost']
                    );
                }
            }

            $status = $this->refreshPaymentStatus($orderId);
            $this->markOrderIfFullyCredited($orderId, $affectsStock);
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        TenantAuditService::safeLog(
            $this->db,
            'cancel',
            'compras',
            'Nota de crédito ' . $creditNumber . ' · OC ' . (string)($order['order_number'] ?? '')
                . ' · total=' . number_format($total, 0, '.', '')
                . ($affectsStock ? ' · con salida stock' : ' · sin stock'),
            'purchase_credit_note',
            $creditNoteId,
            [
                'purchase_order_id' => $orderId,
                'affects_stock' => $affectsStock,
                'total' => $total,
            ]
        );

        return [
            'credit_note_id' => $creditNoteId,
            'credit_number' => $creditNumber,
            'total' => $total,
            'balance' => $status['balance'],
            'payment_status' => $status['payment_status'],
            'fully_credited' => $this->isOrderFullyCredited($orderId),
        ];
    }

    /**
     * ¿La OC quedó sin cantidad acreditable (devolución completa de ítems)?
     */
    public function isOrderFullyCredited(int $orderId): bool
    {
        $order = $this->getOrder($orderId);
        if (!$order || ($order['status'] ?? '') === 'cancelled') {
            return ($order['status'] ?? '') === 'cancelled';
        }
        $creditTotal = round((float)($order['credit_total'] ?? 0), 2);
        $orderTotal = round((float)($order['total'] ?? 0), 2);
        if ($orderTotal > 0.009 && $creditTotal + 0.009 >= $orderTotal) {
            return true;
        }
        foreach ($order['items'] as $item) {
            $received = (int)($item['received_qty'] ?? $item['quantity'] ?? 0);
            $already = (int)($item['credited_qty'] ?? 0);
            if ($received - $already > 0) {
                return false;
            }
        }
        return !empty($order['items']);
    }

    /**
     * Guarda observaciones internas de una factura/OC (columna review_notes).
     *
     * @return array{order_id:int,review_notes:string,changed:bool}
     */
    public function saveReviewNotes(int $orderId, string $notes): array
    {
        $order = $this->query(
            "SELECT id, order_number, invoice_number, invoice_import_id, COALESCE(review_notes,'') AS review_notes
             FROM purchase_orders WHERE id = ? LIMIT 1",
            [$orderId]
        )->fetch();
        if (!$order) {
            throw new \RuntimeException('Factura/OC no encontrada');
        }

        $notes = trim(str_replace(["\r\n", "\r"], "\n", $notes));
        if (function_exists('mb_substr')) {
            $notes = mb_substr($notes, 0, 2000);
        } else {
            $notes = substr($notes, 0, 2000);
        }
        $prev = trim((string)($order['review_notes'] ?? ''));
        if ($prev === $notes) {
            return [
                'order_id' => $orderId,
                'review_notes' => $notes,
                'changed' => false,
            ];
        }

        try {
            $this->query(
                "UPDATE purchase_orders SET review_notes = NULLIF(?, '') WHERE id = ?",
                [$notes, $orderId]
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No se pudo guardar la observación. Ejecute la migración de columnas (review_notes).'
            );
        }

        $importId = (int)($order['invoice_import_id'] ?? 0);
        $label = $notes === ''
            ? 'Observación eliminada'
            : ($prev === '' ? 'Observación agregada' : 'Observación actualizada');
        $detail = $notes !== '' ? $notes : ($prev !== '' ? ('Antes: ' . $prev) : '');
        try {
            (new PurchaseInvoiceImportService($this->db))->logLifecycleEvent(
                $importId > 0 ? $importId : null,
                $orderId,
                'invoice_observation',
                $label,
                $detail,
                [
                    'source' => 'facturas_list',
                    'order_number' => (string)($order['order_number'] ?? ''),
                    'invoice_number' => (string)($order['invoice_number'] ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            // Trazabilidad opcional
        }

        TenantAuditService::safeLog(
            $this->db,
            'note',
            'compras',
            $label . ': OC ' . (string)($order['order_number'] ?? '')
                . ($notes !== '' ? (' — ' . mb_substr($notes, 0, 120)) : ''),
            'purchase_order',
            $orderId,
            ['review_notes' => $notes]
        );

        return [
            'order_id' => $orderId,
            'review_notes' => $notes,
            'changed' => true,
        ];
    }

    /**
     * Marca en notas si la factura quedó totalmente acreditada (devolución).
     */
    private function markOrderIfFullyCredited(int $orderId, bool $affectsStock): void
    {
        if (!$this->isOrderFullyCredited($orderId)) {
            return;
        }
        $tag = $affectsStock ? '[[NC_FULL_RETURN]]' : '[[NC_FULL_ADJUST]]';
        $this->query(
            "UPDATE purchase_orders
             SET notes = CASE
                    WHEN COALESCE(notes,'') LIKE ? THEN notes
                    ELSE CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), ?)
                 END
             WHERE id = ?",
            ['%' . $tag . '%', $tag . ' Devolución/NC total ' . date('Y-m-d H:i'), $orderId]
        );
    }

    /**
     * Anula una OC ya recibida por error: salida de stock y status cancelled, sin NC
     * (el controller reversa el asiento de la OC y devuelve el efectivo al cajón).
     * Si la OC ya tenía NC parciales, el resto se acredita por NC (comportamiento previo).
     * Si había abonos, los cancela automáticamente (el controller revierte contabilidad/caja).
     *
     * @return array{
     *   credit_note_id:int,credit_number:string,total:float,
     *   order_id:int,order_number:string,reversed_payments:list<array>,already_credited?:bool,direct?:bool
     * }
     */
    public function voidReceivedOrder(int $orderId, string $reason = ''): array
    {
        SupplyChainSchema::ensure($this->db);
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('Orden de compra no encontrada');
        }
        if (($order['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Esta OC ya está anulada/cancelada');
        }
        if (($order['status'] ?? '') !== 'received') {
            throw new \RuntimeException('Solo se pueden anular facturas/OC ya recibidas. Use Cancelar si aún está abierta.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Error de recepción';
        }
        if (mb_strlen($reason) < 3) {
            throw new \InvalidArgumentException('Indique un motivo de anulación (mín. 3 caracteres)');
        }

        // Validar cantidades ANTES de tocar abonos (evita estado a medias si falla la NC).
        $lines = [];
        foreach ($order['items'] as $item) {
            $poiId = (int)($item['id'] ?? 0);
            $received = (int)($item['received_qty'] ?? $item['quantity'] ?? 0);
            $already = (int)($item['credited_qty'] ?? 0);
            $available = $received - $already;
            if ($poiId <= 0 || $available <= 0) {
                continue;
            }
            $lines[] = [
                'purchase_order_item_id' => $poiId,
                'quantity' => $available,
            ];
        }
        $alreadyFullyCredited = $lines === [] && $this->isOrderFullyCredited($orderId);
        if ($lines === [] && !$alreadyFullyCredited) {
            throw new \RuntimeException('No hay cantidades recibidas para anular en esta OC');
        }

        // Abonos: se cancelan aquí; el controller revierte asiento/caja de cada uno.
        $reversedPayments = $this->cancelAppliedPaymentsForVoid($orderId, $reason);
        if ($reversedPayments !== []) {
            try {
                $this->refreshPaymentStatus($orderId);
            } catch (\Throwable $e) {
                // continue
            }
        }

        // Sin NC previas: anulación directa (reverso de inventario, sin documento NC).
        // Con NC parciales reales se acredita el resto por NC para no duplicar su asiento.
        if (!$alreadyFullyCredited && !$this->hasPostedCreditNotes($orderId)) {
            $fullReason = 'ANULACIÓN OC: ' . $reason;
            try {
                $this->voidReceivedOrderDirect($orderId, $order, $lines, $fullReason);
            } catch (\Throwable $e) {
                $this->restorePaymentsAfterFailedVoid($reversedPayments);
                try {
                    $this->refreshPaymentStatus($orderId);
                } catch (\Throwable $ignore) {
                }
                throw $e;
            }

            TenantAuditService::safeLog(
                $this->db,
                'cancel',
                'compras',
                'Anulación factura/OC ' . (string)($order['order_number'] ?? '') . ': ' . mb_substr($fullReason, 0, 160),
                'purchase_order',
                $orderId,
                ['reason' => $fullReason, 'direct' => true],
                'warning'
            );

            return [
                'credit_note_id' => 0,
                'credit_number' => '',
                'total' => round((float)($order['total'] ?? 0), 2),
                'order_id' => $orderId,
                'order_number' => (string)($order['order_number'] ?? ''),
                'already_credited' => false,
                'direct' => true,
                'reversed_payments' => $reversedPayments,
            ];
        }

        if ($alreadyFullyCredited) {
            $this->finalizeVoidedOrder($orderId, $reason);
            return [
                'credit_note_id' => 0,
                'credit_number' => '',
                'total' => 0.0,
                'order_id' => $orderId,
                'order_number' => (string)($order['order_number'] ?? ''),
                'already_credited' => true,
                'reversed_payments' => $reversedPayments,
            ];
        }

        $fullReason = 'ANULACIÓN OC: ' . $reason;
        try {
            $result = $this->createCreditNote($orderId, $lines, $fullReason, true, date('Y-m-d'), '');
        } catch (\Throwable $e) {
            // Restaurar abonos si la NC no se creó (evita CxP fantasma).
            $this->restorePaymentsAfterFailedVoid($reversedPayments);
            try {
                $this->refreshPaymentStatus($orderId);
            } catch (\Throwable $ignore) {
            }
            throw $e;
        }
        $this->finalizeVoidedOrder($orderId, $fullReason);

        TenantAuditService::safeLog(
            $this->db,
            'cancel',
            'compras',
            'Anulación factura/OC ' . (string)($order['order_number'] ?? '') . ': ' . mb_substr($fullReason, 0, 160),
            'purchase_order',
            $orderId,
            [
                'credit_note_id' => (int)$result['credit_note_id'],
                'reason' => $fullReason,
            ],
            'warning'
        );

        return [
            'credit_note_id' => (int)$result['credit_note_id'],
            'credit_number' => (string)$result['credit_number'],
            'total' => (float)$result['total'],
            'order_id' => $orderId,
            'order_number' => (string)($order['order_number'] ?? ''),
            'already_credited' => false,
            'reversed_payments' => $reversedPayments,
        ];
    }

    /** @param list<array{id:int,amount?:float,payment_method?:string}> $payments */
    private function restorePaymentsAfterFailedVoid(array $payments): void
    {
        foreach ($payments as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $this->query(
                    "UPDATE supplier_payments SET status = 'applied' WHERE id = ? AND status = 'cancelled'",
                    [$id]
                );
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }

    private function hasPostedCreditNotes(int $orderId): bool
    {
        try {
            return (int)$this->query(
                "SELECT COUNT(*) FROM purchase_credit_notes WHERE purchase_order_id = ? AND status = 'posted'",
                [$orderId]
            )->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Saca del inventario lo recibido (movimiento purchase_cancel) y marca la OC anulada.
     * Contabilidad y caja los revierte el controller.
     *
     * @param array<string,mixed> $order
     * @param list<array{purchase_order_item_id:int,quantity:int}> $lines
     */
    private function voidReceivedOrderDirect(int $orderId, array $order, array $lines, string $reason): void
    {
        $itemsById = [];
        foreach ($order['items'] as $item) {
            $itemsById[(int)$item['id']] = $item;
        }
        $stock = new StockService($this->db);

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $locked = $this->query(
                "SELECT id, status FROM purchase_orders WHERE id = ? FOR UPDATE",
                [$orderId]
            )->fetch();
            if (!$locked || (string)($locked['status'] ?? '') !== 'received') {
                throw new \RuntimeException('Solo se pueden anular facturas/OC ya recibidas');
            }

            foreach ($lines as $line) {
                $item = $itemsById[(int)$line['purchase_order_item_id']] ?? null;
                if (!$item || empty($item['product_id'])) {
                    continue;
                }
                $stock->decrease(
                    (int)$item['product_id'],
                    (int)$line['quantity'],
                    'purchase_cancel',
                    $orderId,
                    'Anulación OC ' . (string)($order['order_number'] ?? ('#' . $orderId)),
                    round((float)($item['unit_cost'] ?? 0), 2)
                );
            }

            $this->finalizeVoidedOrder($orderId, $reason, false);
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function finalizeVoidedOrder(int $orderId, string $reason, bool $refresh = true): void
    {
        $note = 'ANULADA POST-RECEPCIÓN: ' . ($reason !== '' ? $reason : date('Y-m-d H:i'));
        $voidReason = mb_substr($reason !== '' ? $reason : $note, 0, 255);
        // Prefijo en notes + voided_at dedicado (trazabilidad aunque notes se trunque).
        try {
            $this->query(
                "UPDATE purchase_orders
                 SET status = 'cancelled',
                     payment_status = 'paid',
                     voided_at = COALESCE(voided_at, NOW()),
                     void_reason = COALESCE(NULLIF(void_reason,''), ?),
                     notes = CONCAT(?, IF(IFNULL(notes,'') = '', '', ' | '), IFNULL(notes,''))
                 WHERE id = ? AND status IN ('received','cancelled')",
                [$voidReason, $note, $orderId]
            );
        } catch (\Throwable $e) {
            $this->query(
                "UPDATE purchase_orders
                 SET status = 'cancelled',
                     payment_status = 'paid',
                     notes = CONCAT(?, IF(IFNULL(notes,'') = '', '', ' | '), IFNULL(notes,''))
                 WHERE id = ? AND status IN ('received','cancelled')",
                [$note, $orderId]
            );
        }
        if (!$refresh) {
            return;
        }
        // Si ya estaba cancelled, igual refrescar saldos.
        try {
            $this->refreshPaymentStatus($orderId);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Detalle de una nota de crédito (cabecera + ítems + OC + proveedor).
     *
     * @return array<string,mixed>|null
     */
    public function getCreditNote(int $id): ?array
    {
        SupplyChainSchema::ensure($this->db);
        if ($id <= 0) {
            return null;
        }
        try {
            $note = $this->query(
                "SELECT cn.*,
                        po.order_number, po.invoice_number, po.order_date,
                        s.name supplier_name, s.document_number supplier_document,
                        s.email supplier_email, s.phone supplier_phone,
                        u.name created_by_name, e.entry_number accounting_entry_number
                 FROM purchase_credit_notes cn
                 LEFT JOIN purchase_orders po ON po.id = cn.purchase_order_id
                 LEFT JOIN suppliers s ON s.id = cn.supplier_id
                 LEFT JOIN users u ON u.id = cn.created_by
                 LEFT JOIN accounting_entries e ON e.id = cn.accounting_entry_id
                 WHERE cn.id = ?",
                [$id]
            )->fetch();
            if (!$note) {
                return null;
            }
            $note['items'] = $this->query(
                "SELECT * FROM purchase_credit_note_items WHERE credit_note_id = ? ORDER BY id",
                [$id]
            )->fetchAll();
            return $note;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Listado global de notas de crédito de compra.
     *
     * @return list<array<string,mixed>>
     */
    public function listCreditNotes(int $limit = 50, int $offset = 0, string $q = ''): array
    {
        SupplyChainSchema::ensure($this->db);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = ['1=1'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(cn.credit_number LIKE ? OR cn.external_number LIKE ? OR cn.reason LIKE ?
                        OR po.order_number LIKE ? OR po.invoice_number LIKE ? OR s.name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        try {
            return $this->query(
                "SELECT cn.*, po.order_number, po.invoice_number,
                        s.name supplier_name, e.entry_number accounting_entry_number,
                        u.name created_by_name
                 FROM purchase_credit_notes cn
                 LEFT JOIN purchase_orders po ON po.id = cn.purchase_order_id
                 LEFT JOIN suppliers s ON s.id = cn.supplier_id
                 LEFT JOIN accounting_entries e ON e.id = cn.accounting_entry_id
                 LEFT JOIN users u ON u.id = cn.created_by
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY cn.credit_date DESC, cn.id DESC
                 LIMIT {$limit} OFFSET {$offset}",
                $params
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function countCreditNotes(string $q = ''): int
    {
        SupplyChainSchema::ensure($this->db);
        $where = ['1=1'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(cn.credit_number LIKE ? OR cn.external_number LIKE ? OR cn.reason LIKE ?
                        OR po.order_number LIKE ? OR po.invoice_number LIKE ? OR s.name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        try {
            return (int)$this->query(
                "SELECT COUNT(*) FROM purchase_credit_notes cn
                 LEFT JOIN purchase_orders po ON po.id = cn.purchase_order_id
                 LEFT JOIN suppliers s ON s.id = cn.supplier_id
                 WHERE " . implode(' AND ', $where),
                $params
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Cartera por pagar a proveedores, agrupada por orden con saldo pendiente.
     *
     * @param string $dueFilter overdue|due_today|due_soon|''
     * @return list<array<string,mixed>>
     */
    public function payableOrders(int $limit = 100, string $dueFilter = ''): array
    {
        $limit = max(1, min(300, $limit));
        $dueSql = '';
        $dueFilter = strtolower(trim($dueFilter));
        if ($dueFilter === 'overdue') {
            $dueSql = ' AND po.due_date IS NOT NULL AND po.due_date < CURDATE()';
        } elseif ($dueFilter === 'due_today') {
            $dueSql = ' AND po.due_date = CURDATE()';
        } elseif ($dueFilter === 'due_soon') {
            $dueSql = ' AND po.due_date IS NOT NULL AND po.due_date >= CURDATE() AND po.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        }
        try {
            return $this->query(
                "SELECT po.id, po.order_number, po.invoice_number, po.order_date, po.due_date,
                        po.total, po.supplier_invoice_value, po.payment_status, s.name supplier_name, po.supplier_id,
                        COALESCE((
                            SELECT SUM(sp.amount) FROM supplier_payments sp
                            WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                        ), 0) paid_amount,
                        COALESCE((
                            SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                            WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                        ), 0) discount_amount,
                        COALESCE((
                            SELECT SUM(cn.total) FROM purchase_credit_notes cn
                            WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                        ), 0) credit_amount,
                        DATEDIFF(CURDATE(), po.due_date) days_overdue
                 FROM purchase_orders po
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 WHERE po.status = 'received'
                   AND COALESCE(po.payment_mode, 'payable') = 'payable'
                   AND COALESCE(NULLIF(po.supplier_invoice_value, 0), po.total) - COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                   ), 0) - COALESCE((
                        SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                   ), 0) - COALESCE((
                        SELECT SUM(cn.total) FROM purchase_credit_notes cn
                        WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                   ), 0) > 0.009
                   {$dueSql}
                 ORDER BY po.due_date IS NULL, po.due_date, po.id
                 LIMIT {$limit}"
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function payableTotal(): float
    {
        try {
            return round((float)$this->query(
                "SELECT COALESCE(SUM(COALESCE(NULLIF(po.supplier_invoice_value, 0), po.total) - COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                   ), 0) - COALESCE((
                        SELECT SUM(sp.discount_amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                   ), 0) - COALESCE((
                        SELECT SUM(cn.total) FROM purchase_credit_notes cn
                        WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                   ), 0)), 0)
                 FROM purchase_orders po
                 WHERE po.status = 'received'
                   AND COALESCE(po.payment_mode, 'payable') = 'payable'"
            )->fetchColumn(), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** Marca una OC como copia de otra (trazabilidad). */
    public function markDuplicatedFrom(int $orderId, int $sourceOrderId): void
    {
        if ($orderId <= 0 || $sourceOrderId <= 0 || $orderId === $sourceOrderId) {
            return;
        }
        $this->ensureSchema();
        try {
            $this->query(
                "UPDATE purchase_orders
                 SET duplicated_from_id = ?, origin = CASE WHEN origin = 'manual' OR origin IS NULL OR origin = '' THEN 'duplicate' ELSE origin END
                 WHERE id = ?",
                [$sourceOrderId, $orderId]
            );
        } catch (\Throwable $e) {
            error_log('markDuplicatedFrom OC ' . $orderId . ': ' . $e->getMessage());
            throw new \RuntimeException(
                'La orden se creó, pero no se pudo registrar el vínculo de duplicado. Revise el esquema de compras.'
            );
        }
    }

    /**
     * Listado para trazabilidad de facturas/OC en Contabilidad.
     * @return list<array<string,mixed>>
     */
    public function listInvoiceTrace(string $from, string $to, string $q = '', int $limit = 80, string $sort = 'date', string $dir = 'desc'): array
    {
        $this->ensureSchema();
        $limit = max(1, min(200, $limit));
        $sortMap = [
            'oc' => 'po.order_number',
            'supplier' => 's.name',
            'date' => 'COALESCE(po.invoice_date, po.warehouse_date, po.order_date, po.created_at)',
            'total' => 'po.total',
            'status' => 'po.status',
        ];
        $sortSql = $sortMap[$sort] ?? $sortMap['date'];
        $dirSql = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $params = [$from, $to];
        $where = "DATE(COALESCE(po.invoice_date, po.warehouse_date, po.order_date, po.created_at)) BETWEEN ? AND ?
                  AND (po.status = 'received' OR (po.status = 'cancelled' AND (
                        COALESCE(po.notes,'') LIKE '%ANULADA POST-RECEPCIÓN%'
                     OR COALESCE(po.notes,'') LIKE '%[[NC_FULL%'
                     OR COALESCE(po.credit_total,0) > 0.009
                  )))";
        $q = trim($q);
        if ($q !== '') {
            $where .= " AND (po.order_number LIKE ? OR po.invoice_number LIKE ? OR s.name LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $params[] = $limit;
        return $this->query(
            "SELECT po.id, po.order_number, po.invoice_number, po.invoice_date, po.order_date,
                    po.warehouse_date, po.due_date, po.status, po.total, po.paid_total, po.credit_total,
                    po.payment_status, po.accounting_entry_id, po.origin, po.duplicated_from_id,
                    po.received_at, po.created_at, po.sent_at, po.sent_via,
                    s.name supplier_name,
                    e.entry_number accounting_entry_number,
                    (SELECT COUNT(*) FROM supplier_payments sp
                      WHERE sp.purchase_order_id = po.id AND sp.status = 'applied') payments_count,
                    (SELECT COUNT(*) FROM purchase_credit_notes cn
                      WHERE cn.purchase_order_id = po.id AND cn.status = 'posted') credit_notes_count
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             LEFT JOIN accounting_entries e ON e.id = po.accounting_entry_id
             WHERE {$where}
             ORDER BY {$sortSql} {$dirSql}, po.id {$dirSql}
             LIMIT ?",
            $params
        )->fetchAll();
    }

    /**
     * Timeline de hitos de una factura/OC (reconstruido + bitácora interna).
     * @return array{order:?array,events:list<array<string,mixed>>}
     */
    public function invoiceTimeline(int $orderId): array
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return ['order' => null, 'events' => []];
        }
        $events = [];

        $push = static function (array &$events, string $at, string $type, string $label, string $detail = '', ?float $amount = null, array $meta = []): void {
            if ($at === '' || $at === '0000-00-00' || $at === '0000-00-00 00:00:00') {
                return;
            }
            $events[] = [
                'at' => $at,
                'type' => $type,
                'label' => $label,
                'detail' => $detail,
                'amount' => $amount,
                'meta' => $meta,
            ];
        };

        $createdAt = (string)($order['created_at'] ?? $order['order_date'] ?? '');
        $push(
            $events,
            $createdAt,
            'created',
            'Orden creada',
            trim(($order['order_number'] ?? '') . ' · ' . ($order['created_by_name'] ?? 'Usuario')),
            (float)($order['total'] ?? 0),
            ['origin' => $order['origin'] ?? 'manual']
        );

        if (!empty($order['duplicated_from_id'])) {
            $src = $this->query(
                "SELECT order_number FROM purchase_orders WHERE id = ?",
                [(int)$order['duplicated_from_id']]
            )->fetchColumn();
            $push(
                $events,
                $createdAt,
                'duplicated',
                'Duplicada desde otra OC',
                $src ? ('Origen: ' . $src) : ('OC #' . (int)$order['duplicated_from_id'])
            );
        }

        $importId = (int)($order['invoice_import_id'] ?? 0);
        $loggedTypes = [];

        // Bitácora explícita de movimientos internos (carga, edición, revisión…).
        try {
            $lifecycleSql = "SELECT e.event_type, e.label, e.detail, e.meta_json, e.created_at, u.name AS actor_name
                 FROM purchase_invoice_lifecycle_events e
                 LEFT JOIN users u ON u.id = e.actor_user_id
                 WHERE e.purchase_order_id = ?";
            $lifecycleParams = [$orderId];
            if ($importId > 0) {
                $lifecycleSql .= " OR e.import_id = ?";
                $lifecycleParams[] = $importId;
            }
            $lifecycleSql .= " ORDER BY e.created_at ASC, e.id ASC";
            $lifecycleRows = $this->query($lifecycleSql, $lifecycleParams)->fetchAll();
            foreach ($lifecycleRows as $row) {
                $type = (string)($row['event_type'] ?? 'lifecycle');
                $loggedTypes[$type] = true;
                $detail = trim((string)($row['detail'] ?? ''));
                $actor = trim((string)($row['actor_name'] ?? ''));
                if ($actor !== '') {
                    $detail = $detail !== '' ? ($detail . ' · ' . $actor) : ('Por ' . $actor);
                }
                $meta = [];
                if (!empty($row['meta_json'])) {
                    $decoded = json_decode((string)$row['meta_json'], true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                $push(
                    $events,
                    (string)($row['created_at'] ?? ''),
                    $type,
                    (string)($row['label'] ?? 'Movimiento interno'),
                    $detail,
                    null,
                    $meta
                );
            }
        } catch (\Throwable $e) {
            // Tabla aún no migrada
        }

        if ($importId > 0) {
            try {
                $imp = $this->query(
                    "SELECT id, invoice_number, status, source, original_name, file_path,
                            created_at, updated_at, confirmed_at, discarded_at, parse_notes,
                            created_by, discarded_by
                     FROM purchase_invoice_imports WHERE id = ?",
                    [$importId]
                )->fetch();
                if ($imp) {
                    $srcLabel = PurchaseInvoiceImportService::sourceLabel((string)($imp['source'] ?? ''));
                    $fileName = trim((string)($imp['original_name'] ?? ''));
                    $invNo = trim((string)($imp['invoice_number'] ?? ''));
                    $createdImp = (string)($imp['created_at'] ?? $createdAt);

                    if (empty($loggedTypes['invoice_uploaded']) && empty($loggedTypes['invoice_import'])) {
                        $push(
                            $events,
                            $createdImp,
                            'invoice_uploaded',
                            'Factura cargada al sistema',
                            trim(
                                $srcLabel
                                . ($fileName !== '' ? ' · ' . $fileName : '')
                                . ($invNo !== '' ? ' · Nº ' . $invNo : '')
                            ),
                            null,
                            ['import_id' => (int)$imp['id'], 'source' => $imp['source'] ?? null]
                        );
                    }

                    if (empty($loggedTypes['invoice_pending'])) {
                        $push(
                            $events,
                            $createdImp,
                            'invoice_pending',
                            'Pendiente de revisión',
                            'Borrador en cola hasta confirmar o descartar'
                        );
                    }

                    $updatedAt = (string)($imp['updated_at'] ?? '');
                    $confirmedAt = (string)($imp['confirmed_at'] ?? '');
                    $discardedAt = (string)($imp['discarded_at'] ?? '');
                    if (
                        empty($loggedTypes['invoice_edited'])
                        && $updatedAt !== ''
                        && $updatedAt !== $createdImp
                        && strtotime($updatedAt) > (strtotime($createdImp) + 45)
                    ) {
                        // Solo si hubo edición real antes de confirmar/descartar (o sin esos timestamps).
                        $editCutoff = null;
                        if ($confirmedAt !== '') {
                            $editCutoff = strtotime($confirmedAt);
                        } elseif ($discardedAt !== '') {
                            $editCutoff = strtotime($discardedAt);
                        }
                        $updatedTs = strtotime($updatedAt);
                        if ($editCutoff === null || ($updatedTs !== false && $updatedTs < $editCutoff - 5)) {
                            $push(
                                $events,
                                $updatedAt,
                                'invoice_edited',
                                'Borrador editado en revisión',
                                'Se detectó modificación del borrador antes de cerrar el ciclo'
                            );
                        }
                    }

                    if (empty($loggedTypes['invoice_confirm']) && $confirmedAt !== '') {
                        $push(
                            $events,
                            $confirmedAt,
                            'invoice_confirm',
                            'Importación confirmada',
                            'Se generó la OC desde el borrador'
                        );
                    }

                    if (empty($loggedTypes['invoice_discarded']) && $discardedAt !== '') {
                        $push(
                            $events,
                            $discardedAt,
                            'invoice_discarded',
                            'Borrador descartado',
                            trim((string)($imp['parse_notes'] ?? ''))
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Esquema parcial: fallback mínimo
                try {
                    $imp = $this->query(
                        "SELECT id, invoice_number, status, created_at, confirmed_at
                         FROM purchase_invoice_imports WHERE id = ?",
                        [$importId]
                    )->fetch();
                    if ($imp && empty($loggedTypes['invoice_uploaded']) && empty($loggedTypes['invoice_import'])) {
                        $push(
                            $events,
                            (string)($imp['created_at'] ?? $createdAt),
                            'invoice_uploaded',
                            'Factura cargada al sistema',
                            trim(($imp['invoice_number'] ?? '') . ' · estado ' . ($imp['status'] ?? '')),
                            null,
                            ['import_id' => (int)$imp['id']]
                        );
                    }
                    if ($imp && empty($loggedTypes['invoice_confirm']) && !empty($imp['confirmed_at'])) {
                        $push(
                            $events,
                            (string)$imp['confirmed_at'],
                            'invoice_confirm',
                            'Importación confirmada',
                            'Se generó la OC desde el borrador'
                        );
                    }
                } catch (\Throwable $e2) {
                }
            }
        }

        if (!empty($order['sent_at'])) {
            $push(
                $events,
                (string)$order['sent_at'],
                'sent',
                'OC enviada al proveedor',
                trim(($order['sent_via'] ?? '') . ' ' . ($order['sent_to'] ?? ''))
            );
        }

        // Tres fechas del ciclo (documento / CxP / bodega) — siempre visibles en timeline.
        $invDateOnly = substr(trim((string)($order['invoice_date'] ?? '')), 0, 10);
        $dueDateOnly = substr(trim((string)($order['due_date'] ?? '')), 0, 10);
        $whDateOnly = substr(trim((string)($order['warehouse_date'] ?? '')), 0, 10);
        $invNo = trim((string)($order['invoice_number'] ?? ''));

        if ($invDateOnly !== '' && $invDateOnly !== '0000-00-00') {
            $push(
                $events,
                $invDateOnly,
                'date_invoice',
                'Fecha de factura',
                trim(
                    'Fecha impresa en el documento del proveedor'
                    . ($invNo !== '' ? ' · Nº ' . $invNo : '')
                )
            );
        }
        if ($dueDateOnly !== '' && $dueDateOnly !== '0000-00-00') {
            $push(
                $events,
                $dueDateOnly,
                'date_due',
                'Vencimiento de pago',
                'Fecha límite de pago / cuentas por pagar'
            );
        }
        // Solo si aún no hay recepción: si la mercancía ya se recibió, el evento
        // «Mercancía recibida» ya trae la fecha de bodega y este duplicaría.
        if ($whDateOnly !== '' && $whDateOnly !== '0000-00-00'
            && empty($order['received_at']) && ($order['status'] ?? '') !== 'received') {
            $push(
                $events,
                $whDateOnly,
                'date_warehouse',
                'Recepción de bodega',
                'Fecha de ingreso a inventario y del asiento contable'
            );
        }

        if (!empty($order['invoice_number']) || !empty($order['invoice_path'])) {
            $push(
                $events,
                (string)($order['received_at'] ?? $order['confirmed_at'] ?? $order['warehouse_date'] ?? $createdAt),
                'invoice',
                'Factura registrada en el sistema',
                trim(
                    ($invNo !== '' ? $invNo : 'Sin número')
                    . ($invDateOnly !== '' ? ' · Doc. ' . $invDateOnly : '')
                    . ($dueDateOnly !== '' ? ' · Vence ' . $dueDateOnly : '')
                    . (!empty($order['invoice_path']) ? ' · con adjunto' : '')
                )
            );
        }

        if (!empty($order['received_at']) || ($order['status'] ?? '') === 'received') {
            $push(
                $events,
                (string)($order['received_at'] ?? $order['warehouse_date'] ?? $order['order_date']),
                'received',
                'Mercancía recibida / confirmada',
                trim(
                    'Bodega: ' . ($whDateOnly !== '' ? $whDateOnly : '—')
                    . ($invDateOnly !== '' ? ' · Factura: ' . $invDateOnly : '')
                    . ($dueDateOnly !== '' ? ' · Vence: ' . $dueDateOnly : '')
                ),
                (float)($order['total'] ?? 0)
            );
        }

        if (!empty($order['accounting_entry_id'])) {
            $push(
                $events,
                (string)($order['received_at'] ?? $order['warehouse_date'] ?? $createdAt),
                'accounting',
                'Asiento de compra',
                (string)($order['accounting_entry_number'] ?? ('#' . $order['accounting_entry_id'])),
                (float)($order['total'] ?? 0),
                ['entry_id' => (int)$order['accounting_entry_id']]
            );
        }

        // Medio de pago elegido: en compras a crédito se ve en cada abono; en
        // las de contado vive en payment_mode de la orden y se muestra aquí.
        $payMode = (string)($order['payment_mode'] ?? 'payable');
        $payModeLabels = [
            'cash' => 'Efectivo · sale del cajón (110505)',
            'transfer' => 'Transferencia · bancos (111005)',
            'card' => 'Tarjeta · bancos (111005)',
            'payable' => 'Crédito · cuenta por pagar (220505), se paga con abonos',
        ];
        $order['payment_mode_label'] = $payModeLabels[$payMode] ?? $payMode;
        if (in_array($payMode, ['cash', 'transfer', 'card'], true)
            && ($order['status'] ?? '') === 'received'
            && (float)($order['total'] ?? 0) > 0.009) {
            $push(
                $events,
                (string)($order['received_at'] ?? $order['warehouse_date'] ?? $createdAt),
                'payment',
                'Pago de contado al recibir',
                trim(
                    $payModeLabels[$payMode]
                    . (!empty($order['accounting_entry_number'])
                        ? ' · Asiento ' . $order['accounting_entry_number']
                        : (!empty($order['accounting_entry_id']) ? ' · Asiento #' . $order['accounting_entry_id'] : ''))
                ),
                (float)($order['total'] ?? 0),
                ['payment_mode' => $payMode]
            );
        }

        try {
            $stock = $this->query(
                "SELECT movement_date, created_at, type, quantity, notes, reference_type, reference_id
                 FROM stock_movements
                 WHERE (reference_type = 'purchase_order' AND reference_id = ?)
                    OR (reference_type = 'purchase_credit_note' AND reference_id IN (
                        SELECT id FROM purchase_credit_notes WHERE purchase_order_id = ?
                    ))
                    OR (reference_type = 'purchase_cancel' AND reference_id = ?)
                 ORDER BY COALESCE(movement_date, created_at), id",
                [$orderId, $orderId, $orderId]
            )->fetchAll();
            foreach ($stock as $sm) {
                $refType = (string)($sm['reference_type'] ?? '');
                $label = match ($refType) {
                    'purchase_credit_note' => 'Salida por nota de crédito / devolución',
                    'purchase_cancel' => 'Reverso inventario (anulación)',
                    default => ((string)($sm['type'] ?? '') === 'out'
                        ? 'Salida de inventario'
                        : 'Entrada de inventario'),
                };
                $push(
                    $events,
                    (string)($sm['movement_date'] ?? $sm['created_at']),
                    'stock',
                    $label,
                    trim(
                        'Cantidad: ' . (int)($sm['quantity'] ?? 0) . ' ud.'
                        . (trim((string)($sm['notes'] ?? '')) !== '' ? ' · ' . trim((string)$sm['notes']) : '')
                    )
                );
            }
        } catch (\Throwable $e) {
        }

        foreach ($this->orderPayments($orderId) as $p) {
            $payStatus = (string)($p['status'] ?? 'applied');
            // Medio elegido al pagar y, si fue efectivo, su origen (cajón u otro fondo).
            $payDetailParts = [(string)($p['payment_method_label'] ?? ($p['payment_method'] ?? ''))];
            if (($p['cash_origin'] ?? '') !== '') {
                $originTxt = (string)($p['cash_origin_label'] ?? '');
                if (($p['cash_origin'] ?? '') === 'other' && trim((string)($p['cash_source_note'] ?? '')) !== '') {
                    $originTxt .= ': ' . trim((string)$p['cash_source_note']);
                }
                if ($originTxt !== '') {
                    $payDetailParts[] = $originTxt;
                }
            }
            if (trim((string)($p['reference'] ?? '')) !== '') {
                $payDetailParts[] = 'Ref. ' . trim((string)$p['reference']);
            }
            if ($payStatus === 'cancelled') {
                $payDetailParts[] = (string)($p['notes'] ?? '');
                $push(
                    $events,
                    (string)($p['updated_at'] ?? $p['payment_date'] ?? $p['created_at'] ?? ''),
                    'payment_void',
                    'Abono cancelado (anulación)',
                    trim(implode(' · ', array_filter($payDetailParts, static fn($s) => trim((string)$s) !== ''))),
                    (float)($p['amount'] ?? 0),
                    ['payment_id' => (int)($p['id'] ?? 0)]
                );
                continue;
            }
            if ($payStatus !== '' && $payStatus !== 'applied') {
                continue;
            }
            if (!empty($p['accounting_entry_number'])) {
                $payDetailParts[] = 'Asiento ' . $p['accounting_entry_number'];
            }
            $payDiscount = round((float)($p['discount_amount'] ?? 0), 2);
            if ($payDiscount > 0.009) {
                $payDetailParts[] = 'Dcto. pronto pago −$' . number_format($payDiscount, 2, ',', '.');
            }
            $push(
                $events,
                (string)($p['payment_date'] ?? $p['created_at'] ?? ''),
                'payment',
                'Abono a proveedor',
                trim(implode(' · ', array_filter($payDetailParts, static fn($s) => trim((string)$s) !== ''))),
                (float)($p['amount'] ?? 0),
                ['payment_id' => (int)($p['id'] ?? 0)]
            );
        }

        foreach ($this->orderCreditNotes($orderId) as $n) {
            if (($n['status'] ?? 'posted') !== 'posted') {
                continue;
            }
            $affects = (int)($n['affects_stock'] ?? 1) === 1;
            $reason = (string)($n['reason'] ?? '');
            $isVoidNc = str_contains(mb_strtoupper($reason), 'ANULACIÓN OC');
            $push(
                $events,
                (string)($n['credit_date'] ?? $n['created_at'] ?? ''),
                'credit_note',
                $isVoidNc
                    ? 'NC por anulación de factura'
                    : ($affects ? 'Nota de crédito / devolución' : 'Nota de crédito (ajuste comercial)'),
                trim(
                    ($n['credit_number'] ?? '')
                    . ' · ' . $reason
                    . ($affects ? ' · afecta inventario' : ' · sin stock')
                    . (!empty($n['accounting_entry_number'])
                        ? (' · asiento ' . $n['accounting_entry_number'])
                        : (!empty($n['accounting_entry_id']) ? (' · asiento #' . $n['accounting_entry_id']) : ' · asiento pendiente'))
                ),
                (float)($n['total'] ?? 0),
                ['credit_note_id' => (int)($n['id'] ?? 0)]
            );
        }

        if (($order['status'] ?? '') === 'cancelled') {
            $notes = (string)($order['notes'] ?? '');
            $isPostReceive = str_contains($notes, 'ANULADA POST-RECEPCIÓN') || str_contains($notes, '[[NC_FULL');
            $voidReason = '';
            if (preg_match('/ANULADA POST-RECEPCIÓN:\s*(.+)$/u', $notes, $m)) {
                $voidReason = trim($m[1]);
                if (preg_match('/ANULACIÓN OC:\s*(.+)$/u', $voidReason, $m2)) {
                    $voidReason = trim($m2[1]);
                }
            }
            $voidAt = (string)($order['voided_at'] ?? $order['updated_at'] ?? $createdAt);
            $push(
                $events,
                $voidAt,
                'cancelled',
                $isPostReceive ? 'Factura anulada' : 'Orden cancelada',
                $isPostReceive
                    ? trim(
                        'Queda en historial como anulada (no activa).'
                        . ($voidReason !== '' ? ' Motivo: ' . $voidReason : '')
                    )
                    : ''
            );
        }

        $orderReviewNotes = trim((string)($order['review_notes'] ?? ''));
        if ($orderReviewNotes !== '' && empty($loggedTypes['invoice_observation'])) {
            $push(
                $events,
                (string)($order['received_at'] ?? $order['warehouse_date'] ?? $createdAt),
                'invoice_observation',
                'Nota / observación',
                $orderReviewNotes
            );
        }

        // Deduplicar eventos casi idénticos (mismo tipo + segundo).
        $seen = [];
        $deduped = [];
        foreach ($events as $ev) {
            $key = ($ev['type'] ?? '') . '|' . substr((string)($ev['at'] ?? ''), 0, 19) . '|' . ($ev['label'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $ev;
        }
        $events = $deduped;

        // Más reciente primero (trazabilidad Contabilidad / Compras).
        usort($events, static function ($a, $b) {
            $cmp = strcmp((string)$b['at'], (string)$a['at']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($b['type'] ?? ''), (string)($a['type'] ?? ''));
        });

        $order['payments'] = $this->orderPayments($orderId);
        $order['credit_notes'] = $this->orderCreditNotes($orderId);
        $order['balance'] = $this->orderBalance($orderId);

        return ['order' => $order, 'events' => $events];
    }
}
