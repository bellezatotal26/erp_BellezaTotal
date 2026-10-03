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
            // Producto físico recibido al devolver (si difiere del prestado = sustitución).
            'return_product_id' => "ALTER TABLE product_stock_debts ADD COLUMN return_product_id INT UNSIGNED NULL AFTER borrower",
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

        $movementId = (int)$this->db->lastInsertId();
        $this->auditInventoryMovement(
            $movementId,
            $productId,
            $type,
            $quantity,
            $referenceType,
            $referenceId,
            $notes,
            $stockAfter
        );
        return $movementId;
    }

    /**
     * Registra en auditoría solo movimientos de inventario (no ventas ni líneas de OC,
     * que ya tienen un evento resumen en ventas/compras).
     */
    private function auditInventoryMovement(
        int $movementId,
        int $productId,
        string $type,
        int $quantity,
        string $referenceType,
        ?int $referenceId,
        string $notes,
        ?int $stockAfter
    ): void {
        $skip = [
            'sale', 'return', 'sale_credit_note',
            'purchase_order', 'purchase', 'purchase_credit_note',
        ];
        if (in_array($referenceType, $skip, true)) {
            return;
        }
        $interesting = [
            'adjustment', 'opening', 'inventory_exit',
            'loan', 'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle',
            'consignment_in', 'consignment_return',
        ];
        if ($referenceType !== '' && !in_array($referenceType, $interesting, true)) {
            return;
        }
        if ($referenceType === '' && $notes === '') {
            return;
        }

        $productName = '';
        try {
            $productName = (string)$this->query(
                "SELECT name FROM products WHERE id = ? LIMIT 1",
                [$productId]
            )->fetchColumn();
        } catch (\Throwable $e) {
            // ignore
        }

        $dir = $type === 'in' ? 'Entrada' : ($type === 'out' ? 'Salida' : 'Movimiento');
        $refLabel = $referenceType !== '' ? $referenceType : 'manual';
        $module = str_starts_with($referenceType, 'loan') ? 'prestamos' : 'inventario';
        $action = $referenceType === 'adjustment' || $referenceType === 'opening' ? 'adjust' : 'movement';

        TenantAuditService::safeLog(
            $this->db,
            $action,
            $module,
            $dir . ' stock: ' . ($productName !== '' ? $productName : ('#' . $productId))
                . ' · qty=' . $quantity
                . ($stockAfter !== null ? (' · stock=' . $stockAfter) : '')
                . ' · ' . $refLabel
                . ($notes !== '' ? (' — ' . mb_substr($notes, 0, 100)) : ''),
            'stock_movement',
            $movementId,
            [
                'product_id' => $productId,
                'type' => $type,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'stock_after' => $stockAfter,
            ]
        );
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

        // NC de compra: puede operar aunque el producto esté inactivo (ya no se vende).
        $forceDocOut = in_array($referenceType, ['purchase_credit_note'], true);
        if (($product['status'] ?? '') !== 'active' && !$forceDocOut) {
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
        $skipDebt = !empty($oversell['skip_debt']);

        if ($shortfall > 0) {
            // Nota crédito compra: la mercancía debe salir del inventario aunque
            // ya se haya vendido (queda negativo / trazabilidad). No es sobreventa.
            if ($forceDocOut) {
                $allowOversell = true;
                $skipDebt = true;
                $extra = ' · Sin stock en bodega al acreditar (disp. ' . $available . ')';
                $notes = $notes !== '' ? ($notes . $extra) : ('Salida NC compra' . $extra);
            } else {
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
        }

        if ($allowOversell || $forceDocOut) {
            if ($forceDocOut) {
                $stmt = $this->query(
                    "UPDATE products SET stock = stock - ? WHERE id = ?",
                    [$quantity, $productId]
                );
            } else {
                $stmt = $this->query(
                    "UPDATE products SET stock = stock - ?, last_sale_date = NOW()
                     WHERE id = ? AND status = 'active'",
                    [$quantity, $productId]
                );
            }
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

        if ($allowOversell && $shortfall > 0 && !$skipDebt) {
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

        // Bonificación / ingreso a $0: no diluir el costo promedio; conservar el precio de costo.
        if ($updatePurchasePrice && $cost <= 0.009 && $oldCost > 0.009) {
            $cost = $oldCost;
        }

        // Promedio ponderado (WAC). Las bonificaciones ya vienen valorizadas (o se igualan al costo actual).
        if ($updatePurchasePrice) {
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

        // Devoluciones / préstamos recibidos: no cubren sobreventas de venta.
        if ($coverSaleDebts && $referenceType !== 'return' && $referenceType !== 'loan_return'
            && $referenceType !== 'loan_received'
            && $referenceType !== 'sale_credit_note') {
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
        if (!in_array($source, ['sale', 'loan', ProductLoan::SOURCE_RECEIVED], true)) {
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
            // Préstamos (salida o recibidos) NO pueden caer al fallback sin `source`.
            if ($source === ProductLoan::SOURCE || $source === ProductLoan::SOURCE_RECEIVED) {
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
                $upd = $this->query(
                    "UPDATE product_stock_debts
                     SET qty_remaining = ?, status = ?, updated_at = NOW()
                     WHERE id = ? AND qty_remaining = ? AND status IN ('open','partial')",
                    [$newRemaining, $status, (int)$debt['id'], $owed]
                );
                if ($upd->rowCount() < 1) {
                    continue;
                }
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
                'reason_label' => ProductLoan::label((string)$debt['reason'], $this->db),
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
        ProductLoan::syncCatalog($this->db);
        $parsed = ProductLoan::parse($reason, $note, $borrower, $this->db);
        try {
            new ConsignmentService($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
        $product = $this->query(
            "SELECT id, name, stock, product_type, status, COALESCE(ownership,'owned') AS ownership
             FROM products WHERE id = ?",
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
        $own = (string)($product['ownership'] ?? 'owned');
        if ($own === ConsignmentService::OWNERSHIP_CONSIGNMENT) {
            throw new \RuntimeException('Ese producto es de consignación. Use el módulo Consignación.');
        }
        if ($own === ConsignmentService::OWNERSHIP_BORROWED) {
            throw new \RuntimeException(
                'Ese producto está marcado como Prestado (nos lo prestaron). Para devolverlo use «Préstamos recibidos».'
            );
        }

        $available = (int)$product['stock'];
        $shortfall = max(0, $quantity - max(0, $available));
        $label = ProductLoan::label($parsed['reason'], $this->db);
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
            if ($movementId > 0) {
                $this->query(
                    "UPDATE stock_movements SET reference_id = ? WHERE id = ? AND reference_id IS NULL",
                    [$debtId, $movementId]
                );
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
     * - Mismo producto: $returnLines vacío / null y sin $returnProductId.
     * - Sustitución simple: $returnProductId distinto al prestado.
     * - Sustitución múltiple: $returnLines = [{product_id, quantity}, ...] cuya suma = $quantity.
     *
     * @param list<array{product_id?:int,quantity?:int}>|null $returnLines
     * @return array{
     *   returned:int,stock_before:int,stock_after:int,remaining:int,movement_id:int,
     *   return_product_id:int,original_product_id:int,substituted:bool,
     *   lines:list<array{product_id:int,quantity:int,stock_after:int,label:string}>
     * }
     */
    public function returnLoan(
        int $debtId,
        int $quantity,
        ?int $returnProductId = null,
        ?array $returnLines = null
    ): array {
        if ($debtId <= 0 || $quantity <= 0) {
            throw new \InvalidArgumentException('Cantidad de devolución inválida');
        }

        // Calentar lotes/esquema ANTES de la TX (DDL hace COMMIT implícito en MySQL).
        $this->lots();
        $this->ensureSchema();

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
            $owed = (int)$debt['qty_remaining'];
            $take = min($quantity, $owed);
            if ($take <= 0) {
                throw new \RuntimeException('Este préstamo ya está cerrado');
            }

            // Normalizar líneas de ingreso de stock (mismo o sustitutos).
            $qtyByProduct = [];
            if (is_array($returnLines) && $returnLines !== []) {
                foreach ($returnLines as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $pid = (int)($line['product_id'] ?? 0);
                    $q = (int)($line['quantity'] ?? 0);
                    if ($pid <= 0 || $q <= 0) {
                        continue;
                    }
                    $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $q;
                }
            } elseif ($returnProductId !== null && $returnProductId > 0) {
                $qtyByProduct[(int)$returnProductId] = $take;
            } else {
                $qtyByProduct[$originalProductId] = $take;
            }

            if ($qtyByProduct === []) {
                throw new \InvalidArgumentException('Indique al menos un producto a devolver');
            }

            $linesSum = array_sum($qtyByProduct);
            if ($linesSum !== $take) {
                throw new \InvalidArgumentException(
                    'La suma de cantidades (' . $linesSum . ') debe ser exactamente '
                    . $take . ' (unidades a cerrar del préstamo).'
                );
            }

            $substituted = false;
            foreach (array_keys($qtyByProduct) as $pid) {
                if ((int)$pid !== $originalProductId) {
                    $substituted = true;
                    break;
                }
            }

            $orig = $this->query(
                "SELECT name, code FROM products WHERE id = ?",
                [$originalProductId]
            )->fetch() ?: [];
            $origLabel = trim(
                (string)(($orig['code'] ?? '') !== '' ? (($orig['code'] ?? '') . ' · ') : '')
                . ($orig['name'] ?? ('#' . $originalProductId))
            );
            $label = ProductLoan::label((string)$debt['reason'], $this->db);
            $baseNotes = 'Devolución préstamo: ' . $label
                . (!empty($debt['borrower']) ? (' · ' . $debt['borrower']) : '')
                . (!empty($debt['note']) ? (' — ' . $debt['note']) : '');

            $lineResults = [];
            $movementId = 0;
            $firstAltId = 0;
            $altLabels = [];

            foreach ($qtyByProduct as $pid => $lineQty) {
                $pid = (int)$pid;
                $lineQty = (int)$lineQty;
                $prod = $this->query(
                    "SELECT id, name, code, product_type, status, COALESCE(ownership,'owned') AS ownership
                     FROM products WHERE id = ? LIMIT 1",
                    [$pid]
                )->fetch();
                if (!$prod || ($prod['status'] ?? '') !== 'active') {
                    throw new \RuntimeException('El producto de devolución no existe o está inactivo (#' . $pid . ')');
                }
                if (($prod['product_type'] ?? 'product') === 'service') {
                    throw new \RuntimeException('No puede devolver un servicio como reposición de préstamo');
                }
                $own = (string)($prod['ownership'] ?? 'owned');
                if ($own === ConsignmentService::OWNERSHIP_CONSIGNMENT) {
                    throw new \RuntimeException(
                        'No use un producto de consignación como devolución de préstamo.'
                    );
                }

                try {
                    $stockBefore = (int)$this->query(
                        "SELECT stock FROM products WHERE id = ? LIMIT 1 FOR UPDATE",
                        [$pid]
                    )->fetchColumn();
                } catch (\Throwable $e) {
                    $stockBefore = (int)$this->query(
                        "SELECT stock FROM products WHERE id = ? LIMIT 1",
                        [$pid]
                    )->fetchColumn();
                }

                $prodLabel = trim(
                    (string)(($prod['code'] ?? '') !== '' ? (($prod['code'] ?? '') . ' · ') : '')
                    . ($prod['name'] ?? ('#' . $pid))
                );
                $notes = $baseNotes
                    . ' · stock ' . $stockBefore . '→' . ($stockBefore + $lineQty)
                    . ' · +' . $lineQty . ' uds';
                if ($pid !== $originalProductId) {
                    $notes .= ' · Sustitución: prestó ' . $origLabel . ' / devolvió ' . $prodLabel;
                    $altLabels[] = $prodLabel . '×' . $lineQty;
                    if ($firstAltId <= 0) {
                        $firstAltId = $pid;
                    }
                } else {
                    $altLabels[] = $prodLabel . '×' . $lineQty . ' (mismo)';
                }

                $movId = $this->increase(
                    $pid,
                    $lineQty,
                    'loan_return',
                    $debtId,
                    $notes,
                    'in'
                );
                if ($movementId <= 0) {
                    $movementId = $movId;
                }

                $stockAfterLine = (int)$this->query(
                    "SELECT stock FROM products WHERE id = ?",
                    [$pid]
                )->fetchColumn();
                if ($stockAfterLine !== ($stockBefore + $lineQty)) {
                    throw new \RuntimeException(
                        'La devolución no actualizó el inventario de ' . $prodLabel
                        . ' (esperaba ' . ($stockBefore + $lineQty) . ', quedó ' . $stockAfterLine . ').'
                    );
                }

                $lineResults[] = [
                    'product_id' => $pid,
                    'quantity' => $lineQty,
                    'stock_after' => $stockAfterLine,
                    'label' => $prodLabel,
                ];
            }

            if ($substituted && $firstAltId > 0) {
                try {
                    $this->query(
                        "UPDATE product_stock_debts
                         SET return_product_id = ?, updated_at = NOW()
                         WHERE id = ?",
                        [$firstAltId, $debtId]
                    );
                } catch (\Throwable $e) {
                    // Columna aún no migrada
                }

                $swapNote = ' · Cambio: devolvió ' . implode(', ', $altLabels)
                    . ' (entrada en esos SKU, no repone el stock prestado)';
                $loanMovId = (int)($debt['movement_id'] ?? 0);
                try {
                    if ($loanMovId > 0) {
                        $this->query(
                            "UPDATE stock_movements
                             SET notes = CONCAT(IFNULL(notes,''), ?)
                             WHERE id = ?
                               AND product_id = ?
                               AND reference_type = 'loan'
                               AND IFNULL(notes,'') NOT LIKE ?",
                            [$swapNote, $loanMovId, $originalProductId, '%' . mb_substr($swapNote, 0, 40) . '%']
                        );
                    } else {
                        $this->query(
                            "UPDATE stock_movements
                             SET notes = CONCAT(IFNULL(notes,''), ?)
                             WHERE reference_type = 'loan'
                               AND reference_id = ?
                               AND product_id = ?
                               AND IFNULL(notes,'') NOT LIKE ?",
                            [$swapNote, $debtId, $originalProductId, '%' . mb_substr($swapNote, 0, 40) . '%']
                        );
                    }
                } catch (\Throwable $e) {
                    // No bloquear
                }
            }

            $newRemaining = $owed - $take;
            $status = $newRemaining <= 0 ? 'closed' : 'partial';
            $upd = $this->query(
                "UPDATE product_stock_debts
                 SET qty_remaining = ?, status = ?, updated_at = NOW()
                 WHERE id = ? AND qty_remaining = ? AND status IN ('open','partial')",
                [$newRemaining, $status, $debtId, $owed]
            );
            if ($upd->rowCount() < 1) {
                throw new \RuntimeException('El préstamo cambió mientras se registraba la devolución. Intente de nuevo.');
            }

            $primaryPid = $substituted && $firstAltId > 0
                ? $firstAltId
                : $originalProductId;
            $stockBeforePrimary = 0;
            $stockAfterPrimary = (int)$this->query(
                "SELECT stock FROM products WHERE id = ?",
                [$primaryPid]
            )->fetchColumn();
            foreach ($lineResults as $lr) {
                if ((int)$lr['product_id'] === $primaryPid) {
                    $stockAfterPrimary = (int)$lr['stock_after'];
                    $stockBeforePrimary = $stockAfterPrimary - (int)$lr['quantity'];
                    break;
                }
            }

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'returned' => $take,
                'stock_before' => $stockBeforePrimary,
                'stock_after' => $stockAfterPrimary,
                'remaining' => $newRemaining,
                'movement_id' => $movementId,
                'return_product_id' => $primaryPid,
                'original_product_id' => $originalProductId,
                'substituted' => $substituted,
                'lines' => $lineResults,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Unidades pendientes de préstamos recibidos (nos prestaron) por producto.
     */
    public function openReceivedLoanQty(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }
        try {
            return (int)$this->query(
                "SELECT COALESCE(SUM(qty_remaining), 0)
                 FROM product_stock_debts
                 WHERE product_id = ?
                   AND status IN ('open','partial')
                   AND qty_remaining > 0
                   AND COALESCE(source, '') = ?",
                [$productId, ProductLoan::SOURCE_RECEIVED]
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
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
            'roto' => 'Producto roto / dañado',
            'merma' => 'Merma / deterioro',
            'perdida' => 'Pérdida / extravío',
            'robo' => 'Robo / hurto',
            'vencimiento' => 'Vencimiento',
            'devolucion' => 'Devolución (sin nota de crédito)',
            'otro' => 'Otro (detalle en observación)',
        ];
    }

    /**
     * Salida de inventario por pérdida operativa (gasto a inventory_loss_account).
     *
     * @return array{movement_id:int,stock_after:int,reason:string,unit_cost:float,total_cost:float}
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
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new \InvalidArgumentException(
                'Indique una observación/justificación (mín. 5 caracteres) de por qué sale el inventario.'
            );
        }
        if ($productId <= 0 || $quantity <= 0) {
            throw new \InvalidArgumentException('Producto o cantidad inválidos');
        }

        $product = $this->query(
            "SELECT id, name, purchase_price, sale_price, stock, status, product_type
             FROM products WHERE id = ?",
            [$productId]
        )->fetch();
        if (!$product) {
            throw new \InvalidArgumentException('Producto no encontrado');
        }
        if (($product['product_type'] ?? 'product') === 'service') {
            throw new \InvalidArgumentException('No aplica salida de inventario a un servicio');
        }

        $unitCost = round((float)($product['purchase_price'] ?? 0), 2);
        if ($unitCost <= 0) {
            // Sin costo de compra: valorar a precio de venta para no omitir el asiento de pérdida.
            $unitCost = round((float)($product['sale_price'] ?? 0), 2);
        }

        $label = $reasons[$reasonKey];
        $notes = 'Salida inventario: ' . $label
            . ' · Justificación: ' . $note;

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
                $unitCost,
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
                'unit_cost' => $unitCost,
                'total_cost' => round($unitCost * $quantity, 2),
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Entrada de mercancía que nos prestan (no consignación).
     * Producto queda ownership=borrowed; deuda source=loan_received hasta devolver.
     *
     * @return array{movement_id:int,debt_id:int,product_id:int,stock_after:int,created_product:bool}
     */
    public function registerLoanReceived(
        int $quantity,
        string $reason,
        string $note,
        string $lender,
        ?int $productId = null,
        ?string $newName = null,
        ?string $newCode = null,
        ?string $newBarcode = null,
        ?float $unitCost = null
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad debe ser mayor a 0');
        }
        $parsed = ProductLoan::parseReceived($reason, $note, $lender, $this->db);
        $unitCost = round(max(0, (float)($unitCost ?? 0)), 2);
        $created = false;

        try {
            new ConsignmentService($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
        $this->lots();

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            if ($productId !== null && $productId > 0) {
                $product = $this->query(
                    "SELECT id, name, code, ownership, product_type, status FROM products WHERE id = ? LIMIT 1",
                    [$productId]
                )->fetch();
                if (!$product) {
                    throw new \RuntimeException('Producto no encontrado');
                }
                if (($product['product_type'] ?? 'product') === 'service') {
                    throw new \RuntimeException('No aplica a servicios');
                }
                $own = (string)($product['ownership'] ?? 'owned');
                if ($own === ConsignmentService::OWNERSHIP_CONSIGNMENT) {
                    throw new \RuntimeException('Ese producto es de consignación. Use el módulo Consignación.');
                }
                if ($own !== ConsignmentService::OWNERSHIP_BORROWED) {
                    $this->query(
                        "UPDATE products SET ownership = ? WHERE id = ?",
                        [ConsignmentService::OWNERSHIP_BORROWED, $productId]
                    );
                }
                if (($product['status'] ?? '') !== 'active') {
                    $this->query(
                        "UPDATE products SET status = 'active', deleted_at = NULL WHERE id = ?",
                        [$productId]
                    );
                }
            } else {
                $name = trim((string)$newName);
                if ($name === '') {
                    throw new \InvalidArgumentException('Indique el nombre del producto prestado');
                }
                $code = trim((string)($newCode ?? ''));
                if ($code === '') {
                    try {
                        $code = (string)((new SkuSequenceService($this->db))->allocateNext()['sku'] ?? '');
                    } catch (\Throwable $e) {
                        $code = 'PREST-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    }
                }
                $barcode = trim((string)($newBarcode ?? ''));
                $this->query(
                    "INSERT INTO products
                        (code, barcode, name, product_type, ownership, purchase_price, sale_price,
                         stock, min_stock, unit, status, track_lots, created_by)
                     VALUES (?, ?, ?, 'product', ?, ?, 0, 0, 0, 'UND', 'active', 0, ?)",
                    [
                        $code,
                        $barcode !== '' ? $barcode : null,
                        $name,
                        ConsignmentService::OWNERSHIP_BORROWED,
                        $unitCost,
                        $_SESSION['tenant_user_id'] ?? null,
                    ]
                );
                $productId = (int)$this->db->lastInsertId();
                $created = true;
            }

            $reasonLabel = ProductLoan::receivedLabel($parsed['reason'], $this->db);
            $notes = 'Préstamo recibido: ' . $reasonLabel
                . ' · De: ' . $parsed['lender']
                . ($parsed['note'] !== '' ? (' — ' . $parsed['note']) : '')
                . ' · Pendiente devolver';

            $debtCover = null;
            $movementId = $this->increase(
                $productId,
                $quantity,
                'loan_received',
                null,
                $notes,
                'in',
                $unitCost > 0 ? $unitCost : null,
                null,
                null,
                false,
                null,
                null,
                $debtCover,
                false
            );

            $this->openStockDebt(
                $productId,
                $quantity,
                $parsed['reason'],
                $parsed['note'] !== '' ? $parsed['note'] : ('Prestamista: ' . $parsed['lender']),
                null,
                null,
                ProductLoan::SOURCE_RECEIVED,
                $movementId > 0 ? $movementId : null,
                $parsed['lender']
            );
            $debtId = (int)$this->db->lastInsertId();
            if ($debtId <= 0) {
                throw new \RuntimeException('No se pudo registrar el préstamo recibido');
            }
            if ($movementId > 0) {
                $this->query(
                    "UPDATE stock_movements SET reference_id = ? WHERE id = ? AND reference_id IS NULL",
                    [$debtId, $movementId]
                );
            }

            $stockAfter = (int)$this->query("SELECT stock FROM products WHERE id = ?", [$productId])->fetchColumn();

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'movement_id' => $movementId,
                'debt_id' => $debtId,
                'product_id' => $productId,
                'stock_after' => $stockAfter,
                'created_product' => $created,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Devolvemos mercancía que nos habían prestado (sale del inventario borrowed).
     * Si ya se vendió/usó y no hay stock físico, con $allowSettleWithoutStock se cierra la deuda
     * sin exigir unidades en inventario (trazabilidad de liquidación).
     *
     * @return array{returned:int,stock_after:int,remaining:int,movement_id:int,settled_without_stock:int,physical_out:int}
     */
    public function returnLoanReceived(
        int $debtId,
        int $quantity,
        bool $allowSettleWithoutStock = false
    ): array {
        if ($debtId <= 0 || $quantity <= 0) {
            throw new \InvalidArgumentException('Cantidad de devolución inválida');
        }
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
                throw new \RuntimeException('Préstamo recibido no encontrado');
            }
            if (strtolower((string)($debt['source'] ?? '')) !== ProductLoan::SOURCE_RECEIVED) {
                throw new \RuntimeException('El registro no es un préstamo recibido');
            }
            if (!in_array((string)$debt['status'], ['open', 'partial'], true) || (int)$debt['qty_remaining'] <= 0) {
                throw new \RuntimeException('Este préstamo recibido ya está cerrado');
            }

            $productId = (int)$debt['product_id'];
            $owed = (int)$debt['qty_remaining'];
            $take = min($quantity, $owed);
            $newRemaining = $owed - $take;
            $status = $newRemaining <= 0 ? 'closed' : 'partial';

            // Candado de fila de producto: evita leer stock obsoleto frente a compras concurrentes.
            try {
                $stockNow = (int)$this->query(
                    "SELECT stock FROM products WHERE id = ? LIMIT 1 FOR UPDATE",
                    [$productId]
                )->fetchColumn();
            } catch (\Throwable $e) {
                $stockNow = (int)$this->query(
                    "SELECT stock FROM products WHERE id = ? LIMIT 1",
                    [$productId]
                )->fetchColumn();
            }
            $stockBefore = $stockNow;
            $physicalOut = min(max(0, $stockNow), $take);
            $settleQty = $take - $physicalOut;

            if ($settleQty > 0 && !$allowSettleWithoutStock) {
                throw new \RuntimeException(
                    'Stock insuficiente para devolver (' . $stockNow . ' disponible, necesita ' . $take . '). '
                    . 'Si ya vendió o usó lo prestado, marque «Cerrar sin devolución física» en el formulario. '
                    . 'Si aún tiene el producto, no debería estar en 0: revise el inventario.'
                );
            }

            $lender = trim((string)($debt['borrower'] ?? ''));
            $label = ProductLoan::receivedLabel((string)$debt['reason'], $this->db);
            $baseNotes = 'Devolución de préstamo recibido: ' . $label
                . ($lender !== '' ? (' · A: ' . $lender) : '')
                . (!empty($debt['note']) ? (' — ' . $debt['note']) : '');

            $movementId = 0;
            if ($physicalOut > 0) {
                $physNotes = $baseNotes
                    . ' · Salida física: ' . $physicalOut . ' uds'
                    . ' · stock ' . $stockBefore . '→' . ($stockBefore - $physicalOut);
                // Sin payment_mode loss: no capitaliza ni registra pérdida (off-balance).
                $movementId = $this->decrease(
                    $productId,
                    $physicalOut,
                    'loan_received_return',
                    $debtId,
                    $physNotes,
                    null,
                    null,
                    null
                );
            }
            if ($settleQty > 0) {
                $settleNotes = $baseNotes
                    . ' · Liquidación sin stock físico: ' . $settleQty
                    . ' uds (ya vendidas/usadas); se cierra la deuda con el prestamista';
                $stockForSettle = (int)$this->query(
                    "SELECT stock FROM products WHERE id = ? LIMIT 1",
                    [$productId]
                )->fetchColumn();
                $settleId = $this->addMovement(
                    $productId,
                    'adjustment',
                    $settleQty,
                    'loan_received_settle',
                    $debtId,
                    $settleNotes,
                    null,
                    0.0,
                    $stockForSettle
                );
                if ($movementId <= 0) {
                    $movementId = $settleId;
                }
            }

            $upd = $this->query(
                "UPDATE product_stock_debts
                 SET qty_remaining = ?, status = ?, updated_at = NOW()
                 WHERE id = ? AND qty_remaining = ? AND status IN ('open','partial')",
                [$newRemaining, $status, $debtId, $owed]
            );
            if ($upd->rowCount() !== 1) {
                throw new \RuntimeException('Conflicto al actualizar el préstamo recibido; reintente');
            }

            // Si ya no queda deuda recibida abierta, quitar marca Prestado.
            if ($newRemaining <= 0) {
                try {
                    $stillOpen = (int)$this->query(
                        "SELECT COALESCE(SUM(qty_remaining),0) FROM product_stock_debts
                         WHERE product_id = ? AND status IN ('open','partial') AND qty_remaining > 0
                           AND COALESCE(source,'') = ?",
                        [$productId, ProductLoan::SOURCE_RECEIVED]
                    )->fetchColumn();
                    if ($stillOpen <= 0) {
                        $this->query(
                            "UPDATE products SET ownership = 'owned'
                             WHERE id = ? AND COALESCE(ownership,'owned') = 'borrowed'",
                            [$productId]
                        );
                    }
                } catch (\Throwable $e) {
                    // no bloquear
                }
            }

            $stockAfter = (int)$this->query("SELECT stock FROM products WHERE id = ?", [$productId])->fetchColumn();

            // Defensa: si se pidió salida física, el stock DEBE haber bajado.
            if ($physicalOut > 0 && $stockAfter !== ($stockBefore - $physicalOut)) {
                throw new \RuntimeException(
                    'La devolución no actualizó el inventario correctamente '
                    . '(esperaba ' . ($stockBefore - $physicalOut) . ', quedó ' . $stockAfter . '). Reintente.'
                );
            }

            if ($ownTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'returned' => $take,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'remaining' => $newRemaining,
                'movement_id' => $movementId,
                'settled_without_stock' => $settleQty,
                'physical_out' => $physicalOut,
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
    public function listOpenReceivedLoans(int $limit = 200): array
    {
        try {
            $rows = $this->query(
                "SELECT d.*, p.name AS product_name, p.code AS product_code, p.barcode AS product_barcode,
                        p.stock AS product_stock, p.ownership
                 FROM product_stock_debts d
                 JOIN products p ON p.id = d.product_id
                 WHERE COALESCE(d.source, '') = 'loan_received'
                   AND d.status IN ('open','partial') AND d.qty_remaining > 0
                 ORDER BY d.id DESC
                 LIMIT " . max(1, min(500, $limit))
            )->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $enriched = $this->enrichDebtRow($row);
                $reason = (string)($row['reason'] ?? '');
                $enriched['reason_label'] = ProductLoan::receivedLabel($reason, $this->db);
                $out[] = $enriched;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Cambio de estado active/inactive con justificación (trazabilidad sin mover cantidad).
     */
    public function recordProductStatusChange(
        int $productId,
        string $fromStatus,
        string $toStatus,
        string $reason
    ): int {
        $reason = trim($reason);
        if ($productId <= 0) {
            return 0;
        }
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException(
                'Indique una razón (mín. 5 caracteres) para el cambio de estado del producto.'
            );
        }
        $fromStatus = strtolower(trim($fromStatus));
        $toStatus = strtolower(trim($toStatus));
        if ($fromStatus === $toStatus) {
            return 0;
        }
        $stock = (int)$this->query("SELECT stock FROM products WHERE id = ?", [$productId])->fetchColumn();
        $labelFrom = $fromStatus === 'inactive' ? 'Inactivo' : 'Activo';
        $labelTo = $toStatus === 'inactive' ? 'Inactivo' : 'Activo';
        $notes = 'Cambio de estado: ' . $labelFrom . ' → ' . $labelTo
            . ' · Justificación: ' . $reason;

        return $this->addMovement(
            $productId,
            'adjustment',
            0,
            'status_change',
            null,
            $notes,
            null,
            0.0,
            $stock,
            null,
            null,
            date('Y-m-d')
        );
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
     *   loan_received_count:int,
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
            'loan_received_count' => 0,
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
        $loanReceivedCount = 0;
        $saleDebtCount = 0;
        $lines = [];
        foreach ($debts as $d) {
            $src = (string)($d['source'] ?? 'sale');
            if ($src === ProductLoan::SOURCE) {
                $loanCount++;
            } elseif ($src === ProductLoan::SOURCE_RECEIVED) {
                $loanReceivedCount++;
            } else {
                $saleDebtCount++;
            }
            $stock = (int)($d['product_stock'] ?? 0);
            $tag = (string)($d['source_label'] ?? 'Pendiente');
            $line = '[' . $tag . '] ';
            $line .= trim((string)($d['product_code'] ?? '')) !== ''
                ? ((string)$d['product_code'] . ' · ')
                : '';
            $line .= (string)($d['product_name'] ?? 'Producto');
            $line .= ' · stock ' . $stock;
            $line .= ' · pendiente ' . (int)$d['qty_remaining'] . ' uds';
            $line .= ' · ' . (string)($d['reason_label'] ?? '');
            if (!empty($d['invoice_number'])) {
                $line .= ' (' . $d['invoice_number'] . ')';
            }
            if ($src === ProductLoan::SOURCE_RECEIVED && !empty($d['borrower'])) {
                $line .= ' · Nos prestó: ' . $d['borrower'];
            } elseif (!empty($d['borrower'])) {
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
                $lines[] = '[Stock negativo] '
                    . ($code !== '' ? $code . ' · ' : '')
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
            'loan_received_count' => $loanReceivedCount,
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
        $reason = (string)($row['reason'] ?? '');
        if ($row['source'] === ProductLoan::SOURCE_RECEIVED) {
            $row['reason_label'] = ProductLoan::receivedLabel($reason, $this->db);
            $row['source_label'] = 'Nos prestaron';
        } elseif ($row['source'] === ProductLoan::SOURCE) {
            $row['reason_label'] = ProductLoan::label($reason, $this->db);
            $row['source_label'] = 'Préstamo (salida)';
        } else {
            $row['reason_label'] = ProductLoan::label($reason, $this->db);
            if ($row['reason_label'] === $reason || $row['reason_label'] === '') {
                $row['reason_label'] = $reason !== '' ? $reason : 'Venta sin stock';
            }
            $row['source_label'] = 'Venta sin stock';
        }
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
            $label = (string)($d['reason_label'] ?? ProductLoan::label((string)($d['reason'] ?? ''), $this->db));
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
     * Mensaje amigable para UI tras una entrada/ajuste de stock.
     *
     * @param array{covered:int,remaining:int,details:list<array<string,mixed>>}|null $cover
     */
    public function formatDebtCoverAlert(
        int $qtyIn,
        ?array $cover,
        ?int $stockBefore = null,
        ?int $stockAfter = null
    ): string {
        if ($stockBefore !== null && $stockAfter !== null) {
            $base = "Stock: {$stockBefore} → {$stockAfter}";
            if ($qtyIn > 0 && $stockAfter !== $stockBefore) {
                $delta = $stockAfter - $stockBefore;
                $sign = $delta > 0 ? '+' : '';
                $base .= " ({$sign}{$delta} uds)";
            }
        } else {
            $base = "Stock aumentado en {$qtyIn} unidades";
        }
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
     * Fecha/hora visible en trazabilidad.
     * movement_date es DATE (sin hora); si se usa solo, siempre sale 00:00.
     * Se combina la fecha operativa con la hora real de created_at.
     */
    public static function formatMovementDisplayAt(array $m): string
    {
        $created = trim((string)($m['created_at'] ?? ''));
        $mov = trim((string)($m['movement_date'] ?? ''));
        if ($mov === '' || $mov === '0000-00-00' || str_starts_with($mov, '0000-00-00')) {
            return $created !== '' ? date('d/m/Y H:i', strtotime($created)) : '—';
        }

        // Si movement_date ya trae hora (DATETIME legado), usarla.
        if (preg_match('/\d{2}:\d{2}/', $mov)) {
            return date('d/m/Y H:i', strtotime($mov));
        }

        $datePart = date('Y-m-d', strtotime($mov));
        if ($created !== '') {
            $createdDate = date('Y-m-d', strtotime($created));
            if ($createdDate === $datePart) {
                return date('d/m/Y H:i', strtotime($created));
            }
            return date('d/m/Y', strtotime($datePart)) . ' ' . date('H:i', strtotime($created));
        }

        return date('d/m/Y H:i', strtotime($datePart . ' 00:00:00'));
    }

    /**
     * Enriquece movimientos de compra con el IVA de la línea de la OC y el tipo de
     * documento de la factura: el kardex guarda el costo base y el IVA vive en la OC.
     * Agrega purchase_tax_known, purchase_tax_unit, purchase_tax_rate y purchase_vat_in_cost.
     *
     * @param list<array<string,mixed>> $movements
     * @return list<array<string,mixed>>
     */
    public static function attachPurchaseTaxInfo(\PDO $db, array $movements): array
    {
        $orderIds = [];
        foreach ($movements as $m) {
            if (in_array((string)($m['reference_type'] ?? ''), ['purchase_order', 'purchase'], true)
                && (int)($m['reference_id'] ?? 0) > 0
            ) {
                $orderIds[(int)$m['reference_id']] = true;
            }
        }
        if ($orderIds === []) {
            return $movements;
        }

        $items = [];
        $kinds = [];
        foreach (array_chunk(array_keys($orderIds), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $st = $db->prepare(
                    "SELECT purchase_order_id, product_id, quantity, unit_cost, tax_rate, tax_amount
                     FROM purchase_order_items
                     WHERE purchase_order_id IN ($ph)
                     ORDER BY id"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $it) {
                    $items[(int)$it['purchase_order_id'] . ':' . (int)$it['product_id']][] = $it;
                }
            } catch (\Throwable $e) {
                return $movements;
            }
            try {
                $st = $db->prepare(
                    "SELECT purchase_order_id, document_kind
                     FROM purchase_invoice_imports
                     WHERE purchase_order_id IN ($ph)
                     ORDER BY id"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $imp) {
                    $kinds[(int)$imp['purchase_order_id']] = (string)($imp['document_kind'] ?? '');
                }
            } catch (\Throwable $e) {
                // Sin tabla de importación: solo OC manuales.
            }
        }

        foreach ($movements as &$m) {
            $orderId = (int)($m['reference_id'] ?? 0);
            if ($orderId <= 0 || !in_array((string)($m['reference_type'] ?? ''), ['purchase_order', 'purchase'], true)) {
                continue;
            }
            $candidates = $items[$orderId . ':' . (int)($m['product_id'] ?? 0)] ?? [];
            if ($candidates === []) {
                continue;
            }
            // Misma OC puede traer línea pagada y bonificada del mismo producto.
            $movCost = round((float)($m['unit_cost'] ?? 0), 2);
            $item = $candidates[0];
            foreach ($candidates as $cand) {
                if (abs(round((float)$cand['unit_cost'], 2) - $movCost) < 0.01) {
                    $item = $cand;
                    break;
                }
            }
            $qty = (float)($item['quantity'] ?? 0);
            $cost = (float)($item['unit_cost'] ?? 0);
            $rate = (float)($item['tax_rate'] ?? 0);
            $taxAmount = (float)($item['tax_amount'] ?? 0);
            $taxUnit = 0.0;
            if ($taxAmount > 0.009 && $qty > 0) {
                $taxUnit = round($taxAmount / $qty, 2);
            } elseif ($rate > 0.009 && $cost > 0) {
                $taxUnit = round($cost * $rate / 100, 2);
            }
            $m['purchase_tax_known'] = true;
            $m['purchase_tax_unit'] = $taxUnit;
            $m['purchase_tax_rate'] = $rate;
            $m['purchase_vat_in_cost'] = strtolower(trim($kinds[$orderId] ?? '')) === 'electronic';
        }
        unset($m);

        return $movements;
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
        // Préstamos primero: loan_received_settle usa type=adjustment y no debe caer en "Ajuste genérico".
        if ($ref === 'loan') {
            if (preg_match('/sustituci[oó]n|cambio:\s*devolvi/iu', $notes) === 1) {
                $lines[] = 'Salida por préstamo (luego cambiado por otra referencia)';
            } else {
                $lines[] = 'Salida por préstamo de producto';
            }
        } elseif ($ref === 'loan_return') {
            if (preg_match('/sustituci[oó]n|cambio de referencia/iu', $notes) === 1) {
                $lines[] = 'Entrada por cambio de referencia (cierra préstamo de otro SKU)';
            } else {
                $lines[] = 'Entrada por devolución de préstamo';
            }
        } elseif ($ref === 'loan_received') {
            $lines[] = 'Entrada por préstamo recibido (nos prestaron)';
        } elseif ($ref === 'loan_received_return') {
            $lines[] = 'Salida por devolución de préstamo recibido';
        } elseif ($ref === 'loan_received_settle') {
            $lines[] = 'Cierre de préstamo recibido sin stock (ya vendido/usado)';
        } elseif ($isNewProduct) {
            $lines[] = 'Producto nuevo · Inventario inicial';
        } elseif ($ref === 'status_change') {
            $lines[] = 'Cambio de estado del producto';
        } elseif ($ref === 'price_change') {
            $lines[] = 'Cambio de precio (sin movimiento de cantidad)';
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
        } elseif ($ref === 'sale_credit_note') {
            $lines[] = 'Reingreso por nota de crédito de venta (devolución cliente)';
        }

        $dir = $type === 'out' ? 'Salida' : ($type === 'in' ? 'Entrada' : 'Ajuste');
        // Settle: no cambia stock; mostrar qty liquidada sin fingir Δ de inventario.
        if ($ref === 'loan_received_settle') {
            $qtyLine = 'Liquidación: ' . abs($qty) . ' und. (sin cambio de stock)';
            if ($stockAfter !== null) {
                $qtyLine .= ' · Stock ' . $stockAfter;
            }
            $lines[] = $qtyLine;
        } elseif ($qty !== 0 || $type === 'adjustment') {
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

        $money = static fn(float $v): string => '$ ' . number_format($v, 2, ',', '.');
        $costBits = [];
        $taxLine = '';
        $taxKnown = !empty($m['purchase_tax_known']) && $unitCost !== null && $unitCost > 0;
        $taxUnit = round((float)($m['purchase_tax_unit'] ?? 0), 2);
        if ($taxKnown && $taxUnit > 0.009) {
            $withVat = round($unitCost + $taxUnit, 2);
            $rate = (float)($m['purchase_tax_rate'] ?? 0);
            $rateLabel = $rate > 0.009
                ? ' ' . rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . '%'
                : '';
            $costBits[] = 'Costo u. con IVA: ' . $money($withVat);
            if ($qty !== 0) {
                $costBits[] = 'Total con IVA: ' . $money(round($withVat * abs($qty), 2));
            }
            $taxLine = 'Base sin IVA: ' . $money($unitCost) . ' + IVA' . $rateLabel . ': ' . $money($taxUnit) . ' por und.';
        } elseif ($taxKnown && !empty($m['purchase_vat_in_cost'])) {
            $costBits[] = 'Costo u. con IVA: ' . $money($unitCost) . ' (IVA incluido, F. Electrónica)';
            if ($totalCost !== null && $totalCost > 0 && $qty !== 0) {
                $costBits[] = 'Total con IVA: ' . $money($totalCost);
            }
        } elseif (!empty($m['purchase_tax_known']) && $unitCost !== null && $unitCost <= 0.009 && $type === 'in') {
            $costBits[] = 'Bonificado: costo $ 0 (se conserva el costo del inventario)';
        } else {
            if ($unitCost !== null && $unitCost > 0) {
                $costBits[] = 'Costo u.' . ($taxKnown ? ' (sin IVA)' : '') . ': ' . $money($unitCost);
            }
            if ($totalCost !== null && $totalCost > 0 && $qty !== 0) {
                $costBits[] = 'Total: ' . $money($totalCost);
            }
        }
        if ($costBits !== []) {
            $lines[] = implode(' · ', $costBits);
        }
        if ($taxLine !== '') {
            $lines[] = $taxLine;
        }
        if ($payment !== '') {
            $payMap = [
                'equity' => 'capital/aporte',
                'loss' => 'pérdida',
                'cash' => 'caja',
                'credit' => 'crédito',
                'payable' => 'por pagar al proveedor',
            ];
            $lines[] = 'Modo: ' . ($payMap[$payment] ?? $payment);
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

        // Justificación explícita (ajustes / salidas manuales).
        // No recortar notes de préstamos: ahí "Justificación: pendiente devolver" borraba prestamista/motivo.
        if (preg_match('/Justificaci[oó]n:\s*(.+?)(?=\s*·\s*|$)/iu', $notes, $jm)
            && !preg_match('/^(Pr[eé]stamo|Devoluci[oó]n\s+pr[eé]stamo)/iu', $notes)
        ) {
            $j = trim((string)($jm[1] ?? ''));
            if ($j !== '' && !preg_match('/^pendiente\s+devolver$/iu', $j)) {
                return 'Justificación: ' . $j;
            }
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
        ?string $movementDate = null,
        ?array &$debtCoverOut = null
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
            $debtCoverOut = ['covered' => 0, 'remaining' => 0, 'details' => []];
            return 0;
        }

        $cost = $unitCost !== null
            ? round(max(0, $unitCost), 2)
            : round((float)($product['purchase_price'] ?? 0), 2);

        $this->query("UPDATE products SET stock = ? WHERE id = ?", [$newStock, $productId]);

        $movementId = $this->addMovement(
            $productId,
            $diff > 0 ? 'in' : 'out',
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
            $debtCoverOut = $this->coverOpenDebts($productId, $diff);
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
        } else {
            $debtCoverOut = ['covered' => 0, 'remaining' => 0, 'details' => []];
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
        // Permitir stock negativo (oversell/préstamos): no forzar a 0.
        $newStock = $oldStock + $delta;
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
        // Permitir stock negativo: no truncar con max(0) (corrompe kardex tras oversell).
        $newStock = $oldStock - $qty;
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
               AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'sale_credit_note', 'purchase_order', 'purchase_credit_note', 'loan', 'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle', 'status_change', 'consignment_in', 'consignment_return')
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
               AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'sale_credit_note', 'purchase_order', 'purchase_credit_note', 'loan', 'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle', 'status_change', 'consignment_in', 'consignment_return')
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
    /**
     * Clave de agrupación: un movimiento operativo (OC, venta, etc.) o una línea suelta.
     */
    public static function movementGroupKey(array $m): string
    {
        $rt = trim((string)($m['reference_type'] ?? ''));
        $rid = (int)($m['reference_id'] ?? 0);
        if ($rt !== '' && $rid > 0) {
            return $rt . '#' . $rid;
        }
        return 'm#' . (int)($m['id'] ?? 0);
    }

    /**
     * Detecta alta de producto (opening/notes) o producto creado junto al movimiento (p. ej. desde factura).
     */
    public static function isNewProductMovement(array $m): bool
    {
        $ref = (string)($m['reference_type'] ?? '');
        $notes = trim((string)($m['notes'] ?? ''));
        $type = (string)($m['type'] ?? '');

        if (in_array($ref, [
                'adjustment', 'csv_export', 'sale', 'inventory_exit', 'loan',
                'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle',
                'purchase_credit_note',
            ], true)
            || $type === 'out'
        ) {
            return false;
        }
        if (preg_match('/ajuste\s+de\s+stock|producto\s+existente/iu', $notes) === 1) {
            return false;
        }

        $byNotes = in_array($ref, ['opening'], true)
            || preg_match('/producto\s+nuevo|inventario\s+inicial|stock\s+inicial/iu', $notes) === 1;
        if ($ref === 'csv_import') {
            $byNotes = preg_match('/producto\s+nuevo|stock\s+inicial|inventario\s+inicial/iu', $notes) === 1
                && preg_match('/producto\s+existente|ajuste/iu', $notes) !== 1;
        }
        if ($byNotes) {
            return true;
        }

        // Alta desde compra/factura: producto creado minutos antes del ingreso.
        $pc = trim((string)($m['product_created_at'] ?? ''));
        $mc = trim((string)($m['created_at'] ?? ''));
        if ($pc !== '' && $mc !== '' && $type === 'in') {
            $pt = strtotime($pc);
            $mt = strtotime($mc);
            if ($pt && $mt && $pt <= $mt && ($mt - $pt) <= 600) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agrupa filas de stock_movements por operación (reference_type + reference_id).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function buildMovementGroups(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $m) {
            $key = self::movementGroupKey($m);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = $m;
        }

        $groups = [];
        foreach ($buckets as $key => $lines) {
            usort($lines, static function ($a, $b) {
                $na = (string)($a['product_name'] ?? '');
                $nb = (string)($b['product_name'] ?? '');
                $cmp = strcasecmp($na, $nb);
                return $cmp !== 0 ? $cmp : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            });

            $head = $lines[0];
            // Cabecera: el movimiento más reciente del grupo.
            foreach ($lines as $line) {
                $ca = (string)($line['created_at'] ?? '');
                $cb = (string)($head['created_at'] ?? '');
                if ($ca !== '' && ($cb === '' || strcmp($ca, $cb) > 0)) {
                    $head = $line;
                }
            }

            $qtyTotal = 0;
            $costTotal = 0.0;
            $newCount = 0;
            $types = [];
            foreach ($lines as $line) {
                $qtyTotal += abs((int)($line['quantity'] ?? 0));
                $costTotal += (float)($line['total_cost'] ?? 0);
                if (self::isNewProductMovement($line)) {
                    $newCount++;
                }
                $t = (string)($line['type'] ?? '');
                if ($t !== '') {
                    $types[$t] = ($types[$t] ?? 0) + 1;
                }
            }
            arsort($types);
            $dominantType = array_key_first($types) ?: (string)($head['type'] ?? '');

            $groups[] = [
                'key' => $key,
                'reference_type' => (string)($head['reference_type'] ?? ''),
                'reference_id' => (int)($head['reference_id'] ?? 0),
                'reference_code' => (string)($head['purchase_order_number'] ?? $head['sale_document_number'] ?? ''),
                'purchase_order_status' => (string)($head['purchase_order_status'] ?? ''),
                'type' => $dominantType,
                'display_at' => self::formatMovementDisplayAt($head),
                'source_channel' => (string)($head['source_channel'] ?? 'manual'),
                'user_name' => (string)($head['user_name'] ?? '—'),
                'notes' => (string)($head['notes'] ?? ''),
                'lines_count' => count($lines),
                'new_products_count' => $newCount,
                'qty_total' => $qtyTotal,
                'cost_total' => round($costTotal, 2),
                'lines' => $lines,
            ];
        }

        usort($groups, static function ($a, $b) {
            $la = $a['lines'][0] ?? [];
            $lb = $b['lines'][0] ?? [];
            // Usar el head más reciente ya calculado vía display / created
            $ca = '';
            $cb = '';
            foreach ($a['lines'] as $line) {
                $t = (string)($line['created_at'] ?? '');
                if ($t !== '' && strcmp($t, $ca) > 0) {
                    $ca = $t;
                }
            }
            foreach ($b['lines'] as $line) {
                $t = (string)($line['created_at'] ?? '');
                if ($t !== '' && strcmp($t, $cb) > 0) {
                    $cb = $t;
                }
            }
            if ($ca !== $cb) {
                return strcmp($cb, $ca);
            }
            return strcmp((string)$b['key'], (string)$a['key']);
        });

        return $groups;
    }

    /**
     * Lista paginada por movimiento (grupo), no por producto/línea.
     *
     * @return array{groups: list<array>, total: int, line_total: int, truncated: bool}
     */
    public function listMovementGroups(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        // Cargar un ventana amplia de líneas, agrupar y paginar grupos.
        // Evita partir una misma OC entre páginas.
        $scan = $this->listMovements($filters, 2500, 0);
        $allGroups = self::buildMovementGroups($scan['rows']);
        $totalGroups = count($allGroups);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        if ($offset >= $totalGroups && $totalGroups > 0) {
            $offset = 0;
        }

        $pageGroups = array_slice($allGroups, $offset, $limit);
        $flat = [];
        foreach ($pageGroups as $gi => $group) {
            foreach ($group['lines'] as $li => $line) {
                $flat[$gi . ':' . $li] = $line;
            }
        }
        $enriched = array_combine(
            array_keys($flat),
            self::attachPurchaseTaxInfo($this->db, array_values($flat))
        ) ?: [];
        foreach ($enriched as $key => $line) {
            [$gi, $li] = array_map('intval', explode(':', (string)$key));
            $pageGroups[$gi]['lines'][$li] = $line;
        }

        return [
            'groups' => $pageGroups,
            'total' => $totalGroups,
            'line_total' => (int)$scan['total'],
            'truncated' => (int)$scan['total'] > count($scan['rows']),
        ];
    }

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
            $search = \SoftNova\Core\multi_term_like_clause((string)$filters['q'], [
                'p.name',
                'p.code',
                "COALESCE(p.barcode,'')",
                'sm.notes',
                'sm.reference_type',
                "COALESCE(po.order_number,'')",
                "COALESCE(s.invoice_number,'')",
            ]);
            $where[] = $search['sql'];
            foreach ($search['params'] as $p) {
                $params[] = $p;
            }
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

        $fromJoins = "FROM stock_movements sm
             LEFT JOIN products p ON p.id = sm.product_id
             LEFT JOIN purchase_orders po ON po.id = sm.reference_id
                  AND sm.reference_type IN ('purchase_order', 'purchase', 'purchase_cancel')
             LEFT JOIN sales s ON s.id = sm.reference_id
                  AND sm.reference_type IN ('sale', 'return')";

        $total = (int)$this->query(
            "SELECT COUNT(*) {$fromJoins}
             WHERE {$whereSql}",
            $params
        )->fetchColumn();

        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        if ($offset >= $total && $total > 0) {
            $offset = 0;
        }
        $rows = $this->query(
            "SELECT sm.*, p.name product_name, p.code product_code, p.barcode product_barcode,
                    p.source_channel, p.location product_location,
                    p.created_at AS product_created_at,
                    c.name category_name, u.name user_name,
                    po.order_number AS purchase_order_number,
                    po.status AS purchase_order_status,
                    s.invoice_number AS sale_document_number
             {$fromJoins}
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = sm.user_id
             WHERE {$whereSql}
             ORDER BY COALESCE(NULLIF(sm.movement_date, '0000-00-00'), DATE(sm.created_at)) DESC,
                      sm.created_at DESC, sm.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }
}
