<?php
$layout = 'tenant';
$title = 'Préstamos - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Préstamos de productos';
$loans = $loans ?? [];
$receivedLoans = $receivedLoans ?? [];
$loansTotal = (int)($loansTotal ?? 0);
$movements = $movements ?? ['rows' => [], 'total' => 0];
$tab = ($tab ?? 'loans') === 'traza' ? 'traza' : 'loans';
$filters = $filters ?? ['q' => '', 'type' => '', 'status' => 'pending', 'reason' => '', 'person' => '', 'from' => '', 'to' => ''];
$filterOptions = $filterOptions ?? ['reasons' => [], 'people' => []];
$loanSummary = $loanSummary ?? ['out_count' => 0, 'out_units' => 0, 'in_count' => 0, 'in_units' => 0, 'aged_count' => 0];
$stockAlert = $stockAlert ?? ['count' => 0, 'debts' => [], 'summary_lines' => [], 'negative_count' => 0];
$products = $products ?? [];
$canCreate = $canCreate ?? false;
$canEdit = $canEdit ?? false;
$loanReasonsHtml = $loanReasonsHtml ?? '';
$receivedReasonsHtml = $receivedReasonsHtml ?? '';
$base = $viewInstance->route('app/prestamos');
$searchUrl = $base . '?action=searchProducts';

$statusExplicit = isset($_GET['status']) && (string)$_GET['status'] !== '';
$hasFilters = $statusExplicit;
foreach (['q', 'type', 'reason', 'person', 'from', 'to'] as $fk) {
    if ((string)($filters[$fk] ?? '') !== '') {
        $hasFilters = true;
    }
}
$tabUrl = static function (string $target) use ($filters, $statusExplicit, $base): string {
    $qs = [];
    if ($target === 'traza') {
        $qs['tab'] = 'traza';
    }
    foreach (['q', 'type', 'reason', 'person', 'from', 'to'] as $fk) {
        if ((string)($filters[$fk] ?? '') !== '') {
            $qs[$fk] = $filters[$fk];
        }
    }
    if ($statusExplicit) {
        $qs['status'] = $filters['status'];
    }
    return $base . ($qs ? '?' . http_build_query($qs) : '');
};
$fmtDate = static function ($value, bool $withTime = true): string {
    $ts = strtotime((string)$value);
    if (!$ts) {
        return '—';
    }
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
};
?>
<?php echo flashMessage(); ?>

<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <p style="margin:0;flex:1;min-width:220px;color:var(--color-text-secondary);font-size:13px;">
        <strong>Salida:</strong> nosotros prestamos (no es venta). <strong>Recibido:</strong> nos prestan mercancía
        (entra al inventario como <em>Prestado</em>; no es consignación). Cada préstamo tiene un número
        <strong>PR-</strong> y su historial completo en <strong>Trazabilidad</strong>.
    </p>
    <?php if ($canCreate): ?>
    <button type="button" class="btn btn-primary neumorphic-btn" onclick="openLoanModal()">+ Salida por préstamo</button>
    <button type="button" class="btn btn-secondary neumorphic-btn" onclick="openReceivedModal()">+ Nos prestan</button>
    <?php endif; ?>
    <a href="<?php echo $viewInstance->route('app/inventario'); ?>" class="btn btn-secondary">Ir a Inventario</a>
</div>

