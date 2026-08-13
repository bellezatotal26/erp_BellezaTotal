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

        $sale['credited_total'] = (float)$this->query(
            "SELECT COALESCE(SUM(total), 0) FROM sale_credit_notes
             WHERE sale_id = ? AND status = 'posted'",
            [$saleId]
        )->fetchColumn();

        return $sale;
    }

    /**
     * @param list<array{sale_item_id:int,quantity:int}> $lines
     * @return array{credit_note_id:int,credit_number:string,total:float}
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

        $sale = $this->getSaleForCredit($saleId);
        if (!$sale) {
            throw new \RuntimeException('Venta no encontrada');
        }
        if (($sale['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('No se puede emitir NC sobre una venta cancelada');
        }

        $stock = new StockService($this->db);
        $creditDate = $stock->normalizeDate($creditDate !== '' ? $creditDate : date('Y-m-d'));
        $refundMethod = in_array($refundMethod, ['auto', 'cash', 'transfer', 'receivable'], true)
            ? $refundMethod
            : 'auto';

        $itemsById = [];
        foreach ($sale['items'] as $item) {
            $itemsById[(int)$item['id']] = $item;
        }

        $normalized = [];
        $subtotal = 0.0;
        $tax = 0.0;
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
            $lineSub = round($qty * $unitPrice, 2);
            // Preferir prorrateo del impuesto real de la línea si existe.
            $itemSub = round((float)($item['subtotal'] ?? $lineSub), 2);
            $itemTax = round((float)($item['tax_amount'] ?? 0), 2);
            if ($itemSub > 0.009 && $itemTax > 0.009 && $sold > 0) {
                $lineTax = round($itemTax * ($qty / $sold), 2);
            } else {
                $lineTax = round($lineSub * ($rate / 100), 2);
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
            ];
            $subtotal += $lineSub;
            $tax += $lineTax;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Indique al menos un producto y cantidad para la nota de crédito');
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);
        $total = round($subtotal + $tax, 2);
        $creditNumber = $this->nextCreditNumber($creditDate);
        $reason = trim($reason);
        $reasonCode = substr(trim($reasonCode), 0, 40);

        $this->db->beginTransaction();
        try {
            $this->query(
                "INSERT INTO sale_credit_notes
                    (credit_number, sale_id, customer_id, credit_date, reason, reason_code,
                     refund_method, affects_stock, subtotal, tax, total, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $creditNumber,
                    $saleId,
                    $sale['customer_id'] ?: null,
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

                // Devolución del cliente → reingreso a bodega (si aplica).
                // Productos vencidos/rotos: desmarcar affects_stock para no reingresar.
                if ($affectsStock && !empty($row['product_id'])) {
                    $stock->increase(
                        (int)$row['product_id'],
                        (int)$row['quantity'],
                        'sale_credit_note',
                        $creditNoteId,
                        'NC venta ' . $creditNumber . ' / ' . ($sale['invoice_number'] ?? ''),
                        'in',
                        (float)$row['unit_cost'] > 0 ? (float)$row['unit_cost'] : null,
                        null,
                        null,
                        false
                    );
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->syncReceivable($saleId);

        return [
            'credit_note_id' => $creditNoteId,
            'credit_number' => $creditNumber,
            'total' => $total,
        ];
    }

    /** Reduce el saldo de CxC considerando NC emitidas. */
    public function syncReceivable(int $saleId): void
    {
        $sale = $this->query(
            "SELECT s.id, s.total, s.payment_status, s.status,
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
        if (!$sale || ($sale['status'] ?? '') === 'cancelled') {
            return;
        }

        $credited = (float)$this->query(
            "SELECT COALESCE(SUM(total), 0) FROM sale_credit_notes
             WHERE sale_id = ? AND status = 'posted'",
            [$saleId]
        )->fetchColumn();

        $netTotal = max(0, round((float)$sale['total'] - $credited, 2));
        $paid = round((float)$sale['paid_amount'], 2);
        $balance = max(0, round($netTotal - $paid, 2));

        try {
            (new ReceivableService($this->db))->applyPayment($saleId, $paid, $netTotal);
        } catch (\Throwable $e) {
            // Si no hay fila de CxC, no bloquear.
        }

        // Si ya no hay saldo neto pendiente, marcar venta como pagada.
        if ($balance <= 0.009 && $netTotal > 0.009) {
            $this->query(
                "UPDATE sales SET payment_status = 'paid' WHERE id = ? AND status <> 'cancelled'",
                [$saleId]
            );
        } elseif ($paid > 0.009 && $balance > 0.009) {
            $this->query(
                "UPDATE sales SET payment_status = 'partial' WHERE id = ? AND status <> 'cancelled'",
                [$saleId]
            );
        }
    }

    /**
     * Reembolso en caja cuando la venta ya estaba cobrada en efectivo.
     */
    public function syncCash(int $creditNoteId): string
    {
        $note = $this->getCreditNote($creditNoteId);
        if (!$note || ($note['status'] ?? '') !== 'posted') {
            return '';
        }

        $method = $this->resolveRefundMethod($note);
        if ($method !== 'cash') {
            return '';
        }

        $total = round((float)$note['total'], 2);
        if ($total <= 0.009) {
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
            return 'Aviso: no hay caja abierta; el reembolso en efectivo no se registró en el cajón.';
        }
        try {
            $cash->registerMovement(
                $total,
                'Reembolso NC venta ' . ($note['credit_number'] ?? ('#' . $creditNoteId))
                    . (!empty($note['invoice_number']) ? ' / ' . $note['invoice_number'] : ''),
                'expense',
                'sale_credit_note',
                $creditNoteId
            );
            return 'Reembolso en efectivo descontado del cajón.';
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
        $paid = (float)($note['paid_amount'] ?? 0);
        $saleTotal = (float)($note['sale_total'] ?? 0);
        $payMethod = (string)($note['payment_method'] ?? 'cash');
        if ($paid >= ($saleTotal - 0.009) && PaymentMethodCatalog::affectsCash($payMethod)) {
            return 'cash';
        }
        if ($paid >= ($saleTotal - 0.009)) {
            return 'transfer';
        }
        return 'receivable';
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
