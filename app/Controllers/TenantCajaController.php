<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\SupplyChainSchema;
use SoftNova\Services\CustomOptionService;
use function SoftNova\Core\base_url;
use function SoftNova\Core\config;

class TenantCajaController extends TenantController
{
    public function index(): void
    {
        TenantMiddleware::authorize('caja');
        SupplyChainSchema::ensure($this->db);
        
        $action = $this->request->get('action');
        
        if ($action === 'open' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->openCash();
            return;
        }
        if ($action === 'close' && $this->request->method() === 'POST') {
            try {
                if (!TenantMiddleware::canAccess('caja') || !TenantMiddleware::canDo('edit', 'caja')) {
                    $this->json([
                        'success' => false,
                        'message' => 'No tienes permisos para cerrar la caja',
                        'redirect' => base_url(TenantMiddleware::homePath()),
                    ]);
                    return;
                }
                $this->closeCash();
            } catch (\Throwable $e) {
                error_log('caja close action: ' . $e->getMessage());
                $this->json([
                    'success' => false,
                    'message' => 'Error al cerrar caja: ' . $e->getMessage(),
                    'redirect' => base_url('/app/caja'),
                ]);
            }
            return;
        }
        if ($action === 'movement' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->addMovement();
            return;
        }
        if ($action === 'expense' && $this->request->method() === 'POST') {
            // Create en Gastos basta (sin exigir Crear en Caja), igual que el botón en UI.
            TenantMiddleware::authorize('caja', 'view');
            $this->createExpenseFromCaja();
            return;
        }
        if ($action === 'loan' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'view');
            $this->createLoanFromCaja();
            return;
        }
        if ($action === 'pdf' && $this->request->method() === 'GET') {
            $this->closingPdf();
            return;
        }
        if ($action === 'report_whatsapp' && $this->request->method() === 'GET') {
            $this->reportWhatsapp();
            return;
        }
        if ($action === 'report_email' && $this->request->method() === 'POST') {
            $this->reportEmail();
            return;
        }
        if ($action === 'searchProducts' && $this->request->method() === 'GET') {
            $this->searchProducts();
            return;
        }
        if ($action === 'catalogSnapshot' && $this->request->method() === 'GET') {
            $this->catalogSnapshot();
            return;
        }

        // Cierra cajas abiertas cuyo dia de apertura ya termino (pasada medianoche)
        $autoClosed = $this->autoCloseOvernightSessions();
        
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
        $totals = ['incomes' => 0, 'expenses' => 0, 'balance' => 0, 'sales_count' => 0, 'sales_total' => 0, 'movements_count' => 0];
        $isPosRole = TenantMiddleware::isPosRole();
        
        if ($openSession) {
            $totals = $this->computeSessionTotals($openSession);
            $movements = $this->query(
                "SELECT * FROM cash_movements
                 WHERE cash_session_id = ?
                 ORDER BY created_at DESC",
                [$openSession['id']]
            )->fetchAll();

            $salesTotal = (int)$this->query(
                "SELECT COUNT(*) FROM sales WHERE sale_date >= ? AND status != 'cancelled'",
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

        $todayExpenses = [];
        $todayExpensesByUser = [];
        $todayExpensesTotal = 0.0;
        try {
            $todayExpenses = $this->query(
                "SELECT e.id, e.description, e.amount, e.expense_date, e.payment_method,
                        e.receipt_number, e.user_id, e.created_at,
                        COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name,
                        COALESCE(NULLIF(TRIM(ec.name), ''), NULLIF(TRIM(e.category), ''), 'General') AS category_label,
                        s.name AS supplier_name
                 FROM expenses e
                 LEFT JOIN users u ON u.id = e.user_id
                 LEFT JOIN expense_categories ec ON ec.id = e.category_id
                 LEFT JOIN suppliers s ON s.id = e.supplier_id
                 WHERE DATE(e.expense_date) = CURDATE()
                 ORDER BY user_name ASC, e.created_at DESC, e.id DESC"
            )->fetchAll();

            foreach ($todayExpenses as $exp) {
                $uid = (string)((int)($exp['user_id'] ?? 0));
                if (!isset($todayExpensesByUser[$uid])) {
                    $todayExpensesByUser[$uid] = [
                        'user_id' => (int)($exp['user_id'] ?? 0),
                        'user_name' => (string)$exp['user_name'],
                        'total' => 0.0,
                        'items' => [],
                    ];
                }
                $amount = (float)$exp['amount'];
                $todayExpensesByUser[$uid]['total'] += $amount;
                $todayExpensesByUser[$uid]['items'][] = $exp;
                $todayExpensesTotal += $amount;
            }
        } catch (\Throwable $e) {
            $todayExpenses = [];
            $todayExpensesByUser = [];
            $todayExpensesTotal = 0.0;
        }

        $historyFrom = trim((string)$this->request->get('history_from', ''));
        $historyTo = trim((string)$this->request->get('history_to', ''));
        if ($historyFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyFrom)) {
            $historyFrom = date('Y-m-d', strtotime('-30 days'));
        }
        if ($historyTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyTo)) {
            $historyTo = date('Y-m-d');
        }
        if ($historyFrom > $historyTo) {
            $tmp = $historyFrom;
            $historyFrom = $historyTo;
            $historyTo = $tmp;
        }

        $historyWhere = ["cs.status = 'closed'", 'DATE(cs.closing_date) >= ?', 'DATE(cs.closing_date) <= ?'];
        $historyParams = [$historyFrom, $historyTo];
        if ($isPosRole) {
            $historyWhere[] = 'cs.user_id = ?';
            $historyParams[] = (int)($_SESSION['tenant_user_id'] ?? 0);
        }
        $historySessions = $this->query(
            "SELECT cs.*, u.name as user_name
             FROM cash_sessions cs
             JOIN users u ON cs.user_id = u.id
             WHERE " . implode(' AND ', $historyWhere) . "
             ORDER BY cs.closing_date DESC
             LIMIT 100",
            $historyParams
        )->fetchAll();

