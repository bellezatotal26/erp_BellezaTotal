<?php

namespace SoftNova\Services;

/**
 * IVA / impuestos: tasa por producto + prorrateo de descuento + trazabilidad.
 */
class TaxService
{
    /**
     * Lee la tasa global de Configuración (fallback si el producto no define IVA).
     */
    public static function defaultRate(\PDO $db): float
    {
        if (!self::isCalculationEnabled($db)) {
            return 0.0;
        }
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'tax_rate' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null || trim((string)$raw) === '') {
                return 0.0;
            }
            return self::normalizeRate((float)$raw);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** ¿Calcular IVA en ventas/POS? (Configuración → tax_enabled) */
    public static function isCalculationEnabled(\PDO $db): bool
    {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'tax_enabled' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null || trim((string)$raw) === '') {
                return true;
            }
            return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * ¿Usar la tasa IVA de cada producto del inventario en ventas?
     * Desactivado: productos de catálogo van sin IVA (comodín sigue la tasa global).
     * Activado: respeta tax_rate del producto (0 = exento, >0 = aplica ese %).
     */
    public static function isProductTaxEnabled(\PDO $db): bool
    {
        if (!self::isCalculationEnabled($db)) {
            return false;
        }
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'product_tax_enabled' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null || trim((string)$raw) === '') {
                return false;
            }
            return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function normalizeRate(float $rate): float
    {
        if ($rate < 0) {
            $rate = 0.0;
        }
        if ($rate > 100) {
            $rate = 100.0;
        }
        return round($rate, 2);
    }

    /**
     * @param array<string,mixed> $product
     * @param bool $isOpenItem Comodín / ítem libre.
     * @param mixed $postedTaxRate Tasa enviada desde Caja (comodín: 0 = exento, >0 = aplica).
     */
    public static function resolveProductRate(
        array $product,
        float $defaultRate,
        ?\PDO $db = null,
        bool $isOpenItem = false,
        mixed $postedTaxRate = null
    ): float {
        if ($db instanceof \PDO && !self::isCalculationEnabled($db)) {
            return 0.0;
        }
        if ($isOpenItem) {
            // Check del modal: sin IVA → 0; con IVA → tasa global de configuración.
            if ($postedTaxRate !== null && $postedTaxRate !== '') {
                $posted = (float)$postedTaxRate;
                return $posted > 0.009
                    ? self::normalizeRate($defaultRate > 0.009 ? $defaultRate : $posted)
                    : 0.0;
            }
            return self::normalizeRate($defaultRate);
        }
        if ($db instanceof \PDO && !self::isProductTaxEnabled($db)) {
            return 0.0;
        }
        if (!array_key_exists('tax_rate', $product) || $product['tax_rate'] === null || $product['tax_rate'] === '') {
            return self::normalizeRate($defaultRate);
        }
        return self::normalizeRate((float)$product['tax_rate']);
    }

    /**
     * Asegura columnas de IVA (idempotente).
     */
    public static function ensureSchema(\PDO $db): void
    {
        self::addColumnIfMissing(
            $db,
            'products',
            'tax_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER sale_price'
        );
        self::addColumnIfMissing(
            $db,
            'sale_items',
            'tax_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal'
        );
        self::addColumnIfMissing(
            $db,
            'sale_items',
            'tax_amount',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_rate'
        );
        self::addColumnIfMissing(
            $db,
            'sale_items',
            'discount_amount',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_amount'
        );
        self::addColumnIfMissing(
            $db,
            'sale_items',
            'taxable',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_amount'
        );
        self::addColumnIfMissing(
            $db,
            'quote_items',
            'tax_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal'
        );
        self::addColumnIfMissing(
            $db,
            'quote_items',
            'tax_amount',
            'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER tax_rate'
        );
    }

    /**
     * Prorratea el descuento % del documento y calcula IVA por línea.
     *
     * El descuento comercial aplica solo al precio del producto.
     * El IVA se calcula sobre el precio completo (antes de descuento): no se descuenta.
     *
     * - Document `taxable` = neto a cobrar sin IVA (subtotal − descuento), para total/recargos.
     * - Line `taxable` = base imponible del IVA = subtotal de línea (precio completo).
     *
     * @param list<array{subtotal:float|int|string,tax_rate?:float|int|string}> $lines
     * @return array{
     *   subtotal:float,
     *   discount:float,
     *   taxable:float,
     *   tax:float,
     *   lines:list<array<string,mixed>>
     * }
     */
    public static function applyDocumentDiscountAndTax(array $lines, float $discountPercent): array
    {
        $normalized = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $lineSub = round((float)($line['subtotal'] ?? 0), 2);
            $rate = self::normalizeRate((float)($line['tax_rate'] ?? 0));
            $normalized[] = array_merge($line, [
                'subtotal' => $lineSub,
                'tax_rate' => $rate,
            ]);
            $subtotal += $lineSub;
        }
        $subtotal = round($subtotal, 2);
        $discountPercent = max(0.0, min(100.0, $discountPercent));
        $discount = round($subtotal * ($discountPercent / 100), 2);

