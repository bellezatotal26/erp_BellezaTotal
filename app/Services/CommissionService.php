<?php

namespace SoftNova\Services;

/**
 * Comisiones de vendedor y de pasarela (datáfono/tarjeta/link).
 *
 * Parámetros en accounting_settings; tasas por usuario en user_commission_rates;
 * movimientos en sales_commissions.
 */
class CommissionService
{
    public function __construct(private \PDO $db)
    {
        TenantOpsSchema::ensure($db);
        $this->ensureSchema();
        $this->seedDefaults();
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function ensureSchema(): void
    {
        static $done = [];
        $oid = spl_object_id($this->db);
        if (isset($done[$oid])) {
            return;
        }
        $done[$oid] = true;

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS user_commission_rates (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS sales_commissions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                commission_kind VARCHAR(20) NOT NULL DEFAULT 'seller',
                sale_id INT UNSIGNED NOT NULL,
                sale_payment_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                payment_method VARCHAR(50) NULL,
                base_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
                expense_id INT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_sc_sale (sale_id),
                KEY idx_sc_user (user_id),
                KEY idx_sc_status (status),
                KEY idx_sc_kind (commission_kind),
                KEY idx_sc_payment (sale_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $this->query(
                "INSERT IGNORE INTO accounting_accounts (code, name, account_type, nature, is_system)
                 VALUES ('510508', 'Comisiones sobre ventas', 'expense', 'debit', 1)"
            );
        } catch (\Throwable $e) {
            // Contabilidad aún no provisionada en este tenant.
        }
    }

    private function seedDefaults(): void
    {
        $defaults = [
            'seller_commission_enabled' => '0',
            'seller_commission_rate' => '3',
            'seller_commission_base' => 'total', // total|subtotal|profit
            'seller_commission_trigger' => 'on_payment', // on_sale|on_payment
            'seller_commission_auto_expense' => '1',
            'gateway_commission_auto' => '1',
            'dataphone_commission_rate' => '2.5',
            'card_commission_rate' => '2.8',
            'payment_link_commission_rate' => '2.5',
            'debit_card_commission_rate' => '1.5',
            'credit_card_commission_rate' => '2.8',
            'seller_commission_account' => '510508',
            'gateway_commission_account' => '530505',
        ];
        foreach ($defaults as $k => $v) {
            $this->query(
                "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)",
                [$k, $v]
            );
        }
    }

    private function setting(string $key, string $default = ''): string
    {
        $row = $this->query(
            'SELECT setting_value FROM accounting_settings WHERE setting_key = ? LIMIT 1',
            [$key]
        )->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    }

    public function config(): array
    {
        return [
            'seller_enabled' => $this->setting('seller_commission_enabled', '0') === '1',
            'seller_rate' => (float)$this->setting('seller_commission_rate', '3'),
            'seller_base' => $this->setting('seller_commission_base', 'total'),
            'seller_trigger' => $this->setting('seller_commission_trigger', 'on_payment'),
            'seller_auto_expense' => $this->setting('seller_commission_auto_expense', '1') === '1',
            'gateway_auto' => $this->setting('gateway_commission_auto', '1') === '1',
            'dataphone_rate' => (float)$this->setting('dataphone_commission_rate', '2.5'),
            'card_rate' => (float)$this->setting('card_commission_rate', '2.8'),
            'payment_link_rate' => (float)$this->setting('payment_link_commission_rate', '2.5'),
            'debit_card_rate' => (float)$this->setting('debit_card_commission_rate', '1.5'),
            'credit_card_rate' => (float)$this->setting('credit_card_commission_rate', '2.8'),
            'credit_over_60_rate' => (float)$this->setting('credit_over_60_surcharge_rate', '0'),
            'credit_over_60_days' => (int)$this->setting('credit_over_60_surcharge_days', '60'),
            'credit_surcharge_tiers' => $this->creditSurchargeTiers(),
            'quantity_discount_tiers' => $this->quantityDiscountTiers(),
            'seller_account' => $this->setting('seller_commission_account', '510508'),
            'gateway_account' => $this->setting('gateway_commission_account', '530505'),
        ];
    }

    /**
     * Tramos de descuento por cantidad (cualquier producto).
     * Cada tramo: min_qty + rate (%). Se aplica el tramo con mayor min_qty
     * tal que cantidad_del_producto > min_qty.
     *
     * @return list<array{min_qty:int,rate:float}>
     */
    public function quantityDiscountTiers(): array
    {
        $raw = trim($this->setting('quantity_discount_tiers', ''));
        $tiers = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $qty = (int)($row['min_qty'] ?? $row['qty'] ?? $row['min_quantity'] ?? 0);
                    $rate = (float)($row['rate'] ?? 0);
                    if ($qty < 1 || $rate < 0) {
                        continue;
                    }
                    $tiers[] = [
                        'min_qty' => min(1000000, $qty),
                        'rate' => min(100.0, $rate),
                    ];
                }
            }
        }