<?php if (($stockAlert['count'] ?? 0) > 0): ?>
<div class="alert alert-warning alert-stock-pending" style="margin-bottom:18px;">
    <strong>Atención inventario:</strong>
    <?php echo (int)($stockAlert['negative_count'] ?? 0); ?> producto(s) en stock negativo ·
    <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> préstamo(s) de salida ·
    <?php echo (int)($stockAlert['loan_received_count'] ?? 0); ?> nos prestaron ·
    <?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?> venta(s) sin stock.
    <ul>
        <?php foreach (array_slice($stockAlert['summary_lines'] ?? [], 0, 12) as $line): ?>
            <li><?php echo htmlspecialchars((string)$line); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom:18px;">
    <div class="stat-card neumorphic">
        <h4>Prestamos (pendientes)</h4>
        <div class="stat-value"><?php echo (int)$loanSummary['out_count']; ?></div>
        <small style="color:var(--color-text-secondary);"><?php echo (int)$loanSummary['out_units']; ?> uds por recibir</small>
    </div>
    <div class="stat-card neumorphic">
        <h4>Nos prestaron (pendientes)</h4>
        <div class="stat-value" style="color:var(--dmc-0369A1, #0369A1);"><?php echo (int)$loanSummary['in_count']; ?></div>
        <small style="color:var(--color-text-secondary);"><?php echo (int)$loanSummary['in_units']; ?> uds por devolver</small>
    </div>
    <div class="stat-card neumorphic">
        <h4>Más de <?php echo \SoftNova\Services\LoanTraceService::AGING_DAYS; ?> días abiertos</h4>
        <div class="stat-value" style="color:var(--dmc-B45309, #B45309);"><?php echo (int)$loanSummary['aged_count']; ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Stock negativo</h4>
        <div class="stat-value" style="color:var(--dmc-DC2626, #DC2626);"><?php echo (int)($stockAlert['negative_count'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Ventas sin stock (deuda)</h4>
        <div class="stat-value" style="color:var(--dmc-B45309, #B45309);"><?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?></div>
    </div>
</div>

<form method="GET" action="<?php echo $base; ?>" class="card neumorphic loan-filters" style="margin-bottom:14px;">
    <?php if ($tab === 'traza'): ?><input type="hidden" name="tab" value="traza"><?php endif; ?>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:220px;">
            <label style="font-size:12px;">Buscar</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" class="form-control"
                   placeholder="Producto, SKU, EAN, persona, nota o N.º PR-…">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Tipo</label>
            <select name="type" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <option value="loan" <?php echo $filters['type'] === 'loan' ? 'selected' : ''; ?>>Prestamos (salida)</option>
                <option value="loan_received" <?php echo $filters['type'] === 'loan_received' ? 'selected' : ''; ?>>Nos prestaron</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Estado</label>
            <select name="status" class="form-control" style="width:auto;">
                <option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pendientes</option>
                <option value="partial" <?php echo $filters['status'] === 'partial' ? 'selected' : ''; ?>>Con devolución parcial</option>
                <option value="closed" <?php echo $filters['status'] === 'closed' ? 'selected' : ''; ?>>Cerrados</option>
                <option value="all" <?php echo $filters['status'] === 'all' ? 'selected' : ''; ?>>Todos</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Motivo</label>
            <select name="reason" class="form-control" style="width:auto;max-width:200px;">
                <option value="">Todos</option>
                <?php foreach ($filterOptions['reasons'] as $rv => $rl): ?>
                    <option value="<?php echo htmlspecialchars((string)$rv); ?>" <?php echo $filters['reason'] === (string)$rv ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$rl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;min-width:160px;">
            <label style="font-size:12px;">Persona / empresa</label>
            <input type="text" name="person" list="loanPeopleList" value="<?php echo htmlspecialchars($filters['person']); ?>" class="form-control" placeholder="Todas">
            <datalist id="loanPeopleList">
                <?php foreach ($filterOptions['people'] as $person): ?>
                    <option value="<?php echo htmlspecialchars((string)$person); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;"><?php echo $tab === 'traza' ? 'Movimiento desde' : 'Prestado desde'; ?></label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($filters['from']); ?>" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Hasta</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($filters['to']); ?>" class="form-control">
        </div>
        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <?php if ($hasFilters): ?>
            <a href="<?php echo $base . ($tab === 'traza' ? '?tab=traza' : ''); ?>" class="btn btn-secondary">Limpiar</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="loan-tabs" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
    <a href="<?php echo htmlspecialchars($tabUrl('loans')); ?>" class="btn <?php echo $tab === 'loans' ? 'btn-primary' : 'btn-secondary'; ?>">Préstamos</a>
    <a href="<?php echo htmlspecialchars($tabUrl('traza')); ?>" class="btn <?php echo $tab === 'traza' ? 'btn-primary' : 'btn-secondary'; ?>">Trazabilidad (movimientos)</a>
</div>

<?php if ($tab === 'loans'): ?>
<p style="margin:0 0 10px;font-size:13px;color:var(--color-text-secondary);">
    <?php
    $shown = count($loans) + count($receivedLoans);
    echo $shown . ' préstamo(s)';
    if ($loansTotal > $shown) {
        echo ' de ' . $loansTotal . ' (afine el filtro para ver el resto)';
    }
    ?>
    · Use <strong>Historial</strong> para ver cada salida, ingreso y devolución del préstamo.
</p>
<?php
$loanSections = [
    [
        'source' => 'loan',
        'id' => 'prestamos-salida',
        'title' => 'Préstamos de salida (nosotros prestamos)',
        'rows' => $loans,
        'person' => 'A quién',
        'empty' => 'No hay préstamos de salida con este filtro.',
    ],
    [
        'source' => 'loan_received',
        'id' => 'prestamos-recibidos',
        'title' => 'Préstamos recibidos (nos prestaron)',
        'rows' => $receivedLoans,
        'person' => 'Quién nos prestó',
        'empty' => 'No hay préstamos recibidos con este filtro.',
    ],
];
foreach ($loanSections as $section):
    if ($filters['type'] !== '' && $filters['type'] !== $section['source']) {
        continue;
    }
?>
<div class="card neumorphic" id="<?php echo $section['id']; ?>" style="margin-bottom:18px;">
    <div class="card-header"><h3><?php echo htmlspecialchars($section['title']); ?> <span style="font-weight:400;font-size:13px;color:var(--color-text-secondary);">(<?php echo count($section['rows']); ?>)</span></h3></div>
    <div class="card-body">
        <?php if (empty($section['rows'])): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;"><?php echo htmlspecialchars($section['empty']); ?></p>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>N.º / Fecha</th>
                        <th>Producto</th>
                        <th style="min-width:150px;">Avance</th>
                        <th>Estado</th>
                        <th>Motivo</th>
                        <th><?php echo htmlspecialchars($section['person']); ?></th>
                        <th>Nota</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($section['rows'] as $loan):
                    $owed = (int)$loan['qty_owed'];
                    $pct = $owed > 0 ? min(100, (int)round(((int)$loan['qty_returned'] / $owed) * 100)) : 0;
                    $prodName = (string)($loan['product_name'] ?? '');
                ?>
                    <tr>
                        <td style="white-space:nowrap;">
                            <a href="javascript:void(0)" onclick="openLoanHistory(<?php echo (int)$loan['id']; ?>)" style="font-weight:700;"><?php echo htmlspecialchars((string)$loan['code']); ?></a>
                            <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo $fmtDate($loan['created_at'] ?? ''); ?></div>
                            <?php if (!empty($loan['created_by_name'])): ?>
                                <div style="font-size:11px;color:var(--color-text-secondary);">por <?php echo htmlspecialchars((string)$loan['created_by_name']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($prodName); ?></strong>
                            <?php if (!empty($loan['product_code'])): ?>
                                <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$loan['product_code']); ?></div>
                            <?php endif; ?>
                            <div style="font-size:12px;">Stock actual:
                                <strong style="color:<?php echo ((int)($loan['product_stock'] ?? 0) < 0) ? '#DC2626' : 'inherit'; ?>;"><?php echo (int)($loan['product_stock'] ?? 0); ?></strong>
                            </div>
                        </td>
                        <td>
                            <div style="font-size:12px;margin-bottom:4px;">
                                Prestado <strong><?php echo $owed; ?></strong> ·
                                Devuelto <strong><?php echo (int)$loan['qty_returned']; ?></strong> ·
                                Pendiente <strong><?php echo (int)$loan['qty_remaining']; ?></strong>
                            </div>
                            <div class="loan-progress" title="<?php echo $pct; ?>% devuelto"><span style="width:<?php echo $pct; ?>%;"></span></div>
                        </td>
                        <td style="white-space:nowrap;">
                            <span class="badge badge-<?php echo htmlspecialchars((string)$loan['status_badge']); ?>"><?php echo htmlspecialchars((string)$loan['status_label']); ?></span>
                            <div style="font-size:12px;margin-top:4px;color:<?php echo !empty($loan['is_aged']) ? 'var(--dmc-DC2626, #DC2626)' : 'var(--color-text-secondary)'; ?>;">
                                <?php
                                $days = (int)$loan['days_open'];
                                $daysTxt = $days === 0 ? 'hoy' : ($days === 1 ? '1 día' : $days . ' días');
                                echo !empty($loan['is_open']) ? ('Abierto ' . $daysTxt) : ('Cerrado en ' . $daysTxt);
                                ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars((string)($loan['reason_label'] ?? $loan['reason'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($loan['borrower'] ?? '') !== '' ? (string)$loan['borrower'] : '—'); ?></td>
                        <td style="max-width:220px;font-size:13px;"><?php echo htmlspecialchars((string)($loan['note'] ?? '')); ?></td>
                        <td class="table-actions" style="white-space:nowrap;">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openLoanHistory(<?php echo (int)$loan['id']; ?>)">Historial</button>
                            <?php if ($canEdit && !empty($loan['is_open'])): ?>
                                <?php if ($section['source'] === 'loan'): ?>
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="openReturnModal(<?php echo (int)$loan['id']; ?>, <?php echo (int)$loan['qty_remaining']; ?>, <?php echo htmlspecialchars(json_encode($prodName, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo (int)($loan['product_id'] ?? 0); ?>)">
                                    Devolver
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-success"
                                    onclick="openReturnReceivedModal(<?php echo (int)$loan['id']; ?>, <?php echo (int)$loan['qty_remaining']; ?>, <?php echo (int)($loan['product_stock'] ?? 0); ?>, <?php echo htmlspecialchars(json_encode($prodName, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                                    Devolver
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="card neumorphic" id="prestamos-trazabilidad" style="margin-bottom:18px;">
    <div class="card-header">
        <h3>Trazabilidad de préstamos
            <span style="font-weight:400;font-size:13px;color:var(--color-text-secondary);">
                (<?php echo count($movements['rows']); ?><?php echo (int)$movements['total'] > count($movements['rows']) ? ' de ' . (int)$movements['total'] : ''; ?> movimientos)
            </span>
        </h3>
    </div>
    <div class="card-body">
        <p style="margin:0 0 10px;font-size:13px;color:var(--color-text-secondary);">
            Cada fila es un movimiento real de inventario: la salida o ingreso del préstamo, cada devolución
            (incluso con otro producto) y los cierres sin devolución física. Pulse el N.º PR- para ver el expediente completo.
        </p>
        <?php if (empty($movements['rows'])): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">No hay movimientos de préstamos con este filtro.</p>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Préstamo</th>
                        <th>Movimiento</th>
                        <th>Producto</th>
                        <th>Cantidad</th>
                        <th>Stock después</th>
                        <th>Persona / empresa</th>
                        <th>Usuario</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($movements['rows'] as $mv):
                    $notes = (string)($mv['notes'] ?? '');
                ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo $fmtDate($mv['created_at'] ?? ''); ?></td>
                        <td style="white-space:nowrap;">
                            <a href="javascript:void(0)" onclick="openLoanHistory(<?php echo (int)$mv['debt_id']; ?>)" style="font-weight:700;"><?php echo htmlspecialchars((string)$mv['loan_code']); ?></a>
                            <div style="font-size:11px;color:var(--color-text-secondary);"><?php echo $mv['source'] === 'loan_received' ? 'Nos prestaron' : 'Prestamos'; ?></div>
                        </td>
                        <td><span class="badge badge-<?php echo htmlspecialchars((string)$mv['movement_badge']); ?>"><?php echo htmlspecialchars((string)$mv['movement_label']); ?></span></td>
                        <td>
                            <strong><?php echo htmlspecialchars((string)($mv['product_name'] ?? '')); ?></strong>
                            <?php if (!empty($mv['product_code'])): ?>
                                <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$mv['product_code']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($mv['substitution'])): ?>
                                <div style="font-size:12px;color:var(--dmc-B45309, #B45309);">En lugar de: <?php echo htmlspecialchars((string)($mv['loan_product_name'] ?? '')); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;white-space:nowrap;"><?php echo htmlspecialchars((string)$mv['qty_signed']); ?></td>
                        <td><?php echo $mv['stock_after'] !== null ? (int)$mv['stock_after'] : '—'; ?></td>
                        <td><?php echo htmlspecialchars((string)($mv['borrower'] ?? '') !== '' ? (string)$mv['borrower'] : '—'); ?></td>
                        <td><?php echo htmlspecialchars((string)($mv['user_name'] ?? '') !== '' ? (string)$mv['user_name'] : '—'); ?></td>
                        <td style="max-width:280px;font-size:12px;color:var(--color-text-secondary);" title="<?php echo htmlspecialchars($notes); ?>">
                            <?php echo htmlspecialchars(mb_strlen($notes) > 120 ? mb_substr($notes, 0, 120) . '…' : $notes); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div id="loanHistoryModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:760px;">
        <div class="modal-header">
            <h3 id="loanHistoryTitle">Historial del préstamo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('loanHistoryModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" id="loanHistoryBody"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('loanHistoryModal').style.display='none'">Cerrar</button>
        </div>
    </div>
</div>

<style>
.loan-progress { height: 6px; background: var(--color-border, #e2e8f0); border-radius: 4px; overflow: hidden; }
.loan-progress > span { display: block; height: 100%; background: #16A34A; }
.loan-timeline { list-style: none; margin: 0; padding: 0; }
.loan-timeline li { position: relative; padding: 0 0 14px 22px; border-left: 2px solid var(--color-border, #e2e8f0); margin-left: 6px; }
.loan-timeline li:last-child { border-left-color: transparent; }
.loan-timeline li::before { content: ''; position: absolute; left: -7px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: var(--color-primary, #0369A1); }
.loan-timeline li.tl-out::before { background: #D97706; }
.loan-timeline li.tl-in::before { background: #16A34A; }
.loan-timeline li.tl-settle::before { background: #64748B; }
.loan-history-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px 16px; margin-bottom: 14px; font-size: 13px; }
.loan-history-grid span { display: block; font-size: 11px; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: .03em; }
</style>

<script>
window.PRESTAMOS_HISTORY_URL = <?php echo json_encode($base . '?action=history&id='); ?>;

function loanEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function loanFmtDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(v || ''));
    return m ? (m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5]) : '—';
}

function openLoanHistory(id) {
    var modal = document.getElementById('loanHistoryModal');
    var body = document.getElementById('loanHistoryBody');
    var title = document.getElementById('loanHistoryTitle');
    if (!modal || !body) return;
    title.textContent = 'Historial del préstamo';
    body.innerHTML = '<p style="text-align:center;padding:20px;color:var(--color-text-secondary);">Cargando…</p>';
    modal.style.display = 'flex';
    fetch(window.PRESTAMOS_HISTORY_URL + encodeURIComponent(id), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success || !d.loan) {
            body.innerHTML = '<p style="color:#DC2626;">' + loanEsc((d && d.message) || 'No se pudo cargar el historial') + '</p>';
            return;
        }
        var l = d.loan;
        var received = l.source === 'loan_received';
        title.textContent = l.code + ' · ' + (received ? 'Nos prestaron' : 'Prestamos');
        var owed = parseInt(l.qty_owed, 10) || 0;
        var ret = parseInt(l.qty_returned, 10) || 0;
        var pct = owed > 0 ? Math.min(100, Math.round(ret / owed * 100)) : 0;
        var html = '<div class="loan-history-grid">'
            + '<div><span>Producto</span><strong>' + loanEsc(l.product_name) + '</strong>'
            + (l.product_code ? '<div style="font-size:12px;color:var(--color-text-secondary);">' + loanEsc(l.product_code) + '</div>' : '') + '</div>'
            + '<div><span>' + (received ? 'Quién nos prestó' : 'A quién') + '</span>' + loanEsc(l.borrower || '—') + '</div>'
            + '<div><span>Motivo</span>' + loanEsc(l.reason_label || l.reason || '—') + '</div>'
            + '<div><span>Fecha del préstamo</span>' + loanFmtDate(l.created_at)
            + (l.created_by_name ? '<div style="font-size:12px;color:var(--color-text-secondary);">por ' + loanEsc(l.created_by_name) + '</div>' : '') + '</div>'
            + '<div><span>Estado</span><span class="badge badge-' + loanEsc(l.status_badge) + '" style="display:inline-block;text-transform:none;font-size:12px;letter-spacing:0;">' + loanEsc(l.status_label) + '</span></div>'
            + '<div><span>Stock actual del producto</span>' + loanEsc(l.product_stock) + '</div>'
            + '</div>';
        if (l.note) {
            html += '<p style="font-size:13px;margin:0 0 12px;"><strong>Nota:</strong> ' + loanEsc(l.note) + '</p>';
        }
        html += '<div style="font-size:13px;margin-bottom:4px;">Prestado <strong>' + owed + '</strong> · Devuelto <strong>' + ret
            + '</strong> · Pendiente <strong>' + loanEsc(l.qty_remaining) + '</strong></div>'
            + '<div class="loan-progress" style="margin-bottom:16px;"><span style="width:' + pct + '%;"></span></div>';

        var movs = d.movements || [];
        if (!movs.length) {
            html += '<p style="color:var(--color-text-secondary);">Sin movimientos de inventario registrados para este préstamo (registro antiguo).</p>';
        } else {
            html += '<h4 style="margin:0 0 10px;">Línea de tiempo</h4><ul class="loan-timeline">';
            movs.forEach(function (m) {
                var ref = String(m.reference_type || '');
                var cls = ref === 'loan_received_settle' ? 'tl-settle'
                    : ((ref === 'loan' || ref === 'loan_received_return') ? 'tl-out' : 'tl-in');
                html += '<li class="' + cls + '">'
                    + '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
                    + '<strong>' + loanEsc(m.movement_label) + ' · ' + loanEsc(m.qty_signed) + '</strong>'
                    + '<span style="font-size:12px;color:var(--color-text-secondary);">' + loanFmtDate(m.created_at)
                    + (m.user_name ? ' · ' + loanEsc(m.user_name) : '') + '</span></div>'
                    + '<div style="font-size:13px;">' + loanEsc(m.product_name || '')
                    + (m.product_code ? ' <span style="color:var(--color-text-secondary);">(' + loanEsc(m.product_code) + ')</span>' : '')
                    + (m.stock_after != null ? ' · stock después: <strong>' + loanEsc(m.stock_after) + '</strong>' : '')
                    + '</div>'
                    + (m.substitution ? '<div style="font-size:12px;color:var(--dmc-B45309, #B45309);">Devolvieron otro producto en lugar de ' + loanEsc(m.loan_product_name || '') + '</div>' : '')
                    + (m.notes ? '<div style="font-size:12px;color:var(--color-text-secondary);margin-top:2px;">' + loanEsc(m.notes) + '</div>' : '')
                    + '</li>';
            });
            html += '</ul>';
        }
        body.innerHTML = html;
    })
    .catch(function () {
        body.innerHTML = '<p style="color:#DC2626;">Error de conexión al cargar el historial.</p>';
    });
}
</script>

<?php if ($canCreate || $canEdit): ?>
<?php if ($canCreate): ?>
<div id="loanModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Salida por préstamo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('loanModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=loan" data-ajax="true" id="loanForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Producto *</label>
                    <input type="hidden" name="product_id" id="loanProductId" required>
                    <input type="text" id="loanProductSearch" class="form-control" autocomplete="off"
                           placeholder="Buscar nombre, SKU o escanear código…"
                           data-barcode-input="true" data-product-picker="loan">
                    <div id="loanProductResults" class="product-picker-results" style="display:none;"></div>
                    <div id="loanProductSelected" class="product-picker-selected" style="display:none;"></div>
                    <small id="loanStockHint" style="color:var(--color-text-secondary);"></small>
                </div>
                <div class="form-group">
                    <label>Cantidad *</label>
                    <input type="number" name="quantity" id="loanQty" class="form-control" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Motivo *</label>
                    <select name="reason" id="loanReason" class="form-control" required>
                        <?php echo $loanReasonsHtml; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>A quién se presta *</label>
                    <input type="text" name="borrower" id="loanBorrower" class="form-control" maxlength="120" placeholder="Nombre de la persona o empresa" required>
                </div>
                <div class="form-group" id="loanNoteGroup">
                    <label>Nota / detalle <span id="loanNoteReq" style="display:none;">*</span></label>
                    <input type="text" name="note" id="loanNote" class="form-control" maxlength="180" placeholder="Detalle adicional">
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                    Si no hay stock suficiente, el inventario quedará en negativo y el préstamo seguirá pendiente hasta la devolución.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('loanModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar salida</button>
            </div>
        </form>
    </div>
</div>

<div id="receivedModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:560px;">
        <div class="modal-header">
            <h3>Ingresar producto que nos prestan</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('receivedModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=loan_received" data-ajax="true" id="receivedForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <p style="font-size:12px;color:var(--color-text-secondary);margin-top:0;">
                    No es consignación. El producto entra al inventario marcado como <strong>Prestado</strong>
                    y queda pendiente de devolución en este módulo (con trazabilidad).
                </p>
                <div class="form-group">
                    <label>Modo *</label>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                            <input type="radio" name="recv_mode" id="recvModeNew" value="new" checked onchange="toggleRecvMode()">
                            Producto nuevo o desde inventario
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                            <input type="radio" name="recv_mode" id="recvModeExist" value="existing" onchange="toggleRecvMode()">
                            Agregar unidades a un producto Prestado existente
                        </label>
                    </div>
                </div>
                <div id="recvNewWrap">
                    <div class="form-group">
                        <label>Nombre del producto *</label>
                        <input type="text" name="new_name" id="recvNewName" class="form-control" maxlength="180"
                               autocomplete="off" data-barcode-input="true"
                               placeholder="Buscar en inventario o escribir nombre nuevo…">
                        <div id="recvNewResults" class="product-picker-results" style="display:none;"></div>
                        <div id="recvNewSelected" class="product-picker-selected" style="display:none;"></div>
                        <small style="color:var(--color-text-secondary);">
                            Puede buscar un producto ya cargado en inventario (nombre, SKU o EAN).
                            Si no existe, escriba el nombre y se dará de alta como Prestado.
                        </small>
                    </div>
                    <div id="recvNewManualFields">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label>SKU (opcional)</label>
                                <input type="text" name="new_code" id="recvNewCode" class="form-control" maxlength="64" placeholder="Automático si vacío">
                            </div>
                            <div class="form-group">
                                <label>EAN (opcional)</label>
                                <input type="text" name="new_barcode" id="recvNewBarcode" class="form-control" maxlength="64" data-barcode-input="true">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Costo referencial (opcional)</label>
                            <input type="text" name="unit_cost" id="recvUnitCost" class="form-control" inputmode="decimal" placeholder="0">
                            <small style="color:var(--color-text-secondary);">No capitaliza inventario contable; solo referencia.</small>
                        </div>
                    </div>
                </div>
                <div id="recvExistWrap" style="display:none;">
                    <div class="form-group">
                        <label>Producto Prestado *</label>
                        <input type="text" id="recvProductSearch" class="form-control" autocomplete="off"
                               placeholder="Buscar producto Prestado…"
                               data-barcode-input="true">
                        <div id="recvProductResults" class="product-picker-results" style="display:none;"></div>
                        <div id="recvProductSelected" class="product-picker-selected" style="display:none;"></div>
                    </div>
                </div>
                <input type="hidden" name="product_id" id="recvProductId" value="">
                <div class="form-group">
                    <label>Cantidad *</label>
                    <input type="number" name="quantity" id="recvQty" class="form-control" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Motivo *</label>
                    <select name="reason" id="recvReason" class="form-control" required data-custom-other="loan_received_reason">
                        <?php echo $receivedReasonsHtml; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quién nos presta *</label>
                    <input type="text" name="lender" id="recvLender" class="form-control" maxlength="120" placeholder="Persona o empresa" required>
                </div>
                <div class="form-group">
                    <label>Nota / detalle</label>
                    <input type="text" name="note" id="recvNote" class="form-control" maxlength="180" placeholder="Detalle adicional (opcional)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('receivedModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar ingreso</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div id="returnModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:640px;">
        <div class="modal-header">
            <h3>Devolver préstamo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('returnModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=return" data-ajax="true" id="returnLoanForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="debt_id" id="returnDebtId">
            <input type="hidden" name="original_product_id" id="returnOriginalProductId" value="">
            <div class="modal-body">
                <p id="returnProductName" style="font-weight:600;margin-top:0;"></p>
                <div class="form-group" id="returnQtyWrap">
                    <label>Cantidad a devolver *</label>
                    <input type="number" name="quantity" id="returnQty" class="form-control" min="1" required>
                    <small style="color:var(--color-text-secondary);">Máximo pendiente: <span id="returnMax">0</span></small>
                </div>
                <div class="form-group">
                    <label>¿Qué producto está devolviendo? *</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                            <input type="radio" name="return_mode" id="returnModeSame" value="same" checked>
                            El mismo producto prestado
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                            <input type="radio" name="return_mode" id="returnModeDiff" value="different">
                            Otro(s) producto(s) diferente(s)
                        </label>
                    </div>
                </div>
                <div class="form-group" id="returnAltProductWrap" style="display:none;">
                    <label>Productos que entrega *</label>
                    <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">
                        Puede devolver varias referencias. La suma de cantidades debe igualar lo pendiente
                        (<span id="returnLinesNeed">0</span>). El stock se suma a cada producto y se cierra el préstamo del original.
                    </p>
                    <div id="returnLinesList" style="display:flex;flex-direction:column;gap:10px;"></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="addReturnLine()">+ Agregar producto</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="openCreateReturnProduct()">+ Crear producto nuevo</button>
                    </div>
                    <p id="returnLinesSumHint" style="margin:8px 0 0;font-size:13px;font-weight:600;color:var(--dmc-0369A1, #0369A1);"></p>
                    <div id="returnCreateProductBox" style="display:none;margin-top:12px;padding:12px;border:1px dashed var(--color-border);border-radius:8px;background:var(--bg-input,#f8fafc);">
                        <strong style="display:block;margin-bottom:8px;">Alta rápida de producto</strong>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label>Nombre *</label>
                            <input type="text" id="returnNewName" class="form-control" maxlength="255" placeholder="Nombre del producto">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="form-group" style="margin:0;">
                                <label>SKU (opcional)</label>
                                <input type="text" id="returnNewCode" class="form-control" maxlength="120" placeholder="Auto si vacío">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Código barras</label>
                                <input type="text" id="returnNewBarcode" class="form-control" maxlength="120" placeholder="EAN / barras">
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:10px;">
                            <button type="button" class="btn btn-sm btn-primary" onclick="submitCreateReturnProduct()">Crear y agregar</button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="closeCreateReturnProduct()">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('returnModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Confirmar devolución</button>
            </div>
        </form>
    </div>
</div>

<div id="returnReceivedModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Devolver préstamo recibido</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('returnReceivedModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $base; ?>?action=return_received" data-ajax="true" id="returnReceivedForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="debt_id" id="returnRecvDebtId">
            <div class="modal-body">
                <p id="returnRecvProductName" style="font-weight:600;margin-top:0;"></p>
                <div class="form-group">
                    <label>Cantidad a devolver *</label>
                    <input type="number" name="quantity" id="returnRecvQty" class="form-control" min="1" required>
                    <small style="color:var(--color-text-secondary);">
                        Máximo pendiente: <span id="returnRecvMax">0</span>
                        · Stock actual: <span id="returnRecvStock">0</span>
                    </small>
                    <p id="returnRecvPreview" style="margin:8px 0 0;font-size:13px;font-weight:600;color:var(--dmc-0369A1, #0369A1);"></p>
                </div>
                <div id="returnRecvSettleWrap" style="display:none;margin-bottom:12px;padding:10px 12px;border-radius:8px;background:var(--dmb-FEF3C7, #FEF3C7);border:1px solid #F59E0B;color:var(--dmc-92400E, #92400E);font-size:13px;">
                    <p style="margin:0 0 8px;" id="returnRecvSettleText"></p>
                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;">
                        <input type="checkbox" name="settle_without_stock" id="returnRecvSettle" value="1" style="width:auto;margin-top:2px;">
                        Cerrar sin devolución física (ya se vendió / usó lo prestado)
                    </label>
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                    Si hay stock: sale del inventario Prestado y cierra la deuda.
                    Si ya vendió lo prestado (stock 0): marque la casilla de liquidación para cerrar la deuda sin sacar unidades.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('returnReceivedModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Confirmar devolución</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.product-picker-results {
    margin-top: 6px;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    max-height: 220px;
    overflow: auto;
    background: var(--bg-card, #fff);
}
.product-picker-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);
    font-size: 13px;
}
.product-picker-row:last-child { border-bottom: 0; }
.product-picker-row:hover { background: var(--bg-input, #f8fafc); }
.product-picker-selected {
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--bg-input, #f1f5f9);
    font-size: 13px;
}
</style>

<script>
window.PRESTAMOS_SEARCH_URL = <?php echo json_encode($searchUrl); ?>;
window.PRESTAMOS_CREATE_URL = <?php echo json_encode($base . '?action=createReturnProduct'); ?>;
window.PRESTAMOS_PRODUCTS_SEED = <?php echo json_encode(array_values(array_map(static function ($p) {
    return [
        'id' => (int)$p['id'],
        'code' => (string)($p['code'] ?? ''),
        'name' => (string)($p['name'] ?? ''),
        'stock' => (int)($p['stock'] ?? 0),
        'barcode' => (string)($p['barcode'] ?? ''),
    ];
}, $products)), JSON_UNESCAPED_UNICODE); ?>;

var returnLineSeq = 0;

function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function productLabel(p) {
    return ((p.code ? p.code + ' · ' : '') + (p.name || '')).trim();
}

function setLoanProduct(p) {
    var hid = document.getElementById('loanProductId');
    var sel = document.getElementById('loanProductSelected');
    var search = document.getElementById('loanProductSearch');
    var results = document.getElementById('loanProductResults');
    if (!hid || !p) return;
    hid.value = String(p.id);
    hid.dataset.stock = String(p.stock != null ? p.stock : 0);
    if (sel) {
        sel.style.display = '';
        sel.innerHTML = '<strong>' + escHtml(productLabel(p)) + '</strong>'
            + ' <span style="color:var(--color-text-secondary);">· stock ' + escHtml(p.stock) + '</span>'
            + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearLoanProduct()">Cambiar</button>';
    }
    if (search) { search.value = ''; search.style.display = 'none'; }
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    updateLoanStockHint();
}

function clearLoanProduct() {
    var hid = document.getElementById('loanProductId');
    var sel = document.getElementById('loanProductSelected');
    var search = document.getElementById('loanProductSearch');
    var results = document.getElementById('loanProductResults');
    if (hid) { hid.value = ''; hid.dataset.stock = '0'; }
    if (sel) { sel.style.display = 'none'; sel.innerHTML = ''; }
    if (search) { search.style.display = ''; search.focus(); }
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
    updateLoanStockHint();
}

function getReturnNeedQty() {
    var maxEl = document.getElementById('returnMax');
    var qtyEl = document.getElementById('returnQty');
    var max = parseInt(maxEl ? maxEl.textContent : '0', 10) || 0;
    var qty = parseInt(qtyEl ? qtyEl.value : '0', 10) || 0;
    if (qty <= 0) qty = max;
    return Math.min(max, Math.max(0, qty));
}

function updateReturnLinesHint() {
    var need = getReturnNeedQty();
    var needEl = document.getElementById('returnLinesNeed');
    if (needEl) needEl.textContent = String(need);
    var sum = 0;
    document.querySelectorAll('#returnLinesList .return-line-qty').forEach(function (inp) {
        sum += parseInt(inp.value || '0', 10) || 0;
    });
    var hint = document.getElementById('returnLinesSumHint');
    if (!hint) return;
    var rem = need - sum;
    if (sum === need && need > 0) {
        hint.style.color = 'var(--dmc-15803D, #15803D)';
        hint.textContent = 'OK: ' + sum + ' / ' + need + ' uds asignadas.';
    } else {
        hint.style.color = rem < 0 ? 'var(--dmc-DC2626, #DC2626)' : 'var(--dmc-B45309, #B45309)';
        hint.textContent = 'Asignadas ' + sum + ' / ' + need
            + (rem > 0 ? (' · faltan ' + rem) : (rem < 0 ? (' · sobran ' + Math.abs(rem)) : ''));
    }
}

function addReturnLine(preselect) {
    var list = document.getElementById('returnLinesList');
    if (!list) return;
    returnLineSeq += 1;
    var rowId = 'rl_' + returnLineSeq;
    var row = document.createElement('div');
    row.className = 'return-line-row';
    row.dataset.rowId = rowId;
    row.style.cssText = 'border:1px solid var(--color-border);border-radius:8px;padding:10px;background:var(--bg-card,#fff);';
    row.innerHTML =
        '<div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">'
        + '<div style="width:88px;flex-shrink:0;">'
        + '<label style="font-size:12px;">Cant.</label>'
        + '<input type="number" class="form-control return-line-qty" name="return_line_qty[]" min="1" value="1" required>'
        + '</div>'
        + '<div style="flex:1;min-width:200px;">'
        + '<label style="font-size:12px;">Producto</label>'
        + '<input type="hidden" class="return-line-product-id" name="return_line_product_id[]" value="">'
        + '<input type="text" class="form-control return-line-search" autocomplete="off" placeholder="Buscar o escanear…">'
        + '<div class="product-picker-results return-line-results" style="display:none;"></div>'
        + '<div class="product-picker-selected return-line-selected" style="display:none;"></div>'
        + '</div>'
        + '<button type="button" class="btn btn-sm btn-danger" style="margin-top:22px;" title="Quitar">×</button>'
        + '</div>';
    list.appendChild(row);

    var qtyInp = row.querySelector('.return-line-qty');
    var search = row.querySelector('.return-line-search');
    var results = row.querySelector('.return-line-results');
    var selected = row.querySelector('.return-line-selected');
    var hid = row.querySelector('.return-line-product-id');
    var removeBtn = row.querySelector('.btn-danger');

    function pick(p) {
        if (!p || !hid) return;
        var orig = (document.getElementById('returnOriginalProductId') || {}).value || '';
        if (String(p.id) === String(orig)) {
            if (typeof showAlert === 'function') {
                showAlert('Elija un producto distinto al prestado, o use «El mismo producto prestado».', 'error');
            }
            return;
        }
        hid.value = String(p.id);
        if (selected) {
            selected.style.display = '';
            selected.innerHTML = '<strong>' + escHtml(productLabel(p)) + '</strong>'
                + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;">Cambiar</button>';
            var ch = selected.querySelector('button');
            if (ch) {
                ch.addEventListener('click', function () {
                    hid.value = '';
                    selected.style.display = 'none';
                    selected.innerHTML = '';
                    if (search) { search.style.display = ''; search.value = ''; search.focus(); }
                });
            }
        }
        if (search) { search.value = ''; search.style.display = 'none'; }
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
        updateReturnLinesHint();
    }

    var timer = null;
    function runSearch(autoExact) {
        var q = (search.value || '').trim();
        if (q.length < 1) {
            if (results) { results.style.display = 'none'; results.innerHTML = ''; }
            return;
        }
        searchProductsRemote(q, function (list) {
            var orig = (document.getElementById('returnOriginalProductId') || {}).value || '';
            list = (list || []).filter(function (p) { return String(p.id) !== String(orig); });
            if (autoExact && list.length) {
                var ql = q.toLowerCase();
                var exact = list.find(function (p) {
                    return String(p.code || '').toLowerCase() === ql
                        || String(p.barcode || '').toLowerCase() === ql;
                }) || (list.length === 1 ? list[0] : null);
                if (exact) {
                    pick(exact);
                    return;
                }
            }
            if (!results) return;
            if (!list.length) {
                results.style.display = '';
                results.innerHTML = '<div class="product-picker-row" style="cursor:default;color:var(--color-text-secondary);">Sin coincidencias</div>';
                return;
            }
            results.style.display = '';
            results.innerHTML = '';
            list.forEach(function (p) {
                var r = document.createElement('div');
                r.className = 'product-picker-row';
                r.innerHTML = '<span>' + escHtml(productLabel(p)) + '</span>'
                    + '<span style="color:var(--color-text-secondary);">stock ' + escHtml(p.stock) + '</span>';
                r.addEventListener('click', function () { pick(p); });
                results.appendChild(r);
            });
        }, 'owned');
    }

    if (search) {
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { runSearch(false); }, 220);
        });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                runSearch(true);
            }
        });
    }
    if (qtyInp) qtyInp.addEventListener('input', updateReturnLinesHint);
    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            row.remove();
            updateReturnLinesHint();
            if (!document.querySelectorAll('#returnLinesList .return-line-row').length) {
                addReturnLine();
            }
        });
    }

    if (preselect) {
        pick(preselect);
    }
    updateReturnLinesHint();
    return { pick: pick, row: row };
}

function clearReturnLines() {
    var list = document.getElementById('returnLinesList');
    if (list) list.innerHTML = '';
    closeCreateReturnProduct();
}

function openCreateReturnProduct() {
    var box = document.getElementById('returnCreateProductBox');
    if (!box) return;
    box.style.display = '';
    var nm = document.getElementById('returnNewName');
    if (nm) setTimeout(function () { nm.focus(); }, 40);
}

function closeCreateReturnProduct() {
    var box = document.getElementById('returnCreateProductBox');
    if (box) box.style.display = 'none';
    ['returnNewName', 'returnNewCode', 'returnNewBarcode'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
}

function submitCreateReturnProduct() {
    var name = String((document.getElementById('returnNewName') || {}).value || '').trim();
    if (!name) {
        if (typeof showAlert === 'function') showAlert('Indique el nombre del producto', 'error');
        return;
    }
    var fd = new FormData();
    fd.append('name', name);
    fd.append('code', String((document.getElementById('returnNewCode') || {}).value || '').trim());
    fd.append('barcode', String((document.getElementById('returnNewBarcode') || {}).value || '').trim());
    if (window.SeriCsrf && SeriCsrf.get) {
        fd.append('_token', SeriCsrf.get());
        fd.append('csrf_token', SeriCsrf.get());
    }
    fetch(window.PRESTAMOS_CREATE_URL || '', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': (window.SeriCsrf && SeriCsrf.get) ? SeriCsrf.get() : ''
        }
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d || !d.success || !d.product) {
            if (typeof showAlert === 'function') showAlert((d && d.message) || 'No se pudo crear el producto', 'error');
            return;
        }
        if (d.csrf_token && window.SeriCsrf) SeriCsrf.apply(d.csrf_token);
        var empty = null;
        document.querySelectorAll('#returnLinesList .return-line-row').forEach(function (row) {
            var hid = row.querySelector('.return-line-product-id');
            if (hid && !hid.value && !empty) empty = row;
        });
        var api;
        if (empty) {
            // Reuse empty row: trigger pick via temporary handler
            var search = empty.querySelector('.return-line-search');
            var selected = empty.querySelector('.return-line-selected');
            var hid = empty.querySelector('.return-line-product-id');
            var results = empty.querySelector('.return-line-results');
            hid.value = String(d.product.id);
            if (selected) {
                selected.style.display = '';
                selected.innerHTML = '<strong>' + escHtml(productLabel(d.product)) + '</strong>'
                    + ' <span class="badge badge-info">Nuevo</span>'
                    + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;">Cambiar</button>';
                var ch = selected.querySelector('button');
                if (ch) {
                    ch.addEventListener('click', function () {
                        hid.value = '';
                        selected.style.display = 'none';
                        selected.innerHTML = '';
                        if (search) { search.style.display = ''; search.focus(); }
                    });
                }
            }
            if (search) search.style.display = 'none';
            if (results) { results.style.display = 'none'; results.innerHTML = ''; }
            updateReturnLinesHint();
        } else {
            api = addReturnLine(d.product);
        }
        closeCreateReturnProduct();
        if (typeof showAlert === 'function') showAlert('Producto creado: ' + productLabel(d.product), 'success');
    })
    .catch(function () {
        if (typeof showAlert === 'function') showAlert('Error al crear el producto', 'error');
    });
}

function toggleReturnMode() {
    var diff = document.getElementById('returnModeDiff');
    var wrap = document.getElementById('returnAltProductWrap');
    var qtyWrap = document.getElementById('returnQtyWrap');
    var qtyEl = document.getElementById('returnQty');
    var show = !!(diff && diff.checked);
    if (wrap) wrap.style.display = show ? '' : 'none';
    if (show) {
        clearReturnLines();
        addReturnLine();
        updateReturnLinesHint();
        if (qtyEl) qtyEl.addEventListener('input', updateReturnLinesHint);
    } else {
        clearReturnLines();
    }
}

function openReturnModal(id, maxQty, name, originalProductId) {
    document.getElementById('returnDebtId').value = id;
    document.getElementById('returnMax').textContent = maxQty;
    document.getElementById('returnQty').value = maxQty;
    document.getElementById('returnQty').max = maxQty;
    document.getElementById('returnProductName').textContent = 'Prestado: ' + (name || 'Producto');
    document.getElementById('returnOriginalProductId').value = originalProductId || '';
    var same = document.getElementById('returnModeSame');
    if (same) same.checked = true;
    clearReturnLines();
    toggleReturnMode();
    document.getElementById('returnModal').style.display = 'flex';
}

function renderPickerResults(containerId, list, onPick, allowCreateName) {
    var box = document.getElementById(containerId);
    if (!box) return;
    var qInput = allowCreateName ? document.getElementById(allowCreateName) : null;
    var typed = qInput ? String(qInput.value || '').trim() : '';
    if (!list || !list.length) {
        box.style.display = '';
        var empty = '<div class="product-picker-row" style="cursor:default;color:var(--color-text-secondary);">Sin coincidencias en inventario</div>';
        if (typed) {
            empty += '<div class="product-picker-row" id="' + containerId + '_create">'
                + '<span>Usar nombre nuevo: <strong>' + escHtml(typed) + '</strong></span>'
                + '<span style="color:var(--color-accent-text, var(--color-primary));">Crear Prestado</span></div>';
        }
        box.innerHTML = empty;
        var createRow = document.getElementById(containerId + '_create');
        if (createRow) {
            createRow.addEventListener('click', function () {
                var hid = document.getElementById('recvProductId');
                if (hid) hid.value = '';
                var newSel = document.getElementById('recvNewSelected');
                if (newSel) { newSel.style.display = 'none'; newSel.innerHTML = ''; }
                var manual = document.getElementById('recvNewManualFields');
                if (manual) manual.style.display = '';
                box.style.display = 'none';
                box.innerHTML = '';
                if (qInput) qInput.focus();
            });
        }
        return;
    }
    box.style.display = '';
    box.innerHTML = '';
    list.forEach(function (p) {
        var row = document.createElement('div');
        row.className = 'product-picker-row';
        var own = String(p.ownership || 'owned');
        var badge = own === 'borrowed'
            ? ' <span class="badge badge-info">Prestado</span>'
            : '';
        row.innerHTML = '<span>' + escHtml(productLabel(p)) + badge + '</span>'
            + '<span style="color:var(--color-text-secondary);">stock ' + escHtml(p.stock) + '</span>';
        row.addEventListener('click', function () { onPick(p); });
        box.appendChild(row);
    });
    if (typed) {
        var create = document.createElement('div');
        create.className = 'product-picker-row';
        create.innerHTML = '<span>Usar nombre nuevo: <strong>' + escHtml(typed) + '</strong></span>'
            + '<span style="color:var(--color-accent-text, var(--color-primary));">Crear Prestado</span>';
        create.addEventListener('click', function () {
            var hid = document.getElementById('recvProductId');
            if (hid) hid.value = '';
            var newSel = document.getElementById('recvNewSelected');
            if (newSel) { newSel.style.display = 'none'; newSel.innerHTML = ''; }
            var manual = document.getElementById('recvNewManualFields');
            if (manual) manual.style.display = '';
            box.style.display = 'none';
            box.innerHTML = '';
            if (qInput) qInput.focus();
        });
        box.appendChild(create);
    }
}

function searchProductsLocal(q) {
    q = String(q || '').trim().toLowerCase();
    var seed = window.PRESTAMOS_PRODUCTS_SEED || [];
    if (!q) return [];
    return seed.filter(function (p) {
        return String(p.name || '').toLowerCase().indexOf(q) >= 0
            || String(p.code || '').toLowerCase().indexOf(q) >= 0
            || String(p.barcode || '').toLowerCase() === q;
    }).slice(0, 25);
}

function searchProductsRemote(q, cb, mode) {
    var url = (window.PRESTAMOS_SEARCH_URL || '') + '&q=' + encodeURIComponent(q);
    if (mode) url += '&mode=' + encodeURIComponent(mode);
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.products) {
                cb(d.products);
                return;
            }
            cb((mode === 'borrowed') ? [] : searchProductsLocal(q));
        })
        .catch(function () { cb(mode === 'borrowed' ? [] : searchProductsLocal(q)); });
}

