<?php

namespace SoftNova\Services;

/**
 * Notas de crédito de venta (devoluciones de cliente).
 * Emite documento NCV, ajusta CxC/caja y opcionalmente reingresa stock.
 */
class SalesCreditNoteService
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
        static $done = [];
        $oid = spl_object_id($this->db);
        if (isset($done[$oid])) {
            return;
        }
        $done[$oid] = true;

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS sale_credit_notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                credit_number VARCHAR(40) NOT NULL UNIQUE,
                sale_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NULL,
                credit_date DATE NOT NULL,
                reason VARCHAR(255) NULL,
                reason_code VARCHAR(40) NULL,
                refund_method VARCHAR(20) NOT NULL DEFAULT 'auto',
                affects_stock TINYINT(1) NOT NULL DEFAULT 1,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                accounting_entry_id BIGINT UNSIGNED NULL,
                status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_scn_sale (sale_id),
                KEY idx_scn_customer (customer_id),
                KEY idx_scn_date (credit_date),
                KEY idx_scn_entry (accounting_entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS sale_credit_note_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                credit_note_id INT UNSIGNED NOT NULL,
                sale_item_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_scni_note (credit_note_id),
                KEY idx_scni_sale_item (sale_item_id),
                KEY idx_scni_product (product_id),
                CONSTRAINT fk_scni_note FOREIGN KEY (credit_note_id)
                    REFERENCES sale_credit_notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function nextCreditNumber(string $creditDate): string
    {
        $ym = date('Ym', strtotime($creditDate));
        $prefix = 'NCV-' . $ym . '-';
        $last = $this->query(
            "SELECT credit_number FROM sale_credit_notes
             WHERE credit_number LIKE ?
             ORDER BY id DESC LIMIT 1",
            [$prefix . '%']
        )->fetchColumn();
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int)$m[1] + 1;
        }
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSaleForCredit(int $saleId): ?array
    {
        if ($saleId <= 0) {
            return null;
        }
        $sale = $this->query(
            "SELECT s.*, c.name AS customer_name, c.document_number AS customer_document,
                    COALESCE(p.paid_amount, 0) AS paid_amount
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) AS paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE s.id = ?",
            [$saleId]
        )->fetch();
        if (!$sale) {
            return null;
        }

        $sale['items'] = $this->query(
            "SELECT si.*,
                    COALESCE((
                        SELECT SUM(ci.quantity)
                        FROM sale_credit_note_items ci
                        INNER JOIN sale_credit_notes cn ON cn.id = ci.credit_note_id
                        WHERE ci.sale_item_id = si.id AND cn.status = 'posted'
                    ), 0) AS credited_qty
             FROM sale_items si
             WHERE si.sale_id = ?
             ORDER BY si.id",
            [$saleId]
        )->fetchAll();

        $sale['credit_notes'] = $this->query(
            "SELECT cn.*, e.entry_number AS accounting_entry_number
             FROM sale_credit_notes cn
             LEFT JOIN accounting_entries e ON e.id = cn.accounting_entry_id
             WHERE cn.sale_id = ?
             ORDER BY cn.id DESC",
            [$saleId]
        )->fetchAll();

        $sale['credited_total'] = $this->postedCreditTotal($saleId);

        return $sale;
    }

    /** Suma de NC posted de una venta (0 si la tabla aún no existe). */
    public function postedCreditTotal(int $saleId): float
    {
        if ($saleId <= 0) {
            return 0.0;
        }
        try {
            return round((float)$this->query(
                "SELECT COALESCE(SUM(total), 0) FROM sale_credit_notes
                 WHERE sale_id = ? AND status = 'posted'",
                [$saleId]
            )->fetchColumn(), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Saldos de venta considerando NC posted.
     * balance = max(0, total − NC − pagos).
     *
     * @return array{credited:float,paid:float,net_total:float,balance:float,sale_total:float}
     */
    public function saleBalances(int $saleId): array
    {
        $sale = $this->query(
            "SELECT s.id, s.total,
                    COALESCE(p.paid_amount, 0) AS paid_amount
             FROM sales s
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) AS paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE s.id = ?",
            [$saleId]
        )->fetch();
        if (!$sale) {
            return [
                'credited' => 0.0,
                'paid' => 0.0,
                'net_total' => 0.0,
                'balance' => 0.0,
                'sale_total' => 0.0,
            ];
        }
        $saleTotal = round((float)$sale['total'], 2);
        $paid = round((float)$sale['paid_amount'], 2);
        $credited = $this->postedCreditTotal($saleId);
        $netTotal = max(0.0, round($saleTotal - $credited, 2));
        $balance = max(0.0, round($netTotal - $paid, 2));

        return [
            'credited' => $credited,
            'paid' => $paid,
            'net_total' => $netTotal,
            'balance' => $balance,
            'sale_total' => $saleTotal,
        ];
    }

    /**
     * @param list<array{sale_item_id:int,quantity:int}> $lines
     * @return array{credit_note_id:int,credit_number:string,total:float,allocation:array<string,float>}
     */
    public function create(
        int $saleId,
        array $lines,
        string $reason = '',
        string $reasonCode = '',
        bool $affectsStock = true,
        string $creditDate = '',
        string $refundMethod = 'auto'
    ): array {
        $this->ensureSchema();

        $reasonCode = substr(trim($reasonCode), 0, 40);
        // Vencido/roto no debe volver a bodega vendible (evita NC históricas con stock=1 por error).
        if (in_array($reasonCode, ['expired', 'broken'], true)) {
            $affectsStock = false;
        }

        $stock = new StockService($this->db);
        $creditDate = $stock->normalizeDate($creditDate !== '' ? $creditDate : date('Y-m-d'));
        $refundMethod = in_array($refundMethod, ['auto', 'cash', 'transfer', 'receivable'], true)
            ? $refundMethod
            : 'auto';
        $reason = trim($reason);

        // DDL de consignación fuera de la TX (MySQL CREATE/ALTER = COMMIT implícito).
        try {
            (new ConsignmentService($this->db, $stock))->ensureSchema();
        } catch (\Throwable $e) {
            error_log('NC consign schema: ' . $e->getMessage());
        }

        $this->db->beginTransaction();
        try {
            try {
                $saleRow = $this->query(
                    "SELECT s.*, c.name AS customer_name
                     FROM sales s
                     LEFT JOIN customers c ON c.id = s.customer_id
                     WHERE s.id = ?
                     FOR UPDATE",
                    [$saleId]
                )->fetch();
            } catch (\Throwable $e) {
                $saleRow = $this->query(
                    "SELECT s.*, c.name AS customer_name
                     FROM sales s
                     LEFT JOIN customers c ON c.id = s.customer_id
                     WHERE s.id = ?",
                    [$saleId]
                )->fetch();
            }

            if (!$saleRow) {
                throw new \RuntimeException('Venta no encontrada');
            }
            if (($saleRow['status'] ?? '') === 'cancelled') {
                throw new \RuntimeException('No se puede emitir NC sobre una venta cancelada');
            }

            $items = [];
            try {
                $items = $this->query(
                    "SELECT si.*,
                            COALESCE(si.is_consignment, 0) AS is_consignment,
                            COALESCE(p.ownership, 'owned') AS ownership,
                            COALESCE((
                                SELECT SUM(ci.quantity)
                                FROM sale_credit_note_items ci
                                INNER JOIN sale_credit_notes cn ON cn.id = ci.credit_note_id
                                WHERE ci.sale_item_id = si.id AND cn.status = 'posted'
                            ), 0) AS credited_qty
                     FROM sale_items si
                     LEFT JOIN products p ON p.id = si.product_id
                     WHERE si.sale_id = ?
                     ORDER BY si.id
                     FOR UPDATE",
                    [$saleId]
                )->fetchAll();
            } catch (\Throwable $e) {
                $items = $this->query(
                    "SELECT si.*,
                            COALESCE((
                                SELECT SUM(ci.quantity)
                                FROM sale_credit_note_items ci
                                INNER JOIN sale_credit_notes cn ON cn.id = ci.credit_note_id
                                WHERE ci.sale_item_id = si.id AND cn.status = 'posted'
                            ), 0) AS credited_qty
                     FROM sale_items si
                     WHERE si.sale_id = ?
                     ORDER BY si.id",
                    [$saleId]
                )->fetchAll();
            }

            $itemsById = [];
            foreach ($items as $item) {
                $itemsById[(int)$item['id']] = $item;
            }

            $normalized = [];
            $subtotal = 0.0;
            $tax = 0.0;
            $saleSubtotal = round((float)($saleRow['subtotal'] ?? 0), 2);
            $saleDiscount = round((float)($saleRow['discount'] ?? 0), 2);
            $saleTaxHeader = round((float)($saleRow['tax'] ?? 0), 2);
            $discRatio = $saleSubtotal > 0.009 ? ($saleDiscount / $saleSubtotal) : 0.0;
            $saleTaxable = max(0.0, round($saleSubtotal - $saleDiscount, 2));

            foreach ($lines as $line) {
                $itemId = (int)($line['sale_item_id'] ?? 0);
                $qty = (int)($line['quantity'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }
                $item = $itemsById[$itemId] ?? null;
                if (!$item) {
                    throw new \InvalidArgumentException('Línea de venta inválida en la nota de crédito');
                }
                $sold = (int)($item['quantity'] ?? 0);
                $already = (int)($item['credited_qty'] ?? 0);
                $available = $sold - $already;
                if ($qty > $available) {
                    throw new \InvalidArgumentException(
                        'Cantidad a devolver supera lo disponible en «' . ($item['product_name'] ?? '') . '»'
                        . ' (máx. ' . max(0, $available) . ')'
                    );
                }
                $unitPrice = round((float)($item['unit_price'] ?? 0), 2);
                $unitCost = round((float)($item['unit_cost'] ?? 0), 2);
                $rate = round((float)($item['tax_rate'] ?? 0), 2);
                $grossLineSub = round($qty * $unitPrice, 2);
                // Prorratear descuento del documento para no sobre-acreditar vs la venta neta.
                $itemDiscStored = round((float)($item['discount_amount'] ?? 0), 2);
                if ($itemDiscStored > 0.009 && $sold > 0) {
                    $lineDisc = round($itemDiscStored * ($qty / $sold), 2);
                } else {
                    $lineDisc = round($grossLineSub * $discRatio, 2);
                }
                if ($lineDisc > $grossLineSub) {
                    $lineDisc = $grossLineSub;
                }
                $lineSub = max(0.0, round($grossLineSub - $lineDisc, 2));
                $itemTax = round((float)($item['tax_amount'] ?? 0), 2);
                $itemTaxableStored = round((float)($item['taxable'] ?? 0), 2);
                if ($itemTax > 0.009 && $sold > 0) {
                    $lineTax = round($itemTax * ($qty / $sold), 2);
                } elseif ($rate > 0.009) {
                    $lineTax = round($lineSub * ($rate / 100), 2);
                } elseif ($saleTaxHeader > 0.009 && $saleTaxable > 0.009) {
                    // Histórico sin tax_amount: repartir IVA del encabezado por base neta.
                    $lineTax = round($saleTaxHeader * ($lineSub / $saleTaxable), 2);
                    if ($rate <= 0.009 && $lineSub > 0.009) {
                        $rate = round(($lineTax / $lineSub) * 100, 2);
                    }
                } else {
                    $lineTax = 0.0;
                }
                // Si hay taxable guardado, preferir proporción para el IVA.
                if ($itemTaxableStored > 0.009 && $itemTax > 0.009 && $sold > 0 && $lineSub <= 0.009) {
                    $lineSub = round($itemTaxableStored * ($qty / $sold), 2);
                }
                $normalized[] = [
                    'sale_item_id' => $itemId,
                    'product_id' => !empty($item['product_id']) ? (int)$item['product_id'] : null,
                    'product_name' => (string)$item['product_name'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'tax_rate' => $rate,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSub,
                    'line_total' => round($lineSub + $lineTax, 2),
                    'is_consignment' => !empty($item['is_consignment'])
                        || (($item['ownership'] ?? '') === 'consignment'),
                ];
                $subtotal += $lineSub;
                $tax += $lineTax;
            }

            if ($normalized === []) {
                throw new \InvalidArgumentException('Indique al menos un producto y cantidad para la nota de crédito');
            }

            // Ajuste de redondeo del IVA al último ítem.
            $subtotal = round($subtotal, 2);
            $tax = round($tax, 2);
            $total = round($subtotal + $tax, 2);
            $taxSumLines = 0.0;
            foreach ($normalized as $row) {
                $taxSumLines += $row['tax_amount'];
            }
            $taxDiff = round($tax - $taxSumLines, 2);
            if (abs($taxDiff) >= 0.01 && $normalized !== []) {
                $last = count($normalized) - 1;
                $normalized[$last]['tax_amount'] = round($normalized[$last]['tax_amount'] + $taxDiff, 2);
                $normalized[$last]['line_total'] = round(
                    $normalized[$last]['subtotal'] + $normalized[$last]['tax_amount'],
                    2
                );
            }

            $creditNoteId = 0;
            $creditNumber = '';
            $attempts = 0;
            while ($attempts < 5) {
                $attempts++;
                $creditNumber = $this->nextCreditNumber($creditDate);
                try {
                    $this->query(
                        "INSERT INTO sale_credit_notes
                            (credit_number, sale_id, customer_id, credit_date, reason, reason_code,
                             refund_method, affects_stock, subtotal, tax, total, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $creditNumber,
                            $saleId,
                            $saleRow['customer_id'] ?: null,
                            $creditDate,
                            $reason !== '' ? $reason : null,
                            $reasonCode !== '' ? $reasonCode : null,
                            $refundMethod,
                            $affectsStock ? 1 : 0,
                            $subtotal,
                            $tax,
                            $total,
                            $_SESSION['tenant_user_id'] ?? null,
                        ]
                    );
                    $creditNoteId = (int)$this->db->lastInsertId();
                    break;
                } catch (\PDOException $e) {
                    // Colisión de número único: reintentar con siguiente secuencia.
                    if ($attempts >= 5 || !str_contains(strtolower($e->getMessage()), 'duplicate')) {
                        throw $e;
                    }
                }
            }
            if ($creditNoteId <= 0) {
                throw new \RuntimeException('No se pudo asignar número de nota de crédito');
            }

            foreach ($normalized as $row) {
                $this->query(
                    "INSERT INTO sale_credit_note_items
                        (credit_note_id, sale_item_id, product_id, product_name,
                         quantity, unit_price, unit_cost, tax_rate, tax_amount, subtotal, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $creditNoteId,
                        $row['sale_item_id'],
                        $row['product_id'],
                        $row['product_name'],
                        $row['quantity'],
                        $row['unit_price'],
                        $row['unit_cost'],
                        $row['tax_rate'],
                        $row['tax_amount'],
                        $row['subtotal'],
                        $row['line_total'],
                    ]
                );

                if ($affectsStock && !empty($row['product_id'])) {
                    $debtCover = null;
                    $stock->increase(
                        (int)$row['product_id'],
                        (int)$row['quantity'],
                        'sale_credit_note',
                        $creditNoteId,
                        'NC venta ' . $creditNumber . ' / ' . ($saleRow['invoice_number'] ?? ''),
                        'in',
                        (float)$row['unit_cost'] > 0 ? (float)$row['unit_cost'] : null,
                        null,
                        null,
                        false,
                        $creditDate,
                        null,
                        $debtCover,
                        false // igual que devolución por anulación: no cubre sobreventas ajenas
                    );
                }
                if (!empty($row['is_consignment'])) {
                    (new ConsignmentService($this->db, $stock))->reduceDebtForCreditNote(
                        (int)$row['sale_item_id'],
                        (int)$row['quantity']
                    );
                }
            }

            // H1: bloquear NC con reembolso en efectivo si no hay caja abierta (antes del commit).
            $allocationPreview = $this->allocateRefund($creditNoteId);
            if (round((float)($allocationPreview['cash'] ?? 0), 2) > 0.009) {
                (new CashService($this->db))->assertOpenSessionForCash(
                    'emitir una nota de crédito con reembolso en efectivo'
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->syncReceivable($saleId);
        $allocation = $this->allocateRefund($creditNoteId);

        return [
            'credit_note_id' => $creditNoteId,
            'credit_number' => $creditNumber,
            'total' => $total,
            'allocation' => $allocation,
        ];
    }

    /**
     * Reparte el total de la NC: primero reduce CxC pendiente; el resto se reembolsa
     * (efectivo cobrado primero, luego transferencia/banco).
     *
     * @return array{receivable:float,cash:float,transfer:float,method:string}
     */
    public function allocateRefund(int $creditNoteId): array
    {
        $note = $this->getCreditNote($creditNoteId);
        if (!$note || ($note['status'] ?? '') !== 'posted') {
            return ['receivable' => 0.0, 'cash' => 0.0, 'transfer' => 0.0, 'method' => 'auto'];
        }

        $ncTotal = round((float)$note['total'], 2);
        $saleId = (int)($note['sale_id'] ?? 0);
        $saleTotal = round((float)($note['sale_total'] ?? 0), 2);
        $paid = round((float)($note['paid_amount'] ?? 0), 2);
        $forced = (string)($note['refund_method'] ?? 'auto');

        $otherCredited = (float)$this->query(
            "SELECT COALESCE(SUM(total), 0) FROM sale_credit_notes
             WHERE sale_id = ? AND status = 'posted' AND id <> ?",
            [$saleId, $creditNoteId]
        )->fetchColumn();

        $openBefore = max(0.0, round($saleTotal - $otherCredited - $paid, 2));
        $toReceivable = min($ncTotal, $openBefore);
        $toRefund = max(0.0, round($ncTotal - $toReceivable, 2));

        $cashPaid = $this->cashPaidOnSale($saleId, $note);
        $cashAlreadyRefunded = $this->cashAlreadyRefundedOnSale($saleId, $creditNoteId);
        $cashAvailable = max(0.0, round($cashPaid - $cashAlreadyRefunded, 2));

        if ($forced === 'receivable') {
            return [
                'receivable' => $ncTotal,
                'cash' => 0.0,
                'transfer' => 0.0,
                'method' => 'receivable',
            ];
        }
        if ($forced === 'cash') {
            // No egresar del cajón más efectivo del cobrado en la venta.
            $toCash = min($ncTotal, $cashAvailable);
            $toTransfer = max(0.0, round($ncTotal - $toCash, 2));
            return [
                'receivable' => 0.0,
                'cash' => round($toCash, 2),
                'transfer' => $toTransfer,
                'method' => $toTransfer > 0.009 ? ($toCash > 0.009 ? 'mixed' : 'transfer') : 'cash',
            ];
        }
        if ($forced === 'transfer') {
            return [
                'receivable' => 0.0,
                'cash' => 0.0,
                'transfer' => $ncTotal,
                'method' => 'transfer',
            ];
        }

        // auto
        $toCash = min($toRefund, $cashAvailable);
        $toTransfer = max(0.0, round($toRefund - $toCash, 2));
        $method = 'receivable';
        if ($toCash > 0.009 && $toTransfer <= 0.009 && $toReceivable <= 0.009) {
            $method = 'cash';
        } elseif ($toTransfer > 0.009 && $toCash <= 0.009 && $toReceivable <= 0.009) {
            $method = 'transfer';
        } elseif ($toReceivable > 0.009 && $toCash <= 0.009 && $toTransfer <= 0.009) {
            $method = 'receivable';
        } else {
            $method = 'mixed';
        }

        return [
            'receivable' => round($toReceivable, 2),
            'cash' => round($toCash, 2),
            'transfer' => round($toTransfer, 2),
            'method' => $method,
        ];
    }

    /** Efectivo ya reembolsado por otras NC de la misma venta. */
    private function cashAlreadyRefundedOnSale(int $saleId, int $excludeCreditNoteId = 0): float
    {
        if ($saleId <= 0) {
            return 0.0;
        }
        try {
            $sql = "SELECT COALESCE(SUM(cm.amount), 0)
                    FROM cash_movements cm
                    INNER JOIN sale_credit_notes cn ON cn.id = cm.reference_id
                    WHERE cm.reference_type = 'sale_credit_note'
                      AND cm.type = 'expense'
                      AND cn.sale_id = ?";
            $params = [$saleId];
            if ($excludeCreditNoteId > 0) {
                $sql .= ' AND cn.id <> ?';
                $params[] = $excludeCreditNoteId;
            }
            return round((float)$this->query($sql, $params)->fetchColumn(), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** Reduce el saldo de CxC considerando NC emitidas. */
    public function syncReceivable(int $saleId): void
    {
        $status = (string)$this->query(
            "SELECT COALESCE(status, '') FROM sales WHERE id = ?",
            [$saleId]
        )->fetchColumn();
        if ($status === '' || $status === 'cancelled') {
            return;
        }

        $bal = $this->saleBalances($saleId);
        $netTotal = (float)$bal['net_total'];
        $paid = (float)$bal['paid'];
        $balance = (float)$bal['balance'];

        try {
            (new ReceivableService($this->db))->applyPayment($saleId, min($paid, $netTotal), $netTotal);
        } catch (\Throwable $e) {
            // Si no hay fila de CxC, no bloquear.
        }

        // Sin saldo neto pendiente (incluye NC total: netTotal=0) → marcada pagada.
        if ($balance <= 0.009) {
            $this->query(
                "UPDATE sales SET payment_status = 'paid' WHERE id = ? AND status <> 'cancelled'",
                [$saleId]
            );
        } elseif ($paid > 0.009) {
            $this->query(
                "UPDATE sales SET payment_status = 'partial' WHERE id = ? AND status <> 'cancelled'",
                [$saleId]
            );
        } else {
            $this->query(
                "UPDATE sales SET payment_status = 'pending' WHERE id = ? AND status <> 'cancelled'",
                [$saleId]
            );
        }
    }

    /**
     * Reembolso en caja cuando la asignación auto/forzada incluye efectivo.
     */
    public function syncCash(int $creditNoteId): string
    {
        $note = $this->getCreditNote($creditNoteId);
        if (!$note || ($note['status'] ?? '') !== 'posted') {
            return '';
        }

        $allocation = $this->allocateRefund($creditNoteId);
        $refundCash = round((float)($allocation['cash'] ?? 0), 2);
        if ($refundCash <= 0.009) {
            return '';
        }

        try {
            $exists = (int)$this->query(
                "SELECT COUNT(*) FROM cash_movements
                 WHERE reference_type = 'sale_credit_note' AND reference_id = ?",
                [$creditNoteId]
            )->fetchColumn();
            if ($exists > 0) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        $cash = new CashService($this->db);
        if (!$cash->getOpenSession()) {
            return 'Aviso: no hay caja abierta; el reembolso en efectivo ('
                . number_format($refundCash, 0, ',', '.')
                . ') no se registró en el cajón. Ábrala y ajuste manualmente o use «Sincronizar».';
        }

        $invoice = trim((string)($note['invoice_number'] ?? ''));
        $ncNumber = (string)($note['credit_number'] ?? ('#' . $creditNoteId));
        $label = 'NC ' . $ncNumber . ($invoice !== '' ? ' · Factura ' . $invoice : '');
        try {
            $cash->registerMovement(
                $refundCash,
                'Reembolso ' . $label,
                'expense',
                'sale_credit_note',
                $creditNoteId
            );
            $extra = '';
            if (($allocation['transfer'] ?? 0) > 0.009) {
                $extra = ' Además: reembolso banco/transferencia '
                    . number_format((float)$allocation['transfer'], 0, ',', '.') . '.';
            }
            if (($allocation['receivable'] ?? 0) > 0.009) {
                $extra .= ' CxC reducida en '
                    . number_format((float)$allocation['receivable'], 0, ',', '.') . '.';
            }
            return 'Reembolso en efectivo descontado del cajón (' . $label . ').' . $extra;
        } catch (\Throwable $e) {
            error_log('NC venta→Caja #' . $creditNoteId . ': ' . $e->getMessage());
            return 'Aviso: no se pudo registrar el reembolso en caja física.';
        }
    }

    public function resolveRefundMethod(array $note): string
    {
        $method = (string)($note['refund_method'] ?? 'auto');
        if ($method !== 'auto') {
            return $method;
        }
        $id = (int)($note['id'] ?? 0);
        if ($id > 0) {
            $allocation = $this->allocateRefund($id);
            if (($allocation['method'] ?? '') === 'mixed') {
                return 'mixed';
            }
            return (string)($allocation['method'] ?? 'receivable');
        }
        $paid = (float)($note['paid_amount'] ?? 0);
        $saleTotal = (float)($note['sale_total'] ?? 0);
        $payMethod = (string)($note['payment_method'] ?? 'cash');
        $cashPaid = $this->cashPaidOnSale((int)($note['sale_id'] ?? 0), $note);
        if ($cashPaid > 0.009 && $paid >= ($saleTotal - 0.009)) {
            return 'cash';
        }
        if ($paid >= ($saleTotal - 0.009)) {
            return 'transfer';
        }
        return 'receivable';
    }

    /** Efectivo cobrado en la factura original (pagos unitarios o medio único). */
    private function cashPaidOnSale(int $saleId, array $note = []): float
    {
        $cash = 0.0;
        if ($saleId > 0) {
            try {
                $rows = $this->query(
                    "SELECT payment_method, amount FROM sale_payments WHERE sale_id = ?",
                    [$saleId]
                )->fetchAll();
                foreach ($rows as $row) {
                    if (PaymentMethodCatalog::affectsCash((string)($row['payment_method'] ?? ''))) {
                        $cash += (float)($row['amount'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $cash = 0.0;
            }
        }
        if ($cash > 0.009) {
            return round($cash, 2);
        }
        $payMethod = (string)($note['payment_method'] ?? '');
        if ($payMethod !== '' && $payMethod !== 'mixed' && PaymentMethodCatalog::affectsCash($payMethod)) {
            return round((float)($note['paid_amount'] ?? 0), 2);
        }
        return 0.0;
    }

    /** @return array<string,mixed>|null */
    public function getCreditNote(int $id): ?array
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return null;
        }
        try {
            $note = $this->query(
                "SELECT cn.*,
                        s.invoice_number, s.sale_date, s.total AS sale_total,
                        s.payment_method, s.payment_status,
                        COALESCE(pay.paid_amount, 0) AS paid_amount,
                        c.name AS customer_name, c.document_number AS customer_document,
                        c.email AS customer_email, c.phone AS customer_phone,
                        u.name AS created_by_name, e.entry_number AS accounting_entry_number
                 FROM sale_credit_notes cn
                 LEFT JOIN sales s ON s.id = cn.sale_id
                 LEFT JOIN (
                    SELECT sale_id, COALESCE(SUM(amount), 0) AS paid_amount
                    FROM sale_payments GROUP BY sale_id
                 ) pay ON pay.sale_id = s.id
                 LEFT JOIN customers c ON c.id = COALESCE(cn.customer_id, s.customer_id)
                 LEFT JOIN users u ON u.id = cn.created_by
                 LEFT JOIN accounting_entries e ON e.id = cn.accounting_entry_id
                 WHERE cn.id = ?",
                [$id]
            )->fetch();
            if (!$note) {
                return null;
            }
            $note['items'] = $this->query(
                "SELECT * FROM sale_credit_note_items WHERE credit_note_id = ? ORDER BY id",
                [$id]
            )->fetchAll();
            return $note;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listCreditNotes(int $limit = 50, int $offset = 0, string $q = ''): array
    {
        $this->ensureSchema();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = ['1=1'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(cn.credit_number LIKE ? OR s.invoice_number LIKE ? OR c.name LIKE ? OR cn.reason LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $params[] = $limit;
        $params[] = $offset;
        return $this->query(
            "SELECT cn.*, s.invoice_number, c.name AS customer_name,
                    e.entry_number AS accounting_entry_number
             FROM sale_credit_notes cn
             LEFT JOIN sales s ON s.id = cn.sale_id
             LEFT JOIN customers c ON c.id = COALESCE(cn.customer_id, s.customer_id)
             LEFT JOIN accounting_entries e ON e.id = cn.accounting_entry_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY cn.credit_date DESC, cn.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            array_slice($params, 0, -2)
        )->fetchAll();
    }

    public function countCreditNotes(string $q = ''): int
    {
        $this->ensureSchema();
        $where = ['1=1'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(cn.credit_number LIKE ? OR s.invoice_number LIKE ? OR c.name LIKE ? OR cn.reason LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        return (int)$this->query(
            "SELECT COUNT(*)
             FROM sale_credit_notes cn
             LEFT JOIN sales s ON s.id = cn.sale_id
             LEFT JOIN customers c ON c.id = COALESCE(cn.customer_id, s.customer_id)
             WHERE " . implode(' AND ', $where),
            $params
        )->fetchColumn();
    }
}
