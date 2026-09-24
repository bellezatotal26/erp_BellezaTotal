<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Services\CashService;
use SoftNova\Services\AccountingService;
use SoftNova\Services\ExpenseCategoryService;
use SoftNova\Services\PaymentMethodCatalog;
use function SoftNova\Core\base_url;

/**
 * Modulo Gastos del tenant (tabla expenses)
 * Fase 1: categorías financieras/operativas + foto comprobante + medios de pago.
 */
class TenantGastosController extends TenantController
{
    private const MAX_RECEIPT_BYTES = 5242880; // 5 MB
    private const ALLOWED_RECEIPT_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private CashService $cash;
    private AccountingService $accounting;
    private ExpenseCategoryService $categories;

    public function __construct()
    {
        parent::__construct();
        // El rol POS registra gastos solo desde Caja; no carga este módulo.
        if (\SoftNova\Core\TenantMiddleware::isPosRole()) {
            $_SESSION['error'] = 'El rol POS registra gastos desde Caja, no desde el módulo Gastos.';
            $this->redirect(base_url('/app/caja'));
            return;
        }
        \SoftNova\Core\TenantMiddleware::authorize('gastos');
        $this->cash = new CashService($this->db);
        $this->accounting = new AccountingService($this->db);
        $this->categories = new ExpenseCategoryService($this->db);
    }

