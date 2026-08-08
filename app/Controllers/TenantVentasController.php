<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\SaleService;
use SoftNova\Services\CashService;
use SoftNova\Services\ReceivableService;
use SoftNova\Services\AccountingService;
use SoftNova\Services\CustomOptionService;
use SoftNova\Services\PaymentMethodCatalog;
use SoftNova\Services\Integrations\IntegrationManager;

class TenantVentasController extends TenantController
{
    private SaleService $sales;
    private CashService $cash;
    private ReceivableService $receivables;
    private AccountingService $accounting;
    private CustomOptionService $customOptions;
    
    public function __construct()
    {
        parent::__construct();
        $this->sales = new SaleService($this->db);
        $this->cash = new CashService($this->db);
        $this->receivables = new ReceivableService($this->db);
        $this->accounting = new AccountingService($this->db);
        $this->customOptions = new CustomOptionService($this->db);
    }
    
    public function index(): void
    {
        TenantMiddleware::authorize('ventas');
        
        $action = $this->request->get('action');
        
        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('ventas', 'create');
            $this->createSale();
            return;
        }
        if ($action === 'sync_offline' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('ventas', 'create');
            $this->syncOfflineBatch();
            return;
        }
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'cancel' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('ventas', 'delete');
            $this->cancelSale();
            return;
        }
        if ($action === 'payment' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('ventas', 'create');
            $this->registerPayment();
            return;
        }
        if ($action === 'pdf' && $this->request->method() === 'GET') {
            $this->invoicePdf();
            return;
        }
        if ($action === 'report_pdf' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('ventas', 'export');
            $this->reportPdf();
            return;
        }
        if ($action === 'share' && $this->request->method() === 'GET') {
            $this->shareReceipt();
            return;
        }
        if ($action === 'email' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('ventas', 'create');
            $this->emailReceipt();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('ventas', 'export');
            $this->exportSales();
            return;
        }

        (new \SoftNova\Services\SalesDocumentService($this->db));

        $filters = $this->salesListFilters();
        $built = $this->buildSalesWhere($filters);
        $whereSql = $built['whereSql'];
        $params = $built['params'];

        $total = (int)$this->query(
            "SELECT COUNT(*) as c
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             WHERE {$whereSql}",
            $params
        )->fetch()['c'];
        $pagination = $this->paginate($total);
        
        $sales = $this->query(
            "SELECT s.*, c.name as customer_name, u.name as user_name,
                    COALESCE(p.paid_amount, 0) as paid_amount
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             LEFT JOIN users u ON s.user_id = u.id
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE {$whereSql}
             ORDER BY {$filters['orderSql']}, s.id DESC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
            $params
        )->fetchAll();
        
        $todayStats = $this->query(
            "SELECT COALESCE(SUM(total), 0) as t, COUNT(*) as c
             FROM sales
             WHERE DATE(sale_date) = CURDATE() AND status != 'cancelled'"
        )->fetch();
        $todayTotal = (float)($todayStats['t'] ?? 0);
        $todayCount = (int)($todayStats['c'] ?? 0);
        
        $products = $this->query(
            "SELECT id, name, code, sale_price, stock FROM products
             WHERE status = 'active'
             ORDER BY name"
        )->fetchAll();
        
        $customers = $this->query(
            "SELECT id, name, first_name, last_name, document_number, document_type
             FROM customers WHERE status = 'active' ORDER BY name"
        )->fetchAll();
        
        $this->view('tenant.ventas', $this->tenantViewData([
            'sales' => $sales,
            'todayTotal' => $todayTotal,
            'todayCount' => $todayCount,
            'products' => $products,
            'customers' => $customers,
            'pagination' => $pagination,
            'filters' => $filters,
            'cancelReasons' => $this->customOptions->options(CustomOptionService::GROUP_CANCEL_REASON),
            'paymentMethodOptionsHtml' => PaymentMethodCatalog::optionsHtml('cash', true, $this->db),
            'paymentMethodFilterHtml' => PaymentMethodCatalog::optionsHtml((string)($filters['payment_method'] ?? ''), false, $this->db),
        ]));
    }

    /**
     * @return array{
     *   q:string,from:string,to:string,status:string,customer_id:string,payment_method:string,
     *   record_status:string,sort:string,dir:string,orderSql:string,query:array
     * }
     */
    private function salesListFilters(): array
    {
        $base = $this->listFilters([
            'invoice' => 's.invoice_number',
            'customer' => 'c.name',
            'date' => 's.sale_date',
            'total' => 's.total',
            'paid' => 'paid_amount',
            'method' => 's.payment_method',
            'status' => 's.payment_status',
        ], 'date', 'desc');

        $customerId = trim((string)$this->request->get('customer_id', ''));
        if ($customerId !== '' && !ctype_digit($customerId)) {
            $customerId = '';
        }
        $paymentMethod = strtolower(trim((string)$this->request->get('payment_method', '')));
        if ($paymentMethod === '__other__' || $paymentMethod === 'other') {
            $paymentMethod = '';
        }
        if ($paymentMethod !== '' && !preg_match('/^[a-z][a-z0-9_]{0,59}$/', $paymentMethod)) {
            $paymentMethod = '';
        }
        $recordStatus = trim((string)$this->request->get('record_status', 'active'));
        if (!in_array($recordStatus, ['active', 'cancelled', 'all'], true)) {
            $recordStatus = 'active';
        }

        $base['customer_id'] = $customerId;
        $base['payment_method'] = $paymentMethod;
        $base['record_status'] = $recordStatus;
        $base['query'] = array_filter([
            'q' => $base['q'] !== '' ? $base['q'] : null,
            'from' => $base['from'] !== '' ? $base['from'] : null,
            'to' => $base['to'] !== '' ? $base['to'] : null,
            'status' => $base['status'] !== '' ? $base['status'] : null,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
            'record_status' => $recordStatus !== 'active' ? $recordStatus : null,
            'sort' => $base['sort'] !== 'date' ? $base['sort'] : null,
            'dir' => ($base['sort'] !== 'date' || $base['dir'] !== 'desc') ? $base['dir'] : null,
        ], static fn($v) => $v !== null && $v !== '');

        return $base;
    }

    /** @return array{whereSql:string,params:list<mixed>} */
    private function buildSalesWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['record_status'] ?? 'active') === 'cancelled') {
            $where[] = "s.status = 'cancelled'";
        } elseif (($filters['record_status'] ?? 'active') !== 'all') {
            $where[] = "s.status != 'cancelled'";
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(s.invoice_number LIKE ? OR c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.document_number LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'DATE(s.sale_date) >= ?';
            $params[] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'DATE(s.sale_date) <= ?';
            $params[] = $filters['to'];
        }
        if (in_array($filters['status'] ?? '', ['paid', 'partial', 'pending'], true)) {
            $where[] = 's.payment_status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['customer_id'] ?? '') !== '') {
            $where[] = 's.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }
        if (($filters['payment_method'] ?? '') !== '') {
            $where[] = 's.payment_method = ?';
            $params[] = $filters['payment_method'];
        }

        if ($where === []) {
            $where[] = '1=1';
        }

        return ['whereSql' => implode(' AND ', $where), 'params' => $params];
    }

    /** @return list<array<string,mixed>> */
    private function fetchFilteredSales(array $filters, int $limit = 5000): array
    {
        $built = $this->buildSalesWhere($filters);
        $limit = max(1, min(10000, $limit));
        return $this->query(
            "SELECT s.*, COALESCE(c.name, 'General') as customer_name, u.name as user_name,
                    COALESCE(p.paid_amount, 0) as paid_amount
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             LEFT JOIN users u ON s.user_id = u.id
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                FROM sale_payments
                GROUP BY sale_id
             ) p ON p.sale_id = s.id
             WHERE {$built['whereSql']}
             ORDER BY {$filters['orderSql']}, s.id DESC
             LIMIT {$limit}",
            $built['params']
        )->fetchAll();
    }
    
    public function detail(): void
    {
        $id = (int)$this->request->get('id');
        $sale = $this->query(
            "SELECT s.*, c.name as customer_name, c.first_name, c.last_name, u.name as user_name
             FROM sales s
             LEFT JOIN customers c ON s.customer_id = c.id
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.id = ?",
            [$id]
        )->fetch();
        
        if (!$sale) {
            $this->json(['error' => 'Venta no encontrada']);
            return;
        }
        
        $items = $this->query("SELECT * FROM sale_items WHERE sale_id = ?", [$id])->fetchAll();
        $payments = $this->query(
            "SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY payment_date DESC",
            [$id]
        )->fetchAll();
        
        $this->json(['sale' => $sale, 'items' => $items, 'payments' => $payments]);
    }
    
    /**
     * Sync por lotes de ventas capturadas offline.
     * Respuesta por venta: ok | idempotent | conflict | retry.
     * La contabilidad solo corre en servidor tras un commit exitoso.
     */
    private function syncOfflineBatch(): void
    {
        $body = $this->readJsonBody();
        // CSRF desde JSON: php://input no llena $_POST.
        if (!empty($body['csrf_token']) && empty($_POST['csrf_token'])) {
            $_POST['csrf_token'] = (string)$body['csrf_token'];
        }
        if (!$this->validateCsrf()) {
            $this->json([
                'success' => false,
                'message' => 'Token de seguridad inválido o expirado. Recargue Caja e intente de nuevo.',
                'results' => [],
                'summary' => ['ok' => 0, 'conflict' => 0, 'retry' => 0, 'idempotent' => 0],
                'code' => 'csrf',
            ], 419);
            return;
        }

        $sales = $body['sales'] ?? $this->request->post('sales', []);
        if (is_string($sales)) {
            $decoded = json_decode($sales, true);
            $sales = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($sales) || $sales === []) {
            $this->json([
                'success' => false,
                'message' => 'No hay ventas para sincronizar',
                'results' => [],
                'summary' => ['ok' => 0, 'conflict' => 0, 'retry' => 0, 'idempotent' => 0],
            ]);
            return;
        }

        // Límite defensivo para no saturar la petición.
        $sales = array_slice(array_values($sales), 0, 50);
        $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
        if ($userId <= 0) {
            $this->json(['success' => false, 'message' => 'Sesión inválida', 'results' => []]);
            return;
        }

        $settings = [
            'invoice_prefix' => $this->getSetting('invoice_prefix', 'FAC-'),
            'tax_rate' => $this->getSetting('tax_rate', '0'),
        ];

        try {
            $sync = new \SoftNova\Services\OfflineSaleSyncService($this->db);
            $out = $sync->syncBatch($sales, $userId, $settings);
        } catch (\Throwable $e) {
            error_log('sync_offline: ' . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'No se pudo sincronizar: ' . $e->getMessage(),
                'results' => [],
            ], 500);
            return;
        }

        $summary = $out['summary'];
        $accepted = (int)$summary['ok'] + (int)$summary['idempotent'];
        $this->json([
            'success' => true,
            'message' => sprintf(
                'Sync: %d ok, %d ya existían, %d conflictos, %d reintentar',
                (int)$summary['ok'],
                (int)$summary['idempotent'],
                (int)$summary['conflict'],
                (int)$summary['retry']
            ),
            'accepted' => $accepted,
            'results' => $out['results'],
            'summary' => $summary,
            'accounting' => 'server_post_commit',
        ]);
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function createSale(): void
    {
        if (!$this->validateCsrfOrFail('/app/ventas')) {
            return;
        }

        (new \SoftNova\Services\SalesDocumentService($this->db));

        // Cola offline: motor unificado (idempotencia + contabilidad post-commit).
        $clientUuid = trim((string)$this->request->post('client_sale_uuid', ''));
        if ($clientUuid !== '' && preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $clientUuid)) {
            $notes = trim((string)$this->request->post('notes', ''));
            $isOfflineSync = $this->request->post('offline_sync') === '1'
                || str_contains($notes, 'Offline');
            if ($isOfflineSync) {
                $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
                $sync = new \SoftNova\Services\OfflineSaleSyncService($this->db);
                $result = $sync->syncOne($_POST, $userId, [
                    'invoice_prefix' => $this->getSetting('invoice_prefix', 'FAC-'),
                    'tax_rate' => $this->getSetting('tax_rate', '0'),
                ]);
                $ok = in_array($result['status'] ?? '', ['ok', 'idempotent'], true);
                $this->respond(
                    $ok,
                    (string)($result['message'] ?? ($ok ? 'Venta sincronizada' : 'No se pudo sincronizar')),
                    '/app/ventas',
                    [
                        'sale_id' => $result['sale_id'] ?? null,
                        'invoice_number' => $result['invoice_number'] ?? null,
                        'client_sale_uuid' => $result['client_sale_uuid'] ?? $clientUuid,
                        'sync_status' => $result['status'] ?? null,
                        'code' => $result['code'] ?? null,
                        'stock_conflicts' => $result['stock_conflicts'] ?? [],
                        'idempotent' => ($result['status'] ?? '') === 'idempotent',
                        'accounting_posted' => (bool)($result['accounting_posted'] ?? false),
                    ]
                );
                return;
            }

            try {
                $existing = $this->query(
                    "SELECT id, invoice_number, total, payment_status
                     FROM sales WHERE client_sale_uuid = ? LIMIT 1",
                    [$clientUuid]
                )->fetch();
                if ($existing) {
                    $this->respond(
                        true,
                        'Venta ya registrada: ' . $existing['invoice_number'],
                        '/app/ventas',
                        [
                            'sale_id' => (int)$existing['id'],
                            'invoice_number' => (string)$existing['invoice_number'],
                            'idempotent' => true,
                        ]
                    );
                    return;
                }
            } catch (\Throwable $e) {
                // Columna aún no migrada: continuar con flujo normal.
            }
        }
        
        $customerId = $this->request->post('customer_id') ? (int)$this->request->post('customer_id') : null;
        $rawPayMethod = strtolower(trim((string)$this->request->post('payment_method', 'cash')));
        if ($rawPayMethod === '__other__' || $rawPayMethod === 'other') {
            $this->respond(false, 'Seleccione un método de pago o cree uno con la opción Otro', '/app/ventas');
            return;
        }
        $paymentMethod = PaymentMethodCatalog::normalize($rawPayMethod);
        $paymentType = $this->request->post('payment_type', 'full');
        $documentType = (string)$this->request->post('document_type', 'invoice');
        if (!isset(\SoftNova\Services\SalesDocumentService::documentTypes()[$documentType])) {
            $documentType = 'invoice';
        }
        $paymentTerms = (string)$this->request->post('payment_terms', $paymentType === 'credit' ? 'net_30' : 'cash');
        if (!isset(\SoftNova\Services\SalesDocumentService::paymentTerms()[$paymentTerms])) {
            $paymentTerms = $paymentType === 'credit' ? 'net_30' : 'cash';
        }
        $saleDate = trim((string)$this->request->post('sale_date', date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = date('Y-m-d');
        }
        $dueDateInput = trim((string)$this->request->post('due_date', ''));
        $receivedDate = trim((string)$this->request->post('received_date', ''));
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateInput)
            ? $dueDateInput
            : \SoftNova\Services\SalesDocumentService::resolveDueDate($paymentTerms, $saleDate);
        if ($receivedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) {
            $receivedDate = '';
        }
        $initialPayment = (float)$this->request->post('initial_payment', 0);
        $items = $this->request->post('items', []);
        $notes = trim((string)$this->request->post('notes', ''));
        $discountPercent = (float)$this->request->post('discount_percent', 0);
        if ($discountPercent < 0) {
            $discountPercent = 0;
        }
        if ($discountPercent > 100) {
            $discountPercent = 100;
        }
        
        if (empty($items) || !is_array($items)) {
            $this->respond(false, 'Debe agregar al menos un producto', '/app/ventas');
            return;
        }

        $prefix = \SoftNova\Services\SalesDocumentService::prefixFor($documentType);
        if ($documentType === 'invoice') {
            $prefix = $this->getSetting('invoice_prefix', 'FAC-');
        }
        $invoiceNumber = $prefix . date('Ymd', strtotime($saleDate)) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        
        $subtotal = 0;
        $validItems = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $product = $this->query(
                "SELECT * FROM products WHERE id = ? AND status = 'active'",
                [$productId]
            )->fetch();
            if (!$product) {
                continue;
            }
            $up = (float)$product['sale_price'];
            $is = $up * $quantity;
            $subtotal += $is;
            $validItems[] = [
                'product_id' => $productId,
                'product_name' => $product['name'],
                'quantity' => $quantity,
                'unit_price' => $up,
                'unit_cost' => (float)($product['purchase_price'] ?? 0),
                'subtotal' => $is,
                'product_type' => $product['product_type'] ?? 'product',
            ];
        }
        
        if (empty($validItems)) {
            $this->respond(false, 'No se encontraron productos validos', '/app/ventas');
            return;
        }
        
        $discount = round($subtotal * ($discountPercent / 100), 2);
        $taxable = max(0, $subtotal - $discount);
        $taxRate = (float)$this->getSetting('tax_rate', '0');
        $tax = round($taxable * ($taxRate / 100), 2);
        $total = $taxable + $tax;

        if ($discountPercent > 0) {
            $discountNote = 'Descuento ' . rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '%';
            $notes = $notes !== '' ? ($notes . ' · ' . $discountNote) : $discountNote;
        }
        
        $paymentStatus = $paymentType === 'credit' ? 'pending' : 'paid';
        $initialPayment = max(0, $initialPayment);
        if ($paymentType === 'credit') {
            $initialPayment = min($initialPayment, $total);
            if ($initialPayment >= $total && $total > 0) {
                $paymentStatus = 'paid';
            }
        }
        
        try {
            $this->db->beginTransaction();
            
            try {
                $this->query(
                    "INSERT INTO sales
                        (invoice_number, document_type, customer_id, user_id, sale_date, due_date, received_date,
                         subtotal, tax, discount, total, payment_method, payment_terms, payment_status, notes,
                         client_sale_uuid, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $invoiceNumber,
                        $documentType,
                        $customerId,
                        $_SESSION['tenant_user_id'],
                        $saleDate . ' ' . date('H:i:s'),
                        $dueDate,
                        $receivedDate !== '' ? $receivedDate : null,
                        $subtotal,
                        $tax,
                        $discount,
                        $total,
                        $paymentMethod,
                        $paymentTerms,
                        $paymentStatus,
                        $notes,
                        $clientUuid !== '' ? $clientUuid : null,
                        $paymentStatus === 'paid' ? 'completed' : 'pending',
                    ]
                );
            } catch (\PDOException $e) {
                // Carrera: otro reintento offline insertó el mismo UUID entre el SELECT y el INSERT.
                if ($clientUuid !== '' && str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $dup = $this->query(
                        "SELECT id, invoice_number FROM sales WHERE client_sale_uuid = ? LIMIT 1",
                        [$clientUuid]
                    )->fetch();
                    if ($dup) {
                        $this->respond(
                            true,
                            'Venta ya registrada: ' . $dup['invoice_number'],
                            '/app/ventas',
                            [
                                'sale_id' => (int)$dup['id'],
                                'invoice_number' => (string)$dup['invoice_number'],
                                'idempotent' => true,
                            ]
                        );
                        return;
                    }
                }
                throw $e;
            }
            $saleId = (int)$this->db->lastInsertId();
            
            foreach ($validItems as $vi) {
                $this->query(
                    "INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$saleId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['unit_cost'] ?? 0, $vi['subtotal']]
                );
                $this->sales->stock()->decrease(
                    $vi['product_id'],
                    $vi['quantity'],
                    'sale',
                    $saleId,
                    'Venta: ' . $invoiceNumber,
                    (float)($vi['unit_cost'] ?? 0)
                );
            }
            
            $cashAmount = 0.0;
            $paidNow = $paymentType === 'credit' ? $initialPayment : $total;
            if ($paidNow > 0) {
                $this->query(
                    "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $saleId,
                        $paidNow,
                        $paymentMethod,
                        $paymentType === 'credit' ? 'Pago inicial - credito' : 'Pago completo',
                        $_SESSION['tenant_user_id'],
                    ]
                );
                $cashAmount = $paidNow;
                if ($paymentType === 'credit' && $paidNow < $total) {
                    $this->query("UPDATE sales SET payment_status = 'partial' WHERE id = ?", [$saleId]);
                }
            }

            $this->db->commit();

            // Contabilización fuera de la transacción: un periodo cerrado u otro
            // problema contable NO debe impedir registrar la venta.
            try {
                $this->accounting->postSaleCascade($saleId);
            } catch (\Throwable $e) {
                error_log('Contabilidad venta ' . $saleId . ': ' . $e->getMessage());
            }

            try {
                (new \SoftNova\Services\CommissionService($this->db))->processSale($saleId);
            } catch (\Throwable $e) {
                error_log('Comisiones venta ' . $saleId . ': ' . $e->getMessage());
            }

            // Solo el efectivo entra a la caja física; tarjeta/transferencia van al
            // banco en contabilidad, por lo que no deben inflar el arqueo de caja.
            if ($cashAmount > 0 && \SoftNova\Services\PaymentMethodCatalog::affectsCash($paymentMethod)) {
                $label = ($paymentType === 'credit' && $paymentStatus !== 'paid')
                    ? 'Abono inicial: ' . $invoiceNumber
                    : 'Venta: ' . $invoiceNumber;
                $this->cash->registerIncome($cashAmount, $label, 'sale', $saleId);
            }
            
            // Credito / pago parcial: crear tarea de cuentas por cobrar
            if ($paymentType === 'credit') {
                $paidForRx = min($initialPayment, $total);
                $finalStatus = $this->query("SELECT payment_status FROM sales WHERE id = ?", [$saleId])->fetch()['payment_status'] ?? $paymentStatus;
                if ($finalStatus !== 'paid') {
                    $this->receivables->upsertFromSale(
                        $saleId,
                        $customerId,
                        $invoiceNumber,
                        $total,
                        $paidForRx,
                        $notes ?: null,
                        $dueDate
                    );
                }
            }

            // Factura electrónica solo si el documento es electrónico
            $einvoiceNote = '';
            if ($documentType === 'electronic') {
                try {
                    $emit = (new IntegrationManager($this->db))->emitSale($saleId);
                    if (!empty($emit['success'])) {
                        $einvoiceNote = ' · FE: ' . ($emit['message'] ?? 'OK');
                    } elseif (($emit['message'] ?? '') !== 'No hay proveedor de facturación activo') {
                        $einvoiceNote = ' · FE pendiente: ' . ($emit['message'] ?? 'error');
                    }
                } catch (\Throwable $e) {
                    $einvoiceNote = ' · FE no enviada';
                    error_log('emitSale: ' . $e->getMessage());
                }
            }
            
            $msg = $paymentType === 'credit' && $paymentStatus !== 'paid'
                ? 'Venta a credito creada: ' . $invoiceNumber . ' (Pendiente: ' . $this->formatMoney($total - $initialPayment) . ')'
                : 'Venta creada: ' . $invoiceNumber;

            try {
                (new \SoftNova\Services\TenantAuditService($this->db))->log(
                    'create',
                    'ventas',
                    'Venta creada: ' . $invoiceNumber . ' total=' . $total,
                    'sale',
                    $saleId
                );
            } catch (\Throwable $e) {
                error_log('audit createSale: ' . $e->getMessage());
            }

            $this->respond(true, $msg . $einvoiceNote, '/app/ventas', [
                'sale_id' => $saleId,
                'invoice_number' => $invoiceNumber,
                'client_sale_uuid' => $clientUuid !== '' ? $clientUuid : null,
            ]);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            try {
                \SoftNova\Services\TenantNotifyService::saleFailedOrCancelled(
                    $this->db,
                    'failed',
                    'Error al crear venta: ' . $e->getMessage()
                );
            } catch (\Throwable $ignore) {
            }
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/ventas');
        }
    }
    
    private function registerPayment(): void
    {
        if (!$this->validateCsrfOrFail('/app/ventas')) {
            return;
        }
        
        $saleId = (int)$this->request->post('sale_id');
        $amount = (float)$this->request->post('amount');
        $method = \SoftNova\Services\PaymentMethodCatalog::normalize($this->request->post('payment_method', 'cash'));
        $notes = $this->request->post('notes', 'Abono');
        
        if ($amount <= 0) {
            $this->respond(false, 'El monto debe ser mayor a cero', '/app/ventas');
            return;
        }
        
        try {
            $this->db->beginTransaction();
            
            $sale = $this->query(
                "SELECT * FROM sales WHERE id = ? AND payment_status IN ('pending', 'partial') FOR UPDATE",
                [$saleId]
            )->fetch();
            
            if (!$sale) {
                $this->db->rollBack();
                $this->respond(false, 'Venta no encontrada o ya esta pagada', '/app/ventas');
                return;
            }
            
            $alreadyPaid = (float)$this->query(
                "SELECT COALESCE(SUM(amount), 0) as t FROM sale_payments WHERE sale_id = ?",
                [$saleId]
            )->fetch()['t'];
            $balance = max(0, round((float)$sale['total'] - $alreadyPaid, 2));
            
            if ($balance <= 0) {
                $this->db->rollBack();
                $this->respond(false, 'Esta venta ya no tiene saldo pendiente', '/app/ventas');
                return;
            }
            
            if ($amount > $balance + 0.009) {
                $this->db->rollBack();
                $this->respond(false, 'El abono no puede superar el saldo pendiente (' . $this->formatMoney($balance) . ')', '/app/ventas');
                return;
            }
            
            $amount = min($amount, $balance);
            
            $this->query(
                "INSERT INTO sale_payments (sale_id, amount, payment_date, payment_method, notes, user_id)
                 VALUES (?, ?, NOW(), ?, ?, ?)",
                [$saleId, $amount, $method, $notes, $_SESSION['tenant_user_id']]
            );
            $paymentId = (int)$this->db->lastInsertId();
            
            $totalPaid = round($alreadyPaid + $amount, 2);
            $remaining = round((float)$sale['total'] - $totalPaid, 2);
            
            if ($remaining <= 0.009) {
                $this->query("UPDATE sales SET payment_status = 'paid', status = 'completed' WHERE id = ?", [$saleId]);
                $remaining = 0;
            } else {
                $this->query("UPDATE sales SET payment_status = 'partial' WHERE id = ?", [$saleId]);
            }

            $this->db->commit();

            // Contabilización fuera de la transacción: un periodo cerrado no debe
            // impedir registrar el abono.
            try {
                $this->accounting->postSalePayment($paymentId);
            } catch (\Throwable $e) {
                error_log('Contabilidad abono ' . $paymentId . ': ' . $e->getMessage());
            }

            try {
                (new \SoftNova\Services\CommissionService($this->db))->processPayment($paymentId);
            } catch (\Throwable $e) {
                error_log('Comisiones abono ' . $paymentId . ': ' . $e->getMessage());
            }

            // Solo el efectivo entra a la caja física (evita descuadre con contabilidad).
            if (\SoftNova\Services\PaymentMethodCatalog::affectsCash($method)) {
                $this->cash->registerIncome($amount, 'Abono: ' . $sale['invoice_number'], 'sale_payment', $saleId);
            }
            $this->receivables->applyPayment($saleId, $totalPaid, (float)$sale['total']);
            
            $this->respond(true, 'Abono registrado. Pendiente: ' . $this->formatMoney(max(0, $remaining)), '/app/ventas');
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/ventas');
        }
    }
    
    private function cancelSale(): void
    {
        if (!$this->validateCsrfOrFail('/app/ventas')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $reasonCode = trim((string)$this->request->post('cancel_reason', ''));
        $reasonOther = trim((string)$this->request->post('cancel_reason_other', ''));
        $invoiceLabel = '#' . $id;
        try {
            $row = $this->query("SELECT invoice_number FROM sales WHERE id = ?", [$id])->fetch();
            if ($row && !empty($row['invoice_number'])) {
                $invoiceLabel = (string)$row['invoice_number'];
            }
        } catch (\Throwable $e) {
        }

        try {
            if ($reasonCode === '' || $reasonCode === '__other__') {
                if ($reasonOther === '') {
                    throw new \InvalidArgumentException('Seleccione o escriba el motivo de la eliminación');
                }
                $added = $this->customOptions->add(CustomOptionService::GROUP_CANCEL_REASON, $reasonOther);
                $reasonCode = $added['value'];
                $reasonLabel = $added['label'];
            } else {
                $reasonLabel = $this->customOptions->label(CustomOptionService::GROUP_CANCEL_REASON, $reasonCode);
                if ($reasonLabel === $reasonCode && $reasonOther !== '') {
                    $reasonLabel = $reasonOther;
                }
            }

            $result = $this->sales->cancelSale($id, $reasonLabel, $reasonCode);
            $this->receivables->cancelBySale($id);
            $msg = 'Venta eliminada. Stock devuelto.';
            try {
                $this->accounting->reverseSaleCascade($id, 'Venta cancelada: ' . $reasonLabel);
            } catch (\Throwable $accountingError) {
                error_log('Accounting sale reversal: ' . $accountingError->getMessage());
                $msg .= ' La reversión contable quedó pendiente de revisión.';
            }
            try {
                (new \SoftNova\Services\CommissionService($this->db))->cancelForSale($id);
            } catch (\Throwable $e) {
                error_log('Comisiones cancel venta ' . $id . ': ' . $e->getMessage());
            }
            if ($result['reversed_cash'] > 0) {
                $msg .= ' Caja ajustada: -' . $this->formatMoney($result['reversed_cash']);
            }

            try {
                (new \SoftNova\Services\TenantAuditService($this->db))->log(
                    'cancel',
                    'ventas',
                    'Venta cancelada: ' . $invoiceLabel . ' · Motivo: ' . $reasonLabel,
                    'sale',
                    $id
                );
                \SoftNova\Services\TenantNotifyService::saleFailedOrCancelled(
                    $this->db,
                    'cancelled',
                    'Cancelada: ' . $invoiceLabel . ' (' . $reasonLabel . ')',
                    $id
                );
            } catch (\Throwable $e) {
                error_log('audit/notify cancelSale: ' . $e->getMessage());
            }

            $this->respond(true, $msg, '/app/ventas');
        } catch (\Exception $e) {
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/ventas');
        }
    }
    
    private function invoicePdf(): void
    {
        try {
            $id = (int)$this->request->get('id');
            $format = (string)$this->request->get('format', 'a4');
            if ($format !== 'ticket') {
                $format = 'a4';
            }
            $sale = $this->query(
                "SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?",
                [$id]
            )->fetch();

            if (!$sale) {
                http_response_code(404);
                echo 'Venta no encontrada';
                exit;
            }

            $items = $this->query("SELECT * FROM sale_items WHERE sale_id = ?", [$id])->fetchAll();
            $payments = $this->query(
                "SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY payment_date DESC",
                [$id]
            )->fetchAll();

            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateInvoice($sale, $items ?: [], $payments ?: [], $format);
            $prefix = $format === 'ticket' ? 'Ticket_' : 'Doc_';
            $pdf->download($content, $prefix . ($sale['invoice_number'] ?? $id) . '.pdf');
        } catch (\Throwable $e) {
            error_log('invoicePdf: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "No se pudo generar el PDF.\n" . $e->getMessage();
            exit;
        }
    }

    private function shareReceipt(): void
    {
        $id = (int)$this->request->get('id', 0);
        $share = new \SoftNova\Services\ReceiptShareService($this->db, $this->companySettings(), $this->getCurrency());
        $sale = $share->saleSummary($id);
        if (!$sale) {
            $this->json(['success' => false, 'message' => 'Venta no encontrada']);
            return;
        }
        $this->json([
            'success' => true,
            'whatsapp_url' => $share->whatsappUrl($sale),
            'text' => $share->buildText($sale),
            'customer_email' => $sale['customer_email'] ?? '',
            'customer_phone' => $sale['customer_phone'] ?? '',
        ]);
    }

    private function emailReceipt(): void
    {
        if (!$this->validateCsrfOrFail('/app/ventas')) {
            return;
        }
        $id = (int)$this->request->post('id', 0);
        $email = trim((string)$this->request->post('email', ''));
        $share = new \SoftNova\Services\ReceiptShareService($this->db, $this->companySettings(), $this->getCurrency());
        $sale = $share->saleSummary($id);
        if (!$sale) {
            $this->respond(false, 'Venta no encontrada', '/app/ventas');
            return;
        }
        if ($email === '') {
            $email = (string)($sale['customer_email'] ?? '');
        }
        $result = $share->sendEmail($sale, $email);
        $this->respond($result['success'], $result['message'], '/app/ventas');
    }
    
    private function reportPdf(): void
    {
        try {
            $filters = $this->salesListFilters();
            $sales = $this->fetchFilteredSales($filters, 5000);
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateSalesListReport($sales, $filters, $this->db);
            $pdf->download($content, 'reporte_ventas_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            error_log('reportPdf ventas: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "No se pudo generar el PDF.\n" . $e->getMessage();
            exit;
        }
    }

    private function exportSales(): void
    {
        $filters = $this->salesListFilters();
        $rows = $this->fetchFilteredSales($filters, 10000);
        
        $csvRows = [];
        foreach ($rows as $r) {
            $payLabel = PaymentMethodCatalog::label((string)$r['payment_method'], $this->db);
            $statusLabel = (($r['status'] ?? '') === 'cancelled')
                ? 'Cancelado'
                : ($r['payment_status'] === 'paid' ? 'Pagado' : ($r['payment_status'] === 'partial' ? 'Parcial' : 'Pendiente'));
            $csvRows[] = [
                $r['invoice_number'],
                $r['customer_name'],
                $r['sale_date'],
                $r['subtotal'],
                $r['tax'],
                $r['total'],
                $payLabel,
                $r['payment_status'],
                $statusLabel,
                $r['cancel_reason'] ?? '',
            ];
        }
        
        $this->exportCsv(
            'ventas_' . date('Ymd_His') . '.csv',
            ['Factura', 'Cliente', 'Fecha', 'Subtotal', 'Impuesto', 'Total', 'Metodo', 'Pago', 'Estado', 'Motivo cancelacion'],
            $csvRows
        );
    }
}
