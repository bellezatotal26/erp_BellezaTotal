<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\AccountingService;
use SoftNova\Services\LotService;
use SoftNova\Services\ProductSupplierService;
use SoftNova\Services\StockService;
use SoftNova\Services\SupplyChainSchema;
use SoftNova\Services\TenantOpsSchema;
use SoftNova\Services\Integrations\CatalogSyncService;

class TenantInventarioController extends TenantController
{
    private StockService $stock;
    private const MAX_UPLOAD_BYTES = 2097152; // 2MB
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('inventario');
        $this->ensureQuotesSchema();
        TenantOpsSchema::ensure($this->db);
        SupplyChainSchema::ensure($this->db);
        $this->ensureWholesalePriceColumn();
        $this->stock = new StockService($this->db);
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
        try {
            \SoftNova\Services\TaxService::ensureSchema($this->db);
        } catch (\Throwable $e) {
            error_log('TaxService ensureSchema: ' . $e->getMessage());
        }
    }

    private function resolveProductTaxRateFromRequest(): float
    {
        $raw = $this->request->post('tax_rate', null);
        if ($raw === null || $raw === '') {
            return \SoftNova\Services\TaxService::defaultRate($this->db);
        }
        return \SoftNova\Services\TaxService::normalizeRate((float)$raw);
    }
    
    public function index(): void
    {
        try {
            \SoftNova\Services\OpenItemProduct::ensure($this->db);
        } catch (\Throwable $e) {
            error_log('OpenItemProduct ensure: ' . $e->getMessage());
        }
        try {
            (new \SoftNova\Services\ProductOfferService($this->db))->expireDueOffers();
        } catch (\Throwable $e) {
            error_log('ProductOffer expire: ' . $e->getMessage());
        }

        $action = $this->request->get('action');
        if ($action === null || $action === '') {
            $action = $this->request->post('action');
        }
        
        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->create();
            return;
        }
        if ($action === 'edit' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->edit();
            return;
        }
        if ($action === 'delete' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'delete');
            $this->delete();
            return;
        }
        if ($action === 'delete_bulk' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'delete');
            $this->deleteBulk();
            return;
        }
        if ($action === 'add_stock' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->addStock();
            return;
        }
        if ($action === 'stock_exit' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->stockExit();
            return;
        }
        if ($action === 'searchProducts' && $this->request->method() === 'GET') {
            $this->searchProducts();
            return;
        }
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'edit_movement_date' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->editMovementDate();
            return;
        }
        if ($action === 'traceability') {
            $this->traceability();
            return;
        }
        if ($action === 'vencimientos') {
            $this->expiryPanel();
            return;
        }
        if ($action === 'lot_adjust' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->lotAction('adjust');
            return;
        }
        if ($action === 'lot_block' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->lotAction('block');
            return;
        }
        if ($action === 'lot_unblock' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->lotAction('unblock');
            return;
        }
        if ($action === 'lot_writeoff' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->lotAction('writeoff');
            return;
        }
        if ($action === 'lot_repair' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->lotRepair();
            return;
        }
        if ($action === 'supplier_link' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->supplierLink();
            return;
        }
        if ($action === 'supplier_unlink' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->supplierUnlink();
            return;
        }
        if ($action === 'product_suppliers' && $this->request->method() === 'GET') {
            $this->productSuppliers();
            return;
        }
        if ($action === 'import_catalog' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->importCatalog();
            return;
        }
        if ($action === 'save-catalog-integration' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->saveCatalogIntegration();
            return;
        }
        if ($action === 'catalog-test') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->testCatalogIntegration();
            return;
        }
        if ($action === 'ml-oauth-start') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->mlOAuthStart();
            return;
        }
        if ($action === 'ml-oauth-callback') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->mlOAuthCallback();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('inventario', 'export');
            $this->exportProducts();
            return;
        }
        if ($action === 'export_count' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('inventario', 'export');
            $this->exportCountSheet();
            return;
        }
        if ($action === 'offer_activate' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->activateOffer();
            return;
        }
        if ($action === 'offer_cancel' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->cancelOffer();
            return;
        }
        if ($action === 'import_csv' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->importCsv();
            return;
        }
        if ($action === 'repair_inflated_costs' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->repairInflatedCosts();
            return;
        }
        if ($action === 'import_template' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('inventario');
            $this->downloadImportTemplate();
            return;
        }
        if ($action === 'generate_barcode' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->generateBarcode();
            return;
        }
        if ($action === 'generate_sku' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->generateSku();
            return;
        }
        if ($action === 'create_category' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->createCategory();
            return;
        }
        if ($action === 'delete_category' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'delete');
            $this->deleteCategory();
            return;
        }
        if ($action === 'category_report' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('inventario', 'export');
            $this->categoryReport();
            return;
        }
        if ($action === 'traceability_pdf' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('inventario', 'export');
            $this->traceabilityPdf();
            return;
        }
        
        $filters = $this->listFilters([
            'name' => 'p.name',
            'code' => 'p.code',
            'category' => 'c.name',
            'price' => 'p.sale_price',
            'cost' => 'p.purchase_price',
            'stock' => 'p.stock',
            'created' => 'p.created_at',
            'location' => 'p.location',
        ], 'name', 'asc');

        $typeFilter = $this->request->get('type', '');
        $statusFilter = (string)$this->request->get('status', '');
        if (!in_array($statusFilter, ['active', 'inactive', ''], true)) {
            $statusFilter = '';
        }
        $categoryFilter = (int)$this->request->get('category_id', 0);
        $stockFilter = (string)$this->request->get('stock_state', '');
        $channelFilter = (string)$this->request->get('channel', '');
        $locationFilter = trim((string)$this->request->get('location', ''));
        $perPageOptions = [20, 50, 100, 200];
        $perPage = (int)$this->request->get('per_page', 20);
        if (!in_array($perPage, $perPageOptions, true)) {
            $perPage = 20;
        }

        try {
            (new \SoftNova\Services\ConsignmentService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
            // ignore
        }
        // Propio + prestado a nosotros (excluye consignación). KPI sigue con ownedOnly.
        // Incluye activos e inactivos (estado visible en listado); filtro opcional por status.
        $where = [\SoftNova\Services\ConsignmentService::stockableOwnershipSql('p')];
        $params = [];
        if ($statusFilter === 'active' || $statusFilter === 'inactive') {
            $where[] = "COALESCE(p.status, 'active') = ?";
            $params[] = $statusFilter;
        } else {
            $where[] = "COALESCE(p.status, 'active') IN ('active', 'inactive')";
        }
        if ($typeFilter === 'product' || $typeFilter === 'service') {
            $where[] = "p.product_type = ?";
            $params[] = $typeFilter;
        }
        if ($categoryFilter > 0) {
            $where[] = "p.category_id = ?";
            $params[] = $categoryFilter;
        }
        if ($stockFilter === 'low') {
            $where[] = "p.product_type = 'product' AND p.stock <= p.min_stock";
        } elseif ($stockFilter === 'out') {
            $where[] = "p.product_type = 'product' AND p.stock <= 0";
        }
        if ($channelFilter !== '') {
            $where[] = "COALESCE(p.source_channel, 'manual') = ?";
            $params[] = $channelFilter;
        }
        if ($locationFilter !== '') {
            $where[] = "p.location = ?";
            $params[] = $locationFilter;
        }
        if ($filters['q'] !== '') {
            $search = \SoftNova\Core\multi_term_like_clause($filters['q'], [
                'p.name',
                'p.code',
                "COALESCE(p.barcode,'')",
                'p.description',
                "COALESCE(p.notes,'')",
                "COALESCE(p.external_id,'')",
                "COALESCE(p.location,'')",
            ]);
            $where[] = $search['sql'];
            foreach ($search['params'] as $p) {
                $params[] = $p;
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->query(
            "SELECT COUNT(*) as c FROM products p LEFT JOIN categories c ON p.category_id = c.id {$whereSql}",
            $params
        )->fetch()['c'];
        $pagination = $this->paginate($total, $perPage);
        
        $products = $this->query(
            "SELECT p.*, c.name as category_name,
                    DATEDIFF(NOW(), p.created_at) as days_in_inventory,
                    DATEDIFF(NOW(), p.last_sale_date) as days_since_last_sale,
                    COALESCE((
                        SELECT SUM(qi.quantity)
                        FROM quote_items qi
                        JOIN quotes q ON qi.quote_id = q.id
                        WHERE qi.product_id = p.id AND q.status IN ('pending', 'accepted')
                    ), 0) as reserved_qty
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             {$whereSql}
             ORDER BY {$filters['orderSql']}, p.name ASC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
            $params
        )->fetchAll();

        $debtTotals = $this->stock->openDebtTotalsByProduct(array_column($products, 'id'));
        foreach ($products as &$pRow) {
            $pid = (int)$pRow['id'];
            $pRow['stock_debt_qty'] = (int)($debtTotals[$pid] ?? 0);
        }
        unset($pRow);

        $primarySuppliers = [];
        try {
            $ids = array_values(array_filter(array_map('intval', array_column($products, 'id'))));
            if ($ids) {
                SupplyChainSchema::ensure($this->db);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $rows = $this->query(
                    "SELECT ps.product_id, ps.supplier_id, ps.supplier_sku, ps.is_primary
                     FROM product_suppliers ps
                     WHERE ps.product_id IN ({$in}) AND ps.status = 'active'
                     ORDER BY ps.is_primary DESC, ps.id ASC",
                    $ids
                )->fetchAll();
                foreach ($rows as $r) {
                    $pid = (int)$r['product_id'];
                    if (!isset($primarySuppliers[$pid])) {
                        $primarySuppliers[$pid] = $r;
                    }
                }
            }
        } catch (\Throwable $e) {
            $primarySuppliers = [];
        }
        foreach ($products as &$pRow) {
            $ps = $primarySuppliers[(int)$pRow['id']] ?? null;
            $pRow['primary_supplier_id'] = $ps ? (int)$ps['supplier_id'] : 0;
            $pRow['primary_supplier_sku'] = $ps ? (string)($ps['supplier_sku'] ?? '') : '';
        }
        unset($pRow);
        
        $categories = $this->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();
        $ownedSql = \SoftNova\Services\ConsignmentService::ownedOnlySql('products');
        $lowStock = $this->query(
            "SELECT COUNT(*) as c FROM products WHERE stock <= min_stock AND status = 'active' AND product_type = 'product' AND {$ownedSql}"
        )->fetch()['c'];
        $totalProducts = $this->query(
            "SELECT COUNT(*) as c FROM products WHERE status = 'active' AND product_type = 'product' AND {$ownedSql}"
        )->fetch()['c'];
        $totalServices = $this->query(
            "SELECT COUNT(*) as c FROM products WHERE status = 'active' AND product_type = 'service' AND {$ownedSql}"
        )->fetch()['c'];

        $inventoryValue = ['cost' => 0.0, 'sale' => 0.0, 'units' => 0];
        // Totales de costo/venta: solo Administrador y Gerencia (KPI sensible).
        if (\SoftNova\Core\TenantMiddleware::isManagement()) {
            try {
                $row = $this->query(
                    "SELECT
                        COALESCE(SUM(GREATEST(stock, 0) * COALESCE(purchase_price, 0)), 0) AS cost_value,
                        COALESCE(SUM(GREATEST(stock, 0) * COALESCE(sale_price, 0)), 0) AS sale_value,
                        COALESCE(SUM(GREATEST(stock, 0)), 0) AS units
                     FROM products
                     WHERE status = 'active' AND product_type = 'product' AND {$ownedSql}"
                )->fetch();
                $inventoryValue = [
                    'cost' => round((float)($row['cost_value'] ?? 0), 2),
                    'sale' => round((float)($row['sale_value'] ?? 0), 2),
                    'units' => (int)($row['units'] ?? 0),
                ];
            } catch (\Throwable $e) {
                // ignore
            }
        }
        
        $extraQuery = array_filter([
            'type' => ($typeFilter === 'product' || $typeFilter === 'service') ? $typeFilter : null,
            'status' => ($statusFilter === 'active' || $statusFilter === 'inactive') ? $statusFilter : null,
            'category_id' => $categoryFilter > 0 ? $categoryFilter : null,
            'stock_state' => in_array($stockFilter, ['low', 'out'], true) ? $stockFilter : null,
            'channel' => $channelFilter !== '' ? $channelFilter : null,
            'location' => $locationFilter !== '' ? $locationFilter : null,
            'per_page' => $perPage !== 20 ? $perPage : null,
        ], static fn($v) => $v !== null && $v !== '');
        $filters['query'] = array_merge($filters['query'], $extraQuery);

        $catalogStatuses = [];
        try {
            $catalogStatuses = (new CatalogSyncService($this->db))->statuses();
        } catch (\Throwable $e) {
            $catalogStatuses = [];
        }

        $expirySummary = ['expired_lots' => 0, 'expiring_lots' => 0, 'expired_value' => 0.0, 'expiring_value' => 0.0];
        try {
            $expirySummary = (new LotService($this->db))->expirySummary();
        } catch (\Throwable $e) {
            // Instalación sin migrar aún: el panel de vencimientos queda en cero.
        }

        $suppliersCatalog = [];
        try {
            $suppliersCatalog = $this->query(
                "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
            )->fetchAll();
        } catch (\Throwable $e) {
            $suppliersCatalog = [];
        }

        $locationOptions = [];
        try {
            $locationOptions = (new \SoftNova\Services\CustomOptionService($this->db))
                ->options(\SoftNova\Services\CustomOptionService::GROUP_PRODUCT_LOCATION, true);
        } catch (\Throwable $e) {
            $locationOptions = [
                ['value' => 'Bodega', 'label' => 'Bodega'],
                ['value' => 'Almacén 1', 'label' => 'Almacén 1'],
                ['value' => 'Local 2', 'label' => 'Local 2'],
                ['value' => '__other__', 'label' => 'Otro'],
            ];
        }

        $this->view('tenant.inventario', $this->tenantViewData([
            'expirySummary' => $expirySummary,
            'suppliersCatalog' => $suppliersCatalog,
            'products' => $products,
            'categories' => $categories,
            'lowStock' => $lowStock,
            'totalProducts' => $totalProducts,
            'totalServices' => $totalServices,
            'inventoryValue' => $inventoryValue,
            'typeFilter' => $typeFilter,
            'statusFilter' => $statusFilter,
            'categoryFilter' => $categoryFilter,
            'stockFilter' => $stockFilter,
            'channelFilter' => $channelFilter,
            'locationFilter' => $locationFilter,
            'locationOptions' => $locationOptions,
            'filters' => $filters,
            'pagination' => $pagination,
            'perPageOptions' => $perPageOptions,
            'catalogStatuses' => $catalogStatuses,
            'mlOAuthRedirect' => \SoftNova\Core\route('app/inventario') . '?action=ml-oauth-callback',
            'offerOptions' => \SoftNova\Services\ProductOfferService::formOptions(),
            'defaultTaxRate' => \SoftNova\Services\TaxService::defaultRate($this->db),
        ]));
    }
    
    public function detail(): void
    {
        $id = (int)$this->request->get('id');
        $product = $this->query(
            "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?",
            [$id]
        )->fetch();
        
        if (!$product) {
            $this->json(['error' => 'Producto no encontrado']);
            return;
        }
        
        // Kardex operativo: excluye huellas de auditoría CSV (qty 0) y enriquece OC/factura/venta.
        $movements = [];
        try {
            $movements = $this->query(
                "SELECT sm.*, u.name AS user_name,
                        po.order_number AS purchase_order_number,
                        po.invoice_number AS purchase_invoice_number,
                        po.status AS purchase_order_status,
                        po.warehouse_date AS purchase_warehouse_date,
                        s.invoice_number AS sale_document_number
                 FROM stock_movements sm
                 LEFT JOIN users u ON u.id = sm.user_id
                 LEFT JOIN purchase_orders po ON po.id = sm.reference_id
                      AND sm.reference_type IN ('purchase_order', 'purchase', 'purchase_cancel', 'purchase_credit_note')
                 LEFT JOIN sales s ON s.id = sm.reference_id
                      AND sm.reference_type IN ('sale', 'return', 'sale_credit_note')
                 WHERE sm.product_id = ?
                   AND NOT (
                        COALESCE(sm.reference_type, '') = 'csv_export'
                        AND COALESCE(sm.quantity, 0) = 0
                   )
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT 100",
                [$id]
            )->fetchAll();
        } catch (\Throwable $e) {
            // Fallback sin joins (installs viejos).
            $movements = $this->query(
                "SELECT sm.*, u.name AS user_name
                 FROM stock_movements sm
                 LEFT JOIN users u ON u.id = sm.user_id
                 WHERE sm.product_id = ?
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT 100",
                [$id]
            )->fetchAll();
            $movements = array_values(array_filter($movements, static function ($m) {
                return !(
                    (string)($m['reference_type'] ?? '') === 'csv_export'
                    && (int)($m['quantity'] ?? 0) === 0
                );
            }));
        }

        $movements = \SoftNova\Services\StockService::attachPurchaseTaxInfo($this->db, $movements);
        foreach ($movements as &$m) {
            $m['display_at'] = \SoftNova\Services\StockService::formatMovementDisplayAt($m);
            $m['origin_label'] = $this->movementOriginLabel($m);
            $m['motive'] = \SoftNova\Services\StockService::describeMovementMotive($m);
            $m['related_substitution'] = 0;
            $m['related_loan_origin'] = 0;
            $m['affects_local_stock'] = 1;
        }
        unset($m);

        $movements = $this->attachLoanSubstitutionLinks((int)$id, $movements);
        
        $lastSales = $this->query(
            "SELECT si.*, s.invoice_number, s.sale_date
             FROM sale_items si
             JOIN sales s ON si.sale_id = s.id
             WHERE si.product_id = ?
             ORDER BY s.sale_date DESC LIMIT 5",
            [$id]
        )->fetchAll();

        // Compras modernas (OC / facturas de proveedor), no el legado `purchases`.
        $purchases = [];
        $purchaseSql = "SELECT poi.id, poi.quantity, poi.received_qty, poi.unit_cost, %s poi.tax_rate,
                        poi.product_name, poi.lot_code, poi.expiry_date,
                        po.id AS purchase_order_id, po.order_number, po.invoice_number,
                        po.invoice_date, po.warehouse_date, po.received_at, po.status,
                        po.created_at, po.origin, su.name AS supplier_name
                 FROM purchase_order_items poi
                 JOIN purchase_orders po ON po.id = poi.purchase_order_id
                 LEFT JOIN suppliers su ON su.id = po.supplier_id
                 WHERE poi.product_id = ?
                 ORDER BY COALESCE(po.received_at, po.warehouse_date, po.created_at) DESC, po.id DESC
                 LIMIT 30";
        try {
            $purchases = $this->query(sprintf($purchaseSql, 'poi.inventory_cost,'), [$id])->fetchAll();
        } catch (\Throwable $e) {
            try {
                $purchases = $this->query(sprintf($purchaseSql, ''), [$id])->fetchAll();
            } catch (\Throwable $e2) {
                $purchases = [];
            }
        }

        $costSummary = $this->productCostSummary((int)$id, $product);
        $costHistory = $this->productCostHistoryRows((int)$id);

        // Si hay OC recibida sin fila en stock_movements, exponerla como hito sintético.
        $knownPoRefs = [];
        foreach ($movements as $m) {
            $rt = (string)($m['reference_type'] ?? '');
            if (in_array($rt, ['purchase_order', 'purchase'], true) && !empty($m['reference_id'])) {
                $knownPoRefs[(int)$m['reference_id']] = true;
            }
        }
        foreach ($purchases as $po) {
            $poId = (int)($po['purchase_order_id'] ?? 0);
            $status = (string)($po['status'] ?? '');
            if ($poId <= 0 || isset($knownPoRefs[$poId])) {
                continue;
            }
            if (!in_array($status, ['received', 'cancelled'], true)) {
                continue;
            }
            $qty = (int)($po['received_qty'] ?? $po['quantity'] ?? 0);
            if ($qty <= 0) {
                $qty = (int)($po['quantity'] ?? 0);
            }
            $at = (string)($po['received_at'] ?? $po['warehouse_date'] ?? $po['created_at'] ?? '');
            $inv = trim((string)($po['invoice_number'] ?? ''));
            $notes = trim(
                ($po['order_number'] ?? '')
                . ($inv !== '' ? (' / Factura ' . $inv) : '')
                . ' · ' . ($po['supplier_name'] ?? '')
                . ($status === 'cancelled' ? ' · (anulada)' : ' · (desde OC, sin fila kardex)')
            );
            $synthetic = [
                'id' => 0,
                'type' => $status === 'cancelled' ? 'out' : 'in',
                'quantity' => $qty,
                'reference_type' => 'purchase_order',
                'reference_id' => $poId,
                'notes' => $notes,
                'created_at' => $at,
                'movement_date' => substr($at, 0, 10) ?: null,
                'user_name' => null,
                'purchase_order_number' => $po['order_number'] ?? null,
                'purchase_invoice_number' => $inv !== '' ? $inv : null,
                'purchase_order_status' => $status,
                'unit_cost' => $po['unit_cost'] ?? null,
                'product_id' => $id,
                'synthetic' => true,
            ];
            $synthetic = \SoftNova\Services\StockService::attachPurchaseTaxInfo($this->db, [$synthetic])[0];
            $synthetic['display_at'] = \SoftNova\Services\StockService::formatMovementDisplayAt($synthetic);
            $synthetic['origin_label'] = $this->movementOriginLabel($synthetic);
            $synthetic['motive'] = \SoftNova\Services\StockService::describeMovementMotive($synthetic);
            $movements[] = $synthetic;
            $knownPoRefs[$poId] = true;
        }
        usort($movements, static function ($a, $b) {
            $ca = (string)($a['created_at'] ?? $a['movement_date'] ?? '');
            $cb = (string)($b['created_at'] ?? $b['movement_date'] ?? '');
            return strcmp($cb, $ca);
        });
        $movements = array_slice($movements, 0, 100);
        
        $lots = [];
        if (!empty($product['track_lots'])) {
            try {
                $lots = (new LotService($this->db))->lotsForProduct($id);
            } catch (\Throwable $e) {
                $lots = [];
            }
        }

        $productSuppliers = [];
        try {
            $productSuppliers = (new ProductSupplierService($this->db))->suppliersFor($id);
        } catch (\Throwable $e) {
            $productSuppliers = [];
        }

        $this->json([
            'product' => $product,
            'movements' => $movements,
            'lastSales' => $lastSales,
            'purchases' => $purchases,
            'cost_summary' => $costSummary,
            'cost_history' => $costHistory,
            'lots' => $lots,
            'suppliers' => $productSuppliers,
            'stock_debts' => $this->stock->openDebtsForProduct($id),
            'channel_label' => \SoftNova\Core\product_channel_label((string)($product['source_channel'] ?? 'manual')),
        ]);
    }

    /**
     * Cruza préstamos con devoluciones por otra referencia para que la ficha del producto
     * muestre ambos lados del cambio (historial histórico + nuevos con return_product_id).
     *
     * @param list<array<string,mixed>> $movements
     * @return list<array<string,mixed>>
     */
    private function attachLoanSubstitutionLinks(int $productId, array $movements): array
    {
        if ($productId <= 0) {
            return $movements;
        }

        $loanSource = \SoftNova\Services\ProductLoan::SOURCE;
        $seenIds = [];
        foreach ($movements as $ex) {
            $mid = (int)($ex['id'] ?? 0);
            if ($mid > 0) {
                $seenIds[$mid] = true;
            }
        }

        $subsByDebt = []; // debt_id => list of substitute labels

        // 1) Entradas loan_return en OTRO SKU que cierran deuda de ESTE producto.
        try {
            $relatedSubs = $this->query(
                "SELECT sm.*, u.name AS user_name,
                        p.code AS related_product_code,
                        p.name AS related_product_name,
                        p.barcode AS related_product_barcode,
                        d.id AS debt_id
                 FROM stock_movements sm
                 INNER JOIN product_stock_debts d ON d.id = sm.reference_id
                 LEFT JOIN users u ON u.id = sm.user_id
                 LEFT JOIN products p ON p.id = sm.product_id
                 WHERE sm.reference_type = 'loan_return'
                   AND d.product_id = ?
                   AND sm.product_id <> ?
                   AND LOWER(COALESCE(d.source, 'sale')) = ?
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT 80",
                [$productId, $productId, $loanSource]
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            $relatedSubs = [];
        }

        foreach ($relatedSubs as $rel) {
            $mid = (int)($rel['id'] ?? 0);
            $debtId = (int)($rel['debt_id'] ?? $rel['reference_id'] ?? 0);
            $relCode = trim((string)($rel['related_product_code'] ?? ''));
            $relName = trim((string)($rel['related_product_name'] ?? ''));
            $relLabel = trim(($relCode !== '' ? $relCode . ' · ' : '') . ($relName !== '' ? $relName : ('#' . (int)($rel['product_id'] ?? 0))));
            if ($debtId > 0) {
                $subsByDebt[$debtId][] = $relLabel;
            }
            if ($mid > 0 && isset($seenIds[$mid])) {
                continue;
            }
            $rel['related_substitution'] = 1;
            $rel['related_loan_origin'] = 0;
            $rel['affects_local_stock'] = 0;
            $rel['display_at'] = \SoftNova\Services\StockService::formatMovementDisplayAt($rel);
            $rel['origin_label'] = 'Préstamo · cambio de referencia';
            $baseMotive = \SoftNova\Services\StockService::describeMovementMotive($rel);
            $rel['motive'] = 'Cambio: devolvió ' . $relLabel
                . ' (entrada en ese SKU, no repone stock de este producto)'
                . ($baseMotive !== '' ? (' · ' . $baseMotive) : '');
            $movements[] = $rel;
            if ($mid > 0) {
                $seenIds[$mid] = true;
            }
        }

        // Enriquecer salidas de préstamo locales con el resumen del cambio.
        foreach ($movements as &$m) {
            if ((string)($m['reference_type'] ?? '') !== 'loan') {
                continue;
            }
            if ((int)($m['related_loan_origin'] ?? 0) === 1) {
                continue;
            }
            $debtId = (int)($m['reference_id'] ?? 0);
            if ($debtId <= 0 || empty($subsByDebt[$debtId])) {
                continue;
            }
            $labels = array_values(array_unique($subsByDebt[$debtId]));
            $m['has_substitution'] = 1;
            $m['origin_label'] = 'Préstamo · con cambio';
            $extra = 'Cambio posterior: devolvió ' . implode(' / ', $labels);
            $motive = (string)($m['motive'] ?? '');
            if ($motive === '' || stripos($motive, 'Cambio') === false) {
                $m['motive'] = ($motive !== '' ? ($motive . ' · ') : '') . $extra;
            }
        }
        unset($m);

        // 2) Si ESTE producto entró como sustituto, mostrar salida del SKU prestado.
        try {
            $relatedLoans = $this->query(
                "SELECT sm.*, u.name AS user_name,
                        p.code AS related_product_code,
                        p.name AS related_product_name,
                        p.barcode AS related_product_barcode
                 FROM stock_movements sm_ret
                 INNER JOIN product_stock_debts d ON d.id = sm_ret.reference_id
                 INNER JOIN stock_movements sm
                      ON sm.reference_type = 'loan'
                     AND sm.product_id = d.product_id
                     AND (
                          (d.movement_id IS NOT NULL AND d.movement_id > 0 AND sm.id = d.movement_id)
                          OR (sm.reference_id = d.id)
                     )
                 LEFT JOIN users u ON u.id = sm.user_id
                 LEFT JOIN products p ON p.id = d.product_id
                 WHERE sm_ret.reference_type = 'loan_return'
                   AND sm_ret.product_id = ?
                   AND d.product_id <> ?
                   AND LOWER(COALESCE(d.source, 'sale')) = ?
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT 80",
                [$productId, $productId, $loanSource]
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            // Fallback sin movement_id (instala viejos).
            try {
                $relatedLoans = $this->query(
                    "SELECT sm.*, u.name AS user_name,
                            p.code AS related_product_code,
                            p.name AS related_product_name,
                            p.barcode AS related_product_barcode
                     FROM stock_movements sm_ret
                     INNER JOIN product_stock_debts d ON d.id = sm_ret.reference_id
                     INNER JOIN stock_movements sm ON sm.reference_type = 'loan'
                          AND sm.reference_id = d.id
                          AND sm.product_id = d.product_id
                     LEFT JOIN users u ON u.id = sm.user_id
                     LEFT JOIN products p ON p.id = d.product_id
                     WHERE sm_ret.reference_type = 'loan_return'
                       AND sm_ret.product_id = ?
                       AND d.product_id <> ?
                       AND LOWER(COALESCE(d.source, 'sale')) = ?
                     ORDER BY sm.created_at DESC, sm.id DESC
                     LIMIT 80",
                    [$productId, $productId, $loanSource]
                )->fetchAll() ?: [];
            } catch (\Throwable $e2) {
                $relatedLoans = [];
            }
        }

        foreach ($relatedLoans as $rel) {
            $mid = (int)($rel['id'] ?? 0);
            if ($mid > 0 && isset($seenIds[$mid])) {
                continue;
            }
            $relCode = trim((string)($rel['related_product_code'] ?? ''));
            $relName = trim((string)($rel['related_product_name'] ?? ''));
            $relLabel = trim(($relCode !== '' ? $relCode . ' · ' : '') . ($relName !== '' ? $relName : ('#' . (int)($rel['product_id'] ?? 0))));
            $rel['related_substitution'] = 0;
            $rel['related_loan_origin'] = 1;
            $rel['affects_local_stock'] = 0;
            $rel['display_at'] = \SoftNova\Services\StockService::formatMovementDisplayAt($rel);
            $rel['origin_label'] = 'Préstamo · origen del cambio';
            $baseMotive = \SoftNova\Services\StockService::describeMovementMotive($rel);
            $rel['motive'] = 'Cambio: este SKU se recibió por el préstamo de ' . $relLabel
                . ($baseMotive !== '' ? (' · ' . $baseMotive) : '');
            $movements[] = $rel;
            if ($mid > 0) {
                $seenIds[$mid] = true;
            }
        }

        return $movements;
    }

    /** Etiqueta corta de origen para el modal de detalle del producto. */
    /**
     * Costos del producto para la ficha: promedio ponderado vigente (purchase_price),
     * último costo de entrada por compra y promedio de compras (histórico y 90 días).
     * Excluye entradas de OC anuladas.
     *
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function productCostSummary(int $productId, array $product): array
    {
        $avg = round((float)($product['purchase_price'] ?? 0), 2);
        $stock = (int)($product['stock'] ?? 0);
        $out = [
            'avg_cost' => $avg,
            'stock_value' => round(max(0, $stock) * $avg, 2),
            'last_cost' => null,
            'last_cost_date' => null,
            'last_cost_ref' => null,
            'purchase_avg' => null,
            'purchase_units' => 0,
            'purchase_count' => 0,
            'purchase_min' => null,
            'purchase_max' => null,
            'purchase_avg_90' => null,
            'purchase_units_90' => 0,
        ];

        $where = "sm.product_id = ?
                  AND sm.type = 'in'
                  AND sm.reference_type IN ('purchase_order', 'purchase')
                  AND sm.quantity > 0
                  AND COALESCE(sm.unit_cost, 0) > 0
                  AND (sm.reference_type <> 'purchase_order' OR COALESCE(po.status, '') <> 'cancelled')";

        try {
            $agg = $this->query(
                "SELECT COUNT(*) AS n,
                        SUM(sm.quantity) AS units,
                        SUM(sm.quantity * sm.unit_cost) AS value,
                        MIN(sm.unit_cost) AS min_cost,
                        MAX(sm.unit_cost) AS max_cost,
                        SUM(CASE WHEN COALESCE(sm.movement_date, DATE(sm.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                 THEN sm.quantity ELSE 0 END) AS units_90,
                        SUM(CASE WHEN COALESCE(sm.movement_date, DATE(sm.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                 THEN sm.quantity * sm.unit_cost ELSE 0 END) AS value_90
                 FROM stock_movements sm
                 LEFT JOIN purchase_orders po ON po.id = sm.reference_id AND sm.reference_type = 'purchase_order'
                 WHERE {$where}",
                [$productId]
            )->fetch();
            if ($agg && (int)$agg['units'] > 0) {
                $out['purchase_count'] = (int)$agg['n'];
                $out['purchase_units'] = (int)$agg['units'];
                $out['purchase_avg'] = round((float)$agg['value'] / (int)$agg['units'], 2);
                $out['purchase_min'] = round((float)$agg['min_cost'], 2);
                $out['purchase_max'] = round((float)$agg['max_cost'], 2);
                if ((int)$agg['units_90'] > 0) {
                    $out['purchase_units_90'] = (int)$agg['units_90'];
                    $out['purchase_avg_90'] = round((float)$agg['value_90'] / (int)$agg['units_90'], 2);
                }
            }

            $last = $this->query(
                "SELECT sm.unit_cost, COALESCE(sm.movement_date, DATE(sm.created_at)) AS d,
                        po.order_number, po.invoice_number
                 FROM stock_movements sm
                 LEFT JOIN purchase_orders po ON po.id = sm.reference_id AND sm.reference_type = 'purchase_order'
                 WHERE {$where}
                 ORDER BY COALESCE(sm.movement_date, DATE(sm.created_at)) DESC, sm.id DESC
                 LIMIT 1",
                [$productId]
            )->fetch();
            if ($last) {
                $out['last_cost'] = round((float)$last['unit_cost'], 2);
                $out['last_cost_date'] = (string)$last['d'];
                $ref = trim((string)($last['order_number'] ?? ''));
                $inv = trim((string)($last['invoice_number'] ?? ''));
                $out['last_cost_ref'] = trim($ref . ($inv !== '' ? ' / Fact. ' . $inv : '')) ?: null;
            }
        } catch (\Throwable $e) {
            // Ficha sin resumen de compras si la consulta falla.
        }

        return $out;
    }

    /**
     * Historial de precio de costo: filas registradas (costo anterior → nuevo) y, para compras
     * previas al registro, las entradas de compra del kardex (solo costo de entrada).
     *
     * @return list<array<string,mixed>>
     */
    private function productCostHistoryRows(int $productId): array
    {
        $rows = [];
        $knownMovements = [];
        $oldestLogged = null;
        foreach (\SoftNova\Services\ProductCostHistory::forProduct($this->db, $productId, 150) as $h) {
            if (!empty($h['movement_id'])) {
                $knownMovements[(int)$h['movement_id']] = true;
            }
            $created = (string)($h['created_at'] ?? '');
            if ($created !== '' && ($oldestLogged === null || $created < $oldestLogged)) {
                $oldestLogged = $created;
            }
            $rows[] = [
                'date' => (string)($h['effective_date'] ?: substr($created, 0, 10)),
                'at' => $created,
                'source' => (string)($h['source'] ?? ''),
                'qty' => $h['qty'] !== null ? (int)$h['qty'] : null,
                'unit_cost_in' => $h['unit_cost_in'] !== null ? (float)$h['unit_cost_in'] : null,
                'old_cost' => $h['old_cost'] !== null ? (float)$h['old_cost'] : null,
                'new_cost' => (float)$h['new_cost'],
                'stock_after' => $h['stock_after'] !== null ? (int)$h['stock_after'] : null,
                'ref' => trim((string)($h['order_number'] ?? '') . (!empty($h['invoice_number']) ? ' / Fact. ' . $h['invoice_number'] : '')),
                'supplier' => (string)($h['supplier_name'] ?? ''),
                'note' => (string)($h['note'] ?? ''),
                'user' => (string)($h['user_name'] ?? ''),
                'logged' => 1,
            ];
        }

        try {
            $params = [$productId];
            $extra = '';
            if ($oldestLogged !== null) {
                $extra = ' AND sm.created_at < ?';
                $params[] = $oldestLogged;
            }
            $legacy = $this->query(
                "SELECT sm.id, sm.quantity, sm.unit_cost, sm.stock_after, sm.created_at,
                        COALESCE(sm.movement_date, DATE(sm.created_at)) AS d,
                        po.order_number, po.invoice_number, su.name AS supplier_name, u.name AS user_name
                 FROM stock_movements sm
                 LEFT JOIN purchase_orders po ON po.id = sm.reference_id AND sm.reference_type = 'purchase_order'
                 LEFT JOIN suppliers su ON su.id = COALESCE(po.supplier_id, sm.supplier_id)
                 LEFT JOIN users u ON u.id = sm.user_id
                 WHERE sm.product_id = ?
                   AND sm.type = 'in'
                   AND sm.reference_type IN ('purchase_order', 'purchase')
                   AND sm.quantity > 0
                   AND COALESCE(sm.unit_cost, 0) > 0
                   AND (sm.reference_type <> 'purchase_order' OR COALESCE(po.status, '') <> 'cancelled')
                   {$extra}
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT 150",
                $params
            )->fetchAll();
            foreach ($legacy as $m) {
                if (isset($knownMovements[(int)$m['id']])) {
                    continue;
                }
                $rows[] = [
                    'date' => (string)$m['d'],
                    'at' => (string)$m['created_at'],
                    'source' => 'purchase',
                    'qty' => (int)$m['quantity'],
                    'unit_cost_in' => (float)$m['unit_cost'],
                    'old_cost' => null,
                    'new_cost' => null,
                    'stock_after' => $m['stock_after'] !== null ? (int)$m['stock_after'] : null,
                    'ref' => trim((string)($m['order_number'] ?? '') . (!empty($m['invoice_number']) ? ' / Fact. ' . $m['invoice_number'] : '')),
                    'supplier' => (string)($m['supplier_name'] ?? ''),
                    'note' => '',
                    'user' => (string)($m['user_name'] ?? ''),
                    'logged' => 0,
                ];
            }
        } catch (\Throwable $e) {
            // Solo historial registrado.
        }

        usort($rows, static function ($a, $b) {
            $c = strcmp((string)$b['date'], (string)$a['date']);
            return $c !== 0 ? $c : strcmp((string)$b['at'], (string)$a['at']);
        });

        return array_slice($rows, 0, 150);
    }

    private function movementOriginLabel(array $m): string
    {
        $ref = (string)($m['reference_type'] ?? '');
        $base = match ($ref) {
            'purchase_order', 'purchase' => 'Compra / factura',
            'purchase_cancel' => 'Anulación compra',
            'purchase_credit_note' => 'NC compra',
            'csv_import' => 'Importación CSV',
            'csv_export' => 'Exportación CSV',
            'sale' => 'Venta',
            'return', 'sale_credit_note' => 'Devolución venta',
            'opening' => 'Inventario inicial',
            'adjustment' => 'Ajuste manual',
            'inventory_exit' => 'Salida inventario',
            'loan', 'loan_return', 'loan_received', 'loan_received_return', 'loan_received_settle' => 'Préstamo',
            'consignment_in', 'consignment_return' => 'Consignación',
            'status_change' => 'Cambio estado',
            'price_change' => 'Cambio precio',
            default => $ref !== '' ? $ref : 'Movimiento',
        };

        $bits = [$base];
        $oc = trim((string)($m['purchase_order_number'] ?? ''));
        if ($oc !== '') {
            $bits[] = $oc;
        }
        $inv = trim((string)($m['purchase_invoice_number'] ?? ''));
        if ($inv !== '') {
            $bits[] = 'Fact. ' . $inv;
        }
        $sale = trim((string)($m['sale_document_number'] ?? ''));
        if ($sale !== '') {
            $bits[] = $sale;
        }
        if (!empty($m['synthetic'])) {
            $bits[] = 'reconstruido';
        }

        return implode(' · ', $bits);
    }

    /** Ajuste, bloqueo, desbloqueo o baja de un lote. */
    private function lotAction(string $mode): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario?action=vencimientos')) {
            return;
        }
        $lotId = (int)$this->request->post('lot_id');
        if ($lotId <= 0) {
            $this->respond(false, 'Lote no válido', '/app/inventario?action=vencimientos');
            return;
        }

        try {
            $lots = new LotService($this->db);
            $notes = substr(trim((string)$this->request->post('notes', '')), 0, 255);

            if ($mode === 'block') {
                $ok = $lots->blockLot($lotId, $notes !== '' ? $notes : 'Bloqueado (cuarentena)');
                $this->respond($ok, $ok ? 'Lote bloqueado: no se venderá (sigue en bodega hasta baja)' : 'Lote no encontrado', '/app/inventario?action=vencimientos');
                return;
            }

            if ($mode === 'unblock') {
                $ok = $lots->unblockLot($lotId, $notes !== '' ? $notes : 'Desbloqueado');
                $this->respond($ok, $ok ? 'Lote desbloqueado' : 'No se pudo desbloquear (vacío o inexistente)', '/app/inventario?action=vencimientos');
                return;
            }

            if ($mode === 'writeoff') {
                $res = $this->stock->writeOffLotWithStock(
                    $lotId,
                    $notes !== '' ? $notes : 'Baja por vencimiento'
                );
                if (!empty($res['movement_id'])) {
                    $this->postStockAccounting((int)$res['movement_id']);
                }
                $this->respond(!empty($res['ok']), (string)($res['message'] ?? 'Error'), '/app/inventario?action=vencimientos');
                return;
            }

            $res = $this->stock->adjustLotWithStock(
                $lotId,
                (int)$this->request->post('quantity', 0),
                $notes
            );
            if (!empty($res['movement_id'])) {
                $this->postStockAccounting((int)$res['movement_id']);
            }
            $this->respond(!empty($res['ok']), (string)($res['message'] ?? 'Error'), '/app/inventario?action=vencimientos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario?action=vencimientos');
        }
    }

    /** Repara descuadre stock ↔ lotes (sembrar apertura o ajustar stock). */
    private function lotRepair(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario?action=vencimientos')) {
            return;
        }
        $productId = (int)$this->request->post('product_id');
        $mode = trim((string)$this->request->post('mode', 'seed'));
        if ($productId <= 0) {
            $this->respond(false, 'Producto no válido', '/app/inventario?action=vencimientos');
            return;
        }
        if (!in_array($mode, ['seed', 'stock'], true)) {
            $mode = 'seed';
        }
        try {
            $res = $this->stock->repairLotMismatch($productId, $mode);
            $this->respond(!empty($res['ok']), (string)($res['message'] ?? 'Error'), '/app/inventario?action=vencimientos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario?action=vencimientos');
        }
    }

    /** Proveedores de un producto con su código y costo, para el modal de detalle. */
    private function productSuppliers(): void
    {
        $productId = (int)$this->request->get('id', 0);
        if ($productId <= 0) {
            $this->json(['success' => false, 'message' => 'Producto no válido']);
            return;
        }

        try {
            $service = new ProductSupplierService($this->db);
            $this->json([
                'success' => true,
                'suppliers' => $service->suppliersFor($productId),
                'catalog' => $this->query(
                    "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
                )->fetchAll(),
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function supplierLink(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $productId = (int)$this->request->post('product_id');
        $supplierId = (int)$this->request->post('supplier_id');
        if ($productId <= 0 || $supplierId <= 0) {
            $this->respond(false, 'Seleccione producto y proveedor', '/app/inventario');
            return;
        }

        try {
            (new ProductSupplierService($this->db))->link($productId, $supplierId, [
                'supplier_sku' => (string)$this->request->post('supplier_sku', ''),
                'supplier_product_name' => (string)$this->request->post('supplier_product_name', ''),
                'last_cost' => \SoftNova\Core\parse_money($this->request->post('last_cost', 0)),
                'lead_time_days' => (int)$this->request->post('lead_time_days', 0),
                'min_order_qty' => (int)$this->request->post('min_order_qty', 1),
                'is_primary' => (string)$this->request->post('is_primary', '') !== '',
                'notes' => (string)$this->request->post('notes', ''),
            ]);
            $this->respond(true, 'Proveedor vinculado al producto', '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
        }
    }

    private function supplierUnlink(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        try {
            (new ProductSupplierService($this->db))->unlink(
                (int)$this->request->post('product_id'),
                (int)$this->request->post('supplier_id')
            );
            $this->respond(true, 'Proveedor desvinculado', '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
        }
    }

    /** Panel de control de vencimientos: lotes vencidos, por vencer y descuadres. */
    private function expiryPanel(): void
    {
        $days = (int)$this->request->get('days', 0);
        $lots = new LotService($this->db);

        $this->view('tenant.vencimientos', $this->tenantViewData([
            'summary' => $lots->expirySummary(),
            'lots' => $lots->expiringLots($days > 0 ? $days : null, 200),
            'mismatches' => $lots->lotStockMismatches(30),
            'daysFilter' => $days,
        ]));
    }

    private function editMovementDate(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $movementId = (int)$this->request->post('movement_id');
        $date = trim((string)$this->request->post('movement_date', ''));
        try {
            $this->stock->updateMovementDate($movementId, $date);
            $this->respond(true, 'Fecha de movimiento actualizada', '/app/inventario?action=traceability');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario?action=traceability');
        }
    }

    private function traceability(): void
    {
        // Paginar por movimiento (grupo), no por producto/línea.
        $perPage = 20;
        $filters = [
            'q' => trim((string)$this->request->get('q', '')),
            'type' => trim((string)$this->request->get('type', '')),
            'reference_type' => trim((string)$this->request->get('reference_type', '')),
            'from' => $this->normalizeTraceDate((string)$this->request->get('from', '')),
            'to' => $this->normalizeTraceDate((string)$this->request->get('to', '')),
            'product_id' => (int)$this->request->get('product_id', 0) ?: null,
        ];

        $probe = $this->stock->listMovementGroups($filters, $perPage, 0);
        $pagination = $this->paginate($probe['total'], $perPage);
        $result = $pagination['offset'] > 0
            ? $this->stock->listMovementGroups($filters, $perPage, $pagination['offset'])
            : $probe;

        $this->view('tenant.trazabilidad', $this->tenantViewData([
            'movementGroups' => $result['groups'],
            'movementsLineTotal' => (int)($result['line_total'] ?? 0),
            'movementsTruncated' => !empty($result['truncated']),
            'filters' => $filters,
            'pagination' => $pagination,
            'kardex' => $this->stock->valuedKardex(null, 40),
            'costAlerts' => $this->stock->costVariationAlerts(5.0, 20),
            'purchaseProjection' => $this->stock->purchaseProjection(30, 20),
            'pendingAccounting' => $this->stock->pendingAccountingCount(),
        ]));
    }

    /** Normaliza fechas de filtro (Y-m-d o d/m/Y). Inválidas → vacío (no filtran). */
    private function normalizeTraceDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : '';
        }
        foreach (['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                $errors = \DateTimeImmutable::getLastErrors();
                if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                    continue;
                }
                return $dt->format('Y-m-d');
            }
        }
        return '';
    }

    private function importCatalog(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario?integrations=1')) {
            return;
        }
        $provider = (string)$this->request->post('provider', '');
        $limit = max(1, min(500, (int)$this->request->post('limit', 100)));
        $back = '/app/inventario?integrations=1&catalog=' . urlencode($provider);
        try {
            $result = (new CatalogSyncService($this->db))->import($provider, $limit);
            $msg = sprintf(
                'Importación %s: %d nuevos, %d actualizados, %d sin tocar stock, %d errores (%d leídos)',
                $provider,
                $result['created'] ?? 0,
                $result['updated'] ?? 0,
                $result['skipped_stock'] ?? 0,
                $result['errors'] ?? 0,
                $result['fetched'] ?? 0
            );
            $this->respond(($result['errors'] ?? 0) === 0, $msg, $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }

    private function saveCatalogIntegration(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario?integrations=1')) {
            return;
        }
        $provider = (string)$this->request->post('provider', '');
        $back = '/app/inventario?integrations=1&catalog=' . urlencode($provider);
        try {
            $svc = new CatalogSyncService($this->db);
            $svc->saveProvider($provider, [
                'enabled' => $this->request->post('enabled', '0'),
                'store_url' => $this->request->post('store_url', ''),
                'consumer_key' => $this->request->post('consumer_key', ''),
                'consumer_secret' => $this->request->post('consumer_secret', ''),
                'access_token' => $this->request->post('access_token', ''),
                'refresh_token' => $this->request->post('refresh_token', ''),
                'client_id' => $this->request->post('client_id', ''),
                'client_secret' => $this->request->post('client_secret', ''),
                'user_id' => $this->request->post('user_id', ''),
                'site_id' => $this->request->post('site_id', 'MCO'),
                'base_url' => $this->request->post('base_url', ''),
                'stock_authority' => $this->request->post('stock_authority', 'create_only'),
            ]);
            $this->respond(true, 'Integración de catálogo guardada', $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }

    private function testCatalogIntegration(): void
    {
        $provider = (string)$this->request->get('provider', '');
        $this->json((new CatalogSyncService($this->db))->test($provider));
    }

    private function mlOAuthStart(): void
    {
        $svc = new CatalogSyncService($this->db);
        $ml = $svc->mercadoLibre();
        $back = '/app/inventario?integrations=1&catalog=mercadolibre';
        if (!$ml) {
            $this->respond(false, 'Conector ML no disponible', $back);
            return;
        }
        $st = $ml->status();
        if (empty($st['oauth_ready'])) {
            $this->respond(false, 'Guarde primero Client ID y Client Secret', $back);
            return;
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['ml_oauth_state'] = $state;
        $redirect = \SoftNova\Core\route('app/inventario') . '?action=ml-oauth-callback';
        header('Location: ' . $ml->authorizationUrl($redirect, $state));
        exit;
    }

    private function mlOAuthCallback(): void
    {
        $back = '/app/inventario?integrations=1&catalog=mercadolibre';
        $error = (string)$this->request->get('error', '');
        if ($error !== '') {
            $this->respond(false, 'OAuth ML cancelado: ' . $error, $back);
            return;
        }
        $state = (string)$this->request->get('state', '');
        $expected = (string)($_SESSION['ml_oauth_state'] ?? '');
        unset($_SESSION['ml_oauth_state']);
        if ($expected === '' || !hash_equals($expected, $state)) {
            $this->respond(false, 'Estado OAuth inválido. Intente conectar de nuevo.', $back);
            return;
        }
        $code = (string)$this->request->get('code', '');
        if ($code === '') {
            $this->respond(false, 'Sin código de autorización', $back);
            return;
        }
        try {
            $svc = new CatalogSyncService($this->db);
            $ml = $svc->mercadoLibre();
            if (!$ml) {
                throw new \RuntimeException('Conector ML no disponible');
            }
            $redirect = \SoftNova\Core\route('app/inventario') . '?action=ml-oauth-callback';
            $ml->exchangeToken('authorization_code', [
                'code' => $code,
                'redirect_uri' => $redirect,
            ]);
            $svc->settings()->set('mercadolibre', 'enabled', '1', false);
            $this->respond(true, 'Mercado Libre conectado (token + refresh guardados)', $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }
    
    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        
        $name = trim($this->request->post('name'));
        $productType = $this->request->post('product_type', 'product');
        $stock = $productType === 'service' ? 0 : (int)$this->request->post('stock', 0);
        
        if (empty($name)) {
            $this->respond(false, 'El nombre es requerido', '/app/inventario');
            return;
        }
        
        $image = $this->uploadImage();
        if ($image === false) {
            $this->respond(false, 'Imagen invalida. Use JPG/PNG/GIF/WEBP max 2MB', '/app/inventario');
            return;
        }
        
        $code = $this->normalizeProductCodeInput((string)$this->request->post('code', ''));
        if ($code === false) {
            $this->respond(false, 'Código / SKU inválido. Use texto (no notación científica) y máximo 255 caracteres.', '/app/inventario');
            return;
        }
        if ($code === '') {
            $code = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        }
        $barcode = $this->normalizeBarcodeInput((string)$this->request->post('barcode', ''));
        if ($barcode === false) {
            $this->respond(false, 'Código de barras EAN inválido (máx. 64 caracteres).', '/app/inventario');
            return;
        }

        // Mismo SKU/EAN + otro vencimiento = remesa del producto existente, no un producto nuevo.
        $existing = $this->findCatalogProductByCodeOrBarcode($code, $barcode);
        if ($existing) {
            $matchBy = strcasecmp((string)$existing['code'], $code) === 0 ? 'SKU' : 'EAN';
            $lotInputEarly = $this->lotInput();
            $this->respond(
                false,
                "Ya existe el producto «{$existing['name']}» con ese {$matchBy}. "
                . 'No cree otro ítem: use el botón «Lote N» en el producto existente e indique lote/vencimiento.',
                '/app/inventario',
                [
                    'suggest_add_lot' => true,
                    'existing_product' => [
                        'id' => (int)$existing['id'],
                        'name' => (string)$existing['name'],
                        'code' => (string)$existing['code'],
                        'barcode' => (string)($existing['barcode'] ?? ''),
                        'track_lots' => (int)($existing['track_lots'] ?? 0),
                        'stock' => (int)($existing['stock'] ?? 0),
                    ],
                    'lot_prefill' => [
                        'quantity' => $productType === 'service' ? 0 : (int)$this->request->post('stock', 0),
                        'expiry_date' => $lotInputEarly['expiry_date'],
                        'lot_code' => $lotInputEarly['lot_code'],
                        'purchase_price' => \SoftNova\Core\parse_money($this->request->post('purchase_price', 0)),
                    ],
                ]
            );
            return;
        }
        
        $lotInput = $this->lotInput();
        $trackLots = $productType === 'product'
            && ($lotInput['track_lots'] || $lotInput['expiry_date'] !== null);
        $location = $this->normalizeLocationInput((string)$this->request->post('location', ''));

        SupplyChainSchema::ensure($this->db);
        $this->ensureWholesalePriceColumn();

        $wholesale = round(max(0, \SoftNova\Core\parse_money($this->request->post('wholesale_price', 0))), 2);
        $taxRate = $this->resolveProductTaxRateFromRequest();

        try {
            $this->query(
                "INSERT INTO products
                    (code, barcode, name, product_type, description, notes, category_id, purchase_price, sale_price, tax_rate, wholesale_price,
                     stock, min_stock, unit, image, track_lots, expiry_alert_days, max_stock, location, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $code,
                    $barcode !== '' ? $barcode : null,
                    $name,
                    $productType,
                    $this->request->post('description'),
                    trim((string)$this->request->post('notes', '')) ?: null,
                    $this->request->post('category_id') ?: null,
                    round(max(0, \SoftNova\Core\parse_money($this->request->post('purchase_price', 0))), 2),
                    round(max(0, \SoftNova\Core\parse_money($this->request->post('sale_price', 0))), 2),
                    $taxRate,
                    $wholesale,
                    $stock,
                    $this->request->post('min_stock', 5),
                    $this->request->post('unit', 'UND'),
                    $image,
                    $trackLots ? 1 : 0,
                    $lotInput['alert_days'],
                    $this->maxStockInput(),
                    $location,
                    $_SESSION['tenant_user_id'],
                ]
            );
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $again = $this->findCatalogProductByCodeOrBarcode($code, $barcode);
                $this->respond(
                    false,
                    $again
                        ? "El SKU/EAN ya existe («{$again['name']}»). Use «Lote N» en ese producto para otro vencimiento."
                        : 'El SKU ya está registrado. Para otro vencimiento use «Lote N», no un producto nuevo.',
                    '/app/inventario',
                    $again ? [
                        'suggest_add_lot' => true,
                        'existing_product' => [
                            'id' => (int)$again['id'],
                            'name' => (string)$again['name'],
                            'code' => (string)$again['code'],
                            'barcode' => (string)($again['barcode'] ?? ''),
                            'track_lots' => (int)($again['track_lots'] ?? 0),
                            'stock' => (int)($again['stock'] ?? 0),
                        ],
                    ] : []
                );
                return;
            }
            throw $e;
        }
        
        $newId = (int)$this->db->lastInsertId();
        
        if ($stock > 0 && $productType === 'product') {
            $unitCost = \SoftNova\Core\parse_money($this->request->post('purchase_price', 0));
            $movementId = $this->stock->addMovement(
                $newId,
                'in',
                $stock,
                'opening',
                null,
                \SoftNova\Services\StockService::buildStockMotive(
                    'new_product',
                    $stock,
                    0,
                    $stock,
                    'Alta de producto en inventario (use Compras para entradas con proveedor)'
                ),
                null,
                $unitCost,
                $stock,
                null,
                'equity'
            );
            $this->postStockAccounting($movementId);
            if ($trackLots) {
                $this->registerLot($newId, $stock, $unitCost, $movementId, $lotInput);
            }
        }

        $this->syncProductSupplierFromRequest($newId, true);
        
        $this->respond(true, ($productType === 'service' ? 'Servicio' : 'Producto') . ' creado: ' . $name, '/app/inventario');
    }
    
    private function edit(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $name = trim($this->request->post('name'));
        if (empty($name)) {
            $this->respond(false, 'El nombre es requerido', '/app/inventario');
            return;
        }
        
        $oldProduct = $this->query("SELECT * FROM products WHERE id = ?", [$id])->fetch();
        if (!$oldProduct) {
            $this->respond(false, 'Producto no encontrado', '/app/inventario');
            return;
        }

        $newStatus = strtolower(trim((string)$this->request->post('status', 'active')));
        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            $newStatus = 'active';
        }
        $oldStatus = strtolower(trim((string)($oldProduct['status'] ?? 'active')));
        $statusReason = trim((string)$this->request->post('status_change_reason', ''));
        if ($newStatus !== $oldStatus) {
            if (mb_strlen($statusReason) < 5) {
                $this->respond(
                    false,
                    'Al cambiar el estado del producto (activo/inactivo) debe indicar una razón (mín. 5 caracteres). Quedará en Trazabilidad.',
                    '/app/inventario'
                );
                return;
            }
        }
        
        $productType = $this->request->post('product_type', $oldProduct['product_type'] ?? 'product');
        $oldStock = (int)($oldProduct['stock'] ?? 0);
        $newStock = $productType === 'service' ? 0 : (int)$this->request->post('stock', 0);
        $stockChanging = $productType === 'product' && $newStock !== $oldStock;
        $stockAdjNote = trim((string)$this->request->post('stock_adjustment_note', ''));

        // Validar ANTES de escribir stock (evita saldo cambiado sin movimiento/trazabilidad).
        if ($stockChanging) {
            if (mb_strlen($stockAdjNote) < 5) {
                $this->respond(
                    false,
                    'Al cambiar el stock manualmente debe indicar una justificación (mín. 5 caracteres). Quedará en Trazabilidad.',
                    '/app/inventario'
                );
                return;
            }
            if ($oldStock < 0 && $newStock >= 0) {
                $confirm = (string)$this->request->post('confirm_negative_cover', '');
                if ($confirm !== '1') {
                    $this->respond(
                        false,
                        'Este producto está en stock negativo (' . $oldStock . '). '
                        . 'Para dejarlo en ' . $newStock . ' debe confirmar que es una '
                        . 'corrección de inventario (descuadre / error humano), no una compra. '
                        . 'Ejemplo: se vendieron unidades que sí existían → ponga el conteo físico real; '
                        . 'la alerta de Caja se quita al cubrir la deuda. '
                        . 'Use «Lote N» o Compras solo si llegó mercancía nueva.',
                        '/app/inventario'
                    );
                    return;
                }
            }
        }
        
        $image = $this->uploadImage();
        if ($image === false) {
            $this->respond(false, 'Imagen invalida. Use JPG/PNG/GIF/WEBP max 2MB', '/app/inventario');
            return;
        }

        $code = $this->normalizeProductCodeInput((string)$this->request->post('code', ''));
        if ($code === false) {
            $this->respond(false, 'Código / SKU inválido. Use texto (no notación científica) y máximo 255 caracteres.', '/app/inventario');
            return;
        }
        // SKU opcional: si queda vacío, conservar el actual o generar uno.
        if ($code === '') {
            $prev = trim((string)($oldProduct['code'] ?? ''));
            $code = $prev !== ''
                ? $prev
                : ('SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
        }
        $barcode = $this->normalizeBarcodeInput((string)$this->request->post('barcode', ''));
        if ($barcode === false) {
            $this->respond(false, 'Código de barras EAN inválido (máx. 64 caracteres).', '/app/inventario');
            return;
        }
        if ($barcode !== '') {
            $dup = $this->query(
                "SELECT id, name FROM products WHERE barcode = ? AND id <> ? LIMIT 1",
                [$barcode, $id]
            )->fetch();
            if ($dup) {
                $this->respond(
                    false,
                    'El EAN ya está asignado al producto «' . ($dup['name'] ?? ('#' . $dup['id'])) . '».',
                    '/app/inventario'
                );
                return;
            }
        }
        
        $lotInput = $this->lotInput();
        $location = $this->normalizeLocationInput((string)$this->request->post('location', ''));
        SupplyChainSchema::ensure($this->db);
        $this->ensureWholesalePriceColumn();
        $wholesale = round(max(0, \SoftNova\Core\parse_money($this->request->post('wholesale_price', 0))), 2);
        $taxRate = $this->resolveProductTaxRateFromRequest();
        $sql = "UPDATE products SET code = ?, barcode = ?, name = ?, product_type = ?, description = ?, notes = ?, category_id = ?,
                purchase_price = ?, sale_price = ?, tax_rate = ?, wholesale_price = ?, stock = ?, min_stock = ?, unit = ?, status = ?,
                track_lots = ?, expiry_alert_days = ?, max_stock = ?, location = ?";
        $params = [
            $code,
            $barcode !== '' ? $barcode : null,
            $name,
            $productType,
            $this->request->post('description'),
            trim((string)$this->request->post('notes', '')) ?: null,
            $this->request->post('category_id') ?: null,
            round(max(0, \SoftNova\Core\parse_money($this->request->post('purchase_price', 0))), 2),
            round(max(0, \SoftNova\Core\parse_money($this->request->post('sale_price', 0))), 2),
            $taxRate,
            $wholesale,
            // Si el stock cambia, se aplica después con adjustTo (movimiento + cobertura de deuda).
            $stockChanging ? $oldStock : $newStock,
            $this->request->post('min_stock', 5),
            $this->request->post('unit', 'UND'),
            $newStatus,
            ($productType === 'product' && $lotInput['track_lots']) ? 1 : 0,
            $lotInput['alert_days'],
            $this->maxStockInput(),
            $location,
        ];
        
        if ($image) {
            $sql .= ", image = ?";
            $params[] = $image;
        }
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $this->query($sql, $params);

        $oldCostVal = round((float)($oldProduct['purchase_price'] ?? 0), 2);
        $newCostVal = round(max(0, \SoftNova\Core\parse_money($this->request->post('purchase_price', 0))), 2);
        if (abs($newCostVal - $oldCostVal) > 0.009) {
            \SoftNova\Services\ProductCostHistory::record($this->db, (int)$id, $oldCostVal, $newCostVal, [
                'source' => 'manual',
                'reference_type' => 'product_edit',
                'reference_id' => (int)$id,
                'stock_before' => $oldStock,
                'stock_after' => $oldStock,
                'note' => 'Edición manual del precio de costo',
            ]);
        }
        
        $wasTracking = !empty($oldProduct['track_lots']);
        $nowTracking = $productType === 'product' && $lotInput['track_lots'];

        $editMsg = 'Actualizado';
        if ($stockChanging) {
            $debtCover = null;
            $isInventoryCorrection = ($oldStock < 0 && $newStock >= 0);
            $adjNotes = $isInventoryCorrection
                ? ('Corrección de inventario (descuadre/error): ' . $stockAdjNote)
                : ('Justificación: ' . $stockAdjNote);
            $movementId = $this->stock->adjustTo(
                $id,
                $newStock,
                $adjNotes,
                \SoftNova\Core\parse_money($this->request->post('purchase_price', $oldProduct['purchase_price'] ?? 0)),
                null,
                $debtCover
            );
            if ($movementId > 0) {
                $this->postStockAccounting($movementId);
            }
            $editMsg = $this->stock->formatDebtCoverAlert(
                abs($newStock - $oldStock),
                $debtCover,
                $oldStock,
                $newStock
            );
            // Si se activó control de lotes en la misma edición, el lote de apertura se siembra abajo.
            if ($nowTracking && $wasTracking) {
                $diff = $newStock - $oldStock;
                try {
                    $lots = new LotService($this->db);
                    // adjustTo ya registra lote en +; en − ya consume FEFO. Solo alinear si hace falta.
                    if ($diff < 0) {
                        $lots->trimPhysicalToStock($id, $newStock, $movementId);
                    }
                } catch (\Throwable $e) {
                    // no bloquear edición
                }
            }
        }

        // Al activar control de lotes con saldo previo: sembrar lote de apertura.
        if ($nowTracking && !$wasTracking) {
            try {
                (new LotService($this->db))->seedOpeningLot(
                    $id,
                    \SoftNova\Core\parse_money($this->request->post('purchase_price', $oldProduct['purchase_price'] ?? 0)),
                    $lotInput['expiry_date']
                );
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $this->syncProductSupplierFromRequest($id, false);

        if ($newStatus !== $oldStatus) {
            try {
                $this->stock->recordProductStatusChange($id, $oldStatus, $newStatus, $statusReason);
            } catch (\Throwable $e) {
                $this->respond(false, $e->getMessage(), '/app/inventario');
                return;
            }
        }
        
        $this->respond(true, $editMsg, '/app/inventario');
    }

    /** Vincula el proveedor principal desde el formulario de producto (crear/editar). */
    private function syncProductSupplierFromRequest(int $productId, bool $isCreate): void
    {
        if ($productId <= 0) {
            return;
        }
        $supplierId = (int)$this->request->post('supplier_id', 0);
        if ($supplierId <= 0) {
            return;
        }
        try {
            (new ProductSupplierService($this->db))->link($productId, $supplierId, [
                'supplier_sku' => (string)$this->request->post('supplier_sku', ''),
                'last_cost' => \SoftNova\Core\parse_money($this->request->post('purchase_price', 0)),
                'is_primary' => true,
            ]);
        } catch (\Throwable $e) {
            // No bloquear el alta/edición del producto si falla el vínculo.
            if ($isCreate) {
                // ignore
            }
        }
    }
    
    private function addStock(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $qty = (int)$this->request->post('quantity');
        $userNotes = trim((string)$this->request->post('notes', ''));
        $movementDate = trim((string)$this->request->post('movement_date', ''));
        if ($movementDate === '') {
            $movementDate = date('Y-m-d');
        }
        
        if ($qty <= 0) {
            $this->respond(false, 'La cantidad debe ser mayor a 0', '/app/inventario');
            return;
        }
        if (mb_strlen($userNotes) < 5) {
            $this->respond(
                false,
                'Indique una justificación/observación (mín. 5 caracteres) del ingreso manual de stock.',
                '/app/inventario'
            );
            return;
        }

        $productRow = $this->query(
            "SELECT stock, COALESCE(track_lots, 0) AS track_lots FROM products WHERE id = ?",
            [$id]
        )->fetch();
        if (!$productRow) {
            $this->respond(false, 'Producto no encontrado', '/app/inventario');
            return;
        }
        $stockBefore = (int)$productRow['stock'];
        $stockAfter = $stockBefore + $qty;
        $lotInput = $this->lotInput();
        if (!empty($productRow['track_lots']) && $lotInput['expiry_date'] === null) {
            $this->respond(
                false,
                'Este producto controla lotes: indique la fecha de vencimiento del Lote N que está ingresando.',
                '/app/inventario'
            );
            return;
        }
        // De negativo a ≥0 sin compra formal: exigir confirmación explícita.
        if ($stockBefore < 0 && $stockAfter >= 0) {
            $confirm = (string)$this->request->post('confirm_negative_cover', '');
            if ($confirm !== '1') {
                $this->respond(
                    false,
                    'Este producto está en stock negativo (' . $stockBefore . '). '
                    . 'Al agregar ' . $qty . ' quedará en ' . $stockAfter . '. '
                    . 'Esto es un ajuste manual (no reemplaza una compra). '
                    . 'Marque la confirmación en el formulario, o registre la mercancía en Compras. '
                    . 'La deuda de venta pendiente se cubrirá con el ingreso; el stock NO se vuelve a restar.',
                    '/app/inventario'
                );
                return;
            }
        }
        $notes = \SoftNova\Services\StockService::buildStockMotive(
            'adjustment',
            $qty,
            $stockBefore,
            $stockAfter,
            'Justificación: ' . $userNotes
        );
        
        $debtCover = null;
        $movementId = $this->stock->increase(
            $id,
            $qty,
            'adjustment',
            null,
            $notes,
            'in',
            null,
            null,
            'equity',
            false,
            $movementDate,
            [
                'lot_code' => $lotInput['lot_code'],
                'expiry_date' => $lotInput['expiry_date'],
                'alert_days' => $lotInput['alert_days'],
            ],
            $debtCover
        );
        $this->postStockAccounting($movementId);

        $mensaje = $this->stock->formatDebtCoverAlert($qty, $debtCover, $stockBefore, $stockAfter);
        if ($lotInput['expiry_date'] !== null) {
            $mensaje .= ' · Vence ' . $lotInput['expiry_date'];
        }
        $this->respond(true, $mensaje, '/app/inventario', [
            'debt_cover' => $debtCover,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
        ]);
    }

    /** Salida operativa (garantía, roto, devolución, vencimiento) como pérdida/gasto. */
    private function stockExit(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }

        $id = (int)$this->request->post('product_id', 0);
        $qty = (int)$this->request->post('quantity', 0);
        $reason = (string)$this->request->post('reason', '');
        $notes = trim((string)$this->request->post('notes', ''));
        $movementDate = trim((string)$this->request->post('movement_date', ''));

        try {
            $result = $this->stock->registerInventoryExit(
                $id,
                $qty,
                $reason,
                $notes,
                $movementDate !== '' ? $movementDate : null
            );
            $movementId = (int)$result['movement_id'];
            $this->postStockAccounting($movementId);

            $expenseId = $this->createExpenseFromInventoryExit(
                $movementId,
                (string)$result['reason'],
                $notes,
                $movementDate !== '' ? $movementDate : date('Y-m-d')
            );

            $labels = StockService::inventoryExitReasons();
            $label = $labels[$result['reason']] ?? $result['reason'];
            $msg = 'Salida registrada (' . $label . '). Stock actual: ' . (int)$result['stock_after'];
            if ($expenseId > 0) {
                $msg .= ' · Gasto operativo #' . $expenseId . ' del día.';
            }
            $this->respond(true, $msg, '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
        }
    }

    /**
     * Registra la salida también en el módulo Gastos (sin caja y sin doble asiento).
     * Contabilidad ya va por postStockMovement (gasto vs inventario).
     */
    private function createExpenseFromInventoryExit(
        int $movementId,
        string $reason,
        string $extraNote,
        string $expenseDate
    ): int {
        if ($movementId <= 0) {
            return 0;
        }

        $movement = $this->query(
            "SELECT sm.*, p.name product_name, p.code product_code
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id
             WHERE sm.id = ?
             LIMIT 1",
            [$movementId]
        )->fetch();
        if (!$movement) {
            return 0;
        }

        $amount = round((float)($movement['total_cost'] ?? 0), 2);
        if ($amount <= 0) {
            $amount = round((float)($movement['unit_cost'] ?? 0) * abs((int)$movement['quantity']), 2);
        }
        if ($amount <= 0) {
            // Respaldo: valorar a precio de venta si no hay costo de compra
            $sale = (float)$this->query(
                "SELECT COALESCE(sale_price, 0) FROM products WHERE id = ?",
                [(int)$movement['product_id']]
            )->fetchColumn();
            $amount = round($sale * abs((int)$movement['quantity']), 2);
        }
        if ($amount <= 0) {
            return 0;
        }

        $reasonMap = [
            'garantia' => 'inv_warranty',
            'roto' => 'inv_broken',
            'merma' => 'inv_broken',
            'perdida' => 'other_operational',
            'robo' => 'other_operational',
            'devolucion' => 'inv_return',
            'vencimiento' => 'inv_expiry',
            'otro' => 'other_operational',
        ];
        $catCode = $reasonMap[$reason] ?? 'other_operational';
        $catSvc = new \SoftNova\Services\ExpenseCategoryService($this->db);
        $cat = $catSvc->findByCode($catCode) ?: $catSvc->findByCode('other_operational');
        $labels = StockService::inventoryExitReasons();
        $reasonLabel = $labels[$reason] ?? $reason;
        $productLabel = trim(
            ((string)($movement['product_code'] ?? '') !== '' ? ($movement['product_code'] . ' · ') : '')
            . (string)($movement['product_name'] ?? '')
        );
        $description = 'Salida inventario · ' . $reasonLabel . ': ' . $productLabel
            . ' x' . abs((int)$movement['quantity']);
        $notes = 'Origen: salida inventario #' . $movementId
            . ' [salida_inventario:' . $movementId . ']'
            . ($extraNote !== '' ? (' — ' . $extraNote) : '');

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($expenseDate, 0, 10));
        $date = ($dt && $dt->format('Y-m-d') === substr($expenseDate, 0, 10))
            ? $dt->format('Y-m-d')
            : date('Y-m-d');

        try {
            $this->query(
                "INSERT INTO expenses
                    (description, amount, category, category_id, expense_date, supplier_id,
                     payment_method, receipt_number, notes, user_id)
                 VALUES (?, ?, ?, ?, ?, NULL, 'inventory_loss', NULL, ?, ?)",
                [
                    substr($description, 0, 255),
                    $amount,
                    $cat ? (string)$cat['name'] : $reasonLabel,
                    $cat ? (int)$cat['id'] : null,
                    $date,
                    substr($notes, 0, 2000),
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
            return (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('Gasto desde salida inventario ' . $movementId . ': ' . $e->getMessage());
            return 0;
        }
    }

    private function searchProducts(): void
    {
        $q = trim((string)$this->request->get('q', ''));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }
        $exact = $q;
        $search = \SoftNova\Core\multi_term_like_clause($q, [
            'name',
            'code',
            "COALESCE(barcode,'')",
        ]);
        $rows = $this->query(
            "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode,
                    purchase_price, sale_price
             FROM products
             WHERE status = 'active' AND product_type = 'product'
               AND (
                    code = ? OR barcode = ? OR {$search['sql']}
               )
             ORDER BY
                CASE
                    WHEN code = ? THEN 0
                    WHEN barcode = ? THEN 1
                    ELSE 2
                END,
                name ASC
             LIMIT 25",
            array_merge([$exact, $exact], $search['params'], [$exact, $exact])
        )->fetchAll();
        $this->json(['success' => true, 'products' => $rows]);
    }

    /**
     * Datos de lote enviados por el formulario de producto o de entrada de stock.
     *
     * @return array{track_lots:bool,lot_code:string,expiry_date:?string,alert_days:int}
     */
    private function lotInput(): array
    {
        $expiry = trim((string)$this->request->post('expiry_date', ''));
        $dt = $expiry !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $expiry) : null;

        return [
            'track_lots' => (string)$this->request->post('track_lots', '') !== '',
            'lot_code' => substr(trim((string)$this->request->post('lot_code', '')), 0, 60),
            'expiry_date' => ($dt && $dt->format('Y-m-d') === $expiry) ? $expiry : null,
            'alert_days' => max(0, min(3650, (int)$this->request->post('expiry_alert_days', 30))),
        ];
    }

    /** Stock máximo sugerido para las órdenes de reposición. */
    private function maxStockInput(): ?int
    {
        $raw = trim((string)$this->request->post('max_stock', ''));
        if ($raw === '') {
            return null;
        }
        return max(0, (int)$raw);
    }

    /**
     * @param array{lot_code:string,expiry_date:?string,alert_days:int} $lotInput
     */
    private function registerLot(int $productId, int $qty, float $unitCost, int $movementId, array $lotInput): void
    {
        try {
            $lots = new LotService($this->db);
            $lotId = $lots->registerEntry($productId, $qty, $unitCost, [
                'lot_code' => $lotInput['lot_code'],
                'expiry_date' => $lotInput['expiry_date'],
                'notes' => 'Registrado desde inventario',
            ]);
            if ($lotId > 0 && $movementId > 0) {
                $this->query(
                    "INSERT INTO stock_movement_lots
                        (movement_id, lot_id, lot_code, expiry_date, quantity, unit_cost)
                     SELECT ?, id, lot_code, expiry_date, ?, ? FROM product_lots WHERE id = ?",
                    [$movementId, $qty, round($unitCost, 2), $lotId]
                );
            }
        } catch (\Throwable $e) {
            error_log('Registro de lote producto ' . $productId . ': ' . $e->getMessage());
        }
    }

    /**
     * Lleva el movimiento de inventario al libro contable.
     * Un fallo contable no debe revertir el movimiento de bodega.
     */
    private function postStockAccounting(int $movementId): void
    {
        if ($movementId <= 0) {
            return;
        }
        try {
            (new AccountingService($this->db))->postStockMovement($movementId);
        } catch (\Throwable $e) {
            error_log('Contabilidad movimiento inventario ' . $movementId . ': ' . $e->getMessage());
        }
    }
    
    private function delete(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $reason = trim((string)$this->request->post('status_change_reason', ''));
        if (mb_strlen($reason) < 5) {
            $this->respond(
                false,
                'Indique la razón de desactivación (mín. 5 caracteres). Quedará en Trazabilidad.',
                '/app/inventario'
            );
            return;
        }
        try {
            $n = $this->softDeleteProducts([$id], $reason);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
            return;
        }
        $this->respond(
            $n > 0,
            $n > 0 ? 'Producto desactivado (soft-delete)' : 'Producto no encontrado',
            '/app/inventario'
        );
    }

    /** Desactiva varios productos (soft-delete) de una vez. */
    private function deleteBulk(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }

        $raw = $this->request->post('ids', []);
        if (!is_array($raw)) {
            $raw = preg_split('/[,\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            $this->respond(false, 'Seleccione al menos un producto', '/app/inventario');
            return;
        }
        if (count($ids) > 500) {
            $this->respond(false, 'Máximo 500 productos por lote', '/app/inventario');
            return;
        }

        $reason = trim((string)$this->request->post('status_change_reason', ''));
        if (mb_strlen($reason) < 5) {
            $this->respond(
                false,
                'Indique la razón de desactivación (mín. 5 caracteres). Quedará en Trazabilidad.',
                '/app/inventario'
            );
            return;
        }

        try {
            $n = $this->softDeleteProducts($ids, $reason);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
            return;
        }
        try {
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'delete',
                'inventario',
                'Eliminación por lote: ' . $n . ' producto(s)',
                'product',
                null
            );
        } catch (\Throwable $e) {
            // no bloquear
        }

        $this->respond(
            $n > 0,
            $n > 0
                ? ($n === 1 ? '1 producto desactivado' : $n . ' productos desactivados')
                : 'Ningún producto se pudo desactivar',
            '/app/inventario'
        );
    }

    /** @param list<int> $ids */
    private function softDeleteProducts(array $ids, string $reason = ''): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException(
                'Indique la razón de desactivación (mín. 5 caracteres). Quedará en Trazabilidad.'
            );
        }
        // Proteger producto comodín del sistema.
        try {
            $open = \SoftNova\Services\OpenItemProduct::ensure($this->db);
            $openId = (int)($open['id'] ?? 0);
            $ids = array_values(array_filter($ids, static fn($id) => $id !== $openId));
        } catch (\Throwable $e) {
            // ignore
        }
        if ($ids === []) {
            return 0;
        }

        $before = [];
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->query(
                "SELECT id, status FROM products WHERE id IN ($ph)",
                $ids
            )->fetchAll();
            foreach ($rows as $r) {
                $before[(int)$r['id']] = strtolower((string)($r['status'] ?? 'active'));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $code = \SoftNova\Services\OpenItemProduct::CODE;
        try {
            $stmt = $this->query(
                "UPDATE products SET status = 'inactive', deleted_at = NOW()
                 WHERE id IN ($placeholders) AND (status IS NULL OR status <> 'inactive')
                   AND UPPER(COALESCE(code,'')) <> ?",
                array_merge($params, [$code])
            );
        } catch (\Exception $e) {
            $stmt = $this->query(
                "UPDATE products SET status = 'inactive'
                 WHERE id IN ($placeholders) AND UPPER(COALESCE(code,'')) <> ?",
                array_merge($params, [$code])
            );
        }
        $n = max(0, (int)$stmt->rowCount());
        foreach ($ids as $pid) {
            $from = $before[$pid] ?? 'active';
            if ($from === 'inactive') {
                continue;
            }
            try {
                $this->stock->recordProductStatusChange((int)$pid, $from, 'inactive', $reason);
            } catch (\Throwable $e) {
                // no revertir soft-delete; el motivo es informativo
            }
        }
        return $n;
    }
    
    /**
     * @return string|null|false null = sin archivo, string = URL, false = error de validacion
     */
    private function uploadImage()
    {
        if (empty($_FILES['image']['tmp_name'])) {
            return null;
        }
        
        if (!is_uploaded_file($_FILES['image']['tmp_name'])) {
            return false;
        }
        
        if (($_FILES['image']['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            return false;
        }
        
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['image']['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return false;
        }
        
        $ext = self::ALLOWED_MIME[$mime];
        $uploadDir = PUBLIC_PATH . '/uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $uploadDir . $filename;
        
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            return false;
        }
        
        // Ruta relativa: se sirve vía /app/media (autenticado)
        return 'uploads/products/' . $filename;
    }
    
    private function exportProducts(): void
    {
        $filters = $this->listFilters([
            'name' => 'p.name',
            'code' => 'p.code',
            'category' => 'c.name',
            'price' => 'p.sale_price',
            'cost' => 'p.purchase_price',
            'stock' => 'p.stock',
            'created' => 'p.created_at',
            'location' => 'p.location',
        ], 'name', 'asc');

        $typeFilter = $this->request->get('type', '');
        $categoryFilter = (int)$this->request->get('category_id', 0);
        $stockFilter = (string)$this->request->get('stock_state', '');
        $locationFilter = trim((string)$this->request->get('location', ''));

        try {
            (new \SoftNova\Services\ConsignmentService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
            // ignore
        }
        $where = ["p.status = 'active'", \SoftNova\Services\ConsignmentService::ownedOnlySql('p')];
        $params = [];
        if ($typeFilter === 'product' || $typeFilter === 'service') {
            $where[] = "p.product_type = ?";
            $params[] = $typeFilter;
        }
        if ($categoryFilter > 0) {
            $where[] = "p.category_id = ?";
            $params[] = $categoryFilter;
        }
        if ($stockFilter === 'low') {
            $where[] = "p.product_type = 'product' AND p.stock <= p.min_stock";
        } elseif ($stockFilter === 'out') {
            $where[] = "p.product_type = 'product' AND p.stock <= 0";
        }
        if ($locationFilter !== '') {
            $where[] = "p.location = ?";
            $params[] = $locationFilter;
        }
        if ($filters['q'] !== '') {
            $search = \SoftNova\Core\multi_term_like_clause($filters['q'], [
                'p.name',
                'p.code',
                "COALESCE(p.barcode,'')",
                'p.description',
                "COALESCE(p.location,'')",
            ]);
            $where[] = $search['sql'];
            foreach ($search['params'] as $p) {
                $params[] = $p;
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->query(
            "SELECT p.id, p.code, p.barcode, p.name, p.product_type, c.name as category_name, p.purchase_price,
                    p.sale_price, COALESCE(p.tax_rate, 0) AS tax_rate, COALESCE(p.wholesale_price, 0) AS wholesale_price,
                    p.stock, p.min_stock, p.unit, p.status, p.location
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             {$whereSql}
             ORDER BY {$filters['orderSql']}, p.name",
            $params
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            // Prefijo tab: Excel no convierte EAN/SKU largos a notación científica
            $sku = (string)($r['code'] ?? '');
            if ($sku !== '' && preg_match('/^\d{11,}$/', $sku)) {
                $sku = "\t" . $sku;
            }
            $ean = (string)($r['barcode'] ?? '');
            if ($ean !== '' && preg_match('/^\d{11,}$/', $ean)) {
                $ean = "\t" . $ean;
            }
            $csvRows[] = [
                $sku,
                $ean,
                $r['name'],
                $r['product_type'],
                $r['category_name'],
                $r['purchase_price'],
                $r['sale_price'],
                $r['tax_rate'] ?? 0,
                $r['wholesale_price'] ?? 0,
                $r['stock'],
                $r['min_stock'],
                $r['unit'],
                $r['status'],
                $r['location'] ?? '',
            ];

            // La exportación CSV no debe ensuciar el kardex (antes: AJUSTE qty 0).
        }
        
        $this->exportCsv(
            'inventario_' . date('Ymd_His') . '.csv',
            ['SKU', 'EAN', 'Nombre', 'Tipo', 'Categoria', 'Costo', 'Precio', 'IVA', 'Precio_mayor', 'Stock', 'Min', 'Unidad', 'Estado', 'Ubicacion'],
            $csvRows
        );
    }

    /**
     * Plantilla de conteo físico: mismas filas del catálogo filtrado, con Cantidad vacía.
     */
    private function exportCountSheet(): void
    {
        $filters = $this->listFilters([
            'name' => 'p.name',
            'code' => 'p.code',
            'category' => 'c.name',
            'price' => 'p.sale_price',
            'cost' => 'p.purchase_price',
            'stock' => 'p.stock',
            'created' => 'p.created_at',
            'location' => 'p.location',
        ], 'name', 'asc');

        $typeFilter = $this->request->get('type', '');
        $categoryFilter = (int)$this->request->get('category_id', 0);
        $stockFilter = (string)$this->request->get('stock_state', '');
        $locationFilter = trim((string)$this->request->get('location', ''));

        $where = ["p.status = 'active'", "p.product_type = 'product'"];
        $params = [];
        if ($typeFilter === 'service') {
            // Conteo solo aplica a productos físicos; si filtran servicios, devolver vacío.
            $where[] = '1 = 0';
        }
        if ($categoryFilter > 0) {
            $where[] = "p.category_id = ?";
            $params[] = $categoryFilter;
        }
        if ($stockFilter === 'low') {
            $where[] = "p.stock <= p.min_stock";
        } elseif ($stockFilter === 'out') {
            $where[] = "p.stock <= 0";
        }
        if ($locationFilter !== '') {
            $where[] = "p.location = ?";
            $params[] = $locationFilter;
        }
        if ($filters['q'] !== '') {
            $search = \SoftNova\Core\multi_term_like_clause($filters['q'], [
                'p.name',
                'p.code',
                "COALESCE(p.barcode,'')",
                'p.description',
                "COALESCE(p.location,'')",
            ]);
            $where[] = $search['sql'];
            foreach ($search['params'] as $p) {
                $params[] = $p;
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->query(
            "SELECT p.code, p.barcode, p.name, c.name as category_name, p.unit, p.location
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             {$whereSql}
             ORDER BY {$filters['orderSql']}, p.name",
            $params
        )->fetchAll();

        $csvRows = [];
        foreach ($rows as $r) {
            $sku = (string)($r['code'] ?? '');
            if ($sku !== '' && preg_match('/^\d{11,}$/', $sku)) {
                $sku = "\t" . $sku;
            }
            $ean = (string)($r['barcode'] ?? '');
            if ($ean !== '' && preg_match('/^\d{11,}$/', $ean)) {
                $ean = "\t" . $ean;
            }
            $csvRows[] = [
                $sku,
                $ean,
                $r['name'],
                $r['category_name'] ?? '',
                $r['location'] ?? '',
                $r['unit'] ?? 'UND',
                '', // Cantidad vacía para conteo físico
            ];
        }

        $this->exportCsv(
            'conteo_inventario_' . date('Ymd_His') . '.csv',
            ['SKU', 'EAN', 'Nombre', 'Categoria', 'Ubicacion', 'Unidad', 'Cantidad'],
            $csvRows
        );
    }

    private function activateOffer(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $id = (int)$this->request->post('product_id', 0);
        try {
            $svc = new \SoftNova\Services\ProductOfferService($this->db);
            $result = $svc->activate($id, [
                'offer_price' => $this->request->post('offer_price', 0),
                'percent' => $this->request->post('percent', ''),
                'duration' => $this->request->post('duration', ''),
                'starts_at' => $this->request->post('starts_at', ''),
                'ends_at' => $this->request->post('ends_at', ''),
                'reason' => $this->request->post('reason', ''),
                'reason_note' => $this->request->post('reason_note', ''),
            ], (int)($_SESSION['tenant_user_id'] ?? 0) ?: null);

            if (!empty($result['scheduled'])) {
                $msg = 'Oferta programada: '
                    . number_format((float)$result['offer_price'], 2, ',', '.')
                    . ' (−' . rtrim(rtrim(number_format((float)$result['percent'], 2, '.', ''), '0'), '.') . '%)'
                    . ' · ' . ($result['reason_label'] ?? '')
                    . ' desde ' . date('d/m/Y H:i', strtotime((string)$result['starts_at']))
                    . ' hasta ' . date('d/m/Y H:i', strtotime((string)$result['ends_at']));
            } else {
                $msg = 'Oferta activa: '
                    . number_format((float)$result['offer_price'], 2, ',', '.')
                    . ' (−' . rtrim(rtrim(number_format((float)$result['percent'], 2, '.', ''), '0'), '.') . '%)'
                    . ' · ' . ($result['reason_label'] ?? '')
                    . ' hasta ' . date('d/m/Y H:i', strtotime((string)$result['ends_at']));
            }
            $this->respond(true, $msg, '/app/inventario', $result);
        } catch (\InvalidArgumentException $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
        }
    }

    private function cancelOffer(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $id = (int)$this->request->post('product_id', 0);
        try {
            $result = (new \SoftNova\Services\ProductOfferService($this->db))->cancel(
                $id,
                (int)($_SESSION['tenant_user_id'] ?? 0) ?: null
            );
            $this->respond(
                true,
                'Oferta cancelada. Precio restaurado a '
                    . number_format((float)$result['restored_price'], 2, ',', '.'),
                '/app/inventario',
                $result
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
        }
    }

    private function createCategory(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $name = trim(preg_replace('/\s+/', ' ', (string)$this->request->post('name', '')) ?? '');
        if ($name === '' || mb_strlen($name) < 2) {
            $this->respond(false, 'Escriba un nombre de categoría válido', '/app/inventario');
            return;
        }
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }
        $description = trim((string)$this->request->post('description', ''));
        try {
            $exists = $this->query(
                "SELECT id, status FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1",
                [$name]
            )->fetch();
            if ($exists) {
                if (($exists['status'] ?? '') !== 'active') {
                    $this->query(
                        "UPDATE categories SET status = 'active', description = COALESCE(NULLIF(?, ''), description) WHERE id = ?",
                        [$description, (int)$exists['id']]
                    );
                    $this->respond(true, 'Categoría reactivada: ' . $name, '/app/inventario');
                    return;
                }
                $this->respond(false, 'Ya existe la categoría: ' . $name, '/app/inventario');
                return;
            }
            $this->query(
                "INSERT INTO categories (name, description, status) VALUES (?, ?, 'active')",
                [$name, $description !== '' ? $description : null]
            );
            $this->respond(true, 'Categoría creada: ' . $name, '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, 'No se pudo crear la categoría: ' . $e->getMessage(), '/app/inventario');
        }
    }

    private function deleteCategory(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->respond(false, 'Categoría no válida', '/app/inventario');
            return;
        }
        try {
            $cat = $this->query("SELECT id, name FROM categories WHERE id = ?", [$id])->fetch();
            if (!$cat) {
                $this->respond(false, 'Categoría no encontrada', '/app/inventario');
                return;
            }
            $used = (int)$this->query(
                "SELECT COUNT(*) FROM products WHERE category_id = ? AND status = 'active'",
                [$id]
            )->fetchColumn();
            // Soft-delete: productos quedan sin categoría (FK ON DELETE SET NULL) o se desasocian.
            $this->query("UPDATE products SET category_id = NULL WHERE category_id = ?", [$id]);
            $this->query("UPDATE categories SET status = 'inactive' WHERE id = ?", [$id]);
            $msg = 'Categoría eliminada: ' . $cat['name'];
            if ($used > 0) {
                $msg .= " ({$used} producto(s) quedaron sin categoría)";
            }
            $this->respond(true, $msg, '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, 'No se pudo eliminar: ' . $e->getMessage(), '/app/inventario');
        }
    }

    private function categoryReport(): void
    {
        try {
            $categoryId = (int)$this->request->get('category_id', 0);
            $format = strtolower((string)$this->request->get('format', 'pdf'));
            $where = [
                "p.status = 'active'",
                "p.product_type = 'product'",
                \SoftNova\Services\ConsignmentService::ownedOnlySql('p'),
            ];
            $params = [];
            if ($categoryId > 0) {
                $where[] = 'p.category_id = ?';
                $params[] = $categoryId;
            }
            $whereSql = implode(' AND ', $where);
            $rows = $this->query(
                "SELECT COALESCE(c.name, 'Sin categoría') as category_name,
                        p.code, COALESCE(p.barcode, '') AS barcode, p.name, p.location, p.stock, p.min_stock, p.purchase_price, p.sale_price,
                        (p.stock * p.purchase_price) as stock_value
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE {$whereSql}
                 ORDER BY category_name ASC, p.name ASC",
                $params
            )->fetchAll();

            if ($format === 'csv') {
                $csvRows = [];
                foreach ($rows as $r) {
                    $sku = (string)($r['code'] ?? '');
                    if ($sku !== '' && preg_match('/^\d{11,}$/', $sku)) {
                        $sku = "\t" . $sku;
                    }
                    $ean = (string)($r['barcode'] ?? '');
                    if ($ean !== '' && preg_match('/^\d{11,}$/', $ean)) {
                        $ean = "\t" . $ean;
                    }
                    $csvRows[] = [
                        $r['category_name'],
                        $sku,
                        $ean,
                        $r['name'],
                        $r['location'] ?? '',
                        $r['stock'],
                        $r['min_stock'],
                        $r['purchase_price'],
                        $r['sale_price'],
                        $r['stock_value'],
                    ];
                }
                $this->exportCsv(
                    'inventario_por_categoria_' . date('Ymd_His') . '.csv',
                    ['Categoria', 'SKU', 'EAN', 'Producto', 'Ubicacion', 'Stock', 'Min', 'Costo', 'Precio', 'Valor stock'],
                    $csvRows
                );
                return;
            }

            $summary = $this->query(
                "SELECT COALESCE(c.name, 'Sin categoría') as category_name,
                        COUNT(*) as products,
                        COALESCE(SUM(p.stock), 0) as units,
                        COALESCE(SUM(p.stock * p.purchase_price), 0) as value
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE {$whereSql}
                 GROUP BY COALESCE(c.name, 'Sin categoría')
                 ORDER BY category_name",
                $params
            )->fetchAll();

            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateInventoryByCategoryReport($summary, $rows, [
                'category_id' => $categoryId,
            ]);
            $pdf->download($content, 'inventario_categoria_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            error_log('categoryReport: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "No se pudo generar el reporte.\n" . $e->getMessage();
            exit;
        }
    }

    private function traceabilityPdf(): void
    {
        try {
            $filters = [
                'q' => trim((string)$this->request->get('q', '')),
                'type' => trim((string)$this->request->get('type', '')),
                'reference_type' => trim((string)$this->request->get('reference_type', '')),
                'from' => trim((string)$this->request->get('from', '')),
                'to' => trim((string)$this->request->get('to', '')),
                'product_id' => (int)$this->request->get('product_id', 0) ?: null,
                'location' => trim((string)$this->request->get('location', '')),
                'category_id' => (int)$this->request->get('category_id', 0) ?: null,
            ];
            $result = $this->stock->listMovements($filters, 5000, 0);
            $rows = \SoftNova\Services\StockService::attachPurchaseTaxInfo($this->db, $result['rows'] ?? []);
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateTraceabilityReport($rows, $filters);
            $pdf->download($content, 'trazabilidad_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            error_log('traceabilityPdf: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "No se pudo generar el PDF de trazabilidad.\n" . $e->getMessage();
            exit;
        }
    }

    /** @return string|null */
    private function normalizeLocationInput(string $raw): ?string
    {
        $location = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        if ($location === '' || $location === '__other__' || mb_strtolower($location) === 'otro') {
            return null;
        }
        if (mb_strlen($location) > 120) {
            $location = mb_substr($location, 0, 120);
        }
        return $location;
    }

    /**
     * Plantilla CSV UTF-8 (separador ;) lista para llenar e importar.
     */
    private function downloadImportTemplate(): void
    {
        $headers = [
            'SKU', 'EAN', 'Nombre', 'Tipo', 'Categoria', 'Costo', 'Precio', 'IVA', 'Precio_mayor',
            'Stock', 'Min', 'Unidad', 'Estado', 'Ubicacion', 'Lote', 'Vence', 'Modo_stock',
        ];
        // Ejemplos: borre o reemplace. Con Lote/Vence, Stock = cantidad de esa remesa (suma).
        $rows = [
            [
                '',
                '',
                'Servicio de manicure',
                'service',
                'Servicios',
                '0',
                '25000',
                '0',
                '0',
                '0',
                '0',
                'UND',
                'active',
                '',
                '',
                '',
                '',
            ],
            [
                'PROD-001',
                "\t7701234567890",
                'Cepillo para uñas',
                'product',
                'Cuidado personal',
                '1500',
                '2500',
                '19',
                '2000',
                '50',
                '5',
                'UNIDAD',
                'active',
                'Estante A1',
                '',
                '',
                'absoluto',
            ],
            [
                'LOC-ROSA-100',
                "\t7701987654321",
                'Loción rosa 100ml',
                'product',
                'Perfumería',
                '18000',
                '32000',
                '19',
                '28000',
                '12',
                '3',
                'UNIDAD',
                'active',
                'Vitrina 2',
                'L-2026A',
                '2027-06-30',
                'entrada',
            ],
            [
                'LOC-ROSA-100',
                "\t7701987654321",
                'Loción rosa 100ml',
                'product',
                'Perfumería',
                '18000',
                '32000',
                '19',
                '28000',
                '8',
                '3',
                'UNIDAD',
                'active',
                'Vitrina 2',
                'L-2026B',
                '2028-01-15',
                'entrada',
            ],
            [
                'SERV-001',
                '',
                'Servicio de domicilio',
                'service',
                'Servicios',
                '0',
                '5000',
                '0',
                '0',
                '0',
                '0',
                'UNIDAD',
                'active',
                '',
                '',
                '',
                '',
            ],
        ];

        $this->exportCsv('plantilla_inventario_seri.csv', $headers, $rows);
    }

    /**
     * Importar productos desde CSV (mismo formato que export).
     * Se procesa en la misma petición (hasta 5MB) para no depender de la cola de jobs.
     */
    private function importCsv(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }

        $maxBytes = 5 * 1024 * 1024;
        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $this->respond(false, 'Seleccione un archivo CSV', '/app/inventario');
            return;
        }
        $size = (int)($_FILES['csv_file']['size'] ?? 0);
        if ($size > $maxBytes) {
            $this->respond(false, 'El archivo supera el máximo de 5MB', '/app/inventario');
            return;
        }

        $tmp = $_FILES['csv_file']['tmp_name'];
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);

        $magic = @file_get_contents($tmp, false, null, 0, 4);
        if ($magic === "PK\x03\x04") {
            $this->respond(
                false,
                'Subió un Excel (.xlsx). Ábralo en Excel y guarde como CSV UTF-8 (delimitado por punto y coma), luego vuelva a subirlo.',
                '/app/inventario'
            );
            return;
        }

        $estimatedRows = \SoftNova\Services\ProductCsvImporter::estimateDataRows($tmp);

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');

        // Archivos muy grandes: cola + procesamiento extendido (evita timeout del hosting).
        if ($estimatedRows >= 2000 || $size >= 400000) {
            $this->importCsvHeavy($tmp, $userId, $estimatedRows);
            return;
        }

        try {
            $importer = new \SoftNova\Services\ProductCsvImporter($this->db);
            $result = $importer->importFromFile($tmp, $userId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
            return;
        }

        $this->respondImportCsvResult($result, $estimatedRows);
    }

    /**
     * Importación pesada: copia a storage y procesa con límite de tiempo desactivado.
     */
    private function importCsvHeavy(string $tmp, int $userId, int $estimatedRows): void
    {
        $dir = ROOT_PATH . '/storage/imports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $dest = $dir . '/import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                $this->respond(false, 'No se pudo preparar el archivo para importación', '/app/inventario');
                return;
            }
        }

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        try {
            $importer = new \SoftNova\Services\ProductCsvImporter($this->db);
            $result = $importer->importFromFile($dest, $userId);
        } catch (\Throwable $e) {
            @unlink($dest);
            $this->respond(false, $e->getMessage(), '/app/inventario');
            return;
        }
        @unlink($dest);

        $this->respondImportCsvResult($result, $estimatedRows);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function respondImportCsvResult(array $result, int $estimatedRows): void
    {
        $totalRows = (int)($result['total_rows'] ?? 0);
        $processed = (int)$result['created'] + (int)$result['updated'] + (int)$result['skipped'];
        $unique = (int)($result['unique_products'] ?? 0);
        $fileEst = (int)($result['file_rows_estimated'] ?? $estimatedRows);

        $msg = sprintf(
            'Importación CSV: %d fila(s) leídas → %d producto(s) distintos',
            $totalRows > 0 ? $totalRows : $processed,
            $unique > 0 ? $unique : ((int)$result['created'] + (int)$result['updated'])
        );
        $msg .= sprintf(
            ' (%d nuevos, %d actualizados',
            (int)$result['created'],
            (int)$result['updated']
        );
        if (($result['skipped'] ?? 0) > 0) {
            $msg .= ', ' . (int)$result['skipped'] . ' omitidos';
        }
        if (!empty($result['duplicate_rows'])) {
            $msg .= ', ' . (int)$result['duplicate_rows'] . ' filas repetidas';
        }
        if (!empty($result['costs_corrected'])) {
            $msg .= ', ' . (int)$result['costs_corrected'] . ' costos corregidos';
        }
        $msg .= ')';

        if ($fileEst > 0 && $totalRows > 0 && abs($fileEst - $totalRows) > 5) {
            $msg .= sprintf('. En el archivo hay ~%d líneas de datos (diferencia por filas vacías).', $fileEst);
        }
        if ($fileEst >= 2000 && $processed > 0 && $processed < (int)($fileEst * 0.9)) {
            $msg .= ' ADVERTENCIA: no se procesaron todas las filas; puede ser timeout. Vuelva a subir el CSV (actualiza sin borrar).';
        }

        if (!empty($result['explanation'])) {
            $msg .= ' — ' . (string)$result['explanation'];
        }

        $errors = $result['errors'] ?? [];
        if (!empty($errors) && count($errors) <= 5) {
            $msg .= ' Detalle: ' . implode('; ', $errors);
        } elseif (!empty($errors)) {
            $msg .= ' Detalle: ' . $errors[0] . ' (+' . (count($errors) - 1) . ' más)';
        }

        // Evitar mensajes gigantes en toast; el panel en pantalla lleva el detalle.
        $toast = $msg;
        if (strlen($toast) > 280) {
            $toast = sprintf(
                'Importación CSV: %d fila(s) → %d producto(s) (%d nuevos, %d actualizados, %d omitidos). Ver detalle en pantalla.',
                $totalRows > 0 ? $totalRows : $processed,
                $unique > 0 ? $unique : ((int)$result['created'] + (int)$result['updated']),
                (int)$result['created'],
                (int)$result['updated'],
                (int)($result['skipped'] ?? 0)
            );
        }

        $_SESSION['inventory_import_report'] = [
            'at' => date('Y-m-d H:i:s'),
            'message' => $msg,
            'explanation' => (string)($result['explanation'] ?? ''),
            'created' => (int)$result['created'],
            'updated' => (int)$result['updated'],
            'skipped' => (int)($result['skipped'] ?? 0),
            'unique_products' => $unique,
            'total_rows' => $totalRows,
            'file_rows_estimated' => $fileEst,
            'duplicate_rows' => (int)($result['duplicate_rows'] ?? 0),
            'skip_reasons' => $result['skip_reasons'] ?? [],
            'errors' => array_slice($errors, 0, 40),
            'costs_corrected' => (int)($result['costs_corrected'] ?? 0),
        ];

        $this->respond(true, $toast, '/app/inventario', [
            'import' => $result,
        ]);
    }

    /** Corrige costos inflados por importaciones CSV/Excel previas (×10/×100/×1000). */
    private function repairInflatedCosts(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        try {
            $importer = new \SoftNova\Services\ProductCsvImporter($this->db);
            $result = $importer->repairInflatedPurchaseCosts();
        } catch (\Throwable $e) {
            $this->respond(false, 'No se pudieron reparar costos: ' . $e->getMessage(), '/app/inventario');
            return;
        }

        $fixed = (int)($result['fixed'] ?? 0);
        $msg = sprintf(
            'Reparación de costos: %d producto(s) corregido(s) de %d revisado(s).',
            $fixed,
            (int)($result['scanned'] ?? 0)
        );
        if (!empty($result['samples'])) {
            $msg .= ' Ejemplos: ' . implode('; ', $result['samples']);
        }
        if ($fixed === 0) {
            $msg .= ' No se pudo auto-corregir con la regla costo vs precio. ';
            if (!empty($result['top_before'])) {
                $bits = [];
                foreach (array_slice($result['top_before'], 0, 5) as $t) {
                    $bits[] = trim(($t['code'] ?? '') . ' ' . mb_substr((string)($t['name'] ?? ''), 0, 30))
                        . ' costo=' . number_format((float)$t['cost'], 2, ',', '.')
                        . ' venta=' . number_format((float)$t['sale'], 2, ',', '.');
                }
                $msg .= 'Mayores aportes: ' . implode(' | ', $bits);
            }
        }

        $_SESSION['inventory_cost_repair_report'] = [
            'at' => date('Y-m-d H:i:s'),
            'fixed' => $fixed,
            'scanned' => (int)($result['scanned'] ?? 0),
            'samples' => $result['samples'] ?? [],
            'top_before' => $result['top_before'] ?? [],
            'message' => $msg,
        ];

        $this->respond(true, $msg, '/app/inventario', ['repair' => $result]);
    }

    /**
     * Código de barras / SKU como texto puro (sin cast numérico).
     *
     * @return string|false false si es inválido (notación científica o demasiado largo)
     */
    private function normalizeProductCodeInput(string $raw): string|false
    {
        $code = trim($raw);
        $code = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], '', $code);
        if (str_starts_with($code, "'")) {
            $code = substr($code, 1);
        }
        $code = ltrim($code, "\t");
        $code = trim($code, " \t\"'");
        if ($code === '') {
            return '';
        }
        if (preg_match('/^[+-]?\d+(?:[.,]\d+)?[eE][+-]?\d+$/', $code)) {
            return false;
        }
        if (preg_match('/^(\d+)\.0+$/', $code, $m)) {
            $code = $m[1];
        }
        if (mb_strlen($code, 'UTF-8') > 255) {
            return false;
        }
        return $code;
    }

    /** @return string|false */
    private function normalizeBarcodeInput(string $raw): string|false
    {
        $code = $this->normalizeProductCodeInput($raw);
        if ($code === false) {
            return false;
        }
        if (mb_strlen($code, 'UTF-8') > 64) {
            return false;
        }
        return $code;
    }

    /**
     * Busca un producto de catálogo por SKU o EAN (mismo ítem comercial).
     * @return array<string,mixed>|null
     */
    private function findCatalogProductByCodeOrBarcode(string $code, string $barcode): ?array
    {
        if ($code === '' && $barcode === '') {
            return null;
        }
        try {
            if ($code !== '' && $barcode !== '') {
                $row = $this->query(
                    "SELECT id, code, barcode, name, stock, COALESCE(track_lots, 0) AS track_lots
                     FROM products
                     WHERE code = ? OR (barcode IS NOT NULL AND barcode <> '' AND barcode = ?)
                     ORDER BY CASE WHEN code = ? THEN 0 ELSE 1 END, id ASC
                     LIMIT 1",
                    [$code, $barcode, $code]
                )->fetch();
            } elseif ($code !== '') {
                $row = $this->query(
                    "SELECT id, code, barcode, name, stock, COALESCE(track_lots, 0) AS track_lots
                     FROM products WHERE code = ? LIMIT 1",
                    [$code]
                )->fetch();
            } else {
                $row = $this->query(
                    "SELECT id, code, barcode, name, stock, COALESCE(track_lots, 0) AS track_lots
                     FROM products WHERE barcode = ? LIMIT 1",
                    [$barcode]
                )->fetch();
            }
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Genera el siguiente EAN según la secuencia de Configuración → Preferencias. */
    private function generateBarcode(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        try {
            $seq = new \SoftNova\Services\BarcodeSequenceService($this->db);
            $result = $seq->allocateNext();
            $this->json([
                'success' => true,
                'barcode' => $result['barcode'],
                'message' => 'EAN generado: ' . $result['barcode'],
                'next' => $result['next'],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Genera el siguiente SKU según la secuencia de Configuración → Preferencias. */
    private function generateSku(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        try {
            $seq = new \SoftNova\Services\SkuSequenceService($this->db);
            $result = $seq->allocateNext();
            $this->json([
                'success' => true,
                'sku' => $result['sku'],
                'message' => 'SKU generado: ' . $result['sku'],
                'next' => $result['next'],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
