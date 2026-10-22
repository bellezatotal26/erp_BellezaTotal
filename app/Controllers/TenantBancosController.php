<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Core\TenantMiddleware;
use SoftNova\Services\BankingService;
use SoftNova\Services\BoldSettlementService;
use SoftNova\Services\CustomOptionService;
use SoftNova\Services\TenantAuditService;

/**
 * Bancos: cuentas por entidad + cuenta puente Bold, conciliación por extracto y liquidaciones Bold.
 */
class TenantBancosController extends TenantController
{
    private const BASE = '/app/bancos';
    private const MAX_UPLOAD = 10 * 1024 * 1024;
    private const UPLOAD_EXT = ['csv', 'txt', 'tsv', 'xlsx', 'xls'];

    private BankingService $banking;

    public function __construct()
    {
        parent::__construct();
        TenantMiddleware::authorize('contabilidad');
        $this->banking = new BankingService($this->db);
    }

    public function index(): void
    {
        $action = (string)$this->request->get('action', '');

        if ($this->request->method() === 'POST') {
            if (!$this->validateCsrfOrFail(self::BASE)) {
                return;
            }
            match ($action) {
                'save_account' => $this->saveAccount(),
                'save_mapping' => $this->saveMapping(),
                'add_payment_method' => $this->addPaymentMethod(),
                'import_statement' => $this->importStatement(),
                'auto_match' => $this->autoMatch(),
                'match_line' => $this->matchLine(),
                'unmatch_line' => $this->unmatchLine(),
                'ignore_line' => $this->ignoreLine(true),
                'restore_line' => $this->ignoreLine(false),
                'register_lines' => $this->registerLines(),
                'register_suggested' => $this->registerSuggested(),
                'cancel_posting' => $this->cancelPosting(),
                'delete_statement' => $this->deleteStatement(),
                'import_bold' => $this->importBold(),
                'cancel_bold' => $this->cancelBold(),
                default => $this->respond(false, 'Acción inválida', self::BASE),
            };
            return;
        }

        if ($action === 'export_recon') {
            $this->exportRecon();
            return;
        }

        $tab = (string)$this->request->get('tab', 'cuentas');
        if (!in_array($tab, ['cuentas', 'conciliacion', 'bold'], true)) {
            $tab = 'cuentas';
        }

        $data = [
            'tab' => $tab,
            'accounts' => [],
            'kinds' => BankingService::KINDS,
            'postingKinds' => BankingService::POSTING_KINDS,
            'lineStatus' => BankingService::LINE_STATUS,
            'methodOptions' => [],
            'collectionMap' => [],
            'collectionSince' => '',
            'editAccount' => null,
            'suggestedCodes' => [],
        ];
        try {
            $data['accounts'] = $this->banking->accounts();
            $data['methodOptions'] = $this->banking->methodOptions();
            $data['collectionMap'] = $this->banking->collectionMap();
            $data['collectionSince'] = $this->banking->collectionSince();
            $data['suggestedCodes'] = [
                'bank' => $this->banking->suggestCode('bank'),
                'wallet' => $this->banking->suggestCode('wallet'),
                'gateway' => $this->banking->suggestCode('gateway'),
            ];
            $editId = (int)$this->request->get('edit', 0);
            if ($editId > 0) {
                $data['editAccount'] = $this->banking->account($editId);
            }
            if ($tab === 'conciliacion') {
                $data += $this->reconciliationData($data['accounts']);
            } elseif ($tab === 'bold') {
                $data += $this->boldData($data['accounts']);
            }
        } catch (\Throwable $e) {
            error_log('Bancos index: ' . $e->getMessage());
            $_SESSION['error'] = 'No se pudo cargar Bancos: ' . $e->getMessage();
        }

        $this->view('tenant.bancos', $this->tenantViewData($data));
    }

