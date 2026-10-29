<?php
$layout = 'tenant';
$title = 'Contabilidad - Seri ERP';
$pageTitle = 'Contabilidad';
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$tab = $tab ?? 'dashboard';
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo = $dateTo ?? date('Y-m-d');
$accounts = $accounts ?? [];
$entries = $entries ?? [];
$trialBalance = $trialBalance ?? [];
$trialReport = $trialReport ?? null;
$monthlyBalance = $monthlyBalance ?? ['year' => (int)date('Y'), 'months' => [], 'totals' => [], 'chart' => []];
$balanceYear = (int)($balanceYear ?? ($monthlyBalance['year'] ?? date('Y')));
$trialAccountType = $trialAccountType ?? '';
$trialQ = $trialQ ?? '';
$trialIncludeZero = !empty($trialIncludeZero);
$trialView = ($trialView ?? '') === 'accounts' ? 'accounts' : 'operational';
$statements = $statements ?? [];
$ledger = $ledger ?? [];
$periods = $periods ?? [];
$selectedAccountId = $selectedAccountId ?? 0;
$integrationStatuses = $integrationStatuses ?? [];
$activeProvider = $activeProvider ?? null;
$selectedProvider = (string)($_GET['provider'] ?? ($activeProvider ?: 'alegra'));
if (!isset($integrationStatuses[$selectedProvider])) {
    $selectedProvider = 'alegra';
}
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'contabilidad');
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'contabilidad');
$canExport = \SoftNova\Core\TenantMiddleware::canDo('export', 'contabilidad');
$canDelete = \SoftNova\Core\TenantMiddleware::canDo('delete', 'contabilidad');

$purchaseSummary = $purchaseSummary ?? ['cnt' => 0, 'total' => 0];
$expenseByType = $expenseByType ?? [];
$traceMovements = $traceMovements ?? [];
$accountAudit = $accountAudit ?? ['settings' => [], 'notes' => [], 'fixed' => [], 'missing' => []];

function acctFmt(float $amount, array $currency): string
{
    return ($currency['symbol'] ?? '$') . ' ' . number_format(
        $amount,
        (int)($currency['decimals'] ?? 2),
        $currency['decimal'] ?? ',',
        $currency['thousands'] ?? '.'
    );
}

$types = [
    'asset' => 'Activo',
    'liability' => 'Pasivo',
    'equity' => 'Patrimonio',
    'revenue' => 'Ingreso',
    'expense' => 'Gasto',
];
$tabs = [
    'dashboard' => 'Resumen',
    'compras' => 'Compras',
    'gastos' => 'Gastos',
    'invoice-trace' => 'Trazabilidad facturas',
    'vat' => 'Libro IVA',
    'sales-book' => 'Libro ventas / ingresos',
    'withholdings' => 'Retenciones',
    'trace' => 'Inventario',
    'entries' => 'Libro diario',
    'ledger' => 'Libro mayor',
    'trial' => 'Balance',
    'statements' => 'Estados financieros',
    'accounts' => 'Plan de cuentas',
    'commissions' => 'Comisiones',
    'periods' => 'Periodos',
    'integrations' => 'Integraciones',
];
$invoiceTraceOrders = $invoiceTraceOrders ?? [];
$invoiceTraceQ = $invoiceTraceQ ?? '';
$invoiceTraceSort = $invoiceTraceSort ?? 'date';
$invoiceTraceDir = $invoiceTraceDir ?? 'desc';
$vatBook = $vatBook ?? null;
$salesBook = $salesBook ?? null;
$generalLedger = $generalLedger ?? ['rows' => [], 'totals' => [], 'balanced' => [], 'mode' => 'subaccounts'];
$ledgerMode = $ledgerMode ?? 'subaccounts';
$withholdings = $withholdings ?? null;
$statusLabelsPo = [
    'draft' => 'Borrador',
    'ordered' => 'Ordenada',
    'received' => 'Recibida',
    'cancelled' => 'Anulada',
];
$commissionCfg = $commissionCfg ?? [];
$commissionList = $commissionList ?? ['rows' => [], 'pending' => 0, 'paid' => 0, 'cancelled' => 0];
$commissionUsers = $commissionUsers ?? [];
$kindFilter = (string)($_GET['kind'] ?? '');
$statusFilterComm = (string)($_GET['status'] ?? '');
$accountOptions = '';
foreach ($accounts as $account) {
    if ($account['status'] !== 'active' || empty($account['accepts_entries'])) {
        continue;
    }
    $accountOptions .= '<option value="' . htmlspecialchars($account['code']) . '">'
        . htmlspecialchars($account['code'] . ' - ' . $account['name']) . '</option>';
}
?>

<?php echo flashMessage(); ?>

