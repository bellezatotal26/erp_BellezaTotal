<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;

class TenantCajaController extends TenantController
{
    public function index(): void
    {
        TenantMiddleware::authorize('caja');
        
        $action = $this->request->get('action');
        
        if ($action === 'open' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->openCash();
            return;
        }
        if ($action === 'close' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'edit');
            $this->closeCash();
            return;
        }
        if ($action === 'movement' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->addMovement();
            return;
        }
        if ($action === 'pdf' && $this->request->method() === 'GET') {
            $this->closingPdf();
            return;
        }
        if ($action === 'searchProducts' && $this->request->method() === 'GET') {
            $this->searchProducts();
            return;
        }
        
        $openSession = $this->query(
            "SELECT cs.*, u.name as user_name
             FROM cash_sessions cs
             JOIN users u ON cs.user_id = u.id
             WHERE cs.status = 'open'
             ORDER BY cs.opening_date DESC LIMIT 1"
        )->fetch();
        
        $movements = [];
        $todaySales = [];
        $salesPagination = null;
        $totals = ['incomes' => 0, 'expenses' => 0, 'balance' => 0];
        $salesIncome = 0;
        $isPosRole = TenantMiddleware::isPosRole();
        
        if ($openSession) {
            $movements = $this->query(
                "SELECT * FROM cash_movements
                 WHERE cash_session_id = ?
                 ORDER BY created_at DESC",
                [$openSession['id']]
            )->fetchAll();
            
            $totals = $this->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as incomes,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses
                 FROM cash_movements
                 WHERE cash_session_id = ?",
                [$openSession['id']]
            )->fetch();

