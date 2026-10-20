<?php

namespace SoftNova\Services;

/**
 * Liquidaciones Bold (datáfono, Smart / Smart Pro y links de pago) desde el reporte descargado del panel.
 *
 * Las ventas cobradas con medios asignados a la cuenta puente quedan en «Bold por liquidar» por el bruto.
 * Cada depósito registra: Dr banco (neto) + Dr comisión + Dr IVA descontable + Dr retenciones (1355)
 * / Cr cuenta puente (bruto). Asiento source_type 'bold_settlement'.
 */
class BoldSettlementService
{
    private const SYNONYMS = [
        'deposit_date' => ['fecha de deposito', 'fecha deposito', 'fecha de abono', 'fecha abono', 'fecha de liquidacion',
            'fecha liquidacion', 'fecha desembolso', 'fecha de desembolso', 'fecha dispersion', 'fecha de pago al comercio'],
        'tx_date' => ['fecha de transaccion', 'fecha transaccion', 'fecha de la transaccion', 'fecha de venta', 'fecha venta',
            'fecha de pago', 'fecha de creacion', 'fecha creacion', '=fecha', '=date', 'fecha'],
        'ret_renta' => ['retefuente', 'rete fuente', 'retencion en la fuente', 'retencion fuente', 'retencion renta', 'rte fuente'],
        'ret_iva' => ['reteiva', 'rete iva', 'retencion iva', 'retencion de iva', 'rte iva'],
        'ret_ica' => ['reteica', 'rete ica', 'retencion ica', 'retencion de ica', 'rte ica'],
        'vat' => ['iva comision', 'iva sobre comision', 'iva de la comision', 'iva bold', '=iva'],
        'commission' => ['comision bold', 'valor comision', 'valor de la comision', 'costo transaccion', 'costo de la transaccion',
            '=tarifa', 'comision'],
        'net' => ['valor neto', 'monto neto', '=neto', 'valor a depositar', 'monto a depositar', 'valor depositado', 'total a depositar',
            'valor abonado', 'monto abonado', 'monto depositado', 'total depositado', 'neto a pagar', 'total neto', 'valor a pagar',
            '=depositado', '=abonado', 'neto depositado'],
        'gross' => ['valor bruto', 'monto bruto', 'valor transaccion', 'valor de la transaccion', 'valor de la venta', 'valor venta', 'valor de venta',
            'monto de la transaccion', 'monto total', '=valor total', '=monto', '=valor', '=total', '=amount'],
        'status' => ['estado de la transaccion', 'estado transaccion', '=estado', '=status'],
        'method' => ['metodo de pago', 'medio de pago', 'tipo de pago', 'tipo de transaccion', '=canal', '=producto', '=metodo',
            '=franquicia', '=tipo'],
        'reference' => ['id de la transaccion', 'id de transaccion', 'id transaccion', 'referencia', 'id venta', 'codigo transaccion',
            'numero de aprobacion', 'codigo de aprobacion', 'id pago', '=transaccion', '=id', '=aprobacion', '=voucher'],
    ];

    private const OVERRIDES = [
        'col_date' => 'tx_date', 'col_deposit' => 'deposit_date', 'col_ref' => 'reference', 'col_gross' => 'gross',
        'col_commission' => 'commission', 'col_vat' => 'vat', 'col_ret_renta' => 'ret_renta', 'col_ret_iva' => 'ret_iva',
        'col_ret_ica' => 'ret_ica', 'col_net' => 'net', 'col_method' => 'method', 'col_status' => 'status',
    ];

    public const METHOD_GROUPS = ['dataphone' => 'Datáfono', 'payment_link' => 'Link de pago'];

    private BankingService $banking;

