<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\ReceivableService;
use SoftNova\Services\PurchasingService;
use SoftNova\Services\DashboardLayoutService;
use SoftNova\Services\SupplyChainSchema;

/**
 * Dashboard del tenant - panel principal del cliente
 */
class TenantDashboardController extends TenantController
{
    public function index(): void
    {
        $db = $this->db;
        
        // Búsqueda global (AJAX)
        $q = $_GET['q'] ?? '';
        if (!empty($q) && $this->wantsJson()) {
            $this->search($db, $q);
            return;
        }
        
        $action = $this->request->get('action');
        if ($action === 'lookup_barcode' && $this->request->method() === 'GET') {
            $this->lookupBarcode($db);
            return;
        }
        if ($action === 'save_custom_option' && $this->request->method() === 'POST') {
            $this->saveCustomOption($db);
            return;
        }
        if ($action === 'saveLayout' && $this->request->method() === 'POST') {
            $this->saveLayout($db);
            return;
        }

        // La página del dashboard requiere Ver; los endpoints AJAX de arriba se autorizan aparte.
        TenantMiddleware::authorize('dashboard', 'view');
        
        $layoutService = new DashboardLayoutService($db);
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $layout = $layoutService->getLayout($userId);
        $canAccess = static fn(string $module): bool => TenantMiddleware::canAccess($module);
        
        $boardWidgets = $layoutService->resolveBoard($layout, $canAccess);
        $visibleWidgets = array_values(array_filter(
            $boardWidgets,
            static fn(array $w): bool => !empty($w['visible'])
        ));
        
        $stats = [
            'total_products' => (int)$db->query("SELECT COUNT(*) as c FROM products")->fetch()['c'],
            'total_customers' => (int)$db->query("SELECT COUNT(*) as c FROM customers")->fetch()['c'],
            'total_sales' => (int)$db->query("SELECT COUNT(*) as c FROM sales WHERE status != 'cancelled'")->fetch()['c'],
            'today_sales' => (float)$db->query("SELECT COALESCE(SUM(total), 0) as t FROM sales WHERE DATE(sale_date) = CURDATE() AND status != 'cancelled'")->fetch()['t'],
            'low_stock' => (int)$db->query("SELECT COUNT(*) as c FROM products WHERE stock <= min_stock AND status = 'active'")->fetch()['c'],
        ];
        
        $recentSales = [];
        $openReceivables = [];
        $recentlyPaidSales = [];
        $receivablesError = null;
        $openPayables = [];
        $payablesTotal = 0.0;
        $payablesError = null;
        $lowStockProducts = [];
        $todaySoldProducts = [];
        
        // Cargar datos de todos los widgets accesibles para poder mostrarlos sin recargar.
        $needsRecent = $this->widgetInBoard($boardWidgets, 'recent_sales');
        $needsRx = $this->widgetInBoard($boardWidgets, 'receivables');
        $needsPay = $this->widgetInBoard($boardWidgets, 'payables');
        $needsLowList = $this->widgetInBoard($boardWidgets, 'low_stock_list');
        $needsTodaySold = $this->widgetInBoard($boardWidgets, 'today_sold_products');
        
        if ($needsRecent) {
            $recentSales = $db->query("
                SELECT s.*, c.name as customer_name
                FROM sales s
                LEFT JOIN customers c ON s.customer_id = c.id
                WHERE s.status != 'cancelled'
                ORDER BY s.sale_date DESC, s.id DESC
                LIMIT 5
            ")->fetchAll();
        }
        
        if ($needsRx) {
            try {
                $rx = new ReceivableService($db);
                $openReceivables = $rx->listOpen(8);
                $recentlyPaidSales = $rx->listRecentlyPaid(5);
            } catch (\Throwable $e) {
                $openReceivables = [];
                $recentlyPaidSales = [];
                $receivablesError = 'No se pudieron cargar las cuentas por cobrar';
                error_log('Dashboard receivables: ' . $e->getMessage());
            }
        }

        if ($needsPay) {
            try {
                SupplyChainSchema::ensure($db);
                $purchasing = new PurchasingService($db);
                $openPayables = $purchasing->payableOrders(8);
                foreach ($openPayables as &$po) {
                    $paid = (float)($po['paid_amount'] ?? 0);
                    $credit = (float)($po['credit_amount'] ?? 0);
                    $total = (float)($po['total'] ?? 0);
                    $po['balance'] = round(max(0, $total - $paid - $credit), 2);
                }
                unset($po);
                $payablesTotal = $purchasing->payableTotal();
            } catch (\Throwable $e) {
                $openPayables = [];
                $payablesTotal = 0.0;
                $payablesError = 'No se pudieron cargar las cuentas por pagar';
                error_log('Dashboard payables: ' . $e->getMessage());
            }
        }
        
        if ($needsLowList) {
            $lowStockProducts = $db->query("
                SELECT id, name, code, stock, min_stock
                FROM products
                WHERE status = 'active' AND stock <= min_stock
                ORDER BY stock ASC
                LIMIT 8
            ")->fetchAll();
        }

        if ($needsTodaySold) {
            try {
                $itemSub = "COALESCE(si.subtotal, ROUND(si.quantity * si.unit_price, 2))";
                $todaySoldProducts = $db->query("
                    SELECT p.name,
                           SUM(si.quantity) AS total_qty,
                           SUM({$itemSub}) AS total_sales
                    FROM sale_items si
                    JOIN products p ON si.product_id = p.id
                    JOIN sales s ON si.sale_id = s.id
                    WHERE s.status = 'completed'
                      AND DATE(s.sale_date) = CURDATE()
                    GROUP BY p.id, p.name
                    ORDER BY total_qty DESC, total_sales DESC
                    LIMIT 10
                ")->fetchAll();
            } catch (\Throwable $e) {
                // Fallback si subtotal no existe en installs viejos.
                try {
                    $todaySoldProducts = $db->query("
                        SELECT p.name,
                               SUM(si.quantity) AS total_qty,
                               SUM(ROUND(si.quantity * si.unit_price, 2)) AS total_sales
                        FROM sale_items si
                        JOIN products p ON si.product_id = p.id
                        JOIN sales s ON si.sale_id = s.id
                        WHERE s.status = 'completed'
                          AND DATE(s.sale_date) = CURDATE()
                        GROUP BY p.id, p.name
                        ORDER BY total_qty DESC, total_sales DESC
                        LIMIT 10
                    ")->fetchAll();
                } catch (\Throwable $e2) {
                    $todaySoldProducts = [];
                    error_log('Dashboard today sold: ' . $e2->getMessage());
                }
            }
        }
        
        $this->view('tenant.dashboard', $this->tenantViewData([
            'stats' => $stats,
            'recentSales' => $recentSales,
            'openReceivables' => $openReceivables,
            'recentlyPaidSales' => $recentlyPaidSales,
            'receivablesError' => $receivablesError,
            'openPayables' => $openPayables,
            'payablesTotal' => $payablesTotal,
            'payablesError' => $payablesError,
            'lowStockProducts' => $lowStockProducts,
            'todaySoldProducts' => $todaySoldProducts,
            'dashboardLayout' => $layout,
            'visibleWidgets' => $visibleWidgets,
            'boardWidgets' => $boardWidgets,
            'widgetCatalog' => DashboardLayoutService::catalog(),
        ]));
    }
    
    private function widgetInBoard(array $widgets, string $id): bool
    {
        foreach ($widgets as $w) {
            if (($w['id'] ?? '') === $id) {
                return true;
            }
        }
        return false;
    }

    private function widgetVisible(array $widgets, string $id): bool
    {
        foreach ($widgets as $w) {
            if (($w['id'] ?? '') === $id && !empty($w['visible'])) {
                return true;
            }
        }
        return false;
    }
    
    private function saveLayout(\PDO $db): void
    {
        $rawBody = file_get_contents('php://input');
        $jsonBody = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
        
        $token = (string)(
            $_POST['csrf_token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
            ?? (is_array($jsonBody) ? ($jsonBody['csrf_token'] ?? '') : '')
        );
        if (!\SoftNova\Core\Security::verifyCsrfToken($token)) {
            $this->json(['success' => false, 'message' => 'Token de seguridad invalido o expirado']);
            return;
        }
        
        $raw = $this->request->post('layout');
        if (($raw === null || $raw === '') && is_array($jsonBody)) {
            $raw = $jsonBody['layout'] ?? $jsonBody;
        }
        
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } else {
            $decoded = is_array($raw) ? $raw : null;
        }
        
        if (!is_array($decoded)) {
            $this->json(['success' => false, 'message' => 'Layout invalido']);
            return;
        }
        
        if (isset($decoded['layout']) && is_array($decoded['layout'])) {
            $decoded = $decoded['layout'];
        }
        
        // Asegurar indices numericos
        if (!array_is_list($decoded)) {
            $decoded = array_values($decoded);
        }
        
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $service = new DashboardLayoutService($db);
        $ok = $service->saveLayout($userId, $decoded);
        
        if (!$ok) {
            $this->json(['success' => false, 'message' => 'No se pudo guardar el layout']);
            return;
        }
        
        $this->json([
            'success' => true,
            'message' => 'Dashboard actualizado',
            'redirect' => \SoftNova\Core\base_url('app/dashboard'),
        ]);
    }
    
    /**
     * Lookup exacto de producto por código de barras / SKU (products.code).
     */
    private function saveCustomOption(\PDO $db): void
    {
        $token = (string)(
            $_POST['csrf_token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
            ?? ''
        );
        if (!\SoftNova\Core\Security::verifyCsrfToken($token)) {
            $this->json(['success' => false, 'message' => 'Token de seguridad invalido o expirado']);
            return;
        }
        $group = trim((string)$this->request->post('group', ''));
        $label = trim((string)$this->request->post('label', ''));
        $kind = trim((string)$this->request->post('kind', ''));
        if (!$this->canSaveCustomOptionGroup($group)) {
            $this->json(['success' => false, 'message' => 'No tienes permiso para agregar esta opción']);
            return;
        }
        try {
            $svc = new \SoftNova\Services\CustomOptionService($db);
            $extra = [];
            if ($group === \SoftNova\Services\CustomOptionService::GROUP_EXPENSE_CATEGORY && $kind !== '') {
                $extra['kind'] = $kind;
            }
            $opt = $svc->add($group, $label, $extra);
            $this->json([
                'success' => true,
                'message' => $opt['created'] ? 'Opción guardada' : 'Opción ya existía',
                'option' => [
                    'value' => $opt['value'],
                    'label' => $opt['label'],
                    'created' => $opt['created'],
                    'kind' => $opt['kind'] ?? $kind,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function canSaveCustomOptionGroup(string $group): bool
    {
        if (TenantMiddleware::getRole() === 'admin') {
            return true;
        }
        return match ($group) {
            \SoftNova\Services\CustomOptionService::GROUP_EXPENSE_CATEGORY =>
                TenantMiddleware::getRole() === 'admin',
            \SoftNova\Services\CustomOptionService::GROUP_PAYMENT_METHOD =>
                TenantMiddleware::canDo('create', 'ventas')
                || TenantMiddleware::canDo('create', 'caja')
                || TenantMiddleware::canDo('create', 'gastos'),
            \SoftNova\Services\CustomOptionService::GROUP_CANCEL_REASON =>
                TenantMiddleware::canDo('create', 'ventas')
                || TenantMiddleware::canDo('edit', 'ventas')
                || TenantMiddleware::canDo('create', 'caja'),
            \SoftNova\Services\CustomOptionService::GROUP_DISCOUNT_PERCENT =>
                TenantMiddleware::canDo('create', 'ventas')
                || TenantMiddleware::canDo('create', 'caja'),
            \SoftNova\Services\CustomOptionService::GROUP_CUSTOMER_SOURCE =>
                TenantMiddleware::canDo('create', 'clientes')
                || TenantMiddleware::canDo('edit', 'clientes'),
            \SoftNova\Services\CustomOptionService::GROUP_PRODUCT_LOCATION =>
                TenantMiddleware::canDo('create', 'inventario')
                || TenantMiddleware::canDo('edit', 'inventario'),
            \SoftNova\Services\CustomOptionService::GROUP_LOAN_REASON =>
                TenantMiddleware::canDo('create', 'prestamos')
                || TenantMiddleware::canDo('create', 'caja'),
            \SoftNova\Services\CustomOptionService::GROUP_LOAN_RECEIVED_REASON =>
                TenantMiddleware::canDo('create', 'prestamos'),
            default => false,
        };
    }

    private function lookupBarcode(\PDO $db): void
    {
        $code = trim((string)$this->request->get('code', ''));
        if ($code === '' || mb_strlen($code) < 2) {
            $this->json(['success' => false, 'message' => 'Código vacío', 'product' => null]);
            return;
        }

        $eanDigits = preg_replace('/\D+/', '', $code) ?? '';
        $eanAlt = '';
        if (strlen($eanDigits) === 12) {
            $eanAlt = '0' . $eanDigits;
        } elseif (strlen($eanDigits) === 13 && str_starts_with($eanDigits, '0')) {
            $eanAlt = substr($eanDigits, 1);
        }
        $barcodeExpr = "REPLACE(REPLACE(TRIM(COALESCE(barcode,'')), ' ', ''), '-', '')";

        try {
            $stmt = $db->prepare(
                "SELECT id, name, code, barcode, sale_price, wholesale_price, purchase_price, stock, min_stock,
                        product_type, status, COALESCE(ownership,'owned') AS ownership, consignor_id,
                        COALESCE(tax_rate, 0) AS tax_rate
                 FROM products
                 WHERE status = 'active'
                   AND COALESCE(ownership,'owned') <> 'consignment'
                   AND (
                        code = ? OR barcode = ? OR TRIM(COALESCE(barcode,'')) = ?
                        OR ({$barcodeExpr} <> '' AND {$barcodeExpr} IN (?, ?))
                   )
                 ORDER BY CASE WHEN code = ? THEN 0 WHEN barcode = ? THEN 1 ELSE 2 END
                 LIMIT 1"
            );
            $stmt->execute([
                $code, $code, $code,
                $eanDigits !== '' ? $eanDigits : $code,
                $eanAlt !== '' ? $eanAlt : ($eanDigits !== '' ? $eanDigits : $code),
                $code, $code,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Instalaciones sin ownership/barcode: fallback a SKU.
            try {
                $stmt = $db->prepare(
                    "SELECT id, name, code, sale_price, purchase_price, stock, min_stock, product_type, status
                     FROM products
                     WHERE status = 'active' AND (code = ? OR barcode = ?)
                     LIMIT 1"
                );
                $stmt->execute([$code, $code]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e2) {
                try {
                    $stmt = $db->prepare(
                        "SELECT id, name, code, sale_price, purchase_price, stock, min_stock, product_type, status
                         FROM products
                         WHERE status = 'active' AND code = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$code]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                } catch (\Throwable $e3) {
                    $this->json(['success' => false, 'message' => 'Error al buscar código', 'product' => null]);
                    return;
                }
            }
        }

        if (!$row) {
            $this->json(['success' => false, 'message' => 'Producto no encontrado', 'product' => null]);
            return;
        }

        $ownership = (string)($row['ownership'] ?? 'owned');
        $this->json([
            'success' => true,
            'product' => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'code' => (string)($row['code'] ?? ''),
                'barcode' => (string)($row['barcode'] ?? ''),
                'sale_price' => (float)$row['sale_price'],
                'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
                'purchase_price' => (float)($row['purchase_price'] ?? 0),
                'stock' => (int)$row['stock'],
                'min_stock' => (int)($row['min_stock'] ?? 0),
                'product_type' => (string)($row['product_type'] ?? 'product'),
                'ownership' => $ownership,
                'is_consignment' => $ownership === 'consignment' ? 1 : 0,
                'consignor_id' => (int)($row['consignor_id'] ?? 0),
                'tax_rate' => (float)($row['tax_rate'] ?? 0),
            ],
        ]);
    }

    /**
     * Búsqueda global en vivo
     */
    private function search(\PDO $db, string $q): void
    {
        $like = '%' . $q . '%';
        $results = [];
        
        $money = fn(float $amount): string => $this->formatMoney($amount);
        
        if (TenantMiddleware::canAccess('inventario')) {
            $products = $db->prepare("SELECT id, name, code, sale_price, stock FROM products WHERE (name LIKE ? OR code LIKE ?) AND status = 'active' LIMIT 5");
            $products->execute([$like, $like]);
            foreach ($products->fetchAll() as $p) {
                $results[] = [
                    'type' => 'producto',
                    'title' => $p['name'],
                    'subtitle' => 'Stock: ' . $p['stock'] . ' | Precio: ' . $money((float)$p['sale_price']),
                    'url' => \SoftNova\Core\route('app/inventario'),
                    'badge' => 'Producto',
                    'badgeClass' => 'badge-success',
                ];
            }
        }
        
        if (TenantMiddleware::canAccess('clientes')) {
            $customers = $db->prepare("SELECT id, first_name, last_name, name, document_number, email, phone FROM customers WHERE (first_name LIKE ? OR last_name LIKE ? OR name LIKE ? OR document_number LIKE ? OR email LIKE ?) AND status = 'active' LIMIT 5");
            $customers->execute([$like, $like, $like, $like, $like]);
            foreach ($customers->fetchAll() as $c) {
                $displayName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                if (empty($displayName)) {
                    $displayName = $c['name'];
                }
                $results[] = [
                    'type' => 'cliente',
                    'title' => $displayName,
                    'subtitle' => ($c['document_number'] ? 'Doc: ' . $c['document_number'] . ' | ' : '') . ($c['phone'] ?? ''),
                    'url' => \SoftNova\Core\route('app/clientes'),
                    'badge' => 'Cliente',
                    'badgeClass' => 'badge-info',
                ];
            }
        }
        
        if (TenantMiddleware::canAccess('ventas')) {
            $sales = $db->prepare("SELECT s.id, s.invoice_number, s.total, s.sale_date, c.first_name, c.last_name, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.invoice_number LIKE ? ORDER BY s.sale_date DESC LIMIT 5");
            $sales->execute([$like]);
            foreach ($sales->fetchAll() as $s) {
                $custName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                if (empty($custName)) {
                    $custName = $s['customer_name'] ?? 'General';
                }
                $results[] = [
                    'type' => 'venta',
                    'title' => $s['invoice_number'],
                    'subtitle' => $custName . ' | ' . $money((float)$s['total']) . ' | ' . date('d/m/Y', strtotime($s['sale_date'])),
                    'url' => \SoftNova\Core\route('app/ventas'),
                    'badge' => 'Venta',
                    'badgeClass' => 'badge-warning',
                ];
            }
        }
        
        $this->json(['results' => $results]);
    }
}