            // Totales de ventas (todas) para balance de caja
            $allSessionSales = $this->query(
                "SELECT s.id, s.total, s.status,
                        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0) as paid_amount
                 FROM sales s
                 WHERE s.sale_date >= ?",
                [$openSession['opening_date']]
            )->fetchAll();

            $movementSaleIds = [];
            foreach ($movements as $mov) {
                if ($mov['reference_type'] === 'sale' && $mov['reference_id']) {
                    $movementSaleIds[] = (int)$mov['reference_id'];
                }
            }
            foreach ($allSessionSales as $sale) {
                if (($sale['status'] ?? '') === 'cancelled') {
                    continue;
                }
                if (!in_array((int)$sale['id'], $movementSaleIds, true)) {
                    $salesIncome += (float)$sale['total'];
                }
            }
            
            $totals['incomes'] = (float)$totals['incomes'] + $salesIncome;
            $totals['balance'] = $openSession['opening_amount'] + $totals['incomes'] - (float)$totals['expenses'];

            // Lista paginada de ventas de la sesión
            $salesTotal = (int)$this->query(
                "SELECT COUNT(*) FROM sales WHERE sale_date >= ?",
                [$openSession['opening_date']]
            )->fetchColumn();
            $salesPagination = $this->paginate($salesTotal, 15);
            $todaySales = $this->query(
                "SELECT s.id, s.invoice_number, s.total, s.sale_date, s.payment_method,
                        s.payment_status, s.status,
                        c.name as customer_name, u.name as user_name,
                        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0) as paid_amount
                 FROM sales s
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.sale_date >= ?
                 ORDER BY s.sale_date DESC
                 LIMIT {$salesPagination['perPage']} OFFSET {$salesPagination['offset']}",
                [$openSession['opening_date']]
            )->fetchAll();
        }
        
        $historySessions = [];
        if (!$isPosRole) {
            $historySessions = $this->query(
                "SELECT cs.*, u.name as user_name
                 FROM cash_sessions cs
                 JOIN users u ON cs.user_id = u.id
                 WHERE cs.status = 'closed'
                 ORDER BY cs.closing_date DESC LIMIT 10"
            )->fetchAll();
        }

        $posCustomers = [];
        $paymentMethods = [];
        $recentProducts = [];
        $financialCats = [];
        $operationalCats = [];
        $expenseSuppliers = [];
        if ($openSession) {
            try {
                $posCustomers = $this->query(
                    "SELECT id, name, first_name, last_name, document_number, phone
                     FROM customers
                     WHERE status = 'active'
                     ORDER BY COALESCE(NULLIF(TRIM(name), ''), first_name) ASC
                     LIMIT 500"
                )->fetchAll();
            } catch (\Throwable $e) {
                $posCustomers = [];
            }
            $paymentMethods = \SoftNova\Services\PaymentMethodCatalog::all();
            try {
                $recentProducts = $this->query(
                    "SELECT p.id, p.name, p.code, p.sale_price, p.stock
                     FROM products p
                     WHERE p.status = 'active'
                     ORDER BY p.updated_at DESC, p.id DESC
                     LIMIT 12"
                )->fetchAll();
            } catch (\Throwable $e) {
                $recentProducts = $this->query(
                    "SELECT id, name, code, sale_price, stock FROM products WHERE status = 'active' ORDER BY id DESC LIMIT 12"
                )->fetchAll();
            }

            if (TenantMiddleware::canAccess('gastos') && TenantMiddleware::canDo('create', 'gastos')) {
                try {
                    $catSvc = new \SoftNova\Services\ExpenseCategoryService($this->db);
                    $financialCats = $catSvc->listActive('financial');
                    $operationalCats = $catSvc->listActive('operational');
                } catch (\Throwable $e) {
                    $financialCats = [];
                    $operationalCats = [];
                }
                try {
                    $expenseSuppliers = $this->query(
                        "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name LIMIT 300"
                    )->fetchAll();
                } catch (\Throwable $e) {
                    $expenseSuppliers = [];
                }
            }
        }
        
        $this->view('tenant.caja', $this->tenantViewData([
            'openSession' => $openSession,
            'movements' => $movements,
            'todaySales' => $todaySales,
            'salesPagination' => $salesPagination,
            'totals' => $totals,
            'historySessions' => $historySessions,
            'posCustomers' => $posCustomers,
            'paymentMethods' => $paymentMethods,
            'recentProducts' => $recentProducts,
            'financialCats' => $financialCats,
            'operationalCats' => $operationalCats,
            'expenseSuppliers' => $expenseSuppliers,
            'isPosRole' => $isPosRole,
            'invoicePrefix' => $this->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix' LIMIT 1"
            )->fetchColumn() ?: 'FAC-',
            'formatMoney' => fn($a) => $this->formatMoney($a),
        ]));
    }

    /**
     * Búsqueda rápida de productos para el POS de caja (código / nombre / barras).
     */
    private function searchProducts(): void
    {
        TenantMiddleware::authorize('caja');
        $q = trim((string)$this->request->get('q', ''));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }

        $like = '%' . $q . '%';
        $exact = $q;
        try {
            $rows = $this->query(
                "SELECT id, name, code, sale_price, stock, min_stock
                 FROM products
                 WHERE status = 'active'
                   AND (
                        code = ?
                        OR code LIKE ?
                        OR name LIKE ?
                        OR CAST(id AS CHAR) = ?
                   )
                 ORDER BY
                    CASE
                        WHEN code = ? THEN 0
                        WHEN code LIKE ? THEN 1
                        WHEN name LIKE ? THEN 2
                        ELSE 3
                    END,
                    name ASC
                 LIMIT 25",
                [$exact, $like, $like, $exact, $exact, $exact . '%', $like]
            )->fetchAll();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'No se pudo buscar productos', 'products' => []]);
            return;
        }

        $products = array_map(static function (array $p): array {
            return [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'code' => (string)($p['code'] ?? ''),
                'sale_price' => (float)$p['sale_price'],
                'stock' => (int)$p['stock'],
            ];
        }, $rows);

        $this->json(['success' => true, 'products' => $products]);
    }
    
    private function openCash(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        
        $existing = $this->query("SELECT id FROM cash_sessions WHERE status = 'open' LIMIT 1")->fetch();
        if ($existing) {
            $this->respond(false, 'Ya existe una caja abierta. Debe cerrarla primero.', '/app/caja');
            return;
        }
        
        $openingAmount = (float)$this->request->post('opening_amount');
        $notes = $this->request->post('notes', '');
        
        if ($openingAmount < 0) {
            $this->respond(false, 'El monto de apertura no puede ser negativo', '/app/caja');
            return;
        }
        
        $this->query(
            "INSERT INTO cash_sessions (user_id, opening_amount, opening_date, status, notes)
             VALUES (?, ?, NOW(), 'open', ?)",
            [$_SESSION['tenant_user_id'], $openingAmount, $notes]
        );
        
        $this->respond(true, 'Caja abierta exitosamente con ' . $this->formatMoney($openingAmount), '/app/caja');
    }
    
    private function closeCash(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        
        $sessionId = (int)$this->request->post('session_id');
        $closingAmount = (float)$this->request->post('closing_amount');
        $notes = $this->request->post('notes', '');
        
        $session = $this->query(
            "SELECT * FROM cash_sessions WHERE id = ? AND status = 'open'",
            [$sessionId]
        )->fetch();
        
        if (!$session) {
            $this->respond(false, 'Sesion de caja no encontrada o ya esta cerrada', '/app/caja');
            return;
        }
        
        $this->query(
            "UPDATE cash_sessions
             SET closing_amount = ?, closing_date = NOW(), status = 'closed',
                 notes = CONCAT(IFNULL(notes, ''), '\nCierre: ', ?)
             WHERE id = ?",
            [$closingAmount, $notes, $sessionId]
        );
        
        $this->respond(true, 'Caja cerrada exitosamente. Monto final: ' . $this->formatMoney($closingAmount), '/app/caja');
    }
    
    private function addMovement(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        
        $sessionId = (int)$this->request->post('session_id');
        $type = $this->request->post('type');
        $amount = (float)$this->request->post('amount');
        $description = $this->request->post('description', '');
        
        if (!in_array($type, ['income', 'expense'], true)) {
            $this->respond(false, 'Tipo de movimiento invalido', '/app/caja');
            return;
        }
        
        if ($amount <= 0) {
            $this->respond(false, 'El monto debe ser mayor a cero', '/app/caja');
            return;
        }
        
        if (empty(trim($description))) {
            $this->respond(false, 'La descripcion es requerida', '/app/caja');
            return;
        }
        
        $session = $this->query(
            "SELECT id FROM cash_sessions WHERE id = ? AND status = 'open'",
            [$sessionId]
        )->fetch();
        
        if (!$session) {
            $this->respond(false, 'La caja no esta abierta', '/app/caja');
            return;
        }
        
        $this->query(
            "INSERT INTO cash_movements (cash_session_id, type, amount, description, reference_type, user_id, created_at)
             VALUES (?, ?, ?, ?, 'manual', ?, NOW())",
            [$sessionId, $type, $amount, $description, $_SESSION['tenant_user_id'] ?? null]
        );
        
        $label = $type === 'income' ? 'Ingreso' : 'Egreso';
        $this->respond(true, "{$label} registrado: " . $this->formatMoney($amount), '/app/caja');
    }
    
    private function closingPdf(): void
    {
        $id = (int)$this->request->get('id');
        $session = $this->query(
            "SELECT cs.*, u.name as user_name FROM cash_sessions cs JOIN users u ON cs.user_id = u.id WHERE cs.id = ?",
            [$id]
        )->fetch();
        
        if (!$session) {
            echo 'Sesion no encontrada';
            exit;
        }
        
        $movements = $this->query(
            "SELECT * FROM cash_movements WHERE cash_session_id = ? ORDER BY created_at DESC",
            [$id]
        )->fetchAll();
        
        $totals = $this->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as incomes,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses
             FROM cash_movements WHERE cash_session_id = ?",
            [$id]
        )->fetch();
        
        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generateCashClosing($session, $movements, $totals);
        $pdf->download($content, 'Cierre_Caja_' . $id . '.pdf');
    }
}