function bindProductPicker(searchId, resultsId, onPick, excludeIdFn, mode, allowCreateFromInput) {
    var input = document.getElementById(searchId);
    if (!input) return;
    var timer = null;
    function run(autoExact) {
        var q = (input.value || '').trim();
        if (q.length < 1) {
            var box = document.getElementById(resultsId);
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            // Si escribe de nuevo tras haber elegido producto, limpiar selección.
            if (allowCreateFromInput) {
                var hid = document.getElementById('recvProductId');
                if (hid) hid.value = '';
            }
            return;
        }
        // Al tipear en el buscador de alta, no mantener un product_id previo.
        if (allowCreateFromInput) {
            var hidTyping = document.getElementById('recvProductId');
            var newSel = document.getElementById('recvNewSelected');
            if (hidTyping && newSel && newSel.style.display !== 'none') {
                // selection chip visible → leave until Cambiar
            } else if (hidTyping) {
                hidTyping.value = '';
            }
        }
        searchProductsRemote(q, function (list) {
            var exclude = excludeIdFn ? excludeIdFn() : null;
            if (exclude) {
                list = (list || []).filter(function (p) { return String(p.id) !== String(exclude); });
            }
            if (autoExact && list && list.length) {
                var ql = q.toLowerCase();
                var exact = list.find(function (p) {
                    return String(p.code || '').toLowerCase() === ql
                        || String(p.barcode || '').toLowerCase() === ql;
                }) || (list.length === 1 ? list[0] : null);
                if (exact) {
                    onPick(exact);
                    return;
                }
            }
            renderPickerResults(resultsId, list, onPick, allowCreateFromInput ? searchId : null);
        }, mode || 'owned');
    }
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { run(false); }, 220);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            run(true);
        }
    });
}

