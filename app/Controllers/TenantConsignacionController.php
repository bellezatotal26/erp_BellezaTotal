<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\ConsignmentService;
use SoftNova\Services\PaymentMethodCatalog;
use SoftNova\Services\TaxService;

/**
 * Productos por consignación: mercancía de terceros (no proveedores).
 * No capitaliza inventario propio; se paga al consignante tras la venta.
 */
class TenantConsignacionController extends TenantController
{
    private ConsignmentService $consignment;

    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('consignacion');
        $this->consignment = new ConsignmentService($this->db);
    }

    public function index(): void
    {
        $action = (string)$this->request->get('action', '');

        if ($this->request->method() === 'POST') {
            match ($action) {
                'saveConsignor' => $this->saveConsignor(),
                'createProduct' => $this->createProduct(),
                'editProduct' => $this->editProduct(),
                'receive' => $this->receive(),
                'returnStock' => $this->returnStock(),
                'deleteProduct' => $this->deleteProduct(),
                'settle' => $this->settle(),
                default => $this->respond(false, 'Acción no válida', '/app/consignacion'),
            };
            return;
        }

        $tab = (string)$this->request->get('tab', 'productos');
        $consignorFilter = (int)$this->request->get('consignor_id', 0);
        $supplierFilter = (int)$this->request->get('supplier_id', 0);
        $showInactive = (int)$this->request->get('show_inactive', 0) === 1;
        $q = trim((string)$this->request->get('q', ''));

        $consignors = $this->consignment->listConsignors(false);
        $suppliers = $this->loadActiveSuppliers();
        $viewData = [
            'tab' => $tab,
            'stats' => $this->consignment->dashboardStats(),
            'consignors' => $consignors,
            'suppliers' => $suppliers,
            'products' => [],
            'receiveProducts' => [],
            'openDebts' => [],
            'receipts' => [],
            'settlements' => [],
            'consignorFilter' => $consignorFilter,
            'supplierFilter' => $supplierFilter,
            'showInactive' => $showInactive,
            'q' => $q,
            'paymentMethods' => PaymentMethodCatalog::all(),
            'canCreate' => TenantMiddleware::canDo('create', 'consignacion'),
            'canEdit' => TenantMiddleware::canDo('edit', 'consignacion'),
            'canDelete' => TenantMiddleware::canDo('delete', 'consignacion'),
            'fmt' => fn($a) => $this->formatMoney($a),
            'defaultTaxRate' => TaxService::defaultRate($this->db),
        ];

        // Lazy-load: solo datasets de la pestaña activa (+ mínimos para modales).
        if ($tab === 'productos' || $tab === '') {
            $viewData['products'] = $this->consignment->listProducts(
                $consignorFilter ?: null,
                $q,
                $supplierFilter ?: null,
                $showInactive
            );
        } elseif ($tab === 'ingresos') {
            $viewData['receipts'] = $this->consignment->listReceipts(40);
            $viewData['receiveProducts'] = $this->consignment->listProducts(null, '');
        } elseif ($tab === 'liquidaciones') {
            $viewData['openDebts'] = $this->consignment->listOpenDebts($consignorFilter ?: null);
        } elseif ($tab === 'historial') {
            $viewData['receipts'] = $this->consignment->listReceipts(40);
            $viewData['settlements'] = $this->consignment->listSettlements(40);
        } elseif ($tab === 'consignantes') {
            // consignors ya cargados
        } else {
            $viewData['products'] = $this->consignment->listProducts(
                $consignorFilter ?: null,
                $q,
                $supplierFilter ?: null,
                $showInactive
            );
            $viewData['receiveProducts'] = $this->consignment->listProducts(null, '');
            $viewData['openDebts'] = $this->consignment->listOpenDebts($consignorFilter ?: null);
            $viewData['receipts'] = $this->consignment->listReceipts(40);
            $viewData['settlements'] = $this->consignment->listSettlements(40);
        }

        $this->view('tenant.consignacion', $this->tenantViewData($viewData));
    }

    private function saveConsignor(): void
    {
        TenantMiddleware::authorize('consignacion', 'create');
        if (!$this->validateCsrfOrFail('/app/consignacion?tab=consignantes')) {
            return;
        }
        try {
            $id = (int)$this->request->post('id', 0);
            if ($id > 0) {
                TenantMiddleware::authorize('consignacion', 'edit');
            }
            $newId = $this->consignment->saveConsignor([
                'supplier_id' => (int)$this->request->post('supplier_id', 0),
                'name' => $this->request->post('name', ''),
                'document_type' => $this->request->post('document_type', ''),
                'document_number' => $this->request->post('document_number', ''),
                'phone' => $this->request->post('phone', ''),
                'email' => $this->request->post('email', ''),
                'address' => $this->request->post('address', ''),
                'notes' => $this->request->post('notes', ''),
                'status' => $this->request->post('status', 'active'),
            ], $id > 0 ? $id : null);
            $this->respond(true, $id > 0 ? 'Consignante actualizado' : 'Consignante creado', '/app/consignacion?tab=consignantes');
            unset($newId);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion?tab=consignantes');
        }
    }

    private function createProduct(): void
    {
        TenantMiddleware::authorize('consignacion', 'create');
        if (!$this->validateCsrfOrFail('/app/consignacion')) {
            return;
        }
        try {
            $userId = (int)($_SESSION['tenant_user_id'] ?? 0);
            $this->consignment->createProduct([
                'supplier_id' => (int)$this->request->post('supplier_id', 0),
                'consignor_id' => (int)$this->request->post('consignor_id', 0),
                'code' => $this->request->post('code', ''),
                'barcode' => $this->request->post('barcode', ''),
                'name' => $this->request->post('name', ''),
                'description' => $this->request->post('description', ''),
                'purchase_price' => $this->request->post('unit_cost', 0),
                'sale_price' => $this->request->post('sale_price', 0),
                'tax_rate' => $this->request->post('tax_rate', null),
                'initial_qty' => (int)$this->request->post('initial_qty', 0),
                'notes' => $this->request->post('notes', ''),
                'user_id' => $userId,
            ]);
            $this->respond(true, 'Producto consignado registrado', '/app/consignacion');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion');
        }
    }

    private function editProduct(): void
    {
        TenantMiddleware::authorize('consignacion', 'edit');
        if (!$this->validateCsrfOrFail('/app/consignacion')) {
            return;
        }
        try {
            $this->consignment->updateProduct((int)$this->request->post('id', 0), [
                'name' => $this->request->post('name', ''),
                'barcode' => $this->request->post('barcode', ''),
                'sale_price' => $this->request->post('sale_price', 0),
                'purchase_price' => $this->request->post('unit_cost', 0),
                'tax_rate' => $this->request->post('tax_rate', null),
                'notes' => $this->request->post('notes', ''),
                'status' => $this->request->post('status', 'active'),
                'stock' => $this->request->post('stock'),
                'user_id' => (int)($_SESSION['tenant_user_id'] ?? 0),
            ]);
            $this->respond(true, 'Producto actualizado', '/app/consignacion');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion');
        }
    }

    private function receive(): void
    {
        TenantMiddleware::authorize('consignacion', 'create');
        if (!$this->validateCsrfOrFail('/app/consignacion?tab=ingresos')) {
            return;
        }
        try {
            $productId = (int)$this->request->post('product_id', 0);
            $product = $this->consignment->getProduct($productId);
            if (!$product) {
                throw new \InvalidArgumentException('Producto no encontrado');
            }
            $this->consignment->receiveStock([
                'consignor_id' => (int)$product['consignor_id'],
                'receipt_date' => (string)$this->request->post('receipt_date', date('Y-m-d')),
                'notes' => (string)$this->request->post('notes', ''),
                'items' => [[
                    'product_id' => $productId,
                    'quantity' => (int)$this->request->post('quantity', 0),
                    'unit_cost' => (float)$this->request->post('unit_cost', $product['purchase_price'] ?? 0),
                ]],
            ], (int)($_SESSION['tenant_user_id'] ?? 0));
            $this->respond(true, 'Ingreso de consignación registrado (sin compra ni inventario propio)', '/app/consignacion?tab=ingresos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion?tab=ingresos');
        }
    }

    private function returnStock(): void
    {
        TenantMiddleware::authorize('consignacion', 'edit');
        if (!$this->validateCsrfOrFail('/app/consignacion')) {
            return;
        }
        try {
            $this->consignment->returnToConsignor(
                (int)$this->request->post('product_id', 0),
                (int)$this->request->post('quantity', 0),
                (string)$this->request->post('notes', ''),
                (int)($_SESSION['tenant_user_id'] ?? 0)
            );
            $this->respond(true, 'Devolución al consignante registrada', '/app/consignacion');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion');
        }
    }

    private function deleteProduct(): void
    {
        TenantMiddleware::authorize('consignacion', 'edit');
        if (!$this->validateCsrfOrFail('/app/consignacion')) {
            return;
        }
        try {
            $this->consignment->deleteProduct((int)$this->request->post('id', 0));
            $this->respond(true, 'Producto consignado eliminado del catálogo', '/app/consignacion');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion');
        }
    }

    private function settle(): void
    {
        TenantMiddleware::authorize('consignacion', 'edit');
        if (!$this->validateCsrfOrFail('/app/consignacion?tab=liquidaciones')) {
            return;
        }
        try {
            // Precalentar AccountingService fuera de la TX de liquidación (DDL = COMMIT implícito).
            new \SoftNova\Services\AccountingService($this->db);
            $consignorId = (int)$this->request->post('consignor_id', 0);
            $debtIds = $this->request->post('debt_ids', []);
            $qtys = $this->request->post('settle_qty', []);
            if (!is_array($debtIds)) {
                $debtIds = [];
            }
            $items = [];
            foreach ($debtIds as $debtId) {
                $debtId = (int)$debtId;
                $qty = (int)($qtys[$debtId] ?? 0);
                if ($debtId > 0 && $qty > 0) {
                    $items[] = ['debt_id' => $debtId, 'qty' => $qty];
                }
            }
            $result = $this->consignment->settle(
                $consignorId,
                $items,
                (string)$this->request->post('payment_method', 'cash'),
                (string)$this->request->post('notes', ''),
                (int)($_SESSION['tenant_user_id'] ?? 0),
                (string)$this->request->post('settlement_date', date('Y-m-d'))
            );
            $this->respond(
                true,
                'Liquidación #' . $result['settlement_id'] . ' por ' . $this->formatMoney($result['amount']),
                '/app/consignacion?tab=liquidaciones'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/consignacion?tab=liquidaciones');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function loadActiveSuppliers(): array
    {
        return $this->query(
            "SELECT id, name, document_type, document_number, phone, email, address, contact_name
             FROM suppliers
             WHERE status = 'active'
             ORDER BY name ASC
             LIMIT 500"
        )->fetchAll() ?: [];
    }
}
