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
use SoftNova\Services\SalePaymentSplitter;
use SoftNova\Services\SalesDocumentService;
use SoftNova\Services\SupplyChainSchema;
use SoftNova\Services\Integrations\IntegrationManager;
use function SoftNova\Core\base_url;

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
        try {
            \SoftNova\Services\OpenItemProduct::ensure($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
        
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
        if ($action === 'update_payment_method' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('ventas');
            if (!TenantMiddleware::canDo('edit', 'ventas') && !TenantMiddleware::canDo('create', 'ventas')) {
                $this->respond(false, 'No tiene permiso para editar el medio de pago', '/app/ventas');
                return;
            }
            $this->updatePaymentMethod();
            return;
        }
        if ($action === 'payment_splits' && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('ventas');
            $this->paymentSplitsJson();
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
        if (($action === 'summary_csv' || $action === 'summary_pdf') && $this->request->method() === 'GET') {
            TenantMiddleware::authorize('ventas', 'export');
            $this->exportSalesSummary($action === 'summary_pdf' ? 'pdf' : 'csv');
            return;
        }
        if ($action === 'credit_note' && $this->request->method() === 'POST') {
            // H4: emitir NC exige el mismo nivel que cancelar (delete), no solo create.
            TenantMiddleware::authorize('ventas', 'delete');
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
        
        try {
            (new \SoftNova\Services\SalesCreditNoteService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
        }
        try {
            $sales = $this->query(
                "SELECT s.*, c.name as customer_name, u.name as user_name,
                        COALESCE(p.paid_amount, 0) as paid_amount,
                        COALESCE(cn.credit_note_total, 0) AS credit_note_total,
                        COALESCE(cn.credit_notes_count, 0) AS credit_notes_count,
                        cn.credit_note_numbers
                 FROM sales s
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON s.user_id = u.id
                 LEFT JOIN (
                    SELECT sale_id, COALESCE(SUM(amount), 0) as paid_amount
                    FROM sale_payments
                    GROUP BY sale_id
                 ) p ON p.sale_id = s.id
                 LEFT JOIN (
                    SELECT sale_id,
                           SUM(total) AS credit_note_total,
                           COUNT(*) AS credit_notes_count,
                           GROUP_CONCAT(credit_number ORDER BY id SEPARATOR ', ') AS credit_note_numbers
                    FROM sale_credit_notes
                    WHERE status = 'posted'
                    GROUP BY sale_id
                 ) cn ON cn.sale_id = s.id
                 WHERE {$whereSql}
                 ORDER BY {$filters['orderSql']}, s.id DESC
                 LIMIT {$pagination['perPage']} OFFSET {$pagination['offset']}",
                $params
            )->fetchAll();
        } catch (\Throwable $e) {
            $sales = $this->query(
                "SELECT s.*, c.name as customer_name, u.name as user_name,
                        COALESCE(p.paid_amount, 0) as paid_amount,
                        0 AS credit_note_total,
                        0 AS credit_notes_count,
                        NULL AS credit_note_numbers
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
        }
        
        [$sumFrom, $sumTo] = $this->salesSummaryRange();
        $salesSummary = \SoftNova\Services\SalesPeriodSummary::build($this->db, $sumFrom, $sumTo);
        $todayTotal = (float)$salesSummary['total'];
        $todayCount = (int)$salesSummary['count'];
        
        try {
            $products = $this->query(
                "SELECT id, name, code, sale_price, stock,
                        COALESCE(product_type,'product') AS product_type,
                        COALESCE(ownership,'owned') AS ownership,
                        consignor_id
                 FROM products
                 WHERE status = 'active'
                 ORDER BY name"
            )->fetchAll();
        } catch (\Throwable $e) {
            $products = $this->query(
                "SELECT id, name, code, sale_price, stock,
                        COALESCE(product_type,'product') AS product_type
                 FROM products
                 WHERE status = 'active'
                 ORDER BY name"
            )->fetchAll();
        }
        
        $customers = $this->query(
            "SELECT id, name, first_name, last_name, document_number, document_type
             FROM customers WHERE status = 'active' ORDER BY name"
        )->fetchAll();
        
        $this->view('tenant.ventas', $this->tenantViewData([
            'sales' => $sales,
            'todayTotal' => $todayTotal,
            'todayCount' => $todayCount,
            'salesSummary' => $salesSummary,
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
            'sum_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$this->request->get('sum_from', '')) ? (string)$this->request->get('sum_from') : null,
            'sum_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$this->request->get('sum_to', '')) ? (string)$this->request->get('sum_to') : null,
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

        // Completar billete/vueltas desde notas si la venta es anterior a las columnas.
        $cashReceived = isset($sale['cash_received']) ? (float)$sale['cash_received'] : 0.0;
        $cashChange = isset($sale['cash_change']) ? (float)$sale['cash_change'] : 0.0;
        $notes = (string)($sale['notes'] ?? '');
        if ($cashReceived <= 0.009 && preg_match('/Recibido(?:\s+efectivo)?:\s*([\d.,]+)/i', $notes, $m)) {
            $cashReceived = \SoftNova\Core\parse_money($m[1]);
            $sale['cash_received'] = $cashReceived;
        }
        if ($cashChange <= 0.009 && preg_match('/(?:Cambio|Vueltas):\s*([\d.,]+)/i', $notes, $m)) {
            $cashChange = \SoftNova\Core\parse_money($m[1]);
            $sale['cash_change'] = $cashChange;
        }
        if ($cashReceived > 0.009) {
            $cashChange = \SoftNova\Core\cash_change_amount($cashReceived, (float)($sale['total'] ?? 0), $cashChange);
            $sale['cash_change'] = $cashChange;
        }
        
        $items = [];
        try {
            $items = $this->query(
                "SELECT si.*,
                        COALESCE((
                            SELECT SUM(ci.quantity)
                            FROM sale_credit_note_items ci
                            INNER JOIN sale_credit_notes cn ON cn.id = ci.credit_note_id
                            WHERE ci.sale_item_id = si.id AND cn.status = 'posted'
                        ), 0) AS credited_qty
                 FROM sale_items si
                 WHERE si.sale_id = ?
                   AND COALESCE(si.document_hidden, 0) = 0
                 ORDER BY si.id",
                [$id]
            )->fetchAll();
        } catch (\Throwable $e) {
            try {
                $items = $this->query(
                    "SELECT si.*,
                            COALESCE((
                                SELECT SUM(ci.quantity)
                                FROM sale_credit_note_items ci
                                INNER JOIN sale_credit_notes cn ON cn.id = ci.credit_note_id
                                WHERE ci.sale_item_id = si.id AND cn.status = 'posted'
                            ), 0) AS credited_qty
                     FROM sale_items si
                     WHERE si.sale_id = ?
                     ORDER BY si.id",
                    [$id]
                )->fetchAll();
            } catch (\Throwable $e2) {
                $items = $this->query("SELECT * FROM sale_items WHERE sale_id = ?", [$id])->fetchAll();
            }
        }
        $payments = $this->query(
            "SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY payment_date DESC",
            [$id]
        )->fetchAll();

        $creditNotes = [];
        try {
            $creditNotes = (new \SoftNova\Services\SalesCreditNoteService($this->db))
                ->getSaleForCredit($id)['credit_notes'] ?? [];
        } catch (\Throwable $e) {
            $creditNotes = [];
        }

        $paymentChanges = [];
        try {
            $withChanges = \SoftNova\Services\CashClosingReportBuilder::attachSaleAdjustments($this->db, [$sale]);
            $paymentChanges = $withChanges[0]['payment_changes'] ?? [];
            foreach ($paymentChanges as &$ch) {
                $ch['to'] = $this->paymentChangeLabel((string)($ch['to'] ?? ''));
                $ch['from'] = $this->paymentChangeLabel((string)($ch['from'] ?? ''));
            }
            unset($ch);
        } catch (\Throwable $e) {
            $paymentChanges = [];
        }

        $this->json([
            'sale' => $sale,
            'items' => $items,
            'payments' => $payments,
            'credit_notes' => $creditNotes,
            'payment_changes' => $paymentChanges,
            'profit' => $this->saleProfitSummary($sale),
        ]);
    }

    /** "Mixto: Efectivo 1000.00 + Transferencia 500.00" → montos en formato moneda. */
    private function paymentChangeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        return (string)preg_replace_callback(
            '/(?<![\d.,])(\d+)\.(\d{2})(?![\d.,])/',
            static fn(array $m) => '$ ' . number_format((float)($m[1] . '.' . $m[2]), 2, ',', '.'),
            $label
        );
    }

    /**
     * Utilidad bruta de la venta: lo cobrado por productos (total sin domicilio al cliente)
     * menos el costo de lo vendido. Solo para quien ve reportes o contabilidad.
     *
     * @return array{revenue:float,cost:float,profit:float,margin_pct:float,items_without_cost:int}|null
     */
    private function saleProfitSummary(array $sale): ?array
    {
        if (!TenantMiddleware::canAccess('reportes') && !TenantMiddleware::canAccess('contabilidad')) {
            return null;
        }
        $id = (int)($sale['id'] ?? 0);
        if ($id <= 0 || ($sale['status'] ?? '') === 'cancelled') {
            return null;
        }
        $costExpr = 'COALESCE(NULLIF(si.unit_cost, 0), p.purchase_price, 0)';
        $row = null;
        foreach ([
            // Las líneas ocultas de un combo no llevan costo propio: el costo va en la línea visible.
            "SELECT COALESCE(SUM(si.quantity * {$costExpr}), 0) AS cost,
                    SUM(CASE WHEN {$costExpr} <= 0 THEN 1 ELSE 0 END) AS without_cost
             FROM sale_items si
             LEFT JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = ? AND COALESCE(si.document_hidden, 0) = 0",
            "SELECT COALESCE(SUM(si.quantity * {$costExpr}), 0) AS cost,
                    SUM(CASE WHEN {$costExpr} <= 0 THEN 1 ELSE 0 END) AS without_cost
             FROM sale_items si
             LEFT JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = ?",
            "SELECT COALESCE(SUM(si.quantity * COALESCE(p.purchase_price, 0)), 0) AS cost,
                    SUM(CASE WHEN COALESCE(p.purchase_price, 0) <= 0 THEN 1 ELSE 0 END) AS without_cost
             FROM sale_items si
             LEFT JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = ?",
        ] as $sql) {
            try {
                $row = $this->query($sql, [$id])->fetch(\PDO::FETCH_ASSOC) ?: null;
                break;
            } catch (\Throwable $e) {
                $row = null;
            }
        }
        if ($row === null) {
            return null;
        }
        $cost = round((float)($row['cost'] ?? 0), 2);
        $revenue = round(max(0, (float)($sale['total'] ?? 0) - (float)($sale['delivery_fee'] ?? 0)), 2);
        $profit = round($revenue - $cost, 2);
        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin_pct' => $revenue > 0.009 ? round(($profit / $revenue) * 100, 1) : 0.0,
            'items_without_cost' => (int)($row['without_cost'] ?? 0),
        ];
    }

    /** Emite NC de venta (devolución parcial o total de productos). */
    private function creditNote(): void
    {
        if (!$this->validateCsrfOrFail('/app/ventas')) {
            return;
        }

        $saleId = (int)$this->request->post('id', 0);
        $reason = trim((string)$this->request->post('reason', ''));
        $reasonCode = trim((string)$this->request->post('reason_code', ''));
        $creditDate = trim((string)$this->request->post('credit_date', date('Y-m-d')));
        $affectsStock = $this->request->post('affects_stock') === '1';
        $refundMethod = (string)$this->request->post('refund_method', 'auto');

        $itemIds = (array)$this->request->post('item_id', []);
        $quantities = (array)$this->request->post('quantity', []);
        $lines = [];
        $count = max(count($itemIds), count($quantities));
        for ($i = 0; $i < $count; $i++) {
            $itemId = (int)($itemIds[$i] ?? 0);
            $qty = (int)($quantities[$i] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $lines[] = [
                'sale_item_id' => $itemId,
                'quantity' => $qty,
            ];
        }

        try {
            $svc = new \SoftNova\Services\SalesCreditNoteService($this->db);
            $result = $svc->create(
                $saleId,
                $lines,
                $reason,
                $reasonCode,
                $affectsStock,
                $creditDate !== '' ? $creditDate : date('Y-m-d'),
                $refundMethod
            );

            try {
                (new \SoftNova\Services\AccountingService($this->db))
                    ->postSaleCreditNote($result['credit_note_id']);
                $accountingOk = true;
            } catch (\Throwable $e) {
                $accountingOk = false;
                error_log('Contabilidad NC venta ' . $result['credit_note_id'] . ': ' . $e->getMessage());
                try {
                    $this->accounting->enqueuePending(
                        'post_sale_credit_note',
                        'sale_credit_note',
                        (int)$result['credit_note_id'],
                        $e->getMessage()
                    );
                } catch (\Throwable $ignore) {
                }
            }

            $cashNote = $svc->syncCash((int)$result['credit_note_id']);

            try {
                (new \SoftNova\Services\TenantAuditService($this->db))->log(
                    'create',
                    'ventas',
                    'NC ' . $result['credit_number'] . ' por ' . $this->formatMoney($result['total'])
                        . ' · venta #' . $saleId
                        . ($affectsStock ? ' · con reingreso stock' : ' · sin reingreso stock'),
                    'sale_credit_note',
                    (int)$result['credit_note_id']
                );
            } catch (\Throwable $e) {
                error_log('audit NC venta: ' . $e->getMessage());
            }

            $message = 'Nota de crédito ' . $result['credit_number']
                . ' por ' . $this->formatMoney($result['total']) . '.';
            // affects_stock puede haberse forzado a false (vencido/roto) en el servicio.
            $noteRow = $svc->getCreditNote((int)$result['credit_note_id']);
            $stockIn = (int)($noteRow['affects_stock'] ?? ($affectsStock ? 1 : 0)) === 1;
            if ($stockIn) {
                $message .= ' Producto(s) reingresados al inventario.';
            } else {
                $message .= ' Sin reingreso de inventario (p. ej. vencido/roto).';
            }
            if ($cashNote !== '') {
                $message .= ' ' . $cashNote;
            }
            if (!$accountingOk) {
                $message .= ' Asiento contable pendiente: use «Sincronizar» en Contabilidad.';
            }

            $pdfUrl = base_url('/app/ventas?action=credit_note_pdf&id=' . (int)$result['credit_note_id']);
            $this->respond(true, $message, '/app/ventas?action=notas_credito', [
                'pdf_url' => $pdfUrl,
                'credit_note_id' => (int)$result['credit_note_id'],
                'credit_number' => $result['credit_number'],
                'allocation' => $result['allocation'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/ventas');
        }
    }

    private function creditNotePdf(): void
    {
        $id = (int)$this->request->get('id', 0);
        $svc = new \SoftNova\Services\SalesCreditNoteService($this->db);
        $note = $svc->getCreditNote($id);
        if (!$note) {
            $this->respond(false, 'Nota de crédito no encontrada', '/app/ventas?action=notas_credito');
            return;
        }

        $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
        $content = $pdf->generateSaleCreditNote($note, $note['items'] ?? [], [
            'name' => $note['customer_name'] ?? '',
            'document_number' => $note['customer_document'] ?? '',
        ]);
        $filename = 'NC_venta_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($note['credit_number'] ?? $note['id'])) . '.pdf';
        $pdf->download($content, $filename);
    }

    private function creditNotesPanel(): void
    {
        $svc = new \SoftNova\Services\SalesCreditNoteService($this->db);
        $q = trim((string)$this->request->get('q', ''));
        $total = $svc->countCreditNotes($q);
        $pagination = $this->paginate($total);
        $notes = $svc->listCreditNotes($pagination['perPage'], $pagination['offset'], $q);

        $this->view('tenant.ventas', $this->tenantViewData([
            'creditNotesPanel' => true,
            'creditNotes' => $notes,
            'creditNotesQ' => $q,
            'pagination' => $pagination,
            'pageTitle' => 'Notas de crédito de venta',
            'sales' => [],
            'todayTotal' => 0,
            'todayCount' => 0,
            'products' => [],
            'customers' => [],
            'filters' => [
                'q' => '', 'from' => '', 'to' => '', 'status' => '', 'customer_id' => '',
                'payment_method' => '', 'record_status' => 'active', 'sort' => 'date', 'dir' => 'desc', 'query' => [],
            ],
            'cancelReasons' => [],
            'paymentMethodOptionsHtml' => '',
            'paymentMethodFilterHtml' => '',
        ]));
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
                'message' => 'Token de seguridad desfasado. Reintentando automáticamente…',
                'results' => [],
                'summary' => ['ok' => 0, 'conflict' => 0, 'retry' => 0, 'idempotent' => 0],
                'code' => 'csrf',
                'csrf_token' => \SoftNova\Core\Security::generateCsrfToken(),
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

    /**
     * Prepara esquemas (DDL) fuera de cualquier transacción de venta.
     * CREATE/ALTER en MySQL hacen COMMIT implícito y rompen beginTransaction/commit.
     */
    private function ensureSaleRuntimeSchema(): void
    {
        try {
            new SalesDocumentService($this->db);
        } catch (\Throwable $e) {
            error_log('ensureSaleRuntimeSchema SalesDocument: ' . $e->getMessage());
        }
        try {
            SupplyChainSchema::ensure($this->db);
        } catch (\Throwable $e) {
            error_log('ensureSaleRuntimeSchema SupplyChain: ' . $e->getMessage());
        }
        try {
            // Fuerza LotService (y su schema) antes de descontar stock.
            $this->sales->stock()->lots();
        } catch (\Throwable $e) {
            error_log('ensureSaleRuntimeSchema lots: ' . $e->getMessage());
        }
        try {
            // Consignación: CREATE/ALTER antes de TX de venta (MySQL DDL = COMMIT implícito).
            (new \SoftNova\Services\ConsignmentService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
            error_log('ensureSaleRuntimeSchema consignacion: ' . $e->getMessage());
        }
        try {
            new \SoftNova\Services\AccountingService($this->db);
        } catch (\Throwable $e) {
            error_log('ensureSaleRuntimeSchema accounting: ' . $e->getMessage());
        }
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
        $paymentSplitsRaw = $this->request->post('payment_splits', []);
        if (!is_array($paymentSplitsRaw)) {
            $paymentSplitsRaw = [];
        }
        // También acepta claves planas payment_splits[0][method] desde FormData.
        if ($paymentSplitsRaw === []) {
            $paymentSplitsRaw = SalePaymentSplitter::extractFromArray($_POST);
        }
        $paymentType = $this->request->post('payment_type', 'full');
        $documentType = (string)$this->request->post('document_type', 'invoice');
        if (!isset(\SoftNova\Services\SalesDocumentService::documentTypes()[$documentType])) {
            $documentType = 'invoice';
        }
        $paymentTerms = (string)$this->request->post('payment_terms', $paymentType === 'credit' ? 'net_30' : 'cash');
        if (!\SoftNova\Services\SalesDocumentService::isValidTerms($paymentTerms)) {
            $paymentTerms = $paymentType === 'credit' ? 'net_30' : 'cash';
        }
        $saleDate = trim((string)$this->request->post('sale_date', date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = date('Y-m-d');
        }
        // Día de negocio (America/Bogota u otra TZ configurada). Bloquea día futuro por UTC del cliente.
        $saleDate = \SoftNova\Core\BusinessTimezone::clampBusinessDate($saleDate, $this->db);
        $dueDateInput = trim((string)$this->request->post('due_date', ''));
        $receivedDate = trim((string)$this->request->post('received_date', ''));
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateInput)
            ? $dueDateInput
            : \SoftNova\Services\SalesDocumentService::resolveDueDate($paymentTerms, $saleDate);
        if ($receivedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) {
            $receivedDate = '';
        }
        $initialPayment = \SoftNova\Core\parse_money($this->request->post('initial_payment', 0));
        $items = $this->request->post('items', []);
        if (!is_array($items)) {
            $items = [];
        }
        // Reconstruir desde claves planas si el proxy no anidó arrays (combo incluido).
        if ($items === []) {
            foreach ($_POST as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (preg_match('/^items\[(\d+)\]\[components\]\[(\d+)\]\[(\w+)\]$/', $key, $m)) {
                    $idx = (int)$m[1];
                    $cIdx = (int)$m[2];
                    if (!isset($items[$idx]) || !is_array($items[$idx])) {
                        $items[$idx] = [];
                    }
                    if (!isset($items[$idx]['components']) || !is_array($items[$idx]['components'])) {
                        $items[$idx]['components'] = [];
                    }
                    if (!isset($items[$idx]['components'][$cIdx]) || !is_array($items[$idx]['components'][$cIdx])) {
                        $items[$idx]['components'][$cIdx] = [];
                    }
                    $items[$idx]['components'][$cIdx][$m[3]] = $value;
                    continue;
                }
                if (preg_match('/^items\[(\d+)\]\[(\w+)\]$/', $key, $m)) {
                    $idx = (int)$m[1];
                    if (!isset($items[$idx]) || !is_array($items[$idx])) {
                        $items[$idx] = [];
                    }
                    $items[$idx][$m[2]] = $value;
                }
            }
        }
        // Normalizar components_json → components en cada ítem.
        foreach ($items as $k => $item) {
            if (!is_array($item)) {
                continue;
            }
            $comps = $item['components'] ?? null;
            if ((!is_array($comps) || $comps === []) && !empty($item['components_json'])) {
                $decoded = json_decode((string)$item['components_json'], true);
                if (is_array($decoded)) {
                    $items[$k]['components'] = $decoded;
                    $items[$k]['is_combo'] = '1';
                }
            }
        }
        $notes = trim((string)$this->request->post('notes', ''));
        $discountPercent = (float)$this->request->post('discount_percent', 0);
        if ($discountPercent < 0) {
            $discountPercent = 0;
        }
        if ($discountPercent > 100) {
            $discountPercent = 100;
        }
        $deliveryFee = round(\SoftNova\Core\parse_money($this->request->post('delivery_fee', 0)), 2);
        if ($deliveryFee < 0) {
            $deliveryFee = 0.0;
        }
        $deliveryCompanyFee = round(\SoftNova\Core\parse_money($this->request->post('delivery_company_fee', 0)), 2);
        if ($deliveryCompanyFee < 0) {
            $deliveryCompanyFee = 0.0;
        }
        $deliveryTotalPosted = round(\SoftNova\Core\parse_money($this->request->post('delivery_total', 0)), 2);
        if ($deliveryTotalPosted < 0) {
            $deliveryTotalPosted = 0.0;
        }
        // Solo la parte del cliente ($deliveryFee) entra al total de la venta.
        // Empresa → gasto Transporte. delivery_total es informativo.
        $deliveryTotal = $deliveryTotalPosted;
        if ($deliveryTotal <= 0.009 && ($deliveryFee > 0.009 || $deliveryCompanyFee > 0.009)) {
            $deliveryTotal = round($deliveryFee + $deliveryCompanyFee, 2);
        }
        if ($deliveryTotal > 0.009) {
            if ($deliveryCompanyFee > $deliveryTotal) {
                $deliveryCompanyFee = $deliveryTotal;
            }
            // Si delivery_fee llegó como total completo (compat), derivar cliente.
            if (abs($deliveryFee - $deliveryTotal) < 0.011 && $deliveryCompanyFee > 0.009) {
                $deliveryFee = round(max(0, $deliveryTotal - $deliveryCompanyFee), 2);
            } elseif ($deliveryFee <= 0.009 && $deliveryCompanyFee > 0.009) {
                $deliveryFee = round(max(0, $deliveryTotal - $deliveryCompanyFee), 2);
            } elseif ($deliveryFee > $deliveryTotal) {
                $deliveryFee = $deliveryTotal;
            }
            // Ajustar empresa si cliente + empresa no cierran el total.
            $sumParts = round($deliveryFee + $deliveryCompanyFee, 2);
            if ($deliveryCompanyFee <= 0.009 && $deliveryFee < $deliveryTotal) {
                $deliveryCompanyFee = round(max(0, $deliveryTotal - $deliveryFee), 2);
            } elseif (abs($sumParts - $deliveryTotal) > 0.02 && $deliveryCompanyFee > 0.009) {
                $deliveryFee = round(max(0, $deliveryTotal - $deliveryCompanyFee), 2);
            }
        }
        $saleChannel = strtolower(trim((string)$this->request->post('sale_channel', 'retail')));
        if (!in_array($saleChannel, ['retail', 'wholesale'], true)) {
            $saleChannel = 'retail';
        }
        $cashReceivedRaw = $this->request->post('cash_received', null);
        $cashChangeRaw = $this->request->post('cash_change', null);
        $cashReceived = ($cashReceivedRaw !== null && $cashReceivedRaw !== '')
            ? max(0, round(\SoftNova\Core\parse_money($cashReceivedRaw), 2))
            : null;
        $cashChange = ($cashChangeRaw !== null && $cashChangeRaw !== '')
            ? max(0, round(\SoftNova\Core\parse_money($cashChangeRaw), 2))
            : null;
        
        if (empty($items) || !is_array($items)) {
            $this->respond(false, 'Debe agregar al menos un producto', '/app/ventas');
            return;
        }

        // Agrupa cantidades del mismo producto (excepto comodín / combos) para no aprobar 3+3 con stock 5.
        $items = $this->aggregateCartItemsByProduct($items);

        try {
            (new \SoftNova\Services\ProductComboService($this->db))->ensureSchema();
        } catch (\Throwable $e) {
            // ignore
        }

        $prefix = \SoftNova\Services\SalesDocumentService::prefixFor($documentType);
        if ($documentType === 'invoice') {
            $prefix = $this->getSetting('invoice_prefix', 'FAC-');
        }
        $invoiceNumber = $prefix . date('Ymd', strtotime($saleDate)) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        
        $subtotal = 0;
        $validItems = [];
        $qtyDiscountNotes = [];
        $qtyDiscountSvc = null;
        try {
            $qtyDiscountSvc = new \SoftNova\Services\CommissionService($this->db);
        } catch (\Throwable $e) {
            $qtyDiscountSvc = null;
        }
        \SoftNova\Services\TaxService::ensureSchema($this->db);
        $defaultTax = \SoftNova\Services\TaxService::defaultRate($this->db);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // —— Combo: 1 renglón visible + componentes (stock / costo ocultos en factura) ——
            $isCombo = !empty($item['is_combo'])
                || (string)($item['is_combo'] ?? '') === '1'
                || (string)($item['is_combo'] ?? '') === 'true'
                || (string)($item['product_type'] ?? '') === 'combo'
                || !empty($item['components_json'])
                || (isset($item['components']) && is_array($item['components']) && count($item['components']) >= 2
                    && (int)($item['product_id'] ?? 0) <= 0);
            if ($isCombo) {
                $packs = (int)($item['quantity'] ?? 0);
                if ($packs <= 0) {
                    $this->respond(false, 'Cantidad de combo inválida', '/app/caja');
                    return;
                }
                $comboPrice = round((float)($item['unit_price'] ?? 0), 2);
                if ($comboPrice < 0) {
                    $comboPrice = 0.0;
                }
                $comboName = trim((string)($item['product_name'] ?? $item['combo_name'] ?? 'Combo'));
                if ($comboName === '') {
                    $comboName = 'Combo';
                }
                if (mb_strlen($comboName) > 255) {
                    $comboName = mb_substr($comboName, 0, 255);
                }
                if (!str_starts_with(mb_strtolower($comboName), 'combo')) {
                    $comboName = 'Combo: ' . $comboName;
                }
                $comboId = (int)($item['combo_id'] ?? 0);
                $components = $item['components'] ?? [];
                if ((!is_array($components) || count($components) < 2) && !empty($item['components_json'])) {
                    $decoded = json_decode((string)$item['components_json'], true);
                    if (is_array($decoded)) {
                        $components = $decoded;
                    }
                }
                if (!is_array($components)) {
                    $components = [];
                }
                $components = array_values(array_filter($components, static function ($c) {
                    return is_array($c) && (int)($c['product_id'] ?? 0) > 0;
                }));
                if (count($components) < 2) {
                    $this->respond(
                        false,
                        'El combo «' . $comboName . '» no llegó con sus productos. Actualice la página (Ctrl+F5) e intente de nuevo.',
                        '/app/caja'
                    );
                    return;
                }

                $costSumPerPack = 0.0;
                $taxRateCombo = 0.0;
                $compValid = [];
                foreach ($components as $comp) {
                    if (!is_array($comp)) {
                        continue;
                    }
                    $pid = (int)($comp['product_id'] ?? 0);
                    $qtyTotal = (int)($comp['quantity'] ?? 0);
                    $perPack = (int)($comp['quantity_per_pack'] ?? 0);
                    if ($pid <= 0) {
                        continue;
                    }
                    if ($qtyTotal <= 0 && $perPack > 0) {
                        $qtyTotal = $perPack * $packs;
                    }
                    if ($qtyTotal <= 0) {
                        continue;
                    }
                    if ($perPack <= 0) {
                        $perPack = max(1, (int)round($qtyTotal / max(1, $packs)));
                    }
                    $product = $this->query(
                        "SELECT * FROM products WHERE id = ? AND status = 'active'",
                        [$pid]
                    )->fetch();
                    if (!$product) {
                        $this->respond(false, 'Producto del combo no disponible (#' . $pid . ')', '/app/caja');
                        return;
                    }
                    if (\SoftNova\Services\OpenItemProduct::isOpenItem($product)) {
                        $this->respond(false, 'El comodín no puede formar parte de un combo en venta', '/app/caja');
                        return;
                    }
                    $unitCost = round((float)($product['purchase_price'] ?? 0), 2);
                    $costSumPerPack += $unitCost * $perPack;
                    $lineTax = \SoftNova\Services\TaxService::resolveProductRate(
                        $product,
                        $defaultTax,
                        $this->db
                    );
                    if ($lineTax > $taxRateCombo) {
                        $taxRateCombo = $lineTax;
                    }
                    $isService = (($product['product_type'] ?? 'product') === 'service');
                    $available = (int)($product['stock'] ?? 0);
                    $isConsignment = (($product['ownership'] ?? 'owned') === 'consignment');
                    $oversellReason = '';
                    $oversellNote = '';
                    if (!$isService && $qtyTotal > max(0, $available)) {
                        if ($isConsignment) {
                            $this->respond(
                                false,
                                'Stock consignado insuficiente para ' . $product['name']
                                . ' dentro del combo (disponible: ' . $available . ').',
                                '/app/caja'
                            );
                            return;
                        }
                        try {
                            $parsed = \SoftNova\Services\StockOversell::parse(
                                $item['stock_oversell_reason'] ?? ($comp['stock_oversell_reason'] ?? null),
                                $item['stock_oversell_note'] ?? ($comp['stock_oversell_note'] ?? null)
                            );
                        } catch (\InvalidArgumentException $e) {
                            $this->respond(false, $e->getMessage() . ' (' . $product['name'] . ')', '/app/caja');
                            return;
                        }
                        if ($parsed === null) {
                            $this->respond(
                                false,
                                'Stock insuficiente para ' . $product['name']
                                . ' en el combo (disponible: ' . $available . ').',
                                '/app/caja'
                            );
                            return;
                        }
                        $oversellReason = $parsed['reason'];
                        $oversellNote = $parsed['note'];
                    }
                    $compValid[] = [
                        'product_id' => $pid,
                        'product_name' => (string)$product['name'],
                        'quantity' => $qtyTotal,
                        'unit_price' => 0.0,
                        'catalog_price' => round((float)($product['sale_price'] ?? 0), 2),
                        'sync_catalog_price' => false,
                        'unit_cost' => 0.0, // costo va en la línea del combo (evita doble conteo)
                        'stock_unit_cost' => $unitCost, // para decrease()
                        'subtotal' => 0.0,
                        'tax_rate' => 0.0,
                        'product_type' => $product['product_type'] ?? 'product',
                        'stock' => $available,
                        'stock_oversell_reason' => $oversellReason,
                        'stock_oversell_note' => $oversellNote,
                        'is_open_item' => false,
                        'is_consignment' => $isConsignment,
                        'consignor_id' => $isConsignment ? (int)($product['consignor_id'] ?? 0) : 0,
                        'is_combo' => false,
                        'document_hidden' => true,
                        'combo_id' => $comboId > 0 ? $comboId : null,
                        'skip_stock' => false,
                    ];
                }
                if (count($compValid) < 2) {
                    $this->respond(false, 'El combo «' . $comboName . '» no tiene componentes válidos', '/app/caja');
                    return;
                }
                $costSumPerPack = round($costSumPerPack, 2);
                if ($costSumPerPack > 0.009 && !\SoftNova\Services\ProductPriceGuard::saleExceedsCost($comboPrice, $costSumPerPack)) {
                    $this->respond(
                        false,
                        \SoftNova\Services\ProductPriceGuard::message($comboName, $comboPrice, $costSumPerPack),
                        '/app/caja'
                    );
                    return;
                }
                // IVA del combo = mayor tasa de sus componentes según catálogo; nunca la del navegador.
                $postedTax = round($taxRateCombo, 2);
                $lineSub = round($comboPrice * $packs, 2);
                $subtotal += $lineSub;
                $validItems[] = [
                    'product_id' => null,
                    'product_name' => $comboName,
                    'quantity' => $packs,
                    'unit_price' => $comboPrice,
                    'catalog_price' => $comboPrice,
                    'sync_catalog_price' => false,
                    'unit_cost' => $costSumPerPack,
                    'subtotal' => $lineSub,
                    'tax_rate' => $postedTax,
                    'product_type' => 'combo',
                    'stock' => 0,
                    'stock_oversell_reason' => '',
                    'stock_oversell_note' => '',
                    'is_open_item' => false,
                    'is_consignment' => false,
                    'consignor_id' => 0,
                    'is_combo' => true,
                    'document_hidden' => false,
                    'combo_id' => $comboId > 0 ? $comboId : null,
                    'skip_stock' => true,
                ];
                foreach ($compValid as $cv) {
                    $validItems[] = $cv;
                }
                if ($comboId > 0) {
                    try {
                        (new \SoftNova\Services\ProductComboService($this->db))->recordUsage($comboId);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
                continue;
            }

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
            $isOpenItem = \SoftNova\Services\OpenItemProduct::isOpenItem($product);
            $catalogPrice = round((float)($product['sale_price'] ?? 0), 2);
            $wholesalePrice = round((float)($product['wholesale_price'] ?? 0), 2);
            if ($saleChannel === 'wholesale' && $wholesalePrice > 0.009) {
                $catalogPrice = $wholesalePrice;
            }
            $postedPrice = array_key_exists('unit_price', $item)
                ? round((float)$item['unit_price'], 2)
                : $catalogPrice;
            if ($postedPrice < 0) {
                $postedPrice = 0.0;
            }
            $lineName = (string)$product['name'];
            $qtyDiscApplied = false;
            if ($isOpenItem) {
                try {
                    $lineName = \SoftNova\Services\OpenItemProduct::sanitizeLineName(
                        (string)($item['product_name'] ?? $item['name'] ?? '')
                    );
                    $postedPrice = \SoftNova\Services\OpenItemProduct::sanitizeLinePrice($postedPrice);
                } catch (\InvalidArgumentException $e) {
                    $this->respond(false, $e->getMessage(), '/app/ventas');
                    return;
                }
            } elseif ($qtyDiscountSvc) {
                // Descuento automático por cantidad (misma regla que Caja).
                $priceManual = !empty($item['price_manual'])
                    || (string)($item['price_manual'] ?? '') === '1'
                    || (string)($item['price_manual'] ?? '') === 'true';
                $resolvedQty = $qtyDiscountSvc->resolveQuantityDiscount($quantity);
                if (!$priceManual && !empty($resolvedQty['matched'])) {
                    $rate = (float)$resolvedQty['rate'];
                    $postedPrice = round($catalogPrice * (1 - $rate / 100), 2);
                    $qtyDiscApplied = true;
                    $qtyDiscountNotes[$resolvedQty['min_qty'] . ':' . $rate] = true;
                } elseif ($priceManual && !empty($resolvedQty['matched'])) {
                    // Precio manual: se respeta, pero nunca sincroniza catálogo.
                    $qtyDiscApplied = true;
                }
            }
            $up = $postedPrice;
            $is = $up * $quantity;
            $subtotal += $is;
            $unitCost = $isOpenItem ? 0.0 : round((float)($product['purchase_price'] ?? 0), 2);
            if (!$isOpenItem && !\SoftNova\Services\ProductPriceGuard::saleExceedsCost($up, $unitCost)) {
                $this->respond(
                    false,
                    \SoftNova\Services\ProductPriceGuard::message((string)$product['name'], $up, $unitCost),
                    str_contains((string)($_SERVER['HTTP_REFERER'] ?? ''), '/app/caja') ? '/app/caja' : '/app/ventas'
                );
                return;
            }
            $catalogList = round((float)($product['sale_price'] ?? 0), 2);
            if (
                !$isOpenItem
                && $saleChannel !== 'wholesale'
                && !\SoftNova\Services\ProductPriceGuard::saleExceedsCost($catalogList, $unitCost)
            ) {
                $this->respond(
                    false,
                    \SoftNova\Services\ProductPriceGuard::message((string)$product['name'], $catalogList, $unitCost),
                    str_contains((string)($_SERVER['HTTP_REFERER'] ?? ''), '/app/caja') ? '/app/caja' : '/app/ventas'
                );
                return;
            }
            $lineTaxRate = \SoftNova\Services\TaxService::resolveProductRate(
                $product,
                $defaultTax,
                $this->db,
                $isOpenItem,
                $isOpenItem ? ($item['tax_rate'] ?? null) : null
            );
            $validItems[] = [
                'product_id' => $productId,
                'product_name' => $lineName,
                'quantity' => $quantity,
                'unit_price' => $up,
                'catalog_price' => $catalogPrice,
                // Nunca actualizar precio de catálogo desde una venta POS.
                // Un precio negociado o con descuento por cantidad no debe envenenar sale_price.
                'sync_catalog_price' => false,
                'unit_cost' => $unitCost,
                'subtotal' => $is,
                'tax_rate' => $lineTaxRate,
                'product_type' => $isOpenItem ? 'service' : ($product['product_type'] ?? 'product'),
                'stock' => (int)($product['stock'] ?? 0),
                'stock_oversell_reason' => '',
                'stock_oversell_note' => '',
                'is_open_item' => $isOpenItem,
                'is_consignment' => (($product['ownership'] ?? 'owned') === 'consignment'),
                'consignor_id' => (($product['ownership'] ?? 'owned') === 'consignment')
                    ? (int)($product['consignor_id'] ?? 0)
                    : 0,
                'is_combo' => false,
                'document_hidden' => false,
                'combo_id' => null,
                'skip_stock' => $isOpenItem,
            ];
            $lastIdx = count($validItems) - 1;
            $isService = ($validItems[$lastIdx]['product_type'] ?? 'product') === 'service';
            $available = (int)($product['stock'] ?? 0);
            if (!$isService && $quantity > max(0, $available)) {
                if (!empty($validItems[$lastIdx]['is_consignment'])) {
                    $this->respond(
                        false,
                        'Stock consignado insuficiente para ' . $product['name']
                        . ' (disponible: ' . $available . '). No se permite sobreventa de consignación.',
                        '/app/ventas'
                    );
                    return;
                }
                try {
                    $parsed = \SoftNova\Services\StockOversell::parse(
                        $item['stock_oversell_reason'] ?? ($item['oversell_reason'] ?? null),
                        $item['stock_oversell_note'] ?? ($item['oversell_note'] ?? null)
                    );
                } catch (\InvalidArgumentException $e) {
                    $this->respond(false, $e->getMessage() . ' (' . $product['name'] . ')', '/app/ventas');
                    return;
                }
                if ($parsed === null) {
                    $this->respond(
                        false,
                        'Stock insuficiente para ' . $product['name']
                        . ' (disponible: ' . $available . '). Seleccione el motivo de venta sin stock.',
                        '/app/ventas'
                    );
                    return;
                }
                $validItems[$lastIdx]['stock_oversell_reason'] = $parsed['reason'];
                $validItems[$lastIdx]['stock_oversell_note'] = $parsed['note'];
            }
        }
        
        if (empty($validItems)) {
            $this->respond(false, 'No se encontraron productos validos', '/app/ventas');
            return;
        }
        
        $discount = round($subtotal * ($discountPercent / 100), 2);
        $taxBreakdown = \SoftNova\Services\TaxService::applyDocumentDiscountAndTax($validItems, $discountPercent);
        $tax = (float)$taxBreakdown['tax'];
        $taxable = (float)$taxBreakdown['taxable'];
        $validItems = $taxBreakdown['lines'];
        try {
            \SoftNova\Services\TaxService::assertHeaderMatchesLines($tax, $validItems);
        } catch (\RuntimeException $e) {
            $this->respond(false, $e->getMessage(), '/app/ventas');
            return;
        }

        $creditSurcharge = 0.0;
        $creditSurchargeRate = 0.0;
        $threshold = 60;
        if ($paymentType === 'credit') {
            $termDays = \SoftNova\Services\SalesDocumentService::termDays($paymentTerms);
            try {
                $comm = new \SoftNova\Services\CommissionService($this->db);
                $resolved = $comm->resolveCreditSurcharge($termDays);
                if (!empty($resolved['matched'])) {
                    $threshold = (int)$resolved['min_days'];
                    $creditSurchargeRate = (float)$resolved['rate'];
                    $creditSurcharge = round($taxable * ($creditSurchargeRate / 100), 2);
                }
            } catch (\Throwable $e) {
            }
        }
        // Domicilio completo en el total de la venta.
        $total = $taxable + $tax + $creditSurcharge + $deliveryFee;

        if ($discountPercent > 0) {
            $discountNote = 'Descuento ' . rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.') . '%';
            $notes = $notes !== '' ? ($notes . ' · ' . $discountNote) : $discountNote;
        }
        if (!empty($qtyDiscountNotes)) {
            $parts = [];
            foreach (array_keys($qtyDiscountNotes) as $key) {
                [$mq, $rt] = array_pad(explode(':', (string)$key, 2), 2, '0');
                $parts[] = '>' . (int)$mq . 'ud ' . rtrim(rtrim(number_format((float)$rt, 2, '.', ''), '0'), '.') . '%';
            }
            $qNote = 'Desc. cantidad: ' . implode(', ', $parts);
            $notes = $notes !== '' ? ($notes . ' · ' . $qNote) : $qNote;
        }
        if ($saleChannel === 'wholesale') {
            $notes = $notes !== '' ? ($notes . ' · Venta al por mayor') : 'Venta al por mayor';
        }
        if ($creditSurcharge > 0.009) {
            $sNote = 'Recargo crédito >' . (int)$threshold . 'd '
                . number_format($creditSurchargeRate, 2, '.', '') . '%: '
                . number_format($creditSurcharge, 2, '.', '');
            $notes = $notes !== '' ? ($notes . ' · ' . $sNote) : $sNote;
        }
        $deliveryClientShare = $deliveryFee;
        $deliveryTotalNote = isset($deliveryTotal) && $deliveryTotal > 0.009
            ? $deliveryTotal
            : round($deliveryFee + $deliveryCompanyFee, 2);
        if ($deliveryTotalNote > 0.009) {
            $deliveryNote = 'Domicilio: ' . number_format($deliveryTotalNote, 2, '.', '');
            if ($deliveryCompanyFee > 0.009) {
                $deliveryNote .= ' (cliente ' . number_format($deliveryClientShare, 2, '.', '')
                    . ' + empresa ' . number_format($deliveryCompanyFee, 2, '.', '') . ')';
            }
            $notes = $notes !== '' ? ($notes . ' · ' . $deliveryNote) : $deliveryNote;
        }
        
        $paymentStatus = $paymentType === 'credit' ? 'pending' : 'paid';
        $initialPayment = max(0, $initialPayment);
        if ($paymentType === 'credit') {
            $initialPayment = min($initialPayment, $total);
            if ($initialPayment >= $total && $total > 0) {
                $paymentStatus = 'paid';
            }
        }

        $paidNow = $paymentType === 'credit' ? $initialPayment : $total;
        try {
            $payPlan = SalePaymentSplitter::resolve($paymentSplitsRaw, $paymentMethod, $paidNow, $this->db);
        } catch (\InvalidArgumentException $e) {
            $this->respond(false, $e->getMessage(), '/app/ventas');
            return;
        }
        $paymentMethod = $payPlan['method'];
        if ($payPlan['label'] !== '' && str_contains($payPlan['label'], 'Mixto')) {
            $notes = $notes !== '' ? ($notes . ' · ' . $payPlan['label']) : $payPlan['label'];
        }

        $cashDueForChange = (float)($payPlan['cash_total'] ?? 0);
        if ($cashDueForChange <= 0.009 && ($paymentMethod === 'cash' || PaymentMethodCatalog::affectsCash($paymentMethod))) {
            $cashDueForChange = $paymentType === 'credit' ? min($initialPayment, $total) : $total;
        }
        if ($cashReceived !== null && $cashReceived > 0.009) {
            $cashChange = \SoftNova\Core\cash_change_amount(
                $cashReceived,
                $cashDueForChange > 0.009 ? $cashDueForChange : $total,
                $cashChange
            );
        } else {
            $cashChange = null;
        }

        // Efectivo requiere caja abierta (igual que cancel/NC y sync offline).
        if ((float)($payPlan['cash_total'] ?? 0) > 0.009) {
            try {
                $this->cash->assertOpenSessionForCash('registrar una venta cobrada en efectivo');
            } catch (\RuntimeException $e) {
                $this->respond(false, $e->getMessage(), '/app/ventas');
                return;
            }
        }
        
        try {
            // DDL (CREATE/ALTER) debe correr ANTES de beginTransaction:
            // en MySQL cualquier DDL hace COMMIT implícito y luego falla el commit.
            $this->ensureSaleRuntimeSchema();

            $this->db->beginTransaction();
            
            try {
                $this->query(
                    "INSERT INTO sales
                        (invoice_number, document_type, customer_id, user_id, sale_date, due_date, received_date,
                         subtotal, tax, discount, delivery_fee, delivery_company_fee, cash_received, cash_change,
                         total, payment_method, payment_terms, payment_status, notes,
                         client_sale_uuid, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $invoiceNumber,
                        $documentType,
                        $customerId,
                        $_SESSION['tenant_user_id'],
                        // Fecha+hora local del servidor (timezone app). El día viene de sale_date (local).
                        $saleDate . ' ' . date('H:i:s'),
                        $dueDate,
                        $receivedDate !== '' ? $receivedDate : null,
                        $subtotal,
                        $tax,
                        $discount,
                        $deliveryFee,
                        $deliveryCompanyFee,
                        $cashReceived,
                        $cashChange,
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
                $isConsignment = !empty($vi['is_consignment']);
                $isComboLine = !empty($vi['is_combo']);
                $docHidden = !empty($vi['document_hidden']);
                $comboIdVal = !empty($vi['combo_id']) ? (int)$vi['combo_id'] : null;
                $productIdVal = $vi['product_id'] !== null && $vi['product_id'] !== ''
                    ? (int)$vi['product_id']
                    : null;
                if ($productIdVal !== null && $productIdVal <= 0) {
                    $productIdVal = null;
                }
                try {
                    $this->query(
                        "INSERT INTO sale_items
                            (sale_id, product_id, product_name, quantity, unit_price, unit_cost,
                             is_consignment, consignor_id, subtotal, tax_rate, tax_amount,
                             discount_amount, taxable,
                             stock_oversell_reason, stock_oversell_note,
                             is_combo, document_hidden, combo_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $saleId,
                            $productIdVal,
                            $vi['product_name'],
                            $vi['quantity'],
                            $vi['unit_price'],
                            $vi['unit_cost'] ?? 0,
                            $isConsignment ? 1 : 0,
                            $isConsignment && !empty($vi['consignor_id']) ? (int)$vi['consignor_id'] : null,
                            $vi['subtotal'],
                            round((float)($vi['tax_rate'] ?? 0), 2),
                            round((float)($vi['tax_amount'] ?? 0), 2),
                            round((float)($vi['discount_amount'] ?? 0), 2),
                            round((float)($vi['taxable'] ?? $vi['subtotal'] ?? 0), 2),
                            $vi['stock_oversell_reason'] !== '' ? $vi['stock_oversell_reason'] : null,
                            $vi['stock_oversell_note'] !== '' ? $vi['stock_oversell_note'] : null,
                            $isComboLine ? 1 : 0,
                            $docHidden ? 1 : 0,
                            $comboIdVal,
                        ]
                    );
                } catch (\Throwable $e) {
                    try {
                        $this->query(
                            "INSERT INTO sale_items
                                (sale_id, product_id, product_name, quantity, unit_price, unit_cost,
                                 is_consignment, consignor_id, subtotal, tax_rate, tax_amount,
                                 stock_oversell_reason, stock_oversell_note)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $saleId,
                                $productIdVal,
                                $vi['product_name'],
                                $vi['quantity'],
                                $vi['unit_price'],
                                $vi['unit_cost'] ?? 0,
                                $isConsignment ? 1 : 0,
                                $isConsignment && !empty($vi['consignor_id']) ? (int)$vi['consignor_id'] : null,
                                $vi['subtotal'],
                                round((float)($vi['tax_rate'] ?? 0), 2),
                                round((float)($vi['tax_amount'] ?? 0), 2),
                                $vi['stock_oversell_reason'] !== '' ? $vi['stock_oversell_reason'] : null,
                                $vi['stock_oversell_note'] !== '' ? $vi['stock_oversell_note'] : null,
                            ]
                        );
                    } catch (\Throwable $e2) {
                    try {
                        $this->query(
                            "INSERT INTO sale_items
                                (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal,
                                 stock_oversell_reason, stock_oversell_note)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $saleId,
                                $productIdVal,
                                $vi['product_name'],
                                $vi['quantity'],
                                $vi['unit_price'],
                                $vi['unit_cost'] ?? 0,
                                $vi['subtotal'],
                                $vi['stock_oversell_reason'] !== '' ? $vi['stock_oversell_reason'] : null,
                                $vi['stock_oversell_note'] !== '' ? $vi['stock_oversell_note'] : null,
                            ]
                        );
                    } catch (\Throwable $e3) {
                        $this->query(
                            "INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, unit_cost, subtotal)
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [$saleId, $productIdVal, $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['unit_cost'] ?? 0, $vi['subtotal']]
                        );
                    }
                    }
                }
                $saleItemId = (int)$this->db->lastInsertId();
                if (!empty($vi['sync_catalog_price']) && $productIdVal) {
                    $this->sales->stock()->recordSalePriceChange(
                        (int)$productIdVal,
                        (float)$vi['catalog_price'],
                        (float)$vi['unit_price'],
                        $saleId,
                        $invoiceNumber
                    );
                }
                if (!empty($vi['skip_stock']) || !$productIdVal) {
                    continue;
                }
                $oversell = null;
                if (!empty($vi['stock_oversell_reason'])) {
                    $oversell = [
                        'reason' => (string)$vi['stock_oversell_reason'],
                        'note' => (string)($vi['stock_oversell_note'] ?? ''),
                        'sale_item_id' => $saleItemId,
                    ];
                }
                $stockCost = array_key_exists('stock_unit_cost', $vi)
                    ? (float)$vi['stock_unit_cost']
                    : (float)($vi['unit_cost'] ?? 0);
                $this->sales->stock()->decrease(
                    $productIdVal,
                    $vi['quantity'],
                    'sale',
                    $saleId,
                    'Venta: ' . $invoiceNumber . ($docHidden ? ' (combo)' : ''),
                    $stockCost,
                    $oversell
                );
                if ($isConsignment) {
                    (new \SoftNova\Services\ConsignmentService($this->db, $this->sales->stock()))->registerSaleDebt(
                        $saleId,
                        $saleItemId,
                        (int)$productIdVal,
                        (int)$vi['quantity'],
                        $stockCost,
                        (int)($vi['consignor_id'] ?? 0) ?: null
                    );
                }
            }
            
            $cashAmount = 0.0;
            if ($paidNow > 0.009 && !empty($payPlan['splits'])) {
                foreach ($payPlan['splits'] as $split) {
                    $this->query(
                        "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                         VALUES (?, ?, ?, ?, ?)",
                        [
                            $saleId,
                            $split['amount'],
                            $split['method'],
                            $paymentType === 'credit'
                                ? ('Pago inicial - credito · ' . PaymentMethodCatalog::label($split['method'], $this->db))
                                : ('Pago · ' . PaymentMethodCatalog::label($split['method'], $this->db)),
                            $_SESSION['tenant_user_id'],
                        ]
                    );
                }
                $cashAmount = (float)$payPlan['cash_total'];
                if ($paymentType === 'credit' && $paidNow < $total) {
                    $this->query("UPDATE sales SET payment_status = 'partial' WHERE id = ?", [$saleId]);
                }
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }

            // Contabilización fuera de la transacción: un periodo cerrado u otro
            // problema contable NO debe impedir registrar la venta.
            $accountingOk = true;
            try {
                $this->accounting->postSaleCascade($saleId);
            } catch (\Throwable $e) {
                $accountingOk = false;
                error_log('Contabilidad venta ' . $saleId . ': ' . $e->getMessage());
                $this->accounting->enqueuePending(
                    'post_sale',
                    'sale',
                    $saleId,
                    $e->getMessage()
                );
            }

            try {
                (new \SoftNova\Services\CommissionService($this->db))->processSale($saleId);
            } catch (\Throwable $e) {
                error_log('Comisiones venta ' . $saleId . ': ' . $e->getMessage());
            }

            // Solo el efectivo del mixto (o del pago único) entra a la caja física.
            // Ya se exigió caja abierta antes de crear la venta; required=true evita éxito silencioso.
            $cashWarn = '';
            if ($cashAmount > 0.009) {
                $label = ($paymentType === 'credit' && $paymentStatus !== 'paid')
                    ? 'Abono inicial: ' . $invoiceNumber
                    : 'Venta: ' . $invoiceNumber;
                if ($paymentMethod === 'mixed') {
                    $label .= ' (efectivo mixto)';
                }
                try {
                    $this->cash->registerIncome($cashAmount, $label, 'sale', $saleId, true);
                } catch (\RuntimeException $e) {
                    error_log('Caja: venta ' . $saleId . ' sin movimiento de ingreso: ' . $e->getMessage());
                    $cashWarn = ' ' . $e->getMessage();
                }
            }

            // Aporte empresa al domicilio → gasto operacional Transporte.
            if ($deliveryCompanyFee > 0.009) {
                try {
                    (new \SoftNova\Services\DeliveryCompanyExpenseService($this->db))->postForSale(
                        $saleId,
                        $invoiceNumber,
                        $deliveryCompanyFee,
                        (int)($_SESSION['tenant_user_id'] ?? 0)
                    );
                } catch (\Throwable $e) {
                    error_log('Gasto transporte domicilio venta ' . $saleId . ': ' . $e->getMessage());
                }
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
            if (!$accountingOk) {
                $msg .= ' · Asiento contable pendiente: use «Sincronizar» en Contabilidad';
            }
            if ($cashWarn !== '') {
                $msg .= $cashWarn;
            }

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
                'ticket_print_url' => rtrim((string)\SoftNova\Core\base_url('/app/ventas'), '/') . '?action=pdf&format=print&id=' . $saleId,
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
        $amount = \SoftNova\Core\parse_money($this->request->post('amount'));
        $method = \SoftNova\Services\PaymentMethodCatalog::normalize($this->request->post('payment_method', 'cash'));
        $notes = $this->request->post('notes', 'Abono');
        
        if ($amount <= 0) {
            $this->respond(false, 'El monto debe ser mayor a cero', '/app/ventas');
            return;
        }

        // Abono en efectivo: exigir caja abierta antes de grabar (evita descuadre cajón vs libros).
        if (\SoftNova\Services\PaymentMethodCatalog::affectsCash($method)) {
            try {
                $this->cash->assertOpenSessionForCash('registrar un abono en efectivo');
            } catch (\RuntimeException $e) {
                $this->respond(false, $e->getMessage(), '/app/ventas');
                return;
            }
        }
        
        try {
            $this->db->beginTransaction();
            
            $sale = $this->query(
                "SELECT * FROM sales
                 WHERE id = ? AND payment_status IN ('pending', 'partial')
                   AND COALESCE(status, '') <> 'cancelled'
                 FOR UPDATE",
                [$saleId]
            )->fetch();
            
            if (!$sale) {
                $this->db->rollBack();
                $this->respond(false, 'Venta no encontrada, cancelada o ya esta pagada', '/app/ventas');
                return;
            }

            // Saldo = total − NC posted − pagos (misma regla que syncReceivable).
            $ncSvc = new \SoftNova\Services\SalesCreditNoteService($this->db);
            $balances = $ncSvc->saleBalances($saleId);
            $alreadyPaid = (float)$balances['paid'];
            $netTotal = (float)$balances['net_total'];
            $balance = (float)$balances['balance'];

            if ($balance <= 0.009) {
                $this->db->rollBack();
                // Mantener estado coherente si quedó pagada por NC.
                try {
                    $ncSvc->syncReceivable($saleId);
                } catch (\Throwable $e) {
                }
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
            $remaining = round($netTotal - $totalPaid, 2);

            if ($remaining <= 0.009) {
                $this->query(
                    "UPDATE sales SET payment_status = 'paid', status = 'completed'
                     WHERE id = ? AND COALESCE(status, '') <> 'cancelled'",
                    [$saleId]
                );
                $remaining = 0;
            } else {
                $this->query(
                    "UPDATE sales SET payment_status = 'partial'
                     WHERE id = ? AND COALESCE(status, '') <> 'cancelled'",
                    [$saleId]
                );
            }

            $this->db->commit();

            // Contabilización fuera de la transacción: un periodo cerrado no debe
            // impedir registrar el abono.
            try {
                $this->accounting->postSalePayment($paymentId);
            } catch (\Throwable $e) {
                error_log('Contabilidad abono ' . $paymentId . ': ' . $e->getMessage());
                $this->accounting->enqueuePending(
                    'post_sale_payment',
                    'sale_payment',
                    $paymentId,
                    $e->getMessage()
                );
            }

            try {
                (new \SoftNova\Services\CommissionService($this->db))->processPayment($paymentId);
            } catch (\Throwable $e) {
                error_log('Comisiones abono ' . $paymentId . ': ' . $e->getMessage());
            }

            // Solo el efectivo entra a la caja física (evita descuadre con contabilidad).
            if (\SoftNova\Services\PaymentMethodCatalog::affectsCash($method)) {
                $this->cash->registerIncome(
                    $amount,
                    'Abono: ' . $sale['invoice_number'],
                    'sale_payment',
                    $paymentId,
                    true
                );
            }
            $this->receivables->applyPayment($saleId, min($totalPaid, $netTotal), $netTotal);

            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'payment',
                'ventas',
                'Abono ' . $this->formatMoney($amount) . ' en venta ' . $sale['invoice_number'],
                'sale_payment',
                $paymentId
            );
            
            $this->respond(true, 'Abono registrado. Pendiente: ' . $this->formatMoney(max(0, $remaining)), '/app/ventas');
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'Error: ' . $e->getMessage(), '/app/ventas');
        }
    }

    /**
     * JSON de abonos/pagos de una venta (para editar pago mixto).
     */
    private function paymentSplitsJson(): void
    {
        $saleId = (int)$this->request->get('id', 0);
        $sale = $this->query(
            "SELECT id, invoice_number, total, payment_method, payment_status, status
             FROM sales WHERE id = ? LIMIT 1",
            [$saleId]
        )->fetch();
        if (!$sale || ($sale['status'] ?? '') === 'cancelled') {
            $this->json(['success' => false, 'message' => 'Venta no encontrada']);
            return;
        }
        $rows = $this->query(
            "SELECT id, amount, payment_method, notes, payment_date
             FROM sale_payments WHERE sale_id = ? ORDER BY id ASC",
            [$saleId]
        )->fetchAll();
        $splits = [];
        foreach ($rows as $r) {
            $splits[] = [
                'id' => (int)$r['id'],
                'method' => PaymentMethodCatalog::normalize((string)$r['payment_method']),
                'amount' => round((float)$r['amount'], 2),
                'notes' => (string)($r['notes'] ?? ''),
            ];
        }
        // Solo inventar split = total en ventas ya pagadas sin filas (legacy).
        // En crédito pendiente no fabricar un cobro fantasma.
        if (
            $splits === []
            && (float)$sale['total'] > 0
            && (string)($sale['payment_status'] ?? '') === 'paid'
        ) {
            $splits[] = [
                'id' => 0,
                'method' => PaymentMethodCatalog::normalize((string)$sale['payment_method']),
                'amount' => round((float)$sale['total'], 2),
                'notes' => '',
            ];
        }
        $this->json([
            'success' => true,
            'sale_id' => $saleId,
            'invoice_number' => (string)$sale['invoice_number'],
            'total' => round((float)$sale['total'], 2),
            'payment_method' => PaymentMethodCatalog::normalize((string)$sale['payment_method']),
            'payment_status' => (string)$sale['payment_status'],
            'splits' => $splits,
            'paid_target' => round(array_sum(array_column($splits, 'amount')), 2),
        ]);
    }

    /**
     * Cambia el medio de pago (simple o mixto) de una venta ya registrada y ajusta caja.
     */
    private function updatePaymentMethod(): void
    {
        $redirect = str_contains((string)($_SERVER['HTTP_REFERER'] ?? ''), '/app/caja')
            ? '/app/caja'
            : '/app/ventas';

        if (!$this->validateCsrfOrFail($redirect)) {
            return;
        }

        $saleId = (int)$this->request->post('sale_id', 0);
        $sale = $this->query(
            "SELECT id, invoice_number, total, payment_method, payment_status, status
             FROM sales WHERE id = ? LIMIT 1",
            [$saleId]
        )->fetch();
        if (!$sale) {
            $this->respond(false, 'Venta no encontrada', $redirect);
            return;
        }
        if (($sale['status'] ?? '') === 'cancelled') {
            $this->respond(false, 'No se puede editar una venta cancelada', $redirect);
            return;
        }

        $label = (string)($sale['invoice_number'] ?? ('#' . $saleId));
        $oldRows = $this->query(
            "SELECT id, amount, payment_method FROM sale_payments WHERE sale_id = ? ORDER BY id",
            [$saleId]
        )->fetchAll();
        $oldCash = 0.0;
        $oldPaymentIds = [];
        foreach ($oldRows as $r) {
            $oldPaymentIds[] = (int)$r['id'];
            if (PaymentMethodCatalog::affectsCash((string)$r['payment_method'])) {
                $oldCash = round($oldCash + (float)$r['amount'], 2);
            }
        }
        if ($oldRows === [] && PaymentMethodCatalog::affectsCash((string)$sale['payment_method'])) {
            $oldCash = round((float)$sale['total'], 2);
        }
        $oldByMethod = [];
        foreach ($oldRows as $r) {
            $m = PaymentMethodCatalog::normalize((string)$r['payment_method']);
            $oldByMethod[$m] = round(($oldByMethod[$m] ?? 0) + (float)$r['amount'], 2);
        }
        if (count($oldByMethod) > 1) {
            $oldParts = [];
            foreach ($oldByMethod as $m => $amt) {
                $oldParts[] = PaymentMethodCatalog::label($m, $this->db) . ' ' . number_format($amt, 2, '.', '');
            }
            $oldLabel = 'Mixto: ' . implode(' + ', $oldParts);
        } else {
            $oldLabel = PaymentMethodCatalog::label(
                $oldByMethod !== [] ? (string)array_key_first($oldByMethod) : (string)$sale['payment_method'],
                $this->db
            );
        }

        $isMixed = $this->request->post('is_mixed', '0') === '1'
            || strtolower(trim((string)$this->request->post('payment_method', ''))) === 'mixed';
        $splitsRaw = $this->request->post('payment_splits', []);
        if (!is_array($splitsRaw)) {
            $splitsRaw = [];
        }

        // Monto objetivo = lo ya cobrado. No inventar el total en créditos pendientes.
        $paidTarget = 0.0;
        foreach ($oldRows as $r) {
            $paidTarget = round($paidTarget + (float)$r['amount'], 2);
        }
        if ($paidTarget <= 0.009 && ($sale['payment_status'] ?? '') === 'paid') {
            $paidTarget = round((float)$sale['total'], 2);
        }
        if ($paidTarget <= 0.009) {
            $this->respond(
                false,
                'No hay pagos registrados para editar. En ventas a crédito use un abono primero.',
                $redirect
            );
            return;
        }

        try {
            if ($isMixed || count(SalePaymentSplitter::extractFromArray(['payment_splits' => $splitsRaw])) > 0) {
                $plan = SalePaymentSplitter::resolve($splitsRaw, 'cash', $paidTarget, $this->db);
            } else {
                $raw = strtolower(trim((string)$this->request->post('payment_method', '')));
                if ($raw === '' || $raw === '__other__' || $raw === 'other') {
                    $this->respond(false, 'Seleccione un método de pago válido', $redirect);
                    return;
                }
                $plan = SalePaymentSplitter::resolve([], $raw, $paidTarget, $this->db);
            }
        } catch (\InvalidArgumentException $e) {
            $this->respond(false, $e->getMessage(), $redirect);
            return;
        }

        $newMethod = $plan['method'];
        $newCash = (float)$plan['cash_total'];

        // Si el ajuste mueve efectivo del cajón, exigir caja abierta.
        if (abs(round($newCash - $oldCash, 2)) > 0.009) {
            try {
                $this->cash->assertOpenSessionForCash('cambiar el medio de pago cuando hay efectivo involucrado');
            } catch (\RuntimeException $e) {
                $this->respond(false, $e->getMessage(), $redirect);
                return;
            }
        }

        // Reversar asientos de los pagos viejos ANTES de reemplazar filas.
        // Si falla, no se toca la venta (evita doble asiento).
        try {
            foreach ($oldPaymentIds as $oldPayId) {
                if ($oldPayId <= 0) {
                    continue;
                }
                $this->accounting->reverseSource('sale_payment', $oldPayId, 'Cambio medio de pago: ' . $label);
            }
        } catch (\Throwable $e) {
            $this->respond(
                false,
                'No se puede cambiar el medio de pago: la reversión contable falló. '
                . $e->getMessage()
                . ' Abra el periodo o use Sincronizar en Contabilidad.',
                $redirect
            );
            return;
        }

        $newPaymentIds = [];
        try {
            $this->db->beginTransaction();
            $this->query("DELETE FROM sale_payments WHERE sale_id = ?", [$saleId]);
            foreach ($plan['splits'] as $split) {
                $this->query(
                    "INSERT INTO sale_payments (sale_id, amount, payment_method, notes, user_id)
                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $saleId,
                        $split['amount'],
                        $split['method'],
                        'Ajuste medio de pago · ' . PaymentMethodCatalog::label($split['method'], $this->db),
                        $_SESSION['tenant_user_id'] ?? null,
                    ]
                );
                $newPaymentIds[] = (int)$this->db->lastInsertId();
            }
            $this->query(
                "UPDATE sales SET payment_method = ? WHERE id = ?",
                [$newMethod, $saleId]
            );
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, 'No se pudo actualizar el medio de pago: ' . $e->getMessage(), $redirect);
            return;
        }

        $postErrors = [];
        foreach ($newPaymentIds as $newPayId) {
            if ($newPayId <= 0) {
                continue;
            }
            try {
                $this->accounting->postSalePayment($newPayId);
            } catch (\Throwable $e) {
                $postErrors[] = $e->getMessage();
                $this->accounting->enqueuePending(
                    'post_sale_payment',
                    'sale_payment',
                    $newPayId,
                    $e->getMessage()
                );
                error_log('postSalePayment after edit ' . $newPayId . ': ' . $e->getMessage());
            }
        }
        if ($postErrors !== []) {
            $this->respond(
                false,
                'Medio de pago actualizado, pero el asiento contable quedó pendiente: '
                . $postErrors[0]
                . ' Use «Sincronizar» en Contabilidad.',
                $redirect
            );
            return;
        }
        try {
            $comm = new \SoftNova\Services\CommissionService($this->db);
            $comm->cancelForSale($saleId);
            $comm->processSale($saleId);
        } catch (\Throwable $e) {
            error_log('Comisiones after payment edit ' . $saleId . ': ' . $e->getMessage());
        }

        $deltaCash = 0.0;
        try {
            // Sincronizar cajón al neto real de movimientos de esta venta (no solo al
            // cálculo teórico de medios). Así cash→transfer saca el efectivo y no
            // deja un ingreso huérfano que el cuadre interpreta como "entrada".
            $this->cash->syncSaleCashInOpenSession(
                $saleId,
                $label,
                $newCash,
                $oldPaymentIds
            );
            $deltaCash = round($newCash - $oldCash, 2);
        } catch (\Throwable $e) {
            error_log('syncSaleCash after payment edit ' . $saleId . ': ' . $e->getMessage());
            // Fallback legacy por delta teórico
            $deltaCash = round($newCash - $oldCash, 2);
            if (abs($deltaCash) > 0.009) {
                if ($deltaCash > 0) {
                    $this->cash->registerIncome(
                        $deltaCash,
                        'Ajuste medio de pago (entra efectivo): ' . $label,
                        'sale_payment_adjust',
                        $saleId
                    );
                } else {
                    $this->cash->registerMovement(
                        abs($deltaCash),
                        'Ajuste medio de pago (sale efectivo → otro medio): ' . $label,
                        'expense',
                        'sale_payment_adjust',
                        $saleId
                    );
                }
            }
        }

        $newLabel = $plan['label'] !== '' ? $plan['label'] : PaymentMethodCatalog::label($newMethod, $this->db);
        try {
            (new \SoftNova\Services\TenantAuditService($this->db))->log(
                'update',
                'ventas',
                'Medio(s) de pago ' . $label . ': ' . $oldLabel . ' → ' . $newLabel
                    . (abs($deltaCash) > 0.009 ? (' · Δ efectivo ' . number_format($deltaCash, 2, '.', '')) : ''),
                'sale',
                $saleId,
                ['from' => $oldLabel, 'to' => $newLabel, 'delta_cash' => $deltaCash]
            );
        } catch (\Throwable $e) {
        }

        $this->respond(
            true,
            'Medio de pago actualizado'
                . ($plan['label'] !== '' ? ': ' . $plan['label'] : ' a ' . PaymentMethodCatalog::label($newMethod, $this->db)),
            $redirect
        );
    }
    
    private function cancelSale(): void
    {
        if (!$this->validateCsrfOrFail('/app/ventas')) {
            return;
        }

        // Asegura ENUM/status válidos antes de anular.
        try {
            new SalesDocumentService($this->db);
        } catch (\Throwable $e) {
        }
        
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $id = (int)$this->request->post('sale_id', 0);
        }
        if ($id <= 0) {
            $this->respond(false, 'No se recibió el ID de la venta a cancelar', '/app/ventas');
            return;
        }

        $reasonCode = trim((string)$this->request->post('cancel_reason', ''));
        $reasonOther = trim((string)$this->request->post('cancel_reason_other', ''));
        $invoiceLabel = '#' . $id;
        try {
            $row = $this->query("SELECT invoice_number, status FROM sales WHERE id = ?", [$id])->fetch();
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
                $this->accounting->enqueuePending(
                    'reverse_sale_cascade',
                    'sale',
                    $id,
                    $accountingError->getMessage(),
                    ['reason' => 'Venta cancelada: ' . $reasonLabel]
                );
                $msg .= ' IMPORTANTE: la reversión contable quedó en cola — use «Sincronizar» en Contabilidad.';
            }
            try {
                (new \SoftNova\Services\CommissionService($this->db))->cancelForSale($id);
            } catch (\Throwable $e) {
                error_log('Comisiones cancel venta ' . $id . ': ' . $e->getMessage());
            }
            try {
                $nDel = (new \SoftNova\Services\DeliveryCompanyExpenseService($this->db))
                    ->reverseForSale($id, 'Venta cancelada: ' . $reasonLabel);
                if ($nDel > 0) {
                    $msg .= ' Gasto transporte domicilio anulado.';
                }
            } catch (\Throwable $e) {
                error_log('Delivery expense reverse venta ' . $id . ': ' . $e->getMessage());
            }
            if (abs((float)$result['reversed_cash']) > 0.009) {
                $msg .= ' Caja ajustada: ' . $this->formatMoney((float)$result['reversed_cash']);
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
            if (!in_array($format, ['ticket', 'print'], true)) {
                $format = 'a4';
            }
            $sale = $this->query(
                "SELECT s.*, c.name as customer_name,
                        c.document_number as customer_document,
                        u.name as user_name
                 FROM sales s
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.id = ?",
                [$id]
            )->fetch();

            if (!$sale) {
                http_response_code(404);
                echo 'Venta no encontrada';
                exit;
            }

            try {
                $items = $this->query(
                    "SELECT si.*,
                            COALESCE(p.code, '') AS product_code,
                            COALESCE(p.barcode, '') AS barcode
                     FROM sale_items si
                     LEFT JOIN products p ON p.id = si.product_id
                     WHERE si.sale_id = ?
                       AND COALESCE(si.document_hidden, 0) = 0
                     ORDER BY si.id ASC",
                    [$id]
                )->fetchAll();
            } catch (\Throwable $e) {
                $items = $this->query(
                    "SELECT si.*,
                            COALESCE(p.code, '') AS product_code,
                            COALESCE(p.barcode, '') AS barcode
                     FROM sale_items si
                     LEFT JOIN products p ON p.id = si.product_id
                     WHERE si.sale_id = ?
                     ORDER BY si.id ASC",
                    [$id]
                )->fetchAll();
            }
            $payments = $this->query(
                "SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id ASC",
                [$id]
            )->fetchAll();

            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            if ($format === 'print') {
                header('Content-Type: text/html; charset=utf-8');
                echo $pdf->generateTicket58($sale, $items ?: [], $payments ?: [], true);
                exit;
            }
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
        $ticketUrl = $share->ticketPdfUrl($id);
        $this->json([
            'success' => true,
            'whatsapp_url' => $share->whatsappUrl($sale, null, $ticketUrl),
            'ticket_pdf_url' => $ticketUrl,
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
    
    /** Dompdf no escala con miles de filas: por encima de esto se recorta el PDF. */
    private const REPORT_PDF_MAX_SALES = 1200;
    /** Por encima de esto el PDF lista solo encabezados de venta (sin ítems). */
    private const REPORT_PDF_ITEMS_MAX_SALES = 250;

    /** @var array<string,string> */
    private array $payLabelCache = [];

    private function payMethodLabel(string $method): string
    {
        if (!isset($this->payLabelCache[$method])) {
            $this->payLabelCache[$method] = PaymentMethodCatalog::label($method, $this->db);
        }
        return $this->payLabelCache[$method];
    }

    /**
     * Libera el bloqueo de sesión (si no, el resto del ERP queda colgado mientras se genera)
     * y amplía límites para exportaciones grandes.
     */
    private function prepareExportResponse(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private function exportError(string $message, \Throwable $e): void
    {
        error_log($message . ': ' . $e->getMessage());
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message . '. ' . $this->clientErrorMessage($e, 'Intente con un rango de fechas menor.');
        exit;
    }

    private function reportPdf(): void
    {
        $this->prepareExportResponse();
        try {
            $filters = $this->salesListFilters();
            $sales = $this->fetchFilteredSales($filters, self::REPORT_PDF_MAX_SALES + 1);
            $truncated = count($sales) > self::REPORT_PDF_MAX_SALES;
            if ($truncated) {
                $sales = array_slice($sales, 0, self::REPORT_PDF_MAX_SALES);
            }
            $withItems = count($sales) <= self::REPORT_PDF_ITEMS_MAX_SALES;
            if ($withItems) {
                $sales = $this->attachSaleItems($sales);
            }
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateSalesListReport($sales, $filters, $this->db, [
                'with_items' => $withItems,
                'truncated' => $truncated,
                'items_max' => self::REPORT_PDF_ITEMS_MAX_SALES,
            ]);
            $pdf->download($content, 'reporte_ventas_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            $this->exportError('No se pudo generar el PDF de ventas', $e);
        }
    }

    /** Rango del resumen de ventas (sum_from / sum_to); por defecto hoy. */
    private function salesSummaryRange(): array
    {
        $today = date('Y-m-d');
        $validDay = static function (string $d): bool {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $d);
            return $dt && $dt->format('Y-m-d') === $d;
        };
        $from = (string)$this->request->get('sum_from', '');
        $to = (string)$this->request->get('sum_to', '');
        $from = $validDay($from) ? $from : $today;
        $to = $validDay($to) ? $to : $from;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    private function exportSalesSummary(string $format): void
    {
        $this->prepareExportResponse();
        [$from, $to] = $this->salesSummaryRange();
        try {
            $summary = \SoftNova\Services\SalesPeriodSummary::build($this->db, $from, $to);
            $summary['profit'] = (TenantMiddleware::canAccess('reportes') || TenantMiddleware::canAccess('contabilidad'))
                ? \SoftNova\Services\SalesPeriodSummary::profit($this->db, $from, $to)
                : null;
        } catch (\Throwable $e) {
            $this->exportError('No se pudo calcular el resumen de ventas', $e);
            return;
        }
        $suffix = $from === $to ? $from : $from . '_a_' . $to;

        if ($format === 'pdf') {
            try {
                $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
                $pdf->download($pdf->generateSalesPaymentSummary($summary), 'resumen_ventas_' . $suffix . '.pdf');
            } catch (\Throwable $e) {
                $this->exportError('No se pudo generar el PDF del resumen', $e);
            }
            return;
        }

        $total = (float)$summary['total'];
        $pct = static fn(float $v) => $total > 0.009 ? number_format(($v / $total) * 100, 1, ',', '') : '0';
        $money = static fn(float $v) => number_format($v, 2, ',', '');
        $rows = [];
        foreach ($summary['by_method'] as $pm) {
            $rows[] = [$pm['label'], (int)$pm['count'], $money((float)$pm['amount']), $pct((float)$pm['amount'])];
        }
        if ((float)$summary['pending'] > 0.009) {
            $rows[] = ['Por cobrar (crédito)', '', $money((float)$summary['pending']), $pct((float)$summary['pending'])];
        }
        $rows[] = ['Total facturado', (int)$summary['count'], $money($total), '100'];
        if ((float)$summary['discount'] > 0.009) {
            $rows[] = ['Descuentos aplicados', '', $money((float)$summary['discount']), ''];
        }
        $profit = $summary['profit'] ?? null;
        if (is_array($profit)) {
            $rows[] = ['', '', '', ''];
            $rows[] = ['Ventas netas (sin domicilio)', '', $money((float)$profit['revenue']), ''];
            $rows[] = ['Costo de ventas', '', $money((float)$profit['cost']), ''];
            $rows[] = ['Utilidad bruta', '', $money((float)$profit['profit']), number_format((float)$profit['margin_pct'], 1, ',', '')];
            if ((int)$profit['items_without_cost'] > 0) {
                $rows[] = ['Líneas sin costo registrado', (int)$profit['items_without_cost'], '', ''];
            }
        }
        $rows[] = ['Periodo', $from . ' a ' . $to, '', ''];

        $this->exportCsv(
            'resumen_ventas_' . $suffix . '.csv',
            ['Medio de pago', 'Ventas', 'Monto', '%'],
            $rows
        );
    }

    private function exportSales(): void
    {
        $this->prepareExportResponse();
        try {
            $filters = $this->salesListFilters();
            $rows = $this->attachSaleItems($this->fetchFilteredSales($filters, 10000));
        } catch (\Throwable $e) {
            $this->exportError('No se pudo exportar el CSV de ventas', $e);
            return;
        }

        $csvRows = [];
        foreach ($rows as $r) {
            $payLabel = $this->payMethodLabel((string)$r['payment_method']);
            $statusLabel = (($r['status'] ?? '') === 'cancelled')
                ? 'Cancelado'
                : ($r['payment_status'] === 'paid' ? 'Pagado' : ($r['payment_status'] === 'partial' ? 'Parcial' : 'Pendiente'));
            $csvRows[] = [
                'venta',
                $r['invoice_number'],
                $r['customer_name'],
                $r['sale_date'],
                '',
                '',
                '',
                '',
                '',
                $r['subtotal'],
                $r['tax'],
                $r['total'],
                $payLabel,
                $r['payment_status'],
                $statusLabel,
                $r['cancel_reason'] ?? '',
            ];
            foreach (($r['items'] ?? []) as $it) {
                $sku = (string)($it['product_code'] ?? '');
                if ($sku !== '' && preg_match('/^\d{11,}$/', $sku)) {
                    $sku = "\t" . $sku;
                }
                $ean = (string)($it['barcode'] ?? $it['product_barcode'] ?? '');
                if ($ean !== '' && preg_match('/^\d{11,}$/', $ean)) {
                    $ean = "\t" . $ean;
                }
                $csvRows[] = [
                    'item',
                    $r['invoice_number'],
                    $r['customer_name'],
                    $r['sale_date'],
                    $sku,
                    $ean,
                    $it['product_name'] ?? '',
                    $it['quantity'] ?? '',
                    $it['unit_price'] ?? '',
                    $it['subtotal'] ?? '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ];
            }
        }

        $this->exportCsv(
            'ventas_' . date('Ymd_His') . '.csv',
            [
                'Tipo', 'Factura', 'Cliente', 'Fecha', 'SKU', 'EAN', 'Producto', 'Cantidad', 'P. unitario',
                'Subtotal', 'Impuesto', 'Total', 'Metodo', 'Pago', 'Estado', 'Motivo cancelacion',
            ],
            $csvRows
        );
    }

    /**
     * Agrupa líneas del mismo producto_id sumando cantidades (comodín queda separado).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function aggregateCartItemsByProduct(array $items): array
    {
        $grouped = [];
        $openLines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = (int)($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            // Combos: product_id=0 + components — NUNCA descartar por product_id.
            $isCombo = !empty($item['is_combo'])
                || (string)($item['is_combo'] ?? '') === '1'
                || (string)($item['is_combo'] ?? '') === 'true'
                || (string)($item['product_type'] ?? '') === 'combo'
                || !empty($item['components_json'])
                || (isset($item['components']) && is_array($item['components']) && count($item['components']) >= 2
                    && (int)($item['product_id'] ?? 0) <= 0);
            if ($isCombo) {
                $openLines[] = $item;
                continue;
            }

            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $isOpen = !empty($item['is_open_item'])
                || (string)($item['is_open_item'] ?? '') === '1'
                || strtoupper((string)($item['code'] ?? '')) === 'COMODIN';
            if (!$isOpen) {
                try {
                    $probe = $this->query(
                        "SELECT id, code, COALESCE(product_type,'product') AS product_type FROM products WHERE id = ?",
                        [$productId]
                    )->fetch();
                    if ($probe && \SoftNova\Services\OpenItemProduct::isOpenItem($probe)) {
                        $isOpen = true;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            if ($isOpen) {
                $row = $item;
                $row['quantity'] = $quantity;
                $openLines[] = $row;
                continue;
            }
            if (!isset($grouped[$productId])) {
                $grouped[$productId] = $item;
                $grouped[$productId]['quantity'] = $quantity;
            } else {
                $prevQty = (int)$grouped[$productId]['quantity'];
                $prevPrice = (float)($grouped[$productId]['unit_price'] ?? 0);
                $newPrice = array_key_exists('unit_price', $item)
                    ? (float)$item['unit_price']
                    : $prevPrice;
                $totalQty = $prevQty + $quantity;
                if ($totalQty > 0 && (abs($prevPrice - $newPrice) > 0.0001)) {
                    // Precio ponderado si se mezclan líneas (p. ej. combo + venta suelta).
                    $grouped[$productId]['unit_price'] = round(
                        (($prevPrice * $prevQty) + ($newPrice * $quantity)) / $totalQty,
                        2
                    );
                    $grouped[$productId]['price_manual'] = '1';
                }
                $grouped[$productId]['quantity'] = $totalQty;
                $r = (string)($item['stock_oversell_reason'] ?? $item['oversell_reason'] ?? '');
                if ($r !== '') {
                    $grouped[$productId]['stock_oversell_reason'] = $r;
                    $grouped[$productId]['stock_oversell_note'] = (string)(
                        $item['stock_oversell_note'] ?? $item['oversell_note'] ?? ''
                    );
                }
            }
        }
        return array_merge(array_values($grouped), $openLines);
    }

    /**
     * Adjunta ítems de producto a cada venta (una sola consulta).
     *
     * @param list<array<string,mixed>> $sales
     * @return list<array<string,mixed>>
     */
    private function attachSaleItems(array $sales): array
    {
        if ($sales === []) {
            return $sales;
        }
        $ids = [];
        foreach ($sales as $s) {
            $id = (int)($s['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $sales;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $itemRows = $this->query(
            "SELECT si.sale_id, si.product_name, si.quantity, si.unit_price, si.subtotal,
                    COALESCE(p.code, '') AS product_code,
                    COALESCE(p.barcode, '') AS barcode
             FROM sale_items si
             LEFT JOIN products p ON p.id = si.product_id
             WHERE si.sale_id IN ({$ph})
             ORDER BY si.sale_id ASC, si.id ASC",
            $ids
        )->fetchAll();

        $bySale = [];
        foreach ($itemRows as $it) {
            $bySale[(int)$it['sale_id']][] = $it;
        }
        foreach ($sales as &$s) {
            $s['items'] = $bySale[(int)($s['id'] ?? 0)] ?? [];
        }
        unset($s);

        return $sales;
    }
}
