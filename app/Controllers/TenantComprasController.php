<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\AccountingService;
use SoftNova\Services\PaymentMethodCatalog;
use SoftNova\Services\PurchasingService;
use function SoftNova\Core\base_url;

/**
 * Módulo Compras: OC + recepción/factura proveedor + IVA descontable.
 */
class TenantComprasController extends TenantController
{
    private PurchasingService $purchasing;
    private AccountingService $accounting;

    private const MAX_INVOICE_BYTES = 5242880; // 5MB
    private const INVOICE_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('compras');
        $this->purchasing = new PurchasingService($this->db);
        $this->accounting = new AccountingService($this->db);
    }

    public function index(): void
    {
        $action = (string)$this->request->get('action', '');

        if ($action === 'create' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->create();
            return;
        }
        if ($action === 'receive' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->receive();
            return;
        }
        if ($action === 'cancel' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'delete');
            $this->cancel();
            return;
        }
        if ($action === 'pay' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->pay();
            return;
        }
        if ($action === 'credit_note' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->creditNote();
            return;
        }
        if ($action === 'credit_note_pdf' && $this->request->method() === 'GET') {
            $this->creditNotePdf();
            return;
        }
        if ($action === 'notas_credito') {
            $this->creditNotesPanel();
            return;
        }
        if ($action === 'facturas') {
            $this->invoicesPanel();
            return;
        }
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'invoice' && $this->request->method() === 'GET') {
            $this->serveInvoice();
            return;
        }
        if ($action === 'payment_receipt' && $this->request->method() === 'GET') {
            $this->servePaymentReceipt();
            return;
        }
        if ($action === 'invoice_import' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceImport();
            return;
        }
        if ($action === 'quick_supplier' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->quickSupplier();
            return;
        }
        if ($action === 'invoice_preview' && $this->request->method() === 'GET') {
            $this->invoicePreview();
            return;
        }
        if ($action === 'invoice_add_line' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceAddLine();
            return;
        }
        if ($action === 'invoice_link_order' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceLinkOrder();
            return;
        }
        if ($action === 'invoice_confirm' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceConfirm();
            return;
        }
        if ($action === 'invoice_discard' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceDiscard();
            return;
        }
        if ($action === 'reposicion') {
            $this->replenishmentPanel();
            return;
        }
        if ($action === 'generate_orders' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->generateOrders();
            return;
        }
        if ($action === 'draft_add' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->draftAdd();
            return;
        }
        if ($action === 'draft_update' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->draftUpdate();
            return;
        }
        if ($action === 'draft_remove' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->draftRemove();
            return;
        }
        if ($action === 'draft_clear' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->draftClear();
            return;
        }
        if ($action === 'draft_generate' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->draftGenerate();
            return;
        }
        if ($action === 'order_pdf' && $this->request->method() === 'GET') {
            $this->orderPdf();
            return;
        }
        if ($action === 'order_whatsapp' && $this->request->method() === 'GET') {
            $this->orderWhatsapp();
            return;
        }
        if ($action === 'order_email' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->orderEmail();
            return;
        }
        if ($action === 'link_suppliers' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->linkSuppliersFromHistory();
            return;
        }

        $status = (string)$this->request->get('status', 'open');
        if ($status === '') {
            $status = 'open';
        }
        // En Órdenes no se filtra CxP; el vencimiento vive en Facturas.
        $due = '';
        if (!in_array($status, ['open', 'draft', 'ordered', 'cancelled', ''], true)) {
            // Si alguien pide received desde OC, redirigir a Facturas
            if ($status === 'received' || $status === 'invoices') {
                $this->redirect(base_url('app/compras') . '?action=facturas');
                return;
            }
            $status = 'open';
        }
        $total = $this->purchasing->countOrders($status, $due);
        $pagination = $this->paginate($total);
        $orders = $this->purchasing->listOrders($pagination['perPage'], $pagination['offset'], $status, $due);

        $products = $this->query(
            "SELECT id, code, barcode, name, purchase_price, stock
             FROM products
             WHERE status = 'active' AND COALESCE(product_type, 'product') = 'product'
             ORDER BY name"
        )->fetchAll();
        $suppliers = $this->query(
            "SELECT id, name, document_type, document_number, phone, contact_name
             FROM suppliers WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        $openTotal = (float)$this->query(
            "SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE status IN ('draft','ordered')"
        )->fetchColumn();
        $monthReceived = (float)$this->query(
            "SELECT COALESCE(SUM(total),0) FROM purchase_orders
             WHERE status = 'received'
               AND MONTH(COALESCE(warehouse_date, order_date)) = MONTH(CURDATE())
               AND YEAR(COALESCE(warehouse_date, order_date)) = YEAR(CURDATE())"
        )->fetchColumn();

        $this->view('tenant.compras', $this->tenantViewData([
            'comprasTab' => 'ordenes',
            'orders' => $orders,
            'products' => $products,
            'suppliers' => $suppliers,
            'pagination' => $pagination,
            'statusFilter' => $status,
            'dueFilter' => '',
            'paymentFilter' => '',
            'openTotal' => $openTotal,
            'monthReceived' => $monthReceived,
            'payableTotal' => $this->purchasing->payableTotal(),
            'payableOrders' => [],
            'pendingInvoices' => $this->invoiceImports()->pendingImports(20),
            'aiInvoiceReady' => (new \SoftNova\Services\AiService($this->db))->isConfigured(),
        ]));
    }

    /**
     * Momento 2–3: facturas recibidas (CxP, abonos, NC, trazabilidad).
     */
    private function invoicesPanel(): void
    {
        $due = (string)$this->request->get('due', '');
        if (!in_array($due, ['overdue', 'due_today', 'due_soon', 'no_due'], true)) {
            $due = '';
        }
        $payment = (string)$this->request->get('payment', '');
        if (!in_array($payment, ['unpaid', 'partial', 'paid', 'paid_only', 'pending'], true)) {
            $payment = '';
        }

        $total = $this->purchasing->countInvoices($payment, $due);
        $pagination = $this->paginate($total);
        $orders = $this->purchasing->listInvoices($pagination['perPage'], $pagination['offset'], $payment, $due);

        $products = $this->query(
            "SELECT id, code, barcode, name, purchase_price, stock
             FROM products
             WHERE status = 'active' AND COALESCE(product_type, 'product') = 'product'
             ORDER BY name"
        )->fetchAll();
        $suppliers = $this->query(
            "SELECT id, name, document_type, document_number, phone, contact_name
             FROM suppliers WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        $openTotal = (float)$this->query(
            "SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE status IN ('draft','ordered')"
        )->fetchColumn();
        $monthReceived = (float)$this->query(
            "SELECT COALESCE(SUM(total),0) FROM purchase_orders
             WHERE status = 'received'
               AND MONTH(COALESCE(warehouse_date, order_date)) = MONTH(CURDATE())
               AND YEAR(COALESCE(warehouse_date, order_date)) = YEAR(CURDATE())"
        )->fetchColumn();
        $paidMonth = (float)$this->query(
            "SELECT COALESCE(SUM(amount),0) FROM supplier_payments
             WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())"
        )->fetchColumn();

        $this->view('tenant.compras', $this->tenantViewData([
            'comprasTab' => 'facturas',
            'orders' => $orders,
            'products' => $products,
            'suppliers' => $suppliers,
            'pagination' => $pagination,
            'statusFilter' => 'received',
            'dueFilter' => $due,
            'paymentFilter' => $payment,
            'openTotal' => $openTotal,
            'monthReceived' => $monthReceived,
            'paidMonth' => $paidMonth,
            'payableTotal' => $this->purchasing->payableTotal(),
            'payableOrders' => $this->purchasing->payableOrders(50, in_array($due, ['overdue', 'due_today', 'due_soon'], true) ? $due : ''),
            'pendingInvoices' => $this->invoiceImports()->pendingImports(20),
            'aiInvoiceReady' => (new \SoftNova\Services\AiService($this->db))->isConfigured(),
        ]));
    }

    private function invoiceImports(): \SoftNova\Services\PurchaseInvoiceImportService
    {
        return new \SoftNova\Services\PurchaseInvoiceImportService($this->db);
    }

    /**
     * Lee la factura del proveedor y deja el borrador listo para revisión.
     * No toca inventario: eso ocurre al confirmar el modal.
     */
    private function invoiceImport(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        try {
            $resultado = $this->invoiceImports()->ingest(
                [
                    'supplier_id' => (int)$this->request->post('supplier_id', 0) ?: null,
                    'invoice_number' => (string)$this->request->post('invoice_number', ''),
                    'invoice_date' => (string)$this->request->post('invoice_date', ''),
                    'due_date' => (string)$this->request->post('due_date', ''),
                    'payment_mode' => (string)$this->request->post('payment_mode', 'payable'),
                    'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
                    'use_ai' => (string)$this->request->post('use_ai', '') !== '',
                ],
                $_FILES['invoice_file'] ?? null,
                (string)$this->request->post('pasted_text', '')
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
            return;
        }

        $mensaje = empty($resultado['notes']) || str_contains($resultado['notes'], 'Borrador manual')
            ? 'Borrador listo. Agregue o revise los productos antes de ingresarlos al inventario'
            : 'Factura leída. Revise las líneas antes de ingresarlas al inventario';
        if ($resultado['notes'] !== '' && !str_contains($mensaje, $resultado['notes'])) {
            $mensaje .= ' (' . $resultado['notes'] . ')';
        }

        if ($this->request->isAjax()) {
            $this->json([
                'success' => true,
                'message' => $mensaje,
                'import_id' => $resultado['import_id'],
                'preview' => $this->invoiceImports()->get($resultado['import_id']),
            ]);
            return;
        }

        $this->respond(true, $mensaje, '/app/compras?action=facturas&import_id=' . $resultado['import_id']);
    }

    /**
     * Alta rápida de proveedor desde el modal de carga de factura (AJAX).
     * No abandona Compras: devuelve el proveedor creado para seleccionarlo al instante.
     */
    private function quickSupplier(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $name = trim((string)$this->request->post('name', ''));
        $documentNumber = trim((string)$this->request->post('document_number', ''));
        $phone = trim((string)$this->request->post('phone', ''));
        if ($name === '') {
            $this->json(['success' => false, 'message' => 'El nombre del proveedor es obligatorio']);
            return;
        }
        if ($documentNumber === '' || $phone === '') {
            $this->json(['success' => false, 'message' => 'Documento y teléfono son obligatorios']);
            return;
        }

        $documentType = (string)$this->request->post('document_type', 'NIT');
        if (!in_array($documentType, ['NIT', 'CC', 'CE', 'OTROS'], true)) {
            $documentType = 'NIT';
        }

        $dup = $this->query(
            "SELECT id, name FROM suppliers
             WHERE status = 'active' AND document_number = ?
             LIMIT 1",
            [$documentNumber]
        )->fetch();
        if ($dup) {
            $this->json([
                'success' => true,
                'message' => 'Ya existía un proveedor con ese documento; se seleccionó',
                'supplier' => [
                    'id' => (int)$dup['id'],
                    'name' => (string)$dup['name'],
                    'document_type' => $documentType,
                    'document_number' => $documentNumber,
                    'phone' => $phone,
                    'contact_name' => '',
                ],
                'existing' => true,
            ]);
            return;
        }

        try {
            $this->query(
                "INSERT INTO suppliers
                    (name, document_type, document_number, contact_name, email, phone, address, notes, is_ally, discount_percent, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'active')",
                [
                    substr($name, 0, 255),
                    $documentType,
                    substr($documentNumber, 0, 60),
                    substr(trim((string)$this->request->post('contact_name', '')), 0, 120) ?: null,
                    substr(trim((string)$this->request->post('email', '')), 0, 180) ?: null,
                    substr($phone, 0, 60),
                    substr(trim((string)$this->request->post('address', '')), 0, 255) ?: null,
                    substr(trim((string)$this->request->post('notes', '')), 0, 255) ?: null,
                ]
            );
        } catch (\Throwable $e) {
            // Instalaciones sin algunas columnas opcionales
            try {
                $this->query(
                    "INSERT INTO suppliers (name, document_type, document_number, contact_name, email, phone, address, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        substr($name, 0, 255),
                        $documentType,
                        substr($documentNumber, 0, 60),
                        substr(trim((string)$this->request->post('contact_name', '')), 0, 120) ?: null,
                        substr(trim((string)$this->request->post('email', '')), 0, 180) ?: null,
                        substr($phone, 0, 60),
                        substr(trim((string)$this->request->post('address', '')), 0, 255) ?: null,
                        substr(trim((string)$this->request->post('notes', '')), 0, 255) ?: null,
                    ]
                );
            } catch (\Throwable $e2) {
                $this->json(['success' => false, 'message' => 'No se pudo crear el proveedor']);
                return;
            }
        }

        $id = (int)$this->db->lastInsertId();
        $this->json([
            'success' => true,
            'message' => 'Proveedor creado',
            'supplier' => [
                'id' => $id,
                'name' => $name,
                'document_type' => $documentType,
                'document_number' => $documentNumber,
                'phone' => $phone,
                'contact_name' => trim((string)$this->request->post('contact_name', '')),
            ],
            'existing' => false,
        ]);
    }

    /** Datos del borrador para el modal de confirmación. */
    private function invoicePreview(): void
    {
        $data = $this->invoiceImports()->get((int)$this->request->get('id', 0));
        if (!$data) {
            $this->json(['success' => false, 'message' => 'Importación no encontrada']);
            return;
        }

        $supplierId = !empty($data['import']['supplier_id']) ? (int)$data['import']['supplier_id'] : null;
        $this->json([
            'success' => true,
            'import' => $data['import'],
            'items' => $data['items'],
            'open_orders' => $this->purchasing->openOrdersForMatch($supplierId, 80),
        ]);
    }

    /** Agrega producto del catálogo (manual / escáner) al borrador de factura. */
    private function invoiceAddLine(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }

        $importId = (int)$this->request->post('import_id', 0);
        $productId = (int)$this->request->post('product_id', 0);
        $qty = (float)$this->request->post('quantity', 1);
        $costRaw = $this->request->post('unit_cost', '');
        $unitCost = $costRaw === '' || $costRaw === null
            ? null
            : \SoftNova\Core\parse_money($costRaw);

        try {
            $item = $this->invoiceImports()->addManualItem($importId, $productId, $qty, $unitCost);
            $preview = $this->invoiceImports()->get($importId);
            $this->json([
                'success' => true,
                'message' => 'Producto agregado a la factura',
                'item' => $item,
                'import' => $preview['import'] ?? null,
                'items' => $preview['items'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Casa el borrador con una OC y carga sus productos al modal de revisión. */
    private function invoiceLinkOrder(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }

        $importId = (int)$this->request->post('import_id', 0);
        $orderId = (int)$this->request->post('linked_order_id', 0);

        try {
            $result = $this->invoiceImports()->linkOrderAndLoadItems($importId, $orderId);
            $supplierId = !empty($result['import']['supplier_id'])
                ? (int)$result['import']['supplier_id']
                : null;
            $this->json([
                'success' => true,
                'message' => (string)($result['message'] ?? 'OC vinculada'),
                'import' => $result['import'] ?? null,
                'items' => $result['items'] ?? [],
                'added' => (int)($result['added'] ?? 0),
                'updated' => (int)($result['updated'] ?? 0),
                'open_orders' => $this->purchasing->openOrdersForMatch($supplierId, 80),
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Aplica el borrador corregido: crea la orden, recibe el stock y contabiliza. */
    private function invoiceConfirm(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }

        $importId = (int)$this->request->post('import_id');
        if ($importId <= 0) {
            $this->respond(false, 'Importación no válida', '/app/compras?action=facturas');
            return;
        }

        try {
            $service = $this->invoiceImports();
            $service->updateHeader($importId, [
                'supplier_id' => (int)$this->request->post('supplier_id', 0) ?: null,
                'invoice_number' => (string)$this->request->post('invoice_number', ''),
                'invoice_date' => (string)$this->request->post('invoice_date', ''),
                'due_date' => (string)$this->request->post('due_date', ''),
                'payment_mode' => (string)$this->request->post('payment_mode', 'payable'),
                'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
                'linked_order_id' => (int)$this->request->post('linked_order_id', 0) ?: null,
            ]);

            $filas = $this->request->post('items', []);
            if (is_array($filas) && $filas) {
                $service->updateItems($importId, $filas);
            }

            $resultado = $service->confirm(
                $importId,
                (string)$this->request->post('warehouse_date', '')
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=facturas');
            return;
        }

        $mensaje = sprintf(
            'Factura ingresada al inventario: %d línea(s), %d producto(s) nuevo(s)',
            $resultado['received_items'],
            $resultado['created_products']
        );
        if ($resultado['skipped'] > 0) {
            $mensaje .= sprintf(', %d omitida(s)', $resultado['skipped']);
        }
        if (!empty($resultado['cash_note'])) {
            $mensaje .= ' ' . $resultado['cash_note'];
        }
        if (isset($resultado['accounting_ok']) && !$resultado['accounting_ok']) {
            $mensaje .= ' ' . ($resultado['accounting_note'] ?: 'Asiento contable pendiente: use «Sincronizar» en Contabilidad.');
        }

        $this->respond(true, $mensaje, '/app/compras?action=facturas');
    }

    private function replenishment(): \SoftNova\Services\ReplenishmentService
    {
        return new \SoftNova\Services\ReplenishmentService($this->db);
    }

    /** Panel de productos agotados o por agotarse, agrupables por proveedor. */
    private function replenishmentPanel(): void
    {
        $service = $this->replenishment();
        $filtros = [
            'state' => (string)$this->request->get('state', ''),
            'supplier_id' => (int)$this->request->get('supplier_id', 0),
            'category_id' => (int)$this->request->get('category_id', 0),
            'q' => trim((string)$this->request->get('q', '')),
            'days' => (int)$this->request->get('days', 30),
        ];

        $this->view('tenant.reposicion', $this->tenantViewData([
            'needs' => $service->needsList($filtros, 300),
            'summary' => $service->summary(),
            'filters' => $filtros,
            'suppliers' => $this->query(
                "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
            )->fetchAll(),
            'categories' => $this->query(
                "SELECT id, name FROM categories WHERE status = 'active' ORDER BY name"
            )->fetchAll(),
            'draftCart' => $service->draftList(),
            'draftCount' => $service->draftCount(),
            'draftOrders' => $service->pendingOrders(30),
        ]));
    }

    /** Agrega selección a la bandeja (sin crear OC todavía). */
    private function draftAdd(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }
        $seleccion = array_map('intval', (array)$this->request->post('product_ids', []));
        $cantidades = [];
        foreach ((array)$this->request->post('quantities', []) as $productId => $cantidad) {
            $cantidades[(int)$productId] = (int)$cantidad;
        }
        try {
            $r = $this->replenishment()->draftAdd($seleccion, $cantidades);
            $this->respond(
                true,
                sprintf('Bandeja: %d nuevo(s), %d actualizado(s). Genere la OC cuando esté listo.', $r['added'], $r['updated']),
                '/app/compras?action=reposicion'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=reposicion');
        }
    }

    private function draftUpdate(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }
        try {
            $this->replenishment()->draftUpdateQty(
                (int)$this->request->post('product_id', 0),
                (int)$this->request->post('quantity', 0)
            );
            $this->respond(true, 'Cantidad actualizada', '/app/compras?action=reposicion');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=reposicion');
        }
    }

    private function draftRemove(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }
        try {
            $this->replenishment()->draftRemove((int)$this->request->post('product_id', 0));
            $this->respond(true, 'Producto quitado de la bandeja', '/app/compras?action=reposicion');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=reposicion');
        }
    }

    private function draftClear(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }
        $this->replenishment()->draftClear();
        $this->respond(true, 'Bandeja vaciada', '/app/compras?action=reposicion');
    }

    private function draftGenerate(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }
        try {
            $resultado = $this->replenishment()->generateFromDraft();
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=reposicion');
            return;
        }
        $mensaje = sprintf('%d orden(es) de compra creadas en borrador desde la bandeja', count($resultado['orders']));
        $this->respond(true, $mensaje, '/app/compras?action=reposicion');
    }

    /** Crea una OC en borrador por proveedor con las cantidades seleccionadas (inmediato). */
    private function generateOrders(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }

        $seleccion = array_map('intval', (array)$this->request->post('product_ids', []));
        $cantidades = [];
        foreach ((array)$this->request->post('quantities', []) as $productId => $cantidad) {
            $cantidades[(int)$productId] = (int)$cantidad;
        }

        try {
            $resultado = $this->replenishment()->generateOrders($seleccion, $cantidades);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=reposicion');
            return;
        }

        $mensaje = sprintf('%d orden(es) de compra creadas en borrador', count($resultado['orders']));
        if ($resultado['skipped_no_supplier'] > 0) {
            $mensaje .= sprintf(
                '. %d producto(s) quedaron fuera por no tener proveedor asignado',
                $resultado['skipped_no_supplier']
            );
        }

        $this->respond(true, $mensaje, '/app/compras?action=reposicion');
    }

    /** PDF de la orden listo para enviar al proveedor. */
    private function orderPdf(): void
    {
        $orderId = (int)$this->request->get('id', 0);
        $order = $this->purchasing->getOrder($orderId);
        if (!$order) {
            $this->respond(false, 'Orden no encontrada', '/app/compras');
            return;
        }

        $supplier = [];
        if (!empty($order['supplier_id'])) {
            $supplier = $this->query(
                "SELECT name, document_number, email, phone FROM suppliers WHERE id = ?",
                [(int)$order['supplier_id']]
            )->fetch() ?: [];
        }

        // El código del proveedor hace la orden legible para quien la despacha.
        $items = $order['items'];
        foreach ($items as &$item) {
            $item['supplier_sku'] = null;
            if (!empty($item['product_id']) && !empty($order['supplier_id'])) {
                $item['supplier_sku'] = $this->query(
                    "SELECT supplier_sku FROM product_suppliers WHERE product_id = ? AND supplier_id = ?",
                    [(int)$item['product_id'], (int)$order['supplier_id']]
                )->fetchColumn() ?: null;
            }
        }
        unset($item);

        $this->replenishment()->markSent($orderId, 'pdf');

        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generatePurchaseOrder($order, $items, $supplier);
        $pdf->download($content, 'OC_' . ($order['order_number'] ?? $orderId) . '.pdf');
    }

    /** Devuelve el enlace de WhatsApp con la orden y la marca como enviada. */
    private function orderWhatsapp(): void
    {
        $orderId = (int)$this->request->get('id', 0);
        $order = $this->purchasing->getOrder($orderId);
        if (!$order) {
            $this->json(['success' => false, 'message' => 'Orden no encontrada']);
            return;
        }

        $telefono = '';
        if (!empty($order['supplier_id'])) {
            $telefono = (string)$this->query(
                "SELECT phone FROM suppliers WHERE id = ?",
                [(int)$order['supplier_id']]
            )->fetchColumn();
        }

        try {
            $service = $this->replenishment();
            $enlace = $service->whatsappLink($orderId, $telefono);
            $service->markSent($orderId, 'whatsapp', $telefono);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
            return;
        }

        $this->json([
            'success' => true,
            'link' => $enlace,
            'has_phone' => $telefono !== '',
        ]);
    }

    /** Envía la orden por correo al proveedor con el PDF adjunto. */
    private function orderEmail(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $orderId = (int)$this->request->post('id', 0);
        $order = $this->purchasing->getOrder($orderId);
        if (!$order) {
            $this->respond(false, 'Orden no encontrada', '/app/compras');
            return;
        }

        $destino = trim((string)$this->request->post('email', ''));
        if ($destino === '' && !empty($order['supplier_id'])) {
            $destino = (string)$this->query(
                "SELECT email FROM suppliers WHERE id = ?",
                [(int)$order['supplier_id']]
            )->fetchColumn();
        }
        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->respond(false, 'El proveedor no tiene un correo válido registrado', '/app/compras');
            return;
        }

        $empresa = (string)($this->companySettings()['company_name'] ?? 'Nuestra empresa');
        $nombreArchivo = 'OC_' . ($order['order_number'] ?? $orderId) . '.pdf';
        $temporal = null;

        try {
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $contenido = $pdf->generatePurchaseOrder($order, $order['items'], []);

            // MailService adjunta desde disco: el temporal se borra tras el envío.
            $temporal = tempnam(sys_get_temp_dir(), 'oc-') . '.pdf';
            file_put_contents($temporal, $contenido);

            $resultado = (new \SoftNova\Services\MailService($this->companySettings()))->send(
                $destino,
                'Orden de compra ' . ($order['order_number'] ?? '') . ' - ' . $empresa,
                $this->orderEmailBody($order, $empresa),
                $temporal,
                $nombreArchivo
            );
        } catch (\Throwable $e) {
            $this->respond(false, 'No se pudo enviar el correo: ' . $e->getMessage(), '/app/compras');
            return;
        } finally {
            if ($temporal !== null && is_file($temporal)) {
                @unlink($temporal);
            }
        }

        if (empty($resultado['success'])) {
            $this->respond(
                false,
                ($resultado['message'] ?? 'Envío rechazado') . '. Descargue el PDF y envíelo manualmente',
                '/app/compras'
            );
            return;
        }

        $this->replenishment()->markSent($orderId, 'email', $destino);
        $this->respond(true, 'Orden enviada a ' . $destino, '/app/compras');
    }

    private function orderEmailBody(array $order, string $empresa): string
    {
        $lineas = [
            'Buen día,',
            '',
            'Adjuntamos la orden de compra ' . ($order['order_number'] ?? '') . ' de ' . $empresa . '.',
            'Agradecemos confirmar disponibilidad, precios y fecha de entrega.',
            '',
            'Productos solicitados:',
        ];

        foreach ($order['items'] as $item) {
            $ean = trim((string)($item['barcode'] ?? ''));
            $lineas[] = '- ' . (string)$item['product_name']
                . ($ean !== '' ? (' [EAN ' . $ean . ']') : '')
                . ' x ' . (int)$item['quantity'];
        }

        $lineas[] = '';
        $lineas[] = 'Cordialmente,';
        $lineas[] = $empresa;

        return implode("\r\n", $lineas);
    }

    /** Deriva los vínculos producto-proveedor desde el histórico de entradas. */
    private function linkSuppliersFromHistory(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=reposicion')) {
            return;
        }
        try {
            $resultado = (new \SoftNova\Services\ProductSupplierService($this->db))->backfillFromHistory(500);
            $this->respond(
                true,
                sprintf('%d vínculo(s) producto-proveedor derivados del histórico', $resultado['linked']),
                '/app/compras?action=reposicion'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=reposicion');
        }
    }

    private function invoiceDiscard(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }
        try {
            $this->invoiceImports()->discard(
                (int)$this->request->post('import_id'),
                (string)$this->request->post('reason', 'Descartada por el usuario')
            );
            $this->respond(true, 'Importación descartada', '/app/compras?action=facturas');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=facturas');
        }
    }

    private function detail(): void
    {
        $orderId = (int)$this->request->get('id', 0);
        $order = $this->purchasing->getOrder($orderId);
        if (!$order) {
            $this->json(['success' => false, 'message' => 'Orden no encontrada']);
            return;
        }
        $order['payments'] = $this->purchasing->orderPayments($orderId);
        $order['credit_notes'] = $this->purchasing->orderCreditNotes($orderId);
        $order['balance'] = $this->purchasing->orderBalance($orderId);
        $order['credit_total'] = round(array_sum(array_map(
            static fn($n) => (($n['status'] ?? '') === 'posted' ? (float)$n['total'] : 0.0),
            $order['credit_notes']
        )), 2);
        $this->json(['success' => true, 'order' => $order]);
    }

    /**
     * Nota de crédito sobre OC recibida: reduce saldo y opcionalmente stock.
     */
    private function creditNote(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $orderId = (int)$this->request->post('id', 0);
        $reason = trim((string)$this->request->post('reason', ''));
        $externalNumber = trim((string)$this->request->post('external_number', ''));
        $creditDate = trim((string)$this->request->post('credit_date', date('Y-m-d')));
        $affectsStock = $this->request->post('affects_stock') === '1';

        $itemIds = (array)$this->request->post('item_id', []);
        $quantities = (array)$this->request->post('quantity', []);
        $lines = [];
        $count = max(count($itemIds), count($quantities));
        for ($i = 0; $i < $count; $i++) {
            $poiId = (int)($itemIds[$i] ?? 0);
            $qty = (int)($quantities[$i] ?? 0);
            if ($poiId <= 0 || $qty <= 0) {
                continue;
            }
            $lines[] = [
                'purchase_order_item_id' => $poiId,
                'quantity' => $qty,
            ];
        }

        try {
            $result = $this->purchasing->createCreditNote(
                $orderId,
                $lines,
                $reason,
                $affectsStock,
                $creditDate !== '' ? $creditDate : date('Y-m-d'),
                $externalNumber
            );

            try {
                $this->accounting->postPurchaseCreditNote($result['credit_note_id']);
                $accountingOk = true;
            } catch (\Throwable $e) {
                $accountingOk = false;
                error_log('Contabilidad nota crédito compra ' . $result['credit_note_id'] . ': ' . $e->getMessage());
            }

            // Si la OC se pagó en efectivo, la NC recupera dinero al cajón.
            $cashNote = $this->purchasing->syncCashForCreditNote((int)$result['credit_note_id']);

            $message = 'Nota de crédito ' . $result['credit_number']
                . ' por ' . $this->formatMoney($result['total']) . '. ';
            if ($result['balance'] <= 0.009) {
                $message .= 'La orden queda sin saldo pendiente.';
            } elseif ($result['balance'] < 0) {
                $message .= 'Saldo a favor: ' . $this->formatMoney(abs($result['balance'])) . '.';
            } else {
                $message .= 'Saldo pendiente: ' . $this->formatMoney($result['balance']) . '.';
            }
            if ($cashNote !== '') {
                $message .= ' ' . $cashNote;
            }
            if (!$accountingOk) {
                $message .= ' Asiento contable pendiente: use «Sincronizar» en Contabilidad.';
            }

            $pdfUrl = base_url('/app/compras?action=credit_note_pdf&id=' . (int)$result['credit_note_id']);
            $this->respond(true, $message, '/app/compras?action=notas_credito', [
                'pdf_url' => $pdfUrl,
                'credit_note_id' => (int)$result['credit_note_id'],
                'credit_number' => $result['credit_number'],
            ]);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=facturas');
        }
    }

    /** Listado / administración de notas de crédito de compra. */
    private function creditNotesPanel(): void
    {
        $q = trim((string)$this->request->get('q', ''));
        $total = $this->purchasing->countCreditNotes($q);
        $pagination = $this->paginate($total);
        $notes = $this->purchasing->listCreditNotes($pagination['perPage'], $pagination['offset'], $q);

        $this->view('tenant.compras', $this->tenantViewData([
            'creditNotesPanel' => true,
            'creditNotes' => $notes,
            'creditNotesQ' => $q,
            'pagination' => $pagination,
            'orders' => [],
            'products' => [],
            'suppliers' => [],
            'statusFilter' => '',
            'openTotal' => 0,
            'monthReceived' => 0,
            'payableTotal' => $this->purchasing->payableTotal(),
            'payableOrders' => [],
            'pendingInvoices' => [],
            'aiInvoiceReady' => false,
        ]));
    }

    /** PDF descargable de una nota de crédito. */
    private function creditNotePdf(): void
    {
        $note = $this->purchasing->getCreditNote((int)$this->request->get('id', 0));
        if (!$note) {
            $this->respond(false, 'Nota de crédito no encontrada', '/app/compras?action=notas_credito');
            return;
        }

        $supplier = [
            'name' => $note['supplier_name'] ?? '',
            'document_number' => $note['supplier_document'] ?? '',
            'email' => $note['supplier_email'] ?? '',
            'phone' => $note['supplier_phone'] ?? '',
        ];

        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generatePurchaseCreditNote($note, $note['items'] ?? [], $supplier);
        $filename = 'NC_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($note['credit_number'] ?? $note['id'])) . '.pdf';
        $pdf->download($content, $filename);
    }

    /**
     * Abono a proveedor: descarga la cuenta por pagar y genera su asiento.
     */
    private function pay(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $orderId = (int)$this->request->post('id', 0);
        $amount = \SoftNova\Core\parse_money($this->request->post('amount', '0'));
        $paymentDate = trim((string)$this->request->post('payment_date', date('Y-m-d')));
        $method = trim((string)$this->request->post('payment_method', 'cash'));
        $reference = trim((string)$this->request->post('reference', ''));
        $notes = trim((string)$this->request->post('notes', ''));

        $receipt = $this->storePaymentReceiptUpload();
        if ($receipt === false) {
            $this->respond(
                false,
                'Comprobante inválido. Use JPG, PNG, WEBP o PDF de máximo 5 MB',
                '/app/compras?action=facturas'
            );
            return;
        }

        try {
            $method = PaymentMethodCatalog::normalize($method);
            $result = $this->purchasing->registerPayment(
                $orderId,
                $amount,
                $paymentDate !== '' ? $paymentDate : date('Y-m-d'),
                $method,
                $reference,
                $notes,
                $receipt
            );

            try {
                $this->accounting->postSupplierPayment($result['payment_id']);
                $accountingOk = true;
            } catch (\Throwable $e) {
                $accountingOk = false;
                error_log('Contabilidad abono proveedor ' . $result['payment_id'] . ': ' . $e->getMessage());
            }

            $cashNote = $this->purchasing->syncCashForSupplierPayment((int)$result['payment_id']);

            $message = 'Abono registrado por ' . $this->formatMoney($amount) . '. ';
            $message .= $result['balance'] <= 0.009
                ? 'La orden queda pagada en su totalidad.'
                : 'Saldo pendiente: ' . $this->formatMoney($result['balance']) . '.';
            if ($receipt) {
                $message .= ' Comprobante adjunto.';
            }
            if ($cashNote !== '') {
                $message .= ' ' . $cashNote;
            }
            if (!$accountingOk) {
                $message .= ' Asiento contable pendiente: use «Sincronizar» en Contabilidad.';
            }

            $this->respond(true, $message, '/app/compras?action=facturas');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras?action=facturas');
        }
    }

    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $supplierId = (int)$this->request->post('supplier_id', 0) ?: null;
        $orderDate = (string)$this->request->post('order_date', date('Y-m-d'));
        $expectedDate = trim((string)$this->request->post('expected_date', ''));
        $notes = trim((string)$this->request->post('notes', ''));

        $productIds = (array)$this->request->post('product_id', []);
        $quantities = (array)$this->request->post('quantity', []);
        $unitCosts = (array)$this->request->post('unit_cost', []);
        $taxRates = (array)$this->request->post('tax_rate', []);

        $items = [];
        $count = max(count($productIds), count($quantities), count($unitCosts));
        for ($i = 0; $i < $count; $i++) {
            $pid = (int)($productIds[$i] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $items[] = [
                'product_id' => $pid,
                'quantity' => (int)($quantities[$i] ?? 0),
                'unit_cost' => \SoftNova\Core\parse_money($unitCosts[$i] ?? 0),
                'tax_rate' => (float)($taxRates[$i] ?? 19),
            ];
        }

        try {
            $orderId = $this->purchasing->createOrder(
                $supplierId,
                $orderDate !== '' ? $orderDate : date('Y-m-d'),
                null,
                $expectedDate !== '' ? $expectedDate : null,
                $notes,
                $items,
                'ordered',
                0.0,
                null,
                null,
                null,
                false // OC pura: sin dsto pronto pago ni auto-aliado
            );

            $duplicatedFrom = (int)$this->request->post('duplicated_from_id', 0);
            if ($duplicatedFrom > 0) {
                $this->purchasing->markDuplicatedFrom($orderId, $duplicatedFrom);
            }

            $order = $this->purchasing->getOrder($orderId);
            $this->respond(
                true,
                'Orden creada: ' . ($order['order_number'] ?? ('#' . $orderId)) . '. Envíela al proveedor; la factura y recepción se cargan aparte.',
                '/app/compras'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
        }
    }

    private function receive(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }
        try {
            $orderId = (int)$this->request->post('id', 0);
            $this->purchasing->applyInvoiceMeta($orderId, [
                'due_date' => trim((string)$this->request->post('due_date', '')),
                'invoice_date' => trim((string)$this->request->post('invoice_date', '')),
                'invoice_number' => trim((string)$this->request->post('invoice_number', '')),
                'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
            ]);
            $recv = $this->doReceive(
                $orderId,
                (string)$this->request->post('warehouse_date', date('Y-m-d')),
                trim((string)$this->request->post('invoice_number', '')),
                (string)$this->request->post('payment_mode', 'payable'),
                trim((string)$this->request->post('notes', ''))
            );
            $invoiceFile = $this->storeInvoiceUpload();
            if ($invoiceFile === false) {
                $this->respond(false, 'Mercancía recibida, pero falló la subida de la foto/PDF de factura', '/app/compras?action=facturas');
                return;
            }
            if ($invoiceFile !== null) {
                $this->purchasing->attachInvoiceFile($orderId, $invoiceFile);
            }
            $msg = !empty($recv['accounting_ok'])
                ? 'Mercancía recibida, inventario y contabilidad actualizados'
                : 'Mercancía recibida e inventario actualizado';
            if (!empty($recv['debt_alerts'])) {
                $msg .= '. ' . implode(' ', $recv['debt_alerts']);
            }
            if (!empty($recv['cash_note'])) {
                $msg .= ' ' . $recv['cash_note'];
            }
            if (!empty($recv['accounting_note'])) {
                $msg .= ' ' . $recv['accounting_note'];
            }
            $this->respond(true, $msg, '/app/compras?action=facturas');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
        }
    }

    private function doReceive(
        int $orderId,
        string $warehouseDate,
        string $invoiceNumber,
        string $paymentMode,
        string $notes
    ): array {
        $result = $this->purchasing->receiveOrder($orderId, $warehouseDate, $invoiceNumber, $paymentMode, $notes);
        $out = is_array($result) ? $result : [];
        $out['accounting_ok'] = true;
        try {
            $this->accounting->postPurchaseOrder($orderId);
        } catch (\Throwable $e) {
            $out['accounting_ok'] = false;
            $out['accounting_note'] = 'Inventario actualizado, pero el asiento contable quedó pendiente ('
                . $e->getMessage() . '). Use «Sincronizar» en Contabilidad.';
            error_log('Contabilidad OC ' . $orderId . ': ' . $e->getMessage());
        }

        // Compra al contado en efectivo → egreso en caja física.
        $cashNote = $this->purchasing->syncCashForReceivedOrder($orderId);
        if ($cashNote !== '') {
            $out['cash_note'] = $cashNote;
        }
        return $out;
    }

    private function cancel(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }
        try {
            $this->purchasing->cancelOrder(
                (int)$this->request->post('id', 0),
                trim((string)$this->request->post('reason', ''))
            );
            $this->respond(true, 'Orden cancelada', '/app/compras');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
        }
    }

    private function serveInvoice(): void
    {
        $order = $this->purchasing->getOrder((int)$this->request->get('id', 0));
        if (!$order || empty($order['invoice_path'])) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            exit;
        }
        $relative = str_replace(['\\', '..'], ['/', ''], (string)$order['invoice_path']);
        $full = PUBLIC_PATH . '/' . ltrim($relative, '/');
        if (!is_file($full)) {
            // También aceptar path relativo tipo uploads/...
            $alt = ROOT_PATH . '/public/' . ltrim($relative, '/');
            $full = is_file($alt) ? $alt : $full;
        }
        if (!is_file($full)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            exit;
        }
        $mime = (string)($order['invoice_mime'] ?: 'application/octet-stream');
        $name = (string)($order['invoice_original_name'] ?: basename($full));
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit;
    }

    /** Sirve la foto/PDF del comprobante de un abono a proveedor. */
    private function servePaymentReceipt(): void
    {
        $paymentId = (int)$this->request->get('id', 0);
        $payment = $this->purchasing->getSupplierPayment($paymentId);
        if (!$payment || empty($payment['receipt_path'])) {
            http_response_code(404);
            echo 'Comprobante no encontrado';
            exit;
        }
        $relative = str_replace(['\\', '..'], ['/', ''], (string)$payment['receipt_path']);
        $full = PUBLIC_PATH . '/' . ltrim($relative, '/');
        if (!is_file($full)) {
            $alt = ROOT_PATH . '/public/' . ltrim($relative, '/');
            $full = is_file($alt) ? $alt : $full;
        }
        if (!is_file($full)) {
            http_response_code(404);
            echo 'Comprobante no encontrado';
            exit;
        }
        $mime = (string)($payment['receipt_mime'] ?: 'application/octet-stream');
        $name = (string)($payment['receipt_original_name'] ?: basename($full));
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit;
    }

    /**
     * @return array{path:string,original:string,mime:string}|null|false null=sin archivo, false=error
     */
    private function storeInvoiceUpload(): array|null|false
    {
        return $this->storePurchaseUpload('invoice_file', 'po_');
    }

    /**
     * Foto o PDF del comprobante de consignación / transferencia del abono.
     *
     * @return array{path:string,original:string,mime:string}|null|false
     */
    private function storePaymentReceiptUpload(): array|null|false
    {
        return $this->storePurchaseUpload('payment_receipt', 'pay_');
    }

    /**
     * @return array{path:string,original:string,mime:string}|null|false null=sin archivo, false=error
     */
    private function storePurchaseUpload(string $field, string $prefix): array|null|false
    {
        if (empty($_FILES[$field]['tmp_name'])) {
            return null;
        }
        if (!is_uploaded_file($_FILES[$field]['tmp_name'])) {
            return false;
        }
        if (($_FILES[$field]['size'] ?? 0) > self::MAX_INVOICE_BYTES) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES[$field]['tmp_name']);
        if (!isset(self::INVOICE_MIME[$mime])) {
            return false;
        }
        $ext = self::INVOICE_MIME[$mime];
        $dir = PUBLIC_PATH . '/uploads/purchases';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = $prefix . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
            return false;
        }
        return [
            'path' => 'uploads/purchases/' . $filename,
            'original' => (string)($_FILES[$field]['name'] ?? $filename),
            'mime' => $mime,
        ];
    }
}
