<?php

namespace SoftNova\Services;

/**
 * Inventario / kardex valorizado para BD tenant.
 * Cada movimiento guarda costo, saldo y vínculo opcional al asiento contable.
 */
class StockService
{
    private \PDO $db;
    private ?LotService $lots = null;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    /** Servicio de lotes creado bajo demanda: la mayoría de productos no los usan. */
    public function lots(): LotService
    {
        if ($this->lots === null) {
            $this->lots = new LotService($this->db);
        }
        return $this->lots;
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

        // Columnas de sobreventa en líneas de venta.
        foreach ([
            'stock_oversell_reason' => "ALTER TABLE sale_items ADD COLUMN stock_oversell_reason VARCHAR(40) NULL AFTER subtotal",
            'stock_oversell_note' => "ALTER TABLE sale_items ADD COLUMN stock_oversell_note VARCHAR(180) NULL AFTER stock_oversell_reason",
        ] as $name => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM sale_items LIKE " . $this->db->quote($name))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Notas internas del producto (editables después de crear).
        try {
            $exists = $this->db->query("SHOW COLUMNS FROM products LIKE 'notes'")->fetch();
            if (!$exists) {
                $this->db->exec("ALTER TABLE products ADD COLUMN notes TEXT NULL AFTER description");
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Deuda de inventario por ventas sin stock / préstamos.
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS product_stock_debts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    sale_id INT UNSIGNED NULL,
                    sale_item_id INT UNSIGNED NULL,
                    qty_owed INT NOT NULL,
                    qty_remaining INT NOT NULL,
                    reason VARCHAR(40) NOT NULL,
                    note VARCHAR(180) NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'open',
                    source VARCHAR(20) NOT NULL DEFAULT 'sale',
                    movement_id INT UNSIGNED NULL,
                    borrower VARCHAR(120) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    INDEX idx_psd_product_status (product_id, status),
                    INDEX idx_psd_sale (sale_id),
                    INDEX idx_psd_source_status (source, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            // ignore
        }
        foreach ([
            'source' => "ALTER TABLE product_stock_debts ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'sale' AFTER status",
            'movement_id' => "ALTER TABLE product_stock_debts ADD COLUMN movement_id INT UNSIGNED NULL AFTER source",
            'borrower' => "ALTER TABLE product_stock_debts ADD COLUMN borrower VARCHAR(120) NULL AFTER movement_id",
        ] as $col => $sql) {
            try {
                $exists = $this->db->query("SHOW COLUMNS FROM product_stock_debts LIKE " . $this->db->quote($col))->fetch();
                if (!$exists) {
                    $this->db->exec($sql);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $done = true;
    }

    public function normalizeDate(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return date('Y-m-d');
        }
        // Acepta datetime completo y lo reduce a fecha de bodega.
        $date = str_replace('T', ' ', $date);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
            $only = $m[1];
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $only);
            if ($dt && $dt->format('Y-m-d') === $only) {
                return $only;
            }
        }
        foreach (['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $date);
            if ($dt instanceof \DateTimeImmutable) {
                $err = \DateTimeImmutable::getLastErrors();
                if (is_array($err) && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0)) {
                    continue;
                }
                return $dt->format('Y-m-d');
            }
        }
        throw new \InvalidArgumentException('Fecha de ingreso a bodega inválida');
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
     * Actualiza el precio de venta del producto y deja traza en kardex.
     * Usado cuando se vende a un precio distinto al del inventario.
     */
    public function recordSalePriceChange(
        int $productId,
        float $oldPrice,
        float $newPrice,
        ?int $saleId = null,
        string $invoiceNumber = ''
    ): void {
        $oldPrice = round(max(0, $oldPrice), 2);
        $newPrice = round(max(0, $newPrice), 2);
        if (abs($oldPrice - $newPrice) < 0.005) {
            return;
        }

        $product = $this->query(
            "SELECT id, stock FROM products WHERE id = ?",
            [$productId]
        )->fetch();
        if (!$product) {
            return;
        }

        $this->query(
            "UPDATE products SET sale_price = ? WHERE id = ?",
            [$newPrice, $productId]
        );

        $note = 'Cambio precio venta: '
            . number_format($oldPrice, 2, '.', '')
            . ' → '
            . number_format($newPrice, 2, '.', '');
        if ($invoiceNumber !== '') {
            $note .= ' · Venta ' . $invoiceNumber;
        }

        $this->addMovement(
            $productId,
            'adjustment',
            0,
            'price_change',
            $saleId,
            $note,
            null,
            $newPrice,
            (int)$product['stock']
        );
    }

    /**
     * Descontar stock de forma atómica.
     * Si no hay suficiente y se pasa $oversell con motivo válido, permite saldo negativo
     * y registra deuda en product_stock_debts.
     *
     * @param array{reason?:string,note?:string,sale_item_id?:int,skip_debt?:bool}|null $oversell
     * @return int ID movimiento
     */
    public function decrease(
        int $productId,
        int $quantity,
        string $referenceType,
        ?int $referenceId,
        string $notes = '',
        ?float $unitCost = null,
        ?array $oversell = null,
        ?string $paymentMode = null
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
        if (($product['status'] ?? '') !== 'active') {
            throw new \RuntimeException('Producto inactivo: ' . ($product['name'] ?? ('#' . $productId)));
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
                (int)$product['stock'],
                null,
                $paymentMode
            );
        }

        $available = (int)$product['stock'];
        $shortfall = max(0, $quantity - max(0, $available));
        $allowOversell = false;
        $oversellReason = '';
        $oversellNote = '';

        if ($shortfall > 0) {
            try {
                $parsed = StockOversell::parse(
                    $oversell['reason'] ?? null,
                    $oversell['note'] ?? null
                );
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException($e->getMessage());
            }
            if ($parsed === null) {
                throw new \RuntimeException(
                    'Stock insuficiente para: ' . ($product['name'] ?? ('#' . $productId))
                    . ' (disponible: ' . $available . ', solicitado: ' . $quantity . '). '
                    . 'Indique el motivo para vender sin stock.'
                );
            }
            $allowOversell = true;
            $oversellReason = $parsed['reason'];
            $oversellNote = $parsed['note'];
            $reasonLabel = StockOversell::label($oversellReason);
            $extra = ' · Sin stock (' . $reasonLabel . ')'
                . ($oversellNote !== '' ? (': ' . $oversellNote) : '');
            $notes = $notes !== '' ? ($notes . $extra) : ('Salida sin stock' . $extra);
        }

        if ($allowOversell) {
            $stmt = $this->query(
                "UPDATE products SET stock = stock - ?, last_sale_date = NOW()
                 WHERE id = ? AND status = 'active'",
                [$quantity, $productId]
            );
        } else {
            $stmt = $this->query(
                "UPDATE products SET stock = stock - ?, last_sale_date = NOW()
                 WHERE id = ? AND stock >= ? AND status = 'active'",
                [$quantity, $productId, $quantity]
            );
        }

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException(
                'Stock insuficiente para: ' . ($product['name'] ?? ('#' . $productId))
                . ' (disponible: ' . $available . ', solicitado: ' . $quantity . ')'
            );
        }

        $after = $available - $quantity;
        $movementId = $this->addMovement(
            $productId,
            'out',
            $quantity,
            $referenceType,
            $referenceId,
            $notes,
            null,
            $cost,
            $after,
            null,
            $paymentMode
        );

        if ($allowOversell && $shortfall > 0 && empty($oversell['skip_debt'])) {
            $this->openStockDebt(
                $productId,
                $shortfall,
                $oversellReason,
                $oversellNote,
                $referenceType === 'sale' ? $referenceId : null,
                isset($oversell['sale_item_id']) ? (int)$oversell['sale_item_id'] : null,
                'sale',
                $movementId
            );
        }

        $this->consumeLots($productId, $quantity, $movementId);

        return $movementId;
    }

    /**
     * Descarga FEFO del submayor de lotes. Nunca interrumpe la salida de stock:
     * si los lotes están descuadrados se registra la traza parcial y se avisa por log.
     */
    private function consumeLots(int $productId, int $quantity, int $movementId): void
    {
        if ($movementId <= 0 || $quantity <= 0) {
            return;
        }
        try {
            $lots = $this->lots();
            if (!$lots->tracksLots($productId)) {
                return;
            }
            $result = $lots->consumeFefo($productId, $quantity, $movementId);
            if ($result['pending'] > 0) {
                error_log(sprintf(
                    'Lotes insuficientes en producto %d: faltan %d unidades por trazar (movimiento %d)',
                    $productId,
                    $result['pending'],
                    $movementId
                ));
            }
        } catch (\Throwable $e) {
            error_log('Consumo FEFO producto ' . $productId . ': ' . $e->getMessage());
        }
    }

    /**
     * Incrementar stock (compras, devoluciones, ajustes positivos).
     * Tras la entrada, cubre deudas abiertas (sobreventas) en FIFO y deja resumen en $debtCover.
     *
     * @param array{lot_code?:string,expiry_date?:?string,alert_days?:int,supplier_id?:?int,purchase_order_id?:?int}|null $lot
     * @param array{covered:int,remaining:int,details:list<array<string,mixed>}|null $debtCoverOut
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
        ?string $movementDate = null,
        ?array $lot = null,
        ?array &$debtCoverOut = null,
        bool $coverSaleDebts = true
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
        $movementId = $this->addMovement(
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

        $this->registerLotEntry(
            $productId,
            $quantity,
            $cost,
            $movementId,
            $lot,
            $supplierId,
            $movementDate,
            $referenceType,
            $referenceId
        );

        // Devoluciones de venta cancelada / devolución de préstamo: no cubren sobreventas.
        if ($coverSaleDebts && $referenceType !== 'return' && $referenceType !== 'loan_return') {
            $debtCoverOut = $this->coverOpenDebts($productId, $quantity);
            if (($debtCoverOut['covered'] ?? 0) > 0) {
                $coverNote = $this->formatDebtCoverNote($debtCoverOut);
                if ($coverNote !== '') {
                    try {
                        $this->query(
                            "UPDATE stock_movements SET notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ?",
                            [' · ' . $coverNote, $movementId]
                        );
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }
        } else {
            $debtCoverOut = ['covered' => 0, 'remaining' => 0, 'details' => []];
        }

        return $movementId;
    }

    /**
     * Registra unidades vendidas sin stock o prestadas (source=loan).
     */
    public function openStockDebt(
        int $productId,
        int $qty,
        string $reason,
        string $note = '',
        ?int $saleId = null,
        ?int $saleItemId = null,
        string $source = 'sale',
        ?int $movementId = null,
        string $borrower = ''
    ): void {
        if ($qty <= 0) {
            return;
        }
        $source = strtolower(trim($source));
        if (!in_array($source, ['sale', 'loan'], true)) {
            $source = 'sale';
        }
        try {
            $this->query(
                "INSERT INTO product_stock_debts
                    (product_id, sale_id, sale_item_id, qty_owed, qty_remaining, reason, note, status, source, movement_id, borrower)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?)",
                [
                    $productId,
                    $saleId,
                    $saleItemId,
                    $qty,
                    $qty,
                    $reason,
                    $note !== '' ? $note : null,
                    $source,
                    $movementId,
                    $borrower !== '' ? $borrower : null,
                ]
            );
        } catch (\Throwable $e) {
            // Préstamos NO pueden caer al fallback sin `source`: quedarían como venta y se
            // cubrirían al comprar / desaparecerían del módulo Préstamos.
            if ($source === ProductLoan::SOURCE) {
                throw new \RuntimeException(
                    'No se pudo registrar la deuda del préstamo. Actualice el esquema de inventario e intente de nuevo.',
                    0,
                    $e
                );
            }
            // Schema viejo sin columnas nuevas (solo ventas)
            try {
                $this->query(
                    "INSERT INTO product_stock_debts
                        (product_id, sale_id, sale_item_id, qty_owed, qty_remaining, reason, note, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'open')",
                    [
                        $productId,
                        $saleId,
                        $saleItemId,
                        $qty,
                        $qty,
                        $reason,
                        $note !== '' ? $note : null,
                    ]
                );
            } catch (\Throwable $e2) {
                error_log('openStockDebt: ' . $e2->getMessage());
                throw new \RuntimeException('No se pudo registrar la deuda de stock', 0, $e2);
            }
        }
    }

    /**
     * Aplica unidades entrantes a deudas abiertas (FIFO).
     * No vuelve a descontar products.stock: el saldo ya refleja la sobreventa previa.
     *
     * @return array{covered:int,remaining:int,details:list<array<string,mixed>>}
     */
    public function coverOpenDebts(int $productId, int $qtyIn): array
    {
        $result = ['covered' => 0, 'remaining' => 0, 'details' => []];
        if ($productId <= 0 || $qtyIn <= 0) {
            return $result;
        }

        try {
            // Solo deudas de sobreventa (venta): los préstamos se cierran a mano.
            $debts = $this->query(
                "SELECT d.*, s.invoice_number
                 FROM product_stock_debts d
                 LEFT JOIN sales s ON s.id = d.sale_id
                 WHERE d.product_id = ? AND d.status IN ('open','partial') AND d.qty_remaining > 0
                   AND COALESCE(d.source, 'sale') = 'sale'
                 ORDER BY d.id ASC
                 FOR UPDATE",
                [$productId]
            )->fetchAll();
        } catch (\Throwable $e) {
            // Sin FOR UPDATE / tabla ausente
            try {
                $debts = $this->query(
                    "SELECT d.*, s.invoice_number
                     FROM product_stock_debts d
                     LEFT JOIN sales s ON s.id = d.sale_id
                     WHERE d.product_id = ? AND d.status IN ('open','partial') AND d.qty_remaining > 0
                       AND COALESCE(d.source, 'sale') = 'sale'
                     ORDER BY d.id ASC",
                    [$productId]
                )->fetchAll();
            } catch (\Throwable $e2) {
                return $result;
            }
        }

        $left = $qtyIn;
        foreach ($debts as $debt) {
            if ($left <= 0) {
                break;
            }
            $owed = (int)$debt['qty_remaining'];
            $take = min($left, $owed);
            $newRemaining = $owed - $take;
            $status = $newRemaining <= 0 ? 'closed' : 'partial';
            try {
                $this->query(
                    "UPDATE product_stock_debts
                     SET qty_remaining = ?, status = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$newRemaining, $status, (int)$debt['id']]
                );
            } catch (\Throwable $e) {
                continue;
            }
            $left -= $take;
            $result['covered'] += $take;
            $result['details'][] = [
                'debt_id' => (int)$debt['id'],
                'sale_id' => $debt['sale_id'] ? (int)$debt['sale_id'] : null,
                'invoice_number' => (string)($debt['invoice_number'] ?? ''),
                'reason' => (string)$debt['reason'],
                'reason_label' => ProductLoan::label((string)$debt['reason']),
                'note' => (string)($debt['note'] ?? ''),
                'covered' => $take,
                'remaining' => $newRemaining,
            ];
        }

        try {
            $result['remaining'] = (int)$this->query(
                "SELECT COALESCE(SUM(qty_remaining),0) FROM product_stock_debts
                 WHERE product_id = ? AND status IN ('open','partial')
                   AND COALESCE(source, 'sale') = 'sale'",
                [$productId]
            )->fetchColumn();
        } catch (\Throwable $e) {
            $result['remaining'] = 0;
        }

        return $result;
    }

