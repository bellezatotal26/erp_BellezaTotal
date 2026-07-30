<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\SaleService;
use SoftNova\Services\CashService;
use SoftNova\Services\ReceivableService;
use SoftNova\Services\AccountingService;

class TenantCotizacionesController extends TenantController
{
    private SaleService $sales;
    private CashService $cash;
    private AccountingService $accounting;
    
    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('cotizaciones');
        $this->ensureQuotesSchema();
        $this->sales = new SaleService($this->db);
        $this->cash = new CashService($this->db);
        $this->accounting = new AccountingService($this->db);
    }
    
    public function index(): void
    {
        $action = $this->request->get('action');
        
        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('cotizaciones', 'create');
            $this->createQuote();
            return;
        }
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'delete' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('cotizaciones', 'delete');
            $this->deleteQuote();
            return;
        }
        if ($action === 'convert' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('cotizaciones', 'create');
            $this->convertToSale();
            return;
        }
        if ($action === 'pdf' && $this->request->method() === 'GET') {
            $this->quotePdf();
            return;
        }
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('cotizaciones', 'export');
            $this->exportQuotes();
            return;
        }

        $filters = $this->listFilters([
            'number' => 'q.quote_number',
            'customer' => 'c.name',
            'date' => 'q.quote_date',
            'total' => 'q.total',
            'valid' => 'q.valid_until',
            'status' => 'q.status',
        ], 'date', 'desc');

        $where = ['1=1'];
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = '(q.quote_number LIKE ? OR c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.document_number LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($filters['from'] !== '') {
            $where[] = 'DATE(q.quote_date) >= ?';
            $params[] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'DATE(q.quote_date) <= ?';
            $params[] = $filters['to'];
        }
        if (in_array($filters['status'], ['pending', 'accepted', 'converted', 'rejected'], true)) {
            $where[] = 'q.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)$this->query(
            "SELECT COUNT(*) as c
             FROM quotes q
             LEFT JOIN customers c ON q.customer_id = c.id
             WHERE {$whereSql}",
            $params
        )->fetch()['c'];
        $pagination = $this->paginate($total);
        
        $quotes = $this->query(
            "SELECT q.*, c.name as customer_name, u.name as user_name
             FROM quotes q
             LEFT JOIN customers c ON q.customer_id = c.id
             LEFT JOIN users u ON q.user_id = u.id
             WHERE {$whereSql}
             ORDER BY {$filters['orderSql']}, q.id DESC
             LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
            $params
        )->fetchAll();
        
        $products = $this->query(
            "SELECT id, name, code, sale_price, stock FROM products WHERE status = 'active' ORDER BY name"
        )->fetchAll();
        $customers = $this->query(
            "SELECT id, name, first_name, last_name FROM customers WHERE status = 'active' ORDER BY name"
        )->fetchAll();
        
        $this->view('tenant.cotizaciones', $this->tenantViewData([
            'quotes' => $quotes,
            'products' => $products,
            'customers' => $customers,
            'pagination' => $pagination,
            'filters' => $filters,
        ]));
    }
    
    public function detail(): void
    {
        $id = (int)$this->request->get('id');
        $quote = $this->query(
            "SELECT q.*, c.name as customer_name FROM quotes q LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ?",
            [$id]
        )->fetch();
        
        if (!$quote) {
            $this->json(['error' => 'Cotizacion no encontrada']);
            return;
        }
        
        $items = $this->query("SELECT * FROM quote_items WHERE quote_id = ?", [$id])->fetchAll();
        $this->json(['quote' => $quote, 'items' => $items]);
    }
    
    private function createQuote(): void
    {
        if (!$this->validateCsrfOrFail('/app/cotizaciones')) {
            return;
        }
        
        $customerId = $this->request->post('customer_id') ? (int)$this->request->post('customer_id') : null;
        $items = $this->request->post('items', []);
        $notes = $this->request->post('notes', '');
        $validUntil = $this->request->post('valid_until') ?: date('Y-m-d', strtotime('+15 days'));
        
        if (empty($items) || !is_array($items)) {
            $this->respond(false, 'Debe agregar productos', '/app/cotizaciones');
            return;
        }
        
        $quoteNumber = 'COT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        
        $subtotal = 0;
        $validItems = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $product = $this->query("SELECT * FROM products WHERE id = ? AND status = 'active'", [$productId])->fetch();
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
                'subtotal' => $is,
            ];
        }
        
        if (empty($validItems)) {
            $this->respond(false, 'Productos no validos', '/app/cotizaciones');
            return;
        }
        
        $taxRate = (float)$this->getSetting('tax_rate', '0');
        $tax = $subtotal * ($taxRate / 100);
        $total = $subtotal + $tax;
        
        try {
            $this->db->beginTransaction();
            $this->query(
                "INSERT INTO quotes (quote_number, customer_id, user_id, subtotal, tax, total, notes, valid_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$quoteNumber, $customerId, $_SESSION['tenant_user_id'], $subtotal, $tax, $total, $notes, $validUntil]
            );
            $quoteId = (int)$this->db->lastInsertId();
            foreach ($validItems as $vi) {
                $this->query(
                    "INSERT INTO quote_items (quote_id, product_id, product_name, quantity, unit_price, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$quoteId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['subtotal']]
                );
            }
            $this->db->commit();
            $this->respond(true, 'Cotizacion creada: ' . $quoteNumber, '/app/cotizaciones');
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/cotizaciones');
        }
    }
    
    private function deleteQuote(): void
    {
        if (!$this->validateCsrfOrFail('/app/cotizaciones')) {
            return;
        }
        $id = (int)$this->request->post('id');
        if ($id <= 0) {
            $this->respond(false, 'Cotizacion invalida', '/app/cotizaciones');
            return;
        }
        
        $quote = $this->query("SELECT id, status FROM quotes WHERE id = ?", [$id])->fetch();
        if (!$quote) {
            $this->respond(false, 'Cotizacion no encontrada', '/app/cotizaciones');
            return;
        }
        if (($quote['status'] ?? '') === 'converted') {
            $this->respond(false, 'No se puede eliminar una cotizacion ya convertida a venta', '/app/cotizaciones');
            return;
        }
        
        try {
            $this->db->beginTransaction();
            // Por si la FK no tiene CASCADE en tenants antiguos
            $this->query("DELETE FROM quote_items WHERE quote_id = ?", [$id]);
            $stmt = $this->query("DELETE FROM quotes WHERE id = ?", [$id]);
            $this->db->commit();
            
            if ($stmt->rowCount() < 1) {
                $this->respond(false, 'No se pudo eliminar la cotizacion', '/app/cotizaciones');
                return;
            }
            
            $this->respond(true, 'Cotizacion eliminada', '/app/cotizaciones');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'Error al eliminar: ' . $e->getMessage(), '/app/cotizaciones');
        }
    }
    
    private function convertToSale(): void
    {
        if (!$this->validateCsrfOrFail('/app/cotizaciones')) {
            return;
        }
        
        $id = (int)$this->request->post('id');
        $paymentMethod = $this->request->post('payment_method', 'cash');
        $paymentType = $this->request->post('payment_type', 'full');
        $initialPayment = (float)$this->request->post('initial_payment', 0);
        
        $quote = $this->query(
            "SELECT * FROM quotes WHERE id = ? AND status IN ('pending', 'accepted')",
            [$id]
        )->fetch();
        
        if (!$quote) {
            $this->respond(false, 'Cotizacion no encontrada o ya convertida', '/app/cotizaciones');
            return;
        }
        
        $items = $this->query("SELECT * FROM quote_items WHERE quote_id = ?", [$id])->fetchAll();
        if (empty($items)) {
            $this->respond(false, 'La cotizacion no tiene productos', '/app/cotizaciones');
            return;
        }
        
        $prefix = $this->getSetting('invoice_prefix', 'FAC-');
        $invoiceNumber = $prefix . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        
        $paymentStatus = $paymentType === 'credit' ? 'pending' : 'paid';
        if ($paymentType === 'credit' && $initialPayment >= $quote['total']) {
            $paymentStatus = 'paid';
        }
        
        try {
            $this->db->beginTransaction();
            
            $this->query(
                "INSERT INTO sales
                    (invoice_number, customer_id, user_id, sale_date, subtotal, tax, discount, total,
                     payment_method, payment_status, notes, status)
                 VALUES (?, ?, ?, NOW(), ?, ?, 0, ?, ?, ?, ?, ?)",
                [
                    $invoiceNumber,
                    $quote['customer_id'],
                    $_SESSION['tenant_user_id'],
                    $quote['subtotal'],
                    $quote['tax'],
                    $quote['total'],
                    $paymentMethod,
                    $paymentStatus,
                    'Convertido de cotizacion: ' . $quote['quote_number'],
                    $paymentStatus === 'paid' ? 'completed' : 'pending',
                ]
            );
            $saleId = (int)$this->db->lastInsertId();
            
            foreach ($items as $item) {
                $unitCost = 0.0;
                if (!empty($item['product_id'])) {
                    $unitCost = (float)$this->query(
                        "SELECT COALESCE(purchase_price, 0) FROM products WHERE id = ?",
                        [$item['product_id']]
                    )->fetchColumn();
                }
                $this->query(
                    "INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$saleId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $unitCost, $item['subtotal']]
                );
                if (!empty($item['product_id'])) {
                    $this->sales->stock()->decrease(
                        (int)$item['product_id'],
                        (int)$item['quantity'],
                        'sale',
                        $saleId,
                        'Venta (desde cotizacion ' . $quote['quote_number'] . ')'
                    );
                }
            }
            
            $cashAmount = 0.0;
            if ($paymentType === 'credit') {
                $paidNow = max(0, min($initialPayment, (float)$quote['total']));
                if ($paidNow > 0) {
                    $this->query(
                        "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                         VALUES (?, ?, ?, ?, ?)",
                        [$saleId, $paidNow, $paymentMethod, 'Pago inicial desde cotizacion', $_SESSION['tenant_user_id']]
                    );
                    $cashAmount = $paidNow;
                    if ($paidNow >= (float)$quote['total']) {
                        $this->query("UPDATE sales SET payment_status = 'paid', status = 'completed' WHERE id = ?", [$saleId]);
                        $paymentStatus = 'paid';
                    } else {
                        $this->query("UPDATE sales SET payment_status = 'partial' WHERE id = ?", [$saleId]);
                        $paymentStatus = 'partial';
                    }
                }
            } else {
                $this->query(
                    "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                     VALUES (?, ?, ?, ?, ?)",
                    [$saleId, (float)$quote['total'], $paymentMethod, 'Pago completo desde cotizacion', $_SESSION['tenant_user_id']]
                );
                $cashAmount = (float)$quote['total'];
            }

            $this->query("UPDATE quotes SET status = 'converted', updated_at = NOW() WHERE id = ?", [$id]);
            $this->db->commit();

            // Contabilización fuera de la transacción: un periodo cerrado u otro
            // problema contable NO debe impedir convertir la cotización.
            try {
                $this->accounting->postSaleCascade($saleId);
            } catch (\Throwable $e) {
                error_log('Contabilidad venta (cotizacion) ' . $saleId . ': ' . $e->getMessage());
            }

            try {
                (new \SoftNova\Services\CommissionService($this->db))->processSale($saleId);
            } catch (\Throwable $e) {
                error_log('Comisiones venta (cotizacion) ' . $saleId . ': ' . $e->getMessage());
            }

            // Solo el efectivo entra a la caja física (evita descuadre con contabilidad).
            if ($cashAmount > 0 && $paymentMethod === 'cash') {
                $label = ($paymentType === 'credit' && $paymentStatus !== 'paid')
                    ? 'Abono inicial (cotizacion): ' . $invoiceNumber
                    : 'Venta (desde cotizacion): ' . $invoiceNumber;
                $this->cash->registerIncome($cashAmount, $label, 'sale', $saleId);
            }
            
            if ($paymentType === 'credit' && $paymentStatus !== 'paid') {
                (new ReceivableService($this->db))->upsertFromSale(
                    $saleId,
                    $quote['customer_id'] ? (int)$quote['customer_id'] : null,
                    $invoiceNumber,
                    (float)$quote['total'],
                    min($initialPayment, (float)$quote['total']),
                    'Convertido de cotizacion: ' . $quote['quote_number']
                );
            }

            $einvoiceNote = '';
            try {
                $emit = (new \SoftNova\Services\Integrations\IntegrationManager($this->db))->emitSale($saleId);
                if (!empty($emit['success'])) {
                    $einvoiceNote = ' · FE: ' . ($emit['message'] ?? 'OK');
                } elseif (($emit['message'] ?? '') !== 'No hay proveedor de facturación activo') {
                    $einvoiceNote = ' · FE pendiente: ' . ($emit['message'] ?? 'error');
                }
            } catch (\Throwable $e) {
                $einvoiceNote = ' · FE no enviada';
            }
            
            $this->respond(true, 'Cotizacion convertida a venta: ' . $invoiceNumber . $einvoiceNote, '/app/ventas');
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/cotizaciones');
        }
    }
    
    private function quotePdf(): void
    {
        $id = (int)$this->request->get('id');
        $quote = $this->query(
            "SELECT q.*, c.name as customer_name FROM quotes q LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ?",
            [$id]
        )->fetch();
        
        if (!$quote) {
            echo 'Cotizacion no encontrada';
            exit;
        }
        
        $items = $this->query("SELECT * FROM quote_items WHERE quote_id = ?", [$id])->fetchAll();
        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generateQuote($quote, $items);
        $pdf->download($content, 'Cotizacion_' . ($quote['quote_number'] ?? $id) . '.pdf');
    }
    
    private function exportQuotes(): void
    {
        $rows = $this->query(
            "SELECT q.quote_number, COALESCE(c.name, 'General') as customer_name, q.quote_date,
                    q.subtotal, q.tax, q.total, q.status, q.valid_until
             FROM quotes q
             LEFT JOIN customers c ON q.customer_id = c.id
             ORDER BY q.created_at DESC"
        )->fetchAll();
        
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                $r['quote_number'], $r['customer_name'], $r['quote_date'],
                $r['subtotal'], $r['tax'], $r['total'], $r['status'], $r['valid_until'],
            ];
        }
        
        $this->exportCsv(
            'cotizaciones_' . date('Ymd_His') . '.csv',
            ['Numero', 'Cliente', 'Fecha', 'Subtotal', 'Impuesto', 'Total', 'Estado', 'Valida hasta'],
            $csvRows
        );
    }
}