        $out = [];
        $taxTotal = 0.0;
        $allocatedDiscount = 0.0;
        $n = count($normalized);
        foreach ($normalized as $i => $line) {
            $lineSub = (float)$line['subtotal'];
            if ($n === 0) {
                $lineDisc = 0.0;
            } elseif ($i === $n - 1) {
                $lineDisc = round($discount - $allocatedDiscount, 2);
            } else {
                $share = $subtotal > 0.009 ? ($lineSub / $subtotal) : 0.0;
                $lineDisc = round($discount * $share, 2);
                $allocatedDiscount += $lineDisc;
            }
            if ($lineDisc < 0) {
                $lineDisc = 0.0;
            }
            if ($lineDisc > $lineSub) {
                $lineDisc = $lineSub;
            }
            // Base imponible = precio completo; el descuento no reduce el IVA.
            $taxBase = $lineSub;
            $rate = (float)$line['tax_rate'];
            $taxAmt = round($taxBase * ($rate / 100), 2);
            $taxTotal += $taxAmt;
            $out[] = array_merge($line, [
                'discount_amount' => $lineDisc,
                'taxable' => $taxBase,
                'tax_amount' => $taxAmt,
            ]);
        }

        $taxTotal = round($taxTotal, 2);
        $sumLines = 0.0;
        foreach ($out as $row) {
            $sumLines += (float)$row['tax_amount'];
        }
        $diff = round($taxTotal - round($sumLines, 2), 2);
        if (abs($diff) >= 0.01 && $out !== []) {
            $last = count($out) - 1;
            $out[$last]['tax_amount'] = round((float)$out[$last]['tax_amount'] + $diff, 2);
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            // Neto comercial (sin IVA): sirve para total = taxable + tax + extras.
            'taxable' => max(0.0, round($subtotal - $discount, 2)),
            'tax' => $taxTotal,
            'lines' => $out,
        ];
    }

    /**
     * Verifica que el IVA del encabezado cuadre con la suma de líneas.
     */
    public static function assertHeaderMatchesLines(float $headerTax, array $lines, float $tolerance = 0.02): void
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float)($line['tax_amount'] ?? 0);
        }
        if (abs(round($headerTax, 2) - round($sum, 2)) > $tolerance) {
            throw new \RuntimeException(
                'Descuadre IVA encabezado (' . number_format($headerTax, 2, '.', '')
                . ') vs líneas (' . number_format($sum, 2, '.', '') . ')'
            );
        }
    }

    /**
     * Backfill one-shot de tax_rate/tax_amount en sale_items históricos.
     *
     * @return array{sales:int,items:int,skipped:int}
     */
    public static function backfillSaleItemTaxes(\PDO $db): array
    {
        self::ensureSchema($db);
        $flag = 'sale_items_tax_backfill_v1';
        try {
            $chk = $db->prepare("SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1");
            $chk->execute([$flag]);
            if ($chk->fetchColumn()) {
                return ['sales' => 0, 'items' => 0, 'skipped' => 0];
            }
        } catch (\Throwable $e) {
            // continue
        }

        $default = self::defaultRate($db);
        $sales = 0;
        $itemsUpdated = 0;
        $skipped = 0;

        try {
            $saleRows = $db->query(
                "SELECT s.id, s.subtotal, s.discount, s.tax
                 FROM sales s
                 WHERE COALESCE(s.tax, 0) > 0.009
                   AND COALESCE(s.status, '') <> 'cancelled'
                   AND EXISTS (
                        SELECT 1 FROM sale_items si
                        WHERE si.sale_id = s.id
                          AND COALESCE(si.tax_amount, 0) <= 0.009
                   )
                 ORDER BY s.id
                 LIMIT 5000"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['sales' => 0, 'items' => 0, 'skipped' => 0];
        }

        $upd = $db->prepare(
            "UPDATE sale_items
             SET tax_rate = ?, tax_amount = ?,
                 discount_amount = COALESCE(?, discount_amount),
                 taxable = COALESCE(?, taxable)
             WHERE id = ?"
        );

        foreach ($saleRows as $sale) {
            $saleId = (int)$sale['id'];
            $saleSub = round((float)($sale['subtotal'] ?? 0), 2);
            $saleDisc = round((float)($sale['discount'] ?? 0), 2);
            $saleTax = round((float)($sale['tax'] ?? 0), 2);

            $lines = $db->prepare(
                "SELECT si.id, si.subtotal, si.quantity, si.unit_price,
                        COALESCE(si.tax_rate, 0) AS tax_rate,
                        COALESCE(si.tax_amount, 0) AS tax_amount,
                        COALESCE(p.tax_rate, ?) AS product_tax_rate
                 FROM sale_items si
                 LEFT JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id = ?
                 ORDER BY si.id"
            );
            $lines->execute([$default, $saleId]);
            $rows = $lines->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                $skipped++;
                continue;
            }

            $sumTax = 0.0;
            foreach ($rows as $r) {
                $sumTax += (float)$r['tax_amount'];
            }
            if ($sumTax > 0.009) {
                $skipped++;
                continue;
            }

            $build = [];
            $anyRate = false;
            foreach ($rows as $r) {
                $rate = self::normalizeRate((float)$r['product_tax_rate']);
                if ($rate > 0.009) {
                    $anyRate = true;
                }
                $build[] = [
                    'id' => (int)$r['id'],
                    'subtotal' => round((float)$r['subtotal'], 2),
                    'tax_rate' => $rate,
                ];
            }

            $discPct = ($saleSub > 0.009 && $saleDisc > 0.009)
                ? min(100.0, round(($saleDisc / $saleSub) * 100, 4))
                : 0.0;

            if ($anyRate) {
                $breakdown = self::applyDocumentDiscountAndTax($build, $discPct);
                // Ajustar al tax del encabezado si hay leve diferencia de tasas históricas.
                $calcTax = (float)$breakdown['tax'];
                if ($saleTax > 0.009 && abs($calcTax - $saleTax) > 0.05 && $calcTax > 0.009) {
                    $factor = $saleTax / $calcTax;
                    foreach ($breakdown['lines'] as &$bl) {
                        $bl['tax_amount'] = round((float)$bl['tax_amount'] * $factor, 2);
                    }
                    unset($bl);
                    $sumAdj = 0.0;
                    foreach ($breakdown['lines'] as $bl) {
                        $sumAdj += (float)$bl['tax_amount'];
                    }
                    $drift = round($saleTax - $sumAdj, 2);
                    if (abs($drift) >= 0.01 && $breakdown['lines'] !== []) {
                        $last = count($breakdown['lines']) - 1;
                        $breakdown['lines'][$last]['tax_amount'] = round(
                            (float)$breakdown['lines'][$last]['tax_amount'] + $drift,
                            2
                        );
                    }
                }
                foreach ($breakdown['lines'] as $bl) {
                    $upd->execute([
                        (float)$bl['tax_rate'],
                        (float)$bl['tax_amount'],
                        (float)($bl['discount_amount'] ?? 0),
                        (float)($bl['taxable'] ?? 0),
                        (int)$bl['id'],
                    ]);
                    $itemsUpdated++;
                }
            } else {
                // Sin tasas en productos: prorratear sales.tax / descuento por subtotal.
                $inferredRate = 0.0;
                $taxableDoc = max(0.0, round($saleSub - $saleDisc, 2));
                if ($taxableDoc > 0.009 && $saleTax > 0.009) {
                    $inferredRate = self::normalizeRate(($saleTax / $taxableDoc) * 100);
                } elseif ($default > 0.009) {
                    $inferredRate = $default;
                }
                $allocatedTax = 0.0;
                $allocatedDisc = 0.0;
                $n = count($build);
                foreach ($build as $i => $bl) {
                    $lineSub = (float)$bl['subtotal'];
                    if ($i === $n - 1) {
                        $lineTax = round($saleTax - $allocatedTax, 2);
                        $lineDisc = round($saleDisc - $allocatedDisc, 2);
                    } else {
                        $share = $saleSub > 0.009 ? ($lineSub / $saleSub) : 0.0;
                        $lineTax = round($saleTax * $share, 2);
                        $lineDisc = round($saleDisc * $share, 2);
                        $allocatedTax += $lineTax;
                        $allocatedDisc += $lineDisc;
                    }
                    if ($lineDisc < 0) {
                        $lineDisc = 0.0;
                    }
                    if ($lineDisc > $lineSub) {
                        $lineDisc = $lineSub;
                    }
                    $taxable = max(0.0, round($lineSub - $lineDisc, 2));
                    $upd->execute([
                        $inferredRate,
                        max(0.0, $lineTax),
                        $lineDisc,
                        $taxable,
                        (int)$bl['id'],
                    ]);
                    $itemsUpdated++;
                }
            }
            $sales++;
        }

        try {
            $db->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([$flag, date('c')]);
        } catch (\Throwable $e) {
            try {
                $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                    ->execute([$flag, date('c'), 'Backfill IVA en sale_items']);
            } catch (\Throwable $e2) {
                // ignore
            }
        }

        return ['sales' => $sales, 'items' => $itemsUpdated, 'skipped' => $skipped];
    }

    /**
     * Resumen de libro IVA (documentos + asientos contables).
     *
     * @return array<string,mixed>
     */
    public static function vatBook(\PDO $db, string $from, string $to): array
    {
        self::ensureSchema($db);
        $generatedAccount = '240801';
        $deductibleAccount = '240802';
        try {
            // Misma fuente que AccountingService (accounting_settings), con fallback a settings.
            $stmt = $db->query(
                "SELECT setting_key, setting_value FROM accounting_settings
                 WHERE setting_key IN ('vat_generated_account','vat_deductible_account')"
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ($row['setting_key'] === 'vat_generated_account' && trim((string)$row['setting_value']) !== '') {
                    $generatedAccount = trim((string)$row['setting_value']);
                }
                if ($row['setting_key'] === 'vat_deductible_account' && trim((string)$row['setting_value']) !== '') {
                    $deductibleAccount = trim((string)$row['setting_value']);
                }
            }
        } catch (\Throwable $e) {
            try {
                $stmt = $db->prepare(
                    "SELECT setting_key, setting_value FROM settings
                     WHERE setting_key IN ('vat_generated_account','vat_deductible_account')"
                );
                $stmt->execute();
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if ($row['setting_key'] === 'vat_generated_account' && trim((string)$row['setting_value']) !== '') {
                        $generatedAccount = trim((string)$row['setting_value']);
                    }
                    if ($row['setting_key'] === 'vat_deductible_account' && trim((string)$row['setting_value']) !== '') {
                        $deductibleAccount = trim((string)$row['setting_value']);
                    }
                }
            } catch (\Throwable $e2) {
                // defaults
            }
        }

        $salesTax = 0.0;
        $salesCnTax = 0.0;
        $purchaseTax = 0.0;
        $purchaseCnTax = 0.0;
        $byRate = [];

        try {
            $st = $db->prepare(
                "SELECT COALESCE(SUM(tax),0) t FROM sales
                 WHERE DATE(sale_date) BETWEEN ? AND ?
                   AND COALESCE(status,'') NOT IN ('cancelled')"
            );
            $st->execute([$from, $to]);
            $salesTax = (float)$st->fetchColumn();
        } catch (\Throwable $e) {
            $salesTax = 0.0;
        }

        try {
            $st = $db->prepare(
                "SELECT COALESCE(SUM(tax),0) FROM sale_credit_notes
                 WHERE DATE(credit_date) BETWEEN ? AND ?
                   AND COALESCE(status,'') = 'posted'"
            );
            $st->execute([$from, $to]);
            $salesCnTax = (float)$st->fetchColumn();
        } catch (\Throwable $e) {
            $salesCnTax = 0.0;
        }

        try {
            $st = $db->prepare(
                "SELECT COALESCE(SUM(tax),0) FROM purchase_orders
                 WHERE status = 'received'
                   AND DATE(COALESCE(warehouse_date, order_date)) BETWEEN ? AND ?"
            );
            $st->execute([$from, $to]);
            $purchaseTax = (float)$st->fetchColumn();
        } catch (\Throwable $e) {
            $purchaseTax = 0.0;
        }

        try {
            $st = $db->prepare(
                "SELECT COALESCE(SUM(tax),0) FROM purchase_credit_notes
                 WHERE DATE(credit_date) BETWEEN ? AND ?
                   AND COALESCE(status,'') IN ('posted','applied','confirmed')"
            );
            $st->execute([$from, $to]);
            $purchaseCnTax = (float)$st->fetchColumn();
        } catch (\Throwable $e) {
            try {
                $st = $db->prepare(
                    "SELECT COALESCE(SUM(tax),0) FROM purchase_credit_notes
                     WHERE DATE(credit_date) BETWEEN ? AND ?"
                );
                $st->execute([$from, $to]);
                $purchaseCnTax = (float)$st->fetchColumn();
            } catch (\Throwable $e2) {
                $purchaseCnTax = 0.0;
            }
        }

        try {
            $st = $db->prepare(
                "SELECT COALESCE(si.tax_rate, 0) rate,
                        COALESCE(SUM(
                            CASE
                                WHEN COALESCE(si.taxable, 0) > 0.009 THEN si.taxable
                                ELSE GREATEST(si.subtotal - COALESCE(si.discount_amount, 0), 0)
                            END
                        ), 0) base,
                        COALESCE(SUM(si.tax_amount), 0) tax
                 FROM sale_items si
                 INNER JOIN sales s ON s.id = si.sale_id
                 WHERE DATE(s.sale_date) BETWEEN ? AND ?
                   AND COALESCE(s.status,'') NOT IN ('cancelled')
                 GROUP BY COALESCE(si.tax_rate, 0)
                 ORDER BY rate"
            );
            $st->execute([$from, $to]);
            $byRate = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $byRate = [];
        }

        $ledgerGenerated = 0.0;
        $ledgerDeductible = 0.0;
        try {
            $st = $db->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN a.code = ? THEN l.credit - l.debit ELSE 0 END), 0) generated_net,
                    COALESCE(SUM(CASE WHEN a.code = ? THEN l.debit - l.credit ELSE 0 END), 0) deductible_net
                 FROM accounting_lines l
                 INNER JOIN accounting_entries e ON e.id = l.entry_id
                 INNER JOIN accounting_accounts a ON a.id = l.account_id
                 WHERE e.entry_date BETWEEN ? AND ?
                   AND e.status IN ('posted','reversed')
                   AND a.code IN (?, ?)"
            );
            $st->execute([$generatedAccount, $deductibleAccount, $from, $to, $generatedAccount, $deductibleAccount]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            $ledgerGenerated = round((float)($row['generated_net'] ?? 0), 2);
            $ledgerDeductible = round((float)($row['deductible_net'] ?? 0), 2);
        } catch (\Throwable $e) {
            // ignore
        }

        $netGeneratedDocs = round($salesTax - $salesCnTax, 2);
        $netDeductibleDocs = round($purchaseTax - $purchaseCnTax, 2);

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => [
                'generated' => $generatedAccount,
                'deductible' => $deductibleAccount,
            ],
            'documents' => [
                'sales_tax' => round($salesTax, 2),
                'sales_credit_tax' => round($salesCnTax, 2),
                'sales_net' => $netGeneratedDocs,
                'purchases_tax' => round($purchaseTax, 2),
                'purchases_credit_tax' => round($purchaseCnTax, 2),
                'purchases_net' => $netDeductibleDocs,
                'balance_to_pay' => round($netGeneratedDocs - $netDeductibleDocs, 2),
            ],
            'ledger' => [
                'generated_net' => $ledgerGenerated,
                'deductible_net' => $ledgerDeductible,
                'balance' => round($ledgerGenerated - $ledgerDeductible, 2),
            ],
            'by_rate' => $byRate,
            'warnings' => self::vatBookWarnings($netGeneratedDocs, $ledgerGenerated, $netDeductibleDocs, $ledgerDeductible),
        ];
    }

    /**
     * @return list<string>
     */
    private static function vatBookWarnings(
        float $docGen,
        float $ledGen,
        float $docDed,
        float $ledDed
    ): array {
        $w = [];
        if (abs($docGen - $ledGen) > 1.0) {
            $w[] = 'IVA generado en documentos vs libro contable difiere más de $1. Revise sync/asientos pendientes.';
        }
        if (abs($docDed - $ledDed) > 1.0) {
            $w[] = 'IVA descontable en compras vs libro contable difiere más de $1.';
        }
        return $w;
    }

    private static function addColumnIfMissing(\PDO $db, string $table, string $column, string $definition): void
    {
        try {
            $stmt = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
            $stmt->execute([$column]);
            if ($stmt->fetch()) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        try {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {
            $bare = preg_replace('/\s+AFTER\s+`.+?`/i', '', $definition) ?? $definition;
            $bare = preg_replace('/\s+AFTER\s+\w+/i', '', $bare) ?? $bare;
            try {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$bare}");
            } catch (\Throwable $e2) {
                // ignore
            }
        }
    }
}