    public function __construct(private \PDO $db)
    {
        $this->banking = new BankingService($db);
        $this->ensureSchema();
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
        if (isset($done[$oid]) || $this->db->inTransaction()) {
            return;
        }
        $done[$oid] = true;
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bold_settlements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                gateway_account_id INT UNSIGNED NOT NULL,
                bank_account_id INT UNSIGNED NOT NULL,
                settlement_date DATE NOT NULL,
                file_name VARCHAR(190) NULL,
                tx_count INT UNSIGNED NOT NULL DEFAULT 0,
                gross DECIMAL(14,2) NOT NULL DEFAULT 0,
                commission DECIMAL(14,2) NOT NULL DEFAULT 0,
                vat DECIMAL(14,2) NOT NULL DEFAULT 0,
                ret_renta DECIMAL(14,2) NOT NULL DEFAULT 0,
                ret_iva DECIMAL(14,2) NOT NULL DEFAULT 0,
                ret_ica DECIMAL(14,2) NOT NULL DEFAULT 0,
                net DECIMAL(14,2) NOT NULL DEFAULT 0,
                adjustment DECIMAL(14,2) NOT NULL DEFAULT 0,
                entry_id BIGINT UNSIGNED NULL,
                expense_ids VARCHAR(120) NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'posted',
                cancel_reason VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                cancelled_at DATETIME NULL,
                KEY idx_bold_settlement_date (settlement_date),
                KEY idx_bold_settlement_entry (entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bold_settlement_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                settlement_id INT UNSIGNED NOT NULL,
                tx_date DATE NOT NULL,
                deposit_date DATE NULL,
                tx_ref VARCHAR(80) NULL,
                method_group VARCHAR(20) NOT NULL DEFAULT 'dataphone',
                raw_status VARCHAR(40) NULL,
                gross DECIMAL(14,2) NOT NULL DEFAULT 0,
                commission DECIMAL(14,2) NOT NULL DEFAULT 0,
                vat DECIMAL(14,2) NOT NULL DEFAULT 0,
                ret_renta DECIMAL(14,2) NOT NULL DEFAULT 0,
                ret_iva DECIMAL(14,2) NOT NULL DEFAULT 0,
                ret_ica DECIMAL(14,2) NOT NULL DEFAULT 0,
                net DECIMAL(14,2) NOT NULL DEFAULT 0,
                sale_payment_id INT UNSIGNED NULL,
                line_hash CHAR(40) NOT NULL,
                UNIQUE KEY uq_bold_line_hash (line_hash),
                KEY idx_bold_line_settlement (settlement_id),
                KEY idx_bold_line_payment (sale_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function setting(string $key, string $default): string
    {
        $v = $this->query('SELECT setting_value FROM accounting_settings WHERE setting_key = ? LIMIT 1', [$key])->fetchColumn();
        return is_string($v) && $v !== '' ? $v : $default;
    }

    /** @return list<array<string,mixed>> */
    public function gateways(): array
    {
        return array_values(array_filter(
            $this->banking->accounts(true),
            static fn ($a) => $a['kind'] === 'gateway'
        ));
    }

    /**
     * @return array<string,mixed> resumen de la importación
     */
    public function import(int $gatewayId, int $bankId, string $path, string $fileName, array $overrides = []): array
    {
        $gateway = $this->banking->account($gatewayId);
        if (!$gateway || $gateway['kind'] !== 'gateway' || (int)$gateway['is_active'] !== 1) {
            throw new \InvalidArgumentException('Seleccione la cuenta puente de Bold');
        }
        $bank = $this->banking->account($bankId);
        if (!$bank || $bank['kind'] === 'gateway' || (int)$bank['is_active'] !== 1) {
            throw new \InvalidArgumentException('Seleccione el banco donde Bold deposita');
        }
        $methods = $this->banking->gatewayMethods($gatewayId);
        if (!$methods) {
            throw new \RuntimeException(
                'Ningún medio de pago entra a ' . $gateway['name'] . '. Asígnelo en Bancos → Cuentas → «¿A dónde entra cada cobro?».'
            );
        }
        $since = $this->banking->collectionSince();

        $matrix = SpreadsheetMatrixReader::read($path, $fileName);
        [$map, $start, $labels] = $this->resolveColumns($matrix, $overrides);
        $onlyLinks = $methods === ['payment_link'];

        $txs = [];
        $occurrence = [];
        $skipped = ['status' => 0, 'before' => 0, 'invalid' => 0];
        for ($i = $start, $n = count($matrix); $i < $n; $i++) {
            $r = $matrix[$i];
            $cell = static fn (string $f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
            $txDate = SpreadsheetMatrixReader::parseDate($cell('tx_date'));
            $depDate = SpreadsheetMatrixReader::parseDate($cell('deposit_date'));
            if ($txDate === null && $depDate === null) {
                $skipped['invalid']++;
                continue;
            }
            $txDate ??= $depDate;
            $status = SpreadsheetMatrixReader::normalize($cell('status'));
            if ($status !== '' && preg_match('/rechaz|fallid|declin|anulad|cancel|pendient|expirad|error|fraude|abandon|no aprob/', $status)) {
                $skipped['status']++;
                continue;
            }
            $amt = static fn (string $f) => isset($map[$f]) ? SpreadsheetMatrixReader::parseAmount($cell($f)) : null;
            $gross = $amt('gross');
            $net = $amt('net');
            $commission = abs((float)$amt('commission'));
            $vat = abs((float)$amt('vat'));
            $retRenta = abs((float)$amt('ret_renta'));
            $retIva = abs((float)$amt('ret_iva'));
            $retIca = abs((float)$amt('ret_ica'));
            if ($vat > 0 && $gross !== null && $net !== null) {
                // Una columna «IVA» que no cuadra con bruto − neto es el IVA de la venta, no el de la comisión.
                $withoutVat = abs(abs($gross) - abs($net) - $commission - $retRenta - $retIva - $retIca);
                if ($withoutVat <= 1 && abs($withoutVat - $vat) > 1) {
                    $vat = 0.0;
                }
            }
            $deductions = $commission + $vat + $retRenta + $retIva + $retIca;
            if ($gross === null && $net === null) {
                $skipped['invalid']++;
                continue;
            }
            $sign = (($gross ?? $net) < 0) ? -1 : 1;
            if ($sign < 0) {
                // Devolución o contracargo: las deducciones van con el mismo signo del bruto.
                $deductions = -$deductions;
                [$commission, $vat, $retRenta, $retIva, $retIca] = [-$commission, -$vat, -$retRenta, -$retIva, -$retIca];
            }
            $gross ??= round($net + $deductions, 2);
            $net ??= round($gross - $deductions, 2);
            if (abs($gross) < 0.005 && abs($net) < 0.005) {
                $skipped['invalid']++;
                continue;
            }
            if ($since !== '' && $txDate < $since) {
                $skipped['before']++;
                continue;
            }
            $methodText = SpreadsheetMatrixReader::normalize($cell('method'));
            $group = $onlyLinks ? 'payment_link' : 'dataphone';
            if ($methodText !== '') {
                $group = preg_match('/link|boton|pse|online|en linea|web|checkout|ecommerce|virtual/', $methodText)
                    ? 'payment_link' : 'dataphone';
            }
            $ref = $this->cut($cell('reference'), 80);
            $key = $ref !== ''
                ? 'ref:' . $ref
                : $txDate . '|' . number_format($gross, 2, '.', '') . '|' . number_format($net, 2, '.', '');
            if ($ref === '') {
                $occurrence[$key] = ($occurrence[$key] ?? 0) + 1;
                $key .= '#' . $occurrence[$key];
            }
            $txs[] = [
                'tx_date' => $txDate, 'deposit_date' => $depDate, 'ref' => $ref, 'group' => $group,
                'status' => $this->cut($cell('status'), 40), 'gross' => round($gross, 2), 'net' => round($net, 2),
                'commission' => round($commission, 2), 'vat' => round($vat, 2), 'ret_renta' => round($retRenta, 2),
                'ret_iva' => round($retIva, 2), 'ret_ica' => round($retIca, 2), 'hash' => sha1('bold|' . $key),
            ];
        }
        if (!$txs) {
            throw new \RuntimeException(
                'No hay transacciones para liquidar'
                . ($skipped['before'] > 0 ? ' (' . $skipped['before'] . ' son anteriores al ' . date('d/m/Y', strtotime($since)) . ', fecha desde la que Bold va a la cuenta puente)' : '')
                . ($skipped['status'] > 0 ? ' · ' . $skipped['status'] . ' rechazadas/anuladas' : '') . '.'
            );
        }

        $hashes = array_column($txs, 'hash');
        $existing = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $rows = $this->query(
                'SELECT line_hash FROM bold_settlement_lines WHERE line_hash IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $h) {
                $existing[$h] = true;
            }
        }
        $fresh = array_values(array_filter($txs, static fn ($t) => !isset($existing[$t['hash']])));
        if (!$fresh) {
            throw new \RuntimeException('Todas las transacciones de este archivo ya estaban liquidadas.');
        }

        $groups = [];
        foreach ($fresh as $t) {
            $groups[$t['deposit_date'] ?? $t['tx_date']][] = $t;
        }
        ksort($groups);

        $accounting = new AccountingService($this->db);
        $paymentMethods = $this->paymentMethodCodes($methods);
        $usedPayments = [];
        $created = [];
        $matchedSales = 0;
        $this->db->beginTransaction();
        try {
            foreach ($groups as $date => $list) {
                $result = $this->postSettlement($accounting, $gateway, $bank, (string)$date, $list, $fileName, $paymentMethods, $usedPayments);
                $created[] = $result['id'];
                $matchedSales += $result['matched'];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'settlements' => count($created),
            'transactions' => count($fresh),
            'duplicates' => count($txs) - count($fresh),
            'skipped_status' => $skipped['status'],
            'skipped_before' => $skipped['before'],
            'skipped_invalid' => $skipped['invalid'],
            'matched_sales' => $matchedSales,
            'columns' => $labels,
            'gross' => round(array_sum(array_column($fresh, 'gross')), 2),
            'net' => round(array_sum(array_column($fresh, 'net')), 2),
        ];
    }

    /**
     * @param list<array<string,mixed>> $list
     * @param list<string> $paymentMethods
     * @param array<int,bool> $usedPayments
     * @return array{id:int,matched:int}
     */
    private function postSettlement(
        AccountingService $accounting,
        array $gateway,
        array $bank,
        string $date,
        array $list,
        string $fileName,
        array $paymentMethods,
        array &$usedPayments
    ): array {
        $sum = static fn (string $f, ?string $group = null) => round(array_sum(array_map(
            static fn ($t) => ($group === null || $t['group'] === $group) ? (float)$t[$f] : 0.0,
            $list
        )), 2);
        $gross = $sum('gross');
        $net = $sum('net');
        $commission = $sum('commission');
        $vat = $sum('vat');
        $retRenta = $sum('ret_renta');
        $retIva = $sum('ret_iva');
        $retIca = $sum('ret_ica');
        $adjust = round($gross - $net - $commission - $vat - $retRenta - $retIva - $retIca, 2);

        $vatDeductible = $this->setting('gateway_vat_deductible', '1') === '1';
        $expenseFor = static fn (float $c, float $v, float $a) => round($c + ($vatDeductible ? 0 : $v) + $a, 2);

        $this->query(
            'INSERT INTO bold_settlements (gateway_account_id, bank_account_id, settlement_date, file_name, tx_count, gross,
                commission, vat, ret_renta, ret_iva, ret_ica, net, adjustment, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [(int)$gateway['id'], (int)$bank['id'], $date, $this->cut($fileName, 190), count($list), $gross, $commission, $vat,
                $retRenta, $retIva, $retIca, $net, $adjust, $_SESSION['tenant_user_id'] ?? null]
        );
        $id = (int)$this->db->lastInsertId();

        $desc = 'Liquidación Bold ' . date('d/m/Y', strtotime($date)) . ' · ' . count($list) . ' transacción(es) → ' . $bank['name'];
        $parts = [
            [(string)$bank['account_code'], $net, 'Depósito neto Bold'],
            [$this->setting('gateway_commission_account', '530505'), $expenseFor($commission, $vat, $adjust),
                'Comisión Bold' . (abs($adjust) >= 0.01 ? ' (incluye ajuste ' . number_format($adjust, 2, ',', '.') . ')' : '')],
            [$this->setting('vat_deductible_account', '240802'), $vatDeductible ? $vat : 0.0, 'IVA descontable comisión Bold'],
            [$this->setting('gateway_withholding_renta_account', '135515'), $retRenta, 'Retención en la fuente (Bold)'],
            [$this->setting('gateway_withholding_iva_account', '135517'), $retIva, 'ReteIVA (Bold)'],
            [$this->setting('gateway_withholding_ica_account', '135518'), $retIca, 'ReteICA (Bold)'],
            [(string)$gateway['account_code'], -$gross, 'Ventas Bold liquidadas'],
        ];
        $lines = [];
        foreach ($parts as [$code, $value, $label]) {
            $value = round((float)$value, 2);
            if (abs($value) < 0.005) {
                continue;
            }
            $lines[] = [
                'account_code' => $code,
                'debit' => $value > 0 ? $value : 0,
                'credit' => $value < 0 ? -$value : 0,
                'description' => $label,
                'third_party_name' => 'Bold',
            ];
        }
        $entryId = $accounting->postEntry($date, $desc, $lines, 'bold_settlement', $id, 'created');
        $entryNumber = (string)$this->query('SELECT entry_number FROM accounting_entries WHERE id=?', [$entryId])->fetchColumn();

        $expenseIds = [];
        foreach (array_keys(self::METHOD_GROUPS) as $group) {
            $groupGross = $sum('gross', $group);
            $groupAdjust = round($groupGross - $sum('net', $group) - $sum('commission', $group) - $sum('vat', $group)
                - $sum('ret_renta', $group) - $sum('ret_iva', $group) - $sum('ret_ica', $group), 2);
            $amount = $expenseFor($sum('commission', $group), $sum('vat', $group), $groupAdjust);
            if ($amount <= 0) {
                continue;
            }
            $this->query(
                "INSERT INTO expenses (description, amount, category, expense_date, payment_method, notes, user_id)
                 VALUES (?,?,?,?,?,?,?)",
                [
                    'Comisión Bold ' . ($group === 'payment_link' ? 'link de pago' : 'datáfono') . ' · liquidación ' . date('d/m/Y', strtotime($date)),
                    $amount,
                    $group === 'payment_link' ? 'payment_link_commission' : 'dataphone_commission',
                    $date,
                    'transfer',
                    'bold_settlement:' . $id . ' · asiento contable #' . $entryNumber,
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
            $expenseIds[] = (int)$this->db->lastInsertId();
        }
        $this->query(
            'UPDATE bold_settlements SET entry_id=?, expense_ids=? WHERE id=?',
            [$entryId, $expenseIds ? implode(',', $expenseIds) : null, $id]
        );

        $ins = $this->db->prepare(
            'INSERT INTO bold_settlement_lines (settlement_id, tx_date, deposit_date, tx_ref, method_group, raw_status, gross,
                commission, vat, ret_renta, ret_iva, ret_ica, net, sale_payment_id, line_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $matched = 0;
        foreach ($list as $t) {
            $paymentId = $t['gross'] > 0 ? $this->findSalePayment($t['tx_date'], (float)$t['gross'], $paymentMethods, $usedPayments) : null;
            if ($paymentId !== null) {
                $usedPayments[$paymentId] = true;
                $matched++;
            }
            $ins->execute([
                $id, $t['tx_date'], $t['deposit_date'], $t['ref'] ?: null, $t['group'], $t['status'] ?: null, $t['gross'],
                $t['commission'], $t['vat'], $t['ret_renta'], $t['ret_iva'], $t['ret_ica'], $t['net'], $paymentId, $t['hash'],
            ]);
        }
        return ['id' => $id, 'matched' => $matched];
    }

    /** @param list<string> $methods @return list<string> */
    private function paymentMethodCodes(array $methods): array
    {
        $codes = $methods;
        foreach (PaymentMethodCatalog::legacyAliases() as $legacy => $active) {
            if (in_array($active, $methods, true)) {
                $codes[] = $legacy;
            }
        }
        return array_values(array_unique($codes));
    }

    /** @param list<string> $methods @param array<int,bool> $used */
    private function findSalePayment(string $txDate, float $gross, array $methods, array $used): ?int
    {
        if (!$methods) {
            return null;
        }
        $ph = implode(',', array_fill(0, count($methods), '?'));
        $rows = $this->query(
            "SELECT sp.id FROM sale_payments sp
             JOIN sales s ON s.id = sp.sale_id AND COALESCE(s.status, '') <> 'cancelled'
             WHERE sp.payment_method IN ({$ph})
               AND ABS(sp.amount - ?) < 0.01
               AND DATE(sp.payment_date) BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
               AND NOT EXISTS (SELECT 1 FROM bold_settlement_lines bl WHERE bl.sale_payment_id = sp.id)
             ORDER BY ABS(DATEDIFF(sp.payment_date, ?)), sp.id
             LIMIT 10",
            array_merge($methods, [$gross, $txDate, $txDate, $txDate])
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            if (!isset($used[(int)$id])) {
                return (int)$id;
            }
        }
        return null;
    }

    /**
     * @param list<list<string>> $matrix
     * @return array{0:array<string,int>,1:int,2:array<string,string>}
     */
    private function resolveColumns(array $matrix, array $overrides): array
    {
        $detected = SpreadsheetMatrixReader::detectHeader($matrix, self::SYNONYMS, []);
        $map = $detected['map'] ?? [];
        $labels = $detected['labels'] ?? [];
        $start = $detected !== null ? $detected['header'] + 1 : 0;
        foreach (self::OVERRIDES as $input => $field) {
            $idx = SpreadsheetMatrixReader::columnIndex((string)($overrides[$input] ?? ''));
            if ($idx >= 0) {
                $map[$field] = $idx;
                $labels[$field] = 'Columna ' . SpreadsheetMatrixReader::columnLetter($idx);
            }
        }
        $firstRow = (int)($overrides['first_row'] ?? 0);
        if ($firstRow > 0) {
            $start = $firstRow - 1;
        }
        $hasDate = isset($map['tx_date']) || isset($map['deposit_date']);
        $hasValue = isset($map['gross']) || isset($map['net']);
        if (!$hasDate || !$hasValue) {
            throw new \RuntimeException(
                'No se reconocieron las columnas de fecha y valor del reporte Bold. Abra «Opciones avanzadas» e indique las letras de columna. '
                . 'Encabezados leídos: ' . $this->cut(implode(' | ', array_slice($matrix[$detected['header'] ?? 0] ?? [], 0, 14)), 300)
            );
        }
        return [$map, $start, $labels];
    }

    /** @return list<array<string,mixed>> */
    public function settlements(int $limit = 60): array
    {
        return $this->query(
            "SELECT s.*, g.name gateway_name, b.name bank_name, e.entry_number,
                    (SELECT COUNT(*) FROM bold_settlement_lines l WHERE l.settlement_id = s.id AND l.sale_payment_id IS NOT NULL) matched,
                    (SELECT bl.status FROM bank_statement_lines bl WHERE bl.entry_id = s.entry_id AND bl.status = 'matched' LIMIT 1) bank_match
             FROM bold_settlements s
             LEFT JOIN bank_accounts g ON g.id = s.gateway_account_id
             LEFT JOIN bank_accounts b ON b.id = s.bank_account_id
             LEFT JOIN accounting_entries e ON e.id = s.entry_id
             ORDER BY s.settlement_date DESC, s.id DESC LIMIT " . max(1, $limit)
        )->fetchAll();
    }

    public function settlement(int $id): ?array
    {
        $row = $this->query(
            'SELECT s.*, e.entry_number, g.name gateway_name, b.name bank_name FROM bold_settlements s
             LEFT JOIN accounting_entries e ON e.id = s.entry_id
             LEFT JOIN bank_accounts g ON g.id = s.gateway_account_id
             LEFT JOIN bank_accounts b ON b.id = s.bank_account_id
             WHERE s.id = ?',
            [$id]
        )->fetch();
        if (!$row) {
            return null;
        }
        $row['lines'] = $this->query(
            'SELECT l.*, s.invoice_number, sp.payment_method
             FROM bold_settlement_lines l
             LEFT JOIN sale_payments sp ON sp.id = l.sale_payment_id
             LEFT JOIN sales s ON s.id = sp.sale_id
             WHERE l.settlement_id = ? ORDER BY l.tx_date, l.id',
            [$id]
        )->fetchAll();
        return $row;
    }

    public function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if (strlen($reason) < 5) {
            throw new \InvalidArgumentException('Indique el motivo de la anulación (mínimo 5 caracteres)');
        }
        $row = $this->query('SELECT * FROM bold_settlements WHERE id = ?', [$id])->fetch();
        if (!$row || $row['status'] !== 'posted') {
            throw new \RuntimeException('La liquidación no existe o ya está anulada');
        }
        $accounting = new AccountingService($this->db);
        $this->db->beginTransaction();
        try {
            $accounting->reverseSource('bold_settlement', $id, 'Liquidación Bold anulada: ' . $reason);
            $expenseIds = array_filter(array_map('intval', explode(',', (string)$row['expense_ids'])));
            if ($expenseIds) {
                $this->query(
                    "UPDATE expenses SET status = 'cancelled', cancelled_at = NOW()
                     WHERE id IN (" . implode(',', array_fill(0, count($expenseIds), '?')) . ")
                       AND COALESCE(status, 'active') <> 'cancelled'",
                    array_values($expenseIds)
                );
            }
            if (!empty($row['entry_id'])) {
                $this->query(
                    "UPDATE bank_statement_lines SET status='pending', accounting_line_id=NULL, entry_id=NULL, matched_at=NULL,
                        matched_by=NULL WHERE entry_id = ? AND status = 'matched'",
                    [(int)$row['entry_id']]
                );
            }
            // Las transacciones se liberan para poder importar de nuevo el reporte corregido.
            $this->query('DELETE FROM bold_settlement_lines WHERE settlement_id = ?', [$id]);
            $this->query(
                "UPDATE bold_settlements SET status='cancelled', cancel_reason=?, cancelled_at=NOW() WHERE id=?",
                [$this->cut($reason, 255), $id]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ventas cobradas con medios Bold (desde la fecha de activación) que aún no aparecen en ninguna liquidación.
     *
     * @return array{count:int,total:float,rows:list<array<string,mixed>>}
     */
    public function pendingSales(int $gatewayId, int $limit = 100): array
    {
        $methods = $this->paymentMethodCodes($this->banking->gatewayMethods($gatewayId));
        $out = ['count' => 0, 'total' => 0.0, 'rows' => []];
        if (!$methods) {
            return $out;
        }
        $since = $this->banking->collectionSince() ?: date('Y-m-d');
        $ph = implode(',', array_fill(0, count($methods), '?'));
        $base = "FROM sale_payments sp
                 JOIN sales s ON s.id = sp.sale_id AND COALESCE(s.status, '') <> 'cancelled'
                 WHERE sp.payment_method IN ({$ph}) AND sp.amount > 0 AND DATE(sp.payment_date) >= ?
                   AND NOT EXISTS (SELECT 1 FROM bold_settlement_lines bl WHERE bl.sale_payment_id = sp.id)";
        $params = array_merge($methods, [$since]);
        $agg = $this->query('SELECT COUNT(*) cnt, COALESCE(SUM(sp.amount), 0) total ' . $base, $params)->fetch() ?: [];
        $out['count'] = (int)($agg['cnt'] ?? 0);
        $out['total'] = round((float)($agg['total'] ?? 0), 2);
        $out['rows'] = $this->query(
            'SELECT sp.id, sp.sale_id, sp.amount, sp.payment_date, sp.payment_method, s.invoice_number ' . $base
                . ' ORDER BY sp.payment_date DESC, sp.id DESC LIMIT ' . max(1, $limit),
            $params
        )->fetchAll();
        return $out;
    }

    /** Transacciones liquidadas sin venta asociada en el sistema (posible venta no registrada o con otro medio). */
    public function unmatchedTransactions(int $limit = 100): array
    {
        return $this->query(
            "SELECT l.*, s.settlement_date FROM bold_settlement_lines l
             JOIN bold_settlements s ON s.id = l.settlement_id AND s.status = 'posted'
             WHERE l.sale_payment_id IS NULL AND l.gross > 0
             ORDER BY l.tx_date DESC, l.id DESC LIMIT " . max(1, $limit)
        )->fetchAll();
    }

    public function bridgeBalance(array $gateway): float
    {
        return $this->banking->bookBalance((string)$gateway['account_code']);
    }

    private function cut(string $text, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len);
    }
}
