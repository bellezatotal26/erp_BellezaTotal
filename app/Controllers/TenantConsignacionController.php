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

        if ($action === 'export') {
            $this->export();
            return;
        }

        $tab = (string)$this->request->get('tab', 'productos');
        $consignorFilter = (int)$this->request->get('consignor_id', 0);
        $supplierFilter = (int)$this->request->get('supplier_id', 0);
        $productFilters = $this->productFilters();
        $showInactive = $productFilters['status'] !== 'active';
        $q = $productFilters['q'];

        $consignors = $this->consignment->listConsignors(false);
        $suppliers = $this->loadActiveSuppliers();
        $viewData = [
            'tab' => $tab,
            'stats' => $this->consignment->dashboardStats(),
            'consignors' => $consignors,
            'suppliers' => $suppliers,
            'products' => [],
            'productGroups' => [],
            'productFilters' => $productFilters,
            'groupByConsignor' => (int)$this->request->get('group', 0) === 1,
            'receiveProducts' => [],
            'openDebts' => [],
            'receipts' => [],
            'settlements' => [],
            'margin' => null,
            'marginFilters' => $this->marginFilters(),
            'trace' => null,
            'traceFilters' => $this->traceFilters(),
            'traceProducts' => [],
            'consignorFilter' => $consignorFilter,
            'supplierFilter' => $supplierFilter,
            'showInactive' => $showInactive,
            'q' => $q,
            'paymentMethods' => PaymentMethodCatalog::all(),
            'canCreate' => TenantMiddleware::canDo('create', 'consignacion'),
            'canEdit' => TenantMiddleware::canDo('edit', 'consignacion'),
            'canDelete' => TenantMiddleware::canDo('delete', 'consignacion'),
            'canExport' => TenantMiddleware::canDo('export', 'consignacion'),
            'fmt' => fn($a) => $this->formatMoney($a),
            'defaultTaxRate' => TaxService::defaultRate($this->db),
        ];

        // Lazy-load: solo datasets de la pestaña activa (+ mínimos para modales).
        if ($tab === 'productos' || $tab === '') {
            $viewData['products'] = $this->consignment->listProductsDetailed($productFilters);
            if ($viewData['groupByConsignor']) {
                $viewData['productGroups'] = $this->consignment->groupByConsignor($viewData['products']);
            }
        } elseif ($tab === 'margen') {
            $mf = $viewData['marginFilters'];
            $viewData['margin'] = $this->consignment->marginReport($mf['from'], $mf['to'], $mf['consignor_id'], $mf['q']);
        } elseif ($tab === 'trazabilidad') {
            $viewData['trace'] = $this->consignment->traceability($viewData['traceFilters'], 1000);
            $viewData['traceProducts'] = $this->consignment->listProducts(null, '', null, true);
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
            $viewData['products'] = $this->consignment->listProductsDetailed($productFilters);
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

    /** @return array{consignor_id:int,supplier_id:int,q:string,status:string,stock_state:string,sort:string} */
    private function productFilters(): array
    {
        $status = $this->request->get('status');
        if ($status === null) {
            $status = (int)$this->request->get('show_inactive', 0) === 1 ? 'all' : 'active';
        }
        return ConsignmentService::normalizeProductFilters([
            'consignor_id' => $this->request->get('consignor_id', 0),
            'supplier_id' => $this->request->get('supplier_id', 0),
            'q' => $this->request->get('q', ''),
            'status' => $status,
            'stock_state' => $this->request->get('stock_state', ''),
            'sort' => $this->request->get('sort', 'name'),
        ]);
    }

    /** @return array{from:string,to:string,consignor_id:int,q:string,group:string} */
    private function marginFilters(): array
    {
        $from = $this->validDate((string)$this->request->get('from', '')) ?: date('Y-m-01');
        $to = $this->validDate((string)$this->request->get('to', '')) ?: date('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $group = (string)$this->request->get('group', 'product') === 'consignor' ? 'consignor' : 'product';
        return [
            'from' => $from,
            'to' => $to,
            'consignor_id' => max(0, (int)$this->request->get('consignor_id', 0)),
            'q' => trim((string)$this->request->get('q', '')),
            'group' => $group,
        ];
    }

    /** @return array{from:string,to:string,consignor_id:int,product_id:int,type:string,q:string} */
    private function traceFilters(): array
    {
        $rawFrom = $this->request->get('from');
        $rawTo = $this->request->get('to');
        $from = $rawFrom === null ? date('Y-m-d', strtotime('-30 days')) : $this->validDate((string)$rawFrom);
        $to = $rawTo === null ? '' : $this->validDate((string)$rawTo);
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $type = (string)$this->request->get('mtype', '');
        if (!isset(ConsignmentService::TRACE_TYPE_LABELS[$type])) {
            $type = '';
        }
        return [
            'from' => $from,
            'to' => $to,
            'consignor_id' => max(0, (int)$this->request->get('consignor_id', 0)),
            'product_id' => max(0, (int)$this->request->get('product_id', 0)),
            'type' => $type,
            'q' => trim((string)$this->request->get('q', '')),
        ];
    }

    private function validDate(string $raw): string
    {
        $raw = trim($raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : '';
    }

    private function consignorName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $c = $this->consignment->findConsignor($id);
        return $c ? (string)$c['name'] : '#' . $id;
    }

    private function prepareExportResponse(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(300);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private function exportError(string $message, \Throwable $e, int $status = 500): void
    {
        error_log($message . ': ' . $e->getMessage());
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $status === 403 ? $e->getMessage() : $message . '. ' . $this->clientErrorMessage($e, 'Intente de nuevo.');
        exit;
    }

    private static function pct(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 1, ',', '.') . '%';
    }

    /**
     * GET ?action=export&type=productos|margen|trazabilidad&format=csv|pdf (respeta los filtros de la pestaña).
     */
    private function export(): void
    {
        $type = (string)$this->request->get('type', 'productos');
        $format = (string)$this->request->get('format', 'csv') === 'pdf' ? 'pdf' : 'csv';
        if (!TenantMiddleware::canDo('export', 'consignacion')) {
            $this->exportError('Sin permiso', new \RuntimeException('No tiene permiso para exportar en Consignación.'), 403);
            return;
        }
        $this->prepareExportResponse();
        try {
            match ($type) {
                'margen' => $this->exportMargin($format),
                'trazabilidad' => $this->exportTrace($format),
                default => $this->exportProducts($format),
            };
        } catch (\Throwable $e) {
            $this->exportError('No se pudo exportar consignación', $e);
        }
    }

    private function exportProducts(string $format): void
    {
        $f = $this->productFilters();
        $grouped = (int)$this->request->get('group', 0) === 1;
        $products = $this->consignment->listProductsDetailed($f, 5000);

        $filterText = [];
        if ($f['supplier_id'] > 0) {
            foreach ($this->loadActiveSuppliers() as $s) {
                if ((int)$s['id'] === $f['supplier_id']) {
                    $filterText[] = 'Proveedor: ' . $s['name'];
                }
            }
        } elseif ($f['consignor_id'] > 0) {
            $filterText[] = 'Consignante: ' . $this->consignorName($f['consignor_id']);
        }
        if ($f['q'] !== '') {
            $filterText[] = 'Búsqueda: ' . $f['q'];
        }
        $filterText[] = 'Estado: ' . ($f['status'] === 'all' ? 'Todos' : ConsignmentService::STATUS_LABELS[$f['status']]);
        if ($f['stock_state'] !== '') {
            $filterText[] = ConsignmentService::STOCK_STATE_LABELS[$f['stock_state']];
        }

        if ($format === 'csv') {
            $rows = [];
            foreach ($products as $p) {
                $sku = (string)($p['code'] ?? '');
                if ($sku !== '' && preg_match('/^\d{11,}$/', $sku)) {
                    $sku = "\t" . $sku;
                }
                $ean = (string)($p['barcode'] ?? '');
                if ($ean !== '' && preg_match('/^\d{11,}$/', $ean)) {
                    $ean = "\t" . $ean;
                }
                $rows[] = [
                    $sku,
                    $ean,
                    $p['name'],
                    $p['consignor_name'] ?? '',
                    ConsignmentService::STATUS_LABELS[$p['status']] ?? $p['status'],
                    (int)$p['stock'],
                    (float)$p['purchase_price'],
                    (float)$p['sale_price'],
                    $p['unit_margin'],
                    $p['unit_margin_pct'] ?? '',
                    $p['stock_value_cost'],
                    (int)$p['units_sold'],
                    $p['revenue'],
                    $p['cost_sold'],
                    $p['profit'],
                    $p['margin_pct'] ?? '',
                    (int)$p['units_pending'],
                    $p['amount_pending'],
                    $p['last_sale_date'] ? substr((string)$p['last_sale_date'], 0, 10) : '',
                ];
            }
            $this->exportCsv(
                'consignacion_productos_' . date('Ymd_His') . '.csv',
                ['SKU', 'EAN', 'Producto', 'Consignante', 'Estado', 'Stock', 'Costo_pactado', 'Precio_venta',
                    'Margen_unitario', 'Margen_unitario_%', 'Valor_stock_costo', 'Unidades_vendidas', 'Ingreso_ventas',
                    'Costo_vendido', 'Utilidad', 'Margen_%', 'Und_por_liquidar', 'Valor_por_liquidar', 'Ultima_venta'],
                $rows
            );
            return;
        }

        $money = fn($v) => $this->formatMoney((float)$v);
        $productRow = fn(array $p) => ['cells' => [
            (string)$p['code'],
            (string)$p['name'] . (($p['status'] ?? '') === 'inactive' ? ' (inactivo)' : ''),
            (string)($p['consignor_name'] ?? ''),
            (string)(int)$p['stock'],
            $money($p['purchase_price']),
            $money($p['sale_price']),
            self::pct($p['unit_margin_pct']),
            (string)(int)$p['units_sold'],
            $money($p['revenue']),
            $money($p['profit']),
            self::pct($p['margin_pct']),
            $money($p['amount_pending']),
        ]];
        $totalsRow = function (string $label, array $t) use ($money) {
            return ['style' => 'total', 'cells' => [
                $label, '', '', (string)(int)$t['stock'], '', '', '',
                (string)(int)$t['units_sold'], $money($t['revenue']), $money($t['profit']),
                self::pct($t['margin_pct']), $money($t['amount_pending']),
            ]];
        };

        $rows = [];
        $groups = $this->consignment->groupByConsignor($products);
        if ($grouped) {
            foreach ($groups as $g) {
                $rows[] = ['style' => 'group', 'cells' => [$g['consignor_name'] . ' · ' . $g['product_count'] . ' producto(s)']];
                foreach ($g['products'] as $p) {
                    $rows[] = $productRow($p);
                }
                $rows[] = $totalsRow('Subtotal ' . $g['consignor_name'], $g);
            }
        } else {
            foreach ($products as $p) {
                $rows[] = $productRow($p);
            }
        }
        $all = ['stock' => 0, 'units_sold' => 0, 'revenue' => 0.0, 'cost_sold' => 0.0, 'amount_pending' => 0.0];
        foreach ($groups as $g) {
            foreach ($all as $k => $_) {
                $all[$k] += $g[$k];
            }
        }
        $all['profit'] = $all['revenue'] - $all['cost_sold'];
        $all['margin_pct'] = $all['revenue'] > 0.009 ? round($all['profit'] / $all['revenue'] * 100, 1) : null;
        $rows[] = $totalsRow('Total general', $all);

        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generateConsignmentReport(
            'PRODUCTOS EN CONSIGNACIÓN',
            implode(' · ', $filterText) . ' · ' . count($products) . ' producto(s)',
            [
                ['label' => 'SKU'], ['label' => 'Producto'], ['label' => 'Consignante'],
                ['label' => 'Stock', 'align' => 'right'], ['label' => 'Costo pactado', 'align' => 'right'],
                ['label' => 'Precio venta', 'align' => 'right'], ['label' => 'Margen unit.', 'align' => 'right'],
                ['label' => 'Vendidas', 'align' => 'right'], ['label' => 'Ingreso', 'align' => 'right'],
                ['label' => 'Utilidad', 'align' => 'right'], ['label' => 'Margen %', 'align' => 'right'],
                ['label' => 'Por liquidar', 'align' => 'right'],
            ],
            $rows,
            'Ingreso con IVA y neto de descuentos (mismo criterio de Ventas). Utilidad = ingreso − costo pactado con el consignante.'
        );
        $pdf->download($content, 'consignacion_productos_' . date('Ymd_His') . '.pdf');
    }

    private function exportMargin(string $format): void
    {
        $f = $this->marginFilters();
        $report = $this->consignment->marginReport($f['from'], $f['to'], $f['consignor_id'], $f['q']);
        $period = date('d/m/Y', strtotime($f['from'])) . ' al ' . date('d/m/Y', strtotime($f['to']));
        $filterText = ['Periodo: ' . $period];
        if ($f['consignor_id'] > 0) {
            $filterText[] = 'Consignante: ' . $this->consignorName($f['consignor_id']);
        }
        if ($f['q'] !== '') {
            $filterText[] = 'Búsqueda: ' . $f['q'];
        }
        $byConsignor = $f['group'] === 'consignor';

        if ($format === 'csv') {
            $rows = [];
            if ($byConsignor) {
                foreach ($report['by_consignor'] as $g) {
                    $rows[] = [$g['consignor_name'], (int)$g['product_count'], (int)$g['units_sold'], $g['revenue'],
                        $g['cost_sold'], $g['profit'], $g['margin_pct'] ?? '', $g['amount_pending']];
                }
                $headers = ['Consignante', 'Productos', 'Unidades', 'Ingreso', 'Costo_pactado', 'Utilidad', 'Margen_%', 'Por_liquidar'];
            } else {
                foreach ($report['rows'] as $r) {
                    $rows[] = [$r['product_code'], $r['product_name'], $r['consignor_name'], (int)$r['sales_count'],
                        (int)$r['units_sold'], $r['revenue'], $r['cost_sold'], $r['profit'], $r['margin_pct'] ?? '', $r['amount_pending']];
                }
                $headers = ['SKU', 'Producto', 'Consignante', 'Ventas', 'Unidades', 'Ingreso', 'Costo_pactado', 'Utilidad', 'Margen_%', 'Por_liquidar'];
            }
            $this->exportCsv('consignacion_margen_' . $f['from'] . '_' . $f['to'] . '.csv', $headers, $rows);
            return;
        }

        $money = fn($v) => $this->formatMoney((float)$v);
        $t = $report['totals'];
        $rows = [];
        if ($byConsignor) {
            $headers = [
                ['label' => 'Consignante'], ['label' => 'Productos', 'align' => 'right'], ['label' => 'Unidades', 'align' => 'right'],
                ['label' => 'Ingreso', 'align' => 'right'], ['label' => 'Costo pactado', 'align' => 'right'],
                ['label' => 'Utilidad', 'align' => 'right'], ['label' => 'Margen %', 'align' => 'right'],
                ['label' => 'Por liquidar', 'align' => 'right'],
            ];
            foreach ($report['by_consignor'] as $g) {
                $rows[] = ['cells' => [$g['consignor_name'], (string)$g['product_count'], (string)$g['units_sold'],
                    $money($g['revenue']), $money($g['cost_sold']), $money($g['profit']), self::pct($g['margin_pct']), $money($g['amount_pending'])]];
            }
            $rows[] = ['style' => 'total', 'cells' => ['Total', '', (string)$t['units_sold'], $money($t['revenue']),
                $money($t['cost_sold']), $money($t['profit']), self::pct($t['margin_pct']), $money($t['amount_pending'])]];
        } else {
            $headers = [
                ['label' => 'SKU'], ['label' => 'Producto'], ['label' => 'Consignante'],
                ['label' => 'Ventas', 'align' => 'right'], ['label' => 'Unidades', 'align' => 'right'],
                ['label' => 'Ingreso', 'align' => 'right'], ['label' => 'Costo pactado', 'align' => 'right'],
                ['label' => 'Utilidad', 'align' => 'right'], ['label' => 'Margen %', 'align' => 'right'],
                ['label' => 'Por liquidar', 'align' => 'right'],
            ];
            foreach ($report['rows'] as $r) {
                $rows[] = ['cells' => [$r['product_code'], $r['product_name'], (string)$r['consignor_name'],
                    (string)$r['sales_count'], (string)$r['units_sold'], $money($r['revenue']), $money($r['cost_sold']),
                    $money($r['profit']), self::pct($r['margin_pct']), $money($r['amount_pending'])]];
            }
            $rows[] = ['style' => 'total', 'cells' => ['Total', '', '', (string)$t['sales_count'], (string)$t['units_sold'],
                $money($t['revenue']), $money($t['cost_sold']), $money($t['profit']), self::pct($t['margin_pct']), $money($t['amount_pending'])]];
        }

        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generateConsignmentReport(
            'MARGEN DE VENTA · CONSIGNACIÓN',
            implode(' · ', $filterText),
            $headers,
            $rows,
            'Ingreso con IVA y neto de descuentos (mismo criterio de Ventas). Excluye ventas anuladas y unidades devueltas por nota crédito.'
        );
        $pdf->download($content, 'consignacion_margen_' . $f['from'] . '_' . $f['to'] . '.pdf');
    }

    private function exportTrace(string $format): void
    {
        $f = $this->traceFilters();
        $result = $this->consignment->traceability($f, 5000);
        $filterText = [];
        if ($f['from'] !== '' || $f['to'] !== '') {
            $filterText[] = 'Periodo: ' . ($f['from'] !== '' ? date('d/m/Y', strtotime($f['from'])) : 'inicio')
                . ' al ' . ($f['to'] !== '' ? date('d/m/Y', strtotime($f['to'])) : 'hoy');
        }
        if ($f['consignor_id'] > 0) {
            $filterText[] = 'Consignante: ' . $this->consignorName($f['consignor_id']);
        }
        if ($f['product_id'] > 0) {
            $p = $this->consignment->getProduct($f['product_id']);
            $filterText[] = 'Producto: ' . ($p ? $p['name'] : '#' . $f['product_id']);
        }
        if ($f['type'] !== '') {
            $filterText[] = 'Tipo: ' . ConsignmentService::TRACE_TYPE_LABELS[$f['type']];
        }
        if ($f['q'] !== '') {
            $filterText[] = 'Búsqueda: ' . $f['q'];
        }

        if ($format === 'csv') {
            $rows = [];
            foreach ($result['rows'] as $r) {
                $rows[] = [$r['event_date'], $r['kind_label'], $r['product_code'], $r['product_name'], $r['consignor_name'],
                    $r['document'], $r['qty'], $r['unit_cost'], $r['amount'], $r['stock_after'] ?? '', $r['user_name'], $r['notes']];
            }
            $this->exportCsv(
                'consignacion_trazabilidad_' . date('Ymd_His') . '.csv',
                ['Fecha', 'Tipo', 'SKU', 'Producto', 'Consignante', 'Documento', 'Cantidad', 'Costo_und', 'Valor', 'Stock_despues', 'Usuario', 'Notas'],
                $rows
            );
            return;
        }

        $money = fn($v) => $this->formatMoney((float)$v);
        $rows = [];
        foreach ($result['rows'] as $r) {
            $rows[] = ['cells' => [
                date('d/m/Y', strtotime($r['event_date'])),
                $r['kind_label'],
                trim($r['product_code'] . ' ' . $r['product_name']),
                $r['consignor_name'],
                $r['document'],
                ($r['qty'] > 0 && $r['kind'] !== 'settlement' ? '+' : '') . $r['qty'],
                $money($r['unit_cost']),
                $money($r['amount']),
                $r['stock_after'] !== null ? (string)$r['stock_after'] : '—',
                $r['user_name'],
            ]];
        }
        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generateConsignmentReport(
            'TRAZABILIDAD · CONSIGNACIÓN',
            ($filterText ? implode(' · ', $filterText) . ' · ' : '') . count($result['rows']) . ' evento(s)'
                . ($result['truncated'] ? ' (limitado a los más recientes)' : ''),
            [
                ['label' => 'Fecha'], ['label' => 'Tipo'], ['label' => 'Producto'], ['label' => 'Consignante'],
                ['label' => 'Documento'], ['label' => 'Cant.', 'align' => 'right'], ['label' => 'Costo und.', 'align' => 'right'],
                ['label' => 'Valor', 'align' => 'right'], ['label' => 'Stock', 'align' => 'right'], ['label' => 'Usuario'],
            ],
            $rows,
            'Valor = cantidad × costo pactado. En liquidaciones, la cantidad son las unidades pagadas al consignante.'
        );
        $pdf->download($content, 'consignacion_trazabilidad_' . date('Ymd_His') . '.pdf');
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
