<?php

namespace SoftNova\Services;

/**
 * Bancos: cuentas por entidad (bancos, billeteras, pasarelas por liquidar), asignación del medio
 * de pago de las ventas a cada cuenta y conciliación bancaria por extracto (CSV / Excel).
 *
 * Tablas: bank_accounts, bank_statements, bank_statement_lines, bank_postings.
 * Asientos propios: source_type 'bank_posting' (4x1000, comisiones, intereses, traslados).
 */
class BankingService
{
    public const KINDS = [
        'bank' => 'Cuenta bancaria',
        'wallet' => 'Billetera digital (Nequi, Daviplata…)',
        'gateway' => 'Pasarela por liquidar (Bold)',
    ];

    public const COLLECTION_PREFIX = 'collection_account_';
    public const SINCE_KEY = 'collection_accounts_since';
    public const MATCH_WINDOW_DAYS = 5;

    /**
     * Tipos de registro desde el extracto. account: setting + cuenta por defecto; expense: categoría de Gastos
     * cuando el movimiento es un cargo (sale del banco).
     */
    public const POSTING_KINDS = [
        'gmf' => ['label' => 'GMF 4x1000', 'setting' => 'bank_gmf_account', 'default' => '530525', 'expense' => 'bank_fee'],
        'bank_fee' => ['label' => 'Comisiones y cuotas bancarias', 'setting' => 'bank_fee_account', 'default' => '530505', 'expense' => 'bank_fee'],
        'interest' => ['label' => 'Intereses / rendimientos', 'setting' => 'bank_interest_account', 'default' => '421005', 'expense' => null],
        'withholding' => ['label' => 'Retención practicada por el banco', 'setting' => 'bank_withholding_account', 'default' => '135515', 'expense' => null],
        'transfer' => ['label' => 'Traslado / reclasificación entre cuentas propias', 'setting' => null, 'default' => null, 'expense' => null],
        'other' => ['label' => 'Otra cuenta contable', 'setting' => null, 'default' => null, 'expense' => null],
    ];

    public const LINE_STATUS = [
        'pending' => 'Sin cruzar',
        'matched' => 'Cruzado',
        'posted' => 'Registrado',
        'ignored' => 'Ignorado',
    ];