function openLoanModal(preselectId) {
    var modal = document.getElementById('loanModal');
    if (!modal) return;
    clearLoanProduct();
    if (preselectId) {
        var seed = (window.PRESTAMOS_PRODUCTS_SEED || []).find(function (p) {
            return String(p.id) === String(preselectId);
        });
        if (seed) {
            setLoanProduct(seed);
        } else {
            searchProductsRemote(String(preselectId), function (list) {
                var hit = (list || []).find(function (p) { return String(p.id) === String(preselectId); });
                if (hit) setLoanProduct(hit);
            }, 'owned');
        }
    }
    modal.style.display = 'flex';
    var search = document.getElementById('loanProductSearch');
    if (search && search.style.display !== 'none') setTimeout(function () { search.focus(); }, 50);
}

function setRecvProduct(p) {
    var hid = document.getElementById('recvProductId');
    var exist = document.getElementById('recvModeExist');
    var isExist = !!(exist && exist.checked);
    if (!hid || !p) return;
    hid.value = String(p.id);

    if (isExist) {
        var sel = document.getElementById('recvProductSelected');
        var search = document.getElementById('recvProductSearch');
        var results = document.getElementById('recvProductResults');
        if (sel) {
            sel.style.display = '';
            sel.innerHTML = '<strong>' + escHtml(productLabel(p)) + '</strong>'
                + ' <span style="color:var(--color-text-secondary);">· stock ' + escHtml(p.stock) + '</span>'
                + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearRecvProduct()">Cambiar</button>';
        }
        if (search) { search.value = ''; search.style.display = 'none'; }
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
        return;
    }

    // Modo nuevo / desde inventario: producto existente seleccionado.
    var newSel = document.getElementById('recvNewSelected');
    var newSearch = document.getElementById('recvNewName');
    var newResults = document.getElementById('recvNewResults');
    var manual = document.getElementById('recvNewManualFields');
    var own = String(p.ownership || 'owned');
    var ownLabel = own === 'borrowed' ? 'Prestado' : 'Inventario propio';
    if (newSel) {
        newSel.style.display = '';
        newSel.innerHTML = '<strong>' + escHtml(productLabel(p)) + '</strong>'
            + ' <span class="badge badge-info" style="margin-left:6px;">' + escHtml(ownLabel) + '</span>'
            + ' <span style="color:var(--color-text-secondary);">· stock ' + escHtml(p.stock) + '</span>'
            + (own === 'owned'
                ? '<div style="font-size:12px;color:var(--dmc-B45309, #B45309);margin-top:4px;">Al registrar el préstamo recibido se marcará como Prestado.</div>'
                : '')
            + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearRecvProduct()">Cambiar</button>';
    }
    if (newSearch) {
        newSearch.value = String(p.name || '');
        newSearch.style.display = 'none';
    }
    if (newResults) { newResults.style.display = 'none'; newResults.innerHTML = ''; }
    if (manual) manual.style.display = 'none';
    var code = document.getElementById('recvNewCode');
    var bar = document.getElementById('recvNewBarcode');
    var cost = document.getElementById('recvUnitCost');
    if (code) code.value = String(p.code || '');
    if (bar) bar.value = String(p.barcode || '');
    if (cost && p.purchase_price != null) {
        cost.value = window.SeriMoney ? SeriMoney.format(p.purchase_price) : String(p.purchase_price);
    }
}

