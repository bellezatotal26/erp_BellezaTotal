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
    /** Columnas añadidas después de la versión inicial de sales_commissions. */
    private const EXTRA_COLUMNS = [
        'fixed_fee' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'vat_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'withholding_renta' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'withholding_iva' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'withholding_ica' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'accrual_date' => 'DATE NULL',
        'last_error' => 'VARCHAR(255) NULL',
    ];

    /** Medios que cobra un adquirente (datáfono o pasarela web). */
    public const GATEWAY_METHODS = ['debit_card', 'credit_card', 'card', 'dataphone', 'payment_link'];

    /** @var array<int,array<string,bool>> */
    private static array $columnCache = [];

    private ?array $configCache = null;

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
        // El DDL hace commit implícito: nunca dentro de una transacción abierta.
        if (isset($done[$oid]) || $this->db->inTransaction()) {
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

        unset(self::$columnCache[$oid]);
        $cols = $this->commissionColumns();
        foreach (self::EXTRA_COLUMNS as $name => $definition) {
            if (isset($cols[$name])) {
                continue;
            }
            try {
                $this->db->exec("ALTER TABLE sales_commissions ADD COLUMN {$name} {$definition}");
                self::$columnCache[$oid][$name] = true;
            } catch (\Throwable $e) {
                error_log('sales_commissions add column ' . $name . ': ' . $e->getMessage());
            }
        }

        try {
            $this->query(
                "INSERT IGNORE INTO accounting_accounts (code, name, account_type, nature, is_system)
                 VALUES ('510508', 'Comisiones sobre ventas', 'expense', 'debit', 1),
                        ('135517', 'Impuesto a las ventas retenido (ReteIVA)', 'asset', 'debit', 1),
                        ('135518', 'Impuesto de industria y comercio retenido (ReteICA)', 'asset', 'debit', 1)"
            );
        } catch (\Throwable $e) {
            // Contabilidad aún no provisionada en este tenant.
        }
    }

    /** @return array<string,bool> */
    private function commissionColumns(): array
    {
        $oid = spl_object_id($this->db);
        if (!isset(self::$columnCache[$oid])) {
            $cols = [];
            try {
                foreach ($this->db->query('SHOW COLUMNS FROM sales_commissions')->fetchAll() as $c) {
                    $cols[(string)$c['Field']] = true;
                }
            } catch (\Throwable $e) {
                // Tabla aún no creada.
            }
            self::$columnCache[$oid] = $cols;
        }
        return self::$columnCache[$oid];
    }

    private function hasColumn(string $column): bool
    {
        return isset($this->commissionColumns()[$column]);
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
            'dataphone_fixed_fee' => '0',
            'payment_link_commission_rate' => '2.5',
            'debit_card_commission_rate' => '1.5',
            'credit_card_commission_rate' => '2.8',
            'seller_commission_account' => '510508',
            'gateway_commission_account' => '530505',
            'gateway_vat_rate' => '0',
            'gateway_vat_deductible' => '1',
            'gateway_withholding_renta_rate' => '0',
            'gateway_withholding_iva_rate' => '0',
            'gateway_withholding_ica_rate' => '0',
            'gateway_withholding_renta_account' => '135515',
            'gateway_withholding_iva_account' => '135517',
            'gateway_withholding_ica_account' => '135518',
        ];
        $params = [];
        foreach ($defaults as $k => $v) {
            $params[] = $k;
            $params[] = $v;
        }
        $this->query(
            "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES "
                . implode(',', array_fill(0, count($defaults), '(?, ?)')),
            $params
        );
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
        if ($this->configCache !== null) {
            return $this->configCache;
        }
        return $this->configCache = [
            'seller_enabled' => $this->setting('seller_commission_enabled', '0') === '1',
            'seller_rate' => (float)$this->setting('seller_commission_rate', '3'),
            'seller_base' => $this->setting('seller_commission_base', 'total'),
            'seller_trigger' => $this->setting('seller_commission_trigger', 'on_payment'),
            'seller_auto_expense' => $this->setting('seller_commission_auto_expense', '1') === '1',
            'gateway_auto' => $this->setting('gateway_commission_auto', '1') === '1',
            'dataphone_fixed_fee' => (float)$this->setting('dataphone_fixed_fee', '0'),
            'payment_link_rate' => (float)$this->setting('payment_link_commission_rate', '2.5'),
            'debit_card_rate' => (float)$this->setting('debit_card_commission_rate', '1.5'),
            'credit_card_rate' => (float)$this->setting('credit_card_commission_rate', '2.8'),
            'credit_over_60_rate' => (float)$this->setting('credit_over_60_surcharge_rate', '0'),
            'credit_over_60_days' => (int)$this->setting('credit_over_60_surcharge_days', '60'),
            'credit_surcharge_tiers' => $this->creditSurchargeTiers(),
            'quantity_discount_tiers' => $this->quantityDiscountTiers(),
            'seller_account' => $this->setting('seller_commission_account', '510508'),
            'gateway_account' => $this->setting('gateway_commission_account', '530505'),
            // IVA que cobra el adquirente sobre su comisión.
            'gateway_vat_rate' => (float)$this->setting('gateway_vat_rate', '0'),
            'gateway_vat_deductible' => $this->setting('gateway_vat_deductible', '1') === '1',
            'gateway_vat_account' => $this->setting('vat_deductible_account', '240802'),
            // Retenciones que practica el adquirente sobre la venta: renta e ICA sobre la base sin IVA,
            // reteIVA sobre el IVA del pago. ICA en por mil (‰).
            'withholding_renta_rate' => (float)$this->setting('gateway_withholding_renta_rate', '0'),
            'withholding_iva_rate' => (float)$this->setting('gateway_withholding_iva_rate', '0'),
            'withholding_ica_rate' => (float)$this->setting('gateway_withholding_ica_rate', '0'),
            'withholding_renta_account' => $this->setting('gateway_withholding_renta_account', '135515'),
            'withholding_iva_account' => $this->setting('gateway_withholding_iva_account', '135517'),
            'withholding_ica_account' => $this->setting('gateway_withholding_ica_account', '135518'),
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
            'dataphone_fixed_fee' => (string)round(max(0, min(1000000, \SoftNova\Core\parse_money($input['dataphone_fixed_fee'] ?? 0))), 2),
            'payment_link_commission_rate' => (string)max(0, min(30, (float)($input['payment_link_rate'] ?? 2.5))),
            'debit_card_commission_rate' => (string)max(0, min(30, (float)($input['debit_card_rate'] ?? 1.5))),
            'credit_card_commission_rate' => (string)max(0, min(30, (float)($input['credit_card_rate'] ?? 2.8))),
            'credit_over_60_surcharge_rate' => (string)max(0, min(100, (float)($input['credit_over_60_rate'] ?? 0))),
            'credit_over_60_surcharge_days' => (string)max(1, min(365, (int)($input['credit_over_60_days'] ?? 60))),
        ];

        $taxFields = [
            'gateway_vat_rate' => 100.0,
            'gateway_withholding_renta_rate' => 100.0,
            'gateway_withholding_iva_rate' => 100.0,
            'gateway_withholding_ica_rate' => 1000.0,
        ];
        foreach ($taxFields as $field => $max) {
            if (array_key_exists($field, $input)) {
                $value = (float)str_replace(',', '.', (string)$input[$field]);
                $map[$field] = (string)round(max(0, min($max, $value)), 4);
            }
        }
        if (array_key_exists('gateway_vat_deductible', $input)) {
            $map['gateway_vat_deductible'] = !empty($input['gateway_vat_deductible']) ? '1' : '0';
        }

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
        $this->configCache = null;
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

    /** Débito/crédito (y sus códigos legado card/dataphone) se cobran por datáfono. */
    public static function isDataphoneMethod(string $method): bool
    {
        return in_array(strtolower($method), ['debit_card', 'credit_card', 'card', 'dataphone'], true);
    }

    public function gatewayRate(string $method): float
    {
        $cfg = $this->config();
        return match (strtolower($method)) {
            'payment_link' => $cfg['payment_link_rate'],
            'debit_card', 'dataphone' => $cfg['debit_card_rate'],
            'credit_card', 'card' => $cfg['credit_card_rate'],
            default => 0.0,
        };
    }

    public function gatewayFixedFee(string $method): float
    {
        return self::isDataphoneMethod($method) ? (float)$this->config()['dataphone_fixed_fee'] : 0.0;
    }

    /**
     * @return array{rate:float,fixed:float,percent_amount:float,amount:float}
     */
    public function gatewayCommission(string $method, float $base): array
    {
        $rate = $this->gatewayRate($method);
        $fixed = $base > 0 ? $this->gatewayFixedFee($method) : 0.0;
        $pct = $base > 0 ? round($base * $rate / 100, 2) : 0.0;
        return [
            'rate' => $rate,
            'fixed' => round($fixed, 2),
            'percent_amount' => $pct,
            'amount' => round($pct + $fixed, 2),
        ];
    }

    /**
     * Comisión + IVA sobre la comisión + retenciones del adquirente para un pago.
     *
     * @param float $taxRatio Proporción de IVA dentro del pago (impuesto / total de la venta).
     * @return array{rate:float,fixed:float,percent_amount:float,amount:float,vat:float,ret_renta:float,ret_iva:float,ret_ica:float,withholdings:float}
     */
    public function gatewayBreakdown(string $method, float $base, float $taxRatio = 0.0): array
    {
        $calc = $this->gatewayCommission($method, $base) + [
            'vat' => 0.0, 'ret_renta' => 0.0, 'ret_iva' => 0.0, 'ret_ica' => 0.0, 'withholdings' => 0.0,
        ];
        if ($calc['amount'] <= 0) {
            return $calc;
        }
        $cfg = $this->config();
        $taxRatio = max(0.0, min(1.0, $taxRatio));
        $vatPart = $base * $taxRatio;
        $netBase = $base - $vatPart;
        $calc['vat'] = round($calc['amount'] * $cfg['gateway_vat_rate'] / 100, 2);
        $calc['ret_renta'] = round($netBase * $cfg['withholding_renta_rate'] / 100, 2);
        $calc['ret_iva'] = round($vatPart * $cfg['withholding_iva_rate'] / 100, 2);
        $calc['ret_ica'] = round($netBase * $cfg['withholding_ica_rate'] / 1000, 2);
        $calc['withholdings'] = round($calc['ret_renta'] + $calc['ret_iva'] + $calc['ret_ica'], 2);
        return $calc;
    }

    private static function saleTaxRatio(array $sale): float
    {
        $total = (float)($sale['total'] ?? 0);
        if ($total <= 0) {
            return 0.0;
        }
        return max(0.0, min(1.0, (float)($sale['tax'] ?? 0) / $total));
    }

    private static function paymentDate(array $pay): string
    {
        $ts = strtotime((string)($pay['payment_date'] ?? $pay['created_at'] ?? ''));
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
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

        $saleTs = strtotime((string)($sale['sale_date'] ?? $sale['created_at'] ?? ''));

        return $this->insertCommission([
            'kind' => 'seller',
            'sale_id' => (int)$sale['id'],
            'sale_payment_id' => null,
            'user_id' => $userId,
            'payment_method' => null,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'accrual_date' => $saleTs ? date('Y-m-d', $saleTs) : date('Y-m-d'),
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
            'accrual_date' => self::paymentDate($pay),
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
        $base = (float)$pay['amount'];
        if ($this->gatewayCommission($method, $base)['amount'] <= 0) {
            return null;
        }
        // Cobro a una pasarela por liquidar (Bold): la comisión/retención real llega con el reporte.
        // Guard: evita fatal si Bancos/Bold aún no está desplegado en el servidor.
        // H3: no auto-accrue si el medio ya tiene clearing, o si hay pasarela activa y el medio es de datáfono/link.
        if (class_exists(BankingService::class)) {
            $payDate = self::paymentDate($pay);
            if (BankingService::clearingAccountFor($this->db, $method, $payDate) !== null) {
                return null;
            }
            if (
                in_array(strtolower($method), self::GATEWAY_METHODS, true)
                && BankingService::hasActiveGatewayAccount($this->db)
            ) {
                return null;
            }
        }
        $exists = $this->query(
            "SELECT id FROM sales_commissions
             WHERE commission_kind='gateway' AND sale_payment_id=? AND status != 'cancelled' LIMIT 1",
            [$paymentId]
        )->fetch();
        if ($exists) {
            return null;
        }

        $sale = $this->query(
            'SELECT invoice_number, total, tax FROM sales WHERE id=?',
            [(int)$pay['sale_id']]
        )->fetch() ?: [];
        $calc = $this->gatewayBreakdown($method, $base, self::saleTaxRatio($sale));

        $label = PaymentMethodCatalog::label($method, $this->db);
        if (self::isDataphoneMethod($method)) {
            $label = 'Datáfono · ' . $label;
        }
        $detail = [];
        if ($calc['rate'] > 0) {
            $detail[] = rtrim(rtrim(number_format($calc['rate'], 2, ',', '.'), '0'), ',') . '%';
        }
        if ($calc['fixed'] > 0) {
            $detail[] = '$ ' . number_format($calc['fixed'], 0, ',', '.') . ' fijo';
        }

        return $this->insertCommission([
            'kind' => 'gateway',
            'sale_id' => (int)$pay['sale_id'],
            'sale_payment_id' => $paymentId,
            'user_id' => null,
            'payment_method' => $method,
            'base_amount' => $base,
            'rate' => $calc['rate'],
            'amount' => $calc['amount'],
            'fixed_fee' => $calc['fixed'],
            'vat_amount' => $calc['vat'],
            'withholding_renta' => $calc['ret_renta'],
            'withholding_iva' => $calc['ret_iva'],
            'withholding_ica' => $calc['ret_ica'],
            'accrual_date' => self::paymentDate($pay),
            'notes' => mb_substr('Comisión ' . $label . ($detail ? ' (' . implode(' + ', $detail) . ')' : '')
                . ' · venta #' . ($sale['invoice_number'] ?? $pay['sale_id']), 0, 255),
        ], true); // pasarela siempre genera gasto automático
    }

    private function insertCommission(array $data, bool $autoExpense): ?array
    {
        $cols = ['commission_kind', 'sale_id', 'sale_payment_id', 'user_id', 'payment_method',
            'base_amount', 'rate', 'amount', 'status', 'notes'];
        $values = [
            $data['kind'], $data['sale_id'], $data['sale_payment_id'], $data['user_id'],
            $data['payment_method'], $data['base_amount'], $data['rate'], $data['amount'],
            'pending', $data['notes'],
        ];
        foreach (array_keys(self::EXTRA_COLUMNS) as $extra) {
            if (array_key_exists($extra, $data) && $this->hasColumn($extra)) {
                $cols[] = $extra;
                $values[] = $data[$extra];
            }
        }
        $this->query(
            'INSERT INTO sales_commissions (' . implode(', ', $cols) . ')
             VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
            $values
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
                    'dataphone', 'debit_card', 'credit_card', 'card' => 'dataphone_commission',
                    'payment_link' => 'payment_link_commission',
                    default => 'financial',
                })
                : 'sales_commission'; // comisión vendedor

            $desc = $row['notes'] ?: ('Comisión #' . $row['id']);
            $method = $affectCash ? 'cash' : 'transfer';
            $date = $this->accrualDate($row);

            $commission = round((float)$row['amount'], 2);
            $vat = $kind === 'gateway' ? round((float)($row['vat_amount'] ?? 0), 2) : 0.0;
            // IVA no descontable = mayor valor del gasto.
            $expenseAmount = $cfg['gateway_vat_deductible'] ? $commission : round($commission + $vat, 2);

            // Gasto + asiento + paid deben ser atómicos: si falla contabilidad, no marcar liquidada.
            $ownTx = !$this->db->inTransaction();
            if ($ownTx) {
                $this->db->beginTransaction();
            }
            try {
                $this->query(
                    "INSERT INTO expenses
                        (description, amount, category, expense_date, payment_method, notes, user_id)
                     VALUES (?,?,?,?,?,?,?)",
                    [
                        $desc,
                        $expenseAmount,
                        $kind === 'gateway' ? $category : 'sales_commission',
                        $date,
                        $method,
                        'sales_commission:' . $row['id'],
                        $row['user_id'] ?? ($_SESSION['tenant_user_id'] ?? null),
                    ]
                );
                $expenseId = (int)$this->db->lastInsertId();

                if ($kind === 'seller') {
                    $this->postSellerExpense($accounting, $expenseId, $commission, $date, $desc, $affectCash);
                } else {
                    // Pasarela: gasto financiero contra banco (neto cobrado), no contra CxP.
                    $this->postGatewayExpense($accounting, $expenseId, $row, $date, $desc);
                }

                $this->query(
                    "UPDATE sales_commissions SET status='paid', expense_id=?"
                        . ($this->hasColumn('last_error') ? ', last_error=NULL' : '')
                        . ' WHERE id=?',
                    [$expenseId, $row['id']]
                );

                if ($ownTx) {
                    $this->db->commit();
                }
                $settled++;
            } catch (\Throwable $e) {
                if ($ownTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log('Commission settle accounting #' . $row['id'] . ': ' . $e->getMessage());
                if ($ownTx && $this->hasColumn('last_error')) {
                    try {
                        $this->query(
                            'UPDATE sales_commissions SET last_error=? WHERE id=?',
                            [mb_substr($e->getMessage(), 0, 255), $row['id']]
                        );
                    } catch (\Throwable $ignored) {
                    }
                }
                throw $e;
            }
        }

        return $settled;
    }

    /** Fecha contable de la comisión: la del pago (pasarela / abono) o la de la venta. */
    private function accrualDate(array $row): string
    {
        $stored = (string)($row['accrual_date'] ?? '');
        if ($stored !== '' && strtotime($stored)) {
            return date('Y-m-d', strtotime($stored));
        }
        if (!empty($row['sale_payment_id'])) {
            $pay = $this->query(
                'SELECT payment_date FROM sale_payments WHERE id=?',
                [(int)$row['sale_payment_id']]
            )->fetch();
            if ($pay) {
                return self::paymentDate($pay);
            }
        }
        $sale = $this->query('SELECT sale_date FROM sales WHERE id=?', [(int)$row['sale_id']])->fetch();
        $ts = $sale ? strtotime((string)$sale['sale_date']) : false;
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    private function postSellerExpense(
        AccountingService $accounting,
        int $expenseId,
        float $amount,
        string $date,
        string $desc,
        bool $affectCash
    ): void {
        $cfg = $this->config();
        $credit = $affectCash
            ? $this->setting('cash_account', '110505')
            : $this->setting('expense_payable_account', '233595');
        $accounting->postCommissionExpense(
            $expenseId,
            $cfg['seller_account'],
            $amount,
            $date,
            $desc,
            $credit
        );
    }

    /**
     * Comisión de pasarela: Dr gasto financiero (+ IVA descontable + anticipos de retención)
     * / Cr bancos por lo que el adquirente descontó del abono.
     */
    private function postGatewayExpense(
        AccountingService $accounting,
        int $expenseId,
        array $row,
        string $date,
        string $desc
    ): void {
        $cfg = $this->config();
        $commission = round((float)$row['amount'], 2);
        $vat = round((float)($row['vat_amount'] ?? 0), 2);
        $expenseDebit = $cfg['gateway_vat_deductible'] ? $commission : round($commission + $vat, 2);

        $extra = [];
        if ($cfg['gateway_vat_deductible'] && $vat > 0) {
            $extra[] = [$cfg['gateway_vat_account'], $vat, 'IVA descontable comisión adquirente'];
        }
        foreach ([
            ['withholding_renta', 'withholding_renta_account', 'Retención en la fuente (adquirente)'],
            ['withholding_iva', 'withholding_iva_account', 'ReteIVA (adquirente)'],
            ['withholding_ica', 'withholding_ica_account', 'ReteICA (adquirente)'],
        ] as [$col, $accountKey, $label]) {
            $value = round((float)($row[$col] ?? 0), 2);
            if ($value > 0) {
                $extra[] = [$cfg[$accountKey], $value, $label];
            }
        }

        $accounting->postCommissionExpense(
            $expenseId,
            $cfg['gateway_account'],
            $expenseDebit,
            $date,
            $desc,
            $accounting->collectionAccount((string)($row['payment_method'] ?? ''), $date),
            $extra
        );
    }

    /**
     * Venta anulada: revierte el asiento y anula (sin borrar) el gasto de cada comisión.
     */
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
                    $accounting->enqueuePending(
                        'reverse_source',
                        'expense',
                        $expenseId,
                        $e->getMessage(),
                        ['reason' => 'Venta cancelada · comisión']
                    );
                }
                $this->softCancelExpense($expenseId);
            }
            $this->query(
                "UPDATE sales_commissions SET status='cancelled' WHERE id=?",
                [$row['id']]
            );
            $n++;
        }
        return $n;
    }

    private function softCancelExpense(int $expenseId): void
    {
        try {
            $this->query(
                "UPDATE expenses SET status = 'cancelled', cancelled_at = NOW()
                 WHERE id = ? AND COALESCE(status, 'active') <> 'cancelled'",
                [$expenseId]
            );
        } catch (\Throwable $e) {
            try {
                $this->query(
                    "UPDATE expenses
                     SET notes = CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), '[ANULADO ', ?, ']')
                     WHERE id = ?",
                    [date('Y-m-d H:i'), $expenseId]
                );
            } catch (\Throwable $e2) {
                error_log('Commission expense cancel #' . $expenseId . ': ' . $e2->getMessage());
            }
        }
    }

    /**
     * Gasto de comisión anulado desde Gastos: la comisión queda cancelada
     * (el asiento ya lo revierte Gastos).
     */
    public function cancelByExpense(int $expenseId, string $expenseNotes = ''): int
    {
        if ($expenseId <= 0) {
            return 0;
        }
        $where = 'expense_id = ?';
        $params = [$expenseId];
        if (preg_match('/sales_commission:(\d+)/', $expenseNotes, $m)) {
            $where = '(expense_id = ? OR id = ?)';
            $params[] = (int)$m[1];
        }
        return $this->query(
            "UPDATE sales_commissions SET status='cancelled'
             WHERE {$where} AND status != 'cancelled'",
            $params
        )->rowCount();
    }

    /** Comisiones de pasarela pendientes (sin gasto/asiento) y último error registrado. */
    public function pendingGatewaySummary(): array
    {
        $out = ['count' => 0, 'amount' => 0.0, 'last_error' => ''];
        try {
            $row = $this->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) amt FROM sales_commissions
                 WHERE commission_kind='gateway' AND status='pending'"
            )->fetch();
            $out['count'] = (int)($row['cnt'] ?? 0);
            $out['amount'] = (float)($row['amt'] ?? 0);
            if ($out['count'] > 0 && $this->hasColumn('last_error')) {
                $out['last_error'] = (string)($this->query(
                    "SELECT last_error FROM sales_commissions
                     WHERE commission_kind='gateway' AND status='pending' AND last_error IS NOT NULL
                     ORDER BY id DESC LIMIT 1"
                )->fetchColumn() ?: '');
            }
        } catch (\Throwable $e) {
            // Tabla ausente.
        }
        return $out;
    }

    /**
     * Reintenta generar gasto + asiento de comisiones de pasarela que quedaron pendientes.
     *
     * @return array{processed:int,settled:int,errors:int}
     */
    public function settlePendingGateway(int $limit = 100): array
    {
        $out = ['processed' => 0, 'settled' => 0, 'errors' => 0];
        $ids = $this->query(
            "SELECT id FROM sales_commissions
             WHERE commission_kind='gateway' AND status='pending'
             ORDER BY id LIMIT " . max(1, min(500, $limit))
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $out['processed']++;
            try {
                $out['settled'] += $this->settle([(int)$id], false);
            } catch (\Throwable $e) {
                $out['errors']++;
            }
        }
        return $out;
    }

    /**
     * Genera las comisiones de pasarela faltantes de pagos electrónicos del rango
     * (ventas importadas, pagos previos a la configuración, fallos). No recrea las
     * comisiones anuladas a mano.
     *
     * @return array{created:int,settled:int,retried:int,errors:int,remaining:bool}
     */
    public function backfillGateway(string $from, string $to, int $limit = 300): array
    {
        $out = ['created' => 0, 'settled' => 0, 'retried' => 0, 'errors' => 0, 'remaining' => false];
        $methods = self::GATEWAY_METHODS;
        $ph = implode(',', array_fill(0, count($methods), '?'));
        $limit = max(1, min(1000, $limit));
        $rows = $this->query(
            "SELECT sp.id
             FROM sale_payments sp
             INNER JOIN sales s ON s.id = sp.sale_id AND COALESCE(s.status, '') <> 'cancelled'
             LEFT JOIN sales_commissions c
               ON c.sale_payment_id = sp.id AND c.commission_kind = 'gateway'
             WHERE c.id IS NULL
               AND sp.amount > 0
               AND sp.payment_method IN ({$ph})
               AND DATE(sp.payment_date) BETWEEN ? AND ?
             ORDER BY sp.id
             LIMIT " . ($limit + 1),
            array_merge($methods, [$from, $to])
        )->fetchAll(\PDO::FETCH_COLUMN);
        if (count($rows) > $limit) {
            $out['remaining'] = true;
            $rows = array_slice($rows, 0, $limit);
        }
        foreach ($rows as $paymentId) {
            try {
                $row = $this->accrueGateway((int)$paymentId);
                if ($row) {
                    $out['created']++;
                    if (($row['status'] ?? '') === 'paid') {
                        $out['settled']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['errors']++;
                error_log('Commission backfill payment #' . $paymentId . ': ' . $e->getMessage());
            }
        }
        $retry = $this->settlePendingGateway(100);
        $out['retried'] = $retry['settled'];
        $out['errors'] += $retry['errors'];
        return $out;
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
        $dateExpr = $this->hasColumn('accrual_date')
            ? 'COALESCE(c.accrual_date, DATE(c.created_at))'
            : 'DATE(c.created_at)';
        if (!empty($filters['from'])) {
            $where[] = "{$dateExpr} >= ?";
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = "{$dateExpr} <= ?";
            $params[] = $filters['to'];
        }
        $sqlWhere = implode(' AND ', $where);
        $rows = $this->query(
            "SELECT c.*, s.invoice_number, u.name AS seller_name
             FROM sales_commissions c
             LEFT JOIN sales s ON s.id = c.sale_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE {$sqlWhere}
             ORDER BY {$dateExpr} DESC, c.id DESC
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
