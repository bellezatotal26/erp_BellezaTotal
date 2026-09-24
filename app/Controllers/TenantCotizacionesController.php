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
            TenantMiddleware::authorize('ventas', 'create');
            $this->convertToSale();
            return;
        }
        if ($action === 'set_status' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('cotizaciones', 'edit');
            $this->setQuoteStatus();
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
        
        $products = [];
        try {
            $products = $this->query(
                "SELECT id, name, code, sale_price, stock,
                        COALESCE(tax_rate, NULL) tax_rate, COALESCE(taxable, 1) taxable
                 FROM products WHERE status = 'active' ORDER BY name"
            )->fetchAll();
        } catch (\Throwable $e) {
            $products = $this->query(
                "SELECT id, name, code, sale_price, stock FROM products WHERE status = 'active' ORDER BY name"
            )->fetchAll();
        }
        $customers = $this->query(
            "SELECT id, name, first_name, last_name, document_number, phone
             FROM customers WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        \SoftNova\Services\TaxService::ensureSchema($this->db);
        $defaultTaxRate = \SoftNova\Services\TaxService::defaultRate($this->db);
        
        $this->view('tenant.cotizaciones', $this->tenantViewData([
            'quotes' => $quotes,
            'products' => $products,
            'customers' => $customers,
            'pagination' => $pagination,
            'filters' => $filters,
            'defaultTaxRate' => $defaultTaxRate,
            'taxEnabled' => \SoftNova\Services\TaxService::isCalculationEnabled($this->db),
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
        
        \SoftNova\Services\TaxService::ensureSchema($this->db);
        $defaultTax = \SoftNova\Services\TaxService::defaultRate($this->db);
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
                'tax_rate' => \SoftNova\Services\TaxService::resolveProductRate($product, $defaultTax, $this->db),
            ];
        }
        
        if (empty($validItems)) {
            $this->respond(false, 'Productos no validos', '/app/cotizaciones');
            return;
        }
        
        $taxBreakdown = \SoftNova\Services\TaxService::applyDocumentDiscountAndTax($validItems, 0);
        $tax = (float)$taxBreakdown['tax'];
        $validItems = $taxBreakdown['lines'];
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
                try {
                    $this->query(
                        "INSERT INTO quote_items (quote_id, product_id, product_name, quantity, unit_price, subtotal, tax_rate, tax_amount)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $quoteId,
                            $vi['product_id'],
                            $vi['product_name'],
                            $vi['quantity'],
                            $vi['unit_price'],
                            $vi['subtotal'],
                            round((float)($vi['tax_rate'] ?? 0), 2),
                            round((float)($vi['tax_amount'] ?? 0), 2),
                        ]
                    );
                } catch (\Throwable $e) {
                    $this->query(
                        "INSERT INTO quote_items (quote_id, product_id, product_name, quantity, unit_price, subtotal)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$quoteId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['subtotal']]
                    );
                }
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
    
    private function setQuoteStatus(): void
    {
        if (!$this->validateCsrfOrFail('/app/cotizaciones')) {
            return;
        }
        $id = (int)$this->request->post('id');
        $status = (string)$this->request->post('status', '');
        if ($id <= 0 || !in_array($status, ['pending', 'accepted', 'rejected'], true)) {
            $this->respond(false, 'Estado no válido', '/app/cotizaciones');
            return;
        }
        $quote = $this->query("SELECT id, status FROM quotes WHERE id = ?", [$id])->fetch();
        if (!$quote) {
            $this->respond(false, 'Cotización no encontrada', '/app/cotizaciones');
            return;
        }
        if (($quote['status'] ?? '') === 'converted') {
            $this->respond(false, 'No se puede cambiar el estado de una cotización convertida', '/app/cotizaciones');
            return;
        }
        $this->query("UPDATE quotes SET status = ?, updated_at = NOW() WHERE id = ? AND status <> 'converted'", [$status, $id]);
        $labels = ['pending' => 'pendiente', 'accepted' => 'aceptada', 'rejected' => 'rechazada'];
        $this->respond(true, 'Cotización marcada como ' . ($labels[$status] ?? $status), '/app/cotizaciones');
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
        $paymentMethod = \SoftNova\Services\PaymentMethodCatalog::normalize(
            (string)$this->request->post('payment_method', 'cash')
        );
        $paymentType = $this->request->post('payment_type', 'full');
        $initialPayment = \SoftNova\Core\parse_money($this->request->post('initial_payment', 0));

        // Efectivo requiere caja abierta (mismo criterio que Ventas).
        $willTakeCash = ($paymentType !== 'credit' && $paymentMethod === 'cash')
            || ($paymentType === 'credit' && $initialPayment > 0.009 && $paymentMethod === 'cash');
        if ($willTakeCash) {
            $openCash = $this->cash->getOpenSession();
            if (!$openCash) {
                $this->respond(false, 'Debe abrir caja para registrar cobros en efectivo', '/app/cotizaciones');
                return;
            }
        }
        
        try {
            $this->db->beginTransaction();

            $quote = $this->query(
                "SELECT * FROM quotes WHERE id = ? AND status IN ('pending', 'accepted') FOR UPDATE",
                [$id]
            )->fetch();
            
            if (!$quote) {
                throw new \RuntimeException('Cotización no encontrada o ya convertida');
            }
            
            $items = $this->query("SELECT * FROM quote_items WHERE quote_id = ?", [$id])->fetchAll();
            if (empty($items)) {
                throw new \RuntimeException('La cotización no tiene productos');
            }

            // Claim atómico: evita doble clic / race.
            $claimed = $this->query(
                "UPDATE quotes SET status = 'converted', updated_at = NOW()
                 WHERE id = ? AND status IN ('pending','accepted')",
                [$id]
            );
            if ($claimed->rowCount() < 1) {
                throw new \RuntimeException('La cotización ya fue convertida');
            }
            
            $prefix = $this->getSetting('invoice_prefix', 'FAC-');
            $invoiceNumber = $prefix . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            
            $paymentStatus = $paymentType === 'credit' ? 'pending' : 'paid';
            if ($paymentType === 'credit' && $initialPayment >= (float)$quote['total']) {
                $paymentStatus = 'paid';
            }

            $hasDocType = false;
            try {
                $col = $this->query("SHOW COLUMNS FROM sales LIKE 'document_type'")->fetch();
                $hasDocType = (bool)$col;
            } catch (\Throwable $e) {
                $hasDocType = false;
            }

            if ($hasDocType) {
                $this->query(
                    "INSERT INTO sales
                        (invoice_number, document_type, customer_id, user_id, sale_date, subtotal, tax, discount, total,
                         payment_method, payment_status, notes, status)
                     VALUES (?, 'invoice', ?, ?, NOW(), ?, ?, 0, ?, ?, ?, ?, ?)",
                    [
                        $invoiceNumber,
                        $quote['customer_id'],
                        $_SESSION['tenant_user_id'],
                        $quote['subtotal'],
                        $quote['tax'],
                        $quote['total'],
                        $paymentMethod,
                        $paymentStatus,
                        'Convertido de cotización: ' . $quote['quote_number'],
                        $paymentStatus === 'paid' ? 'completed' : 'pending',
                    ]
                );
            } else {
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
                        'Convertido de cotización: ' . $quote['quote_number'],
                        $paymentStatus === 'paid' ? 'completed' : 'pending',
                    ]
                );
            }
            $saleId = (int)$this->db->lastInsertId();

            try {
                $this->query("UPDATE quotes SET sale_id = ? WHERE id = ?", [$saleId, $id]);
            } catch (\Throwable $e) {
                // Columna sale_id aún no migrada
            }
            
            foreach ($items as $item) {
                $unitCost = 0.0;
                $isConsignment = false;
                $consignorId = 0;
                $productStock = null;
                $taxRate = round((float)($item['tax_rate'] ?? 0), 2);
                $taxAmount = round((float)($item['tax_amount'] ?? 0), 2);
                if ($taxAmount <= 0 && $taxRate > 0) {
                    $taxAmount = round((float)$item['subtotal'] * $taxRate / 100, 2);
                }
                if (!empty($item['product_id'])) {
                    $product = $this->query(
                        "SELECT COALESCE(purchase_price, 0) purchase_price, stock,
                                COALESCE(ownership,'owned') ownership, consignor_id,
                                COALESCE(product_type,'product') product_type, name
                         FROM products WHERE id = ?",
                        [$item['product_id']]
                    )->fetch();
                    if ($product) {
                        $unitCost = (float)$product['purchase_price'];
                        $isConsignment = ($product['ownership'] ?? 'owned') === 'consignment';
                        $consignorId = (int)($product['consignor_id'] ?? 0);
                        $productStock = (int)($product['stock'] ?? 0);
                        if (($product['product_type'] ?? 'product') !== 'service'
                            && (int)$item['quantity'] > max(0, $productStock)
                        ) {
                            if ($isConsignment) {
                                throw new \RuntimeException(
                                    'Stock consignado insuficiente para ' . ($product['name'] ?? 'producto')
                                    . ' (disponible: ' . $productStock . ')'
                                );
                            }
                        }
                    }
                }
                $lineInserted = false;
                try {
                    $this->query(
                        "INSERT INTO sale_items
                            (sale_id, product_id, product_name, quantity, unit_price, unit_cost,
                             is_consignment, consignor_id, subtotal, tax_rate, tax_amount, taxable)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $saleId,
                            $item['product_id'],
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price'],
                            $unitCost,
                            $isConsignment ? 1 : 0,
                            $isConsignment && $consignorId > 0 ? $consignorId : null,
                            $item['subtotal'],
                            $taxRate,
                            $taxAmount,
                            (float)$item['subtotal'],
                        ]
                    );
                    $lineInserted = true;
                } catch (\Throwable $e) {
                    // fallback sin columnas fiscales / consignación
                }
                if (!$lineInserted) {
                    try {
                        $this->query(
                            "INSERT INTO sale_items
                                (sale_id, product_id, product_name, quantity, unit_price, unit_cost,
                                 is_consignment, consignor_id, subtotal, tax_rate, tax_amount)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $saleId,
                                $item['product_id'],
                                $item['product_name'],
                                $item['quantity'],
                                $item['unit_price'],
                                $unitCost,
                                $isConsignment ? 1 : 0,
                                $isConsignment && $consignorId > 0 ? $consignorId : null,
                                $item['subtotal'],
                                $taxRate,
                                $taxAmount,
                            ]
                        );
                        $lineInserted = true;
                    } catch (\Throwable $e2) {
                        $this->query(
                            "INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal)
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [$saleId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $unitCost, $item['subtotal']]
                        );
                    }
                }
                $saleItemId = (int)$this->db->lastInsertId();
                if (!empty($item['product_id'])) {
                    $this->sales->stock()->decrease(
                        (int)$item['product_id'],
                        (int)$item['quantity'],
                        'sale',
                        $saleId,
                        'Venta (desde cotización ' . $quote['quote_number'] . ')'
                    );
                    if ($isConsignment) {
                        (new \SoftNova\Services\ConsignmentService($this->db, $this->sales->stock()))->registerSaleDebt(
                            $saleId,
                            $saleItemId,
                            (int)$item['product_id'],
                            (int)$item['quantity'],
                            $unitCost,
                            $consignorId > 0 ? $consignorId : null
                        );
                    }
                }
            }
            
            $cashAmount = 0.0;
            if ($paymentType === 'credit') {
                $paidNow = max(0, min($initialPayment, (float)$quote['total']));
                if ($paidNow > 0) {
                    $this->query(
                        "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                         VALUES (?, ?, ?, ?, ?)",
                        [$saleId, $paidNow, $paymentMethod, 'Pago inicial desde cotización', $_SESSION['tenant_user_id']]
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
                    [$saleId, (float)$quote['total'], $paymentMethod, 'Pago completo desde cotización', $_SESSION['tenant_user_id']]
                );
                $cashAmount = (float)$quote['total'];
            }

            $this->db->commit();

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

            if ($cashAmount > 0 && $paymentMethod === 'cash') {
                $label = ($paymentType === 'credit' && $paymentStatus !== 'paid')
                    ? 'Abono inicial (cotización): ' . $invoiceNumber
                    : 'Venta (desde cotización): ' . $invoiceNumber;
                $this->cash->registerIncome($cashAmount, $label, 'sale', $saleId);
            }
            
            if ($paymentType === 'credit' && $paymentStatus !== 'paid') {
                (new ReceivableService($this->db))->upsertFromSale(
                    $saleId,
                    $quote['customer_id'] ? (int)$quote['customer_id'] : null,
                    $invoiceNumber,
                    (float)$quote['total'],
                    min($initialPayment, (float)$quote['total']),
                    'Convertido de cotización: ' . $quote['quote_number']
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
            
            $this->respond(true, 'Cotización convertida a venta: ' . $invoiceNumber . $einvoiceNote, '/app/ventas');
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo convertir la cotización'), '/app/cotizaciones');
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
