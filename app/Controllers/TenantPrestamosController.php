<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\ConsignmentService;
use SoftNova\Services\LoanTraceService;
use SoftNova\Services\ProductLoan;
use SoftNova\Services\StockService;

/**
 * Préstamos de productos:
 * - Salida: nosotros prestamos (source=loan).
 * - Recibido: nos prestan (ownership=borrowed, source=loan_received); no es consignación.
 * Quien solo tiene "Crear" registra desde Caja (sin ver este módulo), igual que Gastos.
 */
class TenantPrestamosController extends TenantController
{
    private StockService $stock;

    public function __construct()
    {
        parent::__construct();
        if (TenantMiddleware::isPosRole()) {
            $_SESSION['error'] = 'El rol POS registra préstamos desde Caja, no desde este módulo.';
            $this->redirect(\SoftNova\Core\base_url('/app/caja'));
            return;
        }
        TenantMiddleware::authorize('prestamos');
        $this->stock = new StockService($this->db);
        $this->stock->lots();
        ProductLoan::syncCatalog($this->db);
        ProductLoan::syncReceivedCatalog($this->db);
        try {
            new ConsignmentService($this->db);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function index(): void
    {
        $action = $this->request->get('action');

        if ($action === 'loan' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'create');
            $this->createLoan();
            return;
        }
        if ($action === 'loan_received' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'create');
            $this->createLoanReceived();
            return;
        }
        if ($action === 'return' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'edit');
            $this->returnLoan();
            return;
        }
        if ($action === 'return_received' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'edit');
            $this->returnLoanReceived();
            return;
        }
        if ($action === 'searchProducts' && $this->request->method() === 'GET') {
            $this->searchProducts();
            return;
        }
        if ($action === 'createReturnProduct' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'edit');
            $this->createReturnProduct();
            return;
        }
        if ($action === 'history' && $this->request->method() === 'GET') {
            $history = (new LoanTraceService($this->db))->history((int)$this->request->get('id', 0));
            $this->json($history
                ? ['success' => true] + $history
                : ['success' => false, 'message' => 'Préstamo no encontrado']);
            return;
        }

        $tab = $this->request->get('tab') === 'traza' ? 'traza' : 'loans';
        $filters = LoanTraceService::normalizeFilters($_GET, $tab === 'traza' ? 'all' : 'pending');
        $trace = new LoanTraceService($this->db);
        $loans = [];
        $receivedLoans = [];
        $loansTotal = 0;
        $movements = ['rows' => [], 'total' => 0];
        if ($tab === 'traza') {
            $movements = $trace->movements($filters, 500);
        } else {
            $list = $trace->listLoans($filters, 400);
            $loansTotal = $list['total'];
            foreach ($list['rows'] as $row) {
                if ((string)$row['source'] === ProductLoan::SOURCE_RECEIVED) {
                    $receivedLoans[] = $row;
                } else {
                    $loans[] = $row;
                }
            }
        }

        $alert = $this->stock->negativeStockAlertReport(60);
        $canCreate = TenantMiddleware::canDo('create', 'prestamos');
        $canEdit = TenantMiddleware::canDo('edit', 'prestamos');

        $ownedSql = ConsignmentService::ownedOnlySql('products');
        $products = $this->query(
            "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode,
                    COALESCE(ownership,'owned') AS ownership
             FROM products
             WHERE status = 'active' AND product_type = 'product'
               AND {$ownedSql}
             ORDER BY name ASC
             LIMIT 400"
        )->fetchAll();

        $preselectId = (int)$this->request->get('product_id', 0);
        if ($preselectId > 0) {
            $found = false;
            foreach ($products as $p) {
                if ((int)$p['id'] === $preselectId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $extra = $this->query(
                    "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode,
                            COALESCE(ownership,'owned') AS ownership
                     FROM products
                     WHERE id = ? AND status = 'active' AND product_type = 'product' LIMIT 1",
                    [$preselectId]
                )->fetch();
                if ($extra && ($extra['ownership'] ?? 'owned') === 'owned') {
                    array_unshift($products, $extra);
                }
            }
        }

        $this->view('tenant.prestamos', $this->tenantViewData([
            'loans' => $loans,
            'receivedLoans' => $receivedLoans,
            'loansTotal' => $loansTotal,
            'movements' => $movements,
            'tab' => $tab,
            'filters' => $filters,
            'filterOptions' => $trace->filterOptions(),
            'loanSummary' => $trace->summary(),
            'stockAlert' => $alert,
            'products' => $products,
            'canCreate' => $canCreate,
            'canEdit' => $canEdit,
            'receivedReasonsHtml' => ProductLoan::receivedOptionsHtml(null, $this->db),
            'loanReasonsHtml' => ProductLoan::optionsHtml(null, $this->db),
        ]));
    }

    private function createLoan(): void
    {
        if (!$this->validateCsrfOrFail('/app/prestamos')) {
            return;
        }
        try {
            $productId = (int)$this->request->post('product_id', 0);
            $qty = (int)$this->request->post('quantity', 0);
            $result = $this->stock->registerLoan(
                $productId,
                $qty,
                (string)$this->request->post('reason', ''),
                (string)$this->request->post('note', ''),
                (string)$this->request->post('borrower', '')
            );
            $msg = 'Préstamo registrado. Stock actual: ' . (int)$result['stock_after'];
            if ((int)$result['stock_after'] < 0) {
                $msg .= ' (inventario en negativo — pendiente de devolución/reposición).';
            }
            $this->respond(true, $msg, '/app/prestamos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/prestamos');
        }
    }

    private function createLoanReceived(): void
    {
        if (!$this->validateCsrfOrFail('/app/prestamos')) {
            return;
        }
        try {
            $productId = (int)$this->request->post('product_id', 0);
            $result = $this->stock->registerLoanReceived(
                (int)$this->request->post('quantity', 0),
                (string)$this->request->post('reason', ''),
                (string)$this->request->post('note', ''),
                (string)$this->request->post('lender', ''),
                $productId > 0 ? $productId : null,
                (string)$this->request->post('new_name', ''),
                (string)$this->request->post('new_code', ''),
                (string)$this->request->post('new_barcode', ''),
                \SoftNova\Core\parse_money($this->request->post('unit_cost', 0))
            );
            $msg = !empty($result['created_product'])
                ? 'Producto prestado ingresado al inventario. Stock: ' . (int)$result['stock_after']
                : 'Préstamo recibido registrado. Stock: ' . (int)$result['stock_after'];
            $this->respond(true, $msg, '/app/prestamos#prestamos-recibidos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/prestamos');
        }
    }

    private function returnLoan(): void
    {
        if (!$this->validateCsrfOrFail('/app/prestamos')) {
            return;
        }
        try {
            $debtId = (int)$this->request->post('debt_id', 0);
            $qty = (int)$this->request->post('quantity', 0);
            $mode = strtolower(trim((string)$this->request->post('return_mode', 'same')));
            $returnProductId = null;
            $returnLines = null;

            if ($mode === 'different' || $mode === 'other') {
                $ids = $this->request->post('return_line_product_id', []);
                $qtys = $this->request->post('return_line_qty', []);
                if (!is_array($ids)) {
                    $ids = [];
                }
                if (!is_array($qtys)) {
                    $qtys = [];
                }
                $returnLines = [];
                $n = max(count($ids), count($qtys));
                for ($i = 0; $i < $n; $i++) {
                    $pid = (int)($ids[$i] ?? 0);
                    $q = (int)($qtys[$i] ?? 0);
                    if ($pid > 0 && $q > 0) {
                        $returnLines[] = ['product_id' => $pid, 'quantity' => $q];
                    }
                }
                if ($returnLines === []) {
                    // Compatibilidad: un solo producto (campo legacy).
                    $returnProductId = (int)$this->request->post('return_product_id', 0);
                    if ($returnProductId <= 0) {
                        $this->respond(false, 'Agregue al menos un producto que están devolviendo', '/app/prestamos');
                        return;
                    }
                } else {
                    $qty = 0;
                    foreach ($returnLines as $line) {
                        $qty += (int)$line['quantity'];
                    }
                }
            }

            $result = $this->stock->returnLoan($debtId, $qty, $returnProductId, $returnLines);
            $lines = $result['lines'] ?? [];
            if (count($lines) > 1) {
                $parts = [];
                foreach ($lines as $ln) {
                    $parts[] = ((string)($ln['label'] ?? ('#' . ($ln['product_id'] ?? ''))))
                        . ' ×' . (int)($ln['quantity'] ?? 0);
                }
                $msg = 'Devolución registrada: ' . (int)$result['returned'] . ' uds en '
                    . count($lines) . ' referencias (' . implode('; ', $parts) . ').';
            } else {
                $before = (int)($result['stock_before'] ?? 0);
                $after = (int)$result['stock_after'];
                $msg = 'Devolución registrada: ' . (int)$result['returned'] . ' uds. '
                    . 'Stock ' . $before . ' → ' . $after . '.';
                if (!empty($result['substituted'])) {
                    $msg .= ' (producto distinto al prestado).';
                }
            }
            if ((int)$result['remaining'] > 0) {
                $msg .= ' Quedan ' . (int)$result['remaining'] . ' uds pendientes.';
            }
            $this->respond(true, $msg, '/app/prestamos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/prestamos');
        }
    }

    private function returnLoanReceived(): void
    {
        if (!$this->validateCsrfOrFail('/app/prestamos')) {
            return;
        }
        try {
            $allowSettle = (string)$this->request->post('settle_without_stock', '') === '1';
            $result = $this->stock->returnLoanReceived(
                (int)$this->request->post('debt_id', 0),
                (int)$this->request->post('quantity', 0),
                $allowSettle
            );
            $settle = (int)($result['settled_without_stock'] ?? 0);
            $physical = (int)($result['physical_out'] ?? 0);
            $before = (int)($result['stock_before'] ?? 0);
            $after = (int)$result['stock_after'];
            $deltaNote = ' Stock ' . $before . ' → ' . $after . '.';
            if ($settle > 0 && $physical <= 0) {
                $msg = 'Préstamo recibido cerrado sin devolución física (' . (int)$result['returned']
                    . ' uds ya vendidas/usadas).' . $deltaNote;
            } elseif ($settle > 0) {
                $msg = 'Devolución: ' . $physical . ' uds físicas + ' . $settle
                    . ' uds liquidadas sin stock.' . $deltaNote;
            } else {
                $msg = 'Devolución al prestamista: ' . (int)$result['returned']
                    . ' uds.' . $deltaNote;
            }
            if ((int)$result['remaining'] > 0) {
                $msg .= ' Quedan ' . (int)$result['remaining'] . ' uds por devolver.';
            }
            $this->respond(true, $msg, '/app/prestamos#prestamos-recibidos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/prestamos');
        }
    }

    private function searchProducts(): void
    {
        $q = trim((string)$this->request->get('q', ''));
        $mode = strtolower(trim((string)$this->request->get('mode', 'owned')));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }
        $like = '%' . $q . '%';
        $exact = $q;
        if ($mode === 'borrowed') {
            $ownFilter = "AND COALESCE(ownership,'owned') = 'borrowed'";
        } elseif ($mode === 'all' || $mode === 'inventory') {
            // Inventario completo excepto consignación (esa va al módulo Consignación).
            $ownFilter = "AND COALESCE(ownership,'owned') IN ('owned','borrowed')";
        } else {
            $ownFilter = "AND COALESCE(ownership,'owned') = 'owned'";
        }
        $rows = $this->query(
            "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode,
                    COALESCE(ownership,'owned') AS ownership,
                    COALESCE(purchase_price, 0) AS purchase_price
             FROM products
             WHERE status = 'active' AND product_type = 'product'
               {$ownFilter}
               AND (
                    code = ?
                    OR barcode = ?
                    OR name LIKE ?
                    OR code LIKE ?
                    OR barcode LIKE ?
               )
             ORDER BY
                CASE
                    WHEN code = ? THEN 0
                    WHEN barcode = ? THEN 1
                    WHEN code LIKE ? THEN 2
                    ELSE 3
                END,
                name ASC
             LIMIT 25",
            [$exact, $exact, $like, $like, $like, $exact, $exact, $exact . '%']
        )->fetchAll();
        $this->json(['success' => true, 'products' => $rows]);
    }

    private function createReturnProduct(): void
    {
        if (!$this->validateCsrfOrFail('/app/prestamos')) {
            return;
        }
        $name = trim((string)$this->request->post('name', ''));
        $barcode = trim((string)$this->request->post('barcode', ''));
        $code = trim((string)$this->request->post('code', ''));
        if ($name === '') {
            $this->json(['success' => false, 'message' => 'Indique el nombre del producto']);
            return;
        }
        try {
            if ($code === '') {
                try {
                    $code = (string)((new \SoftNova\Services\SkuSequenceService($this->db))->allocateNext()['sku'] ?? '');
                } catch (\Throwable $e) {
                    $code = 'DEV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                }
            }
            if ($code === '') {
                $code = 'DEV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            }
            $ownsCol = false;
            try {
                $ownsCol = (bool)$this->db->query("SHOW COLUMNS FROM products LIKE 'ownership'")->fetch();
            } catch (\Throwable $e) {
                $ownsCol = false;
            }
            if ($ownsCol) {
                $this->query(
                    "INSERT INTO products
                        (code, barcode, name, product_type, ownership, purchase_price, sale_price, tax_rate, stock, status, created_by)
                     VALUES (?, ?, ?, 'product', 'owned', 0, 0, 0, 0, 'active', ?)",
                    [
                        $code,
                        $barcode !== '' ? $barcode : null,
                        $name,
                        $_SESSION['tenant_user_id'] ?? null,
                    ]
                );
            } else {
                $this->query(
                    "INSERT INTO products (code, barcode, name, product_type, purchase_price, sale_price, tax_rate, stock, status, created_by)
                     VALUES (?, ?, ?, 'product', 0, 0, 0, 0, 'active', ?)",
                    [
                        $code,
                        $barcode !== '' ? $barcode : null,
                        $name,
                        $_SESSION['tenant_user_id'] ?? null,
                    ]
                );
            }
            $id = (int)$this->db->lastInsertId();
            $this->json([
                'success' => true,
                'product' => [
                    'id' => $id,
                    'code' => $code,
                    'name' => $name,
                    'barcode' => $barcode,
                    'stock' => 0,
                    'ownership' => 'owned',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
