<?php

namespace SoftNova\Services;

/**
 * Motor contable tenant: partida doble, plan de cuentas y reportes.
 *
 * Los asientos automáticos se identifican por origen para evitar duplicados.
 * El catálogo inicial es una plantilla editable que debe validar el contador.
 */
class AccountingService
{
    public function __construct(private \PDO $db)
    {
        $this->ensureSchema();
        $this->seedChartOfAccounts();
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
            "CREATE TABLE IF NOT EXISTS accounting_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(30) NOT NULL UNIQUE,
                name VARCHAR(180) NOT NULL,
                account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
                nature ENUM('debit','credit') NOT NULL,
                parent_id INT UNSIGNED NULL,
                level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                accepts_entries TINYINT(1) NOT NULL DEFAULT 1,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_account_type (account_type),
                INDEX idx_parent (parent_id),
                INDEX idx_status (status),
                CONSTRAINT fk_account_parent FOREIGN KEY (parent_id)
                    REFERENCES accounting_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS accounting_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entry_number VARCHAR(40) NOT NULL UNIQUE,
                entry_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                source_type VARCHAR(50) NULL,
                source_id BIGINT UNSIGNED NULL,
                source_event VARCHAR(50) NULL,
                status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'posted',
                reversal_of_id BIGINT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                approved_by INT UNSIGNED NULL,
                posted_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_accounting_source (source_type, source_id, source_event),
                INDEX idx_entry_date (entry_date),
                INDEX idx_entry_status (status),
                INDEX idx_entry_source (source_type, source_id),
                CONSTRAINT fk_entry_reversal FOREIGN KEY (reversal_of_id)
                    REFERENCES accounting_entries(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS accounting_lines (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entry_id BIGINT UNSIGNED NOT NULL,
                account_id INT UNSIGNED NOT NULL,
                description VARCHAR(255) NULL,
                debit DECIMAL(14,2) NOT NULL DEFAULT 0,
                credit DECIMAL(14,2) NOT NULL DEFAULT 0,
                third_party_type VARCHAR(30) NULL,
                third_party_id INT UNSIGNED NULL,
                third_party_name VARCHAR(180) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_line_entry (entry_id),
                INDEX idx_line_account (account_id),
                INDEX idx_line_third_party (third_party_type, third_party_id),
                CONSTRAINT fk_line_entry FOREIGN KEY (entry_id)
                    REFERENCES accounting_entries(id) ON DELETE CASCADE,
                CONSTRAINT fk_line_account FOREIGN KEY (account_id)
                    REFERENCES accounting_accounts(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS accounting_periods (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                year SMALLINT UNSIGNED NOT NULL,
                month TINYINT UNSIGNED NOT NULL,
                status ENUM('open','closed') NOT NULL DEFAULT 'open',
                closed_at DATETIME NULL,
                closed_by INT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                UNIQUE KEY uq_accounting_period (year, month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS accounting_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Costo histórico por línea de venta (para COGS correcto).
        try {
            $col = $this->db->query("SHOW COLUMNS FROM sale_items LIKE 'unit_cost'")->fetch();
            if (!$col) {
                $this->db->exec('ALTER TABLE sale_items ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price');
            }
        } catch (\Throwable $e) {
            // silencioso: tabla puede no existir en instalaciones nuevas
        }

        $done = true;
    }

    private function seedChartOfAccounts(): void
    {
        static $seeded = [];
        $connKey = spl_object_id($this->db);
        if (isset($seeded[$connKey])) {
            return;
        }

        $accounts = [
            ['110505', 'Caja general', 'asset', 'debit', 1],
            ['111005', 'Bancos y medios electrónicos', 'asset', 'debit', 1],
            ['130505', 'Clientes nacionales', 'asset', 'debit', 1],
            ['143501', 'Inventarios de mercancías', 'asset', 'debit', 1],
            ['220505', 'Proveedores nacionales', 'liability', 'credit', 1],
            ['233595', 'Costos y gastos por pagar', 'liability', 'credit', 1],
            ['240801', 'IVA generado', 'liability', 'credit', 1],
            ['240802', 'IVA descontable', 'asset', 'debit', 1],
            ['310505', 'Capital social', 'equity', 'credit', 1],
            ['360505', 'Utilidad del ejercicio', 'equity', 'credit', 1],
            ['413501', 'Ingresos por ventas', 'revenue', 'credit', 1],
            ['417501', 'Devoluciones en ventas', 'revenue', 'debit', 1],
            ['510505', 'Gastos generales', 'expense', 'debit', 1],
            ['513505', 'Servicios', 'expense', 'debit', 1],
            ['519595', 'Otros gastos operacionales', 'expense', 'debit', 1],
            ['530505', 'Gastos financieros y comisiones', 'expense', 'debit', 1],
            ['613501', 'Costo de ventas', 'expense', 'debit', 1],
        ];

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO accounting_accounts
                (code, name, account_type, nature, is_system)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($accounts as $account) {
            $stmt->execute($account);
        }

        $settings = [
            'cash_account' => '110505',
            'bank_account' => '111005',
            'receivable_account' => '130505',
            'inventory_account' => '143501',
            'payable_account' => '220505',
            'expense_payable_account' => '233595',
            'vat_generated_account' => '240801',
            'vat_deductible_account' => '240802',
            'sales_account' => '413501',
            'sales_returns_account' => '417501',
            'general_expense_account' => '510505',
            'fixed_expense_account' => '510505',
            'financial_expense_account' => '530505',
            'cost_of_sales_account' => '613501',
        ];
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)"
        );
        foreach ($settings as $settingKey => $value) {
            $stmt->execute([$settingKey, $value]);
        }

        $seeded[$connKey] = true;
    }

    /**
     * Valida cuentas críticas (530505, CxP, inventario) y settings asociados.
     * Repara faltantes con INSERT IGNORE / settings por defecto.
     *
     * @return array{ok:bool,missing:list<string>,fixed:list<string>,notes:list<string>}
     */
    public function auditCriticalAccounts(): array
    {
        $this->seedChartOfAccounts();
        $required = [
            '110505' => 'Caja general',
            '111005' => 'Bancos y medios electrónicos',
            '143501' => 'Inventarios de mercancías',
            '220505' => 'Proveedores nacionales',
            '530505' => 'Gastos financieros y comisiones',
            '613501' => 'Costo de ventas',
        ];
        $missing = [];
        $fixed = [];
        foreach ($required as $code => $name) {
            $row = $this->query(
                'SELECT id FROM accounting_accounts WHERE code = ? LIMIT 1',
                [$code]
            )->fetch();
            if (!$row) {
                $missing[] = $code;
                $type = match (true) {
                    str_starts_with($code, '1') => 'asset',
                    str_starts_with($code, '2') => 'liability',
                    str_starts_with($code, '5'), str_starts_with($code, '6') => 'expense',
                    default => 'expense',
                };
                $nature = in_array($type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';
                $this->query(
                    "INSERT INTO accounting_accounts (code, name, account_type, nature, is_system)
                     VALUES (?, ?, ?, ?, 1)",
                    [$code, $name, $type, $nature]
                );
                $fixed[] = $code;
            }
        }

        $settingsMap = [
            'financial_expense_account' => '530505',
            'payable_account' => '220505',
            'inventory_account' => '143501',
            'dataphone_commission_rate' => '2.5',
            'card_commission_rate' => '2.8',
            'purchase_credit_policy' => 'cxp', // cxp = pendiente/parcial → Proveedores 220505
        ];
        foreach ($settingsMap as $key => $value) {
            $this->query(
                "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)",
                [$key, $value]
            );
        }

        $notes = [
            'Compras pagadas: crédito a caja/banco según medio de pago.',
            'Compras pendientes o parciales: crédito a CxP proveedores (220505). El saldo parcial se liquida fuera del asiento inicial.',
            'Comisiones datáfono/tarjeta: gasto a 530505 (financial_expense_account).',
        ];

        return [
            'ok' => empty($missing) || !empty($fixed),
            'missing' => $missing,
            'fixed' => $fixed,
            'notes' => $notes,
            'settings' => [
                'financial_expense_account' => $this->setting('financial_expense_account', '530505'),
                'payable_account' => $this->setting('payable_account', '220505'),
                'purchase_credit_policy' => $this->setting('purchase_credit_policy', 'cxp'),
                'dataphone_commission_rate' => $this->setting('dataphone_commission_rate', '2.5'),
                'card_commission_rate' => $this->setting('card_commission_rate', '2.8'),
            ],
        ];
    }

    /**
     * Conciliación ventas electrónicas vs gastos de comisión registrados.
     *
     * @return array<string,mixed>
     */
    public function dataphoneReconciliation(string $from, string $to): array
    {
        $electronic = ['dataphone', 'debit_card', 'credit_card', 'card', 'payment_link'];
        $placeholders = implode(',', array_fill(0, count($electronic), '?'));
        $params = array_merge($electronic, [$from, $to]);

        $sales = $this->query(
            "SELECT payment_method,
                    COUNT(*) cnt,
                    COALESCE(SUM(total), 0) total
             FROM sales
             WHERE status = 'completed'
               AND payment_method IN ({$placeholders})
               AND DATE(sale_date) BETWEEN ? AND ?
             GROUP BY payment_method",
            $params
        )->fetchAll();

        $salesTotal = 0.0;
        $dataphoneSales = 0.0;
        $cardSales = 0.0;
        foreach ($sales as $row) {
            $amt = (float)$row['total'];
            $salesTotal += $amt;
            $m = (string)$row['payment_method'];
            if ($m === 'dataphone') {
                $dataphoneSales += $amt;
            } elseif (in_array($m, ['debit_card', 'credit_card', 'card', 'payment_link'], true)) {
                $cardSales += $amt;
            }
        }

        $rateDataphone = (float)$this->setting('dataphone_commission_rate', '2.5');
        $rateCard = (float)$this->setting('card_commission_rate', '2.8');
        $expectedDataphone = round($dataphoneSales * $rateDataphone / 100, 2);
        $expectedCard = round($cardSales * $rateCard / 100, 2);
        $expected = round($expectedDataphone + $expectedCard, 2);

        $expenses = $this->query(
            "SELECT COALESCE(category,'general') category,
                    COUNT(*) cnt,
                    COALESCE(SUM(amount), 0) total
             FROM expenses
             WHERE expense_date BETWEEN ? AND ?
               AND category IN ('dataphone_commission','card_commission','payment_link_commission','financial','bank_fee')
             GROUP BY COALESCE(category,'general')",
            [$from, $to]
        )->fetchAll();

        $recorded = 0.0;
        $recordedDataphone = 0.0;
        foreach ($expenses as $row) {
            $recorded += (float)$row['total'];
            if (($row['category'] ?? '') === 'dataphone_commission') {
                $recordedDataphone += (float)$row['total'];
            }
        }

        $gap = round($expected - $recorded, 2);

        return [
            'from' => $from,
            'to' => $to,
            'sales_by_method' => $sales,
            'sales_total' => $salesTotal,
            'dataphone_sales' => $dataphoneSales,
            'card_sales' => $cardSales,
            'rate_dataphone' => $rateDataphone,
            'rate_card' => $rateCard,
            'expected_dataphone' => $expectedDataphone,
            'expected_card' => $expectedCard,
            'expected_total' => $expected,
            'recorded_expenses' => $expenses,
            'recorded_total' => $recorded,
            'recorded_dataphone' => $recordedDataphone,
            'gap' => $gap,
            'suggest_amount' => max(0, $gap),
            'suggest_category' => 'dataphone_commission',
            'suggest_description' => 'Comisión datáfono/tarjetas ' . $from . ' a ' . $to,
        ];
    }

    /**
     * Margen de ventas agrupado por canal de producto (manual / compra / woo / ml).
     *
     * @return list<array{channel:string,revenue:float,cost:float,profit:float,margin:float,qty:int}>
     */
    public function marginByChannel(string $from, string $to): array
    {
        $rows = $this->query(
            "SELECT COALESCE(NULLIF(p.source_channel,''), 'manual') channel,
                    SUM(si.quantity) qty,
                    COALESCE(SUM(si.subtotal), 0) revenue,
                    COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0) cost
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN products p ON p.id = si.product_id
             WHERE s.status = 'completed' AND DATE(s.sale_date) BETWEEN ? AND ?
             GROUP BY COALESCE(NULLIF(p.source_channel,''), 'manual')
             ORDER BY revenue DESC",
            [$from, $to]
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $revenue = (float)$row['revenue'];
            $cost = (float)$row['cost'];
            $profit = $revenue - $cost;
            $out[] = [
                'channel' => (string)$row['channel'],
                'qty' => (int)$row['qty'],
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0,
            ];
        }
        return $out;
    }

    public function accounts(bool $onlyActive = false): array
    {
        $where = $onlyActive ? "WHERE status = 'active'" : '';
        return $this->query(
            "SELECT * FROM accounting_accounts {$where} ORDER BY code"
        )->fetchAll();
    }

    public function saveAccount(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $code = trim((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $type = (string)($data['account_type'] ?? '');
        $nature = (string)($data['nature'] ?? '');
        $allowedTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];

        if ($code === '' || $name === '' || !in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException('Datos de cuenta inválidos');
        }
        if (!in_array($nature, ['debit', 'credit'], true)) {
            $nature = in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
        }

        if ($id > 0) {
            $existing = $this->query(
                "SELECT * FROM accounting_accounts WHERE id = ? LIMIT 1",
                [$id]
            )->fetch();
            if (!$existing) {
                throw new \InvalidArgumentException('Cuenta contable no encontrada');
            }
            if ((int)$existing['is_system'] === 1) {
                // Cuentas de sistema: no cambiar código/tipo ni desactivar (rompe ventas/gastos).
                $code = $existing['code'];
                $type = $existing['account_type'];
                $nature = $existing['nature'];
                if (($data['status'] ?? 'active') === 'inactive') {
                    throw new \InvalidArgumentException('No se puede desactivar una cuenta de sistema');
                }
            }
            $this->query(
                "UPDATE accounting_accounts
                 SET code = ?, name = ?, account_type = ?, nature = ?,
                     accepts_entries = ?, status = ?
                 WHERE id = ?",
                [
                    $code, $name, $type, $nature,
                    !empty($data['accepts_entries']) ? 1 : 0,
                    ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                    $id,
                ]
            );
            return $id;
        }

        $this->query(
            "INSERT INTO accounting_accounts
                (code, name, account_type, nature, accepts_entries, status)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $code, $name, $type, $nature,
                !empty($data['accepts_entries']) ? 1 : 0,
                ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    public function postEntry(
        string $date,
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $sourceEvent = null,
        ?int $reversalOfId = null
    ): int {
        $dateObject = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Fecha contable inválida');
        }
        if ($this->isPeriodClosed($date)) {
            throw new \RuntimeException('El periodo contable está cerrado');
        }

        $normalized = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $debit = round(max(0, (float)($line['debit'] ?? 0)), 2);
            $credit = round(max(0, (float)($line['credit'] ?? 0)), 2);
            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw new \InvalidArgumentException('Cada línea debe tener solo débito o crédito');
            }

            $accountId = $this->accountId((string)($line['account_code'] ?? ''));
            $normalized[] = array_merge($line, [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
            ]);
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (count($normalized) < 2 || abs($totalDebit - $totalCredit) > 0.009) {
            throw new \InvalidArgumentException('El comprobante no está balanceado');
        }

        if ($sourceType !== null && $sourceId !== null && $sourceEvent !== null) {
            $existing = $this->query(
                "SELECT id FROM accounting_entries
                 WHERE source_type = ? AND source_id = ? AND source_event = ? LIMIT 1",
                [$sourceType, $sourceId, $sourceEvent]
            )->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        }

        $entryNumber = $this->nextEntryNumber($date);
        $this->query(
            "INSERT INTO accounting_entries
                (entry_number, entry_date, description, source_type, source_id,
                 source_event, status, reversal_of_id, created_by, approved_by, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, ?, ?, NOW())",
            [
                $entryNumber, $date, trim($description), $sourceType, $sourceId,
                $sourceEvent, $reversalOfId,
                $_SESSION['tenant_user_id'] ?? null,
                $_SESSION['tenant_user_id'] ?? null,
            ]
        );
        $entryId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare(
            "INSERT INTO accounting_lines
                (entry_id, account_id, description, debit, credit,
                 third_party_type, third_party_id, third_party_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($normalized as $line) {
            $stmt->execute([
                $entryId,
                $line['account_id'],
                trim((string)($line['description'] ?? '')) ?: null,
                $line['debit'],
                $line['credit'],
                $line['third_party_type'] ?? null,
                !empty($line['third_party_id']) ? (int)$line['third_party_id'] : null,
                trim((string)($line['third_party_name'] ?? '')) ?: null,
            ]);
        }

        return $entryId;
    }

    public function postSale(int $saleId): int
    {
        $sale = $this->query(
            "SELECT s.*, COALESCE(c.name, 'Consumidor final') customer_name
             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.id = ?",
            [$saleId]
        )->fetch();
        if (!$sale || $sale['status'] === 'cancelled') {
            throw new \RuntimeException('Venta inválida para contabilizar');
        }

        $total = round((float)$sale['total'], 2);
        $subtotal = round((float)$sale['subtotal'], 2);
        $tax = round((float)$sale['tax'], 2);
        if ($total <= 0) {
            throw new \RuntimeException('La venta no tiene total contable');
        }

        // Reconocimiento: siempre CxC por el total. Los cobros van en postSalePayment.
        $lines = [
            $this->line(
                $this->setting('receivable_account', '130505'),
                $total,
                0,
                'Cuenta por cobrar',
                'customer',
                $sale['customer_id'],
                $sale['customer_name']
            ),
        ];
        if ($subtotal > 0) {
            $lines[] = $this->line(
                $this->setting('sales_account', '413501'),
                0,
                $subtotal,
                'Ingreso por venta'
            );
        }
        if ($tax > 0) {
            $lines[] = $this->line(
                $this->setting('vat_generated_account', '240801'),
                0,
                $tax,
                'IVA generado'
            );
        }
        $credited = round($subtotal + $tax, 2);
        if ($credited <= 0) {
            $lines[] = $this->line(
                $this->setting('sales_account', '413501'),
                0,
                $total,
                'Ingreso por venta'
            );
        } elseif (abs($credited - $total) > 0.009) {
            // Ajuste por descuentos u otros para mantener partida doble.
            $diff = round($total - $credited, 2);
            if ($diff > 0) {
                $lines[] = $this->line(
                    $this->setting('sales_account', '413501'),
                    0,
                    $diff,
                    'Ajuste ingreso'
                );
            } else {
                $lines[] = $this->line(
                    $this->setting('sales_account', '413501'),
                    abs($diff),
                    0,
                    'Descuento en venta'
                );
            }
        }

        // Costo histórico: usa unit_cost capturado en la venta; si no existe (ventas
        // antiguas) cae al costo actual del producto.
        $cost = (float)$this->query(
            "SELECT COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0)
             FROM sale_items si
             JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = ? AND COALESCE(p.product_type, 'product') = 'product'",
            [$saleId]
        )->fetchColumn();
        if ($cost > 0) {
            $lines[] = $this->line(
                $this->setting('cost_of_sales_account', '613501'),
                $cost,
                0,
                'Costo de mercancía vendida'
            );
            $lines[] = $this->line(
                $this->setting('inventory_account', '143501'),
                0,
                $cost,
                'Salida de inventario'
            );
        }

        return $this->postEntry(
            date('Y-m-d', strtotime($sale['sale_date'])),
            'Venta ' . $sale['invoice_number'],
            $lines,
            'sale',
            $saleId,
            'created'
        );
    }

    /**
     * Contabiliza la venta y todos sus abonos (creación o sincronización).
     *
     * @return array{sale_entry:int,payment_entries:int[]}
     */
    public function postSaleCascade(int $saleId): array
    {
        $saleEntry = $this->postSale($saleId);
        $paymentEntries = [];
        $payments = $this->query(
            "SELECT id FROM sale_payments WHERE sale_id = ? ORDER BY id",
            [$saleId]
        )->fetchAll();
        foreach ($payments as $payment) {
            $paymentEntries[] = $this->postSalePayment((int)$payment['id']);
        }
        return [
            'sale_entry' => $saleEntry,
            'payment_entries' => $paymentEntries,
        ];
    }

    public function reverseSaleCascade(int $saleId, string $reason = 'Venta cancelada'): void
    {
        $payments = $this->query(
            "SELECT id FROM sale_payments WHERE sale_id = ? ORDER BY id DESC",
            [$saleId]
        )->fetchAll();
        foreach ($payments as $payment) {
            $this->reverseSource('sale_payment', (int)$payment['id'], $reason);
        }
        $this->reverseSource('sale', $saleId, $reason);
    }

    public function postSalePayment(int $paymentId): int
    {
        $payment = $this->query(
            "SELECT p.*, s.invoice_number, s.customer_id,
                    COALESCE(c.name, 'Consumidor final') customer_name
             FROM sale_payments p
             JOIN sales s ON s.id = p.sale_id
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE p.id = ?",
            [$paymentId]
        )->fetch();
        if (!$payment) {
            throw new \RuntimeException('Abono no encontrado');
        }

        return $this->postEntry(
            date('Y-m-d', strtotime($payment['payment_date'])),
            'Abono venta ' . $payment['invoice_number'],
            [
                $this->line(
                    $this->paymentAccount((string)$payment['payment_method']),
                    (float)$payment['amount'],
                    0,
                    'Ingreso de abono'
                ),
                $this->line(
                    $this->setting('receivable_account', '130505'),
                    0,
                    (float)$payment['amount'],
                    'Disminución de cuenta por cobrar',
                    'customer',
                    $payment['customer_id'],
                    $payment['customer_name']
                ),
            ],
            'sale_payment',
            $paymentId,
            'created'
        );
    }

    public function postExpense(int $expenseId, bool $affectCash): int
    {
        $expense = $this->query(
            "SELECT e.*, COALESCE(s.name, '') supplier_name
             FROM expenses e LEFT JOIN suppliers s ON s.id = e.supplier_id
             WHERE e.id = ?",
            [$expenseId]
        )->fetch();
        if (!$expense) {
            throw new \RuntimeException('Gasto no encontrado');
        }

        $category = (string)($expense['category'] ?? 'general');
        $group = function_exists('\\SoftNova\\Core\\expense_category_group')
            ? \SoftNova\Core\expense_category_group($category)
            : 'operating';

        $expenseAccount = match ($group) {
            'financial' => $this->setting('financial_expense_account', '530505'),
            'fixed' => $category === 'payroll'
                ? $this->setting('payroll_expense_account', $this->setting('fixed_expense_account', '510505'))
                : $this->setting('fixed_expense_account', '510505'),
            default => $this->setting('general_expense_account', '510505'),
        };

        $creditCode = $affectCash
            ? $this->paymentAccount((string)$expense['payment_method'])
            : $this->setting('expense_payable_account', '233595');

        $categoryLabel = function_exists('\\SoftNova\\Core\\expense_category_label')
            ? \SoftNova\Core\expense_category_label($category)
            : $category;
        $groupLabel = function_exists('\\SoftNova\\Core\\expense_group_label')
            ? \SoftNova\Core\expense_group_label($group)
            : $group;

        return $this->postEntry(
            $expense['expense_date'],
            'Gasto [' . $groupLabel . ']: ' . $expense['description'],
            [
                $this->line(
                    $expenseAccount,
                    (float)$expense['amount'],
                    0,
                    $groupLabel . ' · ' . $categoryLabel,
                    'supplier',
                    $expense['supplier_id'],
                    $expense['supplier_name']
                ),
                $this->line(
                    $creditCode,
                    0,
                    (float)$expense['amount'],
                    $affectCash ? 'Pago de gasto' : 'Gasto por pagar',
                    'supplier',
                    $expense['supplier_id'],
                    $expense['supplier_name']
                ),
            ],
            'expense',
            $expenseId,
            'created'
        );
    }

    /**
     * Gasto de comisión de vendedor con cuenta específica (510508).
     */
    public function postCommissionExpense(
        int $expenseId,
        string $expenseAccountCode,
        float $amount,
        string $date,
        string $description,
        string $creditAccountCode
    ): int {
        return $this->postEntry(
            $date,
            $description,
            [
                $this->line($expenseAccountCode, $amount, 0, 'Comisión sobre ventas'),
                $this->line($creditAccountCode, 0, $amount, 'Comisión por pagar / banco'),
            ],
            'expense',
            $expenseId,
            'created'
        );
    }

    /**
     * Asiento detallado de nómina: sueldos + aportes patronales/parafiscales
     * contra CxP de seguridad social y banco/caja (neto).
     */
    public function postPayrollRun(int $runId, bool $payNow = true): int
    {
        (new PayrollService($this->db))->params();

        $run = $this->query('SELECT * FROM payroll_runs WHERE id = ?', [$runId])->fetch();
        if (!$run || ($run['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Liquidación inválida');
        }
        $sum = $this->query(
            "SELECT
                COALESCE(SUM(salary_base + transport_aid + COALESCE(prima,0) + COALESCE(cesantias,0)
                    + COALESCE(cesantias_interest,0) + COALESCE(incapacity_pay,0) + other_earnings),0) labor,
                COALESCE(SUM(health_employee),0) health_emp,
                COALESCE(SUM(pension_employee),0) pension_emp,
                COALESCE(SUM(health_employer),0) health_er,
                COALESCE(SUM(pension_employer),0) pension_er,
                COALESCE(SUM(COALESCE(arl_employer,0)),0) arl,
                COALESCE(SUM(COALESCE(caja_employer,0)),0) caja,
                COALESCE(SUM(COALESCE(sena_employer,0)),0) sena,
                COALESCE(SUM(COALESCE(icbf_employer,0)),0) icbf,
                COALESCE(SUM(net_pay),0) net
             FROM payroll_items WHERE payroll_run_id = ?",
            [$runId]
        )->fetch() ?: [];

        $labor = round((float)$sum['labor'], 2);
        $healthEmp = round((float)$sum['health_emp'], 2);
        $pensionEmp = round((float)$sum['pension_emp'], 2);
        $healthEr = round((float)$sum['health_er'], 2);
        $pensionEr = round((float)$sum['pension_er'], 2);
        $arl = round((float)$sum['arl'], 2);
        $caja = round((float)$sum['caja'], 2);
        $sena = round((float)$sum['sena'], 2);
        $icbf = round((float)$sum['icbf'], 2);
        $net = round((float)$sum['net'], 2);
        $employerCost = round($healthEr + $pensionEr + $arl + $caja + $sena + $icbf, 2);

        if ($labor <= 0 && $employerCost <= 0) {
            throw new \RuntimeException('Liquidación sin montos contables');
        }

        $lines = [];
        if ($labor > 0) {
            $lines[] = $this->line(
                $this->setting('payroll_expense_account', '510503'),
                $labor,
                0,
                'Devengados nómina ' . $run['run_number']
            );
        }
        if ($employerCost > 0) {
            $lines[] = $this->line(
                $this->setting('payroll_employer_expense_account', '510506'),
                $employerCost,
                0,
                'Aportes patronales y parafiscales'
            );
        }

        $healthPay = round($healthEmp + $healthEr, 2);
        $pensionPay = round($pensionEmp + $pensionEr, 2);
        if ($healthPay > 0) {
            $lines[] = $this->line($this->setting('health_payable_account', '237005'), 0, $healthPay, 'Salud por pagar');
        }
        if ($pensionPay > 0) {
            $lines[] = $this->line($this->setting('pension_payable_account', '238030'), 0, $pensionPay, 'Pensión por pagar');
        }
        if ($arl > 0) {
            $lines[] = $this->line($this->setting('arl_payable_account', '237010'), 0, $arl, 'ARL por pagar');
        }
        if ($caja > 0) {
            $lines[] = $this->line($this->setting('caja_payable_account', '237006'), 0, $caja, 'Caja compensación');
        }
        if ($sena > 0) {
            $lines[] = $this->line($this->setting('sena_payable_account', '237008'), 0, $sena, 'SENA por pagar');
        }
        if ($icbf > 0) {
            $lines[] = $this->line($this->setting('icbf_payable_account', '237007'), 0, $icbf, 'ICBF por pagar');
        }
        if ($net > 0) {
            $creditNet = $payNow
                ? $this->paymentAccount((string)($run['payment_method'] ?? 'transfer'))
                : $this->setting('payroll_payable_account', '250505');
            $lines[] = $this->line(
                $creditNet,
                0,
                $net,
                $payNow ? 'Pago neto a empleados' : 'Salarios por pagar'
            );
        }

        return $this->postEntry(
            $run['pay_date'],
            'Nómina ' . $run['run_number'] . ' · ' . $run['period_label'],
            $lines,
            'payroll',
            $runId,
            'created'
        );
    }

    /**
     * Totales de gastos del periodo por grupo contable (fijos / financieros / operativos).
     *
     * @return array{fixed:float,financial:float,operating:float,total:float,by_category:list<array>}
     */
    public function expenseBreakdown(string $from, string $to): array
    {
        $rows = $this->query(
            "SELECT COALESCE(category,'general') category, COALESCE(SUM(amount),0) total, COUNT(*) cnt
             FROM expenses WHERE expense_date BETWEEN ? AND ?
             GROUP BY COALESCE(category,'general') ORDER BY total DESC",
            [$from, $to]
        )->fetchAll();

        $out = [
            'fixed' => 0.0,
            'financial' => 0.0,
            'operating' => 0.0,
            'total' => 0.0,
            'by_category' => [],
            'by_group' => [],
        ];

        foreach ($rows as $row) {
            $code = (string)$row['category'];
            $amount = (float)$row['total'];
            $group = function_exists('\\SoftNova\\Core\\expense_category_group')
                ? \SoftNova\Core\expense_category_group($code)
                : 'operating';
            if (!isset($out[$group])) {
                $group = 'operating';
            }
            $out[$group] += $amount;
            $out['total'] += $amount;
            $out['by_category'][] = [
                'category' => $code,
                'label' => function_exists('\\SoftNova\\Core\\expense_category_label')
                    ? \SoftNova\Core\expense_category_label($code)
                    : $code,
                'group' => $group,
                'group_label' => function_exists('\\SoftNova\\Core\\expense_group_label')
                    ? \SoftNova\Core\expense_group_label($group)
                    : $group,
                'total' => $amount,
                'cnt' => (int)$row['cnt'],
            ];
        }

        foreach (['fixed', 'financial', 'operating'] as $g) {
            $out['by_group'][] = [
                'group' => $g,
                'label' => function_exists('\\SoftNova\\Core\\expense_group_label')
                    ? \SoftNova\Core\expense_group_label($g)
                    : $g,
                'total' => $out[$g],
            ];
        }

        return $out;
    }

    /**
     * Compra a proveedor: Debito Inventario / Credito Proveedores (o caja/banco si pagada).
     */
    public function postPurchase(int $purchaseId): int
    {
        $purchase = $this->query(
            "SELECT p.*, COALESCE(s.name, 'Proveedor') supplier_name
             FROM purchases p
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.id = ?",
            [$purchaseId]
        )->fetch();
        if (!$purchase || ($purchase['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Compra inválida para contabilizar');
        }

        $total = round((float)$purchase['total'], 2);
        if ($total <= 0) {
            throw new \RuntimeException('La compra no tiene total contable');
        }

        // Política CxP: paid → caja/banco; pending|partial → Proveedores (220505).
        // En parcial el asiento inicial es 100% a CxP; abonos posteriores se registran aparte.
        $status = (string)($purchase['payment_status'] ?? 'paid');
        $paid = $status === 'paid';
        $creditCode = $paid
            ? $this->paymentAccount((string)($purchase['payment_method'] ?? 'cash'))
            : $this->setting('payable_account', '220505');

        $creditLabel = match ($status) {
            'paid' => 'Pago compra proveedor',
            'partial' => 'CxP proveedor (compra parcial — saldo por liquidar)',
            default => 'Cuenta por pagar proveedor',
        };

        return $this->postEntry(
            date('Y-m-d', strtotime($purchase['purchase_date'])),
            'Compra ' . $purchase['purchase_number'],
            [
                $this->line(
                    $this->setting('inventory_account', '143501'),
                    $total,
                    0,
                    'Entrada de mercancía',
                    'supplier',
                    $purchase['supplier_id'],
                    $purchase['supplier_name']
                ),
                $this->line(
                    $creditCode,
                    0,
                    $total,
                    $creditLabel,
                    'supplier',
                    $purchase['supplier_id'],
                    $purchase['supplier_name']
                ),
            ],
            'purchase',
            $purchaseId,
            'created'
        );
    }

    public function reversePurchase(int $purchaseId, string $reason = 'Compra cancelada'): ?int
    {
        return $this->reverseSource('purchase', $purchaseId, $reason);
    }

    /**
     * Crea comprobantes para operaciones anteriores a la activación del módulo.
     * En gastos históricos se presume que el método registrado fue pagado.
     *
     * Procesa por lotes para no agotar el tiempo de ejecución con históricos grandes.
     * Devuelve 'remaining' > 0 cuando aún quedan operaciones por sincronizar,
     * de modo que la UI pueda invocar el proceso nuevamente.
     *
     * @param int $limit Máximo de operaciones a procesar por invocación (0 = sin límite).
     * @return array{sales:int,expenses:int,payments:int,errors:int,remaining:int,done:bool}
     */
    public function syncExistingOperations(int $limit = 200): array
    {
        $result = ['sales' => 0, 'expenses' => 0, 'payments' => 0, 'errors' => 0, 'remaining' => 0, 'done' => true];
        $limit = max(0, $limit);
        $budget = $limit > 0 ? $limit : PHP_INT_MAX;
        $limitSql = $limit > 0 ? ' LIMIT ' . ((int)$limit + 1) : '';

        $sales = $this->query(
            "SELECT s.id
             FROM sales s
             LEFT JOIN accounting_entries e
               ON e.source_type = 'sale' AND e.source_id = s.id AND e.source_event = 'created'
             WHERE s.status != 'cancelled' AND e.id IS NULL
             ORDER BY s.id" . $limitSql
        )->fetchAll();
        foreach ($sales as $sale) {
            if ($budget <= 0) {
                break;
            }
            try {
                $posted = $this->postSaleCascade((int)$sale['id']);
                $result['sales']++;
                $result['payments'] += count($posted['payment_entries']);
            } catch (\Throwable $e) {
                $result['errors']++;
                error_log('Accounting sync sale ' . $sale['id'] . ': ' . $e->getMessage());
            }
            $budget--;
        }

        // Abonos sueltos de ventas ya contabilizadas (sin asiento propio).
        if ($budget > 0) {
            $paymentLimitSql = $limit > 0 ? ' LIMIT ' . ((int)$budget + 1) : '';
            $orphanPayments = $this->query(
                "SELECT p.id
                 FROM sale_payments p
                 JOIN sales s ON s.id = p.sale_id AND s.status != 'cancelled'
                 JOIN accounting_entries se
                   ON se.source_type = 'sale' AND se.source_id = s.id AND se.source_event = 'created'
                 LEFT JOIN accounting_entries pe
                   ON pe.source_type = 'sale_payment' AND pe.source_id = p.id AND pe.source_event = 'created'
                 WHERE pe.id IS NULL
                 ORDER BY p.id" . $paymentLimitSql
            )->fetchAll();
            foreach ($orphanPayments as $payment) {
                if ($budget <= 0) {
                    break;
                }
                try {
                    $this->postSalePayment((int)$payment['id']);
                    $result['payments']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    error_log('Accounting sync payment ' . $payment['id'] . ': ' . $e->getMessage());
                }
                $budget--;
            }
        }

        if ($budget > 0) {
            $expenseLimitSql = $limit > 0 ? ' LIMIT ' . ((int)$budget + 1) : '';
            $expenses = $this->query(
                "SELECT x.id
                 FROM expenses x
                 LEFT JOIN accounting_entries e
                   ON e.source_type = 'expense' AND e.source_id = x.id AND e.source_event = 'created'
                 WHERE e.id IS NULL
                 ORDER BY x.id" . $expenseLimitSql
            )->fetchAll();
            foreach ($expenses as $expense) {
                if ($budget <= 0) {
                    break;
                }
                try {
                    $this->postExpense((int)$expense['id'], true);
                    $result['expenses']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    error_log('Accounting sync expense ' . $expense['id'] . ': ' . $e->getMessage());
                }
                $budget--;
            }
        }

        $result['remaining'] = $this->pendingSyncCount();
        $result['done'] = $result['remaining'] === 0;
        return $result;
    }

    /** Cuenta operaciones históricas aún sin asiento contable. */
    public function pendingSyncCount(): int
    {
        $pendingSales = (int)$this->query(
            "SELECT COUNT(*) FROM sales s
             LEFT JOIN accounting_entries e
               ON e.source_type = 'sale' AND e.source_id = s.id AND e.source_event = 'created'
             WHERE s.status != 'cancelled' AND e.id IS NULL"
        )->fetchColumn();
        $pendingPayments = (int)$this->query(
            "SELECT COUNT(*) FROM sale_payments p
             JOIN sales s ON s.id = p.sale_id AND s.status != 'cancelled'
             JOIN accounting_entries se
               ON se.source_type = 'sale' AND se.source_id = s.id AND se.source_event = 'created'
             LEFT JOIN accounting_entries pe
               ON pe.source_type = 'sale_payment' AND pe.source_id = p.id AND pe.source_event = 'created'
             WHERE pe.id IS NULL"
        )->fetchColumn();
        $pendingExpenses = (int)$this->query(
            "SELECT COUNT(*) FROM expenses x
             LEFT JOIN accounting_entries e
               ON e.source_type = 'expense' AND e.source_id = x.id AND e.source_event = 'created'
             WHERE e.id IS NULL"
        )->fetchColumn();
        return $pendingSales + $pendingPayments + $pendingExpenses;
    }

    public function reverseSource(string $sourceType, int $sourceId, string $reason): ?int
    {
        // Solo asientos originales (no reversiones): evita doble reversión.
        $entry = $this->query(
            "SELECT * FROM accounting_entries
             WHERE source_type = ? AND source_id = ? AND status = 'posted'
               AND reversal_of_id IS NULL
             ORDER BY id DESC LIMIT 1",
            [$sourceType, $sourceId]
        )->fetch();
        if (!$entry) {
            return null;
        }

        $rows = $this->query(
            "SELECT l.*, a.code account_code
             FROM accounting_lines l
             JOIN accounting_accounts a ON a.id = l.account_id
             WHERE l.entry_id = ?",
            [$entry['id']]
        )->fetchAll();
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'account_code' => $row['account_code'],
                'debit' => (float)$row['credit'],
                'credit' => (float)$row['debit'],
                'description' => $reason,
                'third_party_type' => $row['third_party_type'],
                'third_party_id' => $row['third_party_id'],
                'third_party_name' => $row['third_party_name'],
            ];
        }

        $reversalId = $this->postEntry(
            date('Y-m-d'),
            'Reversión ' . $entry['entry_number'] . ': ' . $reason,
            $lines,
            $sourceType,
            $sourceId,
            'reversal_' . $entry['id'],
            (int)$entry['id']
        );
        $this->query(
            "UPDATE accounting_entries SET status = 'reversed' WHERE id = ?",
            [$entry['id']]
        );
        return $reversalId;
    }

    public function entries(string $from, string $to, int $limit = 200): array
    {
        return $this->query(
            "SELECT e.*, u.name created_by_name,
                    SUM(l.debit) total_debit, SUM(l.credit) total_credit
             FROM accounting_entries e
             LEFT JOIN accounting_lines l ON l.entry_id = e.id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.entry_date BETWEEN ? AND ?
             GROUP BY e.id
             ORDER BY e.entry_date DESC, e.id DESC
             LIMIT " . max(1, min(500, $limit)),
            [$from, $to]
        )->fetchAll();
    }

    public function entry(int $id): ?array
    {
        $entry = $this->query(
            "SELECT * FROM accounting_entries WHERE id = ?",
            [$id]
        )->fetch();
        if (!$entry) {
            return null;
        }
        $entry['lines'] = $this->query(
            "SELECT l.*, a.code account_code, a.name account_name
             FROM accounting_lines l
             JOIN accounting_accounts a ON a.id = l.account_id
             WHERE l.entry_id = ? ORDER BY l.id",
            [$id]
        )->fetchAll();
        return $entry;
    }

    public function trialBalance(string $from, string $to): array
    {
        return $this->query(
            "SELECT a.id, a.code, a.name, a.account_type, a.nature,
                    COALESCE(SUM(CASE WHEN e.entry_date < ? AND e.status IN ('posted','reversed')
                        THEN l.debit - l.credit ELSE 0 END), 0) opening_balance,
                    COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? AND e.status IN ('posted','reversed')
                        THEN l.debit ELSE 0 END), 0) debit,
                    COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? AND e.status IN ('posted','reversed')
                        THEN l.credit ELSE 0 END), 0) credit,
                    COALESCE(SUM(CASE WHEN e.entry_date <= ? AND e.status IN ('posted','reversed')
                        THEN l.debit - l.credit ELSE 0 END), 0) closing_balance
             FROM accounting_accounts a
             LEFT JOIN accounting_lines l ON l.account_id = a.id
             LEFT JOIN accounting_entries e ON e.id = l.entry_id
             GROUP BY a.id
             HAVING ABS(opening_balance) > 0.009 OR debit > 0 OR credit > 0
             ORDER BY a.code",
            [$from, $from, $to, $from, $to, $to]
        )->fetchAll();
    }

    public function ledger(int $accountId, string $from, string $to): array
    {
        return $this->query(
            "SELECT e.id entry_id, e.entry_number, e.entry_date, e.description entry_description,
                    l.description, l.debit, l.credit, l.third_party_name
             FROM accounting_lines l
             JOIN accounting_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?
               AND e.status IN ('posted','reversed')
             ORDER BY e.entry_date, e.id, l.id",
            [$accountId, $from, $to]
        )->fetchAll();
    }

    public function financialStatements(string $from, string $to): array
    {
        $rows = $this->trialBalance($from, $to);
        $result = [
            'assets' => [], 'liabilities' => [], 'equity' => [],
            'revenue' => [], 'expenses' => [],
            'totals' => ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'revenue' => 0, 'expenses' => 0],
        ];
        $typeKeyMap = [
            'asset' => 'assets',
            'liability' => 'liabilities',
            'equity' => 'equity',
            'revenue' => 'revenue',
            'expense' => 'expenses',
        ];
        foreach ($rows as $row) {
            $type = $row['account_type'];
            $key = $typeKeyMap[$type] ?? null;
            if ($key === null) {
                continue;
            }
            // P&L: movimiento del periodo. Balance: saldo acumulado a la fecha "to".
            if (in_array($type, ['revenue', 'expense'], true)) {
                $balance = (float)$row['debit'] - (float)$row['credit'];
                if (abs($balance) < 0.009) {
                    continue;
                }
            } else {
                $balance = (float)$row['closing_balance'];
            }
            $displayBalance = in_array($type, ['liability', 'equity', 'revenue'], true)
                ? -$balance
                : $balance;
            $row['display_balance'] = $displayBalance;
            $result[$key][] = $row;
            $result['totals'][$key] += $displayBalance;
        }
        $result['profit'] = $result['totals']['revenue'] - $result['totals']['expenses'];
        return $result;
    }

    public function closePeriod(int $year, int $month, bool $close, ?string $notes = null): void
    {
        if ($year < 2000 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Periodo inválido');
        }
        $this->query(
            "INSERT INTO accounting_periods (year, month, status, closed_at, closed_by, notes)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), closed_at = VALUES(closed_at),
                 closed_by = VALUES(closed_by), notes = VALUES(notes)",
            [
                $year,
                $month,
                $close ? 'closed' : 'open',
                $close ? date('Y-m-d H:i:s') : null,
                $close ? ($_SESSION['tenant_user_id'] ?? null) : null,
                $notes,
            ]
        );
    }

    public function periods(): array
    {
        return $this->query(
            "SELECT p.*, u.name closed_by_name
             FROM accounting_periods p
             LEFT JOIN users u ON u.id = p.closed_by
             ORDER BY p.year DESC, p.month DESC"
        )->fetchAll();
    }

    private function isPeriodClosed(string $date): bool
    {
        return (bool)$this->query(
            "SELECT 1 FROM accounting_periods
             WHERE year = YEAR(?) AND month = MONTH(?) AND status = 'closed' LIMIT 1",
            [$date, $date]
        )->fetchColumn();
    }

    private function nextEntryNumber(string $date): string
    {
        $prefix = 'CC-' . date('Ym', strtotime($date)) . '-';
        $last = $this->query(
            "SELECT entry_number FROM accounting_entries
             WHERE entry_number LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$prefix . '%']
        )->fetchColumn();
        $sequence = $last ? ((int)substr((string)$last, -6) + 1) : 1;
        return $prefix . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT);
    }

    private function accountId(string $code): int
    {
        $id = $this->query(
            "SELECT id FROM accounting_accounts
             WHERE code = ? AND status = 'active' AND accepts_entries = 1",
            [$code]
        )->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('Cuenta contable no disponible: ' . $code);
        }
        return (int)$id;
    }

    private function setting(string $key, string $default): string
    {
        $value = $this->query(
            "SELECT setting_value FROM accounting_settings WHERE setting_key = ?",
            [$key]
        )->fetchColumn();
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function paymentAccount(string $method): string
    {
        // Efectivo → caja; resto de medios electrónicos → banco
        if ($method === 'cash' || $method === '') {
            return $this->setting('cash_account', '110505');
        }
        if (function_exists('\\SoftNova\\Core\\is_electronic_payment')) {
            return \SoftNova\Core\is_electronic_payment($method)
                ? $this->setting('bank_account', '111005')
                : $this->setting('cash_account', '110505');
        }
        return in_array($method, ['card', 'debit_card', 'credit_card', 'dataphone', 'payment_link', 'transfer'], true)
            ? $this->setting('bank_account', '111005')
            : $this->setting('cash_account', '110505');
    }

    private function line(
        string $accountCode,
        float $debit,
        float $credit,
        string $description,
        ?string $thirdPartyType = null,
        mixed $thirdPartyId = null,
        ?string $thirdPartyName = null
    ): array {
        return [
            'account_code' => $accountCode,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'description' => $description,
            'third_party_type' => $thirdPartyType,
            'third_party_id' => $thirdPartyId,
            'third_party_name' => $thirdPartyName,
        ];
    }
    public function postPurchaseOrder(int $orderId): ?int
    {
        $purchasing = new PurchasingService($this->db);
        $order = $purchasing->getOrder($orderId);
        if (!$order || $order['status'] !== 'received') {
            throw new \RuntimeException('La orden debe estar recibida para contabilizar');
        }
        if (!empty($order['accounting_entry_id'])) {
            return (int)$order['accounting_entry_id'];
        }

        $subtotal = round((float)$order['subtotal'], 2);
        $tax = round((float)$order['tax'], 2);
        $total = round((float)$order['total'], 2);
        $discount = round((float)($order['early_payment_discount_amount'] ?? 0), 2);
        $gross = round($subtotal + $tax, 2);
        if ($total <= 0) {
            throw new \RuntimeException('La orden no tiene total contable');
        }

        $date = !empty($order['warehouse_date'])
            ? date('Y-m-d', strtotime((string)$order['warehouse_date']))
            : date('Y-m-d', strtotime((string)$order['order_date']));

        $paymentMode = (string)($order['payment_mode'] ?? 'payable');
        $creditCode = match ($paymentMode) {
            'cash' => $this->setting('cash_account', '110505'),
            'transfer', 'card' => $this->setting('bank_account', '111005'),
            default => $this->setting('payable_account', '220505'),
        };
        $creditLabel = match ($paymentMode) {
            'cash' => 'Pago compra en efectivo',
            'transfer', 'card' => 'Pago compra transferencia/tarjeta',
            default => 'Factura proveedor por pagar',
        };

        $label = 'OC ' . $order['order_number'];
        if (!empty($order['invoice_number'])) {
            $label .= ' / Factura ' . $order['invoice_number'];
        }

        $lines = [];
        if ($subtotal > 0) {
            $lines[] = $this->line(
                $this->setting('inventory_account', '143501'),
                $subtotal,
                0,
                'Entrada mercancía ' . $label
            );
        }
        if ($tax > 0) {
            $lines[] = $this->line(
                $this->setting('vat_deductible_account', '240802'),
                $tax,
                0,
                'IVA descontable ' . $label,
                'supplier',
                $order['supplier_id'] ?? null,
                $order['supplier_name'] ?? null
            );
        }
        if (empty($lines)) {
            $lines[] = $this->line(
                $this->setting('inventory_account', '143501'),
                $gross > 0 ? $gross : $total,
                0,
                'Entrada mercancía ' . $label
            );
        }

        if ($discount > 0.009) {
            $lines[] = $this->line(
                '421040',
                0,
                $discount,
                'Descuento pronto pago / aliado ' . $label,
                'supplier',
                $order['supplier_id'] ?? null,
                $order['supplier_name'] ?? null
            );
        }

        $lines[] = $this->line(
            $creditCode,
            0,
            $total,
            $creditLabel,
            'supplier',
            $order['supplier_id'] ?? null,
            $order['supplier_name'] ?? null
        );

        $entryId = $this->postEntry(
            $date,
            'Compra ' . $label,
            $lines,
            'purchase_order',
            $orderId,
            'received'
        );

        $this->query(
            "UPDATE purchase_orders SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $orderId]
        );
        $this->query(
            "UPDATE stock_movements
             SET accounting_entry_id = ?
             WHERE reference_type = 'purchase_order' AND reference_id = ?",
            [$entryId, $orderId]
        );

        return $entryId;
    }

    /**
     * Contabiliza un movimiento de inventario (compra, apertura o ajuste).
     * Las salidas por venta/devolución se omiten: ya van en postSale / reverseSale.
     */
    public function postStockMovement(int $movementId): ?int
    {
        $movement = $this->query(
            "SELECT sm.*, p.name product_name, p.code product_code, p.product_type,
                    s.name supplier_name
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id
             LEFT JOIN suppliers s ON s.id = sm.supplier_id
             WHERE sm.id = ?",
            [$movementId]
        )->fetch();

        if (!$movement) {
            throw new \RuntimeException('Movimiento de inventario no encontrado');
        }
        if (($movement['product_type'] ?? 'product') === 'service') {
            return null;
        }
        if (!empty($movement['accounting_entry_id'])) {
            return (int)$movement['accounting_entry_id'];
        }

        $referenceType = (string)($movement['reference_type'] ?? '');
        if (in_array($referenceType, ['sale', 'return', 'purchase_order'], true)) {
            // sale/return: asiento de venta. purchase_order: asiento de la OC (con IVA).
            return null;
        }

        $amount = round((float)($movement['total_cost'] ?? 0), 2);
        if ($amount <= 0) {
            $amount = round((float)($movement['unit_cost'] ?? 0) * abs((int)$movement['quantity']), 2);
        }
        if ($amount <= 0) {
            return null;
        }

        $qty = (int)$movement['quantity'];
        $label = trim(($movement['product_code'] ?? '') . ' ' . ($movement['product_name'] ?? ''));
        $date = !empty($movement['movement_date'])
            ? date('Y-m-d', strtotime((string)$movement['movement_date']))
            : date('Y-m-d', strtotime((string)$movement['created_at']));
        $inventory = $this->setting('inventory_account', '143501');
        $paymentMode = (string)($movement['payment_mode'] ?? '');
        $type = (string)$movement['type'];

        // Ajuste negativo o salida no-venta: merma / pérdida
        $isOutbound = $type === 'out'
            || ($type === 'adjustment' && ($paymentMode === 'loss' || str_contains((string)($movement['notes'] ?? ''), 'Ajuste (−)')));

        if ($isOutbound) {
            $lines = [
                $this->line(
                    $this->setting('inventory_adjustment_account', '519595'),
                    $amount,
                    0,
                    'Ajuste/merma inventario: ' . $label
                ),
                $this->line(
                    $inventory,
                    0,
                    $amount,
                    'Salida de inventario: ' . $label
                ),
            ];
            $description = 'Ajuste inventario (−) ' . $label;
        } else {
            // Entrada / apertura / ajuste positivo
            $creditCode = match ($paymentMode) {
                'cash' => $this->setting('cash_account', '110505'),
                'transfer', 'card' => $this->setting('bank_account', '111005'),
                'equity', 'opening' => $this->setting('opening_inventory_equity_account', '310505'),
                default => $this->setting('payable_account', '220505'),
            };
            $creditLabel = match ($paymentMode) {
                'cash' => 'Pago en efectivo',
                'transfer', 'card' => 'Pago por transferencia/tarjeta',
                'equity', 'opening' => 'Inventario inicial / capital',
                default => 'Cuenta por pagar proveedores',
            };

            $lines = [
                $this->line(
                    $inventory,
                    $amount,
                    0,
                    'Entrada inventario: ' . $label . ' x' . $qty
                ),
                $this->line(
                    $creditCode,
                    0,
                    $amount,
                    $creditLabel,
                    !empty($movement['supplier_id']) ? 'supplier' : null,
                    $movement['supplier_id'] ?? null,
                    $movement['supplier_name'] ?? null
                ),
            ];
            $description = ($referenceType === 'opening' || $paymentMode === 'equity' || $paymentMode === 'opening')
                ? ('Inventario inicial: ' . $label)
                : ('Compra/entrada inventario: ' . $label);
        }

        $entryId = $this->postEntry(
            $date,
            $description,
            $lines,
            'stock_movement',
            $movementId,
            'created'
        );

        $this->query(
            "UPDATE stock_movements SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $movementId]
        );

        return $entryId;
    }

    /**
     * Crea comprobantes para operaciones anteriores a la activación del módulo.
     * En gastos históricos se presume que el método registrado fue pagado.
     *
     * Procesa por lotes para no agotar el tiempo de ejecución con históricos grandes.
     * Devuelve 'remaining' > 0 cuando aún quedan operaciones por sincronizar,
     * de modo que la UI pueda invocar el proceso nuevamente.
     *
     * @param int $limit Máximo de operaciones a procesar por invocación (0 = sin límite).
     * @return array{sales:int,expenses:int,payments:int,stock:int,errors:int,remaining:int,done:bool}
     */

}
