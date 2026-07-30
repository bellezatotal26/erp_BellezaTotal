<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\ReceivableService;
use SoftNova\Services\DashboardLayoutService;

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
        if ($action === 'saveLayout' && $this->request->method() === 'POST') {
            $this->saveLayout($db);
            return;
        }
        
        $layoutService = new DashboardLayoutService($db);
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        $layout = $layoutService->getLayout($userId);
        
        $visibleWidgets = $layoutService->resolveVisible(
            $layout,
            static fn(string $module): bool => TenantMiddleware::canAccess($module)
        );
        
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
        $lowStockProducts = [];
        
        $needsRecent = $this->widgetVisible($visibleWidgets, 'recent_sales');
        $needsRx = $this->widgetVisible($visibleWidgets, 'receivables');
        $needsLowList = $this->widgetVisible($visibleWidgets, 'low_stock_list');
        
        if ($needsRecent) {
            $recentSales = $db->query("
                SELECT s.*, c.name as customer_name
                FROM sales s
                LEFT JOIN customers c ON s.customer_id = c.id
                WHERE s.status != 'cancelled'
                ORDER BY s.created_at DESC
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
        
        if ($needsLowList) {
            $lowStockProducts = $db->query("
                SELECT id, name, code, stock, min_stock
                FROM products
                WHERE status = 'active' AND stock <= min_stock
                ORDER BY stock ASC
                LIMIT 8
            ")->fetchAll();
        }
        
        $this->view('tenant.dashboard', $this->tenantViewData([
            'stats' => $stats,
            'recentSales' => $recentSales,
            'openReceivables' => $openReceivables,
            'recentlyPaidSales' => $recentlyPaidSales,
            'receivablesError' => $receivablesError,
            'lowStockProducts' => $lowStockProducts,
            'dashboardLayout' => $layout,
            'visibleWidgets' => $visibleWidgets,
            'widgetCatalog' => DashboardLayoutService::catalog(),
        ]));
    }
    
    private function widgetVisible(array $widgets, string $id): bool
    {
        foreach ($widgets as $w) {
            if (($w['id'] ?? '') === $id) {
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
    private function lookupBarcode(\PDO $db): void
    {
        $code = trim((string)$this->request->get('code', ''));
        if ($code === '' || mb_strlen($code) < 2) {
            $this->json(['success' => false, 'message' => 'Código vacío', 'product' => null]);
            return;
        }

        try {
            $stmt = $db->prepare(
                "SELECT id, name, code, sale_price, purchase_price, stock, min_stock, product_type, status
                 FROM products
                 WHERE status = 'active' AND code = ?
                 LIMIT 1"
            );
            $stmt->execute([$code]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'Error al buscar código', 'product' => null]);
            return;
        }

        if (!$row) {
            $this->json(['success' => false, 'message' => 'Producto no encontrado', 'product' => null]);
            return;
        }

        $this->json([
            'success' => true,
            'product' => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'code' => (string)($row['code'] ?? ''),
                'sale_price' => (float)$row['sale_price'],
                'purchase_price' => (float)($row['purchase_price'] ?? 0),
                'stock' => (int)$row['stock'],
                'min_stock' => (int)($row['min_stock'] ?? 0),
                'product_type' => (string)($row['product_type'] ?? 'product'),
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
