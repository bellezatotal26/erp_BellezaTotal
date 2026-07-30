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
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function params(): array
    {
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

    public function employees(string $status = '', string $q = ''): array
    {
        $where = ['1=1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR document_number LIKE ? OR position_title LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        return $this->query(
            'SELECT * FROM employees WHERE ' . implode(' AND ', $where)
            . ' ORDER BY last_name, first_name',
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
     * @param array{include_prima?:bool,include_cesantias?:bool,include_parafiscales?:bool,incapacity?:array<int,array{days?:int,type?:string}>} $options
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

        // Base cotización: salario devengado (sin auxilio transporte ni prima excepcional simplificada)
        $ibc = $base > 0 ? $base : round($daily * max(1, $incapDays > 0 ? min($incapDays, $periodDays) : $periodDays), 2);

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

        $gross = round($base + $transport + $prima + $cesantias + $cesInterest + $incapPay, 2);
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
            'other_earnings' => 0.0,
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
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertItems(int $runId, array $employees, int $daysWorked, array $calcOpts): void
    {
        $ins = $this->db->prepare(
            "INSERT INTO payroll_items
                (payroll_run_id, employee_id, employee_name, salary_base, days_worked, incapacity_days,
                 incapacity_pay, incapacity_type, transport_aid, prima, cesantias, cesantias_interest,
                 other_earnings, health_employee, pension_employee, other_deductions,
                 health_employer, pension_employer, arl_employer, caja_employer, sena_employer, icbf_employer,
                 gross_pay, net_pay)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $gross = $ded = $net = $employer = $primaT = $cesT = $incT = $paraT = 0.0;
        foreach ($employees as $emp) {
            $line = $this->calculateEmployeeLine($emp, $daysWorked, $calcOpts);
            $ins->execute([
                $runId, $line['employee_id'], $line['employee_name'], $line['salary_base'], $line['days_worked'],
                $line['incapacity_days'], $line['incapacity_pay'], $line['incapacity_type'],
                $line['transport_aid'], $line['prima'], $line['cesantias'], $line['cesantias_interest'],
                $line['other_earnings'], $line['health_employee'], $line['pension_employee'], $line['other_deductions'],
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
        }

        $this->query(
            "UPDATE payroll_runs SET gross_total=?, deductions_total=?, net_total=?, employer_total=?,
                prima_total=?, cesantias_total=?, incapacity_total=?, parafiscal_total=? WHERE id=?",
            [
                round($gross, 2), round($ded, 2), round($net, 2), round($employer, 2),
                round($primaT, 2), round($cesT, 2), round($incT, 2), round($paraT, 2), $runId,
            ]
        );
    }

    /** Actualiza incapacidad de un ítem en borrador y recalcula la línea. */
    public function updateItemIncapacity(int $itemId, int $incapDays, string $incapType = 'enfermedad'): void
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
            throw new \RuntimeException('Solo se edita incapacidad en liquidaciones en borrador');
        }
        $emp = $this->query('SELECT * FROM employees WHERE id=?', [(int)$item['employee_id']])->fetch();
        if (!$emp) {
            throw new \RuntimeException('Empleado no encontrado');
        }
        $line = $this->calculateEmployeeLine($emp, (int)$item['run_days'], [
            'include_prima' => !empty($item['include_prima']),
            'include_cesantias' => !empty($item['include_cesantias']),
            'include_parafiscales' => !empty($item['include_parafiscales']),
            'incapacity' => [(int)$emp['id'] => ['days' => $incapDays, 'type' => $incapType]],
        ]);
        $this->query(
            "UPDATE payroll_items SET salary_base=?, days_worked=?, incapacity_days=?, incapacity_pay=?, incapacity_type=?,
                transport_aid=?, prima=?, cesantias=?, cesantias_interest=?, health_employee=?, pension_employee=?,
                health_employer=?, pension_employer=?, arl_employer=?, caja_employer=?, sena_employer=?, icbf_employer=?,
                gross_pay=?, net_pay=? WHERE id=?",
            [
                $line['salary_base'], $line['days_worked'], $line['incapacity_days'], $line['incapacity_pay'], $line['incapacity_type'],
                $line['transport_aid'], $line['prima'], $line['cesantias'], $line['cesantias_interest'],
                $line['health_employee'], $line['pension_employee'], $line['health_employer'], $line['pension_employer'],
                $line['arl_employer'], $line['caja_employer'], $line['sena_employer'], $line['icbf_employer'],
                $line['gross_pay'], $line['net_pay'], $itemId,
            ]
        );
        $this->recalcRunTotals((int)$item['payroll_run_id']);
    }

    private function recalcRunTotals(int $runId): void
    {
        $sum = $this->query(
            "SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(health_employee+pension_employee+other_deductions),0) d,
                    COALESCE(SUM(net_pay),0) n,
                    COALESCE(SUM(health_employer+pension_employer+arl_employer),0) e,
                    COALESCE(SUM(prima),0) p, COALESCE(SUM(cesantias+cesantias_interest),0) c,
                    COALESCE(SUM(incapacity_pay),0) i, COALESCE(SUM(caja_employer+sena_employer+icbf_employer),0) pf
             FROM payroll_items WHERE payroll_run_id=?",
            [$runId]
        )->fetch();
        $this->query(
            "UPDATE payroll_runs SET gross_total=?, deductions_total=?, net_total=?, employer_total=?,
                prima_total=?, cesantias_total=?, incapacity_total=?, parafiscal_total=? WHERE id=?",
            [
                (float)$sum['g'], (float)$sum['d'], (float)$sum['n'], (float)$sum['e'],
                (float)$sum['p'], (float)$sum['c'], (float)$sum['i'], (float)$sum['pf'], $runId,
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

    public function postAndPay(int $runId, bool $affectCash = false): void
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
                    'Liquidación ' . $run['run_number'] . ' · bruto ' . $run['gross_total']
                        . ' · aportes emp. ' . $run['employer_total']
                        . ' · parafiscales ' . ($run['parafiscal_total'] ?? 0),
                    $run['user_id'] ?? ($_SESSION['tenant_user_id'] ?? null),
                ]
            );
            $expenseId = (int)$this->db->lastInsertId();

            $accounting = new AccountingService($this->db);
            try {
                $accounting->postPayrollRun($runId, $affectCash && ($run['payment_method'] ?? '') === 'cash');
            } catch (\Throwable $e) {
                error_log('Payroll detailed accounting: ' . $e->getMessage());
                // Fallback: asiento simple del gasto neto
                try {
                    $accounting->postExpense($expenseId, $affectCash && ($run['payment_method'] ?? '') === 'cash');
                } catch (\Throwable $e2) {
                    error_log('Payroll expense fallback: ' . $e2->getMessage());
                }
            }

            $this->query(
                "UPDATE payroll_runs SET status='paid', expense_id=? WHERE id=?",
                [$expenseId, $runId]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
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
