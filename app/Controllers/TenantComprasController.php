<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\AccountingService;
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
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'invoice' && $this->request->method() === 'GET') {
            $this->serveInvoice();
            return;
        }
        if ($action === 'invoice_import' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('compras', 'create');
            $this->invoiceImport();
            return;
        }
        if ($action === 'invoice_preview' && $this->request->method() === 'GET') {
            $this->invoicePreview();
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

        $status = (string)$this->request->get('status', '');
        $due = (string)$this->request->get('due', '');
        if (!in_array($due, ['overdue', 'due_today', 'due_soon', 'no_due'], true)) {
            $due = '';
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
            "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"
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
            'orders' => $orders,
            'products' => $products,
            'suppliers' => $suppliers,
            'pagination' => $pagination,
            'statusFilter' => $status,
            'dueFilter' => $due,
            'openTotal' => $openTotal,
            'monthReceived' => $monthReceived,
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
                    'use_ai' => (string)$this->request->post('use_ai', '') !== '',
                ],
                $_FILES['invoice_file'] ?? null,
                (string)$this->request->post('pasted_text', '')
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
            return;
        }

        $mensaje = 'Factura leída. Revise las líneas antes de ingresarlas al inventario';
        if ($resultado['notes'] !== '') {
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

        $this->respond(true, $mensaje, '/app/compras?import_id=' . $resultado['import_id']);
    }

    /** Datos del borrador para el modal de confirmación. */
    private function invoicePreview(): void
    {
        $data = $this->invoiceImports()->get((int)$this->request->get('id', 0));
        if (!$data) {
            $this->json(['success' => false, 'message' => 'Importación no encontrada']);
            return;
        }

        $this->json([
            'success' => true,
            'import' => $data['import'],
            'items' => $data['items'],
        ]);
    }

    /** Aplica el borrador corregido: crea la orden, recibe el stock y contabiliza. */
    private function invoiceConfirm(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $importId = (int)$this->request->post('import_id');
        if ($importId <= 0) {
            $this->respond(false, 'Importación no válida', '/app/compras');
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
            $this->respond(false, $e->getMessage(), '/app/compras');
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

        $this->respond(true, $mensaje, '/app/compras');
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
            'draftOrders' => $service->pendingOrders(30),
        ]));
    }

    /** Crea una OC en borrador por proveedor con las cantidades seleccionadas. */
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
            $lineas[] = '- ' . (string)$item['product_name'] . ' x ' . (int)$item['quantity'];
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
            $this->respond(true, 'Importación descartada', '/app/compras');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
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
            } catch (\Throwable $e) {
                error_log('Contabilidad nota crédito compra ' . $result['credit_note_id'] . ': ' . $e->getMessage());
            }

            $message = 'Nota de crédito ' . $result['credit_number']
                . ' por ' . $this->formatMoney($result['total']) . '. ';
            if ($result['balance'] <= 0.009) {
                $message .= 'La orden queda sin saldo pendiente.';
            } elseif ($result['balance'] < 0) {
                $message .= 'Saldo a favor: ' . $this->formatMoney(abs($result['balance'])) . '.';
            } else {
                $message .= 'Saldo pendiente: ' . $this->formatMoney($result['balance']) . '.';
            }

            $pdfUrl = base_url('/app/compras?action=credit_note_pdf&id=' . (int)$result['credit_note_id']);
            $this->respond(true, $message, '/app/compras?action=notas_credito', [
                'pdf_url' => $pdfUrl,
                'credit_note_id' => (int)$result['credit_note_id'],
                'credit_number' => $result['credit_number'],
            ]);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
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
        $amount = (float)str_replace([',', ' '], ['.', ''], (string)$this->request->post('amount', '0'));
        $paymentDate = trim((string)$this->request->post('payment_date', date('Y-m-d')));
        $method = trim((string)$this->request->post('payment_method', 'cash'));
        $reference = trim((string)$this->request->post('reference', ''));
        $notes = trim((string)$this->request->post('notes', ''));

        try {
            $result = $this->purchasing->registerPayment(
                $orderId,
                $amount,
                $paymentDate !== '' ? $paymentDate : date('Y-m-d'),
                $method,
                $reference,
                $notes
            );

            try {
                $this->accounting->postSupplierPayment($result['payment_id']);
            } catch (\Throwable $e) {
                error_log('Contabilidad abono proveedor ' . $result['payment_id'] . ': ' . $e->getMessage());
            }

            $message = 'Abono registrado por ' . $this->formatMoney($amount) . '. ';
            $message .= $result['balance'] <= 0.009
                ? 'La orden queda pagada en su totalidad.'
                : 'Saldo pendiente: ' . $this->formatMoney($result['balance']) . '.';

            $this->respond(true, $message, '/app/compras');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/compras');
        }
    }

    private function create(): void
    {
        if (!$this->validateCsrfOrFail('/app/compras')) {
            return;
        }

        $supplierId = (int)$this->request->post('supplier_id', 0) ?: null;
        $orderDate = (string)$this->request->post('order_date', date('Y-m-d'));
        $warehouseDate = trim((string)$this->request->post('warehouse_date', ''));
        $expectedDate = trim((string)$this->request->post('expected_date', ''));
        $notes = trim((string)$this->request->post('notes', ''));
        $receiveNow = $this->request->post('receive_now') === '1';
        $earlyDiscount = (float)$this->request->post('early_payment_discount_pct', 0);
        $dueDate = trim((string)$this->request->post('due_date', ''));
        $invoiceDate = trim((string)$this->request->post('invoice_date', ''));
        $invoiceNumberCreate = trim((string)$this->request->post('invoice_number', ''));

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
                'unit_cost' => (float)($unitCosts[$i] ?? 0),
                'tax_rate' => (float)($taxRates[$i] ?? 19),
            ];
        }

        try {
            $orderId = $this->purchasing->createOrder(
                $supplierId,
                $orderDate !== '' ? $orderDate : date('Y-m-d'),
                $warehouseDate !== '' ? $warehouseDate : null,
                $expectedDate !== '' ? $expectedDate : null,
                $notes,
                $items,
                'ordered',
                $earlyDiscount,
                $dueDate !== '' ? $dueDate : null,
                $invoiceDate !== '' ? $invoiceDate : null,
                $invoiceNumberCreate !== '' ? $invoiceNumberCreate : null
            );

            $invoiceFile = $this->storeInvoiceUpload();
            if ($invoiceFile === false) {
                $this->respond(false, 'No se pudo subir la foto/PDF de la factura (máx 5MB: JPG, PNG, WEBP o PDF)', '/app/compras');
                return;
            }
            if ($invoiceFile !== null) {
                $this->purchasing->attachInvoiceFile($orderId, $invoiceFile);
            }

            if ($receiveNow) {
                $this->doReceive(
                    $orderId,
                    $warehouseDate !== '' ? $warehouseDate : $orderDate,
                    trim((string)$this->request->post('invoice_number', '')),
                    (string)$this->request->post('payment_mode', 'payable'),
                    $notes
                );
                $this->respond(true, 'Orden creada y mercancía recibida/contabilizada', '/app/compras');
                return;
            }

            $order = $this->purchasing->getOrder($orderId);
            $this->respond(
                true,
                'Orden creada: ' . ($order['order_number'] ?? ('#' . $orderId)),
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
            $this->doReceive(
                $orderId,
                (string)$this->request->post('warehouse_date', date('Y-m-d')),
                trim((string)$this->request->post('invoice_number', '')),
                (string)$this->request->post('payment_mode', 'payable'),
                trim((string)$this->request->post('notes', ''))
            );
            $invoiceFile = $this->storeInvoiceUpload();
            if ($invoiceFile === false) {
                $this->respond(false, 'Mercancía recibida, pero falló la subida de la foto/PDF de factura', '/app/compras');
                return;
            }
            if ($invoiceFile !== null) {
                $this->purchasing->attachInvoiceFile($orderId, $invoiceFile);
            }
            $this->respond(true, 'Mercancía recibida, inventario y contabilidad actualizados', '/app/compras');
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
    ): void {
        $this->purchasing->receiveOrder($orderId, $warehouseDate, $invoiceNumber, $paymentMode, $notes);
        try {
            $this->accounting->postPurchaseOrder($orderId);
        } catch (\Throwable $e) {
            error_log('Contabilidad OC ' . $orderId . ': ' . $e->getMessage());
        }
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

    /**
     * @return array{path:string,original:string,mime:string}|null|false null=sin archivo, false=error
     */
    private function storeInvoiceUpload(): array|null|false
    {
        if (empty($_FILES['invoice_file']['tmp_name'])) {
            return null;
        }
        if (!is_uploaded_file($_FILES['invoice_file']['tmp_name'])) {
            return false;
        }
        if (($_FILES['invoice_file']['size'] ?? 0) > self::MAX_INVOICE_BYTES) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['invoice_file']['tmp_name']);
        if (!isset(self::INVOICE_MIME[$mime])) {
            return false;
        }
        $ext = self::INVOICE_MIME[$mime];
        $dir = PUBLIC_PATH . '/uploads/purchases';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'po_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dest)) {
            return false;
        }
        return [
            'path' => 'uploads/purchases/' . $filename,
            'original' => (string)($_FILES['invoice_file']['name'] ?? $filename),
            'mime' => $mime,
        ];
    }
}
