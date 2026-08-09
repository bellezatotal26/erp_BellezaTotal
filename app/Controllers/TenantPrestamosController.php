<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\ProductLoan;
use SoftNova\Services\StockService;

/**
 * Préstamos de productos: salidas no-venta con motivo obligatorio y devolución manual.
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
        // Requiere Ver el módulo (Crear sin Ver → solo botón en Caja).
        TenantMiddleware::authorize('prestamos');
        $this->stock = new StockService($this->db);
        // Evita DDL (lotes) dentro de transacciones de préstamo/devolución.
        $this->stock->lots();
    }

    public function index(): void
    {
        $action = $this->request->get('action');

        if ($action === 'loan' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'create');
            $this->createLoan();
            return;
        }
        if ($action === 'return' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('prestamos', 'edit');
            $this->returnLoan();
            return;
        }
        if ($action === 'searchProducts' && $this->request->method() === 'GET') {
            $this->searchProducts();
            return;
        }

        $alert = $this->stock->negativeStockAlertReport(60);
        $loans = $this->stock->listOpenLoans(250);
        $canCreate = TenantMiddleware::canDo('create', 'prestamos');
        $canEdit = TenantMiddleware::canDo('edit', 'prestamos');

        $products = $this->query(
            "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode
             FROM products
             WHERE status = 'active' AND product_type = 'product'
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
                    "SELECT id, code, name, stock FROM products
                     WHERE id = ? AND status = 'active' AND product_type = 'product' LIMIT 1",
                    [$preselectId]
                )->fetch();
                if ($extra) {
                    array_unshift($products, $extra);
                }
            }
        }

        $this->view('tenant.prestamos', $this->tenantViewData([
            'loans' => $loans,
            'stockAlert' => $alert,
            'products' => $products,
            'canCreate' => $canCreate,
            'canEdit' => $canEdit,
            'loanReasonsHtml' => ProductLoan::optionsHtml(),
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
            if ($mode === 'different' || $mode === 'other') {
                $returnProductId = (int)$this->request->post('return_product_id', 0);
                if ($returnProductId <= 0) {
                    $this->respond(false, 'Seleccione el producto que está devolviendo', '/app/prestamos');
                    return;
                }
            }
            $result = $this->stock->returnLoan($debtId, $qty, $returnProductId);
            $msg = 'Devolución registrada: ' . (int)$result['returned'] . ' uds. Stock: ' . (int)$result['stock_after'];
            if (!empty($result['substituted'])) {
                $msg .= ' (producto distinto al prestado).';
            }
            if ((int)$result['remaining'] > 0) {
                $msg .= ' Quedan ' . (int)$result['remaining'] . ' uds pendientes.';
            }
            $this->respond(true, $msg, '/app/prestamos');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/prestamos');
        }
    }

    private function searchProducts(): void
    {
        $q = trim((string)$this->request->get('q', ''));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }
        $like = '%' . $q . '%';
        $exact = $q;
        $rows = $this->query(
            "SELECT id, code, name, stock, COALESCE(barcode,'') AS barcode
             FROM products
             WHERE status = 'active' AND product_type = 'product'
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
}
