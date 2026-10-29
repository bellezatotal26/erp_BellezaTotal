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

    /**
     * Prepara tablas/semilla fuera de cualquier transacción.
     * Obligatorio antes de beginTransaction() en flujos que luego contabilizan.
     */
    public static function warm(\PDO $db): void
    {
        $svc = new self($db);
        unset($svc);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $schemaDone = null;

    public function ensureSchema(): void
    {
        self::$schemaDone ??= new \WeakMap();
        if (isset(self::$schemaDone[$this->db])) {
            return;
        }

        // MySQL: CREATE/ALTER hacen COMMIT implícito — nunca mid-TX.
        if ($this->db->inTransaction()) {
            throw new \RuntimeException(
                'El esquema contable no estaba listo dentro de una transacción. '
                . 'Llame AccountingService::warm($db) antes de beginTransaction().'
            );
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

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS accounting_pending (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(40) NOT NULL,
                source_type VARCHAR(50) NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                meta_json TEXT NULL,
                last_error VARCHAR(500) NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_accounting_pending (action, source_type, source_id),
                INDEX idx_accounting_pending_status (status, id)
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

        // Asegura status soft-delete en expenses (consultas de sync lo usan).
        try {
            new ExpenseCategoryService($this->db);
        } catch (\Throwable $e) {
            // ignore
        }

        self::$schemaDone[$this->db] = true;
    }

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $seedDone = null;

    private function seedChartOfAccounts(): void
    {
        self::$seedDone ??= new \WeakMap();
        if (isset(self::$seedDone[$this->db])) {
            return;
        }
        self::$seedDone[$this->db] = true;

        $accounts = [
            ['110505', 'Caja general', 'asset', 'debit', 1],
            ['111005', 'Bancos y medios electrónicos', 'asset', 'debit', 1],
            ['130505', 'Clientes nacionales', 'asset', 'debit', 1],
            ['143501', 'Inventarios de mercancías', 'asset', 'debit', 1],
            ['143510', 'Mercancías en consignación (control)', 'asset', 'debit', 1],
            ['220505', 'Proveedores nacionales', 'liability', 'credit', 1],
            ['220510', 'Consignantes por pagar', 'liability', 'credit', 1],
            ['233595', 'Costos y gastos por pagar', 'liability', 'credit', 1],
            ['240801', 'IVA generado', 'liability', 'credit', 1],
            ['240802', 'IVA descontable', 'asset', 'debit', 1],
            ['310505', 'Capital social', 'equity', 'credit', 1],
            ['360505', 'Utilidad del ejercicio', 'equity', 'credit', 1],
            ['413501', 'Ingresos por ventas', 'revenue', 'credit', 1],
            ['417501', 'Devoluciones en ventas', 'revenue', 'debit', 1],
            ['421040', 'Descuentos en compras', 'revenue', 'credit', 1],
            ['429595', 'Otros ingresos no operacionales', 'revenue', 'credit', 1],
            ['510505', 'Gastos generales', 'expense', 'debit', 1],
            ['511570', 'Impuestos, gravámenes y tasas', 'expense', 'debit', 1],
            ['512010', 'Arrendamientos', 'expense', 'debit', 1],
            ['513095', 'Honorarios / asesoría', 'expense', 'debit', 1],
            ['513505', 'Servicios', 'expense', 'debit', 1],
            ['514505', 'Mantenimiento y reparaciones', 'expense', 'debit', 1],
            ['515010', 'Combustibles y lubricantes', 'expense', 'debit', 1],
            ['515505', 'Transporte', 'expense', 'debit', 1],
            ['516005', 'Publicidad y propaganda', 'expense', 'debit', 1],
            ['519595', 'Otros gastos operacionales', 'expense', 'debit', 1],
            ['530505', 'Gastos financieros y comisiones', 'expense', 'debit', 1],
            ['530510', 'Comisiones datáfono', 'expense', 'debit', 1],
            ['530515', 'Comisiones de ventas', 'expense', 'debit', 1],
            ['530525', 'Gravamen a los movimientos financieros', 'expense', 'debit', 1],
            ['539595', 'Otros gastos no operacionales', 'expense', 'debit', 1],
            ['613501', 'Costo de ventas', 'expense', 'debit', 1],
            ['613510', 'Costo de ventas consignadas', 'expense', 'debit', 1],
            ['135515', 'Retención en la fuente', 'asset', 'debit', 1],
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
            'consignment_inventory_account' => '143510',
            'payable_account' => '220505',
            'consignor_payable_account' => '220510',
            'expense_payable_account' => '233595',
            'vat_generated_account' => '240801',
            'vat_deductible_account' => '240802',
            'sales_account' => '413501',
            'sales_returns_account' => '417501',
            'general_expense_account' => '510505',
            'fixed_expense_account' => '510505',
            'financial_expense_account' => '530505',
            'cost_of_sales_account' => '613501',
            'consignment_cost_account' => '613510',
            'inventory_adjustment_account' => '519595',
            'inventory_loss_account' => '519595',
            'opening_inventory_equity_account' => '310505',
            'purchase_discount_account' => '421040',
            'cash_shortage_account' => '539595',
            'cash_overage_account' => '429595',
            'cash_manual_income_account' => '429595',
            'cash_manual_expense_account' => '519595',
            'retained_earnings_account' => '360505',
        ];
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)"
        );
        foreach ($settings as $settingKey => $value) {
            $stmt->execute([$settingKey, $value]);
        }
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
            '310505' => 'Capital social',
            '421040' => 'Descuentos en compras',
            '429595' => 'Otros ingresos no operacionales',
            '519595' => 'Otros gastos operacionales',
            '530505' => 'Gastos financieros y comisiones',
            '539595' => 'Otros gastos no operacionales',
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
                    str_starts_with($code, '3') => 'equity',
                    str_starts_with($code, '4') => 'revenue',
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
            'purchase_credit_policy' => 'cxp', // cxp = pendiente/parcial → Proveedores 220505
            'inventory_adjustment_account' => '519595',
            'opening_inventory_equity_account' => '310505',
            'purchase_discount_account' => '421040',
            'cash_shortage_account' => '539595',
            'cash_overage_account' => '429595',
        ];
        foreach ($settingsMap as $key => $value) {
            $this->query(
                "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)",
                [$key, $value]
            );
        }

        $notes = [
            'Compras pagadas: crédito a caja/banco según medio de pago.',
            'Compras pendientes o parciales: crédito a CxP proveedores (220505); los abonos posteriores la liquidan desde Compras.',
            'Comisiones datáfono (costo fijo + % débito/crédito) y link de pago: gasto a 530505 contra bancos 111005, en la fecha del pago. Si se configuran: IVA de la comisión a 240802 y retenciones del adquirente a 135515 / 135517 / 135518.',
            'Ajustes y mermas de inventario: gasto a 519595; el inventario inicial se acredita a 310505.',
            'Arqueo de caja: faltantes a 539595 y sobrantes a 429595.',
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

        // Montos reales por medio (incluye la parte electrónica de pagos mixtos).
        try {
            $sales = $this->query(
                "SELECT sp.payment_method,
                        COUNT(DISTINCT sp.sale_id) cnt,
                        COUNT(*) tx,
                        COALESCE(SUM(sp.amount), 0) total,
                        COALESCE(SUM(CASE WHEN s.total > 0
                            THEN sp.amount * LEAST(1, GREATEST(0, COALESCE(s.tax, 0) / s.total))
                            ELSE 0 END), 0) vat_part
                 FROM sale_payments sp
                 INNER JOIN sales s ON s.id = sp.sale_id
                 WHERE COALESCE(s.status, '') <> 'cancelled'
                   AND sp.payment_method IN ({$placeholders})
                   AND DATE(s.sale_date) BETWEEN ? AND ?
                 GROUP BY sp.payment_method",
                $params
            )->fetchAll();
        } catch (\Throwable $e) {
            $sales = $this->query(
                "SELECT payment_method,
                        COUNT(*) cnt,
                        COALESCE(SUM(total), 0) total,
                        COALESCE(SUM(tax), 0) vat_part
                 FROM sales
                 WHERE COALESCE(status, '') <> 'cancelled'
                   AND payment_method IN ({$placeholders})
                   AND DATE(sale_date) BETWEEN ? AND ?
                 GROUP BY payment_method",
                $params
            )->fetchAll();
        }

        // Datáfono = débito + crédito (y legado card/dataphone): fijo por transacción + % por tipo.
        $commissions = new CommissionService($this->db);
        $commCfg = $commissions->config();
        $fixedFee = (float)($commCfg['dataphone_fixed_fee'] ?? 0);
        $vatRate = (float)($commCfg['gateway_vat_rate'] ?? 0);
        $vatDeductible = !empty($commCfg['gateway_vat_deductible']);
        $salesTotal = 0.0;
        $dataphoneSales = 0.0;
        $dataphoneTx = 0;
        $cardSales = 0.0;
        $expectedDataphone = 0.0;
        $expectedCard = 0.0;
        $expectedWithholdings = 0.0;
        foreach ($sales as $row) {
            $amt = (float)$row['total'];
            $tx = (int)($row['tx'] ?? $row['cnt'] ?? 0);
            $vatPart = min($amt, max(0.0, (float)($row['vat_part'] ?? 0)));
            $salesTotal += $amt;
            $m = (string)$row['payment_method'];
            $pctPart = round($amt * $commissions->gatewayRate($m) / 100, 2);
            $commission = 0.0;
            if (CommissionService::isDataphoneMethod($m)) {
                $dataphoneSales += $amt;
                $dataphoneTx += $tx;
                $commission = $pctPart + round($fixedFee * $tx, 2);
                $expectedDataphone += $commission;
            } elseif ($m === 'payment_link') {
                $cardSales += $amt;
                $commission = $pctPart;
                $expectedCard += $commission;
            }
            if ($commission > 0) {
                $net = $amt - $vatPart;
                $expectedWithholdings += $net * (float)$commCfg['withholding_renta_rate'] / 100
                    + $vatPart * (float)$commCfg['withholding_iva_rate'] / 100
                    + $net * (float)$commCfg['withholding_ica_rate'] / 1000;
            }
        }
        $expectedDataphone = round($expectedDataphone, 2);
        $expectedCard = round($expectedCard, 2);
        $expectedCommission = round($expectedDataphone + $expectedCard, 2);
        $expectedVat = round($expectedCommission * $vatRate / 100, 2);
        $expectedWithholdings = round($expectedWithholdings, 2);
        // Lo comparable con Gastos: comisión (+ IVA si no es descontable, va al gasto).
        $expected = $vatDeductible ? $expectedCommission : round($expectedCommission + $expectedVat, 2);
        $rateDataphone = $commissions->gatewayRate('debit_card');
        $rateCard = $commissions->gatewayRate('payment_link');

        $expenses = $this->query(
            "SELECT COALESCE(category,'general') category,
                    COUNT(*) cnt,
                    COALESCE(SUM(amount), 0) total
             FROM expenses
             WHERE expense_date BETWEEN ? AND ?
               AND COALESCE(status, 'active') <> 'cancelled'
               AND category IN ('dataphone_commission','card_commission','payment_link_commission')
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
            'dataphone_tx' => $dataphoneTx,
            'dataphone_fixed_fee' => $fixedFee,
            'card_sales' => $cardSales,
            'rate_dataphone' => $rateDataphone,
            'rate_card' => $rateCard,
            'expected_dataphone' => $expectedDataphone,
            'expected_card' => $expectedCard,
            'expected_commission' => $expectedCommission,
            'expected_vat' => $expectedVat,
            'vat_deductible' => $vatDeductible,
            'expected_withholdings' => $expectedWithholdings,
            'expected_total' => $expected,
            'recorded_expenses' => $expenses,
            'recorded_total' => $recorded,
            'recorded_dataphone' => $recordedDataphone,
            'gap' => $gap,
            'suggest_amount' => max(0, $gap),
            'suggest_category' => 'dataphone_commission',
            'suggest_description' => 'Comisión datáfono/link de pago ' . $from . ' a ' . $to,
        ];
    }

    /**
     * Margen de ventas agrupado por canal de producto (manual / compra / woo / ml).
     *
     * @return list<array{channel:string,revenue:float,cost:float,profit:float,margin:float,qty:int}>
     */
    public function marginByChannel(string $from, string $to): array
    {
        $hiddenSql = $this->schemaHasColumn('sale_items', 'document_hidden')
            ? ' AND COALESCE(si.document_hidden, 0) = 0'
            : '';
        $rows = $this->query(
            "SELECT COALESCE(NULLIF(p.source_channel,''), 'manual') channel,
                    SUM(si.quantity) qty,
                    COALESCE(SUM(si.subtotal), 0) revenue,
                    COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0) cost
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN products p ON p.id = si.product_id
             WHERE COALESCE(s.status, '') <> 'cancelled' AND DATE(s.sale_date) BETWEEN ? AND ?{$hiddenSql}
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
        $natureGiven = in_array($nature, ['debit', 'credit'], true);
        if (!$natureGiven) {
            $nature = in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
        }
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $acceptsEntries = !empty($data['accepts_entries']);

        $duplicate = $this->query(
            "SELECT id FROM accounting_accounts WHERE code = ? AND id <> ? LIMIT 1",
            [$code, $id]
        )->fetchColumn();
        if ($duplicate) {
            throw new \InvalidArgumentException('Ya existe una cuenta con el código ' . $code);
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
                if ($status === 'inactive') {
                    throw new \InvalidArgumentException('No se puede desactivar una cuenta de sistema');
                }
                if (!$acceptsEntries) {
                    throw new \InvalidArgumentException(
                        'Una cuenta de sistema debe recibir movimientos: las ventas, compras y gastos la usan.'
                    );
                }
            }

            $existingCode = (string)$existing['code'];
            $usedBy = $this->query(
                "SELECT setting_key FROM accounting_settings
                 WHERE setting_value = ? AND setting_key LIKE '%account%'
                 ORDER BY setting_key",
                [$existingCode]
            )->fetchAll(\PDO::FETCH_COLUMN);
            $usedLabel = implode(', ', array_slice($usedBy, 0, 4)) . (count($usedBy) > 4 ? '…' : '');
            if ($usedBy) {
                if ($code !== $existingCode) {
                    throw new \InvalidArgumentException(
                        'No se puede cambiar el código: la cuenta ' . $existingCode
                        . ' está configurada en los parámetros contables (' . $usedLabel . ').'
                    );
                }
                if ($status === 'inactive' || !$acceptsEntries) {
                    throw new \InvalidArgumentException(
                        'La cuenta ' . $existingCode . ' está configurada en los parámetros contables ('
                        . $usedLabel . '). Si la desactiva o le quita movimientos, las operaciones no se podrán contabilizar.'
                    );
                }
            }

            $movements = $this->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(l.debit - l.credit), 0) balance
                 FROM accounting_lines l
                 JOIN accounting_entries e ON e.id = l.entry_id
                 WHERE l.account_id = ? AND e.status IN ('posted','reversed')",
                [$id]
            )->fetch() ?: ['cnt' => 0, 'balance' => 0];
            if ((int)$movements['cnt'] > 0) {
                if (!$natureGiven) {
                    $nature = (string)$existing['nature'];
                }
                if ($type !== (string)$existing['account_type'] || $nature !== (string)$existing['nature']) {
                    throw new \InvalidArgumentException(
                        'La cuenta ' . $existingCode . ' ya tiene movimientos: no se puede cambiar su tipo ni su naturaleza '
                        . '(reclasificaría la historia en los estados financieros). Cree una cuenta nueva y traslade el saldo con un comprobante.'
                    );
                }
                if ($code !== $existingCode) {
                    throw new \InvalidArgumentException(
                        'La cuenta ' . $existingCode . ' ya tiene movimientos: no se puede cambiar su código.'
                    );
                }
                $balance = round((float)$movements['balance'], 2);
                if ($status === 'inactive' && abs($balance) >= 0.01) {
                    throw new \InvalidArgumentException(
                        'La cuenta ' . $existingCode . ' tiene saldo (' . number_format($balance, 2, ',', '.')
                        . '). Traslade el saldo a otra cuenta antes de desactivarla.'
                    );
                }
            }

            $this->query(
                "UPDATE accounting_accounts
                 SET code = ?, name = ?, account_type = ?, nature = ?,
                     accepts_entries = ?, status = ?
                 WHERE id = ?",
                [
                    $code, $name, $type, $nature,
                    $acceptsEntries ? 1 : 0,
                    $status,
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
                $acceptsEntries ? 1 : 0,
                $status,
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
        // Periodo cerrado: bloquear (sin re-fechar silenciosamente a otro mes).
        $this->assertPeriodOpen($date);

        $normalized = [];
        $totalDebitCents = 0;
        $totalCreditCents = 0;
        foreach ($lines as $line) {
            // Importes en centavos enteros para evitar drift de float.
            $debitCents = (int)round(max(0, (float)($line['debit'] ?? 0)) * 100);
            $creditCents = (int)round(max(0, (float)($line['credit'] ?? 0)) * 100);
            if (($debitCents > 0 && $creditCents > 0) || ($debitCents <= 0 && $creditCents <= 0)) {
                throw new \InvalidArgumentException('Cada línea debe tener solo débito o crédito');
            }

            $debit = $debitCents / 100;
            $credit = $creditCents / 100;
            $accountId = $this->accountId((string)($line['account_code'] ?? ''));
            $normalized[] = array_merge($line, [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
            ]);
            $totalDebitCents += $debitCents;
            $totalCreditCents += $creditCents;
        }

        if (count($normalized) < 2 || $totalDebitCents !== $totalCreditCents) {
            throw new \InvalidArgumentException('El comprobante no está balanceado');
        }

        if ($sourceType !== null && $sourceId !== null && $sourceEvent !== null) {
            $existing = $this->query(
                "SELECT id FROM accounting_entries
                 WHERE source_type = ? AND source_id = ? AND source_event = ?
                   AND status = 'posted'
                 LIMIT 1",
                [$sourceType, $sourceId, $sourceEvent]
            )->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            // Re-chequeo dentro de la TX (concurrencia / doble clic).
            if ($sourceType !== null && $sourceId !== null && $sourceEvent !== null) {
                $existing = $this->query(
                    "SELECT id FROM accounting_entries
                     WHERE source_type = ? AND source_id = ? AND source_event = ?
                       AND status = 'posted'
                     LIMIT 1 FOR UPDATE",
                    [$sourceType, $sourceId, $sourceEvent]
                )->fetchColumn();
                if ($existing) {
                    if ($ownTx) {
                        $this->db->commit();
                    }
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

            if ($ownTx) {
                $this->db->commit();
            }

            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
        $deliveryFee = round((float)($sale['delivery_fee'] ?? 0), 2);
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
        if ($deliveryFee > 0) {
            $lines[] = $this->line(
                $this->setting('sales_account', '413501'),
                0,
                $deliveryFee,
                'Domicilio / envío'
            );
        }
        $credited = round($subtotal + $tax + $deliveryFee, 2);
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

        // Costo: mercancía propia (CMV ↔ inventario) vs consignada (costo ↔ CxP consignante).
        // Excluir document_hidden (componentes de combo): el costo va en la línea padre.
        $hideSql = $this->schemaHasColumn('sale_items', 'document_hidden')
            ? ' AND COALESCE(si.document_hidden, 0) = 0'
            : '';
        $costOwned = 0.0;
        $costConsignment = 0.0;
        try {
            $costOwned = (float)$this->query(
                "SELECT COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0)
                 FROM sale_items si
                 JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id = ?
                   AND COALESCE(p.product_type, 'product') = 'product'
                   AND COALESCE(p.ownership, 'owned') = 'owned'
                   AND COALESCE(si.is_consignment, 0) = 0
                   {$hideSql}",
                [$saleId]
            )->fetchColumn();
            $costConsignment = (float)$this->query(
                "SELECT COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0)
                 FROM sale_items si
                 JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id = ?
                   AND COALESCE(p.product_type, 'product') = 'product'
                   AND (
                        COALESCE(si.is_consignment, 0) = 1
                        OR COALESCE(p.ownership, 'owned') = 'consignment'
                   )
                   {$hideSql}",
                [$saleId]
            )->fetchColumn();
        } catch (\Throwable $e) {
            // Fallback installs sin columnas ownership / is_consignment
            $costOwned = (float)$this->query(
                "SELECT COALESCE(SUM(si.quantity * COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)), 0)
                 FROM sale_items si
                 JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id = ? AND COALESCE(p.product_type, 'product') = 'product'
                   {$hideSql}",
                [$saleId]
            )->fetchColumn();
        }
        if ($costOwned > 0) {
            $lines[] = $this->line(
                $this->setting('cost_of_sales_account', '613501'),
                $costOwned,
                0,
                'Costo de mercancía vendida'
            );
            $lines[] = $this->line(
                $this->setting('inventory_account', '143501'),
                0,
                $costOwned,
                'Salida de inventario propio'
            );
        }
        if ($costConsignment > 0) {
            $lines[] = $this->line(
                $this->setting('consignment_cost_account', '613510'),
                $costConsignment,
                0,
                'Costo consignación vendida'
            );
            $lines[] = $this->line(
                $this->setting('consignor_payable_account', '220510'),
                0,
                $costConsignment,
                'Por pagar a consignantes'
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

        $paymentDate = date('Y-m-d', strtotime($payment['payment_date']));
        return $this->postEntry(
            $paymentDate,
            'Abono venta ' . $payment['invoice_number'],
            [
                $this->line(
                    $this->collectionAccount((string)$payment['payment_method'], $paymentDate),
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

    /**
     * @param bool $affectCashDrawer Si true, el pago salió del cajón físico (movimiento de caja).
     * @param bool|null $paidNow Si null, coincide con $affectCashDrawer. Si true sin cajón, contabiliza pago (banco, etc.).
     */
    public function postExpense(int $expenseId, bool $affectCashDrawer, ?bool $paidNow = null): int
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
        if (($expense['status'] ?? 'active') === 'cancelled') {
            throw new \RuntimeException('Gasto anulado; no se contabiliza');
        }

        if ($paidNow === null) {
            $paidNow = $affectCashDrawer;
        }

        $category = (string)($expense['category'] ?? 'general');
        $categoryId = (int)($expense['category_id'] ?? 0);
        $categoryLabel = $category;
        $group = 'operating';
        $expenseAccount = null;
        $catRow = null;

        // Preferir catálogo tipado (expense_categories) sobre códigos legados del config.
        if ($categoryId > 0) {
            try {
                $catSvc = new ExpenseCategoryService($this->db);
                $cat = $catSvc->find($categoryId);
                if ($cat) {
                    $catRow = $cat;
                    $categoryLabel = (string)($cat['name'] ?? $category);
                    $kind = (string)($cat['kind'] ?? 'operational');
                    if ($kind === 'administrative' || $kind === 'financial' || $kind === 'management') {
                        $group = 'administrative';
                    } else {
                        $group = 'operating';
                    }
                    $code = trim((string)($cat['account_code'] ?? ''));
                    if ($code !== '') {
                        $expenseAccount = $code;
                    }
                }
            } catch (\Throwable $e) {
                // fallback legado abajo
            }
        }

        if ($expenseAccount === null) {
            $group = function_exists('\\SoftNova\\Core\\expense_category_group')
                ? \SoftNova\Core\expense_category_group($category)
                : $group;
            $expenseAccount = match ($group) {
                'financial' => $this->setting('financial_expense_account', '530505'),
                'fixed' => $category === 'payroll'
                    ? $this->setting('payroll_expense_account', $this->setting('fixed_expense_account', '510505'))
                    : $this->setting('fixed_expense_account', '510505'),
                default => $this->setting('general_expense_account', '510505'),
            };
            if (function_exists('\\SoftNova\\Core\\expense_category_label')) {
                $categoryLabel = \SoftNova\Core\expense_category_label($category);
            }
        }

        $paymentMethod = (string)($expense['payment_method'] ?? 'cash');
        if ($affectCashDrawer) {
            $creditCode = $this->paymentAccount($paymentMethod);
        } elseif ($paidNow) {
            $creditCode = $this->paidExpenseCreditAccount($paymentMethod, $catRow);
        } else {
            $creditCode = $this->setting('expense_payable_account', '233595');
        }

        $groupLabel = 'Gastos operativos';
        if ($catRow) {
            $catKind = (string)($catRow['kind'] ?? 'operational');
            if (ExpenseCategoryService::isAccountingKind($catKind)) {
                $catSvc = new ExpenseCategoryService($this->db);
                $groupLabel = $catSvc->resolveKindGroupLabel($catKind);
            }
        } else {
            $groupLabel = match ($group) {
                'financial' => ExpenseCategoryService::kindGroupLabel('financial'),
                'administrative' => ExpenseCategoryService::kindGroupLabel('administrative'),
                'fixed' => 'Gastos fijos',
                default => (function_exists('\\SoftNova\\Core\\expense_group_label')
                    ? \SoftNova\Core\expense_group_label($group)
                    : 'Gastos operativos'),
            };
        }

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
                    $affectCashDrawer ? 'Pago desde cajón' : ($paidNow ? 'Pago de gasto' : 'Gasto por pagar'),
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
     * Gasto de comisión (vendedor o pasarela) con cuentas específicas.
     * Crédito típico: CxP (vendedor) o bancos (pasarela / retención del adquirente).
     *
     * @param list<array{0:string,1:float,2:string}> $extraDebits [cuenta, valor, descripción]
     *        (IVA descontable, anticipos de retención); se suman al crédito.
     */
    public function postCommissionExpense(
        int $expenseId,
        string $expenseAccountCode,
        float $amount,
        string $date,
        string $description,
        string $creditAccountCode,
        array $extraDebits = []
    ): int {
        $lines = [$this->line($expenseAccountCode, $amount, 0, 'Comisión sobre ventas')];
        $credit = round($amount, 2);
        foreach ($extraDebits as [$code, $value, $label]) {
            $value = round((float)$value, 2);
            if ($value <= 0) {
                continue;
            }
            $lines[] = $this->line((string)$code, $value, 0, (string)$label);
            $credit = round($credit + $value, 2);
        }
        $lines[] = $this->line($creditAccountCode, 0, $credit, 'Comisión por pagar / banco');

        return $this->postEntry(
            $date,
            $description,
            $lines,
            'expense',
            $expenseId,
            'created'
        );
    }

    /**
     * Asiento detallado de nómina: sueldos + aportes patronales/parafiscales
     * contra CxP de seguridad social y banco/caja (neto).
     */
    /**
     * Totales contables de una liquidación (misma base del asiento).
     * company_cost = todo lo que va al gasto: devengados + comisiones + provisiones + aportes.
     *
     * @return array<string,float>
     */
    public function payrollRunTotals(int $runId): array
    {
        $sum = $this->query(
            "SELECT
                COALESCE(SUM(salary_base + transport_aid + COALESCE(prima,0)
                    + COALESCE(incapacity_pay,0) + other_earnings),0) labor,
                COALESCE(SUM(COALESCE(cesantias,0)),0) cesantias,
                COALESCE(SUM(COALESCE(cesantias_interest,0)),0) cesantias_interest,
                COALESCE(SUM(COALESCE(commission_amount,0)),0) commission,
                COALESCE(SUM(COALESCE(vacation_provision,0)),0) vacation,
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

        $out = [];
        foreach (['labor', 'cesantias', 'cesantias_interest', 'commission', 'vacation', 'health_emp', 'pension_emp',
            'health_er', 'pension_er', 'arl', 'caja', 'sena', 'icbf', 'net'] as $k) {
            $out[$k] = round((float)($sum[$k] ?? 0), 2);
        }
        $out['employer_cost'] = round(
            $out['health_er'] + $out['pension_er'] + $out['arl'] + $out['caja'] + $out['sena'] + $out['icbf'],
            2
        );
        $out['company_cost'] = round(
            $out['labor'] + $out['commission'] + $out['cesantias'] + $out['cesantias_interest']
                + $out['vacation'] + $out['employer_cost'],
            2
        );
        return $out;
    }

    /**
     * Fecha de causación de la nómina: el pago si cae dentro del mes liquidado;
     * si no, el último día de ese mes (el costo queda en su periodo).
     */
    public static function payrollAccrualDate(array $run): string
    {
        $year = (int)($run['period_year'] ?? 0);
        $month = (int)($run['period_month'] ?? 0);
        $payDate = (string)($run['pay_date'] ?? '');
        if ($year < 2000 || $month < 1 || $month > 12) {
            return $payDate !== '' ? $payDate : date('Y-m-d');
        }
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));
        if ($payDate >= $periodStart && $payDate <= $periodEnd) {
            return $payDate;
        }
        return $periodEnd;
    }

    public function postPayrollRun(int $runId, bool $payNow = true): int
    {
        (new PayrollService($this->db))->params();

        $run = $this->query('SELECT * FROM payroll_runs WHERE id = ?', [$runId])->fetch();
        if (!$run || ($run['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Liquidación inválida');
        }
        $t = $this->payrollRunTotals($runId);

        $labor = $t['labor'];
        $cesantias = $t['cesantias'];
        $cesInterest = $t['cesantias_interest'];
        $commission = $t['commission'];
        $vacation = $t['vacation'];
        $healthEmp = $t['health_emp'];
        $pensionEmp = $t['pension_emp'];
        $healthEr = $t['health_er'];
        $pensionEr = $t['pension_er'];
        $arl = $t['arl'];
        $caja = $t['caja'];
        $sena = $t['sena'];
        $icbf = $t['icbf'];
        $net = $t['net'];
        $employerCost = $t['employer_cost'];

        if ($labor <= 0 && $employerCost <= 0 && $cesantias <= 0 && $cesInterest <= 0 && $commission <= 0 && $vacation <= 0) {
            throw new \RuntimeException('Liquidación sin montos contables');
        }

        $accrualDate = self::payrollAccrualDate($run);
        $payDate = (string)$run['pay_date'];
        // Pagada después del cierre del mes: se causa contra salarios por pagar y el pago va en su fecha.
        $splitPayment = $payNow && $payDate > $accrualDate;

        $lines = [];
        if ($labor > 0) {
            $lines[] = $this->line(
                $this->setting('payroll_expense_account', '510503'),
                $labor,
                0,
                'Devengados nómina ' . $run['run_number']
            );
        }
        if ($commission > 0) {
            $lines[] = $this->line(
                $this->setting('payroll_commission_account', '510518'),
                $commission,
                0,
                'Comisiones fijas (no salariales) ' . $run['run_number']
            );
        }
        if ($cesantias > 0) {
            $lines[] = $this->line(
                $this->setting('payroll_expense_account', '510503'),
                $cesantias,
                0,
                'Provisión cesantías ' . $run['run_number']
            );
        }
        if ($cesInterest > 0) {
            $lines[] = $this->line(
                $this->setting('payroll_expense_account', '510503'),
                $cesInterest,
                0,
                'Provisión intereses cesantías ' . $run['run_number']
            );
        }
        if ($vacation > 0) {
            $lines[] = $this->line(
                $this->setting('vacation_expense_account', '510539'),
                $vacation,
                0,
                'Provisión vacaciones ' . $run['run_number']
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
        if ($cesantias > 0) {
            $lines[] = $this->line(
                $this->setting('cesantias_payable_account', '261005'),
                0,
                $cesantias,
                'Cesantías por pagar'
            );
        }
        if ($cesInterest > 0) {
            $lines[] = $this->line(
                $this->setting('cesantias_interest_payable_account', '261010'),
                0,
                $cesInterest,
                'Intereses cesantías por pagar'
            );
        }
        if ($vacation > 0) {
            $lines[] = $this->line(
                $this->setting('vacation_payable_account', '261015'),
                0,
                $vacation,
                'Vacaciones consolidadas'
            );
        }
        $method = (string)($run['payment_method'] ?? 'transfer');
        $paidInEntry = $payNow && !$splitPayment;
        if ($net > 0) {
            $creditNet = $this->payrollNetCreditAccount($method, $paidInEntry);
            $lines[] = $this->line(
                $creditNet,
                0,
                $net,
                $paidInEntry ? 'Neto nómina (no descuenta cajón POS)' : 'Salarios por pagar'
            );
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $entryId = $this->postEntry(
                $accrualDate,
                'Nómina ' . $run['run_number'] . ' · ' . $run['period_label'],
                $lines,
                'payroll',
                $runId,
                'created'
            );
            if ($splitPayment && $net > 0) {
                $this->postEntry(
                    $payDate,
                    'Pago nómina ' . $run['run_number'] . ' · ' . $run['period_label'],
                    [
                        $this->line($this->setting('payroll_payable_account', '250505'), $net, 0, 'Pago salarios por pagar'),
                        $this->line($this->payrollPaymentAccount($method), 0, $net, 'Neto nómina (no descuenta cajón POS)'),
                    ],
                    'payroll',
                    $runId,
                    'payment'
                );
            }
            if ($ownTx) {
                $this->db->commit();
            }
            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Revierte todos los asientos de una liquidación (causación y pago separado).
     *
     * @return list<int> ids de las reversiones
     */
    public function reversePayrollRun(int $runId, string $reason): array
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $rev = $this->reverseSource('payroll', $runId, $reason);
            if ($rev === null) {
                break;
            }
            $ids[] = $rev;
        }
        return $ids;
    }

    /**
     * Pago de pasivos laborales (aportes PILA, parafiscales, cesantías, intereses, vacaciones, salarios por pagar).
     * Débito a cada pasivo contra banco o caja de nómina. No genera gasto: el costo ya se causó en la nómina.
     */
    public function postPayrollLiabilityPayment(int $paymentId): int
    {
        (new PayrollService($this->db))->params();

        $payment = $this->query(
            'SELECT * FROM payroll_liability_payments WHERE id = ?',
            [$paymentId]
        )->fetch();
        if (!$payment || ($payment['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Pago de nómina inválido');
        }
        $rows = $this->query(
            'SELECT * FROM payroll_liability_payment_lines WHERE payment_id = ? ORDER BY id',
            [$paymentId]
        )->fetchAll();

        $lines = [];
        $total = 0.0;
        $concepts = PayrollService::LIABILITY_CONCEPTS;
        foreach ($rows as $row) {
            $amount = round((float)$row['amount'], 2);
            if ($amount <= 0) {
                continue;
            }
            $label = $concepts[(string)$row['concept']]['label'] ?? (string)$row['concept'];
            $lines[] = $this->line((string)$row['account_code'], $amount, 0, 'Pago ' . $label);
            $total += $amount;
        }
        $total = round($total, 2);
        if ($total <= 0) {
            throw new \RuntimeException('El pago no tiene valores');
        }
        $lines[] = $this->line(
            $this->payrollPaymentAccount((string)($payment['payment_method'] ?? 'transfer')),
            0,
            $total,
            'Pago nómina (no descuenta cajón POS)'
        );

        $ref = trim((string)($payment['reference'] ?? ''));
        return $this->postEntry(
            (string)$payment['payment_date'],
            'Pago ' . (PayrollService::LIABILITY_KINDS[(string)($payment['kind'] ?? '')] ?? 'nómina')
                . ($ref !== '' ? ' · ' . $ref : ''),
            $lines,
            'payroll_payment',
            $paymentId,
            'created'
        );
    }

    /**
     * Saldo actual (naturaleza crédito) de cuentas de pasivo laboral.
     *
     * @param list<string> $codes
     * @return array<string,float>
     */
    public function liabilityBalances(array $codes, ?string $asOf = null): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if (!$codes) {
            return [];
        }
        $params = $codes;
        $dateSql = '';
        if ($asOf !== null && $asOf !== '') {
            $dateSql = ' AND e.entry_date <= ?';
            $params[] = $asOf;
        }
        $rows = $this->query(
            "SELECT a.code, COALESCE(SUM(l.credit - l.debit), 0) bal
             FROM accounting_accounts a
             LEFT JOIN accounting_lines l ON l.account_id = a.id
             LEFT JOIN accounting_entries e ON e.id = l.entry_id
             WHERE a.code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")
               AND (e.id IS NULL OR (e.status IN ('posted','reversed'){$dateSql}))
             GROUP BY a.code",
            $params
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach ($codes as $code) {
            $out[$code] = round((float)($rows[$code] ?? 0), 2);
        }
        return $out;
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
             FROM expenses
             WHERE expense_date BETWEEN ? AND ?
               AND COALESCE(status, 'active') <> 'cancelled'
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
     * @return array{sales:int,expenses:int,payments:int,purchases:int,stock:int,supplier_payments:int,cash:int,errors:int,remaining:int,done:bool}
     */
    public function syncExistingOperations(int $limit = 200): array
    {
        $result = [
            'sales' => 0,
            'expenses' => 0,
            'payments' => 0,
            'purchases' => 0,
            'stock' => 0,
            'supplier_payments' => 0,
            'cash' => 0,
            'sale_credit_notes' => 0,
            'purchase_credit_notes' => 0,
            'pending_queue' => 0,
            'commissions' => 0,
            'errors' => 0,
            'remaining' => 0,
            'done' => true,
        ];
        $limit = max(0, $limit);
        $budget = $limit > 0 ? $limit : PHP_INT_MAX;
        $limitSql = $limit > 0 ? ' LIMIT ' . ((int)$limit + 1) : '';

        // Primero reintentar la cola de fallos explícitos (anulaciones / ediciones).
        if ($budget > 0) {
            $queued = $this->processPendingQueue(min(50, $budget));
            $result['pending_queue'] = (int)($queued['done'] ?? 0);
            $result['errors'] += (int)($queued['errors'] ?? 0);
            $budget -= (int)($queued['processed'] ?? 0);
        }

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
                "SELECT x.id, x.payment_method, x.category, x.category_id, x.notes
                 FROM expenses x
                 LEFT JOIN accounting_entries e
                   ON e.source_type = 'expense' AND e.source_id = x.id AND e.source_event = 'created'
                 WHERE e.id IS NULL
                   AND COALESCE(x.status, 'active') <> 'cancelled'
                   AND COALESCE(x.payment_method, '') <> 'inventory_loss'
                   AND COALESCE(x.category, '') <> 'payroll'
                   AND COALESCE(x.notes, '') NOT LIKE '%[salida_inventario:%'
                   AND COALESCE(x.notes, '') NOT LIKE '%asiento contable #%'
                 ORDER BY x.id" . $expenseLimitSql
            )->fetchAll();
            foreach ($expenses as $expense) {
                if ($budget <= 0) {
                    break;
                }
                try {
                    $flags = $this->resolveExpensePostFlags($expense);
                    $this->postExpense((int)$expense['id'], $flags['affect_cash'], $flags['paid_now']);
                    $result['expenses']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    error_log('Accounting sync expense ' . $expense['id'] . ': ' . $e->getMessage());
                }
                $budget--;
            }
        }

        // Comisiones de pasarela cuyo gasto/asiento falló al registrar el pago.
        if ($budget > 0 && !$this->db->inTransaction()) {
            try {
                $retry = (new CommissionService($this->db))->settlePendingGateway(min(100, $budget));
                $result['commissions'] = $retry['settled'];
                $result['errors'] += $retry['errors'];
                $budget -= $retry['processed'];
            } catch (\Throwable $e) {
                error_log('Accounting sync commissions: ' . $e->getMessage());
            }
        }

        foreach ($this->historicalSyncSources() as $key => $source) {
            if ($budget <= 0) {
                break;
            }
            $rows = [];
            try {
                $rows = $this->query(
                    $source['sql'] . ' LIMIT ' . ((int)$budget + 1)
                )->fetchAll();
            } catch (\Throwable $e) {
                // Tabla ausente en instalaciones parciales: se omite el origen.
                continue;
            }
            foreach ($rows as $row) {
                if ($budget <= 0) {
                    break;
                }
                try {
                    if ($source['post']((int)$row['id']) !== null) {
                        $result[$key]++;
                    }
                } catch (\Throwable $e) {
                    $result['errors']++;
                    error_log('Accounting sync ' . $key . ' ' . $row['id'] . ': ' . $e->getMessage());
                }
                $budget--;
            }
        }

        $result['remaining'] = $this->pendingSyncCount();
        $result['done'] = $result['remaining'] === 0;
        return $result;
    }

    /**
     * Misma regla que Alta de Gastos: efectivo contable → banco; operativo → caja.
     *
     * @param array<string,mixed> $expense
     * @return array{affect_cash:bool,paid_now:bool}
     */
    private function resolveExpensePostFlags(array $expense): array
    {
        $method = strtolower(trim((string)($expense['payment_method'] ?? 'cash')));
        if ($method === '') {
            $method = 'cash';
        }
        $onAccount = in_array($method, ['credit', 'payable', 'account', 'on_account'], true);
        $isCashPay = $method === 'cash' || (
            class_exists(PaymentMethodCatalog::class) && PaymentMethodCatalog::affectsCash($method)
        );

        $cat = null;
        $categoryId = (int)($expense['category_id'] ?? 0);
        if ($categoryId > 0) {
            try {
                $cat = (new ExpenseCategoryService($this->db))->find($categoryId);
            } catch (\Throwable $e) {
                $cat = null;
            }
        }

        $touchCajon = false;
        if ($isCashPay && $cat) {
            try {
                $touchCajon = (new ExpenseCategoryService($this->db))->affectsCashDrawer($cat);
            } catch (\Throwable $e) {
                $touchCajon = false;
            }
        } elseif ($isCashPay && !$cat) {
            // Sin categoría tipada: no asumir cajón (evita descuadre 110505 vs 111005).
            $touchCajon = false;
        }

        if ($onAccount) {
            return ['affect_cash' => false, 'paid_now' => false];
        }

        return [
            'affect_cash' => $touchCajon,
            'paid_now' => true,
        ];
    }

    /**
     * Encola un asiento fallido para reintento vía «Sincronizar».
     */
    public function enqueuePending(
        string $action,
        string $sourceType,
        int $sourceId,
        string $error,
        ?array $meta = null
    ): void {
        if ($sourceId <= 0 || $action === '' || $sourceType === '') {
            return;
        }
        try {
            $this->query(
                "INSERT INTO accounting_pending
                    (action, source_type, source_id, meta_json, last_error, attempts, status)
                 VALUES (?, ?, ?, ?, ?, 1, 'pending')
                 ON DUPLICATE KEY UPDATE
                    meta_json = VALUES(meta_json),
                    last_error = VALUES(last_error),
                    attempts = accounting_pending.attempts + 1,
                    status = 'pending',
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $action,
                    $sourceType,
                    $sourceId,
                    $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                    mb_substr($error, 0, 500),
                ]
            );
        } catch (\Throwable $e) {
            error_log('accounting_pending enqueue: ' . $e->getMessage());
        }
    }

    public function pendingQueueCount(): int
    {
        try {
            return (int)$this->query(
                "SELECT COUNT(*) FROM accounting_pending WHERE status = 'pending'"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{processed:int,done:int,errors:int}
     */
    public function processPendingQueue(int $limit = 50): array
    {
        $out = ['processed' => 0, 'done' => 0, 'errors' => 0];
        $limit = max(1, min(200, $limit));
        try {
            $rows = $this->query(
                "SELECT * FROM accounting_pending
                 WHERE status = 'pending'
                 ORDER BY id ASC
                 LIMIT " . $limit
            )->fetchAll();
        } catch (\Throwable $e) {
            return $out;
        }

        foreach ($rows as $row) {
            $out['processed']++;
            $id = (int)$row['id'];
            $action = (string)$row['action'];
            $sourceType = (string)$row['source_type'];
            $sourceId = (int)$row['source_id'];
            $meta = [];
            if (!empty($row['meta_json'])) {
                $decoded = json_decode((string)$row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            try {
                match ($action) {
                    'post_sale' => $this->postSaleCascade($sourceId),
                    'post_sale_payment' => $this->postSalePayment($sourceId),
                    'post_expense' => (function () use ($sourceId, $meta): void {
                        $expense = $this->query(
                            "SELECT id, payment_method, category, category_id, notes, status FROM expenses WHERE id = ?",
                            [$sourceId]
                        )->fetch() ?: ['id' => $sourceId];
                        if (($expense['status'] ?? 'active') === 'cancelled') {
                            return; // anulado: no re-postear
                        }
                        // Preferir flags actuales del gasto (meta solo si la fila no aporta datos).
                        $flags = $this->resolveExpensePostFlags($expense);
                        $affect = $flags['affect_cash'];
                        $paid = $flags['paid_now'];
                        if (empty($expense['payment_method']) && empty($expense['category_id'])) {
                            if (array_key_exists('affect_cash', $meta)) {
                                $affect = (bool)$meta['affect_cash'];
                            }
                            if (array_key_exists('paid_now', $meta)) {
                                $paid = (bool)$meta['paid_now'];
                            }
                        }
                        $this->postExpense($sourceId, $affect, $paid);
                    })(),
                    'post_purchase_order' => $this->postPurchaseOrder($sourceId),
                    'post_purchase_credit_note' => $this->postPurchaseCreditNote($sourceId),
                    'post_sale_credit_note' => $this->postSaleCreditNote($sourceId),
                    'post_supplier_payment' => $this->postSupplierPayment($sourceId),
                    'post_cash_session' => $this->postCashSessionClosing($sourceId),
                    'post_cash_movement' => $this->postCashMovement($sourceId),
                    'reverse_source' => $this->reverseSource(
                        $sourceType,
                        $sourceId,
                        (string)($meta['reason'] ?? 'Reintento de reversión')
                    ),
                    'void_purchase_order' => $this->voidPurchaseOrderEntry(
                        $sourceId,
                        (string)($meta['reason'] ?? 'Reintento de anulación de OC')
                    ),
                    'reverse_sale_cascade' => $this->reverseSaleCascade(
                        $sourceId,
                        (string)($meta['reason'] ?? 'Reintento de reversión de venta')
                    ),
                    'post_stock_movement' => $this->postStockMovement($sourceId),
                    default => throw new \RuntimeException('Acción pendiente desconocida: ' . $action),
                };
                $this->query(
                    "UPDATE accounting_pending SET status = 'done', last_error = NULL WHERE id = ?",
                    [$id]
                );
                $out['done']++;
            } catch (\Throwable $e) {
                $out['errors']++;
                $attempts = (int)($row['attempts'] ?? 0) + 1;
                $status = $attempts >= 8 ? 'failed' : 'pending';
                $this->query(
                    "UPDATE accounting_pending
                     SET attempts = ?, last_error = ?, status = ?
                     WHERE id = ?",
                    [$attempts, mb_substr($e->getMessage(), 0, 500), $status, $id]
                );
                error_log('accounting_pending #' . $id . ': ' . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * Orígenes históricos contabilizables además de ventas, abonos y gastos.
     * Cada consulta devuelve solo los registros que aún no tienen comprobante.
     *
     * @return array<string,array{sql:string,post:callable(int):?int}>
     */
    private function historicalSyncSources(): array
    {
        return [
            'purchases' => [
                'sql' => "SELECT o.id
                          FROM purchase_orders o
                          LEFT JOIN accounting_entries e
                            ON e.source_type = 'purchase_order' AND e.source_id = o.id
                               AND e.source_event = 'received'
                          WHERE o.status = 'received' AND o.accounting_entry_id IS NULL AND e.id IS NULL
                          ORDER BY o.id",
                'post' => fn(int $id): ?int => $this->postPurchaseOrder($id),
            ],
            'stock' => [
                'sql' => "SELECT sm.id
                          FROM stock_movements sm
                          JOIN products p ON p.id = sm.product_id
                               AND COALESCE(p.product_type, 'product') = 'product'
                          WHERE sm.accounting_entry_id IS NULL
                            AND COALESCE(sm.total_cost, 0) > 0
                            AND COALESCE(sm.reference_type, '') NOT IN ('sale', 'return', 'sale_credit_note', 'purchase_order', 'purchase_credit_note', 'purchase_cancel', 'loan', 'loan_return', 'loan_received', 'loan_received_return', 'status_change', 'consignment_in', 'consignment_return')
                          ORDER BY sm.id",
                'post' => fn(int $id): ?int => $this->postStockMovement($id),
            ],
            'supplier_payments' => [
                'sql' => "SELECT sp.id
                          FROM supplier_payments sp
                          WHERE sp.accounting_entry_id IS NULL AND sp.status = 'applied'
                          ORDER BY sp.id",
                'post' => fn(int $id): ?int => $this->postSupplierPayment($id),
            ],
            'cash' => [
                'sql' => "SELECT cm.id
                          FROM cash_movements cm
                          WHERE cm.accounting_entry_id IS NULL
                            AND COALESCE(cm.reference_type, 'manual') IN ('manual', '')
                          ORDER BY cm.id",
                'post' => fn(int $id): ?int => $this->postCashMovement($id),
            ],
            'sale_credit_notes' => [
                'sql' => "SELECT cn.id
                          FROM sale_credit_notes cn
                          LEFT JOIN accounting_entries e
                            ON e.source_type = 'sale_credit_note' AND e.source_id = cn.id
                               AND e.reversal_of_id IS NULL
                          WHERE cn.status = 'posted'
                            AND cn.accounting_entry_id IS NULL
                            AND e.id IS NULL
                          ORDER BY cn.id",
                'post' => fn(int $id): ?int => $this->postSaleCreditNote($id),
            ],
            'purchase_credit_notes' => [
                'sql' => "SELECT cn.id
                          FROM purchase_credit_notes cn
                          LEFT JOIN accounting_entries e
                            ON e.source_type = 'purchase_credit_note' AND e.source_id = cn.id
                               AND e.reversal_of_id IS NULL
                          WHERE cn.status = 'posted'
                            AND cn.accounting_entry_id IS NULL
                            AND e.id IS NULL
                          ORDER BY cn.id",
                'post' => fn(int $id): ?int => $this->postPurchaseCreditNote($id),
            ],
            'cash_sessions' => [
                'sql' => "SELECT cs.id
                          FROM cash_sessions cs
                          WHERE cs.status = 'closed'
                            AND cs.closing_entry_id IS NULL
                            AND ABS(COALESCE(cs.difference_amount, 0)) >= 0.01
                          ORDER BY cs.id",
                'post' => fn(int $id): ?int => $this->postCashSessionClosing($id),
            ],
        ];
    }

    /** Cuenta operaciones históricas aún sin asiento contable. */
    /**
     * @param bool $quick true = solo ventas/abonos/gastos/cola (rápido para dashboard).
     *                    false = incluye orígenes históricos (compras, stock, caja…).
     */
    public function pendingSyncCount(bool $quick = false): int
    {
        $pendingSales = 0;
        $pendingPayments = 0;
        $pendingExpenses = 0;
        // En modo quick: tope por origen (evita COUNT full-table que tumba /app/contabilidad).
        $capSql = $quick ? ' LIMIT 501' : '';
        try {
            $pendingSales = (int)$this->query(
                "SELECT COUNT(*) FROM (
                    SELECT s.id FROM sales s
                    LEFT JOIN accounting_entries e
                      ON e.source_type = 'sale' AND e.source_id = s.id AND e.source_event = 'created'
                    WHERE s.status != 'cancelled' AND e.id IS NULL
                    {$capSql}
                 ) t"
            )->fetchColumn();
            $pendingPayments = (int)$this->query(
                "SELECT COUNT(*) FROM (
                    SELECT p.id FROM sale_payments p
                    JOIN sales s ON s.id = p.sale_id AND s.status != 'cancelled'
                    INNER JOIN accounting_entries se
                      ON se.source_type = 'sale' AND se.source_id = s.id AND se.source_event = 'created'
                    LEFT JOIN accounting_entries pe
                      ON pe.source_type = 'sale_payment' AND pe.source_id = p.id AND pe.source_event = 'created'
                    WHERE pe.id IS NULL
                    {$capSql}
                 ) t"
            )->fetchColumn();
            $pendingExpenses = (int)$this->query(
                "SELECT COUNT(*) FROM (
                    SELECT x.id FROM expenses x
                    LEFT JOIN accounting_entries e
                      ON e.source_type = 'expense' AND e.source_id = x.id AND e.source_event = 'created'
                    WHERE COALESCE(x.status, 'active') <> 'cancelled'
                      AND COALESCE(x.payment_method, '') <> 'inventory_loss'
                      AND COALESCE(x.category, '') <> 'payroll'
                      AND COALESCE(x.notes, '') NOT LIKE '%[salida_inventario:%'
                      AND COALESCE(x.notes, '') NOT LIKE '%asiento contable #%'
                      AND e.id IS NULL
                    {$capSql}
                 ) t"
            )->fetchColumn();
        } catch (\Throwable $e) {
            error_log('pendingSyncCount core: ' . $e->getMessage());
        }

        $pendingOthers = 0;
        if (!$quick) {
            foreach ($this->historicalSyncSources() as $source) {
                try {
                    // Tope por origen: evita COUNT enorme que tumba el request.
                    $pendingOthers += (int)$this->query(
                        'SELECT COUNT(*) FROM (' . $source['sql'] . ' LIMIT 501) pending'
                    )->fetchColumn();
                } catch (\Throwable $e) {
                    // Origen no disponible en esta instalación.
                }
            }
        }

        $pendingCommissions = 0;
        try {
            $pendingCommissions = (int)$this->query(
                "SELECT COUNT(*) FROM sales_commissions
                 WHERE commission_kind = 'gateway' AND status = 'pending'"
            )->fetchColumn();
        } catch (\Throwable $e) {
            // Tabla de comisiones aún no creada.
        }

        return $pendingSales + $pendingPayments + $pendingExpenses + $pendingOthers
            + $pendingCommissions + $this->pendingQueueCount();
    }

    /**
     * Anulación de OC recibida (sin NC): reversa el asiento de compra con la misma fecha
     * del original para que el par se cancele en el mismo periodo y no aparezca en el diario.
     * Si ese periodo está cerrado, la reversión va al periodo abierto y sí queda visible.
     */
    public function voidPurchaseOrderEntry(int $orderId, string $reason): ?int
    {
        $date = (string)$this->query(
            "SELECT entry_date FROM accounting_entries
             WHERE source_type = 'purchase_order' AND source_id = ? AND status = 'posted'
               AND reversal_of_id IS NULL
             ORDER BY id DESC LIMIT 1",
            [$orderId]
        )->fetchColumn();
        if ($date === '') {
            return null;
        }
        $date = substr($date, 0, 10);
        if ($this->isPeriodClosed($date)) {
            return $this->reverseSource('purchase_order', $orderId, $reason);
        }
        return $this->reverseSource('purchase_order', $orderId, $reason, $date, 'void_');
    }

    /** SQL: excluye del diario/mayor los pares asiento+anulación de OC del mismo día. */
    private function voidedPairExclusionSql(string $alias): string
    {
        return "NOT EXISTS (
                    SELECT 1 FROM accounting_entries vx
                    JOIN accounting_entries ox ON ox.id = vx.reversal_of_id
                    WHERE LEFT(vx.source_event, 5) = 'void_'
                      AND vx.entry_date = ox.entry_date
                      AND (vx.id = {$alias}.id OR ox.id = {$alias}.id)
                )";
    }

    public function reverseSource(
        string $sourceType,
        int $sourceId,
        string $reason,
        ?string $preferredDate = null,
        string $eventPrefix = 'reversal_'
    ): ?int {
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

        // Misma fecha del original si el periodo sigue abierto; si no, exigir reapertura
        // (no mover silenciosamente a otro mes).
        $postingDate = $this->resolveOpenPostingDate(
            $preferredDate,
            (string)$entry['entry_date'],
            'No se puede revertir el comprobante ' . $entry['entry_number']
            . ': el periodo está cerrado. Reabra el periodo en Contabilidad → Periodos '
            . 'o registre un ajuste manual con referencia al documento.'
        );

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $reversalId = $this->postEntry(
                $postingDate,
                'Reversión ' . $entry['entry_number'] . ': ' . $reason,
                $lines,
                $sourceType,
                $sourceId,
                $eventPrefix . $entry['id'],
                (int)$entry['id']
            );
            $this->query(
                "UPDATE accounting_entries SET status = 'reversed' WHERE id = ?",
                [$entry['id']]
            );
            if ($ownTx) {
                $this->db->commit();
            }
            return $reversalId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Anula un comprobante manual con un asiento espejo (queda la traza de ambos).
     * Los automáticos se anulan desde su documento origen (venta, gasto, compra...).
     */
    public function voidManualEntry(int $entryId, string $reason): int
    {
        $reason = trim($reason);
        if (strlen($reason) < 5) {
            throw new \InvalidArgumentException('Indique el motivo de la anulación (mínimo 5 caracteres)');
        }
        $entry = $this->query(
            "SELECT * FROM accounting_entries WHERE id = ? LIMIT 1",
            [$entryId]
        )->fetch();
        if (!$entry) {
            throw new \InvalidArgumentException('Comprobante no encontrado');
        }
        if (!self::isManualEntry($entry)) {
            throw new \InvalidArgumentException(
                'Solo se pueden anular comprobantes manuales. Los automáticos se anulan desde su documento (venta, gasto, compra…).'
            );
        }
        if (!empty($entry['reversal_of_id'])) {
            throw new \InvalidArgumentException('Este comprobante ya es una anulación');
        }
        if (($entry['status'] ?? '') !== 'posted') {
            throw new \InvalidArgumentException('El comprobante ya fue anulado');
        }

        $rows = $this->query(
            "SELECT l.*, a.code account_code
             FROM accounting_lines l
             JOIN accounting_accounts a ON a.id = l.account_id
             WHERE l.entry_id = ? ORDER BY l.id",
            [$entryId]
        )->fetchAll();
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'account_code' => $row['account_code'],
                'debit' => (float)$row['credit'],
                'credit' => (float)$row['debit'],
                'description' => $row['description'] ?: $reason,
                'third_party_type' => $row['third_party_type'],
                'third_party_id' => $row['third_party_id'],
                'third_party_name' => $row['third_party_name'],
            ];
        }

        $postingDate = $this->resolveOpenPostingDate(
            (string)$entry['entry_date'],
            date('Y-m-d'),
            'No se puede anular el comprobante ' . $entry['entry_number']
            . ': el periodo está cerrado. Reabra el periodo o use un ajuste en un mes abierto.'
        );

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $locked = $this->query(
                "SELECT status FROM accounting_entries WHERE id = ? FOR UPDATE",
                [$entryId]
            )->fetchColumn();
            if ($locked !== 'posted') {
                throw new \InvalidArgumentException('El comprobante ya fue anulado');
            }
            $reversalId = $this->postEntry(
                $postingDate,
                'Anulación ' . $entry['entry_number'] . ': ' . $reason,
                $lines,
                'manual',
                $entryId,
                'reversal_' . $entryId,
                $entryId
            );
            $this->query(
                "UPDATE accounting_entries SET status = 'reversed' WHERE id = ?",
                [$entryId]
            );
            if ($ownTx) {
                $this->db->commit();
            }
            return $reversalId;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public static function isManualEntry(array $entry): bool
    {
        $type = (string)($entry['source_type'] ?? '');
        return ($type === 'manual' || $type === '') && empty($entry['source_id']);
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
               AND " . $this->voidedPairExclusionSql('e') . "
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
        $entry['source_document'] = $this->sourceDocumentForEntry($entry);
        $entry['is_manual'] = self::isManualEntry($entry);
        $entry['reversed_by'] = null;
        if (($entry['status'] ?? '') === 'reversed') {
            $entry['reversed_by'] = $this->query(
                "SELECT id, entry_number, entry_date, description FROM accounting_entries
                 WHERE reversal_of_id = ? ORDER BY id DESC LIMIT 1",
                [$id]
            )->fetch() ?: null;
        }
        $entry['reversal_of'] = null;
        if (!empty($entry['reversal_of_id'])) {
            $entry['reversal_of'] = $this->query(
                "SELECT id, entry_number, entry_date FROM accounting_entries WHERE id = ? LIMIT 1",
                [(int)$entry['reversal_of_id']]
            )->fetch() ?: null;
        }
        return $entry;
    }

    /**
     * Datos del documento origen (gasto, venta, etc.) para el detalle del comprobante.
     *
     * @return array<string,mixed>|null
     */
    private function sourceDocumentForEntry(array $entry): ?array
    {
        $type = (string)($entry['source_type'] ?? '');
        $sourceId = (int)($entry['source_id'] ?? 0);
        if ($sourceId <= 0 || $type === '') {
            return null;
        }

        if ($type === 'expense') {
            try {
                $row = $this->query(
                    "SELECT e.id, e.description, e.amount, e.category, e.expense_date,
                            e.payment_method, e.receipt_number, e.receipt_path, e.receipt_mime,
                            e.notes, ec.name AS category_label, ec.kind AS category_kind,
                            COALESCE(s.name, '') AS supplier_name
                     FROM expenses e
                     LEFT JOIN expense_categories ec ON ec.id = e.category_id
                     LEFT JOIN suppliers s ON s.id = e.supplier_id
                     WHERE e.id = ?",
                    [$sourceId]
                )->fetch();
            } catch (\Throwable $e) {
                return null;
            }
            if (!$row) {
                return null;
            }

            $notes = trim((string)($row['notes'] ?? ''));
            $cashExplanation = '';
            if (preg_match('/(?:Efectivo|Origen pago):\s*([^|]+)/u', $notes, $m)) {
                $cashExplanation = trim($m[1]);
            }

            $paymentMethod = (string)($row['payment_method'] ?? 'cash');
            $paymentLabel = class_exists(PaymentMethodCatalog::class)
                ? PaymentMethodCatalog::label($paymentMethod)
                : $paymentMethod;

            return [
                'type' => 'expense',
                'id' => (int)$row['id'],
                'description' => (string)$row['description'],
                'amount' => (float)$row['amount'],
                'category' => (string)($row['category_label'] ?? $row['category'] ?? ''),
                'category_kind' => (string)($row['category_kind'] ?? ''),
                'supplier_name' => (string)$row['supplier_name'],
                'expense_date' => (string)$row['expense_date'],
                'payment_method' => $paymentMethod,
                'payment_label' => $paymentLabel,
                'receipt_number' => (string)($row['receipt_number'] ?? ''),
                'has_receipt' => !empty($row['receipt_path']),
                'receipt_mime' => (string)($row['receipt_mime'] ?? ''),
                'notes' => $notes,
                'cash_explanation' => $cashExplanation,
            ];
        }

        if ($type === 'sale') {
            try {
                $row = $this->query(
                    "SELECT s.id, s.invoice_number, s.total, s.sale_date, s.payment_method,
                            s.payment_status, s.status, COALESCE(c.name, '') AS customer_name
                     FROM sales s
                     LEFT JOIN customers c ON c.id = s.customer_id
                     WHERE s.id = ?",
                    [$sourceId]
                )->fetch();
            } catch (\Throwable $e) {
                return null;
            }
            if (!$row) {
                return null;
            }
            $paymentMethod = (string)($row['payment_method'] ?? 'cash');
            return [
                'type' => 'sale',
                'id' => (int)$row['id'],
                'invoice_number' => (string)$row['invoice_number'],
                'total' => (float)$row['total'],
                'sale_date' => (string)$row['sale_date'],
                'payment_method' => $paymentMethod,
                'payment_label' => PaymentMethodCatalog::label($paymentMethod),
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'customer_name' => (string)$row['customer_name'],
            ];
        }

        if ($type === 'sale_payment') {
            try {
                $row = $this->query(
                    "SELECT p.id, p.amount, p.payment_date, p.payment_method, p.notes,
                            s.id AS sale_id, s.invoice_number, COALESCE(c.name, '') AS customer_name
                     FROM sale_payments p
                     JOIN sales s ON s.id = p.sale_id
                     LEFT JOIN customers c ON c.id = s.customer_id
                     WHERE p.id = ?",
                    [$sourceId]
                )->fetch();
            } catch (\Throwable $e) {
                return null;
            }
            if (!$row) {
                return null;
            }
            $paymentMethod = (string)($row['payment_method'] ?? 'cash');
            return [
                'type' => 'sale_payment',
                'id' => (int)$row['id'],
                'sale_id' => (int)$row['sale_id'],
                'invoice_number' => (string)$row['invoice_number'],
                'amount' => (float)$row['amount'],
                'payment_date' => (string)$row['payment_date'],
                'payment_method' => $paymentMethod,
                'payment_label' => PaymentMethodCatalog::label($paymentMethod),
                'notes' => (string)($row['notes'] ?? ''),
                'customer_name' => (string)$row['customer_name'],
            ];
        }

        if ($type === 'cash_movement') {
            try {
                $row = $this->query(
                    "SELECT cm.id, cm.type, cm.amount, cm.description, cm.reference_type,
                            cm.reference_id, cm.created_at, u.name AS user_name
                     FROM cash_movements cm
                     LEFT JOIN users u ON u.id = cm.user_id
                     WHERE cm.id = ?",
                    [$sourceId]
                )->fetch();
            } catch (\Throwable $e) {
                return null;
            }
            if (!$row) {
                return null;
            }
            return [
                'type' => 'cash_movement',
                'id' => (int)$row['id'],
                'movement_type' => (string)$row['type'],
                'amount' => (float)$row['amount'],
                'description' => (string)$row['description'],
                'reference_type' => (string)($row['reference_type'] ?? ''),
                'reference_id' => (int)($row['reference_id'] ?? 0),
                'created_at' => (string)$row['created_at'],
                'user_name' => (string)($row['user_name'] ?? ''),
            ];
        }

        if ($type === 'sale_credit_note') {
            try {
                $svc = new SalesCreditNoteService($this->db);
                $note = $svc->getCreditNote($sourceId);
            } catch (\Throwable $e) {
                return null;
            }
            if (!$note) {
                return null;
            }
            return [
                'type' => 'sale_credit_note',
                'id' => (int)$note['id'],
                'credit_number' => (string)($note['credit_number'] ?? ''),
                'invoice_number' => (string)($note['invoice_number'] ?? ''),
                'total' => (float)($note['total'] ?? 0),
                'credit_date' => (string)($note['credit_date'] ?? ''),
                'reason' => (string)($note['reason'] ?? ''),
                'refund_method' => (string)($note['refund_method'] ?? 'auto'),
                'affects_stock' => (int)($note['affects_stock'] ?? 0) === 1,
                'customer_name' => (string)($note['customer_name'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * Balance de prueba clásico (saldo inicial / movimientos / saldo final en Debe-Haber).
     *
     * @param array{account_type?:string,include_zero?:bool,q?:string} $options
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   totals:array<string,float>,
     *   balanced:array{opening:bool,period:bool,closing:bool},
     *   from:string,to:string
     * }
     */
    public function trialBalance(string $from, string $to, array $options = []): array
    {
        $accountType = strtolower(trim((string)($options['account_type'] ?? '')));
        $includeZero = !empty($options['include_zero']);
        $q = trim((string)($options['q'] ?? ''));

        $params = [$from, $from, $to, $from, $to, $to];
        $typeSql = '';
        if (in_array($accountType, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
            $typeSql = ' AND a.account_type = ?';
            $params[] = $accountType;
        }
        $qSql = '';
        if ($q !== '') {
            $qSql = ' AND (a.code LIKE ? OR a.name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Solo asientos posted + reversed.
        // Pares void OC mismo día: anti-join a un set materializado (evita NOT EXISTS correlacionado por línea).
        $rows = $this->query(
            "SELECT a.id, a.code, a.name, a.account_type, a.nature,
                    COALESCE(SUM(CASE WHEN e.entry_date < ? AND e.status IN ('posted','reversed')
                        THEN l.debit - l.credit ELSE 0 END), 0) AS opening_balance,
                    COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? AND e.status IN ('posted','reversed')
                        THEN l.debit ELSE 0 END), 0) AS debit,
                    COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? AND e.status IN ('posted','reversed')
                        THEN l.credit ELSE 0 END), 0) AS credit,
                    COALESCE(SUM(CASE WHEN e.entry_date <= ? AND e.status IN ('posted','reversed')
                        THEN l.debit - l.credit ELSE 0 END), 0) AS closing_balance
             FROM accounting_accounts a
             LEFT JOIN accounting_lines l ON l.account_id = a.id
             LEFT JOIN accounting_entries e ON e.id = l.entry_id
             LEFT JOIN (
                    SELECT vx.id AS eid
                    FROM accounting_entries vx
                    JOIN accounting_entries ox ON ox.id = vx.reversal_of_id
                    WHERE LEFT(vx.source_event, 5) = 'void_'
                      AND vx.entry_date = ox.entry_date
                    UNION
                    SELECT ox.id AS eid
                    FROM accounting_entries vx
                    JOIN accounting_entries ox ON ox.id = vx.reversal_of_id
                    WHERE LEFT(vx.source_event, 5) = 'void_'
                      AND vx.entry_date = ox.entry_date
             ) void_pairs ON void_pairs.eid = e.id
             WHERE void_pairs.eid IS NULL
               {$typeSql} {$qSql}
             GROUP BY a.id, a.code, a.name, a.account_type, a.nature
             ORDER BY a.code",
            $params
        )->fetchAll();

        $enriched = [];
        $totals = [
            'opening_debit' => 0.0,
            'opening_credit' => 0.0,
            'debit' => 0.0,
            'credit' => 0.0,
            'closing_debit' => 0.0,
            'closing_credit' => 0.0,
            'accounts' => 0.0,
        ];

        foreach ($rows as $row) {
            $opening = round((float)$row['opening_balance'], 2);
            $debit = round((float)$row['debit'], 2);
            $credit = round((float)$row['credit'], 2);
            $closing = round((float)$row['closing_balance'], 2);

            if (
                !$includeZero
                && abs($opening) < 0.009
                && abs($debit) < 0.009
                && abs($credit) < 0.009
                && abs($closing) < 0.009
            ) {
                continue;
            }

            $openingDebit = $opening > 0.009 ? $opening : 0.0;
            $openingCredit = $opening < -0.009 ? abs($opening) : 0.0;
            $closingDebit = $closing > 0.009 ? $closing : 0.0;
            $closingCredit = $closing < -0.009 ? abs($closing) : 0.0;

            $row['opening_balance'] = $opening;
            $row['debit'] = $debit;
            $row['credit'] = $credit;
            $row['closing_balance'] = $closing;
            $row['opening_debit'] = $openingDebit;
            $row['opening_credit'] = $openingCredit;
            $row['closing_debit'] = $closingDebit;
            $row['closing_credit'] = $closingCredit;
            $row['account_type_label'] = match ((string)($row['account_type'] ?? '')) {
                'asset' => 'Activo',
                'liability' => 'Pasivo',
                'equity' => 'Patrimonio',
                'revenue' => 'Ingreso',
                'expense' => 'Gasto',
                default => (string)($row['account_type'] ?? ''),
            };
            $row['nature_label'] = (($row['nature'] ?? '') === 'credit') ? 'Crédito' : 'Débito';

            $enriched[] = $row;
            $totals['opening_debit'] += $openingDebit;
            $totals['opening_credit'] += $openingCredit;
            $totals['debit'] += $debit;
            $totals['credit'] += $credit;
            $totals['closing_debit'] += $closingDebit;
            $totals['closing_credit'] += $closingCredit;
            $totals['accounts'] += 1;
        }

        foreach ($totals as $k => $v) {
            if ($k !== 'accounts') {
                $totals[$k] = round((float)$v, 2);
            }
        }

        $balanced = [
            'opening' => abs($totals['opening_debit'] - $totals['opening_credit']) < 0.02,
            'period' => abs($totals['debit'] - $totals['credit']) < 0.02,
            'closing' => abs($totals['closing_debit'] - $totals['closing_credit']) < 0.02,
        ];

        return [
            'rows' => $enriched,
            'totals' => $totals,
            'balanced' => $balanced,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Compatibilidad: lista plana de filas (p. ej. estados financieros).
     *
     * @return list<array<string,mixed>>
     */
    public function trialBalanceRows(string $from, string $to): array
    {
        return $this->trialBalance($from, $to)['rows'];
    }

    /** @var array<string, bool> */
    private static array $schemaColumnCache = [];

    /** @var array<string, bool> */
    private static array $schemaTableCache = [];

    private function schemaHasTable(string $table): bool
    {
        if (!array_key_exists($table, self::$schemaTableCache)) {
            try {
                // information_schema: comparación exacta (SHOW TABLES LIKE trata "_" como comodín
                // y el escape previo borraba guiones bajos → sale_items / purchase_orders daban false).
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    self::$schemaTableCache[$table] = false;
                } else {
                    $stmt = $this->query(
                        'SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                         LIMIT 1',
                        [$table]
                    );
                    self::$schemaTableCache[$table] = (bool)$stmt->fetchColumn();
                }
            } catch (\Throwable $e) {
                self::$schemaTableCache[$table] = false;
            }
        }
        return self::$schemaTableCache[$table];
    }

    private function schemaHasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$schemaColumnCache)) {
            try {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    self::$schemaColumnCache[$key] = false;
                } else {
                    $stmt = $this->query(
                        'SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                         LIMIT 1',
                        [$table, $column]
                    );
                    self::$schemaColumnCache[$key] = (bool)$stmt->fetchColumn();
                }
            } catch (\Throwable $e) {
                self::$schemaColumnCache[$key] = false;
            }
        }
        return self::$schemaColumnCache[$key];
    }

    /**
     * Balance operativo mensual (estilo Excel del cliente).
     * Fuentes: ventas completed, NC venta, facturas OC received, abonos proveedor, gastos.
     *
     * @return array{
     *   year:int,
     *   months:list<array<string,mixed>>,
     *   totals:array<string,float>,
     *   chart:array<string,list<float|string>>,
     *   meta:array<string,mixed>
     * }
     */
    public function monthlyOperationalBalance(int $year): array
    {
        if ($year < 2000 || $year > 2100) {
            $year = (int)date('Y');
        }

        $from = sprintf('%04d-01-01', $year);
        $toExcl = sprintf('%04d-01-01', $year + 1);
        $warnings = [];

        $empty = static function (int $m) use ($year): array {
            $names = [
                1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
            ];
            if ($m < 1 || $m > 12) {
                return [
                    'month' => $m,
                    'month_num' => '',
                    'month_name' => '',
                    'month_label' => '',
                    'month_short' => '',
                    'period_from' => '',
                    'period_to' => '',
                    'ingresos' => 0.0,
                    'costo_venta' => 0.0,
                    'facturas_total' => 0.0,
                    'facturas_contado' => 0.0,
                    'facturas_credito' => 0.0,
                    'facturas_pagadas' => 0.0,
                    'facturas_descuentos' => 0.0,
                    'facturas_pendientes' => 0.0,
                    'utilidad_bruta' => 0.0,
                    'utilidad_operacional' => 0.0,
                    'utilidad_neta' => 0.0,
                    'gastos_operativos' => 0.0,
                    'gastos_fijos' => 0.0,
                    'gastos_financieros' => 0.0,
                ];
            }
            $num = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            $name = $names[$m];
            $fromDay = sprintf('%04d-%02d-01', $year, $m);
            return [
                'month' => $m,
                'month_num' => $num,
                'month_name' => $name,
                'month_label' => $num . ' · ' . $name . ' ' . $year,
                'month_short' => $num . '/' . $year,
                'period_from' => $fromDay,
                'period_to' => date('Y-m-t', strtotime($fromDay) ?: time()),
                'ingresos' => 0.0,
                'costo_venta' => 0.0,
                'facturas_total' => 0.0,
                'facturas_contado' => 0.0,
                'facturas_credito' => 0.0,
                'facturas_pagadas' => 0.0,
                'facturas_descuentos' => 0.0,
                'facturas_pendientes' => 0.0,
                'utilidad_bruta' => 0.0,
                'utilidad_operacional' => 0.0,
                'utilidad_neta' => 0.0,
                'gastos_operativos' => 0.0,
                'gastos_fijos' => 0.0,
                'gastos_financieros' => 0.0,
            ];
        };

        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $byMonth[$m] = $empty($m);
        }

        $addMonth = static function (array &$target, int $m, string $key, float $value): void {
            if ($m < 1 || $m > 12 || abs($value) < 0.00001) {
                return;
            }
            $target[$m][$key] = round((float)$target[$m][$key] + $value, 2);
        };

        $costExpr = $this->schemaHasColumn('sale_items', 'unit_cost')
            ? 'COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)'
            : 'COALESCE(p.purchase_price, 0)';
        // Mismo criterio que cierre de caja: el costo del combo va en la línea padre
        // (document_hidden = componentes internos, unit_cost = 0 para no doble contar).
        $hideSql = $this->schemaHasColumn('sale_items', 'document_hidden')
            ? ' AND COALESCE(si.document_hidden, 0) = 0'
            : '';

        // —— Ventas completed (mismo criterio que dashboard / reportes / contabilidad / caja)
        if ($this->schemaHasTable('sales')) {
            try {
                $salesRows = $this->query(
                    "SELECT MONTH(s.sale_date) AS m,
                            COALESCE(SUM(s.total), 0) AS ingresos
                     FROM sales s
                     WHERE s.sale_date >= ? AND s.sale_date < ?
                       AND s.status = 'completed'
                     GROUP BY MONTH(s.sale_date)",
                    [$from, $toExcl]
                )->fetchAll();
                foreach ($salesRows as $row) {
                    $addMonth($byMonth, (int)$row['m'], 'ingresos', (float)$row['ingresos']);
                }

                if ($this->schemaHasTable('sale_items')) {
                    $cogsRows = $this->query(
                        "SELECT MONTH(s.sale_date) AS m,
                                COALESCE(SUM(si.quantity * {$costExpr}), 0) AS costo
                         FROM sale_items si
                         JOIN sales s ON s.id = si.sale_id
                         LEFT JOIN products p ON p.id = si.product_id
                         WHERE s.sale_date >= ? AND s.sale_date < ?
                           AND s.status = 'completed'
                           {$hideSql}
                         GROUP BY MONTH(s.sale_date)",
                        [$from, $toExcl]
                    )->fetchAll();
                    foreach ($cogsRows as $row) {
                        $addMonth($byMonth, (int)$row['m'], 'costo_venta', (float)$row['costo']);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = 'ventas';
                error_log('monthlyBalance sales: ' . $e->getMessage());
            }
        }

        // —— Notas de crédito de venta (reducen ingresos y costo)
        if ($this->schemaHasTable('sale_credit_notes')) {
            try {
                $ncRows = $this->query(
                    "SELECT MONTH(cn.credit_date) AS m,
                            COALESCE(SUM(cn.total), 0) AS total
                     FROM sale_credit_notes cn
                     WHERE cn.credit_date >= ? AND cn.credit_date < ?
                       AND cn.status = 'posted'
                     GROUP BY MONTH(cn.credit_date)",
                    [$from, $toExcl]
                )->fetchAll();
                foreach ($ncRows as $row) {
                    $addMonth($byMonth, (int)$row['m'], 'ingresos', -((float)$row['total']));
                }

                if ($this->schemaHasTable('sale_credit_note_items')) {
                    $ncCostExpr = $this->schemaHasColumn('sale_credit_note_items', 'unit_cost')
                        ? 'COALESCE(NULLIF(ci.unit_cost, 0), 0)'
                        : '0';
                    $ncCogs = $this->query(
                        "SELECT MONTH(cn.credit_date) AS m,
                                COALESCE(SUM(ci.quantity * {$ncCostExpr}), 0) AS costo
                         FROM sale_credit_note_items ci
                         JOIN sale_credit_notes cn ON cn.id = ci.credit_note_id
                         WHERE cn.credit_date >= ? AND cn.credit_date < ?
                           AND cn.status = 'posted'
                         GROUP BY MONTH(cn.credit_date)",
                        [$from, $toExcl]
                    )->fetchAll();
                    foreach ($ncCogs as $row) {
                        $addMonth($byMonth, (int)$row['m'], 'costo_venta', -((float)$row['costo']));
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = 'nc_venta';
                error_log('monthlyBalance sale NC: ' . $e->getMessage());
            }
        }

        // —— Facturas de compra recibidas
        $poDateParts = [];
        if ($this->schemaHasColumn('purchase_orders', 'invoice_date')) {
            $poDateParts[] = 'po.invoice_date';
        }
        if ($this->schemaHasColumn('purchase_orders', 'warehouse_date')) {
            $poDateParts[] = 'po.warehouse_date';
        }
        $poDateParts[] = 'po.received_at';
        $poDateParts[] = 'po.order_date';
        $poDateExpr = 'COALESCE(' . implode(', ', $poDateParts) . ')';

        $hasPaidTotal = $this->schemaHasColumn('purchase_orders', 'paid_total');
        $hasCreditTotal = $this->schemaHasColumn('purchase_orders', 'credit_total');
        $hasPaymentMode = $this->schemaHasColumn('purchase_orders', 'payment_mode');
        $hasEarlyDisc = $this->schemaHasColumn('purchase_orders', 'early_payment_discount_amount');
        $hasCommDisc = $this->schemaHasColumn('purchase_orders', 'commercial_discount_amount');

        $contadoModes = "('cash','transfer','card')";
        $contadoSql = $hasPaymentMode
            ? "CASE WHEN COALESCE(po.payment_mode,'payable') IN {$contadoModes} THEN po.total ELSE 0 END"
            : '0';
        $creditoSql = $hasPaymentMode
            ? "CASE WHEN COALESCE(po.payment_mode,'payable') = 'payable' THEN po.total ELSE 0 END"
            : 'po.total';

        $pendSql = 'po.total';
        if ($hasPaidTotal) {
            $pendSql .= ' - COALESCE(po.paid_total,0)';
        }
        if ($hasCreditTotal) {
            $pendSql .= ' - COALESCE(po.credit_total,0)';
        }
        $pendSql = "GREATEST({$pendSql}, 0)";

        $discParts = [];
        if ($hasEarlyDisc) {
            $discParts[] = 'COALESCE(po.early_payment_discount_amount,0)';
        }
        if ($hasCommDisc) {
            $discParts[] = 'COALESCE(po.commercial_discount_amount,0)';
        }
        $discSql = $discParts !== [] ? implode(' + ', $discParts) : '0';

        if ($this->schemaHasTable('purchase_orders')) {
            try {
                $voidFilter = $this->schemaHasColumn('purchase_orders', 'voided_at')
                    ? ' AND po.voided_at IS NULL'
                    : '';
                $poRows = $this->query(
                    "SELECT MONTH({$poDateExpr}) AS m,
                            COALESCE(SUM(po.total), 0) AS total,
                            COALESCE(SUM({$contadoSql}), 0) AS contado,
                            COALESCE(SUM({$creditoSql}), 0) AS credito,
                            COALESCE(SUM({$pendSql}), 0) AS pendientes,
                            COALESCE(SUM({$discSql}), 0) AS descuentos
                     FROM purchase_orders po
                     WHERE po.status = 'received'
                       {$voidFilter}
                       AND {$poDateExpr} >= ?
                       AND {$poDateExpr} < ?
                     GROUP BY MONTH({$poDateExpr})",
                    [$from, $toExcl]
                )->fetchAll();
                foreach ($poRows as $row) {
                    $m = (int)$row['m'];
                    $addMonth($byMonth, $m, 'facturas_total', (float)$row['total']);
                    $addMonth($byMonth, $m, 'facturas_contado', (float)$row['contado']);
                    $addMonth($byMonth, $m, 'facturas_credito', (float)$row['credito']);
                    $addMonth($byMonth, $m, 'facturas_pendientes', (float)$row['pendientes']);
                    $addMonth($byMonth, $m, 'facturas_descuentos', -((float)$row['descuentos']));
                }
            } catch (\Throwable $e) {
                $warnings[] = 'compras';
                error_log('monthlyBalance purchases: ' . $e->getMessage());
            }
        }

        // —— $ Fact. Pagadas:
        // 1) abonos a crédito (supplier_payments.payment_date)
        // 2) contado al recibir (paid_total / total − NC, en fecha de factura)
        if ($this->schemaHasTable('supplier_payments')) {
            try {
                $payStatus = $this->schemaHasColumn('supplier_payments', 'status')
                    ? "AND sp.status = 'applied'"
                    : '';
                $payRows = $this->query(
                    "SELECT MONTH(sp.payment_date) AS m,
                            COALESCE(SUM(sp.amount), 0) AS pagado
                     FROM supplier_payments sp
                     WHERE sp.payment_date >= ? AND sp.payment_date < ?
                       {$payStatus}
                     GROUP BY MONTH(sp.payment_date)",
                    [$from, $toExcl]
                )->fetchAll();
                foreach ($payRows as $row) {
                    $addMonth($byMonth, (int)$row['m'], 'facturas_pagadas', (float)$row['pagado']);
                }
            } catch (\Throwable $e) {
                $warnings[] = 'abonos';
                error_log('monthlyBalance payments: ' . $e->getMessage());
            }
        }

        if ($this->schemaHasTable('purchase_orders') && $hasPaymentMode) {
            try {
                $cashPaidExpr = $hasPaidTotal
                    ? 'COALESCE(po.paid_total, 0)'
                    : ($hasCreditTotal
                        ? 'GREATEST(po.total - COALESCE(po.credit_total,0), 0)'
                        : 'po.total');
                $voidFilter = $this->schemaHasColumn('purchase_orders', 'voided_at')
                    ? ' AND po.voided_at IS NULL'
                    : '';
                $cashRows = $this->query(
                    "SELECT MONTH({$poDateExpr}) AS m,
                            COALESCE(SUM({$cashPaidExpr}), 0) AS pagado
                     FROM purchase_orders po
                     WHERE po.status = 'received'
                       {$voidFilter}
                       AND COALESCE(po.payment_mode,'payable') IN {$contadoModes}
                       AND {$poDateExpr} >= ?
                       AND {$poDateExpr} < ?
                     GROUP BY MONTH({$poDateExpr})",
                    [$from, $toExcl]
                )->fetchAll();
                foreach ($cashRows as $row) {
                    $addMonth($byMonth, (int)$row['m'], 'facturas_pagadas', (float)$row['pagado']);
                }
            } catch (\Throwable $e) {
                $warnings[] = 'pagos_contado';
                error_log('monthlyBalance cash paid: ' . $e->getMessage());
            }
        }

        // —— Gastos por grupo (operativo / fijo / financiero)
        if ($this->schemaHasTable('expenses')) {
            try {
                $expStatus = $this->schemaHasColumn('expenses', 'status')
                    ? "AND COALESCE(e.status, 'active') <> 'cancelled'"
                    : '';
                $hasCategory = $this->schemaHasColumn('expenses', 'category');
                $catSelect = $hasCategory ? "COALESCE(e.category, 'general')" : "'general'";
                $catGroup = $hasCategory ? "COALESCE(e.category, 'general')" : "'general'";
                $expRows = $this->query(
                    "SELECT MONTH(e.expense_date) AS m,
                            {$catSelect} AS category,
                            COALESCE(SUM(e.amount), 0) AS total
                     FROM expenses e
                     WHERE e.expense_date >= ? AND e.expense_date < ?
                       {$expStatus}
                     GROUP BY MONTH(e.expense_date), {$catGroup}",
                    [$from, $toExcl]
                )->fetchAll();
                foreach ($expRows as $row) {
                    $m = (int)$row['m'];
                    $amount = round((float)$row['total'], 2);
                    $group = function_exists('\\SoftNova\\Core\\expense_category_group')
                        ? \SoftNova\Core\expense_category_group((string)$row['category'])
                        : 'operating';
                    if ($group === 'fixed') {
                        $addMonth($byMonth, $m, 'gastos_fijos', $amount);
                    } elseif ($group === 'financial') {
                        $addMonth($byMonth, $m, 'gastos_financieros', $amount);
                    } else {
                        $addMonth($byMonth, $m, 'gastos_operativos', $amount);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = 'gastos';
                error_log('monthlyBalance expenses: ' . $e->getMessage());
            }
        }

        $totals = $empty(0);
        unset(
            $totals['month'],
            $totals['month_label'],
            $totals['month_num'],
            $totals['month_name'],
            $totals['month_short'],
            $totals['period_from'],
            $totals['period_to']
        );
        $months = [];
        $monthsWithIncomeNoCost = 0;
        foreach ($byMonth as $row) {
            // Evitar negativos fantasma por redondeo en NC > ventas del mes
            $row['ingresos'] = round((float)$row['ingresos'], 2);
            $row['costo_venta'] = max(0.0, round((float)$row['costo_venta'], 2));
            $row['utilidad_bruta'] = round($row['ingresos'] - $row['costo_venta'], 2);
            $row['utilidad_operacional'] = round(
                $row['utilidad_bruta'] - (float)$row['gastos_operativos'],
                2
            );
            $row['utilidad_neta'] = round(
                $row['utilidad_operacional']
                - (float)$row['gastos_fijos']
                - (float)$row['gastos_financieros'],
                2
            );
            if ($row['ingresos'] > 0.009 && $row['costo_venta'] < 0.009) {
                $monthsWithIncomeNoCost++;
            }
            $months[] = $row;
            foreach ($totals as $k => $_) {
                $totals[$k] = round((float)($totals[$k] ?? 0) + (float)($row[$k] ?? 0), 2);
            }
        }

        if ($monthsWithIncomeNoCost > 0) {
            $warnings[] = 'costo_sin_cargar';
        }

        $chart = [
            'labels' => array_map(static fn(array $r): string => (string)($r['month_short'] ?? $r['month_label']), $months),
            'labels_full' => array_map(static fn(array $r): string => (string)$r['month_label'], $months),
            'ingresos' => array_map(static fn(array $r): float => round((float)$r['ingresos'], 2), $months),
            'costo_venta' => array_map(static fn(array $r): float => round((float)$r['costo_venta'], 2), $months),
            'facturas_total' => array_map(static fn(array $r): float => round((float)$r['facturas_total'], 2), $months),
            'facturas_pagadas' => array_map(static fn(array $r): float => round((float)$r['facturas_pagadas'], 2), $months),
            'utilidad_bruta' => array_map(static fn(array $r): float => round((float)$r['utilidad_bruta'], 2), $months),
            'utilidad_operacional' => array_map(static fn(array $r): float => round((float)$r['utilidad_operacional'], 2), $months),
            'utilidad_neta' => array_map(static fn(array $r): float => round((float)$r['utilidad_neta'], 2), $months),
        ];

        return [
            'year' => $year,
            'months' => $months,
            'totals' => $totals,
            'chart' => $chart,
            'meta' => [
                'from' => $from,
                'to' => sprintf('%04d-12-31', $year),
                'period_label' => 'Enero – Diciembre ' . $year,
                'warnings' => array_values(array_unique($warnings)),
                'sources' => [
                    'ingresos' => 'Ventas de caja/POS con estado completed (misma base que el cierre de caja)',
                    'costo_venta' => 'Ítems de venta: unit_cost de la línea o purchase_price del producto (excluye componentes ocultos de combos, igual que el ojo de costo en caja)',
                    'facturas' => 'Órdenes de compra en estado received (fecha factura / bodega / recepción)',
                    'facturas_pagadas' => 'Abonos a proveedores + compras de contado al recibir',
                    'utilidad_bruta' => 'Ingresos − costo de venta',
                    'utilidad_operacional' => 'Bruta − gastos operativos',
                    'utilidad_neta' => 'Operacional − gastos fijos − gastos financieros',
                ],
            ],
        ];
    }

    /**
     * Libro mayor de síntesis (partida doble): saldo inicial, debe, haber y saldo final
     * por cuenta / grupo PUC. Alimenta auditoría y estados financieros.
     *
     * @param array{account_type?:string,q?:string,include_zero?:bool,mode?:string} $options
     *        mode: subaccounts (detalle) | accounts (4 dígitos) | groups (2) | classes (1)
     * @return array{rows:list<array<string,mixed>>,totals:array,balanced:array,from:string,to:string,mode:string}
     */
    public function generalLedger(string $from, string $to, array $options = []): array
    {
        $mode = strtolower(trim((string)($options['mode'] ?? 'subaccounts')));
        if (!in_array($mode, ['subaccounts', 'accounts', 'groups', 'classes'], true)) {
            $mode = 'subaccounts';
        }
        $base = $this->trialBalance($from, $to, [
            'account_type' => (string)($options['account_type'] ?? ''),
            'q' => (string)($options['q'] ?? ''),
            'include_zero' => !empty($options['include_zero']),
        ]);

        if ($mode === 'subaccounts') {
            $base['mode'] = 'subaccounts';
            $base['mode_label'] = 'Por subcuenta (detalle)';
            return $base;
        }

        $prefixLen = match ($mode) {
            'classes' => 1,
            'groups' => 2,
            default => 4,
        };
        $modeLabel = match ($mode) {
            'classes' => 'Por clase (1 dígito)',
            'groups' => 'Por grupo (2 dígitos)',
            default => 'Por cuenta (4 dígitos)',
        };

        $buckets = [];
        foreach ($base['rows'] as $row) {
            $code = preg_replace('/\D+/', '', (string)($row['code'] ?? '')) ?: (string)($row['code'] ?? '');
            $key = substr($code, 0, $prefixLen);
            if ($key === '') {
                $key = (string)($row['code'] ?? '?');
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'id' => 0,
                    'code' => $key,
                    'name' => $this->pucGroupLabel($key, $mode, (string)($row['name'] ?? '')),
                    'account_type' => (string)($row['account_type'] ?? ''),
                    'account_type_label' => (string)($row['account_type_label'] ?? ''),
                    'nature' => (string)($row['nature'] ?? 'debit'),
                    'nature_label' => (string)($row['nature_label'] ?? 'Débito'),
                    'opening_balance' => 0.0,
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'closing_balance' => 0.0,
                    'opening_debit' => 0.0,
                    'opening_credit' => 0.0,
                    'closing_debit' => 0.0,
                    'closing_credit' => 0.0,
                    'accounts_count' => 0,
                    'is_group' => true,
                ];
            }
            $buckets[$key]['opening_balance'] = round($buckets[$key]['opening_balance'] + (float)$row['opening_balance'], 2);
            $buckets[$key]['debit'] = round($buckets[$key]['debit'] + (float)$row['debit'], 2);
            $buckets[$key]['credit'] = round($buckets[$key]['credit'] + (float)$row['credit'], 2);
            $buckets[$key]['closing_balance'] = round($buckets[$key]['closing_balance'] + (float)$row['closing_balance'], 2);
            $buckets[$key]['opening_debit'] = round($buckets[$key]['opening_debit'] + (float)$row['opening_debit'], 2);
            $buckets[$key]['opening_credit'] = round($buckets[$key]['opening_credit'] + (float)$row['opening_credit'], 2);
            $buckets[$key]['closing_debit'] = round($buckets[$key]['closing_debit'] + (float)$row['closing_debit'], 2);
            $buckets[$key]['closing_credit'] = round($buckets[$key]['closing_credit'] + (float)$row['closing_credit'], 2);
            $buckets[$key]['accounts_count']++;
        }
        ksort($buckets, SORT_STRING);

        return [
            'rows' => array_values($buckets),
            'totals' => $base['totals'],
            'balanced' => $base['balanced'],
            'from' => $from,
            'to' => $to,
            'mode' => $mode,
            'mode_label' => $modeLabel,
        ];
    }

    private function pucGroupLabel(string $key, string $mode, string $fallbackName): string
    {
        if ($mode === 'classes') {
            return match ($key) {
                '1' => 'Activo',
                '2' => 'Pasivo',
                '3' => 'Patrimonio',
                '4' => 'Ingresos',
                '5' => 'Gastos',
                '6' => 'Costos de ventas',
                '7' => 'Costos de producción / operación',
                '8' => 'Cuentas de orden deudoras',
                '9' => 'Cuentas de orden acreedoras',
                default => 'Clase ' . $key,
            };
        }
        if ($mode === 'groups') {
            return 'Grupo ' . $key;
        }
        if ($fallbackName !== '' && strlen($key) >= 4) {
            return 'Cuenta ' . $key;
        }
        return $fallbackName !== '' ? $fallbackName : ('Cuenta ' . $key);
    }

    /**
     * Libro documental de ventas / ingresos del periodo (facturas + NC).
     * No incluye retenciones de adquirente (van al libro de retenciones).
     *
     * @return array<string,mixed>
     */
    public function salesIncomeBook(string $from, string $to): array
    {
        $rows = [];
        $totals = [
            'count' => 0,
            'subtotal' => 0.0,
            'discount' => 0.0,
            'tax' => 0.0,
            'delivery' => 0.0,
            'total' => 0.0,
            'credit_notes_count' => 0,
            'credit_notes_total' => 0.0,
            'net_income' => 0.0,
        ];

        try {
            $sales = $this->query(
                "SELECT s.id, s.invoice_number, s.sale_date, s.subtotal, s.discount, s.tax,
                        COALESCE(s.delivery_fee, 0) AS delivery_fee, s.total,
                        s.payment_method, s.payment_status, s.status, s.notes,
                        COALESCE(c.name, 'Público general') AS customer_name,
                        COALESCE(NULLIF(TRIM(c.document_number), ''), '') AS customer_document,
                        COALESCE(e.entry_number, '') AS entry_number,
                        e.id AS entry_id
                 FROM sales s
                 LEFT JOIN customers c ON c.id = s.customer_id
                 LEFT JOIN accounting_entries e ON e.source_type = 'sale' AND e.source_id = s.id
                      AND e.status IN ('posted','reversed')
                 WHERE DATE(s.sale_date) BETWEEN ? AND ?
                   AND COALESCE(s.status, '') <> 'cancelled'
                 ORDER BY s.sale_date ASC, s.id ASC",
                [$from, $to]
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            $sales = [];
        }

        foreach ($sales as $s) {
            $sub = round((float)($s['subtotal'] ?? 0), 2);
            $disc = round((float)($s['discount'] ?? 0), 2);
            $tax = round((float)($s['tax'] ?? 0), 2);
            $del = round((float)($s['delivery_fee'] ?? 0), 2);
            $tot = round((float)($s['total'] ?? 0), 2);
            $rows[] = [
                'kind' => 'sale',
                'kind_label' => 'Venta',
                'id' => (int)$s['id'],
                'number' => (string)($s['invoice_number'] ?? ''),
                'date' => (string)($s['sale_date'] ?? ''),
                'customer_name' => (string)($s['customer_name'] ?? ''),
                'customer_document' => (string)($s['customer_document'] ?? ''),
                'payment_method' => (string)($s['payment_method'] ?? ''),
                'payment_status' => (string)($s['payment_status'] ?? ''),
                'subtotal' => $sub,
                'discount' => $disc,
                'tax' => $tax,
                'delivery' => $del,
                'total' => $tot,
                'sign' => 1,
                'entry_number' => (string)($s['entry_number'] ?? ''),
                'entry_id' => (int)($s['entry_id'] ?? 0),
                'notes' => SalesDocumentService::displayNotes((string)($s['notes'] ?? '')),
            ];
            $totals['count']++;
            $totals['subtotal'] += $sub;
            $totals['discount'] += $disc;
            $totals['tax'] += $tax;
            $totals['delivery'] += $del;
            $totals['total'] += $tot;
        }

        try {
            $notes = $this->query(
                "SELECT cn.id, cn.credit_number, cn.credit_date, cn.subtotal, cn.tax, cn.total,
                        cn.status, s.invoice_number AS sale_invoice,
                        COALESCE(c.name, 'Público general') AS customer_name,
                        COALESCE(NULLIF(TRIM(c.document_number), ''), '') AS customer_document,
                        COALESCE(e.entry_number, '') AS entry_number,
                        e.id AS entry_id
                 FROM sale_credit_notes cn
                 LEFT JOIN sales s ON s.id = cn.sale_id
                 LEFT JOIN customers c ON c.id = s.customer_id
                 LEFT JOIN accounting_entries e ON e.source_type = 'sale_credit_note' AND e.source_id = cn.id
                      AND e.status IN ('posted','reversed')
                 WHERE cn.status = 'posted'
                   AND DATE(cn.credit_date) BETWEEN ? AND ?
                 ORDER BY cn.credit_date ASC, cn.id ASC",
                [$from, $to]
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            $notes = [];
        }

        foreach ($notes as $n) {
            $sub = round((float)($n['subtotal'] ?? 0), 2);
            $tax = round((float)($n['tax'] ?? 0), 2);
            $tot = round((float)($n['total'] ?? 0), 2);
            $rows[] = [
                'kind' => 'credit_note',
                'kind_label' => 'NC venta',
                'id' => (int)$n['id'],
                'number' => (string)($n['credit_number'] ?? ''),
                'date' => (string)($n['credit_date'] ?? ''),
                'customer_name' => (string)($n['customer_name'] ?? ''),
                'customer_document' => (string)($n['customer_document'] ?? ''),
                'payment_method' => '',
                'payment_status' => '',
                'subtotal' => -$sub,
                'discount' => 0.0,
                'tax' => -$tax,
                'delivery' => 0.0,
                'total' => -$tot,
                'sign' => -1,
                'entry_number' => (string)($n['entry_number'] ?? ''),
                'entry_id' => (int)($n['entry_id'] ?? 0),
                'notes' => 'Ref. ' . (string)($n['sale_invoice'] ?? ''),
            ];
            $totals['credit_notes_count']++;
            $totals['credit_notes_total'] += $tot;
            $totals['subtotal'] -= $sub;
            $totals['tax'] -= $tax;
            $totals['total'] -= $tot;
        }

        usort($rows, static function ($a, $b) {
            $da = (string)($a['date'] ?? '');
            $db = (string)($b['date'] ?? '');
            return strcmp($da, $db) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });

        foreach ($totals as $k => $v) {
            if (is_float($v) || (is_numeric($v) && $k !== 'count' && $k !== 'credit_notes_count')) {
                $totals[$k] = round((float)$v, 2);
            }
        }
        $totals['net_income'] = round((float)$totals['total'], 2);

        $salesAccount = $this->setting('sales_account', '413501');
        $returnsAccount = $this->setting('sales_returns_account', '417501');
        $ledgerSales = 0.0;
        $ledgerReturns = 0.0;
        try {
            $led = $this->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN a.code = ? THEN l.credit - l.debit ELSE 0 END), 0) sales_net,
                    COALESCE(SUM(CASE WHEN a.code = ? THEN l.debit - l.credit ELSE 0 END), 0) returns_net
                 FROM accounting_lines l
                 INNER JOIN accounting_entries e ON e.id = l.entry_id
                 INNER JOIN accounting_accounts a ON a.id = l.account_id
                 WHERE e.entry_date BETWEEN ? AND ?
                   AND e.status IN ('posted','reversed')
                   AND a.code IN (?, ?)",
                [$salesAccount, $returnsAccount, $from, $to, $salesAccount, $returnsAccount]
            )->fetch() ?: [];
            $ledgerSales = round((float)($led['sales_net'] ?? 0), 2);
            $ledgerReturns = round((float)($led['returns_net'] ?? 0), 2);
        } catch (\Throwable $e) {
            // ignore
        }

        $docBase = round((float)$totals['subtotal'] - (float)$totals['discount'] + (float)$totals['delivery'], 2);
        $ledgerNet = round($ledgerSales - $ledgerReturns, 2);
        $warnings = [];
        if (abs($docBase - $ledgerNet) >= 1.0 && ($totals['count'] > 0 || abs($ledgerNet) >= 0.01)) {
            $warnings[] = 'La base documental neta (' . number_format($docBase, 2, ',', '.')
                . ') difiere del mayor de ingresos (' . number_format($ledgerNet, 2, ',', '.')
                . '). Revise asientos pendientes o cuentas de ingreso.';
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => $totals,
            'accounts' => [
                'sales' => $salesAccount,
                'returns' => $returnsAccount,
            ],
            'ledger' => [
                'sales_net' => $ledgerSales,
                'returns_net' => $ledgerReturns,
                'net' => $ledgerNet,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Mayor de una cuenta en un rango.
     * Incluye saldo de apertura (movimientos anteriores a $from) para que la columna Saldo sea usable.
     *
     * @return array{opening_balance:float,rows:list<array<string,mixed>>}
     */
    public function ledger(int $accountId, string $from, string $to, string $q = ''): array
    {
        $account = $this->query(
            "SELECT id, code, name, account_type, nature, status, accepts_entries
             FROM accounting_accounts WHERE id = ? LIMIT 1",
            [$accountId]
        )->fetch() ?: null;
        $opening = 0.0;
        try {
            $opening = (float)$this->query(
                "SELECT COALESCE(SUM(l.debit - l.credit), 0)
                 FROM accounting_lines l
                 JOIN accounting_entries e ON e.id = l.entry_id
                 WHERE l.account_id = ?
                   AND e.entry_date < ?
                   AND e.status IN ('posted','reversed')",
                [$accountId, $from]
            )->fetchColumn();
        } catch (\Throwable $e) {
            $opening = 0.0;
        }

        // Totales del periodo siempre completos (sin filtro de texto) para que el saldo final sea real.
        $period = $this->query(
            "SELECT COALESCE(SUM(l.debit), 0) debit, COALESCE(SUM(l.credit), 0) credit
             FROM accounting_lines l
             JOIN accounting_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?
               AND e.status IN ('posted','reversed')
               AND " . $this->voidedPairExclusionSql('e'),
            [$accountId, $from, $to]
        )->fetch() ?: ['debit' => 0, 'credit' => 0];

        $params = [$accountId, $from, $to];
        $qSql = '';
        $q = trim($q);
        if ($q !== '') {
            $qSql = " AND (l.description LIKE ? OR e.description LIKE ? OR l.third_party_name LIKE ? OR e.entry_number LIKE ?)";
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $rows = $this->query(
            "SELECT e.id entry_id, e.entry_number, e.entry_date, e.description entry_description,
                    e.source_type, e.source_id, e.status entry_status, e.reversal_of_id,
                    l.description, l.debit, l.credit, l.third_party_name
             FROM accounting_lines l
             JOIN accounting_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?
               AND e.status IN ('posted','reversed')
               AND " . $this->voidedPairExclusionSql('e') . $qSql . "
             ORDER BY e.entry_date, e.id, l.id",
            $params
        )->fetchAll();

        $opening = round($opening, 2);
        $debit = round((float)$period['debit'], 2);
        $credit = round((float)$period['credit'], 2);

        return [
            'account' => $account,
            'opening_balance' => $opening,
            'period_debit' => $debit,
            'period_credit' => $credit,
            'closing_balance' => round($opening + $debit - $credit, 2),
            'filtered' => $q !== '',
            'rows' => $rows ?: [],
        ];
    }

    /** Etiqueta en español del origen de un comprobante. */
    public static function sourceLabel(?string $sourceType): string
    {
        return match ((string)$sourceType) {
            '', 'manual' => 'Manual',
            'sale' => 'Venta',
            'sale_payment' => 'Abono de cliente',
            'sale_credit_note' => 'NC de venta',
            'expense' => 'Gasto',
            'payroll' => 'Nómina',
            'payroll_payment' => 'Pago de nómina',
            'bank_posting' => 'Bancos (extracto)',
            'bold_settlement' => 'Liquidación Bold',
            'purchase', 'purchase_order' => 'Compra',
            'purchase_credit_note' => 'NC de compra',
            'purchase_cancel', 'purchase_order_cancel' => 'Anulación de compra',
            'supplier_payment' => 'Pago a proveedor',
            'stock_movement' => 'Inventario',
            'cash_movement' => 'Movimiento de caja',
            'cash_session' => 'Cierre de caja',
            'consignment_settlement', 'consignment_in', 'consignment_return' => 'Consignación',
            'loan', 'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle' => 'Préstamo',
            default => (string)$sourceType,
        };
    }

    /**
     * Retenciones que nos practican (anticipos de impuestos, activo 1355):
     * saldos del libro, de dónde vienen y cuánto debería haber según las comisiones de pasarela.
     *
     * @return array<string,mixed>
     */
    public function withholdingSummary(string $from, string $to): array
    {
        $defs = [
            'renta' => ['label' => 'Retención en la fuente', 'key' => 'gateway_withholding_renta_account', 'default' => '135515', 'rate_key' => 'gateway_withholding_renta_rate', 'rate_unit' => '%', 'column' => 'withholding_renta'],
            'iva' => ['label' => 'ReteIVA', 'key' => 'gateway_withholding_iva_account', 'default' => '135517', 'rate_key' => 'gateway_withholding_iva_rate', 'rate_unit' => '%', 'column' => 'withholding_iva'],
            'ica' => ['label' => 'ReteICA', 'key' => 'gateway_withholding_ica_account', 'default' => '135518', 'rate_key' => 'gateway_withholding_ica_rate', 'rate_unit' => '‰', 'column' => 'withholding_ica'],
        ];

        $expected = [];
        $pending = [];
        $commissionsAvailable = true;
        try {
            $paid = $this->query(
                "SELECT COALESCE(SUM(withholding_renta),0) renta, COALESCE(SUM(withholding_iva),0) iva,
                        COALESCE(SUM(withholding_ica),0) ica, COUNT(*) cnt
                 FROM sales_commissions
                 WHERE commission_kind = 'gateway' AND status = 'paid' AND expense_id IS NOT NULL
                   AND COALESCE(accrual_date, DATE(created_at)) BETWEEN ? AND ?",
                [$from, $to]
            )->fetch() ?: [];
            $pend = $this->query(
                "SELECT COALESCE(SUM(withholding_renta),0) renta, COALESCE(SUM(withholding_iva),0) iva,
                        COALESCE(SUM(withholding_ica),0) ica, COUNT(*) cnt
                 FROM sales_commissions
                 WHERE commission_kind = 'gateway' AND status = 'pending'
                   AND COALESCE(accrual_date, DATE(created_at)) BETWEEN ? AND ?",
                [$from, $to]
            )->fetch() ?: [];
            foreach (array_keys($defs) as $k) {
                $expected[$k] = round((float)($paid[$k] ?? 0), 2);
                $pending[$k] = round((float)($pend[$k] ?? 0), 2);
            }
            $paidCount = (int)($paid['cnt'] ?? 0);
            $pendingCount = (int)($pend['cnt'] ?? 0);
        } catch (\Throwable $e) {
            $commissionsAvailable = false;
            $paidCount = 0;
            $pendingCount = 0;
        }

        $gatewayPayments = 0;
        $gatewayAmount = 0.0;
        try {
            $methods = CommissionService::GATEWAY_METHODS;
            $ph = implode(',', array_fill(0, count($methods), '?'));
            $gp = $this->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(sp.amount), 0) total
                 FROM sale_payments sp
                 JOIN sales s ON s.id = sp.sale_id AND COALESCE(s.status, '') <> 'cancelled'
                 WHERE sp.amount > 0
                   AND sp.payment_method IN ({$ph})
                   AND DATE(sp.payment_date) BETWEEN ? AND ?",
                array_merge($methods, [$from, $to])
            )->fetch() ?: [];
            $gatewayPayments = (int)($gp['cnt'] ?? 0);
            $gatewayAmount = round((float)($gp['total'] ?? 0), 2);
        } catch (\Throwable $e) {
            $gatewayPayments = 0;
        }

        $rows = [];
        foreach ($defs as $k => $def) {
            $code = $this->setting($def['key'], $def['default']);
            $account = $this->query(
                "SELECT id, code, name FROM accounting_accounts WHERE code = ? LIMIT 1",
                [$code]
            )->fetch() ?: null;
            $row = [
                'key' => $k,
                'label' => $def['label'],
                'code' => $code,
                'account_id' => $account ? (int)$account['id'] : 0,
                'account_name' => $account['name'] ?? '',
                'exists' => (bool)$account,
                'rate' => (float)$this->setting($def['rate_key'], '0'),
                'rate_unit' => $def['rate_unit'],
                'opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0,
                'by_source' => [],
                'expected_commissions' => $expected[$k] ?? 0.0,
                'pending_commissions' => $pending[$k] ?? 0.0,
                'commission_ledger' => 0.0,
            ];
            if ($account) {
                $bal = $this->query(
                    "SELECT
                        COALESCE(SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit ELSE 0 END), 0) opening,
                        COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.debit ELSE 0 END), 0) debit,
                        COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.credit ELSE 0 END), 0) credit
                     FROM accounting_lines l
                     JOIN accounting_entries e ON e.id = l.entry_id
                     WHERE l.account_id = ? AND e.status IN ('posted','reversed')",
                    [$from, $from, $to, $from, $to, (int)$account['id']]
                )->fetch() ?: [];
                $row['opening'] = round((float)($bal['opening'] ?? 0), 2);
                $row['debit'] = round((float)($bal['debit'] ?? 0), 2);
                $row['credit'] = round((float)($bal['credit'] ?? 0), 2);
                $row['closing'] = round($row['opening'] + $row['debit'] - $row['credit'], 2);

                $sources = $this->query(
                    "SELECT COALESCE(e.source_type, 'manual') source_type,
                            COUNT(DISTINCT e.id) entries,
                            COALESCE(SUM(l.debit - l.credit), 0) net
                     FROM accounting_lines l
                     JOIN accounting_entries e ON e.id = l.entry_id
                     WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?
                       AND e.status IN ('posted','reversed')
                     GROUP BY COALESCE(e.source_type, 'manual')
                     ORDER BY net DESC",
                    [(int)$account['id'], $from, $to]
                )->fetchAll();
                foreach ($sources as $s) {
                    $row['by_source'][] = [
                        'label' => self::sourceLabel($s['source_type']),
                        'entries' => (int)$s['entries'],
                        'net' => round((float)$s['net'], 2),
                    ];
                }

                if ($commissionsAvailable) {
                    try {
                        $row['commission_ledger'] = round((float)$this->query(
                            "SELECT COALESCE(SUM(l.debit - l.credit), 0)
                             FROM accounting_lines l
                             JOIN accounting_entries e ON e.id = l.entry_id
                             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?
                               AND e.status IN ('posted','reversed')
                               AND e.source_type = 'expense'
                               AND e.source_id IN (
                                   SELECT expense_id FROM sales_commissions
                                   WHERE commission_kind = 'gateway' AND expense_id IS NOT NULL
                               )",
                            [(int)$account['id'], $from, $to]
                        )->fetchColumn(), 2);
                    } catch (\Throwable $e) {
                        $row['commission_ledger'] = 0.0;
                    }
                }
            }
            $rows[] = $row;
        }

        $expenseCategories = [];
        try {
            $codes = array_map(static fn($r) => $r['code'], $rows);
            $in = implode(',', array_fill(0, count($codes), '?'));
            $expenseCategories = $this->query(
                "SELECT code, name, account_code FROM expense_categories
                 WHERE account_code IN ({$in}) AND status = 'active'",
                $codes
            )->fetchAll();
        } catch (\Throwable $e) {
            $expenseCategories = [];
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'commissions_available' => $commissionsAvailable,
            'paid_commissions' => $paidCount,
            'pending_commissions' => $pendingCount,
            'gateway_payments' => $gatewayPayments,
            'gateway_amount' => $gatewayAmount,
            'expense_categories' => $expenseCategories,
        ];
    }

    /**
     * @param list<array<string,mixed>>|null $trialRows Filas ya calculadas (evita 2.º trialBalance).
     */
    public function financialStatements(string $from, string $to, ?array $trialRows = null): array
    {
        $rows = $trialRows ?? $this->trialBalanceRows($from, $to);
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
        // Resultado acumulado antes de "from" (aún sin asiento de cierre): sin él el
        // balance a la fecha "to" no cuadra cuando el rango no arranca en el inicio.
        $priorProfit = 0.0;
        foreach ($rows as $row) {
            $type = $row['account_type'];
            $key = $typeKeyMap[$type] ?? null;
            if ($key === null) {
                continue;
            }
            if (in_array($type, ['revenue', 'expense'], true)) {
                $priorProfit -= (float)($row['opening_balance'] ?? 0);
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
        $result['prior_profit'] = round($priorProfit, 2);
        $result['totals']['equity_with_results'] = round(
            $result['totals']['equity'] + $result['prior_profit'] + $result['profit'],
            2
        );

        // Desglose por origen en cuentas «cajón» (519595 / 539595) para el estado de resultados.
        $bucketCodes = [];
        foreach ($result['expenses'] as $ex) {
            $code = (string)($ex['code'] ?? '');
            if (in_array($code, ['519595', '539595'], true) && abs((float)($ex['display_balance'] ?? 0)) >= 0.01) {
                $bucketCodes[] = $code;
            }
        }
        if ($bucketCodes !== []) {
            try {
                $originsByCode = $this->expenseBucketOriginBreakdown($from, $to, $bucketCodes);
                foreach ($result['expenses'] as &$ex) {
                    $code = (string)($ex['code'] ?? '');
                    if (isset($originsByCode[$code])) {
                        $ex['origins'] = $originsByCode[$code]['origins'];
                    }
                }
                unset($ex);
            } catch (\Throwable $e) {
                error_log('expenseBucketOriginBreakdown: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Desglosa el movimiento del periodo de cuentas cajón (p. ej. 519595, 539595)
     * por origen del asiento (gasto, inventario, compra sin stock, caja, arqueo…).
     *
     * @param list<string> $codes
     * @return array<string, array{code:string,total:float,origins:list<array<string,mixed>>}>
     */
    public function expenseBucketOriginBreakdown(string $from, string $to, array $codes = ['519595', '539595']): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn($c) => preg_replace('/[^0-9A-Za-z]/', '', (string)$c) ?? '',
            $codes
        ))));
        if ($codes === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codes), '?'));
        $rows = $this->query(
            "SELECT a.code,
                    COALESCE(NULLIF(TRIM(e.source_type), ''), 'manual') AS source_type,
                    COUNT(DISTINCT e.id) AS entries,
                    ROUND(COALESCE(SUM(l.debit - l.credit), 0), 2) AS net
             FROM accounting_lines l
             JOIN accounting_entries e ON e.id = l.entry_id
             JOIN accounting_accounts a ON a.id = l.account_id
             WHERE a.code IN ({$ph})
               AND e.entry_date BETWEEN ? AND ?
               AND e.status IN ('posted', 'reversed')
             GROUP BY a.code, COALESCE(NULLIF(TRIM(e.source_type), ''), 'manual')
             HAVING ABS(ROUND(COALESCE(SUM(l.debit - l.credit), 0), 2)) >= 0.01
             ORDER BY a.code, net DESC",
            array_merge($codes, [$from, $to])
        )->fetchAll();

        $out = [];
        foreach ($codes as $code) {
            $out[$code] = ['code' => $code, 'total' => 0.0, 'origins' => []];
        }

        foreach ($rows as $row) {
            $code = (string)$row['code'];
            $source = (string)$row['source_type'];
            $net = round((float)$row['net'], 2);
            $entries = (int)$row['entries'];
            $origin = [
                'source_type' => $source,
                'label' => $this->expenseBucketOriginLabel($code, $source),
                'entries' => $entries,
                'amount' => $net,
                'categories' => [],
            ];
            if ($source === 'expense') {
                $origin['categories'] = $this->expenseOriginCategories($code, $from, $to);
            }
            $out[$code]['origins'][] = $origin;
            $out[$code]['total'] = round($out[$code]['total'] + $net, 2);
        }

        return $out;
    }

    /** Etiqueta legible del origen según la cuenta cajón. */
    private function expenseBucketOriginLabel(string $accountCode, string $sourceType): string
    {
        if ($accountCode === '539595') {
            return match ($sourceType) {
                'cash_session' => 'Faltantes de arqueo de caja',
                'expense' => 'Gastos financieros / otros (módulo Gastos)',
                'manual' => 'Asientos manuales',
                default => self::sourceLabel($sourceType),
            };
        }

        return match ($sourceType) {
            'expense' => 'Gastos registrados (módulo Gastos)',
            'stock_movement' => 'Mermas / salidas de inventario',
            'purchase_order', 'purchase' => 'Compras sin inventario (muestras / externas)',
            'cash_movement' => 'Egresos manuales de caja',
            'cash_session' => 'Ajustes de arqueo de caja',
            'manual' => 'Asientos manuales',
            default => self::sourceLabel($sourceType),
        };
    }

    /**
     * Subdesglose de asientos origen=expense por categoría del gasto.
     *
     * @return list<array{label:string,entries:int,amount:float}>
     */
    private function expenseOriginCategories(string $accountCode, string $from, string $to): array
    {
        try {
            $rows = $this->query(
                "SELECT COALESCE(
                            NULLIF(TRIM(ec.name), ''),
                            NULLIF(TRIM(x.category), ''),
                            'Sin categoría'
                        ) AS cat_label,
                        COUNT(DISTINCT e.id) AS entries,
                        ROUND(COALESCE(SUM(l.debit - l.credit), 0), 2) AS net
                 FROM accounting_lines l
                 JOIN accounting_entries e ON e.id = l.entry_id
                 JOIN accounting_accounts a ON a.id = l.account_id
                 LEFT JOIN expenses x ON x.id = e.source_id
                 LEFT JOIN expense_categories ec ON ec.id = x.category_id
                 WHERE a.code = ?
                   AND e.source_type = 'expense'
                   AND e.entry_date BETWEEN ? AND ?
                   AND e.status IN ('posted', 'reversed')
                 GROUP BY cat_label
                 HAVING ABS(ROUND(COALESCE(SUM(l.debit - l.credit), 0), 2)) >= 0.01
                 ORDER BY net DESC
                 LIMIT 40",
                [$accountCode, $from, $to]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'label' => (string)$r['cat_label'],
                'entries' => (int)$r['entries'],
                'amount' => round((float)$r['net'], 2),
            ];
        }
        return $out;
    }

    public function closePeriod(int $year, int $month, bool $close, ?string $notes = null): void
    {
        if ($year < 2000 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Periodo inválido');
        }
        if ($close) {
            $queue = $this->pendingQueueCount();
            if ($queue > 0) {
                throw new \InvalidArgumentException(
                    "Hay {$queue} asiento(s) en cola de reintento. Sincronice Contabilidad antes de cerrar el periodo."
                );
            }
            $sync = $this->pendingSyncCount();
            if ($sync > 0) {
                throw new \InvalidArgumentException(
                    "Hay {$sync} operación(es) sin comprobante. Ejecute «Sincronizar operaciones» antes de cerrar el periodo."
                );
            }
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

    /**
     * Cierre de resultado del ejercicio: traspasa ingresos/gastos a 360505 (Utilidad del ejercicio).
     * Idempotente por año. Requiere 31/12 del año en periodo abierto.
     */
    public function closeFiscalYear(int $year, ?string $notes = null): int
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Año inválido');
        }
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $this->assertPeriodOpen($to);

        $queue = $this->pendingQueueCount();
        if ($queue > 0) {
            throw new \InvalidArgumentException(
                "Hay {$queue} asiento(s) en cola. Sincronice antes del cierre anual."
            );
        }

        $equityCode = $this->setting('retained_earnings_account', '360505');
        $voidExcl = $this->voidedPairExclusionSql('e');
        $rows = $this->query(
            "SELECT a.code, a.name, a.account_type, a.nature,
                    ROUND(COALESCE(SUM(l.debit - l.credit), 0), 2) AS balance
             FROM accounting_accounts a
             JOIN accounting_lines l ON l.account_id = a.id
             JOIN accounting_entries e ON e.id = l.entry_id
                  AND e.status IN ('posted', 'reversed')
                  AND e.entry_date BETWEEN ? AND ?
                  AND {$voidExcl}
             WHERE a.account_type IN ('revenue', 'expense')
             GROUP BY a.id, a.code, a.name, a.account_type, a.nature
             HAVING ABS(ROUND(COALESCE(SUM(l.debit - l.credit), 0), 2)) > 0.009
             ORDER BY a.code",
            [$from, $to]
        )->fetchAll();

        if ($rows === []) {
            throw new \InvalidArgumentException(
                "No hay movimientos de ingreso/gasto en {$year} para cerrar."
            );
        }

        $lines = [];
        $netDebitCents = 0;
        foreach ($rows as $row) {
            $bal = round((float)$row['balance'], 2);
            $balCents = (int)round($bal * 100);
            if ($balCents === 0) {
                continue;
            }
            // Saldo débito-crédito: positivo = net débito → acreditar para cerrar; negativo = net crédito → debitar.
            if ($balCents > 0) {
                $lines[] = [
                    'account_code' => (string)$row['code'],
                    'debit' => 0,
                    'credit' => $balCents / 100,
                    'description' => 'Cierre ' . $year . ' · ' . $row['name'],
                ];
                $netDebitCents -= $balCents;
            } else {
                $abs = abs($balCents);
                $lines[] = [
                    'account_code' => (string)$row['code'],
                    'debit' => $abs / 100,
                    'credit' => 0,
                    'description' => 'Cierre ' . $year . ' · ' . $row['name'],
                ];
                $netDebitCents += $abs;
            }
        }

        if ($lines === []) {
            throw new \InvalidArgumentException("No hay saldos de resultado a cerrar en {$year}.");
        }

        // Contrapartida a utilidad del ejercicio (equilibra el asiento).
        if ($netDebitCents > 0) {
            $lines[] = [
                'account_code' => $equityCode,
                'debit' => 0,
                'credit' => $netDebitCents / 100,
                'description' => 'Utilidad del ejercicio ' . $year,
            ];
        } elseif ($netDebitCents < 0) {
            $lines[] = [
                'account_code' => $equityCode,
                'debit' => abs($netDebitCents) / 100,
                'credit' => 0,
                'description' => 'Pérdida del ejercicio ' . $year,
            ];
        }

        $desc = 'Cierre de resultado ' . $year
            . ($notes ? (': ' . trim($notes)) : '');

        return $this->postEntry(
            $to,
            $desc,
            $lines,
            'fiscal_close',
            $year,
            'year_close'
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

    /**
     * Para comprobantes digitados por el usuario: no se reubican de mes como los automáticos.
     */
    public function assertPeriodOpen(string $date): void
    {
        if ($this->isPeriodClosed($date)) {
            $ts = strtotime($date) ?: time();
            throw new \InvalidArgumentException(
                'El periodo ' . date('m/Y', $ts) . ' está cerrado. Use una fecha de un periodo abierto '
                . 'o reabra el periodo en Contabilidad → Periodos.'
            );
        }
    }

    private function isPeriodClosed(string $date): bool
    {
        return (bool)$this->query(
            "SELECT 1 FROM accounting_periods
             WHERE year = YEAR(?) AND month = MONTH(?) AND status = 'closed' LIMIT 1",
            [$date, $date]
        )->fetchColumn();
    }

    /**
     * Elige la primera fecha candidata cuyo periodo esté abierto.
     * No inventa un mes futuro: si todas están cerradas, lanza con $failMessage.
     */
    private function resolveOpenPostingDate(?string $preferred, ?string $fallback, string $failMessage): string
    {
        foreach ([$preferred, $fallback] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $candidate);
            if (!$dt || $dt->format('Y-m-d') !== $candidate) {
                continue;
            }
            if (!$this->isPeriodClosed($candidate)) {
                return $candidate;
            }
        }
        throw new \InvalidArgumentException($failMessage);
    }

    /**
     * Auditoría de integridad del libro: asientos desbalanceados o líneas bilaterales.
     *
     * @return array{unbalanced:list<array<string,mixed>>,bad_lines:int,ok:bool}
     */
    public function auditEntryIntegrity(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $unbalanced = [];
        $badLines = 0;
        try {
            // Solo asientos recientes (1 año): full-scan histórico tumba el dashboard.
            $unbalanced = $this->query(
                "SELECT e.id, e.entry_number, e.entry_date, e.status, e.source_type, e.source_id,
                        ROUND(SUM(l.debit), 2) AS total_debit,
                        ROUND(SUM(l.credit), 2) AS total_credit,
                        ROUND(SUM(l.debit) - SUM(l.credit), 2) AS diff
                 FROM accounting_entries e
                 JOIN accounting_lines l ON l.entry_id = e.id
                 WHERE e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 366 DAY)
                 GROUP BY e.id, e.entry_number, e.entry_date, e.status, e.source_type, e.source_id
                 HAVING ABS(SUM(l.debit) - SUM(l.credit)) > 0.009
                 ORDER BY e.entry_date DESC, e.id DESC
                 LIMIT {$limit}"
            )->fetchAll();
            // Solo muestreo: COUNT full de líneas malas puede tardar minutos.
            $badLines = (int)$this->query(
                "SELECT COUNT(*) FROM (
                    SELECT l.id FROM accounting_lines l
                    JOIN accounting_entries e ON e.id = l.entry_id
                    WHERE e.entry_date >= DATE_SUB(CURDATE(), INTERVAL 366 DAY)
                      AND ((l.debit > 0.009 AND l.credit > 0.009)
                        OR (l.debit <= 0.009 AND l.credit <= 0.009))
                    LIMIT 501
                 ) bad"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return ['unbalanced' => [], 'bad_lines' => 0, 'ok' => true, 'error' => $e->getMessage()];
        }

        return [
            'unbalanced' => $unbalanced,
            'bad_lines' => $badLines,
            'ok' => $unbalanced === [] && $badLines === 0,
        ];
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

    /**
     * Crédito contable cuando el gasto ya está pagado pero NO salió del cajón POS.
     * Efectivo desde Gastos = retiro cajero / ahorros → banco (111005), nunca 110505.
     */
    private function paidExpenseCreditAccount(string $method, ?array $category): string
    {
        unset($category);
        if ($method === 'cash' || $method === '') {
            return $this->setting('bank_account', '111005');
        }
        return $this->paymentAccount($method);
    }

    /**
     * Cuenta de crédito del neto de nómina. Nunca usa caja POS (110505).
     */
    private function payrollNetCreditAccount(string $method, bool $paid): string
    {
        if (!$paid) {
            return $this->setting('payroll_payable_account', '250505');
        }
        return $this->payrollPaymentAccount($method);
    }

    /**
     * Cuenta de salida de pagos de nómina: banco para medios electrónicos;
     * efectivo contra la caja de nómina (110510), nunca el cajón POS.
     */
    public function payrollPaymentAccount(string $method): string
    {
        $method = strtolower(trim($method));
        $electronic = ['transfer', 'card', 'debit_card', 'credit_card', 'dataphone', 'payment_link', 'nequi', 'daviplata', 'breb'];
        if (in_array($method, $electronic, true)) {
            return $this->setting('bank_account', '111005');
        }
        $cashAccount = $this->setting('payroll_cash_account', '110510');
        if ($cashAccount === $this->setting('cash_account', '110505')) {
            $cashAccount = '110510';
        }
        return $cashAccount;
    }

    /**
     * Cuenta donde entra el cobro de una venta según el medio de pago (Bancos → Cuentas):
     * banco por entidad, billetera o pasarela por liquidar (Bold). Sin asignación, o con fecha
     * anterior a «collection_accounts_since», se usa la regla general de paymentAccount().
     * Los egresos (gastos, compras, nómina) no usan esta asignación.
     */
    public function collectionAccount(string $method, ?string $date = null): string
    {
        $default = $this->paymentAccount($method);
        $code = PaymentMethodCatalog::normalize($method);
        if ($code === 'cash' || $code === 'mixed') {
            return $default;
        }
        $mapped = $this->setting('collection_account_' . $code, '');
        if ($mapped === '') {
            return $default;
        }
        $since = $this->setting('collection_accounts_since', '');
        if ($since !== '' && $date !== null && $date < $since) {
            return $default;
        }
        $usable = $this->query(
            "SELECT 1 FROM accounting_accounts
             WHERE code = ? AND status = 'active' AND accepts_entries = 1 LIMIT 1",
            [$mapped]
        )->fetchColumn();
        return $usable ? $mapped : $default;
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
        return in_array($method, [
            'card', 'debit_card', 'credit_card', 'dataphone', 'payment_link', 'transfer',
            'nequi', 'daviplata', 'breb',
        ], true)
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
        $commercialDiscount = round((float)($order['commercial_discount_amount'] ?? 0), 2);
        $gross = round($subtotal + $tax, 2);
        if ($total <= 0) {
            // Solo bonificaciones / regalo (total 0): stock ya entró; no hay asiento CxP/caja.
            if ($subtotal <= 0.009 && $tax <= 0.009) {
                return null;
            }
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

        // Líneas sin producto (facturadas sin inventario: muestras, compra externa) van a gasto, no a 1435.
        $nonStock = 0.0;
        foreach ($order['items'] ?? [] as $item) {
            if (empty($item['product_id'])) {
                $nonStock += (float)($item['subtotal'] ?? 0);
            }
        }
        $nonStock = round(min(max(0.0, $subtotal), max(0.0, $nonStock)), 2);
        $stockSubtotal = round($subtotal - $nonStock, 2);

        $lines = [];
        if ($stockSubtotal > 0) {
            $lines[] = $this->line(
                $this->setting('inventory_account', '143501'),
                $stockSubtotal,
                0,
                'Entrada mercancía ' . $label
            );
        }
        if ($nonStock > 0) {
            $lines[] = $this->line(
                $this->setting('purchase_non_stock_account', '519595'),
                $nonStock,
                0,
                'Compra sin inventario (muestras / externa) ' . $label
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

        if ($commercialDiscount > 0.009) {
            $lines[] = $this->line(
                $this->setting('purchase_discount_account', '421040'),
                0,
                $commercialDiscount,
                'Descuento comercial ' . $label,
                'supplier',
                $order['supplier_id'] ?? null,
                $order['supplier_name'] ?? null
            );
        }

        if ($discount > 0.009) {
            $lines[] = $this->line(
                $this->setting('purchase_discount_account', '421040'),
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
     * Contabiliza una nota de crédito de compra (devolución o ajuste comercial).
     * Invierte parcialmente la OC: reduce CxP (o caja/banco) y saca inventario / descuento.
     */
    public function postPurchaseCreditNote(int $creditNoteId): ?int
    {
        $purchasing = new PurchasingService($this->db);
        $note = $this->query(
            "SELECT cn.*, po.order_number, po.payment_mode, po.supplier_id po_supplier_id,
                    s.name supplier_name
             FROM purchase_credit_notes cn
             JOIN purchase_orders po ON po.id = cn.purchase_order_id
             LEFT JOIN suppliers s ON s.id = COALESCE(cn.supplier_id, po.supplier_id)
             WHERE cn.id = ?",
            [$creditNoteId]
        )->fetch();
        if (!$note || ($note['status'] ?? '') !== 'posted') {
            throw new \RuntimeException('Nota de crédito no encontrada');
        }
        if (!empty($note['accounting_entry_id'])) {
            return (int)$note['accounting_entry_id'];
        }

        $subtotal = round((float)$note['subtotal'], 2);
        $tax = round((float)$note['tax'], 2);
        $total = round((float)$note['total'], 2);
        if ($total <= 0) {
            throw new \RuntimeException('La nota de crédito no tiene total contable');
        }
        // Misma lógica que la OC: total = bruto − descuento pronto pago / aliado.
        $gross = round($subtotal + $tax, 2);
        $discount = round(max(0, $gross - $total), 2);

        $date = date('Y-m-d', strtotime((string)$note['credit_date']));
        $paymentMode = (string)($note['payment_mode'] ?? 'payable');
        $label = 'NCC ' . $note['credit_number'] . ' / OC ' . $note['order_number'];
        $affectsStock = (int)($note['affects_stock'] ?? 1) === 1;
        $supplierId = $note['supplier_id'] ?? $note['po_supplier_id'] ?? null;
        $supplierName = $note['supplier_name'] ?? null;
        $lines = [];

        // Débito: reduce CxP / recupera caja-banco. Si la OC a crédito ya tenía abonos,
        // no sobre-debitar 220505: el excedente va a bancos (reembolso / saldo a favor).
        $payableDebit = $total;
        $refundDebit = 0.0;
        if ($paymentMode === 'payable' || $paymentMode === '') {
            $orderId = (int)($note['purchase_order_id'] ?? 0);
            $unpaidBefore = 0.0;
            try {
                $ops = $this->query(
                    "SELECT po.total,
                            COALESCE((
                                SELECT SUM(sp.amount) FROM supplier_payments sp
                                WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                            ), 0) paid,
                            COALESCE((
                                SELECT SUM(cn.total) FROM purchase_credit_notes cn
                                WHERE cn.purchase_order_id = po.id AND cn.status = 'posted' AND cn.id <> ?
                            ), 0) credited
                     FROM purchase_orders po
                     WHERE po.id = ?",
                    [$creditNoteId, $orderId]
                )->fetch();
                if ($ops) {
                    $unpaidBefore = round(
                        max(0, (float)$ops['total'] - (float)$ops['paid'] - (float)$ops['credited']),
                        2
                    );
                }
            } catch (\Throwable $e) {
                $unpaidBefore = $total;
            }
            if ($total > $unpaidBefore + 0.009) {
                $payableDebit = $unpaidBefore;
                $refundDebit = round($total - $unpaidBefore, 2);
            }
            if ($payableDebit > 0.009) {
                $lines[] = $this->line(
                    $this->setting('payable_account', '220505'),
                    $payableDebit,
                    0,
                    'Nota crédito proveedor (reduce CxP) ' . $label,
                    'supplier',
                    $supplierId,
                    $supplierName
                );
            }
            if ($refundDebit > 0.009) {
                $lines[] = $this->line(
                    $this->setting('bank_account', '111005'),
                    $refundDebit,
                    0,
                    'Reembolso / saldo a favor tras NC ' . $label,
                    'supplier',
                    $supplierId,
                    $supplierName
                );
            }
        } else {
            $debitCode = match ($paymentMode) {
                'cash' => $this->setting('cash_account', '110505'),
                'transfer', 'card' => $this->setting('bank_account', '111005'),
                default => $this->setting('payable_account', '220505'),
            };
            $debitLabel = match ($paymentMode) {
                'cash' => 'Devolución/crédito compra en efectivo',
                'transfer', 'card' => 'Devolución/crédito compra transferencia',
                default => 'Nota crédito proveedor (reduce CxP)',
            };
            $lines[] = $this->line(
                $debitCode,
                $total,
                0,
                $debitLabel . ' ' . $label,
                'supplier',
                $supplierId,
                $supplierName
            );
        }

        // Reverso del ingreso por descuentos de la OC (comercial + pronto pago) si aplica.
        if ($discount > 0.009) {
            $lines[] = $this->line(
                $this->setting('purchase_discount_account', '421040'),
                $discount,
                0,
                'Reverso descuentos compra ' . $label,
                'supplier',
                $supplierId,
                $supplierName
            );
        }

        if ($affectsStock) {
            if ($subtotal > 0) {
                $lines[] = $this->line(
                    $this->setting('inventory_account', '143501'),
                    0,
                    $subtotal,
                    'Salida mercancía ' . $label
                );
            }
        } else {
            if ($subtotal > 0) {
                $lines[] = $this->line(
                    $this->setting('purchase_discount_account', '421040'),
                    0,
                    $subtotal,
                    'Descuento/ajuste comercial ' . $label,
                    'supplier',
                    $note['supplier_id'] ?? $note['po_supplier_id'] ?? null,
                    $note['supplier_name'] ?? null
                );
            }
        }

        if ($tax > 0) {
            $lines[] = $this->line(
                $this->setting('vat_deductible_account', '240802'),
                0,
                $tax,
                'Reverso IVA descontable ' . $label,
                'supplier',
                $note['supplier_id'] ?? $note['po_supplier_id'] ?? null,
                $note['supplier_name'] ?? null
            );
        }

        // Si solo hay total (sin subtotal/tax desglosado), acreditar inventario/descuento por el total.
        if (count($lines) === 1) {
            $lines[] = $this->line(
                $affectsStock
                    ? $this->setting('inventory_account', '143501')
                    : $this->setting('purchase_discount_account', '421040'),
                0,
                $total,
                ($affectsStock ? 'Salida mercancía ' : 'Ajuste comercial ') . $label
            );
        }

        $entryId = $this->postEntry(
            $date,
            'Nota crédito compra ' . $label,
            $lines,
            'purchase_credit_note',
            $creditNoteId,
            'posted'
        );

        $this->query(
            "UPDATE purchase_credit_notes SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $creditNoteId]
        );
        $this->query(
            "UPDATE stock_movements
             SET accounting_entry_id = ?
             WHERE reference_type = 'purchase_credit_note' AND reference_id = ?",
            [$entryId, $creditNoteId]
        );

        return $entryId;
    }

    /**
     * Contabiliza una nota de crédito de venta (devolución de cliente).
     * Débito devoluciones en ventas + reverso IVA; crédito CxC/caja/banco;
     * si reingresa stock, revierte CMV/inventario.
     */
    public function postSaleCreditNote(int $creditNoteId): ?int
    {
        $svc = new SalesCreditNoteService($this->db);
        $note = $svc->getCreditNote($creditNoteId);
        if (!$note || ($note['status'] ?? '') !== 'posted') {
            throw new \RuntimeException('Nota de crédito de venta no encontrada');
        }
        if (!empty($note['accounting_entry_id'])) {
            return (int)$note['accounting_entry_id'];
        }

        $subtotal = round((float)$note['subtotal'], 2);
        $tax = round((float)$note['tax'], 2);
        $total = round((float)$note['total'], 2);
        if ($total <= 0) {
            throw new \RuntimeException('La nota de crédito no tiene total contable');
        }

        $date = date('Y-m-d', strtotime((string)$note['credit_date']));
        $allocation = $svc->allocateRefund($creditNoteId);
        $label = 'NCV ' . $note['credit_number']
            . (!empty($note['invoice_number']) ? ' / ' . $note['invoice_number'] : '');
        $affectsStock = (int)($note['affects_stock'] ?? 1) === 1;
        $lines = [];

        // Débito: devoluciones en ventas (contra-ingreso).
        if ($subtotal > 0.009) {
            $lines[] = $this->line(
                $this->setting('sales_returns_account', '417501'),
                $subtotal,
                0,
                'Devolución en ventas ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }
        if ($tax > 0.009) {
            $lines[] = $this->line(
                $this->setting('vat_generated_account', '240801'),
                $tax,
                0,
                'Reverso IVA generado ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }
        if ($subtotal <= 0.009 && $tax <= 0.009) {
            $lines[] = $this->line(
                $this->setting('sales_returns_account', '417501'),
                $total,
                0,
                'Devolución en ventas ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }

        // Crédito: puede repartirse entre CxC / caja / banco (pago mixto o parcial).
        $recv = round((float)($allocation['receivable'] ?? 0), 2);
        $cashAmt = round((float)($allocation['cash'] ?? 0), 2);
        $transferAmt = round((float)($allocation['transfer'] ?? 0), 2);
        $creditedSum = round($recv + $cashAmt + $transferAmt, 2);
        if (abs($creditedSum - $total) > 0.02) {
            // Seguridad: si la asignación no cuadra, usar método simple.
            $method = $svc->resolveRefundMethod($note);
            $recv = $cashAmt = $transferAmt = 0.0;
            if ($method === 'cash') {
                $cashAmt = $total;
            } elseif ($method === 'transfer') {
                $transferAmt = $total;
            } else {
                $recv = $total;
            }
        }
        if ($recv > 0.009) {
            $lines[] = $this->line(
                $this->setting('receivable_account', '130505'),
                0,
                $recv,
                'Reduce cuenta por cobrar ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }
        if ($cashAmt > 0.009) {
            $lines[] = $this->line(
                $this->setting('cash_account', '110505'),
                0,
                $cashAmt,
                'Reembolso en efectivo ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }
        if ($transferAmt > 0.009) {
            $lines[] = $this->line(
                $this->setting('bank_account', '111005'),
                0,
                $transferAmt,
                'Reembolso transferencia/banco ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }
        if ($recv <= 0.009 && $cashAmt <= 0.009 && $transferAmt <= 0.009) {
            $lines[] = $this->line(
                $this->setting('receivable_account', '130505'),
                0,
                $total,
                'Reduce cuenta por cobrar ' . $label,
                'customer',
                $note['customer_id'] ?? null,
                $note['customer_name'] ?? null
            );
        }

        // Reverso CMV / inventario (propio) y CxP consignación.
        // Consignación: siempre se revierte la CxP al consignante (aunque no reingrese stock).
        $costOwned = 0.0;
        $costConsignment = 0.0;
        foreach ($note['items'] ?? [] as $it) {
            $unitCost = (float)($it['unit_cost'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $productId = !empty($it['product_id']) ? (int)$it['product_id'] : 0;
            $saleItemId = !empty($it['sale_item_id']) ? (int)$it['sale_item_id'] : 0;
            $isConsignment = false;
            if ($saleItemId > 0) {
                try {
                    $flag = $this->query(
                        "SELECT COALESCE(si.is_consignment, 0) is_consignment,
                                COALESCE(p.ownership, 'owned') ownership
                         FROM sale_items si
                         LEFT JOIN products p ON p.id = si.product_id
                         WHERE si.id = ? LIMIT 1",
                        [$saleItemId]
                    )->fetch();
                    if ($flag) {
                        $isConsignment = ((int)($flag['is_consignment'] ?? 0) === 1)
                            || (($flag['ownership'] ?? '') === 'consignment');
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            if (!$isConsignment && $productId > 0) {
                try {
                    $own = (string)$this->query(
                        "SELECT COALESCE(ownership, 'owned') FROM products WHERE id = ?",
                        [$productId]
                    )->fetchColumn();
                    $isConsignment = $own === 'consignment';
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            if ($unitCost <= 0 && $productId > 0) {
                $unitCost = (float)$this->query(
                    "SELECT COALESCE(purchase_price, 0) FROM products WHERE id = ?",
                    [$productId]
                )->fetchColumn();
            }
            $lineCost = round($unitCost * $qty, 2);
            if ($isConsignment) {
                $costConsignment += $lineCost;
            } elseif ($affectsStock) {
                $costOwned += $lineCost;
            }
        }
        $costOwned = round($costOwned, 2);
        $costConsignment = round($costConsignment, 2);
        if ($costOwned > 0.009) {
            $lines[] = $this->line(
                $this->setting('inventory_account', '143501'),
                $costOwned,
                0,
                'Reingreso inventario ' . $label
            );
            $lines[] = $this->line(
                $this->setting('cost_of_sales_account', '613501'),
                0,
                $costOwned,
                'Reverso CMV ' . $label
            );
        }
        if ($costConsignment > 0.009) {
            $lines[] = $this->line(
                $this->setting('consignor_payable_account', '220510'),
                $costConsignment,
                0,
                'Reverso CxP consignante ' . $label
            );
            $lines[] = $this->line(
                $this->setting('consignment_cost_account', '613510'),
                0,
                $costConsignment,
                'Reverso costo consignación ' . $label
            );
        }

        $entryId = $this->postEntry(
            $date,
            'Nota crédito venta ' . $label,
            $lines,
            'sale_credit_note',
            $creditNoteId,
            'created'
        );

        $this->query(
            "UPDATE sale_credit_notes SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $creditNoteId]
        );
        $this->query(
            "UPDATE stock_movements
             SET accounting_entry_id = ?
             WHERE reference_type = 'sale_credit_note' AND reference_id = ?",
            [$entryId, $creditNoteId]
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
        if (in_array($referenceType, [
            'sale', 'return', 'sale_credit_note', 'purchase_order', 'purchase_credit_note', 'purchase_cancel',
            'loan', 'loan_return', 'loan_received', 'loan_received_return', 'status_change',
            'consignment_in', 'consignment_return',
        ], true)) {
            // sale/return/NCV: asiento de venta. purchase_order / NCC: asiento propio.
            // purchase_cancel: lo cubre el reverso del asiento de la OC.
            // loan / consignación / status_change: sin capitalizar inventario propio.
            return null;
        }

        $paymentMode = (string)($movement['payment_mode'] ?? '');
        $type = (string)$movement['type'];

        $amount = round((float)($movement['total_cost'] ?? 0), 2);
        if ($amount <= 0) {
            $amount = round((float)($movement['unit_cost'] ?? 0) * abs((int)$movement['quantity']), 2);
        }
        if ($amount <= 0 && ($referenceType === 'inventory_exit' || $paymentMode === 'loss' || $type === 'out')) {
            // Último recurso: valorizar a PVP para no dejar la pérdida sin asiento.
            $sale = (float)$this->query(
                "SELECT COALESCE(sale_price, 0) FROM products WHERE id = ?",
                [(int)$movement['product_id']]
            )->fetchColumn();
            $amount = round($sale * abs((int)$movement['quantity']), 2);
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

        // Ajuste negativo o salida no-venta: merma / pérdida operativa
        $isOutbound = $type === 'out'
            || $referenceType === 'inventory_exit'
            || ($type === 'adjustment' && ($paymentMode === 'loss' || str_contains((string)($movement['notes'] ?? ''), 'Ajuste (−)')));

        if ($isOutbound) {
            $lossAccount = $this->setting(
                'inventory_loss_account',
                $this->setting('inventory_adjustment_account', '519595')
            );
            $exitNote = trim((string)($movement['notes'] ?? ''));
            $debitMemo = $exitNote !== ''
                ? $exitNote
                : ('Pérdida / salida inventario: ' . $label);
            $lines = [
                $this->line(
                    $lossAccount,
                    $amount,
                    0,
                    mb_substr($debitMemo, 0, 255)
                ),
                $this->line(
                    $inventory,
                    0,
                    $amount,
                    'Baja de inventario (activo): ' . $label
                ),
            ];
            $description = $referenceType === 'inventory_exit'
                ? ('Pérdida inventario: ' . $label . ' · $' . number_format($amount, 2, '.', ''))
                : ('Ajuste inventario (−) ' . $label);
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
     * Conciliación entre los submayores operativos y el libro contable.
     * Un descuadre indica operaciones sin asiento o asientos manuales sueltos.
     *
     * @return list<array{label:string,account:string,operational:float,accounting:float,gap:float,hint:string}>
     */
    public function subledgerReconciliation(): array
    {
        $rows = [];

        $rows[] = $this->reconcileLine(
            'Cartera de clientes',
            $this->setting('receivable_account', '130505'),
            "SELECT COALESCE(SUM(balance), 0) FROM receivables WHERE status != 'paid'",
            'Revise ventas a crédito sin asiento o abonos no registrados.'
        );

        $rows[] = $this->reconcileLine(
            'Proveedores por pagar',
            $this->setting('payable_account', '220505'),
            "SELECT COALESCE(SUM(po.total - COALESCE((
                        SELECT SUM(sp.amount) FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id AND sp.status = 'applied'
                   ), 0) - COALESCE((
                        SELECT SUM(cn.total) FROM purchase_credit_notes cn
                        WHERE cn.purchase_order_id = po.id AND cn.status = 'posted'
                   ), 0)), 0)
             FROM purchase_orders po
             WHERE po.status = 'received' AND COALESCE(po.payment_mode, 'payable') = 'payable'",
            'Revise órdenes recibidas sin contabilizar, abonos o notas de crédito sin asiento.'
        );

        $rows[] = $this->reconcileLine(
            'Inventario valorizado',
            $this->setting('inventory_account', '143501'),
            "SELECT COALESCE(SUM(stock * purchase_price), 0)
             FROM products
             WHERE COALESCE(product_type, 'product') = 'product' AND status = 'active'
               AND COALESCE(ownership, 'owned') = 'owned'",
            'El costo promedio cambia con cada compra; una brecha pequeña es normal. La consignación no se capitaliza en 143501.'
        );

        return array_values(array_filter($rows));
    }

    /**
     * @return array{label:string,account:string,operational:float,accounting:float,gap:float,hint:string}|null
     */
    private function reconcileLine(string $label, string $accountCode, string $operationalSql, string $hint): ?array
    {
        try {
            $operational = round((float)$this->query($operationalSql)->fetchColumn(), 2);
        } catch (\Throwable $e) {
            return null;
        }

        try {
            $balance = $this->query(
                "SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) net
                 FROM accounting_lines l
                 JOIN accounting_accounts a ON a.id = l.account_id
                 JOIN accounting_entries e ON e.id = l.entry_id
                 WHERE a.code = ? AND e.status IN ('posted', 'reversed')",
                [$accountCode]
            )->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        // Los pasivos tienen naturaleza crédito: se presentan en positivo.
        $accounting = round((float)$balance, 2);
        if (str_starts_with($accountCode, '2')) {
            $accounting = -$accounting;
        }

        return [
            'label' => $label,
            'account' => $accountCode,
            'operational' => $operational,
            'accounting' => $accounting,
            'gap' => round($operational - $accounting, 2),
            'hint' => $hint,
        ];
    }

    /**
     * Liquidación a consignante: Dr. CxP consignantes / Cr. caja o banco.
     */
    public function postConsignmentSettlement(int $settlementId): ?int
    {
        $row = $this->query(
            "SELECT s.*, c.name consignor_name
             FROM consignment_settlements s
             LEFT JOIN consignors c ON c.id = s.consignor_id
             WHERE s.id = ?",
            [$settlementId]
        )->fetch();
        if (!$row) {
            throw new \RuntimeException('Liquidación de consignación no encontrada');
        }
        if (!empty($row['accounting_entry_id'])) {
            return (int)$row['accounting_entry_id'];
        }
        $amount = round((float)$row['amount'], 2);
        if ($amount <= 0) {
            return null;
        }
        $method = (string)($row['payment_method'] ?? 'cash');
        $creditAccount = PaymentMethodCatalog::affectsCash($method)
            ? $this->setting('cash_account', '110505')
            : $this->setting('bank_account', '111005');
        $name = (string)($row['consignor_name'] ?? 'Consignante');
        $lines = [
            $this->line(
                $this->setting('consignor_payable_account', '220510'),
                $amount,
                0,
                'Liquidación consignación · ' . $name,
                'consignor',
                (int)$row['consignor_id'],
                $name
            ),
            $this->line(
                $creditAccount,
                0,
                $amount,
                'Pago a consignante · ' . PaymentMethodCatalog::label($method, $this->db)
            ),
        ];
        return $this->postEntry(
            date('Y-m-d', strtotime((string)$row['settlement_date'])),
            'Liquidación consignación #' . $settlementId . ' · ' . $name,
            $lines,
            'consignment_settlement',
            $settlementId,
            'created'
        );
    }

    /**
     * Abono a proveedor: liquida la cuenta por pagar contra caja o banco.
     */
    public function postSupplierPayment(int $paymentId): ?int
    {
        $payment = $this->query(
            "SELECT sp.*, s.name supplier_name,
                    po.order_number, po.invoice_number
             FROM supplier_payments sp
             LEFT JOIN suppliers s ON s.id = sp.supplier_id
             LEFT JOIN purchase_orders po ON po.id = sp.purchase_order_id
             WHERE sp.id = ?",
            [$paymentId]
        )->fetch();

        if (!$payment) {
            throw new \RuntimeException('Abono a proveedor no encontrado');
        }
        if (($payment['status'] ?? 'applied') === 'cancelled') {
            return null;
        }
        if (!empty($payment['accounting_entry_id'])) {
            return (int)$payment['accounting_entry_id'];
        }

        $amount = round((float)$payment['amount'], 2);
        if ($amount <= 0) {
            return null;
        }

        $date = date('Y-m-d', strtotime((string)$payment['payment_date']));
        $method = (string)($payment['payment_method'] ?? 'cash');
        $supplierName = (string)($payment['supplier_name'] ?? 'Proveedor');
        $cashFromDrawer = !array_key_exists('cash_from_drawer', $payment)
            || (int)$payment['cash_from_drawer'] === 1;
        // Efectivo fuera del cajón: crédito a bancos (igual que gastos fuera de POS), no 110505.
        $creditCode = ($method === 'cash' && !$cashFromDrawer)
            ? $this->setting('bank_account', '111005')
            : $this->paymentAccount($method);

        $label = $supplierName;
        if (!empty($payment['order_number'])) {
            $label .= ' / OC ' . $payment['order_number'];
        }
        if (!empty($payment['invoice_number'])) {
            $label .= ' / Factura ' . $payment['invoice_number'];
        }

        $creditDetail = 'Pago a proveedor ' . $label;
        if ($method === 'cash' && !$cashFromDrawer) {
            $src = trim((string)($payment['cash_source_note'] ?? ''));
            $creditDetail .= $src !== ''
                ? (' · Efectivo fuera del cajón: ' . $src)
                : ' · Efectivo fuera del cajón';
        }

        // Dcto. pronto pago obtenido al pagar: baja la CxP sin salir de caja/bancos.
        $discountAmount = round((float)($payment['discount_amount'] ?? 0), 2);
        $lines = [
            $this->line(
                $this->setting('payable_account', '220505'),
                round($amount + $discountAmount, 2),
                0,
                'Abono cuenta por pagar ' . $label,
                'supplier',
                $payment['supplier_id'] ?? null,
                $supplierName
            ),
            $this->line(
                $creditCode,
                0,
                $amount,
                $creditDetail,
                'supplier',
                $payment['supplier_id'] ?? null,
                $supplierName
            ),
        ];
        if ($discountAmount > 0.009) {
            $lines[] = $this->line(
                $this->setting('purchase_discount_account', '421040'),
                0,
                $discountAmount,
                'Descuento pronto pago ' . $label,
                'supplier',
                $payment['supplier_id'] ?? null,
                $supplierName
            );
        }

        $entryId = $this->postEntry(
            $date,
            'Abono a proveedor ' . $label,
            $lines,
            'supplier_payment',
            $paymentId,
            'created'
        );

        $this->query(
            "UPDATE supplier_payments SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $paymentId]
        );

        return $entryId;
    }

    /**
     * Movimiento manual de caja (no ligado a venta ni gasto).
     * Los movimientos con origen en otro módulo ya tienen su propio asiento.
     */
    public function postCashMovement(int $movementId): ?int
    {
        $movement = $this->query(
            "SELECT * FROM cash_movements WHERE id = ?",
            [$movementId]
        )->fetch();

        if (!$movement) {
            throw new \RuntimeException('Movimiento de caja no encontrado');
        }
        if (!empty($movement['accounting_entry_id'])) {
            return (int)$movement['accounting_entry_id'];
        }

        $reference = (string)($movement['reference_type'] ?? '');
        if (!in_array($reference, ['manual', ''], true)) {
            return null;
        }

        $amount = round((float)$movement['amount'], 2);
        if ($amount <= 0) {
            return null;
        }

        $date = date('Y-m-d', strtotime((string)$movement['created_at']));
        $cash = $this->setting('cash_account', '110505');
        $description = trim((string)($movement['description'] ?? '')) ?: 'Movimiento manual de caja';
        $isIncome = (string)$movement['type'] === 'income';

        $lines = $isIncome
            ? [
                $this->line($cash, $amount, 0, 'Ingreso a caja: ' . $description),
                $this->line(
                    $this->setting('cash_manual_income_account', '429595'),
                    0,
                    $amount,
                    'Otros ingresos: ' . $description
                ),
            ]
            : [
                $this->line(
                    $this->setting('cash_manual_expense_account', '519595'),
                    $amount,
                    0,
                    'Otros gastos: ' . $description
                ),
                $this->line($cash, 0, $amount, 'Salida de caja: ' . $description),
            ];

        $entryId = $this->postEntry(
            $date,
            ($isIncome ? 'Ingreso manual de caja: ' : 'Egreso manual de caja: ') . $description,
            $lines,
            'cash_movement',
            $movementId,
            'created'
        );

        $this->query(
            "UPDATE cash_movements SET accounting_entry_id = ? WHERE id = ?",
            [$entryId, $movementId]
        );

        return $entryId;
    }

    /**
     * Arqueo de caja: registra únicamente el faltante o sobrante del cierre.
     * El efectivo en sí ya entró al libro por la venta o el gasto que lo originó.
     */
    public function postCashSessionClosing(int $sessionId): ?int
    {
        SupplyChainSchema::ensure($this->db);

        $session = $this->query(
            "SELECT * FROM cash_sessions WHERE id = ?",
            [$sessionId]
        )->fetch();

        if (!$session) {
            throw new \RuntimeException('Sesión de caja no encontrada');
        }
        if (($session['status'] ?? '') !== 'closed') {
            throw new \RuntimeException('La caja debe estar cerrada para contabilizar el arqueo');
        }
        if (!empty($session['closing_entry_id'])) {
            return (int)$session['closing_entry_id'];
        }

        $difference = round((float)($session['difference_amount'] ?? 0), 2);
        if (abs($difference) < 0.01) {
            return null;
        }

        $date = !empty($session['closing_date'])
            ? date('Y-m-d', strtotime((string)$session['closing_date']))
            : date('Y-m-d');
        $cash = $this->setting('cash_account', '110505');
        $amount = abs($difference);
        $label = 'arqueo caja #' . $sessionId . ' del ' . date('d/m/Y', strtotime($date));

        // difference_amount = contado − esperado: negativo es faltante.
        $lines = $difference < 0
            ? [
                $this->line(
                    $this->setting('cash_shortage_account', '539595'),
                    $amount,
                    0,
                    'Faltante de ' . $label
                ),
                $this->line($cash, 0, $amount, 'Ajuste por faltante de caja'),
            ]
            : [
                $this->line($cash, $amount, 0, 'Ajuste por sobrante de caja'),
                $this->line(
                    $this->setting('cash_overage_account', '429595'),
                    0,
                    $amount,
                    'Sobrante de ' . $label
                ),
            ];

        $entryId = $this->postEntry(
            $date,
            ($difference < 0 ? 'Faltante de ' : 'Sobrante de ') . $label,
            $lines,
            'cash_session',
            $sessionId,
            'closing'
        );

        $this->query(
            "UPDATE cash_sessions SET closing_entry_id = ? WHERE id = ?",
            [$entryId, $sessionId]
        );

        return $entryId;
    }
}
