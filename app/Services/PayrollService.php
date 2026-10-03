<?php

namespace SoftNova\Services;

/**
 * Nómina Colombia: empleados, liquidación mensual, primas, cesantías,
 * incapacidad, parafiscales/ARL y enlace contable detallado.
 */
class PayrollService
{
    private bool $paramsDirty = false;
    /** @var array<string, true>|null */
    private ?array $employeeColsCache = null;

    public function __construct(private \PDO $db)
    {
        TenantOpsSchema::ensure($db);
        // Nómina depende de accounting_settings / accounting_accounts
        // (si Contabilidad nunca se abrió, params() reventaba al cargar el módulo).
        new AccountingService($db);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function params(): array
    {
        static $cache = [];
        $oid = spl_object_id($this->db);
        if (isset($cache[$oid]) && !$this->paramsDirty) {
            return $cache[$oid];
        }
        $this->paramsDirty = false;

        $defaults = self::PARAM_DEFAULTS + [
            'payroll_expense_account' => '510503',
            'payroll_employer_expense_account' => '510506',
            'payroll_payable_account' => '250505',
            'health_payable_account' => '237005',
            'pension_payable_account' => '238030',
            'arl_payable_account' => '237010',
            'caja_payable_account' => '237006',
            'sena_payable_account' => '237008',
            'icbf_payable_account' => '237007',
            'cesantias_payable_account' => '261005',
            'payroll_commission_account' => '510518',
            'vacation_expense_account' => '510539',
            'vacation_payable_account' => '261015',
        ];

        $keys = array_keys($defaults);
        $stored = $this->query(
            'SELECT setting_key, setting_value FROM accounting_settings WHERE setting_key IN ('
                . implode(',', array_fill(0, count($keys), '?')) . ')',
            $keys
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $missing = array_diff_key($defaults, $stored);
        if ($missing) {
            $insSetting = $this->db->prepare(
                'INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)'
            );
            foreach ($missing as $key => $value) {
                $insSetting->execute([$key, $value]);
            }
        }
        $this->ensurePayrollAccounts();

        $out = [];
        foreach ($defaults as $key => $default) {
            $val = array_key_exists($key, $stored) ? (string)$stored[$key] : $default;
            $out[$key] = str_ends_with($key, '_account') ? $val : (float)$val;
        }
        $cache[$oid] = $out;
        return $out;
    }

    /** Valores legales Colombia 2026 (Ley 2101 jornada 42 h desde jul-2026, Ley 2466 nocturno 19:00 y dominical 90%). */
    public const PARAM_DEFAULTS = [
        'smmlv' => '1750905',
        'transport_aid' => '249095',
        'health_employee_rate' => '4',
        'pension_employee_rate' => '4',
        'health_employer_rate' => '8.5',
        'pension_employer_rate' => '12',
        'arl_employer_rate' => '0.522',
        'caja_employer_rate' => '4',
        'sena_employer_rate' => '2',
        'icbf_employer_rate' => '3',
        'incapacity_rate' => '66.67',
        'cesantias_rate' => '8.33',
        'cesantias_interest_rate' => '12',
        'prima_rate' => '8.33',
        'vacation_rate' => '4.17',
        'payroll_monthly_hours' => '210',
        'max_weekly_hours' => '42',
        'max_daily_hours' => '8',
        'night_start_hour' => '19',
        'night_end_hour' => '6',
        'overtime_day_rate' => '25',
        'overtime_night_rate' => '75',
        'night_surcharge_rate' => '35',
        'dominical_rate' => '90',
        'overtime_dom_day_rate' => '115',
        'overtime_dom_night_rate' => '165',
    ];

    /** Rango permitido por parámetro editable: [mín, máx]. */
    private const PARAM_RANGES = [
        'smmlv' => [1, 100000000],
        'transport_aid' => [0, 10000000],
        'health_employee_rate' => [0, 100],
        'pension_employee_rate' => [0, 100],
        'health_employer_rate' => [0, 100],
        'pension_employer_rate' => [0, 100],
        'arl_employer_rate' => [0, 100],
        'caja_employer_rate' => [0, 100],
        'sena_employer_rate' => [0, 100],
        'icbf_employer_rate' => [0, 100],
        'incapacity_rate' => [0, 100],
        'cesantias_rate' => [0, 100],
        'cesantias_interest_rate' => [0, 100],
        'prima_rate' => [0, 100],
        'vacation_rate' => [0, 100],
        'payroll_monthly_hours' => [1, 400],
        'max_weekly_hours' => [1, 80],
        'max_daily_hours' => [1, 24],
        'night_start_hour' => [0, 23],
        'night_end_hour' => [0, 23],
        'overtime_day_rate' => [0, 400],
        'overtime_night_rate' => [0, 400],
        'night_surcharge_rate' => [0, 400],
        'dominical_rate' => [0, 400],
        'overtime_dom_day_rate' => [0, 400],
        'overtime_dom_night_rate' => [0, 400],
    ];

    /** Porcentajes que por ley llevan decimales (8,5 % · 0,522 % · 66,67 % · 8,33 % · 4,17 %); el resto se guarda entero. */
    public const DECIMAL_RATE_KEYS = [
        'health_employer_rate', 'arl_employer_rate', 'incapacity_rate',
        'cesantias_rate', 'prima_rate', 'vacation_rate',
    ];

    /**
     * Fracción de un % de provisión. 8,33 % y 4,17 % son el redondeo legal de 1/12 y 1/24:
     * se usan exactos para que la prima semestral dé medio salario y no se pague de menos.
     */
    private static function provisionFraction(float $pct): float
    {
        foreach ([100 / 12, 100 / 24] as $exact) {
            if (abs($pct - $exact) < 0.006) {
                return $exact / 100;
            }
        }
        return max(0.0, $pct) / 100;
    }

    /**
     * Guarda parámetros editables validando números y rangos. Campos vacíos conservan el valor actual.
     * @param array<string,mixed> $input
     */
    public function saveParams(array $input): void
    {
        $labels = [
            'smmlv' => 'SMMLV', 'transport_aid' => 'Auxilio de transporte',
            'payroll_monthly_hours' => 'Horas mensuales', 'max_weekly_hours' => 'Jornada máxima semanal',
            'max_daily_hours' => 'Jornada máxima diaria',
            'night_start_hour' => 'Inicio nocturno', 'night_end_hour' => 'Fin nocturno',
            'cesantias_rate' => 'Cesantías', 'cesantias_interest_rate' => 'Intereses sobre cesantías',
            'prima_rate' => 'Prima de servicios', 'vacation_rate' => 'Vacaciones',
        ];
        $clean = [];
        foreach (self::PARAM_RANGES as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = str_replace(',', '.', trim((string)$input[$key]));
            if ($raw === '') {
                continue;
            }
            $name = $labels[$key] ?? $key;
            if (!is_numeric($raw)) {
                throw new \InvalidArgumentException($name . ': valor no numérico');
            }
            $num = (float)$raw;
            if (str_ends_with($key, '_rate') && !in_array($key, self::DECIMAL_RATE_KEYS, true)) {
                $num = round($num);
            }
            if ($num < $min || $num > $max) {
                throw new \InvalidArgumentException($name . ': debe estar entre ' . $min . ' y ' . $max);
            }
            $clean[$key] = (string)$num;
        }
        if (!$clean) {
            return;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $this->db->beginTransaction();
        try {
            foreach ($clean as $k => $v) {
                $stmt->execute([$k, $v]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        $this->clearParamsCache();
    }

    /**
     * Guarda la comisión fija quincenal por empleado y recalcula las liquidaciones en borrador afectadas.
     * @param array<int|string, mixed> $byEmployee employee_id => monto
     * @return int empleados actualizados
     */
    public function saveFixedCommissions(array $byEmployee): int
    {
        $current = [];
        foreach ($this->query('SELECT id, fixed_commission FROM employees')->fetchAll() as $row) {
            $current[(int)$row['id']] = round((float)$row['fixed_commission'], 2);
        }
        $changed = [];
        foreach ($byEmployee as $empId => $raw) {
            $id = (int)$empId;
            if (!isset($current[$id]) || is_array($raw)) {
                continue;
            }
            $amount = trim((string)$raw) === '' ? 0.0 : round(\SoftNova\Core\parse_money($raw), 2);
            if ($amount < 0 || $amount > 100000000) {
                throw new \InvalidArgumentException('Comisión fija: el valor debe estar entre 0 y 100.000.000');
            }
            if (abs($amount - $current[$id]) >= 0.005) {
                $changed[$id] = $amount;
            }
        }
        if (!$changed) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare('UPDATE employees SET fixed_commission=? WHERE id=?');
            foreach ($changed as $id => $amount) {
                $upd->execute([$amount, $id]);
            }
            $ids = array_keys($changed);
            $draftItems = $this->query(
                "SELECT pi.id, pi.payroll_run_id FROM payroll_items pi
                 JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
                 WHERE pr.status = 'draft' AND pi.employee_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            )->fetchAll();
            $runIds = [];
            foreach ($draftItems as $it) {
                $this->recalcPayrollItem((int)$it['id'], null, null, false);
                $runIds[(int)$it['payroll_run_id']] = true;
            }
            foreach (array_keys($runIds) as $runId) {
                $this->recalcRunTotals($runId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return count($changed);
    }

    private function ensurePayrollAccounts(): void
    {
        static $done = [];
        $oid = spl_object_id($this->db);
        if (isset($done[$oid])) {
            return;
        }
        $done[$oid] = true;
        $accounts = [
            ['510503', 'Nómina y prestaciones', 'expense', 'debit'],
            ['510506', 'Aportes patronales y parafiscales', 'expense', 'debit'],
            ['250505', 'Salarios por pagar', 'liability', 'credit'],
            ['237005', 'Aportes salud por pagar', 'liability', 'credit'],
            ['238030', 'Aportes pensión por pagar', 'liability', 'credit'],
            ['237010', 'ARL por pagar', 'liability', 'credit'],
            ['237006', 'Caja de compensación por pagar', 'liability', 'credit'],
            ['237008', 'SENA por pagar', 'liability', 'credit'],
            ['237007', 'ICBF por pagar', 'liability', 'credit'],
            ['261005', 'Cesantías por pagar', 'liability', 'credit'],
            ['510518', 'Comisiones', 'expense', 'debit'],
            ['510539', 'Vacaciones', 'expense', 'debit'],
            ['261015', 'Vacaciones consolidadas', 'liability', 'credit'],
        ];
        $codes = array_column($accounts, 0);
        $existing = $this->query(
            'SELECT code FROM accounting_accounts WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')',
            $codes
        )->fetchAll(\PDO::FETCH_COLUMN);
        $existing = array_flip(array_map('strval', $existing));
        $ins = null;
        foreach ($accounts as $a) {
            if (isset($existing[$a[0]])) {
                continue;
            }
            $ins ??= $this->db->prepare(
                "INSERT IGNORE INTO accounting_accounts (code, name, account_type, nature, is_system)
                 VALUES (?, ?, ?, ?, 1)"
            );
            $ins->execute($a);
        }
    }

    /** @return array<string, true> */
    private function employeeColumns(): array
    {
        if ($this->employeeColsCache !== null) {
            return $this->employeeColsCache;
        }
        $this->employeeColsCache = [];
        try {
            $rows = $this->db->query('SHOW COLUMNS FROM employees')->fetchAll();
            foreach ($rows as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $this->employeeColsCache[$field] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->employeeColsCache = [];
        }
        return $this->employeeColsCache;
    }

    /** @param int|null $scheduleId null = todos, 0 = sin horario, >0 = ese horario */
    public function employees(string $status = '', string $q = '', ?int $scheduleId = null): array
    {
        TenantOpsSchema::ensure($this->db);
        $this->employeeColsCache = null; // refrescar tras migración de esquema
        $cols = $this->employeeColumns();
        $where = ['1=1'];
        $params = [];
        if ($status !== '' && isset($cols['status'])) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($scheduleId !== null && isset($cols['schedule_id'])) {
            if ($scheduleId > 0) {
                $where[] = 'schedule_id = ?';
                $params[] = $scheduleId;
            } else {
                $where[] = '(schedule_id IS NULL OR schedule_id = 0)';
            }
        }
        if ($q !== '') {
            $likeParts = [];
            foreach (['first_name', 'last_name', 'document_number', 'position_title', 'name'] as $c) {
                if (isset($cols[$c])) {
                    $likeParts[] = $c . ' LIKE ?';
                    $params[] = '%' . $q . '%';
                }
            }
            if ($likeParts) {
                $where[] = '(' . implode(' OR ', $likeParts) . ')';
            }
        }

        $order = 'id ASC';
        if (isset($cols['last_name'], $cols['first_name'])) {
            $order = 'last_name, first_name';
        } elseif (isset($cols['name'])) {
            $order = 'name';
        } elseif (isset($cols['first_name'])) {
            $order = 'first_name';
        }

        return $this->query(
            'SELECT * FROM employees WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $order,
            $params
        )->fetchAll();
    }

    public function saveEmployee(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $docType = trim((string)($data['document_type'] ?? 'CC')) ?: 'CC';
        $doc = preg_replace('/\s+/', '', (string)($data['document_number'] ?? ''));
        $first = trim((string)($data['first_name'] ?? ''));
        $last = trim((string)($data['last_name'] ?? ''));
        if ($doc === '' || $first === '') {
            throw new \InvalidArgumentException('Documento y nombre son obligatorios');
        }
        $salary = max(0, (float)($data['salary'] ?? 0));
        $fields = [
            $docType, $doc, $first, $last,
            trim((string)($data['email'] ?? '')) ?: null,
            trim((string)($data['phone'] ?? '')) ?: null,
            trim((string)($data['position_title'] ?? '')) ?: null,
            ($data['hire_date'] ?? '') !== '' ? $data['hire_date'] : null,
            $salary,
            trim((string)($data['contract_type'] ?? 'indefinido')) ?: 'indefinido',
            trim((string)($data['payment_method'] ?? 'transfer')) ?: 'transfer',
            trim((string)($data['bank_account'] ?? '')) ?: null,
            !empty($data['has_transport_aid']) ? 1 : 0,
            in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active',
            trim((string)($data['notes'] ?? '')) ?: null,
            $this->validScheduleId((int)($data['schedule_id'] ?? 0)),
        ];

        if ($id > 0) {
            $this->query(
                "UPDATE employees SET document_type=?, document_number=?, first_name=?, last_name=?,
                    email=?, phone=?, position_title=?, hire_date=?, salary=?, contract_type=?,
                    payment_method=?, bank_account=?, has_transport_aid=?, status=?, notes=?, schedule_id=?
                 WHERE id=?",
                array_merge($fields, [$id])
            );
            return $id;
        }

        $this->query(
            "INSERT INTO employees
                (document_type, document_number, first_name, last_name, email, phone, position_title,
                 hire_date, salary, contract_type, payment_method, bank_account, has_transport_aid, status, notes, schedule_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            $fields
        );
        return (int)$this->db->lastInsertId();
    }

    private function validScheduleId(int $scheduleId): ?int
    {
        if ($scheduleId <= 0) {
            return null;
        }
        $ok = $this->query('SELECT id FROM payroll_schedules WHERE id=?', [$scheduleId])->fetchColumn();
        return $ok ? $scheduleId : null;
    }

    // ───────────────────────── Horarios ─────────────────────────

    public const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /**
     * Horarios con días decodificados, métricas (según ventana nocturna vigente) y nº de empleados.
     * @return array<int, array<string,mixed>> indexado por id
     */
    public function schedules(): array
    {
        $p = $this->params();
        $rows = $this->query(
            "SELECT s.*, (SELECT COUNT(*) FROM employees e WHERE e.schedule_id = s.id AND e.status = 'active') AS employee_count
             FROM payroll_schedules s ORDER BY s.name"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $days = $this->normalizeDays(json_decode((string)$row['days_json'], true) ?: []);
            $row['days'] = $days;
            $row['metrics'] = $this->scheduleMetrics($days, $p);
            $row['employee_count'] = (int)$row['employee_count'];
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data name, color, notes, days[1..7]{on,start,end,break}
     */
    public function saveSchedule(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El horario necesita un nombre');
        }
        $name = mb_substr($name, 0, 80);
        $color = (string)($data['color'] ?? '#6366f1');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#6366f1';
        }
        $days = $this->normalizeDays(is_array($data['days'] ?? null) ? $data['days'] : [], true);
        $worked = array_filter($days, static fn ($d) => $d['on']);
        if (!$worked) {
            throw new \InvalidArgumentException('Marque al menos un día laborable');
        }
        foreach ($worked as $n => $d) {
            if ($d['start'] === $d['end']) {
                throw new \InvalidArgumentException(self::WEEKDAYS[$n] . ': la hora de entrada y salida no pueden ser iguales');
            }
            $span = $this->spanMinutes($d['start'], $d['end']);
            if ($d['break'] >= $span) {
                throw new \InvalidArgumentException(self::WEEKDAYS[$n] . ': el descanso supera la duración del turno');
            }
        }
        $notes = trim((string)($data['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, 255) : null;
        $json = json_encode($days, JSON_UNESCAPED_UNICODE);

        if ($id > 0) {
            $this->query(
                'UPDATE payroll_schedules SET name=?, color=?, days_json=?, notes=? WHERE id=?',
                [$name, $color, $json, $notes, $id]
            );
            return $id;
        }
        $this->query(
            'INSERT INTO payroll_schedules (name, color, days_json, notes) VALUES (?,?,?,?)',
            [$name, $color, $json, $notes]
        );
        return (int)$this->db->lastInsertId();
    }

    /** Elimina el horario y deja sin horario a los empleados que lo tenían. */
    public function deleteSchedule(int $id): int
    {
        $this->db->beginTransaction();
        try {
            $released = $this->query('UPDATE employees SET schedule_id = NULL WHERE schedule_id = ?', [$id])->rowCount();
            $this->query('DELETE FROM payroll_schedules WHERE id = ?', [$id]);
            $this->db->commit();
            return $released;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Asigna (o quita con 0) un horario a varios empleados.
     * @param int[] $employeeIds
     */
    public function assignSchedule(int $scheduleId, array $employeeIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn ($v) => $v > 0)));
        if (!$ids) {
            throw new \InvalidArgumentException('Seleccione al menos un empleado');
        }
        $target = null;
        if ($scheduleId > 0) {
            $target = $this->validScheduleId($scheduleId);
            if ($target === null) {
                throw new \InvalidArgumentException('Horario no encontrado');
            }
        }
        return $this->query(
            'UPDATE employees SET schedule_id = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$target], $ids)
        )->rowCount();
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<int, array{on:bool,start:string,end:string,break:int}>
     */
    private function normalizeDays(array $raw, bool $fromForm = false): array
    {
        $out = [];
        foreach (self::WEEKDAYS as $n => $_) {
            $d = is_array($raw[$n] ?? null) ? $raw[$n] : (is_array($raw[(string)$n] ?? null) ? $raw[(string)$n] : []);
            $on = $fromForm ? (($d['on'] ?? '') === '1') : !empty($d['on']);
            $out[$n] = [
                'on' => $on,
                'start' => $this->cleanTime((string)($d['start'] ?? ''), '08:00'),
                'end' => $this->cleanTime((string)($d['end'] ?? ''), '17:00'),
                'break' => max(0, min(240, (int)($d['break'] ?? 0))),
            ];
        }
        return $out;
    }

    private function cleanTime(string $t, string $default): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : $default;
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }

    /** Duración del turno en minutos; si la salida es menor o igual a la entrada, cruza medianoche. */
    private function spanMinutes(string $start, string $end): int
    {
        $s = $this->toMinutes($start);
        $e = $this->toMinutes($end);
        return $e > $s ? $e - $s : $e + 1440 - $s;
    }

    /**
     * Horas semanales, nocturnas (ventana de Parámetros) y dominicales + alertas legales.
     * El descanso se descuenta primero del tramo diurno.
     * @param array<int, array{on:bool,start:string,end:string,break:int}> $days
     * Lo que exceda la jornada diaria de Parámetros cuenta como hora extra (se toma del final del turno):
     * diurna/nocturna según la ventana nocturna y dominical si el día es domingo.
     * @return array{weekly_hours:float,weekly_night_hours:float,weekly_night_ordinary_hours:float,weekly_sunday_hours:float,
     *   weekly_extra_day:float,weekly_extra_night:float,weekly_extra_dom_day:float,weekly_extra_dom_night:float,weekly_extra_hours:float,
     *   monthly_hours:float,daily:array<int,float>,daily_extra:array<int,float>,warnings:string[]}
     */
    public function scheduleMetrics(array $days, ?array $params = null): array
    {
        $p = $params ?? $this->params();
        $ns = (int)($p['night_start_hour'] ?? 19) * 60;
        $ne = (int)($p['night_end_hour'] ?? 6) * 60;
        $windows = $ns > $ne
            ? [[0, $ne], [$ns, 1440 + $ne], [1440 + $ns, 2880]]
            : [[$ns, $ne], [1440 + $ns, 1440 + $ne]];
        $maxDailyMin = (int)round(max(1.0, (float)($p['max_daily_hours'] ?? 8)) * 60);
        $overlapNight = static function (int $from, int $to) use ($windows): int {
            $m = 0;
            foreach ($windows as [$ws, $we]) {
                $m += max(0, min($to, $we) - max($from, $ws));
            }
            return $m;
        };

        $weekly = $night = $sunday = 0.0;
        $exDay = $exNight = $exDomDay = $exDomNight = 0.0;
        $nightExtraTotal = 0.0;
        $daily = [];
        $dailyExtra = [];
        $warnings = [];
        foreach ($days as $n => $d) {
            if (empty($d['on'])) {
                $daily[$n] = 0.0;
                $dailyExtra[$n] = 0.0;
                continue;
            }
            $s = $this->toMinutes($d['start']);
            $e = $s + $this->spanMinutes($d['start'], $d['end']);
            $nightMin = 0;
            foreach ($windows as [$ws, $we]) {
                $nightMin += max(0, min($e, $we) - max($s, $ws));
            }
            $span = $e - $s;
            $break = (int)$d['break'];
            $dayMin = $span - $nightMin;
            if ($break > $dayMin) {
                $nightMin = max(0, $nightMin - ($break - $dayMin));
            }
            $net = max(0, $span - $break);
            $daily[$n] = round($net / 60, 2);
            $weekly += $net / 60;
            $night += $nightMin / 60;
            if ($n === 7) {
                $sunday += $net / 60;
            }

            $extraMin = max(0, $net - $maxDailyMin);
            $dailyExtra[$n] = round($extraMin / 60, 2);
            if ($extraMin > 0) {
                $extraNight = min($extraMin, $nightMin, $overlapNight($e - $extraMin, $e));
                $extraDay = $extraMin - $extraNight;
                $nightExtraTotal += $extraNight / 60;
                if ($n === 7) {
                    $exDomDay += $extraDay / 60;
                    $exDomNight += $extraNight / 60;
                } else {
                    $exDay += $extraDay / 60;
                    $exNight += $extraNight / 60;
                }
                $warnings[] = sprintf('%s: %s h sobre la jornada diaria (%s h) → hora extra',
                    self::WEEKDAYS[$n],
                    rtrim(rtrim(number_format($extraMin / 60, 2, ',', ''), '0'), ','),
                    rtrim(rtrim(number_format($maxDailyMin / 60, 2, ',', ''), '0'), ','));
            }
            if ($net > 600) {
                $warnings[] = self::WEEKDAYS[$n] . ': más de 10 h efectivas en el día';
            }
        }
        $max = (float)($p['max_weekly_hours'] ?? 42);
        $weeklyOrdinary = $weekly - ($exDay + $exNight + $exDomDay + $exDomNight);
        if ($weeklyOrdinary > $max + 0.001) {
            $warnings[] = sprintf('Horas ordinarias sobre la jornada máxima (%s h/semana): el exceso de %s h debe liquidarse como horas extra',
                rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($weeklyOrdinary - $max, 2, '.', ''), '0'), '.'));
        }
        if (count(array_filter($days, static fn ($d) => !empty($d['on']))) === 7) {
            $warnings[] = 'Sin día de descanso semanal';
        }

        return [
            'weekly_hours' => round($weekly, 2),
            'weekly_night_hours' => round($night, 2),
            'weekly_night_ordinary_hours' => round(max(0, $night - $nightExtraTotal), 2),
            'weekly_sunday_hours' => round($sunday, 2),
            'weekly_extra_day' => round($exDay, 2),
            'weekly_extra_night' => round($exNight, 2),
            'weekly_extra_dom_day' => round($exDomDay, 2),
            'weekly_extra_dom_night' => round($exDomNight, 2),
            'weekly_extra_hours' => round($exDay + $exNight + $exDomDay + $exDomNight, 2),
            'monthly_hours' => round($weekly * 30 / 7, 2),
            'daily' => $daily,
            'daily_extra' => $dailyExtra,
            'warnings' => $warnings,
        ];
    }

    /**
     * Horas extra del periodo según el horario (exceso diario sobre la jornada de Parámetros),
     * semanales × días / 7 con redondeo a 0,25 h.
     * @param array<int, array<string,mixed>> $schedules resultado de schedules()
     * @return array{day_hours:float,night_hours:float,dom_day_hours:float,dom_night_hours:float}
     */
    private function scheduleOvertimeForPeriod(array $employee, array $schedules, int $days): array
    {
        $out = ['day_hours' => 0.0, 'night_hours' => 0.0, 'dom_day_hours' => 0.0, 'dom_night_hours' => 0.0];
        $sid = (int)($employee['schedule_id'] ?? 0);
        if ($sid <= 0 || !isset($schedules[$sid])) {
            return $out;
        }
        $m = $schedules[$sid]['metrics'];
        $factor = max(1, $days) / 7;
        $q = static fn (float $weekly) => round($weekly * $factor * 4) / 4;
        $out['day_hours'] = $q((float)($m['weekly_extra_day'] ?? 0));
        $out['night_hours'] = $q((float)($m['weekly_extra_night'] ?? 0));
        $out['dom_day_hours'] = $q((float)($m['weekly_extra_dom_day'] ?? 0));
        $out['dom_night_hours'] = $q((float)($m['weekly_extra_dom_night'] ?? 0));
        return $out;
    }

    /**
     * Horas RNO del periodo a partir del horario del empleado (semanales × días / 7, redondeo a 0,25 h).
     * @param array<int, array<string,mixed>> $schedules resultado de schedules()
     */
    private function scheduleNightHoursForPeriod(array $employee, array $schedules, int $days, bool $excludeExtra = false): float
    {
        $sid = (int)($employee['schedule_id'] ?? 0);
        if ($sid <= 0 || !isset($schedules[$sid])) {
            return 0.0;
        }
        // Si las HE se precargan, las horas nocturnas extra van como HEN/HEND y no como RNO.
        $weeklyNight = (float)$schedules[$sid]['metrics'][$excludeExtra ? 'weekly_night_ordinary_hours' : 'weekly_night_hours'];
        return round($weeklyNight * max(1, $days) / 7 * 4) / 4;
    }

    /**
     * Matriz Colombia (simplificada): HED, HEN, RNO, Dom. ordinario, HEDD, HEND.
     * HE/Dom: hours × vh × (1 + recargo%). RNO: solo recargo (hours × vh × %).
     *
     * @param array{
     *   day_hours?:float,night_hours?:float,night_surcharge_hours?:float,
     *   dominical_hours?:float,dom_day_hours?:float,dom_night_hours?:float
     * } $otCfg
     * @return array<string,float>
     */
    private function calculateOvertime(float $salary, array $otCfg, array $params): array
    {
        $monthlyHours = max(1.0, (float)($params['payroll_monthly_hours'] ?? 230));
        $hourly = $salary / $monthlyHours;
        $h = static fn ($k) => max(0.0, round((float)($otCfg[$k] ?? 0), 2));
        $dayHours = $h('day_hours');
        $nightHours = $h('night_hours');
        $rnoHours = $h('night_surcharge_hours');
        $domHours = $h('dominical_hours');
        $heddHours = $h('dom_day_hours');
        $hendHours = $h('dom_night_hours');

        $hedRate = (float)($params['overtime_day_rate'] ?? 25);
        $henRate = (float)($params['overtime_night_rate'] ?? 75);
        $rnoRate = (float)($params['night_surcharge_rate'] ?? 35);
        $domRate = (float)($params['dominical_rate'] ?? 75);
        $heddRate = (float)($params['overtime_dom_day_rate'] ?? 100);
        $hendRate = (float)($params['overtime_dom_night_rate'] ?? 150);

        $dayAmount = round($dayHours * $hourly * (1 + $hedRate / 100), 2);
        $nightAmount = round($nightHours * $hourly * (1 + $henRate / 100), 2);
        // Recargo nocturno ordinario: solo el % extra sobre la hora ordinaria.
        $rnoAmount = round($rnoHours * $hourly * ($rnoRate / 100), 2);
        $domAmount = round($domHours * $hourly * (1 + $domRate / 100), 2);
        $heddAmount = round($heddHours * $hourly * (1 + $heddRate / 100), 2);
        $hendAmount = round($hendHours * $hourly * (1 + $hendRate / 100), 2);
        $total = round($dayAmount + $nightAmount + $rnoAmount + $domAmount + $heddAmount + $hendAmount, 2);

        return [
            'day_hours' => $dayHours,
            'night_hours' => $nightHours,
            'night_surcharge_hours' => $rnoHours,
            'dominical_hours' => $domHours,
            'dom_day_hours' => $heddHours,
            'dom_night_hours' => $hendHours,
            'day_amount' => $dayAmount,
            'night_amount' => $nightAmount,
            'night_surcharge_amount' => $rnoAmount,
            'dominical_amount' => $domAmount,
            'dom_day_amount' => $heddAmount,
            'dom_night_amount' => $hendAmount,
            'total' => $total,
            'hourly_rate' => round($hourly, 2),
        ];
    }

    /** Quincenas que cubre una liquidación: hasta 15 días = 1, más = 2 (mes completo). */
    public static function quincenasInPeriod(int $periodDays): int
    {
        return $periodDays <= 15 ? 1 : 2;
    }

    /**
     * @param array{include_prima?:bool,include_cesantias?:bool,include_parafiscales?:bool,incapacity?:array<int,array{days?:int,type?:string}>,overtime?:array<int|string,array{day_hours?:float,night_hours?:float,dominical_hours?:float}>} $options
     * @return array<string,float|int|string>
     */
    public function calculateEmployeeLine(array $employee, int $daysWorked = 30, array $options = []): array
    {
        $p = $this->params();
        $periodDays = max(1, min(31, $daysWorked));
        $empId = (int)$employee['id'];
        $incCfg = $options['incapacity'][$empId] ?? $options['incapacity'][(string)$empId] ?? [];
        $incapDays = max(0, min($periodDays, (int)($incCfg['days'] ?? 0)));
        $incapType = (string)($incCfg['type'] ?? 'enfermedad');
        $workedDays = max(0, $periodDays - $incapDays);

        $salary = (float)$employee['salary'];
        $daily = $salary / 30;
        $base = round($daily * $workedDays, 2);

        $otCfg = $options['overtime'][$empId] ?? $options['overtime'][(string)$empId] ?? [];
        $ot = $this->calculateOvertime($salary, $otCfg, $p);

        // Incapacidad: días 1-2 al 100% (empleador); resto al % configurado (EPS/ARL según tipo)
        $incapPay = 0.0;
        if ($incapDays > 0) {
            $fullDays = min(2, $incapDays);
            $partialDays = max(0, $incapDays - $fullDays);
            $rate = ($incapType === 'laboral') ? 100.0 : (float)$p['incapacity_rate'];
            $incapPay = round(($daily * $fullDays) + ($daily * $partialDays * $rate / 100), 2);
        }

        $transport = 0.0;
        if (!empty($employee['has_transport_aid']) && $salary <= ($p['smmlv'] * 2) && $workedDays > 0) {
            $transport = round(((float)$p['transport_aid'] / 30) * $workedDays, 2);
        }

        $prima = 0.0;
        if (!empty($options['include_prima'])) {
            // Prima de servicios del semestre: % mensual de Provisiones × 6 meses (8,33 % → medio salario).
            $prima = round($salary * self::provisionFraction((float)$p['prima_rate']) * 6, 2);
        }

        $cesantias = 0.0;
        $cesInterest = 0.0;
        if (!empty($options['include_cesantias'])) {
            // Provisión mensual: salario × % cesantías; intereses = % anual sobre la provisión ÷ 12.
            $cesantias = round($salary * self::provisionFraction((float)$p['cesantias_rate']), 2);
            $cesInterest = round($cesantias * (float)$p['cesantias_interest_rate'] / 100 / 12, 2);
        }

        $vacation = 0.0;
        if (!empty($options['include_vacaciones'])) {
            // Provisión mensual de vacaciones (15 días hábiles/año ≈ 4,17 %); no suma al neto.
            $vacation = round($salary * self::provisionFraction((float)$p['vacation_rate']), 2);
        }

        // Base cotización: salario + HE/dominicales (sin auxilio transporte ni prima)
        $ibcBase = $base > 0 ? $base : round($daily * max(1, $incapDays > 0 ? min($incapDays, $periodDays) : $periodDays), 2);
        $ibc = round($ibcBase + $ot['total'], 2);

        $healthEmp = round($ibc * (float)$p['health_employee_rate'] / 100, 2);
        $pensionEmp = round($ibc * (float)$p['pension_employee_rate'] / 100, 2);
        $healthEr = round($ibc * (float)$p['health_employer_rate'] / 100, 2);
        $pensionEr = round($ibc * (float)$p['pension_employer_rate'] / 100, 2);
        $arl = round($ibc * (float)$p['arl_employer_rate'] / 100, 2);

        $caja = $sena = $icbf = 0.0;
        if (!empty($options['include_parafiscales'])) {
            $caja = round($ibc * (float)$p['caja_employer_rate'] / 100, 2);
            $sena = round($ibc * (float)$p['sena_employer_rate'] / 100, 2);
            $icbf = round($ibc * (float)$p['icbf_employer_rate'] / 100, 2);
        }

        // Comisión fija no salarial por quincena, fuera del IBC (no genera aportes ni prestaciones).
        $commission = max(0.0, round((float)($employee['fixed_commission'] ?? 0) * self::quincenasInPeriod($periodDays), 2));

        $gross = round($base + $transport + $prima + $incapPay + $ot['total'] + $commission, 2);
        $deductions = round($healthEmp + $pensionEmp, 2);
        $net = round($gross - $deductions, 2);
        $name = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));

        return [
            'employee_id' => $empId,
            'employee_name' => $name,
            'salary_base' => $base,
            'days_worked' => $workedDays,
            'incapacity_days' => $incapDays,
            'incapacity_pay' => $incapPay,
            'incapacity_type' => $incapDays > 0 ? $incapType : '',
            'transport_aid' => $transport,
            'commission_amount' => $commission,
            'prima' => $prima,
            'cesantias' => $cesantias,
            'cesantias_interest' => $cesInterest,
            'vacation_provision' => $vacation,
            'overtime_day_hours' => $ot['day_hours'],
            'overtime_day_amount' => $ot['day_amount'],
            'overtime_night_hours' => $ot['night_hours'],
            'overtime_night_amount' => $ot['night_amount'],
            'night_surcharge_hours' => $ot['night_surcharge_hours'],
            'night_surcharge_amount' => $ot['night_surcharge_amount'],
            'dominical_hours' => $ot['dominical_hours'],
            'dominical_amount' => $ot['dominical_amount'],
            'overtime_dom_day_hours' => $ot['dom_day_hours'],
            'overtime_dom_day_amount' => $ot['dom_day_amount'],
            'overtime_dom_night_hours' => $ot['dom_night_hours'],
            'overtime_dom_night_amount' => $ot['dom_night_amount'],
            'other_earnings' => $ot['total'],
            'health_employee' => $healthEmp,
            'pension_employee' => $pensionEmp,
            'other_deductions' => 0.0,
            'health_employer' => $healthEr,
            'pension_employer' => $pensionEr,
            'arl_employer' => $arl,
            'caja_employer' => $caja,
            'sena_employer' => $sena,
            'icbf_employer' => $icbf,
            'gross_pay' => $gross,
            'net_pay' => $net,
        ];
    }

    /**
     * @param array{include_prima?:bool,include_cesantias?:bool,include_parafiscales?:bool,incapacity?:array,schedule_rno?:bool} $options
     */
    public function createRun(
        int $year,
        int $month,
        string $payDate,
        int $daysWorked = 30,
        string $paymentMethod = 'transfer',
        ?int $userId = null,
        string $notes = '',
        array $options = []
    ): int {
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));
        $includePrima = !empty($options['include_prima']) || in_array($month, [6, 12], true) && !empty($options['auto_prima']);
        if (array_key_exists('include_prima', $options)) {
            $includePrima = !empty($options['include_prima']);
        }
        $includeCes = !empty($options['include_cesantias']);
        $includeVac = !empty($options['include_vacaciones']);
        $includePara = array_key_exists('include_parafiscales', $options)
            ? !empty($options['include_parafiscales'])
            : true;

        $calcOpts = [
            'include_prima' => $includePrima,
            'include_cesantias' => $includeCes,
            'include_vacaciones' => $includeVac,
            'include_parafiscales' => $includePara,
            'incapacity' => $options['incapacity'] ?? [],
        ];

        $exists = $this->query(
            "SELECT id, status FROM payroll_runs WHERE period_year=? AND period_month=? LIMIT 1",
            [$year, $month]
        )->fetch();
        if ($exists && ($exists['status'] ?? '') !== 'cancelled') {
            throw new \RuntimeException('Ya existe una liquidación para ese periodo');
        }

        $employees = $this->employees('active');
        if (!$employees) {
            throw new \RuntimeException('No hay empleados activos para liquidar');
        }
        $preRno = !empty($options['schedule_rno']);
        $preOt = !empty($options['schedule_ot']);
        if ($preRno || $preOt) {
            $schedules = $this->schedules();
            $calcOpts['overtime'] = [];
            foreach ($employees as $emp) {
                $cfg = [];
                if ($preRno) {
                    $rno = $this->scheduleNightHoursForPeriod($emp, $schedules, $daysWorked, $preOt);
                    if ($rno > 0) {
                        $cfg['night_surcharge_hours'] = $rno;
                    }
                }
                if ($preOt) {
                    $cfg += array_filter($this->scheduleOvertimeForPeriod($emp, $schedules, $daysWorked), static fn ($h) => $h > 0);
                }
                if ($cfg) {
                    $calcOpts['overtime'][(int)$emp['id']] = $cfg;
                }
            }
        }

        $number = sprintf('NOM-%04d%02d', $year, $month);
        $label = sprintf('%02d/%04d', $month, $year);
        $this->db->beginTransaction();
        try {
            if ($exists && ($exists['status'] ?? '') === 'cancelled') {
                $this->query('DELETE FROM payroll_runs WHERE id=?', [(int)$exists['id']]);
            }
            $this->query(
                "INSERT INTO payroll_runs
                    (run_number, period_year, period_month, period_label, pay_date, days_worked,
                     payment_method, status, notes, user_id, include_prima, include_cesantias, include_vacaciones, include_parafiscales)
                 VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?,?)",
                [
                    $number, $year, $month, $label, $payDate, $daysWorked, $paymentMethod,
                    $notes ?: null, $userId, $includePrima ? 1 : 0, $includeCes ? 1 : 0, $includeVac ? 1 : 0, $includePara ? 1 : 0,
                ]
            );
            $runId = (int)$this->db->lastInsertId();
            $this->insertItems($runId, $employees, $daysWorked, $calcOpts);
            $this->db->commit();
            return $runId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function insertItems(int $runId, array $employees, int $daysWorked, array $calcOpts): void
    {
        $ins = $this->db->prepare(
            "INSERT INTO payroll_items
                (payroll_run_id, employee_id, employee_name, salary_base, days_worked, incapacity_days,
                 incapacity_pay, incapacity_type, transport_aid, commission_amount, prima, cesantias, cesantias_interest,
                 vacation_provision,
                 other_earnings, overtime_day_hours, overtime_day_amount, overtime_night_hours, overtime_night_amount,
                 night_surcharge_hours, night_surcharge_amount,
                 dominical_hours, dominical_amount,
                 overtime_dom_day_hours, overtime_dom_day_amount, overtime_dom_night_hours, overtime_dom_night_amount,
                 health_employee, pension_employee, other_deductions,
                 health_employer, pension_employer, arl_employer, caja_employer, sena_employer, icbf_employer,
                 gross_pay, net_pay)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $gross = $ded = $net = $employer = $primaT = $cesT = $vacT = $incT = $paraT = $otT = $comT = 0.0;
        foreach ($employees as $emp) {
            $line = $this->calculateEmployeeLine($emp, $daysWorked, $calcOpts);
            $ins->execute([
                $runId, $line['employee_id'], $line['employee_name'], $line['salary_base'], $line['days_worked'],
                $line['incapacity_days'], $line['incapacity_pay'], $line['incapacity_type'],
                $line['transport_aid'], $line['commission_amount'], $line['prima'], $line['cesantias'], $line['cesantias_interest'],
                $line['vacation_provision'],
                $line['other_earnings'], $line['overtime_day_hours'], $line['overtime_day_amount'],
                $line['overtime_night_hours'], $line['overtime_night_amount'],
                $line['night_surcharge_hours'], $line['night_surcharge_amount'],
                $line['dominical_hours'], $line['dominical_amount'],
                $line['overtime_dom_day_hours'], $line['overtime_dom_day_amount'],
                $line['overtime_dom_night_hours'], $line['overtime_dom_night_amount'],
                $line['health_employee'], $line['pension_employee'], $line['other_deductions'],
                $line['health_employer'], $line['pension_employer'], $line['arl_employer'],
                $line['caja_employer'], $line['sena_employer'], $line['icbf_employer'],
                $line['gross_pay'], $line['net_pay'],
            ]);
            $gross += $line['gross_pay'];
            $ded += $line['health_employee'] + $line['pension_employee'] + $line['other_deductions'];
            $net += $line['net_pay'];
            $employer += $line['health_employer'] + $line['pension_employer'] + $line['arl_employer'];
            $paraT += $line['caja_employer'] + $line['sena_employer'] + $line['icbf_employer'];
            $primaT += $line['prima'];
            $cesT += $line['cesantias'] + $line['cesantias_interest'];
            $vacT += $line['vacation_provision'];
            $incT += $line['incapacity_pay'];
            $otT += $line['other_earnings'];
            $comT += $line['commission_amount'];
        }

        $this->query(
            "UPDATE payroll_runs SET gross_total=?, deductions_total=?, net_total=?, employer_total=?,
                prima_total=?, cesantias_total=?, vacation_total=?, incapacity_total=?, overtime_total=?, parafiscal_total=?, commission_total=? WHERE id=?",
            [
                round($gross, 2), round($ded, 2), round($net, 2), round($employer, 2),
                round($primaT, 2), round($cesT, 2), round($vacT, 2), round($incT, 2), round($otT, 2), round($paraT, 2), round($comT, 2), $runId,
            ]
        );
    }

    /** Recalcula una línea en borrador conservando incapacidad y HE/dominicales salvo override. */
    private function recalcPayrollItem(
        int $itemId,
        ?array $incapOverride = null,
        ?array $otOverride = null,
        bool $recalcTotals = true
    ): void
    {
        $item = $this->query(
            "SELECT pi.*, pr.status, pr.days_worked AS run_days, pr.include_prima, pr.include_cesantias, pr.include_vacaciones, pr.include_parafiscales
             FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
             WHERE pi.id=?",
            [$itemId]
        )->fetch();
        if (!$item) {
            throw new \RuntimeException('Ítem no encontrado');
        }
        if (($item['status'] ?? '') !== 'draft') {
            throw new \RuntimeException('Solo se edita en liquidaciones en borrador');
        }
        $emp = $this->query('SELECT * FROM employees WHERE id=?', [(int)$item['employee_id']])->fetch();
        if (!$emp) {
            throw new \RuntimeException('Empleado no encontrado');
        }

        $incapDays = $incapOverride !== null
            ? max(0, (int)($incapOverride['days'] ?? 0))
            : (int)($item['incapacity_days'] ?? 0);
        $incapType = $incapOverride !== null
            ? (string)($incapOverride['type'] ?? 'enfermedad')
            : (string)($item['incapacity_type'] ?? 'enfermedad');
        $ot = $otOverride ?? [
            'day_hours' => (float)($item['overtime_day_hours'] ?? 0),
            'night_hours' => (float)($item['overtime_night_hours'] ?? 0),
            'night_surcharge_hours' => (float)($item['night_surcharge_hours'] ?? 0),
            'dominical_hours' => (float)($item['dominical_hours'] ?? 0),
            'dom_day_hours' => (float)($item['overtime_dom_day_hours'] ?? 0),
            'dom_night_hours' => (float)($item['overtime_dom_night_hours'] ?? 0),
        ];

        $line = $this->calculateEmployeeLine($emp, (int)$item['run_days'], [
            'include_prima' => !empty($item['include_prima']),
            'include_cesantias' => !empty($item['include_cesantias']),
            'include_vacaciones' => !empty($item['include_vacaciones']),
            'include_parafiscales' => !empty($item['include_parafiscales']),
            'incapacity' => [(int)$emp['id'] => ['days' => $incapDays, 'type' => $incapType]],
            'overtime' => [(int)$emp['id'] => $ot],
        ]);
        $this->query(
            "UPDATE payroll_items SET salary_base=?, days_worked=?, incapacity_days=?, incapacity_pay=?, incapacity_type=?,
                transport_aid=?, commission_amount=?, prima=?, cesantias=?, cesantias_interest=?, vacation_provision=?, other_earnings=?,
                overtime_day_hours=?, overtime_day_amount=?, overtime_night_hours=?, overtime_night_amount=?,
                night_surcharge_hours=?, night_surcharge_amount=?,
                dominical_hours=?, dominical_amount=?,
                overtime_dom_day_hours=?, overtime_dom_day_amount=?, overtime_dom_night_hours=?, overtime_dom_night_amount=?,
                health_employee=?, pension_employee=?, health_employer=?, pension_employer=?, arl_employer=?,
                caja_employer=?, sena_employer=?, icbf_employer=?, gross_pay=?, net_pay=? WHERE id=?",
            [
                $line['salary_base'], $line['days_worked'], $line['incapacity_days'], $line['incapacity_pay'], $line['incapacity_type'],
                $line['transport_aid'], $line['commission_amount'], $line['prima'], $line['cesantias'], $line['cesantias_interest'],
                $line['vacation_provision'], $line['other_earnings'],
                $line['overtime_day_hours'], $line['overtime_day_amount'], $line['overtime_night_hours'], $line['overtime_night_amount'],
                $line['night_surcharge_hours'], $line['night_surcharge_amount'],
                $line['dominical_hours'], $line['dominical_amount'],
                $line['overtime_dom_day_hours'], $line['overtime_dom_day_amount'],
                $line['overtime_dom_night_hours'], $line['overtime_dom_night_amount'],
                $line['health_employee'], $line['pension_employee'], $line['health_employer'], $line['pension_employer'],
                $line['arl_employer'], $line['caja_employer'], $line['sena_employer'], $line['icbf_employer'],
                $line['gross_pay'], $line['net_pay'], $itemId,
            ]
        );
        if ($recalcTotals) {
            $this->recalcRunTotals((int)$item['payroll_run_id']);
        }
    }

    /** Actualiza incapacidad de un ítem en borrador y recalcula la línea. */
    public function updateItemIncapacity(int $itemId, int $incapDays, string $incapType = 'enfermedad'): void
    {
        $this->recalcPayrollItem($itemId, ['days' => $incapDays, 'type' => $incapType], null);
    }

    /**
     * Actualiza horas extras / recargos de un ítem en borrador.
     * @param array{day_hours?:float,night_hours?:float,night_surcharge_hours?:float,dominical_hours?:float,dom_day_hours?:float,dom_night_hours?:float} $hours
     */
    public function updateItemOvertime(int $itemId, array $hours): void
    {
        $this->recalcPayrollItem($itemId, null, [
            'day_hours' => max(0, (float)($hours['day_hours'] ?? 0)),
            'night_hours' => max(0, (float)($hours['night_hours'] ?? 0)),
            'night_surcharge_hours' => max(0, (float)($hours['night_surcharge_hours'] ?? 0)),
            'dominical_hours' => max(0, (float)($hours['dominical_hours'] ?? 0)),
            'dom_day_hours' => max(0, (float)($hours['dom_day_hours'] ?? 0)),
            'dom_night_hours' => max(0, (float)($hours['dom_night_hours'] ?? 0)),
        ]);
    }

    /**
     * Captura masiva de HE en una liquidación borrador.
     * @param array<int|string, array<string,float|int|string>> $byItemId
     */
    public function updateRunOvertimeBulk(int $runId, array $byItemId): int
    {
        $run = $this->query('SELECT id, status FROM payroll_runs WHERE id=?', [$runId])->fetch();
        if (!$run) {
            throw new \RuntimeException('Liquidación no encontrada');
        }
        if (($run['status'] ?? '') !== 'draft') {
            throw new \RuntimeException('Solo se edita en liquidaciones en borrador');
        }
        $current = [];
        foreach ($this->query(
            'SELECT id, overtime_day_hours, overtime_night_hours, night_surcharge_hours, dominical_hours,
                    overtime_dom_day_hours, overtime_dom_night_hours
             FROM payroll_items WHERE payroll_run_id=?',
            [$runId]
        )->fetchAll() as $row) {
            $current[(int)$row['id']] = [
                'day_hours' => round((float)$row['overtime_day_hours'], 2),
                'night_hours' => round((float)$row['overtime_night_hours'], 2),
                'night_surcharge_hours' => round((float)$row['night_surcharge_hours'], 2),
                'dominical_hours' => round((float)$row['dominical_hours'], 2),
                'dom_day_hours' => round((float)$row['overtime_dom_day_hours'], 2),
                'dom_night_hours' => round((float)$row['overtime_dom_night_hours'], 2),
            ];
        }
        $updated = 0;
        $this->db->beginTransaction();
        try {
            foreach ($byItemId as $itemId => $hours) {
                $id = (int)$itemId;
                if (!isset($current[$id]) || !is_array($hours)) {
                    continue;
                }
                $new = [];
                foreach ($current[$id] as $k => $_) {
                    $new[$k] = round(max(0, (float)($hours[$k] ?? 0)), 2);
                }
                if ($new === $current[$id]) {
                    continue;
                }
                // Recalc sin re-sumar el run en cada ítem (una sola vez al final).
                $this->recalcPayrollItem($id, null, $new, false);
                $updated++;
            }
            if ($updated > 0) {
                $this->recalcRunTotals($runId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $updated;
    }

    public function countEmployees(string $status = 'active'): int
    {
        $cols = $this->employeeColumns();
        if (!isset($cols['status']) || $status === '') {
            return (int)$this->query('SELECT COUNT(*) FROM employees')->fetchColumn();
        }
        return (int)$this->query(
            'SELECT COUNT(*) FROM employees WHERE status=?',
            [$status]
        )->fetchColumn();
    }

    /** Invalida caché de parámetros (tras guardar en UI). */
    public function clearParamsCache(): void
    {
        $this->paramsDirty = true;
        $this->employeeColsCache = null;
    }

    private function recalcRunTotals(int $runId): void
    {
        $sum = $this->query(
            "SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(health_employee+pension_employee+other_deductions),0) d,
                    COALESCE(SUM(net_pay),0) n,
                    COALESCE(SUM(health_employer+pension_employer+arl_employer),0) e,
                    COALESCE(SUM(prima),0) p, COALESCE(SUM(cesantias+cesantias_interest),0) c,
                    COALESCE(SUM(vacation_provision),0) v,
                    COALESCE(SUM(incapacity_pay),0) i, COALESCE(SUM(other_earnings),0) ot,
                    COALESCE(SUM(caja_employer+sena_employer+icbf_employer),0) pf,
                    COALESCE(SUM(commission_amount),0) com
             FROM payroll_items WHERE payroll_run_id=?",
            [$runId]
        )->fetch();
        $this->query(
            "UPDATE payroll_runs SET gross_total=?, deductions_total=?, net_total=?, employer_total=?,
                prima_total=?, cesantias_total=?, vacation_total=?, incapacity_total=?, overtime_total=?, parafiscal_total=?, commission_total=? WHERE id=?",
            [
                (float)$sum['g'], (float)$sum['d'], (float)$sum['n'], (float)$sum['e'],
                (float)$sum['p'], (float)$sum['c'], (float)$sum['v'], (float)$sum['i'], (float)$sum['ot'], (float)$sum['pf'], (float)$sum['com'], $runId,
            ]
        );
    }

    public function runs(int $limit = 24, int $year = 0, string $status = ''): array
    {
        $where = [];
        $params = [];
        if ($year > 0) {
            $where[] = 'period_year = ?';
            $params[] = $year;
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        return $this->query(
            'SELECT pr.*, (SELECT COUNT(*) FROM payroll_items pi WHERE pi.payroll_run_id = pr.id) AS employee_count
             FROM payroll_runs pr' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                . ' ORDER BY period_year DESC, period_month DESC LIMIT ' . max(1, $limit),
            $params
        )->fetchAll();
    }

    /** @return list<int> */
    public function runYears(): array
    {
        return array_map('intval', $this->query(
            'SELECT DISTINCT period_year FROM payroll_runs ORDER BY period_year DESC'
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function runDetail(int $id): ?array
    {
        $run = $this->query('SELECT * FROM payroll_runs WHERE id=?', [$id])->fetch();
        if (!$run) {
            return null;
        }
        $items = $this->query(
            'SELECT * FROM payroll_items WHERE payroll_run_id=? ORDER BY employee_name',
            [$id]
        )->fetchAll();
        return ['run' => $run, 'items' => $items];
    }

    public function postAndPay(int $runId): array
    {
        $detail = $this->runDetail($runId);
        if (!$detail) {
            throw new \RuntimeException('Liquidación no encontrada');
        }
        $run = $detail['run'];
        if (($run['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Liquidación cancelada');
        }
        if (in_array($run['status'], ['posted', 'paid'], true)) {
            throw new \RuntimeException('La liquidación ya fue contabilizada');
        }
        $net = (float)$run['net_total'];
        if ($net <= 0) {
            throw new \RuntimeException('Neto a pagar inválido');
        }

        $this->db->beginTransaction();
        try {
            $accounting = new AccountingService($this->db);
            $entryId = $accounting->postPayrollRun($runId, true);

            $this->query(
                "INSERT INTO expenses
                    (description, amount, category, expense_date, payment_method, notes, user_id)
                 VALUES (?,?,?,?,?,?,?)",
                [
                    'Nómina ' . $run['period_label'],
                    $net,
                    'payroll',
                    $run['pay_date'],
                    $run['payment_method'] ?: 'transfer',
                    'Liquidación ' . $run['run_number'] . ' · asiento contable #' . $entryId
                        . ' · bruto ' . $run['gross_total']
                        . ' · aportes emp. ' . $run['employer_total']
                        . ' · parafiscales ' . ($run['parafiscal_total'] ?? 0)
                        . ' · no descuenta cajón POS',
                    $run['user_id'] ?? ($_SESSION['tenant_user_id'] ?? null),
                ]
            );
            $expenseId = (int)$this->db->lastInsertId();

            $this->query(
                "UPDATE payroll_runs SET status='paid', expense_id=? WHERE id=?",
                [$expenseId, $runId]
            );
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'expense_id' => $expenseId,
                'accounting_entry_id' => $entryId,
                'run_number' => (string)$run['run_number'],
                'net_total' => $net,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function cancelRun(int $runId): void
    {
        $run = $this->query('SELECT * FROM payroll_runs WHERE id=?', [$runId])->fetch();
        if (!$run) {
            throw new \RuntimeException('Liquidación no encontrada');
        }
        if (($run['status'] ?? '') === 'paid') {
            throw new \RuntimeException('No se puede cancelar una nómina ya pagada.');
        }
        $this->query("UPDATE payroll_runs SET status='cancelled' WHERE id=?", [$runId]);
    }
}
