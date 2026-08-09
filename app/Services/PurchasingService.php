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
        bool $autoApplyAllyDiscount = true
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
                'lot_code' => substr(trim((string)($item['lot_code'] ?? '')), 0, 60) ?: null,
                'expiry_date' => $this->validExpiry($item['expiry_date'] ?? null),
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
                 tax_rate, tax_amount, subtotal, line_total, lot_code, expiry_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
                $row['lot_code'],
                $row['expiry_date'],
            ]);
        }

        return $orderId;
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
                    COALESCE((
                        SELECT SUM(ci.quantity)
                        FROM purchase_credit_note_items ci
                        JOIN purchase_credit_notes cn ON cn.id = ci.credit_note_id
                        WHERE ci.purchase_order_item_id = poi.id AND cn.status = 'posted'
                    ), 0) credited_qty
             FROM purchase_order_items poi
             WHERE poi.purchase_order_id = ?
             ORDER BY poi.id",
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

    /**
     * @param string $dueFilter overdue|due_today|due_soon|no_due|''
     * @return list<array<string,mixed>>
     */
    public function listOrders(int $limit = 50, int $offset = 0, string $status = '', string $dueFilter = ''): array
    {
        [$whereSql, $params] = $this->orderFilters($status, $dueFilter);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $orderBy = $dueFilter !== ''
            ? 'ORDER BY po.due_date IS NULL, po.due_date ASC, po.id DESC'
            : 'ORDER BY po.order_date DESC, po.id DESC';
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

    public function countOrders(string $status = '', string $dueFilter = ''): int
    {
        [$whereSql, $params] = $this->orderFilters($status, $dueFilter);
        return (int)$this->query(
            "SELECT COUNT(*) FROM purchase_orders po {$whereSql}",
            $params
        )->fetchColumn();
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function orderFilters(string $status, string $dueFilter): array
    {
        $where = ['1=1'];
        $params = [];
        if ($status !== '' && in_array($status, ['draft', 'ordered', 'received', 'cancelled'], true)) {
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
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function balancePendingSql(): string
    {
        return "po.total - COALESCE(po.paid_total, 0) - COALESCE(po.credit_total, 0) > 0.009";
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
        $debtAlerts = [];

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

                $debtCover = null;
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

        return [
            'order_id' => $orderId,
            'movement_ids' => $movementIds,
            'debt_alerts' => $debtAlerts,
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
        $payment = $this->query(
            "SELECT sp.id, sp.amount, sp.payment_method, sp.status,
                    po.order_number, s.name supplier_name
             FROM supplier_payments sp
             LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
             LEFT JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.id = ?",
            [$paymentId]
        )->fetch();
        if (!$payment || ($payment['status'] ?? 'applied') === 'cancelled') {
            return '';
        }
        if (!PaymentMethodCatalog::affectsCash((string)($payment['payment_method'] ?? ''))) {
            return '';
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
            "SELECT cn.id, cn.total, cn.credit_number, cn.status,
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
        $total = round((float)$note['total'], 2);
        if ($total <= 0.009) {
            return '';
        }
        $label = 'Nota crédito compra ' . ($note['credit_number'] ?? ('#' . $creditNoteId))
            . (!empty($note['order_number']) ? ' / OC ' . $note['order_number'] : '');
        return $this->postCashMovement($total, $label, 'income', 'purchase_credit_note', $creditNoteId);
    }

    /**
     * @return string Nota para el usuario
     */
    private function postCashMovement(
        float $amount,
        string $description,
        string $type,
        string $referenceType,
        int $referenceId
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
            $cash->registerMovement($amount, $description, $type, $referenceType, $referenceId);
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
     * @return array{payment_id:int,paid_total:float,balance:float,payment_status:string}
     */
    public function registerPayment(
        int $orderId,
        float $amount,
        string $paymentDate,
        string $paymentMethod = 'cash',
        string $reference = '',
        string $notes = ''
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

        $balance = $this->orderBalance($orderId);
        if ($balance <= 0.009) {
            throw new \RuntimeException('Esta orden ya está pagada en su totalidad');
        }
        if ($amount - $balance > 0.009) {
            throw new \InvalidArgumentException(
                'El abono supera el saldo pendiente (' . number_format($balance, 2, ',', '.') . ')'
            );
        }

        $stock = new StockService($this->db);
        $paymentDate = $stock->normalizeDate($paymentDate);

        $this->db->beginTransaction();
        try {
            $this->query(
                "INSERT INTO supplier_payments
                    (supplier_id, purchase_order_id, payment_date, amount,
                     payment_method, reference, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int)$order['supplier_id'],
                    $orderId,
                    $paymentDate,
                    $amount,
                    $paymentMethod !== '' ? $paymentMethod : 'cash',
                    $reference !== '' ? $reference : null,
                    $notes !== '' ? $notes : null,
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
            $paymentId = (int)$this->db->lastInsertId();
            $status = $this->refreshPaymentStatus($orderId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'payment_id' => $paymentId,
            'paid_total' => $status['paid_total'],
            'balance' => $status['balance'],
            'payment_status' => $status['payment_status'],
        ];
    }

    /** Saldo pendiente de la orden: total − abonos − notas de crédito. */
    public function orderBalance(int $orderId): float
    {
        $row = $this->query(
            "SELECT po.total,
                    COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) paid,
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
        return round((float)$row['total'] - (float)$row['paid'] - (float)$row['credited'], 2);
    }

    /**
     * Recalcula paid_total, credit_total y payment_status.
     *
     * @return array{paid_total:float,credit_total:float,balance:float,payment_status:string}
     */
    public function refreshPaymentStatus(int $orderId): array
    {
        $row = $this->query(
            "SELECT po.total, po.payment_mode,
                    COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                    ), 0) paid,
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
        $paid = round((float)$row['paid'], 2);
        $credited = round((float)$row['credited'], 2);

        // Los modos distintos de 'payable' se pagaron de contado al recibir.
        if ((string)($row['payment_mode'] ?? 'payable') !== 'payable') {
            $paid = $total;
        }

        $balance = round($total - $paid - $credited, 2);
        $status = match (true) {
            $balance <= 0.009 => 'paid',
            ($paid + $credited) > 0.009 => 'partial',
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
            'balance' => $balance,
            'payment_status' => $status,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function orderPayments(int $orderId): array
    {
        try {
            return $this->query(
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
            $lineTax = round($lineSub * ($rate / 100), 2);
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
        $total = round($subtotal + $tax, 2);
        $creditNumber = $this->nextCreditNumber($creditDate);

        $this->db->beginTransaction();
        try {
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
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'credit_note_id' => $creditNoteId,
            'credit_number' => $creditNumber,
            'total' => $total,
            'balance' => $status['balance'],
            'payment_status' => $status['payment_status'],
        ];
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
                        po.total, po.payment_status, s.name supplier_name, po.supplier_id,
                        COALESCE((
                            SELECT SUM(sp.amount) FROM supplier_payments sp
                            WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                        ), 0) paid_amount,
                        COALESCE((
                            SELECT SUM(cn.total) FROM purchase_credit_notes cn
                            WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                        ), 0) credit_amount,
                        DATEDIFF(CURDATE(), po.due_date) days_overdue
                 FROM purchase_orders po
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 WHERE po.status = 'received'
                   AND COALESCE(po.payment_mode, 'payable') = 'payable'
                   AND po.total - COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
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
                "SELECT COALESCE(SUM(po.total - COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
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
}
