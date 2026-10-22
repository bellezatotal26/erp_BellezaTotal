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
        if ($action === 'update' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->updateOrder();
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
        if ($action === 'void_received' && $this->request->method() === 'POST') {
            if (
                !TenantMiddleware::canDo('edit', 'compras')
                && !TenantMiddleware::canDo('delete', 'compras')
            ) {
                TenantMiddleware::authorize('compras', 'edit');
                return;
            }
            $this->voidReceived();
            return;
        }
        if ($action === 'pay' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->pay();
            return;
        }
        if ($action === 'fix_payment_cash_source' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->fixPaymentCashSource();
            return;
        }
        if ($action === 'fix_payment_discount' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->fixPaymentDiscount();
            return;
        }
        if ($action === 'purchase_limit' && $this->request->method() === 'POST') {
            $this->savePurchaseLimit();
            return;
        }
        if ($action === 'review_notes' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'edit');
            $this->saveReviewNotes();
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
        if ($action === 'export' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('compras', 'export');
            $this->exportCompras();
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
        if ($action === 'quick_product' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->quickProduct();
            return;
        }
        if ($action === 'invoice_preview' && $this->request->method() === 'GET') {
            $this->invoicePreview();
            return;
        }
        if (($action === 'invoice_export_xlsx' || $action === 'invoice_export_csv') && $this->request->method() === 'GET') {
            $this->invoiceExportXlsx();
            return;
        }
        if ($action === 'open_orders_match' && $this->request->method() === 'GET') {
            $this->openOrdersMatch();
            return;
        }
        if ($action === 'invoice_add_line' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceAddLine();
            return;
        }
        if ($action === 'invoice_remove_line' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceRemoveLine();
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
        if ($action === 'invoice_save_draft' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceSaveDraft();
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
        // '' o 'all' = sin filtro de estado (todas)
        if ($status === 'all') {
            $status = '';
        }
        // En Órdenes no se filtra CxP; el vencimiento vive en Facturas.
        $due = '';
        if ($status !== '' && !in_array($status, ['open', 'draft', 'ordered', 'cancelled'], true)) {
            // Si alguien pide received desde OC, redirigir a Facturas
            if ($status === 'received' || $status === 'invoices') {
                $this->redirect(base_url('app/compras') . '?action=facturas');
                return;
            }
            $status = 'open';
        }
        $listFilters = $this->compraListFilters('ordenes');
        $per = $this->resolvePerPage();
        $total = $this->purchasing->countOrders($status, $due, $listFilters);
        $pagination = $this->paginate($total, $per['perPage']);
        $orders = $this->purchasing->listOrders($pagination['perPage'], $pagination['offset'], $status, $due, $listFilters);

        $products = $this->query(
            "SELECT id, code, barcode, name, purchase_price, stock, COALESCE(tax_rate, 0) AS tax_rate
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
        $openCount = (int)$this->query(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered')"
        )->fetchColumn();
        $monthReceived = (float)$this->query(
            "SELECT COALESCE(SUM(GREATEST(total - COALESCE(credit_total, 0), 0)),0) FROM purchase_orders
             WHERE status = 'received'
               AND MONTH(COALESCE(warehouse_date, invoice_date, order_date)) = MONTH(CURDATE())
               AND YEAR(COALESCE(warehouse_date, invoice_date, order_date)) = YEAR(CURDATE())"
        )->fetchColumn();

        $this->view('tenant.compras', $this->tenantViewData([
            'comprasTab' => 'ordenes',
            'orders' => $orders,
            'products' => $products,
            'suppliers' => $suppliers,
            'defaultTaxRate' => \SoftNova\Services\TaxService::defaultRate($this->db),
            'pagination' => $pagination,
            'perPageOptions' => $per['options'],
            'statusFilter' => $status,
            'dueFilter' => '',
            'paymentFilter' => '',
            'qFilter' => (string)($listFilters['q'] ?? ''),
            'fromFilter' => (string)($listFilters['from'] ?? ''),
            'toFilter' => (string)($listFilters['to'] ?? ''),
            'supplierFilter' => (int)($listFilters['supplier_id'] ?? 0),
            'listQuery' => $this->compraListQuery($listFilters, [
                'status' => $status !== '' ? $status : null,
                'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
            ]),
            'sortFilters' => [
                'sort' => (string)($listFilters['sort'] ?? ''),
                'dir' => (string)($listFilters['dir'] ?? 'desc'),
                'query' => $this->compraListQuery($listFilters, [
                    'status' => $status !== '' ? $status : null,
                    'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
                ]),
            ],
            'openTotal' => $openTotal,
            'openCount' => $openCount,
            'monthReceived' => $monthReceived,
            'payableTotal' => $this->purchasing->payableTotal(),
            'purchaseBudget' => $this->purchaseBudgetStatus(),
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
        if (!in_array($payment, ['unpaid', 'partial', 'paid', 'paid_only', 'pending', 'voided', 'active'], true)) {
            $payment = '';
        }

        $listFilters = $this->compraListFilters('facturas');
        $per = $this->resolvePerPage();
        $total = $this->purchasing->countInvoices($payment, $due, $listFilters);
        $pagination = $this->paginate($total, $per['perPage']);
        $orders = $this->purchasing->listInvoices($pagination['perPage'], $pagination['offset'], $payment, $due, $listFilters);

        $products = $this->query(
            "SELECT id, code, barcode, name, purchase_price, stock, COALESCE(tax_rate, 0) AS tax_rate
             FROM products
             WHERE status = 'active' AND COALESCE(product_type, 'product') = 'product'
             ORDER BY name"
        )->fetchAll();
        $suppliers = $this->query(
            "SELECT id, name, document_type, document_number, phone, contact_name
             FROM suppliers WHERE status = 'active' ORDER BY name"
        )->fetchAll();
        $categories = [];
        try {
            $categories = $this->query(
                "SELECT id, name FROM categories WHERE status = 'active' ORDER BY name"
            )->fetchAll() ?: [];
        } catch (\Throwable $e) {
            $categories = [];
        }

        $openTotal = (float)$this->query(
            "SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE status IN ('draft','ordered')"
        )->fetchColumn();
        $openCount = (int)$this->query(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','ordered')"
        )->fetchColumn();
        $monthReceived = (float)$this->query(
            "SELECT COALESCE(SUM(GREATEST(total - COALESCE(credit_total, 0), 0)),0) FROM purchase_orders
             WHERE status = 'received'
               AND MONTH(COALESCE(warehouse_date, invoice_date, order_date)) = MONTH(CURDATE())
               AND YEAR(COALESCE(warehouse_date, invoice_date, order_date)) = YEAR(CURDATE())"
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
            'categories' => $categories,
            'defaultTaxRate' => \SoftNova\Services\TaxService::defaultRate($this->db),
            'pagination' => $pagination,
            'perPageOptions' => $per['options'],
            'statusFilter' => 'received',
            'dueFilter' => $due,
            'paymentFilter' => $payment,
            'qFilter' => (string)($listFilters['q'] ?? ''),
            'fromFilter' => (string)($listFilters['from'] ?? ''),
            'toFilter' => (string)($listFilters['to'] ?? ''),
            'supplierFilter' => (int)($listFilters['supplier_id'] ?? 0),
            'listQuery' => $this->compraListQuery($listFilters, [
                'action' => 'facturas',
                'payment' => $payment !== '' ? $payment : null,
                'due' => $due !== '' ? $due : null,
                'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
            ]),
            'sortFilters' => [
                'sort' => (string)($listFilters['sort'] ?? ''),
                'dir' => (string)($listFilters['dir'] ?? 'desc'),
                'query' => $this->compraListQuery($listFilters, [
                    'action' => 'facturas',
                    'payment' => $payment !== '' ? $payment : null,
                    'due' => $due !== '' ? $due : null,
                    'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
                ]),
            ],
            'openTotal' => $openTotal,
            'openCount' => $openCount,
            'monthReceived' => $monthReceived,
            'paidMonth' => $paidMonth,
            'invoiceQuick' => $this->purchasing->invoiceQuickSummary($listFilters),
            'invoiceFiltered' => $this->purchasing->invoiceSummary($payment, $due, $listFilters),
            'payableTotal' => $this->purchasing->payableTotal(),
            'purchaseBudget' => $this->purchaseBudgetStatus(),
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
                    'warehouse_date' => (string)$this->request->post('warehouse_date', ''),
                    'payment_mode' => (string)$this->request->post('payment_mode', 'payable'),
                    'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
                    'commercial_discount_pct' => $this->request->post('commercial_discount_pct', ''),
                    'commercial_discount_amount' => $this->request->post('commercial_discount_amount', ''),
                    'linked_order_id' => (int)$this->request->post('linked_order_id', 0) ?: null,
                    'use_ai' => (string)$this->request->post('use_ai', '') !== '',
                    'document_kind' => (string)$this->request->post('document_kind', ''),
                    'apply_product_vat' => $this->request->post('apply_product_vat', null),
                    'tax_override_amount' => $this->request->post('tax_override_amount', null),
                    'vat_already_included' => $this->request->post('vat_already_included', '0'),
                ],
                $_FILES['invoice_file'] ?? null,
                (string)$this->request->post('pasted_text', '')
            );
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras');
            return;
        }

        $itemsCount = (int)($resultado['items_count'] ?? 0);
        $spreadsheetFailed = !empty($resultado['spreadsheet_failed']);
        if ($spreadsheetFailed && $itemsCount === 0) {
            $mensaje = 'El archivo no produjo líneas de producto. '
                . 'Revise el Excel (use .xlsx o CSV), o agregue productos a mano en la revisión.';
        } elseif ($itemsCount === 0 && (empty($resultado['notes']) || str_contains((string)$resultado['notes'], 'Borrador manual'))) {
            $mensaje = 'Borrador listo. Agregue o revise los productos antes de ingresarlos al inventario';
        } elseif ($itemsCount === 0) {
            $mensaje = 'Borrador creado sin líneas detectadas. Complete los productos en la revisión.';
        } else {
            $mensaje = 'Factura leída (' . $itemsCount . ' línea(s)). Revise antes de ingresar al inventario';
        }
        if ($resultado['notes'] !== '' && !str_contains($mensaje, $resultado['notes'])) {
            $mensaje .= ' (' . $resultado['notes'] . ')';
        }

        if ($this->request->isAjax()) {
            $this->json([
                'success' => true,
                'message' => $mensaje,
                'import_id' => $resultado['import_id'],
                'items_count' => $itemsCount,
                'spreadsheet_failed' => $spreadsheetFailed,
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

    /**
     * Alta rápida de producto desde la revisión de factura (sin salir a Inventario).
     * Stock inicial 0: el ingreso físico lo hace la propia factura al confirmar.
     */
    private function quickProduct(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $name = trim((string)$this->request->post('name', ''));
        if ($name === '') {
            $this->json(['success' => false, 'message' => 'El nombre del producto es obligatorio']);
            return;
        }

        $code = trim((string)$this->request->post('code', ''));
        $code = preg_replace('/\s+/', ' ', $code) ?? '';
        if (mb_strlen($code) > 255) {
            $this->json(['success' => false, 'message' => 'SKU demasiado largo (máx. 255)']);
            return;
        }
        if ($code === '') {
            $code = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        }

        $barcode = trim((string)$this->request->post('barcode', ''));
        if (mb_strlen($barcode) > 64) {
            $this->json(['success' => false, 'message' => 'EAN inválido (máx. 64)']);
            return;
        }

        $dup = null;
        try {
            if ($barcode !== '') {
                $dup = $this->query(
                    "SELECT id, name, code, barcode, purchase_price, COALESCE(tax_rate,0) AS tax_rate
                     FROM products
                     WHERE status = 'active'
                       AND (code = ? OR barcode = ?)
                     LIMIT 1",
                    [$code, $barcode]
                )->fetch();
            } else {
                $dup = $this->query(
                    "SELECT id, name, code, barcode, purchase_price, COALESCE(tax_rate,0) AS tax_rate
                     FROM products
                     WHERE status = 'active' AND code = ?
                     LIMIT 1",
                    [$code]
                )->fetch();
            }
        } catch (\Throwable $e) {
            $dup = null;
        }
        if ($dup) {
            $this->json([
                'success' => true,
                'message' => 'Ya existía en catálogo; se usará ese producto',
                'existing' => true,
                'product' => [
                    'id' => (int)$dup['id'],
                    'code' => (string)$dup['code'],
                    'barcode' => (string)($dup['barcode'] ?? ''),
                    'name' => (string)$dup['name'],
                    'purchase_price' => (float)($dup['purchase_price'] ?? 0),
                    'tax_rate' => (float)($dup['tax_rate'] ?? 0),
                ],
            ]);
            return;
        }

        $purchase = round(max(0, \SoftNova\Core\parse_money($this->request->post('purchase_price', 0))), 2);
        $sale = round(max(0, \SoftNova\Core\parse_money($this->request->post('sale_price', 0))), 2);
        if ($sale <= 0.009) {
            $this->json(['success' => false, 'message' => 'Indique el precio de venta']);
            return;
        }

        $taxRate = (float)$this->request->post('tax_rate', \SoftNova\Services\TaxService::defaultRate($this->db));
        if ($taxRate < 0) {
            $taxRate = 0;
        }
        if ($taxRate > 100) {
            $taxRate = 100;
        }

        $categoryId = (int)$this->request->post('category_id', 0);
        $unit = trim((string)$this->request->post('unit', 'UND'));
        if ($unit === '') {
            $unit = 'UND';
        }
        $minStock = max(0, (int)$this->request->post('min_stock', 5));
        $description = trim((string)$this->request->post('description', ''));

        try {
            \SoftNova\Services\SupplyChainSchema::ensure($this->db);
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $this->query(
                "INSERT INTO products
                    (code, barcode, name, product_type, description, category_id, purchase_price, sale_price, tax_rate,
                     wholesale_price, stock, min_stock, unit, track_lots, created_by, status)
                 VALUES (?, ?, ?, 'product', ?, ?, ?, ?, ?, 0, 0, ?, ?, 0, ?, 'active')",
                [
                    $code,
                    $barcode !== '' ? $barcode : null,
                    substr($name, 0, 255),
                    $description !== '' ? $description : null,
                    $categoryId > 0 ? $categoryId : null,
                    $purchase,
                    $sale,
                    $taxRate,
                    $minStock,
                    substr($unit, 0, 20),
                    $_SESSION['tenant_user_id'] ?? null,
                ]
            );
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $this->json(['success' => false, 'message' => 'Ya existe un producto con ese SKU o EAN']);
                return;
            }
            // Fallback sin wholesale_price / track_lots
            try {
                $this->query(
                    "INSERT INTO products
                        (code, barcode, name, product_type, description, category_id, purchase_price, sale_price, tax_rate,
                         stock, min_stock, unit, created_by, status)
                     VALUES (?, ?, ?, 'product', ?, ?, ?, ?, ?, 0, ?, ?, ?, 'active')",
                    [
                        $code,
                        $barcode !== '' ? $barcode : null,
                        substr($name, 0, 255),
                        $description !== '' ? $description : null,
                        $categoryId > 0 ? $categoryId : null,
                        $purchase,
                        $sale,
                        $taxRate,
                        $minStock,
                        substr($unit, 0, 20),
                        $_SESSION['tenant_user_id'] ?? null,
                    ]
                );
            } catch (\Throwable $e2) {
                $this->json(['success' => false, 'message' => 'No se pudo crear el producto: ' . $e2->getMessage()]);
                return;
            }
        }

        $id = (int)$this->db->lastInsertId();
        $this->json([
            'success' => true,
            'message' => 'Producto creado en inventario',
            'existing' => false,
            'product' => [
                'id' => $id,
                'code' => $code,
                'barcode' => $barcode,
                'name' => $name,
                'purchase_price' => $purchase,
                'tax_rate' => $taxRate,
            ],
        ]);
    }

    /** Datos del borrador para el modal de confirmación (solo lectura). */
    private function invoicePreview(): void
    {
        $data = $this->invoiceImports()->get((int)$this->request->get('id', 0));
        if (!$data) {
            $this->json(['success' => false, 'message' => 'Importación no encontrada']);
            return;
        }

        $supplierId = !empty($data['import']['supplier_id']) ? (int)$data['import']['supplier_id'] : null;
        $orderMatch = null;

        // La sincronización/escritura de líneas con OC se hace solo por POST
        // (invoice_link_order + CSRF), no vía GET sync_oc.
        if (!empty($data['import']['linked_order_id'])) {
            try {
                $orderMatch = $this->invoiceImports()->buildOrderMatchReport((int)$data['import']['id']);
            } catch (\Throwable $e) {
                $orderMatch = null;
            }
        }

        $this->json([
            'success' => true,
            'import' => $data['import'],
            'items' => $data['items'],
            'open_orders' => $this->purchasing->openOrdersForMatch($supplierId, 40),
            'order_match' => $orderMatch,
        ]);
    }

    /** OC abiertas del proveedor para el desplegable «Cruce con OC». */
    private function openOrdersMatch(): void
    {
        $supplierId = (int)$this->request->get('supplier_id', 0);
        if ($supplierId <= 0) {
            $this->json(['success' => true, 'open_orders' => []]);
            return;
        }
        $this->json([
            'success' => true,
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
        if (function_exists('\\SoftNova\\Core\\parse_quantity')) {
            $qty = \SoftNova\Core\parse_quantity($this->request->post('quantity', 1));
        }
        $costRaw = $this->request->post('unit_cost', '');
        $unitCost = $costRaw === '' || $costRaw === null
            ? null
            : \SoftNova\Core\parse_money($costRaw);
        $asBonus = in_array(strtolower(trim((string)$this->request->post('as_bonus', ''))), ['1', 'true', 'yes', 'on'], true);
        $forceSeparate = in_array(strtolower(trim((string)$this->request->post('force_separate', ''))), ['1', 'true', 'yes', 'on'], true);
        if ($asBonus) {
            $unitCost = 0.0;
            $forceSeparate = true;
        }

        try {
            $item = $this->invoiceImports()->addManualItem(
                $importId,
                $productId,
                $qty,
                $unitCost,
                null,
                null,
                $asBonus,
                $forceSeparate
            );
            $preview = $this->invoiceImports()->get($importId);
            $msg = $asBonus
                ? 'Bonificación agregada en fila aparte (mismo SKU, costo $0)'
                : 'Producto agregado a la factura';
            $this->json([
                'success' => true,
                'message' => $msg,
                'item' => $item,
                'import' => $preview['import'] ?? null,
                'items' => $preview['items'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $this->clientErrorMessage($e, 'No se pudo completar la operación')]);
        }
    }

    /** Elimina una línea del borrador de factura en revisión. */
    private function invoiceRemoveLine(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }

        $importId = (int)$this->request->post('import_id', 0);
        $itemId = (int)$this->request->post('item_id', 0);

        try {
            $result = $this->invoiceImports()->removeItem($importId, $itemId);
            $this->json([
                'success' => true,
                'message' => 'Línea eliminada',
                'import' => $result['import'] ?? null,
                'items' => $result['items'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $this->clientErrorMessage($e, 'No se pudo eliminar la línea')]);
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
                'order_match' => $result['order_match'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $this->clientErrorMessage($e, 'No se pudo completar la operación')]);
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
                'warehouse_date' => (string)$this->request->post('warehouse_date', ''),
                'payment_mode' => (string)$this->request->post('payment_mode', 'payable'),
                'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
                'commercial_discount_pct' => $this->request->post('commercial_discount_pct', ''),
                'commercial_discount_amount' => $this->request->post('commercial_discount_amount', ''),
                'linked_order_id' => (int)$this->request->post('linked_order_id', 0) ?: null,
                'tax_override_amount' => $this->request->post('tax_override_amount', null),
                'review_notes' => (string)$this->request->post('review_notes', ''),
            ]);

            $filas = $this->request->post('items', []);
            if (is_array($filas) && $filas) {
                $service->updateItems($importId, $filas);
            }

            $resultado = $service->confirm(
                $importId,
                (string)$this->request->post('warehouse_date', ''),
                (string)$this->request->post('oc_disposition', 'close') === 'keep_open' ? 'keep_open' : 'close'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=facturas');
            return;
        }

        $mensaje = sprintf(
            'Factura ingresada al inventario: %d línea(s), %d producto(s) nuevo(s)',
            $resultado['received_items'],
            $resultado['created_products']
        );
        if (!empty($resultado['bill_only'])) {
            $mensaje .= sprintf(', %d facturada(s) sin ingresar a inventario', (int)$resultado['bill_only']);
        }
        if ($resultado['skipped'] > 0) {
            $mensaje .= sprintf(', %d omitida(s)', $resultado['skipped']);
        }
        if (!empty($resultado['oc_kept_open'])) {
            $mensaje .= sprintf(
                '. OC %s sigue abierta con %d ud(s) pendientes',
                $resultado['oc_source_number'] ?: ('#' . (int)($resultado['source_order_id'] ?? 0)),
                (int)($resultado['oc_remaining_qty'] ?? 0)
            );
        } elseif (!empty($resultado['oc_source_closed'])) {
            $mensaje .= '. OC ' . ($resultado['oc_source_number'] ?: '') . ' quedó sin pendientes (cerrada).';
        }
        if (!empty($resultado['oc_excess_qty'])) {
            $mensaje .= sprintf(
                '. Aviso: %d ud(s) de la factura exceden lo pendiente en la OC (ingresaron igual).',
                (int)$resultado['oc_excess_qty']
            );
        }
        if (!empty($resultado['cash_note'])) {
            $mensaje .= ' ' . $resultado['cash_note'];
        }
        if (isset($resultado['accounting_ok']) && !$resultado['accounting_ok']) {
            $mensaje .= ' ' . ($resultado['accounting_note'] ?: 'Asiento contable pendiente: use «Sincronizar» en Contabilidad.');
        }

        $this->respond(true, $mensaje, '/app/compras?action=facturas');
    }

    /**
     * Guarda correcciones del modal (IVA %, dcto %, costos, etc.) sin ingresar al inventario.
     * Usado por «Cerrar sin aplicar».
     */
    private function invoiceSaveDraft(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }

        $importId = (int)$this->request->post('import_id');
        if ($importId <= 0) {
            $this->json(['success' => false, 'message' => 'Importación no válida']);
            return;
        }

        try {
            $service = $this->invoiceImports();
            $current = $service->get($importId);
            if (!$current || (($current['import']['status'] ?? '') !== 'pending')) {
                throw new \RuntimeException('Solo se pueden guardar borradores pendientes');
            }

            $service->updateHeader($importId, [
                'supplier_id' => (int)$this->request->post('supplier_id', 0) ?: null,
                'invoice_number' => (string)$this->request->post('invoice_number', ''),
                'invoice_date' => (string)$this->request->post('invoice_date', ''),
                'due_date' => (string)$this->request->post('due_date', ''),
                'warehouse_date' => (string)$this->request->post('warehouse_date', ''),
                'payment_mode' => (string)$this->request->post('payment_mode', 'payable'),
                'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
                'commercial_discount_pct' => $this->request->post('commercial_discount_pct', ''),
                'commercial_discount_amount' => $this->request->post('commercial_discount_amount', ''),
                'linked_order_id' => (int)$this->request->post('linked_order_id', 0) ?: null,
                'tax_override_amount' => $this->request->post('tax_override_amount', null),
                'review_notes' => (string)$this->request->post('review_notes', ''),
            ]);

            $filas = $this->request->post('items', []);
            if (is_array($filas) && $filas) {
                $service->updateItems($importId, $filas);
            }

            $service->logLifecycleEvent(
                $importId,
                (int)($current['import']['linked_order_id'] ?? $current['import']['purchase_order_id'] ?? 0) ?: null,
                'invoice_edited',
                'Borrador editado en revisión',
                'Se guardaron cambios de cabecera y/o líneas sin ingresar al inventario'
            );

            $preview = $service->get($importId);
            $this->json([
                'success' => true,
                'message' => 'Borrador guardado (IVA y descuentos incluidos)',
                'import' => $preview['import'] ?? null,
                'items' => $preview['items'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $this->clientErrorMessage($e, 'No se pudo completar la operación')]);
        }
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=reposicion');
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=reposicion');
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=reposicion');
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=reposicion');
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=reposicion');
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
            $this->json(['success' => false, 'message' => $this->clientErrorMessage($e, 'No se pudo completar la operación')]);
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo enviar el correo'), '/app/compras');
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=reposicion');
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=facturas');
        }
    }

    /**
     * Excel (.xlsx) de líneas de una factura leída (pendiente) para revisión externa.
     * Columnas de montos van con formato moneda de celda (no texto).
     */
    private function invoiceExportXlsx(): void
    {
        $importId = (int)$this->request->get('import_id', $this->request->get('id', 0));
        $data = $this->invoiceImports()->get($importId);
        if (!$data) {
            $this->respond(false, 'Factura leída no encontrada', '/app/compras?action=facturas');
            return;
        }

        $import = $data['import'];
        $status = (string)($import['status'] ?? '');
        if ($status !== 'pending' && $status !== 'processing') {
            $this->respond(false, 'Solo se pueden exportar facturas pendientes de confirmar', '/app/compras?action=facturas');
            return;
        }

        $actionLabels = [
            'add_stock' => 'Ingresar',
            'create_product' => 'Crear producto',
            'skip' => 'Omitir',
        ];

        $money = static fn (float $n): array => ['v' => round($n, 2), 't' => 'currency'];
        $rows = [];
        $linesSum = 0.0;
        foreach ($data['items'] as $it) {
            $lineTotal = (float)($it['line_total'] ?? 0);
            $linesSum += $lineTotal;
            $unitCost = (float)($it['unit_cost'] ?? 0);
            $invCost = $it['inventory_cost'] ?? null;
            $salePrice = $it['sale_price'] ?? null;
            $matched = trim((string)($it['matched_product_name'] ?? ''));
            if ($matched !== '' && !empty($it['matched_product_code'])) {
                $matched .= ' [' . (string)$it['matched_product_code'] . ']';
            }
            $rowAction = (string)($it['row_action'] ?? 'add_stock');
            $isBonus = $unitCost <= 0.009 && $lineTotal <= 0.009
                && stripos((string)($it['description'] ?? ''), 'bonific') !== false;

            $rows[] = [
                (int)($it['line_no'] ?? 0),
                (string)($it['supplier_sku'] ?? ''),
                (string)($it['barcode'] ?? ''),
                (string)($it['description'] ?? ''),
                $matched,
                $actionLabels[$rowAction] ?? $rowAction,
                $isBonus ? 1 : 0,
                ['v' => (float)($it['quantity'] ?? 0), 't' => 'qty'],
                $money($unitCost),
                ['v' => (float)($it['tax_rate'] ?? 0), 't' => 'number'],
                ['v' => (float)($it['discount_pct'] ?? 0), 't' => 'number'],
                (string)($it['lot_code'] ?? ''),
                (string)($it['expiry_date'] ?? ''),
                ($invCost !== null && $invCost !== '') ? $money((float)$invCost) : null,
                ($salePrice !== null && $salePrice !== '') ? $money((float)$salePrice) : null,
                $money($lineTotal),
            ];
        }

        $subtotal = (float)($import['subtotal'] ?? 0);
        $taxTotal = (float)($import['tax_total'] ?? 0);
        if (isset($import['tax_override_amount']) && $import['tax_override_amount'] !== null && $import['tax_override_amount'] !== '') {
            $taxTotal = (float)$import['tax_override_amount'];
        }
        $disc = \SoftNova\Services\PurchasingService::resolvePurchaseDiscounts(
            $subtotal,
            $taxTotal,
            (float)($import['early_payment_discount_pct'] ?? 0),
            (float)($import['commercial_discount_pct'] ?? 0),
            (float)($import['commercial_discount_amount'] ?? 0)
        );
        $commercialAmt = (float)$disc['commercial_amount'];
        $subAfterDisc = max(0.0, round($subtotal - $commercialAmt, 2));

        $blank = array_fill(0, 16, null);
        $sumRow = static function (string $label, mixed $value) use ($blank): array {
            $row = $blank;
            $row[14] = $label;
            $row[15] = $value;
            return $row;
        };

        $rows[] = $blank;
        $head = $blank;
        $head[3] = 'RESUMEN FACTURA';
        $head[14] = 'Etiqueta';
        $head[15] = 'Valor';
        $rows[] = $head;
        $rows[] = $sumRow('Factura', (string)($import['invoice_number'] ?? ''));
        $rows[] = $sumRow('Proveedor', (string)($import['supplier_name'] ?? ''));
        $rows[] = $sumRow('Fecha', (string)($import['invoice_date'] ?? ''));
        $rows[] = $sumRow('Origen', strtoupper((string)($import['source'] ?? '')));
        $rows[] = $sumRow('Suma líneas', $money($linesSum));
        $rows[] = $sumRow('Total bruto', $money((float)$disc['gross']));
        $rows[] = $sumRow('Desc. comercial', $money(-1 * $commercialAmt));
        $rows[] = $sumRow('Sub total', $money($subAfterDisc));
        $rows[] = $sumRow('IVA', $money($taxTotal));
        $rows[] = $sumRow('Valor neto', $money((float)$disc['total']));

        $invNo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($import['invoice_number'] ?? 'sin_numero')) ?: 'sin_numero';
        $headers = [
            'Linea', 'Referencia', 'EAN', 'Descripcion', 'Producto_catalogo', 'Accion', 'Bonif',
            'Cantidad', 'Costo_factura', 'IVA_pct', 'Dcto_pct', 'Lote', 'Vencimiento',
            'Costo_inventario', 'Precio_venta', 'Total_linea',
        ];
        // 14 = Precio_venta en líneas (tipo por celda) y etiqueta en resumen (texto).
        $columnTypes = [
            0 => 'number',
            7 => 'qty',
            8 => 'currency',
            9 => 'number',
            10 => 'number',
            13 => 'currency',
            15 => 'currency',
        ];

        try {
            \SoftNova\Services\SimpleXlsxWriter::download(
                'factura_pendiente_' . $invNo . '_' . $importId . '_' . date('Ymd_His') . '.xlsx',
                $headers,
                $rows,
                $columnTypes
            );
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo generar el Excel'), '/app/compras?action=facturas');
        }
    }

    private function detail(): void
    {
        $orderId = (int)$this->request->get('id', 0);
        $timeline = $this->purchasing->invoiceTimeline($orderId);
        $order = $timeline['order'] ?? null;
        if (!$order) {
            $this->json(['success' => false, 'message' => 'Orden no encontrada']);
            return;
        }
        $order['timeline'] = $timeline['events'] ?? [];
        $order['credit_total'] = round(array_sum(array_map(
            static fn($n) => (($n['status'] ?? '') === 'posted' ? (float)$n['total'] : 0.0),
            $order['credit_notes'] ?? []
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
            $this->purchasing->assertCashDrawerForReversal($orderId);
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
                $this->accounting->enqueuePending(
                    'post_purchase_credit_note',
                    'purchase_credit_note',
                    (int)$result['credit_note_id'],
                    $e->getMessage()
                );
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
            if (!empty($result['fully_credited'])) {
                $message .= ' Factura totalmente acreditada/devuelta.';
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=facturas');
        }
    }

    /** Listado / administración de notas de crédito de compra. */
    private function creditNotesPanel(): void
    {
        $q = trim((string)$this->request->get('q', ''));
        $per = $this->resolvePerPage();
        $total = $this->purchasing->countCreditNotes($q);
        $pagination = $this->paginate($total, $per['perPage']);
        $notes = $this->purchasing->listCreditNotes($pagination['perPage'], $pagination['offset'], $q);

        $this->view('tenant.compras', $this->tenantViewData([
            'creditNotesPanel' => true,
            'creditNotes' => $notes,
            'creditNotesQ' => $q,
            'pagination' => $pagination,
            'perPageOptions' => $per['options'],
            'listQuery' => array_filter([
                'action' => 'notas_credito',
                'q' => $q !== '' ? $q : null,
                'per_page' => $per['perPage'] !== 20 ? (string)$per['perPage'] : null,
            ], static fn($v) => $v !== null && $v !== ''),
            'orders' => [],
            'products' => [],
            'suppliers' => [],
            'statusFilter' => '',
            'qFilter' => $q,
            'fromFilter' => '',
            'toFilter' => '',
            'supplierFilter' => 0,
            'openTotal' => 0,
            'openCount' => 0,
            'monthReceived' => 0,
            'payableTotal' => $this->purchasing->payableTotal(),
            'payableOrders' => [],
            'pendingInvoices' => [],
            'aiInvoiceReady' => false,
        ]));
    }

    /** @return array{q:string,supplier_id:int,from:string,to:string} */
    /**
     * @param 'facturas'|'ordenes' $panel
     * @return array{
     *   q:string,supplier_id:int,from:string,to:string,
     *   sort:string,dir:string,orderSql:string
     * }
     */
    private function compraListFilters(string $panel = 'ordenes'): array
    {
        $q = trim((string)$this->request->get('q', ''));
        $from = trim((string)$this->request->get('from', ''));
        $to = trim((string)$this->request->get('to', ''));
        $supplierId = (int)$this->request->get('supplier_id', 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }

        $sortable = $panel === 'facturas'
            ? [
                'invoice' => 'po.invoice_number',
                'order' => 'po.order_number',
                'date' => 'COALESCE(po.invoice_date, po.warehouse_date, po.order_date)',
                'due' => 'po.due_date',
                'supplier' => 's.name',
                'total' => 'po.total',
                'discount' => 'CASE
                    WHEN COALESCE(po.commercial_discount_amount, 0) > 0.009 THEN po.commercial_discount_amount
                    WHEN COALESCE(po.commercial_discount_pct, 0) > 0.009 THEN ROUND(COALESCE(po.subtotal, 0) * po.commercial_discount_pct / 100, 2)
                    ELSE 0 END',
                'credit' => 'COALESCE(po.credit_total, 0)',
                'net' => '(po.total - COALESCE(po.credit_total, 0))',
                'paid' => 'COALESCE(po.paid_total, 0)',
                'balance' => 'GREATEST(po.total - COALESCE(po.paid_total, 0) - COALESCE(po.credit_total, 0), 0)',
                'payment' => 'po.payment_mode',
            ]
            : [
                'order' => 'po.order_number',
                'date' => 'po.order_date',
                'expected' => 'po.expected_date',
                'supplier' => 's.name',
                'total' => 'po.total',
                'status' => 'po.status',
            ];

        $sort = (string)$this->request->get('sort', '');
        $dir = strtolower((string)$this->request->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderSql = '';
        if ($sort !== '' && isset($sortable[$sort])) {
            $orderSql = $sortable[$sort] . ' ' . strtoupper($dir);
        } else {
            $sort = '';
        }

        return [
            'q' => $q,
            'supplier_id' => $supplierId > 0 ? $supplierId : 0,
            'from' => $from,
            'to' => $to,
            'sort' => $sort,
            'dir' => $dir,
            'orderSql' => $orderSql,
        ];
    }

    /** @param array<string,mixed> $extra */
    private function compraListQuery(array $listFilters, array $extra = []): array
    {
        return array_filter(array_merge([
            'q' => ($listFilters['q'] ?? '') !== '' ? $listFilters['q'] : null,
            'from' => ($listFilters['from'] ?? '') !== '' ? $listFilters['from'] : null,
            'to' => ($listFilters['to'] ?? '') !== '' ? $listFilters['to'] : null,
            'supplier_id' => !empty($listFilters['supplier_id']) ? (string)$listFilters['supplier_id'] : null,
            'sort' => ($listFilters['sort'] ?? '') !== '' ? $listFilters['sort'] : null,
            'dir' => ($listFilters['sort'] ?? '') !== '' ? ($listFilters['dir'] ?? null) : null,
        ], $extra), static fn($v) => $v !== null && $v !== '');
    }

    /**
     * CSV de OC / Facturas / NC respetando filtros actuales.
     * Sin filtros → exporta todo el listado del tab.
     */
    private function exportCompras(): void
    {
        $panel = (string)$this->request->get('panel', 'ordenes');
        if (!in_array($panel, ['ordenes', 'facturas', 'notas_credito'], true)) {
            $panel = 'ordenes';
        }

        $listFilters = $this->compraListFilters($panel === 'facturas' ? 'facturas' : 'ordenes');
        $limit = 20000;

        if ($panel === 'facturas') {
            $due = (string)$this->request->get('due', '');
            if (!in_array($due, ['overdue', 'due_today', 'due_soon', 'no_due'], true)) {
                $due = '';
            }
            $payment = (string)$this->request->get('payment', '');
            if (!in_array($payment, ['unpaid', 'partial', 'paid', 'paid_only', 'pending', 'voided', 'active'], true)) {
                $payment = '';
            }
            $rows = $this->purchasing->listInvoices($limit, 0, $payment, $due, $listFilters);
            $csvRows = [];
            foreach ($rows as $o) {
                $total = (float)($o['total'] ?? 0);
                $paid = (float)($o['paid_total'] ?? 0);
                $credit = (float)($o['credit_total'] ?? 0);
                $balance = max(0, $total - $paid - $credit);
                $mode = (string)($o['payment_mode'] ?? 'payable');
                $isCancelled = ($o['status'] ?? '') === 'cancelled';
                $stateLabel = $isCancelled
                    ? 'Anulada'
                    : ($mode === 'payable' && $balance > 0.009
                        ? (($paid + $credit) > 0.009 ? 'Abono parcial' : 'Por pagar')
                        : 'Pagada');
                $daysOver = isset($o['days_overdue']) ? (int)$o['days_overdue'] : null;
                $overdueDays = (!$isCancelled && $mode === 'payable' && $balance > 0.009 && !empty($o['due_date']) && $daysOver !== null && $daysOver > 0)
                    ? (string)$daysOver
                    : '';
                $discAmt = round((float)($o['commercial_discount_amount'] ?? 0), 2);
                $discPct = (float)($o['commercial_discount_pct'] ?? 0);
                if ($discAmt <= 0.009 && $discPct > 0.009) {
                    $discAmt = round((float)($o['subtotal'] ?? 0) * $discPct / 100, 2);
                }
                $csvRows[] = [
                    (string)($o['invoice_number'] ?? ''),
                    (string)($o['order_number'] ?? ''),
                    substr((string)($o['invoice_date'] ?? $o['warehouse_date'] ?? $o['order_date'] ?? ''), 0, 10),
                    (string)($o['due_date'] ?? ''),
                    $overdueDays,
                    (string)($o['supplier_name'] ?? ''),
                    number_format($total, 2, '.', ''),
                    number_format($discAmt, 2, '.', ''),
                    number_format($paid, 2, '.', ''),
                    number_format($credit, 2, '.', ''),
                    number_format($balance, 2, '.', ''),
                    $stateLabel,
                    match ($mode) {
                        'cash' => 'Efectivo',
                        'transfer' => 'Transferencia',
                        'card' => 'Tarjeta',
                        default => 'Crédito',
                    },
                ];
            }
            $this->exportCsv(
                'compras_facturas_' . ($payment === 'unpaid' ? 'por_pagar_' : '') . date('Ymd_His') . '.csv',
                ['Factura', 'OC', 'Fecha', 'Vence', 'Días vencida', 'Proveedor', 'Total', 'Dcto comercial', 'Abonado', 'NC', 'Saldo', 'Estado pago', 'Modo pago'],
                $csvRows
            );
            return;
        }

        if ($panel === 'notas_credito') {
            $q = trim((string)$this->request->get('q', ''));
            $rows = $this->purchasing->listCreditNotes($limit, 0, $q);
            $csvRows = [];
            foreach ($rows as $n) {
                $csvRows[] = [
                    (string)($n['credit_number'] ?? ''),
                    substr((string)($n['credit_date'] ?? ''), 0, 10),
                    (string)($n['supplier_name'] ?? ''),
                    (string)($n['order_number'] ?? ''),
                    (string)($n['invoice_number'] ?? $n['external_number'] ?? ''),
                    (string)($n['reason'] ?? ''),
                    (int)($n['affects_stock'] ?? 0) === 1 ? 'Si' : 'No',
                    number_format((float)($n['total'] ?? 0), 2, '.', ''),
                    (string)($n['accounting_entry_number'] ?? ''),
                ];
            }
            $this->exportCsv(
                'compras_nc_' . date('Ymd_His') . '.csv',
                ['Numero', 'Fecha', 'Proveedor', 'OC', 'Factura', 'Motivo', 'Afecta stock', 'Total', 'Asiento'],
                $csvRows
            );
            return;
        }

        $status = (string)$this->request->get('status', '');
        if ($status === 'all') {
            $status = '';
        }
        if ($status !== '' && !in_array($status, ['open', 'draft', 'ordered', 'cancelled'], true)) {
            $status = '';
        }
        $rows = $this->purchasing->listOrders($limit, 0, $status, '', $listFilters);
        $statusLabels = [
            'draft' => 'Borrador',
            'ordered' => 'Ordenada',
            'received' => 'Recibida',
            'cancelled' => 'Cancelada',
        ];
        $csvRows = [];
        foreach ($rows as $o) {
            $st = (string)($o['status'] ?? '');
            $csvRows[] = [
                (string)($o['order_number'] ?? ''),
                substr((string)($o['order_date'] ?? ''), 0, 10),
                (string)($o['expected_date'] ?? ''),
                (string)($o['supplier_name'] ?? ''),
                number_format((float)($o['total'] ?? 0), 2, '.', ''),
                $statusLabels[$st] ?? $st,
                (string)($o['notes'] ?? ''),
            ];
        }
        $this->exportCsv(
            'compras_ordenes_' . date('Ymd_His') . '.csv',
            ['OC', 'Fecha', 'Entrega est.', 'Proveedor', 'Total', 'Estado', 'Notas'],
            $csvRows
        );
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
     * Observaciones internas de factura/OC (review_notes).
     */
    private function purchaseBudgetStatus(): ?array
    {
        try {
            return (new \SoftNova\Services\PurchaseBudgetService($this->db))->status(
                (string)$this->request->get('pb_from', ''),
                (string)$this->request->get('pb_to', '')
            );
        } catch (\Throwable $e) {
            error_log('Semáforo compras: ' . $e->getMessage());
            return null;
        }
    }

    /** Límite mensual de compras (semáforo): solo Administrador o Gerencia. */
    private function savePurchaseLimit(): void
    {
        $back = $this->request->post('return', '') === 'facturas' ? '/app/compras?action=facturas' : '/app/compras';
        $rangeQs = http_build_query(array_filter([
            'pb_from' => (string)$this->request->post('pb_from', ''),
            'pb_to' => (string)$this->request->post('pb_to', ''),
        ], static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1));
        if ($rangeQs !== '') {
            $back .= (str_contains($back, '?') ? '&' : '?') . $rangeQs;
        }
        if (!$this->validateCsrfOrFail($back)) {
            return;
        }
        if (!TenantMiddleware::isManagement()) {
            $this->respond(false, 'Solo el administrador o gerencia pueden cambiar el límite de compras', $back);
            return;
        }
        $raw = trim((string)$this->request->post('limit', ''));
        if ($raw !== '' && !is_numeric($raw)) {
            $this->respond(false, 'Monto límite inválido', $back);
            return;
        }
        $limit = $raw === '' ? 0.0 : (float)$raw;
        $warnPct = (int)$this->request->post('warn_pct', \SoftNova\Services\PurchaseBudgetService::DEFAULT_WARN_PCT);
        try {
            (new \SoftNova\Services\PurchaseBudgetService($this->db))->save($limit, $warnPct);
            $msg = $limit > 0
                ? 'Límite mensual de compras actualizado a ' . $this->formatMoney($limit)
                : 'Límite mensual de compras desactivado';
            $this->respond(true, $msg, $back);
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo guardar el límite'), $back);
        }
    }

    private function saveReviewNotes(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }
        $orderId = (int)$this->request->post('id', 0);
        $notes = (string)$this->request->post('review_notes', '');
        try {
            $result = $this->purchasing->saveReviewNotes($orderId, $notes);
            $msg = $result['changed']
                ? ($result['review_notes'] !== '' ? 'Observación guardada' : 'Observación eliminada')
                : 'Sin cambios';
            $this->respond(true, $msg, '/app/compras?action=facturas');
        } catch (\Throwable $e) {
            $this->respond(
                false,
                $this->clientErrorMessage($e, 'No se pudo guardar la observación'),
                '/app/compras?action=facturas'
            );
        }
    }

    /**
     * Corrección: el abono quedó como efectivo del cajón pero fue de otro origen.
     * Reintegra el cajón, reversa el asiento y lo re-emite con crédito a bancos.
     */
    private function fixPaymentCashSource(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }
        $paymentId = (int)$this->request->post('payment_id', 0);
        $sourceNote = trim((string)$this->request->post('cash_source_note', ''));
        try {
            $result = $this->purchasing->correctSupplierPaymentCashSource($paymentId, $sourceNote);

            $accountingNote = '';
            if (($result['accounting_entry_id'] ?? 0) > 0) {
                $reversed = false;
                try {
                    $this->accounting->reverseSource(
                        'supplier_payment',
                        $paymentId,
                        'Corrección abono #' . $paymentId . ': el efectivo no salió del cajón'
                    );
                    $reversed = true;
                } catch (\Throwable $e) {
                    error_log('Reverso corrección abono #' . $paymentId . ': ' . $e->getMessage());
                    $this->accounting->enqueuePending(
                        'reverse_source',
                        'supplier_payment',
                        $paymentId,
                        $e->getMessage(),
                        ['reason' => 'Corrección abono #' . $paymentId . ': el efectivo no salió del cajón']
                    );
                }
                if ($reversed) {
                    try {
                        $this->purchasing->clearSupplierPaymentAccountingEntry($paymentId);
                        $this->accounting->postSupplierPayment($paymentId);
                        $accountingNote = ' Asiento re-emitido con crédito a bancos (111005).';
                    } catch (\Throwable $e) {
                        error_log('Re-emisión corrección abono #' . $paymentId . ': ' . $e->getMessage());
                        $this->accounting->enqueuePending(
                            'post_supplier_payment',
                            'supplier_payment',
                            $paymentId,
                            $e->getMessage()
                        );
                        $accountingNote = ' Asiento pendiente: use «Sincronizar» en Contabilidad.';
                    }
                } else {
                    $accountingNote = ' Reverso contable pendiente: use «Sincronizar» en Contabilidad.';
                }
            }

            $this->respond(
                true,
                'Origen del efectivo corregido en el abono #' . $paymentId
                    . (($result['order_number'] ?? '') !== '' ? ' (OC ' . $result['order_number'] . ')' : '')
                    . ' por ' . $this->formatMoney((float)($result['amount'] ?? 0)) . '. '
                    . ($result['cash_note'] ?? '') . $accountingNote,
                '/app/compras?action=facturas'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo corregir el origen del efectivo'), '/app/compras?action=facturas');
        }
    }

    /**
     * Corrección: el dcto. pronto pago de un abono se registró mal.
     * Actualiza el descuento, reversa el asiento y lo re-emite (421040 ajustado).
     * El monto pagado no cambia: la caja no se toca.
     */
    private function fixPaymentDiscount(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }
        $paymentId = (int)$this->request->post('payment_id', 0);
        $pctRaw = trim((string)$this->request->post('discount_pct', ''));
        $pct = $pctRaw !== ''
            ? max(0.0, min(100.0, (float)str_replace(',', '.', $pctRaw)))
            : null;
        $amountRaw = trim((string)$this->request->post('discount_amount', ''));
        $discountAmount = $amountRaw !== '' ? \SoftNova\Core\parse_money($amountRaw) : null;
        $reason = trim((string)$this->request->post('reason', ''));
        try {
            $result = $this->purchasing->correctSupplierPaymentDiscount(
                $paymentId,
                $discountAmount,
                $pct,
                $reason
            );

            $accountingNote = '';
            if (($result['accounting_entry_id'] ?? 0) > 0) {
                $reversed = false;
                try {
                    $this->accounting->reverseSource(
                        'supplier_payment',
                        $paymentId,
                        'Corrección dcto. pronto pago abono #' . $paymentId . ': $'
                            . number_format((float)$result['old_discount'], 2, '.', '')
                            . ' → $' . number_format((float)$result['new_discount'], 2, '.', '')
                    );
                    $reversed = true;
                } catch (\Throwable $e) {
                    error_log('Reverso corrección dcto. abono #' . $paymentId . ': ' . $e->getMessage());
                    $this->accounting->enqueuePending(
                        'reverse_source',
                        'supplier_payment',
                        $paymentId,
                        $e->getMessage(),
                        ['reason' => 'Corrección dcto. pronto pago abono #' . $paymentId]
                    );
                }
                if ($reversed) {
                    try {
                        $this->purchasing->clearSupplierPaymentAccountingEntry($paymentId);
                        $this->accounting->postSupplierPayment($paymentId);
                        $accountingNote = ' Asiento re-emitido con el descuento corregido (ingreso 421040 ajustado).';
                    } catch (\Throwable $e) {
                        error_log('Re-emisión corrección dcto. abono #' . $paymentId . ': ' . $e->getMessage());
                        $this->accounting->enqueuePending(
                            'post_supplier_payment',
                            'supplier_payment',
                            $paymentId,
                            $e->getMessage()
                        );
                        $accountingNote = ' Asiento pendiente: use «Sincronizar» en Contabilidad.';
                    }
                } else {
                    $accountingNote = ' Reverso contable pendiente: use «Sincronizar» en Contabilidad.';
                }
            }

            $this->respond(
                true,
                'Dcto. pronto pago del abono #' . $paymentId
                    . (($result['order_number'] ?? '') !== '' ? ' (OC ' . $result['order_number'] . ')' : '')
                    . ' corregido: ' . $this->formatMoney((float)$result['old_discount'])
                    . ' → ' . $this->formatMoney((float)$result['new_discount'])
                    . '. Saldo pendiente de la factura: ' . $this->formatMoney((float)$result['balance'])
                    . '.' . $accountingNote,
                '/app/compras?action=facturas'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo corregir el descuento'), '/app/compras?action=facturas');
        }
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
        $invoiceValue = \SoftNova\Core\parse_money($this->request->post('invoice_value', '0'));
        $discountPct = max(0.0, min(100.0, (float)str_replace(',', '.', (string)$this->request->post('discount_pct', '0'))));
        $discountAmount = \SoftNova\Core\parse_money($this->request->post('discount_amount', '0'));
        $paymentDate = trim((string)$this->request->post('payment_date', date('Y-m-d')));
        $method = trim((string)$this->request->post('payment_method', 'cash'));
        $reference = trim((string)$this->request->post('reference', ''));
        $notes = trim((string)$this->request->post('notes', ''));
        $cashSource = strtolower(trim((string)$this->request->post('cash_source', '')));
        // Sin origen explícito NO se asume cajón: es la fuente de errores al abonar.
        if (PaymentMethodCatalog::affectsCash(PaymentMethodCatalog::normalize($method))
            && $cashSource !== 'drawer' && $cashSource !== 'other') {
            $this->respond(
                false,
                'Debe indicar el origen del efectivo (Caja u Otro) para registrar el abono.',
                '/app/compras?action=facturas'
            );
            return;
        }
        $cashFromDrawer = $cashSource !== 'other';
        $cashSourceNote = trim((string)$this->request->post('cash_source_note', ''));

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
                $receipt,
                [
                    'cash_from_drawer' => $cashFromDrawer,
                    'cash_source_note' => $cashSourceNote,
                    'invoice_value' => $invoiceValue,
                    'discount_pct' => $discountPct,
                    'discount_amount' => $discountAmount,
                ]
            );

            try {
                $this->accounting->postSupplierPayment($result['payment_id']);
                $accountingOk = true;
            } catch (\Throwable $e) {
                $accountingOk = false;
                error_log('Contabilidad abono proveedor ' . $result['payment_id'] . ': ' . $e->getMessage());
                $this->accounting->enqueuePending(
                    'post_supplier_payment',
                    'supplier_payment',
                    (int)$result['payment_id'],
                    $e->getMessage()
                );
            }

            $cashNote = $this->purchasing->syncCashForSupplierPayment((int)$result['payment_id']);

            $message = 'Abono registrado por ' . $this->formatMoney($amount) . '. ';
            if (($result['discount_amount'] ?? 0) > 0.009) {
                $message .= 'Dcto. pronto pago aplicado: ' . $this->formatMoney((float)$result['discount_amount']) . '. ';
            }
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras?action=facturas');
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
        $defaultTax = \SoftNova\Services\TaxService::defaultRate($this->db);

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
                'tax_rate' => (float)($taxRates[$i] ?? $defaultTax),
            ];
        }

        try {
            $status = $this->request->post('save_as_draft') === '1' ? 'draft' : 'ordered';
            $orderId = $this->purchasing->createOrder(
                $supplierId,
                $orderDate !== '' ? $orderDate : date('Y-m-d'),
                null,
                $expectedDate !== '' ? $expectedDate : null,
                $notes,
                $items,
                $status,
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras');
        }
    }

    private function updateOrder(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $orderId = (int)$this->request->post('order_id', 0);
        if ($orderId <= 0) {
            $this->respond(false, 'Orden inválida', '/app/compras');
            return;
        }

        $supplierId = (int)$this->request->post('supplier_id', 0) ?: null;
        $orderDate = (string)$this->request->post('order_date', date('Y-m-d'));
        $expectedDate = trim((string)$this->request->post('expected_date', ''));
        $notes = trim((string)$this->request->post('notes', ''));
        $existing = $this->purchasing->getOrder($orderId);
        if (!$existing) {
            $this->respond(false, 'Orden no encontrada', '/app/compras');
            return;
        }
        $requestedDraft = $this->request->post('save_as_draft') === '1';
        if (($existing['status'] ?? '') === 'ordered' && $requestedDraft) {
            $status = 'ordered';
        } else {
            $status = $requestedDraft ? 'draft' : 'ordered';
        }

        $productIds = (array)$this->request->post('product_id', []);
        $quantities = (array)$this->request->post('quantity', []);
        $unitCosts = (array)$this->request->post('unit_cost', []);
        $taxRates = (array)$this->request->post('tax_rate', []);
        $defaultTax = \SoftNova\Services\TaxService::defaultRate($this->db);

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
                'tax_rate' => (float)($taxRates[$i] ?? $defaultTax),
            ];
        }

        try {
            $this->purchasing->updatePendingOrder(
                $orderId,
                $supplierId,
                $orderDate !== '' ? $orderDate : date('Y-m-d'),
                $expectedDate !== '' ? $expectedDate : null,
                $notes,
                $items,
                $status
            );
            $order = $this->purchasing->getOrder($orderId);
            $this->respond(
                true,
                'Orden actualizada: ' . ($order['order_number'] ?? ('#' . $orderId)),
                '/app/compras'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras');
        }
    }

    private function receive(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }
        try {
            $orderId = (int)$this->request->post('id', 0);
            if ((string)$this->request->post('payment_mode', 'payable') === 'cash'
                && !(new \SoftNova\Services\CashService($this->db))->getOpenSession()
            ) {
                throw new \RuntimeException(
                    'Para pagar en efectivo desde el cajón debe haber una caja abierta. Abra la caja o elija otra forma de pago.'
                );
            }
            $this->purchasing->applyInvoiceMeta($orderId, [
                'due_date' => trim((string)$this->request->post('due_date', '')),
                'invoice_date' => trim((string)$this->request->post('invoice_date', '')),
                'invoice_number' => trim((string)$this->request->post('invoice_number', '')),
                'early_payment_discount_pct' => $this->request->post('early_payment_discount_pct', ''),
                'commercial_discount_pct' => $this->request->post('commercial_discount_pct', ''),
                'commercial_discount_amount' => $this->request->post('commercial_discount_amount', ''),
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras');
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
            $out['accounting_note'] = 'Inventario actualizado, pero el asiento contable quedó pendiente. Use «Sincronizar» en Contabilidad.';
            error_log('Contabilidad OC ' . $orderId . ': ' . $e->getMessage());
            $this->accounting->enqueuePending(
                'post_purchase_order',
                'purchase_order',
                $orderId,
                $e->getMessage()
            );
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
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo completar la operación'), '/app/compras');
        }
    }

    /**
     * Anula factura/OC ya recibida por error: NC total + salida stock + asiento + status cancelled.
     */
    private function voidReceived(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras?action=facturas')) {
            return;
        }
        $orderId = (int)$this->request->post('id', 0);
        $reason = trim((string)$this->request->post('reason', ''));
        try {
            $this->purchasing->assertCashDrawerForReversal($orderId);
            $result = $this->purchasing->voidReceivedOrder($orderId, $reason);

            // Reverso contable/caja de abonos que se cancelaron al anular.
            $accountingIssues = [];
            foreach (($result['reversed_payments'] ?? []) as $pay) {
                $payId = (int)($pay['id'] ?? 0);
                if ($payId <= 0) {
                    continue;
                }
                try {
                    $this->accounting->reverseSource(
                        'supplier_payment',
                        $payId,
                        'Anulación OC ' . ($result['order_number'] ?? '') . ' — reverso abono #' . $payId
                    );
                } catch (\Throwable $e) {
                    $accountingIssues[] = 'abono #' . $payId;
                    $this->accounting->enqueuePending(
                        'reverse_source',
                        'supplier_payment',
                        $payId,
                        $e->getMessage(),
                        ['reason' => 'Anulación OC ' . ($result['order_number'] ?? '') . ' — reverso abono #' . $payId]
                    );
                    error_log('Reverso abono proveedor #' . $payId . ': ' . $e->getMessage());
                }
                try {
                    $this->purchasing->syncCashReverseForSupplierPayment($payId);
                } catch (\Throwable $e) {
                    error_log('Reverso caja abono #' . $payId . ': ' . $e->getMessage());
                }
            }

            $accountingOk = true;
            $cashNote = '';
            if (!empty($result['direct'])) {
                $voidReason = 'Anulación OC ' . ($result['order_number'] ?? '') . ': '
                    . ($reason !== '' ? $reason : 'Error de recepción');
                try {
                    $this->accounting->voidPurchaseOrderEntry($orderId, $voidReason);
                } catch (\Throwable $e) {
                    $accountingOk = false;
                    $this->accounting->enqueuePending(
                        'void_purchase_order',
                        'purchase_order',
                        $orderId,
                        $e->getMessage(),
                        ['reason' => $voidReason]
                    );
                    error_log('Contabilidad anulación OC ' . $orderId . ': ' . $e->getMessage());
                }
                $cashNote = $this->purchasing->syncCashForVoidedOrder($orderId);
            } elseif (!empty($result['credit_note_id'])) {
                try {
                    $this->accounting->postPurchaseCreditNote((int)$result['credit_note_id']);
                } catch (\Throwable $e) {
                    $accountingOk = false;
                    $this->accounting->enqueuePending(
                        'post_purchase_credit_note',
                        'purchase_credit_note',
                        (int)$result['credit_note_id'],
                        $e->getMessage()
                    );
                    error_log('Contabilidad anulación OC/NC ' . $result['credit_note_id'] . ': ' . $e->getMessage());
                }
                $cashNote = $this->purchasing->syncCashForCreditNote((int)$result['credit_note_id']);
            }

            if (!empty($result['direct'])) {
                $msg = 'OC ' . ($result['order_number'] ?? '') . ' anulada por '
                    . $this->formatMoney((float)($result['total'] ?? 0))
                    . '. Inventario y contabilidad revertidos.';
            } elseif (!empty($result['already_credited'])) {
                $msg = 'OC ' . ($result['order_number'] ?? '') . ' marcada como anulada (ya estaba totalmente acreditada).';
            } else {
                $msg = 'OC ' . ($result['order_number'] ?? '') . ' anulada. Ya tenía notas crédito: el resto se acreditó con NC '
                    . ($result['credit_number'] ?? '') . ' por '
                    . $this->formatMoney((float)($result['total'] ?? 0))
                    . '. Inventario y CxP/caja revertidos.';
            }
            $revCount = count($result['reversed_payments'] ?? []);
            if ($revCount > 0) {
                $msg .= ' Se revirtieron ' . $revCount . ' abono(s).';
            }
            if ($cashNote !== '') {
                $msg .= ' ' . $cashNote;
            }
            if (!$accountingOk || $accountingIssues !== []) {
                $msg .= ' Asiento pendiente: use «Sincronizar» en Contabilidad.';
            }
            $this->respond(true, $msg, '/app/compras?action=facturas', [
                'credit_note_id' => (int)($result['credit_note_id'] ?? 0),
                'credit_number' => (string)($result['credit_number'] ?? ''),
                'csrf_token' => \SoftNova\Core\csrf_token(),
            ]);
        } catch (\Throwable $e) {
            $this->respond(false, $this->clientErrorMessage($e, 'No se pudo anular la factura'), '/app/compras?action=facturas');
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
