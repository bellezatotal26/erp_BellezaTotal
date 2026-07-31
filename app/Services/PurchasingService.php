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
        ?string $invoiceNumber = null
    ): int {
        if (!in_array($status, ['draft', 'ordered'], true)) {
            $status = 'ordered';
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('Agregue al menos un producto a la orden');
        }

        // Descuento aliado del proveedor (si no se envió otro)
        if ($earlyPaymentDiscountPct <= 0 && $supplierId) {
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
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $cost = round((float)($item['unit_cost'] ?? 0), 2);
            $rate = round(max(0, (float)($item['tax_rate'] ?? 0)), 2);
            if ($qty <= 0 || $cost < 0) {
                continue;
            }
            $lineSub = round($qty * $cost, 2);
            $lineTax = round($lineSub * ($rate / 100), 2);
            $productId = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            $name = trim((string)($item['product_name'] ?? ''));
            if ($name === '' && $productId) {
                $name = (string)$this->query("SELECT name FROM products WHERE id = ?", [$productId])->fetchColumn();
            }
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => $rate,
                'tax_amount' => $lineTax,
                'subtotal' => $lineSub,
                'line_total' => round($lineSub + $lineTax, 2),
            ];
            $subtotal += $lineSub;
            $tax += $lineTax;
        }
        if (empty($normalized)) {
            throw new \InvalidArgumentException('Los ítems de la orden no son válidos');
        }

        $gross = round($subtotal + $tax, 2);
        $earlyPaymentDiscountPct = max(0, min(100, round($earlyPaymentDiscountPct, 2)));
        $discountAmt = round($gross * ($earlyPaymentDiscountPct / 100), 2);
        $total = round($gross - $discountAmt, 2);

        $orderNumber = $this->nextOrderNumber($orderDate);
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
        $orderId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare(
            "INSERT INTO purchase_order_items
                (purchase_order_id, product_id, product_name, quantity, unit_cost,
                 tax_rate, tax_amount, subtotal, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($normalized as $row) {
            $stmt->execute([
                $orderId,
                $row['product_id'],
                $row['product_name'],
                $row['quantity'],
                $row['unit_cost'],
                $row['tax_rate'],
                $row['tax_amount'],
                $row['subtotal'],
                $row['line_total'],
            ]);
        }

        return $orderId;
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
            "SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id",
            [$id]
        )->fetchAll();
        return $order;
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

    public function listOrders(int $limit = 50, int $offset = 0, string $status = ''): array
    {
        $where = '';
        $params = [];
        if ($status !== '' && in_array($status, ['draft', 'ordered', 'received', 'cancelled'], true)) {
            $where = 'WHERE po.status = ?';
            $params[] = $status;
        }
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return $this->query(
            "SELECT po.*, s.name supplier_name
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             {$where}
             ORDER BY po.order_date DESC, po.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    public function countOrders(string $status = ''): int
    {
        if ($status !== '' && in_array($status, ['draft', 'ordered', 'received', 'cancelled'], true)) {
            return (int)$this->query(
                "SELECT COUNT(*) FROM purchase_orders WHERE status = ?",
                [$status]
            )->fetchColumn();
        }
        return (int)$this->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
    }

    /**
     * Recibe mercancía: inventaría, actualiza costos y deja lista para asiento.
     */
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

        $this->db->beginTransaction();
        try {
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

                $movementId = $stock->increase(
                    $productId,
                    $qty,
                    'purchase_order',
                    $orderId,
                    'OC ' . $order['order_number'] . ($invoiceNumber !== '' ? (' / Factura ' . $invoiceNumber) : ''),
                    'in',
                    (float)$item['unit_cost'],
                    $order['supplier_id'] ? (int)$order['supplier_id'] : null,
                    $paymentMode,
                    true,
                    $warehouseDate
                );
                if ($movementId > 0) {
                    $movementIds[] = $movementId;
                }
                $this->query(
                    "UPDATE purchase_order_items SET received_qty = quantity WHERE id = ?",
                    [(int)$item['id']]
                );
            }

            $this->query(
                "UPDATE purchase_orders
                 SET status = 'received',
                     warehouse_date = ?,
                     invoice_number = ?,
                     payment_mode = ?,
                     notes = CASE WHEN ? = '' THEN notes ELSE CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), ?) END,
                     received_at = NOW(),
                     received_by = ?
                 WHERE id = ?",
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

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'order_id' => $orderId,
            'movement_ids' => $movementIds,
        ];
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
}