        $posCustomers = [];
        $paymentMethods = [];
        $recentProducts = [];
        $financialCats = [];
        $operationalCats = [];
        $administrativeCats = [];
        $expenseSuppliers = [];
        $loanProducts = [];
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
                $this->ensureWholesalePriceColumn();
                $this->repairProductUtf8Names();
                $openItem = \SoftNova\Services\OpenItemProduct::ensure($this->db);
                // Últimos 5 productos vendidos (por fecha de venta más reciente).
                $recentProducts = $this->query(
                    "SELECT p.id, p.name, p.code, p.sale_price, COALESCE(p.wholesale_price, 0) AS wholesale_price,
                            p.stock, COALESCE(p.product_type,'product') AS product_type,
                            MAX(s.sale_date) AS last_sold_at
                     FROM sale_items si
                     INNER JOIN sales s ON s.id = si.sale_id
                     INNER JOIN products p ON p.id = si.product_id
                     WHERE p.status = 'active'
                       AND COALESCE(s.status, 'completed') <> 'cancelled'
                       AND si.product_id IS NOT NULL
                       AND UPPER(COALESCE(p.code,'')) <> ?
                     GROUP BY p.id, p.name, p.code, p.sale_price, p.wholesale_price, p.stock, p.product_type
                     ORDER BY last_sold_at DESC, p.id DESC
                     LIMIT 5",
                    [\SoftNova\Services\OpenItemProduct::CODE]
                )->fetchAll();
                // Comodín siempre visible al inicio de accesos rápidos.
                array_unshift($recentProducts, [
                    'id' => (int)$openItem['id'],
                    'name' => (string)$openItem['name'],
                    'code' => \SoftNova\Services\OpenItemProduct::CODE,
                    'sale_price' => 0,
                    'wholesale_price' => 0,
                    'stock' => 0,
                    'product_type' => 'service',
                    'is_open_item' => 1,
                    'last_sold_at' => null,
                ]);
            } catch (\Throwable $e) {
                try {
                    $recentProducts = $this->query(
                        "SELECT p.id, p.name, p.code, p.sale_price, 0 AS wholesale_price,
                                p.stock, COALESCE(p.product_type,'product') AS product_type,
                                MAX(s.sale_date) AS last_sold_at
                         FROM sale_items si
                         INNER JOIN sales s ON s.id = si.sale_id
                         INNER JOIN products p ON p.id = si.product_id
                         WHERE p.status = 'active'
                           AND COALESCE(s.status, 'completed') <> 'cancelled'
                           AND si.product_id IS NOT NULL
                         GROUP BY p.id
                         ORDER BY last_sold_at DESC, p.id DESC
                         LIMIT 5"
                    )->fetchAll();
                } catch (\Throwable $e2) {
                    $recentProducts = [];
                }
            }
            foreach ($recentProducts as &$rp) {
                $rp['name'] = \SoftNova\Core\utf8_fix((string)($rp['name'] ?? ''));
                $rp['code'] = \SoftNova\Core\utf8_fix((string)($rp['code'] ?? ''));
            }
            unset($rp);

            if ($openSession && $this->canRegisterExpenseFromCaja()) {
                try {
                    $catSvc = new \SoftNova\Services\ExpenseCategoryService($this->db);
                    $financialCats = $catSvc->listActive('financial');
                    $operationalCats = $catSvc->listActive('operational');
                    $administrativeCats = $catSvc->listActive('administrative');
                } catch (\Throwable $e) {
                    $financialCats = [];
                    $operationalCats = [];
                    $administrativeCats = [];
                }
                try {
                    $expenseSuppliers = $this->query(
                        "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name LIMIT 300"
                    )->fetchAll();
                } catch (\Throwable $e) {
                    $expenseSuppliers = [];
                }
            }
            if ($openSession && $this->canLoanFromCaja()) {
                try {
                    $loanProducts = $this->query(
                        "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode
                         FROM products
                         WHERE status = 'active'
                           AND COALESCE(product_type, 'product') = 'product'
                           AND UPPER(COALESCE(code,'')) <> ?
                         ORDER BY name ASC
                         LIMIT 400",
                        [\SoftNova\Services\OpenItemProduct::CODE]
                    )->fetchAll();
                    // Deep-link desde Inventario: asegurar el producto aunque no esté en el top 400.
                    $preselectLoanId = (int)$this->request->get('loan_product_id', 0);
                    if ($preselectLoanId > 0) {
                        $found = false;
                        foreach ($loanProducts as $lp) {
                            if ((int)$lp['id'] === $preselectLoanId) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $extra = $this->query(
                                "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode FROM products
                                 WHERE id = ? AND status = 'active'
                                   AND COALESCE(product_type, 'product') = 'product'
                                   AND UPPER(COALESCE(code,'')) <> ?
                                 LIMIT 1",
                                [$preselectLoanId, \SoftNova\Services\OpenItemProduct::CODE]
                            )->fetch();
                            if ($extra) {
                                array_unshift($loanProducts, $extra);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $loanProducts = [];
                }
            }
        }

        if ($autoClosed > 0 && empty($_SESSION['success'])) {
            $_SESSION['success'] = $autoClosed === 1
                ? 'Se cerro automaticamente 1 caja al pasar la medianoche.'
                : "Se cerraron automaticamente {$autoClosed} cajas al pasar la medianoche.";
        }
        
        $company = $this->companySettings();
        $cancelReasons = [];
        try {
            $cancelReasons = (new CustomOptionService($this->db))
                ->options(CustomOptionService::GROUP_CANCEL_REASON);
        } catch (\Throwable $e) {
            $cancelReasons = [];
        }

        $creditSurchargeRate = 0.0;
        $creditSurchargeDays = 60;
        try {
            $commCfg = (new \SoftNova\Services\CommissionService($this->db))->config();
            $creditSurchargeRate = (float)($commCfg['credit_over_60_rate'] ?? 0);
            $creditSurchargeDays = (int)($commCfg['credit_over_60_days'] ?? 60);
            if ($creditSurchargeDays < 1) {
                $creditSurchargeDays = 60;
            }
        } catch (\Throwable $e) {
        }
        $taxRate = (float)$this->getSetting('tax_rate', '0');
        if ($taxRate < 0) {
            $taxRate = 0.0;
        }

        $stockAlert = [
            'count' => 0,
            'negative_count' => 0,
            'loan_count' => 0,
            'sale_debt_count' => 0,
            'summary_lines' => [],
            'summary_text' => '',
        ];
        try {
            $stockAlert = (new \SoftNova\Services\StockService($this->db))->negativeStockAlertReport(30);
        } catch (\Throwable $e) {
            // ignore
        }

        $this->view('tenant.caja', $this->tenantViewData([
            'openSession' => $openSession,
            'movements' => $movements,
            'todaySales' => $todaySales,
            'salesPagination' => $salesPagination,
            'todayExpensesByUser' => $todayExpensesByUser,
            'todayExpensesTotal' => $todayExpensesTotal,
            'todayExpensesCount' => count($todayExpenses),
            'taxRate' => $taxRate,
            'totals' => $totals,
            'historySessions' => $historySessions,
            'historyFrom' => $historyFrom,
            'historyTo' => $historyTo,
            'posCustomers' => $posCustomers,
            'paymentMethods' => $paymentMethods,
            'recentProducts' => $recentProducts,
            'financialCats' => $financialCats,
            'operationalCats' => $operationalCats,
            'administrativeCats' => $administrativeCats,
            'expenseSuppliers' => $expenseSuppliers,
            'loanProducts' => $loanProducts,
            'canLoanFromCaja' => $this->canLoanFromCaja(),
            'loanReasonsHtml' => \SoftNova\Services\ProductLoan::optionsHtml(),
            'loanProductIdPrefill' => (int)$this->request->get('loan_product_id', 0),
            'isPosRole' => $isPosRole,
            'canExpenseFromCaja' => $this->canRegisterExpenseFromCaja(),
            'cancelReasons' => $cancelReasons,
            'stockAlert' => $stockAlert,
            'companyEmail' => (string)($company['company_email'] ?? ''),
            'cajaReportEmail' => $this->resolveCajaReportEmail((string)($company['company_email'] ?? '')),
            'creditSurchargeRate' => $creditSurchargeRate,
            'creditSurchargeDays' => $creditSurchargeDays,
            'invoicePrefix' => $this->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix' LIMIT 1"
            )->fetchColumn() ?: 'FAC-',
            'formatMoney' => fn($a) => $this->formatMoney($a),
        ]));
    }

    /** Correo predeterminado para reportes de cierre (settings → company_email). */
    private function resolveCajaReportEmail(string $companyEmailFallback = ''): string
    {
        $saved = trim($this->getSetting('caja_report_email', ''));
        if ($saved !== '' && filter_var($saved, FILTER_VALIDATE_EMAIL)) {
            return $saved;
        }
        $fallback = trim($companyEmailFallback);
        return ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) ? $fallback : '';
    }

    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->query(
            "SELECT id FROM settings WHERE setting_key = ?",
            [$key]
        )->fetch();
        if ($existing) {
            $this->query(
                "UPDATE settings SET setting_value = ? WHERE setting_key = ?",
                [$value, $key]
            );
        } else {
            $this->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)",
                [$key, $value]
            );
        }
        \SoftNova\Services\SettingsCache::invalidate();
    }

    /** Valida la contraseña de cualquier administrador activo del tenant. */
    private function verifyTenantAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        try {
            $rows = $this->query(
                "SELECT password, role FROM users WHERE status = 'active'"
            )->fetchAll();
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($rows as $row) {
            $role = TenantMiddleware::normalizeRole((string)($row['role'] ?? ''));
            if ($role !== 'admin') {
                continue;
            }
            if (\SoftNova\Core\Security::verifyPassword($password, (string)($row['password'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private function ensureWholesalePriceColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $col = $this->db->query("SHOW COLUMNS FROM products LIKE 'wholesale_price'")->fetch();
            if (!$col) {
                $this->db->exec(
                    "ALTER TABLE products ADD COLUMN wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sale_price"
                );
            }
        } catch (\Throwable $e) {
            error_log('ensureWholesalePriceColumn: ' . $e->getMessage());
        }
    }

    /**
     * Búsqueda rápida de productos para el POS de caja (código / nombre / barras).
     */
    private function searchProducts(): void
    {
        TenantMiddleware::authorize('caja');
        $this->ensureWholesalePriceColumn();
        $q = trim((string)$this->request->get('q', ''));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }

        $like = '%' . $q . '%';
        $exact = $q;
        try {
            $rows = $this->query(
                "SELECT id, name, code, sale_price, COALESCE(wholesale_price, 0) AS wholesale_price,
                        stock, min_stock, COALESCE(product_type,'product') AS product_type,
                        COALESCE(barcode,'') AS barcode
                 FROM products
                 WHERE status = 'active'
                   AND (
                        code = ?
                        OR barcode = ?
                        OR code LIKE ?
                        OR name LIKE ?
                        OR barcode LIKE ?
                        OR CAST(id AS CHAR) = ?
                   )
                 ORDER BY
                    CASE
                        WHEN code = ? THEN 0
                        WHEN barcode = ? THEN 1
                        WHEN code LIKE ? THEN 2
                        WHEN name LIKE ? THEN 3
                        ELSE 4
                    END,
                    name ASC
                 LIMIT 25",
                [$exact, $exact, $like, $like, $like, $exact, $exact, $exact, $exact . '%', $like]
            )->fetchAll();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'No se pudo buscar productos', 'products' => []]);
            return;
        }

        $products = array_map(static function (array $p): array {
            $code = \SoftNova\Core\utf8_fix((string)($p['code'] ?? ''));
            return [
                'id' => (int)$p['id'],
                'name' => \SoftNova\Core\utf8_fix((string)$p['name']),
                'code' => $code,
                'sale_price' => (float)$p['sale_price'],
                'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
                'stock' => (int)$p['stock'],
                'product_type' => (string)($p['product_type'] ?? 'product'),
                'is_open_item' => strtoupper($code) === \SoftNova\Services\OpenItemProduct::CODE ? 1 : 0,
            ];
        }, $rows);

        $this->json(['success' => true, 'products' => $products]);
    }

    /**
     * Snapshot de catálogo para el POS offline (IndexedDB / búsqueda degradada).
     */
    private function catalogSnapshot(): void
    {
        TenantMiddleware::authorize('caja');
        $limit = max(100, min(5000, (int)$this->request->get('limit', 2500)));

        try {
            \SoftNova\Services\OpenItemProduct::ensure($this->db);
            $rows = $this->query(
                "SELECT id, name, code, sale_price, stock, COALESCE(product_type, 'product') AS product_type
                 FROM products
                 WHERE status = 'active'
                 ORDER BY
                    CASE WHEN UPPER(COALESCE(code,'')) = ? THEN 0 ELSE 1 END,
                    name ASC
                 LIMIT {$limit}",
                [\SoftNova\Services\OpenItemProduct::CODE]
            )->fetchAll();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'No se pudo cargar el catálogo', 'products' => []]);
            return;
        }

        $products = array_map(static function (array $p): array {
            $code = \SoftNova\Core\utf8_fix((string)($p['code'] ?? ''));
            return [
                'id' => (int)$p['id'],
                'name' => \SoftNova\Core\utf8_fix((string)$p['name']),
                'code' => $code,
                'sale_price' => (float)$p['sale_price'],
                'stock' => (int)$p['stock'],
                'product_type' => (string)($p['product_type'] ?? 'product'),
                'is_open_item' => strtoupper($code) === \SoftNova\Services\OpenItemProduct::CODE ? 1 : 0,
            ];
        }, $rows);

        $this->json([
            'success' => true,
            'products' => $products,
            'synced_at' => date('c'),
            'count' => count($products),
        ]);
    }
    
    /**
     * Corrige en BD nombres/códigos con mojibake (Ã', Ã±, etc.).
     * Idempotente y barato: solo toca filas sospechosas.
     */
    private function repairProductUtf8Names(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $rows = $this->query(
                "SELECT id, name, code FROM products
                 WHERE name LIKE '%Ã%' OR name LIKE '%Â%' OR code LIKE '%Ã%' OR code LIKE '%Â%'
                 LIMIT 500"
            )->fetchAll();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $name = \SoftNova\Core\utf8_fix((string)$row['name']);
            $code = \SoftNova\Core\utf8_fix((string)($row['code'] ?? ''));
            if ($name === (string)$row['name'] && $code === (string)($row['code'] ?? '')) {
                continue;
            }
            try {
                $this->query(
                    "UPDATE products SET name = ?, code = ? WHERE id = ?",
                    [$name, $code !== '' ? $code : null, $id]
                );
            } catch (\Throwable $e) {
                // ignore fila
            }
        }
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

        $sessionId = (int)$this->request->post('session_id', 0);
        $closingAmount = (float)$this->request->post('closing_amount', 0);
        $notes = trim((string)$this->request->post('notes', ''));

        if ($sessionId <= 0) {
            $this->json([
                'success' => false,
                'message' => 'Sesion de caja invalida',
                'redirect' => base_url('/app/caja'),
            ]);
            return;
        }

        $session = $this->query(
            "SELECT id, notes, opening_amount, opening_date, user_id
             FROM cash_sessions WHERE id = ? AND status = 'open' LIMIT 1",
            [$sessionId]
        )->fetch();

        if (!$session) {
            $this->json([
                'success' => false,
                'message' => 'Sesion de caja no encontrada o ya esta cerrada',
                'redirect' => base_url('/app/caja'),
            ]);
            return;
        }

        $prevNotes = (string)($session['notes'] ?? '');
        $noteLine = $notes !== '' ? ('Cierre: ' . $notes) : 'Cierre manual';
        $newNotes = trim($prevNotes . ($prevNotes !== '' ? "\n" : '') . $noteLine);

        // Trazabilidad: dejar constancia de stock negativo / deudas al cerrar.
        try {
            $stockAlert = (new \SoftNova\Services\StockService($this->db))->negativeStockAlertReport(25);
            if (!empty($stockAlert['summary_text'])) {
                $newNotes = trim($newNotes . "\n" . $stockAlert['summary_text']);
            }
        } catch (\Throwable $e) {
            $stockAlert = ['count' => 0, 'summary_lines' => []];
        }

        $expectedCash = $this->computeExpectedCash($session);
        $difference = round($closingAmount - $expectedCash, 2);

        $stmt = $this->query(
            "UPDATE cash_sessions
             SET closing_amount = ?, closing_date = NOW(), status = 'closed', notes = ?,
                 expected_amount = ?, difference_amount = ?
             WHERE id = ? AND status = 'open'",
            [$closingAmount, $newNotes, $expectedCash, $difference, $sessionId]
        );

        if ($stmt->rowCount() < 1) {
            $this->json([
                'success' => false,
                'message' => 'La caja ya fue cerrada por otro usuario',
                'redirect' => base_url('/app/caja'),
            ]);
            return;
        }

        $this->postCashClosingAccounting($sessionId);

        $base = rtrim((string)config('app.url', ''), '/');
        $pdfUrl = $base . '/app/caja?action=pdf&id=' . $sessionId;

        $message = 'Caja cerrada correctamente.';
        if (abs($difference) >= 0.01) {
            $message .= $difference < 0
                ? ' Faltante de ' . $this->formatMoney(abs($difference)) . '.'
                : ' Sobrante de ' . $this->formatMoney($difference) . '.';
        }
        if (!empty($stockAlert['count'])) {
            $message .= ' Atención: ' . (int)$stockAlert['count']
                . ' producto(s) con stock negativo o pendiente (préstamo/venta sin stock).';
        }

        $this->json([
            'success' => true,
            'message' => $message,
            'redirect' => base_url('/app/caja?share_close=' . $sessionId),
            'pdf_url' => $pdfUrl,
            'session_id' => $sessionId,
            'expected_cash' => $expectedCash,
            'difference' => $difference,
            'stock_alert_count' => (int)($stockAlert['count'] ?? 0),
        ]);
    }

    /**
     * Efectivo que debería haber en el cajón: base de apertura más los
     * movimientos de efectivo, más las ventas en efectivo que no quedaron
     * registradas como movimiento.
     */
    private function computeExpectedCash(array $session, ?string $until = null): float
    {
        $sessionId = (int)$session['id'];
        $opening = (float)($session['opening_amount'] ?? 0);

        $movTotals = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) incomes,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) expenses
             FROM cash_movements
             WHERE cash_session_id = ?",
            [$sessionId]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0];

        $cash = $opening + (float)$movTotals['incomes'] - (float)$movTotals['expenses'];

        // Ventas en efectivo sin movimiento de caja asociado (evita doble conteo).
        $params = [$sessionId, $sessionId, (string)($session['opening_date'] ?? '')];
        $untilSql = '';
        if ($until) {
            $untilSql = ' AND s.sale_date <= ?';
            $params[] = $until;
        }

        $orphanCashSales = $this->query(
            "SELECT COALESCE(SUM(s.total), 0)
             FROM sales s
             WHERE s.status != 'cancelled'
               AND s.id NOT IN (
                    SELECT COALESCE(reference_id, 0) FROM cash_movements
                    WHERE cash_session_id = ? AND reference_type = 'sale'
               )
               AND s.id NOT IN (
                    SELECT COALESCE(reference_id, 0) FROM cash_movements
                    WHERE cash_session_id = ? AND reference_type = 'sale_payment'
               )
               AND s.sale_date >= ?" . $untilSql . "
               AND COALESCE(s.payment_method, 'cash') IN ('cash', '')",
            $params
        )->fetchColumn();

        return round($cash + (float)$orphanCashSales, 2);
    }

    /** El asiento del arqueo no debe impedir el cierre de la caja. */
    private function postCashClosingAccounting(int $sessionId): void
    {
        try {
            (new \SoftNova\Services\AccountingService($this->db))->postCashSessionClosing($sessionId);
        } catch (\Throwable $e) {
            error_log('Contabilidad arqueo caja ' . $sessionId . ': ' . $e->getMessage());
        }
    }
    
    /**
     * POS y usuarios con "Crear" en Préstamos pueden registrar salidas desde caja
     * sin entrar al módulo Préstamos (mismo patrón que Gastos).
     */
    private function canLoanFromCaja(): bool
    {
        if (TenantMiddleware::isPosRole()) {
            return TenantMiddleware::canDo('create', 'caja')
                || TenantMiddleware::canDo('create', 'prestamos');
        }
        return TenantMiddleware::canDo('create', 'prestamos');
    }

    private function createLoanFromCaja(): void
    {
        if (!$this->canLoanFromCaja()) {
            $this->respond(false, 'No tienes permiso para registrar préstamos desde caja', '/app/caja');
            return;
        }
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }

        $openSession = $this->query(
            "SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch();
        if (!$openSession) {
            $this->respond(false, 'Debe haber una caja abierta para registrar el préstamo', '/app/caja');
            return;
        }

        try {
            $stock = new \SoftNova\Services\StockService($this->db);
            $productId = (int)$this->request->post('product_id', 0);
            $qty = (int)$this->request->post('quantity', 0);
            $result = $stock->registerLoan(
                $productId,
                $qty,
                (string)$this->request->post('reason', ''),
                (string)$this->request->post('note', ''),
                (string)$this->request->post('borrower', '')
            );
            $msg = 'Préstamo registrado. Stock actual: ' . (int)$result['stock_after'];
            if ((int)$result['stock_after'] < 0) {
                $msg .= ' (inventario en negativo — pendiente de devolución).';
            }
            $this->respond(true, $msg, '/app/caja');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/caja');
        }
    }

    /**
     * POS y usuarios con permiso de gastos pueden registrar egresos desde caja
     * sin entrar al módulo Gastos.
     */
    private function canRegisterExpenseFromCaja(): bool
    {
        if (TenantMiddleware::isPosRole()) {
            return TenantMiddleware::canDo('create', 'caja')
                || TenantMiddleware::canDo('create', 'gastos');
        }
        // Solo "Crear" en Gastos basta (sin Ver): registra desde Caja, no abre el módulo.
        return TenantMiddleware::canDo('create', 'gastos');
    }

    private function createExpenseFromCaja(): void
    {
        if (!$this->canRegisterExpenseFromCaja()) {
            $this->respond(false, 'No tienes permiso para registrar gastos desde caja', '/app/caja');
            return;
        }
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }

        $openSession = $this->query(
            "SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch();
        if (!$openSession) {
            $this->respond(false, 'Debe haber una caja abierta para registrar el gasto', '/app/caja');
            return;
        }

        $description = trim((string)$this->request->post('description', ''));
        $amount = (float)$this->request->post('amount', 0);
        $categoryId = (int)$this->request->post('category_id', 0);
        $expenseDate = trim((string)$this->request->post('expense_date', date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            $expenseDate = date('Y-m-d');
        }
        $supplierId = $this->request->post('supplier_id') ? (int)$this->request->post('supplier_id') : null;
        $paymentMethod = \SoftNova\Services\PaymentMethodCatalog::normalize(
            $this->request->post('payment_method', 'cash')
        );
        $receiptNumber = trim((string)$this->request->post('receipt_number', ''));
        $notes = trim((string)$this->request->post('notes', ''));
        $affectCash = $this->request->post('affect_cash') === '1';

        if ($description === '' || $amount <= 0) {
            $this->respond(false, 'Descripción y monto son requeridos', '/app/caja');
            return;
        }

        $rawCategory = trim((string)$this->request->post('category_id', ''));
        if ($rawCategory === '' || $rawCategory === '__other__' || $categoryId <= 0) {
            $this->respond(false, 'Seleccione una categoría de gasto válida', '/app/caja');
            return;
        }

        $categories = new \SoftNova\Services\ExpenseCategoryService($this->db);
        $cat = $categories->find($categoryId);
        if (!$cat) {
            $this->respond(false, 'La categoría de gasto no existe o está inactiva', '/app/caja');
            return;
        }
        $category = (string)$cat['name'];

        $receipt = $this->storeExpenseReceiptUpload();
        if ($receipt === false) {
            $this->respond(false, 'Comprobante inválido (JPG/PNG/WebP/PDF, máx. 5 MB)', '/app/caja');
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
                    $receiptNumber !== '' ? $receiptNumber : null,
                    $receipt['path'] ?? null,
                    $receipt['original'] ?? null,
                    $receipt['mime'] ?? null,
                    $notes !== '' ? $notes : null,
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
            $expenseId = (int)$this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!empty($receipt['path'])) {
                $this->deleteExpenseReceiptFile((string)$receipt['path']);
            }
            $this->respond(false, 'No se pudo registrar el gasto: ' . $e->getMessage(), '/app/caja');
            return;
        }

        try {
            (new \SoftNova\Services\AccountingService($this->db))->postExpense($expenseId, $affectCash);
        } catch (\Throwable $e) {
            error_log('Contabilidad gasto caja ' . $expenseId . ': ' . $e->getMessage());
        }

        if ($affectCash && \SoftNova\Services\PaymentMethodCatalog::affectsCash($paymentMethod)) {
            (new \SoftNova\Services\CashService($this->db))->registerMovement(
                $amount,
                'Gasto: ' . $description,
                'expense',
                'expense',
                $expenseId
            );
        }

        $this->respond(true, 'Gasto registrado: ' . $this->formatMoney($amount), '/app/caja');
    }

    /**
     * @return array{path:string,original:string,mime:string}|null|false
     */
    private function storeExpenseReceiptUpload(): array|null|false
    {
        if (empty($_FILES['receipt_file']['tmp_name'])) {
            return null;
        }
        if (!is_uploaded_file($_FILES['receipt_file']['tmp_name'])) {
            return false;
        }
        if (($_FILES['receipt_file']['size'] ?? 0) > 5242880) {
            return false;
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['receipt_file']['tmp_name']);
        if (!isset($allowed[$mime])) {
            return false;
        }
        $ext = $allowed[$mime];
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

    private function deleteExpenseReceiptFile(string $relative): void
    {
        $relative = str_replace(['..', '\\'], ['', '/'], $relative);
        $full = STORAGE_PATH . '/expenses/' . ltrim($relative, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }

    private function addMovement(): void
    {
        if (TenantMiddleware::isPosRole()) {
            $this->respond(false, 'El rol POS no puede registrar movimientos manuales de caja', '/app/caja');
            return;
        }
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

        $movementId = (int)$this->db->lastInsertId();
        try {
            (new \SoftNova\Services\AccountingService($this->db))->postCashMovement($movementId);
        } catch (\Throwable $e) {
            error_log('Contabilidad movimiento caja ' . $movementId . ': ' . $e->getMessage());
        }
        
        $label = $type === 'income' ? 'Ingreso' : 'Egreso';
        $this->respond(true, "{$label} registrado: " . $this->formatMoney($amount), '/app/caja');
    }
    
    private function closingPdf(): void
    {
        TenantMiddleware::authorize('caja');

        $id = (int)$this->request->get('id');
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            http_response_code(404);
            echo 'Sesion no encontrada';
            exit;
        }

        try {
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateCashClosing(
                $report['session'],
                $report['movements'],
                $report['totals'],
                $report['sales']
            );
            $pdf->download($content, 'Cierre_Caja_' . $id . '_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            error_log('closingPdf: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No se pudo generar el PDF: ' . $e->getMessage();
            exit;
        }
    }

    /** WhatsApp con resumen del cierre (el PDF se descarga aparte). */
    private function reportWhatsapp(): void
    {
        TenantMiddleware::authorize('caja');
        $id = (int)$this->request->get('id', 0);
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            $this->json(['success' => false, 'message' => 'Sesión de caja no encontrada']);
            return;
        }

        $phone = preg_replace('/\D+/', '', (string)$this->request->get('phone', ''));
        if (strlen($phone) === 10) {
            $phone = '57' . $phone;
        }

        $text = $this->closingWhatsappText($report);
        $link = $phone !== ''
            ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($text)
            : 'https://wa.me/?text=' . rawurlencode($text);

        $this->json([
            'success' => true,
            'link' => $link,
            'pdf_url' => rtrim((string)config('app.url', ''), '/') . '/app/caja?action=pdf&id=' . $id,
        ]);
    }

    /** Envía el PDF de cierre por correo. */
    private function reportEmail(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        TenantMiddleware::authorize('caja');

        $id = (int)$this->request->post('id', 0);
        $to = strtolower(trim((string)$this->request->post('email', '')));
        $adminPassword = (string)$this->request->post('admin_password', '');
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            $this->respond(false, 'Sesión de caja no encontrada', '/app/caja');
            return;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->respond(false, 'Indique un correo válido', '/app/caja?share_close=' . $id);
            return;
        }

        $defaultEmail = strtolower(trim($this->getSetting('caja_report_email', '')));
        $isChangingDefault = ($defaultEmail !== '' && strcasecmp($defaultEmail, $to) !== 0);
        if ($isChangingDefault && !$this->verifyTenantAdminPassword($adminPassword)) {
            $this->respond(
                false,
                'Para cambiar el correo predeterminado debe ingresar la clave de administrador del sistema',
                '/app/caja?share_close=' . $id
            );
            return;
        }

        $company = $this->companySettings();
        $empresa = (string)($company['company_name'] ?? 'Seri ERP');
        $session = $report['session'];
        $filename = 'Cierre_Caja_' . $id . '.pdf';
        $temporal = null;
        $resultado = ['success' => false, 'message' => 'No se pudo enviar'];

        try {
            $pdf = new \SoftNova\Services\PdfService($company, $this->getCurrency());
            $content = $pdf->generateCashClosing(
                $session,
                $report['movements'],
                $report['totals'],
                $report['sales']
            );
            $temporal = tempnam(sys_get_temp_dir(), 'caja-') . '.pdf';
            file_put_contents($temporal, $content);

            $apertura = !empty($session['opening_date'])
                ? date('d/m/Y H:i', strtotime((string)$session['opening_date']))
                : '—';
            $cierre = !empty($session['closing_date'])
                ? date('d/m/Y H:i', strtotime((string)$session['closing_date']))
                : 'En curso';
            $body = "Reporte de cierre de caja\n"
                . "Empresa: {$empresa}\n"
                . "Sesión #{$id}\n"
                . "Usuario: " . ($session['user_name'] ?? '') . "\n"
                . "Apertura: {$apertura}\n"
                . "Cierre: {$cierre}\n"
                . "Ventas: " . (int)($report['totals']['sales_count'] ?? 0)
                . " · Total: " . $this->formatMoney((float)($report['totals']['sales_total'] ?? 0)) . "\n"
                . "Adjunto encontrará el PDF del cierre.\n";

            $resultado = (new \SoftNova\Services\MailService($company))->send(
                $to,
                'Cierre de caja #' . $id . ' - ' . $empresa,
                $body,
                $temporal,
                $filename
            );
        } catch (\Throwable $e) {
            $this->respond(false, 'No se pudo enviar el correo: ' . $e->getMessage(), '/app/caja?share_close=' . $id);
            return;
        } finally {
            if ($temporal !== null && is_file($temporal)) {
                @unlink($temporal);
            }
        }

        if (!empty($resultado['success'])) {
            // Primer envío o cambio autorizado → queda como predeterminado.
            if ($defaultEmail === '' || $isChangingDefault) {
                try {
                    $this->upsertSetting('caja_report_email', $to);
                } catch (\Throwable $e) {
                    error_log('caja_report_email save: ' . $e->getMessage());
                }
            }
        }

        $msg = (string)($resultado['message'] ?? '');
        if (!empty($resultado['success']) && ($defaultEmail === '' || $isChangingDefault)) {
            $msg .= ($defaultEmail === '' ? ' Correo guardado como predeterminado.' : ' Correo predeterminado actualizado.');
        }

        $this->respond(
            (bool)$resultado['success'],
            $msg,
            '/app/caja?focus=cierresReportes'
        );
    }

    /**
     * @return array{session:array,movements:list,totals:array,sales:list}|null
     */
    private function loadClosingReport(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $session = $this->query(
            "SELECT cs.*, u.name as user_name FROM cash_sessions cs JOIN users u ON cs.user_id = u.id WHERE cs.id = ?",
            [$id]
        )->fetch();

        if (!$session) {
            return null;
        }

        if (TenantMiddleware::isPosRole()) {
            $uid = (int)($_SESSION['tenant_user_id'] ?? 0);
            if ((int)($session['user_id'] ?? 0) !== $uid) {
                return null;
            }
        }

        $movements = $this->query(
            "SELECT * FROM cash_movements WHERE cash_session_id = ? ORDER BY created_at ASC",
            [$id]
        )->fetchAll();

        $totals = $this->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as incomes,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses
             FROM cash_movements WHERE cash_session_id = ?",
            [$id]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0];

        try {
            $fullTotals = $this->computeSessionTotals($session, $session['closing_date'] ?? null);
            $totals['incomes'] = $fullTotals['incomes'] ?? $totals['incomes'];
            $totals['expenses'] = $fullTotals['expenses'] ?? $totals['expenses'];
            $totals['payment_breakdown'] = $fullTotals['payment_breakdown'] ?? [];
            $totals['sales_total'] = $fullTotals['sales_total'] ?? 0;
            $totals['sales_count'] = $fullTotals['sales_count'] ?? 0;
            $totals['expected_cash'] = $fullTotals['expected_cash'] ?? null;
            $totals['counted_cash'] = $fullTotals['counted_cash'] ?? null;
            $totals['difference'] = $fullTotals['difference'] ?? null;
        } catch (\Throwable $e) {
            $totals['payment_breakdown'] = [];
        }

        $sales = [];
        try {
            $opening = $session['opening_date'] ?? null;
            $closing = $session['closing_date'] ?? null;
            if ($opening) {
                if ($closing) {
                    $sales = $this->query(
                        "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method,
                                COALESCE(c.name, 'Publico general') as customer_name
                         FROM sales s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.sale_date >= ? AND s.sale_date <= ?
                           AND s.status != 'cancelled'
                         ORDER BY s.sale_date ASC",
                        [$opening, $closing]
                    )->fetchAll();
                } else {
                    $sales = $this->query(
                        "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method,
                                COALESCE(c.name, 'Publico general') as customer_name
                         FROM sales s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.sale_date >= ?
                           AND s.status != 'cancelled'
                         ORDER BY s.sale_date ASC",
                        [$opening]
                    )->fetchAll();
                }
            }
        } catch (\Throwable $e) {
            $sales = [];
        }

        return [
            'session' => $session,
            'movements' => $movements ?: [],
            'totals' => $totals ?: ['incomes' => 0, 'expenses' => 0],
            'sales' => $sales,
        ];
    }

    /** @param array{session:array,totals:array,sales:list} $report */
    private function closingWhatsappText(array $report): string
    {
        $session = $report['session'];
        $totals = $report['totals'];
        $empresa = (string)($this->companySettings()['company_name'] ?? 'Seri ERP');
        $id = (int)($session['id'] ?? 0);
        $lines = [
            '*' . $empresa . '* — Cierre de caja #' . $id,
            'Usuario: ' . ($session['user_name'] ?? ''),
            'Apertura: ' . (!empty($session['opening_date']) ? date('d/m/Y H:i', strtotime((string)$session['opening_date'])) : '—'),
            'Cierre: ' . (!empty($session['closing_date']) ? date('d/m/Y H:i', strtotime((string)$session['closing_date'])) : 'En curso'),
            'Apertura $: ' . $this->formatMoney((float)($session['opening_amount'] ?? 0)),
            'Cierre $: ' . $this->formatMoney((float)($session['closing_amount'] ?? 0)),
            'Ventas: ' . (int)($totals['sales_count'] ?? 0) . ' · ' . $this->formatMoney((float)($totals['sales_total'] ?? 0)),
        ];
        if (isset($totals['expected_cash'])) {
            $lines[] = 'Efectivo esperado: ' . $this->formatMoney((float)$totals['expected_cash']);
        }
        if (isset($totals['difference']) && abs((float)$totals['difference']) >= 0.01) {
            $diff = (float)$totals['difference'];
            $lines[] = ($diff < 0 ? 'Faltante: ' : 'Sobrante: ') . $this->formatMoney(abs($diff));
        }
        $lines[] = '';
        $lines[] = 'PDF: ' . rtrim((string)config('app.url', ''), '/') . '/app/caja?action=pdf&id=' . $id;
        return implode("\n", $lines);
    }

    /**
     * Totales de una sesion de caja (movimientos + ventas no duplicadas).
     *
     * @return array{incomes:float,expenses:float,balance:float,sales_count:int,sales_total:float,movements_count:int}
     */
    private function computeSessionTotals(array $session, ?string $until = null): array
    {
        $sessionId = (int)$session['id'];
        $openingAmount = (float)($session['opening_amount'] ?? 0);
        $openingDate = (string)($session['opening_date'] ?? '');
        $until = $until ?: null;

        $movTotals = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as incomes,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses,
                COUNT(*) as movements_count
             FROM cash_movements
             WHERE cash_session_id = ?",
            [$sessionId]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0, 'movements_count' => 0];

        $incomes = (float)$movTotals['incomes'];
        $expenses = (float)$movTotals['expenses'];
        $movementsCount = (int)$movTotals['movements_count'];

        $movements = $this->query(
            "SELECT reference_type, reference_id FROM cash_movements WHERE cash_session_id = ?",
            [$sessionId]
        )->fetchAll();
        $coveredSaleIds = [];
        foreach ($movements as $mov) {
            $rt = (string)($mov['reference_type'] ?? '');
            if (
                in_array($rt, ['sale', 'sale_payment', 'sale_payment_adjust', 'sale_cancel'], true)
                && !empty($mov['reference_id'])
            ) {
                $coveredSaleIds[(int)$mov['reference_id']] = true;
            }
        }

        if ($until) {
            $sales = $this->query(
                "SELECT id, total, status, payment_method, payment_status
                 FROM sales WHERE sale_date >= ? AND sale_date <= ?",
                [$openingDate, $until]
            )->fetchAll();
            $payRows = $this->query(
                "SELECT sp.payment_method, sp.amount
                 FROM sale_payments sp
                 INNER JOIN sales s ON s.id = sp.sale_id
                 WHERE s.sale_date >= ? AND s.sale_date <= ?
                   AND COALESCE(s.status, '') <> 'cancelled'",
                [$openingDate, $until]
            )->fetchAll();
        } else {
            $sales = $this->query(
                "SELECT id, total, status, payment_method, payment_status
                 FROM sales WHERE sale_date >= ?",
                [$openingDate]
            )->fetchAll();
            $payRows = $this->query(
                "SELECT sp.payment_method, sp.amount
                 FROM sale_payments sp
                 INNER JOIN sales s ON s.id = sp.sale_id
                 WHERE s.sale_date >= ?
                   AND COALESCE(s.status, '') <> 'cancelled'",
                [$openingDate]
            )->fetchAll();
        }

        // Solo ventas en efectivo/pagadas SIN movimiento de caja (legacy) entran a ingresos.
        // Evita inflar el balance con tarjeta/Nequi/crédito o doble conteo de abonos.
        $salesIncome = 0.0;
        $salesCount = 0;
        $salesTotal = 0.0;
        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $salesCount++;
            $saleTotal = (float)$sale['total'];
            $salesTotal += $saleTotal;
            $saleId = (int)$sale['id'];
            if (isset($coveredSaleIds[$saleId])) {
                continue;
            }
            $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
            $payStatus = (string)($sale['payment_status'] ?? '');
            if (
                \SoftNova\Services\PaymentMethodCatalog::affectsCash($methodCode)
                && in_array($payStatus, ['paid', ''], true)
            ) {
                $salesIncome += $saleTotal;
            }
        }
        $incomes += $salesIncome;

        $paymentBreakdown = [];
        if ($payRows !== []) {
            foreach ($payRows as $pr) {
                $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($pr['payment_method'] ?? 'cash'));
                if (!isset($paymentBreakdown[$methodCode])) {
                    $paymentBreakdown[$methodCode] = [
                        'label' => \SoftNova\Services\PaymentMethodCatalog::label($methodCode),
                        'total' => 0.0,
                        'count' => 0,
                    ];
                }
                $paymentBreakdown[$methodCode]['total'] += (float)$pr['amount'];
                $paymentBreakdown[$methodCode]['count']++;
            }
        } else {
            // Fallback: ventas sin filas de pago (instalaciones viejas).
            foreach ($sales as $sale) {
                if (($sale['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
                if (!isset($paymentBreakdown[$methodCode])) {
                    $paymentBreakdown[$methodCode] = [
                        'label' => \SoftNova\Services\PaymentMethodCatalog::label($methodCode),
                        'total' => 0.0,
                        'count' => 0,
                    ];
                }
                $paymentBreakdown[$methodCode]['total'] += (float)$sale['total'];
                $paymentBreakdown[$methodCode]['count']++;
            }
        }

        return [
            'incomes' => $incomes,
            'expenses' => $expenses,
            'balance' => $openingAmount + $incomes - $expenses,
            'sales_count' => $salesCount,
            'sales_total' => $salesTotal,
            'movements_count' => $movementsCount,
            'payment_breakdown' => $paymentBreakdown,
            // Solo efectivo: es lo que debe cuadrar contra el conteo del cajón.
            'expected_cash' => $this->computeExpectedCash($session, $until),
            'counted_cash' => isset($session['closing_amount']) && $session['closing_amount'] !== null
                ? round((float)$session['closing_amount'], 2)
                : null,
            'difference' => isset($session['difference_amount']) && $session['difference_amount'] !== null
                ? round((float)$session['difference_amount'], 2)
                : null,
        ];
    }

    /**
     * Cierra automaticamente cajas abiertas cuando ya paso la medianoche
     * del dia en que se abrieron.
     */
    private function autoCloseOvernightSessions(): int
    {
        $closed = 0;
        try {
            $openSessions = $this->query(
                "SELECT * FROM cash_sessions WHERE status = 'open'"
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0;
        }

        $today = date('Y-m-d');
        foreach ($openSessions as $session) {
            $openDay = date('Y-m-d', strtotime((string)$session['opening_date']));
            if ($today <= $openDay) {
                continue;
            }

            $closingAt = $openDay . ' 23:59:59';
            try {
                // Cierre ciego: se asume el efectivo esperado, sin faltante ni sobrante.
                $expectedCash = $this->computeExpectedCash($session, $closingAt);
                $this->query(
                    "UPDATE cash_sessions
                     SET closing_amount = ?, closing_date = ?, status = 'closed',
                         expected_amount = ?, difference_amount = 0,
                         notes = CONCAT(IFNULL(notes, ''), ?)
                     WHERE id = ? AND status = 'open'",
                    [
                        $expectedCash,
                        $closingAt,
                        $expectedCash,
                        "\nCierre automatico a medianoche del " . date('d/m/Y', strtotime($openDay)),
                        (int)$session['id'],
                    ]
                );
                $closed++;
            } catch (\Throwable $e) {
                error_log('autoCloseOvernightSessions #' . ($session['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        return $closed;
    }
}