    /** Anula deudas de una venta cancelada (antes de devolver stock). */
    public function cancelDebtsForSale(int $saleId): void
    {
        if ($saleId <= 0) {
            return;
        }
        try {
            $this->query(
                "UPDATE product_stock_debts
                 SET status = 'cancelled', qty_remaining = 0, updated_at = NOW()
                 WHERE sale_id = ? AND status IN ('open','partial')",
                [$saleId]
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function openDebtsForProduct(int $productId, int $limit = 20): array
    {
        if ($productId <= 0) {
            return [];
        }
        try {
            $rows = $this->query(
                "SELECT d.*, s.invoice_number
                 FROM product_stock_debts d
                 LEFT JOIN sales s ON s.id = d.sale_id
                 WHERE d.product_id = ? AND d.status IN ('open','partial') AND d.qty_remaining > 0
                 ORDER BY d.id ASC
                 LIMIT " . max(1, min(50, $limit)),
                [$productId]
            )->fetchAll();
            return array_map([$this, 'enrichDebtRow'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Salida por préstamo (no es venta). Descuenta stock (puede quedar negativo) y
     * deja deuda source=loan hasta devolución manual.
     *
     * @return array{movement_id:int,debt_id:int,stock_after:int}
     */
    public function registerLoan(
        int $productId,
        int $quantity,
        string $reason,
        string $note = '',
        string $borrower = ''
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad del préstamo debe ser mayor a 0');
        }
        $parsed = ProductLoan::parse($reason, $note, $borrower);
        $product = $this->query(
            "SELECT id, name, stock, product_type, status FROM products WHERE id = ?",
            [$productId]
        )->fetch();
        if (!$product) {
            throw new \RuntimeException('Producto no encontrado');
        }
        if (($product['product_type'] ?? 'product') === 'service') {
            throw new \RuntimeException('No se pueden prestar servicios');
        }
        if (($product['status'] ?? '') !== 'active') {
            throw new \RuntimeException('Producto inactivo');
        }

        $available = (int)$product['stock'];
        $shortfall = max(0, $quantity - max(0, $available));
        $label = ProductLoan::label($parsed['reason']);
        $who = $parsed['borrower'] !== '' ? $parsed['borrower'] : '—';
        $notes = 'Préstamo: ' . $label
            . ' · A: ' . $who
            . ($parsed['note'] !== '' ? (' — ' . $parsed['note']) : '');

        $oversell = null;
        if ($shortfall > 0) {
            // Permite negativo; la deuda del préstamo se registra aparte (qty completa).
            $oversell = [
                'reason' => StockOversell::REASON_PRESTAMO,
                'note' => $parsed['note'] !== '' ? $parsed['note'] : $label,
                'skip_debt' => true,
            ];
        }

        // Calentar lotes/esquema ANTES de la TX (DDL hace COMMIT implícito en MySQL).
        $this->lots();

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $movementId = $this->decrease(
                $productId,
                $quantity,
                'loan',
                null,
                $notes,
                null,
                $oversell
            );

            $this->openStockDebt(
                $productId,
                $quantity,
                $parsed['reason'],
                $parsed['note'],
                null,
                null,
                ProductLoan::SOURCE,
                $movementId > 0 ? $movementId : null,
                $parsed['borrower']
            );

            $debtId = (int)$this->db->lastInsertId();
            if ($debtId <= 0) {
                throw new \RuntimeException('No se pudo obtener el ID de la deuda del préstamo');
            }

            $stockAfter = (int)$this->query(
                "SELECT stock FROM products WHERE id = ?",
                [$productId]
            )->fetchColumn();

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'movement_id' => $movementId,
                'debt_id' => $debtId,
                'stock_after' => $stockAfter,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Devuelve unidades de un préstamo abierto (reposición manual).
     * Si $returnProductId es otro producto, el stock entra a ese ítem y se cierra
     * el préstamo del original (sustitución).
     *
     * @return array{returned:int,stock_after:int,remaining:int,movement_id:int,return_product_id:int,substituted:bool}
     */
    public function returnLoan(int $debtId, int $quantity, ?int $returnProductId = null): array
    {
        if ($debtId <= 0 || $quantity <= 0) {
            throw new \InvalidArgumentException('Cantidad de devolución inválida');
        }

        // Calentar lotes/esquema ANTES de la TX (DDL hace COMMIT implícito en MySQL).
        $this->lots();

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            try {
                $debt = $this->query(
                    "SELECT * FROM product_stock_debts WHERE id = ? LIMIT 1 FOR UPDATE",
                    [$debtId]
                )->fetch();
            } catch (\Throwable $e) {
                $debt = $this->query(
                    "SELECT * FROM product_stock_debts WHERE id = ? LIMIT 1",
                    [$debtId]
                )->fetch();
            }
            if (!$debt) {
                throw new \RuntimeException('Préstamo no encontrado');
            }
            if (strtolower((string)($debt['source'] ?? 'sale')) !== ProductLoan::SOURCE) {
                throw new \RuntimeException('El registro no es un préstamo');
            }
            if (!in_array((string)$debt['status'], ['open', 'partial'], true) || (int)$debt['qty_remaining'] <= 0) {
                throw new \RuntimeException('Este préstamo ya está cerrado');
            }

            $originalProductId = (int)$debt['product_id'];
            $stockProductId = $originalProductId;
            $substituted = false;
            $alt = null;

            if ($returnProductId !== null && $returnProductId > 0 && $returnProductId !== $originalProductId) {
                $alt = $this->query(
                    "SELECT id, name, code, product_type, status FROM products WHERE id = ? LIMIT 1",
                    [$returnProductId]
                )->fetch();
                if (!$alt || ($alt['status'] ?? '') !== 'active') {
                    throw new \RuntimeException('El producto de devolución no existe o está inactivo');
                }
                if (($alt['product_type'] ?? 'product') === 'service') {
                    throw new \RuntimeException('No puede devolver un servicio como reposición de préstamo');
                }
                $stockProductId = (int)$alt['id'];
                $substituted = true;
            }

            $owed = (int)$debt['qty_remaining'];
            $take = min($quantity, $owed);
            $newRemaining = $owed - $take;
            $status = $newRemaining <= 0 ? 'closed' : 'partial';

            $orig = $this->query(
                "SELECT name, code FROM products WHERE id = ?",
                [$originalProductId]
            )->fetch() ?: [];
            $label = ProductLoan::label((string)$debt['reason']);
            $notes = 'Devolución préstamo: ' . $label
                . (!empty($debt['borrower']) ? (' · ' . $debt['borrower']) : '')
                . (!empty($debt['note']) ? (' — ' . $debt['note']) : '');
            if ($substituted && is_array($alt)) {
                $origLabel = trim((string)(($orig['code'] ?? '') !== '' ? ($orig['code'] . ' · ') : '') . ($orig['name'] ?? ('#' . $originalProductId)));
                $altLabel = trim((string)(($alt['code'] ?? '') !== '' ? ($alt['code'] . ' · ') : '') . ($alt['name'] ?? ('#' . $stockProductId)));
                $notes .= ' · Sustitución: prestó ' . $origLabel . ' / devolvió ' . $altLabel;
            }

            $movementId = $this->increase(
                $stockProductId,
                $take,
                'loan_return',
                $debtId,
                $notes,
                'in'
            );

            $upd = $this->query(
                "UPDATE product_stock_debts
                 SET qty_remaining = ?, status = ?, updated_at = NOW()
                 WHERE id = ? AND qty_remaining = ? AND status IN ('open','partial')",
                [$newRemaining, $status, $debtId, $owed]
            );
            if ($upd->rowCount() < 1) {
                throw new \RuntimeException('El préstamo cambió mientras se registraba la devolución. Intente de nuevo.');
            }

            $stockAfter = (int)$this->query(
                "SELECT stock FROM products WHERE id = ?",
                [$stockProductId]
            )->fetchColumn();

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'returned' => $take,
                'stock_after' => $stockAfter,
                'remaining' => $newRemaining,
                'movement_id' => $movementId,
                'return_product_id' => $stockProductId,
                'original_product_id' => $originalProductId,
                'substituted' => $substituted,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Motivos de salida operativa (pérdida / gasto operacional).
     *
     * @return array<string,string>
     */
    public static function inventoryExitReasons(): array
    {
        return [
            'garantia' => 'Garantía',
            'roto' => 'Producto roto',
            'devolucion' => 'Devolución',
            'vencimiento' => 'Vencimiento',
        ];
    }

    /**
     * Salida de inventario por pérdida operativa (gasto a inventory_loss_account).
     *
     * @return array{movement_id:int,stock_after:int,reason:string}
     */
    public function registerInventoryExit(
        int $productId,
        int $quantity,
        string $reason,
        string $note = '',
        ?string $movementDate = null
    ): array {
        $reasons = self::inventoryExitReasons();
        $reasonKey = strtolower(trim($reason));
        if (!isset($reasons[$reasonKey])) {
            throw new \InvalidArgumentException('Motivo de salida inválido');
        }
        if ($productId <= 0 || $quantity <= 0) {
            throw new \InvalidArgumentException('Producto o cantidad inválidos');
        }

        $label = $reasons[$reasonKey];
        $note = trim($note);
        $notes = 'Salida inventario: ' . $label
            . ($note !== '' ? (' — ' . $note) : '');

        // Calentar esquema de lotes fuera de TX
        $this->lots();

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            if ($ownTx && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            $movementId = $this->decrease(
                $productId,
                $quantity,
                'inventory_exit',
                null,
                $notes,
                null,
                null,
                'loss'
            );

            if ($movementDate !== null && $movementDate !== '' && $movementId > 0) {
                try {
                    $this->updateMovementDate($movementId, $movementDate);
                } catch (\Throwable $e) {
                    // fecha opcional
                }
            }

            $stockAfter = (int)$this->query(
                "SELECT stock FROM products WHERE id = ?",
                [$productId]
            )->fetchColumn();

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'movement_id' => $movementId,
                'stock_after' => $stockAfter,
                'reason' => $reasonKey,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOpenLoans(int $limit = 200): array
    {
        try {
            $rows = $this->query(
                "SELECT d.*, p.name AS product_name, p.code AS product_code, p.stock AS product_stock
                 FROM product_stock_debts d
                 JOIN products p ON p.id = d.product_id
                 WHERE COALESCE(d.source, 'sale') = 'loan'
                   AND d.status IN ('open','partial') AND d.qty_remaining > 0
                 ORDER BY d.id DESC
                 LIMIT " . max(1, min(500, $limit))
            )->fetchAll();
            return array_map([$this, 'enrichDebtRow'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Productos en stock negativo y/o con deudas abiertas (venta o préstamo) para alertas de caja.
     *
     * @return array{
     *   count:int,
     *   negative_count:int,
     *   loan_count:int,
     *   sale_debt_count:int,
     *   products:list<array<string,mixed>>,
     *   debts:list<array<string,mixed>>,
     *   summary_lines:list<string>,
     *   summary_text:string
     * }
     */
    public function negativeStockAlertReport(int $limit = 40): array
    {
        $empty = [
            'count' => 0,
            'negative_count' => 0,
            'loan_count' => 0,
            'sale_debt_count' => 0,
            'products' => [],
            'debts' => [],
            'summary_lines' => [],
            'summary_text' => '',
        ];

        // Si el stock ya se repuso por edición/ajuste sin pasar por coverOpenDebts,
        // cerrar deudas de venta huérfanas para que la alerta desaparezca.
        $this->reconcileStaleSaleDebts();

        try {
            $products = $this->query(
                "SELECT p.id, p.code, p.name, p.stock,
                        COALESCE((
                            SELECT SUM(d.qty_remaining) FROM product_stock_debts d
                            WHERE d.product_id = p.id AND d.status IN ('open','partial') AND d.qty_remaining > 0
                        ), 0) AS debt_qty
                 FROM products p
                 WHERE p.status = 'active' AND p.product_type = 'product'
                   AND (
                        p.stock < 0
                        OR EXISTS (
                            SELECT 1 FROM product_stock_debts d
                            WHERE d.product_id = p.id AND d.status IN ('open','partial') AND d.qty_remaining > 0
                        )
                   )
                 ORDER BY p.stock ASC, p.name ASC
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll();

            $debts = $this->query(
                "SELECT d.*, p.name AS product_name, p.code AS product_code, p.stock AS product_stock,
                        s.invoice_number
                 FROM product_stock_debts d
                 JOIN products p ON p.id = d.product_id
                 LEFT JOIN sales s ON s.id = d.sale_id
                 WHERE d.status IN ('open','partial') AND d.qty_remaining > 0
                 ORDER BY (p.stock < 0) DESC, d.id DESC
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll();
            $debts = array_map([$this, 'enrichDebtRow'], $debts);
        } catch (\Throwable $e) {
            return $empty;
        }

        $negativeCount = 0;
        foreach ($products as $p) {
            if ((int)$p['stock'] < 0) {
                $negativeCount++;
            }
        }
        $loanCount = 0;
        $saleDebtCount = 0;
        $lines = [];
        foreach ($debts as $d) {
            $src = (string)($d['source'] ?? 'sale');
            if ($src === ProductLoan::SOURCE) {
                $loanCount++;
            } else {
                $saleDebtCount++;
            }
            $stock = (int)($d['product_stock'] ?? 0);
            $line = trim((string)($d['product_code'] ?? '')) !== ''
                ? ((string)$d['product_code'] . ' · ')
                : '';
            $line .= (string)($d['product_name'] ?? 'Producto');
            $line .= ' · stock ' . $stock;
            $line .= ' · pendiente ' . (int)$d['qty_remaining'] . ' uds';
            $line .= ' · ' . (string)($d['reason_label'] ?? '');
            if (!empty($d['invoice_number'])) {
                $line .= ' (' . $d['invoice_number'] . ')';
            }
            if (!empty($d['borrower'])) {
                $line .= ' · A: ' . $d['borrower'];
            }
            if (!empty($d['note'])) {
                $line .= ' — ' . $d['note'];
            }
            $lines[] = $line;
        }

        // Productos negativos sin fila de deuda (ajustes viejos)
        foreach ($products as $p) {
            if ((int)$p['stock'] >= 0) {
                continue;
            }
            $hasDebt = false;
            foreach ($debts as $d) {
                if ((int)$d['product_id'] === (int)$p['id']) {
                    $hasDebt = true;
                    break;
                }
            }
            if (!$hasDebt) {
                $code = trim((string)($p['code'] ?? ''));
                $lines[] = ($code !== '' ? $code . ' · ' : '')
                    . (string)$p['name']
                    . ' · stock ' . (int)$p['stock']
                    . ' · sin motivo registrado';
            }
        }

        $summary = $lines === []
            ? ''
            : ("Stock negativo / pendiente:\n- " . implode("\n- ", $lines));

        return [
            'count' => count($products),
            'negative_count' => $negativeCount,
            'loan_count' => $loanCount,
            'sale_debt_count' => $saleDebtCount,
            'products' => $products,
            'debts' => $debts,
            'summary_lines' => $lines,
            'summary_text' => $summary,
        ];
    }

    /**
     * Cierra deudas de venta sin stock cuando el producto ya tiene unidades en bodega
     * (reposición hecha por edición/ajuste/CSV que no pasó por coverOpenDebts).
     * Los préstamos NO se auto-cierran: requieren devolución manual.
     */
    private function reconcileStaleSaleDebts(): void
    {
        try {
            $rows = $this->query(
                "SELECT p.id, p.stock,
                        COALESCE(SUM(d.qty_remaining), 0) AS debt_qty
                 FROM products p
                 INNER JOIN product_stock_debts d ON d.product_id = p.id
                 WHERE p.status = 'active' AND p.product_type = 'product'
                   AND p.stock > 0
                   AND d.status IN ('open','partial') AND d.qty_remaining > 0
                   AND COALESCE(d.source, 'sale') = 'sale'
                 GROUP BY p.id, p.stock
                 HAVING debt_qty > 0
                 LIMIT 200"
            )->fetchAll();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $stock = (int)($row['stock'] ?? 0);
            $debtQty = (int)($row['debt_qty'] ?? 0);
            if ($stock <= 0 || $debtQty <= 0) {
                continue;
            }
            try {
                $this->coverOpenDebts((int)$row['id'], min($stock, $debtQty));
            } catch (\Throwable $e) {
                // No bloquear el reporte de alertas.
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function enrichDebtRow(array $row): array
    {
        $row['source'] = strtolower((string)($row['source'] ?? 'sale')) ?: 'sale';
        $row['reason_label'] = ProductLoan::label((string)($row['reason'] ?? ''));
        $row['source_label'] = $row['source'] === ProductLoan::SOURCE ? 'Préstamo' : 'Venta sin stock';
        return $row;
    }

    /** @return array<int,int> product_id => qty_remaining */
    public function openDebtTotalsByProduct(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) {
            return [];
        }
        try {
            $in = implode(',', $ids);
            $rows = $this->query(
                "SELECT product_id, COALESCE(SUM(qty_remaining),0) AS qty
                 FROM product_stock_debts
                 WHERE product_id IN ({$in}) AND status IN ('open','partial')
                 GROUP BY product_id"
            )->fetchAll();
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['product_id']] = (int)$r['qty'];
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array{covered:int,remaining:int,details:list<array<string,mixed>>} $cover
     */
    public function formatDebtCoverNote(array $cover): string
    {
        $covered = (int)($cover['covered'] ?? 0);
        if ($covered <= 0) {
            return '';
        }
        $parts = [];
        foreach (($cover['details'] ?? []) as $d) {
            $label = (string)($d['reason_label'] ?? ProductLoan::label((string)($d['reason'] ?? '')));
            $inv = (string)($d['invoice_number'] ?? '');
            $bit = $d['covered'] . ' uds · ' . $label;
            if ($inv !== '') {
                $bit .= ' (' . $inv . ')';
            }
            if (!empty($d['note'])) {
                $bit .= ' — ' . $d['note'];
            }
            $parts[] = $bit;
        }
        $msg = 'Cubre deuda de sobreventa: ' . implode('; ', $parts);
        $rem = (int)($cover['remaining'] ?? 0);
        if ($rem > 0) {
            $msg .= '. Quedan ' . $rem . ' uds pendientes';
        }
        return $msg;
    }

    /**
     * Mensaje amigable para UI tras una entrada de stock.
     *
     * @param array{covered:int,remaining:int,details:list<array<string,mixed>>}|null $cover
     */
    public function formatDebtCoverAlert(int $qtyIn, ?array $cover): string
    {
        $base = "Stock aumentado en {$qtyIn} unidades";
        if (!$cover || (int)($cover['covered'] ?? 0) <= 0) {
            return $base;
        }
        return $base . '. ' . $this->formatDebtCoverNote($cover);
    }

    /**
     * Alimenta el submayor de lotes en las entradas. Se crea lote incluso sin
     * fecha de vencimiento para que el saldo por lotes siga cuadrando con el stock.
     */
    private function registerLotEntry(
        int $productId,
        int $quantity,
        float $cost,
        int $movementId,
        ?array $lot,
        ?int $supplierId,
        ?string $movementDate,
        string $referenceType = '',
        ?int $referenceId = null
    ): void {
        if ($movementId <= 0 || $quantity <= 0) {
            return;
        }
        try {
            $lots = $this->lots();
            $hasLotData = !empty($lot['lot_code']) || !empty($lot['expiry_date']);
            if (!$hasLotData && !$lots->tracksLots($productId)) {
                return;
            }

            // Una devolución vuelve a su lote original; crear uno nuevo duplicaría remesas.
            if ($referenceType === 'return' && !$hasLotData
                && $this->restoreReturnedLots($productId, $quantity, $referenceId)) {
                return;
            }

            // Una fecha de vencimiento en la entrada activa el control de lotes.
            if ($hasLotData && !$lots->tracksLots($productId)) {
                $lots->configureProduct($productId, true, (int)($lot['alert_days'] ?? 30));
            }

            $lotId = $lots->registerEntry($productId, $quantity, $cost, [
                'lot_code' => (string)($lot['lot_code'] ?? ''),
                'expiry_date' => $lot['expiry_date'] ?? null,
                'supplier_id' => $lot['supplier_id'] ?? $supplierId,
                'purchase_order_id' => $lot['purchase_order_id'] ?? null,
                'received_date' => $movementDate,
                'notes' => (string)($lot['notes'] ?? ''),
            ]);

            if ($lotId > 0) {
                $this->query(
                    "INSERT INTO stock_movement_lots
                        (movement_id, lot_id, lot_code, expiry_date, quantity, unit_cost)
                     SELECT ?, id, lot_code, expiry_date, ?, ? FROM product_lots WHERE id = ?",
                    [$movementId, $quantity, round($cost, 2), $lotId]
                );
            }
        } catch (\Throwable $e) {
            error_log('Registro de lote producto ' . $productId . ': ' . $e->getMessage());
        }
    }

    /**
     * Reingresa las unidades a los lotes de los que salieron en la venta anulada.
     *
     * @return bool true si la devolución quedó completamente trazada
     */
    private function restoreReturnedLots(int $productId, int $quantity, ?int $saleId): bool
    {
        if ($saleId === null || $saleId <= 0) {
            return false;
        }

        $movements = $this->query(
            "SELECT id FROM stock_movements
             WHERE product_id = ? AND reference_type = 'sale' AND reference_id = ? AND type = 'out'
             ORDER BY id DESC",
            [$productId, $saleId]
        )->fetchAll();

        $restored = 0;
        foreach ($movements as $movement) {
            if ($restored >= $quantity) {
                break;
            }
            $restored += $this->lots()->restoreFromMovement((int)$movement['id']);
        }

        return $restored >= $quantity;
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
     * Motivo legible y detallado para trazabilidad (columna Motivo).
     * Segmenta producto nuevo vs ajuste de producto existente.
     * Devuelve texto plano (PDF / export); para UI usar describeMovementMotiveLines().
     */
    public static function describeMovementMotive(array $m): string
    {
        return implode(' · ', self::describeMovementMotiveLines($m));
    }

    /**
     * Líneas del motivo (sin duplicar datos ya presentes en notes estructurados).
     * @return list<string>
     */
    public static function describeMovementMotiveLines(array $m): array
    {
        $ref = (string)($m['reference_type'] ?? '');
        $type = (string)($m['type'] ?? '');
        $qty = (int)($m['quantity'] ?? 0);
        $stockAfter = isset($m['stock_after']) && $m['stock_after'] !== null ? (int)$m['stock_after'] : null;
        $notes = trim((string)($m['notes'] ?? ''));
        $unitCost = isset($m['unit_cost']) ? round((float)$m['unit_cost'], 2) : null;
        $totalCost = isset($m['total_cost']) ? round((float)$m['total_cost'], 2) : null;
        $payment = trim((string)($m['payment_mode'] ?? ''));

        $userDetail = self::extractMotiveUserDetail($notes);

        $isNewProduct = in_array($ref, ['opening'], true)
            || preg_match('/producto\s+nuevo|inventario\s+inicial|stock\s+inicial/iu', $notes) === 1;

        if ($ref === 'csv_import') {
            $isNewProduct = preg_match('/producto\s+nuevo|stock\s+inicial|inventario\s+inicial/iu', $notes) === 1
                && preg_match('/producto\s+existente|ajuste/iu', $notes) !== 1;
        }

        if (in_array($ref, ['adjustment', 'csv_export'], true)
            || ($ref === 'csv_import' && !$isNewProduct)
            || preg_match('/ajuste\s+de\s+stock/iu', $notes) === 1
        ) {
            $isNewProduct = false;
        }

        $lines = [];
        if ($isNewProduct) {
            $lines[] = 'Producto nuevo · Inventario inicial';
        } elseif (in_array($ref, ['adjustment', 'csv_import', 'csv_export'], true) || $type === 'adjustment') {
            $lines[] = 'Ajuste · Producto existente';
        } elseif ($ref === 'inventory_exit') {
            $lines[] = 'Salida / pérdida de inventario';
        } elseif ($ref === 'sale') {
            $lines[] = 'Salida por venta';
        } elseif ($ref === 'return') {
            $lines[] = 'Entrada por devolución de venta';
        } elseif ($ref === 'purchase' || $ref === 'purchase_order') {
            $lines[] = 'Entrada por compra a proveedor';
        } elseif ($ref === 'purchase_credit_note') {
            $lines[] = 'Ajuste por nota de crédito de compra';
        } elseif ($ref === 'loan') {
            $lines[] = 'Salida por préstamo de producto';
        } elseif ($ref === 'loan_return') {
            $lines[] = 'Entrada por devolución de préstamo';
        } elseif ($ref === 'price_change') {
            $lines[] = 'Cambio de precio (sin movimiento de cantidad)';
        }

        $dir = $type === 'out' ? 'Salida' : ($type === 'in' ? 'Entrada' : 'Ajuste');
        if ($qty !== 0 || $type === 'adjustment') {
            $signed = $type === 'out' ? -abs($qty) : ($type === 'in' ? abs($qty) : $qty);
            $qtyLine = $dir . ': ' . abs($qty) . ' und.';
            if ($signed !== 0) {
                $qtyLine .= ' (Δ ' . ($signed > 0 ? '+' : '') . $signed . ')';
            }
            if ($stockAfter !== null && $qty !== 0) {
                $stockBefore = $type === 'out'
                    ? $stockAfter + abs($qty)
                    : ($type === 'in' ? $stockAfter - abs($qty) : null);
                if ($stockBefore !== null) {
                    $qtyLine .= ' · Stock ' . $stockBefore . ' → ' . $stockAfter;
                } else {
                    $qtyLine .= ' · Stock ' . $stockAfter;
                }
            } elseif ($stockAfter !== null) {
                $qtyLine .= ' · Stock ' . $stockAfter;
            }
            $lines[] = $qtyLine;
        } elseif ($stockAfter !== null) {
            $lines[] = 'Stock resultante: ' . $stockAfter;
        }

        $costBits = [];
        if ($unitCost !== null && $unitCost > 0) {
            $costBits[] = 'Costo u.: ' . number_format($unitCost, 2, '.', '');
        }
        if ($totalCost !== null && $totalCost > 0 && $qty !== 0) {
            $costBits[] = 'Total: ' . number_format($totalCost, 2, '.', '');
        }
        if ($payment !== '') {
            $payMap = ['equity' => 'capital/aporte', 'loss' => 'pérdida', 'cash' => 'caja', 'credit' => 'crédito'];
            $costBits[] = 'Modo: ' . ($payMap[$payment] ?? $payment);
        }
        if ($costBits !== []) {
            $lines[] = implode(' · ', $costBits);
        }

        if ($userDetail !== '') {
            $lines[] = $userDetail;
        } elseif ($notes !== '' && $lines === []) {
            $lines[] = $notes;
        }

        return $lines !== [] ? $lines : ['—'];
    }

    /** Extrae solo el detalle de usuario de notes estructurados (evita duplicar stock/cantidad). */
    private static function extractMotiveUserDetail(string $notes): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }

        // Preferir el último "Detalle:" (el de usuario).
        if (preg_match_all('/Detalle:\s*(.+?)(?=\s*·\s*(?:Producto|Ajuste|Cantidad|Stock|Detalle|$)|$)/iu', $notes, $matches)) {
            $candidates = array_map('trim', $matches[1]);
            $candidates = array_values(array_filter($candidates, static function ($c) {
                if ($c === '') {
                    return false;
                }
                // Solo descartar plantillas internas, no el texto libre del usuario.
                return preg_match(
                    '/^(Producto existente\s*\(añadir\/reducir.*?\)|Inventario inicial al crear.*?|Cantidad movida:|Stock:\s*\d)/iu',
                    $c
                ) !== 1;
            }));
            if ($candidates !== []) {
                $detail = (string)end($candidates);
                // Quitar coletilla redundante si ya clasificamos arriba.
                $detail = preg_replace('/\s*\(producto existente\)\s*$/iu', '', $detail);
                return trim((string)$detail);
            }
        }

        // Notes antiguos sin estructura: devolver tal cual si no son solo la plantilla.
        if (preg_match('/^(Producto nuevo|Ajuste de stock)\b/iu', $notes) === 1) {
            return '';
        }

        return $notes;
    }

    /**
     * Texto de motivo al registrar alta o ajuste (escritura en stock_movements.notes).
     */
    public static function buildStockMotive(
        string $kind,
        int $quantity,
        ?int $stockBefore = null,
        ?int $stockAfter = null,
        string $detail = ''
    ): string {
        $detail = trim($detail);
        $parts = [];

        if ($kind === 'new_product') {
            $parts[] = 'Producto nuevo';
            $parts[] = 'Inventario inicial al crear el ítem en catálogo';
        } else {
            $parts[] = 'Ajuste de stock';
            $parts[] = 'Producto existente (añadir/reducir cantidad no es alta nueva)';
        }

        if ($quantity !== 0) {
            $parts[] = 'Cantidad movida: ' . abs($quantity)
                . ($kind !== 'new_product' ? ($quantity >= 0 ? ' (+)' : ' (−)') : '');
        }
        if ($stockBefore !== null && $stockAfter !== null) {
            $parts[] = 'Stock: ' . $stockBefore . ' → ' . $stockAfter;
        } elseif ($stockAfter !== null) {
            $parts[] = 'Stock resultante: ' . $stockAfter;
        }
        if ($detail !== '') {
            $parts[] = 'Detalle: ' . $detail;
        }

        return implode(' · ', $parts);
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

        $movementId = $this->addMovement(
            $productId,
            'adjustment',
            abs($diff),
            'adjustment',
            null,
            self::buildStockMotive(
                'adjustment',
                $diff,
                $old,
                $newStock,
                $notes !== '' ? $notes : 'Ajuste manual de stock'
            ),
            null,
            $cost,
            $newStock,
            null,
            $diff > 0 ? 'equity' : 'loss',
            $movementDate
        );

        if ($diff > 0) {
            $this->coverOpenDebts($productId, $diff);
            try {
                if ($this->lots()->tracksLots($productId)) {
                    $this->lots()->registerEntry($productId, $diff, $cost, [
                        'lot_code' => '',
                        'expiry_date' => null,
                        'notes' => 'Ajuste de stock (+)',
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Lote en adjustTo +: ' . $e->getMessage());
            }
        } elseif ($diff < 0) {
            $this->consumeLots($productId, abs($diff), $movementId);
        }

        return $movementId;
    }

    /**
     * Ajuste de cantidad de un lote + sincronización de products.stock y kardex.
     *
     * @return array{ok:bool,message:string,movement_id:int}
     */
    public function adjustLotWithStock(int $lotId, int $newQuantity, string $notes = ''): array
    {
        $result = $this->lots()->adjustLot($lotId, $newQuantity, $notes);
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => 'Lote no encontrado', 'movement_id' => 0];
        }
        $delta = (int)$result['delta'];
        if ($delta === 0) {
            return ['ok' => true, 'message' => 'Lote sin cambios', 'movement_id' => 0];
        }

        $productId = (int)$result['product_id'];
        $product = $this->query("SELECT stock, purchase_price FROM products WHERE id = ?", [$productId])->fetch();
        if (!$product) {
            return ['ok' => false, 'message' => 'Producto no encontrado', 'movement_id' => 0];
        }

        $oldStock = (int)$product['stock'];
        $newStock = max(0, $oldStock + $delta);
        $this->query("UPDATE products SET stock = ? WHERE id = ?", [$newStock, $productId]);

        $cost = (float)$result['unit_cost'] > 0
            ? (float)$result['unit_cost']
            : round((float)($product['purchase_price'] ?? 0), 2);

        $motive = self::buildStockMotive(
            'adjustment',
            $delta,
            $oldStock,
            $newStock,
            ($notes !== '' ? $notes . ' · ' : '') . 'Ajuste lote ' . $result['lot_code']
        );

        $movementId = $this->addMovement(
            $productId,
            $delta > 0 ? 'in' : 'out',
            abs($delta),
            'lot_adjust',
            $lotId,
            $motive,
            null,
            $cost,
            $newStock,
            null,
            $delta > 0 ? 'equity' : 'loss'
        );

        // Traza del lote en el movimiento (sin volver a alterar cantidad del lote).
        try {
            $this->query(
                "INSERT INTO stock_movement_lots
                    (movement_id, lot_id, lot_code, expiry_date, quantity, unit_cost)
                 SELECT ?, id, lot_code, expiry_date, ?, ? FROM product_lots WHERE id = ?",
                [$movementId, abs($delta), $cost, $lotId]
            );
        } catch (\Throwable $e) {
            // ignore
        }

        if ($delta > 0) {
            $this->coverOpenDebts($productId, $delta);
        }

        return [
            'ok' => true,
            'message' => 'Lote ajustado y stock sincronizado',
            'movement_id' => $movementId,
        ];
    }

    /**
     * Baja formal de un lote (vencimiento/merma): descuenta stock + depleta lote.
     *
     * @return array{ok:bool,message:string,movement_id:int}
     */
    public function writeOffLotWithStock(int $lotId, string $reason = ''): array
    {
        $info = $this->lots()->writeOffLot($lotId, $reason !== '' ? $reason : 'Baja por vencimiento');
        if (!$info) {
            return ['ok' => false, 'message' => 'Lote no encontrado o ya vacío', 'movement_id' => 0];
        }

        $productId = (int)$info['product_id'];
        $qty = (int)$info['quantity'];
        $product = $this->query("SELECT stock, purchase_price FROM products WHERE id = ?", [$productId])->fetch();
        if (!$product) {
            return ['ok' => false, 'message' => 'Producto no encontrado', 'movement_id' => 0];
        }

        $oldStock = (int)$product['stock'];
        $newStock = max(0, $oldStock - $qty);
        $this->query("UPDATE products SET stock = ? WHERE id = ?", [$newStock, $productId]);

        $cost = (float)$info['unit_cost'] > 0
            ? (float)$info['unit_cost']
            : round((float)($product['purchase_price'] ?? 0), 2);

        $movementId = $this->addMovement(
            $productId,
            'out',
            $qty,
            'lot_writeoff',
            $lotId,
            self::buildStockMotive(
                'expiry_writeoff',
                -$qty,
                $oldStock,
                $newStock,
                ($reason !== '' ? $reason . ' · ' : '') . 'Baja lote ' . $info['lot_code']
            ),
            null,
            $cost,
            $newStock,
            null,
            'loss'
        );

        try {
            $this->query(
                "INSERT INTO stock_movement_lots
                    (movement_id, lot_id, lot_code, expiry_date, quantity, unit_cost)
                 SELECT ?, id, lot_code, expiry_date, ?, ? FROM product_lots WHERE id = ?",
                [$movementId, $qty, $cost, $lotId]
            );
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'ok' => true,
            'message' => "Baja de {$qty} uds del lote {$info['lot_code']}",
            'movement_id' => $movementId,
        ];
    }

    /**
     * Repara descuadre: si falta lote, siembra apertura; si sobra lote, no toca (requiere ajuste manual).
     * Si mode=stock, ajusta products.stock a la suma física de lotes.
     *
     * @return array{ok:bool,message:string,seeded:int,stock_delta:int}
     */
    public function repairLotMismatch(int $productId, string $mode = 'seed'): array
    {
        $lots = $this->lots();
        if (!$lots->tracksLots($productId)) {
            return ['ok' => false, 'message' => 'El producto no controla lotes', 'seeded' => 0, 'stock_delta' => 0];
        }

        $product = $this->query(
            "SELECT stock, purchase_price FROM products WHERE id = ?",
            [$productId]
        )->fetch();
        if (!$product) {
            return ['ok' => false, 'message' => 'Producto no encontrado', 'seeded' => 0, 'stock_delta' => 0];
        }

        $stock = (int)$product['stock'];
        $lotStock = $lots->physicalLotStock($productId);
        $cost = round((float)($product['purchase_price'] ?? 0), 2);

        if ($mode === 'stock') {
            $delta = $lotStock - $stock;
            if ($delta === 0) {
                return ['ok' => true, 'message' => 'Ya estaba cuadrado', 'seeded' => 0, 'stock_delta' => 0];
            }
            $newStock = max(0, $lotStock);
            $this->query("UPDATE products SET stock = ? WHERE id = ?", [$newStock, $productId]);
            $this->addMovement(
                $productId,
                $delta > 0 ? 'in' : 'out',
                abs($delta),
                'lot_reconcile',
                null,
                self::buildStockMotive(
                    'adjustment',
                    $delta,
                    $stock,
                    $newStock,
                    'Cuadre de stock a suma de lotes (sin alterar remesas)'
                ),
                null,
                $cost,
                $newStock,
                null,
                $delta > 0 ? 'equity' : 'loss'
            );
            if ($delta > 0) {
                $this->coverOpenDebts($productId, $delta);
            }
            return ['ok' => true, 'message' => 'Stock ajustado a la suma de lotes', 'seeded' => 0, 'stock_delta' => $delta];
        }

        // mode seed: crear lote por el faltante
        if ($stock > $lotStock) {
            $seeded = $lots->seedOpeningLot($productId, $cost);
            return [
                'ok' => true,
                'message' => $seeded > 0
                    ? "Se creó lote de apertura por {$seeded} uds"
                    : 'No había faltante de lote',
                'seeded' => $seeded,
                'stock_delta' => 0,
            ];
        }
        if ($stock < $lotStock) {
            return [
                'ok' => false,
                'message' => 'Hay más unidades en lotes que en stock. Use “Ajustar stock a lotes” o ajuste el lote sobrante.',
                'seeded' => 0,
                'stock_delta' => 0,
            ];
        }

        return ['ok' => true, 'message' => 'Ya estaba cuadrado', 'seeded' => 0, 'stock_delta' => 0];
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
               AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'purchase_order', 'purchase_credit_note', 'loan', 'loan_return')
               AND (
                    sm.type = 'in'
                    OR sm.type = 'out'
                    OR sm.type = 'adjustment'
                    OR sm.reference_type IN ('purchase', 'opening', 'adjustment', 'inventory_exit')
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
               AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'purchase_order', 'purchase_credit_note', 'loan', 'loan_return')
               AND (
                    sm.type = 'in'
                    OR sm.type = 'out'
                    OR sm.type = 'adjustment'
                    OR sm.reference_type IN ('purchase', 'opening', 'adjustment', 'inventory_exit')
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

        $type = trim((string)($filters['type'] ?? ''));
        if ($type !== '') {
            if ($type === 'in') {
                // Entradas: type=in (compras, agregar stock, etc.)
                $where[] = "sm.type = 'in'";
            } elseif ($type === 'out') {
                $where[] = "sm.type = 'out'";
            } elseif ($type === 'adjustment') {
                // Ajustes manuales: type=adjustment O entradas/salidas con origen adjustment
                $where[] = "(sm.type = 'adjustment' OR sm.reference_type = 'adjustment')";
            } else {
                $where[] = 'sm.type = ?';
                $params[] = $type;
            }
        }

        $ref = trim((string)($filters['reference_type'] ?? ''));
        if ($ref !== '') {
            if ($ref === 'adjustment') {
                $where[] = "(sm.reference_type = 'adjustment' OR sm.type = 'adjustment')";
            } elseif ($ref === 'purchase') {
                $where[] = "sm.reference_type IN ('purchase', 'purchase_order')";
            } else {
                $where[] = 'sm.reference_type = ?';
                $params[] = $ref;
            }
        }

        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.barcode,\'\') LIKE ? OR sm.notes LIKE ? OR sm.reference_type LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $from = trim((string)($filters['from'] ?? ''));
        $to = trim((string)($filters['to'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'DATE(COALESCE(NULLIF(sm.movement_date, \'0000-00-00\'), sm.created_at)) >= ?';
            $params[] = $from;
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'DATE(COALESCE(NULLIF(sm.movement_date, \'0000-00-00\'), sm.created_at)) <= ?';
            $params[] = $to;
        }

        if (!empty($filters['location'])) {
            $where[] = 'p.location = ?';
            $params[] = $filters['location'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int)$filters['category_id'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)$this->query(
            "SELECT COUNT(*) FROM stock_movements sm
             LEFT JOIN products p ON p.id = sm.product_id
             WHERE {$whereSql}",
            $params
        )->fetchColumn();

        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        if ($offset >= $total && $total > 0) {
            $offset = 0;
        }
        $rows = $this->query(
            "SELECT sm.*, p.name product_name, p.code product_code, p.source_channel, p.location product_location,
                    c.name category_name, u.name user_name
             FROM stock_movements sm
             LEFT JOIN products p ON p.id = sm.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = sm.user_id
             WHERE {$whereSql}
             ORDER BY COALESCE(NULLIF(sm.movement_date, '0000-00-00'), sm.created_at) DESC, sm.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }
}