function clearRecvProduct() {
    var hid = document.getElementById('recvProductId');
    if (hid) hid.value = '';

    var exist = document.getElementById('recvModeExist');
    var isExist = !!(exist && exist.checked);

    var sel = document.getElementById('recvProductSelected');
    var search = document.getElementById('recvProductSearch');
    var results = document.getElementById('recvProductResults');
    if (sel) { sel.style.display = 'none'; sel.innerHTML = ''; }
    if (search) { search.style.display = ''; if (isExist) search.focus(); }
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }

    var newSel = document.getElementById('recvNewSelected');
    var newSearch = document.getElementById('recvNewName');
    var newResults = document.getElementById('recvNewResults');
    var manual = document.getElementById('recvNewManualFields');
    if (newSel) { newSel.style.display = 'none'; newSel.innerHTML = ''; }
    if (newSearch) {
        newSearch.style.display = '';
        if (!isExist) {
            newSearch.value = '';
            newSearch.focus();
        }
    }
    if (newResults) { newResults.style.display = 'none'; newResults.innerHTML = ''; }
    if (manual) manual.style.display = '';
}

function toggleRecvMode() {
    var exist = document.getElementById('recvModeExist');
    var isExist = !!(exist && exist.checked);
    var newWrap = document.getElementById('recvNewWrap');
    var existWrap = document.getElementById('recvExistWrap');
    if (newWrap) newWrap.style.display = isExist ? 'none' : '';
    if (existWrap) existWrap.style.display = isExist ? '' : 'none';
    clearRecvProduct();
    var code = document.getElementById('recvNewCode');
    var bar = document.getElementById('recvNewBarcode');
    var cost = document.getElementById('recvUnitCost');
    if (!isExist) {
        if (code) code.value = '';
        if (bar) bar.value = '';
        if (cost) cost.value = '';
    }
}

