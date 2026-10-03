<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
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
                'save_params' => $this->saveParams(),
                'save_incapacity' => $this->saveIncapacity(),
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

        $tab = (string)$this->request->get('tab', 'employees');
        if (!in_array($tab, ['employees', 'schedules', 'runs', 'params'], true)) {
            $tab = 'employees';
        }

        $empFilters = $this->employeeFilters();
        $runFilters = $this->runFilters();
        $runYears = [];
        try {
            $q = $empFilters['q'];
            $status = $empFilters['status'];
            $employees = $tab === 'schedules'
                ? $this->payroll->employees('active')
                : ($tab === 'employees'
                    ? $this->payroll->employees($status, $q, $empFilters['schedule'])
                    : $this->payroll->employees());
            $recentRuns = $this->payroll->runs(36);
            $runs = ($runFilters['year'] > 0 || $runFilters['status'] !== '')
                ? $this->payroll->runs(120, $runFilters['year'], $runFilters['status'])
                : $recentRuns;
            $runYears = $this->payroll->runYears();
            $params = $this->payroll->params();
            $schedules = $this->payroll->schedules();
            $activeCount = $this->payroll->countEmployees('active');
            $monthPayroll = 0.0;
            $y = (int)date('Y');
            $m = (int)date('n');
            foreach ($recentRuns as $run) {
                if ((int)$run['period_year'] === $y && (int)$run['period_month'] === $m
                    && in_array(($run['status'] ?? ''), ['paid', 'posted', 'draft'], true)
                ) {
                    if (($run['status'] ?? '') === 'paid' || $monthPayroll <= 0) {
                        $monthPayroll = (float)$run['net_total'];
                    }
                    if (($run['status'] ?? '') === 'paid') {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Nomina index: ' . $e->getMessage());
            $_SESSION['error'] = 'No se pudo cargar nómina: ' . $e->getMessage();
            $employees = [];
            $runs = [];
            $params = [];
            $schedules = [];
            $q = '';
            $status = '';
            $activeCount = 0;
            $monthPayroll = 0.0;
        }

        $this->view('tenant.nomina', $this->tenantViewData([
            'tab' => $tab,
            'employees' => $employees,
            'runs' => $runs,
            'params' => $params,
            'schedules' => $schedules,
            'q' => $q,
            'statusFilter' => $status,
            'scheduleFilter' => $empFilters['schedule'],
            'runFilters' => $runFilters,
            'runYears' => $runYears,
            'activeCount' => $activeCount,
            'monthPayroll' => $monthPayroll,
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
            $msg = 'Nómina contabilizada (asiento #' . $entryId . ') y marcada como pagada. No descuenta el cajón de caja.';
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'pay',
                'nomina',
                'Nómina pagada: ' . ($result['run_number'] ?? '') . ' · neto '
                    . number_format((float)($result['net_total'] ?? 0), 0, ',', '.')
                    . ' · asiento contable #' . $entryId,
                'payroll_run',
                $id
            );
            $this->respond(true, $msg, '/app/nomina?tab=runs&action=run_detail&id=' . $id);
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

    private function runDetail(): void
    {
        $id = (int)$this->request->get('id', 0);
        $detail = $this->payroll->runDetail($id);
        if (!$detail) {
            $this->respond(false, 'Liquidación no encontrada', '/app/nomina?tab=runs');
            return;
        }
        $accountingEntry = $this->query(
            "SELECT id, entry_number FROM accounting_entries
             WHERE source_type = 'payroll' AND source_id = ? AND status = 'posted' AND source_event = 'created'
             ORDER BY id DESC LIMIT 1",
            [$id]
        )->fetch() ?: null;

        $this->view('tenant.nomina', $this->tenantViewData([
            'tab' => 'run_detail',
            'employees' => [],
            'runs' => [],
            'params' => $this->payroll->params(),
            'detail' => $detail,
            'accountingEntry' => $accountingEntry,
            'q' => '',
            'statusFilter' => '',
            'activeCount' => 0,
            'monthPayroll' => 0,
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