    private const STATEMENT_SYNONYMS = [
        'date' => ['fecha movimiento', 'fecha transaccion', 'fecha de transaccion', 'fecha operacion', 'fecha contable',
            'fecha valor', '=fecha', '=date', 'fecha'],
        'debit' => ['debitos', 'debito', 'retiros', 'retiro', 'cargos', 'cargo', 'salidas', 'egresos', 'valor debito', '=debit'],
        'credit' => ['creditos', 'credito', 'depositos', 'deposito', 'abonos', 'abono', 'consignaciones', 'entradas',
            'ingresos', 'valor credito', '=credit'],
        'balance' => ['saldo final', 'saldo disponible', 'saldo total', 'saldo', '=balance'],
        'amount' => ['valor transaccion', 'valor movimiento', '=valor', '=monto', '=importe', '=amount', 'valor', 'monto', 'importe'],
        'type' => ['tipo movimiento', 'tipo de movimiento', 'naturaleza', '=tipo', '=d c', '=db cr'],
        'reference' => ['referencia', 'numero documento', 'no documento', 'documento', 'comprobante', '=ref', '=reference'],
        'description' => ['descripcion', 'concepto', 'detalle', 'transaccion', 'movimiento', 'glosa', '=description'],
    ];

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
        // El DDL hace commit implícito: nunca dentro de una transacción abierta.
        if (isset($done[$oid]) || $this->db->inTransaction()) {
            return;
        }
        $done[$oid] = true;

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bank_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'bank',
                bank_name VARCHAR(80) NULL,
                account_number VARCHAR(40) NULL,
                account_code VARCHAR(30) NOT NULL,
                provider VARCHAR(30) NULL,
                settle_bank_account_id INT UNSIGNED NULL,
                reconcile_from DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bank_account_code (account_code),
                KEY idx_bank_kind (kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bank_statements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bank_account_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(190) NULL,
                period_from DATE NULL,
                period_to DATE NULL,
                opening_balance DECIMAL(14,2) NULL,
                closing_balance DECIMAL(14,2) NULL,
                lines_count INT UNSIGNED NOT NULL DEFAULT 0,
                credits DECIMAL(14,2) NOT NULL DEFAULT 0,
                debits DECIMAL(14,2) NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_bank_statement_account (bank_account_id, period_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bank_statement_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                statement_id INT UNSIGNED NOT NULL,
                bank_account_id INT UNSIGNED NOT NULL,
                line_no INT UNSIGNED NOT NULL DEFAULT 0,
                line_date DATE NOT NULL,
                description VARCHAR(255) NULL,
                reference VARCHAR(80) NULL,
                amount DECIMAL(14,2) NOT NULL,
                balance DECIMAL(14,2) NULL,
                line_hash CHAR(40) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                suggestion VARCHAR(20) NULL,
                accounting_line_id BIGINT UNSIGNED NULL,
                entry_id BIGINT UNSIGNED NULL,
                posting_id INT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                matched_at DATETIME NULL,
                matched_by INT UNSIGNED NULL,
                UNIQUE KEY uq_bank_line_hash (bank_account_id, line_hash),
                KEY idx_bank_line_statement (statement_id, line_date),
                KEY idx_bank_line_status (bank_account_id, status),
                KEY idx_bank_line_acc (accounting_line_id),
                KEY idx_bank_line_posting (posting_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bank_postings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bank_account_id INT UNSIGNED NOT NULL,
                statement_id INT UNSIGNED NULL,
                kind VARCHAR(20) NOT NULL,
                counter_account VARCHAR(30) NOT NULL,
                posting_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                description VARCHAR(255) NOT NULL,
                lines_count INT UNSIGNED NOT NULL DEFAULT 0,
                entry_id BIGINT UNSIGNED NULL,
                expense_id INT UNSIGNED NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'posted',
                cancel_reason VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                cancelled_at DATETIME NULL,
                KEY idx_bank_posting_account (bank_account_id, posting_date),
                KEY idx_bank_posting_entry (entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $this->query(
                "INSERT IGNORE INTO accounting_accounts (code, name, account_type, nature, is_system)
                 VALUES ('421005', 'Intereses financieros', 'revenue', 'credit', 1),
                        ('530525', 'Gravamen a los movimientos financieros', 'expense', 'debit', 1)"
            );
            $defaults = [];
            foreach (self::POSTING_KINDS as $meta) {
                if ($meta['setting'] !== null) {
                    $defaults[] = $meta['setting'];
                    $defaults[] = $meta['default'];
                }
            }
            $this->query(
                'INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES '
                    . implode(',', array_fill(0, intdiv(count($defaults), 2), '(?, ?)')),
                $defaults
            );
        } catch (\Throwable $e) {
            // Contabilidad aún no provisionada.
        }

        // La cuenta principal de bancos (111005) queda registrada como primera cuenta.
        try {
            if ((int)$this->query('SELECT COUNT(*) FROM bank_accounts')->fetchColumn() === 0) {
                $code = $this->setting('bank_account', '111005');
                $this->query(
                    "INSERT IGNORE INTO bank_accounts (name, kind, bank_name, account_code, notes)
                     VALUES ('Cuenta principal de bancos', 'bank', NULL, ?, 'Cuenta por defecto de los medios electrónicos')",
                    [$code]
                );
            }
        } catch (\Throwable $e) {
            error_log('bank_accounts seed: ' . $e->getMessage());
        }
    }

    private function setting(string $key, string $default = ''): string
    {
        try {
            $v = $this->query('SELECT setting_value FROM accounting_settings WHERE setting_key = ? LIMIT 1', [$key])
                ->fetchColumn();
        } catch (\Throwable $e) {
            return $default;
        }
        return is_string($v) && $v !== '' ? $v : $default;
    }

    private function saveSetting(string $key, string $value): void
    {
        $this->query(
            'INSERT INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    /**
     * Cuenta puente si el medio de pago entra a una pasarela por liquidar en esa fecha.
     * Sin crear esquema ni lanzar errores: lo usa CommissionService en pleno registro de ventas.
     */
    public static function clearingAccountFor(\PDO $db, string $method, string $date): ?string
    {
        try {
            $code = PaymentMethodCatalog::normalize($method);
            if ($code === 'cash' || $code === 'mixed') {
                return null;
            }
            $stmt = $db->prepare(
                'SELECT setting_key, setting_value FROM accounting_settings WHERE setting_key IN (?, ?)'
            );
            $stmt->execute([self::COLLECTION_PREFIX . $code, self::SINCE_KEY]);
            $vals = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
            $account = trim((string)($vals[self::COLLECTION_PREFIX . $code] ?? ''));
            if ($account === '') {
                return null;
            }
            $since = (string)($vals[self::SINCE_KEY] ?? '');
            if ($since !== '' && $date < $since) {
                return null;
            }
            $q = $db->prepare(
                "SELECT 1 FROM bank_accounts WHERE account_code = ? AND kind = 'gateway' AND is_active = 1 LIMIT 1"
            );
            $q->execute([$account]);
            return $q->fetchColumn() ? $account : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Hay al menos una cuenta pasarela (Bold) activa: la comisión/retención llega en liquidación. */
    public static function hasActiveGatewayAccount(\PDO $db): bool
    {
        try {
            $q = $db->query(
                "SELECT 1 FROM bank_accounts WHERE kind = 'gateway' AND is_active = 1 LIMIT 1"
            );
            return (bool)$q->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ───────────────────────────── b1: cuentas ─────────────────────────────

    /** @return list<array<string,mixed>> */
    public function accounts(bool $onlyActive = false): array
    {
        $rows = $this->query(
            'SELECT b.*, a.name account_name, a.status account_status
             FROM bank_accounts b
             LEFT JOIN accounting_accounts a ON a.code = b.account_code'
                . ($onlyActive ? ' WHERE b.is_active = 1' : '')
                . " ORDER BY FIELD(b.kind, 'bank', 'wallet', 'gateway'), b.id"
        )->fetchAll();
        $mapped = $this->collectionMap();
        $pending = [];
        try {
            foreach ($this->query(
                "SELECT bank_account_id, COUNT(*) cnt, COALESCE(SUM(amount), 0) total
                 FROM bank_statement_lines WHERE status = 'pending' GROUP BY bank_account_id"
            )->fetchAll() as $p) {
                $pending[(int)$p['bank_account_id']] = $p;
            }
        } catch (\Throwable $e) {
            $pending = [];
        }
        foreach ($rows as &$r) {
            $r['balance'] = $this->bookBalance((string)$r['account_code']);
            $r['kind_label'] = self::KINDS[$r['kind']] ?? $r['kind'];
            $r['methods'] = array_keys(array_filter($mapped, static fn ($id) => $id === (int)$r['id']));
            $r['last_statement'] = $this->query(
                'SELECT MAX(period_to) FROM bank_statements WHERE bank_account_id = ?',
                [(int)$r['id']]
            )->fetchColumn() ?: null;
            $r['pending_count'] = (int)($pending[(int)$r['id']]['cnt'] ?? 0);
            $r['pending_sum'] = round((float)($pending[(int)$r['id']]['total'] ?? 0), 2);
        }
        unset($r);
        return $rows;
    }

    public function account(int $id): ?array
    {
        $row = $this->query('SELECT * FROM bank_accounts WHERE id = ?', [$id])->fetch();
        return $row ?: null;
    }

    public function accountByCode(string $code): ?array
    {
        $row = $this->query('SELECT * FROM bank_accounts WHERE account_code = ?', [$code])->fetch();
        return $row ?: null;
    }

    public function bookBalance(string $code, ?string $asOf = null): float
    {
        $sql = "SELECT COALESCE(SUM(l.debit - l.credit), 0)
                FROM accounting_lines l
                JOIN accounting_accounts a ON a.id = l.account_id
                JOIN accounting_entries e ON e.id = l.entry_id
                WHERE a.code = ? AND e.status IN ('posted','reversed')";
        $params = [$code];
        if ($asOf !== null) {
            $sql .= ' AND e.entry_date <= ?';
            $params[] = $asOf;
        }
        return round((float)$this->query($sql, $params)->fetchColumn(), 2);
    }

    public function suggestCode(string $kind): string
    {
        $prefix = $kind === 'gateway' ? '138095' : '111005';
        $codes = $this->query(
            "SELECT code FROM accounting_accounts WHERE code LIKE ? AND LENGTH(code) = 8",
            [$prefix . '__']
        )->fetchAll(\PDO::FETCH_COLUMN);
        $max = 0;
        foreach ($codes as $c) {
            $max = max($max, (int)substr((string)$c, 6));
        }
        return $prefix . str_pad((string)min(99, $max + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Alta o edición. En alta crea la subcuenta contable (activo, naturaleza débito) si no existe.
     */
    public function saveAccount(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $kind = (string)($data['kind'] ?? 'bank');
        if ($name === '') {
            throw new \InvalidArgumentException('Indique el nombre de la cuenta');
        }
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Tipo de cuenta inválido');
        }
        $bankName = trim((string)($data['bank_name'] ?? '')) ?: null;
        $number = trim((string)($data['account_number'] ?? '')) ?: null;
        $notes = trim((string)($data['notes'] ?? '')) ?: null;
        $active = !empty($data['is_active']) ? 1 : 0;
        $settleId = (int)($data['settle_bank_account_id'] ?? 0) ?: null;
        $reconcileFrom = trim((string)($data['reconcile_from'] ?? ''));
        $reconcileFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconcileFrom) ? $reconcileFrom : null;
        $provider = $kind === 'gateway' ? (trim((string)($data['provider'] ?? 'bold')) ?: 'bold') : null;
        if ($kind !== 'gateway') {
            $settleId = null;
        } elseif ($settleId !== null) {
            $settle = $this->account($settleId);
            if (!$settle || $settle['kind'] === 'gateway') {
                throw new \InvalidArgumentException('La cuenta de depósito debe ser un banco o billetera');
            }
        }

        if ($id > 0) {
            $existing = $this->account($id);
            if (!$existing) {
                throw new \InvalidArgumentException('Cuenta bancaria no encontrada');
            }
            if (!$active && in_array($id, $this->collectionMap(), true)) {
                throw new \InvalidArgumentException(
                    'La cuenta recibe cobros de ventas (Medios de pago). Reasigne esos medios antes de desactivarla.'
                );
            }
            if ($kind !== $existing['kind'] && in_array($id, $this->collectionMap(), true)) {
                throw new \InvalidArgumentException('No cambie el tipo de una cuenta que recibe cobros; reasigne los medios primero.');
            }
            $this->query(
                'UPDATE bank_accounts SET name=?, kind=?, bank_name=?, account_number=?, provider=?,
                    settle_bank_account_id=?, reconcile_from=?, is_active=?, notes=? WHERE id=?',
                [$name, $kind, $bankName, $number, $provider, $settleId, $reconcileFrom, $active, $notes, $id]
            );
            $this->query(
                "UPDATE accounting_accounts SET name = ? WHERE code = ? AND is_system = 0",
                [$this->cut($name, 180), (string)$existing['account_code']]
            );
            return $id;
        }

        $code = preg_replace('/\D/', '', (string)($data['account_code'] ?? '')) ?: $this->suggestCode($kind);
        if (strlen($code) < 6 || strlen($code) > 12) {
            throw new \InvalidArgumentException('El código contable debe tener entre 6 y 12 dígitos');
        }
        $expected = $kind === 'gateway' ? '13' : '11';
        if (!str_starts_with($code, $expected)) {
            throw new \InvalidArgumentException(
                $kind === 'gateway'
                    ? 'Una pasarela por liquidar es una cuenta por cobrar: use un código 13 (sugerido ' . $this->suggestCode('gateway') . ').'
                    : 'Un banco o billetera es disponible: use un código 11 (sugerido ' . $this->suggestCode($kind) . ').'
            );
        }
        if ($this->accountByCode($code)) {
            throw new \InvalidArgumentException('Ya hay una cuenta bancaria con el código ' . $code);
        }
        $acc = $this->query('SELECT * FROM accounting_accounts WHERE code = ? LIMIT 1', [$code])->fetch();
        if ($acc) {
            if ($acc['account_type'] !== 'asset' || $acc['status'] !== 'active' || (int)$acc['accepts_entries'] !== 1) {
                throw new \InvalidArgumentException(
                    'La cuenta contable ' . $code . ' existe pero no es un activo activo que reciba movimientos.'
                );
            }
        } else {
            $this->query(
                "INSERT INTO accounting_accounts (code, name, account_type, nature, accepts_entries, status)
                 VALUES (?, ?, 'asset', 'debit', 1, 'active')",
                [$code, $this->cut($name, 180)]
            );
        }
        $this->query(
            'INSERT INTO bank_accounts (name, kind, bank_name, account_number, account_code, provider,
                settle_bank_account_id, reconcile_from, is_active, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$name, $kind, $bankName, $number, $code, $provider, $settleId, $reconcileFrom, $active, $notes]
        );
        return (int)$this->db->lastInsertId();
    }

    /** Medios de pago que pueden asignarse (todos menos efectivo y mixto). @return array<string,string> */
    public function methodOptions(): array
    {
        $out = [];
        foreach (PaymentMethodCatalog::all() as $code => $meta) {
            if (in_array($code, ['cash', 'mixed'], true)) {
                continue;
            }
            $out[$code] = $meta['label'];
        }
        try {
            foreach ((new CustomOptionService($this->db))->custom(CustomOptionService::GROUP_PAYMENT_METHOD) as $opt) {
                $code = (string)($opt['value'] ?? '');
                if ($code !== '' && !isset($out[$code]) && !PaymentMethodCatalog::affectsCash($code)) {
                    $out[$code] = (string)$opt['label'];
                }
            }
        } catch (\Throwable $e) {
            // Sin medios personalizados.
        }
        return $out;
    }

    /** @return array<string,int|null> medio => id de bank_accounts (null = regla general) */
    public function collectionMap(): array
    {
        $rows = $this->query(
            'SELECT s.setting_key, b.id FROM accounting_settings s
             LEFT JOIN bank_accounts b ON b.account_code = s.setting_value
             WHERE s.setting_key LIKE ?',
            [self::COLLECTION_PREFIX . '%']
        )->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $method = substr((string)$r['setting_key'], strlen(self::COLLECTION_PREFIX));
            $map[$method] = $r['id'] !== null ? (int)$r['id'] : null;
        }
        return $map;
    }

    public function collectionSince(): string
    {
        return $this->setting(self::SINCE_KEY, '');
    }

    /**
     * @param array<string,int|string> $map medio => id de cuenta (0 / '' = regla general)
     */
    public function saveCollectionMap(array $map, string $since): void
    {
        $options = $this->methodOptions();
        $since = preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : '';
        $any = false;
        foreach ($options as $method => $label) {
            $accountId = (int)($map[$method] ?? 0);
            $key = self::COLLECTION_PREFIX . $method;
            if ($accountId <= 0) {
                $this->query('DELETE FROM accounting_settings WHERE setting_key = ?', [$key]);
                continue;
            }
            $acc = $this->account($accountId);
            if (!$acc || (int)$acc['is_active'] !== 1) {
                throw new \InvalidArgumentException('Cuenta inválida o inactiva para ' . $label);
            }
            $this->saveSetting($key, (string)$acc['account_code']);
            $any = true;
        }
        if ($any) {
            $this->saveSetting(self::SINCE_KEY, $since !== '' ? $since : ($this->collectionSince() ?: date('Y-m-d')));
        }
    }

    /** Medios asignados a una pasarela por liquidar. @return list<string> */
    public function gatewayMethods(int $gatewayAccountId): array
    {
        return array_keys(array_filter($this->collectionMap(), static fn ($id) => $id === $gatewayAccountId));
    }

    // ───────────────────────────── b2: extractos ─────────────────────────────

    /**
     * Importa un extracto. $overrides: col_date, col_desc, col_ref, col_amount, col_debit, col_credit,
     * col_balance (letras), first_row (número de fila donde empiezan los datos) e invert_sign.
     *
     * @return array<string,mixed>
     */
    public function importStatement(int $bankAccountId, string $path, string $fileName, array $overrides = []): array
    {
        $account = $this->account($bankAccountId);
        if (!$account || $account['kind'] === 'gateway') {
            throw new \InvalidArgumentException('Seleccione un banco o billetera para el extracto');
        }
        $matrix = SpreadsheetMatrixReader::read($path, $fileName);
        [$map, $start, $labels] = $this->resolveStatementColumns($matrix, $overrides);
        $invert = !empty($overrides['invert_sign']);

        $rows = [];
        $occurrence = [];
        $skipped = 0;
        for ($i = $start, $n = count($matrix); $i < $n; $i++) {
            $r = $matrix[$i];
            $date = SpreadsheetMatrixReader::parseDate((string)($r[$map['date']] ?? ''));
            if ($date === null) {
                $skipped++;
                continue;
            }
            $amount = null;
            if (isset($map['amount'])) {
                $amount = SpreadsheetMatrixReader::parseAmount((string)($r[$map['amount']] ?? ''));
                if ($amount !== null && isset($map['type'])) {
                    $t = SpreadsheetMatrixReader::normalize((string)($r[$map['type']] ?? ''));
                    if ($t !== '' && preg_match('/^(d|db|deb|debito|cargo|retiro|egreso|salida|nd)\b/', $t)) {
                        $amount = -abs($amount);
                    } elseif ($t !== '' && preg_match('/^(c|cr|cred|credito|abono|deposito|ingreso|entrada|nc)\b/', $t)) {
                        $amount = abs($amount);
                    }
                }
            }
            if ($amount === null && (isset($map['debit']) || isset($map['credit']))) {
                $deb = isset($map['debit']) ? SpreadsheetMatrixReader::parseAmount((string)($r[$map['debit']] ?? '')) : null;
                $cre = isset($map['credit']) ? SpreadsheetMatrixReader::parseAmount((string)($r[$map['credit']] ?? '')) : null;
                if ($deb !== null || $cre !== null) {
                    $amount = round(abs((float)$cre) - abs((float)$deb), 2);
                }
            }
            if ($amount === null || abs($amount) < 0.005) {
                $skipped++;
                continue;
            }
            if ($invert) {
                $amount = -$amount;
            }
            $desc = isset($map['description']) ? trim((string)($r[$map['description']] ?? '')) : '';
            $ref = isset($map['reference']) ? trim((string)($r[$map['reference']] ?? '')) : '';
            $balance = isset($map['balance']) ? SpreadsheetMatrixReader::parseAmount((string)($r[$map['balance']] ?? '')) : null;
            $desc = $this->cut($desc, 255);
            $ref = $this->cut($ref, 80);
            $key = $date . '|' . number_format($amount, 2, '.', '') . '|' . SpreadsheetMatrixReader::normalize($desc)
                . '|' . $ref . '|' . ($balance !== null ? number_format($balance, 2, '.', '') : '');
            $occurrence[$key] = ($occurrence[$key] ?? 0) + 1;
            $rows[] = [
                'date' => $date, 'amount' => $amount, 'desc' => $desc, 'ref' => $ref, 'balance' => $balance,
                'hash' => sha1($key . '#' . $occurrence[$key]), 'no' => $i + 1,
            ];
        }
        if ($rows === []) {
            throw new \RuntimeException(
                'No se encontraron movimientos con fecha y valor. Revise las columnas (opciones avanzadas del formulario).'
            );
        }

        $dates = array_column($rows, 'date');
        $from = min($dates);
        $to = max($dates);
        $descending = $rows[0]['date'] > end($rows)['date'];
        $closing = null;
        $opening = null;
        $balances = array_values(array_filter($rows, static fn ($r) => $r['balance'] !== null));
        if ($balances) {
            $last = $descending ? $balances[0] : end($balances);
            $closing = (float)$last['balance'];
            $opening = round($closing - array_sum(array_column($rows, 'amount')), 2);
        }

        // Continuidad con el extracto anterior: huecos, solapes o salto de saldo.
        $continuity = '';
        try {
            $prev = $this->query(
                'SELECT period_from, period_to, closing_balance FROM bank_statements
                 WHERE bank_account_id = ? ORDER BY period_to DESC, id DESC LIMIT 1',
                [$bankAccountId]
            )->fetch();
        } catch (\Throwable $e) {
            $prev = null;
        }
        if ($prev) {
            $money = static fn (float $v) => number_format($v, 2, ',', '.');
            if ($opening !== null && $prev['closing_balance'] !== null
                && abs($opening - (float)$prev['closing_balance']) > 0.01
            ) {
                $continuity = 'El saldo inicial de este extracto (' . $money($opening)
                    . ') no coincide con el cierre del extracto anterior (' . $money((float)$prev['closing_balance'])
                    . '): pueden faltar movimientos entre periodos.';
            } elseif ($from > date('Y-m-d', strtotime((string)$prev['period_to'] . ' +1 day'))) {
                $continuity = 'Hay un hueco de fechas: el extracto anterior llega hasta '
                    . date('d/m/Y', strtotime((string)$prev['period_to'])) . ' y este empieza el '
                    . date('d/m/Y', strtotime($from)) . '.';
            } elseif ($from <= (string)$prev['period_to'] && $to >= (string)$prev['period_from']) {
                $continuity = 'El periodo se solapa con el extracto anterior (' . date('d/m/Y', strtotime((string)$prev['period_from']))
                    . ' – ' . date('d/m/Y', strtotime((string)$prev['period_to'])) . '); los movimientos repetidos se omiten.';
            }
        }

        $this->db->beginTransaction();
        try {
            $this->query(
                'INSERT INTO bank_statements (bank_account_id, file_name, period_from, period_to, opening_balance,
                    closing_balance, created_by) VALUES (?,?,?,?,?,?,?)',
                [$bankAccountId, $this->cut($fileName, 190), $from, $to, $opening, $closing, $_SESSION['tenant_user_id'] ?? null]
            );
            $statementId = (int)$this->db->lastInsertId();
            $ins = $this->db->prepare(
                'INSERT IGNORE INTO bank_statement_lines
                    (statement_id, bank_account_id, line_no, line_date, description, reference, amount, balance,
                     line_hash, suggestion)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $inserted = 0;
            $credits = 0.0;
            $debits = 0.0;
            foreach ($rows as $r) {
                $ins->execute([
                    $statementId, $bankAccountId, $r['no'], $r['date'], $r['desc'] ?: null, $r['ref'] ?: null,
                    $r['amount'], $r['balance'], $r['hash'], self::suggestKind($r['desc'], $r['amount']),
                ]);
                if ($ins->rowCount() > 0) {
                    $inserted++;
                    if ($r['amount'] > 0) {
                        $credits += $r['amount'];
                    } else {
                        $debits += -$r['amount'];
                    }
                }
            }
            if ($inserted === 0) {
                $this->db->rollBack();
                throw new \RuntimeException('Todos los movimientos de este archivo ya estaban importados.');
            }
            $this->query(
                'UPDATE bank_statements SET lines_count=?, credits=?, debits=? WHERE id=?',
                [$inserted, round($credits, 2), round($debits, 2), $statementId]
            );
            if (empty($account['reconcile_from'])) {
                $this->query('UPDATE bank_accounts SET reconcile_from = ? WHERE id = ?', [$from, $bankAccountId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $matched = $this->autoMatch($statementId);
        return [
            'statement_id' => $statementId,
            'read' => count($rows),
            'inserted' => $inserted,
            'duplicates' => count($rows) - $inserted,
            'skipped' => $skipped,
            'matched' => $matched,
            'columns' => $labels,
            'continuity' => $continuity,
        ];
    }

    /**
     * @param list<list<string>> $matrix
     * @return array{0:array<string,int>,1:int,2:array<string,string>}
     */
    private function resolveStatementColumns(array $matrix, array $overrides): array
    {
        $detected = SpreadsheetMatrixReader::detectHeader($matrix, self::STATEMENT_SYNONYMS, ['date']);
        $map = [];
        $start = 0;
        $labels = [];
        if ($detected && (isset($detected['map']['amount']) || isset($detected['map']['debit']) || isset($detected['map']['credit']))) {
            $map = $detected['map'];
            $start = $detected['header'] + 1;
            $labels = $detected['labels'];
        } else {
            $guess = $this->guessHeaderless($matrix);
            if ($guess) {
                $map = $guess;
                foreach ($guess as $f => $c) {
                    $labels[$f] = 'Columna ' . SpreadsheetMatrixReader::columnLetter($c);
                }
            }
        }
        $fields = [
            'col_date' => 'date', 'col_desc' => 'description', 'col_ref' => 'reference', 'col_amount' => 'amount',
            'col_debit' => 'debit', 'col_credit' => 'credit', 'col_balance' => 'balance',
        ];
        $manualAmount = false;
        foreach ($fields as $input => $field) {
            $idx = SpreadsheetMatrixReader::columnIndex((string)($overrides[$input] ?? ''));
            if ($idx >= 0) {
                $map[$field] = $idx;
                $labels[$field] = 'Columna ' . SpreadsheetMatrixReader::columnLetter($idx);
                if (in_array($field, ['amount', 'debit', 'credit'], true)) {
                    $manualAmount = true;
                }
            }
        }
        if ($manualAmount) {
            foreach (['amount', 'debit', 'credit'] as $f) {
                if (SpreadsheetMatrixReader::columnIndex((string)($overrides['col_' . $f] ?? '')) < 0) {
                    unset($map[$f], $labels[$f]);
                }
            }
            unset($map['type']);
        }
        $firstRow = (int)($overrides['first_row'] ?? 0);
        if ($firstRow > 0) {
            $start = $firstRow - 1;
        }
        if (!isset($map['date']) || !(isset($map['amount']) || isset($map['debit']) || isset($map['credit']))) {
            throw new \RuntimeException(
                'No se reconocieron las columnas de fecha y valor. Abra «Opciones avanzadas» e indique las letras de columna. '
                . 'Primera fila leída: ' . $this->cut(implode(' | ', array_slice($matrix[0] ?? [], 0, 10)), 200)
            );
        }
        return [$map, $start, $labels];
    }

    /**
     * Extractos sin encabezados (p. ej. archivo plano de Bancolombia: cuenta, oficina, fecha AAAAMMDD,
     * valor con signo, código, descripción).
     *
     * @param list<list<string>> $matrix
     * @return array<string,int>|null
     */
    private function guessHeaderless(array $matrix): ?array
    {
        $sample = array_slice($matrix, 0, 60);
        $cols = 0;
        foreach ($sample as $r) {
            $cols = max($cols, count($r));
        }
        if ($cols < 2 || count($sample) < 2) {
            return null;
        }
        $stats = [];
        for ($c = 0; $c < $cols; $c++) {
            $dates = $amounts = $decimals = $negatives = $text = $filled = 0;
            $distinct = [];
            foreach ($sample as $r) {
                $v = trim((string)($r[$c] ?? ''));
                if ($v === '') {
                    continue;
                }
                $filled++;
                $distinct[$v] = true;
                if (SpreadsheetMatrixReader::parseDate($v) !== null) {
                    $dates++;
                }
                $a = SpreadsheetMatrixReader::parseAmount($v);
                if ($a !== null && !preg_match('/^\d{9,}$/', $v)) {
                    $amounts++;
                    if (preg_match('/[.,]\d{1,2}$/', $v)) {
                        $decimals++;
                    }
                    if ($a < 0) {
                        $negatives++;
                    }
                }
                if (preg_match('/[a-zA-Z]{3,}/', $v)) {
                    $text++;
                }
            }
            $stats[$c] = compact('dates', 'amounts', 'decimals', 'negatives', 'text', 'filled') + ['distinct' => count($distinct)];
        }
        $rows = count($sample);
        $dateCol = null;
        $best = 0;
        foreach ($stats as $c => $s) {
            if ($s['dates'] >= $rows * 0.8 && $s['dates'] > $best) {
                $best = $s['dates'];
                $dateCol = $c;
            }
        }
        if ($dateCol === null) {
            return null;
        }
        $amountCol = null;
        $bestScore = 0.0;
        foreach ($stats as $c => $s) {
            if ($c === $dateCol || $s['amounts'] < $rows * 0.8 || $s['distinct'] < 2) {
                continue;
            }
            $score = $s['decimals'] * 2 + $s['negatives'] * 2 + $s['distinct'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $amountCol = $c;
            }
        }
        if ($amountCol === null) {
            return null;
        }
        $map = ['date' => $dateCol, 'amount' => $amountCol];
        $descCol = null;
        $bestText = 0;
        foreach ($stats as $c => $s) {
            if ($c !== $dateCol && $c !== $amountCol && $s['text'] > $bestText) {
                $bestText = $s['text'];
                $descCol = $c;
            }
        }
        if ($descCol !== null) {
            $map['description'] = $descCol;
        }
        return $map;
    }

    /** Sugerencia de registro por la descripción del banco. */
    public static function suggestKind(string $description, float $amount): ?string
    {
        $d = strtoupper(SpreadsheetMatrixReader::normalize($description));
        if ($d === '') {
            return null;
        }
        if ($amount < 0) {
            if (preg_match('/4 ?X ?1000|4X MIL|GMF|GRAVAMEN|IMPTO GOBIERNO|IMPUESTO GOBIERNO|IMP GOBIERNO|IMPTO 4/', $d)) {
                return 'gmf';
            }
            if (preg_match('/RETEFUENTE|RETENCION|RETE FTE|RTE FTE/', $d)) {
                return 'withholding';
            }
            if (preg_match('/CUOTA (DE )?MANEJO|COMISION|COMIS |IVA CUOTA|IVA COMIS|COBRO SERVICIO|MANEJO TARJETA|CHEQUERA|EXTRACTO/', $d)) {
                return 'bank_fee';
            }
        } elseif (preg_match('/INTERES|RENDIMIENTO/', $d)) {
            return 'interest';
        }
        return null;
    }

    /**
     * Cruce automático: misma cuenta, mismo valor y fecha a ±MATCH_WINDOW_DAYS; la más cercana gana.
     */
    /** Vuelve a pendiente los movimientos cruzados con asientos que luego se revirtieron o anularon. */
    public function releaseStaleMatches(int $bankAccountId): int
    {
        return $this->query(
            "UPDATE bank_statement_lines bl
             LEFT JOIN accounting_entries e ON e.id = bl.entry_id
             SET bl.status = 'pending', bl.accounting_line_id = NULL, bl.entry_id = NULL, bl.matched_at = NULL, bl.matched_by = NULL
             WHERE bl.bank_account_id = ? AND bl.status = 'matched' AND (e.id IS NULL OR e.status <> 'posted')",
            [$bankAccountId]
        )->rowCount();
    }

    public function autoMatch(int $statementId): int
    {
        $statement = $this->statementRow($statementId);
        if (!$statement) {
            return 0;
        }
        $account = $this->account((int)$statement['bank_account_id']);
        if (!$account) {
            return 0;
        }
        $this->releaseStaleMatches((int)$account['id']);
        $lines = $this->query(
            "SELECT id, line_date, amount, reference FROM bank_statement_lines
             WHERE statement_id = ? AND status = 'pending' ORDER BY line_date, id",
            [$statementId]
        )->fetchAll();
        $used = [];
        $matched = 0;
        foreach ($lines as $line) {
            $cands = $this->bookCandidates($account, (string)$line['line_date'], (float)$line['amount'], self::MATCH_WINDOW_DAYS, 10, (string)($line['reference'] ?? ''));
            foreach ($cands as $c) {
                if (isset($used[(int)$c['line_id']])) {
                    continue;
                }
                $used[(int)$c['line_id']] = true;
                $this->query(
                    "UPDATE bank_statement_lines SET status='matched', accounting_line_id=?, entry_id=?,
                        matched_at=NOW(), matched_by=NULL WHERE id=? AND status='pending'",
                    [(int)$c['line_id'], (int)$c['entry_id'], (int)$line['id']]
                );
                $matched++;
                break;
            }
        }
        return $matched;
    }

    /**
     * Líneas del libro de la cuenta aún sin cruzar con el mismo valor (débito = entrada al banco).
     * Si el extracto trae referencia, los asientos que la mencionan (número, descripción o
     * tercero) van primero: evita falsos cruces cuando hay varios valores iguales.
     *
     * @return list<array<string,mixed>>
     */
    public function bookCandidates(array $account, string $date, float $amount, int $windowDays, int $limit = 8, string $reference = ''): array
    {
        $field = $amount > 0 ? 'l.debit' : 'l.credit';
        $other = $amount > 0 ? 'l.credit' : 'l.debit';
        $reference = trim($reference);
        $refSql = '0 AS ref_match';
        $refOrder = '';
        $refParams = [];
        if (strlen($reference) >= 4) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $reference) . '%';
            $refSql = "(CASE WHEN e.entry_number = ? OR e.description LIKE ? OR l.description LIKE ? OR l.third_party_name LIKE ? THEN 1 ELSE 0 END) AS ref_match";
            $refOrder = 'ref_match DESC, ';
            $refParams = [$reference, $like, $like, $like];
        }
        return $this->query(
            "SELECT l.id line_id, e.id entry_id, e.entry_number, e.entry_date, e.description entry_description,
                    e.source_type, l.description, l.third_party_name, ({$field}) amount,
                    ABS(DATEDIFF(e.entry_date, ?)) days_off, {$refSql}
             FROM accounting_lines l
             JOIN accounting_accounts a ON a.id = l.account_id
             JOIN accounting_entries e ON e.id = l.entry_id
             WHERE a.code = ?
               AND e.status = 'posted' AND e.reversal_of_id IS NULL
               AND ABS({$field} - ?) < 0.005 AND {$other} = 0
               AND e.entry_date BETWEEN DATE_SUB(?, INTERVAL {$windowDays} DAY) AND DATE_ADD(?, INTERVAL {$windowDays} DAY)
               AND NOT EXISTS (SELECT 1 FROM bank_statement_lines bl
                               WHERE bl.accounting_line_id = l.id AND bl.status = 'matched')
               AND NOT EXISTS (SELECT 1 FROM bank_postings bp
                               WHERE bp.entry_id = e.id AND bp.bank_account_id = ?)
             ORDER BY {$refOrder}days_off, e.entry_date, l.id
             LIMIT " . max(1, min(50, $limit)),
            array_merge([$date], $refParams, [(string)$account['account_code'], abs($amount), $date, $date, (int)$account['id']])
        )->fetchAll();
    }

    public function matchLine(int $lineId, int $accountingLineId): void
    {
        $line = $this->lineRow($lineId);
        if (!$line || $line['status'] !== 'pending') {
            throw new \RuntimeException('El movimiento ya no está pendiente');
        }
        $account = $this->account((int)$line['bank_account_id']);
        $cands = $this->bookCandidates($account, (string)$line['line_date'], (float)$line['amount'], 45, 50, (string)($line['reference'] ?? ''));
        foreach ($cands as $c) {
            if ((int)$c['line_id'] === $accountingLineId) {
                $this->query(
                    "UPDATE bank_statement_lines SET status='matched', accounting_line_id=?, entry_id=?,
                        matched_at=NOW(), matched_by=? WHERE id=?",
                    [$accountingLineId, (int)$c['entry_id'], $_SESSION['tenant_user_id'] ?? null, $lineId]
                );
                return;
            }
        }
        throw new \RuntimeException('Ese asiento ya no está disponible para cruzar (valor, cuenta o fecha no coinciden)');
    }

    public function unmatchLine(int $lineId): void
    {
        $this->query(
            "UPDATE bank_statement_lines SET status='pending', accounting_line_id=NULL, entry_id=NULL,
                matched_at=NULL, matched_by=NULL WHERE id=? AND status='matched'",
            [$lineId]
        );
    }

    public function setIgnored(int $lineId, bool $ignore, string $note = ''): void
    {
        if ($ignore) {
            $this->query(
                "UPDATE bank_statement_lines SET status='ignored', notes=? WHERE id=? AND status='pending'",
                [$this->cut($note, 255) ?: null, $lineId]
            );
        } else {
            $this->query("UPDATE bank_statement_lines SET status='pending' WHERE id=? AND status='ignored'", [$lineId]);
        }
    }

    public function counterAccountFor(string $kind, ?string $counterCode): string
    {
        $meta = self::POSTING_KINDS[$kind] ?? null;
        if ($meta === null) {
            throw new \InvalidArgumentException('Tipo de registro inválido');
        }
        if ($meta['setting'] !== null) {
            return $this->setting($meta['setting'], (string)$meta['default']);
        }
        $code = trim((string)$counterCode);
        if ($code === '') {
            throw new \InvalidArgumentException(
                $kind === 'transfer' ? 'Seleccione la otra cuenta bancaria' : 'Seleccione la cuenta contable de contrapartida'
            );
        }
        return $code;
    }

    /**
     * Registra movimientos del extracto que no están en libros. Agrupa por mes: un asiento (y un gasto
     * si es cargo bancario) por grupo.
     *
     * @param list<int> $lineIds
     * @return list<int> ids de bank_postings
     */
    public function registerLines(array $lineIds, string $kind, ?string $counterCode = null, string $note = ''): array
    {
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds))));
        if (!$lineIds) {
            throw new \InvalidArgumentException('Seleccione al menos un movimiento');
        }
        $counter = $this->counterAccountFor($kind, $counterCode);
        $ph = implode(',', array_fill(0, count($lineIds), '?'));
        $lines = $this->query(
            "SELECT * FROM bank_statement_lines WHERE id IN ({$ph}) AND status = 'pending' ORDER BY line_date, id",
            $lineIds
        )->fetchAll();
        if (!$lines) {
            throw new \RuntimeException('Los movimientos seleccionados ya no están pendientes');
        }
        $bankId = (int)$lines[0]['bank_account_id'];
        foreach ($lines as $l) {
            if ((int)$l['bank_account_id'] !== $bankId) {
                throw new \InvalidArgumentException('Seleccione movimientos de una sola cuenta');
            }
        }
        $account = $this->account($bankId);
        if (!$account) {
            throw new \RuntimeException('Cuenta bancaria no encontrada');
        }
        if ($counter === (string)$account['account_code']) {
            throw new \InvalidArgumentException('La contrapartida no puede ser la misma cuenta del extracto');
        }
        $ok = $this->query(
            "SELECT 1 FROM accounting_accounts WHERE code = ? AND status = 'active' AND accepts_entries = 1",
            [$counter]
        )->fetchColumn();
        if (!$ok) {
            throw new \InvalidArgumentException('La cuenta contable ' . $counter . ' no existe o no recibe movimientos');
        }

        $groups = [];
        foreach ($lines as $l) {
            $groups[substr((string)$l['line_date'], 0, 7)][] = $l;
        }
        $accounting = new AccountingService($this->db);
        $meta = self::POSTING_KINDS[$kind];
        $label = $meta['label'];
        if ($kind === 'transfer') {
            $other = $this->accountByCode($counter);
            $label = 'Traslado ' . ($other ? $other['name'] : $counter);
        }
        $ids = [];
        foreach ($groups as $ym => $group) {
            $sum = round(array_sum(array_map(static fn ($l) => (float)$l['amount'], $group)), 2);
            if (abs($sum) < 0.005) {
                continue;
            }
            $date = max(array_column($group, 'line_date'));
            $desc = $label . ' · ' . $account['name'] . ' · '
                . (count($group) === 1
                    ? ($group[0]['description'] ?: date('d/m/Y', strtotime($date)))
                    : count($group) . ' movimientos ' . $ym);
            if ($note !== '') {
                $desc .= ' · ' . $note;
            }
            $desc = $this->cut($desc, 250);
            $abs = abs($sum);

            $this->db->beginTransaction();
            try {
                $this->query(
                    'INSERT INTO bank_postings (bank_account_id, statement_id, kind, counter_account, posting_date, amount,
                        description, lines_count, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$bankId, (int)$group[0]['statement_id'], $kind, $counter, $date, $sum, $desc, count($group),
                        $_SESSION['tenant_user_id'] ?? null]
                );
                $postingId = (int)$this->db->lastInsertId();
                $bankLine = ['account_code' => (string)$account['account_code'], 'debit' => $sum > 0 ? $abs : 0,
                    'credit' => $sum < 0 ? $abs : 0, 'description' => $desc];
                $counterLine = ['account_code' => $counter, 'debit' => $sum < 0 ? $abs : 0,
                    'credit' => $sum > 0 ? $abs : 0, 'description' => $label];
                $entryId = $accounting->postEntry($date, $desc, [$counterLine, $bankLine], 'bank_posting', $postingId, 'created');
                $entryNumber = (string)$this->query('SELECT entry_number FROM accounting_entries WHERE id=?', [$entryId])
                    ->fetchColumn();
                $expenseId = null;
                if ($meta['expense'] !== null && $sum < 0) {
                    $this->query(
                        "INSERT INTO expenses (description, amount, category, expense_date, payment_method, notes, user_id)
                         VALUES (?,?,?,?,?,?,?)",
                        [$desc, $abs, $meta['expense'], $date, 'transfer',
                            'bank_posting:' . $postingId . ' · asiento contable #' . $entryNumber,
                            $_SESSION['tenant_user_id'] ?? null]
                    );
                    $expenseId = (int)$this->db->lastInsertId();
                }
                $this->query('UPDATE bank_postings SET entry_id=?, expense_id=? WHERE id=?', [$entryId, $expenseId, $postingId]);
                $gIds = array_map(static fn ($l) => (int)$l['id'], $group);
                $this->query(
                    "UPDATE bank_statement_lines SET status='posted', posting_id=?, entry_id=?, matched_at=NOW(), matched_by=?
                     WHERE id IN (" . implode(',', array_fill(0, count($gIds), '?')) . ") AND status='pending'",
                    array_merge([$postingId, $entryId, $_SESSION['tenant_user_id'] ?? null], $gIds)
                );
                $this->db->commit();
                $ids[] = $postingId;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }
        return $ids;
    }

    /** Registra en bloque las sugerencias (4x1000, comisiones, intereses, retenciones) de un extracto. */
    public function registerSuggested(int $statementId): array
    {
        $rows = $this->query(
            "SELECT id, suggestion FROM bank_statement_lines
             WHERE statement_id = ? AND status = 'pending' AND suggestion IS NOT NULL",
            [$statementId]
        )->fetchAll();
        $byKind = [];
        foreach ($rows as $r) {
            if (isset(self::POSTING_KINDS[$r['suggestion']]) && self::POSTING_KINDS[$r['suggestion']]['setting'] !== null) {
                $byKind[$r['suggestion']][] = (int)$r['id'];
            }
        }
        $out = ['postings' => 0, 'lines' => 0];
        foreach ($byKind as $kind => $ids) {
            $out['postings'] += count($this->registerLines($ids, $kind));
            $out['lines'] += count($ids);
        }
        return $out;
    }

    public function cancelPosting(int $postingId, string $reason): void
    {
        $reason = trim($reason);
        if (strlen($reason) < 5) {
            throw new \InvalidArgumentException('Indique el motivo de la anulación (mínimo 5 caracteres)');
        }
        $posting = $this->query('SELECT * FROM bank_postings WHERE id = ?', [$postingId])->fetch();
        if (!$posting || $posting['status'] !== 'posted') {
            throw new \RuntimeException('El registro no existe o ya está anulado');
        }
        $accounting = new AccountingService($this->db);
        $this->db->beginTransaction();
        try {
            $accounting->reverseSource('bank_posting', $postingId, 'Registro bancario anulado: ' . $reason);
            if (!empty($posting['expense_id'])) {
                $this->query(
                    "UPDATE expenses SET status = 'cancelled', cancelled_at = NOW()
                     WHERE id = ? AND COALESCE(status, 'active') <> 'cancelled'",
                    [(int)$posting['expense_id']]
                );
            }
            $this->query(
                "UPDATE bank_postings SET status='cancelled', cancel_reason=?, cancelled_at=NOW() WHERE id=?",
                [$this->cut($reason, 255), $postingId]
            );
            $this->query(
                "UPDATE bank_statement_lines SET status='pending', posting_id=NULL, entry_id=NULL, matched_at=NULL,
                    matched_by=NULL WHERE posting_id=?",
                [$postingId]
            );
            if (!empty($posting['entry_id'])) {
                $this->query(
                    "UPDATE bank_statement_lines SET status='pending', accounting_line_id=NULL, entry_id=NULL, matched_at=NULL,
                        matched_by=NULL WHERE entry_id = ? AND status = 'matched'",
                    [(int)$posting['entry_id']]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteStatement(int $statementId): void
    {
        $posted = (int)$this->query(
            "SELECT COUNT(*) FROM bank_statement_lines WHERE statement_id = ? AND status = 'posted'",
            [$statementId]
        )->fetchColumn();
        if ($posted > 0) {
            throw new \RuntimeException(
                'El extracto tiene ' . $posted . ' movimiento(s) registrados en contabilidad. Anule esos registros antes de eliminarlo.'
            );
        }
        $this->db->beginTransaction();
        try {
            $this->query('DELETE FROM bank_statement_lines WHERE statement_id = ?', [$statementId]);
            $this->query('DELETE FROM bank_statements WHERE id = ?', [$statementId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function statements(int $bankAccountId, int $limit = 36): array
    {
        return $this->query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM bank_statement_lines l WHERE l.statement_id = s.id AND l.status = 'pending') pending,
                    (SELECT COUNT(*) FROM bank_statement_lines l WHERE l.statement_id = s.id) total_lines
             FROM bank_statements s WHERE s.bank_account_id = ?
             ORDER BY s.period_to DESC, s.id DESC LIMIT " . max(1, $limit),
            [$bankAccountId]
        )->fetchAll();
    }

    public function statementRow(int $id): ?array
    {
        $row = $this->query('SELECT * FROM bank_statements WHERE id = ?', [$id])->fetch();
        return $row ?: null;
    }

    private function lineRow(int $id): ?array
    {
        $row = $this->query('SELECT * FROM bank_statement_lines WHERE id = ?', [$id])->fetch();
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function statementLines(int $statementId, string $status = ''): array
    {
        $sql = "SELECT l.*, e.entry_number, e.entry_date, e.description entry_description, e.source_type,
                       p.kind posting_kind, p.status posting_status
                FROM bank_statement_lines l
                LEFT JOIN accounting_entries e ON e.id = l.entry_id
                LEFT JOIN bank_postings p ON p.id = l.posting_id
                WHERE l.statement_id = ?";
        $params = [$statementId];
        if (isset(self::LINE_STATUS[$status])) {
            $sql .= ' AND l.status = ?';
            $params[] = $status;
        }
        return $this->query($sql . ' ORDER BY l.line_date, l.line_no, l.id', $params)->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function postings(int $bankAccountId, int $limit = 50): array
    {
        return $this->query(
            "SELECT p.*, e.entry_number FROM bank_postings p
             LEFT JOIN accounting_entries e ON e.id = p.entry_id
             WHERE p.bank_account_id = ? ORDER BY p.posting_date DESC, p.id DESC LIMIT " . max(1, $limit),
            [$bankAccountId]
        )->fetchAll();
    }

    /**
     * Conciliación al cierre del extracto:
     * saldo banco = saldo libros + movimientos del banco sin registrar − partidas del libro que el banco no muestra.
     *
     * @return array<string,mixed>
     */
    public function reconciliation(int $statementId): array
    {
        $st = $this->statementRow($statementId);
        if (!$st) {
            throw new \RuntimeException('Extracto no encontrado');
        }
        $account = $this->account((int)$st['bank_account_id']);
        $this->releaseStaleMatches((int)$account['id']);
        $code = (string)$account['account_code'];
        $to = (string)$st['period_to'];
        $from = (string)($account['reconcile_from'] ?: $st['period_from']);

        $book = $this->bookBalance($code, $to);
        $pending = (float)$this->query(
            "SELECT COALESCE(SUM(amount), 0) FROM bank_statement_lines
             WHERE bank_account_id = ? AND status = 'pending' AND line_date <= ?",
            [(int)$account['id'], $to]
        )->fetchColumn();
        $outstandingSql = "FROM accounting_lines l
             JOIN accounting_accounts a ON a.id = l.account_id
             JOIN accounting_entries e ON e.id = l.entry_id
             WHERE a.code = ? AND e.status = 'posted' AND e.reversal_of_id IS NULL
               AND e.entry_date BETWEEN ? AND ?
               AND NOT EXISTS (SELECT 1 FROM bank_statement_lines bl WHERE bl.accounting_line_id = l.id AND bl.status = 'matched')
               AND NOT EXISTS (SELECT 1 FROM bank_postings bp WHERE bp.entry_id = e.id AND bp.bank_account_id = ?)";
        $params = [$code, $from, $to, (int)$account['id']];
        $outstandingSum = (float)$this->query('SELECT COALESCE(SUM(l.debit - l.credit), 0) ' . $outstandingSql, $params)
            ->fetchColumn();
        $outstanding = $this->query(
            'SELECT l.id line_id, e.id entry_id, e.entry_number, e.entry_date, e.description, e.source_type,
                    l.debit, l.credit, l.third_party_name ' . $outstandingSql . ' ORDER BY e.entry_date, e.id LIMIT 300',
            $params
        )->fetchAll();

        $bank = $st['closing_balance'] !== null ? (float)$st['closing_balance'] : null;
        $expected = round($book + $pending - $outstandingSum, 2);
        return [
            'statement' => $st,
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'bank_balance' => $bank,
            'book_balance' => $book,
            'pending_sum' => round($pending, 2),
            'outstanding_sum' => round($outstandingSum, 2),
            'outstanding' => $outstanding,
            'expected_bank' => $expected,
            'difference' => $bank !== null ? round($bank - $expected, 2) : null,
            'counts' => $this->query(
                'SELECT status, COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM bank_statement_lines
                 WHERE statement_id = ? GROUP BY status',
                [$statementId]
            )->fetchAll(\PDO::FETCH_UNIQUE),
        ];
    }

    private function cut(string $text, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len);
    }
}
