<?php

namespace SoftNova\Services;

/**
 * Nómina Colombia: empleados, liquidación mensual, primas, cesantías,
 * incapacidad, parafiscales/ARL y enlace contable detallado.
 */
class PayrollService
{
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
        static $cache = null;
        static $cacheOid = null;
        $oid = spl_object_id($this->db);
        if ($cache !== null && $cacheOid === $oid) {
            return $cache;
        }

        $defaults = [
            'smmlv' => '1423500',
            'transport_aid' => '200000',
            'health_employee_rate' => '4',
            'pension_employee_rate' => '4',
            'health_employer_rate' => '8.5',
            'pension_employer_rate' => '12',
            'arl_employer_rate' => '0.522', // riesgo I típico
            'caja_employer_rate' => '4',
            'sena_employer_rate' => '2',
            'icbf_employer_rate' => '3',
            'incapacity_rate' => '66.67',
            'payroll_monthly_hours' => '240',
            'overtime_day_rate' => '25',
            'overtime_night_rate' => '75',
            'dominical_rate' => '75',
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
        ];
        foreach ($defaults as $key => $value) {
            $this->query(
                "INSERT IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)",
                [$key, $value]
            );
        }

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
        ];
        $ins = $this->db->prepare(
            "INSERT IGNORE INTO accounting_accounts (code, name, account_type, nature, is_system)
             VALUES (?, ?, ?, ?, 1)"
        );
        foreach ($accounts as $a) {
            $ins->execute($a);
        }

        $out = [];
        foreach ($defaults as $key => $default) {
            $val = $this->setting($key, $default);
            $out[$key] = str_ends_with($key, '_account') ? $val : (float)$val;
        }
        $cache = $out;
        $cacheOid = $oid;
        return $out;
    }

    private function setting(string $key, string $default): string
    {
        $row = $this->query(
            'SELECT setting_value FROM accounting_settings WHERE setting_key = ? LIMIT 1',
            [$key]
        )->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    }

    /** @var array<string, true>|null */
    private ?array $employeeColsCache = null;

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

    public function employees(string $status = '', string $q = ''): array
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
        ];

        if ($id > 0) {
            $this->query(
                "UPDATE employees SET document_type=?, document_number=?, first_name=?, last_name=?,
                    email=?, phone=?, position_title=?, hire_date=?, salary=?, contract_type=?,
                    payment_method=?, bank_account=?, has_transport_aid=?, status=?, notes=?
                 WHERE id=?",
                array_merge($fields, [$id])
            );
            return $id;
        }

        $this->query(
            "INSERT INTO employees
                (document_type, document_number, first_name, last_name, email, phone, position_title,
                 hire_date, salary, contract_type, payment_method, bank_account, has_transport_aid, status, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            $fields
        );
        return (int)$this->db->lastInsertId();
    }

    /**
     * @param array{day_hours?:float,night_hours?:float,dominical_hours?:float} $otCfg
     * @return array{day_hours:float,night_hours:float,dominical_hours:float,day_amount:float,night_amount:float,dominical_amount:float,total:float,hourly_rate:float}
     */
    private function calculateOvertime(float $salary, array $otCfg, array $params): array
    {
        $monthlyHours = max(1.0, (float)($params['payroll_monthly_hours'] ?? 240));
        $hourly = $salary / $monthlyHours;
        $dayHours = max(0.0, round((float)($otCfg['day_hours'] ?? 0), 2));
        $nightHours = max(0.0, round((float)($otCfg['night_hours'] ?? 0), 2));
        $domHours = max(0.0, round((float)($otCfg['dominical_hours'] ?? 0), 2));
        $hedRate = (float)($params['overtime_day_rate'] ?? 25);
        $henRate = (float)($params['overtime_night_rate'] ?? 75);
        $domRate = (float)($params['dominical_rate'] ?? 75);

        $dayAmount = round($dayHours * $hourly * (1 + $hedRate / 100), 2);
        $nightAmount = round($nightHours * $hourly * (1 + $henRate / 100), 2);
        $domAmount = round($domHours * $hourly * (1 + $domRate / 100), 2);
        $total = round($dayAmount + $nightAmount + $domAmount, 2);

        return [
            'day_hours' => $dayHours,
            'night_hours' => $nightHours,
            'dominical_hours' => $domHours,
            'day_amount' => $dayAmount,
            'night_amount' => $nightAmount,
            'dominical_amount' => $domAmount,
            'total' => $total,
            'hourly_rate' => round($hourly, 2),
        ];
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
            // Prima de servicios semestre: salario * 180/360 ≈ medio salario (semestre completo)
            $prima = round($salary / 2, 2);
        }

        $cesantias = 0.0;
        $cesInterest = 0.0;
        if (!empty($options['include_cesantias'])) {
            // Provisión mensual típica: salario/12 + interés aprox. 1% mensual sobre provisión
            $cesantias = round($salary / 12, 2);
            $cesInterest = round($cesantias * 0.12 / 12, 2);
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

        $gross = round($base + $transport + $prima + $cesantias + $cesInterest + $incapPay + $ot['total'], 2);
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
            'prima' => $prima,
            'cesantias' => $cesantias,
            'cesantias_interest' => $cesInterest,
            'overtime_day_hours' => $ot['day_hours'],
            'overtime_day_amount' => $ot['day_amount'],
            'overtime_night_hours' => $ot['night_hours'],
            'overtime_night_amount' => $ot['night_amount'],
            'dominical_hours' => $ot['dominical_hours'],
            'dominical_amount' => $ot['dominical_amount'],
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
     * @param array{include_prima?:bool,include_cesantias?:bool,include_parafiscales?:bool,incapacity?:array} $options
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
        $includePara = array_key_exists('include_parafiscales', $options)
            ? !empty($options['include_parafiscales'])
            : true;

        $calcOpts = [
            'include_prima' => $includePrima,
            'include_cesantias' => $includeCes,
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
                     payment_method, status, notes, user_id, include_prima, include_cesantias, include_parafiscales)
                 VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?)",
                [
                    $number, $year, $month, $label, $payDate, $daysWorked, $paymentMethod,
                    $notes ?: null, $userId, $includePrima ? 1 : 0, $includeCes ? 1 : 0, $includePara ? 1 : 0,
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
                 incapacity_pay, incapacity_type, transport_aid, prima, cesantias, cesantias_interest,
                 other_earnings, overtime_day_hours, overtime_day_amount, overtime_night_hours, overtime_night_amount,
                 dominical_hours, dominical_amount,
                 health_employee, pension_employee, other_deductions,
                 health_employer, pension_employer, arl_employer, caja_employer, sena_employer, icbf_employer,
                 gross_pay, net_pay)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $gross = $ded = $net = $employer = $primaT = $cesT = $incT = $paraT = $otT = 0.0;
        foreach ($employees as $emp) {
            $line = $this->calculateEmployeeLine($emp, $daysWorked, $calcOpts);
            $ins->execute([
                $runId, $line['employee_id'], $line['employee_name'], $line['salary_base'], $line['days_worked'],
                $line['incapacity_days'], $line['incapacity_pay'], $line['incapacity_type'],
                $line['transport_aid'], $line['prima'], $line['cesantias'], $line['cesantias_interest'],
                $line['other_earnings'], $line['overtime_day_hours'], $line['overtime_day_amount'],
                $line['overtime_night_hours'], $line['overtime_night_amount'],
                $line['dominical_hours'], $line['dominical_amount'],
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
            $incT += $line['incapacity_pay'];
            $otT += $line['other_earnings'];
        }

        $this->query(
            "UPDATE payroll_runs SET gross_total=?, deductions_total=?, net_total=?, employer_total=?,
                prima_total=?, cesantias_total=?, incapacity_total=?, overtime_total=?, parafiscal_total=? WHERE id=?",
            [
                round($gross, 2), round($ded, 2), round($net, 2), round($employer, 2),
                round($primaT, 2), round($cesT, 2), round($incT, 2), round($otT, 2), round($paraT, 2), $runId,
            ]
        );
    }

    /** Recalcula una línea en borrador conservando incapacidad y HE/dominicales salvo override. */
    private function recalcPayrollItem(int $itemId, ?array $incapOverride = null, ?array $otOverride = null): void
    {
        $item = $this->query(
            "SELECT pi.*, pr.status, pr.days_worked AS run_days, pr.include_prima, pr.include_cesantias, pr.include_parafiscales
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
            'dominical_hours' => (float)($item['dominical_hours'] ?? 0),
        ];

        $line = $this->calculateEmployeeLine($emp, (int)$item['run_days'], [
            'include_prima' => !empty($item['include_prima']),
            'include_cesantias' => !empty($item['include_cesantias']),
            'include_parafiscales' => !empty($item['include_parafiscales']),
            'incapacity' => [(int)$emp['id'] => ['days' => $incapDays, 'type' => $incapType]],
            'overtime' => [(int)$emp['id'] => $ot],
        ]);
        $this->query(
            "UPDATE payroll_items SET salary_base=?, days_worked=?, incapacity_days=?, incapacity_pay=?, incapacity_type=?,
                transport_aid=?, prima=?, cesantias=?, cesantias_interest=?, other_earnings=?,
                overtime_day_hours=?, overtime_day_amount=?, overtime_night_hours=?, overtime_night_amount=?,
                dominical_hours=?, dominical_amount=?,
                health_employee=?, pension_employee=?, health_employer=?, pension_employer=?, arl_employer=?,
                caja_employer=?, sena_employer=?, icbf_employer=?, gross_pay=?, net_pay=? WHERE id=?",
            [
                $line['salary_base'], $line['days_worked'], $line['incapacity_days'], $line['incapacity_pay'], $line['incapacity_type'],
                $line['transport_aid'], $line['prima'], $line['cesantias'], $line['cesantias_interest'], $line['other_earnings'],
                $line['overtime_day_hours'], $line['overtime_day_amount'], $line['overtime_night_hours'], $line['overtime_night_amount'],
                $line['dominical_hours'], $line['dominical_amount'],
                $line['health_employee'], $line['pension_employee'], $line['health_employer'], $line['pension_employer'],
                $line['arl_employer'], $line['caja_employer'], $line['sena_employer'], $line['icbf_employer'],
                $line['gross_pay'], $line['net_pay'], $itemId,
            ]
        );
        $this->recalcRunTotals((int)$item['payroll_run_id']);
    }

    /** Actualiza incapacidad de un ítem en borrador y recalcula la línea. */
    public function updateItemIncapacity(int $itemId, int $incapDays, string $incapType = 'enfermedad'): void
    {
        $this->recalcPayrollItem($itemId, ['days' => $incapDays, 'type' => $incapType], null);
    }

    /** Actualiza horas extras y dominicales de un ítem en borrador. */
    public function updateItemOvertime(int $itemId, float $dayHours, float $nightHours, float $dominicalHours): void
    {
        $this->recalcPayrollItem($itemId, null, [
            'day_hours' => max(0, $dayHours),
            'night_hours' => max(0, $nightHours),
            'dominical_hours' => max(0, $dominicalHours),
        ]);
    }

    private function recalcRunTotals(int $runId): void
    {
        $sum = $this->query(
            "SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(health_employee+pension_employee+other_deductions),0) d,
                    COALESCE(SUM(net_pay),0) n,
                    COALESCE(SUM(health_employer+pension_employer+arl_employer),0) e,
                    COALESCE(SUM(prima),0) p, COALESCE(SUM(cesantias+cesantias_interest),0) c,
                    COALESCE(SUM(incapacity_pay),0) i, COALESCE(SUM(other_earnings),0) ot,
                    COALESCE(SUM(caja_employer+sena_employer+icbf_employer),0) pf
             FROM payroll_items WHERE payroll_run_id=?",
            [$runId]
        )->fetch();
        $this->query(
            "UPDATE payroll_runs SET gross_total=?, deductions_total=?, net_total=?, employer_total=?,
                prima_total=?, cesantias_total=?, incapacity_total=?, overtime_total=?, parafiscal_total=? WHERE id=?",
            [
                (float)$sum['g'], (float)$sum['d'], (float)$sum['n'], (float)$sum['e'],
                (float)$sum['p'], (float)$sum['c'], (float)$sum['i'], (float)$sum['ot'], (float)$sum['pf'], $runId,
            ]
        );
    }

    public function runs(int $limit = 24): array
    {
        return $this->query(
            "SELECT * FROM payroll_runs ORDER BY period_year DESC, period_month DESC LIMIT " . (int)$limit
        )->fetchAll();
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