function openReceivedModal() {
    var modal = document.getElementById('receivedModal');
    if (!modal) return;
    var neu = document.getElementById('recvModeNew');
    if (neu) neu.checked = true;
    toggleRecvMode();
    var qty = document.getElementById('recvQty');
    if (qty) qty.value = '1';
    var lender = document.getElementById('recvLender');
    if (lender) lender.value = '';
    var note = document.getElementById('recvNote');
    if (note) note.value = '';
    modal.style.display = 'flex';
    var name = document.getElementById('recvNewName');
    if (name && name.style.display !== 'none') setTimeout(function () { name.focus(); }, 50);
}

function openReturnReceivedModal(id, maxQty, stockQty, name) {
    document.getElementById('returnRecvDebtId').value = id;
    document.getElementById('returnRecvMax').textContent = maxQty;
    var stockEl = document.getElementById('returnRecvStock');
    var stock = parseInt(stockQty, 10);
    if (isNaN(stock)) stock = 0;
    if (stockEl) stockEl.textContent = String(stock);
    var qtyEl = document.getElementById('returnRecvQty');
    qtyEl.value = maxQty;
    qtyEl.max = maxQty;
    qtyEl.dataset.currentStock = String(stock);
    document.getElementById('returnRecvProductName').textContent = 'Prestado a nosotros: ' + (name || 'Producto');
    updateReturnRecvPreview();

    var settleWrap = document.getElementById('returnRecvSettleWrap');
    var settleCb = document.getElementById('returnRecvSettle');
    var settleText = document.getElementById('returnRecvSettleText');
    var needSettle = stock < parseInt(maxQty, 10);
    if (settleWrap) {
        if (needSettle) {
            settleWrap.style.display = 'block';
            if (settleText) {
                settleText.innerHTML = 'Stock actual <strong>' + stock + '</strong> e intenta devolver <strong>'
                    + maxQty + '</strong>. Si ya vendió o usó lo que le prestaron, cierre la deuda aquí '
                    + '(no baja más el stock). Si el producto sigue en tienda, corrija el inventario antes.';
            }
            if (settleCb) {
                settleCb.checked = false;
                settleCb.required = true;
            }
        } else {
            settleWrap.style.display = 'none';
            if (settleCb) {
                settleCb.checked = false;
                settleCb.required = false;
            }
        }
    }
    document.getElementById('returnReceivedModal').style.display = 'flex';
}

