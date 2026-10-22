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
        try {
            $this->indexInner();
        } catch (\Throwable $e) {
            error_log('caja index: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            \SoftNova\Core\Logger::error('caja index', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            http_response_code(500);
            $showDebug = (bool)\SoftNova\Core\config('app.debug', false);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>Error interno del servidor</h1>';
            echo '<p>No se pudo cargar Caja. Si el problema continúa, contacte soporte.</p>';
            if ($showDebug) {
                echo '<pre style="white-space:pre-wrap;background:#111;color:#eee;padding:12px;">'
                    . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString())
                    . '</pre>';
            }
        }
    }

    private function indexInner(): void
    {
        try {
            $this->ensureCajaRuntimeSchema();
        } catch (\Throwable $e) {
            error_log('caja schema init: ' . $e->getMessage());
        }
        
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
                    'message' => (bool)config('app.debug', false)
                        ? ('Error al cerrar caja: ' . $e->getMessage())
                        : 'Error al cerrar caja. Revise el cierre e intente de nuevo, o contacte soporte.',
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
        if ($action === 'report_detail' && $this->request->method() === 'GET') {
            $this->reportDetailJson();
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
        if ($action === 'listCombos' && $this->request->method() === 'GET') {
            $this->listCombos();
            return;
        }
        if ($action === 'getCombo' && $this->request->method() === 'GET') {
            $this->getCombo();
            return;
        }
        if ($action === 'expandCombo' && $this->request->method() === 'GET') {
            $this->expandCombo();
            return;
        }
        if ($action === 'saveCombo' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->saveCombo();
            return;
        }
        if ($action === 'deleteCombo' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'edit');
            $this->deleteCombo();
            return;
        }
        if ($action === 'liveSync' && $this->request->method() === 'GET') {
            $this->liveSyncJson();
            return;
        }

        // Cierra cajas abiertas cuyo dia de apertura ya termino (pasada medianoche)
        $autoClosed = $this->autoCloseOvernightSessions();

        $ctx = $this->getOpenSessionsContext();
        $isAdminViewer = $ctx['is_admin_viewer'];
        $openSessions = $ctx['open_sessions'];
        $openSession = $ctx['open_session'];
        $openSessionsCount = $ctx['open_sessions_count'];
        
        $movements = [];
        $hiddenVoidedCount = 0;
        $todaySales = [];
        $salesPagination = null;
        $totals = ['incomes' => 0, 'expenses' => 0, 'other_incomes' => 0, 'balance' => 0, 'sales_count' => 0, 'sales_total' => 0, 'movements_count' => 0];
        $isPosRole = TenantMiddleware::isPosRole();
        
        if ($openSession) {
            $sessionIds = $ctx['session_ids'];

            try {
                if (count($sessionIds) === 1) {
                    $totals = $this->computeSessionTotals($openSession);
                } else {
                    $totals = $this->computeUnifiedOpenTotals($openSessions);
                }
            } catch (\Throwable $e) {
                error_log('caja computeSessionTotals: ' . $e->getMessage());
                $totals = ['incomes' => 0, 'expenses' => 0, 'other_incomes' => 0, 'balance' => 0, 'sales_count' => 0, 'sales_total' => 0, 'movements_count' => 0];
            }
            $movements = $this->fetchSessionMovements($sessionIds);
            $hiddenVoidedCount = 0;
            if ($movements !== []) {
                $hiddenVoidedCount = (int)($movements[0]['_hidden_voided_count'] ?? 0);
                $movements = array_values(array_filter(
                    $movements,
                    static fn($m) => empty($m['_meta_only']) && (int)($m['id'] ?? 0) > 0
                ));
            }

            $salesTotal = (int)$this->query(
                "SELECT COUNT(*) FROM sales WHERE sale_date >= ? AND status != 'cancelled'",
                [$openSession['opening_date']]
            )->fetchColumn();
            $salesPagination = $this->paginate($salesTotal, 15);
            try {
                (new \SoftNova\Services\SalesCreditNoteService($this->db))->ensureSchema();
            } catch (\Throwable $e) {
            }
            try {
                $todaySales = $this->query(
                    "SELECT s.id, s.invoice_number, s.total, s.sale_date, s.payment_method,
                            s.payment_status, s.status, s.notes,
                            c.name as customer_name, u.name as user_name,
                            COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0) as paid_amount,
                            COALESCE(cn.credit_note_total, 0) AS credit_note_total,
                            cn.credit_note_numbers
                     FROM sales s
                     LEFT JOIN customers c ON s.customer_id = c.id
                     LEFT JOIN users u ON s.user_id = u.id
                     LEFT JOIN (
                        SELECT sale_id,
                               SUM(total) AS credit_note_total,
                               GROUP_CONCAT(credit_number ORDER BY id SEPARATOR ', ') AS credit_note_numbers
                        FROM sale_credit_notes
                        WHERE status = 'posted'
                        GROUP BY sale_id
                     ) cn ON cn.sale_id = s.id
                     WHERE s.sale_date >= ?
                     ORDER BY s.sale_date DESC, s.id DESC
                     LIMIT {$salesPagination['perPage']} OFFSET {$salesPagination['offset']}",
                    [$openSession['opening_date']]
                )->fetchAll();
            } catch (\Throwable $e) {
                $todaySales = $this->query(
                    "SELECT s.id, s.invoice_number, s.total, s.sale_date, s.payment_method,
                            s.payment_status, s.status, s.notes,
                            c.name as customer_name, u.name as user_name,
                            COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0) as paid_amount,
                            0 AS credit_note_total,
                            NULL AS credit_note_numbers
                     FROM sales s
                     LEFT JOIN customers c ON s.customer_id = c.id
                     LEFT JOIN users u ON s.user_id = u.id
                     WHERE s.sale_date >= ?
                     ORDER BY s.sale_date DESC, s.id DESC
                     LIMIT {$salesPagination['perPage']} OFFSET {$salesPagination['offset']}",
                    [$openSession['opening_date']]
                )->fetchAll();
            }
        }

        $todayExpenses = [];
        $todayExpensesByUser = [];
        $todayExpensesTotal = 0.0;
        try {
            if ($openSession) {
                $expenseSessionIds = $ctx['session_ids'];
                $expenseIn = implode(',', array_fill(0, count($expenseSessionIds), '?'));
                // Solo egresos que realmente salieron del cajón (movimiento de caja).
                // Admin: todos los usuarios. Resto: solo los propios.
                $expenseSql = "SELECT e.id, e.description, e.amount, e.expense_date, e.payment_method,
                            e.receipt_number, e.user_id, e.created_at,
                            COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name,
                            COALESCE(NULLIF(TRIM(ec.name), ''), NULLIF(TRIM(e.category), ''), 'General') AS category_label,
                            s.name AS supplier_name
                     FROM expenses e
                     INNER JOIN cash_movements cm
                        ON cm.reference_type = 'expense' AND cm.reference_id = e.id
                       AND cm.type = 'expense' AND cm.cash_session_id IN ({$expenseIn})
                     LEFT JOIN users u ON u.id = e.user_id
                     LEFT JOIN expense_categories ec ON ec.id = e.category_id
                     LEFT JOIN suppliers s ON s.id = e.supplier_id
                     WHERE COALESCE(e.status, 'active') <> 'cancelled'";
                $expenseParams = $expenseSessionIds;
                if (!$isAdminViewer) {
                    $expenseSql .= " AND e.user_id = ?";
                    $expenseParams[] = (int)($_SESSION['tenant_user_id'] ?? 0);
                }
                $expenseSql .= " ORDER BY e.created_at DESC, e.id DESC";
                $todayExpenses = $this->query($expenseSql, $expenseParams)->fetchAll();
                $todayExpenses = array_merge(
                    $todayExpenses ?: [],
                    $this->purchaseCashOutflows($expenseSessionIds, $isAdminViewer ? 0 : (int)($_SESSION['tenant_user_id'] ?? 0))
                );
                usort($todayExpenses, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
            }

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

        // Reportes de cierre: por defecto últimos 2 días; filtro opcional de un solo día.
        $historyDate = trim((string)$this->request->get('history_date', ''));
        if ($historyDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDate)) {
            $historyDate = '';
        }
        if ($historyDate !== '') {
            $historyWhere = ["cs.status = 'closed'", 'DATE(cs.closing_date) = ?'];
            $historyParams = [$historyDate];
        } else {
            $historyFrom = date('Y-m-d', strtotime('-1 day'));
            $historyTo = date('Y-m-d');
            $historyWhere = ["cs.status = 'closed'", 'DATE(cs.closing_date) >= ?', 'DATE(cs.closing_date) <= ?'];
            $historyParams = [$historyFrom, $historyTo];
        }
        if ($isPosRole) {
            $historyWhere[] = 'cs.user_id = ?';
            $historyParams[] = (int)($_SESSION['tenant_user_id'] ?? 0);
        }
        try {
            $historySessions = $this->query(
                "SELECT cs.*, u.name as user_name
                 FROM cash_sessions cs
                 JOIN users u ON cs.user_id = u.id
                 WHERE " . implode(' AND ', $historyWhere) . "
                 ORDER BY cs.closing_date DESC
                 LIMIT 50",
                $historyParams
            )->fetchAll();
        } catch (\Throwable $e) {
            $historySessions = [];
        }
        // Enriquecer listado: ventas todos los medios (el cajón solo muestra efectivo).
        foreach ($historySessions as &$histRow) {
            try {
                $ht = $this->computeSessionTotals($histRow, $histRow['closing_date'] ?? null);
                $histRow['report_sales_total'] = (float)($ht['sales_total'] ?? 0);
                $histRow['report_sales_count'] = (int)($ht['sales_count'] ?? 0);
                $histRow['report_cash_sales'] = (float)($ht['cash_sales'] ?? 0);
                $histRow['report_expenses'] = (float)($ht['expenses'] ?? 0);
                $histRow['report_expected_cash'] = (float)(
                    $histRow['expected_amount']
                    ?? $ht['expected_cash']
                    ?? 0
                );
                $payLabels = [];
                foreach (($ht['payment_breakdown'] ?? []) as $code => $pb) {
                    $payLabels[] = [
                        'code' => (string)$code,
                        'label' => (string)($pb['label'] ?? $code),
                        'total' => round((float)($pb['total'] ?? 0), 2),
                        'count' => (int)($pb['count'] ?? 0),
                    ];
                }
                $histRow['report_payments'] = $payLabels;
            } catch (\Throwable $e) {
                $histRow['report_sales_total'] = null;
                $histRow['report_sales_count'] = 0;
                $histRow['report_cash_sales'] = null;
                $histRow['report_expenses'] = null;
                $histRow['report_expected_cash'] = isset($histRow['expected_amount'])
                    ? (float)$histRow['expected_amount']
                    : null;
                $histRow['report_payments'] = [];
            }
        }
        unset($histRow);

        $posCustomers = [];
        $paymentMethods = [];
        $recentProducts = [];
        $offerProducts = [];
        $suggestedCombos = [];
        $consignmentProducts = [];
        $operationalCats = [];
        $financialCats = [];
        $administrativeCats = [];
        $managementCats = [];
        $customSubgroupSections = [];
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
                try {
                    (new \SoftNova\Services\ProductOfferService($this->db))->expireDueOffers();
                } catch (\Throwable $e) {
                    // ignore
                }
                $openItem = \SoftNova\Services\OpenItemProduct::ensure($this->db);
                $offerProducts = [];
                try {
                    $offerProducts = (new \SoftNova\Services\ProductOfferService($this->db))->latestActiveOffers(3);
                } catch (\Throwable $e) {
                    $offerProducts = [];
                }
                foreach ($offerProducts as &$op) {
                    $op['tax_rate'] = \SoftNova\Services\TaxService::resolveProductRate(
                        $op,
                        \SoftNova\Services\TaxService::defaultRate($this->db),
                        $this->db
                    );
                }
                unset($op);
                try {
                    $suggestedCombos = (new \SoftNova\Services\ProductComboService($this->db))->listAvailable(6);
                } catch (\Throwable $e) {
                    $suggestedCombos = [];
                }
                // Últimos 5 productos vendidos (por fecha de venta más reciente).
                $recentProducts = $this->query(
                    "SELECT p.id, p.name, p.code, p.sale_price, COALESCE(p.wholesale_price, 0) AS wholesale_price,
                            COALESCE(p.purchase_price, 0) AS purchase_price,
                            p.stock, COALESCE(p.product_type,'product') AS product_type,
                            COALESCE(p.tax_rate, 0) AS tax_rate,
                            MAX(s.sale_date) AS last_sold_at
                     FROM sale_items si
                     INNER JOIN sales s ON s.id = si.sale_id
                     INNER JOIN products p ON p.id = si.product_id
                     WHERE p.status = 'active'
                       AND COALESCE(s.status, 'completed') <> 'cancelled'
                       AND si.product_id IS NOT NULL
                       AND UPPER(COALESCE(p.code,'')) <> ?
                     GROUP BY p.id, p.name, p.code, p.sale_price, p.wholesale_price, p.purchase_price, p.stock, p.product_type, p.tax_rate
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
                if (empty($rp['is_open_item'])) {
                    $rp['tax_rate'] = \SoftNova\Services\TaxService::resolveProductRate(
                        $rp,
                        \SoftNova\Services\TaxService::defaultRate($this->db),
                        $this->db
                    );
                } else {
                    $rp['tax_rate'] = \SoftNova\Services\TaxService::defaultRate($this->db);
                }
            }
            unset($rp);

            // Acceso rápido: mercancía en consignación (no inventario propio).
            try {
                (new \SoftNova\Services\ConsignmentService($this->db))->ensureSchema();
                $consignmentProducts = $this->query(
                    "SELECT p.id, p.name, p.code, p.sale_price, COALESCE(p.wholesale_price, 0) AS wholesale_price,
                            COALESCE(p.purchase_price, 0) AS purchase_price,
                            p.stock, COALESCE(p.product_type,'product') AS product_type,
                            COALESCE(p.ownership,'owned') AS ownership, p.consignor_id,
                            COALESCE(p.tax_rate, 0) AS tax_rate,
                            c.name AS consignor_name
                     FROM products p
                     LEFT JOIN consignors c ON c.id = p.consignor_id
                     WHERE p.status = 'active'
                       AND COALESCE(p.ownership,'owned') = 'consignment'
                       AND COALESCE(p.product_type,'product') = 'product'
                     ORDER BY c.name ASC, p.name ASC
                     LIMIT 300"
                )->fetchAll();
                foreach ($consignmentProducts as &$cp) {
                    $cp['name'] = \SoftNova\Core\utf8_fix((string)($cp['name'] ?? ''));
                    $cp['code'] = \SoftNova\Core\utf8_fix((string)($cp['code'] ?? ''));
                    $cp['consignor_name'] = \SoftNova\Core\utf8_fix((string)($cp['consignor_name'] ?? ''));
                    $cp['is_consignment'] = 1;
                    // Tasa cruda del catálogo (0 = exento). Caja aplica la misma regla que inventario.
                    $cp['tax_rate'] = round((float)($cp['tax_rate'] ?? 0), 2);
                }
                unset($cp);
            } catch (\Throwable $e) {
                $consignmentProducts = [];
            }

            if ($openSession && $this->canRegisterExpenseFromCaja()) {
                try {
                    $catSvc = new \SoftNova\Services\ExpenseCategoryService($this->db);
                    $operationalCats = $catSvc->listActive('operational');
                    $financialCats = $catSvc->listActive('financial');
                    $administrativeCats = $catSvc->listActive('administrative');
                    $managementCats = $catSvc->listActive('management');
                    $customSubgroupSections = $catSvc->listCustomSubgroupSections();
                } catch (\Throwable $e) {
                    $operationalCats = [];
                    $financialCats = [];
                    $administrativeCats = [];
                    $managementCats = [];
                    $customSubgroupSections = [];
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
        $creditSurchargeTiers = [];
        $quantityDiscountTiers = [];
        try {
            $commCfg = (new \SoftNova\Services\CommissionService($this->db))->config();
            $creditSurchargeRate = (float)($commCfg['credit_over_60_rate'] ?? 0);
            $creditSurchargeDays = (int)($commCfg['credit_over_60_days'] ?? 60);
            $creditSurchargeTiers = $commCfg['credit_surcharge_tiers'] ?? [];
            $quantityDiscountTiers = $commCfg['quantity_discount_tiers'] ?? [];
            if ($creditSurchargeDays < 1) {
                $creditSurchargeDays = 60;
            }
        } catch (\Throwable $e) {
        }
        $taxRate = \SoftNova\Services\TaxService::normalizeRate((float)$this->getSetting('tax_rate', '0'));
        $taxEnabled = \SoftNova\Services\TaxService::isCalculationEnabled($this->db);
        $productTaxEnabled = \SoftNova\Services\TaxService::isProductTaxEnabled($this->db);
        if (!$taxEnabled) {
            $taxRate = 0.0;
        }

        $stockAlert = [
            'count' => 0,
            'negative_count' => 0,
            'loan_count' => 0,
            'loan_received_count' => 0,
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
            'hiddenVoidedCount' => $hiddenVoidedCount,
            'todaySales' => $todaySales,
            'salesPagination' => $salesPagination,
            'todayExpensesByUser' => $todayExpensesByUser,
            'todayExpensesTotal' => $todayExpensesTotal,
            'todayExpensesCount' => count($todayExpenses),
            'taxRate' => $taxRate,
            'taxEnabled' => $taxEnabled,
            'productTaxEnabled' => $productTaxEnabled,
            'totals' => $totals,
            'historySessions' => $historySessions,
            'historyDate' => $historyDate,
            'posCustomers' => $posCustomers,
            'paymentMethods' => $paymentMethods,
            'recentProducts' => $recentProducts,
            'offerProducts' => $offerProducts,
            'suggestedCombos' => $suggestedCombos,
            'consignmentProducts' => $consignmentProducts,
            'operationalCats' => $operationalCats,
            'financialCats' => $financialCats,
            'administrativeCats' => $administrativeCats,
            'managementCats' => $managementCats,
            'customSubgroupSections' => $customSubgroupSections,
            'expenseSuppliers' => $expenseSuppliers,
            'loanProducts' => $loanProducts,
            'canLoanFromCaja' => $this->canLoanFromCaja(),
            'loanReasonsHtml' => \SoftNova\Services\ProductLoan::optionsHtml(null, $this->db),
            'loanProductIdPrefill' => (int)$this->request->get('loan_product_id', 0),
            'isPosRole' => $isPosRole,
            'isAdminViewer' => $isAdminViewer,
            'canManageCashSession' => $openSession ? $this->canManageCashSession($openSession) : false,
            'openSessionsCount' => count($openSessions),
            'canExpenseFromCaja' => $this->canRegisterExpenseFromCaja(),
            'cancelReasons' => $cancelReasons,
            'stockAlert' => $stockAlert,
            'companyEmail' => (string)($company['company_email'] ?? ''),
            'cajaReportEmail' => $this->resolveCajaReportEmail((string)($company['company_email'] ?? '')),
            'creditSurchargeRate' => $creditSurchargeRate,
            'creditSurchargeDays' => $creditSurchargeDays,
            'creditSurchargeTiers' => $creditSurchargeTiers,
            'quantityDiscountTiers' => $quantityDiscountTiers,
            'invoicePrefix' => $this->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix' LIMIT 1"
            )->fetchColumn() ?: 'FAC-',
            'autoPrintTicket' => !empty(
                \SoftNova\Services\SalesDocumentConfig::fromSettings($company)['ticket']['auto_print_ticket']
            ),
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
        try {
            \SoftNova\Services\TaxService::ensureSchema($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            (new \SoftNova\Services\ProductOfferService($this->db))->expireDueOffers();
        } catch (\Throwable $e) {
            // ignore
        }
        $q = trim((string)$this->request->get('q', ''));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }

        $like = '%' . $q . '%';
        $exact = $q;
        // EAN limpio (solo dígitos) para lectores / espacios / guiones.
        $eanDigits = preg_replace('/\D+/', '', $q) ?? '';
        $eanAlt = '';
        if (strlen($eanDigits) === 12) {
            $eanAlt = '0' . $eanDigits;
        } elseif (strlen($eanDigits) === 13 && str_starts_with($eanDigits, '0')) {
            $eanAlt = substr($eanDigits, 1);
        }
        $barcodeExpr = "REPLACE(REPLACE(TRIM(COALESCE(barcode,'')), ' ', ''), '-', '')";
        try {
            $rows = $this->query(
                "SELECT id, name, code, sale_price, COALESCE(wholesale_price, 0) AS wholesale_price,
                        COALESCE(purchase_price, 0) AS purchase_price,
                        stock, min_stock, COALESCE(product_type,'product') AS product_type,
                        COALESCE(barcode,'') AS barcode,
                        COALESCE(description,'') AS description,
                        COALESCE(ownership,'owned') AS ownership,
                        consignor_id, COALESCE(tax_rate, 0) AS tax_rate
                 FROM products
                 WHERE status = 'active'
                   AND COALESCE(ownership,'owned') <> 'consignment'
                   AND (
                        code = ?
                        OR barcode = ?
                        OR TRIM(COALESCE(barcode,'')) = ?
                        OR ({$barcodeExpr} <> '' AND {$barcodeExpr} IN (?, ?))
                        OR code LIKE ?
                        OR name LIKE ?
                        OR barcode LIKE ?
                        OR CAST(id AS CHAR) = ?
                   )
                 ORDER BY
                    CASE
                        WHEN code = ? THEN 0
                        WHEN barcode = ? OR TRIM(COALESCE(barcode,'')) = ? OR {$barcodeExpr} IN (?, ?) THEN 1
                        WHEN code LIKE ? THEN 2
                        WHEN name LIKE ? THEN 3
                        ELSE 4
                    END,
                    name ASC
                 LIMIT 25",
                [
                    $exact, $exact, $exact,
                    $eanDigits !== '' ? $eanDigits : $exact,
                    $eanAlt !== '' ? $eanAlt : ($eanDigits !== '' ? $eanDigits : $exact),
                    $like, $like, $like, $exact,
                    $exact, $exact, $exact,
                    $eanDigits !== '' ? $eanDigits : $exact,
                    $eanAlt !== '' ? $eanAlt : ($eanDigits !== '' ? $eanDigits : $exact),
                    $exact . '%', $like,
                ]
            )->fetchAll();
        } catch (\Throwable $e) {
            try {
                $rows = $this->query(
                    "SELECT id, name, code, sale_price, COALESCE(wholesale_price, 0) AS wholesale_price,
                            COALESCE(purchase_price, 0) AS purchase_price,
                            stock, min_stock, COALESCE(product_type,'product') AS product_type,
                            COALESCE(barcode,'') AS barcode,
                            COALESCE(description,'') AS description
                     FROM products
                     WHERE status = 'active'
                       AND (
                            code = ?
                            OR barcode = ?
                            OR TRIM(COALESCE(barcode,'')) = ?
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
                    [$exact, $exact, $exact, $like, $like, $like, $exact, $exact, $exact, $exact . '%', $like]
                )->fetchAll();
            } catch (\Throwable $e2) {
                $this->json(['success' => false, 'message' => 'No se pudo buscar productos', 'products' => []]);
                return;
            }
        }

        $defaultTax = \SoftNova\Services\TaxService::defaultRate($this->db);
        $products = array_map(function (array $p) use ($defaultTax): array {
            $code = \SoftNova\Core\utf8_fix((string)($p['code'] ?? ''));
            $ownership = (string)($p['ownership'] ?? 'owned');
            $tax = \SoftNova\Services\TaxService::resolveProductRate($p, $defaultTax, $this->db);
            return [
                'id' => (int)$p['id'],
                'name' => \SoftNova\Core\utf8_fix((string)$p['name']),
                'code' => $code,
                'barcode' => \SoftNova\Core\utf8_fix((string)($p['barcode'] ?? '')),
                'description' => \SoftNova\Core\utf8_fix((string)($p['description'] ?? '')),
                'sale_price' => (float)$p['sale_price'],
                'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
                'purchase_price' => (float)($p['purchase_price'] ?? 0),
                'stock' => (int)$p['stock'],
                'product_type' => (string)($p['product_type'] ?? 'product'),
                'is_open_item' => strtoupper($code) === \SoftNova\Services\OpenItemProduct::CODE ? 1 : 0,
                'ownership' => $ownership,
                'is_consignment' => $ownership === 'consignment' ? 1 : 0,
                'consignor_id' => (int)($p['consignor_id'] ?? 0),
                'tax_rate' => $tax,
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
        $limit = max(100, min(5000, (int)$this->request->get('limit', 3000)));
        $offset = max(0, (int)$this->request->get('offset', 0));

        try {
            \SoftNova\Services\OpenItemProduct::ensure($this->db);
            try {
                \SoftNova\Services\TaxService::ensureSchema($this->db);
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                (new \SoftNova\Services\ProductOfferService($this->db))->expireDueOffers();
            } catch (\Throwable $e) {
                // ignore
            }
            $total = (int)$this->query(
                "SELECT COUNT(*) FROM products
                 WHERE status = 'active'
                   AND COALESCE(ownership,'owned') <> 'consignment'"
            )->fetchColumn();
            $rows = $this->query(
                "SELECT id, name, code, sale_price, COALESCE(wholesale_price,0) AS wholesale_price,
                        COALESCE(purchase_price,0) AS purchase_price, stock,
                        COALESCE(product_type, 'product') AS product_type,
                        COALESCE(barcode,'') AS barcode,
                        COALESCE(description,'') AS description,
                        COALESCE(ownership,'owned') AS ownership, consignor_id,
                        COALESCE(tax_rate, 0) AS tax_rate
                 FROM products
                 WHERE status = 'active'
                   AND COALESCE(ownership,'owned') <> 'consignment'
                 ORDER BY
                    CASE WHEN UPPER(COALESCE(code,'')) = ? THEN 0 ELSE 1 END,
                    id ASC
                 LIMIT {$limit} OFFSET {$offset}",
                [\SoftNova\Services\OpenItemProduct::CODE]
            )->fetchAll();
        } catch (\Throwable $e) {
            try {
                $total = (int)$this->query(
                    "SELECT COUNT(*) FROM products WHERE status = 'active'"
                )->fetchColumn();
                $rows = $this->query(
                    "SELECT id, name, code, sale_price, COALESCE(purchase_price,0) AS purchase_price, stock,
                            COALESCE(product_type, 'product') AS product_type,
                            COALESCE(barcode,'') AS barcode,
                            COALESCE(description,'') AS description
                     FROM products
                     WHERE status = 'active'
                     ORDER BY
                        CASE WHEN UPPER(COALESCE(code,'')) = ? THEN 0 ELSE 1 END,
                        id ASC
                     LIMIT {$limit} OFFSET {$offset}",
                    [\SoftNova\Services\OpenItemProduct::CODE]
                )->fetchAll();
            } catch (\Throwable $e2) {
                $this->json(['success' => false, 'message' => 'No se pudo cargar el catálogo', 'products' => []]);
                return;
            }
        }

        $defaultTax = \SoftNova\Services\TaxService::defaultRate($this->db);
        $products = array_map(function (array $p) use ($defaultTax): array {
            $code = \SoftNova\Core\utf8_fix((string)($p['code'] ?? ''));
            $ownership = (string)($p['ownership'] ?? 'owned');
            $tax = \SoftNova\Services\TaxService::resolveProductRate($p, $defaultTax, $this->db);
            return [
                'id' => (int)$p['id'],
                'name' => \SoftNova\Core\utf8_fix((string)$p['name']),
                'code' => $code,
                'barcode' => \SoftNova\Core\utf8_fix((string)($p['barcode'] ?? '')),
                'description' => \SoftNova\Core\utf8_fix((string)($p['description'] ?? '')),
                'sale_price' => (float)$p['sale_price'],
                'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
                'purchase_price' => (float)($p['purchase_price'] ?? 0),
                'stock' => (int)$p['stock'],
                'product_type' => (string)($p['product_type'] ?? 'product'),
                'is_open_item' => strtoupper($code) === \SoftNova\Services\OpenItemProduct::CODE ? 1 : 0,
                'ownership' => $ownership,
                'is_consignment' => $ownership === 'consignment' ? 1 : 0,
                'consignor_id' => (int)($p['consignor_id'] ?? 0),
                'tax_rate' => $tax,
            ];
        }, $rows);

        $fetched = count($products);
        $this->json([
            'success' => true,
            'products' => $products,
            'synced_at' => date('c'),
            'count' => $fetched,
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total ?? ($offset + $fetched),
            'has_more' => ($offset + $fetched) < (int)($total ?? ($offset + $fetched)),
        ]);
    }

    private function listCombos(): void
    {
        TenantMiddleware::authorize('caja');
        $svc = new \SoftNova\Services\ProductComboService($this->db);
        $all = $this->request->get('all') === '1';
        $combos = $all ? $svc->listCombos(true, 100) : $svc->listAvailable(50);
        $this->json([
            'success' => true,
            'combos' => $combos,
            'reasons' => \SoftNova\Services\ProductComboService::REASONS,
        ]);
    }

    private function getCombo(): void
    {
        TenantMiddleware::authorize('caja');
        $id = (int)$this->request->get('id', 0);
        $svc = new \SoftNova\Services\ProductComboService($this->db);
        $combo = $svc->getCombo($id, true);
        if (!$combo) {
            $this->json(['success' => false, 'message' => 'Combo no encontrado'], 404);
            return;
        }
        $this->json(['success' => true, 'combo' => $combo]);
    }

    private function expandCombo(): void
    {
        TenantMiddleware::authorize('caja');
        $id = (int)$this->request->get('id', 0);
        $packs = max(1, min(99, (int)$this->request->get('packs', 1)));
        $override = $this->request->get('price', null);
        $overridePrice = ($override !== null && $override !== '')
            ? round((float)$override, 2)
            : null;
        $svc = new \SoftNova\Services\ProductComboService($this->db);
        $result = $svc->expandForSale($id, $packs, $overridePrice);
        if (empty($result['ok'])) {
            $this->json(['success' => false, 'message' => (string)($result['message'] ?? 'No se pudo aplicar el combo')]);
            return;
        }
        $this->json([
            'success' => true,
            'message' => 'Combo listo para la venta',
            'combo' => $result['combo'] ?? null,
            'lines' => $result['lines'] ?? [],
            'combo_price' => $result['combo_price'] ?? 0,
            'retail_sum' => $result['retail_sum'] ?? 0,
        ]);
    }

    private function saveCombo(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        $itemsRaw = $this->request->post('items', []);
        if (!is_array($itemsRaw)) {
            $itemsRaw = [];
        }
        // FormData plano items[0][product_id]
        if ($itemsRaw === [] && isset($_POST['items']) && is_array($_POST['items'])) {
            $itemsRaw = $_POST['items'];
        }
        $data = [
            'name' => (string)$this->request->post('name', ''),
            'sale_price' => \SoftNova\Core\parse_money($this->request->post('sale_price', 0)),
            'reason' => (string)$this->request->post('reason', 'promocion'),
            'reason_note' => (string)$this->request->post('reason_note', ''),
            'valid_from' => (string)$this->request->post('valid_from', ''),
            'valid_until' => (string)$this->request->post('valid_until', ''),
            'notes' => (string)$this->request->post('notes', ''),
            'items' => $itemsRaw,
        ];
        $comboId = (int)$this->request->post('id', 0);
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $svc = new \SoftNova\Services\ProductComboService($this->db);
        $result = $svc->saveCombo($data, $userId ?: null, $comboId > 0 ? $comboId : null);
        $this->json([
            'success' => !empty($result['ok']),
            'message' => (string)($result['message'] ?? ''),
            'combo' => $result['combo'] ?? null,
            'csrf_token' => \SoftNova\Core\csrf_token(),
        ], !empty($result['ok']) ? 200 : 422);
    }

    private function deleteCombo(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        $svc = new \SoftNova\Services\ProductComboService($this->db);
        $ok = $svc->deactivate($id);
        $this->json([
            'success' => $ok,
            'message' => $ok ? 'Combo archivado' : 'No se pudo archivar el combo',
            'csrf_token' => \SoftNova\Core\csrf_token(),
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

        $openingAmount = \SoftNova\Core\parse_money($this->request->post('opening_amount'));
        $notes = $this->request->post('notes', '');

        if ($openingAmount < 0) {
            $this->respond(false, 'El monto de apertura no puede ser negativo', '/app/caja');
            return;
        }

        // H2 (decisión cliente): UNA sola caja abierta para todo el negocio.
        // Candado MySQL para que dos clics/peticiones a la vez no creen dos sesiones.
        $lockOk = false;
        $error = '';
        $sessionId = 0;
        try {
            try {
                $lockRow = $this->query("SELECT GET_LOCK('seri_cash_open', 5) AS got")->fetch();
                $lockOk = (int)($lockRow['got'] ?? 0) === 1;
            } catch (\Throwable $e) {
                $lockOk = false;
            }
            if (!$lockOk) {
                $error = 'Otra apertura de caja está en curso. Espere un momento e intente de nuevo.';
            } else {
                $existing = $this->query(
                    "SELECT cs.id, cs.opening_date, COALESCE(NULLIF(TRIM(u.name), ''), 'otro usuario') AS user_name
                     FROM cash_sessions cs
                     LEFT JOIN users u ON u.id = cs.user_id
                     WHERE cs.status = 'open'
                     ORDER BY cs.id ASC LIMIT 1"
                )->fetch();
                if ($existing) {
                    $desde = !empty($existing['opening_date'])
                        ? date('d/m/Y H:i', strtotime((string)$existing['opening_date']))
                        : '';
                    $error = 'Ya hay una caja abierta por ' . (string)$existing['user_name']
                        . ($desde !== '' ? ' desde el ' . $desde : '')
                        . '. Solo puede haber una caja abierta: debe cerrarse primero.';
                } else {
                    $this->query(
                        "INSERT INTO cash_sessions (user_id, opening_amount, opening_date, status, notes)
                         VALUES (?, ?, NOW(), 'open', ?)",
                        [$_SESSION['tenant_user_id'], $openingAmount, $notes]
                    );
                    $sessionId = (int)$this->db->lastInsertId();
                }
            }
        } finally {
            if ($lockOk) {
                try {
                    $this->query("SELECT RELEASE_LOCK('seri_cash_open')");
                } catch (\Throwable $e) {
                }
            }
        }

        if ($error !== '') {
            $this->respond(false, $error, '/app/caja');
            return;
        }

        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'open',
            'caja',
            'Apertura de caja con ' . $this->formatMoney($openingAmount),
            'cash_session',
            $sessionId
        );

        $this->respond(true, 'Caja abierta exitosamente con ' . $this->formatMoney($openingAmount), '/app/caja');
    }
    
    private function closeCash(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }

        $this->ensureCajaRuntimeSchema();

        $sessionId = (int)$this->request->post('session_id', 0);
        $closingAmount = \SoftNova\Core\parse_money($this->request->post('closing_amount', 0));
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

        if (!$this->canManageCashSession($session)) {
            $this->json([
                'success' => false,
                'message' => $this->cashSessionOwnerMessage($session, 'cerrarla'),
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

        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'close',
            'caja',
            'Cierre de caja · contado ' . $this->formatMoney($closingAmount)
                . ' · esperado ' . $this->formatMoney($expectedCash)
                . ' · dif. ' . $this->formatMoney($difference),
            'cash_session',
            $sessionId
        );

        $this->json([
            'success' => true,
            'message' => $message,
            'redirect' => base_url('/app/caja?share_close=' . $sessionId),
            'auto_pdf' => false,
            'session_id' => $sessionId,
            'expected_cash' => $expectedCash,
            'difference' => $difference,
            'stock_alert_count' => (int)($stockAlert['count'] ?? 0),
        ]);
    }

    /**
     * Columnas de ventas/caja que no vienen en installs antiguos (payment_terms, arqueo, etc.).
     */
    private function ensureCajaRuntimeSchema(): void
    {
        try {
            SupplyChainSchema::ensure($this->db);
        } catch (\Throwable $e) {
            error_log('caja SupplyChainSchema: ' . $e->getMessage());
        }
        try {
            new \SoftNova\Services\SalesDocumentService($this->db);
        } catch (\Throwable $e) {
            error_log('caja SalesDocumentService: ' . $e->getMessage());
        }
        try {
            (new \SoftNova\Services\ConsignmentService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
            error_log('caja ConsignmentService: ' . $e->getMessage());
        }
        try {
            (new \SoftNova\Services\ProductComboService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
            error_log('caja ProductComboService: ' . $e->getMessage());
        }
    }

    /**
     * Efectivo que debería haber en el cajón: base de apertura más los
     * movimientos de efectivo, más las ventas en efectivo que no quedaron
     * registradas como movimiento.
     */
    private function computeExpectedCash(array $session, ?string $until = null): float
    {
        $this->ensureCajaRuntimeSchema();
        return (new \SoftNova\Services\CashService($this->db))->expectedCashForSession($session, $until);
    }

    /** El asiento del arqueo no debe impedir el cierre de la caja. */
    private function postCashClosingAccounting(int $sessionId): void
    {
        try {
            (new \SoftNova\Services\AccountingService($this->db))->postCashSessionClosing($sessionId);
        } catch (\Throwable $e) {
            error_log('Contabilidad arqueo caja ' . $sessionId . ': ' . $e->getMessage());
            try {
                (new \SoftNova\Services\AccountingService($this->db))->enqueuePending(
                    'post_cash_session',
                    'cash_session',
                    $sessionId,
                    $e->getMessage()
                );
            } catch (\Throwable $ignore) {
            }
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
        $amount = \SoftNova\Core\parse_money($this->request->post('amount', 0));
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
        $cashExplanation = trim((string)$this->request->post('cash_explanation', ''));

        if ($description === '' || $amount <= 0) {
            $this->respond(false, 'Descripción y monto son requeridos', '/app/caja');
            return;
        }

        $rawCategory = trim((string)$this->request->post('category_id', ''));
        if (str_starts_with($rawCategory, '__other_')) {
            $this->respond(
                false,
                'Solo el administrador puede crear categorías nuevas. Use Configuración → Administración de gastos.',
                '/app/caja'
            );
            return;
        }
        if ($rawCategory === '' || $categoryId <= 0) {
            $this->respond(false, 'Seleccione una categoría de gasto válida', '/app/caja');
            return;
        }

        $categories = new \SoftNova\Services\ExpenseCategoryService($this->db);
        $cat = $categories->find($categoryId);
        if (!$cat) {
            $this->respond(false, 'La categoría de gasto no existe o está inactiva', '/app/caja');
            return;
        }
        $isCashPay = \SoftNova\Services\PaymentMethodCatalog::affectsCash($paymentMethod);
        $isAccountingExpense = $categories->isAccounting($cat);
        $touchCajon = $isCashPay && $categories->affectsCashDrawer($cat);
        $markPaid = !$isCashPay && $paymentMethod !== '';

        if ($isCashPay && $isAccountingExpense) {
            if ($cashExplanation === '') {
                $this->respond(
                    false,
                    'En gastos contables con efectivo indique la explicación (ej. retiro cajero). No descuenta el cajón.',
                    '/app/caja'
                );
                return;
            }
            $markPaid = true;
            $prefix = 'Efectivo: ' . $cashExplanation;
            $notes = $notes !== '' ? ($prefix . ' | ' . $notes) : $prefix;
        } elseif ($isCashPay && $touchCajon) {
            $markPaid = true;
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
            (new \SoftNova\Services\AccountingService($this->db))->postExpense($expenseId, $touchCajon, $markPaid);
        } catch (\Throwable $e) {
            error_log('Contabilidad gasto caja ' . $expenseId . ': ' . $e->getMessage());
            try {
                (new \SoftNova\Services\AccountingService($this->db))->enqueuePending(
                    'post_expense',
                    'expense',
                    $expenseId,
                    $e->getMessage(),
                    ['affect_cash' => $touchCajon, 'paid_now' => $markPaid]
                );
            } catch (\Throwable $ignore) {
            }
        }

        if ($touchCajon) {
            (new \SoftNova\Services\CashService($this->db))->registerMovement(
                $amount,
                'Gasto: ' . $description,
                'expense',
                'expense',
                $expenseId
            );
        }

        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'create',
            'caja',
            'Gasto desde caja: ' . $description . ' · ' . $this->formatMoney($amount),
            'expense',
            $expenseId
        );

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
        $amount = \SoftNova\Core\parse_money($this->request->post('amount'));
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
            "SELECT id, user_id FROM cash_sessions WHERE id = ? AND status = 'open'",
            [$sessionId]
        )->fetch();
        
        if (!$session) {
            $this->respond(false, 'La caja no esta abierta', '/app/caja');
            return;
        }

        if (!$this->canManageCashSession($session)) {
            $this->respond(false, $this->cashSessionOwnerMessage($session, 'registrar ingresos o retiros manuales'), '/app/caja');
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
            try {
                (new \SoftNova\Services\AccountingService($this->db))->enqueuePending(
                    'post_cash_movement',
                    'cash_movement',
                    $movementId,
                    $e->getMessage()
                );
            } catch (\Throwable $ignore) {
            }
        }
        
        $label = $type === 'income' ? 'Ingreso' : 'Egreso';
        (new \SoftNova\Services\TenantAuditService($this->db))->log(
            'movement',
            'caja',
            $label . ' manual: ' . $description . ' · ' . $this->formatMoney($amount),
            'cash_movement',
            $movementId
        );
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
            // Costo/utilidad solo visibles en Detalle (admin + icono ojo); el PDF no los incluye.
            $content = $pdf->generateCashClosing(
                $report['session'],
                $report['movements'],
                $report['totals'],
                $report['sales'],
                $report['admin_expenses'] ?? [],
                false
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

    /**
     * Detalle JSON del cierre (para modal en pantalla antes de PDF/correo).
     */
    private function reportDetailJson(): void
    {
        TenantMiddleware::authorize('caja');
        $id = (int)$this->request->get('id', 0);
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            $this->json(['success' => false, 'message' => 'Sesión de caja no encontrada']);
            return;
        }

        $session = $report['session'];
        $totals = $report['totals'];
        $recon = $totals['reconciliation']
            ?? \SoftNova\Services\CashClosingReportBuilder::buildReconciliation($totals, $session);

        $opening = (float)($session['opening_amount'] ?? 0);
        $cashSales = (float)($totals['cash_sales'] ?? 0);
        $otherCash = (float)($totals['other_cash_incomes'] ?? 0);
        $expenses = (float)($totals['expenses'] ?? 0);
        $purchases = round(min($expenses, max(0.0, (float)($totals['purchases_cash'] ?? 0))), 2);
        $refunds = round(min($otherCash, max(0.0, (float)($totals['purchase_refunds_cash'] ?? 0))), 2);
        $ncCashRefunds = round(min($expenses, max(0.0, (float)($totals['sale_credit_refunds_cash'] ?? 0))), 2);
        $ncDocTotal = round(max(0.0, (float)($totals['sale_credit_notes_total'] ?? 0)), 2);
        $ncDocCount = (int)($totals['sale_credit_notes_count'] ?? 0);
        $ncNumbers = trim((string)($totals['sale_credit_note_numbers'] ?? ''));
        $otherExpenses = round(max(0.0, $expenses - $purchases - $ncCashRefunds), 2);
        $expected = (float)($totals['expected_cash'] ?? $session['expected_amount'] ?? 0);
        $counted = (float)($totals['counted_cash'] ?? $session['closing_amount'] ?? 0);
        $diff = (float)($totals['difference'] ?? ($counted - $expected));
        $salesTotal = (float)($totals['sales_total'] ?? 0);
        $salesCount = (int)($totals['sales_count'] ?? 0);

        $payments = [];
        foreach (($totals['payment_breakdown'] ?? []) as $code => $row) {
            $payments[] = [
                'code' => (string)$code,
                'label' => (string)($row['label'] ?? $code),
                'total' => round((float)($row['total'] ?? 0), 2),
                'total_fmt' => $this->formatMoney((float)($row['total'] ?? 0)),
                'count' => (int)($row['count'] ?? 0),
                'is_cash' => ((string)$code === 'cash'),
            ];
        }

        $isAdmin = TenantMiddleware::isManagement();
        $salesPayload = [
            'count' => $salesCount,
            'subtotal' => (float)($totals['sales_subtotal_before_discount'] ?? 0),
            'subtotal_fmt' => $this->formatMoney((float)($totals['sales_subtotal_before_discount'] ?? 0)),
            'discount' => (float)($totals['sales_discount_total'] ?? 0),
            'discount_fmt' => $this->formatMoney((float)($totals['sales_discount_total'] ?? 0)),
            'tax' => (float)($totals['sales_tax_total'] ?? 0),
            'tax_fmt' => $this->formatMoney((float)($totals['sales_tax_total'] ?? 0)),
            'delivery' => (float)($totals['sales_delivery_total'] ?? 0),
            'delivery_fmt' => $this->formatMoney((float)($totals['sales_delivery_total'] ?? 0)),
            'total' => $salesTotal,
            'total_fmt' => $this->formatMoney($salesTotal),
            'collected' => (float)($totals['collected_total'] ?? 0),
            'collected_fmt' => $this->formatMoney((float)($totals['collected_total'] ?? 0)),
            'reconstructed_fmt' => $this->formatMoney((float)($recon['reconstructed_total'] ?? 0)),
            'ventas_totales_con_base' => (float)($totals['ventas_totales_con_base'] ?? ($salesTotal + $opening)),
            'ventas_totales_con_base_fmt' => $this->formatMoney(
                (float)($totals['ventas_totales_con_base'] ?? ($salesTotal + $opening))
            ),
            'pay_gap' => (float)(($totals['wiring']['pay_gap'] ?? ($salesTotal - (float)($totals['collected_total'] ?? 0)))),
            'pay_gap_fmt' => $this->formatMoney(
                (float)(($totals['wiring']['pay_gap'] ?? ($salesTotal - (float)($totals['collected_total'] ?? 0))))
            ),
            'cancelled' => (float)($totals['sales_cancelled_total'] ?? 0),
            'cancelled_fmt' => $this->formatMoney((float)($totals['sales_cancelled_total'] ?? 0)),
            'cancelled_count' => (int)($totals['sales_cancelled_count'] ?? 0),
            'nc_total' => $ncDocTotal,
            'nc_total_fmt' => $this->formatMoney($ncDocTotal),
            'nc_count' => $ncDocCount,
            'nc_numbers' => $ncNumbers,
        ];
        if ($isAdmin) {
            $salesPayload['cost'] = (float)($totals['sales_cost_total'] ?? 0);
            $salesPayload['cost_fmt'] = $this->formatMoney((float)($totals['sales_cost_total'] ?? 0));
            $salesPayload['gross_profit'] = (float)($totals['gross_profit'] ?? 0);
            $salesPayload['gross_profit_fmt'] = $this->formatMoney((float)($totals['gross_profit'] ?? 0));
            $salesPayload['gross_margin_pct'] = (float)($totals['gross_margin_pct'] ?? 0);
        }

        // Autor por movimiento + resumen por usuario (caja única compartida).
        $movPayload = [];
        $byUser = [];
        foreach (($report['movements'] ?? []) as $m) {
            $uName = trim((string)($m['user_name'] ?? 'Sin usuario'));
            if ($uName === '') {
                $uName = 'Sin usuario';
            }
            $isIn = (string)($m['type'] ?? '') === 'income';
            $amt = round((float)($m['amount'] ?? 0), 2);
            $movPayload[] = [
                'time' => !empty($m['created_at']) ? date('H:i', strtotime((string)$m['created_at'])) : '—',
                'user_name' => $uName,
                'type_label' => $isIn ? 'Ingreso' : 'Egreso',
                'description' => (string)(($m['origin_label'] ?? '') !== '' ? $m['origin_label'] : ($m['description'] ?? '')),
                'amount_fmt' => ($isIn ? '+' : '−') . $this->formatMoney($amt),
            ];
            if (!isset($byUser[$uName])) {
                $byUser[$uName] = ['user_name' => $uName, 'incomes' => 0.0, 'expenses' => 0.0, 'count' => 0];
            }
            $byUser[$uName][$isIn ? 'incomes' : 'expenses'] = round($byUser[$uName][$isIn ? 'incomes' : 'expenses'] + $amt, 2);
            $byUser[$uName]['count']++;
        }
        $byUserPayload = [];
        foreach ($byUser as $bu) {
            $byUserPayload[] = $bu + [
                'incomes_fmt' => $this->formatMoney($bu['incomes']),
                'expenses_fmt' => $this->formatMoney($bu['expenses']),
                'net_fmt' => $this->formatMoney(round($bu['incomes'] - $bu['expenses'], 2)),
            ];
        }

        $this->json([
            'success' => true,
            'session_id' => $id,
            'can_view_margin' => $isAdmin,
            'closing_notes' => \SoftNova\Services\CashClosingReportBuilder::closingObservation($session),
            'movements' => $movPayload,
            'by_user' => $byUserPayload,
            'user_name' => (string)($session['user_name'] ?? ''),
            'opening_date' => !empty($session['opening_date'])
                ? date('d/m/Y H:i', strtotime((string)$session['opening_date']))
                : '—',
            'closing_date' => !empty($session['closing_date'])
                ? date('d/m/Y H:i', strtotime((string)$session['closing_date']))
                : '—',
            'drawer' => [
                'opening' => $opening,
                'opening_fmt' => $this->formatMoney($opening),
                'cash_sales' => $cashSales,
                'cash_sales_fmt' => $this->formatMoney($cashSales),
                'other_cash' => $otherCash,
                'other_cash_fmt' => $this->formatMoney($otherCash),
                'expenses' => $expenses,
                'expenses_fmt' => $this->formatMoney($expenses),
                'purchases' => $purchases,
                'purchases_fmt' => $this->formatMoney($purchases),
                'nc_cash_refunds' => $ncCashRefunds,
                'nc_cash_refunds_fmt' => $this->formatMoney($ncCashRefunds),
                'other_expenses' => $otherExpenses,
                'other_expenses_fmt' => $this->formatMoney($otherExpenses),
                'refunds' => $refunds,
                'refunds_fmt' => $this->formatMoney($refunds),
                'other_cash_net_fmt' => $this->formatMoney($otherCash - $refunds),
                'expected' => $expected,
                'expected_fmt' => $this->formatMoney($expected),
                'counted' => $counted,
                'counted_fmt' => $this->formatMoney($counted),
                'difference' => $diff,
                'difference_fmt' => $this->formatMoney($diff),
                'net_day' => round($expected - $opening, 2),
                'net_day_fmt' => $this->formatMoney($expected - $opening),
            ],
            'sales' => $salesPayload,
            'payments' => $payments,
            'payments_sum_fmt' => $this->formatMoney((float)($recon['payments_sum'] ?? 0)),
            'reconciliation' => $recon,
            'note' => 'El contado del cajón (efectivo) no es el total de ventas: ese total incluye transferencia, Nequi, tarjetas, etc.',
            'pdf_url' => rtrim((string)config('app.url', ''), '/') . '/app/caja?action=pdf&id=' . $id,
        ]);
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
                $report['sales'],
                $report['admin_expenses'] ?? [],
                false
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
                . "--- Cajón ---\n"
                . "Base cajón: " . $this->formatMoney((float)($session['opening_amount'] ?? 0)) . "\n"
                . "Efectivo ventas del día: " . $this->formatMoney((float)($report['totals']['cash_sales'] ?? 0)) . "\n"
                . "Egresos cajón: " . $this->formatMoney((float)($report['totals']['expenses'] ?? 0))
                . $this->purchaseCashNote($report['totals']) . "\n"
                . "Efectivo esperado en cajón: " . $this->formatMoney((float)($report['totals']['expected_cash'] ?? 0)) . "\n"
                . "Efectivo contado: " . $this->formatMoney((float)($session['closing_amount'] ?? 0)) . "\n"
                . "--- Ventas ---\n"
                . "Sub-total sin descuentos: " . $this->formatMoney((float)($report['totals']['sales_subtotal_before_discount'] ?? 0)) . "\n"
                . "Descuento total aplicado: " . $this->formatMoney((float)($report['totals']['sales_discount_total'] ?? 0)) . "\n"
                . "IVA: " . $this->formatMoney((float)($report['totals']['sales_tax_total'] ?? 0)) . "\n"
                . "Domicilios: " . $this->formatMoney((float)($report['totals']['sales_delivery_total'] ?? 0)) . "\n"
                . "Ventas facturadas: " . (int)($report['totals']['sales_count'] ?? 0)
                . " · Total: " . $this->formatMoney((float)($report['totals']['sales_total'] ?? 0)) . "\n";
            $ncCountMail = (int)($report['totals']['sale_credit_notes_count'] ?? 0);
            $ncCashMail = (float)($report['totals']['sale_credit_refunds_cash'] ?? 0);
            $ncDocMail = (float)($report['totals']['sale_credit_notes_total'] ?? 0);
            if ($ncCountMail > 0 || $ncCashMail > 0.009 || $ncDocMail > 0.009) {
                $body .= "NC / devoluciones: {$ncCountMail}"
                    . " · Documental: " . $this->formatMoney($ncDocMail)
                    . " · Efectivo reembolsado: " . $this->formatMoney($ncCashMail) . "\n";
                $ncNumsMail = trim((string)($report['totals']['sale_credit_note_numbers'] ?? ''));
                if ($ncNumsMail !== '') {
                    $body .= "NC: {$ncNumsMail}\n";
                }
            }
            $closingObs = \SoftNova\Services\CashClosingReportBuilder::closingObservation($session);
            if ($closingObs !== '') {
                $body .= "Observación de cierre: " . $closingObs . "\n";
            }
            $body .= "Adjunto encontrará el PDF del cierre.\n";

            $bodyHtml = \SoftNova\Services\CashClosingReportBuilder::buildEmailHtml(
                $empresa,
                $session,
                $report['totals'],
                fn(float $n) => $this->formatMoney($n),
                $id
            );

            $resultado = (new \SoftNova\Services\MailService($company))->send(
                $to,
                'Cierre de caja #' . $id . ' - ' . $empresa,
                $body,
                $temporal,
                $filename,
                $bodyHtml
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

        // H5: Gerencia (y admin) ven PDF/detalle de cualquier cierre; cajero solo el propio.
        if (!TenantMiddleware::isManagement()) {
            $uid = (int)($_SESSION['tenant_user_id'] ?? 0);
            if ((int)($session['user_id'] ?? 0) !== $uid) {
                return null;
            }
        }

        $movements = $this->fetchSessionMovements([(int)$id], false);
        $movements = array_values(array_filter(
            $movements ?: [],
            static fn($m) => empty($m['_meta_only']) && (int)($m['id'] ?? 0) > 0
        ));

        $cashSvc = new \SoftNova\Services\CashService($this->db);
        $movNet = $cashSvc->totalsForSession($id);
        $totals = [
            'incomes' => $movNet['incomes'],
            'expenses' => $movNet['expenses'],
        ];

        try {
            $fullTotals = $this->computeSessionTotals($session, $session['closing_date'] ?? null);
            $totals = array_merge($totals, $fullTotals);
        } catch (\Throwable $e) {
            $totals['payment_breakdown'] = $totals['payment_breakdown'] ?? [];
        }

        // Preferir arqueo guardado al cerrar (evita recalcular con otra ventana horaria).
        if (isset($session['expected_amount']) && $session['expected_amount'] !== null) {
            $totals['expected_cash'] = round((float)$session['expected_amount'], 2);
        }
        if (isset($session['closing_amount']) && $session['closing_amount'] !== null) {
            $totals['counted_cash'] = round((float)$session['closing_amount'], 2);
        }
        if (isset($session['difference_amount']) && $session['difference_amount'] !== null) {
            $totals['difference'] = round((float)$session['difference_amount'], 2);
        }

        $sales = [];
        try {
            $sales = $this->salesForCashSessionReport($session);
            $sales = \SoftNova\Services\CashClosingReportBuilder::attachSaleAdjustments($this->db, $sales);
        } catch (\Throwable $e) {
            $sales = [];
        }

        $totals['reconciliation'] = \SoftNova\Services\CashClosingReportBuilder::buildReconciliation($totals, $session);

        return [
            'session' => $session,
            'movements' => $movements ?: [],
            'totals' => $totals ?: ['incomes' => 0, 'expenses' => 0],
            'sales' => $sales,
            'admin_expenses' => $this->loadAdministrativeExpensesForSession($session),
        ];
    }

    /**
     * Ventas del cierre: por rango sale_date de la sesión + ventas ligadas a movimientos
     * de esa caja (evita perder cobros nocturnos mal fechados por UTC).
     *
     * @return list<array<string,mixed>>
     */
    private function salesForCashSessionReport(array $session): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        $opening = (string)($session['opening_date'] ?? '');
        $closing = (string)($session['closing_date'] ?? '');
        if ($opening === '' && $sessionId <= 0) {
            return [];
        }

        $byId = [];

        $addRows = static function (array $rows) use (&$byId): void {
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $byId[$id] = $row;
            }
        };

        if ($opening !== '') {
            if ($closing !== '') {
                $addRows($this->query(
                    "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method, s.notes,
                            COALESCE(s.subtotal, 0) AS subtotal, COALESCE(s.discount, 0) AS discount,
                            COALESCE(s.tax, 0) AS tax, COALESCE(s.delivery_fee, 0) AS delivery_fee,
                            COALESCE(c.name, 'Publico general') as customer_name,
                            COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                     FROM sales s
                     LEFT JOIN customers c ON s.customer_id = c.id
                     LEFT JOIN users u ON u.id = s.user_id
                     WHERE s.sale_date >= ? AND s.sale_date <= ?
                       AND COALESCE(s.status, '') <> 'cancelled'
                     ORDER BY s.sale_date DESC, s.id DESC",
                    [$opening, $closing]
                )->fetchAll() ?: []);
            } else {
                $addRows($this->query(
                    "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method, s.notes,
                            COALESCE(s.subtotal, 0) AS subtotal, COALESCE(s.discount, 0) AS discount,
                            COALESCE(s.tax, 0) AS tax, COALESCE(s.delivery_fee, 0) AS delivery_fee,
                            COALESCE(c.name, 'Publico general') as customer_name,
                            COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                     FROM sales s
                     LEFT JOIN customers c ON s.customer_id = c.id
                     LEFT JOIN users u ON u.id = s.user_id
                     WHERE s.sale_date >= ?
                       AND COALESCE(s.status, '') <> 'cancelled'
                     ORDER BY s.sale_date DESC, s.id DESC",
                    [$opening]
                )->fetchAll() ?: []);
            }
        }

        if ($sessionId > 0) {
            // Cobros registrados en esta sesión (efectivo) aunque sale_date esté corrido.
            $addRows($this->query(
                "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method, s.notes,
                        COALESCE(s.subtotal, 0) AS subtotal, COALESCE(s.discount, 0) AS discount,
                        COALESCE(s.tax, 0) AS tax, COALESCE(s.delivery_fee, 0) AS delivery_fee,
                        COALESCE(c.name, 'Publico general') as customer_name,
                        COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                 FROM cash_movements cm
                 INNER JOIN sales s ON s.id = cm.reference_id
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE cm.cash_session_id = ?
                   AND cm.reference_type IN ('sale', 'sale_cancel', 'sale_payment_adjust')
                   AND COALESCE(s.status, '') <> 'cancelled'",
                [$sessionId]
            )->fetchAll() ?: []);

            $addRows($this->query(
                "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method, s.notes,
                        COALESCE(s.subtotal, 0) AS subtotal, COALESCE(s.discount, 0) AS discount,
                        COALESCE(s.tax, 0) AS tax, COALESCE(s.delivery_fee, 0) AS delivery_fee,
                        COALESCE(c.name, 'Publico general') as customer_name,
                        COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                 FROM cash_movements cm
                 INNER JOIN sale_payments sp ON sp.id = cm.reference_id
                 INNER JOIN sales s ON s.id = sp.sale_id
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE cm.cash_session_id = ?
                   AND cm.reference_type = 'sale_payment'
                   AND COALESCE(s.status, '') <> 'cancelled'",
                [$sessionId]
            )->fetchAll() ?: []);
        }

        $sales = array_values($byId);
        usort($sales, static function ($a, $b) {
            return strcmp((string)($b['sale_date'] ?? ''), (string)($a['sale_date'] ?? ''))
                ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        });

        return $this->attachSaleCreditNotesToSales($sales);
    }

    /**
     * Agrega totales/números de NC posted a cada venta del cierre.
     *
     * @param list<array<string,mixed>> $sales
     * @return list<array<string,mixed>>
     */
    private function attachSaleCreditNotesToSales(array $sales): array
    {
        if ($sales === []) {
            return $sales;
        }
        $ids = [];
        foreach ($sales as $sale) {
            $id = (int)($sale['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $idList = array_keys($ids);
        if ($idList === []) {
            return $sales;
        }

        $map = [];
        try {
            $ph = implode(',', array_fill(0, count($idList), '?'));
            $rows = $this->query(
                "SELECT sale_id,
                        COALESCE(SUM(total), 0) AS credit_note_total,
                        COUNT(*) AS credit_notes_count,
                        GROUP_CONCAT(credit_number ORDER BY id SEPARATOR ', ') AS credit_note_numbers
                 FROM sale_credit_notes
                 WHERE status = 'posted' AND sale_id IN ({$ph})
                 GROUP BY sale_id",
                $idList
            )->fetchAll() ?: [];
            foreach ($rows as $row) {
                $sid = (int)($row['sale_id'] ?? 0);
                if ($sid > 0) {
                    $map[$sid] = $row;
                }
            }
        } catch (\Throwable $e) {
            $map = [];
        }

        foreach ($sales as &$sale) {
            $sid = (int)($sale['id'] ?? 0);
            $info = $map[$sid] ?? null;
            $sale['credit_note_total'] = round((float)($info['credit_note_total'] ?? 0), 2);
            $sale['credit_notes_count'] = (int)($info['credit_notes_count'] ?? 0);
            $sale['credit_note_numbers'] = (string)($info['credit_note_numbers'] ?? '');
        }
        unset($sale);

        return $sales;
    }

    /**
     * Gastos administrativos del día de la sesión (informativos; no afectan el cajón).
     *
     * @return list<array<string,mixed>>
     */
    private function loadAdministrativeExpensesForSession(array $session): array
    {
        $from = !empty($session['opening_date'])
            ? date('Y-m-d', strtotime((string)$session['opening_date']))
            : date('Y-m-d');
        $to = !empty($session['closing_date'])
            ? date('Y-m-d', strtotime((string)$session['closing_date']))
            : $from;

        $adminCodes = \SoftNova\Services\ExpenseCategoryService::ADMINISTRATIVE_CODES;
        $codePlaceholders = implode(',', array_fill(0, count($adminCodes), '?'));

        try {
            return $this->query(
                "SELECT e.description, e.amount, e.expense_date, e.payment_method, e.notes,
                        COALESCE(ec.name, e.category, 'Administrativo') AS category_name,
                        COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                 FROM expenses e
                 LEFT JOIN expense_categories ec ON ec.id = e.category_id
                 LEFT JOIN users u ON u.id = e.user_id
                 WHERE e.expense_date BETWEEN ? AND ?
                   AND COALESCE(e.status, 'active') <> 'cancelled'
                   AND (
                     ec.kind = 'administrative'
                     OR ec.code IN ({$codePlaceholders})
                   )
                 ORDER BY e.expense_date ASC, e.id ASC",
                array_merge([$from, $to], $adminCodes)
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('loadAdministrativeExpensesForSession: ' . $e->getMessage());
            return [];
        }
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
            '',
            '*Arqueo de efectivo:*',
            'Base cajón (apertura): ' . $this->formatMoney((float)($session['opening_amount'] ?? 0)),
            'Efectivo ventas del día: ' . $this->formatMoney((float)($totals['cash_sales'] ?? 0)),
            'Egresos cajón: ' . $this->formatMoney((float)($totals['expenses'] ?? 0)) . $this->purchaseCashNote($totals),
            'Efectivo esperado en cajón: ' . $this->formatMoney((float)($totals['expected_cash'] ?? 0)),
            'Contado al cierre: ' . $this->formatMoney((float)($session['closing_amount'] ?? 0)),
            'Sub-total sin descuentos: ' . $this->formatMoney((float)($totals['sales_subtotal_before_discount'] ?? 0)),
            'Descuento total aplicado: ' . $this->formatMoney((float)($totals['sales_discount_total'] ?? 0)),
            'Ventas facturadas: ' . (int)($totals['sales_count'] ?? 0) . ' · ' . $this->formatMoney((float)($totals['sales_total'] ?? 0)),
        ];
        $ncCountWa = (int)($totals['sale_credit_notes_count'] ?? 0);
        $ncCashWa = (float)($totals['sale_credit_refunds_cash'] ?? 0);
        $ncDocWa = (float)($totals['sale_credit_notes_total'] ?? 0);
        if ($ncCountWa > 0 || $ncCashWa > 0.009 || $ncDocWa > 0.009) {
            $lines[] = 'NC / devoluciones: ' . $ncCountWa
                . ' · Documental ' . $this->formatMoney($ncDocWa)
                . ' · Efectivo −' . $this->formatMoney($ncCashWa);
            $ncNumsWa = trim((string)($totals['sale_credit_note_numbers'] ?? ''));
            if ($ncNumsWa !== '') {
                $lines[] = 'NC: ' . $ncNumsWa;
            }
        }
        $payBreak = $totals['payment_breakdown'] ?? [];
        if (is_array($payBreak) && $payBreak !== []) {
            uasort($payBreak, static function ($a, $b) {
                return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
            });
            $lines[] = '';
            $lines[] = '*Totales por medio de pago (sin la base):*';
            foreach ($payBreak as $code => $pb) {
                $label = (string)($pb['label'] ?? $code);
                $lines[] = '• ' . $label . ': ' . $this->formatMoney((float)($pb['total'] ?? 0))
                    . ' (' . (int)($pb['count'] ?? 0) . ')';
            }
        }
        if (isset($totals['difference']) && abs((float)$totals['difference']) >= 0.01) {
            $diff = (float)$totals['difference'];
            $lines[] = ($diff < 0 ? 'Faltante: ' : 'Sobrante: ') . $this->formatMoney(abs($diff));
        }
        $closingObs = \SoftNova\Services\CashClosingReportBuilder::closingObservation($session);
        if ($closingObs !== '') {
            $lines[] = '';
            $lines[] = '*Observación de cierre:* ' . $closingObs;
        }
        $lines[] = '';
        $lines[] = 'PDF: ' . rtrim((string)config('app.url', ''), '/') . '/app/caja?action=pdf&id=' . $id;
        return implode("\n", $lines);
    }

    /**
     * Totales de una sesion de caja (movimientos + ventas no duplicadas).
     * Ingresos/egresos incluyen todos los medios; expected_cash sigue siendo solo cajón.
     *
     * @return array{incomes:float,expenses:float,balance:float,other_incomes:float,sales_count:int,sales_total:float,movements_count:int}
     */
    private function computeSessionTotals(array $session, ?string $until = null): array
    {
        $sessionId = (int)$session['id'];
        $openingAmount = (float)($session['opening_amount'] ?? 0);
        $openingDate = (string)($session['opening_date'] ?? '');
        $until = $until ?: null;

        $cashSvc = new \SoftNova\Services\CashService($this->db);
        $movTotals = $cashSvc->totalsForSession($sessionId);

        // Clasificar movimientos: los de venta (incl. ajuste de medio) no deben
        // inflar "otros ingresos efectivo" ni "egresos" del resumen de cuadre.
        $saleLinkedIncome = 0.0;
        $saleLinkedExpense = 0.0;
        $paymentAdjustExpense = 0.0;
        $manualCashIncomes = 0.0;
        $saleCreditRefundsCash = 0.0;
        try {
            $byRef = $this->query(
                "SELECT type, COALESCE(reference_type, '') AS reference_type,
                        COALESCE(SUM(amount), 0) AS total
                 FROM cash_movements
                 WHERE cash_session_id = ?
                 GROUP BY type, COALESCE(reference_type, '')",
                [$sessionId]
            )->fetchAll() ?: [];
            $saleTypes = \SoftNova\Services\CashService::SALE_CASH_REF_TYPES;
            $revTypes = \SoftNova\Services\CashService::EXPENSE_REVERSAL_TYPES;
            foreach ($byRef as $br) {
                $t = (string)($br['type'] ?? '');
                $rt = (string)($br['reference_type'] ?? '');
                $amt = round((float)($br['total'] ?? 0), 2);
                if ($amt < 0.009) {
                    continue;
                }
                if ($rt === 'sale_credit_note' && $t === 'expense') {
                    $saleCreditRefundsCash = round($saleCreditRefundsCash + $amt, 2);
                }
                if (in_array($rt, $saleTypes, true)) {
                    if ($t === 'income') {
                        $saleLinkedIncome = round($saleLinkedIncome + $amt, 2);
                    } elseif ($t === 'expense') {
                        $saleLinkedExpense = round($saleLinkedExpense + $amt, 2);
                        if ($rt === 'sale_payment_adjust') {
                            $paymentAdjustExpense = round($paymentAdjustExpense + $amt, 2);
                        }
                    }
                    continue;
                }
                if ($t === 'income' && !in_array($rt, $revTypes, true)) {
                    $manualCashIncomes = round($manualCashIncomes + $amt, 2);
                }
            }
            $manualCashIncomes = round($manualCashIncomes + (float)($movTotals['unpaired_reversals'] ?? 0), 2);
        } catch (\Throwable $e) {
            $manualCashIncomes = max(0.0, (float)$movTotals['incomes'] - $saleLinkedIncome);
        }
        $purchasesCash = round((float)($movTotals['purchases'] ?? 0), 2);
        $purchaseRefundsCash = round((float)($movTotals['unpaired_purchase_reversals'] ?? 0), 2);

        // Egresos de cajón para KPI/cuadre: sin movimientos ligados a ventas
        // (ajustes de medio, cancelaciones). Esos ya se reflejan en el desglose
        // actual de medios y en expected_cash (neto de movimientos).
        $expenses = round(max(0.0, (float)$movTotals['expenses'] - $saleLinkedExpense), 2);
        $movementsCount = (int)$movTotals['movements_count'];

        $movements = $this->query(
            "SELECT reference_type, reference_id FROM cash_movements WHERE cash_session_id = ?",
            [$sessionId]
        )->fetchAll();
        $coveredSaleIds = [];
        $paymentRefIds = [];
        foreach ($movements as $mov) {
            $rt = (string)($mov['reference_type'] ?? '');
            $rid = (int)($mov['reference_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            if (in_array($rt, ['sale', 'sale_cancel', 'sale_payment_adjust'], true)) {
                // sale_payment_adjust guarda sale_id (no id de sale_payments).
                $coveredSaleIds[$rid] = true;
            } elseif ($rt === 'sale_payment') {
                $paymentRefIds[$rid] = true;
            }
        }
        if ($paymentRefIds !== []) {
            $payIdList = array_keys($paymentRefIds);
            $ph = implode(',', array_fill(0, count($payIdList), '?'));
            try {
                $linked = $this->query(
                    "SELECT sale_id FROM sale_payments WHERE id IN ({$ph})",
                    $payIdList
                )->fetchAll() ?: [];
                foreach ($linked as $lr) {
                    $sid = (int)($lr['sale_id'] ?? 0);
                    if ($sid > 0) {
                        $coveredSaleIds[$sid] = true;
                    }
                }
            } catch (\Throwable $e) {
                // esquema antiguo sin sale_payments
            }
        }

        // Ventas del periodo:
        // - "nacidas" en la ventana (sale_date): cuentan en facturado (sales_total)
        // - solo ligadas por cobro/ajuste de ESTA sesión: no inflan facturado;
        //   solo aportan el cobro de la sesión al desglose de medios.
        $salesById = [];
        $bornSaleIds = [];
        $mergeSales = static function (array $rows, bool $asBorn = false) use (&$salesById, &$bornSaleIds): void {
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $salesById[$id] = $row;
                if ($asBorn) {
                    $bornSaleIds[$id] = true;
                }
            }
        };

        if ($openingDate !== '') {
            try {
                if ($until) {
                    $mergeSales($this->query(
                        "SELECT id, total, status, payment_method, payment_status, sale_date,
                                COALESCE(subtotal, 0) AS subtotal, COALESCE(discount, 0) AS discount,
                                COALESCE(tax, 0) AS tax, COALESCE(delivery_fee, 0) AS delivery_fee
                         FROM sales WHERE sale_date >= ? AND sale_date <= ?",
                        [$openingDate, $until]
                    )->fetchAll() ?: [], true);
                } else {
                    $mergeSales($this->query(
                        "SELECT id, total, status, payment_method, payment_status, sale_date,
                                COALESCE(subtotal, 0) AS subtotal, COALESCE(discount, 0) AS discount,
                                COALESCE(tax, 0) AS tax, COALESCE(delivery_fee, 0) AS delivery_fee
                         FROM sales WHERE sale_date >= ?",
                        [$openingDate]
                    )->fetchAll() ?: [], true);
                }
            } catch (\Throwable $e) {
                if ($until) {
                    $mergeSales($this->query(
                        "SELECT id, total, status, payment_method, payment_status, sale_date
                         FROM sales WHERE sale_date >= ? AND sale_date <= ?",
                        [$openingDate, $until]
                    )->fetchAll() ?: [], true);
                } else {
                    $mergeSales($this->query(
                        "SELECT id, total, status, payment_method, payment_status, sale_date
                         FROM sales WHERE sale_date >= ?",
                        [$openingDate]
                    )->fetchAll() ?: [], true);
                }
            }
        }

        $sessionPaymentIds = $paymentRefIds; // id pago => true (movimientos sale_payment de esta sesión)
        if ($sessionId > 0) {
            try {
                $mergeSales($this->query(
                    "SELECT s.id, s.total, s.status, s.payment_method, s.payment_status, s.sale_date,
                            COALESCE(s.subtotal, 0) AS subtotal, COALESCE(s.discount, 0) AS discount,
                            COALESCE(s.tax, 0) AS tax, COALESCE(s.delivery_fee, 0) AS delivery_fee
                     FROM cash_movements cm
                     INNER JOIN sales s ON s.id = cm.reference_id
                     WHERE cm.cash_session_id = ?
                       AND cm.reference_type IN ('sale', 'sale_cancel', 'sale_payment_adjust')",
                    [$sessionId]
                )->fetchAll() ?: [], false);
                // Venta con movimiento 'sale' en esta sesión = nace aquí a efectos de facturado.
                $saleMovIds = $this->query(
                    "SELECT DISTINCT reference_id FROM cash_movements
                     WHERE cash_session_id = ? AND reference_type = 'sale' AND reference_id > 0",
                    [$sessionId]
                )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                foreach ($saleMovIds as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) {
                        $bornSaleIds[$sid] = true;
                    }
                }
                $mergeSales($this->query(
                    "SELECT s.id, s.total, s.status, s.payment_method, s.payment_status, s.sale_date,
                            COALESCE(s.subtotal, 0) AS subtotal, COALESCE(s.discount, 0) AS discount,
                            COALESCE(s.tax, 0) AS tax, COALESCE(s.delivery_fee, 0) AS delivery_fee
                     FROM cash_movements cm
                     INNER JOIN sale_payments sp ON sp.id = cm.reference_id
                     INNER JOIN sales s ON s.id = sp.sale_id
                     WHERE cm.cash_session_id = ?
                       AND cm.reference_type = 'sale_payment'",
                    [$sessionId]
                )->fetchAll() ?: [], false);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $sales = array_values($salesById);

        // Cobros del desglose:
        // - ventas nacidas: todos sus sale_payments (medios actuales de la factura del día)
        // - solo abono a factura vieja: únicamente los pagos con movimiento en ESTA sesión
        $payRows = [];
        $bornIdList = array_keys($bornSaleIds);
        if ($bornIdList !== []) {
            $ph = implode(',', array_fill(0, count($bornIdList), '?'));
            try {
                $payRows = $this->query(
                    "SELECT sp.id, sp.sale_id, sp.payment_method, sp.amount
                     FROM sale_payments sp
                     INNER JOIN sales s ON s.id = sp.sale_id
                     WHERE sp.sale_id IN ({$ph})
                       AND COALESCE(s.status, '') <> 'cancelled'",
                    $bornIdList
                )->fetchAll() ?: [];
            } catch (\Throwable $e) {
                $payRows = [];
            }
        }
        $sessionPayIdList = array_keys($sessionPaymentIds);
        if ($sessionPayIdList !== []) {
            $ph = implode(',', array_fill(0, count($sessionPayIdList), '?'));
            try {
                $extraPays = $this->query(
                    "SELECT sp.id, sp.sale_id, sp.payment_method, sp.amount
                     FROM sale_payments sp
                     INNER JOIN sales s ON s.id = sp.sale_id
                     WHERE sp.id IN ({$ph})
                       AND COALESCE(s.status, '') <> 'cancelled'",
                    $sessionPayIdList
                )->fetchAll() ?: [];
                $seenPay = [];
                foreach ($payRows as $pr) {
                    $seenPay[(int)($pr['id'] ?? 0)] = true;
                }
                foreach ($extraPays as $pr) {
                    $pid = (int)($pr['id'] ?? 0);
                    if ($pid > 0 && empty($seenPay[$pid])) {
                        $payRows[] = $pr;
                        $seenPay[$pid] = true;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Facturado = solo ventas nacidas en el periodo (no facturas viejas por abono).
        $orphanCashSales = 0.0;
        $salesCount = 0;
        $salesTotal = 0.0;
        $salesDiscountTotal = 0.0;
        $salesSubtotalBeforeDiscount = 0.0;
        $salesTaxTotal = 0.0;
        $salesDeliveryTotal = 0.0;
        $collectionsOnlyCount = 0;
        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $saleId = (int)$sale['id'];
            $isBorn = isset($bornSaleIds[$saleId]);
            if (!$isBorn) {
                $collectionsOnlyCount++;
                continue;
            }
            $salesCount++;
            $saleTotal = (float)$sale['total'];
            $salesTotal += $saleTotal;
            $disc = round((float)($sale['discount'] ?? 0), 2);
            $sub = round((float)($sale['subtotal'] ?? 0), 2);
            if ($sub <= 0.009) {
                $sub = round($saleTotal + max(0.0, $disc), 2);
            }
            $salesDiscountTotal += max(0.0, $disc);
            $salesSubtotalBeforeDiscount += max(0.0, $sub);
            $salesTaxTotal += max(0.0, round((float)($sale['tax'] ?? 0), 2));
            $salesDeliveryTotal += max(0.0, round((float)($sale['delivery_fee'] ?? 0), 2));
            if (isset($coveredSaleIds[$saleId])) {
                continue;
            }
            $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
            $payStatus = (string)($sale['payment_status'] ?? '');
            if (
                \SoftNova\Services\PaymentMethodCatalog::affectsCash($methodCode)
                && in_array($payStatus, ['paid', ''], true)
            ) {
                $orphanCashSales += $saleTotal;
            }
        }

        $paymentBreakdown = [];
        if ($payRows !== []) {
            foreach ($payRows as $pr) {
                $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($pr['payment_method'] ?? 'cash'));
                if (!isset($paymentBreakdown[$methodCode])) {
                    $paymentBreakdown[$methodCode] = [
                        'label' => \SoftNova\Services\PaymentMethodCatalog::label($methodCode, $this->db),
                        'total' => 0.0,
                        'count' => 0,
                    ];
                }
                $paymentBreakdown[$methodCode]['total'] += (float)$pr['amount'];
                $paymentBreakdown[$methodCode]['count']++;
            }
        } else {
            // Fallback: ventas nacidas sin filas de pago (instalaciones viejas).
            foreach ($sales as $sale) {
                if (($sale['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $saleId = (int)$sale['id'];
                if (!isset($bornSaleIds[$saleId])) {
                    continue;
                }
                $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
                if (!isset($paymentBreakdown[$methodCode])) {
                    $paymentBreakdown[$methodCode] = [
                        'label' => \SoftNova\Services\PaymentMethodCatalog::label($methodCode, $this->db),
                        'total' => 0.0,
                        'count' => 0,
                    ];
                }
                $paymentBreakdown[$methodCode]['total'] += (float)$sale['total'];
                $paymentBreakdown[$methodCode]['count']++;
            }
        }

        // Cobros en medios que NO entran al cajón (Nequi, transferencia, tarjetas, etc.).
        $otherIncomes = 0.0;
        $collectedTotal = 0.0;
        foreach ($paymentBreakdown as $code => $row) {
            $amt = (float)($row['total'] ?? 0);
            $collectedTotal = round($collectedTotal + $amt, 2);
            if (!\SoftNova\Services\PaymentMethodCatalog::affectsCash((string)$code)) {
                $otherIncomes += $amt;
            }
        }

        // Gastos fuera de cajón: informativos (no inflan KPI egresos).
        $expenseParams = [$openingDate];
        $expenseUntilSql = '';
        if ($until) {
            $expenseUntilSql = ' AND COALESCE(e.created_at, CONCAT(e.expense_date, \' 23:59:59\')) <= ?';
            $expenseParams[] = $until;
        }
        try {
            $orphanExpenses = (float)$this->query(
                "SELECT COALESCE(SUM(e.amount), 0)
                 FROM expenses e
                 WHERE COALESCE(e.created_at, CONCAT(e.expense_date, ' 00:00:00')) >= ?
                   AND COALESCE(e.status, 'active') <> 'cancelled'
                   AND NOT EXISTS (
                        SELECT 1 FROM cash_movements cm
                        WHERE cm.reference_type = 'expense' AND cm.reference_id = e.id
                   )" . $expenseUntilSql,
                $expenseParams
            )->fetchColumn();
        } catch (\Throwable $e) {
            $orphanExpenses = 0.0;
        }
        unset($orphanExpenses); // reservado / no suma al KPI

        // Efectivo de ventas (medios) + ingresos manuales de cajón.
        $cashSalesFromPayments = 0.0;
        foreach ($paymentBreakdown as $code => $row) {
            if (\SoftNova\Services\PaymentMethodCatalog::affectsCash((string)$code)) {
                $cashSalesFromPayments = round($cashSalesFromPayments + (float)($row['total'] ?? 0), 2);
            }
        }
        $cashSales = $cashSalesFromPayments;

        $otherCashIncomes = round(max(0.0, $manualCashIncomes), 2);
        $cashIncomes = round($cashSales + $otherCashIncomes, 2);
        // Ingresos del día = cobros (todos los medios) + ingresos manuales de caja.
        $incomes = round($collectedTotal + $otherCashIncomes, 2);

        // Redondear totales y orden preferido (efectivo / billeteras / tarjetas primero).
        $preferred = [
            'cash', 'nequi', 'daviplata', 'breb', 'transfer',
            'credit_card', 'debit_card', 'card', 'dataphone', 'payment_link',
        ];
        foreach ($paymentBreakdown as $code => &$row) {
            $row['total'] = round((float)$row['total'], 2);
        }
        unset($row);
        uksort($paymentBreakdown, static function (string $a, string $b) use ($preferred, $paymentBreakdown): int {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);
            $ia = $ia === false ? 1000 : $ia;
            $ib = $ib === false ? 1000 : $ib;
            if ($ia !== $ib) {
                return $ia <=> $ib;
            }
            return ((float)($paymentBreakdown[$b]['total'] ?? 0) <=> (float)($paymentBreakdown[$a]['total'] ?? 0));
        });

        $incomes = round($incomes, 2);
        $expenses = round($expenses, 2);
        $otherIncomes = round($otherIncomes, 2);
        $cashSales = round($cashSales, 2);
        // Una sola fuente de verdad para el cajón (misma que closeCash).
        $expectedCash = $cashSvc->expectedCashForSession($session, $until);
        $balance = $expectedCash;

        // Costo de mercancía / utilidad bruta (solo ventas facturadas del periodo).
        $salesCostTotal = 0.0;
        $activeSaleIds = $bornIdList;
        if ($activeSaleIds !== []) {
            $costPh = implode(',', array_fill(0, count($activeSaleIds), '?'));
            try {
                $hasUnitCost = (bool)$this->query("SHOW COLUMNS FROM sale_items LIKE 'unit_cost'")->fetch();
                $hasDocHidden = (bool)$this->query("SHOW COLUMNS FROM sale_items LIKE 'document_hidden'")->fetch();
                $costExpr = $hasUnitCost
                    ? 'COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)'
                    : 'COALESCE(p.purchase_price, 0)';
                $hideSql = $hasDocHidden ? ' AND COALESCE(si.document_hidden, 0) = 0' : '';
                $salesCostTotal = (float)$this->query(
                    "SELECT COALESCE(SUM(si.quantity * {$costExpr}), 0)
                     FROM sale_items si
                     LEFT JOIN products p ON p.id = si.product_id
                     INNER JOIN sales s ON s.id = si.sale_id
                     WHERE si.sale_id IN ({$costPh})
                       AND COALESCE(s.status, '') <> 'cancelled'{$hideSql}",
                    $activeSaleIds
                )->fetchColumn();
            } catch (\Throwable $e) {
                $salesCostTotal = 0.0;
            }
        }
        $salesCostTotal = round($salesCostTotal, 2);
        $grossProfit = round($salesTotal - $salesCostTotal, 2);
        $grossMarginPct = $salesTotal > 0.009
            ? round(($grossProfit / $salesTotal) * 100, 2)
            : 0.0;

        // Ventas anuladas (informativo; no entran al total facturado).
        $salesCancelledTotal = 0.0;
        $salesCancelledCount = 0;
        if ($openingDate !== '') {
            try {
                if ($until) {
                    $canc = $this->query(
                        "SELECT COALESCE(SUM(total), 0) AS t, COUNT(*) AS c
                         FROM sales
                         WHERE sale_date >= ? AND sale_date <= ?
                           AND COALESCE(status, '') = 'cancelled'",
                        [$openingDate, $until]
                    )->fetch() ?: [];
                } else {
                    $canc = $this->query(
                        "SELECT COALESCE(SUM(total), 0) AS t, COUNT(*) AS c
                         FROM sales
                         WHERE sale_date >= ?
                           AND COALESCE(status, '') = 'cancelled'",
                        [$openingDate]
                    )->fetch() ?: [];
                }
                $salesCancelledTotal = round((float)($canc['t'] ?? 0), 2);
                $salesCancelledCount = (int)($canc['c'] ?? 0);
            } catch (\Throwable $e) {
                $salesCancelledTotal = 0.0;
                $salesCancelledCount = 0;
            }
        }

        // NC de venta emitidas en la ventana de la sesión (documental; el efectivo ya está en sale_credit_refunds_cash).
        $saleCreditNotesTotal = 0.0;
        $saleCreditNotesCount = 0;
        $saleCreditNoteNumbers = '';
        if ($openingDate !== '') {
            try {
                $ncParams = [$openingDate];
                $ncUntilSql = '';
                if ($until) {
                    $ncUntilSql = ' AND COALESCE(cn.created_at, CONCAT(cn.credit_date, \' 23:59:59\')) <= ?';
                    $ncParams[] = $until;
                }
                $ncRow = $this->query(
                    "SELECT COALESCE(SUM(cn.total), 0) AS t, COUNT(*) AS c,
                            GROUP_CONCAT(cn.credit_number ORDER BY cn.id SEPARATOR ', ') AS nums
                     FROM sale_credit_notes cn
                     WHERE cn.status = 'posted'
                       AND COALESCE(cn.created_at, CONCAT(cn.credit_date, ' 00:00:00')) >= ?
                       {$ncUntilSql}",
                    $ncParams
                )->fetch() ?: [];
                $saleCreditNotesTotal = round((float)($ncRow['t'] ?? 0), 2);
                $saleCreditNotesCount = (int)($ncRow['c'] ?? 0);
                $saleCreditNoteNumbers = (string)($ncRow['nums'] ?? '');
                if (mb_strlen($saleCreditNoteNumbers) > 120) {
                    $saleCreditNoteNumbers = mb_substr($saleCreditNoteNumbers, 0, 117) . '…';
                }
            } catch (\Throwable $e) {
                $saleCreditNotesTotal = 0.0;
                $saleCreditNotesCount = 0;
                $saleCreditNoteNumbers = '';
            }
        }

        $payGap = round($salesTotal - $collectedTotal, 2);
        // Narrative drawer check (medios + manual − egresos KPI) vs esperado real.
        $narrativeDrawer = round($openingAmount + $cashSales + $otherCashIncomes - $expenses, 2);
        $drawerGap = round($expectedCash - $narrativeDrawer, 2);

        return [
            'incomes' => $incomes,
            'expenses' => $expenses,
            // Parte de "expenses" que son compras a proveedor con efectivo del cajón (OC / abonos).
            'purchases_cash' => min($expenses, $purchasesCash),
            // Reembolsos NC venta en efectivo de esta sesión (parte de expenses).
            'sale_credit_refunds_cash' => min($expenses, $saleCreditRefundsCash),
            'sale_credit_notes_total' => $saleCreditNotesTotal,
            'sale_credit_notes_count' => $saleCreditNotesCount,
            'sale_credit_note_numbers' => $saleCreditNoteNumbers,
            'other_incomes' => $otherIncomes,
            'cash_incomes' => round($cashIncomes, 2),
            // Efectivo cobrado en ventas (desglose de medios affects_cash).
            'cash_sales' => $cashSales,
            // Ingresos en efectivo que no son venta (ajustes manuales, reintegros, etc.).
            'other_cash_incomes' => $otherCashIncomes,
            // Parte de "other_cash_incomes": compras anuladas cuyo egreso salió en otra caja.
            'purchase_refunds_cash' => min($otherCashIncomes, $purchaseRefundsCash),
            'opening_amount' => round($openingAmount, 2),
            // Neto que el día aportó al cajón (esperado − base).
            'cash_day_net' => round($expectedCash - $openingAmount, 2),
            // balance === expected_cash (misma fuente canónica del arqueo).
            'balance' => $balance,
            'sales_count' => $salesCount,
            'sales_total' => round($salesTotal, 2),
            // Cobrado en el periodo (Σ medios del desglose acotado a la sesión).
            'collected_total' => round($collectedTotal, 2),
            'collections_only_count' => $collectionsOnlyCount,
            // Ventas facturadas + fondo de apertura (resumen ejecutivo; NO es solo facturado).
            'ventas_totales_con_base' => round($salesTotal + $openingAmount, 2),
            // Bruto de mercancía antes del descuento de documento (todos los medios).
            'sales_subtotal_before_discount' => round($salesSubtotalBeforeDiscount, 2),
            // Descuento de documento acumulado en ventas del periodo (todos los medios).
            'sales_discount_total' => round($salesDiscountTotal, 2),
            'sales_tax_total' => round($salesTaxTotal, 2),
            'sales_delivery_total' => round($salesDeliveryTotal, 2),
            'sales_cost_total' => $salesCostTotal,
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $grossMarginPct,
            'sales_cancelled_total' => $salesCancelledTotal,
            'sales_cancelled_count' => $salesCancelledCount,
            'movements_count' => $movementsCount,
            'payment_breakdown' => $paymentBreakdown,
            // Solo efectivo: es lo que debe cuadrar contra el conteo del cajón.
            'expected_cash' => $expectedCash,
            'counted_cash' => isset($session['closing_amount']) && $session['closing_amount'] !== null
                ? round((float)$session['closing_amount'], 2)
                : null,
            'difference' => isset($session['difference_amount']) && $session['difference_amount'] !== null
                ? round((float)$session['difference_amount'], 2)
                : null,
            'wiring' => [
                'pay_gap' => $payGap,
                'drawer_gap' => $drawerGap,
                'narrative_drawer' => $narrativeDrawer,
                'orphan_cash_hint' => round($orphanCashSales, 2),
                'sale_linked_income' => round($saleLinkedIncome, 2),
                'sale_linked_expense' => round($saleLinkedExpense, 2),
            ],
        ];
    }

    /**
     * Totales unificados de todas las cajas abiertas (vista administrador).
     * Una sola cuenta operativa: movimientos de todas las sesiones + ventas desde la apertura más antigua (sin duplicar).
     */
    private function computeUnifiedOpenTotals(array $sessions): array
    {
        if ($sessions === []) {
            return [
                'incomes' => 0, 'expenses' => 0, 'balance' => 0, 'other_incomes' => 0, 'cash_incomes' => 0,
                'cash_sales' => 0, 'other_cash_incomes' => 0, 'opening_amount' => 0, 'cash_day_net' => 0,
                'sales_count' => 0, 'sales_total' => 0, 'movements_count' => 0,
                'sales_subtotal_before_discount' => 0, 'sales_discount_total' => 0,
                'sales_tax_total' => 0, 'sales_delivery_total' => 0,
                'payment_breakdown' => [], 'expected_cash' => 0,
                'counted_cash' => null, 'difference' => null,
            ];
        }
        // Usar la apertura más antigua como ancla de ventas (evita doble conteo entre sesiones).
        usort($sessions, static fn($a, $b) => strcmp((string)$a['opening_date'], (string)$b['opening_date']));
        $anchor = $sessions[0];
        $opening = array_sum(array_map(
            static fn($s) => (float)($s['opening_amount'] ?? 0),
            $sessions
        ));
        $anchor['opening_amount'] = $opening;

        // Totales de ventas/medios desde ancla; luego alinear ingresos/egresos de cajón
        // con la suma de sesiones sin reintroducir ajustes de medio como "otros ingresos".
        $base = $this->computeSessionTotals($anchor);
        $ids = array_map(static fn($s) => (int)$s['id'], $sessions);
        $cashSvc = new \SoftNova\Services\CashService($this->db);
        $mov = $cashSvc->totalsForSessions($ids);

        $cashSales = round((float)($base['cash_sales'] ?? 0), 2);
        $otherIncomes = round((float)($base['other_incomes'] ?? 0), 2);
        $otherCashIncomes = round((float)($base['other_cash_incomes'] ?? 0), 2);
        // Egresos KPI ya excluyen ajustes de medio en computeSessionTotals; para multi-sesión
        // recalcular excluyendo sale-linked de todas las cajas abiertas.
        $saleLinkedExpenseAll = 0.0;
        $saleLinkedIncomeAll = null;
        try {
            if ($ids !== []) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $saleTypes = \SoftNova\Services\CashService::SALE_CASH_REF_TYPES;
                $typePh = implode(',', array_fill(0, count($saleTypes), '?'));
                $saleLinked = $this->query(
                    "SELECT
                        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense,
                        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income
                     FROM cash_movements
                     WHERE cash_session_id IN ({$ph})
                       AND reference_type IN ({$typePh})",
                    array_merge($ids, $saleTypes)
                )->fetch() ?: [];
                $saleLinkedExpenseAll = (float)($saleLinked['expense'] ?? 0);
                $saleLinkedIncomeAll = (float)($saleLinked['income'] ?? 0);
            }
        } catch (\Throwable $e) {
            $saleLinkedExpenseAll = 0.0;
            $saleLinkedIncomeAll = null;
        }
        $expenses = round(max(0.0, (float)$mov['expenses'] - $saleLinkedExpenseAll), 2);
        // Ingresos de cajón no ligados a venta de TODAS las cajas abiertas (no solo la ancla).
        if ($saleLinkedIncomeAll !== null) {
            $otherCashIncomes = round(max(0.0, (float)$mov['incomes'] - $saleLinkedIncomeAll), 2);
        }
        $purchasesCash = min($expenses, round((float)($mov['purchases'] ?? 0), 2));
        $purchaseRefundsCash = min($otherCashIncomes, round((float)($mov['unpaired_purchase_reversals'] ?? 0), 2));
        $saleCreditRefundsCash = 0.0;
        try {
            if ($ids !== []) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $saleCreditRefundsCash = round((float)$this->query(
                    "SELECT COALESCE(SUM(amount), 0)
                     FROM cash_movements
                     WHERE cash_session_id IN ({$ph})
                       AND type = 'expense'
                       AND reference_type = 'sale_credit_note'",
                    $ids
                )->fetchColumn(), 2);
            }
        } catch (\Throwable $e) {
            $saleCreditRefundsCash = (float)($base['sale_credit_refunds_cash'] ?? 0);
        }
        $cashIncomes = round($cashSales + $otherCashIncomes, 2);
        $incomes = round($cashSales + $otherIncomes + $otherCashIncomes, 2);

        $expectedCash = $cashSvc->expectedCashForSessions($sessions);

        return [
            'incomes' => round($incomes, 2),
            'expenses' => round($expenses, 2),
            'purchases_cash' => $purchasesCash,
            'sale_credit_refunds_cash' => min($expenses, $saleCreditRefundsCash),
            'sale_credit_notes_total' => round((float)($base['sale_credit_notes_total'] ?? 0), 2),
            'sale_credit_notes_count' => (int)($base['sale_credit_notes_count'] ?? 0),
            'sale_credit_note_numbers' => (string)($base['sale_credit_note_numbers'] ?? ''),
            'other_incomes' => round($otherIncomes, 2),
            'cash_incomes' => round($cashIncomes, 2),
            'cash_sales' => $cashSales,
            'other_cash_incomes' => $otherCashIncomes,
            'purchase_refunds_cash' => $purchaseRefundsCash,
            'opening_amount' => round($opening, 2),
            'cash_day_net' => round($expectedCash - $opening, 2),
            'balance' => round($expectedCash, 2),
            'sales_count' => (int)($base['sales_count'] ?? 0),
            'sales_total' => round((float)($base['sales_total'] ?? 0), 2),
            'collected_total' => round((float)($base['collected_total'] ?? 0), 2),
            'sales_subtotal_before_discount' => round((float)($base['sales_subtotal_before_discount'] ?? 0), 2),
            'sales_discount_total' => round((float)($base['sales_discount_total'] ?? 0), 2),
            'sales_tax_total' => round((float)($base['sales_tax_total'] ?? 0), 2),
            'sales_delivery_total' => round((float)($base['sales_delivery_total'] ?? 0), 2),
            'sales_cancelled_total' => round((float)($base['sales_cancelled_total'] ?? 0), 2),
            'sales_cancelled_count' => (int)($base['sales_cancelled_count'] ?? 0),
            'movements_count' => (int)$mov['movements_count'],
            'payment_breakdown' => $base['payment_breakdown'] ?? [],
            'expected_cash' => round($expectedCash, 2),
            'counted_cash' => null,
            'difference' => null,
            'wiring' => $base['wiring'] ?? [],
        ];
    }

    /**
     * Cierra automaticamente cajas abiertas cuando ya paso la medianoche
     * del dia en que se abrieron (zona horaria de negocio).
     *
     * No crea sesiones nuevas: solo cierra las que quedaron en status=open.
     * El usuario que se ve en historial es quien ABRIÓ la sesión, no quien cerró.
     */
    private function autoCloseOvernightSessions(): int
    {
        $closed = 0;
        try {
            $tzName = \SoftNova\Core\BusinessTimezone::resolve($this->db);
            \SoftNova\Core\BusinessTimezone::applyPhp($tzName);
            \SoftNova\Core\BusinessTimezone::applyToPdo($this->db, $tzName);
            $tz = new \DateTimeZone($tzName);
            $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

            $openSessions = $this->query(
                "SELECT * FROM cash_sessions WHERE status = 'open'"
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0;
        }

        foreach ($openSessions as $session) {
            $openingRaw = trim((string)($session['opening_date'] ?? ''));
            if ($openingRaw === '') {
                continue;
            }
            try {
                $openAt = new \DateTimeImmutable($openingRaw, $tz);
            } catch (\Throwable $e) {
                $openAt = new \DateTimeImmutable(date('Y-m-d H:i:s', strtotime($openingRaw)), $tz);
            }
            $openDay = $openAt->format('Y-m-d');
            if ($today <= $openDay) {
                continue;
            }

            $closingAt = $openDay . ' 23:59:59';
            try {
                // Cierre ciego: se asume el efectivo esperado, sin faltante ni sobrante.
                $expectedCash = $this->computeExpectedCash($session, $closingAt);
                $stmt = $this->query(
                    "UPDATE cash_sessions
                     SET closing_amount = ?, closing_date = ?, status = 'closed',
                         expected_amount = ?, difference_amount = 0,
                         notes = CONCAT(IFNULL(notes, ''), ?)
                     WHERE id = ? AND status = 'open'",
                    [
                        $expectedCash,
                        $closingAt,
                        $expectedCash,
                        "\n[[AUTO_CLOSE]] Cierre automatico a medianoche del "
                            . $openAt->format('d/m/Y')
                            . ' (sesion quedo abierta; no es un segundo cierre manual).',
                        (int)$session['id'],
                    ]
                );
                if ($stmt && $stmt->rowCount() > 0) {
                    $closed++;
                }
            } catch (\Throwable $e) {
                error_log('autoCloseOvernightSessions #' . ($session['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        return $closed;
    }

    /**
     * Caja compartida con responsable: todos venden en la caja abierta, pero solo
     * quien la abrió, Administrador o Gerencia pueden cerrarla o moverla a mano.
     *
     * @param array<string,mixed> $session
     */
    private function canManageCashSession(array $session): bool
    {
        if (TenantMiddleware::isManagement()) {
            return true;
        }
        $uid = (int)($_SESSION['tenant_user_id'] ?? 0);
        return $uid > 0 && (int)($session['user_id'] ?? 0) === $uid;
    }

    /**
     * @param array<string,mixed> $session
     */
    private function cashSessionOwnerMessage(array $session, string $what): string
    {
        $owner = '';
        try {
            $row = $this->query(
                "SELECT name FROM users WHERE id = ? LIMIT 1",
                [(int)($session['user_id'] ?? 0)]
            )->fetch();
            $owner = trim((string)($row['name'] ?? ''));
        } catch (\Throwable $e) {
        }
        return 'Esta caja la abrió ' . ($owner !== '' ? $owner : 'otro usuario')
            . '. Solo esa persona, el administrador o Gerencia pueden ' . $what . '.';
    }

    /**
     * Contexto de sesiones abiertas (vista admin unificada o sesión del usuario).
     *
     * @return array{
     *   is_admin_viewer:bool,
     *   open_sessions:list<array<string,mixed>>,
     *   open_session:?array<string,mixed>,
     *   session_ids:list<int>,
     *   open_sessions_count:int
     * }
     */
    private function getOpenSessionsContext(): array
    {
        $isAdminViewer = TenantMiddleware::isManagement();
        $openSessions = $this->query(
            "SELECT cs.*, u.name as user_name
             FROM cash_sessions cs
             JOIN users u ON cs.user_id = u.id
             WHERE cs.status = 'open'
             ORDER BY cs.opening_date ASC"
        )->fetchAll() ?: [];

        $openSession = $openSessions[0] ?? null;
        if ($isAdminViewer && count($openSessions) > 1) {
            $openSession = $openSessions[0];
            $openSession['user_name'] = 'Caja unificada (' . count($openSessions) . ' abiertas)';
            $openSession['opening_amount'] = array_sum(array_map(
                static fn($s) => (float)($s['opening_amount'] ?? 0),
                $openSessions
            ));
            $openSession['opening_date'] = $openSessions[0]['opening_date'];
            $openSession['_unified_ids'] = array_map(static fn($s) => (int)$s['id'], $openSessions);
        } elseif ($openSession) {
            $openSession = $openSessions[count($openSessions) - 1];
        }

        $sessionIds = !empty($openSession['_unified_ids'])
            ? array_map('intval', (array)$openSession['_unified_ids'])
            : ($openSession ? [(int)$openSession['id']] : []);

        return [
            'is_admin_viewer' => $isAdminViewer,
            'open_sessions' => $openSessions,
            'open_session' => $openSession,
            'session_ids' => $sessionIds,
            'open_sessions_count' => count($openSessions),
        ];
    }

    /**
     * @param list<int> $sessionIds
     * @return list<array<string,mixed>>
     */
    private function fetchSessionMovements(array $sessionIds, bool $newestFirst = true): array
    {
        $sessionIds = array_values(array_filter(array_map('intval', $sessionIds), static fn($id) => $id > 0));
        if ($sessionIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($sessionIds), '?'));
        $order = $newestFirst ? 'DESC' : 'ASC';

        try {
            $movements = $this->query(
                "SELECT cm.*, COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                 FROM cash_movements cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 WHERE cm.cash_session_id IN ({$in})
                 ORDER BY cm.created_at {$order}, cm.id {$order}",
                $sessionIds
            )->fetchAll();
        } catch (\Throwable $e) {
            $movements = $this->query(
                "SELECT * FROM cash_movements
                 WHERE cash_session_id IN ({$in})
                 ORDER BY created_at {$order}, id {$order}",
                $sessionIds
            )->fetchAll();
        }

        return $this->filterOffsetCashMovements($this->enrichMovementOrigins($movements ?: []));
    }

    /** Sufijo de texto plano con compras OC, reintegros y reembolsos NC incluidos en los totales del cajón. */
    private function purchaseCashNote(array $totals): string
    {
        $parts = [];
        $purchases = (float)($totals['purchases_cash'] ?? 0);
        $refunds = (float)($totals['purchase_refunds_cash'] ?? 0);
        $ncCash = (float)($totals['sale_credit_refunds_cash'] ?? 0);
        $ncCount = (int)($totals['sale_credit_notes_count'] ?? 0);
        if ($purchases > 0.009) {
            $parts[] = 'incluye compras a proveedores ' . $this->formatMoney($purchases);
        }
        if ($refunds > 0.009) {
            $parts[] = 'reintegro compras anuladas +' . $this->formatMoney($refunds);
        }
        if ($ncCash > 0.009 || $ncCount > 0) {
            $parts[] = 'reembolsos NC venta −' . $this->formatMoney($ncCash)
                . ($ncCount > 0 ? ' (' . $ncCount . ')' : '');
        }
        return $parts === [] ? '' : ' (' . implode('; ', $parts) . ')';
    }

    /**
     * Compras a proveedor pagadas con efectivo del cajón (OC de contado y abonos) en las cajas dadas,
     * con el mismo formato que las filas de gastos. Excluye las anuladas dentro de esas mismas cajas.
     *
     * @param list<int> $sessionIds
     * @return list<array<string,mixed>>
     */
    private function purchaseCashOutflows(array $sessionIds, int $onlyUserId = 0): array
    {
        $sessionIds = array_values(array_filter(array_map('intval', $sessionIds), static fn($id) => $id > 0));
        if ($sessionIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($sessionIds), '?'));
        try {
            $rows = $this->query(
                "SELECT cm.id, cm.type, cm.amount, cm.description, cm.reference_type, cm.reference_id,
                        cm.user_id, cm.created_at,
                        COALESCE(NULLIF(TRIM(u.name), ''), 'Sin usuario') AS user_name
                 FROM cash_movements cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 WHERE cm.cash_session_id IN ({$in})
                   AND cm.reference_type IN ('purchase_order', 'supplier_payment', 'purchase_credit_note',
                                             'purchase_order_cancel', 'supplier_payment_cancel', 'supplier_payment_cashfix')",
                $sessionIds
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($this->filterOffsetCashMovements($rows) as $m) {
            if ((int)($m['id'] ?? 0) <= 0 || (string)($m['type'] ?? '') !== 'expense') {
                continue;
            }
            if ($onlyUserId > 0 && (int)($m['user_id'] ?? 0) !== $onlyUserId) {
                continue;
            }
            $isOrder = (string)($m['reference_type'] ?? '') === 'purchase_order';
            $out[] = [
                'id' => 'cm' . (int)$m['id'],
                'description' => (string)($m['description'] ?? ''),
                'amount' => (float)($m['amount'] ?? 0),
                'expense_date' => substr((string)($m['created_at'] ?? ''), 0, 10),
                'payment_method' => 'cash',
                'receipt_number' => null,
                'user_id' => (int)($m['user_id'] ?? 0),
                'created_at' => (string)($m['created_at'] ?? ''),
                'user_name' => (string)($m['user_name'] ?? 'Sin usuario'),
                'category_label' => $isOrder ? 'Compra OC (contado)' : 'Abono a proveedor',
                'supplier_name' => null,
            ];
        }
        return $out;
    }

    /**
     * Oculta pares egreso+reverso ya anulados (compra OC anulada, gasto eliminado, etc.)
     * para que el listado de caja no muestre egresos “fantasmas”.
     *
     * @param list<array<string,mixed>> $movements
     * @return list<array<string,mixed>>
     */
    private function filterOffsetCashMovements(array $movements): array
    {
        if ($movements === []) {
            return [];
        }

        // Solo se ocultan pares completos dentro del mismo listado (mismas cajas que los totales).
        // Si el egreso salió en otra caja, el reverso se muestra: es dinero que vuelve a este cajón.
        // Si la anulación es parcial (NC por una parte), se muestran ambos.
        $reverseOf = [
            'expense_cancel' => 'expense',
            'supplier_payment_cancel' => 'supplier_payment',
            'supplier_payment_cashfix' => 'supplier_payment',
            'purchase_order_cancel' => 'purchase_order',
        ];

        $ncIds = [];
        foreach ($movements as $m) {
            if ((string)($m['type'] ?? '') === 'income'
                && (string)($m['reference_type'] ?? '') === 'purchase_credit_note'
                && (int)($m['reference_id'] ?? 0) > 0
            ) {
                $ncIds[(int)$m['reference_id']] = true;
            }
        }
        $ncOrder = [];
        if ($ncIds !== []) {
            $ph = implode(',', array_fill(0, count($ncIds), '?'));
            try {
                $rows = $this->query(
                    "SELECT id, purchase_order_id FROM purchase_credit_notes WHERE id IN ({$ph})",
                    array_keys($ncIds)
                )->fetchAll() ?: [];
                foreach ($rows as $row) {
                    if ((int)($row['purchase_order_id'] ?? 0) > 0) {
                        $ncOrder[(int)$row['id']] = (int)$row['purchase_order_id'];
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $groups = [];
        foreach ($movements as $m) {
            $mid = (int)($m['id'] ?? 0);
            $rt = (string)($m['reference_type'] ?? '');
            $rid = (int)($m['reference_id'] ?? 0);
            if ($mid <= 0 || $rid <= 0) {
                continue;
            }
            $type = (string)($m['type'] ?? '');
            $key = null;
            $side = null;
            if ($type === 'expense' && in_array($rt, $reverseOf, true)) {
                $key = $rt . ':' . $rid;
                $side = 'out';
            } elseif ($type === 'income' && isset($reverseOf[$rt])) {
                $key = $reverseOf[$rt] . ':' . $rid;
                $side = 'in';
            } elseif ($type === 'income' && $rt === 'purchase_credit_note' && isset($ncOrder[$rid])) {
                $key = 'purchase_order:' . $ncOrder[$rid];
                $side = 'in';
            }
            if ($key === null) {
                continue;
            }
            $groups[$key][$side]['ids'][] = $mid;
            $groups[$key][$side]['sum'] = ($groups[$key][$side]['sum'] ?? 0.0) + (float)($m['amount'] ?? 0);
        }

        $hideIds = [];
        $uncoveredOrders = [];
        foreach ($groups as $key => $g) {
            if (!empty($g['out']) && str_starts_with((string)$key, 'purchase_order:')
                && (empty($g['in']) || (float)$g['in']['sum'] + 0.01 < (float)$g['out']['sum'])
            ) {
                $uncoveredOrders[(int)substr((string)$key, 15)] = $g['out']['ids'];
            }
            if (empty($g['out']) || empty($g['in'])) {
                continue;
            }
            if ((float)$g['in']['sum'] + 0.01 < (float)$g['out']['sum']) {
                continue;
            }
            foreach (array_merge($g['out']['ids'], $g['in']['ids']) as $id) {
                $hideIds[$id] = true;
            }
        }

        // OC anulada cuyo reintegro no quedó en estas cajas: el egreso sí descontó este cajón.
        $voidedNoteIds = [];
        if ($uncoveredOrders !== []) {
            $ph = implode(',', array_fill(0, count($uncoveredOrders), '?'));
            try {
                $rows = $this->query(
                    "SELECT id FROM purchase_orders WHERE id IN ({$ph}) AND status = 'cancelled'",
                    array_keys($uncoveredOrders)
                )->fetchAll() ?: [];
                foreach ($rows as $row) {
                    foreach ($uncoveredOrders[(int)$row['id']] ?? [] as $id) {
                        $voidedNoteIds[$id] = true;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if ($voidedNoteIds !== []) {
            foreach ($movements as &$m) {
                if (isset($voidedNoteIds[(int)($m['id'] ?? 0)])) {
                    $m['description'] = (string)($m['description'] ?? '')
                        . ' (OC anulada después; el reintegro no quedó en esta caja)';
                }
            }
            unset($m);
        }

        if ($hideIds === []) {
            return $movements;
        }

        $kept = [];
        $hidden = 0;
        foreach ($movements as $m) {
            $mid = (int)($m['id'] ?? 0);
            if ($mid > 0 && isset($hideIds[$mid])) {
                $hidden++;
                continue;
            }
            $kept[] = $m;
        }

        if ($hidden > 0 && $kept !== []) {
            $kept[0]['_hidden_voided_count'] = $hidden;
        } elseif ($hidden > 0) {
            // Solo metadato: no inventar filas falsas en el kardex de caja.
            $kept[] = [
                'id' => 0,
                '_meta_only' => true,
                '_hidden_voided_count' => $hidden,
            ];
        }

        return $kept;
    }

    /**
     * Enriquece movimientos de caja con etiqueta y enlace al documento origen.
     *
     * @param list<array<string,mixed>> $movements
     * @return list<array<string,mixed>>
     */
    private function enrichMovementOrigins(array $movements): array
    {
        foreach ($movements as &$mov) {
            $mov['origin_label'] = null;
            $mov['origin_url'] = null;
            $rt = (string)($mov['reference_type'] ?? '');
            $rid = (int)($mov['reference_id'] ?? 0);
            if ($rid <= 0 || $rt === '' || $rt === 'manual') {
                continue;
            }

            if ($rt === 'sale' || $rt === 'sale_cancel' || $rt === 'sale_payment_adjust') {
                $inv = $this->query(
                    "SELECT invoice_number FROM sales WHERE id = ? LIMIT 1",
                    [$rid]
                )->fetchColumn();
                if ($rt === 'sale_payment_adjust') {
                    $mov['origin_label'] = 'Ajuste medio · ' . ($inv ?: ('#' . $rid));
                } elseif ($rt === 'sale_cancel') {
                    $mov['origin_label'] = 'Cancelación · ' . ($inv ?: ('#' . $rid));
                } else {
                    $mov['origin_label'] = 'Venta ' . ($inv ?: '#' . $rid);
                }
                $mov['origin_url'] = base_url('/app/ventas');
                continue;
            }

            if ($rt === 'sale_payment') {
                $row = $this->query(
                    "SELECT s.invoice_number FROM sale_payments p
                     JOIN sales s ON s.id = p.sale_id WHERE p.id = ? LIMIT 1",
                    [$rid]
                )->fetch();
                if (!$row) {
                    $inv = $this->query(
                        "SELECT invoice_number FROM sales WHERE id = ? LIMIT 1",
                        [$rid]
                    )->fetchColumn();
                    if ($inv) {
                        $mov['origin_label'] = 'Venta ' . $inv . ' (abono legacy)';
                        $mov['origin_url'] = base_url('/app/ventas');
                    }
                    continue;
                }
                $mov['origin_label'] = 'Abono · ' . $row['invoice_number'];
                $mov['origin_url'] = base_url('/app/ventas');
                continue;
            }

            if ($rt === 'expense') {
                $mov['origin_label'] = 'Gasto #' . $rid;
                $mov['origin_url'] = base_url('/app/gastos');
                continue;
            }

            if ($rt === 'expense_cancel') {
                $mov['origin_label'] = 'Reverso gasto #' . $rid;
                $mov['origin_url'] = base_url('/app/gastos');
                continue;
            }

            if ($rt === 'purchase_order' || $rt === 'purchase_order_cancel') {
                $row = $this->query(
                    "SELECT order_number, status FROM purchase_orders WHERE id = ? LIMIT 1",
                    [$rid]
                )->fetch();
                $num = $row['order_number'] ?? ('#' . $rid);
                $mov['origin_label'] = $rt === 'purchase_order_cancel'
                    ? 'Devolución anulación ' . $num
                    : 'Compra ' . $num . ((($row['status'] ?? '') === 'cancelled') ? ' (anulada)' : '');
                $mov['origin_url'] = base_url('/app/compras?action=facturas');
                continue;
            }

            if ($rt === 'purchase_credit_note') {
                $row = $this->query(
                    "SELECT cn.credit_number, po.order_number
                     FROM purchase_credit_notes cn
                     LEFT JOIN purchase_orders po ON po.id = cn.purchase_order_id
                     WHERE cn.id = ? LIMIT 1",
                    [$rid]
                )->fetch();
                $mov['origin_label'] = 'NC compra ' . ($row['credit_number'] ?? ('#' . $rid))
                    . (!empty($row['order_number']) ? ' / ' . $row['order_number'] : '');
                $mov['origin_url'] = base_url('/app/compras?action=facturas');
                continue;
            }

            if ($rt === 'sale_credit_note') {
                try {
                    $row = $this->query(
                        "SELECT cn.credit_number, s.invoice_number
                         FROM sale_credit_notes cn
                         LEFT JOIN sales s ON s.id = cn.sale_id
                         WHERE cn.id = ? LIMIT 1",
                        [$rid]
                    )->fetch();
                } catch (\Throwable $e) {
                    $row = false;
                }
                $mov['origin_label'] = 'NC venta ' . ($row['credit_number'] ?? ('#' . $rid))
                    . (!empty($row['invoice_number']) ? ' / ' . $row['invoice_number'] : '');
                $mov['origin_url'] = base_url('/app/ventas?action=notas_credito');
                continue;
            }

            if ($rt === 'supplier_payment' || $rt === 'supplier_payment_cancel' || $rt === 'supplier_payment_cashfix') {
                $mov['origin_label'] = match ($rt) {
                    'supplier_payment_cancel' => 'Reverso abono #' . $rid,
                    'supplier_payment_cashfix' => 'Corrección abono #' . $rid,
                    default => 'Abono proveedor #' . $rid,
                };
                $mov['origin_url'] = base_url('/app/compras?action=facturas');
                continue;
            }

            $mov['origin_label'] = ucfirst(str_replace('_', ' ', $rt)) . ' #' . $rid;
        }
        unset($mov);

        return $movements;
    }

    private function liveSyncJson(): void
    {
        TenantMiddleware::authorize('caja', 'view');

        $ctx = $this->getOpenSessionsContext();
        $openSession = $ctx['open_session'];
        if (!$openSession) {
            $this->json([
                'success' => true,
                'open' => false,
                'csrf_token' => \SoftNova\Core\csrf_token(),
                'server_time' => date('c'),
            ]);
            return;
        }

        $sessionIds = $ctx['session_ids'];
        try {
            $totals = count($sessionIds) === 1
                ? $this->computeSessionTotals($openSession)
                : $this->computeUnifiedOpenTotals($ctx['open_sessions']);
        } catch (\Throwable $e) {
            error_log('caja liveSync: ' . $e->getMessage());
            $totals = [
                'incomes' => 0.0,
                'expenses' => 0.0,
                'balance' => 0.0,
                'expected_cash' => 0.0,
                'sales_count' => 0,
                'sales_total' => 0.0,
            ];
        }

        $movements = $this->fetchSessionMovements($sessionIds, true);
        $incomes = (float)($totals['incomes'] ?? 0);
        $expenses = (float)($totals['expenses'] ?? 0);
        $otherIncomes = (float)($totals['other_incomes'] ?? 0);
        $cashSales = (float)($totals['cash_sales'] ?? 0);
        $otherCashIncomes = (float)($totals['other_cash_incomes'] ?? 0);
        $balance = (float)($totals['balance'] ?? 0);
        $expectedCash = (float)($totals['expected_cash'] ?? $balance);

        $this->json([
            'success' => true,
            'open' => true,
            'csrf_token' => \SoftNova\Core\csrf_token(),
            'server_time' => date('c'),
            'totals' => [
                'incomes' => $incomes,
                'expenses' => $expenses,
                'other_incomes' => $otherIncomes,
                'cash_sales' => $cashSales,
                'other_cash_incomes' => $otherCashIncomes,
                'opening_amount' => (float)($totals['opening_amount'] ?? $openSession['opening_amount'] ?? 0),
                'balance' => $balance,
                'expected_cash' => $expectedCash,
                'sales_count' => (int)($totals['sales_count'] ?? 0),
                'sales_total' => (float)($totals['sales_total'] ?? 0),
                'sales_subtotal_before_discount' => (float)($totals['sales_subtotal_before_discount'] ?? 0),
                'sales_discount_total' => (float)($totals['sales_discount_total'] ?? 0),
                'incomes_fmt' => '+' . $this->formatMoney($incomes),
                'expenses_fmt' => '-' . $this->formatMoney($expenses),
                'other_incomes_fmt' => '+' . $this->formatMoney($otherIncomes),
                'cash_sales_fmt' => '+' . $this->formatMoney($cashSales),
                'other_cash_incomes_fmt' => '+' . $this->formatMoney($otherCashIncomes),
                'balance_fmt' => $this->formatMoney($expectedCash),
                'expected_cash_fmt' => $this->formatMoney($expectedCash),
            ],
            'movements' => array_map(function (array $m): array {
                $type = (string)($m['type'] ?? '');
                $amount = (float)($m['amount'] ?? 0);
                return [
                    'id' => (int)($m['id'] ?? 0),
                    'time' => date('H:i', strtotime((string)($m['created_at'] ?? 'now'))),
                    'user_name' => (string)($m['user_name'] ?? '—'),
                    'type' => $type,
                    'type_label' => $type === 'income' ? 'Ingreso' : 'Egreso',
                    'description' => (string)($m['description'] ?? ''),
                    'origin_label' => (string)($m['origin_label'] ?? ''),
                    'origin_url' => (string)($m['origin_url'] ?? ''),
                    'amount' => $amount,
                    'amount_fmt' => ($type === 'income' ? '+' : '-') . $this->formatMoney($amount),
                ];
            }, array_values(array_filter($movements, static fn($m) => empty($m['_meta_only'])))),
            'movements_count' => count(array_filter($movements, static fn($m) => empty($m['_meta_only']) && (int)($m['id'] ?? 0) > 0)),
            'hidden_voided_count' => (int)(($movements[0]['_hidden_voided_count'] ?? 0)),
        ]);
    }
}