    public function index(): void
    {
        $action = $this->request->get('action');

        if ($action === 'create' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('gastos', 'create');
            $this->create();
            return;
        }
        if ($action === 'edit' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('gastos', 'edit');
            $this->edit();
            return;
        }
        if ($action === 'delete' && $this->request->method() === 'POST') {
            \SoftNova\Core\TenantMiddleware::authorize('gastos', 'delete');
            $this->delete();
            return;
        }
        if ($action === 'receipt' && $this->request->method() === 'GET') {
            $this->serveReceipt();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            \SoftNova\Core\TenantMiddleware::authorize('gastos', 'export');
            $this->exportExpenses();
            return;
        }

        $kindFilter = (string)$this->request->get('kind', '');
        $q = trim((string)$this->request->get('q', ''));
        $from = trim((string)$this->request->get('from', ''));
        $to = trim((string)$this->request->get('to', ''));
        $paymentFilter = trim((string)$this->request->get('payment_method', ''));
        $supplierFilter = (int)$this->request->get('supplier_id', 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }
        if ($paymentFilter !== '') {
            $known = array_keys(PaymentMethodCatalog::all());
            if (!in_array($paymentFilter, $known, true)) {
                $paymentFilter = '';
            }
        }

        $whereParts = [];
        $params = [];
        $accountingWhere = ExpenseCategoryService::accountingKindsWhere('ec');
        if ($kindFilter === 'accounting') {
            $whereParts[] = "({$accountingWhere})";
        } elseif (in_array($kindFilter, ['operational', 'administrative', 'financial', 'management'], true)
            || str_starts_with($kindFilter, ExpenseCategoryService::SUBGROUP_KIND_PREFIX)) {
            $whereParts[] = 'ec.kind = ?';
            $params[] = $kindFilter;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $whereParts[] = '(e.description LIKE ? OR e.notes LIKE ? OR e.receipt_number LIKE ?
                OR s.name LIKE ? OR ec.name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($from !== '') {
            $whereParts[] = 'e.expense_date >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $whereParts[] = 'e.expense_date <= ?';
            $params[] = $to;
        }
        if ($paymentFilter !== '') {
            $whereParts[] = 'e.payment_method = ?';
            $params[] = $paymentFilter;
        }
        if ($supplierFilter > 0) {
            $whereParts[] = 'e.supplier_id = ?';
            $params[] = $supplierFilter;
        }

        $isAdmin = \SoftNova\Core\TenantMiddleware::getRole() === 'admin';
        $ownUserId = (int)($_SESSION['tenant_user_id'] ?? 0);
        if (!$isAdmin && $ownUserId > 0) {
            $whereParts[] = 'e.user_id = ?';
            $params[] = $ownUserId;
        }

        // Listado y totales: solo gastos activos (anulados quedan en BD para trazabilidad).
        $whereParts[] = "COALESCE(e.status, 'active') <> 'cancelled'";
        $where = $whereParts === [] ? '' : ('WHERE ' . implode(' AND ', $whereParts));

        $per = $this->resolvePerPage();
        $countSql = "SELECT COUNT(*) as c
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN suppliers s ON e.supplier_id = s.id
             {$where}";
        $total = (int)$this->query($countSql, $params)->fetch()['c'];
        $pagination = $this->paginate($total, $per['perPage']);

        $expenses = $this->query(
            "SELECT e.*, s.name as supplier_name,
                    COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name,
                    ec.name as category_label, ec.kind as category_kind, ec.account_code,
                    ae.id AS accounting_entry_id, ae.entry_number AS accounting_entry_number
             FROM expenses e
             LEFT JOIN suppliers s ON e.supplier_id = s.id
             LEFT JOIN users u ON e.user_id = u.id
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN accounting_entries ae ON ae.source_type = 'expense'
                 AND ae.source_id = e.id AND ae.status = 'posted' AND ae.source_event = 'created'
             {$where}
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
            $params
        )->fetchAll();

        $monthUserSql = " AND COALESCE(status, 'active') <> 'cancelled'";
        $monthUserParams = [];
        if (!$isAdmin && $ownUserId > 0) {
            $monthUserSql .= ' AND user_id = ?';
            $monthUserParams = [$ownUserId];
        }

        $monthTotal = (float)$this->query(
            "SELECT COALESCE(SUM(amount), 0) as t FROM expenses
             WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())"
                . $monthUserSql,
            $monthUserParams
        )->fetch()['t'];

        $monthOwnJoin = " AND COALESCE(e.status, 'active') <> 'cancelled'";
        $monthOwnParams = [];
        if (!$isAdmin && $ownUserId > 0) {
            $monthOwnJoin .= ' AND e.user_id = ?';
            $monthOwnParams = [$ownUserId];
        }

        $monthOperational = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id AND ec.kind = 'operational'
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())"
                . $monthOwnJoin,
            $monthOwnParams
        )->fetch()['t'];
        $monthAccounting = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id AND ({$accountingWhere})
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())"
                . $monthOwnJoin,
            $monthOwnParams
        )->fetch()['t'];
        $monthAdministrative = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id AND ec.kind = 'administrative'
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())"
                . $monthOwnJoin,
            $monthOwnParams
        )->fetch()['t'];
        $monthFinancial = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id AND ec.kind = 'financial'
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())"
                . $monthOwnJoin,
            $monthOwnParams
        )->fetch()['t'];
        $monthManagement = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id AND ec.kind = 'management'
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())"
                . $monthOwnJoin,
            $monthOwnParams
        )->fetch()['t'];

        $suppliers = $this->query(
            "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        $operationalCats = $this->categories->listActive('operational');
        $financialCats = $this->categories->listActive('financial');
        $administrativeCats = $this->categories->listActive('administrative');
        $managementCats = $this->categories->listActive('management');

        $customSubgroupSections = $this->categories->listCustomSubgroupSections();

        $this->view('tenant.gastos', $this->tenantViewData([
            'expenses' => $expenses,
            'monthTotal' => $monthTotal,
            'monthOperational' => $monthOperational,
            'monthAccounting' => $monthAccounting,
            'monthAdministrative' => $monthAdministrative,
            'monthFinancial' => $monthFinancial,
            'monthManagement' => $monthManagement,
            'suppliers' => $suppliers,
            'operationalCats' => $operationalCats,
            'financialCats' => $financialCats,
            'administrativeCats' => $administrativeCats,
            'managementCats' => $managementCats,
            'customSubgroupSections' => $customSubgroupSections,
            'kindFilter' => $kindFilter,
            'qFilter' => $q,
            'fromFilter' => $from,
            'toFilter' => $to,
            'paymentFilter' => $paymentFilter,
            'supplierFilter' => $supplierFilter,
            'pagination' => $pagination,
            'perPageOptions' => $per['options'],
            'listQuery' => array_filter([
                'q' => $q !== '' ? $q : null,
                'kind' => $kindFilter !== '' ? $kindFilter : null,
                'from' => $from !== '' ? $from : null,
                'to' => $to !== '' ? $to : null,
                'payment_method' => $paymentFilter !== '' ? $paymentFilter : null,
                'supplier_id' => $supplierFilter > 0 ? (string)$supplierFilter : null,
                'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
            ], static fn($v) => $v !== null && $v !== ''),
            'paymentMethods' => PaymentMethodCatalog::all(),
        ]));
    }

    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/gastos')) {
            return;
        }

        $description = trim($this->request->post('description', ''));
        $amount = \SoftNova\Core\parse_money($this->request->post('amount', 0));
        $rawCategory = trim((string)$this->request->post('category_id', ''));
        $categoryId = (int)$rawCategory;
        $expenseDate = $this->request->post('expense_date') ?: date('Y-m-d');
        $supplierId = $this->request->post('supplier_id') ? (int)$this->request->post('supplier_id') : null;
        $paymentMethod = PaymentMethodCatalog::normalize($this->request->post('payment_method', 'cash'));
        $receiptNumber = trim($this->request->post('receipt_number', ''));
        $notes = trim($this->request->post('notes', ''));
        $cashExplanation = trim((string)$this->request->post('cash_explanation', ''));

        if ($description === '' || $amount <= 0) {
            $this->respond(false, 'Descripcion y monto son requeridos', '/app/gastos');
            return;
        }
        if (str_starts_with($rawCategory, '__other_')) {
            $this->respond(
                false,
                'Solo el administrador puede crear categorías nuevas. Use Configuración → Administración de gastos.',
                '/app/gastos'
            );
            return;
        }
        if ($rawCategory === '' || $categoryId <= 0) {
            $this->respond(false, 'Seleccione una categoría de gasto válida', '/app/gastos');
            return;
        }
        $cat = $this->categories->find($categoryId);
        if (!$cat) {
            $this->respond(false, 'La categoría de gasto no existe o está inactiva', '/app/gastos');
            return;
        }
        $category = (string)$cat['name'];
        $isCashPay = PaymentMethodCatalog::affectsCash($paymentMethod);
        $isAccountingExpense = $this->categories->isAccounting($cat);
        // Contables en efectivo: no tocan el cajón (exigen explicación).
        // Operativos en efectivo: sí descuentan caja.
        $touchCajon = $isCashPay && $this->categories->affectsCashDrawer($cat);
        $markPaid = !$isCashPay && $paymentMethod !== '';

        if ($isCashPay && $isAccountingExpense) {
            if ($cashExplanation === '') {
                $this->respond(
                    false,
                    'En gastos contables con efectivo indique la explicación (ej. retiro cajero Bancolombia Calle 80). No descuenta el cajón.',
                    '/app/gastos'
                );
                return;
            }
            $markPaid = true;
            $prefix = 'Efectivo: ' . $cashExplanation;
            $notes = $notes !== '' ? ($prefix . ' | ' . $notes) : $prefix;
        } elseif ($isCashPay && $touchCajon) {
            $markPaid = true;
        }

        $receipt = $this->storeReceiptUpload();
        if ($receipt === false) {
            $this->respond(false, 'Comprobante inválido (JPG/PNG/WebP/PDF, máx. 5 MB)', '/app/gastos');
            return;
        }

        try {
            $this->db->beginTransaction();
            $this->query(
                "INSERT INTO expenses
                    (description, amount, category, category_id, expense_date, supplier_id,
                     payment_method, receipt_number, receipt_path, receipt_original_name, receipt_mime,
                     notes, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $description,
                    $amount,
                    $category,
                    $cat ? (int)$cat['id'] : null,
                    $expenseDate,
                    $supplierId,
                    $paymentMethod,
                    $receiptNumber ?: null,
                    $receipt['path'] ?? null,
                    $receipt['original'] ?? null,
                    $receipt['mime'] ?? null,
                    $notes ?: null,
                    $_SESSION['tenant_user_id'],
                ]
            );

            $expenseId = (int)$this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!empty($receipt['path'])) {
                $this->deleteReceiptFile((string)$receipt['path']);
            }
            $this->respond(false, 'No se pudo registrar el gasto: ' . $e->getMessage(), '/app/gastos');
            return;
        }

        try {
            $this->accounting->postExpense($expenseId, $touchCajon, $markPaid);
            $accountingNote = '';
        } catch (\Throwable $e) {
            error_log('Contabilidad gasto ' . $expenseId . ': ' . $e->getMessage());
            $this->accounting->enqueuePending(
                'post_expense',
                'expense',
                $expenseId,
                $e->getMessage(),
                ['affect_cash' => $touchCajon, 'paid_now' => $markPaid]
            );
            $accountingNote = ' Asiento pendiente: use «Sincronizar» en Contabilidad.';
        }

        if ($touchCajon) {
            $this->cash->registerMovement(
                $amount,
                'Gasto: ' . $description,
                'expense',
                'expense',
                $expenseId
            );
        }

        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'create',
            'gastos',
            'Gasto registrado (' . ExpenseCategoryService::kindLabel((string)($cat['kind'] ?? '')) . '): '
                . $description . ' · ' . $this->formatMoney($amount),
            'expense',
            $expenseId
        );

        $this->respond(true, 'Gasto registrado: ' . $this->formatMoney($amount) . $accountingNote, '/app/gastos');
    }

    private function edit(): void
    {
        if (!$this->validateCsrfOrFail('/app/gastos')) {
            return;
        }

        $id = (int)$this->request->post('id');
        $description = trim($this->request->post('description', ''));
        $amount = \SoftNova\Core\parse_money($this->request->post('amount', 0));
        $rawCategory = trim((string)$this->request->post('category_id', ''));
        $categoryId = (int)$rawCategory;
        $expenseDate = $this->request->post('expense_date') ?: date('Y-m-d');
        $supplierId = $this->request->post('supplier_id') ? (int)$this->request->post('supplier_id') : null;
        $paymentMethod = PaymentMethodCatalog::normalize($this->request->post('payment_method', 'cash'));
        $receiptNumber = trim($this->request->post('receipt_number', ''));
        $notes = trim($this->request->post('notes', ''));
        $cashExplanation = trim((string)$this->request->post('cash_explanation', ''));

        if ($id <= 0 || $description === '' || $amount <= 0) {
            $this->respond(false, 'Datos invalidos', '/app/gastos');
            return;
        }
        if (str_starts_with($rawCategory, '__other_')) {
            $this->respond(
                false,
                'Solo el administrador puede crear categorías nuevas. Use Configuración → Administración de gastos.',
                '/app/gastos'
            );
            return;
        }
        if ($rawCategory === '' || $categoryId <= 0) {
            $this->respond(false, 'Seleccione una categoría de gasto válida', '/app/gastos');
            return;
        }

        $isAdmin = \SoftNova\Core\TenantMiddleware::getRole() === 'admin';
        $ownUserId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $existing = $this->query("SELECT * FROM expenses WHERE id = ?", [$id])->fetch();
        if (!$existing) {
            $this->respond(false, 'Gasto no encontrado', '/app/gastos');
            return;
        }
        if (($existing['status'] ?? 'active') === 'cancelled') {
            $this->respond(false, 'Este gasto está anulado y no se puede editar', '/app/gastos');
            return;
        }
        if (!$isAdmin && $ownUserId > 0 && (int)($existing['user_id'] ?? 0) !== $ownUserId) {
            $this->respond(false, 'No puede editar gastos de otro usuario', '/app/gastos');
            return;
        }

        $posted = $this->query(
            "SELECT 1 FROM accounting_entries
             WHERE source_type = 'expense' AND source_id = ? AND status = 'posted' LIMIT 1",
            [$id]
        )->fetchColumn();
        if ($posted) {
            $this->respond(
                false,
                'El gasto ya está contabilizado. Elimínelo para generar una reversión y registre uno nuevo.',
                '/app/gastos'
            );
            return;
        }

        $cat = $this->categories->find($categoryId);
        if (!$cat) {
            $this->respond(false, 'La categoría de gasto no existe o está inactiva', '/app/gastos');
            return;
        }
        $category = (string)$cat['name'];
        $isCashPay = PaymentMethodCatalog::affectsCash($paymentMethod);
        $isAccountingExpense = $this->categories->isAccounting($cat);
        $touchCajon = $isCashPay && $this->categories->affectsCashDrawer($cat);

        if ($isCashPay && $isAccountingExpense) {
            if ($cashExplanation === '') {
                $this->respond(
                    false,
                    'En gastos contables con efectivo indique la explicación. No descuenta el cajón.',
                    '/app/gastos'
                );
                return;
            }
            $prefix = 'Efectivo: ' . $cashExplanation;
            $notes = $notes !== '' ? ($prefix . ' | ' . $notes) : $prefix;
        }

        $receipt = $this->storeReceiptUpload();
        if ($receipt === false) {
            $this->respond(false, 'Comprobante inválido (JPG/PNG/WebP/PDF, máx. 5 MB)', '/app/gastos');
            return;
        }

        $receiptPath = $existing['receipt_path'] ?? null;
        $receiptOriginal = $existing['receipt_original_name'] ?? null;
        $receiptMime = $existing['receipt_mime'] ?? null;
        if (is_array($receipt)) {
            if (!empty($existing['receipt_path'])) {
                $this->deleteReceiptFile((string)$existing['receipt_path']);
            }
            $receiptPath = $receipt['path'] ?? null;
            $receiptOriginal = $receipt['original'] ?? null;
            $receiptMime = $receipt['mime'] ?? null;
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->query(
                "UPDATE expenses SET description = ?, amount = ?, category = ?, category_id = ?, expense_date = ?,
                    supplier_id = ?, payment_method = ?, receipt_number = ?, receipt_path = ?,
                    receipt_original_name = ?, receipt_mime = ?, notes = ?
                 WHERE id = ?",
                [
                    $description,
                    $amount,
                    $category,
                    (int)$cat['id'],
                    $expenseDate,
                    $supplierId,
                    $paymentMethod,
                    $receiptNumber ?: null,
                    $receiptPath,
                    $receiptOriginal,
                    $receiptMime,
                    $notes ?: null,
                    $id,
                ]
            );
            if ($stmt->rowCount() < 1) {
                $still = $this->query("SELECT id FROM expenses WHERE id = ?", [$id])->fetchColumn();
                if (!$still) {
                    throw new \RuntimeException('Gasto no encontrado');
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_array($receipt) && !empty($receipt['path'])) {
                $this->deleteReceiptFile((string)$receipt['path']);
            }
            $this->respond(false, 'No se pudo actualizar el gasto: ' . $e->getMessage(), '/app/gastos');
            return;
        }

        $this->cash->reverseExpenseMovements($id, (string)$existing['description']);
        if ($touchCajon) {
            $this->cash->registerMovement(
                $amount,
                'Gasto: ' . $description,
                'expense',
                'expense',
                $id
            );
        }

        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'edit',
            'gastos',
            'Gasto actualizado (' . ExpenseCategoryService::kindLabel((string)($cat['kind'] ?? '')) . '): '
                . $description . ' · ' . $this->formatMoney($amount),
            'expense',
            $id
        );

        $this->respond(true, 'Gasto actualizado', '/app/gastos');
    }

    private function delete(): void
    {
        if (!$this->validateCsrfOrFail('/app/gastos')) {
            return;
        }

        $id = (int)$this->request->post('id');
        try {
            $expense = $this->query(
                "SELECT description, receipt_path, status FROM expenses WHERE id = ?",
                [$id]
            )->fetch();
            if (!$expense) {
                throw new \RuntimeException('Gasto no encontrado');
            }
            if (($expense['status'] ?? 'active') === 'cancelled') {
                throw new \RuntimeException('Este gasto ya está anulado');
            }
            $reversed = true;
            try {
                $this->accounting->reverseSource('expense', $id, 'Gasto anulado');
            } catch (\Throwable $e) {
                $reversed = false;
                error_log('Reversa contable gasto ' . $id . ': ' . $e->getMessage());
                $this->accounting->enqueuePending(
                    'reverse_source',
                    'expense',
                    $id,
                    $e->getMessage(),
                    ['reason' => 'Gasto anulado']
                );
            }
            // Soft-delete: conserva fila + comprobante para trazabilidad del asiento.
            try {
                $this->query(
                    "UPDATE expenses
                     SET status = 'cancelled', cancelled_at = NOW()
                     WHERE id = ? AND COALESCE(status, 'active') <> 'cancelled'",
                    [$id]
                );
            } catch (\Throwable $e) {
                // Compatibilidad si aún no migró status: marcar por nota y borrar solo si falla.
                $this->query(
                    "UPDATE expenses
                     SET notes = CONCAT(IFNULL(notes,''), IF(notes IS NULL OR notes='', '', ' | '), '[ANULADO ', ?, ']')
                     WHERE id = ?",
                    [date('Y-m-d H:i'), $id]
                );
            }
            $cashReversed = $this->cash->reverseExpenseMovements($id, $expense['description']);
            $message = $reversed
                ? 'Gasto anulado y asiento revertido (queda en historial)'
                : 'Gasto anulado (revisar asiento contable; queda en historial)';
            if ($cashReversed > 0) {
                $message .= '. Caja ajustada: +' . $this->formatMoney($cashReversed);
            }
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'delete',
                'gastos',
                'Gasto anulado: ' . ($expense['description'] ?? '') . ($reversed ? ' (asiento revertido)' : ''),
                'expense',
                $id
            );
            $this->respond(true, $message, '/app/gastos');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'No se pudo anular: ' . $e->getMessage(), '/app/gastos');
        }
    }

    private function serveReceipt(): void
    {
        $id = (int)$this->request->get('id', 0);
        $row = $this->query(
            "SELECT receipt_path, receipt_original_name, receipt_mime FROM expenses WHERE id = ?",
            [$id]
        )->fetch();
        if (!$row || empty($row['receipt_path'])) {
            http_response_code(404);
            echo 'Comprobante no encontrado';
            return;
        }
        $full = $this->receiptAbsolutePath((string)$row['receipt_path']);
        if (!is_file($full)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }
        $mime = (string)($row['receipt_mime'] ?: 'application/octet-stream');
        $name = (string)($row['receipt_original_name'] ?: basename($full));
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string)filesize($full));
        readfile($full);
        exit;
    }

    /**
     * @return array{path:string,original:string,mime:string}|null|false
     */
    private function storeReceiptUpload(): array|null|false
    {
        if (empty($_FILES['receipt_file']['tmp_name'])) {
            return null;
        }
        if (!is_uploaded_file($_FILES['receipt_file']['tmp_name'])) {
            return false;
        }
        if (($_FILES['receipt_file']['size'] ?? 0) > self::MAX_RECEIPT_BYTES) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['receipt_file']['tmp_name']);
        if (!isset(self::ALLOWED_RECEIPT_MIME[$mime])) {
            return false;
        }
        $ext = self::ALLOWED_RECEIPT_MIME[$mime];
        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_SESSION['tenant_db_name'] ?? 'tenant')) ?: 'tenant';
        $dir = STORAGE_PATH . '/expenses/' . $tenantKey;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'exp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($_FILES['receipt_file']['tmp_name'], $dest)) {
            return false;
        }
        return [
            'path' => $tenantKey . '/' . $filename,
            'original' => (string)($_FILES['receipt_file']['name'] ?? $filename),
            'mime' => $mime,
        ];
    }

    private function receiptAbsolutePath(string $relative): string
    {
        $relative = str_replace(['..', '\\'], ['', '/'], $relative);
        return STORAGE_PATH . '/expenses/' . ltrim($relative, '/');
    }

    private function deleteReceiptFile(string $relative): void
    {
        $full = $this->receiptAbsolutePath($relative);
        if (is_file($full)) {
            @unlink($full);
        }
    }

    private function exportExpenses(): void
    {
        $kindFilter = (string)$this->request->get('kind', '');
        $q = trim((string)$this->request->get('q', ''));
        $from = trim((string)$this->request->get('from', ''));
        $to = trim((string)$this->request->get('to', ''));
        $paymentFilter = trim((string)$this->request->get('payment_method', ''));
        $supplierFilter = (int)$this->request->get('supplier_id', 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }
        if ($paymentFilter !== '') {
            $known = array_keys(PaymentMethodCatalog::all());
            if (!in_array($paymentFilter, $known, true)) {
                $paymentFilter = '';
            }
        }

        $whereParts = [];
        $params = [];
        $accountingWhere = ExpenseCategoryService::accountingKindsWhere('ec');
        if ($kindFilter === 'accounting') {
            $whereParts[] = "({$accountingWhere})";
        } elseif (in_array($kindFilter, ['operational', 'administrative', 'financial', 'management'], true)
            || str_starts_with($kindFilter, ExpenseCategoryService::SUBGROUP_KIND_PREFIX)) {
            $whereParts[] = 'ec.kind = ?';
            $params[] = $kindFilter;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $whereParts[] = '(e.description LIKE ? OR e.notes LIKE ? OR e.receipt_number LIKE ?
                OR s.name LIKE ? OR ec.name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($from !== '') {
            $whereParts[] = 'e.expense_date >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $whereParts[] = 'e.expense_date <= ?';
            $params[] = $to;
        }
        if ($paymentFilter !== '') {
            $whereParts[] = 'e.payment_method = ?';
            $params[] = $paymentFilter;
        }
        if ($supplierFilter > 0) {
            $whereParts[] = 'e.supplier_id = ?';
            $params[] = $supplierFilter;
        }
        if (\SoftNova\Core\TenantMiddleware::getRole() !== 'admin') {
            $whereParts[] = 'e.user_id = ?';
            $params[] = (int)($_SESSION['tenant_user_id'] ?? 0);
        }
        $whereParts[] = "COALESCE(e.status, 'active') <> 'cancelled'";
        $where = $whereParts === [] ? '' : ('WHERE ' . implode(' AND ', $whereParts));

        $sql = "SELECT e.expense_date,
                    COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name,
                    e.description, COALESCE(ec.name, e.category) category,
                    ec.kind, e.amount, e.payment_method,
                    COALESCE(s.name, '') as supplier_name, e.receipt_number
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN suppliers s ON e.supplier_id = s.id
             LEFT JOIN users u ON u.id = e.user_id
             {$where}
             ORDER BY e.expense_date DESC";
        $rows = $this->query($sql, $params)->fetchAll();

        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['expense_date'],
                $r['user_name'],
                $r['description'],
                $r['category'],
                match ((string)($r['kind'] ?? '')) {
                    'financial' => 'Contable — Financiero',
                    'management' => 'Contable — Gestión',
                    'administrative' => 'Contable — Administrativo',
                    'operational' => 'Operativo',
                    default => '',
                },
                $r['amount'],
                PaymentMethodCatalog::label((string)$r['payment_method']),
                $r['supplier_name'],
                $r['receipt_number'],
            ];
        }

        $this->exportCsv(
            'gastos_' . date('Ymd_His') . '.csv',
            ['Fecha', 'Usuario', 'Descripcion', 'Categoria', 'Tipo', 'Monto', 'Metodo', 'Proveedor', 'Comprobante'],
            $csvRows
        );
    }
}
