<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\AccountingService;
use SoftNova\Services\Integrations\IntegrationManager;

class TenantContabilidadController extends TenantController
{
    private AccountingService $accounting;

    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('contabilidad');
        $this->accounting = new AccountingService($this->db);
    }

    public function index(): void
    {
        $action = (string)$this->request->get('action', '');

        if ($this->request->method() === 'POST') {
            $redirect = match ($action) {
                'save-integration', 'set-active-provider' => '/app/contabilidad?tab=integrations',
                'save-account' => '/app/contabilidad?tab=accounts',
                'save-commission-rates', 'save-commission-config', 'save-user-commission', 'settle-commissions' => '/app/contabilidad?tab=commissions',
                'period' => '/app/contabilidad?tab=periods',
                'manual-entry' => '/app/contabilidad?tab=entries',
                default => '/app/contabilidad',
            };
            if ($action === 'save-catalog-integration') {
                // Compat: catálogo e-commerce vive en Inventario → Integraciones
                if (!$this->validateCsrfOrFail('/app/inventario?integrations=1')) {
                    return;
                }
                $this->redirectCatalogToInventario();
                return;
            }
            if (!$this->validateCsrfOrFail($redirect)) {
                return;
            }
            match ($action) {
                'manual-entry' => $this->createManualEntry(),
                'save-account' => $this->saveAccount(),
                'save-commission-rates' => $this->saveCommissionRates(),
                'save-commission-config' => $this->saveCommissionConfig(),
                'save-user-commission' => $this->saveUserCommission(),
                'settle-commissions' => $this->settleCommissions(),
                'period' => $this->updatePeriod(),
                'sync' => $this->syncOperations(),
                'save-integration' => $this->saveIntegration(),
                'set-active-provider' => $this->setActiveProvider(),
                default => $this->respond(false, 'Acción contable inválida', '/app/contabilidad'),
            };
            return;
        }

        if ($action === 'entry') {
            $this->entryDetail();
            return;
        }
        if ($action === 'integration-test') {
            $this->testIntegration();
            return;
        }
        if ($action === 'catalog-test' || $action === 'ml-oauth-start' || $action === 'ml-oauth-callback') {
            $this->redirectCatalogToInventario($action);
            return;
        }
        if ($action === 'export') {
            TenantMiddleware::authorize('contabilidad', 'export');
            $this->exportReport();
            return;
        }
        if ($action === 'invoice-timeline') {
            $this->invoiceTimeline();
            return;
        }

        $from = $this->validDate((string)$this->request->get('from', date('Y-m-01')), date('Y-m-01'));
        $to = $this->validDate((string)$this->request->get('to', date('Y-m-d')), date('Y-m-d'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $tab = (string)$this->request->get('tab', 'dashboard');
        $accountId = max(0, (int)$this->request->get('account_id', 0));
        $integrations = new IntegrationManager($this->db);
        \SoftNova\Services\TenantOpsSchema::ensure($this->db);

        $purchaseSummary = ['cnt' => 0, 'total' => 0.0];
        $invoiceTraceOrders = [];
        $invoiceTraceQ = trim((string)$this->request->get('q', ''));
        $needsPurchaseSummary = in_array($tab, ['dashboard', 'compras'], true);
        try {
            $purchasing = new \SoftNova\Services\PurchasingService($this->db);
            if ($needsPurchaseSummary) {
                $purchaseSummary = $this->query(
                    "SELECT COUNT(*) cnt,
                            COALESCE(SUM(GREATEST(total - COALESCE(credit_total, 0), 0)),0) total
                     FROM purchase_orders
                     WHERE status = 'received'
                       AND DATE(COALESCE(warehouse_date, order_date)) BETWEEN ? AND ?",
                    [$from, $to]
                )->fetch() ?: ['cnt'=>0,'total'=>0];
            }
            if ($tab === 'invoice-trace') {
                $invoiceTraceOrders = $purchasing->listInvoiceTrace($from, $to, $invoiceTraceQ, 100);
            }
        } catch (\Throwable $e) {
            // schema aún no migrado
        }
        if ($needsPurchaseSummary) {
            try {
                // Solo sumar legacy si no hay OC received en el rango (evita doble conteo en migraciones híbridas).
                $poCnt = (int)($purchaseSummary['cnt'] ?? 0);
                if ($poCnt === 0) {
                    $legacy = $this->query(
                        "SELECT COUNT(*) cnt, COALESCE(SUM(total),0) total
                         FROM purchases WHERE status='completed' AND DATE(purchase_date) BETWEEN ? AND ?",
                        [$from, $to]
                    )->fetch() ?: ['cnt'=>0,'total'=>0];
                    $purchaseSummary['cnt'] = (int)$legacy['cnt'];
                    $purchaseSummary['total'] = (float)$legacy['total'];
                }
            } catch (\Throwable $e) {
                // tabla purchases puede no existir en installs nuevos
            }
        }
        $expenseByType = [];
        if (in_array($tab, ['dashboard', 'gastos'], true)) {
            try {
                $expenseByType = $this->query(
                    "SELECT COALESCE(category,'general') category, COUNT(*) cnt, COALESCE(SUM(amount),0) total
                     FROM expenses
                     WHERE expense_date BETWEEN ? AND ?
                       AND COALESCE(status, 'active') <> 'cancelled'
                     GROUP BY COALESCE(category,'general') ORDER BY total DESC",
                    [$from, $to]
                )->fetchAll();
            } catch (\Throwable $e) {
                $expenseByType = [];
            }
        }
        $trace = ['rows' => [], 'total' => 0];
        if (in_array($tab, ['dashboard', 'trace'], true)) {
            $stock = new \SoftNova\Services\StockService($this->db);
            $trace = $stock->listMovements(['from' => $from, 'to' => $to], 25, 0);
        }

        $accounts = $this->accounting->accounts();
        $needsStatements = in_array($tab, ['dashboard', 'statements'], true);
        $needsRecon = $tab === 'dashboard';
        $needsAccountAudit = in_array($tab, ['dashboard', 'accounts'], true);
        $needsEntries = in_array($tab, ['dashboard', 'entries'], true);
        $needsLedger = $tab === 'ledger' && $accountId > 0;
        $needsPeriods = in_array($tab, ['dashboard', 'periods'], true);

        $trialAccountType = trim((string)$this->request->get('account_type', ''));
        $trialQ = trim((string)$this->request->get('q', ''));
        $trialIncludeZero = (string)$this->request->get('include_zero', '') === '1';
        $balanceYear = (int)$this->request->get('year', 0);
        if ($balanceYear < 2000 || $balanceYear > 2100) {
            $balanceYear = (int)substr($from, 0, 4);
        }
        $monthlyBalance = $tab === 'trial'
            ? $this->accounting->monthlyOperationalBalance($balanceYear)
            : [
                'year' => $balanceYear,
                'months' => [],
                'totals' => [],
                'chart' => [],
            ];
        $trialReport = $needsStatements
            ? $this->accounting->trialBalance($from, $to, [
                'account_type' => '',
                'q' => '',
                'include_zero' => false,
            ])
            : [
                'rows' => [],
                'totals' => [
                    'opening_debit' => 0, 'opening_credit' => 0,
                    'debit' => 0, 'credit' => 0,
                    'closing_debit' => 0, 'closing_credit' => 0,
                    'accounts' => 0,
                ],
                'balanced' => ['opening' => true, 'period' => true, 'closing' => true],
                'from' => $from,
                'to' => $to,
            ];
        $trialBalance = $trialReport['rows'];
        $statements = $needsStatements ? $this->accounting->financialStatements($from, $to) : [
            'assets' => [], 'liabilities' => [], 'equity' => [],
            'revenue' => [], 'expenses' => [],
            'totals' => ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'revenue' => 0, 'expenses' => 0],
            'profit' => 0,
        ];
        $ledgerReport = $needsLedger
            ? $this->accounting->ledger($accountId, $from, $to)
            : ['opening_balance' => 0.0, 'rows' => []];
        $ledger = $ledgerReport['rows'] ?? [];
        $ledgerOpening = (float)($ledgerReport['opening_balance'] ?? 0);
        $accountAudit = $needsAccountAudit ? $this->accounting->auditCriticalAccounts() : [];
        $reconciliation = $needsRecon ? $this->accounting->subledgerReconciliation() : [];
        $pendingQueue = $needsRecon ? $this->accounting->pendingQueueCount() : 0;
        // pendingSync = operaciones sin asiento (sin incluir cola, para no doble-contar en UI).
        $pendingSync = $needsRecon
            ? max(0, $this->accounting->pendingSyncCount() - $pendingQueue)
            : 0;

        $commissionCfg = [];
        $commissionList = ['rows' => [], 'pending' => 0, 'paid' => 0, 'cancelled' => 0];
        $commissionUsers = [];
        if ($tab === 'commissions') {
            $comm = new \SoftNova\Services\CommissionService($this->db);
            $commissionCfg = $comm->config();
            $commissionUsers = $comm->userRates();
            $commissionList = $comm->list([
                'from' => $from,
                'to' => $to,
                'kind' => (string)$this->request->get('kind', ''),
                'status' => (string)$this->request->get('status', ''),
            ], 150);
        }

        $vatBook = null;
        if ($tab === 'vat') {
            try {
                $vatBook = \SoftNova\Services\TaxService::vatBook($this->db, $from, $to);
            } catch (\Throwable $e) {
                $vatBook = [
                    'from' => $from,
                    'to' => $to,
                    'documents' => [],
                    'ledger' => [],
                    'by_rate' => [],
                    'warnings' => ['No se pudo calcular el libro IVA: ' . $e->getMessage()],
                    'accounts' => ['generated' => '240801', 'deductible' => '240802'],
                ];
            }
        }

        $this->view('tenant.contabilidad', $this->tenantViewData([
            'tab' => $tab,
            'dateFrom' => $from,
            'dateTo' => $to,
            'accounts' => $accounts,
            'entries' => $needsEntries ? $this->accounting->entries($from, $to) : [],
            'trialBalance' => $trialBalance,
            'trialReport' => $trialReport,
            'monthlyBalance' => $monthlyBalance,
            'balanceYear' => $balanceYear,
            'trialAccountType' => $trialAccountType,
            'trialQ' => $trialQ,
            'trialIncludeZero' => $trialIncludeZero,
            'statements' => $statements,
            'ledger' => $ledger,
            'ledgerOpening' => $ledgerOpening,
            'selectedAccountId' => $accountId,
            'periods' => $needsPeriods ? $this->accounting->periods() : [],
            'integrationStatuses' => $integrations->statuses(),
            'activeProvider' => $integrations->settings()->getActiveProvider(),
            'purchaseSummary' => $purchaseSummary,
            'expenseByType' => $expenseByType,
            'traceMovements' => $trace['rows'],
            'invoiceTraceOrders' => $invoiceTraceOrders,
            'invoiceTraceQ' => $invoiceTraceQ,
            'accountAudit' => $accountAudit,
            'reconciliation' => $reconciliation,
            'pendingSync' => $pendingSync,
            'pendingQueue' => $pendingQueue,
            'commissionCfg' => $commissionCfg,
            'commissionList' => $commissionList,
            'commissionUsers' => $commissionUsers,
            'vatBook' => $vatBook,
        ]));
    }

    /**
     * Compatibilidad: catálogo Woo/ML se gestiona en Inventario → Integraciones.
     */
    private function redirectCatalogToInventario(string $action = ''): void
    {
        $provider = (string)($this->request->get('provider', '') ?: $this->request->post('provider', 'woocommerce'));
        if (!in_array($provider, ['woocommerce', 'mercadolibre'], true)) {
            $provider = 'woocommerce';
        }
        $qs = 'integrations=1&catalog=' . urlencode($provider);
        if ($action === 'ml-oauth-callback') {
            // Reenviar query OAuth completa al nuevo endpoint
            $params = $_GET;
            unset($params['action']);
            $target = \SoftNova\Core\route('app/inventario') . '?action=ml-oauth-callback';
            if ($params) {
                $target .= '&' . http_build_query($params);
            }
            header('Location: ' . $target);
            exit;
        }
        if ($action === 'ml-oauth-start') {
            header('Location: ' . \SoftNova\Core\route('app/inventario') . '?action=ml-oauth-start');
            exit;
        }
        if ($action === 'catalog-test') {
            header('Location: ' . \SoftNova\Core\route('app/inventario') . '?action=catalog-test&provider=' . urlencode($provider));
            exit;
        }
        // POST save-catalog-integration: no re-ejecutar; pedir reconfiguración en Inventario
        $this->respond(
            false,
            'Las integraciones WooCommerce / Mercado Libre se configuran en Inventario → Integraciones.',
            '/app/inventario?' . $qs
        );
    }

    private function saveCommissionRates(): void
    {
        // Compatibilidad con el formulario antiguo de Cuentas: solo tasas de pasarela.
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $svc = new \SoftNova\Services\CommissionService($this->db);
            $cfg = $svc->config();
            $svc->saveConfig([
                'seller_enabled' => $cfg['seller_enabled'],
                'seller_rate' => $cfg['seller_rate'],
                'seller_base' => $cfg['seller_base'],
                'seller_trigger' => $cfg['seller_trigger'],
                'seller_auto_expense' => $cfg['seller_auto_expense'],
                'gateway_auto' => $cfg['gateway_auto'],
                'dataphone_rate' => $this->request->post('dataphone_commission_rate', $cfg['dataphone_rate']),
                'card_rate' => $this->request->post('card_commission_rate', $cfg['card_rate']),
                'payment_link_rate' => $cfg['payment_link_rate'],
                'debit_card_rate' => $cfg['debit_card_rate'],
                'credit_card_rate' => $cfg['credit_card_rate'],
            ]);
            $this->respond(true, 'Tasas de pasarela actualizadas. Ver panel Comisiones para el resto.', '/app/contabilidad?tab=commissions');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=accounts');
        }
    }

    private function saveCommissionConfig(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $svc = new \SoftNova\Services\CommissionService($this->db);
            $svc->saveConfig([
                'seller_enabled' => $this->request->post('seller_enabled', '0') === '1',
                'seller_rate' => $this->request->post('seller_rate', '3'),
                'seller_base' => $this->request->post('seller_base', 'total'),
                'seller_trigger' => $this->request->post('seller_trigger', 'on_payment'),
                'seller_auto_expense' => $this->request->post('seller_auto_expense', '0') === '1',
                'gateway_auto' => $this->request->post('gateway_auto', '0') === '1',
                'dataphone_rate' => $this->request->post('dataphone_rate', '2.5'),
                'card_rate' => $this->request->post('card_rate', '2.8'),
                'payment_link_rate' => $this->request->post('payment_link_rate', '2.5'),
                'debit_card_rate' => $this->request->post('debit_card_rate', '1.5'),
                'credit_card_rate' => $this->request->post('credit_card_rate', '2.8'),
                'credit_over_60_rate' => $this->request->post('credit_over_60_rate', '0'),
                'credit_over_60_days' => $this->request->post('credit_over_60_days', '60'),
                'credit_tier_days' => $this->request->post('credit_tier_days', []),
                'credit_tier_rates' => $this->request->post('credit_tier_rates', []),
                'qty_discount_qty' => $this->request->post('qty_discount_qty', []),
                'qty_discount_rates' => $this->request->post('qty_discount_rates', []),
            ]);
            $this->respond(true, 'Parámetros de comisiones guardados', '/app/contabilidad?tab=commissions');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=commissions');
        }
    }

    private function saveUserCommission(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $svc = new \SoftNova\Services\CommissionService($this->db);
            $svc->saveUserRate(
                (int)$this->request->post('user_id', 0),
                (float)$this->request->post('rate', 0),
                $this->request->post('enabled', '0') === '1'
            );
            $this->respond(true, 'Tasa de vendedor actualizada', '/app/contabilidad?tab=commissions');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=commissions');
        }
    }

    private function settleCommissions(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $ids = $this->request->post('ids', []);
            if (!is_array($ids)) {
                $ids = [];
            }
            $n = (new \SoftNova\Services\CommissionService($this->db))->settle($ids, false);
            $this->respond(true, $n . ' comisión(es) liquidada(s)', '/app/contabilidad?tab=commissions');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=commissions');
        }
    }

    private function saveIntegration(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $provider = (string)$this->request->post('provider', '');
        $makeActive = $this->request->post('make_active', '0') === '1';
        try {
            $manager = new IntegrationManager($this->db);
            $manager->saveProvider($provider, [
                'enabled' => $this->request->post('enabled', '0'),
                'email' => $this->request->post('email', ''),
                'token' => $this->request->post('token', ''),
                'base_url' => $this->request->post('base_url', ''),
                'tax_id' => $this->request->post('tax_id', ''),
                'stamp' => $this->request->post('stamp', '0'),
                'sync_sales' => $this->request->post('sync_sales', '0'),
                'sync_payments' => $this->request->post('sync_payments', '0'),
                'sync_expenses' => $this->request->post('sync_expenses', '0'),
                'username' => $this->request->post('username', ''),
                'access_key' => $this->request->post('access_key', ''),
                'partner_id' => $this->request->post('partner_id', 'SeriERP'),
                'client_id' => $this->request->post('client_id', ''),
                'client_secret' => $this->request->post('client_secret', ''),
                'password' => $this->request->post('password', ''),
                // Siigo (IDs de documento/vendedor/medio/grupo contable)
                'document_id' => $this->request->post('document_id', ''),
                'seller_id' => $this->request->post('seller_id', ''),
                'payment_type_id' => $this->request->post('payment_type_id', ''),
                'account_group_id' => $this->request->post('account_group_id', ''),
                // Factus
                'numbering_range_id' => $this->request->post('numbering_range_id', ''),
                'tax_rate' => $this->request->post('tax_rate', '0'),
                'municipality_id' => $this->request->post('municipality_id', ''),
                // DIAN
                'nit' => $this->request->post('nit', ''),
                'dv' => $this->request->post('dv', ''),
                'legal_name' => $this->request->post('legal_name', ''),
                'resolution_number' => $this->request->post('resolution_number', ''),
                'prefix' => $this->request->post('prefix', ''),
                'range_from' => $this->request->post('range_from', ''),
                'range_to' => $this->request->post('range_to', ''),
                'technical_key' => $this->request->post('technical_key', ''),
                'software_id' => $this->request->post('software_id', ''),
                'pin' => $this->request->post('pin', ''),
                'environment' => $this->request->post('environment', 'habilitacion'),
            ], $makeActive);
            $this->respond(true, 'Integración guardada', '/app/contabilidad?tab=integrations&provider=' . urlencode($provider));
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=integrations');
        }
    }

    private function setActiveProvider(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $provider = trim((string)$this->request->post('provider', ''));
            (new IntegrationManager($this->db))->setActive($provider !== '' ? $provider : null);
            $this->respond(true, $provider !== '' ? 'Proveedor activo actualizado' : 'Sin proveedor activo', '/app/contabilidad?tab=integrations');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=integrations');
        }
    }

    private function testIntegration(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $provider = (string)$this->request->get('provider', '');
        $this->json((new IntegrationManager($this->db))->test($provider));
    }

    private function createManualEntry(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        $date = $this->validDate(
            (string)$this->request->post('entry_date', date('Y-m-d')),
            date('Y-m-d')
        );
        $description = trim((string)$this->request->post('description', ''));
        $accountCodes = $this->request->post('account_code', []);
        $debits = $this->request->post('debit', []);
        $credits = $this->request->post('credit', []);
        $lineDescriptions = $this->request->post('line_description', []);

        if ($description === '' || !is_array($accountCodes)) {
            $this->respond(false, 'Descripción y líneas contables son obligatorias', '/app/contabilidad');
            return;
        }

        $lines = [];
        foreach ($accountCodes as $i => $code) {
            $code = trim((string)$code);
            $debit = round((float)($debits[$i] ?? 0), 2);
            $credit = round((float)($credits[$i] ?? 0), 2);
            if ($code === '' || ($debit <= 0 && $credit <= 0)) {
                continue;
            }
            $lines[] = [
                'account_code' => $code,
                'debit' => $debit,
                'credit' => $credit,
                'description' => trim((string)($lineDescriptions[$i] ?? '')),
            ];
        }

        try {
            $this->db->beginTransaction();
            $id = $this->accounting->postEntry($date, $description, $lines, 'manual', null, null);
            $this->db->commit();
            $this->respond(true, 'Comprobante contable registrado', '/app/contabilidad?tab=entries&entry_id=' . $id);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=entries');
        }
    }

    private function saveAccount(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $this->accounting->saveAccount([
                'id' => (int)$this->request->post('id', 0),
                'code' => $this->request->post('code', ''),
                'name' => $this->request->post('name', ''),
                'account_type' => $this->request->post('account_type', ''),
                'nature' => $this->request->post('nature', ''),
                'accepts_entries' => $this->request->post('accepts_entries', '0') === '1',
                'status' => $this->request->post('status', 'active'),
            ]);
            $this->respond(true, 'Cuenta contable guardada', '/app/contabilidad?tab=accounts');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=accounts');
        }
    }

    private function updatePeriod(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $this->accounting->closePeriod(
                (int)$this->request->post('year'),
                (int)$this->request->post('month'),
                $this->request->post('status') === 'closed',
                trim((string)$this->request->post('notes', '')) ?: null
            );
            $this->respond(true, 'Periodo contable actualizado', '/app/contabilidad?tab=periods');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=periods');
        }
    }

    private function syncOperations(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        // Procesa por lotes para evitar timeouts con históricos grandes.
        $result = $this->accounting->syncExistingOperations(200);

        $partes = [];
        foreach ([
            'pending_queue' => 'reintentos de cola',
            'sales' => 'ventas',
            'payments' => 'abonos de clientes',
            'expenses' => 'gastos',
            'purchases' => 'compras',
            'stock' => 'movimientos de inventario',
            'supplier_payments' => 'abonos a proveedores',
            'cash' => 'movimientos de caja',
            'cash_sessions' => 'cierres/arqueos de caja',
            'sale_credit_notes' => 'NC de ventas',
            'purchase_credit_notes' => 'NC de compras',
        ] as $clave => $etiqueta) {
            $cantidad = (int)($result[$clave] ?? 0);
            if ($cantidad > 0) {
                $partes[] = $cantidad . ' ' . $etiqueta;
            }
        }

        $message = $partes
            ? 'Sincronización: ' . implode(', ', $partes) . ' contabilizados'
            : 'No había operaciones pendientes de contabilizar';
        if (!empty($result['remaining'])) {
            $message .= sprintf('. Quedan %d operaciones: vuelve a ejecutar para continuar', $result['remaining']);
        }
        if ($result['errors'] > 0) {
            $message .= sprintf('. %d operaciones requieren revisión', $result['errors']);
        }
        $this->respond($result['errors'] === 0, $message, '/app/contabilidad');
    }

    private function entryDetail(): void
    {
        $entry = $this->accounting->entry((int)$this->request->get('id', 0));
        if (!$entry) {
            $this->json(['success' => false, 'message' => 'Comprobante no encontrado']);
            return;
        }
        $this->json(['success' => true, 'entry' => $entry]);
    }

    private function invoiceTimeline(): void
    {
        $id = (int)$this->request->get('id', 0);
        try {
            $purchasing = new \SoftNova\Services\PurchasingService($this->db);
            $data = $purchasing->invoiceTimeline($id);
            if (empty($data['order'])) {
                $this->json(['success' => false, 'message' => 'Factura / OC no encontrada']);
                return;
            }
            $this->json(['success' => true, 'order' => $data['order'], 'events' => $data['events']]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function exportReport(): void
    {
        $from = $this->validDate((string)$this->request->get('from', date('Y-m-01')), date('Y-m-01'));
        $to = $this->validDate((string)$this->request->get('to', date('Y-m-d')), date('Y-m-d'));
        $report = (string)$this->request->get('report', 'trial-balance');

        if ($report === 'invoice-trace') {
            $q = trim((string)$this->request->get('q', ''));
            $purchasing = new \SoftNova\Services\PurchasingService($this->db);
            $rows = [];
            foreach ($purchasing->listInvoiceTrace($from, $to, $q, 500) as $o) {
                $balance = round(
                    (float)$o['total']
                    - (float)($o['paid_total'] ?? 0)
                    - (float)($o['credit_total'] ?? 0),
                    2
                );
                $rows[] = [
                    $o['order_number'] ?? '',
                    $o['invoice_number'] ?? '',
                    $o['supplier_name'] ?? '',
                    substr((string)($o['invoice_date'] ?? $o['order_date'] ?? ''), 0, 10),
                    substr((string)($o['warehouse_date'] ?? ''), 0, 10),
                    substr((string)($o['due_date'] ?? ''), 0, 10),
                    $o['status'] ?? '',
                    $o['total'] ?? 0,
                    $o['paid_total'] ?? 0,
                    $o['credit_total'] ?? 0,
                    $balance,
                    $o['accounting_entry_number'] ?? ($o['accounting_entry_id'] ? ('#' . $o['accounting_entry_id']) : ''),
                    $o['payments_count'] ?? 0,
                    $o['credit_notes_count'] ?? 0,
                    $o['origin'] ?? '',
                ];
            }
            $this->exportCsv(
                'trazabilidad_facturas_' . $from . '_' . $to . '.csv',
                [
                    'OC', 'Factura', 'Proveedor', 'Fecha factura', 'Fecha bodega', 'Vence',
                    'Estado', 'Total', 'Pagado', 'NC', 'Saldo', 'Asiento', 'Abonos', 'Notas crédito', 'Origen',
                ],
                $rows
            );
            return;
        }

        if ($report === 'journal') {
            $rows = [];
            foreach ($this->accounting->entries($from, $to, 500) as $entry) {
                $rows[] = [
                    $entry['entry_number'],
                    $entry['entry_date'],
                    $entry['description'],
                    $entry['total_debit'],
                    $entry['total_credit'],
                    $entry['status'],
                ];
            }
            $this->exportCsv(
                'libro_diario_' . $from . '_' . $to . '.csv',
                ['Comprobante', 'Fecha', 'Concepto', 'Debe', 'Haber', 'Estado'],
                $rows
            );
            return;
        }

        if ($report === 'vat') {
            $book = \SoftNova\Services\TaxService::vatBook($this->db, $from, $to);
            $docs = $book['documents'] ?? [];
            $led = $book['ledger'] ?? [];
            $rows = [
                ['Ventas IVA', $docs['sales_tax'] ?? 0],
                ['NC ventas IVA', $docs['sales_credit_tax'] ?? 0],
                ['IVA generado neto (docs)', $docs['sales_net'] ?? 0],
                ['Compras IVA', $docs['purchases_tax'] ?? 0],
                ['NC compras IVA', $docs['purchases_credit_tax'] ?? 0],
                ['IVA descontable neto (docs)', $docs['purchases_net'] ?? 0],
                ['Saldo a pagar (docs)', $docs['balance_to_pay'] ?? 0],
                ['Libro 240801 neto', $led['generated_net'] ?? 0],
                ['Libro 240802 neto', $led['deductible_net'] ?? 0],
                ['Saldo libro contable', $led['balance'] ?? 0],
            ];
            foreach (($book['by_rate'] ?? []) as $r) {
                $rows[] = [
                    'Tarifa ' . number_format((float)($r['rate'] ?? 0), 2, '.', '') . '%',
                    'base=' . number_format((float)($r['base'] ?? 0), 2, '.', '')
                        . '; iva=' . number_format((float)($r['tax'] ?? 0), 2, '.', ''),
                ];
            }
            foreach (($book['warnings'] ?? []) as $w) {
                $rows[] = ['Advertencia', $w];
            }
            $this->exportCsv(
                'libro_iva_' . $from . '_' . $to . '.csv',
                ['Concepto', 'Valor'],
                $rows
            );
            return;
        }

        if ($report === 'trial-balance' || $report === 'monthly-balance') {
            $year = (int)$this->request->get('year', (int)substr($from, 0, 4));
            $monthly = $this->accounting->monthlyOperationalBalance($year);
            $rows = [];
            foreach ($monthly['months'] as $row) {
                $rows[] = [
                    $row['month_label'],
                    $row['ingresos'],
                    $row['costo_venta'],
                    $row['facturas_total'],
                    $row['facturas_contado'],
                    $row['facturas_credito'],
                    $row['facturas_pagadas'],
                    $row['facturas_descuentos'],
                    $row['facturas_pendientes'],
                    $row['utilidad_bruta'],
                    $row['utilidad_operacional'],
                    $row['utilidad_neta'],
                ];
            }
            $t = $monthly['totals'];
            $rows[] = [
                'TOTAL',
                $t['ingresos'] ?? 0,
                $t['costo_venta'] ?? 0,
                $t['facturas_total'] ?? 0,
                $t['facturas_contado'] ?? 0,
                $t['facturas_credito'] ?? 0,
                $t['facturas_pagadas'] ?? 0,
                $t['facturas_descuentos'] ?? 0,
                $t['facturas_pendientes'] ?? 0,
                $t['utilidad_bruta'] ?? 0,
                $t['utilidad_operacional'] ?? 0,
                $t['utilidad_neta'] ?? 0,
            ];
            $this->exportCsv(
                'balance_' . $year . '.csv',
                [
                    'Mes', 'Ingresos', 'Costo de venta',
                    'Facturas Total', 'Contado', 'Crédito',
                    'Fact. Pagadas', 'Dctos X Pagos', 'Pendientes',
                    'Utilidad Bruta', 'Utilidad Operacional', 'Utilidad Neta',
                ],
                $rows
            );
            return;
        }

        if ($report === 'accounts-trial') {
            $rows = [];
            $reportData = $this->accounting->trialBalance($from, $to, [
                'account_type' => trim((string)$this->request->get('account_type', '')),
                'q' => trim((string)$this->request->get('q', '')),
                'include_zero' => (string)$this->request->get('include_zero', '') === '1',
            ]);
            foreach ($reportData['rows'] as $row) {
                $rows[] = [
                    $row['code'],
                    $row['name'],
                    $row['account_type_label'] ?? $row['account_type'] ?? '',
                    $row['nature_label'] ?? '',
                    $row['opening_debit'] ?? 0,
                    $row['opening_credit'] ?? 0,
                    $row['debit'],
                    $row['credit'],
                    $row['closing_debit'] ?? 0,
                    $row['closing_credit'] ?? 0,
                ];
            }
            $t = $reportData['totals'];
            $rows[] = [
                '', 'TOTALES', '', '',
                $t['opening_debit'] ?? 0,
                $t['opening_credit'] ?? 0,
                $t['debit'] ?? 0,
                $t['credit'] ?? 0,
                $t['closing_debit'] ?? 0,
                $t['closing_credit'] ?? 0,
            ];
            $this->exportCsv(
                'balance_cuentas_' . $from . '_' . $to . '.csv',
                [
                    'Código', 'Cuenta', 'Tipo', 'Naturaleza',
                    'SI Debe', 'SI Haber', 'Debe', 'Haber', 'SF Debe', 'SF Haber',
                ],
                $rows
            );
            return;
        }
    }

    private function validDate(string $date, string $fallback): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : $fallback;
    }
}
