<?php
$layout = 'tenant';
$title = 'Bancos - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Bancos';
$tab = $tab ?? 'cuentas';
$accounts = $accounts ?? [];
$kinds = $kinds ?? [];
$postingKinds = $postingKinds ?? [];
$lineStatus = $lineStatus ?? [];
$methodOptions = $methodOptions ?? [];
$collectionMap = $collectionMap ?? [];
$collectionSince = $collectionSince ?? '';
$editAccount = $editAccount ?? null;
$suggestedCodes = $suggestedCodes ?? [];
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$base = $viewInstance->route('app/bancos');
$entryBase = $viewInstance->route('app/contabilidad') . '?tab=entries&entry_id=';
$saleBase = $viewInstance->route('app/ventas');
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'contabilidad');
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'contabilidad');
$canDelete = \SoftNova\Core\TenantMiddleware::canDo('delete', 'contabilidad');

if (!function_exists('bkMoney')) {
    function bkMoney(float $a, array $c): string
    {
        return ($a < 0 ? '− ' : '') . $c['symbol'] . ' ' . number_format(abs($a), $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
    }
}
if (!function_exists('bkDate')) {
    function bkDate(?string $d): string
    {
        $ts = $d ? strtotime($d) : false;
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}
if (!function_exists('bkTip')) {
    function bkTip(string $text): string
    {
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return '<span class="bk-tip" tabindex="0" title="' . $t . '" aria-label="' . $t . '">i</span>';
    }
}
$h = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$entryLink = static function ($entryId, $number) use ($entryBase, $h): string {
    if (empty($entryId)) {
        return '—';
    }
    return '<a href="' . $entryBase . (int)$entryId . '" target="_blank" rel="noopener">#' . $h($number ?: $entryId) . '</a>';
};

$bankTotal = 0.0;
$bridgeTotal = 0.0;
$activeCount = 0;
$accountsById = [];
foreach ($accounts as $a) {
    $accountsById[(int)$a['id']] = $a;
    if ((int)$a['is_active'] !== 1) {
        continue;
    }
    $activeCount++;
    if ($a['kind'] === 'gateway') {
        $bridgeTotal += (float)$a['balance'];
    } else {
        $bankTotal += (float)$a['balance'];
    }
}
$activeAccounts = array_values(array_filter($accounts, static fn ($a) => (int)$a['is_active'] === 1));
$bankOnly = array_values(array_filter($activeAccounts, static fn ($a) => $a['kind'] !== 'gateway'));
$hasGateway = (bool)array_filter($activeAccounts, static fn ($a) => $a['kind'] === 'gateway');
?>
<?php echo flashMessage(); ?>

<style>
.bk-tabs{display:flex;gap:4px;flex-wrap:wrap;padding:4px;margin-bottom:18px;border-radius:12px;background:var(--color-secondary);border:1px solid var(--color-border);width:fit-content;max-width:100%}
.bk-tabs a{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:9px;font-size:14px;font-weight:600;color:var(--color-text-secondary);text-decoration:none}
.bk-tabs a:hover{color:var(--color-text-primary)}
.bk-tabs a.active{background:var(--color-white);color:var(--color-accent-text, var(--color-primary));box-shadow:0 1px 4px rgba(0,0,0,.08)}
.bk-tip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-left:6px;border-radius:50%;background:var(--color-border);color:var(--color-text-secondary);font:italic 700 11px/1 Georgia,serif;cursor:help;vertical-align:middle}
.bk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.bk-grid .full{grid-column:1/-1}
.bk-help{font-size:13px;color:var(--color-text-secondary);line-height:1.5;margin:0 0 12px}
.bk-note{font-size:12px;color:var(--color-text-secondary);line-height:1.5}
.bk-chip{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;margin:1px 2px;border-radius:999px;background:var(--color-secondary);border:1px solid var(--color-border);white-space:nowrap}
.bk-chip.ok{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.45);color:#047857}
.bk-chip.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.5);color:#b45309}
.bk-chip.bad{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.45);color:#b91c1c}
.bk-chip.info{background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.4);color:#1d4ed8}
.bk-amount{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.bk-amount.in{color:#047857}.bk-amount.out{color:#b91c1c}
.bk-recon{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px}
.bk-recon div{padding:10px 12px;border-radius:10px;background:var(--color-secondary);border:1px solid var(--color-border);font-size:12px;color:var(--color-text-secondary)}
.bk-recon b{display:block;font-size:17px;color:var(--color-text-primary);margin-top:2px;font-variant-numeric:tabular-nums}
.bk-recon .diff-ok{border-color:rgba(16,185,129,.6);background:rgba(16,185,129,.08)}
.bk-recon .diff-bad{border-color:rgba(239,68,68,.6);background:rgba(239,68,68,.06)}
.bk-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.bk-toolbar .form-group{margin:0}
.bk-inline{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}
.bk-inline .form-control{width:auto;min-width:120px;max-width:280px;padding:4px 8px;font-size:12px}
.bk-filter a{margin-right:6px;font-size:13px;text-decoration:none}
.bk-filter a.active{font-weight:700;text-decoration:underline}
.bk-lines td{vertical-align:top;font-size:13px}
.bk-lines tr.st-matched td,.bk-lines tr.st-posted td{background:rgba(16,185,129,.04)}
.bk-lines tr.st-ignored td{opacity:.6}
.bk-desc{max-width:340px;word-break:break-word}
.bk-sub{display:block;font-size:11px;color:var(--color-text-secondary);margin-top:2px}
.bk-bulk{position:sticky;bottom:0;z-index:5;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;padding:12px 14px;border-radius:12px;background:var(--color-white);border:1px solid var(--color-border);box-shadow:0 -4px 16px rgba(0,0,0,.06)}
.bk-bulk .form-group{margin:0}
.bk-empty{padding:26px;text-align:center;border:2px dashed var(--color-border);border-radius:12px;color:var(--color-text-secondary);font-size:14px}
.bk-adv{margin-top:10px}
.bk-adv summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--color-text-secondary)}
.bk-adv .bk-grid{margin-top:10px;grid-template-columns:repeat(auto-fit,minmax(110px,1fr))}
.bk-map td{vertical-align:middle}
.bk-map select{max-width:340px}
.card + .card{margin-top:16px}
</style>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Saldo en bancos (libros)</h4><div class="stat-value"><?php echo bkMoney($bankTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Bold por liquidar <?php echo bkTip('Ventas cobradas con datáfono o link de pago que Bold aún no ha depositado. Baja con cada liquidación importada.'); ?></h4><div class="stat-value" style="color:var(--color-accent-text, var(--color-primary));"><?php echo bkMoney($bridgeTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Cuentas activas</h4><div class="stat-value" style="font-size:18px;"><?php echo (int)$activeCount; ?></div></div>
    <div class="stat-card neumorphic"><h4>Cobros asignados desde</h4><div class="stat-value" style="font-size:18px;"><?php echo $collectionSince !== '' ? bkDate($collectionSince) : 'Sin asignar'; ?></div></div>
</div>

<nav class="bk-tabs">
    <a class="<?php echo $tab === 'cuentas' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=cuentas">Cuentas y medios de pago</a>
    <a class="<?php echo $tab === 'conciliacion' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=conciliacion">Conciliación bancaria</a>
    <a class="<?php echo $tab === 'bold' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=bold">Liquidaciones Bold</a>
</nav>

<?php if ($tab === 'cuentas'): ?>
<?php
$form = $editAccount ?: ['id' => 0, 'name' => '', 'kind' => 'bank', 'bank_name' => '', 'account_number' => '', 'account_code' => '',
    'provider' => 'bold', 'settle_bank_account_id' => null, 'reconcile_from' => null, 'is_active' => 1, 'notes' => ''];
$isEdit = (int)$form['id'] > 0;
?>
<div class="card neumorphic" id="bk-nueva-cuenta">
    <div class="card-header"><h3>Cuentas bancarias, billeteras y pasarelas</h3></div>
    <div class="card-body">
        <p class="bk-help">Cada cuenta tiene su propia subcuenta contable: bancos y billeteras en <b>1110</b> (disponible) y la pasarela Bold en <b>1380</b> (cuenta por cobrar «Bold por liquidar»). Los pagos a proveedores, gastos y nómina siguen saliendo de la cuenta bancaria principal configurada en Contabilidad.</p>
        <?php if (!$accounts): ?>
            <div class="bk-empty">Aún no hay cuentas. Cree la primera con el formulario de abajo.</div>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Nombre</th><th>Tipo</th><th>Entidad / número</th><th>Cuenta contable</th><th class="bk-amount">Saldo en libros</th><th>Recibe cobros de</th><th>Último extracto</th><th>Por conciliar</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): ?>
                <tr>
                    <td><strong><?php echo $h($a['name']); ?></strong><?php if ($a['kind'] === 'gateway' && !empty($a['settle_bank_account_id']) && isset($accountsById[(int)$a['settle_bank_account_id']])): ?><span class="bk-sub">Deposita en <?php echo $h($accountsById[(int)$a['settle_bank_account_id']]['name']); ?></span><?php endif; ?></td>
                    <td><span class="bk-chip <?php echo $a['kind'] === 'gateway' ? 'info' : ''; ?>"><?php echo $h($a['kind_label']); ?></span></td>
                    <td><?php echo $h(trim(($a['bank_name'] ?? '') . ' ' . ($a['account_number'] ?? '')) ?: '—'); ?></td>
                    <td><?php echo $h($a['account_code']); ?><span class="bk-sub"><?php echo $h($a['account_name'] ?? ''); ?></span></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$a['balance'], $currency); ?></td>
                    <td><?php if ($a['methods']): foreach ($a['methods'] as $m): ?><span class="bk-chip"><?php echo $h($methodOptions[$m] ?? $m); ?></span><?php endforeach; else: ?><span class="bk-note">—</span><?php endif; ?></td>
                    <td><?php echo $a['kind'] === 'gateway' ? '<span class="bk-note">N/A</span>' : bkDate($a['last_statement']); ?></td>
                    <td><?php if ($a['kind'] === 'gateway'): ?><span class="bk-note">N/A</span><?php elseif ((int)($a['pending_count'] ?? 0) > 0): ?><span class="bk-chip warn"><?php echo (int)$a['pending_count']; ?> mov. · <?php echo bkMoney((float)($a['pending_sum'] ?? 0), $currency); ?></span><?php else: ?><span class="bk-chip ok">Al día</span><?php endif; ?></td>
                    <td><span class="badge <?php echo (int)$a['is_active'] === 1 ? 'badge-success' : 'badge-warning'; ?>"><?php echo (int)$a['is_active'] === 1 ? 'Activa' : 'Inactiva'; ?></span></td>
                    <td style="white-space:nowrap;"><?php if ($a['kind'] !== 'gateway'): ?><a class="btn btn-sm btn-primary" href="<?php echo $base; ?>?tab=conciliacion&amp;bank_id=<?php echo (int)$a['id']; ?>">Conciliar</a> <?php endif; ?><?php if ($canEdit): ?><a class="btn btn-sm btn-secondary" href="<?php echo $base; ?>?tab=cuentas&amp;edit=<?php echo (int)$a['id']; ?>#bkAccountForm">Editar</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isEdit ? $canEdit : $canCreate): ?>
