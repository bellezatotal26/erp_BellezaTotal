<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Services\CashService;
use SoftNova\Services\AccountingService;
use SoftNova\Services\ExpenseCategoryService;
use SoftNova\Services\PaymentMethodCatalog;

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
        $where = '';
        $params = [];
        if (in_array($kindFilter, ['financial', 'operational'], true)) {
            $where = 'WHERE ec.kind = ?';
            $params[] = $kindFilter;
        }

        $countSql = "SELECT COUNT(*) as c
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             {$where}";
        $total = (int)$this->query($countSql, $params)->fetch()['c'];
        $pagination = $this->paginate($total);

        $expenses = $this->query(
            "SELECT e.*, s.name as supplier_name, u.name as user_name,
                    ec.name as category_label, ec.kind as category_kind, ec.account_code
             FROM expenses e
             LEFT JOIN suppliers s ON e.supplier_id = s.id
             LEFT JOIN users u ON e.user_id = u.id
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             {$where}
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
            $params
        )->fetchAll();

        $monthTotal = (float)$this->query(
            "SELECT COALESCE(SUM(amount), 0) as t FROM expenses
             WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())"
        )->fetch()['t'];

        $monthFinancial = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             JOIN expense_categories ec ON ec.id = e.category_id AND ec.kind = 'financial'
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())"
        )->fetch()['t'];
        $monthOperational = (float)$this->query(
            "SELECT COALESCE(SUM(e.amount), 0) t
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             WHERE MONTH(e.expense_date) = MONTH(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())
               AND (ec.kind = 'operational' OR ec.id IS NULL)"
        )->fetch()['t'];

        $suppliers = $this->query(
            "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        $this->view('tenant.gastos', $this->tenantViewData([
            'expenses' => $expenses,
            'monthTotal' => $monthTotal,
            'monthFinancial' => $monthFinancial,
            'monthOperational' => $monthOperational,
            'suppliers' => $suppliers,
            'categories' => $this->categories->listActive(),
            'kindFilter' => $kindFilter,
            'pagination' => $pagination,
            'paymentMethods' => PaymentMethodCatalog::all(),
        ]));
    }

    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/gastos')) {
            return;
        }

        $description = trim($this->request->post('description', ''));
        $amount = (float)$this->request->post('amount', 0);
        $categoryId = (int)$this->request->post('category_id', 0);
        $cat = $categoryId > 0 ? $this->categories->find($categoryId) : null;
        $category = $cat ? (string)$cat['name'] : trim($this->request->post('category', 'General'));
        $expenseDate = $this->request->post('expense_date') ?: date('Y-m-d');
        $supplierId = $this->request->post('supplier_id') ? (int)$this->request->post('supplier_id') : null;
        $paymentMethod = PaymentMethodCatalog::normalize($this->request->post('payment_method', 'cash'));
        $receiptNumber = trim($this->request->post('receipt_number', ''));
        $notes = trim($this->request->post('notes', ''));
        $affectCash = $this->request->post('affect_cash') === '1';

        if ($description === '' || $amount <= 0) {
            $this->respond(false, 'Descripcion y monto son requeridos', '/app/gastos');
            return;
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
            $this->accounting->postExpense($expenseId, $affectCash);
        } catch (\Throwable $e) {
            error_log('Contabilidad gasto ' . $expenseId . ': ' . $e->getMessage());
        }

        if ($affectCash && PaymentMethodCatalog::affectsCash($paymentMethod)) {
            $this->cash->registerMovement(
                $amount,
                'Gasto: ' . $description,
                'expense',
                'expense',
                $expenseId
            );
        }

        $this->respond(true, 'Gasto registrado: ' . $this->formatMoney($amount), '/app/gastos');
    }

    private function edit(): void
    {
        if (!$this->validateCsrfOrFail('/app/gastos')) {
            return;
        }

        $id = (int)$this->request->post('id');
        $description = trim($this->request->post('description', ''));
        $amount = (float)$this->request->post('amount', 0);

        if ($id <= 0 || $description === '' || $amount <= 0) {
            $this->respond(false, 'Datos invalidos', '/app/gastos');
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

        $categoryId = (int)$this->request->post('category_id', 0);
        $cat = $categoryId > 0 ? $this->categories->find($categoryId) : null;
        $category = $cat ? (string)$cat['name'] : trim($this->request->post('category', 'General'));
        $paymentMethod = PaymentMethodCatalog::normalize($this->request->post('payment_method', 'cash'));

        $this->query(
            "UPDATE expenses SET description = ?, amount = ?, category = ?, category_id = ?, expense_date = ?,
                supplier_id = ?, payment_method = ?, receipt_number = ?, notes = ?
             WHERE id = ?",
            [
                $description,
                $amount,
                $category,
                $cat ? (int)$cat['id'] : null,
                $this->request->post('expense_date') ?: date('Y-m-d'),
                $this->request->post('supplier_id') ? (int)$this->request->post('supplier_id') : null,
                $paymentMethod,
                trim($this->request->post('receipt_number', '')) ?: null,
                trim($this->request->post('notes', '')) ?: null,
                $id,
            ]
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
                "SELECT description, receipt_path FROM expenses WHERE id = ?",
                [$id]
            )->fetch();
            if (!$expense) {
                throw new \RuntimeException('Gasto no encontrado');
            }
            $reversed = true;
            try {
                $this->accounting->reverseSource('expense', $id, 'Gasto eliminado');
            } catch (\Throwable $e) {
                $reversed = false;
                error_log('Reversa contable gasto ' . $id . ': ' . $e->getMessage());
            }
            $this->query("DELETE FROM expenses WHERE id = ?", [$id]);
            if (!empty($expense['receipt_path'])) {
                $this->deleteReceiptFile((string)$expense['receipt_path']);
            }
            $cashReversed = $this->cash->reverseExpenseMovements($id, $expense['description']);
            $message = $reversed ? 'Gasto eliminado y asiento revertido' : 'Gasto eliminado (revisar asiento contable manualmente)';
            if ($cashReversed > 0) {
                $message .= '. Caja ajustada: +' . $this->formatMoney($cashReversed);
            }
            $this->respond(true, $message, '/app/gastos');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'No se pudo eliminar: ' . $e->getMessage(), '/app/gastos');
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
        $rows = $this->query(
            "SELECT e.expense_date, e.description, COALESCE(ec.name, e.category) category,
                    ec.kind, e.amount, e.payment_method,
                    COALESCE(s.name, '') as supplier_name, e.receipt_number
             FROM expenses e
             LEFT JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN suppliers s ON e.supplier_id = s.id
             ORDER BY e.expense_date DESC"
        )->fetchAll();

        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['expense_date'],
                $r['description'],
                $r['category'],
                $r['kind'] === 'financial' ? 'Financiero' : 'Operativo',
                $r['amount'],
                PaymentMethodCatalog::label((string)$r['payment_method']),
                $r['supplier_name'],
                $r['receipt_number'],
            ];
        }

        $this->exportCsv(
            'gastos_' . date('Ymd_His') . '.csv',
            ['Fecha', 'Descripcion', 'Categoria', 'Tipo', 'Monto', 'Metodo', 'Proveedor', 'Comprobante'],
            $csvRows
        );
    }
}
