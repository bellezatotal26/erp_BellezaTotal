<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\PayrollPilaService;
use SoftNova\Services\PayrollService;
use SoftNova\Services\PdfService;
use SoftNova\Services\TenantOpsSchema;

/**
 * Módulo Nómina: empleados, liquidaciones, prestaciones y PDF.
 */
class TenantNominaController extends TenantController
{
    private PayrollService $payroll;

    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('nomina');
        TenantOpsSchema::ensure($this->db);
        $this->payroll = new PayrollService($this->db);
    }

    public function index(): void
    {
        $action = (string)$this->request->get('action', '');

        if ($this->request->method() === 'POST') {
            if (!$this->validateCsrfOrFail('/app/nomina')) {
                return;
            }
            match ($action) {
                'save_employee' => $this->saveEmployee(),
                'create_run' => $this->createRun(),
                'pay_run' => $this->payRun(),
                'cancel_run' => $this->cancelRun(),
                'cancel_paid_run' => $this->cancelPaidRun(),
                'save_liability_payment' => $this->saveLiabilityPayment(),
                'cancel_liability_payment' => $this->cancelLiabilityPayment(),
                'save_params' => $this->saveParams(),
                'save_incapacity' => $this->saveIncapacity(),
                'save_novelties' => $this->saveNovelties(),
                'save_overtime' => $this->saveOvertime(),
                'save_overtime_bulk' => $this->saveOvertimeBulk(),
                'save_schedule' => $this->saveSchedule(),
                'delete_schedule' => $this->deleteSchedule(),
                'assign_schedule' => $this->assignSchedule(),
                default => $this->respond(false, 'Acción inválida', '/app/nomina'),
            };
            return;
        }

        if ($action === 'run_detail') {
            $this->runDetail();
            return;
        }
        if ($action === 'pdf') {
            $this->payrollPdf();
            return;
        }
        if ($action === 'payslip') {
            $this->payslipPdf();
            return;
        }
        if ($action === 'export_employees') {
            $this->exportEmployees();
            return;
        }
        if ($action === 'export_runs') {
            $this->exportRuns();
            return;
        }
        if ($action === 'export_run_cost') {
            $this->exportRunCost();
            return;
        }
        if ($action === 'pila_preview') {
            $this->pilaPreview();
            return;
        }
        if ($action === 'export_pila') {
            $this->exportPila();
            return;
        }

        $tab = (string)$this->request->get('tab', 'employees');
        if (!in_array($tab, ['employees', 'schedules', 'runs', 'payments', 'params'], true)) {
            $tab = 'employees';
        }
        $liabilities = [];
        $liabilityPayments = [];
        if ($tab === 'payments') {
            try {
                $liabilities = $this->payroll->liabilitySummary();
                $liabilityPayments = $this->payroll->liabilityPayments(100);
            } catch (\Throwable $e) {
                error_log('Nomina pagos: ' . $e->getMessage());
                $_SESSION['error'] = 'No se pudieron cargar los pagos de nómina: ' . $e->getMessage();
            }
        }

        $empFilters = $this->employeeFilters();
        $runFilters = $this->runFilters();
        $employees = [];
        $activeEmployees = [];
        $runs = [];
        $runYears = [];
        $params = [];
        $schedules = [];
        $scheduleCount = 0;
        $q = $empFilters['q'];
        $status = $empFilters['status'];
        $activeCount = 0;
        $monthPayroll = 0.0;

        try {
            // Datos livianos del encabezado (todas las pestañas).
            $params = $this->payroll->params();
            $activeCount = $this->payroll->countEmployees('active');
            $scheduleCount = $this->payroll->countSchedules();
            $monthPayroll = $this->payroll->monthNetPayroll((int)date('Y'), (int)date('n'));

            // Carga bajo demanda: evita schedules/empleados/runs pesados fuera de su pestaña.
            if ($tab === 'employees') {
                $employees = $this->payroll->employees($status, $q, $empFilters['schedule']);
                $schedules = $this->payroll->schedules();
                $scheduleCount = count($schedules);
            } elseif ($tab === 'schedules') {
                $employees = $this->payroll->employees('active');
                $activeEmployees = $employees;
                $schedules = $this->payroll->schedules();
                $scheduleCount = count($schedules);
            } elseif ($tab === 'runs') {
                $activeEmployees = $this->payroll->employees('active');
                $schedules = $this->payroll->schedules(); // flags RNO/HE del modal
                $scheduleCount = count($schedules);
                $runYears = $this->payroll->runYears();
                $runs = ($runFilters['year'] > 0 || $runFilters['status'] !== '')
                    ? $this->payroll->runs(120, $runFilters['year'], $runFilters['status'])
                    : $this->payroll->runs(36);
            } elseif ($tab === 'params') {
                $employees = $this->payroll->employees('active'); // comisiones fijas
            }
        } catch (\Throwable $e) {
            error_log('Nomina index: ' . $e->getMessage());
            $_SESSION['error'] = 'No se pudo cargar nómina: ' . $e->getMessage();
        }

        $this->view('tenant.nomina', $this->tenantViewData([
            'tab' => $tab,
            'employees' => $employees,
            'activeEmployees' => $activeEmployees,
            'runs' => $runs,
            'params' => $params,
            'schedules' => $schedules,
            'scheduleCount' => $scheduleCount,
            'q' => $q,
            'statusFilter' => $status,
            'scheduleFilter' => $empFilters['schedule'],
            'runFilters' => $runFilters,
            'runYears' => $runYears,
            'activeCount' => $activeCount,
            'monthPayroll' => $monthPayroll,
            'liabilities' => $liabilities,
            'liabilityPayments' => $liabilityPayments,
            'pilaCatalogs' => PayrollPilaService::catalogs(),
        ]));
    }

    public const RUN_STATUS_LABELS = [
        'draft' => 'Borrador', 'paid' => 'Pagada', 'posted' => 'Contabilizada', 'cancelled' => 'Cancelada',
    ];

    private const CONTRACT_LABELS = [
        'indefinido' => 'Indefinido', 'fijo' => 'Término fijo', 'obra' => 'Obra/labor', 'prestacion' => 'Prestación de servicios',
    ];

    /** @return array{q:string,status:string,schedule:?int} */
    private function employeeFilters(): array
    {
        $status = (string)$this->request->get('status', '');
        $sched = trim((string)$this->request->get('schedule', ''));
        return [
            'q' => mb_substr(trim((string)$this->request->get('q', '')), 0, 80),
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
            'schedule' => $sched === '' ? null : max(0, (int)$sched),
        ];
    }

    /** @return array{year:int,status:string} */
    private function runFilters(): array
    {
        $year = (int)$this->request->get('year', 0);
        $status = (string)$this->request->get('run_status', '');
        return [
            'year' => ($year >= 2000 && $year <= 2100) ? $year : 0,
            'status' => isset(self::RUN_STATUS_LABELS[$status]) ? $status : '',
        ];
    }

    private function prepareExportResponse(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(300);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private function exportError(string $message, \Throwable $e): void
    {
        error_log($message . ': ' . $e->getMessage());
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message . '. ' . $this->clientErrorMessage($e, 'Intente de nuevo.');
        exit;
    }

    private function exportEmployees(): void
    {
        $format = (string)$this->request->get('format', 'csv') === 'pdf' ? 'pdf' : 'csv';
        $this->prepareExportResponse();
        try {
            $f = $this->employeeFilters();
            $employees = $this->payroll->employees($f['status'], $f['q'], $f['schedule']);
            $schedules = $this->payroll->schedules();
            $filterText = [];
            if ($f['q'] !== '') {
                $filterText[] = 'Búsqueda: ' . $f['q'];
            }
            if ($f['status'] !== '') {
                $filterText[] = 'Estado: ' . ($f['status'] === 'active' ? 'Activos' : 'Inactivos');
            }
            if ($f['schedule'] !== null) {
                $filterText[] = 'Horario: ' . ($f['schedule'] > 0 ? ($schedules[$f['schedule']]['name'] ?? '#' . $f['schedule']) : 'Sin horario');
            }

            if ($format === 'pdf') {
                $pdf = new PdfService($this->companySettings(), $this->getCurrency());
                $content = $pdf->generatePayrollEmployeesReport($employees, $schedules, $filterText);
                $pdf->download($content, 'empleados_' . date('Ymd_His') . '.pdf');
                return;
            }

            $rows = [];
            foreach ($employees as $e) {
                $rows[] = [
                    $e['document_type'] ?? '',
                    $e['document_number'] ?? '',
                    $e['first_name'] ?? '',
                    $e['last_name'] ?? '',
                    $e['position_title'] ?? '',
                    $e['hire_date'] ?? '',
                    self::CONTRACT_LABELS[$e['contract_type'] ?? ''] ?? ($e['contract_type'] ?? ''),
                    $e['salary'] ?? 0,
                    $e['fixed_commission'] ?? 0,
                    !empty($e['has_transport_aid']) ? 'Sí' : 'No',
                    $schedules[(int)($e['schedule_id'] ?? 0)]['name'] ?? 'Sin horario',
                    \SoftNova\Core\payment_method_label((string)($e['payment_method'] ?? '')),
                    $e['bank_account'] ?? '',
                    $e['email'] ?? '',
                    $e['phone'] ?? '',
                    ($e['status'] ?? '') === 'active' ? 'Activo' : 'Inactivo',
                ];
            }
        } catch (\Throwable $e) {
            $this->exportError('No se pudo exportar empleados', $e);
            return;
        }
        $this->exportCsv('empleados_' . date('Ymd_His') . '.csv', [
            'Tipo doc', 'Documento', 'Nombres', 'Apellidos', 'Cargo', 'Fecha ingreso', 'Contrato', 'Salario',
            'Comision fija quincenal', 'Aux. transporte', 'Horario', 'Medio de pago', 'Cuenta bancaria', 'Email', 'Telefono', 'Estado',
        ], $rows);
    }

    private function exportRuns(): void
    {
        $format = (string)$this->request->get('format', 'csv') === 'pdf' ? 'pdf' : 'csv';
        $this->prepareExportResponse();
        try {
            $f = $this->runFilters();
            $runs = $this->payroll->runs(500, $f['year'], $f['status']);
            $filterText = [];
            if ($f['year'] > 0) {
                $filterText[] = 'Año: ' . $f['year'];
            }
            if ($f['status'] !== '') {
                $filterText[] = 'Estado: ' . self::RUN_STATUS_LABELS[$f['status']];
            }

            if ($format === 'pdf') {
                $pdf = new PdfService($this->companySettings(), $this->getCurrency());
                $content = $pdf->generatePayrollRunsReport($runs, $filterText, self::RUN_STATUS_LABELS);
                $pdf->download($content, 'liquidaciones_nomina_' . date('Ymd_His') . '.pdf');
                return;
            }

            $rows = [];
            foreach ($runs as $r) {
                $rows[] = [
                    $r['run_number'],
                    $r['period_label'],
                    $r['pay_date'],
                    (int)$r['days_worked'],
                    (int)($r['employee_count'] ?? 0),
                    self::RUN_STATUS_LABELS[$r['status']] ?? $r['status'],
                    $r['gross_total'] ?? 0,
                    $r['deductions_total'] ?? 0,
                    $r['net_total'] ?? 0,
                    $r['employer_total'] ?? 0,
                    $r['parafiscal_total'] ?? 0,
                    $r['prima_total'] ?? 0,
                    $r['cesantias_total'] ?? 0,
                    $r['vacation_total'] ?? 0,
                    $r['incapacity_total'] ?? 0,
                    $r['overtime_total'] ?? 0,
                    $r['commission_total'] ?? 0,
                    \SoftNova\Core\payment_method_label((string)($r['payment_method'] ?? '')),
                ];
            }
        } catch (\Throwable $e) {
            $this->exportError('No se pudo exportar liquidaciones', $e);
            return;
        }
        $this->exportCsv('liquidaciones_nomina_' . date('Ymd_His') . '.csv', [
            'Numero', 'Periodo', 'Fecha pago', 'Dias', 'Empleados', 'Estado', 'Bruto', 'Deducciones', 'Neto',
            'Aportes empleador', 'Parafiscales', 'Primas', 'Cesantias + intereses', 'Vacaciones', 'Incapacidades',
            'HE / recargos', 'Comisiones', 'Medio de pago',
        ], $rows);
    }

    private function saveEmployee(): void
    {
        $id = (int)$this->request->post('id', 0);
        TenantMiddleware::authorize('nomina', $id > 0 ? 'edit' : 'create');
        try {
            $this->payroll->saveEmployee([
                'id' => $id,
                'document_type' => $this->request->post('document_type', 'CC'),
                'document_number' => $this->request->post('document_number', ''),
                'first_name' => $this->request->post('first_name', ''),
                'last_name' => $this->request->post('last_name', ''),
                'email' => $this->request->post('email', ''),
                'phone' => $this->request->post('phone', ''),
                'position_title' => $this->request->post('position_title', ''),
                'hire_date' => $this->request->post('hire_date', ''),
                'salary' => $this->request->post('salary', 0),
                'contract_type' => $this->request->post('contract_type', 'indefinido'),
                'payment_method' => $this->request->post('payment_method', 'transfer'),
                'bank_account' => $this->request->post('bank_account', ''),
                'has_transport_aid' => $this->request->post('has_transport_aid', '0') === '1',
                'arl_rate' => $this->request->post('arl_rate', ''),
                'exempt_1141' => $this->request->post('exempt_1141', '0') === '1',
                'eps_code' => $this->request->post('eps_code', ''),
                'afp_code' => $this->request->post('afp_code', ''),
                'arl_code' => $this->request->post('arl_code', ''),
                'caja_code' => $this->request->post('caja_code', ''),
                'cotizante_type' => $this->request->post('cotizante_type', '1'),
                'cotizante_subtype' => $this->request->post('cotizante_subtype', '00'),
                'status' => $this->request->post('status', 'active'),
                'notes' => $this->request->post('notes', ''),
                'schedule_id' => (int)$this->request->post('schedule_id', 0),
            ]);
            $this->respond(true, 'Empleado guardado', '/app/nomina?tab=employees');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=employees');
        }
    }

    private function createRun(): void
    {
        TenantMiddleware::authorize('nomina', 'create');
        try {
            $year = (int)$this->request->post('period_year', date('Y'));
            $month = (int)$this->request->post('period_month', date('n'));
            $incapacity = [];
            $rawDays = $this->request->post('incapacity_days', []);
            $rawTypes = $this->request->post('incapacity_type', []);
            if (is_array($rawDays)) {
                foreach ($rawDays as $empId => $days) {
                    $d = (int)$days;
                    if ($d > 0) {
                        $incapacity[(int)$empId] = [
                            'days' => $d,
                            'type' => is_array($rawTypes) ? (string)($rawTypes[$empId] ?? 'enfermedad') : 'enfermedad',
                        ];
                    }
                }
            }
            $rawEmpIds = $this->request->post('employee_ids', []);
            $employeeIds = [];
            if (is_array($rawEmpIds)) {
                foreach ($rawEmpIds as $rawId) {
                    $id = (int)$rawId;
                    if ($id > 0) {
                        $employeeIds[] = $id;
                    }
                }
            }
            $employeeIds = array_values(array_unique($employeeIds));
            if (!$employeeIds) {
                throw new \RuntimeException('Seleccione al menos un empleado para liquidar');
            }
            $runId = $this->payroll->createRun(
                $year,
                $month,
                (string)$this->request->post('pay_date', date('Y-m-d')),
                (int)$this->request->post('days_worked', 30),
                (string)$this->request->post('payment_method', 'transfer'),
                (int)($_SESSION['tenant_user_id'] ?? 0) ?: null,
                trim((string)$this->request->post('notes', '')),
                [
                    'include_prima' => $this->request->post('include_prima') === '1',
                    'include_cesantias' => $this->request->post('include_cesantias') === '1',
                    'include_vacaciones' => $this->request->post('include_vacaciones') === '1',
                    'include_parafiscales' => $this->request->post('include_parafiscales', '1') === '1',
                    'schedule_rno' => $this->request->post('schedule_rno') === '1',
                    'schedule_ot' => $this->request->post('schedule_ot') === '1',
                    'incapacity' => $incapacity,
                    'employee_ids' => $employeeIds,
                ]
            );
            $this->respond(true, 'Liquidación creada', '/app/nomina?tab=runs&action=run_detail&id=' . $runId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function saveIncapacity(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $itemId = (int)$this->request->post('item_id', 0);
            $runId = (int)$this->request->post('run_id', 0);
            $this->payroll->updateItemIncapacity(
                $itemId,
                (int)$this->request->post('incapacity_days', 0),
                (string)$this->request->post('incapacity_type', 'enfermedad')
            );
            $this->respond(true, 'Incapacidad actualizada', '/app/nomina?tab=runs&action=run_detail&id=' . $runId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function saveNovelties(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $itemId = (int)$this->request->post('item_id', 0);
            $runId = (int)$this->request->post('run_id', 0);
            $rawOther = preg_replace('/[^\d.,\-]/', '', trim((string)$this->request->post('other_deductions', '0'))) ?? '0';
            if ($rawOther !== '' && str_contains($rawOther, ',')) {
                $other = (float)str_replace(['.', ','], ['', '.'], $rawOther);
            } elseif ($rawOther !== '') {
                $other = (float)$rawOther;
            } else {
                $other = 0.0;
            }
            $this->payroll->updateItemNovelties($itemId, [
                'unpaid_days' => (int)$this->request->post('unpaid_days', 0),
                'vacation_days' => (int)$this->request->post('vacation_days', 0),
                'other_deductions' => max(0.0, $other),
            ]);
            $this->respond(true, 'Novedades actualizadas', '/app/nomina?tab=runs&action=run_detail&id=' . $runId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function exportRunCost(): void
    {
        TenantMiddleware::authorize('nomina', 'view');
        $runId = (int)$this->request->get('id', 0);
        $detail = $this->payroll->runDetail($runId);
        if (!$detail) {
            $this->respond(false, 'Liquidación no encontrada', '/app/nomina?tab=runs');
            return;
        }
        $run = $detail['run'];
        $items = $detail['items'];
        $period = sprintf('%04d-%02d', (int)$run['period_year'], (int)$run['period_month']);
        $rows = [];
        foreach ($items as $it) {
            $employer = (float)($it['health_employer'] ?? 0) + (float)($it['pension_employer'] ?? 0)
                + (float)($it['arl_employer'] ?? 0) + (float)($it['caja_employer'] ?? 0)
                + (float)($it['sena_employer'] ?? 0) + (float)($it['icbf_employer'] ?? 0);
            $prov = (float)($it['cesantias'] ?? 0) + (float)($it['cesantias_interest'] ?? 0)
                + (float)($it['vacation_provision'] ?? 0);
            $ded = (float)($it['health_employee'] ?? 0) + (float)($it['pension_employee'] ?? 0)
                + (float)($it['fsp_employee'] ?? 0) + (float)($it['other_deductions'] ?? 0);
            $company = (float)($it['gross_pay'] ?? 0) + $employer + $prov;
            $rows[] = [
                $it['employee_name'] ?? '',
                (int)($it['days_worked'] ?? 0),
                (int)($it['unpaid_days'] ?? 0),
                (int)($it['vacation_days'] ?? 0),
                (int)($it['incapacity_days'] ?? 0),
                number_format((float)($it['salary_base'] ?? 0), 2, '.', ''),
                number_format((float)($it['vacation_pay'] ?? 0), 2, '.', ''),
                number_format((float)($it['transport_aid'] ?? 0), 2, '.', ''),
                number_format((float)($it['other_earnings'] ?? 0), 2, '.', ''),
                number_format((float)($it['incapacity_pay'] ?? 0), 2, '.', ''),
                number_format((float)($it['commission_amount'] ?? 0), 2, '.', ''),
                number_format((float)($it['prima'] ?? 0), 2, '.', ''),
                number_format((float)($it['gross_pay'] ?? 0), 2, '.', ''),
                number_format((float)($it['health_employee'] ?? 0), 2, '.', ''),
                number_format((float)($it['pension_employee'] ?? 0), 2, '.', ''),
                number_format((float)($it['fsp_employee'] ?? 0), 2, '.', ''),
                number_format((float)($it['other_deductions'] ?? 0), 2, '.', ''),
                number_format($ded, 2, '.', ''),
                number_format((float)($it['net_pay'] ?? 0), 2, '.', ''),
                number_format((float)($it['health_employer'] ?? 0), 2, '.', ''),
                number_format((float)($it['pension_employer'] ?? 0), 2, '.', ''),
                number_format((float)($it['arl_employer'] ?? 0), 2, '.', ''),
                number_format((float)($it['caja_employer'] ?? 0), 2, '.', ''),
                number_format((float)($it['sena_employer'] ?? 0), 2, '.', ''),
                number_format((float)($it['icbf_employer'] ?? 0), 2, '.', ''),
                number_format($employer, 2, '.', ''),
                number_format((float)($it['cesantias'] ?? 0), 2, '.', ''),
                number_format((float)($it['cesantias_interest'] ?? 0), 2, '.', ''),
                number_format((float)($it['vacation_provision'] ?? 0), 2, '.', ''),
                number_format($prov, 2, '.', ''),
                number_format($company, 2, '.', ''),
            ];
        }
        $this->exportCsv('costo_nomina_' . $period . '_' . date('Ymd_His') . '.csv', [
            'Empleado', 'Dias laborados', 'Dias no remunerados', 'Dias vacaciones', 'Dias incapacidad',
            'Salario', 'Vacaciones pagadas', 'Auxilio transporte', 'HE/recargos', 'Incapacidad', 'Comision', 'Prima',
            'Total devengado', 'Salud emp', 'Pension emp', 'FSP', 'Otras deducciones', 'Total deducciones', 'Neto',
            'Salud empresa', 'Pension empresa', 'ARL', 'Caja', 'SENA', 'ICBF', 'Total aportes empresa',
            'Cesantias', 'Intereses cesantias', 'Prov. vacaciones', 'Total provisiones', 'Costo empresa',
        ], $rows);
    }

    private function saveOvertime(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $itemId = (int)$this->request->post('item_id', 0);
            $runId = (int)$this->request->post('run_id', 0);
            $this->payroll->updateItemOvertime($itemId, [
                'day_hours' => (float)$this->request->post('overtime_day_hours', 0),
                'night_hours' => (float)$this->request->post('overtime_night_hours', 0),
                'night_surcharge_hours' => (float)$this->request->post('night_surcharge_hours', 0),
                'dominical_hours' => (float)$this->request->post('dominical_hours', 0),
                'dom_day_hours' => (float)$this->request->post('overtime_dom_day_hours', 0),
                'dom_night_hours' => (float)$this->request->post('overtime_dom_night_hours', 0),
            ]);
            $this->respond(true, 'Horas extras actualizadas', '/app/nomina?tab=runs&action=run_detail&id=' . $runId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function saveOvertimeBulk(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $runId = (int)$this->request->post('run_id', 0);
            $raw = $this->request->post('ot', []);
            if (!is_array($raw)) {
                $raw = [];
            }
            $byItem = [];
            foreach ($raw as $itemId => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $byItem[(int)$itemId] = [
                    'day_hours' => (float)($row['day'] ?? 0),
                    'night_hours' => (float)($row['night'] ?? 0),
                    'night_surcharge_hours' => (float)($row['rno'] ?? 0),
                    'dominical_hours' => (float)($row['dom'] ?? 0),
                    'dom_day_hours' => (float)($row['hedd'] ?? 0),
                    'dom_night_hours' => (float)($row['hend'] ?? 0),
                ];
            }
            $n = $this->payroll->updateRunOvertimeBulk($runId, $byItem);
            $msg = $n > 0 ? $n . ' empleado(s) con HE recalculadas' : 'Sin cambios en horas extras';
            $this->respond(true, $msg, '/app/nomina?tab=runs&action=run_detail&id=' . $runId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function payRun(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $id = (int)$this->request->post('id', 0);
            $result = $this->payroll->postAndPay($id);
            $entryId = (int)($result['accounting_entry_id'] ?? 0);
            $msg = 'Nómina contabilizada (asiento #' . $entryId . ') y marcada como pagada. Gasto registrado por el costo total: '
                . number_format((float)($result['company_cost'] ?? 0), 0, ',', '.')
                . '. No descuenta el cajón de caja.';
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'pay',
                'nomina',
                'Nómina pagada: ' . ($result['run_number'] ?? '') . ' · neto '
                    . number_format((float)($result['net_total'] ?? 0), 0, ',', '.')
                    . ' · costo total ' . number_format((float)($result['company_cost'] ?? 0), 0, ',', '.')
                    . ' · asiento contable #' . $entryId,
                'payroll_run',
                $id
            );
            \SoftNova\Services\TenantNotifyService::push(
                $this->db,
                'payroll_paid:' . $id . ':' . time(),
                'Nómina contabilizada y pagada',
                $msg,
                '/app/nomina?tab=runs&action=run_detail&id=' . $id,
                true,
                'payroll',
                'Nómina'
            );
            $this->respond(true, $msg, '/app/nomina?tab=runs&action=run_detail&id=' . $id, [
                'sticky_alert' => true,
            ]);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function cancelRun(): void
    {
        TenantMiddleware::authorize('nomina', 'delete');
        try {
            $this->payroll->cancelRun((int)$this->request->post('id', 0));
            $this->respond(true, 'Liquidación cancelada', '/app/nomina?tab=runs');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
        }
    }

    private function cancelPaidRun(): void
    {
        TenantMiddleware::authorize('nomina', 'delete');
        $id = (int)$this->request->post('id', 0);
        $back = '/app/nomina?tab=runs&action=run_detail&id=' . $id;
        try {
            $result = $this->payroll->cancelPaidRun($id, (string)$this->request->post('reason', ''));
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'cancel',
                'nomina',
                'Pago de nómina anulado: ' . $result['run_number'] . ' · motivo: '
                    . mb_substr(trim((string)$this->request->post('reason', '')), 0, 200)
                    . ' · reversiones: ' . (count($result['reversals']) ? '#' . implode(', #', $result['reversals']) : 'ninguna'),
                'payroll_run',
                $id
            );
            $this->respond(
                true,
                'Pago anulado: asientos revertidos y gasto anulado. La liquidación quedó en borrador para corregirla.',
                $back
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }

    private function saveLiabilityPayment(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $amounts = $this->request->post('amounts', []);
            $result = $this->payroll->createLiabilityPayment([
                'payment_date' => (string)$this->request->post('payment_date', date('Y-m-d')),
                'kind' => (string)$this->request->post('kind', 'pila'),
                'payment_method' => (string)$this->request->post('payment_method', 'transfer'),
                'reference' => (string)$this->request->post('reference', ''),
                'notes' => (string)$this->request->post('notes', ''),
                'amounts' => is_array($amounts) ? $amounts : [],
            ], (int)($_SESSION['tenant_user_id'] ?? 0) ?: null);
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'create',
                'nomina',
                'Pago de nómina registrado · ' . number_format($result['total'], 0, ',', '.')
                    . ' · asiento contable #' . $result['entry_id'],
                'payroll_payment',
                $result['id']
            );
            $this->respond(
                true,
                'Pago registrado (' . number_format($result['total'], 0, ',', '.') . ') y contabilizado. No genera gasto: el costo ya se causó en la nómina.',
                '/app/nomina?tab=payments'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=payments');
        }
    }

    private function cancelLiabilityPayment(): void
    {
        TenantMiddleware::authorize('nomina', 'delete');
        try {
            $id = (int)$this->request->post('id', 0);
            $result = $this->payroll->cancelLiabilityPayment($id, (string)$this->request->post('reason', ''));
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'cancel',
                'nomina',
                'Pago de nómina anulado · ' . number_format($result['total'], 0, ',', '.')
                    . ($result['reversal_id'] > 0 ? ' · reversión #' . $result['reversal_id'] : ''),
                'payroll_payment',
                $id
            );
            $this->respond(true, 'Pago anulado y asiento revertido', '/app/nomina?tab=payments');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=payments');
        }
    }

    private function saveParams(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $input = [];
            foreach (array_keys(PayrollService::PARAM_DEFAULTS) as $k) {
                $input[$k] = $this->request->post($k, '');
            }
            $this->payroll->saveParams($input);
            $commissions = $this->request->post('fixed_commission', []);
            $updated = is_array($commissions) ? $this->payroll->saveFixedCommissions($commissions) : 0;
            $msg = 'Parámetros de nómina guardados';
            if ($updated > 0) {
                $msg .= ' · ' . $updated . ' comisión(es) fija(s) actualizada(s)';
            }
            $this->respond(true, $msg, '/app/nomina?tab=params');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=params');
        }
    }

    private function saveSchedule(): void
    {
        $id = (int)$this->request->post('id', 0);
        TenantMiddleware::authorize('nomina', $id > 0 ? 'edit' : 'create');
        try {
            $days = $this->request->post('days', []);
            $this->payroll->saveSchedule([
                'id' => $id,
                'name' => $this->request->post('name', ''),
                'color' => $this->request->post('color', '#6366f1'),
                'notes' => $this->request->post('notes', ''),
                'days' => is_array($days) ? $days : [],
            ]);
            $this->respond(true, 'Horario guardado', '/app/nomina?tab=schedules');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=schedules');
        }
    }

    private function deleteSchedule(): void
    {
        TenantMiddleware::authorize('nomina', 'delete');
        try {
            $released = $this->payroll->deleteSchedule((int)$this->request->post('id', 0));
            $msg = 'Horario eliminado' . ($released > 0 ? ' (' . $released . ' empleado(s) quedaron sin horario)' : '');
            $this->respond(true, $msg, '/app/nomina?tab=schedules');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=schedules');
        }
    }

    private function assignSchedule(): void
    {
        TenantMiddleware::authorize('nomina', 'edit');
        try {
            $ids = $this->request->post('employee_ids', []);
            $scheduleId = (int)$this->request->post('schedule_id', 0);
            $n = $this->payroll->assignSchedule($scheduleId, is_array($ids) ? $ids : []);
            $msg = $scheduleId > 0
                ? 'Horario aplicado a ' . $n . ' empleado(s)'
                : 'Horario retirado de ' . $n . ' empleado(s)';
            $this->respond(true, $msg, '/app/nomina?tab=schedules');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=schedules');
        }
    }

    private function pilaPreview(): void
    {
        TenantMiddleware::authorize('nomina', 'view');
        $id = (int)$this->request->get('id', 0);
        try {
            $bundle = (new PayrollPilaService($this->db))->buildFromRun($id);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/nomina?tab=runs');
            return;
        }
        $schedules = [];
        try {
            $schedules = $this->payroll->schedules();
        } catch (\Throwable $e) {
            $schedules = [];
        }
        $this->view('tenant.nomina', $this->tenantViewData([
            'tab' => 'pila',
            'employees' => [],
            'activeEmployees' => [],
            'runs' => [],
            'params' => $this->payroll->params(),
            'schedules' => $schedules,
            'scheduleCount' => count($schedules),
            'detail' => null,
            'pila' => $bundle,
            'pilaCatalogs' => PayrollPilaService::catalogs(),
            'q' => '',
            'statusFilter' => '',
            'activeCount' => $this->payroll->countEmployees('active'),
            'monthPayroll' => $this->payroll->monthNetPayroll((int)date('Y'), (int)date('n')),
        ]));
    }

    private function exportPila(): void
    {
        TenantMiddleware::authorize('nomina', 'view');
        $id = (int)$this->request->get('id', 0);
        $format = (string)$this->request->get('format', 'csv') === 'txt' ? 'txt' : 'csv';
        $this->prepareExportResponse();
        try {
            $svc = new PayrollPilaService($this->db);
            $bundle = $svc->buildFromRun($id);
            $period = preg_replace('/\W+/', '', (string)$bundle['period']) ?: date('Ym');
            if ($format === 'txt') {
                $body = $svc->plainText($bundle);
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="pila_' . $period . '_' . date('Ymd_His') . '.txt"');
                echo $body;
                exit;
            }
            $this->exportCsv(
                'pila_' . $period . '_' . date('Ymd_His') . '.csv',
                $svc->csvHeaders(),
                $svc->csvRows($bundle)
            );
        } catch (\Throwable $e) {
            $this->exportError('No se pudo exportar PILA', $e);
        }
    }

    private function runDetail(): void
    {
        $id = (int)$this->request->get('id', 0);
        $detail = $this->payroll->runDetail($id);
        if (!$detail) {
            $this->respond(false, 'Liquidación no encontrada', '/app/nomina?tab=runs');
            return;
        }
        $accountingEntry = $this->query(
            "SELECT id, entry_number, entry_date FROM accounting_entries
             WHERE source_type = 'payroll' AND source_id = ? AND status = 'posted' AND source_event = 'created'
             ORDER BY id DESC LIMIT 1",
            [$id]
        )->fetch() ?: null;
        $paymentEntry = $this->query(
            "SELECT id, entry_number, entry_date FROM accounting_entries
             WHERE source_type = 'payroll' AND source_id = ? AND status = 'posted' AND source_event = 'payment'
             ORDER BY id DESC LIMIT 1",
            [$id]
        )->fetch() ?: null;

        $activeEmployees = [];
        try {
            $activeEmployees = $this->payroll->employees('active');
        } catch (\Throwable $e) {
            $activeEmployees = [];
        }

        $schedules = [];
        try {
            $schedules = $this->payroll->schedules();
        } catch (\Throwable $e) {
            $schedules = [];
        }

        $this->view('tenant.nomina', $this->tenantViewData([
            'tab' => 'run_detail',
            'employees' => [],
            'activeEmployees' => $activeEmployees,
            'runs' => [],
            'params' => $this->payroll->params(),
            'schedules' => $schedules,
            'scheduleCount' => count($schedules),
            'detail' => $detail,
            'accountingEntry' => $accountingEntry,
            'paymentEntry' => $paymentEntry,
            'pilaCatalogs' => PayrollPilaService::catalogs(),
            'q' => '',
            'statusFilter' => '',
            'activeCount' => count($activeEmployees),
            'monthPayroll' => $this->payroll->monthNetPayroll((int)date('Y'), (int)date('n')),
        ]));
    }

    private function payrollPdf(): void
    {
        $id = (int)$this->request->get('id', 0);
        $detail = $this->payroll->runDetail($id);
        if (!$detail) {
            $this->respond(false, 'Liquidación no encontrada', '/app/nomina?tab=runs');
            return;
        }
        $pdf = new PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generatePayrollRun($detail['run'], $detail['items']);
        $pdf->download($content, 'Nomina_' . ($detail['run']['run_number'] ?? $id) . '.pdf');
    }

    private function payslipPdf(): void
    {
        $runId = (int)$this->request->get('run_id', 0);
        $itemId = (int)$this->request->get('item_id', 0);
        $detail = $this->payroll->runDetail($runId);
        if (!$detail) {
            $this->respond(false, 'Liquidación no encontrada', '/app/nomina?tab=runs');
            return;
        }
        $item = null;
        foreach ($detail['items'] as $row) {
            if ((int)$row['id'] === $itemId) {
                $item = $row;
                break;
            }
        }
        if (!$item) {
            $this->respond(false, 'Comprobante no encontrado', '/app/nomina?tab=runs&action=run_detail&id=' . $runId);
            return;
        }
        $emp = $this->query('SELECT * FROM employees WHERE id=?', [(int)$item['employee_id']])->fetch() ?: [];
        $pdf = new PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generatePayslip($detail['run'], $item, $emp);
        $safe = preg_replace('/\W+/', '_', (string)$item['employee_name']);
        $pdf->download($content, 'Comprobante_' . $safe . '.pdf');
    }
}
