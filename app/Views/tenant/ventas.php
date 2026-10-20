<?php
$layout = 'tenant';
$title = 'Ventas - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Punto de Venta';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$sales = $sales ?? [];
$todayTotal = $todayTotal ?? 0;
$todayCount = $todayCount ?? 0;
$products = $products ?? [];
$customers = $customers ?? [];
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0];
$canCreateSale = \SoftNova\Core\TenantMiddleware::canDo('create', 'ventas');
$canDeleteSale = \SoftNova\Core\TenantMiddleware::canDo('delete', 'ventas');
$canExportSale = \SoftNova\Core\TenantMiddleware::canDo('export', 'ventas');
$canEditPaymentMethod = $canCreateSale || \SoftNova\Core\TenantMiddleware::canDo('edit', 'ventas');
$filters = $filters ?? ['q'=>'','from'=>'','to'=>'','status'=>'','customer_id'=>'','payment_method'=>'','record_status'=>'active','sort'=>'date','dir'=>'desc','query'=>[]];
$cancelReasons = $cancelReasons ?? [];
$paymentMethodOptionsHtml = $paymentMethodOptionsHtml ?? \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true);
$paymentMethodFilterHtml = $paymentMethodFilterHtml ?? '';
$ventasBase = $viewInstance->route('app/ventas');
$filterQuery = http_build_query($filters['query'] ?? []);
$needBarcode = true;
$pageScripts = ['ventas.js'];
$creditNotesPanel = !empty($creditNotesPanel);
$creditNotes = $creditNotes ?? [];
$creditNotesQ = $creditNotesQ ?? '';