<div class="card neumorphic" id="bkAccountForm">
    <div class="card-header"><h3><?php echo $isEdit ? 'Editar cuenta: ' . $h($form['name']) : 'Nueva cuenta'; ?></h3></div>
    <div class="card-body">
        <form method="POST" action="<?php echo $base; ?>?action=save_account">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
            <div class="bk-grid">
                <div class="form-group"><label>Nombre *</label><input class="form-control" name="name" required maxlength="120" value="<?php echo $h($form['name']); ?>" placeholder="Bancolombia ahorros / Bold por liquidar"></div>
                <div class="form-group"><label>Tipo</label>
                    <select class="form-control" name="kind" id="bkKind">
                        <?php foreach ($kinds as $k => $label): ?>
                            <option value="<?php echo $h($k); ?>" <?php echo $form['kind'] === $k ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Entidad</label><input class="form-control" name="bank_name" maxlength="80" value="<?php echo $h($form['bank_name']); ?>" placeholder="Bancolombia, Nequi, Bold…"></div>
                <div class="form-group"><label>Número de cuenta</label><input class="form-control" name="account_number" maxlength="40" value="<?php echo $h($form['account_number']); ?>"></div>
                <div class="form-group"><label>Cuenta contable <?php echo bkTip('Se crea automáticamente si no existe. Bancos/billeteras: código 11…; pasarela Bold: código 13…'); ?></label>
                    <?php if ($isEdit): ?>
                        <input class="form-control" value="<?php echo $h($form['account_code']); ?>" disabled>
                    <?php else: ?>
                        <input class="form-control" name="account_code" id="bkCode" maxlength="12" inputmode="numeric" placeholder="<?php echo $h($suggestedCodes['bank'] ?? ''); ?>">
                        <span class="bk-sub">Vacío = se usa el sugerido</span>
                    <?php endif; ?>
                </div>
                <div class="form-group bk-gw"><label>Banco donde deposita</label>
                    <select class="form-control" name="settle_bank_account_id">
                        <option value="0">—</option>
                        <?php foreach ($accounts as $a): if ($a['kind'] === 'gateway') continue; ?>
                            <option value="<?php echo (int)$a['id']; ?>" <?php echo (int)($form['settle_bank_account_id'] ?? 0) === (int)$a['id'] ? 'selected' : ''; ?>><?php echo $h($a['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="provider" value="bold">
                <div class="form-group bk-bank"><label>Conciliar desde <?php echo bkTip('Fecha desde la que se comparan libros contra extractos. Se fija sola con el primer extracto importado.'); ?></label><input class="form-control" type="date" name="reconcile_from" value="<?php echo $h($form['reconcile_from'] ?? ''); ?>"></div>
                <div class="form-group full"><label>Notas</label><input class="form-control" name="notes" maxlength="255" value="<?php echo $h($form['notes'] ?? ''); ?>"></div>
                <label style="display:flex;gap:8px;align-items:center;font-weight:normal;"><input type="checkbox" name="is_active" value="1" <?php echo (int)$form['is_active'] === 1 ? 'checked' : ''; ?>> Activa</label>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;">
                <button class="btn btn-primary" type="submit"><?php echo $isEdit ? 'Guardar cambios' : 'Crear cuenta'; ?></button>
                <?php if ($isEdit): ?><a class="btn btn-secondary" href="<?php echo $base; ?>?tab=cuentas">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card neumorphic" id="bk-cobros">
    <div class="card-header"><h3>¿A dónde entra cada cobro de ventas? <?php echo bkTip('Solo afecta los cobros de ventas registrados desde la fecha indicada. Las ventas anteriores no se modifican.'); ?></h3></div>
    <div class="card-body">
        <p class="bk-help">Asigne cada medio de pago a la cuenta donde realmente llega el dinero. Para Bold (datáfono, Smart / Smart Pro y links de pago) elija la cuenta <b>Bold por liquidar</b>: la venta queda como cuenta por cobrar a Bold por el valor bruto y, al importar la liquidación, se registran la comisión, el IVA y las retenciones reales y el neto que llega al banco.
        <br>Al asignar un medio a Bold se <b>deja de estimar</b> la comisión con tarifas para ese medio (la comisión real llega con la liquidación).</p>
        <div class="bk-toolbar">
            <a class="btn btn-secondary" href="#bk-nueva-cuenta">+ Nueva cuenta bancaria</a>
            <span class="bk-note">Las cuentas creadas arriba aparecen automáticamente en cada lista «Cuenta que recibe el dinero».</span>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=save_mapping">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="table-container"><table class="bk-map">
                <thead><tr><th>Medio de pago</th><th>Cuenta que recibe el dinero</th></tr></thead>
                <tbody>
                <?php foreach ($methodOptions as $code => $label): $sel = (int)($collectionMap[$code] ?? 0); ?>
                    <tr>
                        <td><?php echo $h($label); ?><span class="bk-sub"><?php echo $h($code); ?></span></td>
                        <td>
                            <select class="form-control" name="map[<?php echo $h($code); ?>]" <?php echo $canEdit ? '' : 'disabled'; ?>>
                                <option value="0">Regla general (cuenta bancaria principal)</option>
                                <?php foreach ($activeAccounts as $a): ?>
                                    <option value="<?php echo (int)$a['id']; ?>" <?php echo $sel === (int)$a['id'] ? 'selected' : ''; ?>><?php echo $h($a['name'] . ' · ' . $a['account_code'] . ($a['kind'] === 'gateway' ? ' (por liquidar)' : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($canEdit): ?>
            <div class="bk-toolbar" style="margin-top:12px;">
                <div class="form-group"><label>Aplicar a cobros desde</label><input class="form-control" type="date" name="since" value="<?php echo $h($collectionSince !== '' ? $collectionSince : date('Y-m-d')); ?>"></div>
                <button class="btn btn-primary" type="submit" onclick="return confirm('¿Guardar la asignación? Los cobros desde la fecha indicada se contabilizarán en las cuentas elegidas.');">Guardar asignación</button>
            </div>
            <p class="bk-note">Recomendado: use como fecha el día siguiente al último cierre o conciliación, para no mezclar ventas ya contabilizadas con la regla anterior.</p>
            <?php endif; ?>
        </form>
        <?php if ($canCreate): ?>
        <form method="POST" action="<?php echo $base; ?>?action=add_payment_method" style="margin-top:16px;border-top:1px solid var(--color-border);padding-top:14px;">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="bk-toolbar">
                <div class="form-group">
                    <label>Nuevo medio de pago</label>
                    <input class="form-control" type="text" name="new_payment_method" maxlength="120" placeholder="Ej.: PSE, Addi, Sistecrédito…" required>
                </div>
                <button class="btn btn-secondary" type="submit">+ Agregar medio de pago</button>
            </div>
            <p class="bk-note">Al agregarlo aparece como una fila más en la tabla de arriba (para asignarle cuenta) y también como opción al cobrar en Ventas. No es efectivo: entra a banco/pasarela.</p>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'conciliacion'): ?>
<?php
$banks = $banks ?? [];
$bankId = (int)($bankId ?? 0);
$statements = $statements ?? [];
$postings = $postings ?? [];
$statement = $statement ?? null;
$recon = $recon ?? null;
$lines = $lines ?? [];
$candidates = $candidates ?? [];
$lineFilter = $lineFilter ?? '';
$counterAccounts = $counterAccounts ?? [];
?>
<?php if (!$banks): ?>
    <div class="bk-empty">Primero cree una cuenta bancaria en la pestaña <a href="<?php echo $base; ?>?tab=cuentas">Cuentas y medios de pago</a>.</div>
<?php else: ?>
<div class="bk-toolbar">
    <form method="GET" action="<?php echo $base; ?>" class="bk-inline">
        <input type="hidden" name="tab" value="conciliacion">
        <div class="form-group"><label>Cuenta</label>
            <select class="form-control" name="bank_id" onchange="this.form.submit()" style="min-width:240px;">
                <?php foreach ($banks as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>" <?php echo $bankId === (int)$b['id'] ? 'selected' : ''; ?>><?php echo $h($b['name'] . ' · ' . $b['account_code']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($canCreate): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Importar extracto</h3></div>
    <div class="card-body">
        <p class="bk-help">Descargue el extracto o los movimientos desde la sucursal virtual (Bancolombia, Davivienda, Nequi…) en <b>CSV, TXT o Excel (.xlsx)</b>. Las columnas de fecha, descripción y valor (o débito / crédito) se detectan solas. Los movimientos repetidos de un archivo anterior se omiten.</p>
        <form method="POST" action="<?php echo $base; ?>?action=import_statement" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="bank_id" value="<?php echo $bankId; ?>">
            <div class="bk-toolbar">
                <div class="form-group"><label>Archivo</label><input class="form-control" type="file" name="file" accept=".csv,.txt,.tsv,.xlsx,.xls" required></div>
                <button class="btn btn-primary" type="submit">Importar y cruzar</button>
            </div>
            <details class="bk-adv">
                <summary>Opciones avanzadas (si las columnas no se reconocen)</summary>
                <div class="bk-grid">
                    <div class="form-group"><label>Col. fecha</label><input class="form-control" name="col_date" maxlength="3" placeholder="A"></div>
                    <div class="form-group"><label>Col. descripción</label><input class="form-control" name="col_desc" maxlength="3"></div>
                    <div class="form-group"><label>Col. referencia</label><input class="form-control" name="col_ref" maxlength="3"></div>
                    <div class="form-group"><label>Col. valor (±)</label><input class="form-control" name="col_amount" maxlength="3"></div>
                    <div class="form-group"><label>Col. débito</label><input class="form-control" name="col_debit" maxlength="3"></div>
                    <div class="form-group"><label>Col. crédito</label><input class="form-control" name="col_credit" maxlength="3"></div>
                    <div class="form-group"><label>Col. saldo</label><input class="form-control" name="col_balance" maxlength="3"></div>
                    <div class="form-group"><label>Primera fila de datos</label><input class="form-control" type="number" min="1" name="first_row"></div>
                </div>
                <label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin-top:8px;"><input type="checkbox" name="invert_sign" value="1"> Invertir signo (si los retiros salen positivos)</label>
            </details>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header"><h3>Extractos importados</h3></div>
    <div class="card-body">
        <?php if (!$statements): ?>
            <div class="bk-empty">Sin extractos para esta cuenta.</div>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Periodo</th><th>Archivo</th><th>Movimientos</th><th>Sin cruzar</th><th class="bk-amount">Créditos</th><th class="bk-amount">Débitos</th><th class="bk-amount">Saldo final</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($statements as $s): ?>
                <tr<?php echo $statement && (int)$statement['id'] === (int)$s['id'] ? ' style="background:rgba(59,130,246,.06)"' : ''; ?>>
                    <td><?php echo bkDate($s['period_from']) . ' – ' . bkDate($s['period_to']); ?></td>
                    <td><?php echo $h($s['file_name'] ?? ''); ?><span class="bk-sub"><?php echo bkDate($s['created_at'] ?? null); ?></span></td>
                    <td><?php echo (int)$s['total_lines']; ?></td>
                    <td><?php echo (int)$s['pending'] > 0 ? '<span class="bk-chip warn">' . (int)$s['pending'] . '</span>' : '<span class="bk-chip ok">0</span>'; ?></td>
                    <td class="bk-amount in"><?php echo bkMoney((float)$s['credits'], $currency); ?></td>
                    <td class="bk-amount out"><?php echo bkMoney((float)$s['debits'], $currency); ?></td>
                    <td class="bk-amount"><?php echo $s['closing_balance'] !== null ? bkMoney((float)$s['closing_balance'], $currency) : '—'; ?></td>
                    <td><a class="btn btn-sm btn-secondary" href="<?php echo $base; ?>?tab=conciliacion&amp;statement_id=<?php echo (int)$s['id']; ?>">Conciliar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($statement && $recon): ?>
<?php
$sid = (int)$statement['id'];
$counts = $recon['counts'] ?? [];
$diff = $recon['difference'];
$otherBanks = array_values(array_filter($bankOnly, static fn ($b) => (int)$b['id'] !== $bankId));
?>
<div class="card neumorphic" id="bkStatement">
    <div class="card-header"><h3>Conciliación <?php echo bkDate($statement['period_from']) . ' – ' . bkDate($statement['period_to']); ?> · <?php echo $h($recon['account']['name']); ?></h3></div>
    <div class="card-body">
        <div class="bk-recon">
            <div>Saldo según banco<b><?php echo $recon['bank_balance'] !== null ? bkMoney((float)$recon['bank_balance'], $currency) : 'Sin saldo en archivo'; ?></b></div>
            <div>Saldo en libros al <?php echo bkDate($recon['to']); ?><b><?php echo bkMoney((float)$recon['book_balance'], $currency); ?></b></div>
            <div>+ Movimientos del banco sin registrar <?php echo bkTip('Líneas «Sin cruzar» hasta el cierre: el banco las tiene y los libros no (4x1000, comisiones, consignaciones no registradas…).'); ?><b><?php echo bkMoney((float)$recon['pending_sum'], $currency); ?></b></div>
            <div>− Partidas del libro que el banco no muestra <?php echo bkTip('Asientos de esta cuenta desde «Conciliar desde» que no están cruzados con ningún movimiento del extracto (cheques no cobrados, consignaciones en tránsito o errores).'); ?><b><?php echo bkMoney((float)$recon['outstanding_sum'], $currency); ?></b></div>
            <div>= Saldo esperado en banco<b><?php echo bkMoney((float)$recon['expected_bank'], $currency); ?></b></div>
            <div class="<?php echo $diff === null ? '' : (abs((float)$diff) < 0.5 ? 'diff-ok' : 'diff-bad'); ?>">Diferencia<b><?php echo $diff === null ? '—' : bkMoney((float)$diff, $currency); ?></b></div>
        </div>
        <?php if ($diff !== null && abs((float)$diff) >= 0.5): ?>
            <p class="bk-note">Si la diferencia viene de antes de empezar a conciliar (saldo inicial distinto entre banco y libros), ajústela con un comprobante manual en Contabilidad. Si aparece después, revise movimientos ignorados o cruces incorrectos.</p>
        <?php endif; ?>

        <div class="bk-toolbar">
            <a class="btn btn-secondary" href="<?php echo $base; ?>?action=export_recon&amp;statement_id=<?php echo $sid; ?>">Descargar conciliación (Excel)</a>
            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo $base; ?>?action=auto_match" class="bk-inline">
                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>">
                <button class="btn btn-secondary" type="submit">Cruce automático</button>
            </form>
            <?php endif; ?>
            <?php if ($canCreate): ?>
            <form method="POST" action="<?php echo $base; ?>?action=register_suggested" class="bk-inline">
                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>">
                <button class="btn btn-primary" type="submit" onclick="return confirm('Se crearán asientos (y gastos cuando son cargos) para los movimientos sugeridos como 4x1000, comisiones, intereses o retenciones. ¿Continuar?');">Registrar sugeridos</button>
            </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <form method="POST" action="<?php echo $base; ?>?action=delete_statement" class="bk-inline" style="margin-left:auto;">
                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>">
                <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('¿Eliminar este extracto y sus movimientos? Solo es posible si ninguno fue registrado en contabilidad.');">Eliminar extracto</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="bk-filter" style="margin-bottom:10px;">
            <?php $total = array_sum(array_map(static fn ($c) => (int)$c['cnt'], $counts)); ?>
            <a class="<?php echo $lineFilter === '' ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=conciliacion&amp;statement_id=<?php echo $sid; ?>#bkStatement">Todos (<?php echo (int)$total; ?>)</a>
            <?php foreach ($lineStatus as $st => $label): ?>
                <a class="<?php echo $lineFilter === $st ? 'active' : ''; ?>" href="<?php echo $base; ?>?tab=conciliacion&amp;statement_id=<?php echo $sid; ?>&amp;status=<?php echo $st; ?>#bkStatement"><?php echo $h($label); ?> (<?php echo (int)($counts[$st]['cnt'] ?? 0); ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if (!$lines): ?>
            <div class="bk-empty">No hay movimientos con este filtro.</div>
        <?php else: ?>
        <div class="table-container"><table class="bk-lines">
            <thead><tr>
                <th><?php if ($canCreate): ?><input type="checkbox" id="bkAll" title="Seleccionar pendientes"><?php endif; ?></th>
                <th>Fecha</th><th>Descripción</th><th class="bk-amount">Valor</th><th>Estado</th><th>Cruce / acción</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lines as $l): $lid = (int)$l['id']; $amt = (float)$l['amount']; ?>
                <tr class="st-<?php echo $h($l['status']); ?>">
                    <td><?php if ($canCreate && $l['status'] === 'pending'): ?><input type="checkbox" class="bk-pick" name="line_ids[]" value="<?php echo $lid; ?>" form="bnkBulk" data-sug="<?php echo $h($l['suggestion'] ?? ''); ?>"><?php endif; ?></td>
                    <td style="white-space:nowrap;"><?php echo bkDate($l['line_date']); ?></td>
                    <td class="bk-desc"><?php echo $h($l['description'] ?? ''); ?><?php if (!empty($l['reference'])): ?><span class="bk-sub">Ref. <?php echo $h($l['reference']); ?></span><?php endif; ?></td>
                    <td class="bk-amount <?php echo $amt >= 0 ? 'in' : 'out'; ?>"><?php echo bkMoney($amt, $currency); ?><?php if ($l['balance'] !== null): ?><span class="bk-sub">Saldo <?php echo bkMoney((float)$l['balance'], $currency); ?></span><?php endif; ?></td>
                    <td>
                        <?php
                        $cls = ['pending' => 'warn', 'matched' => 'ok', 'posted' => 'ok', 'ignored' => ''][$l['status']] ?? '';
                        echo '<span class="bk-chip ' . $cls . '">' . $h($lineStatus[$l['status']] ?? $l['status']) . '</span>';
                        if ($l['status'] === 'pending' && !empty($l['suggestion'])) {
                            echo '<span class="bk-chip info">Sugerido: ' . $h($postingKinds[$l['suggestion']]['label'] ?? $l['suggestion']) . '</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($l['status'] === 'matched'): ?>
                            Asiento <?php echo $entryLink($l['entry_id'], $l['entry_number']); ?> · <?php echo bkDate($l['entry_date']); ?>
                            <span class="bk-sub"><?php echo $h(\SoftNova\Services\AccountingService::sourceLabel($l['source_type'] ?? '') . ' · ' . ($l['entry_description'] ?? '')); ?></span>
                            <?php if ($canEdit): ?>
                            <form method="POST" action="<?php echo $base; ?>?action=unmatch_line" class="bk-inline">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>"><input type="hidden" name="line_id" value="<?php echo $lid; ?>">
                                <button class="btn btn-sm btn-secondary" type="submit">Deshacer cruce</button>
                            </form>
                            <?php endif; ?>
                        <?php elseif ($l['status'] === 'posted'): ?>
                            Registrado: <?php echo $h($postingKinds[$l['posting_kind'] ?? '']['label'] ?? ($l['posting_kind'] ?? '')); ?> · asiento <?php echo $entryLink($l['entry_id'], $l['entry_number']); ?>
                            <span class="bk-sub">Para deshacerlo, anule el registro en «Registros desde el extracto».</span>
                        <?php elseif ($l['status'] === 'ignored'): ?>
                            <?php echo $h($l['notes'] ?: 'Ignorado'); ?>
                            <?php if ($canEdit): ?>
                            <form method="POST" action="<?php echo $base; ?>?action=restore_line" class="bk-inline">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>"><input type="hidden" name="line_id" value="<?php echo $lid; ?>">
                                <button class="btn btn-sm btn-secondary" type="submit">Restaurar</button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($canEdit && !empty($candidates[$lid])): ?>
                            <form method="POST" action="<?php echo $base; ?>?action=match_line" class="bk-inline">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>"><input type="hidden" name="line_id" value="<?php echo $lid; ?>">
                                <select class="form-control" name="accounting_line_id">
                                    <?php foreach ($candidates[$lid] as $c): ?>
                                        <option value="<?php echo (int)$c['line_id']; ?>">#<?php echo $h($c['entry_number']); ?> · <?php echo bkDate($c['entry_date']); ?> · <?php echo $h(\SoftNova\Services\AccountingService::sourceLabel($c['source_type'] ?? '')); ?> · <?php echo $h(mb_strimwidth((string)($c['entry_description'] ?? ''), 0, 50, '…')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary" type="submit">Cruzar</button>
                            </form>
                            <?php elseif ($canEdit): ?>
                                <span class="bk-sub">Sin asiento con el mismo valor (±15 días). Regístrelo abajo o ignórelo.</span>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                            <form method="POST" action="<?php echo $base; ?>?action=ignore_line" class="bk-inline" style="margin-top:4px;">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="statement_id" value="<?php echo $sid; ?>"><input type="hidden" name="line_id" value="<?php echo $lid; ?>">
                                <input class="form-control" name="note" maxlength="200" placeholder="Motivo (opcional)">
                                <button class="btn btn-sm btn-secondary" type="submit">Ignorar</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>

        <?php if ($canCreate): ?>
        <form method="POST" action="<?php echo $base; ?>?action=register_lines" id="bnkBulk" class="bk-bulk">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="statement_id" value="<?php echo $sid; ?>">
            <strong style="align-self:center;">Registrar seleccionados (<span id="bkPicked">0</span>)</strong>
            <div class="form-group"><label>Tipo</label>
                <select class="form-control" name="kind" id="bkBulkKind">
                    <?php foreach ($postingKinds as $k => $meta): ?>
                        <option value="<?php echo $h($k); ?>"><?php echo $h($meta['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="bkCounterBank" style="display:none;"><label>Otra cuenta</label>
                <select class="form-control" name="counter_bank">
                    <option value="">Seleccione…</option>
                    <?php foreach ($activeAccounts as $b): if ((int)$b['id'] === $bankId) continue; ?>
                        <option value="<?php echo $h($b['account_code']); ?>"><?php echo $h($b['name'] . ' · ' . $b['account_code']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="bkCounterAccount" style="display:none;"><label>Cuenta contable</label>
                <input class="form-control" name="counter_account" list="bkAccountsList" placeholder="Código, ej. 130505" maxlength="12" style="min-width:200px;">
                <datalist id="bkAccountsList">
                    <?php foreach ($counterAccounts as $ca): ?>
                        <option value="<?php echo $h($ca['code']); ?>"><?php echo $h($ca['name']); ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group"><label>Nota</label><input class="form-control" name="note" maxlength="120"></div>
            <button class="btn btn-primary" type="submit" id="bkBulkBtn" disabled>Registrar</button>
            <span class="bk-note" style="flex-basis:100%;">Se crea un asiento por mes contra la cuenta del extracto. 4x1000 y comisiones bancarias también aparecen en Gastos (bloqueados allí; se anulan desde aquí). Use «Traslado» para movimientos entre cuentas propias; en la otra cuenta ese asiento se cruza con su extracto.</span>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Partidas del libro que el banco no muestra (<?php echo count($recon['outstanding']); ?>)</h3></div>
    <div class="card-body">
        <p class="bk-help">Asientos de <?php echo $h($recon['account']['account_code']); ?> entre <?php echo bkDate($recon['from']); ?> y <?php echo bkDate($recon['to']); ?> sin cruzar con el extracto. Si alguno corresponde a un movimiento que el banco registró con otra fecha, crúcelo desde la línea del extracto.</p>
        <?php if (!$recon['outstanding']): ?>
            <div class="bk-empty">Todo lo registrado en libros aparece en el extracto.</div>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Fecha</th><th>Asiento</th><th>Origen</th><th>Descripción</th><th class="bk-amount">Entrada</th><th class="bk-amount">Salida</th></tr></thead>
            <tbody>
            <?php foreach ($recon['outstanding'] as $o): ?>
                <tr>
                    <td><?php echo bkDate($o['entry_date']); ?></td>
                    <td><?php echo $entryLink($o['entry_id'], $o['entry_number']); ?></td>
                    <td><?php echo $h(\SoftNova\Services\AccountingService::sourceLabel($o['source_type'] ?? '')); ?></td>
                    <td class="bk-desc"><?php echo $h($o['description'] ?? ''); ?><?php if (!empty($o['third_party_name'])): ?><span class="bk-sub"><?php echo $h($o['third_party_name']); ?></span><?php endif; ?></td>
                    <td class="bk-amount in"><?php echo (float)$o['debit'] > 0 ? bkMoney((float)$o['debit'], $currency) : ''; ?></td>
                    <td class="bk-amount out"><?php echo (float)$o['credit'] > 0 ? bkMoney((float)$o['credit'], $currency) : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header"><h3>Registros desde el extracto</h3></div>
    <div class="card-body">
        <?php if (!$postings): ?>
            <div class="bk-empty">Aún no hay registros (4x1000, comisiones, intereses, traslados) para esta cuenta.</div>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th class="bk-amount">Valor</th><th>Contrapartida</th><th>Asiento</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($postings as $p): $pa = (float)$p['amount']; ?>
                <tr>
                    <td><?php echo bkDate($p['posting_date']); ?></td>
                    <td><?php echo $h($postingKinds[$p['kind']]['label'] ?? $p['kind']); ?></td>
                    <td class="bk-desc"><?php echo $h($p['description']); ?><span class="bk-sub"><?php echo (int)$p['lines_count']; ?> movimiento(s)</span></td>
                    <td class="bk-amount <?php echo $pa >= 0 ? 'in' : 'out'; ?>"><?php echo bkMoney($pa, $currency); ?></td>
                    <td><?php echo $h($p['counter_account']); ?></td>
                    <td><?php echo $entryLink($p['entry_id'], $p['entry_number']); ?></td>
                    <td><?php echo $p['status'] === 'posted' ? '<span class="bk-chip ok">Contabilizado</span>' : '<span class="bk-chip bad" title="' . $h($p['cancel_reason'] ?? '') . '">Anulado</span>'; ?></td>
                    <td>
                        <?php if ($canDelete && $p['status'] === 'posted'): ?>
                        <form method="POST" action="<?php echo $base; ?>?action=cancel_posting" class="bk-inline">
                            <?php echo \SoftNova\Core\csrf_field(); ?>
                            <input type="hidden" name="posting_id" value="<?php echo (int)$p['id']; ?>">
                            <input type="hidden" name="bank_id" value="<?php echo $bankId; ?>">
                            <input type="hidden" name="statement_id" value="<?php echo $statement ? (int)$statement['id'] : 0; ?>">
                            <input class="form-control" name="reason" required minlength="5" maxlength="200" placeholder="Motivo">
                            <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('¿Anular? Se revierte el asiento, se anula el gasto y los movimientos vuelven a «Sin cruzar».');">Anular</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<?php
$gateways = $gateways ?? [];
$banks = $banks ?? [];
$gateway = $gateway ?? null;
$bridgeBalance = (float)($bridgeBalance ?? 0);
$gatewayMethods = $gatewayMethods ?? [];
$pendingSales = $pendingSales ?? ['count' => 0, 'total' => 0.0, 'rows' => []];
$settlements = $settlements ?? [];
$settlementDetail = $settlementDetail ?? null;
$unmatchedTx = $unmatchedTx ?? [];
$methodGroups = $methodGroups ?? [];
?>
<?php if (!$gateway): ?>
    <div class="bk-empty">
        Para liquidar Bold cree una cuenta de tipo <b>Pasarela por liquidar</b> (ej. «Bold por liquidar») en <a href="<?php echo $base; ?>?tab=cuentas">Cuentas y medios de pago</a>
        y asígnele los medios de pago que cobra con Bold (tarjeta débito/crédito en datáfono o Smart, link de pago).
    </div>
<?php else: ?>
<?php if (count($gateways) > 1): ?>
<form method="GET" action="<?php echo $base; ?>" class="bk-toolbar">
    <input type="hidden" name="tab" value="bold">
    <div class="form-group"><label>Pasarela</label>
        <select class="form-control" name="gateway_id" onchange="this.form.submit()">
            <?php foreach ($gateways as $g): ?>
                <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === (int)$gateway['id'] ? 'selected' : ''; ?>><?php echo $h($g['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
<?php endif; ?>

<div class="bk-recon">
    <div>Saldo <?php echo $h($gateway['name']); ?> (<?php echo $h($gateway['account_code']); ?>)<b><?php echo bkMoney($bridgeBalance, $currency); ?></b></div>
    <div>Ventas Bold sin liquidar <?php echo bkTip('Cobros con medios asignados a Bold, desde la fecha de activación, que aún no aparecen en ninguna liquidación importada.'); ?><b><?php echo (int)$pendingSales['count']; ?> · <?php echo bkMoney((float)$pendingSales['total'], $currency); ?></b></div>
    <div>Medios asignados<b style="font-size:13px;"><?php if ($gatewayMethods): foreach ($gatewayMethods as $m): ?><span class="bk-chip"><?php echo $h($methodOptions[$m] ?? $m); ?></span><?php endforeach; else: ?><span class="bk-chip bad">Ninguno</span><?php endif; ?></b></div>
</div>
<?php if (!$gatewayMethods): ?>
    <p class="bk-note" style="color:#b91c1c;">Asigne los medios de pago de Bold a esta cuenta en <a href="<?php echo $base; ?>?tab=cuentas">Cuentas y medios de pago</a> antes de importar liquidaciones.</p>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Importar reporte de Bold</h3></div>
    <div class="card-body">
        <p class="bk-help">En el panel de Bold descargue el reporte de <b>ventas / depósitos</b> (CSV o Excel) del periodo. Por cada fecha de depósito se crea una liquidación: <b>Dr banco (neto) + Dr comisión + Dr IVA descontable + Dr retenciones (135515 / 135517 / 135518) / Cr <?php echo $h($gateway['name']); ?> (bruto)</b>, y la comisión aparece en Gastos. Las transacciones ya liquidadas, rechazadas o anteriores a la fecha de activación se omiten.</p>
        <form method="POST" action="<?php echo $base; ?>?action=import_bold" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="gateway_id" value="<?php echo (int)$gateway['id']; ?>">
            <div class="bk-toolbar">
                <div class="form-group"><label>Banco donde Bold deposita</label>
                    <select class="form-control" name="bank_id" required>
                        <?php foreach ($banks as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo (int)($gateway['settle_bank_account_id'] ?? 0) === (int)$b['id'] ? 'selected' : ''; ?>><?php echo $h($b['name'] . ' · ' . $b['account_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Archivo</label><input class="form-control" type="file" name="file" accept=".csv,.txt,.tsv,.xlsx,.xls" required></div>
                <button class="btn btn-primary" type="submit" <?php echo $gatewayMethods && $banks ? '' : 'disabled'; ?> onclick="return confirm('Se crearán los asientos de liquidación y los gastos de comisión. ¿Continuar?');">Importar liquidación</button>
            </div>
            <details class="bk-adv">
                <summary>Opciones avanzadas (si las columnas no se reconocen)</summary>
                <div class="bk-grid">
                    <div class="form-group"><label>Col. fecha venta</label><input class="form-control" name="col_date" maxlength="3"></div>
                    <div class="form-group"><label>Col. fecha depósito</label><input class="form-control" name="col_deposit" maxlength="3"></div>
                    <div class="form-group"><label>Col. ID / ref.</label><input class="form-control" name="col_ref" maxlength="3"></div>
                    <div class="form-group"><label>Col. valor bruto</label><input class="form-control" name="col_gross" maxlength="3"></div>
                    <div class="form-group"><label>Col. comisión</label><input class="form-control" name="col_commission" maxlength="3"></div>
                    <div class="form-group"><label>Col. IVA comisión</label><input class="form-control" name="col_vat" maxlength="3"></div>
                    <div class="form-group"><label>Col. retefuente</label><input class="form-control" name="col_ret_renta" maxlength="3"></div>
                    <div class="form-group"><label>Col. reteIVA</label><input class="form-control" name="col_ret_iva" maxlength="3"></div>
                    <div class="form-group"><label>Col. reteICA</label><input class="form-control" name="col_ret_ica" maxlength="3"></div>
                    <div class="form-group"><label>Col. neto</label><input class="form-control" name="col_net" maxlength="3"></div>
                    <div class="form-group"><label>Col. medio</label><input class="form-control" name="col_method" maxlength="3"></div>
                    <div class="form-group"><label>Col. estado</label><input class="form-control" name="col_status" maxlength="3"></div>
                    <div class="form-group"><label>Primera fila de datos</label><input class="form-control" type="number" min="1" name="first_row"></div>
                </div>
            </details>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($settlementDetail): $sd = $settlementDetail; ?>
<div class="card neumorphic" id="bkSettlement">
    <div class="card-header"><h3>Liquidación Bold <?php echo bkDate($sd['settlement_date']); ?> · <?php echo $h($sd['bank_name'] ?? ''); ?></h3></div>
    <div class="card-body">
        <div class="bk-recon">
            <div>Bruto<b><?php echo bkMoney((float)$sd['gross'], $currency); ?></b></div>
            <div>Comisión<b><?php echo bkMoney((float)$sd['commission'], $currency); ?></b></div>
            <div>IVA comisión<b><?php echo bkMoney((float)$sd['vat'], $currency); ?></b></div>
            <div>Retenciones<b><?php echo bkMoney((float)$sd['ret_renta'] + (float)$sd['ret_iva'] + (float)$sd['ret_ica'], $currency); ?></b></div>
            <?php if (abs((float)$sd['adjustment']) >= 0.01): ?><div>Ajuste <?php echo bkTip('Diferencia entre bruto − neto y los descuentos detallados en el reporte. Se registró como mayor/menor comisión.'); ?><b><?php echo bkMoney((float)$sd['adjustment'], $currency); ?></b></div><?php endif; ?>
            <div>Neto depositado<b><?php echo bkMoney((float)$sd['net'], $currency); ?></b></div>
        </div>
        <p class="bk-note">Asiento <?php echo $entryLink($sd['entry_id'], $sd['entry_number']); ?> · archivo <?php echo $h($sd['file_name'] ?? ''); ?><?php if ($sd['status'] !== 'posted'): ?> · <span class="bk-chip bad">Anulada: <?php echo $h($sd['cancel_reason'] ?? ''); ?></span><?php endif; ?></p>
        <?php if ($sd['lines']): ?>
        <div class="table-container"><table>
            <thead><tr><th>Fecha venta</th><th>ID / ref.</th><th>Medio</th><th class="bk-amount">Bruto</th><th class="bk-amount">Comisión + IVA</th><th class="bk-amount">Retenciones</th><th class="bk-amount">Neto</th><th>Venta en el sistema</th></tr></thead>
            <tbody>
            <?php foreach ($sd['lines'] as $ln): ?>
                <tr>
                    <td><?php echo bkDate($ln['tx_date']); ?></td>
                    <td><?php echo $h($ln['tx_ref'] ?? '—'); ?><?php if (!empty($ln['raw_status'])): ?><span class="bk-sub"><?php echo $h($ln['raw_status']); ?></span><?php endif; ?></td>
                    <td><?php echo $h($methodGroups[$ln['method_group']] ?? $ln['method_group']); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$ln['gross'], $currency); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$ln['commission'] + (float)$ln['vat'], $currency); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$ln['ret_renta'] + (float)$ln['ret_iva'] + (float)$ln['ret_ica'], $currency); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$ln['net'], $currency); ?></td>
                    <td><?php echo !empty($ln['sale_payment_id']) ? '<span class="bk-chip ok">' . $h($ln['invoice_number'] ?: ('Pago #' . $ln['sale_payment_id'])) . '</span>' : '<span class="bk-chip warn">Sin asociar</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($canDelete && $sd['status'] === 'posted'): ?>
        <form method="POST" action="<?php echo $base; ?>?action=cancel_bold" class="bk-inline" style="margin-top:12px;">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="settlement_id" value="<?php echo (int)$sd['id']; ?>">
            <input class="form-control" name="reason" required minlength="5" maxlength="200" placeholder="Motivo de la anulación">
            <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('¿Anular la liquidación? Se revierte el asiento, se anula el gasto de comisión y las transacciones quedan libres para importar de nuevo.');">Anular liquidación</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header"><h3>Liquidaciones registradas</h3></div>
    <div class="card-body">
        <?php if (!$settlements): ?>
            <div class="bk-empty">Aún no se han importado liquidaciones de Bold.</div>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Depósito</th><th>Transacciones</th><th class="bk-amount">Bruto</th><th class="bk-amount">Comisión + IVA</th><th class="bk-amount">Retenciones</th><th class="bk-amount">Neto</th><th>Banco</th><th>Asiento</th><th>Extracto</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($settlements as $s): ?>
                <tr>
                    <td><?php echo bkDate($s['settlement_date']); ?></td>
                    <td><?php echo (int)$s['tx_count']; ?><span class="bk-sub"><?php echo (int)$s['matched']; ?> con venta</span></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$s['gross'], $currency); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$s['commission'] + (float)$s['vat'] + (float)$s['adjustment'], $currency); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$s['ret_renta'] + (float)$s['ret_iva'] + (float)$s['ret_ica'], $currency); ?></td>
                    <td class="bk-amount in"><?php echo bkMoney((float)$s['net'], $currency); ?></td>
                    <td><?php echo $h($s['bank_name'] ?? ''); ?></td>
                    <td><?php echo $entryLink($s['entry_id'], $s['entry_number']); ?></td>
                    <td><?php echo $s['status'] !== 'posted' ? '—' : (!empty($s['bank_match']) ? '<span class="bk-chip ok">Cruzado</span>' : '<span class="bk-chip warn" title="Importe el extracto del banco para cruzar el depósito">Pendiente</span>'); ?></td>
                    <td><?php echo $s['status'] === 'posted' ? '<span class="bk-chip ok">Contabilizada</span>' : '<span class="bk-chip bad">Anulada</span>'; ?></td>
                    <td><a class="btn btn-sm btn-secondary" href="<?php echo $base; ?>?tab=bold&amp;settlement_id=<?php echo (int)$s['id']; ?><?php echo $gateway ? '&amp;gateway_id=' . (int)$gateway['id'] : ''; ?>#bkSettlement">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($gateway): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Ventas Bold sin liquidar (<?php echo (int)$pendingSales['count']; ?>)</h3></div>
    <div class="card-body">
        <p class="bk-help">Cobros de los últimos días que Bold normalmente deposita en 1–2 días hábiles. Si una venta lleva varios días aquí, revise en el panel de Bold si fue rechazada o si se registró con otro medio de pago.</p>
        <?php if (!$pendingSales['rows']): ?>
            <div class="bk-empty">No hay ventas Bold pendientes de liquidar.</div>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Fecha</th><th>Venta</th><th>Medio</th><th class="bk-amount">Valor</th><th>Días</th></tr></thead>
            <tbody>
            <?php foreach ($pendingSales['rows'] as $ps): $days = (int)floor((time() - strtotime((string)$ps['payment_date'])) / 86400); ?>
                <tr>
                    <td><?php echo bkDate($ps['payment_date']); ?></td>
                    <td><?php echo $h($ps['invoice_number'] ?: ('Venta #' . $ps['sale_id'])); ?></td>
                    <td><?php echo $h($methodOptions[$ps['payment_method']] ?? $ps['payment_method']); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$ps['amount'], $currency); ?></td>
                    <td><span class="bk-chip <?php echo $days > 4 ? 'warn' : ''; ?>"><?php echo max(0, $days); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($unmatchedTx): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Transacciones Bold sin venta asociada (<?php echo count($unmatchedTx); ?>)</h3></div>
    <div class="card-body">
        <p class="bk-help">Bold las liquidó pero no se encontró un cobro del mismo valor (±1 día) con un medio asignado a Bold. Puede ser una venta registrada con otro medio (efectivo, transferencia), con valor distinto (propina, ajuste) o no registrada. La contabilidad ya está correcta; esto es solo para control.</p>
        <div class="table-container"><table>
            <thead><tr><th>Fecha venta</th><th>Depósito</th><th>ID / ref.</th><th>Medio</th><th class="bk-amount">Bruto</th></tr></thead>
            <tbody>
            <?php foreach ($unmatchedTx as $u): ?>
                <tr>
                    <td><?php echo bkDate($u['tx_date']); ?></td>
                    <td><?php echo bkDate($u['settlement_date']); ?></td>
                    <td><?php echo $h($u['tx_ref'] ?? '—'); ?></td>
                    <td><?php echo $h($methodGroups[$u['method_group']] ?? $u['method_group']); ?></td>
                    <td class="bk-amount"><?php echo bkMoney((float)$u['gross'], $currency); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
    var kind = document.getElementById('bkKind');
    var code = document.getElementById('bkCode');
    var suggested = <?php echo json_encode($suggestedCodes, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    function syncKind() {
        if (!kind) return;
        var gw = kind.value === 'gateway';
        document.querySelectorAll('.bk-gw').forEach(function (el) { el.style.display = gw ? '' : 'none'; });
        document.querySelectorAll('.bk-bank').forEach(function (el) { el.style.display = gw ? 'none' : ''; });
        if (code && suggested[kind.value]) code.placeholder = suggested[kind.value];
    }
    if (kind) { kind.addEventListener('change', syncKind); syncKind(); }

    var bulkKind = document.getElementById('bkBulkKind');
    function syncBulk() {
        if (!bulkKind) return;
        var v = bulkKind.value;
        var bank = document.getElementById('bkCounterBank');
        var acc = document.getElementById('bkCounterAccount');
        if (bank) bank.style.display = v === 'transfer' ? '' : 'none';
        if (acc) acc.style.display = v === 'other' ? '' : 'none';
    }
    if (bulkKind) { bulkKind.addEventListener('change', syncBulk); syncBulk(); }

    var picks = Array.prototype.slice.call(document.querySelectorAll('.bk-pick'));
    var counter = document.getElementById('bkPicked');
    var btn = document.getElementById('bkBulkBtn');
    function syncPicked() {
        var n = picks.filter(function (p) { return p.checked; }).length;
        if (counter) counter.textContent = n;
        if (btn) btn.disabled = n === 0;
        if (n > 0 && bulkKind) {
            var sugs = picks.filter(function (p) { return p.checked; }).map(function (p) { return p.getAttribute('data-sug') || ''; });
            var first = sugs[0];
            if (first && sugs.every(function (s) { return s === first; })) {
                bulkKind.value = first;
                syncBulk();
            }
        }
    }
    picks.forEach(function (p) { p.addEventListener('change', syncPicked); });
    var all = document.getElementById('bkAll');
    if (all) {
        all.addEventListener('change', function () {
            picks.forEach(function (p) { p.checked = all.checked; });
            syncPicked();
        });
    }
    var bulk = document.getElementById('bnkBulk');
    if (bulk) {
        bulk.addEventListener('submit', function (e) {
            var n = picks.filter(function (p) { return p.checked; }).length;
            var label = bulkKind ? bulkKind.options[bulkKind.selectedIndex].text : '';
            if (!n || !confirm('¿Registrar ' + n + ' movimiento(s) como «' + label + '»? Se creará el asiento contable.')) {
                e.preventDefault();
            }
        });
    }
})();
</script>