    /** Descarga Excel de la conciliación: resumen + extracto sin cruzar + libros sin banco. */
    private function exportRecon(): void
    {
        $statementId = (int)$this->request->get('statement_id', 0);
        try {
            $recon = $this->banking->reconciliation($statementId);
            $account = $recon['account'];
            $rows = [];
            $rows[] = ['RESUMEN', (string)$recon['to'], 'Cuenta: ' . (string)($account['name'] ?? '') . ' (' . (string)($account['account_code'] ?? '') . ')', '', null, ''];
            $rows[] = ['RESUMEN', '', 'Saldo según banco', '', $recon['bank_balance'], ''];
            $rows[] = ['RESUMEN', '', 'Saldo en libros', '', (float)$recon['book_balance'], ''];
            $rows[] = ['RESUMEN', '', 'Extracto sin registrar (neto)', '', (float)$recon['pending_sum'], ''];
            $rows[] = ['RESUMEN', '', 'Libros que el banco no muestra (neto)', '', (float)$recon['outstanding_sum'], ''];
            $rows[] = ['RESUMEN', '', 'Diferencia por conciliar', '', $recon['difference'], ''];
            foreach ($this->banking->statementLines($statementId, 'pending') as $l) {
                $rows[] = [
                    'Extracto sin cruzar',
                    (string)$l['line_date'],
                    (string)($l['description'] ?? ''),
                    (string)($l['reference'] ?? ''),
                    (float)$l['amount'],
                    'Sin cruzar',
                ];
            }
            foreach ($recon['outstanding'] as $o) {
                $net = round((float)($o['debit'] ?? 0) - (float)($o['credit'] ?? 0), 2);
                $rows[] = [
                    'Libros sin banco',
                    (string)($o['entry_date'] ?? ''),
                    (string)(($o['entry_number'] ?? '') . ' · ' . ($o['description'] ?? '') . (($o['third_party_name'] ?? '') !== '' ? ' · ' . $o['third_party_name'] : '')),
                    (string)($o['entry_number'] ?? ''),
                    $net,
                    'Sin banco',
                ];
            }
            \SoftNova\Services\SimpleXlsxWriter::download(
                'conciliacion_' . preg_replace('/\D/', '', (string)($account['account_code'] ?? 'banco'))
                    . '_' . $statementId . '_' . date('Ymd_His') . '.xlsx',
                ['Tipo', 'Fecha', 'Descripción', 'Referencia', 'Valor', 'Estado'],
                $rows,
                [4 => 'currency']
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), self::BASE . '?tab=conciliacion&statement_id=' . $statementId);
        }
    }

    /** @param list<array<string,mixed>> $accounts */
    private function reconciliationData(array $accounts): array
    {
        $banks = array_values(array_filter($accounts, static fn ($a) => $a['kind'] !== 'gateway'));
        $statementId = (int)$this->request->get('statement_id', 0);
        $statement = $statementId > 0 ? $this->banking->statementRow($statementId) : null;
        $bankId = $statement ? (int)$statement['bank_account_id'] : (int)$this->request->get('bank_id', 0);
        if ($bankId <= 0 && $banks) {
            $bankId = (int)$banks[0]['id'];
        }
        $out = [
            'banks' => $banks,
            'bankId' => $bankId,
            'statements' => [],
            'postings' => [],
            'statement' => $statement,
            'recon' => null,
            'lines' => [],
            'candidates' => [],
            'lineFilter' => '',
            'counterAccounts' => $this->counterAccounts(),
        ];
        if ($bankId <= 0) {
            return $out;
        }
        $out['statements'] = $this->banking->statements($bankId);
        $out['postings'] = $this->banking->postings($bankId);
        if ($statement) {
            $filter = (string)$this->request->get('status', '');
            $out['lineFilter'] = isset(BankingService::LINE_STATUS[$filter]) ? $filter : '';
            $out['recon'] = $this->banking->reconciliation($statementId);
            $out['lines'] = $this->banking->statementLines($statementId, $out['lineFilter']);
            $account = $out['recon']['account'];
            $count = 0;
            foreach ($out['lines'] as $l) {
                if ($l['status'] !== 'pending' || $count >= 150) {
                    continue;
                }
                $count++;
                $cands = $this->banking->bookCandidates($account, (string)$l['line_date'], (float)$l['amount'], 15, 5, (string)($l['reference'] ?? ''));
                if ($cands) {
                    $out['candidates'][(int)$l['id']] = $cands;
                }
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $accounts */
    private function boldData(array $accounts): array
    {
        $bold = new BoldSettlementService($this->db);
        $gateways = array_values(array_filter($accounts, static fn ($a) => $a['kind'] === 'gateway' && (int)$a['is_active'] === 1));
        $banks = array_values(array_filter($accounts, static fn ($a) => $a['kind'] !== 'gateway' && (int)$a['is_active'] === 1));
        $gatewayId = (int)$this->request->get('gateway_id', 0);
        $gateway = null;
        foreach ($gateways as $g) {
            if ($gatewayId <= 0 || (int)$g['id'] === $gatewayId) {
                $gateway = $g;
                break;
            }
        }
        $settlementId = (int)$this->request->get('settlement_id', 0);
        return [
            'gateways' => $gateways,
            'banks' => $banks,
            'gateway' => $gateway,
            'bridgeBalance' => $gateway ? $bold->bridgeBalance($gateway) : 0.0,
            'gatewayMethods' => $gateway ? $this->banking->gatewayMethods((int)$gateway['id']) : [],
            'pendingSales' => $gateway ? $bold->pendingSales((int)$gateway['id']) : ['count' => 0, 'total' => 0.0, 'rows' => []],
            'settlements' => $bold->settlements(),
            'settlementDetail' => $settlementId > 0 ? $bold->settlement($settlementId) : null,
            'unmatchedTx' => $bold->unmatchedTransactions(),
            'methodGroups' => BoldSettlementService::METHOD_GROUPS,
        ];
    }

    /** @return list<array{code:string,name:string}> */
    private function counterAccounts(): array
    {
        try {
            return $this->db->query(
                "SELECT code, name FROM accounting_accounts WHERE status = 'active' AND accepts_entries = 1 ORDER BY code LIMIT 3000"
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function saveAccount(): void
    {
        $id = (int)$this->request->post('id', 0);
        TenantMiddleware::authorize('contabilidad', $id > 0 ? 'edit' : 'create');
        try {
            $savedId = $this->banking->saveAccount([
                'id' => $id,
                'name' => $this->request->post('name', ''),
                'kind' => $this->request->post('kind', 'bank'),
                'bank_name' => $this->request->post('bank_name', ''),
                'account_number' => $this->request->post('account_number', ''),
                'account_code' => $this->request->post('account_code', ''),
                'provider' => $this->request->post('provider', 'bold'),
                'settle_bank_account_id' => $this->request->post('settle_bank_account_id', 0),
                'reconcile_from' => $this->request->post('reconcile_from', ''),
                'is_active' => $this->request->post('is_active', '0') === '1',
                'notes' => $this->request->post('notes', ''),
            ]);
            TenantAuditService::safeLog(
                $this->db,
                $id > 0 ? 'update' : 'create',
                'bancos',
                ($id > 0 ? 'Cuenta bancaria actualizada: ' : 'Cuenta bancaria creada: ') . trim((string)$this->request->post('name', '')),
                'bank_account',
                $savedId
            );
            $this->respond(true, $id > 0 ? 'Cuenta actualizada' : 'Cuenta creada', self::BASE . '?tab=cuentas');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), self::BASE . '?tab=cuentas' . ($id > 0 ? '&edit=' . $id : ''));
        }
    }

    /** Agrega un medio de pago personalizado: aparece en esta asignación y al cobrar en Ventas. */
    private function addPaymentMethod(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        try {
            $label = trim((string)$this->request->post('new_payment_method', ''));
            $opt = (new CustomOptionService($this->db))->add(
                CustomOptionService::GROUP_PAYMENT_METHOD,
                $label
            );
            TenantAuditService::safeLog(
                $this->db,
                'create',
                'bancos',
                'Medio de pago creado: ' . (string)($opt['label'] ?? $label),
                null,
                null,
                ['value' => (string)($opt['value'] ?? '')]
            );
            $this->respond(
                true,
                'Medio de pago «' . (string)($opt['label'] ?? $label) . '» creado. Ya puede asignarle una cuenta abajo.',
                self::BASE . '?tab=cuentas#bk-cobros'
            );
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), self::BASE . '?tab=cuentas#bk-cobros');
        }
    }

    private function saveMapping(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        try {
            $map = $_POST['map'] ?? [];
            $this->banking->saveCollectionMap(is_array($map) ? $map : [], (string)$this->request->post('since', ''));
            TenantAuditService::safeLog(
                $this->db,
                'update',
                'bancos',
                'Asignación de medios de pago a cuentas bancarias actualizada',
                null,
                null,
                ['map' => is_array($map) ? array_filter($map) : [], 'since' => $this->banking->collectionSince()]
            );
            $this->respond(true, 'Asignación guardada. Los cobros desde ' . $this->fmtDate($this->banking->collectionSince()) . ' se contabilizan en la cuenta elegida.', self::BASE . '?tab=cuentas');
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), self::BASE . '?tab=cuentas');
        }
    }

    private function importStatement(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        $bankId = (int)$this->request->post('bank_id', 0);
        $back = self::BASE . '?tab=conciliacion&bank_id=' . $bankId;
        try {
            [$path, $name] = $this->uploadedFile('file');
            $result = $this->banking->importStatement($bankId, $path, $name, $this->overrides([
                'col_date', 'col_desc', 'col_ref', 'col_amount', 'col_debit', 'col_credit', 'col_balance', 'first_row', 'invert_sign',
            ]));
            TenantAuditService::safeLog(
                $this->db,
                'create',
                'bancos',
                'Extracto importado: ' . $name . ' (' . $result['inserted'] . ' movimientos)',
                'bank_statement',
                (int)$result['statement_id'],
                ['duplicates' => $result['duplicates'], 'matched' => $result['matched']]
            );
            $msg = 'Extracto importado: ' . $result['inserted'] . ' movimiento(s), ' . $result['matched'] . ' cruzado(s) automáticamente';
            if ($result['duplicates'] > 0) {
                $msg .= ', ' . $result['duplicates'] . ' ya existían (omitidos)';
            }
            if ($result['skipped'] > 0) {
                $msg .= ', ' . $result['skipped'] . ' fila(s) sin fecha o valor';
            }
            if (!empty($result['continuity'])) {
                $msg .= ' ⚠ ' . $result['continuity'];
            }
            $msg .= '. Columnas usadas: ' . $this->columnsText($result['columns']);
            $this->respond(true, $msg, self::BASE . '?tab=conciliacion&statement_id=' . (int)$result['statement_id']);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }

    private function autoMatch(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $statementId = (int)$this->request->post('statement_id', 0);
        try {
            $n = $this->banking->autoMatch($statementId);
            $this->respond(true, $n > 0 ? $n . ' movimiento(s) cruzados' : 'No se encontraron nuevos cruces', $this->statementUrl($statementId));
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $this->statementUrl($statementId));
        }
    }

    private function matchLine(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $statementId = (int)$this->request->post('statement_id', 0);
        try {
            $this->banking->matchLine((int)$this->request->post('line_id', 0), (int)$this->request->post('accounting_line_id', 0));
            $this->respond(true, 'Movimiento cruzado', $this->statementUrl($statementId));
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $this->statementUrl($statementId));
        }
    }

    private function unmatchLine(): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $statementId = (int)$this->request->post('statement_id', 0);
        $this->banking->unmatchLine((int)$this->request->post('line_id', 0));
        $this->respond(true, 'Cruce deshecho', $this->statementUrl($statementId));
    }

    private function ignoreLine(bool $ignore): void
    {
        TenantMiddleware::authorize('contabilidad', 'edit');
        $statementId = (int)$this->request->post('statement_id', 0);
        $this->banking->setIgnored((int)$this->request->post('line_id', 0), $ignore, (string)$this->request->post('note', ''));
        $this->respond(true, $ignore ? 'Movimiento ignorado' : 'Movimiento restaurado', $this->statementUrl($statementId));
    }

    private function registerLines(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        $statementId = (int)$this->request->post('statement_id', 0);
        try {
            $ids = $_POST['line_ids'] ?? [];
            $kind = (string)$this->request->post('kind', '');
            $counter = $kind === 'transfer'
                ? (string)$this->request->post('counter_bank', '')
                : (string)$this->request->post('counter_account', '');
            $postings = $this->banking->registerLines(
                is_array($ids) ? $ids : [],
                $kind,
                $counter,
                trim((string)$this->request->post('note', ''))
            );
            foreach ($postings as $pid) {
                TenantAuditService::safeLog($this->db, 'create', 'bancos', 'Movimientos del extracto registrados (' . $kind . ')', 'bank_posting', $pid);
            }
            $this->respond(true, count($postings) . ' asiento(s) creados desde el extracto', $this->statementUrl($statementId));
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $this->statementUrl($statementId));
        }
    }

    private function registerSuggested(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        $statementId = (int)$this->request->post('statement_id', 0);
        try {
            $r = $this->banking->registerSuggested($statementId);
            if ($r['lines'] === 0) {
                $this->respond(false, 'No hay movimientos sugeridos pendientes (4x1000, comisiones, intereses o retenciones)', $this->statementUrl($statementId));
                return;
            }
            TenantAuditService::safeLog($this->db, 'create', 'bancos', 'Sugeridos registrados: ' . $r['lines'] . ' movimientos', 'bank_statement', $statementId);
            $this->respond(true, $r['lines'] . ' movimiento(s) registrados en ' . $r['postings'] . ' asiento(s)', $this->statementUrl($statementId));
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $this->statementUrl($statementId));
        }
    }

    private function cancelPosting(): void
    {
        TenantMiddleware::authorize('contabilidad', 'delete');
        $statementId = (int)$this->request->post('statement_id', 0);
        $bankId = (int)$this->request->post('bank_id', 0);
        $back = $statementId > 0 ? $this->statementUrl($statementId) : self::BASE . '?tab=conciliacion&bank_id=' . $bankId;
        $postingId = (int)$this->request->post('posting_id', 0);
        try {
            $reason = (string)$this->request->post('reason', '');
            $this->banking->cancelPosting($postingId, $reason);
            TenantAuditService::safeLog($this->db, 'delete', 'bancos', 'Registro bancario anulado: ' . $reason, 'bank_posting', $postingId, [], 'warning');
            $this->respond(true, 'Registro anulado: asiento revertido y movimientos devueltos a «Sin cruzar»', $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }

    private function deleteStatement(): void
    {
        TenantMiddleware::authorize('contabilidad', 'delete');
        $statementId = (int)$this->request->post('statement_id', 0);
        $statement = $this->banking->statementRow($statementId);
        $back = self::BASE . '?tab=conciliacion' . ($statement ? '&bank_id=' . (int)$statement['bank_account_id'] : '');
        try {
            $this->banking->deleteStatement($statementId);
            TenantAuditService::safeLog(
                $this->db,
                'delete',
                'bancos',
                'Extracto eliminado: ' . ($statement['file_name'] ?? ('#' . $statementId)),
                'bank_statement',
                $statementId,
                [],
                'warning'
            );
            $this->respond(true, 'Extracto eliminado', $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $statement ? $this->statementUrl($statementId) : $back);
        }
    }

    private function importBold(): void
    {
        TenantMiddleware::authorize('contabilidad', 'create');
        $gatewayId = (int)$this->request->post('gateway_id', 0);
        $back = self::BASE . '?tab=bold&gateway_id=' . $gatewayId;
        try {
            [$path, $name] = $this->uploadedFile('file');
            $bold = new BoldSettlementService($this->db);
            $r = $bold->import($gatewayId, (int)$this->request->post('bank_id', 0), $path, $name, $this->overrides([
                'col_date', 'col_deposit', 'col_ref', 'col_gross', 'col_commission', 'col_vat', 'col_ret_renta', 'col_ret_iva',
                'col_ret_ica', 'col_net', 'col_method', 'col_status', 'first_row',
            ]));
            TenantAuditService::safeLog(
                $this->db,
                'create',
                'bancos',
                'Liquidaciones Bold importadas: ' . $name . ' (' . $r['transactions'] . ' transacciones, ' . $r['settlements'] . ' depósitos)',
                null,
                null,
                ['gross' => $r['gross'], 'net' => $r['net']]
            );
            $msg = $r['settlements'] . ' liquidación(es) registradas con ' . $r['transactions'] . ' transacción(es): bruto '
                . number_format($r['gross'], 0, ',', '.') . ', neto ' . number_format($r['net'], 0, ',', '.')
                . '. ' . $r['matched_sales'] . ' asociadas a ventas del sistema.';
            if ($r['duplicates'] > 0) {
                $msg .= ' ' . $r['duplicates'] . ' ya estaban liquidadas (omitidas).';
            }
            if ($r['skipped_before'] > 0) {
                $msg .= ' ' . $r['skipped_before'] . ' anteriores a la fecha de activación (omitidas).';
            }
            if ($r['skipped_status'] > 0) {
                $msg .= ' ' . $r['skipped_status'] . ' rechazadas/anuladas (omitidas).';
            }
            $msg .= ' Columnas usadas: ' . $this->columnsText($r['columns']);
            $this->respond(true, $msg, $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back);
        }
    }

    private function cancelBold(): void
    {
        TenantMiddleware::authorize('contabilidad', 'delete');
        $id = (int)$this->request->post('settlement_id', 0);
        $back = self::BASE . '?tab=bold';
        try {
            $reason = (string)$this->request->post('reason', '');
            (new BoldSettlementService($this->db))->cancel($id, $reason);
            TenantAuditService::safeLog($this->db, 'delete', 'bancos', 'Liquidación Bold anulada: ' . $reason, 'bold_settlement', $id, [], 'warning');
            $this->respond(true, 'Liquidación anulada: asiento revertido, gasto de comisión anulado y transacciones liberadas para reimportar', $back);
        } catch (\Throwable $e) {
            $this->respond(false, $e->getMessage(), $back . '&settlement_id=' . $id);
        }
    }

    /** @return array{0:string,1:string} ruta temporal y nombre original */
    private function uploadedFile(string $field): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('Seleccione el archivo');
        }
        if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            throw new \RuntimeException('No se pudo subir el archivo (código ' . (int)$f['error'] . ')');
        }
        if ((int)$f['size'] <= 0 || (int)$f['size'] > self::MAX_UPLOAD) {
            throw new \InvalidArgumentException('El archivo debe pesar menos de 10 MB');
        }
        $name = basename((string)$f['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::UPLOAD_EXT, true)) {
            throw new \InvalidArgumentException('Formato no soportado. Use CSV, TXT o Excel (.xlsx)');
        }
        return [(string)$f['tmp_name'], $name];
    }

    /** @param list<string> $keys */
    private function overrides(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = trim((string)$this->request->post($k, ''));
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,string> $columns */
    private function columnsText(array $columns): string
    {
        $labels = [
            'date' => 'fecha', 'tx_date' => 'fecha', 'deposit_date' => 'fecha depósito', 'description' => 'descripción',
            'reference' => 'referencia', 'amount' => 'valor', 'debit' => 'débito', 'credit' => 'crédito', 'balance' => 'saldo',
            'type' => 'tipo', 'gross' => 'bruto', 'net' => 'neto', 'commission' => 'comisión', 'vat' => 'IVA',
            'ret_renta' => 'retefuente', 'ret_iva' => 'reteIVA', 'ret_ica' => 'reteICA', 'method' => 'medio', 'status' => 'estado',
        ];
        $parts = [];
        foreach ($columns as $field => $label) {
            $parts[] = ($labels[$field] ?? $field) . ' = «' . $label . '»';
        }
        return $parts ? implode(', ', $parts) : '—';
    }

    private function statementUrl(int $statementId): string
    {
        return self::BASE . '?tab=conciliacion&statement_id=' . $statementId;
    }

    private function fmtDate(string $date): string
    {
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : $date;
    }
}
