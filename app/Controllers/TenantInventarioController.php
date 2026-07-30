<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\StockService;
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
        
        $filters = $this->listFilters([
            'name' => 'p.name',
            'code' => 'p.code',
            'category' => 'c.name',
            'price' => 'p.sale_price',
            'cost' => 'p.purchase_price',
            'stock' => 'p.stock',
            'created' => 'p.created_at',
        ], 'name', 'asc');

        $typeFilter = $this->request->get('type', '');
        $categoryFilter = (int)$this->request->get('category_id', 0);
        $stockFilter = (string)$this->request->get('stock_state', '');
        $channelFilter = (string)$this->request->get('channel', '');

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
        if ($filters['q'] !== '') {
            $where[] = "(p.name LIKE ? OR p.code LIKE ? OR p.description LIKE ? OR COALESCE(p.external_id,'') LIKE ?)";
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like);
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
        ], static fn($v) => $v !== null && $v !== '');
        $filters['query'] = array_merge($filters['query'], $extraQuery);

        $catalogStatuses = [];
        try {
            $catalogStatuses = (new CatalogSyncService($this->db))->statuses();
        } catch (\Throwable $e) {
            $catalogStatuses = [];
        }

        $this->view('tenant.inventario', $this->tenantViewData([
            'products' => $products,
            'categories' => $categories,
            'lowStock' => $lowStock,
            'totalProducts' => $totalProducts,
            'totalServices' => $totalServices,
            'typeFilter' => $typeFilter,
            'categoryFilter' => $categoryFilter,
            'stockFilter' => $stockFilter,
            'channelFilter' => $channelFilter,
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
        
        $this->json([
            'product' => $product,
            'movements' => $movements,
            'lastSales' => $lastSales,
            'purchases' => $purchases,
            'channel_label' => \SoftNova\Core\product_channel_label((string)($product['source_channel'] ?? 'manual')),
        ]);
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
        
        $code = $this->request->post('code');
        if (empty($code)) {
            $code = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        }
        
        $this->query(
            "INSERT INTO products
                (code, name, product_type, description, category_id, purchase_price, sale_price,
                 stock, min_stock, unit, image, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $code,
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
                $_SESSION['tenant_user_id'],
            ]
        );
        
        $newId = (int)$this->db->lastInsertId();
        
        if ($stock > 0 && $productType === 'product') {
            $this->stock->addMovement($newId, 'in', $stock, 'adjustment', null, 'Stock inicial (ajuste; use Compras para proveedor)');
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
        
        $sql = "UPDATE products SET code = ?, name = ?, product_type = ?, description = ?, category_id = ?,
                purchase_price = ?, sale_price = ?, stock = ?, min_stock = ?, unit = ?, status = ?";
        $params = [
            $this->request->post('code'),
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
            $this->stock->addMovement(
                $id,
                $diff > 0 ? 'in' : 'out',
                abs($diff),
                'adjustment',
                null,
                'Ajuste manual de stock'
            );
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
        
        $this->stock->increase(
            $id,
            $qty,
            'adjustment',
            null,
            $notes,
            'in',
            null,
            null,
            null,
            false,
            $movementDate
        );
        $this->respond(true, "Stock aumentado en {$qty} unidades", '/app/inventario');
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
        
        $baseUrl = \SoftNova\Core\config('app.url', 'http://localhost/SoftNova/public');
        return rtrim($baseUrl, '/') . '/uploads/products/' . $filename;
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
        ], 'name', 'asc');

        $typeFilter = $this->request->get('type', '');
        $categoryFilter = (int)$this->request->get('category_id', 0);
        $stockFilter = (string)$this->request->get('stock_state', '');

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
        if ($filters['q'] !== '') {
            $where[] = "(p.name LIKE ? OR p.code LIKE ? OR p.description LIKE ?)";
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->query(
            "SELECT p.code, p.name, p.product_type, c.name as category_name, p.purchase_price,
                    p.sale_price, p.stock, p.min_stock, p.unit, p.status
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             {$whereSql}
             ORDER BY {$filters['orderSql']}, p.name",
            $params
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['code'],
                $r['name'],
                $r['product_type'],
                $r['category_name'],
                $r['purchase_price'],
                $r['sale_price'],
                $r['stock'],
                $r['min_stock'],
                $r['unit'],
                $r['status'],
            ];
        }
        
        $this->exportCsv(
            'inventario_' . date('Ymd_His') . '.csv',
            ['SKU', 'Nombre', 'Tipo', 'Categoria', 'Costo', 'Precio', 'Stock', 'Min', 'Unidad', 'Estado'],
            $csvRows
        );
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
}