function updateReturnRecvPreview() {
    var qtyEl = document.getElementById('returnRecvQty');
    var preview = document.getElementById('returnRecvPreview');
    if (!qtyEl || !preview) return;
    var stock = parseInt(qtyEl.dataset.currentStock || '0', 10);
    if (isNaN(stock)) stock = 0;
    var qty = parseInt(qtyEl.value || '0', 10);
    if (isNaN(qty) || qty < 0) qty = 0;
    var physical = Math.min(Math.max(0, stock), qty);
    var after = stock - physical;
    if (qty <= 0) {
        preview.textContent = '';
        return;
    }
    preview.innerHTML = 'Al confirmar: stock <strong>' + stock + ' → ' + after + '</strong>'
        + (physical < qty
            ? ' (solo ' + physical + ' físicas; el resto requiere liquidación sin stock)'
            : ' (se restan del inventario las unidades prestadas; lo comprado por factura se mantiene)');
}

function updateLoanStockHint() {
    var hid = document.getElementById('loanProductId');
    var hint = document.getElementById('loanStockHint');
    if (!hid || !hint) return;
    if (!hid.value) { hint.textContent = ''; return; }
    var stock = parseInt(hid.dataset.stock || '0', 10);
    var qty = parseInt((document.getElementById('loanQty') || {}).value || '1', 10) || 1;
    if (qty > Math.max(0, stock)) {
        hint.textContent = 'Stock actual: ' + stock + '. Esta salida dejará el inventario en ' + (stock - qty) + '.';
        hint.style.color = 'var(--dmc-B45309, #B45309)';
    } else {
        hint.textContent = 'Stock actual: ' + stock;
        hint.style.color = '';
    }
}