function fmtV(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<meta name="currency-symbol" content="<?php echo $currency['symbol']; ?>">
<meta name="currency-decimals" content="<?php echo $currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<?php if ($creditNotesPanel): ?>
<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <a href="<?php echo $ventasBase; ?>" class="btn btn-secondary">← Volver a ventas</a>
    <span style="font-size:13px;color:var(--color-text-secondary);">
        Emite una NC desde el botón <strong>NC</strong> de una venta (devolución por vencido, roto u otros).
    </span>
    <form method="GET" action="<?php echo $ventasBase; ?>" style="display:flex;gap:8px;align-items:center;margin-left:auto;">
        <input type="hidden" name="action" value="notas_credito">
        <input type="search" name="q" value="<?php echo htmlspecialchars($creditNotesQ); ?>" class="form-control" placeholder="Buscar NC, factura, cliente…" style="min-width:220px;">
        <button type="submit" class="btn btn-primary">Buscar</button>
    </form>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Notas de crédito de venta</h3></div>
    <div class="card-body">
        <?php if (empty($creditNotes)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">
                Aún no hay notas de crédito de venta. Use el botón <strong>NC</strong> en una venta activa.
            </p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead>
                    <tr>
                        <th>Número</th><th>Fecha</th><th>Cliente</th><th>Venta</th>
                        <th>Motivo</th><th>Reembolso</th><th>Stock</th><th>Asiento</th><th>Total</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($creditNotes as $n): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$n['credit_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr((string)($n['credit_date'] ?? ''), 0, 10)); ?></td>
                        <td><?php echo htmlspecialchars((string)($n['customer_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string)($n['invoice_number'] ?? '—')); ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars((string)($n['reason'] ?? '')); ?>">
                            <?php echo htmlspecialchars((string)($n['reason'] ?: '—')); ?>
                        </td>
                        <td><?php
                            $rm = (string)($n['refund_method'] ?? 'auto');
                            echo htmlspecialchars(match ($rm) {
                                'cash' => 'Efectivo',
                                'transfer' => 'Transferencia',
                                'receivable' => 'CxC',
                                default => 'Auto',
                            });
                        ?></td>
                        <td><?php echo (int)($n['affects_stock'] ?? 0) === 1 ? 'Reingreso' : 'No'; ?></td>
                        <td><?php echo htmlspecialchars((string)($n['accounting_entry_number'] ?? '—')); ?></td>
                        <td><?php echo fmtV((float)$n['total'], $currency); ?></td>
                        <td class="table-actions" style="white-space:nowrap;">
                            <a class="btn btn-sm btn-primary"
                               href="<?php echo $ventasBase; ?>?action=credit_note_pdf&amp;id=<?php echo (int)$n['id']; ?>"
                               target="_blank" rel="noopener">PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
                <?php
                $paginationBaseUrl = $ventasBase;
                $paginationQuery = array_filter([
                    'action' => 'notas_credito',
                    'q' => $creditNotesQ !== '' ? $creditNotesQ : null,
                ]);
                $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
                ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php return; endif; ?>

<?php
$salesSummary = $salesSummary ?? ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'count' => (int)$todayCount, 'total' => (float)$todayTotal, 'discount' => 0, 'by_method' => [], 'collected' => 0, 'pending' => 0];
$sumToday = date('Y-m-d');
$sumIsToday = $salesSummary['from'] === $sumToday && $salesSummary['to'] === $sumToday;
$sumLabel = $sumIsToday ? 'Hoy' : ($salesSummary['from'] === $salesSummary['to']
    ? date('d/m/Y', strtotime($salesSummary['from']))
    : date('d/m/Y', strtotime($salesSummary['from'])) . ' – ' . date('d/m/Y', strtotime($salesSummary['to'])));
$sumKeep = array_diff_key($filters['query'] ?? [], ['sum_from' => 1, 'sum_to' => 1, 'page' => 1]);
$sumPresetUrl = static function (string $from, string $to) use ($ventasBase, $sumKeep): string {
    return $ventasBase . '?' . http_build_query($sumKeep + ['sum_from' => $from, 'sum_to' => $to]);
};
?>
<div class="card neumorphic" style="margin-bottom:14px;">
    <div class="card-body" style="padding:10px 16px;">
        <form method="GET" action="<?php echo $ventasBase; ?>" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <?php foreach ($sumKeep as $k => $v): if (is_array($v)) continue; ?>
                <input type="hidden" name="<?php echo htmlspecialchars((string)$k); ?>" value="<?php echo htmlspecialchars((string)$v); ?>">
            <?php endforeach; ?>
            <strong style="font-size:13px;align-self:center;">Resumen de ventas</strong>
            <div class="form-group" style="margin:0;"><label style="font-size:12px;">Desde</label><input class="form-control" type="date" name="sum_from" value="<?php echo htmlspecialchars($salesSummary['from']); ?>"></div>
            <div class="form-group" style="margin:0;"><label style="font-size:12px;">Hasta</label><input class="form-control" type="date" name="sum_to" value="<?php echo htmlspecialchars($salesSummary['to']); ?>"></div>
            <button class="btn btn-primary btn-sm" type="submit">Ver</button>
            <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($sumPresetUrl($sumToday, $sumToday)); ?>">Hoy</a>
            <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($sumPresetUrl(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')))); ?>">Ayer</a>
            <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($sumPresetUrl(date('Y-m-d', strtotime('-6 days')), $sumToday)); ?>">7 días</a>
            <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($sumPresetUrl(date('Y-m-01'), $sumToday)); ?>">Este mes</a>
        </form>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Ventas <?php echo htmlspecialchars($sumLabel); ?></h4><div class="stat-value"><?php echo (int)$salesSummary['count']; ?></div></div>
    <div class="stat-card neumorphic">
        <h4>Total <?php echo htmlspecialchars($sumLabel); ?></h4>
        <div class="stat-value" style="color:#10B981;"><?php echo fmtV((float)$salesSummary['total'], $currency); ?></div>
        <?php if ((float)$salesSummary['discount'] > 0.009): ?>
            <small style="color:var(--color-text-secondary);">Descuentos: <?php echo fmtV((float)$salesSummary['discount'], $currency); ?></small>
        <?php endif; ?>
    </div>
</div>

<?php
$sumMethods = $salesSummary['by_method'] ?? [];
$sumPending = (float)($salesSummary['pending'] ?? 0);
$sumTotal = (float)($salesSummary['total'] ?? 0);
$sumExportQs = http_build_query(['sum_from' => $salesSummary['from'], 'sum_to' => $salesSummary['to']]);
$sumPalette = ['#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#6366F1', '#EF4444'];
?>
<details class="card neumorphic sales-pay-summary" id="salesPaySummary">
    <summary class="sales-pay-summary__head">
        <span class="sales-pay-summary__chevron" aria-hidden="true">▸</span>
        <strong>Medios de pago</strong>
        <span class="sales-pay-summary__period"><?php echo htmlspecialchars($sumLabel); ?></span>
        <?php if ($sumTotal > 0.009): ?>
            <span class="sales-pay-summary__bar" title="Distribución por medio de pago">
                <?php foreach ($sumMethods as $i => $pm):
                    $w = max(0.5, ((float)$pm['amount'] / $sumTotal) * 100); ?>
                    <span style="width:<?php echo round($w, 2); ?>%;background:<?php echo $sumPalette[$i % count($sumPalette)]; ?>;" title="<?php echo htmlspecialchars($pm['label']); ?>"></span>
                <?php endforeach; ?>
                <?php if ($sumPending > 0.009): ?>
                    <span style="width:<?php echo round(($sumPending / $sumTotal) * 100, 2); ?>%;background:#F59E0B;opacity:.55;" title="Por cobrar"></span>
                <?php endif; ?>
            </span>
            <span class="sales-pay-summary__hint"><?php echo count($sumMethods); ?> medio(s)</span>
        <?php else: ?>
            <span class="sales-pay-summary__hint">Sin ventas en el periodo</span>
        <?php endif; ?>
        <?php if ($canExportSale && $sumTotal > 0.009): ?>
            <span class="sales-pay-summary__actions" onclick="event.stopPropagation();">
                <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $ventasBase; ?>?action=summary_csv&amp;<?php echo htmlspecialchars($sumExportQs); ?>" title="Descargar resumen en CSV">CSV</a>
                <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $ventasBase; ?>?action=summary_pdf&amp;<?php echo htmlspecialchars($sumExportQs); ?>" title="Descargar resumen en PDF">PDF</a>
            </span>
        <?php endif; ?>
    </summary>
    <?php if ($sumTotal > 0.009): ?>
    <div class="sales-pay-summary__body">
        <?php foreach ($sumMethods as $i => $pm):
            $pct = round(((float)$pm['amount'] / $sumTotal) * 100, 1);
            $color = $sumPalette[$i % count($sumPalette)]; ?>
            <div class="sales-pay-chip" style="border-left-color:<?php echo $color; ?>;">
                <div class="sales-pay-chip__label"><?php echo htmlspecialchars($pm['label']); ?></div>
                <div class="sales-pay-chip__amount"><?php echo fmtV((float)$pm['amount'], $currency); ?></div>
                <div class="sales-pay-chip__meta"><?php echo (int)$pm['count']; ?> venta(s) · <?php echo str_replace('.', ',', (string)$pct); ?>%</div>
                <div class="sales-pay-chip__track"><span style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></span></div>
            </div>
        <?php endforeach; ?>
        <?php if ($sumPending > 0.009):
            $pct = round(($sumPending / $sumTotal) * 100, 1); ?>
            <div class="sales-pay-chip sales-pay-chip--pending">
                <div class="sales-pay-chip__label">Por cobrar (crédito)</div>
                <div class="sales-pay-chip__amount"><?php echo fmtV($sumPending, $currency); ?></div>
                <div class="sales-pay-chip__meta">Saldo pendiente · <?php echo str_replace('.', ',', (string)$pct); ?>%</div>
                <div class="sales-pay-chip__track"><span style="width:<?php echo $pct; ?>%;background:#F59E0B;"></span></div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</details>
<style>
.sales-pay-summary { margin-bottom: 18px; padding: 0; overflow: hidden; }
.sales-pay-summary__head {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    padding: 10px 16px; cursor: pointer; list-style: none; font-size: 13px; user-select: none;
}
.sales-pay-summary__head::-webkit-details-marker { display: none; }
.sales-pay-summary__chevron { display: inline-block; transition: transform .2s; color: var(--color-text-secondary); }
.sales-pay-summary[open] .sales-pay-summary__chevron { transform: rotate(90deg); }
.sales-pay-summary__period { color: var(--color-text-secondary); font-size: 12px; }
.sales-pay-summary__bar {
    display: inline-flex; flex: 1; min-width: 120px; max-width: 360px; height: 8px;
    border-radius: 99px; overflow: hidden; background: var(--color-border);
}
.sales-pay-summary__bar span { display: block; height: 100%; }
.sales-pay-summary__hint { font-size: 12px; color: var(--color-text-secondary); }
.sales-pay-summary__actions { margin-left: auto; display: inline-flex; gap: 6px; }
.sales-pay-summary__body {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px;
    padding: 12px 16px 14px; border-top: 1px dashed var(--color-border);
}
.sales-pay-chip {
    padding: 10px 12px; border: 1px solid var(--color-border); border-left: 4px solid #10B981;
    border-radius: 8px;
}
.sales-pay-chip__label { font-size: 12px; color: var(--color-text-secondary); }
.sales-pay-chip__amount { font-size: 16px; font-weight: 700; margin: 2px 0; }
.sales-pay-chip__meta { font-size: 11px; color: var(--color-text-secondary); }
.sales-pay-chip__track { height: 4px; margin-top: 6px; border-radius: 99px; background: var(--color-border); overflow: hidden; }
.sales-pay-chip__track span { display: block; height: 100%; }
.sales-pay-chip--pending { border-left-color: #F59E0B; background: rgba(245, 158, 11, 0.06); }
.sales-pay-chip--pending .sales-pay-chip__label,
.sales-pay-chip--pending .sales-pay-chip__amount { color: var(--dmc-B45309, #B45309); }
</style>
<script>
(function () {
    var el = document.getElementById('salesPaySummary');
    if (!el) return;
    var key = 'seri_ventas_pay_summary_open';
    try { if (localStorage.getItem(key) === '1') el.open = true; } catch (e) {}
    el.addEventListener('toggle', function () {
        try { localStorage.setItem(key, el.open ? '1' : '0'); } catch (e) {}
    });
})();

(function () {
    function notify(msg, type) {
        if (typeof showAlert === 'function') { showAlert(msg, type); } else { alert(msg); }
    }
    function fileNameFrom(res, fallback) {
        var cd = res.headers.get('Content-Disposition') || '';
        var m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
        return m ? decodeURIComponent(m[1]) : fallback;
    }
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-export-download]');
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey || !window.fetch) return;
        e.preventDefault();
        e.stopPropagation();
        if (link.dataset.busy === '1') return;
        var original = link.textContent;
        link.dataset.busy = '1';
        link.classList.add('disabled');
        link.setAttribute('aria-busy', 'true');
        link.textContent = 'Generando…';
        fetch(link.href, { credentials: 'same-origin' })
            .then(function (res) {
                var type = (res.headers.get('Content-Type') || '').toLowerCase();
                var isFile = type.indexOf('application/pdf') !== -1 || type.indexOf('text/csv') !== -1;
                if (!res.ok || !isFile) {
                    return res.text().then(function (txt) {
                        var msg = type.indexOf('text/plain') !== -1 && txt.trim() !== ''
                            ? txt.trim().slice(0, 300)
                            : 'No se pudo generar el archivo (sesión expirada o sin permiso). Recargue la página e intente de nuevo.';
                        throw new Error(msg);
                    });
                }
                var name = fileNameFrom(res, type.indexOf('pdf') !== -1 ? 'reporte.pdf' : 'export.csv');
                return res.blob().then(function (blob) {
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = name;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
                    notify('Descarga lista: ' + name, 'success');
                });
            })
            .catch(function (err) {
                notify(err && err.message ? err.message : 'Error de red al exportar', 'error');
            })
            .finally(function () {
                link.dataset.busy = '';
                link.classList.remove('disabled');
                link.removeAttribute('aria-busy');
                link.textContent = original;
            });
    }, true);
})();
</script>

<div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;">
    <?php if ($canCreateSale): ?>
        <button onclick="openSaleModal()" class="btn btn-primary neumorphic-btn" title="Nueva venta">Nueva Venta</button>
    <?php endif; ?>
    <a href="<?php echo $ventasBase; ?>?action=notas_credito" class="btn btn-secondary" title="Panel de notas de crédito">Notas de crédito</a>
    <?php if ($canExportSale): ?>
        <a href="<?php echo $ventasBase; ?>?action=export<?php echo $filterQuery !== '' ? '&amp;' . htmlspecialchars($filterQuery) : ''; ?>" class="btn btn-secondary" data-export-download title="Exportar CSV con los filtros del listado">Exportar CSV</a>
        <a href="<?php echo $ventasBase; ?>?action=report_pdf<?php echo $filterQuery !== '' ? '&amp;' . htmlspecialchars($filterQuery) : ''; ?>" class="btn btn-secondary" data-export-download title="Descargar PDF con los filtros del listado (sin fechas: máx. 1200 ventas)">Reporte PDF</a>
    <?php endif; ?>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $ventasBase; ?>" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($filters['sort']); ?>">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($filters['dir']); ?>">
            <?php foreach (['sum_from', 'sum_to'] as $sk): if (!empty($filters['query'][$sk])): ?>
                <input type="hidden" name="<?php echo $sk; ?>" value="<?php echo htmlspecialchars((string)$filters['query'][$sk]); ?>">
            <?php endif; endforeach; ?>
            <div class="form-group" style="margin:0;min-width:200px;flex:1;">
                <label>Buscar</label>
                <input class="form-control" type="search" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="Factura, cliente o documento..." title="Buscar ventas">
            </div>
            <div class="form-group" style="margin:0;min-width:180px;">
                <label>Cliente</label>
                <select class="form-control" name="customer_id">
                    <option value="">Todos</option>
                    <?php foreach ($customers as $c):
                        $clabel = trim(($c['first_name'] ?? $c['name']) . ' ' . ($c['last_name'] ?? ''));
                        $cid = (string)(int)$c['id'];
                    ?>
                        <option value="<?php echo $cid; ?>" <?php echo ($filters['customer_id'] ?? '') === $cid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($clabel !== '' ? $clabel : ('Cliente #' . $cid)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Desde</label><input class="form-control" type="date" name="from" value="<?php echo htmlspecialchars($filters['from']); ?>"></div>
            <div class="form-group" style="margin:0;"><label>Hasta</label><input class="form-control" type="date" name="to" value="<?php echo htmlspecialchars($filters['to']); ?>"></div>
            <div class="form-group" style="margin:0;min-width:150px;">
                <label>Tipo de pago</label>
                <select class="form-control" name="payment_method">
                    <option value="">Todos</option>
                    <?php echo $paymentMethodFilterHtml; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label>Estado pago</label>
                <select class="form-control" name="status">
                    <option value="">Todos</option>
                    <option value="paid" <?php echo $filters['status']==='paid'?'selected':''; ?>>Pagado</option>
                    <option value="partial" <?php echo $filters['status']==='partial'?'selected':''; ?>>Parcial</option>
                    <option value="pending" <?php echo $filters['status']==='pending'?'selected':''; ?>>Pendiente</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label>Registro</label>
                <select class="form-control" name="record_status">
                    <option value="active" <?php echo ($filters['record_status'] ?? 'active')==='active'?'selected':''; ?>>Activas</option>
                    <option value="cancelled" <?php echo ($filters['record_status'] ?? '')==='cancelled'?'selected':''; ?>>Canceladas</option>
                    <option value="all" <?php echo ($filters['record_status'] ?? '')==='all'?'selected':''; ?>>Todas</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="<?php echo $ventasBase; ?>">Limpiar</a>
        </form>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>📋 Últimas Ventas</h3></div>
    <div class="card-body">
        <?php if (empty($sales)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay ventas con esos filtros</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Factura','column'=>'invoice','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Cliente','column'=>'customer','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Fecha','column'=>'date','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Total','column'=>'total','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Pagado','column'=>'paid','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Pago','column'=>'method','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th>NC</th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Estado','column'=>'status','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                    <?php
                    $ncTot = round((float)($sale['credit_note_total'] ?? 0), 2);
                    $saleTot = round((float)($sale['total'] ?? 0), 2);
                    $ncFull = $ncTot > 0.009 && $ncTot >= ($saleTot - 0.02);
                    $ncTip = trim((string)($sale['credit_note_numbers'] ?? ''));
                    if ($ncTip === '' && $ncTot > 0.009) {
                        $ncTip = 'Nota(s) de crédito';
                    }
                    $saleObs = \SoftNova\Services\SalesDocumentService::displayNotes((string)($sale['notes'] ?? ''));
                    ?>
                    <tr<?php echo $ncFull ? ' style="opacity:0.85;"' : ''; ?>>
                        <td>
                            <strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong>
                            <?php if ($ncFull): ?>
                                <div><span class="badge badge-warning" title="<?php echo htmlspecialchars($ncTip); ?>">Devuelta</span></div>
                            <?php elseif ($ncTot > 0.009): ?>
                                <div><span class="badge badge-info" title="<?php echo htmlspecialchars($ncTip); ?>">Con NC</span></div>
                            <?php endif; ?>
                            <?php if ($saleObs !== ''): ?>
                                <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;max-width:220px;" title="<?php echo htmlspecialchars($saleObs); ?>">
                                    <?php echo htmlspecialchars(mb_strlen($saleObs) > 80 ? mb_substr($saleObs, 0, 77) . '…' : $saleObs); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'General'); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></td>
                        <td style="font-weight:600;">
                            <?php echo fmtV($saleTot, $currency); ?>
                            <?php if ($ncTot > 0.009): ?>
                                <div style="font-size:11px;color:var(--color-text-secondary);">Neto <?php echo fmtV(max(0, $saleTot - $ncTot), $currency); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $sale['payment_status'] === 'paid' ? fmtV($saleTot, $currency) : fmtV($sale['paid_amount'] ?? 0, $currency); ?></td>
                        <td>
                            <span class="badge <?php echo $sale['payment_method'] === 'cash' ? 'badge-success' : (in_array($sale['payment_method'], ['mixed','card','dataphone','payment_link','transfer','debit_card','credit_card','nequi'], true) ? 'badge-info' : 'badge-warning'); ?>">
                                <?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)$sale['payment_method'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($ncTot > 0.009): ?>
                                <span class="badge badge-warning" title="<?php echo htmlspecialchars($ncTip); ?>">
                                    NC <?php echo fmtV($ncTot, $currency); ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($sale['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge badge-danger">Cancelado</span>
                            <?php elseif ($ncFull): ?>
                                <span class="badge badge-warning">Devuelta</span>
                            <?php elseif ($sale['payment_status'] === 'paid'): ?>
                                <span class="badge badge-success">Pagado</span>
                            <?php elseif ($sale['payment_status'] === 'partial'): ?>
                                <span class="badge badge-warning">Parcial</span>
                            <?php else: ?>
                                <span class="badge badge-info">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <button onclick="viewDetail(<?php echo $sale['id']; ?>)" class="btn btn-sm btn-info" title="Ver detalle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            <a href="<?php echo $ventasBase; ?>?action=pdf&id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="PDF carta"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg></a>
                            <a href="<?php echo $ventasBase; ?>?action=pdf&format=print&id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="Imprimir tirilla 58mm">58mm</a>
                            <button type="button" class="btn btn-sm btn-success" onclick="shareSale(<?php echo (int)$sale['id']; ?>)" title="WhatsApp / Correo">↗</button>
                            <?php if ($canEditPaymentMethod && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-secondary" title="Editar medio de pago"
                                    onclick="openEditPaymentMethodModal(<?php echo (int)$sale['id']; ?>, '<?php echo htmlspecialchars((string)($sale['invoice_number'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string)($sale['payment_method'] ?? 'cash'), ENT_QUOTES); ?>')">
                                    Pago
                                </button>
                            <?php endif; ?>
                            <?php if ($canDeleteSale && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-warning" title="Nota de crédito / devolución"
                                    onclick="openSaleCreditNoteModal(<?php echo (int)$sale['id']; ?>)">NC</button>
                            <?php endif; ?>
                            <?php if (in_array($sale['payment_status'], ['pending', 'partial'])): ?>
                                <button onclick="openPaymentModal(<?php echo (int)$sale['id']; ?>,<?php echo (float)$sale['total']; ?>,<?php echo (float)($sale['paid_amount'] ?? 0); ?>,<?php echo (float)($sale['credit_note_total'] ?? 0); ?>)" class="btn btn-sm btn-success" title="Registrar abono"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></button>
                            <?php endif; ?>
                            <?php if ($canDeleteSale && $sale['status'] !== 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-danger" title="Eliminar venta"
                                    onclick="openCancelSaleModal(<?php echo (int)$sale['id']; ?>, '<?php echo htmlspecialchars($sale['invoice_number'] ?? '', ENT_QUOTES); ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $pagination = $pagination ?? null;
            $paginationBaseUrl = $ventasBase;
            $paginationQuery = $filters['query'] ?? [];
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Nueva Venta -->
<div id="saleModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:750px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header"><h3>Nueva Venta</h3><button onclick="closeSaleModal()" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=create" data-ajax="true" data-prepare="saleItems" onsubmit="return prepareSaleItems()">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="form-group">
                <label>Cliente <span class="field-tip" data-tip="Escriba para buscar por nombre o documento y elija un cliente. Deje vacío o seleccione Consumidor Final si no aplica.">?</span></label>
                <div class="customer-combobox" id="customerCombobox">
                    <input type="hidden" name="customer_id" id="customerId" value="">
                    <input type="text" id="customerSearch" class="form-control" placeholder="Buscar o seleccionar cliente..." autocomplete="off" aria-autocomplete="list" aria-expanded="false">
                    <ul class="combobox-list" id="customerList" hidden>
                        <li class="combobox-option" data-id="" data-label="Consumidor Final" data-search="consumidor final">Consumidor Final</li>
                        <?php foreach ($customers as $c):
                            $label = trim(($c['first_name'] ?? $c['name']) . ' ' . ($c['last_name'] ?? ''));
                            $search = strtolower($label . ' ' . ($c['document_number'] ?? ''));
                        ?>
                            <li class="combobox-option" data-id="<?php echo (int)$c['id']; ?>" data-label="<?php echo htmlspecialchars($label); ?>" data-search="<?php echo htmlspecialchars($search); ?>">
                                <?php echo htmlspecialchars($label); ?>
                                <?php if (!empty($c['document_number'])): ?>
                                    <small><?php echo htmlspecialchars(($c['document_type'] ?? '') . ' ' . $c['document_number']); ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button type="button" onclick="quickAddCustomer()" class="link-btn">+ Nuevo Cliente</button>
            </div>
            <div class="form-group">
                <label>Productos <span class="field-tip" data-tip="Seleccione el producto, indique la cantidad y pulse + para agregarlo a la venta.">?</span></label>
                <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                    <input type="text" id="saleBarcodeScan" class="form-control" placeholder="Escanear…" autocomplete="off" data-barcode-input="true" style="flex:1;min-width:120px;" title="Pistola de códigos de barras">
                    <select id="productSelect" class="form-control" style="flex:2;min-width:180px;">
                        <option value="">Seleccionar producto...</option>
                        <?php foreach ($products as $p):
                            $isOpen = strtoupper((string)($p['code'] ?? '')) === \SoftNova\Services\OpenItemProduct::CODE;
                        ?>
                            <option value="<?php echo $p['id']; ?>"
                                    data-price="<?php echo $p['sale_price']; ?>"
                                    data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                    data-stock="<?php echo $p['stock']; ?>"
                                    data-type="<?php echo htmlspecialchars($p['product_type'] ?? 'product'); ?>"
                                    data-code="<?php echo htmlspecialchars($p['code'] ?? ''); ?>"
                                    data-open-item="<?php echo $isOpen ? '1' : '0'; ?>"
                                    data-ownership="<?php echo htmlspecialchars((string)($p['ownership'] ?? 'owned')); ?>"
                                    data-consignment="<?php echo (($p['ownership'] ?? 'owned') === 'consignment') ? '1' : '0'; ?>">
                                <?php echo htmlspecialchars(($p['code'] ?? '') !== '' ? ($p['code'] . ' — ') : ''); ?><?php echo htmlspecialchars($p['name']); ?>
                                <?php if ($isOpen): ?>
                                    (nombre y precio libres)
                                <?php elseif (($p['ownership'] ?? 'owned') === 'consignment'): ?>
                                    · Consignación (<?php echo fmtV($p['sale_price'], $currency); ?>) — Stock: <?php echo (int)$p['stock']; ?>
                                <?php else: ?>
                                    (<?php echo fmtV($p['sale_price'], $currency); ?>) — Stock: <?php echo (int)$p['stock']; ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="productQty" value="1" min="1" class="form-control" style="width:80px;" placeholder="Cant" title="Cantidad a vender">
                    <button type="button" class="btn btn-primary" id="addProductBtn" onclick="addProduct(event)">+</button>
                </div>
                <p id="productStockHint" style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;min-height:18px;"></p>
                <table id="itemsTable" style="width:100%;margin-top:10px;display:none;">
                    <thead><tr><th>Producto</th><th>Cant</th><th>Precio</th><th>Restante</th><th>Subtotal</th><th></th></tr></thead>
                    <tbody></tbody>
                    <tfoot><tr><td colspan="4" style="text-align:right;font-weight:600;">Total:</td><td id="saleTotal" style="font-weight:700;color:#10B981;">0</td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="form-group">
                <label>Notas <span class="field-tip" data-tip="Observaciones internas de la venta (referencia, mesa, entrega, etc.). No aparecen como ítem facturado.">?</span></label>
                <input type="text" name="notes" class="form-control" placeholder="Observaciones...">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Condición de pago</label>
                    <select name="payment_terms" id="paymentTerms" class="form-control" onchange="onPaymentTermsChange()">
                        <?php foreach (\SoftNova\Services\SalesDocumentService::paymentTerms() as $code => $lab): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($lab); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de Pago <span class="field-tip" data-tip="Contado: cobra el monto completo ahora. Crédito: deja saldo pendiente y genera una cuenta por cobrar.">?</span></label>
                    <select name="payment_type" id="paymentType" class="form-control" onchange="toggleCredit()">
                        <option value="full">Contado</option>
                        <option value="credit">Crédito</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="document_type" value="invoice">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Fecha facturación</label>
                    <input type="date" name="sale_date" id="saleDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="onPaymentTermsChange()">
                </div>
                <div class="form-group">
                    <label>Fecha vencimiento</label>
                    <input type="date" name="due_date" id="dueDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Fecha recepción</label>
                    <input type="date" name="received_date" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Método de Pago <span class="field-tip" data-tip="Medio con el que se recibe el dinero (efectivo, tarjeta, transferencia u otro).">?</span></label>
                    <select name="payment_method" class="form-control" data-custom-other="payment_method">
                        <?php echo $paymentMethodOptionsHtml; ?>
                    </select>
                </div>
                <div class="form-group" id="initialPaymentGroup" style="display:none;">
                    <label>Abono Inicial <span class="field-tip" data-tip="Monto que el cliente paga hoy. El resto queda como cuenta por cobrar.">?</span></label>
                    <input type="text" name="initial_payment" class="form-control money-input" data-money="1" inputmode="decimal" placeholder="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSaleModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="submitSaleBtn">Completar Venta</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Quick Customer (encima del modal de venta) -->
<div id="quickCustomerModal" class="modal-overlay" style="display:none;z-index:1400;">
    <div class="modal-content neumorphic" style="max-width:450px;">
        <div class="modal-header">
            <h3>Nuevo Cliente</h3>
            <button onclick="closeQuickCustomer()" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/clientes'); ?>?action=create" id="quickCustomerForm" onsubmit="return submitQuickCustomer(this)">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group"><label>Tipo Doc. <span class="field-tip" data-tip="Tipo de identificación del cliente (CC, CE, NIT, etc.).">?</span></label><select name="document_type" class="form-control"><option value="CC">C.C</option><option value="CE">C.E</option><option value="NIT">NIT</option><option value="PPT">PPT</option><option value="OTROS">OTROS</option></select></div>
                    <div class="form-group"><label>N° Documento * <span class="field-tip" data-tip="Número de identificación sin puntos ni espacios.">?</span></label><input type="text" name="document_number" class="form-control" placeholder="Número" required></div>
                    <div class="form-group"><label>Nombre *</label><input type="text" name="first_name" class="form-control" required placeholder="Nombres"></div>
                    <div class="form-group"><label>Apellido</label><input type="text" name="last_name" class="form-control" placeholder="Apellidos"></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="correo@ejemplo.com"></div>
                    <div class="form-group"><label>Teléfono *</label><input type="text" name="phone" class="form-control" placeholder="Teléfono" required></div>
                </div>
                <div class="form-group"><label>Dirección</label><textarea name="address" class="form-control" rows="2" placeholder="Dirección"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickCustomer()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear y Seleccionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal venta sin stock -->
<div id="oversellModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:440px;">
        <div class="modal-header">
            <h3>Vender sin stock</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('oversellModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p id="oversellInfo" style="margin:0 0 12px;color:var(--color-text-secondary);font-size:13px;"></p>
            <div class="form-group">
                <label>Motivo *</label>
                <select id="oversellReason" class="form-control">
                    <?php echo \SoftNova\Services\StockOversell::optionsHtml(); ?>
                </select>
            </div>
            <div class="form-group" id="oversellNoteGroup" style="display:none;">
                <label>Detalle (obligatorio si elige Otro)</label>
                <input type="text" id="oversellNote" class="form-control" maxlength="180" placeholder="Describa el motivo…">
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                El inventario quedará en negativo o cero. Al reponer mercancía se cubrirá esta deuda y se mostrará el motivo.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('oversellModal').style.display='none'">Cancelar</button>
            <button type="button" class="btn btn-primary" id="oversellConfirmBtn">Continuar venta</button>
        </div>
    </div>
</div>

<!-- Modal producto comodín -->
<div id="openItemModal" class="modal-overlay" style="display:none;z-index:1250;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header">
            <h3>Producto comodín</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('openItemModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:13px;color:var(--color-text-secondary);">
                Para ventas puntuales que no están en inventario. No descuenta stock.
            </p>
            <div class="form-group">
                <label>Nombre del producto *</label>
                <input type="text" id="openItemName" class="form-control" maxlength="255" placeholder="Ej: Servicio de instalación">
            </div>
            <div class="form-group">
                <label>Precio de venta *</label>
                <input type="text" id="openItemPrice" class="form-control money-input" data-money="1" inputmode="decimal" placeholder="0">
            </div>
            <div class="form-group" id="openItemTaxWrap" style="margin-bottom:0;">
                <label style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer;font-weight:500;">
                    <input type="checkbox" id="openItemHasTax" style="width:auto;margin:0;" checked>
                    <span>Este producto tiene IVA (<span id="openItemTaxRateLabel"><?php
                        echo htmlspecialchars((string)\SoftNova\Services\TaxService::defaultRate(\SoftNova\Core\TenantMiddleware::getDb()));
                    ?></span>%)</span>
                </label>
                <small style="display:block;margin-top:6px;color:var(--color-text-secondary);">
                    Si lo desmarca, se vende como exento (sin IVA).
                </small>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('openItemModal').style.display='none'">Cancelar</button>
            <button type="button" class="btn btn-primary" id="openItemConfirmBtn">Agregar a la venta</button>
        </div>
    </div>
</div>

<!-- Modal Abono -->
<div id="paymentModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:400px;">
        <div class="modal-header"><h3>Registrar Abono</h3><button onclick="document.getElementById('paymentModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/ventas'); ?>?action=payment" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="sale_id" id="paySaleId">
            <p id="payInfo" style="margin-bottom:10px;"></p>
            <div class="form-group"><label>Monto * <span class="field-tip" data-tip="Cantidad que el cliente abona ahora. No puede superar el saldo pendiente (total − notas de crédito − abonos previos).">?</span></label><input type="text" name="amount" id="payAmount" class="form-control money-input" data-money="1" inputmode="decimal" required placeholder="0"></div>
            <div class="form-group"><label>Método <span class="field-tip" data-tip="Medio de pago de este abono.">?</span></label><select name="payment_method" class="form-control" data-custom-other="payment_method"><?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?></select></div>
            <div class="form-group"><label>Notas <span class="field-tip" data-tip="Referencia del abono (número de transferencia, recibo, etc.).">?</span></label><input type="text" name="notes" class="form-control" placeholder="Abono"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('paymentModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-success">Registrar Abono</button></div>
        </form>
    </div>
</div>

<!-- Modal editar medio de pago (simple o mixto) -->
<div id="editPaymentMethodModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Editar medio de pago</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('editPaymentMethodModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=update_payment_method" data-ajax="true" id="editPayMethodForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="sale_id" id="editPaySaleId" value="">
            <input type="hidden" name="is_mixed" id="editPayIsMixed" value="0">
            <div class="modal-body">
                <p style="margin:0 0 12px;color:var(--color-text-secondary);">
                    Venta <strong id="editPayInvoice"></strong>
                    · Monto a repartir: <strong id="editPayTarget">—</strong>
                </p>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;cursor:pointer;">
                    <input type="checkbox" id="editPayMixedToggle"> Pago mixto (varios medios)
                </label>
                <div id="editPaySingleWrap" class="form-group">
                    <label>Nuevo medio de pago *</label>
                    <select name="payment_method" id="editPayMethodSelect" class="form-control" data-custom-other="payment_method">
                        <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?>
                    </select>
                </div>
                <div id="editPayMixedWrap" style="display:none;">
                    <div id="editPayMixedRows"></div>
                    <button type="button" class="btn btn-sm btn-secondary" id="editPayMixedAdd" style="margin-top:6px;">+ Agregar método</button>
                    <p id="editPayMixedSummary" style="font-size:12px;margin:8px 0 0;color:var(--color-text-secondary);"></p>
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:10px 0 0;">
                    La suma de los montos debe igualar lo cobrado. La caja se ajusta si cambia la parte en efectivo.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editPaymentMethodModal').style.display='none'">Volver</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>
<template id="editPayMethodOptionsTpl"><?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?></template>

<!-- Modal Detalle -->
<div id="saleDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:650px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header"><h3>Detalle de Venta</h3><button onclick="document.getElementById('saleDetailModal').style.display='none'" class="modal-close">&times;</button></div>
        <div class="modal-body" id="saleDetailContent"></div>
    </div>
</div>

<div id="shareModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header"><h3>Enviar recibo</h3><button type="button" class="modal-close" onclick="document.getElementById('shareModal').style.display='none'">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <label>WhatsApp (celular)</label>
                <input type="tel" id="sharePhone" class="form-control" placeholder="Ej. 3001234567" inputmode="tel" autocomplete="tel">
                <small style="color:var(--color-text-secondary);">Digite el número del cliente. Se abre el chat con el mensaje del recibo.</small>
            </div>
            <a id="shareWhatsappBtn" href="#" target="_blank" class="btn btn-success" style="width:100%;margin-bottom:10px;">Abrir WhatsApp</a>
            <form method="POST" action="<?php echo $viewInstance->route('app/ventas'); ?>?action=email" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="id" id="shareSaleId">
                <div class="form-group"><label>Correo</label><input type="email" name="email" id="shareEmail" class="form-control" required></div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">Se adjunta el ticket 58mm en PDF.</p>
                <button type="submit" class="btn btn-primary" style="width:100%;">Enviar por correo</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal cancelar venta -->
<div id="cancelSaleModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:440px;">
        <div class="modal-header">
            <h3>Eliminar venta</h3>
            <button type="button" class="modal-close" onclick="closeCancelSaleModal()">&times;</button>
        </div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=cancel" data-ajax="true" id="cancelSaleForm" onsubmit="return prepareCancelSale()">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="cancelSaleId" value="">
            <input type="hidden" name="cancel_reason_other" id="cancelReasonOther" value="">
            <div class="modal-body">
                <p style="margin:0 0 12px;color:var(--color-text-secondary);">
                    Se cancelará <strong id="cancelSaleInvoice"></strong>, se devolverá el stock y se ajustará la caja.
                </p>
                <div class="form-group">
                    <label>Motivo de eliminación *</label>
                    <select name="cancel_reason" id="cancelReasonSelect" class="form-control" required data-custom-other="sale_cancel_reason">
                        <option value="">Seleccione un motivo...</option>
                        <?php foreach ($cancelReasons as $opt): ?>
                            <option value="<?php echo htmlspecialchars((string)$opt['value']); ?>"><?php echo htmlspecialchars((string)$opt['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelSaleModal()">Volver</button>
                <button type="submit" class="btn btn-danger">Confirmar eliminación</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canDeleteSale): ?>
<!-- Modal Nota de crédito venta (requiere ventas.delete) -->
<div id="saleCreditNoteModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:720px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Nota de crédito / devolución</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('saleCreditNoteModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=credit_note" data-ajax="true" id="saleCreditNoteForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="scnSaleId" value="">
            <div class="modal-body">
                <p id="scnSaleLabel" style="font-weight:600;margin:0 0 8px;"></p>
                <p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 12px;">
                    Use esto cuando el cliente devuelve producto(s) ya vendidos (vencido, roto, cambio de opinión, etc.).
                    Se genera la NC y, si aplica, el producto reingresa al inventario.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;">
                        <label>Fecha NC *</label>
                        <input type="date" name="credit_date" id="scnDate" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Motivo *</label>
                        <select name="reason_code" id="scnReasonCode" class="form-control" required onchange="onScnReasonChange()">
                            <option value="">Seleccione…</option>
                            <option value="expired">Producto vencido</option>
                            <option value="broken">Producto roto / dañado</option>
                            <option value="wrong">Producto equivocado</option>
                            <option value="other">Otro</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Detalle del motivo</label>
                    <input type="text" name="reason" id="scnReason" class="form-control" maxlength="255" placeholder="Ej. Lote vencido 2026-03">
                </div>
                <div class="form-group">
                    <label>Reembolso / contrapartida</label>
                    <select name="refund_method" id="scnRefundMethod" class="form-control">
                        <option value="auto">Automático (según cómo se cobró)</option>
                        <option value="receivable">Reducir cuenta por cobrar</option>
                        <option value="cash">Reembolso en efectivo (sale del cajón)</option>
                        <option value="transfer">Reembolso transferencia/banco</option>
                    </select>
                </div>
                <label style="display:flex;gap:8px;align-items:flex-start;margin:10px 0 14px;">
                    <input type="checkbox" name="affects_stock" id="scnAffectsStock" value="1" style="margin-top:3px;">
                    <span>
                        Reingresar producto(s) al inventario
                        <small style="display:block;color:var(--color-text-secondary);font-weight:400;">
                            Desmarque si está vencido o roto (no vuelve a bodega vendible).
                        </small>
                    </span>
                </label>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th><th>Vendido</th><th>Ya NC</th><th>Disponible</th><th>Devolver</th>
                            </tr>
                        </thead>
                        <tbody id="scnItemsBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('saleCreditNoteModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Emitir nota de crédito</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    window.invRouteVentasDetail = '<?php echo $viewInstance->route('app/ventas'); ?>?action=detail';
    window.invRouteVentasShare = '<?php echo $viewInstance->route('app/ventas'); ?>?action=share';
    window.ventasBase = <?php echo json_encode($ventasBase); ?>;
    window.cajaTaxRate = <?php echo json_encode((float)(\SoftNova\Services\TaxService::defaultRate(\SoftNova\Core\TenantMiddleware::getDb()))); ?>;
    window.cajaTaxEnabled = <?php echo json_encode(\SoftNova\Services\TaxService::isCalculationEnabled(\SoftNova\Core\TenantMiddleware::getDb())); ?>;
</script>
