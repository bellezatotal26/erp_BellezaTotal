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
                'save-integration', 'set-active-provider', 'save-catalog-integration' => '/app/contabilidad?tab=integrations',
                'save-account' => '/app/contabilidad?tab=accounts',
                'save-commission-rates', 'save-commission-config', 'save-user-commission', 'settle-commissions' => '/app/contabilidad?tab=commissions',
                'period' => '/app/contabilidad?tab=periods',
                'manual-entry' => '/app/contabilidad?tab=entries',
                default => '/app/contabilidad',
            };
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
                'save-catalog-integration' => $this->saveCatalogIntegration(),
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
        if ($action === 'catalog-test') {
            $this->testCatalogIntegration();
            return;
        }
        if ($action === 'ml-oauth-start') {
            $this->mlOAuthStart();
            return;
        }
        if ($action === 'ml-oauth-callback') {
            $this->mlOAuthCallback();
            return;
        }
        if ($action === 'export') {
            TenantMiddleware::authorize('contabilidad', 'export');
            $this->exportReport();
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
        $catalog = new \SoftNova\Services\Integrations\CatalogSyncService($this->db);
        \SoftNova\Services\TenantOpsSchema::ensure($this->db);

        $purchaseSummary = ['cnt' => 0, 'total' => 0.0];
        try {
            (new \SoftNova\Services\PurchasingService($this->db));
            $purchaseSummary = $this->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(total),0) total
                 FROM purchase_orders
                 WHERE status = 'received'
                   AND DATE(COALESCE(warehouse_date, order_date)) BETWEEN ? AND ?",
                [$from, $to]
            )->fetch() ?: ['cnt'=>0,'total'=>0];
        } catch (\Throwable $e) {
            // schema aún no migrado
        }
        try {
            $legacy = $this->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(total),0) total
                 FROM purchases WHERE status='completed' AND DATE(purchase_date) BETWEEN ? AND ?",
                [$from, $to]
            )->fetch() ?: ['cnt'=>0,'total'=>0];
            $purchaseSummary['cnt'] = (int)$purchaseSummary['cnt'] + (int)$legacy['cnt'];
            $purchaseSummary['total'] = (float)$purchaseSummary['total'] + (float)$legacy['total'];
        } catch (\Throwable $e) {
            // tabla purchases puede no existir en installs nuevos
        }
        $expenseByType = $this->query(
            "SELECT COALESCE(category,'general') category, COUNT(*) cnt, COALESCE(SUM(amount),0) total
             FROM expenses WHERE expense_date BETWEEN ? AND ?
             GROUP BY COALESCE(category,'general') ORDER BY total DESC",
            [$from, $to]
        )->fetchAll();
        $stock = new \SoftNova\Services\StockService($this->db);
        $trace = $stock->listMovements(['from' => $from, 'to' => $to], 25, 0);

        $accounts = $this->accounting->accounts();
        $trialBalance = $this->accounting->trialBalance($from, $to);
        $statements = $this->accounting->financialStatements($from, $to);
        $ledger = $accountId > 0
            ? $this->accounting->ledger($accountId, $from, $to)
            : [];
        $accountAudit = $this->accounting->auditCriticalAccounts();

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

        $this->view('tenant.contabilidad', $this->tenantViewData([
            'tab' => $tab,
            'dateFrom' => $from,
            'dateTo' => $to,
            'accounts' => $accounts,
            'entries' => $this->accounting->entries($from, $to),
            'trialBalance' => $trialBalance,
            'statements' => $statements,
            'ledger' => $ledger,
            'selectedAccountId' => $accountId,
            'periods' => $this->accounting->periods(),
            'integrationStatuses' => $integrations->statuses(),
            'catalogStatuses' => $catalog->statuses(),
            'activeProvider' => $integrations->settings()->getActiveProvider(),
            'purchaseSummary' => $purchaseSummary,
            'expenseByType' => $expenseByType,
            'traceMovements' => $trace['rows'],
            'accountAudit' => $accountAudit,
            'mlOAuthRedirect' => \SoftNova\Core\route('app/contabilidad') . '?action=ml-oauth-callback',
            'commissionCfg' => $commissionCfg,
            'commissionList' => $commissionList,
            'commissionUsers' => $commissionUsers,
        ]));
    }

    private function saveCatalogIntegration(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $provider = (string)$this->request->post('provider', '');
        try {
            $svc = new \SoftNova\Services\Integrations\CatalogSyncService($this->db);
            $svc->saveProvider($provider, [
                'enabled' => $this->request->post('enabled', '0'),
                'store_url' => $this->request->post('store_url', ''),
                'consumer_key' => $this->request->post('consumer_key', ''),
                'consumer_secret' => $this->request->post('consumer_secret', ''),
                'access_token' => $this->request->post('access_token', ''),
                'refresh_token' => $this->request->post('refresh_token', ''),
                'client_id' => $this->request->post('client_id', ''),
                'client_secret' => $this->request->post('client_secret', ''),
                'user_id' => $this->request->post('user_id', ''),
                'site_id' => $this->request->post('site_id', 'MCO'),
                'base_url' => $this->request->post('base_url', ''),
                'stock_authority' => $this->request->post('stock_authority', 'create_only'),
            ]);
            $this->respond(true, 'Integración de catálogo guardada', '/app/contabilidad?tab=integrations&provider=' . urlencode($provider));
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=integrations');
        }
    }

    private function mlOAuthStart(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $svc = new \SoftNova\Services\Integrations\CatalogSyncService($this->db);
        $ml = $svc->mercadoLibre();
        if (!$ml) {
            $this->respond(false, 'Conector ML no disponible', '/app/contabilidad?tab=integrations&provider=mercadolibre');
            return;
        }
        $st = $ml->status();
        if (empty($st['oauth_ready'])) {
            $this->respond(false, 'Guarde primero Client ID y Client Secret', '/app/contabilidad?tab=integrations&provider=mercadolibre');
            return;
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['ml_oauth_state'] = $state;
        $redirect = \SoftNova\Core\route('app/contabilidad') . '?action=ml-oauth-callback';
        header('Location: ' . $ml->authorizationUrl($redirect, $state));
        exit;
    }

    private function mlOAuthCallback(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $error = (string)$this->request->get('error', '');
        if ($error !== '') {
            $this->respond(false, 'OAuth ML cancelado: ' . $error, '/app/contabilidad?tab=integrations&provider=mercadolibre');
            return;
        }
        $state = (string)$this->request->get('state', '');
        $expected = (string)($_SESSION['ml_oauth_state'] ?? '');
        unset($_SESSION['ml_oauth_state']);
        if ($expected === '' || !hash_equals($expected, $state)) {
            $this->respond(false, 'Estado OAuth inválido. Intente conectar de nuevo.', '/app/contabilidad?tab=integrations&provider=mercadolibre');
            return;
        }
        $code = (string)$this->request->get('code', '');
        if ($code === '') {
            $this->respond(false, 'Sin código de autorización', '/app/contabilidad?tab=integrations&provider=mercadolibre');
            return;
        }
        try {
            $svc = new \SoftNova\Services\Integrations\CatalogSyncService($this->db);
            $ml = $svc->mercadoLibre();
            if (!$ml) {
                throw new \RuntimeException('Conector ML no disponible');
            }
            $redirect = \SoftNova\Core\route('app/contabilidad') . '?action=ml-oauth-callback';
            $ml->exchangeToken('authorization_code', [
                'code' => $code,
                'redirect_uri' => $redirect,
            ]);
            $svc->settings()->set('mercadolibre', 'enabled', '1', false);
            $this->respond(true, 'Mercado Libre conectado (token + refresh guardados)', '/app/contabilidad?tab=integrations&provider=mercadolibre');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), '/app/contabilidad?tab=integrations&provider=mercadolibre');
        }
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

    private function testCatalogIntegration(): void
    {
        $provider = (string)$this->request->get('provider', '');
        $result = (new \SoftNova\Services\Integrations\CatalogSyncService($this->db))->test($provider);
        $this->json($result);
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
            $this->respond(true, 'Comprobante contable registrado', '/app/contabilidad?tab=entries&entry=' . $id);
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
        $message = sprintf(
            'Sincronización: %d ventas, %d abonos y %d gastos contabilizados',
            $result['sales'],
            $result['payments'] ?? 0,
            $result['expenses']
        );
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

    private function exportReport(): void
    {
        $from = $this->validDate((string)$this->request->get('from', date('Y-m-01')), date('Y-m-01'));
        $to = $this->validDate((string)$this->request->get('to', date('Y-m-d')), date('Y-m-d'));
        $report = (string)$this->request->get('report', 'trial-balance');

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

        $rows = [];
        foreach ($this->accounting->trialBalance($from, $to) as $row) {
            $rows[] = [
                $row['code'], $row['name'], $row['opening_balance'],
                $row['debit'], $row['credit'], $row['closing_balance'],
            ];
        }
        $this->exportCsv(
            'balance_prueba_' . $from . '_' . $to . '.csv',
            ['Código', 'Cuenta', 'Saldo inicial', 'Debe', 'Haber', 'Saldo final'],
            $rows
        );
    }

    private function validDate(string $date, string $fallback): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : $fallback;
    }
}