(function(){
    bindProductPicker('loanProductSearch', 'loanProductResults', setLoanProduct, null, 'owned');
    bindProductPicker('recvProductSearch', 'recvProductResults', setRecvProduct, null, 'borrowed');
    bindProductPicker('recvNewName', 'recvNewResults', setRecvProduct, null, 'all', true);

    var qty = document.getElementById('loanQty');
    var reason = document.getElementById('loanReason');
    if (qty) qty.addEventListener('input', updateLoanStockHint);
    var recvQty = document.getElementById('returnRecvQty');
    if (recvQty) recvQty.addEventListener('input', updateReturnRecvPreview);
    var returnQty = document.getElementById('returnQty');
    if (returnQty) returnQty.addEventListener('input', updateReturnLinesHint);
    if (reason) reason.addEventListener('change', function(){
        var req = document.getElementById('loanNoteReq');
        var note = document.getElementById('loanNote');
        var isOther = reason.value === 'otro' || reason.value === '__other__';
        if (req) req.style.display = isOther ? 'inline' : 'none';
        if (note) note.required = isOther;
    });
    var recvReason = document.getElementById('recvReason');
    if (recvReason && window.SoftNovaCustomSelect) {
        SoftNovaCustomSelect.bind(recvReason);
    }
    var same = document.getElementById('returnModeSame');
    var diff = document.getElementById('returnModeDiff');
    if (same) same.addEventListener('change', toggleReturnMode);
    if (diff) diff.addEventListener('change', toggleReturnMode);

    var loanForm = document.getElementById('loanForm');
    if (loanForm) {
        loanForm.addEventListener('submit', function (e) {
            var hid = document.getElementById('loanProductId');
            if (!hid || !hid.value) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Seleccione o escanee un producto', 'error');
                return false;
            }
        }, true);
    }
    var recvForm = document.getElementById('receivedForm');
    if (recvForm) {
        recvForm.addEventListener('submit', function (e) {
            var exist = document.getElementById('recvModeExist');
            var hid = document.getElementById('recvProductId');
            if (exist && exist.checked) {
                if (!hid || !hid.value) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (typeof showAlert === 'function') showAlert('Seleccione el producto Prestado', 'error');
                    return false;
                }
            } else {
                var nm = document.getElementById('recvNewName');
                var hasProduct = !!(hid && hid.value);
                var hasName = !!(nm && String(nm.value || '').trim());
                if (!hasProduct && !hasName) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (typeof showAlert === 'function') showAlert('Busque un producto del inventario o escriba un nombre nuevo', 'error');
                    return false;
                }
            }
        }, true);
    }
    var form = document.getElementById('returnLoanForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var diffMode = document.getElementById('returnModeDiff');
            if (!(diffMode && diffMode.checked)) return;
            var rows = document.querySelectorAll('#returnLinesList .return-line-row');
            if (!rows.length) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Agregue al menos un producto de devolución', 'error');
                return false;
            }
            var sum = 0;
            var incomplete = false;
            rows.forEach(function (row) {
                var hid = row.querySelector('.return-line-product-id');
                var q = parseInt((row.querySelector('.return-line-qty') || {}).value || '0', 10) || 0;
                if (!hid || !hid.value || q <= 0) incomplete = true;
                sum += q;
            });
            if (incomplete) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Complete producto y cantidad en cada línea', 'error');
                return false;
            }
            var need = getReturnNeedQty();
            if (sum !== need) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (typeof showAlert === 'function') {
                    showAlert('La suma de líneas (' + sum + ') debe ser ' + need + ' uds', 'error');
                }
                return false;
            }
            // Alinear quantity del form con la suma (fuente de verdad en modo sustitución).
            var qtyEl = document.getElementById('returnQty');
            if (qtyEl) qtyEl.value = String(sum);
        }, true);
    }

    if (window.SoftNovaBarcode && typeof SoftNovaBarcode.onScan === 'function') {
        SoftNovaBarcode.onScan(function (code, product) {
            var loanOpen = document.getElementById('loanModal') && document.getElementById('loanModal').style.display !== 'none';
            var retOpen = document.getElementById('returnModal') && document.getElementById('returnModal').style.display !== 'none';
            var recvOpen = document.getElementById('receivedModal') && document.getElementById('receivedModal').style.display !== 'none';
            if (!product) return false;
            if (loanOpen) {
                setLoanProduct(product);
                return true;
            }
            if (recvOpen) {
                var exist = document.getElementById('recvModeExist');
                var own = String(product.ownership || 'owned');
                if (own === 'borrowed') {
                    if (exist) {
                        exist.checked = true;
                        toggleRecvMode();
                    }
                } else {
                    var neu = document.getElementById('recvModeNew');
                    if (neu) {
                        neu.checked = true;
                        toggleRecvMode();
                    }
                }
                setRecvProduct(product);
                return true;
            }
            if (retOpen) {
                var diffMode = document.getElementById('returnModeDiff');
                if (diffMode && !diffMode.checked) {
                    diffMode.checked = true;
                    toggleReturnMode();
                }
                var emptyRow = null;
                document.querySelectorAll('#returnLinesList .return-line-row').forEach(function (row) {
                    var hid = row.querySelector('.return-line-product-id');
                    if (hid && !hid.value && !emptyRow) emptyRow = row;
                });
                if (!emptyRow) {
                    addReturnLine(product);
                } else {
                    // Simular pick en fila vacía
                    var hid = emptyRow.querySelector('.return-line-product-id');
                    var search = emptyRow.querySelector('.return-line-search');
                    var selected = emptyRow.querySelector('.return-line-selected');
                    var results = emptyRow.querySelector('.return-line-results');
                    var orig = (document.getElementById('returnOriginalProductId') || {}).value || '';
                    if (String(product.id) === String(orig)) {
                        if (typeof showAlert === 'function') {
                            showAlert('Elija un producto distinto al prestado', 'error');
                        }
                        return true;
                    }
                    hid.value = String(product.id);
                    if (selected) {
                        selected.style.display = '';
                        selected.innerHTML = '<strong>' + escHtml(productLabel(product)) + '</strong>'
                            + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;">Cambiar</button>';
                        var ch = selected.querySelector('button');
                        if (ch) {
                            ch.addEventListener('click', function () {
                                hid.value = '';
                                selected.style.display = 'none';
                                selected.innerHTML = '';
                                if (search) { search.style.display = ''; search.focus(); }
                            });
                        }
                    }
                    if (search) search.style.display = 'none';
                    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
                    updateReturnLinesHint();
                }
                return true;
            }
            return false;
        });
    }

    var params = new URLSearchParams(window.location.search);
    var pid = params.get('product_id');
    if (pid && typeof openLoanModal === 'function') {
        openLoanModal(pid);
    }
})();
</script>
<?php endif; ?>
