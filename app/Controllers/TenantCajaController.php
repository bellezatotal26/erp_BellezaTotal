<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\SupplyChainSchema;
use function SoftNova\Core\base_url;
use function SoftNova\Core\config;

class TenantCajaController extends TenantController
{
    public function index(): void
    {
        TenantMiddleware::authorize('caja');
        SupplyChainSchema::ensure($this->db);
        
        $action = $this->request->get('action');
        
        if ($action === 'open' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->openCash();
            return;
        }
        if ($action === 'close' && $this->request->method() === 'POST') {
            try {
                if (!TenantMiddleware::canAccess('caja') || !TenantMiddleware::canDo('edit', 'caja')) {
                    $this->json([
                        'success' => false,
                        'message' => 'No tienes permisos para cerrar la caja',
                        'redirect' => base_url(TenantMiddleware::homePath()),
                    ]);
                    return;
                }
                $this->closeCash();
            } catch (\Throwable $e) {
                error_log('caja close action: ' . $e->getMessage());
                $this->json([
                    'success' => false,
                    'message' => 'Error al cerrar caja: ' . $e->getMessage(),
                    'redirect' => base_url('/app/caja'),
                ]);
            }
            return;
        }
        if ($action === 'movement' && $this->request->method() === 'POST') {
            TenantMiddleware::authorize('caja', 'create');
            $this->addMovement();
            return;
        }
        if ($action === 'pdf' && $this->request->method() === 'GET') {
            $this->closingPdf();
            return;
        }
        if ($action === 'report_whatsapp' && $this->request->method() === 'GET') {
            $this->reportWhatsapp();
            return;
        }
        if ($action === 'report_email' && $this->request->method() === 'POST') {
            $this->reportEmail();
            return;
        }
        if ($action === 'searchProducts' && $this->request->method() === 'GET') {
            $this->searchProducts();
            return;
        }
        if ($action === 'catalogSnapshot' && $this->request->method() === 'GET') {
            $this->catalogSnapshot();
            return;
        }

        // Cierra cajas abiertas cuyo dia de apertura ya termino (pasada medianoche)
        $autoClosed = $this->autoCloseOvernightSessions();
        
        $openSession = $this->query(
            "SELECT cs.*, u.name as user_name
             FROM cash_sessions cs
             JOIN users u ON cs.user_id = u.id
             WHERE cs.status = 'open'
             ORDER BY cs.opening_date DESC LIMIT 1"
        )->fetch();
        
        $movements = [];
        $todaySales = [];
        $salesPagination = null;
        $totals = ['incomes' => 0, 'expenses' => 0, 'balance' => 0, 'sales_count' => 0, 'sales_total' => 0, 'movements_count' => 0];
        $isPosRole = TenantMiddleware::isPosRole();
        
        if ($openSession) {
            $totals = $this->computeSessionTotals($openSession);
            $movements = $this->query(
                "SELECT * FROM cash_movements
                 WHERE cash_session_id = ?
                 ORDER BY created_at DESC",
                [$openSession['id']]
            )->fetchAll();

            $salesTotal = (int)$this->query(
                "SELECT COUNT(*) FROM sales WHERE sale_date >= ? AND status != 'cancelled'",
                [$openSession['opening_date']]
            )->fetchColumn();
            $salesPagination = $this->paginate($salesTotal, 15);
            $todaySales = $this->query(
                "SELECT s.id, s.invoice_number, s.total, s.sale_date, s.payment_method,
                        s.payment_status, s.status,
                        c.name as customer_name, u.name as user_name,
                        COALESCE((SELECT SUM(sp.amount) FROM sale_payments sp WHERE sp.sale_id = s.id), 0) as paid_amount
                 FROM sales s
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.sale_date >= ?
                 ORDER BY s.sale_date DESC
                 LIMIT {$salesPagination['perPage']} OFFSET {$salesPagination['offset']}",
                [$openSession['opening_date']]
            )->fetchAll();
        }

        $historyFrom = trim((string)$this->request->get('history_from', ''));
        $historyTo = trim((string)$this->request->get('history_to', ''));
        if ($historyFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyFrom)) {
            $historyFrom = date('Y-m-d', strtotime('-30 days'));
        }
        if ($historyTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyTo)) {
            $historyTo = date('Y-m-d');
        }
        if ($historyFrom > $historyTo) {
            $tmp = $historyFrom;
            $historyFrom = $historyTo;
            $historyTo = $tmp;
        }

        $historyWhere = ["cs.status = 'closed'", 'DATE(cs.closing_date) >= ?', 'DATE(cs.closing_date) <= ?'];
        $historyParams = [$historyFrom, $historyTo];
        if ($isPosRole) {
            $historyWhere[] = 'cs.user_id = ?';
            $historyParams[] = (int)($_SESSION['tenant_user_id'] ?? 0);
        }
        $historySessions = $this->query(
            "SELECT cs.*, u.name as user_name
             FROM cash_sessions cs
             JOIN users u ON cs.user_id = u.id
             WHERE " . implode(' AND ', $historyWhere) . "
             ORDER BY cs.closing_date DESC
             LIMIT 100",
            $historyParams
        )->fetchAll();

        $posCustomers = [];
        $paymentMethods = [];
        $recentProducts = [];
        $financialCats = [];
        $operationalCats = [];
        $expenseSuppliers = [];
        if ($openSession) {
            try {
                $posCustomers = $this->query(
                    "SELECT id, name, first_name, last_name, document_number, phone
                     FROM customers
                     WHERE status = 'active'
                     ORDER BY COALESCE(NULLIF(TRIM(name), ''), first_name) ASC
                     LIMIT 500"
                )->fetchAll();
            } catch (\Throwable $e) {
                $posCustomers = [];
            }
            $paymentMethods = \SoftNova\Services\PaymentMethodCatalog::all();
            try {
                $recentProducts = $this->query(
                    "SELECT p.id, p.name, p.code, p.sale_price, p.stock
                     FROM products p
                     WHERE p.status = 'active'
                     ORDER BY p.updated_at DESC, p.id DESC
                     LIMIT 12"
                )->fetchAll();
            } catch (\Throwable $e) {
                $recentProducts = $this->query(
                    "SELECT id, name, code, sale_price, stock FROM products WHERE status = 'active' ORDER BY id DESC LIMIT 12"
                )->fetchAll();
            }

            if (!$isPosRole && TenantMiddleware::canAccess('gastos') && TenantMiddleware::canDo('create', 'gastos')) {
                try {
                    $catSvc = new \SoftNova\Services\ExpenseCategoryService($this->db);
                    $financialCats = $catSvc->listActive('financial');
                    $operationalCats = $catSvc->listActive('operational');
                } catch (\Throwable $e) {
                    $financialCats = [];
                    $operationalCats = [];
                }
                try {
                    $expenseSuppliers = $this->query(
                        "SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name LIMIT 300"
                    )->fetchAll();
                } catch (\Throwable $e) {
                    $expenseSuppliers = [];
                }
            }
        }

        if ($autoClosed > 0 && empty($_SESSION['success'])) {
            $_SESSION['success'] = $autoClosed === 1
                ? 'Se cerro automaticamente 1 caja al pasar la medianoche.'
                : "Se cerraron automaticamente {$autoClosed} cajas al pasar la medianoche.";
        }
        
        $company = $this->companySettings();
        $this->view('tenant.caja', $this->tenantViewData([
            'openSession' => $openSession,
            'movements' => $movements,
            'todaySales' => $todaySales,
            'salesPagination' => $salesPagination,
            'totals' => $totals,
            'historySessions' => $historySessions,
            'historyFrom' => $historyFrom,
            'historyTo' => $historyTo,
            'posCustomers' => $posCustomers,
            'paymentMethods' => $paymentMethods,
            'recentProducts' => $recentProducts,
            'financialCats' => $financialCats,
            'operationalCats' => $operationalCats,
            'expenseSuppliers' => $expenseSuppliers,
            'isPosRole' => $isPosRole,
            'companyEmail' => (string)($company['company_email'] ?? ''),
            'invoicePrefix' => $this->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix' LIMIT 1"
            )->fetchColumn() ?: 'FAC-',
            'formatMoney' => fn($a) => $this->formatMoney($a),
        ]));
    }

    /**
     * Búsqueda rápida de productos para el POS de caja (código / nombre / barras).
     */
    private function searchProducts(): void
    {
        TenantMiddleware::authorize('caja');
        $q = trim((string)$this->request->get('q', ''));
        if (mb_strlen($q) < 1) {
            $this->json(['success' => true, 'products' => []]);
            return;
        }

        $like = '%' . $q . '%';
        $exact = $q;
        try {
            $rows = $this->query(
                "SELECT id, name, code, sale_price, stock, min_stock
                 FROM products
                 WHERE status = 'active'
                   AND (
                        code = ?
                        OR code LIKE ?
                        OR name LIKE ?
                        OR CAST(id AS CHAR) = ?
                   )
                 ORDER BY
                    CASE
                        WHEN code = ? THEN 0
                        WHEN code LIKE ? THEN 1
                        WHEN name LIKE ? THEN 2
                        ELSE 3
                    END,
                    name ASC
                 LIMIT 25",
                [$exact, $like, $like, $exact, $exact, $exact . '%', $like]
            )->fetchAll();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'No se pudo buscar productos', 'products' => []]);
            return;
        }

        $products = array_map(static function (array $p): array {
            return [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'code' => (string)($p['code'] ?? ''),
                'sale_price' => (float)$p['sale_price'],
                'stock' => (int)$p['stock'],
            ];
        }, $rows);

        $this->json(['success' => true, 'products' => $products]);
    }

    /**
     * Snapshot de catálogo para el POS offline (IndexedDB / búsqueda degradada).
     */
    private function catalogSnapshot(): void
    {
        TenantMiddleware::authorize('caja');
        $limit = max(100, min(5000, (int)$this->request->get('limit', 2500)));

        try {
            $rows = $this->query(
                "SELECT id, name, code, sale_price, stock, COALESCE(product_type, 'product') AS product_type
                 FROM products
                 WHERE status = 'active'
                 ORDER BY name ASC
                 LIMIT {$limit}"
            )->fetchAll();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'No se pudo cargar el catálogo', 'products' => []]);
            return;
        }

        $products = array_map(static function (array $p): array {
            return [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'code' => (string)($p['code'] ?? ''),
                'sale_price' => (float)$p['sale_price'],
                'stock' => (int)$p['stock'],
                'product_type' => (string)($p['product_type'] ?? 'product'),
            ];
        }, $rows);

        $this->json([
            'success' => true,
            'products' => $products,
            'synced_at' => date('c'),
            'count' => count($products),
        ]);
    }
    
    private function openCash(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        
        $existing = $this->query("SELECT id FROM cash_sessions WHERE status = 'open' LIMIT 1")->fetch();
        if ($existing) {
            $this->respond(false, 'Ya existe una caja abierta. Debe cerrarla primero.', '/app/caja');
            return;
        }
        
        $openingAmount = (float)$this->request->post('opening_amount');
        $notes = $this->request->post('notes', '');
        
        if ($openingAmount < 0) {
            $this->respond(false, 'El monto de apertura no puede ser negativo', '/app/caja');
            return;
        }
        
        $this->query(
            "INSERT INTO cash_sessions (user_id, opening_amount, opening_date, status, notes)
             VALUES (?, ?, NOW(), 'open', ?)",
            [$_SESSION['tenant_user_id'], $openingAmount, $notes]
        );
        
        $this->respond(true, 'Caja abierta exitosamente con ' . $this->formatMoney($openingAmount), '/app/caja');
    }
    
    private function closeCash(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }

        $sessionId = (int)$this->request->post('session_id', 0);
        $closingAmount = (float)$this->request->post('closing_amount', 0);
        $notes = trim((string)$this->request->post('notes', ''));

        if ($sessionId <= 0) {
            $this->json([
                'success' => false,
                'message' => 'Sesion de caja invalida',
                'redirect' => base_url('/app/caja'),
            ]);
            return;
        }

        $session = $this->query(
            "SELECT id, notes, opening_amount, opening_date, user_id
             FROM cash_sessions WHERE id = ? AND status = 'open' LIMIT 1",
            [$sessionId]
        )->fetch();

        if (!$session) {
            $this->json([
                'success' => false,
                'message' => 'Sesion de caja no encontrada o ya esta cerrada',
                'redirect' => base_url('/app/caja'),
            ]);
            return;
        }

        $prevNotes = (string)($session['notes'] ?? '');
        $noteLine = $notes !== '' ? ('Cierre: ' . $notes) : 'Cierre manual';
        $newNotes = trim($prevNotes . ($prevNotes !== '' ? "\n" : '') . $noteLine);

        $expectedCash = $this->computeExpectedCash($session);
        $difference = round($closingAmount - $expectedCash, 2);

        $stmt = $this->query(
            "UPDATE cash_sessions
             SET closing_amount = ?, closing_date = NOW(), status = 'closed', notes = ?,
                 expected_amount = ?, difference_amount = ?
             WHERE id = ? AND status = 'open'",
            [$closingAmount, $newNotes, $expectedCash, $difference, $sessionId]
        );

        if ($stmt->rowCount() < 1) {
            $this->json([
                'success' => false,
                'message' => 'La caja ya fue cerrada por otro usuario',
                'redirect' => base_url('/app/caja'),
            ]);
            return;
        }

        $this->postCashClosingAccounting($sessionId);

        $base = rtrim((string)config('app.url', ''), '/');
        $pdfUrl = $base . '/app/caja?action=pdf&id=' . $sessionId;

        $message = 'Caja cerrada correctamente.';
        if (abs($difference) >= 0.01) {
            $message .= $difference < 0
                ? ' Faltante de ' . $this->formatMoney(abs($difference)) . '.'
                : ' Sobrante de ' . $this->formatMoney($difference) . '.';
        }

        $this->json([
            'success' => true,
            'message' => $message,
            'redirect' => base_url('/app/caja?share_close=' . $sessionId),
            'pdf_url' => $pdfUrl,
            'session_id' => $sessionId,
            'expected_cash' => $expectedCash,
            'difference' => $difference,
        ]);
    }

    /**
     * Efectivo que debería haber en el cajón: base de apertura más los
     * movimientos de efectivo, más las ventas en efectivo que no quedaron
     * registradas como movimiento.
     */
    private function computeExpectedCash(array $session, ?string $until = null): float
    {
        $sessionId = (int)$session['id'];
        $opening = (float)($session['opening_amount'] ?? 0);

        $movTotals = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) incomes,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) expenses
             FROM cash_movements
             WHERE cash_session_id = ?",
            [$sessionId]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0];

        $cash = $opening + (float)$movTotals['incomes'] - (float)$movTotals['expenses'];

        // Ventas en efectivo sin movimiento de caja asociado (evita doble conteo).
        $params = [$sessionId, $sessionId, (string)($session['opening_date'] ?? '')];
        $untilSql = '';
        if ($until) {
            $untilSql = ' AND s.sale_date <= ?';
            $params[] = $until;
        }

        $orphanCashSales = $this->query(
            "SELECT COALESCE(SUM(s.total), 0)
             FROM sales s
             WHERE s.status != 'cancelled'
               AND s.id NOT IN (
                    SELECT COALESCE(reference_id, 0) FROM cash_movements
                    WHERE cash_session_id = ? AND reference_type = 'sale'
               )
               AND s.id NOT IN (
                    SELECT COALESCE(reference_id, 0) FROM cash_movements
                    WHERE cash_session_id = ? AND reference_type = 'sale_payment'
               )
               AND s.sale_date >= ?" . $untilSql . "
               AND COALESCE(s.payment_method, 'cash') IN ('cash', '')",
            $params
        )->fetchColumn();

        return round($cash + (float)$orphanCashSales, 2);
    }

    /** El asiento del arqueo no debe impedir el cierre de la caja. */
    private function postCashClosingAccounting(int $sessionId): void
    {
        try {
            (new \SoftNova\Services\AccountingService($this->db))->postCashSessionClosing($sessionId);
        } catch (\Throwable $e) {
            error_log('Contabilidad arqueo caja ' . $sessionId . ': ' . $e->getMessage());
        }
    }
    
    private function addMovement(): void
    {
        if (TenantMiddleware::isPosRole()) {
            $this->respond(false, 'El rol POS no puede registrar movimientos manuales de caja', '/app/caja');
            return;
        }
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        
        $sessionId = (int)$this->request->post('session_id');
        $type = $this->request->post('type');
        $amount = (float)$this->request->post('amount');
        $description = $this->request->post('description', '');
        
        if (!in_array($type, ['income', 'expense'], true)) {
            $this->respond(false, 'Tipo de movimiento invalido', '/app/caja');
            return;
        }
        
        if ($amount <= 0) {
            $this->respond(false, 'El monto debe ser mayor a cero', '/app/caja');
            return;
        }
        
        if (empty(trim($description))) {
            $this->respond(false, 'La descripcion es requerida', '/app/caja');
            return;
        }
        
        $session = $this->query(
            "SELECT id FROM cash_sessions WHERE id = ? AND status = 'open'",
            [$sessionId]
        )->fetch();
        
        if (!$session) {
            $this->respond(false, 'La caja no esta abierta', '/app/caja');
            return;
        }
        
        $this->query(
            "INSERT INTO cash_movements (cash_session_id, type, amount, description, reference_type, user_id, created_at)
             VALUES (?, ?, ?, ?, 'manual', ?, NOW())",
            [$sessionId, $type, $amount, $description, $_SESSION['tenant_user_id'] ?? null]
        );

        $movementId = (int)$this->db->lastInsertId();
        try {
            (new \SoftNova\Services\AccountingService($this->db))->postCashMovement($movementId);
        } catch (\Throwable $e) {
            error_log('Contabilidad movimiento caja ' . $movementId . ': ' . $e->getMessage());
        }
        
        $label = $type === 'income' ? 'Ingreso' : 'Egreso';
        $this->respond(true, "{$label} registrado: " . $this->formatMoney($amount), '/app/caja');
    }
    
    private function closingPdf(): void
    {
        TenantMiddleware::authorize('caja');

        $id = (int)$this->request->get('id');
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            http_response_code(404);
            echo 'Sesion no encontrada';
            exit;
        }

        try {
            $pdf = new \SoftNova\Services\PdfService($this->companySettings(), $this->getCurrency());
            $content = $pdf->generateCashClosing(
                $report['session'],
                $report['movements'],
                $report['totals'],
                $report['sales']
            );
            $pdf->download($content, 'Cierre_Caja_' . $id . '_' . date('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            error_log('closingPdf: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No se pudo generar el PDF: ' . $e->getMessage();
            exit;
        }
    }

    /** WhatsApp con resumen del cierre (el PDF se descarga aparte). */
    private function reportWhatsapp(): void
    {
        TenantMiddleware::authorize('caja');
        $id = (int)$this->request->get('id', 0);
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            $this->json(['success' => false, 'message' => 'Sesión de caja no encontrada']);
            return;
        }

        $phone = preg_replace('/\D+/', '', (string)$this->request->get('phone', ''));
        if (strlen($phone) === 10) {
            $phone = '57' . $phone;
        }

        $text = $this->closingWhatsappText($report);
        $link = $phone !== ''
            ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($text)
            : 'https://wa.me/?text=' . rawurlencode($text);

        $this->json([
            'success' => true,
            'link' => $link,
            'pdf_url' => rtrim((string)config('app.url', ''), '/') . '/app/caja?action=pdf&id=' . $id,
        ]);
    }

    /** Envía el PDF de cierre por correo. */
    private function reportEmail(): void
    {
        if (!$this->validateCsrfOrFail('/app/caja')) {
            return;
        }
        TenantMiddleware::authorize('caja');

        $id = (int)$this->request->post('id', 0);
        $to = trim((string)$this->request->post('email', ''));
        $report = $this->loadClosingReport($id);
        if ($report === null) {
            $this->respond(false, 'Sesión de caja no encontrada', '/app/caja');
            return;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->respond(false, 'Indique un correo válido', '/app/caja?share_close=' . $id);
            return;
        }

        $company = $this->companySettings();
        $empresa = (string)($company['company_name'] ?? 'Seri ERP');
        $session = $report['session'];
        $filename = 'Cierre_Caja_' . $id . '.pdf';
        $temporal = null;

        try {
            $pdf = new \SoftNova\Services\PdfService($company, $this->getCurrency());
            $content = $pdf->generateCashClosing(
                $session,
                $report['movements'],
                $report['totals'],
                $report['sales']
            );
            $temporal = tempnam(sys_get_temp_dir(), 'caja-') . '.pdf';
            file_put_contents($temporal, $content);

            $apertura = !empty($session['opening_date'])
                ? date('d/m/Y H:i', strtotime((string)$session['opening_date']))
                : '—';
            $cierre = !empty($session['closing_date'])
                ? date('d/m/Y H:i', strtotime((string)$session['closing_date']))
                : 'En curso';
            $body = "Reporte de cierre de caja\n"
                . "Empresa: {$empresa}\n"
                . "Sesión #{$id}\n"
                . "Usuario: " . ($session['user_name'] ?? '') . "\n"
                . "Apertura: {$apertura}\n"
                . "Cierre: {$cierre}\n"
                . "Ventas: " . (int)($report['totals']['sales_count'] ?? 0)
                . " · Total: " . $this->formatMoney((float)($report['totals']['sales_total'] ?? 0)) . "\n"
                . "Adjunto encontrará el PDF del cierre.\n";

            $resultado = (new \SoftNova\Services\MailService($company))->send(
                $to,
                'Cierre de caja #' . $id . ' - ' . $empresa,
                $body,
                $temporal,
                $filename
            );
        } catch (\Throwable $e) {
            $this->respond(false, 'No se pudo enviar el correo: ' . $e->getMessage(), '/app/caja?share_close=' . $id);
            return;
        } finally {
            if ($temporal !== null && is_file($temporal)) {
                @unlink($temporal);
            }
        }

        $this->respond(
            (bool)$resultado['success'],
            (string)$resultado['message'],
            '/app/caja?focus=cierresReportes'
        );
    }

    /**
     * @return array{session:array,movements:list,totals:array,sales:list}|null
     */
    private function loadClosingReport(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $session = $this->query(
            "SELECT cs.*, u.name as user_name FROM cash_sessions cs JOIN users u ON cs.user_id = u.id WHERE cs.id = ?",
            [$id]
        )->fetch();

        if (!$session) {
            return null;
        }

        if (TenantMiddleware::isPosRole()) {
            $uid = (int)($_SESSION['tenant_user_id'] ?? 0);
            if ((int)($session['user_id'] ?? 0) !== $uid) {
                return null;
            }
        }

        $movements = $this->query(
            "SELECT * FROM cash_movements WHERE cash_session_id = ? ORDER BY created_at ASC",
            [$id]
        )->fetchAll();

        $totals = $this->query(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as incomes,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses
             FROM cash_movements WHERE cash_session_id = ?",
            [$id]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0];

        try {
            $fullTotals = $this->computeSessionTotals($session, $session['closing_date'] ?? null);
            $totals['incomes'] = $fullTotals['incomes'] ?? $totals['incomes'];
            $totals['expenses'] = $fullTotals['expenses'] ?? $totals['expenses'];
            $totals['payment_breakdown'] = $fullTotals['payment_breakdown'] ?? [];
            $totals['sales_total'] = $fullTotals['sales_total'] ?? 0;
            $totals['sales_count'] = $fullTotals['sales_count'] ?? 0;
            $totals['expected_cash'] = $fullTotals['expected_cash'] ?? null;
            $totals['counted_cash'] = $fullTotals['counted_cash'] ?? null;
            $totals['difference'] = $fullTotals['difference'] ?? null;
        } catch (\Throwable $e) {
            $totals['payment_breakdown'] = [];
        }

        $sales = [];
        try {
            $opening = $session['opening_date'] ?? null;
            $closing = $session['closing_date'] ?? null;
            if ($opening) {
                if ($closing) {
                    $sales = $this->query(
                        "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method,
                                COALESCE(c.name, 'Publico general') as customer_name
                         FROM sales s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.sale_date >= ? AND s.sale_date <= ?
                           AND s.status != 'cancelled'
                         ORDER BY s.sale_date ASC",
                        [$opening, $closing]
                    )->fetchAll();
                } else {
                    $sales = $this->query(
                        "SELECT s.id, s.invoice_number, s.sale_date, s.total, s.status, s.payment_method,
                                COALESCE(c.name, 'Publico general') as customer_name
                         FROM sales s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.sale_date >= ?
                           AND s.status != 'cancelled'
                         ORDER BY s.sale_date ASC",
                        [$opening]
                    )->fetchAll();
                }
            }
        } catch (\Throwable $e) {
            $sales = [];
        }

        return [
            'session' => $session,
            'movements' => $movements ?: [],
            'totals' => $totals ?: ['incomes' => 0, 'expenses' => 0],
            'sales' => $sales,
        ];
    }

    /** @param array{session:array,totals:array,sales:list} $report */
    private function closingWhatsappText(array $report): string
    {
        $session = $report['session'];
        $totals = $report['totals'];
        $empresa = (string)($this->companySettings()['company_name'] ?? 'Seri ERP');
        $id = (int)($session['id'] ?? 0);
        $lines = [
            '*' . $empresa . '* — Cierre de caja #' . $id,
            'Usuario: ' . ($session['user_name'] ?? ''),
            'Apertura: ' . (!empty($session['opening_date']) ? date('d/m/Y H:i', strtotime((string)$session['opening_date'])) : '—'),
            'Cierre: ' . (!empty($session['closing_date']) ? date('d/m/Y H:i', strtotime((string)$session['closing_date'])) : 'En curso'),
            'Apertura $: ' . $this->formatMoney((float)($session['opening_amount'] ?? 0)),
            'Cierre $: ' . $this->formatMoney((float)($session['closing_amount'] ?? 0)),
            'Ventas: ' . (int)($totals['sales_count'] ?? 0) . ' · ' . $this->formatMoney((float)($totals['sales_total'] ?? 0)),
        ];
        if (isset($totals['expected_cash'])) {
            $lines[] = 'Efectivo esperado: ' . $this->formatMoney((float)$totals['expected_cash']);
        }
        if (isset($totals['difference']) && abs((float)$totals['difference']) >= 0.01) {
            $diff = (float)$totals['difference'];
            $lines[] = ($diff < 0 ? 'Faltante: ' : 'Sobrante: ') . $this->formatMoney(abs($diff));
        }
        $lines[] = '';
        $lines[] = 'PDF: ' . rtrim((string)config('app.url', ''), '/') . '/app/caja?action=pdf&id=' . $id;
        return implode("\n", $lines);
    }

    /**
     * Totales de una sesion de caja (movimientos + ventas no duplicadas).
     *
     * @return array{incomes:float,expenses:float,balance:float,sales_count:int,sales_total:float,movements_count:int}
     */
    private function computeSessionTotals(array $session, ?string $until = null): array
    {
        $sessionId = (int)$session['id'];
        $openingAmount = (float)($session['opening_amount'] ?? 0);
        $openingDate = (string)($session['opening_date'] ?? '');
        $until = $until ?: null;

        $movTotals = $this->query(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as incomes,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses,
                COUNT(*) as movements_count
             FROM cash_movements
             WHERE cash_session_id = ?",
            [$sessionId]
        )->fetch() ?: ['incomes' => 0, 'expenses' => 0, 'movements_count' => 0];

        $incomes = (float)$movTotals['incomes'];
        $expenses = (float)$movTotals['expenses'];
        $movementsCount = (int)$movTotals['movements_count'];

        $movements = $this->query(
            "SELECT reference_type, reference_id FROM cash_movements WHERE cash_session_id = ?",
            [$sessionId]
        )->fetchAll();
        $movementSaleIds = [];
        foreach ($movements as $mov) {
            if (($mov['reference_type'] ?? '') === 'sale' && !empty($mov['reference_id'])) {
                $movementSaleIds[] = (int)$mov['reference_id'];
            }
        }

        if ($until) {
            $sales = $this->query(
                "SELECT id, total, status, payment_method FROM sales WHERE sale_date >= ? AND sale_date <= ?",
                [$openingDate, $until]
            )->fetchAll();
        } else {
            $sales = $this->query(
                "SELECT id, total, status, payment_method FROM sales WHERE sale_date >= ?",
                [$openingDate]
            )->fetchAll();
        }

        $salesIncome = 0.0;
        $salesCount = 0;
        $salesTotal = 0.0;
        $paymentBreakdown = [];
        foreach ($sales as $sale) {
            if (($sale['status'] ?? '') === 'cancelled') {
                continue;
            }
            $salesCount++;
            $saleTotal = (float)$sale['total'];
            $salesTotal += $saleTotal;
            if (!in_array((int)$sale['id'], $movementSaleIds, true)) {
                $salesIncome += $saleTotal;
            }
            $methodCode = \SoftNova\Services\PaymentMethodCatalog::normalize((string)($sale['payment_method'] ?? 'cash'));
            if (!isset($paymentBreakdown[$methodCode])) {
                $paymentBreakdown[$methodCode] = [
                    'label' => \SoftNova\Services\PaymentMethodCatalog::label($methodCode),
                    'total' => 0.0,
                    'count' => 0,
                ];
            }
            $paymentBreakdown[$methodCode]['total'] += $saleTotal;
            $paymentBreakdown[$methodCode]['count']++;
        }

        $incomes += $salesIncome;

        return [
            'incomes' => $incomes,
            'expenses' => $expenses,
            'balance' => $openingAmount + $incomes - $expenses,
            'sales_count' => $salesCount,
            'sales_total' => $salesTotal,
            'movements_count' => $movementsCount,
            'payment_breakdown' => $paymentBreakdown,
            // Solo efectivo: es lo que debe cuadrar contra el conteo del cajón.
            'expected_cash' => $this->computeExpectedCash($session, $until),
            'counted_cash' => isset($session['closing_amount']) && $session['closing_amount'] !== null
                ? round((float)$session['closing_amount'], 2)
                : null,
            'difference' => isset($session['difference_amount']) && $session['difference_amount'] !== null
                ? round((float)$session['difference_amount'], 2)
                : null,
        ];
    }

    /**
     * Cierra automaticamente cajas abiertas cuando ya paso la medianoche
     * del dia en que se abrieron.
     */
    private function autoCloseOvernightSessions(): int
    {
        $closed = 0;
        try {
            $openSessions = $this->query(
                "SELECT * FROM cash_sessions WHERE status = 'open'"
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0;
        }

        $today = date('Y-m-d');
        foreach ($openSessions as $session) {
            $openDay = date('Y-m-d', strtotime((string)$session['opening_date']));
            if ($today <= $openDay) {
                continue;
            }

            $closingAt = $openDay . ' 23:59:59';
            try {
                // Cierre ciego: se asume el efectivo esperado, sin faltante ni sobrante.
                $expectedCash = $this->computeExpectedCash($session, $closingAt);
                $this->query(
                    "UPDATE cash_sessions
                     SET closing_amount = ?, closing_date = ?, status = 'closed',
                         expected_amount = ?, difference_amount = 0,
                         notes = CONCAT(IFNULL(notes, ''), ?)
                     WHERE id = ? AND status = 'open'",
                    [
                        $expectedCash,
                        $closingAt,
                        $expectedCash,
                        "\nCierre automatico a medianoche del " . date('d/m/Y', strtotime($openDay)),
                        (int)$session['id'],
                    ]
                );
                $closed++;
            } catch (\Throwable $e) {
                error_log('autoCloseOvernightSessions #' . ($session['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        return $closed;
    }
}
