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
        $this->stock = new StockService($this->db);
    }
    
    public function index(): void
    {
        $action = $this->request->get('action');
        
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
        if ($action === 'add_stock' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'edit');
            $this->addStock();
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
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('inventario', 'export');
            $this->exportProducts();
            return;
        }
        if ($action === 'import_csv' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('inventario', 'create');
            $this->importCsv();
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
        $categoryFilter = (int)$this->request->get('category_id', 0);
        $stockFilter = (string)$this->request->get('stock_state', '');
        $channelFilter = (string)$this->request->get('channel', '');
        $locationFilter = trim((string)$this->request->get('location', ''));

        $where = ["p.status = 'active'"];
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
        if ($channelFilter !== '') {
            $where[] = "COALESCE(p.source_channel, 'manual') = ?";
            $params[] = $channelFilter;
        }
        if ($locationFilter !== '') {
            $where[] = "p.location = ?";
            $params[] = $locationFilter;
        }
        if ($filters['q'] !== '') {
            $where[] = "(p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.barcode,'') LIKE ? OR p.description LIKE ? OR COALESCE(p.external_id,'') LIKE ? OR COALESCE(p.location,'') LIKE ?)";
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->query(
            "SELECT COUNT(*) as c FROM products p LEFT JOIN categories c ON p.category_id = c.id {$whereSql}",
            $params
        )->fetch()['c'];
        $pagination = $this->paginate($total);
        
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
        
        $categories = $this->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();
        $lowStock = $this->query(
            "SELECT COUNT(*) as c FROM products WHERE stock <= min_stock AND status = 'active' AND product_type = 'product'"
        )->fetch()['c'];
        $totalProducts = $this->query(
            "SELECT COUNT(*) as c FROM products WHERE status = 'active' AND product_type = 'product'"
        )->fetch()['c'];
        $totalServices = $this->query(
            "SELECT COUNT(*) as c FROM products WHERE status = 'active' AND product_type = 'service'"
        )->fetch()['c'];
        
        $extraQuery = array_filter([
            'type' => ($typeFilter === 'product' || $typeFilter === 'service') ? $typeFilter : null,
            'category_id' => $categoryFilter > 0 ? $categoryFilter : null,
            'stock_state' => in_array($stockFilter, ['low', 'out'], true) ? $stockFilter : null,
            'channel' => $channelFilter !== '' ? $channelFilter : null,
            'location' => $locationFilter !== '' ? $locationFilter : null,
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
            'typeFilter' => $typeFilter,
            'categoryFilter' => $categoryFilter,
            'stockFilter' => $stockFilter,
            'channelFilter' => $channelFilter,
            'locationFilter' => $locationFilter,
            'locationOptions' => $locationOptions,
            'filters' => $filters,
            'pagination' => $pagination,
            'catalogStatuses' => $catalogStatuses,
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
        
        $movements = $this->query(
            "SELECT sm.*, u.name as user_name
             FROM stock_movements sm
             LEFT JOIN users u ON sm.user_id = u.id
             WHERE sm.product_id = ?
             ORDER BY COALESCE(sm.movement_date, sm.created_at) DESC, sm.id DESC LIMIT 50",
            [$id]
        )->fetchAll();
        
        $lastSales = $this->query(
            "SELECT si.*, s.invoice_number, s.sale_date
             FROM sale_items si
             JOIN sales s ON si.sale_id = s.id
             WHERE si.product_id = ?
             ORDER BY s.sale_date DESC LIMIT 5",
            [$id]
        )->fetchAll();

        $purchases = $this->query(
            "SELECT pi.*, pu.purchase_number, pu.purchase_date, su.name supplier_name
             FROM purchase_items pi
             JOIN purchases pu ON pu.id = pi.purchase_id
             LEFT JOIN suppliers su ON su.id = pu.supplier_id
             WHERE pi.product_id = ? AND pu.status = 'completed'
             ORDER BY pu.purchase_date DESC LIMIT 10",
            [$id]
        )->fetchAll();
        
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
            'lots' => $lots,
            'suppliers' => $productSuppliers,
            'channel_label' => \SoftNova\Core\product_channel_label((string)($product['source_channel'] ?? 'manual')),
        ]);
    }

    /** Ajuste manual o bloqueo de un lote (mermas, roturas, cuarentena). */
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
                $ok = $lots->blockLot($lotId, $notes);
                $this->respond($ok, $ok ? 'Lote bloqueado: no se venderá' : 'Lote no encontrado', '/app/inventario?action=vencimientos');
                return;
            }

            $ok = $lots->adjustLot($lotId, (int)$this->request->post('quantity', 0), $notes);
            $this->respond($ok, $ok ? 'Lote ajustado' : 'Lote no encontrado', '/app/inventario?action=vencimientos');
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
                'last_cost' => (float)$this->request->post('last_cost', 0),
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
        $page = max(1, (int)$this->request->get('page', 1));
        $perPage = 40;
        $filters = [
            'q' => trim((string)$this->request->get('q', '')),
            'type' => trim((string)$this->request->get('type', '')),
            'reference_type' => trim((string)$this->request->get('reference_type', '')),
            'from' => trim((string)$this->request->get('from', '')),
            'to' => trim((string)$this->request->get('to', '')),
            'product_id' => (int)$this->request->get('product_id', 0) ?: null,
        ];
        $result = $this->stock->listMovements($filters, $perPage, ($page - 1) * $perPage);
        $pagination = $this->paginate($result['total'], $perPage);

        $this->view('tenant.trazabilidad', $this->tenantViewData([
            'movements' => $result['rows'],
            'filters' => $filters,
            'pagination' => $pagination,
            'kardex' => $this->stock->valuedKardex(null, 40),
            'costAlerts' => $this->stock->costVariationAlerts(5.0, 20),
            'purchaseProjection' => $this->stock->purchaseProjection(30, 20),
            'pendingAccounting' => $this->stock->pendingAccountingCount(),
        ]));
    }

    private function importCatalog(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        $provider = (string)$this->request->post('provider', '');
        try {
            $result = (new CatalogSyncService($this->db))->import($provider);
            $msg = sprintf(
                'Importación %s: %d nuevos, %d actualizados, %d sin tocar stock, %d errores',
                $provider,
                $result['created'] ?? 0,
                $result['updated'] ?? 0,
                $result['skipped_stock'] ?? 0,
                $result['errors'] ?? 0
            );
            $this->respond(($result['errors'] ?? 0) === 0, $msg, '/app/inventario');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
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
        
        $lotInput = $this->lotInput();
        $trackLots = $productType === 'product'
            && ($lotInput['track_lots'] || $lotInput['expiry_date'] !== null);
        $location = $this->normalizeLocationInput((string)$this->request->post('location', ''));

        SupplyChainSchema::ensure($this->db);

        $this->query(
            "INSERT INTO products
                (code, barcode, name, product_type, description, category_id, purchase_price, sale_price,
                 stock, min_stock, unit, image, track_lots, expiry_alert_days, max_stock, location, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $code,
                $barcode !== '' ? $barcode : null,
                $name,
                $productType,
                $this->request->post('description'),
                $this->request->post('category_id') ?: null,
                $this->request->post('purchase_price', 0),
                $this->request->post('sale_price', 0),
                $stock,
                $this->request->post('min_stock', 5),
                $this->request->post('unit', 'UNIDAD'),
                $image,
                $trackLots ? 1 : 0,
                $lotInput['alert_days'],
                $this->maxStockInput(),
                $location,
                $_SESSION['tenant_user_id'],
            ]
        );
        
        $newId = (int)$this->db->lastInsertId();
        
        if ($stock > 0 && $productType === 'product') {
            $unitCost = (float)$this->request->post('purchase_price', 0);
            $movementId = $this->stock->addMovement(
                $newId,
                'in',
                $stock,
                'opening',
                null,
                'Stock inicial (use Compras para entradas con proveedor)',
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
        
        $productType = $this->request->post('product_type', $oldProduct['product_type'] ?? 'product');
        $newStock = $productType === 'service' ? 0 : (int)$this->request->post('stock', 0);
        
        $image = $this->uploadImage();
        if ($image === false) {
            $this->respond(false, 'Imagen invalida. Use JPG/PNG/GIF/WEBP max 2MB', '/app/inventario');
            return;
        }

        $code = $this->normalizeProductCodeInput((string)$this->request->post('code', ''));
        if ($code === false || $code === '') {
            $this->respond(false, 'Código / SKU inválido. Use texto (no notación científica) y máximo 255 caracteres.', '/app/inventario');
            return;
        }
        $barcode = $this->normalizeBarcodeInput((string)$this->request->post('barcode', ''));
        if ($barcode === false) {
            $this->respond(false, 'Código de barras EAN inválido (máx. 64 caracteres).', '/app/inventario');
            return;
        }
        
        $lotInput = $this->lotInput();
        $location = $this->normalizeLocationInput((string)$this->request->post('location', ''));
        SupplyChainSchema::ensure($this->db);
        $sql = "UPDATE products SET code = ?, barcode = ?, name = ?, product_type = ?, description = ?, category_id = ?,
                purchase_price = ?, sale_price = ?, stock = ?, min_stock = ?, unit = ?, status = ?,
                track_lots = ?, expiry_alert_days = ?, max_stock = ?, location = ?";
        $params = [
            $code,
            $barcode !== '' ? $barcode : null,
            $name,
            $productType,
            $this->request->post('description'),
            $this->request->post('category_id') ?: null,
            $this->request->post('purchase_price', 0),
            $this->request->post('sale_price', 0),
            $newStock,
            $this->request->post('min_stock', 5),
            $this->request->post('unit', 'UNIDAD'),
            $this->request->post('status', 'active'),
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
        
        $oldStock = (int)$oldProduct['stock'];
        if ($newStock !== $oldStock && $productType === 'product') {
            $diff = $newStock - $oldStock;
            $movementId = $this->stock->addMovement(
                $id,
                $diff > 0 ? 'in' : 'out',
                abs($diff),
                'adjustment',
                null,
                $diff > 0 ? 'Ajuste (+): manual de stock' : 'Ajuste (−): manual de stock',
                null,
                (float)$this->request->post('purchase_price', $oldProduct['purchase_price'] ?? 0),
                $newStock,
                null,
                $diff > 0 ? 'equity' : 'loss'
            );
            $this->postStockAccounting($movementId);
        }
        
        $this->respond(true, 'Actualizado', '/app/inventario');
    }
    
    private function addStock(): void
    {
        if (!$this->validateCsrfOrFail('/app/inventario')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $qty = (int)$this->request->post('quantity');
        $notes = $this->request->post('notes', 'Entrada de stock');
        $movementDate = trim((string)$this->request->post('movement_date', ''));
        if ($movementDate === '') {
            $movementDate = date('Y-m-d H:i:s');
        }
        
        if ($qty <= 0) {
            $this->respond(false, 'La cantidad debe ser mayor a 0', '/app/inventario');
            return;
        }
        
        $lotInput = $this->lotInput();
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
            ]
        );
        $this->postStockAccounting($movementId);

        $mensaje = "Stock aumentado en {$qty} unidades";
        if ($lotInput['expiry_date'] !== null) {
            $mensaje .= ' con vencimiento ' . $lotInput['expiry_date'];
        }
        $this->respond(true, $mensaje, '/app/inventario');
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
        
        try {
            $this->query(
                "UPDATE products SET status = 'inactive', deleted_at = NOW() WHERE id = ?",
                [$id]
            );
        } catch (\Exception $e) {
            $this->query("UPDATE products SET status = 'inactive' WHERE id = ?", [$id]);
        }
        
        $this->respond(true, 'Producto desactivado (soft-delete)', '/app/inventario');
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

        $where = ["p.status = 'active'"];
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
            $where[] = "(p.name LIKE ? OR p.code LIKE ? OR COALESCE(p.barcode,'') LIKE ? OR p.description LIKE ? OR COALESCE(p.location,'') LIKE ?)";
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->query(
            "SELECT p.id, p.code, p.barcode, p.name, p.product_type, c.name as category_name, p.purchase_price,
                    p.sale_price, p.stock, p.min_stock, p.unit, p.status, p.location
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             {$whereSql}
             ORDER BY {$filters['orderSql']}, p.name",
            $params
        )->fetchAll();
        
        $csvRows = [];
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
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
                $r['stock'],
                $r['min_stock'],
                $r['unit'],
                $r['status'],
                $r['location'] ?? '',
            ];

            // Huella en trazabilidad: exportación CSV (sin alterar stock).
            try {
                $this->stock->addMovement(
                    (int)$r['id'],
                    'adjustment',
                    0,
                    'csv_export',
                    null,
                    'Exportación CSV de catálogo',
                    $userId ?: null,
                    (float)($r['purchase_price'] ?? 0),
                    (int)($r['stock'] ?? 0),
                    null,
                    null
                );
            } catch (\Throwable $e) {
                // No bloquear la descarga si falla el registro de auditoría.
            }
        }
        
        $this->exportCsv(
            'inventario_' . date('Ymd_His') . '.csv',
            ['SKU', 'EAN', 'Nombre', 'Tipo', 'Categoria', 'Costo', 'Precio', 'Stock', 'Min', 'Unidad', 'Estado', 'Ubicacion'],
            $csvRows
        );
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
            $where = ["p.status = 'active'", "p.product_type = 'product'"];
            $params = [];
            if ($categoryId > 0) {
                $where[] = 'p.category_id = ?';
                $params[] = $categoryId;
            }
            $whereSql = implode(' AND ', $where);
            $rows = $this->query(
                "SELECT COALESCE(c.name, 'Sin categoría') as category_name,
                        p.code, p.name, p.location, p.stock, p.min_stock, p.purchase_price, p.sale_price,
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
                    $csvRows[] = [
                        $r['category_name'],
                        $r['code'],
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
                    ['Categoria', 'SKU', 'Producto', 'Ubicacion', 'Stock', 'Min', 'Costo', 'Precio', 'Valor stock'],
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
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateTraceabilityReport($result['rows'] ?? [], $filters);
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
     * Importar productos desde CSV (mismo formato que export).
     * Archivos > ~200KB se encolan para no bloquear la petición.
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

        // Archivos grandes: encolar
        if ($size > 200 * 1024) {
            $dir = STORAGE_PATH . '/uploads/csv_imports';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dest = $dir . '/import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
            if (!move_uploaded_file($tmp, $dest)) {
                $this->respond(false, 'No se pudo guardar el CSV para la cola', '/app/inventario');
                return;
            }
            (new \SoftNova\Services\JobQueue($this->db))->push('import_csv', [
                'path' => $dest,
                'user_id' => $userId,
            ]);
            $this->respond(true, 'CSV grande encolado. Ejecute "Procesar jobs" o php database/process_jobs.php', '/app/inventario');
            return;
        }

        try {
            $importer = new \SoftNova\Services\ProductCsvImporter($this->db);
            $result = $importer->importFromFile($tmp, $userId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/inventario');
            return;
        }

        $msg = sprintf(
            'Importación CSV: %d creados, %d actualizados',
            $result['created'],
            $result['updated']
        );
        if ($result['skipped'] > 0) {
            $msg .= ', ' . $result['skipped'] . ' omitidos';
        }
        $errors = $result['errors'] ?? [];
        if (!empty($errors) && count($errors) <= 5) {
            $msg .= '. ' . implode('; ', $errors);
        } elseif (!empty($errors)) {
            $msg .= '. ' . $errors[0] . ' (+' . (count($errors) - 1) . ' más)';
        }

        $this->respond(true, $msg, '/app/inventario');
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
}