<div class="alert alert-info" style="margin-bottom:16px;">
    <strong>Contabilidad nativa.</strong>
    Libro mayor = síntesis de partida doble; Retenciones (ReteFuente / ReteIVA / ReteICA) y Libro de ventas/ingresos son independientes.
    Antes de usarlos como información oficial, un contador debe validar cuentas, impuestos y políticas de la empresa.
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:12px 16px;">
        <div class="accounting-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="btn btn-sm <?php echo $tab === $key ? 'btn-primary' : 'btn-secondary'; ?>"
                   href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=<?php echo $key; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">
                    <?php echo htmlspecialchars($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $viewInstance->route('app/contabilidad'); ?>"
              style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <?php if ($tab === 'ledger'): ?>
                <div class="form-group" style="margin:0;min-width:260px;flex:1;">
                    <label>Cuenta (detalle opcional)</label>
                    <select name="account_id" class="form-control">
                        <option value="">Síntesis (todas las cuentas)</option>
                        <?php foreach ($types as $typeKey => $typeLabel): ?>
                            <optgroup label="<?php echo htmlspecialchars($typeLabel); ?>">
                            <?php foreach ($accounts as $account): ?>
                                <?php if (($account['account_type'] ?? '') !== $typeKey) { continue; } ?>
                                <option value="<?php echo (int)$account['id']; ?>" <?php echo (int)$account['id'] === (int)$selectedAccountId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['code'] . ' - ' . $account['name'] . (($account['status'] ?? 'active') !== 'active' ? ' (inactiva)' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ((int)$selectedAccountId <= 0): ?>
                    <div class="form-group" style="margin:0;min-width:160px;">
                        <label>Nivel de síntesis</label>
                        <select name="ledger_mode" class="form-control">
                            <option value="subaccounts" <?php echo $ledgerMode === 'subaccounts' ? 'selected' : ''; ?>>Subcuenta</option>
                            <option value="accounts" <?php echo $ledgerMode === 'accounts' ? 'selected' : ''; ?>>Cuenta (4 dígitos)</option>
                            <option value="groups" <?php echo $ledgerMode === 'groups' ? 'selected' : ''; ?>>Grupo (2 dígitos)</option>
                            <option value="classes" <?php echo $ledgerMode === 'classes' ? 'selected' : ''; ?>>Clase (1 dígito)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;min-width:140px;">
                        <label>Tipo</label>
                        <select name="account_type" class="form-control">
                            <option value="">Todas</option>
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $trialAccountType === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;min-width:160px;flex:1;">
                        <label>Buscar cuenta</label>
                        <input class="form-control" type="search" name="q" value="<?php echo htmlspecialchars($trialQ); ?>" placeholder="Código o nombre">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label style="display:flex;gap:6px;align-items:center;white-space:nowrap;">
                            <input type="checkbox" name="include_zero" value="1" <?php echo $trialIncludeZero ? 'checked' : ''; ?>> Incluir ceros
                        </label>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="form-group" style="margin:0;"><label>Desde</label><input class="form-control" type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>"></div>
            <div class="form-group" style="margin:0;"><label>Hasta</label><input class="form-control" type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>"></div>
            <?php if ($tab === 'ledger' && (int)$selectedAccountId > 0): ?>
                <div class="form-group" style="margin:0;min-width:180px;">
                    <label>Buscar en movimientos</label>
                    <input class="form-control" type="search" name="lq" value="<?php echo htmlspecialchars((string)($ledgerQ ?? '')); ?>" placeholder="Concepto, tercero o comprobante">
                </div>
            <?php endif; ?>
            <?php if ($tab === 'trial' && $trialView === 'accounts'): ?>
                <input type="hidden" name="view" value="accounts">
                <div class="form-group" style="margin:0;min-width:150px;">
                    <label>Tipo de cuenta</label>
                    <select name="account_type" class="form-control">
                        <option value="">Todas</option>
                        <?php foreach ($types as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $trialAccountType === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:200px;flex:1;">
                    <label>Buscar cuenta</label>
                    <input class="form-control" type="search" name="q" value="<?php echo htmlspecialchars($trialQ); ?>" placeholder="Código o nombre">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="display:flex;gap:6px;align-items:center;white-space:nowrap;">
                        <input type="checkbox" name="include_zero" value="1" <?php echo $trialIncludeZero ? 'checked' : ''; ?>> Incluir cuentas en cero
                    </label>
                </div>
            <?php elseif ($tab === 'trial'): ?>
                <div class="form-group" style="margin:0;min-width:140px;">
                    <label>Año del Balance</label>
                    <select name="year" class="form-control" title="El Balance muestra los 12 meses de este año">
                        <?php
                        $yNow = (int)date('Y');
                        for ($y = $yNow; $y >= $yNow - 6; $y--):
                        ?>
                            <option value="<?php echo $y; ?>" <?php echo $balanceYear === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($tab === 'invoice-trace'): ?>
                <div class="form-group" style="margin:0;min-width:220px;flex:1;">
                    <label>Buscar</label>
                    <input class="form-control" type="search" name="q" value="<?php echo htmlspecialchars($invoiceTraceQ); ?>" placeholder="OC, factura o proveedor">
                </div>
                <input type="hidden" name="trace_sort" value="<?php echo htmlspecialchars($invoiceTraceSort); ?>">
                <input type="hidden" name="trace_dir" value="<?php echo htmlspecialchars($invoiceTraceDir); ?>">
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">Aplicar</button>
        </form>
    </div>
</div>

<?php if ($tab === 'dashboard'): ?>
    <?php
    $totals = $statements['totals'] ?? [];
    $profit = (float)($statements['profit'] ?? 0);
    ?>
    <?php if ($canCreate): ?>
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-bottom:12px;">
            <?php if ((int)($pendingSync ?? 0) > 0): ?>
                <span style="font-size:13px;color:var(--dmc-DC2626, #DC2626);font-weight:600;">
                    <?php echo (int)$pendingSync; ?> operación(es) sin comprobante contable
                    <?php if ((int)($pendingQueue ?? 0) > 0): ?>
                        <span style="font-weight:500;color:var(--color-text-secondary);">
                            (<?php echo (int)$pendingQueue; ?> en cola de reintento)
                        </span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=sync" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <button type="submit" class="btn btn-secondary" onclick="return confirm('¿Contabilizar las operaciones anteriores que aún no tienen asiento? Incluye ventas, abonos, NC, gastos, compras, inventario, caja y la cola de reintentos.')">
                    Sincronizar operaciones existentes
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php $reconciliation = $reconciliation ?? []; ?>
    <?php
    $entryIntegrity = $entryIntegrity ?? ['ok' => true, 'unbalanced' => [], 'bad_lines' => 0];
    if (empty($entryIntegrity['ok'])):
        $unb = $entryIntegrity['unbalanced'] ?? [];
        $badLn = (int)($entryIntegrity['bad_lines'] ?? 0);
    ?>
        <div class="card neumorphic" style="margin-bottom:18px;border:1px solid rgba(220,38,38,.35);">
            <div class="card-header"><h3 style="margin:0;color:var(--dmc-DC2626,#DC2626);">Integridad del libro</h3></div>
            <div class="card-body" style="font-size:13px;">
                <?php if ($unb): ?>
                    <p style="margin:0 0 8px;font-weight:600;"><?php echo count($unb); ?> comprobante(s) con Debe ≠ Haber</p>
                    <ul style="margin:0 0 10px;padding-left:18px;">
                        <?php foreach (array_slice($unb, 0, 8) as $u): ?>
                            <li>
                                <?php echo htmlspecialchars((string)($u['entry_number'] ?? '')); ?>
                                · <?php echo htmlspecialchars((string)($u['entry_date'] ?? '')); ?>
                                · diff <?php echo acctFmt((float)($u['diff'] ?? 0), $currency); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($badLn > 0): ?>
                    <p style="margin:0;color:var(--dmc-B45309,#B45309);"><?php echo $badLn; ?> línea(s) con débito y crédito a la vez, o ambas en cero.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($reconciliation)): ?>
        <p style="margin:0 0 14px;font-size:12px;color:var(--color-text-secondary);">
            <a href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=dashboard&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&diagnostics=1">
                Ver conciliación operativa vs contable
            </a>
            (consulta pesada; se carga aparte para no bloquear el dashboard)
        </p>
    <?php endif; ?>
    <?php if (!empty($reconciliation)): ?>
        <div class="card neumorphic" style="margin-bottom:18px;">
            <div class="card-header"><h3>Conciliación operativa vs contable</h3></div>
            <div class="card-body">
                <div class="table-container"><table>
                    <thead><tr><th>Concepto</th><th>Cuenta</th><th>Saldo operativo</th><th>Saldo contable</th><th>Diferencia</th></tr></thead>
                    <tbody>
                    <?php foreach ($reconciliation as $rec): ?>
                        <?php $cuadra = abs((float)$rec['gap']) < 1; ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($rec['label']); ?>
                                <?php if (!$cuadra): ?>
                                    <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars($rec['hint']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($rec['account']); ?></td>
                            <td><?php echo acctFmt((float)$rec['operational'], $currency); ?></td>
                            <td><?php echo acctFmt((float)$rec['accounting'], $currency); ?></td>
                            <td style="font-weight:600;color:<?php echo $cuadra ? '#10B981' : '#DC2626'; ?>;">
                                <?php echo acctFmt((float)$rec['gap'], $currency); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    <?php endif; ?>
    <div class="stats-grid">
        <div class="stat-card neumorphic"><h4>Activos</h4><div class="stat-value"><?php echo acctFmt((float)($totals['assets'] ?? 0), $currency); ?></div></div>
        <div class="stat-card neumorphic"><h4>Pasivos</h4><div class="stat-value"><?php echo acctFmt((float)($totals['liabilities'] ?? 0), $currency); ?></div></div>
        <div class="stat-card neumorphic"><h4>Ingresos</h4><div class="stat-value" style="color:#10B981;"><?php echo acctFmt((float)($totals['revenue'] ?? 0), $currency); ?></div></div>
        <div class="stat-card neumorphic"><h4>Resultado</h4><div class="stat-value" style="color:<?php echo $profit >= 0 ? '#10B981' : '#DC2626'; ?>;"><?php echo acctFmt($profit, $currency); ?></div></div>
    </div>
    <div class="card neumorphic">
        <div class="card-header"><h3>Últimos comprobantes</h3></div>
        <div class="card-body">
            <?php $dashboardEntries = array_slice($entries, 0, 8); ?>
            <?php if (!$dashboardEntries): ?>
                <p class="dashboard-empty">No hay movimientos contables en el periodo.</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Comprobante</th><th>Fecha</th><th>Concepto</th><th>Usuario</th><th>Debe</th><th>Haber</th><th></th></tr></thead>
                    <tbody><?php foreach ($dashboardEntries as $entry): ?><tr>
                        <td><?php echo htmlspecialchars($entry['entry_number']); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($entry['entry_date'])); ?></td>
                        <td><?php echo htmlspecialchars($entry['description']); ?></td>
                        <td><?php echo htmlspecialchars((string)($entry['created_by_name'] ?? '—')); ?></td>
                        <td><?php echo acctFmt((float)$entry['total_debit'], $currency); ?></td>
                        <td><?php echo acctFmt((float)$entry['total_credit'], $currency); ?></td>
                        <td><button type="button" class="btn btn-sm btn-info" onclick="showAccountingEntry(<?php echo (int)$entry['id']; ?>)">Ver detalle</button></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'entries'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px;flex-wrap:wrap;">
        <h3 style="margin:0;">Libro diario</h3>
        <div style="display:flex;gap:8px;">
            <?php if ($canExport): ?>
                <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=export&report=journal&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">Exportar CSV</a>
            <?php endif; ?>
            <?php if ($canCreate): ?><button type="button" class="btn btn-primary" onclick="openModal('manualEntryModal')">Nuevo comprobante</button><?php endif; ?>
        </div>
    </div>
    <div class="card neumorphic"><div class="card-body">
        <div class="table-container"><table>
            <thead><tr><th>Comprobante</th><th>Fecha</th><th>Concepto</th><th>Origen</th><th>Usuario</th><th>Debe</th><th>Haber</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php if (!$entries): ?><tr><td colspan="9" class="dashboard-empty">Sin comprobantes</td></tr><?php endif; ?>
            <?php foreach ($entries as $entry): ?><tr>
                <td><?php echo htmlspecialchars($entry['entry_number']); ?></td>
                <td><?php echo date('d/m/Y', strtotime($entry['entry_date'])); ?></td>
                <td><?php echo htmlspecialchars($entry['description']); ?></td>
                <td><?php echo htmlspecialchars($entry['source_type'] ?: 'Manual'); ?></td>
                <td><?php echo htmlspecialchars((string)($entry['created_by_name'] ?? '—')); ?></td>
                <td><?php echo acctFmt((float)$entry['total_debit'], $currency); ?></td>
                <td><?php echo acctFmt((float)$entry['total_credit'], $currency); ?></td>
                <td><span class="badge <?php echo $entry['status'] === 'posted' ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars(['posted' => 'Contabilizado', 'reversed' => 'Anulado', 'draft' => 'Borrador'][$entry['status']] ?? $entry['status']); ?></span></td>
                <td><button type="button" class="btn btn-sm btn-info" onclick="showAccountingEntry(<?php echo (int)$entry['id']; ?>)">Ver detalle</button></td>
            </tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>

<?php elseif ($tab === 'ledger'): ?>
    <?php
    $ledgerOpening = (float)($ledgerOpening ?? 0);
    $lr = is_array($ledgerReport ?? null) ? $ledgerReport : [];
    $ledgerAccount = $lr['account'] ?? null;
    $ledgerSign = (($ledgerAccount['nature'] ?? 'debit') === 'credit') ? -1 : 1;
    $ledgerFiltered = !empty($lr['filtered']);
    $ledgerBase = $viewInstance->route('app/contabilidad');
    $gl = is_array($generalLedger ?? null) ? $generalLedger : [];
    $glRows = $gl['rows'] ?? [];
    $glTot = $gl['totals'] ?? [];
    $glBal = $gl['balanced'] ?? [];
    $ledgerLink = static function (int $accountId) use ($ledgerBase, $dateFrom, $dateTo): string {
        return $ledgerBase . '?tab=ledger&account_id=' . $accountId . '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo);
    };
    $trCell = static function ($v) use ($currency): string {
        return abs((float)$v) < 0.009 ? '—' : acctFmt((float)$v, $currency);
    };
    ?>
    <?php if (!$selectedAccountId || !$ledgerAccount): ?>
        <div class="card neumorphic">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div>
                    <h3 style="margin:0;">Libro mayor — síntesis contable</h3>
                    <p style="margin:4px 0 0;font-size:12px;color:var(--color-text-secondary);">
                        Saldos iniciales, débitos, créditos y saldos finales por cuenta
                        (<?php echo htmlspecialchars((string)($gl['mode_label'] ?? 'Por subcuenta')); ?>).
                        Audita la partida doble y alimenta los estados financieros.
                        Retenciones → <a href="<?php echo $ledgerBase; ?>?tab=withholdings&amp;from=<?php echo urlencode($dateFrom); ?>&amp;to=<?php echo urlencode($dateTo); ?>">pestaña Retenciones</a>.
                    </p>
                </div>
                <?php if ($canExport): ?>
                    <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($ledgerBase . '?' . http_build_query([
                        'action' => 'export', 'report' => 'general-ledger',
                        'from' => $dateFrom, 'to' => $dateTo,
                        'ledger_mode' => $ledgerMode,
                        'account_type' => $trialAccountType,
                        'q' => $trialQ,
                        'include_zero' => $trialIncludeZero ? '1' : '',
                    ])); ?>">Exportar CSV</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                    <?php
                    $pill = static function (bool $ok, string $label): string {
                        $cls = $ok ? 'badge-success' : 'badge-danger';
                        $txt = $ok ? 'Cuadra' : 'Descuadre';
                        return '<span class="badge ' . $cls . '">' . htmlspecialchars($label . ': ' . $txt) . '</span>';
                    };
                    echo $pill(!empty($glBal['opening']), 'Apertura');
                    echo ' ';
                    echo $pill(!empty($glBal['period']), 'Periodo');
                    echo ' ';
                    echo $pill(!empty($glBal['closing']), 'Cierre');
                    ?>
                </div>
                <?php if (!$glRows): ?>
                    <p class="dashboard-empty">Sin movimientos en el rango (o todas las cuentas en cero).</p>
                <?php else: ?>
                    <div class="table-container"><table class="trial-table">
                        <thead><tr>
                            <th>Código</th><th>Cuenta / grupo</th><th>Tipo</th>
                            <th class="num">Si Debe</th><th class="num">Si Haber</th>
                            <th class="num">Debe</th><th class="num">Haber</th>
                            <th class="num">Sf Debe</th><th class="num">Sf Haber</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($glRows as $row): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['id'])): ?>
                                        <a href="<?php echo $ledgerLink((int)$row['id']); ?>"><strong><?php echo htmlspecialchars((string)$row['code']); ?></strong></a>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars((string)$row['code']); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars((string)$row['name']); ?>
                                    <?php if (!empty($row['accounts_count']) && (int)$row['accounts_count'] > 1): ?>
                                        <div style="font-size:11px;color:var(--color-text-secondary);"><?php echo (int)$row['accounts_count']; ?> subcuentas</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)($row['account_type_label'] ?? '')); ?></td>
                                <td class="num"><?php echo $trCell($row['opening_debit'] ?? 0); ?></td>
                                <td class="num"><?php echo $trCell($row['opening_credit'] ?? 0); ?></td>
                                <td class="num"><?php echo $trCell($row['debit'] ?? 0); ?></td>
                                <td class="num"><?php echo $trCell($row['credit'] ?? 0); ?></td>
                                <td class="num"><?php echo $trCell($row['closing_debit'] ?? 0); ?></td>
                                <td class="num"><?php echo $trCell($row['closing_credit'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="trial-total">
                            <td colspan="3">TOTALES (<?php echo count($glRows); ?> filas)</td>
                            <td class="num"><?php echo acctFmt((float)($glTot['opening_debit'] ?? 0), $currency); ?></td>
                            <td class="num"><?php echo acctFmt((float)($glTot['opening_credit'] ?? 0), $currency); ?></td>
                            <td class="num"><?php echo acctFmt((float)($glTot['debit'] ?? 0), $currency); ?></td>
                            <td class="num"><?php echo acctFmt((float)($glTot['credit'] ?? 0), $currency); ?></td>
                            <td class="num"><?php echo acctFmt((float)($glTot['closing_debit'] ?? 0), $currency); ?></td>
                            <td class="num"><?php echo acctFmt((float)($glTot['closing_credit'] ?? 0), $currency); ?></td>
                        </tr>
                        </tfoot>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card neumorphic">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <div>
                    <h3 style="margin:0;">Libro mayor · <?php echo htmlspecialchars($ledgerAccount['code'] . ' ' . $ledgerAccount['name']); ?></h3>
                    <p style="margin:4px 0 0;font-size:12px;">
                        <a href="<?php echo $ledgerBase; ?>?tab=ledger&amp;from=<?php echo urlencode($dateFrom); ?>&amp;to=<?php echo urlencode($dateTo); ?>">← Volver a síntesis</a>
                    </p>
                </div>
                <?php if ($canExport): ?>
                    <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($ledgerBase . '?' . http_build_query([
                        'action' => 'export', 'report' => 'ledger', 'account_id' => (int)$ledgerAccount['id'],
                        'from' => $dateFrom, 'to' => $dateTo, 'lq' => (string)($ledgerQ ?? ''),
                    ])); ?>">Exportar CSV</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php $running = $ledgerOpening; ?>
                <div class="ledger-summary">
                    <div><span>Saldo inicial</span><strong><?php echo acctFmt($ledgerOpening * $ledgerSign, $currency); ?></strong></div>
                    <div><span>Debe del periodo</span><strong><?php echo acctFmt((float)($lr['period_debit'] ?? 0), $currency); ?></strong></div>
                    <div><span>Haber del periodo</span><strong><?php echo acctFmt((float)($lr['period_credit'] ?? 0), $currency); ?></strong></div>
                    <div><span>Saldo final</span><strong><?php echo acctFmt((float)($lr['closing_balance'] ?? 0) * $ledgerSign, $currency); ?></strong></div>
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">
                    Naturaleza <strong><?php echo $ledgerSign < 0 ? 'crédito' : 'débito'; ?></strong>
                    (saldo = <?php echo $ledgerSign < 0 ? 'Haber − Debe' : 'Debe − Haber'; ?>).
                    <?php if ($ledgerFiltered): ?>
                        <br><strong>Búsqueda aplicada:</strong> totales de arriba son de toda la cuenta.
                    <?php endif; ?>
                    <?php
                    $whCodesHint = ['135515', '135517', '135518'];
                    if (in_array((string)$ledgerAccount['code'], $whCodesHint, true)):
                    ?>
                        <br>Esta es una cuenta de retención: vea la <a href="<?php echo $ledgerBase; ?>?tab=withholdings&amp;from=<?php echo urlencode($dateFrom); ?>&amp;to=<?php echo urlencode($dateTo); ?>">conciliación en Retenciones</a>.
                    <?php endif; ?>
                </p>
                <div class="table-container"><table class="trial-table">
                    <thead><tr><th>Fecha</th><th>Comprobante</th><th>Concepto</th><th>Origen</th><th>Tercero</th><th class="num">Debe</th><th class="num">Haber</th><th class="num">Saldo</th></tr></thead>
                    <tbody>
                    <tr style="background:var(--dmb-F8FAFC, #F8FAFC);font-weight:600;">
                        <td colspan="5">Saldo inicial (antes de <?php echo htmlspecialchars(date('d/m/Y', strtotime($dateFrom) ?: time())); ?>)</td>
                        <td></td><td></td>
                        <td class="num"><?php echo acctFmt($ledgerOpening * $ledgerSign, $currency); ?></td>
                    </tr>
                    <?php if (!$ledger): ?>
                        <tr><td colspan="8" class="dashboard-empty">
                            <?php echo $ledgerFiltered ? 'Ningún movimiento coincide con la búsqueda' : 'Sin movimientos en el periodo'; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($ledger as $line): $running += (float)$line['debit'] - (float)$line['credit']; ?><tr>
                        <td><?php echo date('d/m/Y', strtotime($line['entry_date'])); ?></td>
                        <td><button type="button" class="btn btn-sm btn-secondary" onclick="showAccountingEntry(<?php echo (int)$line['entry_id']; ?>)"><?php echo htmlspecialchars($line['entry_number']); ?></button></td>
                        <td>
                            <?php echo htmlspecialchars($line['description'] ?: $line['entry_description']); ?>
                            <?php if (($line['entry_status'] ?? '') === 'reversed'): ?><span class="badge badge-warning">Anulado</span><?php endif; ?>
                            <?php if (!empty($line['reversal_of_id'])): ?><span class="badge badge-info">Reversión</span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(\SoftNova\Services\AccountingService::sourceLabel($line['source_type'] ?? null)); ?></td>
                        <td><?php echo htmlspecialchars($line['third_party_name'] ?: '-'); ?></td>
                        <td class="num"><?php echo (float)$line['debit'] > 0 ? acctFmt((float)$line['debit'], $currency) : '—'; ?></td>
                        <td class="num"><?php echo (float)$line['credit'] > 0 ? acctFmt((float)$line['credit'], $currency) : '—'; ?></td>
                        <td class="num"><?php echo $ledgerFiltered ? '' : acctFmt($running * $ledgerSign, $currency); ?></td>
                    </tr><?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr class="trial-total">
                        <td colspan="5">Totales del periodo y saldo final</td>
                        <td class="num"><?php echo acctFmt((float)($lr['period_debit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($lr['period_credit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($lr['closing_balance'] ?? 0) * $ledgerSign, $currency); ?></td>
                    </tr>
                    </tfoot>
                </table></div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'withholdings'): ?>
    <?php
    $wh = is_array($withholdings ?? null) ? $withholdings : null;
    $whRows = $wh['rows'] ?? [];
    $whBase = $viewInstance->route('app/contabilidad');
    $ledgerLinkWh = static function (int $accountId) use ($whBase, $dateFrom, $dateTo): string {
        return $whBase . '?tab=ledger&account_id=' . $accountId . '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo);
    };
    ?>
    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;">Libro de retenciones (sufridas)</h3>
                <p style="margin:4px 0 0;font-size:12px;color:var(--color-text-secondary);">
                    ReteFuente, ReteIVA y ReteICA independientes del Libro mayor.
                    Periodo <?php echo htmlspecialchars(date('d/m/Y', strtotime($dateFrom) ?: time())); ?>
                    → <?php echo htmlspecialchars(date('d/m/Y', strtotime($dateTo) ?: time())); ?>.
                </p>
            </div>
            <?php if ($canExport): ?>
                <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($whBase . '?' . http_build_query([
                    'action' => 'export', 'report' => 'withholdings', 'from' => $dateFrom, 'to' => $dateTo,
                ])); ?>">Exportar CSV</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$whRows): ?>
                <p class="dashboard-empty">No hay datos de retenciones para el periodo.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:16px;">
                    <?php foreach ($whRows as $w): ?>
                        <?php
                        $gap = round((float)$w['commission_ledger'] - (float)$w['expected_commissions'], 2);
                        if (!$w['exists']) {
                            $stLabel = 'Cuenta no creada'; $stClass = 'badge-warning';
                        } elseif (abs($gap) >= 1) {
                            $stLabel = 'Diferencia ' . acctFmt($gap, $currency); $stClass = 'badge-danger';
                        } elseif ((float)$w['pending_commissions'] > 0.009) {
                            $stLabel = 'Pendiente ' . acctFmt((float)$w['pending_commissions'], $currency); $stClass = 'badge-warning';
                        } elseif ((float)$w['rate'] <= 0) {
                            $stLabel = 'Tarifa en 0'; $stClass = 'badge-info';
                        } else {
                            $stLabel = 'Cuadra'; $stClass = 'badge-success';
                        }
                        ?>
                        <div style="border:1px solid var(--color-border,#e5e7eb);border-radius:10px;padding:14px;background:var(--bg-card,#fff);">
                            <div style="display:flex;justify-content:space-between;gap:8px;align-items:start;">
                                <div>
                                    <strong style="font-size:15px;"><?php echo htmlspecialchars((string)$w['label']); ?></strong>
                                    <div style="font-size:12px;color:var(--color-text-secondary);">
                                        <?php if ((int)$w['account_id'] > 0): ?>
                                            <a href="<?php echo $ledgerLinkWh((int)$w['account_id']); ?>"><?php echo htmlspecialchars((string)$w['code']); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars((string)$w['code']); ?>
                                        <?php endif; ?>
                                        · <?php echo number_format((float)$w['rate'], 2, ',', '.') . ' ' . htmlspecialchars((string)$w['rate_unit']); ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($stLabel); ?></span>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;font-size:13px;">
                                <div><span style="color:var(--color-text-secondary);">Saldo inicial</span><br><strong><?php echo acctFmt((float)$w['opening'], $currency); ?></strong></div>
                                <div><span style="color:var(--color-text-secondary);">Saldo final</span><br><strong><?php echo acctFmt((float)$w['closing'], $currency); ?></strong></div>
                                <div><span style="color:var(--color-text-secondary);">Debe</span><br><?php echo acctFmt((float)$w['debit'], $currency); ?></div>
                                <div><span style="color:var(--color-text-secondary);">Haber</span><br><?php echo acctFmt((float)$w['credit'], $currency); ?></div>
                                <div><span style="color:var(--color-text-secondary);">Esperado comisiones</span><br><?php echo acctFmt((float)$w['expected_commissions'], $currency); ?></div>
                                <div><span style="color:var(--color-text-secondary);">En libro (pasarela)</span><br><?php echo acctFmt((float)$w['commission_ledger'], $currency); ?></div>
                            </div>
                            <?php if (!empty($w['by_source'])): ?>
                                <div style="margin-top:10px;font-size:12px;color:var(--color-text-secondary);">
                                    <?php foreach ($w['by_source'] as $i => $src): ?>
                                        <?php echo $i ? ' · ' : ''; ?><?php echo htmlspecialchars((string)$src['label']); ?>: <?php echo acctFmt((float)$src['net'], $currency); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="ledger-note">
                    <strong>Origen de las retenciones sufridas</strong>
                    <ul>
                        <li>
                            Datáfono / link de pago: tarifas en
                            <a href="<?php echo $whBase; ?>?tab=commissions">Comisiones</a>.
                            Periodo: <?php echo (int)($wh['gateway_payments'] ?? 0); ?> pago(s)
                            por <?php echo acctFmt((float)($wh['gateway_amount'] ?? 0), $currency); ?>;
                            <?php echo (int)($wh['paid_commissions'] ?? 0); ?> comisión(es) contabilizada(s)
                            <?php if ((int)($wh['pending_commissions'] ?? 0) > 0): ?>
                                · <strong><?php echo (int)$wh['pending_commissions']; ?> pendiente(s)</strong>
                            <?php endif; ?>.
                        </li>
                        <?php foreach (($wh['expense_categories'] ?? []) as $cat): ?>
                            <li>Gastos «<?php echo htmlspecialchars((string)$cat['name']); ?>» → cuenta <?php echo htmlspecialchars((string)$cat['account_code']); ?>.</li>
                        <?php endforeach; ?>
                        <li>Retenciones por transferencia de clientes: comprobante manual (aún no automático).</li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'sales-book'): ?>
    <?php
    $sb = is_array($salesBook ?? null) ? $salesBook : [];
    $sbRows = $sb['rows'] ?? [];
    $sbTot = $sb['totals'] ?? [];
    $sbLed = $sb['ledger'] ?? [];
    $sbAcc = $sb['accounts'] ?? ['sales' => '413501', 'returns' => '417501'];
    $sbBase = $viewInstance->route('app/contabilidad');
    ?>
    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;">Libro de ventas / ingresos</h3>
                <p style="margin:4px 0 0;font-size:12px;color:var(--color-text-secondary);">
                    Documentos de venta y notas de crédito del periodo. Las retenciones de adquirente van en
                    <a href="<?php echo $sbBase; ?>?tab=withholdings&amp;from=<?php echo urlencode($dateFrom); ?>&amp;to=<?php echo urlencode($dateTo); ?>">Retenciones</a>;
                    el IVA en <a href="<?php echo $sbBase; ?>?tab=vat&amp;from=<?php echo urlencode($dateFrom); ?>&amp;to=<?php echo urlencode($dateTo); ?>">Libro IVA</a>.
                </p>
            </div>
            <?php if ($canExport): ?>
                <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($sbBase . '?' . http_build_query([
                    'action' => 'export', 'report' => 'sales-book', 'from' => $dateFrom, 'to' => $dateTo,
                ])); ?>">Exportar CSV</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!empty($sb['warnings'])): ?>
                <div class="alert alert-warning" style="margin-bottom:14px;">
                    <?php foreach ($sb['warnings'] as $w): ?>
                        <div><?php echo htmlspecialchars((string)$w); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="ledger-summary" style="margin-bottom:14px;">
                <div><span>Ventas</span><strong><?php echo (int)($sbTot['count'] ?? 0); ?></strong></div>
                <div><span>NC</span><strong><?php echo (int)($sbTot['credit_notes_count'] ?? 0); ?></strong></div>
                <div><span>Total documental</span><strong><?php echo acctFmt((float)($sbTot['total'] ?? 0), $currency); ?></strong></div>
                <div><span>Mayor <?php echo htmlspecialchars((string)$sbAcc['sales']); ?> neto</span><strong><?php echo acctFmt((float)($sbLed['net'] ?? 0), $currency); ?></strong></div>
            </div>
            <div class="table-container" style="margin-bottom:14px;">
                <table>
                    <thead><tr><th>Resumen</th><th class="num">Monto</th></tr></thead>
                    <tbody>
                        <tr><td>Subtotal (neto de NC)</td><td class="num"><?php echo acctFmt((float)($sbTot['subtotal'] ?? 0), $currency); ?></td></tr>
                        <tr><td>Descuentos</td><td class="num"><?php echo acctFmt((float)($sbTot['discount'] ?? 0), $currency); ?></td></tr>
                        <tr><td>IVA</td><td class="num"><?php echo acctFmt((float)($sbTot['tax'] ?? 0), $currency); ?></td></tr>
                        <tr><td>Domicilios</td><td class="num"><?php echo acctFmt((float)($sbTot['delivery'] ?? 0), $currency); ?></td></tr>
                        <tr><td>NC ventas (valor absoluto)</td><td class="num"><?php echo acctFmt((float)($sbTot['credit_notes_total'] ?? 0), $currency); ?></td></tr>
                        <tr><td><strong>Ingreso neto documental</strong></td><td class="num"><strong><?php echo acctFmt((float)($sbTot['net_income'] ?? 0), $currency); ?></strong></td></tr>
                        <tr><td>Libro contable <?php echo htmlspecialchars((string)$sbAcc['sales']); ?> − <?php echo htmlspecialchars((string)$sbAcc['returns']); ?></td><td class="num"><?php echo acctFmt((float)($sbLed['net'] ?? 0), $currency); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <?php if (!$sbRows): ?>
                <p class="dashboard-empty">Sin ventas ni NC en el periodo.</p>
            <?php else: ?>
                <div class="table-container"><table class="trial-table">
                    <thead><tr>
                        <th>Fecha</th><th>Tipo</th><th>Número</th><th>Cliente</th><th>Medio</th>
                        <th class="num">Subtotal</th><th class="num">Dcto</th><th class="num">IVA</th><th class="num">Total</th><th>Asiento</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sbRows as $r): ?>
                        <tr<?php echo ($r['kind'] ?? '') === 'credit_note' ? ' style="background:#FFF7ED;"' : ''; ?>>
                            <td><?php echo !empty($r['date']) ? date('d/m/Y', strtotime((string)$r['date']) ?: time()) : '—'; ?></td>
                            <td><span class="badge <?php echo ($r['kind'] ?? '') === 'credit_note' ? 'badge-warning' : 'badge-success'; ?>"><?php echo htmlspecialchars((string)($r['kind_label'] ?? '')); ?></span></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string)($r['number'] ?? '')); ?></strong>
                                <?php if (!empty($r['notes'])): ?>
                                    <div style="font-size:11px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$r['notes']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)($r['customer_name'] ?? '')); ?>
                                <?php if (!empty($r['customer_document'])): ?>
                                    <div style="font-size:11px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$r['customer_document']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(($r['payment_method'] ?? '') !== '' ? \SoftNova\Services\PaymentMethodCatalog::label((string)$r['payment_method']) : '—'); ?></td>
                            <td class="num"><?php echo acctFmt((float)($r['subtotal'] ?? 0), $currency); ?></td>
                            <td class="num"><?php echo abs((float)($r['discount'] ?? 0)) > 0.009 ? acctFmt((float)$r['discount'], $currency) : '—'; ?></td>
                            <td class="num"><?php echo acctFmt((float)($r['tax'] ?? 0), $currency); ?></td>
                            <td class="num"><strong><?php echo acctFmt((float)($r['total'] ?? 0), $currency); ?></strong></td>
                            <td>
                                <?php if (!empty($r['entry_id'])): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="showAccountingEntry(<?php echo (int)$r['entry_id']; ?>)"><?php echo htmlspecialchars((string)($r['entry_number'] ?: ('#' . $r['entry_id']))); ?></button>
                                <?php else: ?>
                                    <span style="color:var(--color-text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr class="trial-total">
                        <td colspan="5">TOTALES</td>
                        <td class="num"><?php echo acctFmt((float)($sbTot['subtotal'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($sbTot['discount'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($sbTot['tax'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($sbTot['total'] ?? 0), $currency); ?></td>
                        <td></td>
                    </tr>
                    </tfoot>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'trial' && $trialView === 'accounts'): ?>
    <?php
    $tr = is_array($trialReport) ? $trialReport : ['rows' => [], 'totals' => [], 'balanced' => []];
    $trRows = $tr['rows'] ?? [];
    $trTot = $tr['totals'] ?? [];
    $trBal = $tr['balanced'] ?? [];
    $trialBase = $viewInstance->route('app/contabilidad');
    $trialExport = $trialBase . '?' . http_build_query([
        'action' => 'export',
        'report' => 'accounts-trial',
        'from' => $dateFrom,
        'to' => $dateTo,
        'account_type' => $trialAccountType,
        'q' => $trialQ,
        'include_zero' => $trialIncludeZero ? '1' : '',
    ]);
    $trCell = static function ($v) use ($currency): string {
        return abs((float)$v) < 0.009 ? '—' : acctFmt((float)$v, $currency);
    };
    ?>
    <div class="trial-switch">
        <a class="btn btn-sm btn-secondary" href="<?php echo $trialBase; ?>?tab=trial&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">Balance operativo (gerencial)</a>
        <a class="btn btn-sm btn-primary" href="<?php echo $trialBase; ?>?tab=trial&view=accounts&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">Balance de comprobación (libro)</a>
    </div>
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;">Balance de comprobación (libro mayor)</h3>
                <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px;">
                    Fuente: asientos contables. <?php echo htmlspecialchars(date('d/m/Y', strtotime($dateFrom) ?: time())); ?> → <?php echo htmlspecialchars(date('d/m/Y', strtotime($dateTo) ?: time())); ?>
                    · <?php echo (int)($trTot['accounts'] ?? 0); ?> cuenta(s).
                    Saldo inicial = acumulado antes de «Desde»; saldo final = acumulado a «Hasta».
                </div>
            </div>
            <?php if ($canExport): ?>
                <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($trialExport); ?>">Exportar CSV</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($trialAccountType === '' && $trialQ === ''): ?>
                <div class="trial-checks">
                    <?php foreach (['opening' => 'Saldo inicial', 'period' => 'Movimientos del periodo', 'closing' => 'Saldo final'] as $bk => $bl): ?>
                        <?php $ok = !empty($trBal[$bk]); ?>
                        <span class="badge <?php echo $ok ? 'badge-success' : 'badge-danger'; ?>"><?php echo $bl . ($ok ? ' cuadra' : ' NO cuadra'); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">Con filtros aplicados los totales no tienen que cuadrar (solo se ve una parte del plan de cuentas).</p>
            <?php endif; ?>
            <div class="table-container"><table class="trial-table">
                <thead>
                    <tr>
                        <th rowspan="2">Código</th><th rowspan="2">Cuenta</th><th rowspan="2">Tipo</th>
                        <th colspan="2" class="trial-group">Saldo inicial</th>
                        <th colspan="2" class="trial-group">Movimientos</th>
                        <th colspan="2" class="trial-group">Saldo final</th>
                    </tr>
                    <tr>
                        <th class="num">Debe</th><th class="num">Haber</th>
                        <th class="num">Debe</th><th class="num">Haber</th>
                        <th class="num">Debe</th><th class="num">Haber</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$trRows): ?><tr><td colspan="9" class="dashboard-empty">Sin cuentas con saldo o movimientos en el periodo</td></tr><?php endif; ?>
                <?php foreach ($trRows as $row): ?>
                    <tr>
                        <td><a href="<?php echo $trialBase; ?>?tab=ledger&account_id=<?php echo (int)$row['id']; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>" title="Ver libro mayor"><strong><?php echo htmlspecialchars($row['code']); ?></strong></a></td>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['account_type_label'] ?? ''); ?></td>
                        <td class="num"><?php echo $trCell($row['opening_debit'] ?? 0); ?></td>
                        <td class="num"><?php echo $trCell($row['opening_credit'] ?? 0); ?></td>
                        <td class="num"><?php echo $trCell($row['debit'] ?? 0); ?></td>
                        <td class="num"><?php echo $trCell($row['credit'] ?? 0); ?></td>
                        <td class="num"><?php echo $trCell($row['closing_debit'] ?? 0); ?></td>
                        <td class="num"><?php echo $trCell($row['closing_credit'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($trRows): ?>
                <tfoot>
                    <tr class="trial-total">
                        <td colspan="3">Totales</td>
                        <td class="num"><?php echo acctFmt((float)($trTot['opening_debit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($trTot['opening_credit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($trTot['debit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($trTot['credit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($trTot['closing_debit'] ?? 0), $currency); ?></td>
                        <td class="num"><?php echo acctFmt((float)($trTot['closing_credit'] ?? 0), $currency); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table></div>
        </div>
    </div>

<?php elseif ($tab === 'trial'): ?>
    <div class="trial-switch">
        <a class="btn btn-sm btn-primary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=trial&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&year=<?php echo (int)$balanceYear; ?>">Balance operativo (gerencial)</a>
        <a class="btn btn-sm btn-secondary" href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=trial&view=accounts&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">Balance de comprobación (libro)</a>
    </div>
    <div class="card neumorphic" style="margin-bottom:14px;">
        <div class="card-body" style="font-size:13px;color:var(--color-text-secondary);padding:12px 16px;">
            <strong style="color:var(--color-text);">Vista gerencial:</strong> suma documentos operativos (ventas, compras, gastos).
            No es el libro mayor. Para cifras oficiales use
            <a href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=trial&view=accounts&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">Balance de comprobación (cuentas)</a>
            o Estados financieros.
        </div>
    </div>
    <?php
    $mb = $monthlyBalance;
    $mt = $mb['totals'] ?? [];
    $exportUrl = $viewInstance->route('app/contabilidad')
        . '?action=export&report=monthly-balance&year=' . (int)$balanceYear;
    $fmtCell = static function (float $v, array $currency): string {
        if (abs($v) < 0.009) {
            return '—';
        }
        $neg = $v < 0;
        $txt = acctFmt(abs($v), $currency);
        return $neg ? ('− ' . $txt) : $txt;
    };
    $chartJson = json_encode(
        $mb['chart'] ?? [],
        JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP
    );
    $balWarnings = $mb['meta']['warnings'] ?? [];
    $balSources = $mb['meta']['sources'] ?? [];
    $periodLabel = (string)($mb['meta']['period_label'] ?? ('Enero – Diciembre ' . (int)$balanceYear));
    $periodFrom = (string)($mb['meta']['from'] ?? ($balanceYear . '-01-01'));
    $periodTo = (string)($mb['meta']['to'] ?? ($balanceYear . '-12-31'));
    $warnLabels = [
        'ventas' => 'ventas',
        'nc_venta' => 'notas crédito venta',
        'compras' => 'facturas de compra',
        'abonos' => 'abonos a proveedores',
        'pagos_contado' => 'pagos de contado',
        'gastos' => 'gastos',
        'costo_sin_cargar' => 'costo de venta en 0 (revise precio de compra en inventario)',
    ];
    ?>
    <style>
        .bal-table{border-collapse:separate;border-spacing:0;width:100%;}
        .bal-table thead th{font-size:13px;font-weight:700;color:#000;white-space:nowrap;vertical-align:bottom;padding:10px 12px;border-bottom:1px solid #CBD5E1;}
        .bal-group-facturas{background:#FB923C;color:#000;text-align:center;}
        .bal-group-utilidad{background:#86EFAC;color:#000;text-align:center;}
        .bal-th-util{background:#4ADE80;color:#000;}
        .bal-th-base{background:#86EFAC;color:#000;}
        .bal-totals-row td{font-weight:700;background:var(--dmb-F1F5F9, #F1F5F9);font-size:13px;padding:10px 12px;border-top:2px solid #94A3B8;border-bottom:2px solid #94A3B8;color:var(--dmc-0F172A, #0F172A);}
        /* Más específico que .bal-totals-row td para que el rojo/blanco no se pierda */
        .bal-totals-row td.bal-total-ingresos{background:#991B1B !important;color:#fff !important;font-weight:800;}
        .bal-month-cell{min-width:168px;white-space:nowrap;}
        .bal-month-num{display:inline-block;min-width:2.2em;color:var(--dmc-64748B, #64748B);font-variant-numeric:tabular-nums;}
        .bal-month-name{font-weight:700;color:var(--dmc-0F172A, #0F172A);}
        .bal-month-range{display:block;font-size:11px;font-weight:500;color:var(--dmc-64748B, #64748B);margin-top:2px;}
        .bal-row-active td{background:var(--dmb-FFFBEB, #FFFBEB);}
        .bal-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-bottom:16px;}
        .bal-chart-card{min-height:280px;}
        .bal-chart-card .card-body{position:relative;height:240px;}
        .bal-chart-card canvas{max-height:240px;}
        .bal-chart-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--color-text-secondary);font-size:13px;}
        .bal-sources{margin:10px 0 0;padding:10px 12px;background:var(--dmb-F8FAFC, #F8FAFC);border:1px solid #E2E8F0;border-radius:8px;font-size:12px;color:var(--color-text-secondary);line-height:1.45;}
        .bal-sources strong{color:var(--color-text);}
        .bal-sources ul{margin:6px 0 0;padding-left:18px;}
        .bal-period-badge{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;margin:8px 0 0;padding:8px 12px;background:var(--dmb-EFF6FF, #EFF6FF);border:1px solid #BFDBFE;border-radius:8px;font-size:13px;color:var(--dmc-1E3A8A, #1E3A8A);}
        .bal-howto{margin:10px 0 0;font-size:12px;color:var(--color-text-secondary);max-width:820px;line-height:1.45;}
    </style>

    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
            <h3 style="margin:0 0 4px;">Balance operativo <?php echo (int)$balanceYear; ?></h3>
            <div class="bal-period-badge">
                <strong>Periodo:</strong>
                <span><?php echo htmlspecialchars($periodLabel); ?></span>
                <span style="opacity:.75;">·</span>
                <span><?php echo htmlspecialchars(date('d/m/Y', strtotime($periodFrom) ?: time())); ?>
                    → <?php echo htmlspecialchars(date('d/m/Y', strtotime($periodTo) ?: time())); ?></span>
            </div>
            <p class="bal-howto">
                Cada fila es un <strong>mes calendario</strong> del año seleccionado (arriba, filtro «Año»).
                La fila sombreada bajo los encabezados es el <strong>total del año</strong>.
                Ingresos y costo salen de ventas de caja; Facturas = compras recibidas; Utilidad se calcula de esas columnas.
            </p>
            <p style="margin:8px 0 0;font-size:13px;color:var(--color-text-secondary);max-width:720px;">
                Bruta = ingresos − costo · Operacional = bruta − gastos operativos · Neta = operacional − gastos fijos/financieros.
            </p>
            <?php if (!empty($balWarnings)): ?>
                <?php
                $warnTxt = [];
                foreach ($balWarnings as $w) {
                    $warnTxt[] = $warnLabels[(string)$w] ?? (string)$w;
                }
                ?>
                <p style="margin:8px 0 0;font-size:12px;color:var(--dmc-B45309, #B45309);">
                    Atención: <?php echo htmlspecialchars(implode('; ', $warnTxt)); ?>.
                </p>
            <?php endif; ?>
            <?php if (!empty($balSources)): ?>
                <details class="bal-sources">
                    <summary style="cursor:pointer;font-weight:600;color:var(--color-text);">De dónde salen los datos</summary>
                    <ul>
                        <?php if (!empty($balSources['ingresos'])): ?>
                            <li><strong>Ingresos:</strong> <?php echo htmlspecialchars((string)$balSources['ingresos']); ?></li>
                        <?php endif; ?>
                        <?php if (!empty($balSources['costo_venta'])): ?>
                            <li><strong>Costo de venta:</strong> <?php echo htmlspecialchars((string)$balSources['costo_venta']); ?></li>
                        <?php endif; ?>
                        <?php if (!empty($balSources['facturas'])): ?>
                            <li><strong>Facturas:</strong> <?php echo htmlspecialchars((string)$balSources['facturas']); ?></li>
                        <?php endif; ?>
                        <?php if (!empty($balSources['facturas_pagadas'])): ?>
                            <li><strong>$ Fact. pagadas:</strong> <?php echo htmlspecialchars((string)$balSources['facturas_pagadas']); ?></li>
                        <?php endif; ?>
                        <?php if (!empty($balSources['utilidad_bruta'])): ?>
                            <li><strong>Utilidad:</strong>
                                <?php echo htmlspecialchars((string)$balSources['utilidad_bruta']); ?>;
                                <?php echo htmlspecialchars((string)($balSources['utilidad_operacional'] ?? '')); ?>;
                                <?php echo htmlspecialchars((string)($balSources['utilidad_neta'] ?? '')); ?>.
                            </li>
                        <?php endif; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
            <?php if ($canExport): ?>
                <a class="btn btn-secondary" href="<?php echo htmlspecialchars($exportUrl); ?>">Exportar CSV</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats-grid" style="margin-bottom:16px;">
        <div class="stat-card neumorphic">
            <h4>Ingresos <?php echo (int)$balanceYear; ?></h4>
            <div class="stat-value" style="font-size:20px;color:var(--dmc-991B1B, #991B1B);"><?php echo acctFmt((float)($mt['ingresos'] ?? 0), $currency); ?></div>
        </div>
        <div class="stat-card neumorphic">
            <h4>Costo de venta</h4>
            <div class="stat-value" style="font-size:20px;"><?php echo acctFmt((float)($mt['costo_venta'] ?? 0), $currency); ?></div>
        </div>
        <div class="stat-card neumorphic">
            <h4>Utilidad bruta</h4>
            <div class="stat-value" style="font-size:20px;color:var(--dmc-059669, #059669);"><?php echo $fmtCell((float)($mt['utilidad_bruta'] ?? 0), $currency); ?></div>
        </div>
        <div class="stat-card neumorphic">
            <h4>Utilidad neta</h4>
            <div class="stat-value" style="font-size:20px;color:<?php echo ((float)($mt['utilidad_neta'] ?? 0) >= 0) ? '#059669' : '#DC2626'; ?>;">
                <?php echo $fmtCell((float)($mt['utilidad_neta'] ?? 0), $currency); ?>
            </div>
        </div>
    </div>

    <div class="bal-charts">
        <div class="card neumorphic bal-chart-card">
            <div class="card-header"><h3 style="margin:0;font-size:15px;">Ingresos vs costo · <?php echo (int)$balanceYear; ?></h3></div>
            <div class="card-body"><canvas id="balChartIncome" aria-label="Gráfico ingresos vs costo"></canvas></div>
        </div>
        <div class="card neumorphic bal-chart-card">
            <div class="card-header"><h3 style="margin:0;font-size:15px;">Utilidades · <?php echo (int)$balanceYear; ?></h3></div>
            <div class="card-body"><canvas id="balChartProfit" aria-label="Gráfico utilidades"></canvas></div>
        </div>
        <div class="card neumorphic bal-chart-card">
            <div class="card-header"><h3 style="margin:0;font-size:15px;">Facturas vs pagos · <?php echo (int)$balanceYear; ?></h3></div>
            <div class="card-body"><canvas id="balChartInvoices" aria-label="Gráfico facturas vs pagos"></canvas></div>
        </div>
    </div>

    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <h3 style="margin:0;font-size:15px;">Tabla mensual · <?php echo htmlspecialchars($periodLabel); ?></h3>
            <span style="font-size:12px;color:var(--color-text-secondary);">Fila «Total año» = suma de los 12 meses</span>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-container" style="overflow-x:auto;">
                <table class="bal-table" style="min-width:1180px;">
                    <thead>
                        <tr>
                            <th rowspan="2" class="bal-th-base">Mes / periodo</th>
                            <th rowspan="2" class="bal-th-base">Ingresos</th>
                            <th rowspan="2" class="bal-th-base">Costo de venta</th>
                            <th colspan="6" class="bal-group-facturas">Facturas de compra</th>
                            <th colspan="3" class="bal-group-utilidad">Utilidad</th>
                        </tr>
                        <tr>
                            <th class="bal-group-facturas">Total</th>
                            <th class="bal-group-facturas">Contado</th>
                            <th class="bal-group-facturas">Crédito</th>
                            <th class="bal-group-facturas">$ Fact. Pagadas</th>
                            <th class="bal-group-facturas">Dctos X Pagos</th>
                            <th class="bal-group-facturas">Pendientes</th>
                            <th class="bal-th-util">Bruta</th>
                            <th class="bal-th-util">Operacional</th>
                            <th class="bal-th-util">Neta</th>
                        </tr>
                        <tr class="bal-totals-row">
                            <td class="bal-month-cell">
                                <span class="bal-month-name">Total <?php echo (int)$balanceYear; ?></span>
                                <span class="bal-month-range"><?php echo htmlspecialchars($periodLabel); ?></span>
                            </td>
                            <td class="bal-total-ingresos" style="text-align:right;"><?php echo acctFmt((float)($mt['ingresos'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['costo_venta'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['facturas_total'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['facturas_contado'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['facturas_credito'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['facturas_pagadas'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['facturas_descuentos'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['facturas_pendientes'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['utilidad_bruta'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['utilidad_operacional'] ?? 0), $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)($mt['utilidad_neta'] ?? 0), $currency); ?></td>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($mb['months'] ?? []) as $row): ?>
                        <?php
                        $hasData = abs((float)$row['ingresos'])
                            + abs((float)$row['costo_venta'])
                            + abs((float)$row['facturas_total'])
                            + abs((float)$row['utilidad_neta']) > 0.009;
                        $mName = (string)($row['month_name'] ?? '');
                        $mNum = (string)($row['month_num'] ?? $row['month_label'] ?? '');
                        $pFrom = (string)($row['period_from'] ?? '');
                        $pTo = (string)($row['period_to'] ?? '');
                        $rangeTxt = '';
                        if ($pFrom !== '' && $pTo !== '') {
                            $rangeTxt = date('d/m/Y', strtotime($pFrom) ?: time())
                                . ' – '
                                . date('d/m/Y', strtotime($pTo) ?: time());
                        }
                        ?>
                        <tr class="<?php echo $hasData ? 'bal-row-active' : ''; ?>">
                            <td class="bal-month-cell">
                                <span class="bal-month-num"><?php echo htmlspecialchars($mNum); ?></span>
                                <span class="bal-month-name"><?php echo htmlspecialchars($mName !== '' ? $mName : (string)$row['month_label']); ?></span>
                                <?php if ($rangeTxt !== ''): ?>
                                    <span class="bal-month-range"><?php echo htmlspecialchars($rangeTxt); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['ingresos'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['costo_venta'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['facturas_total'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['facturas_contado'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['facturas_credito'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['facturas_pagadas'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['facturas_descuentos'], $currency); ?></td>
                            <td style="text-align:right;"><?php echo $fmtCell((float)$row['facturas_pendientes'], $currency); ?></td>
                            <td style="text-align:right;background:rgba(187,247,208,.25);"><?php echo $fmtCell((float)$row['utilidad_bruta'], $currency); ?></td>
                            <td style="text-align:right;background:rgba(187,247,208,.25);"><?php echo $fmtCell((float)$row['utilidad_operacional'], $currency); ?></td>
                            <td style="text-align:right;background:rgba(187,247,208,.35);font-weight:600;color:<?php echo ((float)$row['utilidad_neta'] >= 0) ? 'inherit' : '#DC2626'; ?>;">
                                <?php echo $fmtCell((float)$row['utilidad_neta'], $currency); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="<?php echo $viewInstance->asset('js/chart.umd.min.js'); ?>"></script>
    <script>
    (function(){
        var data = <?php echo $chartJson ?: '{}'; ?>;
        if (!data || !data.labels) return;

        function sumArr(arr) {
            if (!arr || !arr.length) return 0;
            var t = 0;
            for (var i = 0; i < arr.length; i++) t += Number(arr[i]) || 0;
            return t;
        }
        var hasIncome = Math.abs(sumArr(data.ingresos)) + Math.abs(sumArr(data.costo_venta)) > 0.009;
        var hasProfit = Math.abs(sumArr(data.utilidad_bruta)) + Math.abs(sumArr(data.utilidad_operacional)) + Math.abs(sumArr(data.utilidad_neta)) > 0.009;
        var hasInv = Math.abs(sumArr(data.facturas_total)) + Math.abs(sumArr(data.facturas_pagadas)) > 0.009;

        function showEmpty(canvasId) {
            var canvas = document.getElementById(canvasId);
            if (!canvas || !canvas.parentNode) return;
            canvas.style.display = 'none';
            var empty = document.createElement('div');
            empty.className = 'bal-chart-empty';
            empty.textContent = 'Sin movimientos en este año';
            canvas.parentNode.appendChild(empty);
        }

        if (!window.Chart) {
            showEmpty('balChartIncome');
            showEmpty('balChartProfit');
            showEmpty('balChartInvoices');
            return;
        }

        var moneyTick = function(v){
            try {
                return new Intl.NumberFormat('es-CO', {notation:'compact', compactDisplay:'short'}).format(v);
            } catch (e) { return v; }
        };
        var commonOpts = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var raw = Number(ctx.parsed.y || 0);
                            try {
                                return (ctx.dataset.label || '') + ': ' +
                                    new Intl.NumberFormat('es-CO', {style:'currency', currency:'COP', maximumFractionDigits:0}).format(raw);
                            } catch (e) {
                                return (ctx.dataset.label || '') + ': ' + raw;
                            }
                        }
                    }
                }
            },
            scales: { y: { ticks: { callback: moneyTick } } }
        };

        function mount(id, cfg, hasData) {
            var el = document.getElementById(id);
            if (!el) return;
            if (!hasData) { showEmpty(id); return; }
            if (el._balChart) {
                try { el._balChart.destroy(); } catch (e) {}
            }
            el._balChart = new Chart(el, cfg);
        }

        mount('balChartIncome', {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Ingresos', data: data.ingresos, backgroundColor: 'rgba(153,27,27,0.75)', borderRadius: 4 },
                    { label: 'Costo de venta', data: data.costo_venta, backgroundColor: 'rgba(100,116,139,0.55)', borderRadius: 4 }
                ]
            },
            options: commonOpts
        }, hasIncome);

        mount('balChartProfit', {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Bruta', data: data.utilidad_bruta, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.15)', tension: 0.3, fill: true },
                    { label: 'Operacional', data: data.utilidad_operacional, borderColor: '#2563EB', backgroundColor: 'rgba(37,99,235,0.08)', tension: 0.3, fill: false },
                    { label: 'Neta', data: data.utilidad_neta, borderColor: '#0F766E', backgroundColor: 'rgba(15,118,110,0.08)', tension: 0.3, fill: false }
                ]
            },
            options: commonOpts
        }, hasProfit);

        mount('balChartInvoices', {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Facturas (total)', data: data.facturas_total, backgroundColor: 'rgba(251,146,60,0.8)', borderRadius: 4 },
                    { label: 'Pagadas', data: data.facturas_pagadas, backgroundColor: 'rgba(13,124,74,0.65)', borderRadius: 4 }
                ]
            },
            options: commonOpts
        }, hasInv);
    })();
    </script>

<?php elseif ($tab === 'statements'): ?>
    <?php
    $stmtProfit = (float)($statements['profit'] ?? 0);
    $stmtPrior = (float)($statements['prior_profit'] ?? 0);
    $equityAccounts = (float)($statements['totals']['equity'] ?? 0);
    $equityTotal = (float)($statements['totals']['equity_with_results'] ?? ($equityAccounts + $stmtPrior + $stmtProfit));
    $assetsTot = (float)($statements['totals']['assets'] ?? 0);
    $liabTot = (float)($statements['totals']['liabilities'] ?? 0);
    $stmtGap = round($assetsTot - ($liabTot + $equityTotal), 2);
    ?>
    <p style="margin:0 0 12px;font-size:13px;color:var(--color-text-secondary);max-width:900px;">
        Situación financiera = saldos acumulados a la fecha «Hasta». Resultados = movimiento del periodo (Desde–Hasta).
        Mientras no exista asiento de cierre del ejercicio, el patrimonio incluye de forma calculada los resultados
        de periodos anteriores a «Desde» y el resultado del periodo.
    </p>
    <div class="accounting-statements">
        <div class="card neumorphic"><div class="card-header"><h3>Estado de situación financiera</h3></div><div class="card-body">
            <?php foreach (['assets' => 'Activos', 'liabilities' => 'Pasivos'] as $group => $label): ?>
                <h4><?php echo $label; ?></h4>
                <?php foreach (($statements[$group] ?? []) as $row): ?>
                    <div class="accounting-statement-row"><span><?php echo htmlspecialchars($row['code'] . ' ' . $row['name']); ?></span><strong><?php echo acctFmt((float)$row['display_balance'], $currency); ?></strong></div>
                <?php endforeach; ?>
                <div class="accounting-statement-total"><span>Total <?php echo strtolower($label); ?></span><strong><?php echo acctFmt((float)($statements['totals'][$group] ?? 0), $currency); ?></strong></div>
            <?php endforeach; ?>
            <h4>Patrimonio</h4>
            <?php foreach (($statements['equity'] ?? []) as $row): ?>
                <div class="accounting-statement-row"><span><?php echo htmlspecialchars($row['code'] . ' ' . $row['name']); ?></span><strong><?php echo acctFmt((float)$row['display_balance'], $currency); ?></strong></div>
            <?php endforeach; ?>
            <div class="accounting-statement-row">
                <span>Resultados de periodos anteriores <small style="color:var(--color-text-secondary);">(calculado, antes de <?php echo htmlspecialchars(date('d/m/Y', strtotime($dateFrom) ?: time())); ?>)</small></span>
                <strong style="color:<?php echo $stmtPrior >= 0 ? '#059669' : '#DC2626'; ?>;"><?php echo acctFmt($stmtPrior, $currency); ?></strong>
            </div>
            <div class="accounting-statement-row">
                <span>Resultado del periodo <small style="color:var(--color-text-secondary);">(calculado, sin cerrar)</small></span>
                <strong style="color:<?php echo $stmtProfit >= 0 ? '#059669' : '#DC2626'; ?>;"><?php echo acctFmt($stmtProfit, $currency); ?></strong>
            </div>
            <div class="accounting-statement-total"><span>Total patrimonio</span><strong><?php echo acctFmt($equityTotal, $currency); ?></strong></div>
            <div class="accounting-statement-total" style="margin-top:10px;">
                <span>Pasivo + patrimonio</span>
                <strong><?php echo acctFmt($liabTot + $equityTotal, $currency); ?></strong>
            </div>
            <p style="font-size:12px;margin:8px 0 0;color:<?php echo abs($stmtGap) >= 1 ? '#DC2626' : 'var(--color-text-secondary)'; ?>;">
                Activos <?php echo acctFmt($assetsTot, $currency); ?>
                <?php if (abs($stmtGap) >= 1): ?>
                    · diferencia contra pasivo + patrimonio: <?php echo acctFmt($stmtGap, $currency); ?>.
                    Revise el balance de comprobación (algún comprobante descuadrado o cuenta sin tipo).
                <?php else: ?>
                    · cuadra con pasivo + patrimonio.
                <?php endif; ?>
            </p>
        </div></div>
        <div class="card neumorphic"><div class="card-header"><h3>Estado de resultados</h3></div><div class="card-body">
            <h4>Ingresos</h4>
            <?php foreach (($statements['revenue'] ?? []) as $row): ?><div class="accounting-statement-row"><span><?php echo htmlspecialchars($row['code'] . ' ' . $row['name']); ?></span><strong><?php echo acctFmt((float)$row['display_balance'], $currency); ?></strong></div><?php endforeach; ?>
            <div class="accounting-statement-total"><span>Total ingresos</span><strong><?php echo acctFmt((float)($statements['totals']['revenue'] ?? 0), $currency); ?></strong></div>
            <h4 style="margin-top:18px;">Gastos y costos</h4>
            <?php foreach (($statements['expenses'] ?? []) as $row): ?>
                <?php
                $expCode = (string)($row['code'] ?? '');
                $expOrigins = $row['origins'] ?? [];
                $ledgerHref = $viewInstance->route('app/contabilidad')
                    . '?tab=ledger&account_id=' . (int)($row['id'] ?? 0)
                    . '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo);
                ?>
                <div class="accounting-statement-row">
                    <span>
                        <?php echo htmlspecialchars($expCode . ' ' . ($row['name'] ?? '')); ?>
                        <?php if ($expOrigins && (int)($row['id'] ?? 0) > 0): ?>
                            <a href="<?php echo htmlspecialchars($ledgerHref); ?>" style="font-size:11px;margin-left:6px;font-weight:500;">ver mayor</a>
                        <?php endif; ?>
                    </span>
                    <strong><?php echo acctFmt((float)$row['display_balance'], $currency); ?></strong>
                </div>
                <?php if (!empty($expOrigins)): ?>
                    <div class="accounting-expense-origins" style="margin:2px 0 10px 12px;padding:8px 10px;border-left:3px solid rgba(148,163,184,.55);background:rgba(148,163,184,.08);border-radius:0 8px 8px 0;font-size:12px;">
                        <div style="font-weight:600;margin-bottom:6px;color:var(--color-text-secondary);">Desglose por origen</div>
                        <?php foreach ($expOrigins as $ori): ?>
                            <div class="accounting-statement-row" style="font-size:12px;padding:3px 0;">
                                <span>
                                    <?php echo htmlspecialchars((string)($ori['label'] ?? '')); ?>
                                    <small style="color:var(--color-text-secondary);">
                                        (<?php echo (int)($ori['entries'] ?? 0); ?> comprobante<?php echo (int)($ori['entries'] ?? 0) === 1 ? '' : 's'; ?>)
                                    </small>
                                </span>
                                <strong><?php echo acctFmt((float)($ori['amount'] ?? 0), $currency); ?></strong>
                            </div>
                            <?php foreach (($ori['categories'] ?? []) as $cat): ?>
                                <div class="accounting-statement-row" style="font-size:11px;padding:2px 0 2px 14px;color:var(--color-text-secondary);">
                                    <span>· <?php echo htmlspecialchars((string)($cat['label'] ?? '')); ?>
                                        <small>(<?php echo (int)($cat['entries'] ?? 0); ?>)</small>
                                    </span>
                                    <span><?php echo acctFmt((float)($cat['amount'] ?? 0), $currency); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="accounting-statement-total"><span>Total gastos</span><strong><?php echo acctFmt((float)($statements['totals']['expenses'] ?? 0), $currency); ?></strong></div>
            <div class="accounting-statement-total" style="margin-top:18px;color:<?php echo ($statements['profit'] ?? 0) >= 0 ? '#10B981' : '#DC2626'; ?>;"><span>Resultado del periodo</span><strong><?php echo acctFmt((float)($statements['profit'] ?? 0), $currency); ?></strong></div>
        </div></div>
    </div>

<?php elseif ($tab === 'accounts'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="margin:0;">Plan de cuentas</h3>
        <?php if ($canEdit): ?><button type="button" class="btn btn-primary" onclick="newAccountingAccount()">Nueva cuenta</button><?php endif; ?>
    </div>
    <div class="card neumorphic" style="margin-bottom:14px;">
        <div class="card-header"><h3>Auditoría contable (CxP y comisiones)</h3></div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin-bottom:10px;">
                Cuentas críticas: <code>530505</code> gastos financieros/comisiones,
                <code>220505</code> proveedores (CxP en compras a crédito),
                <code>143501</code> inventario.
            </p>
            <ul style="font-size:13px;margin:0 0 12px 18px;">
                <?php foreach (($accountAudit['notes'] ?? []) as $note): ?>
                    <li><?php echo htmlspecialchars($note); ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="font-size:13px;margin:0;">
                Las comisiones de vendedor y pasarela (datáfono, tarjetas, link) se parametrizan y liquidan en
                <a href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=commissions">Contabilidad → Comisiones</a>.
            </p>
        </div>
    </div>
    <div class="card neumorphic"><div class="card-body"><div class="table-container"><table>
        <thead><tr><th>Código</th><th>Cuenta</th><th>Tipo</th><th>Naturaleza</th><th>Estado</th><th>Origen</th><th>Acción</th></tr></thead>
        <tbody><?php foreach ($accounts as $account): ?><tr>
            <td><strong><?php echo htmlspecialchars($account['code']); ?></strong></td>
            <td><?php echo htmlspecialchars($account['name']); ?></td>
            <td><?php echo htmlspecialchars($types[$account['account_type']] ?? $account['account_type']); ?></td>
            <td><?php echo $account['nature'] === 'debit' ? 'Débito' : 'Crédito'; ?></td>
            <td><span class="badge <?php echo $account['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>"><?php echo $account['status'] === 'active' ? 'Activa' : 'Inactiva'; ?></span></td>
            <td><?php echo !empty($account['is_system']) ? 'Sistema' : 'Usuario'; ?></td>
            <td><?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-secondary" onclick='editAccountingAccount(<?php echo json_encode($account, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Editar</button><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div></div></div>

<?php elseif ($tab === 'commissions'): ?>
    <?php
    function fmtComm(float $a, array $c): string {
        return ($c['symbol'] ?? '$') . ' ' . number_format($a, (int)($c['decimals'] ?? 2), $c['decimal'] ?? ',', $c['thousands'] ?? '.');
    }
    ?>
    <div class="stats-grid" style="margin-bottom:16px;">
        <div class="stat-card neumorphic"><h4>Pendientes</h4><div class="stat-value" style="color:#F59E0B;"><?php echo fmtComm((float)$commissionList['pending'], $currency); ?></div></div>
        <div class="stat-card neumorphic"><h4>Pagadas / registradas</h4><div class="stat-value" style="color:#10B981;"><?php echo fmtComm((float)$commissionList['paid'], $currency); ?></div></div>
        <div class="stat-card neumorphic"><h4>Canceladas</h4><div class="stat-value"><?php echo fmtComm((float)$commissionList['cancelled'], $currency); ?></div></div>
    </div>

    <?php $commissionPending = $commissionPending ?? ['count' => 0, 'amount' => 0, 'last_error' => '']; ?>
    <?php if ((int)$commissionPending['count'] > 0): ?>
    <div class="alert alert-warning" style="margin-bottom:16px;padding:12px 14px;border-radius:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.45);">
        <strong><?php echo (int)$commissionPending['count']; ?> comisión(es) de datáfono / link de pago sin gasto ni asiento</strong>
        (<?php echo fmtComm((float)$commissionPending['amount'], $currency); ?>).
        Se reintentan con «Sincronizar» en Contabilidad o con «Recalcular comisiones del periodo».
        <?php if ($commissionPending['last_error'] !== ''): ?>
            <br><small>Último error: <?php echo htmlspecialchars($commissionPending['last_error']); ?></small>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header"><h3>Parámetros de comisiones</h3></div>
        <div class="card-body">
            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=save-commission-config" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <h4 style="margin:0 0 10px;">Comisión de vendedor</h4>
                <div class="form-grid">
                    <div class="form-group"><label><input type="checkbox" name="seller_enabled" value="1" <?php echo !empty($commissionCfg['seller_enabled']) ? 'checked' : ''; ?>> Activar comisión de vendedor</label></div>
                    <div class="form-group"><label><input type="checkbox" name="seller_auto_expense" value="1" <?php echo !empty($commissionCfg['seller_auto_expense']) ? 'checked' : ''; ?>> Generar gasto/asiento al instante</label></div>
                    <div class="form-group"><label>% global (si el usuario no tiene tasa propia)</label>
                        <input class="form-control" type="number" step="0.01" min="0" max="100" name="seller_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['seller_rate'] ?? 3)); ?>">
                    </div>
                    <div class="form-group"><label>Base de cálculo</label>
                        <select class="form-control" name="seller_base">
                            <option value="total" <?php echo ($commissionCfg['seller_base'] ?? '') === 'total' ? 'selected' : ''; ?>>Total de la venta / abono</option>
                            <option value="subtotal" <?php echo ($commissionCfg['seller_base'] ?? '') === 'subtotal' ? 'selected' : ''; ?>>Subtotal (sin IVA)</option>
                            <option value="profit" <?php echo ($commissionCfg['seller_base'] ?? '') === 'profit' ? 'selected' : ''; ?>>Utilidad (total − costo)</option>
                        </select>
                    </div>
                    <div class="form-group"><label>¿Cuándo se genera?</label>
                        <select class="form-control" name="seller_trigger">
                            <option value="on_payment" <?php echo ($commissionCfg['seller_trigger'] ?? '') === 'on_payment' ? 'selected' : ''; ?>>Al cobrar (recomendado)</option>
                            <option value="on_sale" <?php echo ($commissionCfg['seller_trigger'] ?? '') === 'on_sale' ? 'selected' : ''; ?>>Al crear la venta (sobre el total)</option>
                        </select>
                    </div>
                </div>
                <h4 style="margin:18px 0 10px;">Comisión de pasarela (datáfono / link de pago)</h4>
                <div class="form-group"><label><input type="checkbox" name="gateway_auto" value="1" <?php echo !empty($commissionCfg['gateway_auto']) ? 'checked' : ''; ?>> Generar automáticamente al registrar el pago</label></div>
                <?php
                $dpFee = (float)($commissionCfg['dataphone_fixed_fee'] ?? 0);
                $dpFeeTxt = $dpFee > 0
                    ? number_format($dpFee, (int)($currency['decimals'] ?? 0), $currency['decimal'] ?? ',', $currency['thousands'] ?? '.')
                    : '';
                ?>
                <div class="gateway-dataphone-box">
                    <div class="gateway-dataphone-box__head">
                        <strong>Datáfono</strong>
                        <small>Aplica a cada pago con tarjeta débito o crédito: costo fijo por transacción + % según el tipo de tarjeta.</small>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Costo fijo por transacción ($)</label>
                            <input class="form-control money-input" type="text" inputmode="decimal" data-money="1" autocomplete="off"
                                   name="dataphone_fixed_fee" placeholder="0" value="<?php echo htmlspecialchars($dpFeeTxt); ?>">
                        </div>
                    </div>
                    <div class="gateway-dataphone-box__subs">
                        <div class="gateway-dataphone-box__sub">
                            <span class="gateway-dataphone-box__sub-title">↳ Tarjeta débito</span>
                            <div class="form-group" style="margin:0;"><label>% comisión débito</label><input class="form-control" type="number" step="0.01" min="0" max="30" name="debit_card_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['debit_card_rate'] ?? 1.5)); ?>"></div>
                        </div>
                        <div class="gateway-dataphone-box__sub">
                            <span class="gateway-dataphone-box__sub-title">↳ Tarjeta crédito</span>
                            <div class="form-group" style="margin:0;"><label>% comisión crédito</label><input class="form-control" type="number" step="0.01" min="0" max="30" name="credit_card_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['credit_card_rate'] ?? 2.8)); ?>"></div>
                        </div>
                    </div>
                    <small class="gateway-dataphone-box__example" id="dataphoneFeeExample"></small>
                </div>
                <div class="form-grid" style="margin-top:12px;">
                    <div class="form-group"><label>% Link de pago</label><input class="form-control" type="number" step="0.01" min="0" max="30" name="payment_link_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['payment_link_rate'] ?? 2.5)); ?>"></div>
                </div>
                <div class="gateway-dataphone-box gateway-tax-box" style="margin-top:12px;">
                    <div class="gateway-dataphone-box__head">
                        <strong>IVA y retenciones del adquirente</strong>
                        <small>Lo que el banco / pasarela descuenta además de la comisión en cada pago con datáfono o link de pago. Deje en 0 lo que no le apliquen; valide las tarifas con su contador.</small>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>% IVA sobre la comisión</label>
                            <input class="form-control" type="number" step="0.01" min="0" max="100" name="gateway_vat_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['gateway_vat_rate'] ?? 0)); ?>">
                            <label style="font-weight:normal;margin-top:6px;"><input type="checkbox" name="gateway_vat_deductible" value="1" <?php echo !empty($commissionCfg['gateway_vat_deductible']) ? 'checked' : ''; ?>> IVA descontable (cuenta <?php echo htmlspecialchars((string)($commissionCfg['gateway_vat_account'] ?? '240802')); ?>); sin marcar se suma al gasto</label>
                        </div>
                        <div class="form-group">
                            <label>% Retención en la fuente</label>
                            <input class="form-control" type="number" step="0.01" min="0" max="100" name="gateway_withholding_renta_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['withholding_renta_rate'] ?? 0)); ?>">
                            <small>Sobre el pago sin IVA · cuenta <?php echo htmlspecialchars((string)($commissionCfg['withholding_renta_account'] ?? '135515')); ?></small>
                        </div>
                        <div class="form-group">
                            <label>% ReteIVA</label>
                            <input class="form-control" type="number" step="0.01" min="0" max="100" name="gateway_withholding_iva_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['withholding_iva_rate'] ?? 0)); ?>">
                            <small>Sobre el IVA de la venta · cuenta <?php echo htmlspecialchars((string)($commissionCfg['withholding_iva_account'] ?? '135517')); ?></small>
                        </div>
                        <div class="form-group">
                            <label>ReteICA (por mil ‰)</label>
                            <input class="form-control" type="number" step="0.001" min="0" max="1000" name="gateway_withholding_ica_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['withholding_ica_rate'] ?? 0)); ?>">
                            <small>Sobre el pago sin IVA · cuenta <?php echo htmlspecialchars((string)($commissionCfg['withholding_ica_account'] ?? '135518')); ?></small>
                        </div>
                    </div>
                </div>
                <h4 style="margin:18px 0 10px;">Crédito largo (recargo por tramos)</h4>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">
                    Defina varias condiciones: si el plazo de la venta es <strong>mayor</strong> a X días, se aplica el %.
                    Si varios tramos aplican, gana el de más días (ej. 90d con tramos 30→1% y 60→2% usa 2%).
                </p>
                <div id="creditSurchargeTiers" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
                    <?php
                    $tiersUi = $commissionCfg['credit_surcharge_tiers'] ?? [];
                    if (empty($tiersUi) && !empty($commissionCfg['credit_over_60_rate'])) {
                        $tiersUi = [[
                            'min_days' => (int)($commissionCfg['credit_over_60_days'] ?? 60),
                            'rate' => (float)$commissionCfg['credit_over_60_rate'],
                        ]];
                    }
                    if (empty($tiersUi)) {
                        $tiersUi = [
                            ['min_days' => 60, 'rate' => 0],
                            ['min_days' => 90, 'rate' => 0],
                            ['min_days' => 120, 'rate' => 0],
                        ];
                    }
                    foreach ($tiersUi as $tier):
                    ?>
                    <div class="credit-tier-row" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                        <div class="form-group" style="margin:0;">
                            <label>Mayor a (días)</label>
                            <input class="form-control" style="width:110px;" type="number" min="1" max="3650" step="1" name="credit_tier_days[]" value="<?php echo (int)$tier['min_days']; ?>">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>% recargo</label>
                            <input class="form-control" style="width:110px;" type="number" min="0" max="100" step="0.01" name="credit_tier_rates[]" value="<?php echo htmlspecialchars((string)$tier['rate']); ?>">
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.credit-tier-row').remove()" title="Quitar">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addCreditSurchargeTier()">+ Agregar tramo</button>
                <input type="hidden" name="credit_over_60_days" value="<?php echo (int)($commissionCfg['credit_over_60_days'] ?? 60); ?>">
                <input type="hidden" name="credit_over_60_rate" value="<?php echo htmlspecialchars((string)($commissionCfg['credit_over_60_rate'] ?? 0)); ?>">
                <script>
                (function () {
                    var box = document.querySelector('.gateway-dataphone-box');
                    var out = document.getElementById('dataphoneFeeExample');
                    if (!box || !out) return;
                    function num(v) {
                        if (window.SeriMoney && typeof SeriMoney.parse === 'function') return SeriMoney.parse(v) || 0;
                        return parseFloat(String(v || '').replace(/\./g, '').replace(',', '.')) || 0;
                    }
                    function money(n) {
                        var r = Math.round(n * 100) / 100;
                        return '$ ' + (window.SeriMoney && typeof SeriMoney.format === 'function'
                            ? SeriMoney.format(r)
                            : r.toLocaleString('es-CO', { maximumFractionDigits: 2 }));
                    }
                    function pct(n) { return String(Math.round(n * 100) / 100).replace('.', ','); }
                    function refresh() {
                        var fixed = num(box.querySelector('[name="dataphone_fixed_fee"]').value);
                        var deb = parseFloat(box.querySelector('[name="debit_card_rate"]').value) || 0;
                        var cre = parseFloat(box.querySelector('[name="credit_card_rate"]').value) || 0;
                        var base = 100000;
                        out.innerHTML = 'Ejemplo pago de ' + money(base) + ': '
                            + '<strong>débito</strong> ' + money(fixed) + ' + ' + pct(deb) + '% = <strong>' + money(fixed + base * deb / 100) + '</strong> · '
                            + '<strong>crédito</strong> ' + money(fixed) + ' + ' + pct(cre) + '% = <strong>' + money(fixed + base * cre / 100) + '</strong>';
                    }
                    box.addEventListener('input', refresh);
                    box.addEventListener('change', refresh);
                    refresh();
                })();
                function addCreditSurchargeTier() {
                    var wrap = document.getElementById('creditSurchargeTiers');
                    if (!wrap) return;
                    var row = document.createElement('div');
                    row.className = 'credit-tier-row';
                    row.style.cssText = 'display:flex;gap:10px;align-items:end;flex-wrap:wrap;';
                    row.innerHTML =
                        '<div class="form-group" style="margin:0;"><label>Mayor a (días)</label>' +
                        '<input class="form-control" style="width:110px;" type="number" min="1" max="3650" step="1" name="credit_tier_days[]" value="60"></div>' +
                        '<div class="form-group" style="margin:0;"><label>% recargo</label>' +
                        '<input class="form-control" style="width:110px;" type="number" min="0" max="100" step="0.01" name="credit_tier_rates[]" value="0"></div>' +
                        '<button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.credit-tier-row\').remove()" title="Quitar">✕</button>';
                    wrap.appendChild(row);
                }
                function addQtyDiscountTier() {
                    var wrap = document.getElementById('qtyDiscountTiers');
                    if (!wrap) return;
                    var row = document.createElement('div');
                    row.className = 'qty-discount-tier-row';
                    row.style.cssText = 'display:flex;gap:10px;align-items:end;flex-wrap:wrap;';
                    row.innerHTML =
                        '<div class="form-group" style="margin:0;"><label>Mayor a (unidades)</label>' +
                        '<input class="form-control" style="width:130px;" type="number" min="1" max="1000000" step="1" name="qty_discount_qty[]" value="10"></div>' +
                        '<div class="form-group" style="margin:0;"><label>% descuento</label>' +
                        '<input class="form-control" style="width:110px;" type="number" min="0" max="100" step="0.01" name="qty_discount_rates[]" value="0"></div>' +
                        '<button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.qty-discount-tier-row\').remove()" title="Quitar">✕</button>';
                    wrap.appendChild(row);
                }
                </script>

                <h4 style="margin:22px 0 10px;">Descuento por cantidad (cualquier producto)</h4>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">
                    Si en Caja un producto supera X unidades, se aplica automáticamente el % de descuento sobre su precio.
                    Aplica a <strong>cualquier</strong> producto. Si varios tramos aplican, gana el de más unidades
                    (ej. 25 uds con tramos 10→5% y 20→8% usa 8%).
                </p>
                <div id="qtyDiscountTiers" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
                    <?php
                    $qtyTiersUi = $commissionCfg['quantity_discount_tiers'] ?? [];
                    if (empty($qtyTiersUi)) {
                        $qtyTiersUi = [
                            ['min_qty' => 10, 'rate' => 0],
                            ['min_qty' => 20, 'rate' => 0],
                            ['min_qty' => 50, 'rate' => 0],
                        ];
                    }
                    foreach ($qtyTiersUi as $qt):
                    ?>
                    <div class="qty-discount-tier-row" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                        <div class="form-group" style="margin:0;">
                            <label>Mayor a (unidades)</label>
                            <input class="form-control" style="width:130px;" type="number" min="1" max="1000000" step="1" name="qty_discount_qty[]" value="<?php echo (int)$qt['min_qty']; ?>">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>% descuento</label>
                            <input class="form-control" style="width:110px;" type="number" min="0" max="100" step="0.01" name="qty_discount_rates[]" value="<?php echo htmlspecialchars((string)$qt['rate']); ?>">
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.qty-discount-tier-row').remove()" title="Quitar">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addQtyDiscountTier()">+ Agregar tramo</button>
                <button type="submit" class="btn btn-primary" style="margin-top:12px;">Guardar parámetros</button>
            </form>
            <?php else: ?>
                <p class="dashboard-empty">Sin permiso de edición.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header"><h3>Tasas por vendedor (usuario)</h3></div>
        <div class="card-body"><div class="table-container"><table>
            <thead><tr><th>Usuario</th><th>Rol</th><th>% comisión</th><th>Activa</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($commissionUsers as $u): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['name']); ?><br><small><?php echo htmlspecialchars($u['email'] ?? ''); ?></small></td>
                    <td><?php echo htmlspecialchars($u['role'] ?? ''); ?></td>
                    <?php if ($canEdit): ?>
                    <td colspan="3">
                        <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=save-user-commission" data-ajax="true" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <input class="form-control" style="width:100px;" type="number" step="0.01" min="0" max="100" name="rate" value="<?php echo htmlspecialchars((string)($u['rate'] ?? ($commissionCfg['seller_rate'] ?? 0))); ?>">
                            <label><input type="checkbox" name="enabled" value="1" <?php echo !empty($u['rate_enabled']) ? 'checked' : ''; ?>> Usar tasa</label>
                            <button class="btn btn-sm btn-secondary" type="submit">Guardar</button>
                        </form>
                    </td>
                    <?php else: ?>
                    <td><?php echo $u['rate'] !== null ? (float)$u['rate'] . '%' : 'Global'; ?></td>
                    <td><?php echo !empty($u['rate_enabled']) ? 'Sí' : '—'; ?></td>
                    <td></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p style="font-size:12px;color:var(--color-text-secondary);margin-top:8px;">Sin «Usar tasa» → aplica el % global. Con tasa 0% activa → ese usuario no genera comisión.</p>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <h3 style="margin:0;">Movimientos de comisiones</h3>
            <form method="GET" action="<?php echo $viewInstance->route('app/contabilidad'); ?>" style="display:flex;gap:6px;flex-wrap:wrap;">
                <input type="hidden" name="tab" value="commissions">
                <input type="hidden" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                <input type="hidden" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
                <select name="kind" class="form-control" style="width:auto;">
                    <option value="">Todos los tipos</option>
                    <option value="seller" <?php echo $kindFilter === 'seller' ? 'selected' : ''; ?>>Vendedor</option>
                    <option value="gateway" <?php echo $kindFilter === 'gateway' ? 'selected' : ''; ?>>Pasarela</option>
                </select>
                <select name="status" class="form-control" style="width:auto;">
                    <option value="">Todos los estados</option>
                    <option value="pending" <?php echo $statusFilterComm === 'pending' ? 'selected' : ''; ?>>Pendiente</option>
                    <option value="paid" <?php echo $statusFilterComm === 'paid' ? 'selected' : ''; ?>>Pagada</option>
                    <option value="cancelled" <?php echo $statusFilterComm === 'cancelled' ? 'selected' : ''; ?>>Cancelada</option>
                </select>
                <button class="btn btn-secondary" type="submit">Filtrar</button>
            </form>
            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=recalc-commissions" data-ajax="true" style="margin:0;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                <input type="hidden" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
                <button class="btn btn-primary" type="submit"
                        title="Crea las comisiones faltantes de pagos con datáfono / link de pago del periodo y contabiliza las pendientes"
                        onclick="return confirm('¿Recalcular comisiones de datáfono / link de pago del <?php echo htmlspecialchars($dateFrom); ?> al <?php echo htmlspecialchars($dateTo); ?>? Solo crea las que falten; no duplica ni recrea las anuladas.')">Recalcular comisiones del periodo</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=settle-commissions" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
            <?php endif; ?>
            <div class="table-container"><table>
                <thead><tr>
                    <?php if ($canEdit): ?><th></th><?php endif; ?>
                    <th>Fecha</th><th>Tipo</th><th>Venta</th><th>Vendedor / medio</th><th>Base</th><th>%</th><th>Monto</th><th>Estado</th>
                </tr></thead>
                <tbody>
                <?php if (empty($commissionList['rows'])): ?>
                    <tr><td colspan="9" class="dashboard-empty">Sin comisiones en el periodo. Active los parámetros y registre ventas.</td></tr>
                <?php endif; ?>
                <?php foreach ($commissionList['rows'] as $c): ?>
                    <tr>
                        <?php if ($canEdit): ?>
                        <td><?php if (($c['status'] ?? '') === 'pending'): ?>
                            <input type="checkbox" name="ids[]" value="<?php echo (int)$c['id']; ?>">
                        <?php endif; ?></td>
                        <?php endif; ?>
                        <td><?php echo !empty($c['accrual_date'])
                            ? date('d/m/Y', strtotime($c['accrual_date']))
                            : date('d/m/Y H:i', strtotime($c['created_at'])); ?></td>
                        <td><?php echo ($c['commission_kind'] ?? '') === 'gateway' ? 'Pasarela' : 'Vendedor'; ?></td>
                        <td><?php echo htmlspecialchars($c['invoice_number'] ?? ('#' . $c['sale_id'])); ?></td>
                        <?php
                        $cIsGateway = ($c['commission_kind'] ?? '') === 'gateway';
                        $cMethod = (string)($c['payment_method'] ?? '');
                        $cFixed = $cIsGateway
                            ? round((float)$c['amount'] - round((float)$c['base_amount'] * (float)$c['rate'] / 100, 2), 2)
                            : 0.0;
                        $cVat = (float)($c['vat_amount'] ?? 0);
                        $cRet = (float)($c['withholding_renta'] ?? 0) + (float)($c['withholding_iva'] ?? 0) + (float)($c['withholding_ica'] ?? 0);
                        ?>
                        <td><?php echo htmlspecialchars($c['seller_name'] ?? ($cMethod !== '' ? \SoftNova\Services\PaymentMethodCatalog::label($cMethod) : '—')); ?></td>
                        <td><?php echo fmtComm((float)$c['base_amount'], $currency); ?></td>
                        <td><?php echo (float)$c['rate']; ?>%<?php if ($cFixed > 0.009): ?> <small style="color:var(--color-text-secondary);">+ <?php echo fmtComm($cFixed, $currency); ?></small><?php endif; ?></td>
                        <td><strong><?php echo fmtComm((float)$c['amount'], $currency); ?></strong>
                            <?php if ($cVat > 0.009): ?><br><small style="color:var(--color-text-secondary);">+ IVA <?php echo fmtComm($cVat, $currency); ?></small><?php endif; ?>
                            <?php if ($cRet > 0.009): ?><br><small style="color:var(--color-text-secondary);">Retenciones <?php echo fmtComm($cRet, $currency); ?></small><?php endif; ?>
                        </td>
                        <td><span class="badge"><?php echo htmlspecialchars($c['status']); ?></span>
                            <?php if (($c['status'] ?? '') === 'pending' && !empty($c['last_error'])): ?><br><small style="color:#DC2626;" title="<?php echo htmlspecialchars($c['last_error']); ?>">Error al contabilizar</small><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary" style="margin-top:12px;" onclick="return confirm('¿Liquidar seleccionadas (crear gasto + asiento)?')">Liquidar seleccionadas</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'periods'): ?>
    <div class="card neumorphic"><div class="card-header"><h3>Cierre de periodos</h3></div><div class="card-body">
        <?php
        $pSync = (int)($pendingSync ?? 0);
        $pQueue = (int)($pendingQueue ?? 0);
        if ($pSync > 0 || $pQueue > 0):
        ?>
            <div style="margin-bottom:14px;padding:10px 12px;border-radius:8px;background:rgba(245,158,11,.12);font-size:13px;">
                <strong>Antes de cerrar:</strong>
                <?php if ($pSync > 0): ?>
                    <?php echo $pSync; ?> operación(es) sin comprobante
                <?php endif; ?>
                <?php if ($pQueue > 0): ?>
                    <?php echo $pSync > 0 ? ' · ' : ''; ?><?php echo $pQueue; ?> en cola de reintento
                <?php endif; ?>
                . Use «Sincronizar» en el tablero; el cierre se rechaza si quedan pendientes.
            </div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=period" data-ajax="true" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:18px;"
                  onsubmit="var st=this.status.value; if(st==='closed'){ return confirm('¿Cerrar el periodo? Quedará bloqueado: no se podrán contabilizar documentos de ese mes (ni re-fechar a otro). Debe sincronizar pendientes antes.'); } return confirm('¿Reabrir el periodo? Quedará registro en notas/usuario de cierre.');">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group" style="margin:0;"><label>Año</label><input class="form-control" type="number" name="year" min="2000" max="2100" value="<?php echo date('Y'); ?>" required></div>
                <div class="form-group" style="margin:0;"><label>Mes</label><select class="form-control" name="month"><?php for ($m=1;$m<=12;$m++): ?><option value="<?php echo $m; ?>" <?php echo $m === (int)date('n') ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endfor; ?></select></div>
                <div class="form-group" style="margin:0;"><label>Estado</label><select class="form-control" name="status"><option value="closed">Cerrar</option><option value="open">Reabrir</option></select></div>
                <div class="form-group" style="margin:0;min-width:220px;"><label>Notas</label><input class="form-control" name="notes" placeholder="Motivo de cierre o reapertura"></div>
                <button class="btn btn-primary" type="submit">Actualizar periodo</button>
            </form>
            <p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
                Cerrar un periodo <strong>bloquea todos los asientos</strong> de ese mes (automáticos y manuales). No se re-fechan a otro mes.
                La reapertura queda auditada (usuario + fecha + notas).
            </p>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=close-year" data-ajax="true"
                  style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:18px;padding-top:12px;border-top:1px solid var(--color-border,#e5e7eb);"
                  onsubmit="return confirm('¿Generar el asiento de cierre de resultado del año? Traspasará ingresos y gastos a Utilidad del ejercicio (360505). El 31/12 debe estar en periodo abierto. Es idempotente por año.');">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group" style="margin:0;"><label>Cierre anual</label><input class="form-control" type="number" name="year" min="2000" max="2100" value="<?php echo (int)date('Y') - 1; ?>" required></div>
                <div class="form-group" style="margin:0;min-width:220px;"><label>Notas</label><input class="form-control" name="notes" placeholder="Opcional"></div>
                <button class="btn btn-secondary" type="submit">Cerrar resultado del año</button>
            </form>
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 14px;">
                El cierre anual crea un comprobante al 31/12 que deja en cero las cuentas de ingreso/gasto del año contra 360505.
                Si ya existe para ese año, no se duplica.
            </p>
        <?php endif; ?>
        <div class="table-container"><table><thead><tr><th>Periodo</th><th>Estado</th><th>Cerrado por</th><th>Fecha</th><th>Notas</th></tr></thead><tbody>
            <?php if (!$periods): ?><tr><td colspan="5" class="dashboard-empty">Todos los periodos están abiertos</td></tr><?php endif; ?>
            <?php foreach ($periods as $period): ?><tr>
                <td><?php echo str_pad((string)$period['month'], 2, '0', STR_PAD_LEFT) . '/' . $period['year']; ?></td>
                <td><span class="badge <?php echo $period['status'] === 'closed' ? 'badge-danger' : 'badge-success'; ?>"><?php echo $period['status'] === 'closed' ? 'Cerrado' : 'Abierto'; ?></span></td>
                <td><?php echo htmlspecialchars($period['closed_by_name'] ?: '-'); ?></td>
                <td><?php echo $period['closed_at'] ? date('d/m/Y H:i', strtotime($period['closed_at'])) : '-'; ?></td>
                <td><?php echo htmlspecialchars($period['notes'] ?: '-'); ?></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>

<?php elseif ($tab === 'invoice-trace'): ?>
    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h3 style="margin:0;">Trazabilidad de facturas de compra</h3>
            <?php if ($canExport): ?>
            <a class="btn btn-sm btn-secondary"
               href="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=export&report=invoice-trace&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&q=<?php echo urlencode($invoiceTraceQ); ?>&trace_sort=<?php echo urlencode($invoiceTraceSort); ?>&trace_dir=<?php echo urlencode($invoiceTraceDir); ?>">
                Exportar CSV
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p style="color:var(--color-text-secondary);margin-top:0;">
                Ciclo de vida de cada factura/OC: fechas (factura, vencimiento, bodega), carga, revisión, recepción, asiento, abonos y notas de crédito.
            </p>
            <?php if (empty($invoiceTraceOrders)): ?>
                <p style="color:var(--color-text-secondary);">No hay facturas/OC en el periodo.</p>
            <?php else: ?>
                <div class="invoice-trace-layout">
                    <div class="table-container">
                        <table>
                            <?php
                            $traceTh = static function (string $key, string $label) use ($viewInstance, $dateFrom, $dateTo, $invoiceTraceQ, $invoiceTraceSort, $invoiceTraceDir): string {
                                $active = $invoiceTraceSort === $key;
                                $nextDir = $active && $invoiceTraceDir === 'asc' ? 'desc' : 'asc';
                                $url = $viewInstance->route('app/contabilidad')
                                    . '?tab=invoice-trace&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo)
                                    . '&q=' . urlencode($invoiceTraceQ)
                                    . '&trace_sort=' . urlencode($key) . '&trace_dir=' . $nextDir;
                                $arrow = $active ? ($invoiceTraceDir === 'asc' ? ' ▲' : ' ▼') : ' ⇅';
                                $title = $active
                                    ? ($invoiceTraceDir === 'asc' ? 'Ascendente · clic para descendente' : 'Descendente · clic para ascendente')
                                    : 'Clic para ordenar ascendente';
                                return '<a href="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($title) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">'
                                    . htmlspecialchars($label) . $arrow . '</a>';
                            };
                            ?>
                            <thead>
                                <tr>
                                    <th><?php echo $traceTh('oc', 'OC / Factura'); ?></th>
                                    <th><?php echo $traceTh('supplier', 'Proveedor'); ?></th>
                                    <th><?php echo $traceTh('date', 'Fechas'); ?></th>
                                    <th><?php echo $traceTh('total', 'Total'); ?></th>
                                    <th><?php echo $traceTh('status', 'Estado'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($invoiceTraceOrders as $o): ?>
                                <?php
                                $bal = round(
                                    (float)$o['total']
                                    - (float)($o['paid_total'] ?? 0)
                                    - (float)($o['credit_total'] ?? 0),
                                    2
                                );
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($o['order_number'] ?? ''); ?></strong>
                                        <div style="font-size:12px;color:var(--color-text-secondary);">
                                            <?php echo htmlspecialchars($o['invoice_number'] ?? 'Sin factura'); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($o['supplier_name'] ?? '—'); ?></td>
                                    <td style="font-size:12px;line-height:1.45;">
                                        <?php
                                        $fmtTraceDate = static function ($v): string {
                                            $v = trim((string)$v);
                                            if ($v === '' || str_starts_with($v, '0000-00-00')) {
                                                return '—';
                                            }
                                            $ts = strtotime($v);
                                            return $ts ? date('d/m/Y', $ts) : '—';
                                        };
                                        ?>
                                        <div><strong>Fact:</strong> <?php echo htmlspecialchars($fmtTraceDate($o['invoice_date'] ?? '')); ?></div>
                                        <div><strong>Vence:</strong> <?php echo htmlspecialchars($fmtTraceDate($o['due_date'] ?? '')); ?></div>
                                        <div><strong>Bodega:</strong> <?php echo htmlspecialchars($fmtTraceDate($o['warehouse_date'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <?php echo acctFmt((float)$o['total'], $currency); ?>
                                        <?php if ($bal > 0.009): ?>
                                            <div style="font-size:11px;color:var(--dmc-DC2626, #DC2626);">Saldo <?php echo acctFmt($bal, $currency); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($statusLabelsPo[$o['status'] ?? ''] ?? ($o['status'] ?? '')); ?></span>
                                        <div style="font-size:11px;color:var(--color-text-secondary);margin-top:4px;">
                                            <?php echo (int)($o['payments_count'] ?? 0); ?> abono(s)
                                            · <?php echo (int)($o['credit_notes_count'] ?? 0); ?> NC
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary"
                                                onclick="openInvoiceTimeline(<?php echo (int)$o['id']; ?>)">Timeline</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="invoiceTimelineModal" class="modal-overlay" style="display:none;">
        <div class="modal-content neumorphic" style="max-width:720px;max-height:90vh;overflow:auto;">
            <div class="modal-header">
                <h3 id="invoiceTimelineTitle">Timeline de factura</h3>
                <button type="button" class="modal-close" onclick="document.getElementById('invoiceTimelineModal').style.display='none'">&times;</button>
            </div>
            <div class="modal-body">
                <div id="invoiceTimelineMeta" style="margin-bottom:14px;font-size:13px;color:var(--color-text-secondary);"></div>
                <ol id="invoiceTimelineList" class="invoice-timeline"></ol>
            </div>
        </div>
    </div>
    <script>
    window.invoiceTimelineUrl = <?php echo json_encode($viewInstance->route('app/contabilidad') . '?action=invoice-timeline'); ?>;
    function openInvoiceTimeline(id) {
        fetch(window.invoiceTimelineUrl + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return showAlert(d.message || 'No se pudo cargar', 'error');
                var o = d.order || {};
                var events = d.events || [];
                document.getElementById('invoiceTimelineTitle').textContent =
                    'Timeline · ' + (o.order_number || ('#' + id));
                var sym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
                var dec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0', 10);
                document.getElementById('invoiceTimelineMeta').innerHTML =
                    '<strong>' + esc(o.supplier_name || 'Sin proveedor') + '</strong>'
                    + ' · Factura ' + esc(o.invoice_number || '—')
                    + ' · Total ' + sym + parseFloat(o.total || 0).toFixed(dec)
                    + (o.balance != null ? (' · Saldo ' + sym + parseFloat(o.balance || 0).toFixed(dec)) : '')
                    + '<div style="margin-top:10px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;">'
                    + '<div style="padding:8px 10px;border-radius:8px;background:rgba(30,58,138,0.08);border:1px solid rgba(30,58,138,0.2);">'
                    + '<div style="font-size:11px;color:var(--color-text-secondary);">Fecha de factura</div>'
                    + '<div style="font-weight:700;color:var(--dmc-1E3A8A, #1e3a8a);">' + esc(formatTimelineAt(o.invoice_date)) + '</div></div>'
                    + '<div style="padding:8px 10px;border-radius:8px;background:rgba(180,83,9,0.08);border:1px solid rgba(180,83,9,0.25);">'
                    + '<div style="font-size:11px;color:var(--color-text-secondary);">Vencimiento de pago</div>'
                    + '<div style="font-weight:700;color:var(--dmc-B45309, #B45309);">' + esc(formatTimelineAt(o.due_date)) + '</div></div>'
                    + '<div style="padding:8px 10px;border-radius:8px;background:rgba(4,120,87,0.08);border:1px solid rgba(4,120,87,0.25);">'
                    + '<div style="font-size:11px;color:var(--color-text-secondary);">Recepción de bodega</div>'
                    + '<div style="font-weight:700;color:var(--dmc-047857, #047857);">' + esc(formatTimelineAt(o.warehouse_date)) + '</div></div>'
                    + '</div>';
                var list = document.getElementById('invoiceTimelineList');
                list.innerHTML = '';
                if (!events.length) {
                    list.innerHTML = '<li class="invoice-timeline-empty">Sin eventos registrados</li>';
                } else {
                    events.forEach(function(ev) {
                        var li = document.createElement('li');
                        li.className = 'invoice-timeline-item type-' + esc(ev.type || '');
                        var amt = (ev.amount != null && Math.abs(parseFloat(ev.amount)) > 0.009)
                            ? ('<span class="invoice-timeline-amt">' + sym + parseFloat(ev.amount).toFixed(dec) + '</span>')
                            : '';
                        li.innerHTML =
                            '<div class="invoice-timeline-dot"></div>'
                            + '<div class="invoice-timeline-body">'
                            + '<div class="invoice-timeline-when">' + esc(formatTimelineAt(ev.at)) + '</div>'
                            + '<div class="invoice-timeline-label">' + esc(ev.label || '') + amt + '</div>'
                            + (ev.detail ? ('<div class="invoice-timeline-detail">' + esc(ev.detail) + '</div>') : '')
                            + '</div>';
                        list.appendChild(li);
                    });
                }
                document.getElementById('invoiceTimelineModal').style.display = 'flex';
            })
            .catch(function() { showAlert('Error al cargar timeline', 'error'); });
    }
    function formatTimelineAt(v) {
        if (!v) return '—';
        var s = String(v).replace('T', ' ');
        if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
            var d = s.substr(0, 10).split('-');
            var t = s.length > 10 ? s.substr(11, 5) : '';
            return d[2] + '/' + d[1] + '/' + d[0] + (t ? (' ' + t) : '');
        }
        return s;
    }
    </script>

<?php elseif ($tab === 'compras'): ?>
    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header"><h3>Compras del periodo</h3></div>
        <div class="card-body">
            <p><strong><?php echo (int)$purchaseSummary['cnt']; ?></strong> compras · Total
                <strong><?php echo acctFmt((float)$purchaseSummary['total'], $currency); ?></strong></p>
            <p style="color:var(--color-text-secondary);">Las compras cargan inventario y generan asiento (Inventario / Proveedores o caja-banco).</p>
            <a class="btn btn-primary" href="<?php echo $viewInstance->route('app/compras'); ?>">Ir al módulo Compras</a>
        </div>
    </div>

<?php elseif ($tab === 'gastos'): ?>
    <div class="card neumorphic">
        <div class="card-header"><h3>Gastos tipificados del periodo</h3></div>
        <div class="card-body">
            <?php if (empty($expenseByType)): ?>
                <p style="color:var(--color-text-secondary);">Sin gastos en el periodo.</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Tipo</th><th>Cantidad</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($expenseByType as $row): ?>
                        <?php
                        $cat = (string)$row['category'];
                        $grp = \SoftNova\Core\expense_category_group($cat);
                        ?>
                        <tr>
                            <td>
                                <span class="badge badge-info" style="margin-right:6px;"><?php echo htmlspecialchars(\SoftNova\Core\expense_group_label($grp)); ?></span>
                                <?php echo htmlspecialchars(\SoftNova\Core\expense_category_label($cat)); ?>
                            </td>
                            <td><?php echo (int)$row['cnt']; ?></td>
                            <td><?php echo acctFmt((float)$row['total'], $currency); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <a class="btn btn-secondary" style="margin-top:12px;" href="<?php echo $viewInstance->route('app/gastos'); ?>">Gestionar gastos</a>
        </div>
    </div>

<?php elseif ($tab === 'trace'): ?>
    <div class="card neumorphic">
        <div class="card-header"><h3>Movimientos de inventario (periodo)</h3></div>
        <div class="card-body">
            <?php if (empty($traceMovements)): ?>
                <p style="color:var(--color-text-secondary);">Sin movimientos en el periodo.</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cant.</th><th>Origen</th></tr></thead>
                    <tbody>
                    <?php foreach ($traceMovements as $m): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($m['movement_date'] ?? $m['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($m['product_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($m['type']); ?></td>
                            <td><?php echo (int)$m['quantity']; ?></td>
                            <td><?php echo htmlspecialchars(($m['reference_type'] ?? '') . (!empty($m['reference_id']) ? ' #'.$m['reference_id'] : '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <a class="btn btn-secondary" style="margin-top:12px;" href="<?php echo $viewInstance->route('app/inventario'); ?>?action=traceability">Trazabilidad completa</a>
        </div>
    </div>

<?php elseif ($tab === 'vat'): ?>
    <?php
    $vb = is_array($vatBook) ? $vatBook : [];
    $docs = $vb['documents'] ?? [];
    $led = $vb['ledger'] ?? [];
    $acc = $vb['accounts'] ?? ['generated' => '240801', 'deductible' => '240802'];
    $fmtMoney = static function ($n): string {
        return '$' . number_format((float)$n, 2, ',', '.');
    };
    ?>
    <div class="card neumorphic" style="margin-bottom:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h3 style="margin:0;">Libro IVA</h3>
            <?php if ($canExport): ?>
            <a class="btn btn-sm btn-secondary"
               href="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=export&report=vat&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">
                Exportar CSV
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p style="color:var(--color-text-secondary);margin-top:0;">
                Cruza IVA de documentos (ventas/compras/NC) con saldos de las cuentas
                <?php echo htmlspecialchars((string)$acc['generated']); ?> (generado) y
                <?php echo htmlspecialchars((string)$acc['deductible']); ?> (descontable).
                Es una ayuda operativa; valide con su contador antes de declarar.
            </p>
            <?php if (!empty($vb['warnings'])): ?>
                <div class="alert alert-warning" style="margin-bottom:14px;">
                    <?php foreach ($vb['warnings'] as $w): ?>
                        <div><?php echo htmlspecialchars((string)$w); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="table-container" style="margin-bottom:18px;">
                <table>
                    <thead><tr><th>Concepto</th><th style="text-align:right;">Documentos</th><th style="text-align:right;">Libro contable</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>IVA generado (ventas − NC)</td>
                            <td style="text-align:right;"><?php echo $fmtMoney($docs['sales_net'] ?? 0); ?></td>
                            <td style="text-align:right;"><?php echo $fmtMoney($led['generated_net'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td>
                                IVA descontable (compras − NC + comisiones de pasarela)
                                <div style="font-size:12px;color:var(--color-text-secondary);">
                                    Compras <?php echo $fmtMoney($docs['purchases_net'] ?? 0); ?>
                                    · Comisiones datáfono / link <?php echo $fmtMoney($docs['gateway_commission_tax'] ?? 0); ?>
                                </div>
                            </td>
                            <td style="text-align:right;"><?php echo $fmtMoney($docs['deductible_net'] ?? ($docs['purchases_net'] ?? 0)); ?></td>
                            <td style="text-align:right;"><?php echo $fmtMoney($led['deductible_net'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Saldo a pagar / a favor</strong></td>
                            <td style="text-align:right;"><strong><?php echo $fmtMoney($docs['balance_to_pay'] ?? 0); ?></strong></td>
                            <td style="text-align:right;"><strong><?php echo $fmtMoney($led['balance'] ?? 0); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="table-container" style="margin-bottom:18px;">
                <table>
                    <thead><tr><th>Detalle documentos</th><th style="text-align:right;">Monto</th></tr></thead>
                    <tbody>
                        <tr><td>IVA ventas</td><td style="text-align:right;"><?php echo $fmtMoney($docs['sales_tax'] ?? 0); ?></td></tr>
                        <tr><td>IVA NC ventas</td><td style="text-align:right;"><?php echo $fmtMoney($docs['sales_credit_tax'] ?? 0); ?></td></tr>
                        <tr><td>IVA compras</td><td style="text-align:right;"><?php echo $fmtMoney($docs['purchases_tax'] ?? 0); ?></td></tr>
                        <tr><td>IVA NC compras</td><td style="text-align:right;"><?php echo $fmtMoney($docs['purchases_credit_tax'] ?? 0); ?></td></tr>
                        <tr>
                            <td>
                                IVA comisiones datáfono / link de pago
                                <?php if (isset($docs['gateway_vat_deductible']) && !$docs['gateway_vat_deductible']): ?>
                                    <div style="font-size:12px;color:var(--color-text-secondary);">No descontable según Comisiones: se lleva como mayor gasto.</div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;"><?php echo $fmtMoney($docs['gateway_commission_tax'] ?? 0); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <h4 style="margin:0 0 10px;">Ventas por tarifa (líneas)</h4>
            <?php if (empty($vb['by_rate'])): ?>
                <p style="color:var(--color-text-secondary);">Sin desglose por tarifa en el periodo (o columnas aún no migradas).</p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Tarifa %</th><th style="text-align:right;">Base</th><th style="text-align:right;">IVA</th></tr></thead>
                        <tbody>
                        <?php foreach ($vb['by_rate'] as $r): ?>
                            <tr>
                                <td><?php echo number_format((float)($r['rate'] ?? 0), 2, ',', '.'); ?>%</td>
                                <td style="text-align:right;"><?php echo $fmtMoney($r['base'] ?? 0); ?></td>
                                <td style="text-align:right;"><?php echo $fmtMoney($r['tax'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'integrations'): ?>
    <?php
    $providerMeta = [
        'alegra' => 'Emite factura electrónica DIAN vía Alegra (proveedor autorizado).',
        'siigo' => 'Emite documentos electrónicos vía Siigo Nube API.',
        'factus' => 'API especializada en facturación electrónica Colombia.',
        'dian' => 'Datos fiscales para emisión directa. Requiere ser Proveedor Tecnológico o factura gratuita DIAN.',
    ];
    $st = $integrationStatuses[$selectedProvider] ?? [];
    $form = $st['form'] ?? [];
    $routeBase = $viewInstance->route('app/contabilidad');
    $saveAction = 'save-integration';
    $testAction = 'integration-test';
    ?>
    <div class="alert alert-info" style="margin-bottom:16px;">
        Configure aquí la <strong>facturación electrónica</strong> (Alegra / Siigo / Factus / DIAN).
        WooCommerce y Mercado Libre se gestionan en
        <a href="<?php echo $viewInstance->route('app/inventario'); ?>?integrations=1"><strong>Inventario → Integraciones</strong></a>.
        Las credenciales se guardan cifradas por empresa.
    </div>

    <div class="card neumorphic" style="margin-bottom:18px;">
        <div class="card-header"><h3>Proveedor activo (facturación electrónica)</h3></div>
        <div class="card-body">
            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=set-active-provider" data-ajax="true" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <div class="form-group" style="margin:0;min-width:220px;">
                    <label>Usar para facturación electrónica</label>
                    <select class="form-control" name="provider">
                        <option value="">Ninguno</option>
                        <?php foreach ($integrationStatuses as $code => $stOpt): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $activeProvider === $code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($stOpt['label'] ?? $code); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">Guardar proveedor activo</button>
            </form>
            <?php else: ?>
                <p style="margin:0;">Activo: <strong><?php echo htmlspecialchars($activeProvider ?: 'Ninguno'); ?></strong></p>
            <?php endif; ?>
        </div>
    </div>

    <h4 style="margin:8px 0 10px;">Facturación electrónica</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
        <?php foreach ($integrationStatuses as $code => $stOpt): ?>
            <a class="btn <?php echo $selectedProvider === $code ? 'btn-primary' : 'btn-secondary'; ?>"
               href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=integrations&provider=<?php echo urlencode($code); ?>">
                <?php echo htmlspecialchars($stOpt['label'] ?? $code); ?>
                <?php if (!empty($stOpt['active'])): ?><span class="badge badge-success" style="margin-left:6px;">Activo</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/inventario'); ?>?integrations=1">Integraciones e-commerce (Inventario)</a>
    </div>

    <div class="card neumorphic">
        <div class="card-header">
            <h3><?php echo htmlspecialchars($st['label'] ?? $selectedProvider); ?></h3>
        </div>
        <div class="card-body">
            <p style="margin-top:0;color:var(--color-text-secondary);">
                <?php echo htmlspecialchars($providerMeta[$selectedProvider] ?? ''); ?>
            </p>
            <p>
                Estado:
                <?php if (!empty($st['configured'])): ?>
                    <span class="badge badge-success">Listo</span>
                <?php elseif (!empty($st['enabled'])): ?>
                    <span class="badge badge-warning">Habilitado, faltan credenciales</span>
                <?php else: ?>
                    <span class="badge badge-danger">Deshabilitado</span>
                <?php endif; ?>
            </p>

            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $routeBase; ?>?action=<?php echo $saveAction; ?>" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="provider" value="<?php echo htmlspecialchars($selectedProvider); ?>">

                <div class="form-grid" style="margin-bottom:12px;">
                    <div class="form-group">
                        <label><input type="checkbox" name="enabled" value="1" <?php echo !empty($form['enabled']) && $form['enabled'] !== '0' ? 'checked' : ''; ?>> Habilitar conector</label>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="make_active" value="1" <?php echo $activeProvider === $selectedProvider ? 'checked' : ''; ?>> Marcar como proveedor activo</label>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="sync_sales" value="1" <?php echo !empty($form['sync_sales']) && $form['sync_sales'] !== '0' ? 'checked' : ''; ?>> Sincronizar ventas</label>
                    </div>
                </div>

                <?php if ($selectedProvider === 'alegra'): ?>
                    <div class="form-grid">
                        <div class="form-group"><label>Email Alegra</label><input class="form-control" name="email" value="<?php echo htmlspecialchars($form['email'] ?? ''); ?>" autocomplete="off"></div>
                        <div class="form-group"><label>Token API <?php if (!empty($form['token_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="token" placeholder="<?php echo !empty($form['token_set']) ? 'Dejar vacío para conservar' : 'Token'; ?>" autocomplete="new-password"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Base URL</label><input class="form-control" name="base_url" value="<?php echo htmlspecialchars($form['base_url'] ?? 'https://api.alegra.com/api/v1'); ?>"></div>
                        <div class="form-group"><label>ID impuesto IVA (Alegra)</label><input class="form-control" name="tax_id" value="<?php echo htmlspecialchars($form['tax_id'] ?? ''); ?>" placeholder="Ej: 1 (ID del IVA en Alegra)"></div>
                        <div class="form-group"><label><input type="checkbox" name="stamp" value="1" <?php echo !empty($form['stamp']) && $form['stamp'] !== '0' ? 'checked' : ''; ?>> Generar factura electrónica (timbrar DIAN)</label></div>
                    </div>
                    <div class="form-grid" style="margin-top:8px;">
                        <div class="form-group"><label><input type="checkbox" name="sync_payments" value="1" <?php echo !empty($form['sync_payments']) && $form['sync_payments'] !== '0' ? 'checked' : ''; ?>> Sync abonos</label></div>
                        <div class="form-group"><label><input type="checkbox" name="sync_expenses" value="1" <?php echo !empty($form['sync_expenses']) && $form['sync_expenses'] !== '0' ? 'checked' : ''; ?>> Sync gastos</label></div>
                    </div>
                    <p style="font-size:12px;color:var(--color-text-secondary);">El ID del impuesto se obtiene en Alegra → Impuestos (o GET /taxes). Con sync ventas activo, cada venta nueva hace POST /invoices.</p>

                <?php elseif ($selectedProvider === 'siigo'): ?>
                    <div class="form-grid">
                        <div class="form-group"><label>Usuario API</label><input class="form-control" name="username" value="<?php echo htmlspecialchars($form['username'] ?? ''); ?>" autocomplete="off"></div>
                        <div class="form-group"><label>Access Key <?php if (!empty($form['access_key_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="access_key" placeholder="<?php echo !empty($form['access_key_set']) ? 'Dejar vacío para conservar' : 'Access key'; ?>" autocomplete="new-password"></div>
                        <div class="form-group"><label>Partner-Id</label><input class="form-control" name="partner_id" value="<?php echo htmlspecialchars($form['partner_id'] ?? 'SeriERP'); ?>"></div>
                        <div class="form-group"><label>Base URL</label><input class="form-control" name="base_url" value="<?php echo htmlspecialchars($form['base_url'] ?? 'https://api.siigo.com'); ?>"></div>
                        <div class="form-group"><label>ID tipo documento (FV)</label><input class="form-control" name="document_id" value="<?php echo htmlspecialchars($form['document_id'] ?? ''); ?>" placeholder="GET /v1/document-types"></div>
                        <div class="form-group"><label>ID vendedor</label><input class="form-control" name="seller_id" value="<?php echo htmlspecialchars($form['seller_id'] ?? ''); ?>" placeholder="GET /v1/users"></div>
                        <div class="form-group"><label>ID medio de pago</label><input class="form-control" name="payment_type_id" value="<?php echo htmlspecialchars($form['payment_type_id'] ?? ''); ?>" placeholder="GET /v1/payment-types"></div>
                        <div class="form-group"><label>ID impuesto IVA</label><input class="form-control" name="tax_id" value="<?php echo htmlspecialchars($form['tax_id'] ?? ''); ?>" placeholder="Opcional (GET /v1/taxes)"></div>
                        <div class="form-group"><label>ID grupo contable</label><input class="form-control" name="account_group_id" value="<?php echo htmlspecialchars($form['account_group_id'] ?? ''); ?>" placeholder="Opcional (para crear productos)"></div>
                        <div class="form-group"><label><input type="checkbox" name="stamp" value="1" <?php echo !empty($form['stamp']) && $form['stamp'] !== '0' ? 'checked' : ''; ?>> Timbrar factura electrónica (DIAN)</label></div>
                    </div>
                    <p style="font-size:12px;color:var(--color-text-secondary);">Los IDs se consultan una sola vez desde Siigo Nube. Con sync ventas activo, cada venta hace POST /v1/invoices (resuelve/crea cliente y productos).</p>

                <?php elseif ($selectedProvider === 'factus'): ?>
                    <div class="form-grid">
                        <div class="form-group"><label>Client ID</label><input class="form-control" name="client_id" value="<?php echo htmlspecialchars($form['client_id'] ?? ''); ?>" autocomplete="off"></div>
                        <div class="form-group"><label>Client Secret <?php if (!empty($form['client_secret_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="client_secret" placeholder="<?php echo !empty($form['client_secret_set']) ? 'Dejar vacío para conservar' : 'Secret'; ?>" autocomplete="new-password"></div>
                        <div class="form-group"><label>Usuario (email)</label><input class="form-control" name="username" value="<?php echo htmlspecialchars($form['username'] ?? ''); ?>" autocomplete="off"></div>
                        <div class="form-group"><label>Password <?php if (!empty($form['password_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="password" placeholder="<?php echo !empty($form['password_set']) ? 'Dejar vacío para conservar' : 'Password'; ?>" autocomplete="new-password"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Base URL</label><input class="form-control" name="base_url" value="<?php echo htmlspecialchars($form['base_url'] ?? 'https://api-sandbox.factus.com.co'); ?>"></div>
                        <div class="form-group"><label>ID rango numeración</label><input class="form-control" name="numbering_range_id" value="<?php echo htmlspecialchars($form['numbering_range_id'] ?? ''); ?>" placeholder="Opcional si solo hay uno activo"></div>
                        <div class="form-group"><label>% IVA por defecto</label><input class="form-control" name="tax_rate" value="<?php echo htmlspecialchars($form['tax_rate'] ?? '19'); ?>" placeholder="19"></div>
                        <div class="form-group"><label>ID municipio</label><input class="form-control" name="municipality_id" value="<?php echo htmlspecialchars($form['municipality_id'] ?? ''); ?>" placeholder="Opcional (tabla municipios)"></div>
                    </div>
                    <p style="font-size:12px;color:var(--color-text-secondary);">Factus valida contra DIAN. El cliente y los productos se envían en la misma factura (POST /v1/bills/validate), no requiere crearlos antes.</p>

                <?php else: /* dian */ ?>
                    <div class="form-grid">
                        <div class="form-group"><label>NIT</label><input class="form-control" name="nit" value="<?php echo htmlspecialchars($form['nit'] ?? ''); ?>"></div>
                        <div class="form-group"><label>DV</label><input class="form-control" name="dv" value="<?php echo htmlspecialchars($form['dv'] ?? ''); ?>" maxlength="1"></div>
                        <div class="form-group" style="grid-column:1/-1;"><label>Razón social</label><input class="form-control" name="legal_name" value="<?php echo htmlspecialchars($form['legal_name'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Resolución</label><input class="form-control" name="resolution_number" value="<?php echo htmlspecialchars($form['resolution_number'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Prefijo</label><input class="form-control" name="prefix" value="<?php echo htmlspecialchars($form['prefix'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Rango desde</label><input class="form-control" name="range_from" value="<?php echo htmlspecialchars($form['range_from'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Rango hasta</label><input class="form-control" name="range_to" value="<?php echo htmlspecialchars($form['range_to'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Software ID</label><input class="form-control" name="software_id" value="<?php echo htmlspecialchars($form['software_id'] ?? ''); ?>"></div>
                        <div class="form-group"><label>PIN <?php if (!empty($form['pin_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="pin" placeholder="<?php echo !empty($form['pin_set']) ? 'Dejar vacío para conservar' : 'PIN'; ?>" autocomplete="new-password"></div>
                        <div class="form-group"><label>Clave técnica <?php if (!empty($form['technical_key_set'])): ?><small>(guardado)</small><?php endif; ?></label><input class="form-control" type="password" name="technical_key" placeholder="<?php echo !empty($form['technical_key_set']) ? 'Dejar vacío para conservar' : 'Clave técnica'; ?>" autocomplete="new-password"></div>
                        <div class="form-group"><label>Ambiente</label>
                            <select class="form-control" name="environment">
                                <option value="habilitacion" <?php echo ($form['environment'] ?? '') === 'habilitacion' ? 'selected' : ''; ?>>Habilitación / pruebas</option>
                                <option value="produccion" <?php echo ($form['environment'] ?? '') === 'produccion' ? 'selected' : ''; ?>>Producción</option>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-warning" style="margin-top:12px;">
                        La DIAN no acepta envíos directos de cualquier software. Sin ser Proveedor Tecnológico autorizado,
                        use Alegra, Siigo o Factus. Este panel guarda los datos fiscales para una fase futura.
                    </div>
                <?php endif; ?>

                <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Guardar configuración</button>
                    <button type="button" class="btn btn-secondary" onclick="testIntegrationProvider(this, '<?php echo htmlspecialchars($selectedProvider); ?>')">Probar conexión</button>
                    <span id="integrationTestResult" style="font-size:13px;"></span>
                </div>
            </form>
            <?php else: ?>
                <p class="dashboard-empty">No tiene permiso para editar integraciones.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div id="manualEntryModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:950px;">
        <div class="modal-header"><h3>Nuevo comprobante manual</h3><button type="button" class="modal-close" onclick="closeModal('manualEntryModal')">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=manual-entry" data-ajax="true" onsubmit="return validateAccountingEntry()">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group"><label>Fecha *</label><input class="form-control" type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group"><label>Concepto *</label><input class="form-control" name="description" maxlength="255" required></div>
                </div>
                <div class="table-container"><table id="manualEntryLines">
                    <thead><tr><th>Cuenta</th><th>Detalle</th><th>Debe</th><th>Haber</th><th></th></tr></thead>
                    <tbody></tbody>
                    <tfoot><tr><th colspan="2">Totales</th><th id="manualDebitTotal">0</th><th id="manualCreditTotal">0</th><th></th></tr></tfoot>
                </table></div>
                <button type="button" class="btn btn-secondary" onclick="addAccountingLine()">Agregar línea</button>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('manualEntryModal')">Cancelar</button><button type="submit" class="btn btn-primary">Contabilizar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div id="accountModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header"><h3 id="accountModalTitle">Cuenta contable</h3><button type="button" class="modal-close" onclick="closeModal('accountModal')">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=save-account" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" id="accountId">
            <div class="modal-body">
            <p id="accountSystemHint" style="display:none;font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">
                Cuenta de sistema: la usan ventas, compras, gastos y caja. Solo se puede cambiar el nombre.
            </p>
            <div class="form-grid">
                <div class="form-group"><label>Código *</label><input class="form-control" name="code" id="accountCode" maxlength="30" required></div>
                <div class="form-group"><label>Nombre *</label><input class="form-control" name="name" id="accountName" maxlength="180" required></div>
                <div class="form-group"><label>Tipo *</label><select class="form-control" name="account_type" id="accountType" required><?php foreach ($types as $value => $label): ?><option value="<?php echo $value; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Naturaleza *</label><select class="form-control" name="nature" id="accountNature"><option value="debit">Débito</option><option value="credit">Crédito</option></select></div>
                <div class="form-group"><label>Estado</label><select class="form-control" name="status" id="accountStatus"><option value="active">Activa</option><option value="inactive">Inactiva</option></select></div>
                <div class="form-group"><label><input type="checkbox" name="accepts_entries" id="accountAccepts" value="1" checked> Recibe movimientos</label></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('accountModal')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="entryDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:900px;">
        <div class="modal-header"><h3 id="entryDetailTitle">Comprobante</h3><button type="button" class="modal-close" onclick="closeModal('entryDetailModal')">&times;</button></div>
        <div class="modal-body">
            <div id="entryDetailBody"><p class="dashboard-empty">Cargando...</p></div>
            <?php if ($canDelete): ?>
            <div id="entryVoidBox" class="entry-void-box" style="display:none;">
                <form method="POST" action="<?php echo $viewInstance->route('app/contabilidad'); ?>?action=void-entry" data-ajax="true">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <input type="hidden" name="entry_id" id="voidEntryId" value="">
                    <div class="form-group">
                        <label for="voidEntryReason"><strong>Anular comprobante manual</strong> · motivo *</label>
                        <input class="form-control" type="text" name="reason" id="voidEntryReason" minlength="5" maxlength="200" required placeholder="Ej.: valor digitado errado, se registra de nuevo">
                    </div>
                    <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">
                        Se genera un comprobante de reversión con los mismos valores invertidos. El original queda como «Anulado»; ninguno se borra.
                    </p>
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Anular este comprobante? Se registrará el asiento de reversión.">Anular comprobante</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.accounting-tabs{display:flex;gap:7px;flex-wrap:wrap}
.accounting-statements{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.accounting-statement-row,.accounting-statement-total{display:flex;justify-content:space-between;gap:15px;padding:7px 0;border-bottom:1px solid var(--color-border)}
.accounting-statement-total{font-weight:700;border-top:2px solid var(--color-border);border-bottom:0}
@media(max-width:900px){.accounting-statements{grid-template-columns:1fr}}
.trial-switch{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.trial-checks{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.trial-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.trial-table th.trial-group{text-align:center;border-bottom:1px solid var(--color-border)}
.trial-table .trial-total td{font-weight:700;border-top:2px solid var(--color-border);background:var(--dmb-F1F5F9, #F1F5F9)}
.ledger-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:12px}
.ledger-summary div{padding:10px 12px;border:1px solid var(--color-border);border-radius:8px;background:var(--dmb-F8FAFC, #F8FAFC);display:flex;flex-direction:column;gap:4px}
.ledger-summary span{font-size:12px;color:var(--color-text-secondary)}
.ledger-summary strong{font-size:15px;font-variant-numeric:tabular-nums}
.ledger-note{margin-top:12px;padding:10px 12px;border:1px solid #E2E8F0;border-radius:8px;background:var(--dmb-F8FAFC, #F8FAFC);font-size:12px;color:var(--color-text-secondary);line-height:1.5}
.ledger-note ul{margin:6px 0 0;padding-left:18px}
.ledger-note strong{color:var(--color-text)}
.entry-void-box{margin-top:16px;padding:12px 14px;border:1px solid #FECACA;border-radius:8px;background:var(--dmb-FEF2F2, #FEF2F2)}
.entry-void-box .form-group{margin:0 0 8px}
</style>

<script>
var accountingAccountOptions = <?php echo json_encode($accountOptions); ?>;
var accountingCurrency = <?php echo json_encode($currency); ?>;
var gastosReceiptBase = <?php echo json_encode($viewInstance->route('app/gastos')); ?>;

function openModal(id) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (el) el.style.display = 'flex';
}

function closeModal(id) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (el) el.style.display = 'none';
}

function accountingMoney(value) {
    return (accountingCurrency.symbol || '$') + ' ' + Number(value || 0).toLocaleString('es-CO', {
        minimumFractionDigits: accountingCurrency.decimals || 2,
        maximumFractionDigits: accountingCurrency.decimals || 2
    });
}

function addAccountingLine() {
    var tbody = document.querySelector('#manualEntryLines tbody');
    if (!tbody) return;
    var row = document.createElement('tr');
    row.innerHTML = '<td><select name="account_code[]" class="form-control" required><option value="">Seleccione</option>' + accountingAccountOptions + '</select></td>' +
        '<td><input name="line_description[]" class="form-control" maxlength="255"></td>' +
        '<td><input name="debit[]" type="number" min="0" step="0.01" class="form-control accounting-debit" value="0" oninput="updateAccountingTotals()"></td>' +
        '<td><input name="credit[]" type="number" min="0" step="0.01" class="form-control accounting-credit" value="0" oninput="updateAccountingTotals()"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove();updateAccountingTotals()">X</button></td>';
    tbody.appendChild(row);
}

function updateAccountingTotals() {
    var debit = 0, credit = 0;
    document.querySelectorAll('.accounting-debit').forEach(function(el){ debit += Number(el.value || 0); });
    document.querySelectorAll('.accounting-credit').forEach(function(el){ credit += Number(el.value || 0); });
    document.getElementById('manualDebitTotal').textContent = accountingMoney(debit);
    document.getElementById('manualCreditTotal').textContent = accountingMoney(credit);
}

function validateAccountingEntry() {
    var debit = 0, credit = 0;
    document.querySelectorAll('.accounting-debit').forEach(function(el){ debit += Number(el.value || 0); });
    document.querySelectorAll('.accounting-credit').forEach(function(el){ credit += Number(el.value || 0); });
    if (debit <= 0 || Math.abs(debit - credit) > 0.009) {
        showAlert('El comprobante debe tener Debe = Haber y valores mayores a cero', 'error');
        return false;
    }
    return true;
}

function newAccountingAccount() {
    document.getElementById('accountModalTitle').textContent = 'Nueva cuenta';
    document.getElementById('accountId').value = '';
    document.getElementById('accountCode').value = '';
    document.getElementById('accountName').value = '';
    document.getElementById('accountType').value = 'asset';
    document.getElementById('accountNature').value = 'debit';
    document.getElementById('accountStatus').value = 'active';
    document.getElementById('accountAccepts').checked = true;
    setAccountSystemLock(false);
    openModal('accountModal');
}

function editAccountingAccount(account) {
    document.getElementById('accountModalTitle').textContent = 'Editar cuenta';
    document.getElementById('accountId').value = account.id;
    document.getElementById('accountCode').value = account.code;
    document.getElementById('accountName').value = account.name;
    document.getElementById('accountType').value = account.account_type;
    document.getElementById('accountNature').value = account.nature;
    document.getElementById('accountStatus').value = account.status;
    document.getElementById('accountAccepts').checked = Number(account.accepts_entries) === 1;
    setAccountSystemLock(Number(account.is_system) === 1);
    openModal('accountModal');
}

function setAccountSystemLock(locked) {
    document.getElementById('accountCode').readOnly = locked;
    ['accountType', 'accountNature', 'accountStatus'].forEach(function(id){
        var el = document.getElementById(id);
        el.style.pointerEvents = locked ? 'none' : '';
        el.style.opacity = locked ? '0.65' : '';
        el.tabIndex = locked ? -1 : 0;
    });
    var accepts = document.getElementById('accountAccepts');
    accepts.style.pointerEvents = locked ? 'none' : '';
    var hint = document.getElementById('accountSystemHint');
    if (hint) hint.style.display = locked ? 'block' : 'none';
}

function renderExpenseSourceDocument(doc) {
    if (!doc || doc.type !== 'expense') return '';
    var html = '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-bg-secondary,#f8fafc);">';
    html += '<h4 style="margin:0 0 10px;font-size:15px;">Documento de gasto</h4>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:13px;margin-bottom:10px;">';
    html += '<div><span style="color:var(--color-text-secondary);">Concepto</span><br><strong>' + escapeAccounting(doc.description) + '</strong></div>';
    html += '<div><span style="color:var(--color-text-secondary);">Monto</span><br><strong>' + accountingMoney(doc.amount) + '</strong></div>';
    html += '<div><span style="color:var(--color-text-secondary);">Categoría</span><br>' + escapeAccounting(doc.category || '—') + '</div>';
    html += '<div><span style="color:var(--color-text-secondary);">Medio de pago</span><br>' + escapeAccounting(doc.payment_label || doc.payment_method || '—') + '</div>';
    if (doc.supplier_name) {
        html += '<div><span style="color:var(--color-text-secondary);">Proveedor</span><br>' + escapeAccounting(doc.supplier_name) + '</div>';
    }
    if (doc.receipt_number) {
        html += '<div><span style="color:var(--color-text-secondary);">N° comprobante</span><br>' + escapeAccounting(doc.receipt_number) + '</div>';
    }
    html += '</div>';
    if (doc.cash_explanation) {
        html += '<p style="margin:0 0 8px;padding:8px 10px;border-left:3px solid #F59E0B;background:rgba(245,158,11,.08);font-size:13px;">';
        html += '<strong>Explicación del efectivo:</strong> ' + escapeAccounting(doc.cash_explanation) + '</p>';
    } else if (doc.notes) {
        html += '<p style="margin:0 0 8px;font-size:13px;color:var(--color-text-secondary);"><strong>Notas:</strong> ' + escapeAccounting(doc.notes) + '</p>';
    }
    if (doc.has_receipt) {
        var receiptUrl = gastosReceiptBase + '?action=receipt&id=' + encodeURIComponent(doc.id);
        var mime = String(doc.receipt_mime || '');
        if (mime.indexOf('image/') === 0) {
            html += '<div style="margin-top:8px;"><span style="font-size:12px;color:var(--color-text-secondary);">Comprobante adjunto</span><br>';
            html += '<a href="' + receiptUrl + '" target="_blank" rel="noopener"><img src="' + receiptUrl + '" alt="Comprobante" style="max-width:100%;max-height:260px;margin-top:6px;border-radius:6px;border:1px solid var(--color-border);"></a></div>';
        } else {
            html += '<p style="margin:8px 0 0;"><a class="btn btn-sm btn-secondary" href="' + receiptUrl + '" target="_blank" rel="noopener">Ver comprobante adjunto (PDF/archivo)</a></p>';
        }
    }
    html += '</div>';
    return html;
}

function renderSaleSourceDocument(doc) {
    if (!doc || doc.type !== 'sale') return '';
    var html = '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-bg-secondary,#f8fafc);">';
    html += '<h4 style="margin:0 0 10px;font-size:15px;">Documento de venta</h4>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:13px;">';
    html += '<div><span style="color:var(--color-text-secondary);">Factura</span><br><strong>' + escapeAccounting(doc.invoice_number || ('#' + doc.id)) + '</strong></div>';
    html += '<div><span style="color:var(--color-text-secondary);">Total</span><br><strong>' + accountingMoney(doc.total) + '</strong></div>';
    if (doc.customer_name) {
        html += '<div><span style="color:var(--color-text-secondary);">Cliente</span><br>' + escapeAccounting(doc.customer_name) + '</div>';
    }
    html += '<div><span style="color:var(--color-text-secondary);">Medio de pago</span><br>' + escapeAccounting(doc.payment_label || doc.payment_method || '—') + '</div>';
    html += '<div><span style="color:var(--color-text-secondary);">Estado pago</span><br>' + escapeAccounting(doc.payment_status || '—') + '</div>';
    html += '</div></div>';
    return html;
}

function renderSalePaymentSourceDocument(doc) {
    if (!doc || doc.type !== 'sale_payment') return '';
    var html = '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-bg-secondary,#f8fafc);">';
    html += '<h4 style="margin:0 0 10px;font-size:15px;">Abono de venta</h4>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:13px;">';
    html += '<div><span style="color:var(--color-text-secondary);">Factura</span><br><strong>' + escapeAccounting(doc.invoice_number || '—') + '</strong></div>';
    html += '<div><span style="color:var(--color-text-secondary);">Monto abono</span><br><strong>' + accountingMoney(doc.amount) + '</strong></div>';
    html += '<div><span style="color:var(--color-text-secondary);">Medio</span><br>' + escapeAccounting(doc.payment_label || doc.payment_method || '—') + '</div>';
    if (doc.notes) {
        html += '<div><span style="color:var(--color-text-secondary);">Notas</span><br>' + escapeAccounting(doc.notes) + '</div>';
    }
    html += '</div></div>';
    return html;
}

function renderSaleCreditNoteSourceDocument(doc) {
    if (!doc || doc.type !== 'sale_credit_note') return '';
    var html = '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-bg-secondary,#f8fafc);">';
    html += '<h4 style="margin:0 0 10px;font-size:15px;">Nota de crédito de venta</h4>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:13px;">';
    html += '<div><span style="color:var(--color-text-secondary);">NC</span><br><strong>' + escapeAccounting(doc.credit_number || ('#' + doc.id)) + '</strong></div>';
    html += '<div><span style="color:var(--color-text-secondary);">Total</span><br><strong>' + accountingMoney(doc.total) + '</strong></div>';
    if (doc.invoice_number) {
        html += '<div><span style="color:var(--color-text-secondary);">Venta</span><br>' + escapeAccounting(doc.invoice_number) + '</div>';
    }
    if (doc.customer_name) {
        html += '<div><span style="color:var(--color-text-secondary);">Cliente</span><br>' + escapeAccounting(doc.customer_name) + '</div>';
    }
    html += '<div><span style="color:var(--color-text-secondary);">Stock</span><br>' + (doc.affects_stock ? 'Reingreso' : 'Sin reingreso') + '</div>';
    if (doc.reason) {
        html += '<div><span style="color:var(--color-text-secondary);">Motivo</span><br>' + escapeAccounting(doc.reason) + '</div>';
    }
    html += '</div></div>';
    return html;
}

function renderCashMovementSourceDocument(doc) {
    if (!doc || doc.type !== 'cash_movement') return '';
    var html = '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid var(--color-border);border-radius:8px;background:var(--color-bg-secondary,#f8fafc);">';
    html += '<h4 style="margin:0 0 10px;font-size:15px;">Movimiento de caja</h4>';
    html += '<p style="margin:0;font-size:13px;"><strong>' + escapeAccounting(doc.description) + '</strong> · ' + accountingMoney(doc.amount) + '</p>';
    if (doc.user_name) {
        html += '<p style="margin:6px 0 0;font-size:12px;color:var(--color-text-secondary);">Usuario: ' + escapeAccounting(doc.user_name) + '</p>';
    }
    html += '</div>';
    return html;
}

function renderSourceDocument(doc) {
    if (!doc || !doc.type) return '';
    if (doc.type === 'expense') return renderExpenseSourceDocument(doc);
    if (doc.type === 'sale') return renderSaleSourceDocument(doc);
    if (doc.type === 'sale_payment') return renderSalePaymentSourceDocument(doc);
    if (doc.type === 'sale_credit_note') return renderSaleCreditNoteSourceDocument(doc);
    if (doc.type === 'cash_movement') return renderCashMovementSourceDocument(doc);
    return '';
}

function showAccountingEntry(id) {
    var url = <?php echo json_encode($viewInstance->route('app/contabilidad')); ?> + '?action=entry&id=' + encodeURIComponent(id);
    document.getElementById('entryDetailBody').innerHTML = '<p class="dashboard-empty">Cargando...</p>';
    var voidBox = document.getElementById('entryVoidBox');
    if (voidBox) voidBox.style.display = 'none';
    openModal('entryDetailModal');
    fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(response){ return response.json(); })
        .then(function(data){
            if (!data.success) throw new Error(data.message || 'No se pudo cargar');
            var entry = data.entry;
            document.getElementById('entryDetailTitle').textContent = entry.entry_number + ' · ' + entry.description;
            var statusLabels = {posted: 'Contabilizado', reversed: 'Anulado', draft: 'Borrador'};
            var html = '<p style="margin:0 0 10px;"><strong>Fecha:</strong> ' + escapeAccounting(entry.entry_date) + ' &nbsp; <strong>Estado:</strong> ' + escapeAccounting(statusLabels[entry.status] || entry.status) + (entry.is_manual ? ' &nbsp; <span class="badge badge-info">Manual</span>' : '') + '</p>';
            if (entry.reversed_by) {
                html += '<div class="alert alert-warning" style="margin:0 0 10px;padding:8px 12px;">Anulado por <a href="#" onclick="showAccountingEntry(' + Number(entry.reversed_by.id) + ');return false;">' + escapeAccounting(entry.reversed_by.entry_number) + '</a> (' + escapeAccounting(entry.reversed_by.entry_date) + ').</div>';
            }
            if (entry.reversal_of) {
                html += '<div class="alert alert-info" style="margin:0 0 10px;padding:8px 12px;">Reversión del comprobante <a href="#" onclick="showAccountingEntry(' + Number(entry.reversal_of.id) + ');return false;">' + escapeAccounting(entry.reversal_of.entry_number) + '</a>.</div>';
            }
            if (voidBox) {
                document.getElementById('voidEntryId').value = entry.can_void ? entry.id : '';
                document.getElementById('voidEntryReason').value = '';
                voidBox.style.display = entry.can_void ? 'block' : 'none';
            }
            if (entry.source_document) {
                html += renderSourceDocument(entry.source_document);
            }
            html += '<p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">';
            html += '<strong>Partida doble (PUC):</strong> cada fila es un movimiento contable. ';
            html += '<strong>Debe (cargo)</strong> registra el gasto; <strong>Haber (abono)</strong> indica de dónde salió el dinero ';
            html += '(<em>Banco</em> = retiro/transferencia; <em>Caja general</em> = cajón físico del POS). ';
            html += 'La suma Debe y Haber debe ser igual.</p>';
            html += '<div class="table-container"><table><thead><tr><th>Cuenta</th><th>Detalle</th><th>Tercero</th><th>Debe (cargo)</th><th>Haber (abono)</th></tr></thead><tbody>';
            entry.lines.forEach(function(line){
                html += '<tr><td>' + escapeAccounting(line.account_code + ' - ' + line.account_name) + '</td><td>' + escapeAccounting(line.description || '') + '</td><td>' + escapeAccounting(line.third_party_name || '-') + '</td><td>' + accountingMoney(line.debit) + '</td><td>' + accountingMoney(line.credit) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            document.getElementById('entryDetailBody').innerHTML = html;
        })
        .catch(function(error){
            document.getElementById('entryDetailBody').innerHTML = '<p class="dashboard-empty" style="color:var(--dmc-DC2626, #DC2626);">' + escapeAccounting(error.message) + '</p>';
        });
}

function escapeAccounting(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function testIntegrationProvider(btn, provider) {
    var result = document.getElementById('integrationTestResult');
    btn.disabled = true;
    result.textContent = 'Probando...';
    result.style.color = 'var(--color-text-secondary)';
    var url = <?php echo json_encode($viewInstance->route('app/contabilidad')); ?>
        + '?action=' + encodeURIComponent(<?php echo json_encode($testAction ?? 'integration-test'); ?>)
        + '&provider=' + encodeURIComponent(provider);
    fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(response){ return response.json(); })
        .then(function(data){
            result.textContent = data.message || (data.success ? 'Conexión OK' : 'Error');
            result.style.color = data.success ? '#10B981' : 'var(--dmc-DC2626, #DC2626)';
        })
        .catch(function(){
            result.textContent = 'No se pudo probar la conexión';
            result.style.color = 'var(--dmc-DC2626, #DC2626)';
        })
        .finally(function(){ btn.disabled = false; });
}

document.addEventListener('DOMContentLoaded', function(){
    var tbody = document.querySelector('#manualEntryLines tbody');
    if (tbody && !tbody.children.length) {
        addAccountingLine();
        addAccountingLine();
    }
    var params = new URLSearchParams(window.location.search);
    var entryId = parseInt(params.get('entry_id') || params.get('entry') || '0', 10);
    if (entryId > 0 && typeof showAccountingEntry === 'function') {
        showAccountingEntry(entryId);
    }
});
</script>