        usort($tiers, static fn($a, $b) => $a['min_qty'] <=> $b['min_qty']);
        $uniq = [];
        foreach ($tiers as $t) {
            $uniq[(int)$t['min_qty']] = $t;
        }
        return array_values($uniq);
    }

    /**
     * Resuelve el descuento por cantidad aplicable.
     *
     * @return array{rate:float,min_qty:int,matched:bool}
     */
    public function resolveQuantityDiscount(int $quantity): array
    {
        $best = ['rate' => 0.0, 'min_qty' => 0, 'matched' => false];
        foreach ($this->quantityDiscountTiers() as $tier) {
            $min = (int)$tier['min_qty'];
            $rate = (float)$tier['rate'];
            if ($quantity > $min && $rate > 0 && $min >= (int)$best['min_qty']) {
                $best = ['rate' => $rate, 'min_qty' => $min, 'matched' => true];
            }
        }
        return $best;
    }

    /**
     * @param list<array<string,mixed>>|string|null $raw
     * @return list<array{min_qty:int,rate:float}>
     */
    public static function normalizeQuantityDiscountTiers(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qty = (int)($row['min_qty'] ?? $row['qty'] ?? $row['min_quantity'] ?? 0);
            $rate = (float)($row['rate'] ?? 0);
            if ($qty < 1 || $rate < 0) {
                continue;
            }
            $out[$qty] = ['min_qty' => min(1000000, $qty), 'rate' => min(100.0, $rate)];
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Tramos de recargo por plazo de crédito.
     * Cada tramo: min_days + rate (%). Se aplica el tramo con mayor min_days
     * tal que días_del_plazo > min_days.
     *
     * @return list<array{min_days:int,rate:float}>
     */
    public function creditSurchargeTiers(): array
    {
        $raw = trim($this->setting('credit_surcharge_tiers', ''));
        $tiers = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $days = (int)($row['min_days'] ?? $row['days'] ?? 0);
                    $rate = (float)($row['rate'] ?? 0);
                    if ($days < 1 || $rate < 0) {
                        continue;
                    }
                    $tiers[] = [
                        'min_days' => min(3650, $days),
                        'rate' => min(100.0, $rate),
                    ];
                }
            }
        }

        // Compat: un solo umbral legado → un tramo.
        if ($tiers === []) {
            $legacyDays = (int)$this->setting('credit_over_60_surcharge_days', '60');
            $legacyRate = (float)$this->setting('credit_over_60_surcharge_rate', '0');
            if ($legacyDays < 1) {
                $legacyDays = 60;
            }
            if ($legacyRate > 0) {
                $tiers[] = ['min_days' => $legacyDays, 'rate' => min(100.0, $legacyRate)];
            }
        }

        usort($tiers, static fn($a, $b) => $a['min_days'] <=> $b['min_days']);
        // Deduplicar por min_days (gana el último rate definido).
        $uniq = [];
        foreach ($tiers as $t) {
            $uniq[(int)$t['min_days']] = $t;
        }
        return array_values($uniq);
    }

    /**
     * Resuelve el recargo aplicable para un plazo en días.
     *
     * @return array{rate:float,min_days:int,matched:bool}
     */
    public function resolveCreditSurcharge(int $termDays): array
    {
        $best = ['rate' => 0.0, 'min_days' => 0, 'matched' => false];
        foreach ($this->creditSurchargeTiers() as $tier) {
            $min = (int)$tier['min_days'];
            $rate = (float)$tier['rate'];
            if ($termDays > $min && $rate > 0 && $min >= (int)$best['min_days']) {
                $best = ['rate' => $rate, 'min_days' => $min, 'matched' => true];
            }
        }
        return $best;
    }

    /**
     * @param list<array<string,mixed>>|string|null $raw
     * @return list<array{min_days:int,rate:float}>
     */
    public static function normalizeCreditSurchargeTiers(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $days = (int)($row['min_days'] ?? $row['days'] ?? 0);
            $rate = (float)($row['rate'] ?? 0);
            if ($days < 1 || $rate < 0) {
                continue;
            }
            $out[$days] = ['min_days' => min(3650, $days), 'rate' => min(100.0, $rate)];
        }
        ksort($out);
        return array_values($out);
    }

    public function saveConfig(array $input): void
    {
        $map = [
            'seller_commission_enabled' => !empty($input['seller_enabled']) ? '1' : '0',
            'seller_commission_rate' => (string)max(0, min(100, (float)($input['seller_rate'] ?? 0))),
            'seller_commission_base' => in_array(($input['seller_base'] ?? ''), ['total', 'subtotal', 'profit'], true)
                ? $input['seller_base'] : 'total',
            'seller_commission_trigger' => in_array(($input['seller_trigger'] ?? ''), ['on_sale', 'on_payment'], true)
                ? $input['seller_trigger'] : 'on_payment',
            'seller_commission_auto_expense' => !empty($input['seller_auto_expense']) ? '1' : '0',
            'gateway_commission_auto' => !empty($input['gateway_auto']) ? '1' : '0',
            'dataphone_commission_rate' => (string)max(0, min(30, (float)($input['dataphone_rate'] ?? 2.5))),
            'card_commission_rate' => (string)max(0, min(30, (float)($input['card_rate'] ?? 2.8))),
            'payment_link_commission_rate' => (string)max(0, min(30, (float)($input['payment_link_rate'] ?? 2.5))),
            'debit_card_commission_rate' => (string)max(0, min(30, (float)($input['debit_card_rate'] ?? 1.5))),
            'credit_card_commission_rate' => (string)max(0, min(30, (float)($input['credit_card_rate'] ?? 2.8))),
            'credit_over_60_surcharge_rate' => (string)max(0, min(100, (float)($input['credit_over_60_rate'] ?? 0))),
            'credit_over_60_surcharge_days' => (string)max(1, min(365, (int)($input['credit_over_60_days'] ?? 60))),
        ];

        $tiers = self::normalizeCreditSurchargeTiers($input['credit_surcharge_tiers'] ?? null);
        // Si el formulario envía arrays paralelos days[] / rate[]
        if ($tiers === [] && isset($input['credit_tier_days'], $input['credit_tier_rates'])
            && is_array($input['credit_tier_days']) && is_array($input['credit_tier_rates'])) {
            $built = [];
            $daysArr = array_values($input['credit_tier_days']);
            $ratesArr = array_values($input['credit_tier_rates']);
            $n = max(count($daysArr), count($ratesArr));
            for ($i = 0; $i < $n; $i++) {
                $built[] = [
                    'min_days' => (int)($daysArr[$i] ?? 0),
                    'rate' => (float)($ratesArr[$i] ?? 0),
                ];
            }
            $tiers = self::normalizeCreditSurchargeTiers($built);
        }
        $map['credit_surcharge_tiers'] = json_encode($tiers, JSON_UNESCAPED_UNICODE);

        // Mantener legado = primer / mayor tramo para clientes viejos del POS.
        if ($tiers !== []) {
            $last = $tiers[count($tiers) - 1];
            $map['credit_over_60_surcharge_days'] = (string)$last['min_days'];
            $map['credit_over_60_surcharge_rate'] = (string)$last['rate'];
            // Si hay un tramo típico 60, usarlo como "principal" en legacy fields.
            foreach ($tiers as $t) {
                if ((int)$t['min_days'] === 60) {
                    $map['credit_over_60_surcharge_days'] = '60';
                    $map['credit_over_60_surcharge_rate'] = (string)$t['rate'];
                    break;
                }
            }
        }

        $qtyTiers = self::normalizeQuantityDiscountTiers($input['quantity_discount_tiers'] ?? null);
        if ($qtyTiers === [] && isset($input['qty_discount_qty'], $input['qty_discount_rates'])
            && is_array($input['qty_discount_qty']) && is_array($input['qty_discount_rates'])) {
            $builtQty = [];
            $qtyArr = array_values($input['qty_discount_qty']);
            $rateArr = array_values($input['qty_discount_rates']);
            $nQty = max(count($qtyArr), count($rateArr));
            for ($i = 0; $i < $nQty; $i++) {
                $builtQty[] = [
                    'min_qty' => (int)($qtyArr[$i] ?? 0),
                    'rate' => (float)($rateArr[$i] ?? 0),
                ];
            }
            $qtyTiers = self::normalizeQuantityDiscountTiers($builtQty);
        }
        $map['quantity_discount_tiers'] = json_encode($qtyTiers, JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare(
            "INSERT INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($map as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }

    public function saveUserRate(int $userId, float $rate, bool $enabled = true): void
    {
        $this->query(
            "INSERT INTO user_commission_rates (user_id, rate, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), enabled = VALUES(enabled)",
            [$userId, max(0, min(100, $rate)), $enabled ? 1 : 0]
        );
    }

    /** @return list<array> */
    public function userRates(): array
    {
        return $this->query(
            "SELECT u.id, u.name, u.email, u.role, u.status,
                    COALESCE(r.rate, NULL) AS rate,
                    COALESCE(r.enabled, 0) AS rate_enabled
             FROM users u
             LEFT JOIN user_commission_rates r ON r.user_id = u.id
             WHERE u.status = 'active'
             ORDER BY u.name"
        )->fetchAll();
    }

    public function rateForUser(int $userId): ?float
    {
        $cfg = $this->config();
        if (!$cfg['seller_enabled']) {
            return null;
        }
        $row = $this->query(
            'SELECT rate, enabled FROM user_commission_rates WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch();
        if ($row && (int)$row['enabled'] === 1) {
            return (float)$row['rate']; // 0 = sin comisión para este usuario
        }
        return $cfg['seller_rate']; // global
    }

    public function gatewayRate(string $method): float
    {
        $cfg = $this->config();
        return match (strtolower($method)) {
            'dataphone' => $cfg['dataphone_rate'],
            'payment_link' => $cfg['payment_link_rate'],
            'debit_card' => $cfg['debit_card_rate'],
            'credit_card' => $cfg['credit_card_rate'],
            'card' => $cfg['card_rate'],
            default => 0.0,
        };
    }

    private function saleBaseAmount(array $sale, string $base): float
    {
        return match ($base) {
            'subtotal' => (float)($sale['subtotal'] ?? $sale['total'] ?? 0),
            'profit' => $this->saleProfit((int)$sale['id'], $sale),
            default => (float)($sale['total'] ?? 0),
        };
    }

    private function saleProfit(int $saleId, array $sale): float
    {
        $cost = (float)$this->query(
            "SELECT COALESCE(SUM(quantity * COALESCE(NULLIF(unit_cost,0), 0)), 0) c
             FROM sale_items WHERE sale_id = ?",
            [$saleId]
        )->fetch()['c'];
        if ($cost <= 0) {
            $cost = (float)$this->query(
                "SELECT COALESCE(SUM(si.quantity * COALESCE(p.purchase_price,0)), 0) c
                 FROM sale_items si LEFT JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id = ?",
                [$saleId]
            )->fetch()['c'];
        }
        return max(0, (float)($sale['total'] ?? 0) - $cost);
    }

    /**
     * Tras crear venta (y pagos iniciales).
     */
    public function processSale(int $saleId): array
    {
        $created = [];
        $sale = $this->query('SELECT * FROM sales WHERE id = ?', [$saleId])->fetch();
        if (!$sale || ($sale['status'] ?? '') === 'cancelled') {
            return $created;
        }
        $cfg = $this->config();

        // Pasarela sobre pagos de la venta
        if ($cfg['gateway_auto']) {
            $payments = $this->query(
                'SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id',
                [$saleId]
            )->fetchAll();
            foreach ($payments as $pay) {
                $row = $this->accrueGateway((int)$pay['id']);
                if ($row) {
                    $created[] = $row;
                }
            }
        }

        // Vendedor
        if ($cfg['seller_enabled']) {
            if ($cfg['seller_trigger'] === 'on_sale') {
                $row = $this->accrueSellerOnSale($sale);
                if ($row) {
                    $created[] = $row;
                }
            } else {
                $payments = $this->query(
                    'SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id',
                    [$saleId]
                )->fetchAll();
                foreach ($payments as $pay) {
                    $row = $this->accrueSellerOnPayment((int)$pay['id']);
                    if ($row) {
                        $created[] = $row;
                    }
                }
            }
        }

        return $created;
    }

    /** Tras registrar un abono. */
    public function processPayment(int $paymentId): array
    {
        $created = [];
        $cfg = $this->config();
        if ($cfg['gateway_auto']) {
            $row = $this->accrueGateway($paymentId);
            if ($row) {
                $created[] = $row;
            }
        }
        if ($cfg['seller_enabled'] && $cfg['seller_trigger'] === 'on_payment') {
            $row = $this->accrueSellerOnPayment($paymentId);
            if ($row) {
                $created[] = $row;
            }
        }
        return $created;
    }

    private function accrueSellerOnSale(array $sale): ?array
    {
        $userId = (int)($sale['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        // Evitar duplicado on_sale
        $exists = $this->query(
            "SELECT id FROM sales_commissions
             WHERE commission_kind='seller' AND sale_id=? AND sale_payment_id IS NULL
               AND status != 'cancelled' LIMIT 1",
            [(int)$sale['id']]
        )->fetch();
        if ($exists) {
            return null;
        }

        $rate = $this->rateForUser($userId);
        if ($rate === null || $rate <= 0) {
            return null;
        }
        $cfg = $this->config();
        $base = $this->saleBaseAmount($sale, $cfg['seller_base']);
        $amount = round($base * $rate / 100, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->insertCommission([
            'kind' => 'seller',
            'sale_id' => (int)$sale['id'],
            'sale_payment_id' => null,
            'user_id' => $userId,
            'payment_method' => null,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'notes' => 'Comisión vendedor · venta #' . ($sale['invoice_number'] ?? $sale['id']),
        ], $cfg['seller_auto_expense']);
    }

    private function accrueSellerOnPayment(int $paymentId): ?array
    {
        $pay = $this->query('SELECT * FROM sale_payments WHERE id = ?', [$paymentId])->fetch();
        if (!$pay) {
            return null;
        }
        $exists = $this->query(
            "SELECT id FROM sales_commissions
             WHERE commission_kind='seller' AND sale_payment_id=? AND status != 'cancelled' LIMIT 1",
            [$paymentId]
        )->fetch();
        if ($exists) {
            return null;
        }

        $sale = $this->query('SELECT * FROM sales WHERE id = ?', [(int)$pay['sale_id']])->fetch();
        if (!$sale) {
            return null;
        }
        $userId = (int)($sale['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $rate = $this->rateForUser($userId);
        if ($rate === null || $rate <= 0) {
            return null;
        }

        $cfg = $this->config();
        $payAmount = (float)$pay['amount'];
        // Proporción sobre base elegida
        $fullBase = $this->saleBaseAmount($sale, $cfg['seller_base']);
        $saleTotal = max(0.01, (float)$sale['total']);
        $base = $cfg['seller_base'] === 'total'
            ? $payAmount
            : round($fullBase * ($payAmount / $saleTotal), 2);
        $amount = round($base * $rate / 100, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->insertCommission([
            'kind' => 'seller',
            'sale_id' => (int)$sale['id'],
            'sale_payment_id' => $paymentId,
            'user_id' => $userId,
            'payment_method' => $pay['payment_method'] ?? null,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'notes' => 'Comisión vendedor · abono venta #' . ($sale['invoice_number'] ?? $sale['id']),
        ], $cfg['seller_auto_expense']);
    }

    private function accrueGateway(int $paymentId): ?array
    {
        $pay = $this->query('SELECT * FROM sale_payments WHERE id = ?', [$paymentId])->fetch();
        if (!$pay) {
            return null;
        }
        $method = (string)($pay['payment_method'] ?? '');
        $rate = $this->gatewayRate($method);
        if ($rate <= 0) {
            return null;
        }
        $exists = $this->query(
            "SELECT id FROM sales_commissions
             WHERE commission_kind='gateway' AND sale_payment_id=? AND status != 'cancelled' LIMIT 1",
            [$paymentId]
        )->fetch();
        if ($exists) {
            return null;
        }

        $base = (float)$pay['amount'];
        $amount = round($base * $rate / 100, 2);
        if ($amount <= 0) {
            return null;
        }
        $sale = $this->query('SELECT invoice_number FROM sales WHERE id=?', [(int)$pay['sale_id']])->fetch();

        return $this->insertCommission([
            'kind' => 'gateway',
            'sale_id' => (int)$pay['sale_id'],
            'sale_payment_id' => $paymentId,
            'user_id' => null,
            'payment_method' => $method,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'notes' => 'Comisión ' . $method . ' · venta #' . ($sale['invoice_number'] ?? $pay['sale_id']),
        ], true); // pasarela siempre genera gasto automático
    }

    private function insertCommission(array $data, bool $autoExpense): ?array
    {
        $this->query(
            "INSERT INTO sales_commissions
                (commission_kind, sale_id, sale_payment_id, user_id, payment_method,
                 base_amount, rate, amount, status, notes)
             VALUES (?,?,?,?,?,?,?,?, 'pending', ?)",
            [
                $data['kind'], $data['sale_id'], $data['sale_payment_id'], $data['user_id'],
                $data['payment_method'], $data['base_amount'], $data['rate'], $data['amount'],
                $data['notes'],
            ]
        );
        $id = (int)$this->db->lastInsertId();
        $row = $this->query('SELECT * FROM sales_commissions WHERE id=?', [$id])->fetch();
        if ($autoExpense && $row) {
            try {
                $this->settle([$id], false);
                $row = $this->query('SELECT * FROM sales_commissions WHERE id=?', [$id])->fetch();
            } catch (\Throwable $e) {
                error_log('Commission auto expense #' . $id . ': ' . $e->getMessage());
            }
        }
        return $row ?: null;
    }

    /**
     * Liquida comisiones pendientes → gasto + asiento.
     * @param list<int> $ids
     */
    public function settle(array $ids, bool $affectCash = false): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->query(
            "SELECT * FROM sales_commissions WHERE id IN ({$ph}) AND status = 'pending'",
            $ids
        )->fetchAll();
        if (!$rows) {
            return 0;
        }

        $cfg = $this->config();
        $accounting = new AccountingService($this->db);
        $settled = 0;

        foreach ($rows as $row) {
            $kind = $row['commission_kind'];
            $category = $kind === 'gateway'
                ? (match ((string)$row['payment_method']) {
                    'dataphone' => 'dataphone_commission',
                    'payment_link' => 'payment_link_commission',
                    'debit_card', 'credit_card', 'card' => 'card_commission',
                    default => 'financial',
                })
                : 'sales_commission'; // comisión vendedor

            $desc = $row['notes'] ?: ('Comisión #' . $row['id']);
            $method = $affectCash ? 'cash' : 'transfer';

            $this->query(
                "INSERT INTO expenses
                    (description, amount, category, expense_date, payment_method, notes, user_id)
                 VALUES (?,?,?,?,?,?,?)",
                [
                    $desc,
                    (float)$row['amount'],
                    $kind === 'gateway' ? $category : 'sales_commission',
                    date('Y-m-d'),
                    $method,
                    'sales_commission:' . $row['id'],
                    $row['user_id'] ?? ($_SESSION['tenant_user_id'] ?? null),
                ]
            );
            $expenseId = (int)$this->db->lastInsertId();

            try {
                if ($kind === 'seller') {
                    $this->postSellerExpense($accounting, $expenseId, (float)$row['amount'], $desc, $affectCash);
                } else {
                    // Pasarela: gasto financiero contra banco (neto cobrado), no contra CxP.
                    $this->postGatewayExpense($accounting, $expenseId, (float)$row['amount'], $desc);
                }
            } catch (\Throwable $e) {
                error_log('Commission settle accounting: ' . $e->getMessage());
            }

            $this->query(
                "UPDATE sales_commissions SET status='paid', expense_id=? WHERE id=?",
                [$expenseId, $row['id']]
            );
            $settled++;
        }

        return $settled;
    }

    private function postSellerExpense(
        AccountingService $accounting,
        int $expenseId,
        float $amount,
        string $desc,
        bool $affectCash
    ): void {
        // Usa cuenta 510508 específicamente
        $expense = $this->query('SELECT * FROM expenses WHERE id=?', [$expenseId])->fetch();
        if (!$expense) {
            return;
        }
        $cfg = $this->config();
        $credit = $affectCash
            ? $this->setting('cash_account', '110505')
            : $this->setting('expense_payable_account', '233595');
        $accounting->postCommissionExpense(
            $expenseId,
            $cfg['seller_account'],
            $amount,
            (string)$expense['expense_date'],
            $desc,
            $credit
        );
    }

    /**
     * Comisión de pasarela: Dr gasto financiero / Cr bancos (retención del adquirente).
     */
    private function postGatewayExpense(
        AccountingService $accounting,
        int $expenseId,
        float $amount,
        string $desc
    ): void {
        $expense = $this->query('SELECT * FROM expenses WHERE id=?', [$expenseId])->fetch();
        if (!$expense) {
            return;
        }
        $cfg = $this->config();
        $accounting->postCommissionExpense(
            $expenseId,
            $cfg['gateway_account'],
            $amount,
            (string)$expense['expense_date'],
            $desc,
            $this->setting('bank_account', '111005')
        );
    }

    public function cancelForSale(int $saleId): int
    {
        $rows = $this->query(
            "SELECT * FROM sales_commissions WHERE sale_id=? AND status != 'cancelled'",
            [$saleId]
        )->fetchAll();
        $n = 0;
        $accounting = new AccountingService($this->db);
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'paid' && !empty($row['expense_id'])) {
                $expenseId = (int)$row['expense_id'];
                try {
                    $accounting->reverseSource('expense', $expenseId, 'Venta cancelada · comisión');
                } catch (\Throwable $e) {
                    error_log('Commission reverse: ' . $e->getMessage());
                }
                try {
                    $this->query('DELETE FROM expenses WHERE id = ?', [$expenseId]);
                } catch (\Throwable $e) {
                    error_log('Commission expense delete: ' . $e->getMessage());
                }
            }
            $this->query(
                "UPDATE sales_commissions SET status='cancelled', expense_id=NULL WHERE id=?",
                [$row['id']]
            );
            $n++;
        }
        return $n;
    }

    /** @return array{rows:list,pending:float,paid:float,cancelled:float} */
    public function list(array $filters = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['kind'])) {
            $where[] = 'c.commission_kind = ?';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'c.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'DATE(c.created_at) >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'DATE(c.created_at) <= ?';
            $params[] = $filters['to'];
        }
        $sqlWhere = implode(' AND ', $where);
        $rows = $this->query(
            "SELECT c.*, s.invoice_number, u.name AS seller_name
             FROM sales_commissions c
             LEFT JOIN sales s ON s.id = c.sale_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE {$sqlWhere}
             ORDER BY c.id DESC
             LIMIT " . (int)$limit,
            $params
        )->fetchAll();

        $sum = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) pending,
                COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid,
                COALESCE(SUM(CASE WHEN status='cancelled' THEN amount ELSE 0 END),0) cancelled
             FROM sales_commissions c WHERE {$sqlWhere}",
            $params
        )->fetch() ?: ['pending' => 0, 'paid' => 0, 'cancelled' => 0];

        return [
            'rows' => $rows,
            'pending' => (float)$sum['pending'],
            'paid' => (float)$sum['paid'],
            'cancelled' => (float)$sum['cancelled'],
        ];
    }
}
