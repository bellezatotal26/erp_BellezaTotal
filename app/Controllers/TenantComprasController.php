<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\AccountingService;
use SoftNova\Services\PurchasingService;

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
        if ($action === 'detail' && $this->request->method() === 'GET') {
            $this->detail();
            return;
        }
        if ($action === 'invoice' && $this->request->method() === 'GET') {
            $this->serveInvoice();
            return;
        }

        $status = (string)$this->request->get('status', '');
        $total = $this->purchasing->countOrders($status);
        $pagination = $this->paginate($total);
        $orders = $this->purchasing->listOrders($pagination['perPage'], $pagination['offset'], $status);

        $products = $this->query(
            "SELECT id, code, name, purchase_price, stock
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
            'openTotal' => $openTotal,
            'monthReceived' => $monthReceived,
        ]));
    }

    private function detail(): void
    {
        $order = $this->purchasing->getOrder((int)$this->request->get('id', 0));
        if (!$order) {
            $this->json(['success' => false, 'message' => 'Orden no encontrada']);
            return;
        }
        $this->json(['success' => true, 'order' => $order]);
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
