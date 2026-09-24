<?php
$layout = 'tenant';
$title = 'Compras - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Compras / Órdenes de compra';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$orders = $orders ?? [];
$products = $products ?? [];
$suppliers = $suppliers ?? [];
$categories = $categories ?? [];
$defaultTaxRate = $defaultTaxRate ?? 0;
$openTotal = $openTotal ?? 0;
$monthReceived = $monthReceived ?? 0;
$payableTotal = $payableTotal ?? 0;
$payableOrders = $payableOrders ?? [];
$pendingInvoices = $pendingInvoices ?? [];
$aiInvoiceReady = !empty($aiInvoiceReady);
$statusFilter = $statusFilter ?? 'open';
$dueFilter = $dueFilter ?? '';
$paymentFilter = $paymentFilter ?? '';
$qFilter = $qFilter ?? '';
$fromFilter = $fromFilter ?? '';
$toFilter = $toFilter ?? '';
$supplierFilter = (int)($supplierFilter ?? 0);
$paidMonth = $paidMonth ?? 0;
$comprasTab = $comprasTab ?? 'ordenes';
$isFacturasTab = $comprasTab === 'facturas';
$creditNotesPanel = !empty($creditNotesPanel);
$creditNotes = $creditNotes ?? [];
$creditNotesQ = $creditNotesQ ?? '';
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0, 'decimal' => ',', 'thousands' => '.'];
$pagination = $pagination ?? null;
$perPageOptions = $perPageOptions ?? [20, 50, 100, 200];
$listQuery = $listQuery ?? [];
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'compras');
$needBarcode = !$creditNotesPanel;
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'compras');
$canDelete = \SoftNova\Core\TenantMiddleware::canDo('delete', 'compras');
$comprasBase = $viewInstance->route('app/compras');

if (!function_exists('fmtC')) {
    function fmtC(float $a, array $c): string
    {
        return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
    }
}

$statusLabels = [
    'draft' => 'Borrador',
    'ordered' => 'Ordenada',
    'received' => 'Recibida',
    'cancelled' => 'Cancelada',
];

if ($creditNotesPanel) {
    $pageTitle = 'Notas de crédito (compras)';
} elseif ($isFacturasTab) {
    $pageTitle = 'Compras / Facturas';
} else {
    $pageTitle = 'Compras / Órdenes de compra';
}
?>
<meta name="currency-symbol" content="<?php echo htmlspecialchars($currency['symbol']); ?>">
<meta name="currency-decimals" content="<?php echo (int)$currency['decimals']; ?>">
<meta name="currency-thousands" content="<?php echo htmlspecialchars($currency['thousands'] ?? '.'); ?>">
<meta name="currency-decimal" content="<?php echo htmlspecialchars($currency['decimal'] ?? ','); ?>">
<?php echo flashMessage(); ?>

<?php if ($creditNotesPanel): ?>
<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=facturas" class="btn btn-secondary">← Volver a facturas</a>
    <?php if ($canEdit): ?>
        <span style="font-size:13px;color:var(--color-text-secondary);">
            Para emitir una NC use el botón <strong>NC</strong> en la pestaña Facturas.
        </span>
    <?php endif; ?>
    <?php if (\SoftNova\Core\TenantMiddleware::canDo('export', 'compras')): ?>
        <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=export&amp;panel=notas_credito<?php echo $creditNotesQ !== '' ? '&amp;q=' . rawurlencode($creditNotesQ) : ''; ?>"
           class="btn btn-secondary" title="Exportar NC (respeta búsqueda)">Exportar CSV</a>
    <?php endif; ?>
    <form method="GET" action="<?php echo $viewInstance->route('app/compras'); ?>" style="display:flex;gap:8px;align-items:center;margin-left:auto;">
        <input type="hidden" name="action" value="notas_credito">
        <input type="search" name="q" value="<?php echo htmlspecialchars($creditNotesQ); ?>" class="form-control" placeholder="Buscar NC, OC, proveedor…" style="min-width:220px;">
        <button type="submit" class="btn btn-primary">Buscar</button>
    </form>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Notas de crédito emitidas</h3></div>
    <div class="card-body">
        <?php if (empty($creditNotes)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">
                Aún no hay notas de crédito. Emítalas desde Facturas (botón NC).
            </p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead>
                    <tr>
                        <th>Número</th><th>Fecha</th><th>Proveedor</th><th>OC</th><th>Factura</th>
                        <th>Motivo</th><th>Stock</th><th>Asiento</th><th>Total</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($creditNotes as $n): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$n['credit_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr((string)($n['credit_date'] ?? ''), 0, 10)); ?></td>
                        <td><?php echo htmlspecialchars((string)($n['supplier_name'] ?? '—')); ?></td>
                        <td>
                            <?php if (!empty($n['purchase_order_id'])): ?>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="viewPurchase(<?php echo (int)$n['purchase_order_id']; ?>)">
                                    <?php echo htmlspecialchars((string)($n['order_number'] ?? '#' . $n['purchase_order_id'])); ?>
                                </button>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($n['invoice_number'] ?? $n['external_number'] ?? '—')); ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars((string)($n['reason'] ?? '')); ?>">
                            <?php echo htmlspecialchars((string)($n['reason'] ?: '—')); ?>
                        </td>
                        <td><?php echo (int)($n['affects_stock'] ?? 0) === 1 ? 'Sí' : 'No'; ?></td>
                        <td><?php echo htmlspecialchars((string)($n['accounting_entry_number'] ?? '—')); ?></td>
                        <td><?php echo fmtC((float)$n['total'], $currency); ?></td>
                        <td class="table-actions" style="white-space:nowrap;">
                            <a class="btn btn-sm btn-primary"
                               href="<?php echo $viewInstance->route('app/compras'); ?>?action=credit_note_pdf&amp;id=<?php echo (int)$n['id']; ?>"
                               target="_blank" rel="noopener">PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (!empty($pagination)): ?>
                <?php
                $paginationBaseUrl = $viewInstance->route('app/compras');
                $paginationQuery = $listQuery ?: array_filter([
                    'action' => 'notas_credito',
                    'q' => $creditNotesQ !== '' ? $creditNotesQ : null,
                ]);
                $paginationPerPageOptions = $perPageOptions;
                $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery', 'paginationPerPageOptions'));
                ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="purchaseDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:720px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Detalle de compra</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('purchaseDetailModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" id="purchaseDetailContent"></div>
    </div>
</div>
<script>
var comprasBase = <?php echo json_encode($viewInstance->route('app/compras')); ?>;
window.poCurrency = {
    symbol: <?php echo json_encode($currency['symbol'] ?? '$'); ?>,
    decimals: <?php echo (int)($currency['decimals'] ?? 0); ?>,
    decimal: <?php echo json_encode($currency['decimal'] ?? ','); ?>,
    thousands: <?php echo json_encode($currency['thousands'] ?? '.'); ?>
};
function poMoney(v) {
    var c = window.poCurrency || { symbol: '$', decimals: 0, decimal: ',', thousands: '.' };
    var n = parseFloat(v);
    if (!isFinite(n)) n = 0;
    var formatted;
    if (window.SeriMoney && typeof SeriMoney.format === 'function') {
        formatted = SeriMoney.format(n, {
            decimals: c.decimals,
            decimal: c.decimal || ',',
            thousands: c.thousands || '.'
        });
    } else {
        var fixed = Math.abs(n).toFixed(c.decimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands || '.');
        formatted = (n < 0 ? '-' : '') + (c.decimals > 0
            ? parts[0] + (c.decimal || ',') + parts[1]
            : parts[0]);
    }
    return (c.symbol || '$') + ' ' + formatted;
}
function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
}
function viewPurchase(id) {
    fetch(comprasBase + '?action=detail&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success || !d.order) return showAlert(d.message || 'No se pudo cargar', 'error');
            var o = d.order, items = o.items || [];
            var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">';
            h += '<div><strong>OC:</strong> ' + esc(o.order_number) + '</div>';
            h += '<div><strong>Estado:</strong> ' + esc(o.status) + '</div>';
            h += '<div><strong>Proveedor:</strong> ' + esc(o.supplier_name || '—') + '</div>';
            h += '<div><strong>Factura:</strong> ' + esc(o.invoice_number || '—') + '</div>';
            h += '<div><strong>Total:</strong> ' + poMoney(o.total) + '</div>';
            h += '<div><strong>Notas crédito:</strong> ' + poMoney(o.credit_total) + '</div>';
            h += '<div><strong>Asiento:</strong> ' + esc(o.accounting_entry_number || (o.accounting_entry_id ? ('#'+o.accounting_entry_id) : 'pendiente')) + '</div>';
            h += '</div>';
            if (o.review_notes) {
                h += '<p style="margin:0 0 12px;padding:10px 12px;border-radius:8px;background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.2);font-size:13px;">'
                    + '<strong>Observación:</strong> ' + esc(o.review_notes) + '</p>';
            }
            if (items.length) {
                h += '<table><thead><tr><th>Producto</th><th>Cant</th><th>Costo</th><th>Total</th></tr></thead><tbody>';
                items.forEach(function(it) {
                    h += '<tr><td>' + esc(it.product_name) + '</td><td>' + it.quantity + '</td>';
                    h += '<td>' + poMoney(it.unit_cost) + '</td>';
                    h += '<td>' + poMoney(it.line_total) + '</td></tr>';
                });
                h += '</tbody></table>';
            }
            var notes = o.credit_notes || [];
            if (notes.length) {
                h += '<h4 style="margin-top:16px;">Notas de crédito</h4>';
                h += '<table><thead><tr><th>Número</th><th>Fecha</th><th>Motivo</th><th>Stock</th><th>Asiento</th><th>Total</th><th></th></tr></thead><tbody>';
                notes.forEach(function(n) {
                    h += '<tr><td>' + esc(n.credit_number || '') + '</td>';
                    h += '<td>' + esc((n.credit_date || '').substr(0,10)) + '</td>';
                    h += '<td>' + esc(n.reason || n.external_number || '—') + '</td>';
                    h += '<td>' + (parseInt(n.affects_stock, 10) === 1 ? 'Sí' : 'No') + '</td>';
                    h += '<td>' + esc(n.accounting_entry_number || '—') + '</td>';
                    h += '<td>' + poMoney(n.total) + '</td>';
                    h += '<td><a class="btn btn-sm btn-primary" href="' + comprasBase + '?action=credit_note_pdf&id=' + n.id + '" target="_blank" rel="noopener">PDF</a></td></tr>';
                });
                h += '</tbody></table>';
            } else {
                h += '<p class="rpt-empty" style="padding:12px;">Sin notas de crédito en esta OC</p>';
            }
            document.getElementById('purchaseDetailContent').innerHTML = h;
            document.getElementById('purchaseDetailModal').style.display = 'flex';
        });
}
</script>
<?php return; endif; ?>

<?php
$comprasBase = $viewInstance->route('app/compras');
$tabBtn = static function (bool $active): string {
    return $active
        ? 'btn btn-primary'
        : 'btn btn-secondary';
};
?>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
    <a href="<?php echo $comprasBase; ?>" class="<?php echo $tabBtn(!$isFacturasTab); ?>">Órdenes de compra</a>
    <a href="<?php echo $comprasBase; ?>?action=facturas" class="<?php echo $tabBtn($isFacturasTab); ?>">Facturas</a>
    <a href="<?php echo $comprasBase; ?>?action=notas_credito" class="btn btn-secondary">Notas de crédito</a>
    <a href="<?php echo $comprasBase; ?>?action=reposicion" class="btn btn-secondary">Panel de reposición</a>
</div>

<?php if ($isFacturasTab): ?>
<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Por pagar</h4><div class="stat-value" style="color:<?php echo $payableTotal > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo fmtC((float)$payableTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Recibido este mes</h4><div class="stat-value" style="color:#10B981;"><?php echo fmtC((float)$monthReceived, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Abonos del mes</h4><div class="stat-value"><?php echo fmtC((float)$paidMonth, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Facturas</h4><div class="stat-value"><?php echo (int)($pagination['total'] ?? count($orders)); ?></div></div>
</div>
<?php else: ?>
<div class="stats-grid compras-stats-grid">
    <div class="stat-card neumorphic stat-card--stack">
        <h4>OC abiertas</h4>
        <div class="stat-value"><?php echo (int)($openCount ?? 0); ?></div>
        <small><?php echo fmtC((float)$openTotal, $currency); ?> en pedidos</small>
    </div>
    <div class="stat-card neumorphic stat-card--stack">
        <h4>Recibido este mes</h4>
        <div class="stat-value" style="color:#10B981;"><?php echo fmtC((float)$monthReceived, $currency); ?></div>
    </div>
    <div class="stat-card neumorphic stat-card--stack">
        <h4>Por pagar</h4>
        <div class="stat-value" style="color:<?php echo $payableTotal > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo fmtC((float)$payableTotal, $currency); ?></div>
        <small><a href="<?php echo $comprasBase; ?>?action=facturas">Ver en Facturas →</a></small>
    </div>
    <div class="stat-card neumorphic stat-card--stack">
        <h4>Pedidos listados</h4>
        <div class="stat-value"><?php echo (int)($pagination['total'] ?? count($orders)); ?></div>
        <small>Según filtro activo</small>
    </div>
</div>
<?php endif; ?>

<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <?php if (!$isFacturasTab && $canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="openPurchaseModal()"
                title="Crear una OC solo de pedido (sin inventario ni CxP hasta recibir/cargar factura)">Nueva orden de compra</button>
    <?php endif; ?>
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-secondary" onclick="openInvoiceUpload()"
                title="Leer foto/PDF/Excel de factura: revise líneas, case con OC y confirme para inventario + CxP">Cargar factura de proveedor</button>
    <?php endif; ?>
    <?php
    $canExportCompras = \SoftNova\Core\TenantMiddleware::canDo('export', 'compras');
    $exportPanel = $isFacturasTab ? 'facturas' : 'ordenes';
    $exportQs = http_build_query(array_merge($listQuery, [
        'action' => 'export',
        'panel' => $exportPanel,
    ]));
    ?>
    <?php if ($canExportCompras): ?>
        <a href="<?php echo $comprasBase . '?' . $exportQs; ?>" class="btn btn-secondary"
           title="Exporta el listado actual. Si hay filtros aplicados, solo exporta lo filtrado; si no, exporta todo.">Exportar CSV</a>
    <?php endif; ?>
</div>

<form method="GET" action="<?php echo $comprasBase; ?>" class="inv-filters-form" style="margin-bottom:16px;">
    <?php if ($isFacturasTab): ?>
        <input type="hidden" name="action" value="facturas">
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
            <label style="font-size:12px;">Buscar</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($qFilter); ?>" class="form-control"
                   placeholder="<?php echo $isFacturasTab ? 'Nº factura, OC, proveedor…' : 'Nº OC, proveedor…'; ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Proveedor</label>
            <select name="supplier_id" class="form-control" style="width:auto;max-width:220px;">
                <option value="0">Todos</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?php echo (int)$sup['id']; ?>" <?php echo $supplierFilter === (int)$sup['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$sup['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Desde</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($fromFilter); ?>" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Hasta</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($toFilter); ?>" class="form-control">
        </div>
        <?php if ($isFacturasTab): ?>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Pago</label>
                <select name="payment" class="form-control" style="width:auto;">
                    <option value="">Todas (activas + anuladas)</option>
                    <option value="active" <?php echo $paymentFilter === 'active' ? 'selected' : ''; ?>>Solo activas</option>
                    <option value="voided" <?php echo $paymentFilter === 'voided' ? 'selected' : ''; ?>>Solo anuladas</option>
                    <option value="unpaid" <?php echo $paymentFilter === 'unpaid' ? 'selected' : ''; ?>>Con saldo</option>
                    <option value="partial" <?php echo $paymentFilter === 'partial' ? 'selected' : ''; ?>>Abono parcial</option>
                    <option value="paid" <?php echo $paymentFilter === 'paid' ? 'selected' : ''; ?>>Pagadas</option>
                    <option value="paid_only" <?php echo $paymentFilter === 'paid_only' ? 'selected' : ''; ?>>Saldo en cero</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Vencimiento</label>
                <select name="due" class="form-control" style="width:auto;">
                    <option value="">Todos</option>
                    <option value="overdue" <?php echo $dueFilter === 'overdue' ? 'selected' : ''; ?>>Vencidas</option>
                    <option value="due_today" <?php echo $dueFilter === 'due_today' ? 'selected' : ''; ?>>Vencen hoy</option>
                    <option value="due_soon" <?php echo $dueFilter === 'due_soon' ? 'selected' : ''; ?>>Por vencer (7 días)</option>
                    <option value="no_due" <?php echo $dueFilter === 'no_due' ? 'selected' : ''; ?>>Sin fecha</option>
                </select>
            </div>
        <?php else: ?>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Estado</label>
                <select name="status" class="form-control" style="width:auto;">
                    <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Abiertas (borrador + ordenada)</option>
                    <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>Todas</option>
                    <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Borrador</option>
                    <option value="ordered" <?php echo $statusFilter === 'ordered' ? 'selected' : ''; ?>>Ordenada</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelada</option>
                </select>
            </div>
        <?php endif; ?>
        <input type="hidden" name="per_page" value="<?php echo (int)($pagination['perPage'] ?? 20); ?>">
        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="<?php echo $isFacturasTab ? ($comprasBase . '?action=facturas') : $comprasBase; ?>" class="btn btn-secondary">Limpiar</a>
        </div>
    </div>
</form>

<p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
    <?php if ($isFacturasTab): ?>
        <strong>Momento 2–3:</strong> al llegar la mercancía cargue o confirme la factura (inventario + asiento).
        Aquí controla <strong>saldo</strong>, <strong>abonos</strong>, <strong>notas de crédito</strong> y facturas pagadas.
        Las facturas <strong>anuladas</strong> permanecen en la lista (badge rojo) y en el historial de movimientos para trazabilidad.
        Puede casar cada factura con una OC de pedido.
    <?php else: ?>
        <strong>Momento 1:</strong> las OC solo solicitan pedido al proveedor (sin saldo ni abonos).
        Cuando llega la factura y la mercancía, use <a href="<?php echo $comprasBase; ?>?action=facturas">Facturas</a>
        o el botón <strong>Recibir</strong>.
    <?php endif; ?>
</p>

<?php if ($isFacturasTab && !empty($pendingInvoices)): ?>
<div class="card neumorphic" style="margin-bottom:18px;border-left:4px solid #F59E0B;">
    <div class="card-header"><h3>Facturas leídas pendientes de confirmar</h3></div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--color-text-secondary);margin-top:0;">
            Ya interpretadas pero <strong>aún no afectan el inventario</strong>. Revise líneas (puede agregar productos a mano) y confirme.
        </p>
        <div class="table-container"><table>
            <thead><tr><th>Factura</th><th>Proveedor</th><th>Fecha</th><th>Origen</th><th>Líneas</th><th>Total</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($pendingInvoices as $inv): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($inv['invoice_number'] ?? '—')); ?></td>
                    <td><?php echo htmlspecialchars((string)($inv['supplier_name'] ?? 'Sin asignar')); ?></td>
                    <td><?php echo htmlspecialchars((string)($inv['invoice_date'] ?? '—')); ?></td>
                    <td><?php echo htmlspecialchars(strtoupper((string)$inv['source'])); ?></td>
                    <td><?php echo (int)$inv['items_count']; ?></td>
                    <td><?php echo fmtC((float)$inv['total'], $currency); ?></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="openInvoiceReview(<?php echo (int)$inv['id']; ?>, this)">Revisar</button>
                        <?php if ($canCreate): ?>
                            <form method="POST" action="<?php echo $comprasBase; ?>?action=invoice_discard" style="display:inline;">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="import_id" value="<?php echo (int)$inv['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Descartar esta factura leída?')">Descartar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php elseif (!$isFacturasTab && !empty($pendingInvoices)): ?>
<div class="card neumorphic" style="margin-bottom:18px;border-left:4px solid #F59E0B;">
    <div class="card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <p style="margin:0;flex:1;font-size:13px;">
            Hay <strong><?php echo count($pendingInvoices); ?></strong> factura(s) leída(s) pendientes de confirmar.
        </p>
        <a href="<?php echo $comprasBase; ?>?action=facturas" class="btn btn-primary">Ir a Facturas</a>
    </div>
</div>
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header"><h3><?php echo $isFacturasTab ? 'Facturas de proveedor' : 'Órdenes de compra'; ?></h3></div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <?php
            $hasCompraFilters = $qFilter !== '' || $fromFilter !== '' || $toFilter !== '' || $supplierFilter > 0
                || ($isFacturasTab && ($paymentFilter !== '' || $dueFilter !== ''))
                || (!$isFacturasTab && $statusFilter !== '' && $statusFilter !== 'open');
            ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">
                <?php
                if ($hasCompraFilters) {
                    echo $isFacturasTab ? 'No hay facturas con esos filtros' : 'No hay órdenes con esos filtros';
                } else {
                    echo $isFacturasTab ? 'Aún no hay facturas recibidas' : 'Aún no hay órdenes de compra';
                }
                ?>
            </p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead>
                    <tr>
                        <?php if ($isFacturasTab): ?>
                            <th>Factura</th><th>OC</th><th>Fecha</th><th>Vence</th><th>Proveedor</th><th>Foto</th>
                            <th>Total</th><th>NC</th><th>Neto</th><th>Abonado</th><th>Saldo</th><th>Pago</th><th>Asiento</th><th>Acciones</th>
                        <?php else: ?>
                            <th>OC</th><th>Fecha</th><th>Entrega est.</th><th>Proveedor</th>
                            <th>Total est.</th><th>Estado</th><th>Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <?php
                    $st = $o['status'] ?? '';
                    $badge = match ($st) {
                        'received' => 'badge-success',
                        'cancelled' => 'badge-danger',
                        'ordered' => 'badge-warning',
                        default => 'badge-secondary',
                    };
                    $creditTot = round((float)($o['credit_total'] ?? 0), 2);
                    $orderNet = round((float)$o['total'] - $creditTot, 2);
                    $orderBalance = round(
                        (float)$o['total']
                        - (float)($o['paid_total'] ?? 0)
                        - $creditTot,
                        2
                    );
                    $isVoided = $st === 'cancelled' && (
                        !empty($o['voided_at'])
                        || str_contains((string)($o['notes'] ?? ''), 'ANULADA POST-RECEPCIÓN')
                        || str_contains((string)($o['notes'] ?? ''), '[[NC_FULL')
                        || $creditTot > 0.009
                    );
                    $isPayable = $st === 'received'
                        && ($o['payment_mode'] ?? 'payable') === 'payable'
                        && $orderBalance > 0.009;
                    $fullyCredited = $st === 'received' && $creditTot > 0.009 && $orderNet <= 0.009;
                    $dueDate = $o['due_date'] ?? null;
                    $daysOver = isset($o['days_overdue']) ? (int)$o['days_overdue'] : null;
                    $dueOverdue = $dueDate && $daysOver !== null && $daysOver > 0 && $isPayable;
                    $dueSoon = $dueDate && $daysOver !== null && $daysOver <= 0 && $daysOver >= -7 && $isPayable;
                    $payLabel = match ($o['payment_mode'] ?? 'payable') {
                        'cash' => 'Efectivo',
                        'transfer' => 'Transferencia',
                        'card' => 'Tarjeta',
                        default => 'Crédito',
                    };
                    ?>
                    <tr<?php echo ($fullyCredited || $isVoided) ? ' style="opacity:0.78;"' : ''; ?>>
                        <?php if ($isFacturasTab): ?>
                            <td>
                                <strong><?php echo htmlspecialchars((string)($o['invoice_number'] ?? '—')); ?></strong>
                                <?php if ($isVoided): ?>
                                    <div><span class="badge badge-danger" title="Anulada post-recepción · queda en historial">Anulada</span></div>
                                <?php elseif ($fullyCredited): ?>
                                    <div><span class="badge badge-warning" title="Totalmente acreditada / devolución">Devuelta</span></div>
                                <?php elseif ($creditTot > 0.009): ?>
                                    <div><span class="badge badge-info" title="Tiene nota(s) de crédito">Con NC</span></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($o['order_number']); ?></td>
                            <td><?php echo !empty($o['invoice_date']) ? date('d/m/Y', strtotime((string)$o['invoice_date'])) : date('d/m/Y', strtotime($o['order_date'])); ?></td>
                            <td<?php
                                if ($dueOverdue) {
                                    echo ' style="color:#DC2626;font-weight:600;"';
                                } elseif ($dueSoon) {
                                    echo ' style="color:#B45309;font-weight:600;"';
                                }
                            ?>>
                                <?php if ($dueDate): ?>
                                    <?php echo date('d/m/Y', strtotime((string)$dueDate)); ?>
                                    <?php if ($dueOverdue): ?>
                                        <small>(<?php echo $daysOver; ?> d)</small>
                                    <?php elseif ($dueSoon && $daysOver === 0): ?>
                                        <small>(hoy)</small>
                                    <?php elseif ($dueSoon): ?>
                                        <small>(<?php echo abs($daysOver); ?> d)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($o['supplier_name'] ?? '—'); ?></td>
                            <td>
                                <?php if (!empty($o['invoice_path'])): ?>
                                    <a class="btn btn-sm btn-secondary" href="<?php echo $comprasBase; ?>?action=invoice&id=<?php echo (int)$o['id']; ?>" target="_blank" title="Ver foto/PDF factura">Ver</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?php echo fmtC((float)$o['total'], $currency); ?></td>
                            <td style="color:<?php echo $creditTot > 0.009 ? '#B45309' : 'inherit'; ?>;">
                                <?php echo $creditTot > 0.009 ? ('−' . fmtC($creditTot, $currency)) : '—'; ?>
                            </td>
                            <td style="font-weight:600;"><?php echo fmtC(max(0, $orderNet), $currency); ?></td>
                            <td style="color:#10B981;"><?php echo fmtC((float)($o['paid_total'] ?? 0), $currency); ?></td>
                            <td style="font-weight:600;color:<?php echo $isPayable ? '#DC2626' : '#10B981'; ?>;">
                                <?php echo fmtC(max(0, $orderBalance), $currency); ?>
                            </td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($payLabel); ?></td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($o['accounting_entry_id'] ? ('#' . $o['accounting_entry_id']) : '—'); ?></td>
                            <td class="table-actions" style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="viewPurchase(<?php echo (int)$o['id']; ?>)" title="Ver detalle, historial y trazabilidad">Ver</button>
                                <?php
                                $hasObs = trim((string)($o['review_notes'] ?? '')) !== '';
                                $obsPayload = htmlspecialchars(json_encode([
                                    'id' => (int)$o['id'],
                                    'order' => (string)$o['order_number'],
                                    'invoice' => (string)($o['invoice_number'] ?? ''),
                                    'notes' => (string)($o['review_notes'] ?? ''),
                                    'canEdit' => (bool)$canEdit,
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES);
                                ?>
                                <button type="button" class="btn btn-sm <?php echo $hasObs ? 'btn-primary' : 'btn-secondary'; ?>"
                                        onclick="openObservationsModal(<?php echo $obsPayload; ?>)"
                                        title="<?php echo $hasObs ? 'Ver / editar observaciones' : 'Agregar observaciones'; ?>">
                                    Observaciones<?php echo $hasObs ? ' ·' : ''; ?>
                                </button>
                                <?php if (!$isVoided && $canEdit && !$fullyCredited): ?>
                                    <button type="button" class="btn btn-sm btn-secondary"
                                            onclick="openCreditNoteModal(<?php echo (int)$o['id']; ?>)"
                                            title="Nota de crédito / devolución">NC</button>
                                <?php endif; ?>
                                <?php if (!$isVoided && $canEdit && $isPayable): ?>
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="openPayModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>', <?php echo $orderBalance; ?>, '<?php echo htmlspecialchars($o['supplier_name'] ?? '', ENT_QUOTES); ?>')"
                                            title="Registrar abono al proveedor">Abonar</button>
                                <?php endif; ?>
                                <?php if (!$isVoided && ($canEdit || !empty($canDelete)) && !$fullyCredited): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="voidReceivedOrder(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>')"
                                            title="Anular factura recibida por error (devolución total + stock + asiento)">Anular</button>
                                <?php elseif (!$isVoided && ($canEdit || !empty($canDelete)) && $fullyCredited): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="voidReceivedOrder(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>')"
                                            title="Marcar como anulada (ya tiene NC total)">Cerrar anulación</button>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td><strong><?php echo htmlspecialchars($o['order_number']); ?></strong></td>
                            <td><?php echo date('d/m/Y', strtotime($o['order_date'])); ?></td>
                            <td><?php echo !empty($o['expected_date']) ? date('d/m/Y', strtotime((string)$o['expected_date'])) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($o['supplier_name'] ?? 'Sin proveedor'); ?></td>
                            <td style="font-weight:600;"><?php echo fmtC((float)$o['total'], $currency); ?></td>
                            <td><span class="badge <?php echo $badge; ?>"><?php echo $statusLabels[$st] ?? $st; ?></span></td>
                            <td class="table-actions" style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="viewPurchase(<?php echo (int)$o['id']; ?>)" title="Ver detalle de la orden, ítems y EAN">Ver</button>
                                <?php if ($canEdit && in_array($st, ['draft', 'ordered'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="editPurchase(<?php echo (int)$o['id']; ?>)" title="Editar pedido">Editar</button>
                                <?php endif; ?>
                                <?php if ($canCreate): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="duplicatePurchase(<?php echo (int)$o['id']; ?>)" title="Crear una OC nueva copiando productos y cantidades">Duplicar</button>
                                <?php endif; ?>
                                <?php if (in_array($st, ['draft', 'ordered'], true)): ?>
                                    <a href="<?php echo $comprasBase; ?>?action=order_pdf&id=<?php echo (int)$o['id']; ?>"
                                       class="btn btn-sm btn-secondary" target="_blank" rel="noopener"
                                       title="Descargar PDF de la orden para el proveedor (incluye EAN)">PDF</a>
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="btn btn-sm btn-success"
                                                onclick="poWhatsapp(<?php echo (int)$o['id']; ?>)"
                                                title="Abrir WhatsApp con el detalle de la OC (productos y EAN)">WhatsApp</button>
                                        <button type="button" class="btn btn-sm btn-info"
                                                onclick="poEmail(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['supplier_name'] ?? '', ENT_QUOTES); ?>')"
                                                title="Enviar la OC por correo con PDF adjunto">Correo</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($canEdit && in_array($st, ['draft', 'ordered'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-success" onclick="openReceiveModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>')" title="Registrar llegada a bodega: factura, vencimiento, pago e inventario">Recibir</button>
                                <?php endif; ?>
                                <?php if ($canDelete && in_array($st, ['draft', 'ordered'], true)): ?>
                                    <form method="POST" action="<?php echo $comprasBase; ?>?action=cancel" style="display:inline;" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Cancelar esta OC?')" title="Cancelar la orden (no se puede si ya fue recibida)">✕</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
            <?php
            $paginationBaseUrl = $comprasBase;
            $paginationQuery = $listQuery ?: ($isFacturasTab
                ? array_filter([
                    'action' => 'facturas',
                    'payment' => $paymentFilter !== '' ? $paymentFilter : null,
                    'due' => $dueFilter !== '' ? $dueFilter : null,
                ])
                : array_filter([
                    'status' => $statusFilter !== '' && $statusFilter !== 'open' ? $statusFilter : null,
                ]));
            $paginationPerPageOptions = $perPageOptions;
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery', 'paginationPerPageOptions'));
            ?>
    </div>
</div>

<!-- Modal Nueva OC (solo documento de pedido) -->
<div id="purchaseModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:820px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3 id="purchaseModalTitle">Nueva orden de compra</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('purchaseModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=create" data-ajax="true" data-prepare="purchaseItems" id="purchaseForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="duplicated_from_id" id="poDuplicatedFromId" value="">
            <input type="hidden" name="order_id" id="poEditId" value="">
            <input type="hidden" name="save_as_draft" id="poSaveAsDraft" value="0">
            <div class="modal-body">
                <p id="purchaseModalHint" style="display:none;font-size:13px;color:var(--color-text-secondary);margin:0 0 12px;">
                    Copia editable: ajuste proveedor, fechas e ítems. La factura y recepción se hacen aparte.
                </p>
                <p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 12px;">
                    La OC es solo el pedido para enviar al proveedor.
                    Foto, vencimiento, dsto. pronto pago y recepción van en <strong>Cargar factura</strong> o al <strong>Recibir</strong>.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;">
                        <label>Proveedor <span class="field-tip" data-tip="A quién se solicita el pedido. Puede dejarse vacío y asignarlo después al recibir o cargar la factura." tabindex="0">?</span></label>
                        <select name="supplier_id" id="poSupplierId" class="form-control">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Fecha OC * <span class="field-tip" data-tip="Fecha del documento de pedido (solicitud al proveedor). No es la fecha de ingreso a bodega." tabindex="0">?</span></label>
                        <input type="date" name="order_date" id="poOrderDate" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Entrega estimada <span class="field-tip" data-tip="Fecha aproximada en que espera que llegue la mercancía. Sirve para seguimiento, no afecta inventario." tabindex="0">?</span></label>
                        <input type="date" name="expected_date" id="poExpectedDate" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notas <span class="field-tip" data-tip="Observaciones internas o para el proveedor (condiciones, contacto, referencia del pedido)." tabindex="0">?</span></label>
                    <input type="text" name="notes" id="poNotes" class="form-control" placeholder="Observaciones del pedido">
                </div>

                <h4 style="margin:12px 0 8px;">Ítems</h4>
                <div class="form-group" style="margin-bottom:8px;position:relative;">
                    <label>Buscar / escanear producto <span class="field-tip" data-tip="Busque por nombre, SKU o EAN. Con pistola: escanee y pulse Enter para agregar. Solo productos existentes del catálogo." tabindex="0">?</span></label>
                    <input type="search" id="poProductSearch" class="form-control" placeholder="Nombre, SKU o EAN… Enter para agregar"
                           autocomplete="off" data-barcode-input="true" title="Escriba para buscar o use la pistola de códigos">
                    <ul class="combobox-list" id="poProductSuggest" hidden style="z-index:30;"></ul>
                    <small style="color:var(--color-text-secondary);">Escriba el nombre o escanee el código. Enter agrega el producto a la orden.</small>
                </div>
                <div id="poItems"></div>
                <button type="button" class="btn btn-secondary" onclick="addPoItem()" style="margin-top:8px;">+ Agregar línea vacía</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('purchaseModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-secondary" id="poSaveDraftBtn" onclick="document.getElementById('poSaveAsDraft').value='1'">Guardar borrador</button>
                <button type="submit" class="btn btn-primary" id="poSaveOrderBtn" onclick="document.getElementById('poSaveAsDraft').value='0'">Guardar y generar OC</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Recibir -->
<div id="receiveModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Recibir mercancía / factura</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('receiveModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=receive" data-ajax="true" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="receiveOrderId">
            <p id="receiveOrderLabel" style="font-weight:600;margin-bottom:10px;"></p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="margin:0;">
                    <label>Fecha ingreso a bodega * <span class="field-tip" data-tip="Fecha real en que la mercancía entra al inventario. Define el movimiento de stock y el asiento contable." tabindex="0">?</span></label>
                    <input type="date" name="warehouse_date" id="receiveWarehouseDate" class="form-control" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Fecha factura <span class="field-tip" data-tip="Fecha impresa en la factura del proveedor. Puede diferir de la fecha de bodega." tabindex="0">?</span></label>
                    <input type="date" name="invoice_date" id="receiveInvoiceDate" class="form-control">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>N° factura proveedor <span class="field-tip" data-tip="Número o consecutivo de la factura del proveedor. Queda en la trazabilidad y en Facturas." tabindex="0">?</span></label>
                    <input type="text" name="invoice_number" class="form-control" placeholder="FV-001">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Vencimiento de pago <span class="field-tip" data-tip="Fecha límite para pagar al proveedor (CxP). Se usa en filtros de vencidas / por vencer." tabindex="0">?</span></label>
                    <input type="date" name="due_date" id="receiveDueDate" class="form-control">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Desc. pronto pago % <span class="field-tip" data-tip="Solo aplica si paga antes del vencimiento. Reduce el total de la OC/factura." tabindex="0">?</span></label>
                    <input type="number" step="0.01" min="0" max="100" name="early_payment_discount_pct" id="receiveEarlyDiscount" class="form-control" value="" placeholder="0">
                </div>
                <div class="form-group" style="margin:0;grid-column:1/-1;">
                    <label>Desc. comercial <span class="field-tip" data-tip="Descuento general de la factura (siempre aplica). Indique % o monto $; si hay monto, manda el $." tabindex="0">?</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <input type="number" step="0.01" min="0" max="100" name="commercial_discount_pct" id="receiveCommercialPct" class="form-control" value="" placeholder="%">
                        <input type="text" inputmode="decimal" name="commercial_discount_amount" id="receiveCommercialAmt" class="form-control money-input" data-money="1" value="" placeholder="$ monto" autocomplete="off">
                    </div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Pago / contrapartida * <span class="field-tip" data-tip="Crédito = queda por pagar (220505). Efectivo/transferencia/tarjeta = sale de caja o bancos al recibir." tabindex="0">?</span></label>
                    <select name="payment_mode" class="form-control" required>
                        <option value="payable">Por pagar (220505) + IVA descontable</option>
                        <option value="cash">Efectivo (sale del cajón)</option>
                        <option value="transfer">Transferencia</option>
                        <option value="card">Tarjeta</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Foto / PDF factura <span class="field-tip" data-tip="Adjunto de evidencia (foto o PDF). Se consulta después desde Facturas → Ver." tabindex="0">?</span></label>
                <input type="file" name="invoice_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
            </div>
            <div class="form-group">
                <label>Notas <span class="field-tip" data-tip="Comentarios de la recepción (faltantes, novedades, calidad)." tabindex="0">?</span></label>
                <input type="text" name="notes" class="form-control">
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">Si elige <strong>Efectivo</strong>, debe haber una caja abierta: el total se registra como egreso en Caja.</p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('receiveModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Recibir y contabilizar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Observaciones de factura -->
<div id="observationsModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Observaciones de factura</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('observationsModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $comprasBase; ?>?action=review_notes" data-ajax="true" id="observationsForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="obsOrderId" value="">
            <p id="obsOrderLabel" style="font-weight:600;margin-bottom:12px;"></p>
            <div class="form-group">
                <label for="obsNotes">Observaciones <span class="field-tip" data-tip="Notas internas sobre esta factura (diferencias, acuerdos con el proveedor, pendientes de revisión, etc.). Quedan en la trazabilidad." tabindex="0">?</span></label>
                <textarea name="review_notes" id="obsNotes" class="form-control" rows="5" maxlength="2000"
                          placeholder="Escriba aquí las observaciones de esta factura…"
                          <?php echo $canEdit ? '' : 'readonly'; ?>></textarea>
                <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">Máximo 2000 caracteres. Vaciar el texto y guardar elimina la observación.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('observationsModal').style.display='none'">Cerrar</button>
                <?php if ($canEdit): ?>
                    <button type="submit" class="btn btn-primary" id="obsSaveBtn">Guardar</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Modal Abono a proveedor -->
<div id="payModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Registrar abono al proveedor</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('payModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=pay"
              data-ajax="true" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="payOrderId">
            <p id="payOrderLabel" style="font-weight:600;margin-bottom:4px;"></p>
            <p id="payBalanceLabel" style="font-size:13px;color:var(--color-text-secondary);margin-bottom:12px;"></p>
            <div class="form-group">
                <label>Valor del abono * <span class="field-tip" data-tip="Monto que paga ahora al proveedor. No puede superar el saldo pendiente (total − abonos − notas de crédito)." tabindex="0">?</span></label>
                <input type="text" inputmode="decimal" name="amount" id="payAmount" class="form-control money-input" data-money="1" required placeholder="0">
            </div>
            <div class="form-group">
                <label>Fecha del pago * <span class="field-tip" data-tip="Fecha real del desembolso. Define la fecha del asiento contable del abono." tabindex="0">?</span></label>
                <input type="date" name="payment_date" id="payDate" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Medio de pago * <span class="field-tip" data-tip="Efectivo: elija si sale del cajón o de otro origen. Transferencia/tarjeta afectan bancos (111005)." tabindex="0">?</span></label>
                <select name="payment_method" id="payMethod" class="form-control" required>
                    <option value="cash">Efectivo</option>
                    <option value="transfer">Transferencia (111005)</option>
                    <option value="card">Tarjeta (111005)</option>
                </select>
            </div>
            <div id="payCashSourceWrap" class="form-group" style="display:none;">
                <label>Origen del efectivo * <span class="field-tip" data-tip="Caja: descuenta el cajón de la sesión abierta (110505). Otro: no toca la caja; indique de dónde salió el dinero." tabindex="0">?</span></label>
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin:6px 0 8px;">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:500;cursor:pointer;">
                        <input type="radio" name="cash_source" id="payCashDrawer" value="drawer" checked>
                        Caja (descuenta del cajón)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:500;cursor:pointer;">
                        <input type="radio" name="cash_source" id="payCashOther" value="other">
                        Otro (no descuenta de caja)
                    </label>
                </div>
                <p id="payCashDrawerHint" style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;">
                    El abono en efectivo se registra como egreso en la caja abierta.
                </p>
                <div id="payCashOtherWrap" style="display:none;">
                    <label for="payCashSourceNote">¿De dónde salió el efectivo? *</label>
                    <textarea name="cash_source_note" id="payCashSourceNote" class="form-control" rows="2"
                              placeholder="Ej: caja fuerte, retiro bancario, fondos del dueño…" maxlength="255"></textarea>
                    <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                        Obligatorio si elige Otro. No se descontará de la caja abierta; el asiento usa bancos (111005).
                    </small>
                </div>
            </div>
            <div class="form-group">
                <label>Referencia / comprobante <span class="field-tip" data-tip="Número de transferencia, voucher o recibo. Útil para conciliar con el extracto bancario." tabindex="0">?</span></label>
                <input type="text" name="reference" class="form-control" placeholder="N° transacción o recibo">
            </div>
            <div class="form-group">
                <label>Foto del comprobante <span class="field-tip" data-tip="Adjunte la foto o PDF del comprobante de consignación, transferencia o pago. Queda guardado con el abono para consulta posterior." tabindex="0">?</span></label>
                <input type="file" name="payment_receipt" id="payReceiptFile" class="form-control"
                       accept="image/jpeg,image/png,image/webp,application/pdf">
                <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                    Opcional. JPG, PNG, WEBP o PDF · máx. 5 MB.
                </small>
            </div>
            <div class="form-group">
                <label>Notas <span class="field-tip" data-tip="Observación interna del abono (quién pagó, acuerdo, etc.)." tabindex="0">?</span></label>
                <input type="text" name="notes" class="form-control">
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);">
                Genera el asiento <em>Proveedores (220505) débito / Caja o Bancos crédito</em>.
            </p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('payModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar abono</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="poEmailModal" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:440px;">
        <div class="modal-header">
            <h3>Enviar orden por correo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('poEmailModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $comprasBase; ?>?action=order_email">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="poEmailOrderId">
            <p style="margin-top:0;font-weight:600;" id="poEmailSupplier"></p>
            <div class="form-group">
                <label>Correo destino <span class="field-tip" data-tip="Si lo deja vacío se usa el email del proveedor. Se adjunta el PDF de la OC con productos y EAN." tabindex="0">?</span></label>
                <input type="email" name="email" class="form-control" placeholder="Se usa el del proveedor si lo deja vacío">
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);">
                Se adjunta el PDF de la orden. Si el hosting no tiene correo saliente, descargue el PDF y envíelo manualmente.
            </p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('poEmailModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isFacturasTab && !empty($payableOrders)): ?>
<div class="card neumorphic" style="margin-top:18px;">
    <div class="card-header"><h3>Cuentas por pagar a proveedores<?php
        echo match ($dueFilter) {
            'overdue' => ' — vencidas',
            'due_today' => ' — vencen hoy',
            'due_soon' => ' — por vencer (7 días)',
            default => '',
        };
    ?></h3></div>
    <div class="card-body">
        <div class="table-container"><table>
            <thead>
                <tr>
                    <th>OC</th><th>Proveedor</th><th>Factura</th><th>Vence</th>
                    <th>Total</th><th>Abonado</th><th>Saldo</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payableOrders as $p): ?>
                <?php
                $saldo = round((float)$p['total'] - (float)$p['paid_amount'] - (float)($p['credit_amount'] ?? 0), 2);
                $overdue = $p['due_date'] && (int)($p['days_overdue'] ?? 0) > 0;
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($p['order_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($p['supplier_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['invoice_number'] ?? '—'); ?></td>
                    <td<?php echo $overdue ? ' style="color:#DC2626;font-weight:600;"' : ''; ?>>
                        <?php echo $p['due_date'] ? date('d/m/Y', strtotime($p['due_date'])) : '—'; ?>
                        <?php echo $overdue ? ' (' . (int)$p['days_overdue'] . ' d)' : ''; ?>
                    </td>
                    <td><?php echo fmtC((float)$p['total'], $currency); ?></td>
                    <td style="color:#10B981;"><?php echo fmtC((float)$p['paid_amount'], $currency); ?></td>
                    <td style="font-weight:600;color:#DC2626;"><?php echo fmtC($saldo, $currency); ?></td>
                    <td>
                        <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-sm btn-primary"
                                    onclick="openPayModal(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars($p['order_number'], ENT_QUOTES); ?>', <?php echo $saldo; ?>, '<?php echo htmlspecialchars($p['supplier_name'] ?? '', ENT_QUOTES); ?>')">Abonar</button>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="openCreditNoteModal(<?php echo (int)$p['id']; ?>)">NC</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<!-- Modal cargar factura de proveedor -->
<div id="invoiceUploadModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic invoice-shell-modal" style="max-width:620px;">
        <div class="modal-header">
            <h3>Cargar factura de proveedor</h3>
            <button type="button" class="modal-close" onclick="closeInvoiceUpload()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="invoiceUploadForm" class="invoice-shell-form"
              action="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_import">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="invoice-shell-body">
                <p style="margin-top:0;font-size:13px;color:var(--color-text-secondary);">
                    Primero elija el <strong>tipo de documento</strong> (define el IVA de los productos).
                    Luego puede <strong>cargar un archivo</strong> (foto/PDF/Excel), <strong>pegar texto</strong>
                    o <strong>continuar sin archivo</strong> y agregar productos a mano (buscar / escanear).
                    En la revisión también podrá <strong>cruzar con una OC</strong> abierta.
                    <strong>Nada entra al inventario hasta que confirme.</strong>
                </p>
                <div class="form-group" style="margin-bottom:14px;">
                    <label>Tipo de documento * <span class="field-tip" data-tip="F. Electrónica: IVA (p.ej. 19%) se suma al costo/precio de compra de cada producto. Remisión: sin IVA. F. Normal: se pregunta si llevan IVA y luego puede ajustar por producto." tabindex="0">?</span></label>
                    <div class="invoice-doc-kind" role="radiogroup" aria-label="Tipo de documento" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;">
                        <label class="invoice-doc-kind-opt" style="display:flex;flex-direction:column;gap:4px;padding:10px;border:1px solid var(--color-border,#e5e7eb);border-radius:8px;cursor:pointer;margin:0;">
                            <span style="display:flex;align-items:center;gap:6px;font-weight:600;">
                                <input type="radio" name="document_kind" value="electronic" required style="width:auto;margin:0;"> F. Electrónica
                            </span>
                            <small style="color:var(--color-text-secondary);line-height:1.3;">IVA inventario (<?php echo rtrim(rtrim(number_format((float)($defaultTaxRate ?? 19), 2, '.', ''), '0'), '.') ?: '19'; ?>%)</small>
                        </label>
                        <label class="invoice-doc-kind-opt" style="display:flex;flex-direction:column;gap:4px;padding:10px;border:1px solid var(--color-border,#e5e7eb);border-radius:8px;cursor:pointer;margin:0;">
                            <span style="display:flex;align-items:center;gap:6px;font-weight:600;">
                                <input type="radio" name="document_kind" value="standard" style="width:auto;margin:0;"> F. Normal
                            </span>
                            <small style="color:var(--color-text-secondary);line-height:1.3;">IVA sí / no / monto exacto</small>
                        </label>
                        <label class="invoice-doc-kind-opt" style="display:flex;flex-direction:column;gap:4px;padding:10px;border:1px solid var(--color-border,#e5e7eb);border-radius:8px;cursor:pointer;margin:0;">
                            <span style="display:flex;align-items:center;gap:6px;font-weight:600;">
                                <input type="radio" name="document_kind" value="remission" style="width:auto;margin:0;"> Remisión
                            </span>
                            <small style="color:var(--color-text-secondary);line-height:1.3;">Sin IVA (exentos)</small>
                        </label>
                    </div>
                </div>
                <div id="invoiceStandardVatAsk" class="form-group" style="display:none;margin-bottom:14px;padding:12px;border:1px solid #F59E0B;border-radius:8px;background:rgba(245,158,11,0.08);">
                    <label style="font-weight:600;display:block;margin-bottom:8px;">¿Los productos de esta factura llevan IVA? *</label>
                    <div style="display:flex;flex-wrap:wrap;gap:16px;">
                        <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;">
                            <input type="radio" name="apply_product_vat" value="1" style="width:auto;margin:0;"> Sí, aplicar IVA (<?php echo rtrim(rtrim(number_format((float)($defaultTaxRate ?? 19), 2, '.', ''), '0'), '.') ?: '19'; ?>%)
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;">
                            <input type="radio" name="apply_product_vat" value="0" style="width:auto;margin:0;"> No, productos exentos
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;">
                            <input type="radio" name="apply_product_vat" value="2" style="width:auto;margin:0;"> IVA personalizado (monto exacto)
                        </label>
                    </div>
                    <div id="invoiceCustomVatWrap" style="display:none;margin-top:12px;">
                        <label style="font-weight:600;display:block;margin-bottom:6px;">Valor exacto del IVA de la factura *</label>
                        <input type="text" inputmode="decimal" name="tax_override_amount" id="invoiceTaxOverrideAmount"
                               class="form-control money-input" data-money="1" placeholder="0"
                               style="max-width:280px;" autocomplete="off">
                        <small style="display:block;margin-top:6px;color:var(--color-text-secondary);">
                            Use el IVA impreso en la factura (formato moneda). Ese monto se contabiliza en 240802 y se prorratea a las líneas al confirmar.
                        </small>
                    </div>
                    <small id="invoiceStandardVatHint" style="display:block;margin-top:8px;color:var(--color-text-secondary);">
                        En la revisión podrá cambiar el % IVA de cada producto individualmente.
                    </small>
                </div>
                <div id="invoiceElectronicVatAsk" class="form-group" style="display:none;margin-bottom:14px;padding:12px;border:1px solid #3B82F6;border-radius:8px;background:rgba(59,130,246,0.08);">
                    <label style="display:flex;align-items:flex-start;gap:8px;margin:0;cursor:pointer;font-weight:600;">
                        <input type="checkbox" name="vat_already_included" value="1" style="width:auto;margin-top:3px;">
                        <span>Los precios del archivo <strong>ya incluyen IVA</strong>
                            <small style="display:block;font-weight:500;color:var(--color-text-secondary);margin-top:4px;">
                                Márquelo si el Excel/PDF trae costo con IVA. Si no, el sistema sumará el
                                <?php echo rtrim(rtrim(number_format((float)($defaultTaxRate ?? 19), 2, '.', ''), '0'), '.') ?: '19'; ?>% al costo de compra.
                            </small>
                        </span>
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Proveedor * <span class="field-tip" data-tip="Obligatorio para casar líneas, precios y CxP. Puede crear uno con + Nuevo sin perder el archivo." tabindex="0">?</span></label>
                        <div class="customer-combobox invoice-supplier-combobox" id="invoiceSupplierCombobox" data-required="1">
                            <input type="hidden" name="supplier_id" id="invoiceSupplierId" value="" required>
                            <div style="display:flex;gap:8px;align-items:stretch;">
                                <input type="search" id="invoiceSupplierSearch" class="form-control" autocomplete="off"
                                       placeholder="Buscar proveedor por nombre, NIT o teléfono…"
                                       aria-expanded="false" aria-controls="invoiceSupplierList" style="flex:1;">
                                <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                                        onclick="openQuickSupplierModal('upload')" title="Agregar proveedor nuevo">+ Nuevo</button>
                            </div>
                            <ul class="combobox-list" id="invoiceSupplierList" hidden></ul>
                        </div>
                        <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                            Escriba para filtrar. Si no está en la lista, use <strong>+ Nuevo</strong> sin salir de aquí.
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Número de factura <span class="field-tip" data-tip="Consecutivo del proveedor. Si viene en el archivo, la lectura lo propone; puede editarlo en la revisión." tabindex="0">?</span></label>
                        <input type="text" name="invoice_number" class="form-control" maxlength="80" placeholder="Se detecta si viene en el archivo">
                    </div>
                    <div style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
                        <div class="form-group" style="margin:0;">
                            <label>Fecha de factura <span class="field-tip" data-tip="Fecha del documento del proveedor. Se puede ajustar al revisar antes de confirmar." tabindex="0">?</span></label>
                            <input type="date" name="invoice_date" id="uploadInvoiceDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Vencimiento de pago <span class="field-tip" data-tip="Fecha límite de CxP. Alimenta filtros de vencidas / por vencer en Facturas." tabindex="0">?</span></label>
                            <input type="date" name="due_date" id="uploadDueDate" class="form-control">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Recepción de bodega * <span class="field-tip" data-tip="Fecha real de ingreso a inventario y del asiento contable. Puede ser distinta a la fecha de factura (p. ej. si la mercancía llegó otro día)." tabindex="0">?</span></label>
                            <input type="date" name="warehouse_date" id="uploadWarehouseDate" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Forma de pago <span class="field-tip" data-tip="Crédito deja saldo por pagar. Efectivo/transferencia/tarjeta liquidan al confirmar el ingreso." tabindex="0">?</span></label>
                        <select name="payment_mode" class="form-control">
                            <option value="payable">Crédito (cuenta por pagar)</option>
                            <option value="cash">Efectivo</option>
                            <option value="transfer">Transferencia</option>
                            <option value="card">Tarjeta</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Desc. pronto pago % <span class="field-tip" data-tip="Solo aplica si paga antes del vencimiento. Reduce el total de la factura." tabindex="0">?</span></label>
                        <input type="number" step="0.01" min="0" max="100" name="early_payment_discount_pct" class="form-control" value="" placeholder="0">
                        <small style="color:var(--color-text-secondary);">Opcional. Condicionado a pago anticipado.</small>
                    </div>
                    <div class="form-group">
                        <label>Desc. comercial <span class="field-tip" data-tip="Descuento general de la factura (siempre aplica). Puede indicar % o monto en pesos; si hay monto, manda el monto." tabindex="0">?</span></label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <input type="number" step="0.01" min="0" max="100" name="commercial_discount_pct" class="form-control" value="" placeholder="%">
                            <input type="text" inputmode="decimal" name="commercial_discount_amount" class="form-control money-input" data-money="1" value="" placeholder="$ monto" autocomplete="off">
                        </div>
                        <small style="color:var(--color-text-secondary);">Siempre aplica. Use % o $ (si llena $, prevalece el monto).</small>
                    </div>
                    <div class="form-group">
                        <label>Cruce con OC <span class="field-tip" data-tip="Órdenes de compra abiertas del proveedor elegido. Al seleccionarla se cruza con la factura entrante (cantidades y costos) antes de ingresar al inventario." tabindex="0">?</span></label>
                        <select name="linked_order_id" id="uploadLinkedOrderId" class="form-control" disabled>
                            <option value="">Seleccione proveedor primero…</option>
                        </select>
                        <small style="color:var(--color-text-secondary);" id="uploadLinkedOrderHint">Se listan OC en borrador u ordenadas del proveedor.</small>
                    </div>
                </div>
                    <div class="form-group">
                        <label>Archivo de la factura <span class="field-tip" data-tip="Opcional. Excel, Word, PDF, XML DIAN, CSV o foto. Si no tiene archivo, use «Continuar sin archivo» y agregue productos a mano. Escaneos: active Interpretar con IA." tabindex="0">?</span></label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;">
                            <a class="btn btn-secondary btn-sm"
                               href="<?php echo htmlspecialchars($viewInstance->asset('templates/plantilla_factura_compra_seri.csv'), ENT_QUOTES); ?>"
                               download="plantilla_factura_compra_seri.csv"
                               title="Descargar plantilla CSV con las columnas exactas que lee el sistema">
                                Descargar plantilla CSV
                            </a>
                            <small style="color:var(--color-text-secondary);">
                                Use esta plantilla si el CSV del proveedor falla o viene incompleto.
                            </small>
                        </div>
                        <input type="file" name="invoice_file" class="form-control" style="padding:8px;"
                               accept=".csv,.txt,.pdf,.xml,.zip,.xlsx,.xlsm,.ods,.docx,image/*">
                        <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                            Preferible <strong>.xlsx</strong> o <strong>CSV</strong> con la plantilla Seri.
                            Separador <strong>;</strong>. Columnas: Referencia, EAN, Descripcion, Cantidad, Bonif, Costo_unitario, IVA, Descuento, Total_linea, Lote, Vencimiento, Precio_venta.
                        </small>
                        <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                            <strong>EAN:</strong> escríbalo completo (13 dígitos), sin notación científica. En Excel formatee la columna como Texto antes de pegar.
                            El formato antiguo <strong>.xls</strong> no es compatible (guárdelo como .xlsx o CSV).
                        </small>
                        <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                            También acepta PDF, XML/ZIP DIAN, fotos/CamScanner (marque «Interpretar con IA»).
                        </small>
                    </div>
                    <div class="form-group">
                        <label>O pegue el texto de la factura <span class="field-tip" data-tip="Opcional. Una línea por producto: nombre, cantidad, $ unitario, $ total (separados por comas). También acepta el texto tal como viene del PDF." tabindex="0">?</span></label>
                        <textarea name="pasted_text" rows="3" class="form-control"
                                  placeholder="Ej: AMPOLLAS VA, 15, $ 1.000,00, $ 15.000,00&#10;IGORA ROYAL 8.0, 1, $ 18.000,00, $ 21.420,00"></textarea>
                        <small style="color:var(--color-text-secondary);display:block;margin-top:6px;">
                            Una línea por producto. Separe con comas: <strong>nombre</strong>, <strong>cantidad</strong>, <strong>$ unitario</strong>, <strong>$ total</strong>.
                        </small>
                    </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;<?php echo $aiInvoiceReady ? '' : 'opacity:.55;'; ?>">
                        <input type="checkbox" name="use_ai" id="invoiceUseAi" value="1" style="width:auto;margin-top:3px;"
                               <?php echo $aiInvoiceReady ? 'checked' : 'disabled'; ?>>
                        <span>
                            Interpretar con IA <span class="field-tip" data-tip="Usa IA para leer fotos o PDF escaneados. Si el PDF ya tiene texto, también mejora el armado de líneas. Requiere OPENROUTER_API_KEY." tabindex="0">?</span>
                            <small style="display:block;color:var(--color-text-secondary);font-weight:400;">
                                Necesario para PDF escaneados o fotos. Si el PDF ya trae texto, también mejora la lectura.
                            </small>
                            <?php if (!$aiInvoiceReady): ?>
                                <small style="display:block;color:#B45309;">Configure OPENROUTER_API_KEY para habilitarlo.</small>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
            </div>
            <div class="modal-footer invoice-shell-footer" style="flex-wrap:wrap;gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="closeInvoiceUpload()">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="startManualInvoiceDraft()"
                        title="Crea el borrador vacío y abre la revisión para buscar/escanear productos">
                    Continuar sin archivo
                </button>
                <button type="submit" class="btn btn-secondary"
                        title="Interpreta el archivo o el texto pegado">Leer factura</button>
            </div>
        </form>
    </div>
</div>

<!-- Alta rápida de proveedor (por encima de cargar factura / revisión) -->
<div id="quickSupplierModal" class="modal-overlay" style="display:none;z-index:1400;">
    <div class="modal-content neumorphic" style="max-width:520px;max-height:85vh;overflow-y:auto;position:relative;z-index:1401;">
        <div class="modal-header">
            <h3>Nuevo proveedor</h3>
            <button type="button" class="modal-close" onclick="closeQuickSupplierModal()">&times;</button>
        </div>
        <form id="quickSupplierForm" onsubmit="return submitQuickSupplier(event);">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <p style="margin-top:0;font-size:13px;color:var(--color-text-secondary);">
                Se guardará y quedará seleccionado en la factura. No perderá lo que ya cargó.
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Nombre de empresa * <span class="field-tip" data-tip="Razón social o nombre comercial. Quedará seleccionado en la factura al guardar." tabindex="0">?</span></label>
                    <input type="text" name="name" id="qsName" class="form-control" required maxlength="255">
                </div>
                <div class="form-group">
                    <label>Tipo doc. <span class="field-tip" data-tip="Tipo de identificación tributaria o personal del proveedor." tabindex="0">?</span></label>
                    <select name="document_type" id="qsDocType" class="form-control">
                        <option value="NIT">NIT</option>
                        <option value="CC">C.C</option>
                        <option value="CE">C.E</option>
                        <option value="OTROS">OTROS</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>N° documento * <span class="field-tip" data-tip="NIT o documento sin puntos. Debe ser único en el catálogo de proveedores." tabindex="0">?</span></label>
                    <input type="text" name="document_number" id="qsDocNum" class="form-control" required maxlength="60">
                </div>
                <div class="form-group">
                    <label>Contacto <span class="field-tip" data-tip="Persona de contacto habitual para pedidos o reclamos." tabindex="0">?</span></label>
                    <input type="text" name="contact_name" id="qsContact" class="form-control" maxlength="120">
                </div>
                <div class="form-group">
                    <label>Teléfono * <span class="field-tip" data-tip="Usado para WhatsApp al enviar órdenes de compra. Incluya indicativo si aplica." tabindex="0">?</span></label>
                    <input type="text" name="phone" id="qsPhone" class="form-control" required maxlength="60">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Email <span class="field-tip" data-tip="Correo por defecto al enviar la OC. Puede sobrescribirse al momento del envío." tabindex="0">?</span></label>
                    <input type="email" name="email" id="qsEmail" class="form-control" maxlength="180">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Dirección <span class="field-tip" data-tip="Dirección comercial del proveedor (referencia interna)." tabindex="0">?</span></label>
                    <input type="text" name="address" id="qsAddress" class="form-control" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickSupplierModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="qsSubmitBtn">Guardar y seleccionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Alta rápida de producto (desde revisión de factura) -->
<div id="quickProductModal" class="modal-overlay" style="display:none;z-index:1450;">
    <div class="modal-content neumorphic" style="max-width:560px;max-height:90vh;overflow-y:auto;position:relative;z-index:1451;">
        <div class="modal-header">
            <h3>Nuevo producto</h3>
            <button type="button" class="modal-close" onclick="closeQuickProductModal()">&times;</button>
        </div>
        <form id="quickProductForm" onsubmit="return submitQuickProduct(event);">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <p style="margin-top:0;font-size:13px;color:var(--color-text-secondary);">
                Se crea en inventario (stock 0) y se agrega a esta factura. El ingreso físico ocurre al confirmar.
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Nombre *</label>
                    <input type="text" name="name" id="qpName" class="form-control" required maxlength="255" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>SKU / código</label>
                    <input type="text" name="code" id="qpCode" class="form-control" maxlength="255" placeholder="Vacío = automático" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>EAN / barras</label>
                    <input type="text" name="barcode" id="qpBarcode" class="form-control" maxlength="64" placeholder="Opcional" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Categoría</label>
                    <select name="category_id" id="qpCategory" class="form-control">
                        <option value="">Sin categoría</option>
                        <?php foreach (($categories ?? []) as $cat): ?>
                            <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars((string)$cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Unidad</label>
                    <input type="text" name="unit" id="qpUnit" class="form-control" value="UND" maxlength="20">
                </div>
                <div class="form-group">
                    <label>P. compra</label>
                    <input type="text" inputmode="decimal" name="purchase_price" id="qpPurchase" class="form-control money-input" data-money="1" value="0" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>P. venta *</label>
                    <input type="text" inputmode="decimal" name="sale_price" id="qpSale" class="form-control money-input" data-money="1" required value="" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>IVA %</label>
                    <input type="number" name="tax_rate" id="qpTax" class="form-control" min="0" max="100" step="0.01"
                           value="<?php echo htmlspecialchars((string)($defaultTaxRate ?? 0)); ?>">
                </div>
                <div class="form-group">
                    <label>Stock mín.</label>
                    <input type="number" name="min_stock" id="qpMinStock" class="form-control" min="0" value="5">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Descripción</label>
                    <textarea name="description" id="qpDesc" class="form-control" rows="2" maxlength="500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickProductModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="qpSubmitBtn">Crear y agregar a la factura</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal confirmación previa al inventario -->
<div id="invoiceReviewModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic invoice-shell-modal invoice-review-shell">
        <div class="modal-header">
            <h3>Confirmar ingreso al inventario</h3>
            <button type="button" class="modal-close" onclick="closeInvoiceReview()">&times;</button>
        </div>
        <form method="POST" id="invoiceReviewForm" class="invoice-shell-form"
              action="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_confirm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="import_id" id="irImportId">
            <div class="invoice-shell-body" id="invoiceReviewBody"></div>
            <div class="modal-footer invoice-shell-footer">
                <button type="button" class="btn btn-secondary" onclick="closeInvoiceReview()">Cerrar sin aplicar</button>
                <button type="submit" class="btn btn-success" id="invoiceConfirmSubmitBtn"
                        onclick="return confirmInvoiceReceive()">
                    Confirmar e ingresar al inventario
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal detalle -->
<div id="purchaseDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:720px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Detalle de compra</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('purchaseDetailModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" id="purchaseDetailContent"></div>
    </div>
</div>

<!-- Modal Nota de crédito -->
<div id="creditNoteModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:720px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Nota de crédito / devolución (compra)</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('creditNoteModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=credit_note" data-ajax="true" id="creditNoteForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="cnOrderId" value="">
            <p id="cnOrderLabel" style="margin-top:0;font-size:13px;color:var(--color-text-secondary);"></p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Fecha <span class="field-tip" data-tip="Fecha de la nota de crédito. Define el asiento contable inverso." tabindex="0">?</span></label>
                    <input type="date" name="credit_date" id="cnDate" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>N° documento proveedor <span class="field-tip" data-tip="Número de la NC emitida por el proveedor (si la tiene). Queda en trazabilidad." tabindex="0">?</span></label>
                    <input type="text" name="external_number" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div class="form-group">
                <label>Motivo <span class="field-tip" data-tip="Devolución, daño, descuento comercial, error de facturación, etc." tabindex="0">?</span></label>
                <input type="text" name="reason" class="form-control" placeholder="Devolución, mercancía dañada, descuento, etc.">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="affects_stock" id="cnAffectsStock" value="1" checked style="width:auto;">
                    Devolver mercancía al proveedor (salida de inventario)
                    <span class="field-tip" data-tip="Marcado: sale stock y reduce CxP. Si no hay unidades en bodega, el stock puede quedar negativo (no bloquea la NC). Desmarcado: solo ajuste comercial (baja el saldo, no toca inventario)." tabindex="0">?</span>
                </label>
                <small style="color:var(--color-text-secondary);">Si lo desmarca, solo reduce el saldo por pagar (ajuste comercial sin tocar stock).</small>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Recibido</th><th>Ya NC</th><th>Disponible</th><th>Cant. NC</th></tr>
                    </thead>
                    <tbody id="cnItemsBody"></tbody>
                </table>
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);margin-top:10px;">
                Reduce el saldo de la OC y genera el asiento contable inverso (CxP / Inventario / IVA).
                Si hay devolución física, queda registrada en trazabilidad.
            </p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('creditNoteModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Emitir nota de crédito</button>
            </div>
        </form>
    </div>
</div>

<script>
window.poProducts = <?php echo json_encode(array_map(static function ($p) {
    return [
        'id' => (int)$p['id'],
        'code' => $p['code'],
        'barcode' => $p['barcode'] ?? '',
        'name' => $p['name'],
        'purchase_price' => (float)$p['purchase_price'],
        'tax_rate' => (float)($p['tax_rate'] ?? 0),
    ];
}, $products), JSON_UNESCAPED_UNICODE); ?>;
window.poDefaultTaxRate = <?php echo json_encode((float)($defaultTaxRate ?? 0)); ?>;
window.comprasBase = <?php echo json_encode($comprasBase); ?>;
var comprasBase = window.comprasBase;
window.poDetailUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=detail';
window.poWarehouseKey = 'seri_last_warehouse_date';
window.poInvoicePreviewUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_preview';
window.poInvoiceAddLineUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_add_line';
window.poInvoiceRemoveLineUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_remove_line';
window.poInvoiceLinkOrderUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_link_order';
window.poInvoiceSaveDraftUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_save_draft';
window.poOpenOrdersMatchUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=open_orders_match';
window.poCsrfToken = <?php echo json_encode(\SoftNova\Core\csrf_token()); ?>;
window.poQuickSupplierUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=quick_supplier';
window.poQuickProductUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=quick_product';
window.poCategories = <?php echo json_encode(array_map(static fn($c) => [
    'id' => (int)$c['id'],
    'name' => (string)$c['name'],
], $categories ?? []), JSON_UNESCAPED_UNICODE); ?>;
window.poSuppliers = <?php echo json_encode(array_map(static fn($s) => [
    'id' => (int)$s['id'],
    'name' => (string)$s['name'],
    'document_type' => (string)($s['document_type'] ?? ''),
    'document_number' => (string)($s['document_number'] ?? ''),
    'phone' => (string)($s['phone'] ?? ''),
    'contact_name' => (string)($s['contact_name'] ?? ''),
], $suppliers), JSON_UNESCAPED_UNICODE); ?>;
window.poCurrency = {
    symbol: <?php echo json_encode($currency['symbol'] ?? '$'); ?>,
    decimals: <?php echo (int)($currency['decimals'] ?? 0); ?>,
    decimal: <?php echo json_encode($currency['decimal'] ?? ','); ?>,
    thousands: <?php echo json_encode($currency['thousands'] ?? '.'); ?>
};
window._quickSupplierTarget = 'upload';

function supplierSearchText(s) {
    return [s.name, s.document_type, s.document_number, s.phone, s.contact_name]
        .filter(Boolean).join(' ').toLowerCase();
}

function supplierLabel(s) {
    if (!s) return '';
    var extra = s.document_number ? (' · ' + (s.document_type || 'Doc') + ' ' + s.document_number) : '';
    return (s.name || '') + extra;
}

function rebuildSupplierList(listEl) {
    if (!listEl) return;
    var html = '';
    (window.poSuppliers || []).forEach(function(s) {
        html += '<li class="combobox-option" data-id="' + s.id + '" data-label="' + esc(s.name) + '"' +
            ' data-search="' + esc(supplierSearchText(s)) + '">' +
            esc(s.name) +
            (s.document_number ? '<small>' + esc((s.document_type || 'Doc') + ': ' + s.document_number + (s.phone ? ' · ' + s.phone : '')) + '</small>' : '') +
            '</li>';
    });
    listEl.innerHTML = html;
}

function upsertPoSupplier(s) {
    if (!s || !s.id) return;
    window.poSuppliers = window.poSuppliers || [];
    var found = false;
    for (var i = 0; i < window.poSuppliers.length; i++) {
        if (String(window.poSuppliers[i].id) === String(s.id)) {
            window.poSuppliers[i] = Object.assign({}, window.poSuppliers[i], s);
            found = true;
            break;
        }
    }
    if (!found) {
        window.poSuppliers.push(s);
        window.poSuppliers.sort(function(a, b) {
            return String(a.name || '').localeCompare(String(b.name || ''), 'es', { sensitivity: 'base' });
        });
    }
    rebuildSupplierList(document.getElementById('invoiceSupplierList'));
    var reviewList = document.getElementById('reviewSupplierList');
    if (reviewList) rebuildSupplierList(reviewList);
}

function bindSupplierCombobox(wrapId, hiddenId, searchId, listId) {
    var wrap = document.getElementById(wrapId);
    var hidden = document.getElementById(hiddenId);
    var input = document.getElementById(searchId);
    var list = document.getElementById(listId);
    if (!wrap || !hidden || !input || !list || wrap.dataset.ready === '1') return;
    wrap.dataset.ready = '1';
    rebuildSupplierList(list);

    function openList() {
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function closeList() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }
    function filterList() {
        var q = input.value.toLowerCase().trim();
        var visible = 0;
        list.querySelectorAll('.combobox-option').forEach(function(li) {
            var match = !q || (li.getAttribute('data-search') || '').indexOf(q) !== -1;
            li.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (visible > 0) openList(); else closeList();
    }
    function selectOption(li) {
        hidden.value = li.getAttribute('data-id') || '';
        input.value = li.getAttribute('data-label') || li.textContent.trim();
        closeList();
        if (hidden.id === 'invoiceSupplierId') {
            loadUploadOpenOrders(hidden.value);
        }
        if (hidden.id === 'reviewSupplierId') {
            refreshReviewOpenOrders(hidden.value);
        }
    }

    input.addEventListener('focus', function() { filterList(); openList(); });
    input.addEventListener('input', function() {
        hidden.value = '';
        filterList();
        if (hidden.id === 'invoiceSupplierId') {
            loadUploadOpenOrders('');
        }
    });
    list.addEventListener('mousedown', function(e) {
        var li = e.target.closest('.combobox-option');
        if (!li) return;
        e.preventDefault();
        selectOption(li);
    });
    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) closeList();
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeList();
        if (e.key === 'Enter') {
            var first = list.querySelector('.combobox-option:not([style*="display: none"])');
            if (first && !list.hidden) {
                e.preventDefault();
                selectOption(first);
            }
        }
    });
}

function setSupplierCombobox(hiddenId, searchId, supplierId) {
    var hidden = document.getElementById(hiddenId);
    var input = document.getElementById(searchId);
    if (!hidden || !input) return;
    var id = supplierId ? String(supplierId) : '';
    hidden.value = id;
    if (!id) {
        input.value = '';
        return;
    }
    var found = (window.poSuppliers || []).find(function(s) { return String(s.id) === id; });
    input.value = found ? found.name : ('#' + id);
}

function openInvoiceUpload() {
    document.getElementById('invoiceUploadForm').reset();
    setSupplierCombobox('invoiceSupplierId', 'invoiceSupplierSearch', '');
    rebuildSupplierList(document.getElementById('invoiceSupplierList'));
    loadUploadOpenOrders('');
    syncInvoiceDocumentKindUi();
    var inv = document.getElementById('uploadInvoiceDate');
    var wh = document.getElementById('uploadWarehouseDate');
    if (inv) inv.value = poToday();
    if (wh) {
        wh.value = poRememberedDate();
        wh.onchange = function() { poRemember(wh.value); };
    }
    document.getElementById('invoiceUploadModal').style.display = 'flex';
}
function closeInvoiceUpload() {
    document.getElementById('invoiceUploadModal').style.display = 'none';
}
function closeInvoiceReview(opts) {
    opts = opts || {};
    var skipSave = !!opts.skipSave;
    var modal = document.getElementById('invoiceReviewModal');
    var form = document.getElementById('invoiceReviewForm');
    var importId = window._invoiceReviewImportId
        || parseInt((document.getElementById('irImportId') || {}).value || '0', 10);

    if (skipSave || !form || !importId || !window.poInvoiceSaveDraftUrl) {
        if (modal) modal.style.display = 'none';
        return;
    }

    if (window.SeriMoney && typeof SeriMoney.normalizeForm === 'function') {
        SeriMoney.normalizeForm(form);
    }

    var fd = new FormData(form);
    fd.set('import_id', String(importId));

    var btn = document.querySelector('#invoiceReviewModal .invoice-shell-footer .btn-secondary');
    var prevLabel = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Guardando…';
    }

    fetch(window.poInvoiceSaveDraftUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success) {
            throw new Error((d && d.message) || 'No se pudo guardar el borrador');
        }
        if (modal) modal.style.display = 'none';
        if (typeof showAlert === 'function') {
            showAlert(d.message || 'Borrador guardado', 'success');
        }
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') {
            showAlert(err.message || 'Error al guardar. El modal permanece abierto.', 'error');
        }
    })
    .finally(function() {
        if (btn) {
            btn.disabled = false;
            btn.textContent = prevLabel || 'Cerrar sin aplicar';
        }
        if (window.SeriMoney && typeof SeriMoney.restoreForm === 'function') {
            SeriMoney.restoreForm(form);
        }
    });
}

function syncInvoiceDocumentKindUi() {
    var form = document.getElementById('invoiceUploadForm');
    if (!form) return;
    var kind = (form.querySelector('input[name="document_kind"]:checked') || {}).value || '';
    var ask = document.getElementById('invoiceStandardVatAsk');
    if (ask) ask.style.display = (kind === 'standard') ? '' : 'none';
    var elecAsk = document.getElementById('invoiceElectronicVatAsk');
    if (elecAsk) elecAsk.style.display = (kind === 'electronic') ? '' : 'none';
    form.querySelectorAll('.invoice-doc-kind-opt').forEach(function(lab) {
        var on = lab.querySelector('input[name="document_kind"]');
        lab.style.borderColor = (on && on.checked) ? 'var(--color-primary, #2563eb)' : 'var(--color-border, #e5e7eb)';
        lab.style.boxShadow = (on && on.checked) ? '0 0 0 1px var(--color-primary, #2563eb)' : 'none';
    });
    syncInvoiceCustomVatUi();
}

function syncInvoiceCustomVatUi() {
    var form = document.getElementById('invoiceUploadForm');
    if (!form) return;
    var vatEl = form.querySelector('input[name="apply_product_vat"]:checked');
    var mode = vatEl ? String(vatEl.value) : '';
    var wrap = document.getElementById('invoiceCustomVatWrap');
    var hint = document.getElementById('invoiceStandardVatHint');
    var amt = document.getElementById('invoiceTaxOverrideAmount');
    var isCustom = mode === '2';
    if (wrap) wrap.style.display = isCustom ? '' : 'none';
    if (hint) {
        hint.style.display = isCustom ? 'none' : '';
        hint.textContent = mode === '0'
            ? 'En la revisión puede activar IVA % por producto si hace falta.'
            : 'En la revisión podrá cambiar el % IVA de cada producto individualmente.';
    }
    if (amt) {
        if (isCustom) amt.setAttribute('required', 'required');
        else {
            amt.removeAttribute('required');
            if (mode !== '2') amt.value = '';
        }
        if (isCustom && window.SeriMoney && typeof SeriMoney.enhance === 'function') {
            SeriMoney.enhance(amt);
        }
    }
}

function validateInvoiceUploadMeta() {
    var form = document.getElementById('invoiceUploadForm');
    if (!form) return false;
    var kindEl = form.querySelector('input[name="document_kind"]:checked');
    if (!kindEl) {
        if (typeof showAlert === 'function') showAlert('Seleccione el tipo de documento (F. Electrónica, F. Normal o Remisión)', 'warning');
        return false;
    }
    if (kindEl.value === 'standard') {
        var vatEl = form.querySelector('input[name="apply_product_vat"]:checked');
        if (!vatEl) {
            if (typeof showAlert === 'function') showAlert('En Factura Normal indique si los productos llevan IVA, son exentos o IVA personalizado', 'warning');
            var ask = document.getElementById('invoiceStandardVatAsk');
            if (ask) ask.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return false;
        }
        if (String(vatEl.value) === '2') {
            var amtEl = document.getElementById('invoiceTaxOverrideAmount');
            var amt = amtEl
                ? (window.SeriMoney && typeof SeriMoney.parse === 'function'
                    ? SeriMoney.parse(amtEl.value)
                    : parseFloat(String(amtEl.value || '').replace(/\./g, '').replace(',', '.')))
                : NaN;
            if (!isFinite(amt) || amt < 0.01) {
                if (typeof showAlert === 'function') showAlert('Indique el valor exacto del IVA de la factura (mayor a 0)', 'warning');
                if (amtEl && amtEl.focus) amtEl.focus();
                return false;
            }
        }
    }
    var wh = form.querySelector('input[name="warehouse_date"]');
    if (wh && !String(wh.value || '').trim()) {
        if (typeof showAlert === 'function') showAlert('Indique la fecha de recepción de bodega', 'warning');
        if (wh.focus) wh.focus();
        return false;
    }
    if (wh && wh.value) poRemember(wh.value);
    return true;
}

(function bindInvoiceDocKind() {
    var form = document.getElementById('invoiceUploadForm');
    if (!form || form.dataset.docKindBound) return;
    form.dataset.docKindBound = '1';
    form.addEventListener('change', function(e) {
        var t = e.target;
        if (!t) return;
        if (t.name === 'document_kind') syncInvoiceDocumentKindUi();
        if (t.name === 'apply_product_vat') syncInvoiceCustomVatUi();
    });
    form.addEventListener('submit', function(e) {
        if (!validateInvoiceUploadMeta()) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        var footer = form.querySelector('.invoice-shell-footer');
        if (footer && !footer.querySelector('.inv-upload-busy')) {
            var busy = document.createElement('div');
            busy.className = 'inv-upload-busy';
            busy.style.cssText = 'flex:1 1 100%;font-size:13px;color:var(--color-text-secondary);margin:4px 0 0;';
            busy.textContent = 'Leyendo factura… esto puede tardar si usa IA o PDF. No cierre la ventana.';
            footer.appendChild(busy);
        }
        form.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.textContent = 'Leyendo…';
    });
})();

/** Crea un borrador de factura vacío (sin PDF/foto) y abre la revisión para agregar productos a mano. */
function startManualInvoiceDraft() {
    var form = document.getElementById('invoiceUploadForm');
    var hid = document.getElementById('invoiceSupplierId');
    if (!form) return;
    if (!hid || !hid.value) {
        if (typeof showAlert === 'function') {
            showAlert('Seleccione un proveedor de la lista (o cree uno con + Nuevo)', 'warning');
        }
        var search = document.getElementById('invoiceSupplierSearch');
        if (search) search.focus();
        return;
    }
    if (!validateInvoiceUploadMeta()) return;
    var fd = new FormData(form);
    fd.delete('invoice_file');
    fd.set('pasted_text', '');
    fd.set('use_ai', '');
    fd.delete('use_ai');

    var btn = form.querySelector('button[onclick="startManualInvoiceDraft()"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Preparando…'; }

    fetch(form.action, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.import_id) {
            throw new Error((d && d.message) || 'No se pudo crear el borrador');
        }
        closeInvoiceUpload();
        if (typeof showAlert === 'function') {
            showAlert(d.message || 'Borrador listo: agregue productos', 'success');
        }
        openInvoiceReview(d.import_id);
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Continuar sin archivo'; }
    });
}

function openQuickSupplierModal(target) {
    window._quickSupplierTarget = target || 'upload';
    var form = document.getElementById('quickSupplierForm');
    if (form) form.reset();
    var modal = document.getElementById('quickSupplierModal');
    if (!modal) return;
    // Asegura que quede por encima del modal de factura (base 1300) y de la revisión.
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    modal.style.zIndex = '1400';
    modal.style.display = 'flex';
    setTimeout(function() {
        var n = document.getElementById('qsName');
        if (n) n.focus();
    }, 50);
}
function closeQuickSupplierModal() {
    document.getElementById('quickSupplierModal').style.display = 'none';
}

function submitQuickSupplier(e) {
    e.preventDefault();
    var form = document.getElementById('quickSupplierForm');
    var btn = document.getElementById('qsSubmitBtn');
    if (!form) return false;
    var fd = new FormData(form);
    if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }
    fetch(window.poQuickSupplierUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.supplier) {
            throw new Error((d && d.message) || 'No se pudo crear el proveedor');
        }
        upsertPoSupplier(d.supplier);
        var target = window._quickSupplierTarget || 'upload';
        if (target === 'review') {
            setSupplierCombobox('reviewSupplierId', 'reviewSupplierSearch', d.supplier.id);
            refreshReviewOpenOrders(d.supplier.id);
        } else {
            setSupplierCombobox('invoiceSupplierId', 'invoiceSupplierSearch', d.supplier.id);
            loadUploadOpenOrders(d.supplier.id);
        }
        closeQuickSupplierModal();
        if (typeof showAlert === 'function') {
            showAlert(d.message || 'Proveedor listo', 'success');
        }
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Guardar y seleccionar'; }
    });
    return false;
}

function poMoney(v) {
    var c = window.poCurrency || { symbol: '$', decimals: 0, decimal: ',', thousands: '.' };
    var n = parseFloat(v);
    if (!isFinite(n)) n = 0;
    var formatted;
    if (window.SeriMoney && typeof SeriMoney.format === 'function') {
        formatted = SeriMoney.format(n, {
            decimals: c.decimals,
            decimal: c.decimal || ',',
            thousands: c.thousands || '.'
        });
    } else {
        var fixed = Math.abs(n).toFixed(c.decimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands || '.');
        formatted = (n < 0 ? '-' : '') + (c.decimals > 0
            ? parts[0] + (c.decimal || ',') + parts[1]
            : parts[0]);
    }
    return (c.symbol || '$') + ' ' + formatted;
}

function supplierOptionsHtml(selected) {
    var h = '<option value="">Seleccione…</option>';
    (window.poSuppliers || []).forEach(function(s) {
        h += '<option value="' + s.id + '"' + (String(selected) === String(s.id) ? ' selected' : '') + '>' + esc(s.name) + '</option>';
    });
    return h;
}

document.addEventListener('DOMContentLoaded', function() {
    bindSupplierCombobox('invoiceSupplierCombobox', 'invoiceSupplierId', 'invoiceSupplierSearch', 'invoiceSupplierList');
    var uploadForm = document.getElementById('invoiceUploadForm');
    if (uploadForm && uploadForm.dataset.supplierValidateBound !== '1') {
        uploadForm.dataset.supplierValidateBound = '1';
        uploadForm.addEventListener('submit', function(ev) {
            var hid = document.getElementById('invoiceSupplierId');
            if (!hid || !hid.value) {
                ev.preventDefault();
                if (typeof showAlert === 'function') {
                    showAlert('Seleccione un proveedor de la lista (o cree uno con + Nuevo)', 'warning');
                }
                var search = document.getElementById('invoiceSupplierSearch');
                if (search) search.focus();
                return false;
            }
        });
    }
});

function openInvoiceReview(importId, triggerBtn) {
    var modal = document.getElementById('invoiceReviewModal');
    var body = document.getElementById('invoiceReviewBody');
    if (modal && body) {
        body.innerHTML = '<div class="inv-review-loading" style="padding:28px 16px;text-align:center;">'
            + '<p style="margin:0 0 8px;font-weight:600;">Cargando factura…</p>'
            + '<p style="margin:0;font-size:13px;color:var(--color-text-secondary);">Un momento, preparando líneas y productos.</p>'
            + '</div>';
        document.getElementById('irImportId').value = String(importId || '');
        modal.style.display = 'flex';
    }
    if (triggerBtn) {
        triggerBtn.disabled = true;
        triggerBtn.dataset.prevLabel = triggerBtn.textContent;
        triggerBtn.textContent = 'Cargando…';
    }

    fetch(window.poInvoicePreviewUrl + '&id=' + importId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) {
            if (modal) modal.style.display = 'none';
            return showAlert(d.message || 'No se pudo cargar la factura', 'error');
        }
        window._invoiceReviewOpenOrders = d.open_orders || [];
        window._invoiceOrderMatch = d.order_match || null;
        // Pintar en el siguiente tick para no congelar el click.
        setTimeout(function() {
            renderInvoiceReview(d.import, d.items || []);
            var search = document.getElementById('irProductSearch');
            if (search && !(d.items || []).length) search.focus();
            if (d.import && d.import.linked_order_id && !(d.items || []).length) {
                linkInvoiceToOrder(d.import.linked_order_id);
            }
        }, 0);
    })
    .catch(function() {
        if (modal) modal.style.display = 'none';
        showAlert('No se pudo cargar la factura', 'error');
    })
    .finally(function() {
        if (triggerBtn) {
            triggerBtn.disabled = false;
            triggerBtn.textContent = triggerBtn.dataset.prevLabel || 'Revisar';
        }
    });
}

function renderInvoiceReview(imp, items) {
    document.getElementById('irImportId').value = imp.id;
    window._invoiceReviewImportId = imp.id;
    window._invoiceReviewItems = items || [];

    var tip = function(t) {
        return ' <span class="field-tip" data-tip="' + esc(t) + '" tabindex="0">?</span>';
    };
    var docKind = String(imp.document_kind || '');
    var docLabels = { electronic: 'F. Electrónica', standard: 'F. Normal', remission: 'Remisión' };
    var docLabel = docLabels[docKind] || 'Documento';
    var applyVatMode = parseInt(imp.apply_product_vat, 10);
    if (isNaN(applyVatMode)) applyVatMode = 0;
    var isCustomVat = docKind === 'standard' && applyVatMode === 2;
    var taxLocked = docKind === 'remission' || docKind === 'electronic' || isCustomVat;
    var defaultTax = (window.poDefaultTaxRate != null ? parseFloat(window.poDefaultTaxRate) : 0) || 0;
    window._invoiceReviewCustomVat = isCustomVat;
    window._invoiceReviewTaxOverride = isCustomVat
        ? (parseFloat(imp.tax_override_amount) || parseFloat(imp.tax_total) || 0)
        : 0;

    var h = '';
    if (docKind) {
        h += '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px;">' +
             '<span class="badge" style="background:#EEF2FF;color:#3730A3;padding:4px 10px;border-radius:999px;font-weight:600;">' +
             esc(docLabel) + '</span>';
        if (docKind === 'electronic') {
            h += '<small style="color:var(--color-text-secondary);">IVA (' + defaultTax + '%) incluido en el costo unitario / precio de compra.</small>';
        } else if (docKind === 'remission') {
            h += '<small style="color:var(--color-text-secondary);">Remisión: productos exentos (IVA 0%).</small>';
        } else if (docKind === 'standard' && isCustomVat) {
            h += '<small style="color:var(--color-text-secondary);">F. Normal: IVA personalizado (monto exacto de la factura → Contabilidad 240802).</small>';
        } else if (docKind === 'standard') {
            h += '<small style="color:var(--color-text-secondary);">F. Normal: ajuste IVA por producto. ' +
                 (applyVatMode === 1
                    ? 'Preferencia inicial: con IVA.'
                    : 'Preferencia inicial: sin IVA.') +
                 '</small>';
            h += '<span style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">' +
                 '<button type="button" class="btn btn-sm btn-secondary" onclick="bulkInvoiceLineTax(' + defaultTax + ')">IVA ' + defaultTax + '% a todos</button>' +
                 '<button type="button" class="btn btn-sm btn-secondary" onclick="bulkInvoiceLineTax(0)">Quitar IVA (0%)</button>' +
                 '</span>';
        }
        h += '</div>';
    }

    if (isCustomVat) {
        var ovFmt = window._invoiceReviewTaxOverride > 0
            ? window._invoiceReviewTaxOverride
            : (parseFloat(imp.tax_override_amount) || 0);
        h += '<div class="form-group" style="margin:0 0 14px;padding:12px;border:1px solid #F59E0B;border-radius:8px;background:rgba(245,158,11,0.08);">' +
             '<label style="font-weight:600;">IVA personalizado de la factura ($) *' + tip('Monto exacto de IVA impreso en la factura. Se contabiliza en 240802 y se prorratea a las líneas al ingresar al inventario.') + '</label>' +
             '<input type="text" inputmode="decimal" name="tax_override_amount" id="reviewTaxOverrideAmount" class="form-control money-input" data-money="1" style="max-width:280px;" value="' +
             esc(window.SeriMoney ? SeriMoney.format(ovFmt) : ovFmt) +
             '" oninput="recalcInvoiceReviewTotals()" required autocomplete="off">' +
             '<small style="display:block;margin-top:6px;color:var(--color-text-secondary);">Las líneas quedan a costo neto; el % por producto no aplica. Al confirmar, el IVA se reparte por peso de subtotal.</small>' +
             '</div>';
    }

    h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px;">';
    h += '<div class="form-group" style="margin:0;grid-column:1/-1;"><label>Proveedor *' + tip('Proveedor de la factura. Debe coincidir para casar códigos y CxP.') + '</label>' +
         '<div class="customer-combobox invoice-supplier-combobox" id="reviewSupplierCombobox">' +
         '<input type="hidden" name="supplier_id" id="reviewSupplierId" value="' + esc(imp.supplier_id || '') + '" required>' +
         '<div style="display:flex;gap:8px;align-items:stretch;">' +
         '<input type="search" id="reviewSupplierSearch" class="form-control" autocomplete="off" placeholder="Buscar proveedor…" style="flex:1;">' +
         '<button type="button" class="btn btn-secondary" style="white-space:nowrap;" onclick="openQuickSupplierModal(\'review\')">+ Nuevo</button>' +
         '</div><ul class="combobox-list" id="reviewSupplierList" hidden></ul></div></div>';
    h += '<div class="form-group" style="margin:0;grid-column:1/-1;"><label>Cruce con OC' + tip('Al elegir una OC abierta se cruzan cantidades y costos con la factura. Si la factura es parcial, use «Recepción parcial» para dejar la OC abierta con lo pendiente.') + '</label>' +
         '<select name="linked_order_id" id="reviewLinkedOrderId" class="form-control">' +
         openOrdersOptionsHtml(imp.linked_order_id || '') +
         '</select>' +
         '<small style="color:var(--color-text-secondary);display:block;margin-top:4px;">Revise el resumen de cruce abajo antes de ingresar al inventario.</small></div>';
    h += renderOrderMatchPanel(window._invoiceOrderMatch);
    h += renderOcDispositionPanel(imp.linked_order_id || '', window._invoiceOrderMatch);
    h += '<div class="form-group" style="margin:0;"><label>N.º factura' + tip('Consecutivo del proveedor. Identifica el documento en Facturas y PDF.') + '</label>' +
         '<input type="text" name="invoice_number" class="form-control" maxlength="80" value="' + esc(imp.invoice_number || '') + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Fecha factura' + tip('Fecha impresa en la factura del proveedor.') + '</label>' +
         '<input type="date" name="invoice_date" class="form-control" value="' + esc(poIsoDate(imp.invoice_date)) + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Vencimiento pago' + tip('Fecha límite de CxP. Alimenta alertas de vencidas / por vencer.') + '</label>' +
         '<input type="date" name="due_date" class="form-control" value="' + esc(poIsoDate(imp.due_date)) + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Recepción de bodega *' + tip('Fecha real de ingreso a inventario y del asiento contable. Puede diferir de la fecha de factura.') + '</label>' +
         '<input type="date" name="warehouse_date" class="form-control" value="' + esc(poDefaultWarehouseDate(imp)) + '" required></div>';
    h += '<div class="form-group" style="margin:0;"><label>Desc. pronto pago %' + tip('Solo si paga antes del vencimiento. Reduce el total al confirmar.') + '</label>' +
         '<input type="number" step="0.01" min="0" max="100" name="early_payment_discount_pct" class="form-control" value="' +
         (imp.early_payment_discount_pct != null && imp.early_payment_discount_pct !== '' ? esc(imp.early_payment_discount_pct) : '0') +
         '" placeholder="0" oninput="recalcInvoiceReviewTotals()"></div>';
    h += '<div class="form-group" style="margin:0;grid-column:1/-1;"><label>Desc. comercial' + tip('Descuento general de la factura: siempre aplica. Indique % o monto $; si hay monto, manda el $.') + '</label>' +
         '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
         '<input type="number" step="0.01" min="0" max="100" name="commercial_discount_pct" class="form-control" value="' +
         (imp.commercial_discount_pct != null && imp.commercial_discount_pct !== '' ? esc(imp.commercial_discount_pct) : '0') +
         '" placeholder="% comercial" oninput="recalcInvoiceReviewTotals()">' +
         '<input type="text" inputmode="decimal" name="commercial_discount_amount" class="form-control money-input" data-money="1" value="' +
         esc(window.SeriMoney
            ? SeriMoney.format(imp.commercial_discount_amount != null && imp.commercial_discount_amount !== '' ? imp.commercial_discount_amount : 0)
            : (imp.commercial_discount_amount != null && imp.commercial_discount_amount !== '' ? imp.commercial_discount_amount : '0')) +
         '" placeholder="$ monto" oninput="recalcInvoiceReviewTotals()" autocomplete="off">' +
         '</div></div>';
    h += '<div class="form-group" style="margin:0;"><label>Forma de pago' + tip('Crédito = CxP. Efectivo sale del cajón; transferencia/tarjeta de bancos.') + '</label><select name="payment_mode" class="form-control">';
    [['payable','Crédito (CxP)'],['cash','Efectivo (sale del cajón)'],['transfer','Transferencia'],['card','Tarjeta']].forEach(function(o) {
        h += '<option value="' + o[0] + '"' + (imp.payment_mode === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
    });
    h += '</select></div>';
    h += '<div class="form-group" style="margin:0;grid-column:1/-1;"><label>Nota / observación' +
         tip('Queda guardada en el Detalle de compra y en el historial de movimientos (trazabilidad).') + '</label>' +
         '<textarea name="review_notes" id="reviewNotes" class="form-control" rows="2" maxlength="2000" ' +
         'placeholder="Ej. mercancía incompleta, acuerdo de precio, pendiente de revisión…">' +
         esc(imp.review_notes || '') + '</textarea>' +
         '<small style="color:var(--color-text-secondary);display:block;margin-top:4px;">Opcional. Se verá al abrir «Ver» en Facturas / compras.</small></div>';
    h += '</div>';

    if (imp.parse_notes) {
        h += '<p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">Lectura: ' + esc(imp.parse_notes) + '</p>';
    }

    h += '<div class="card" style="margin:0 0 14px;padding:12px;border:1px solid var(--color-border, #e5e7eb);border-radius:8px;">' +
         '<label style="font-weight:600;display:block;margin-bottom:8px;">Agregar producto del catálogo (nombre / SKU / EAN o escáner)' +
         tip('Busque o escanee productos existentes. Si no existe, cree uno nuevo sin salir de aquí. El mismo SKU puede ir en dos filas: una normal y otra de bonificación ($0).') + '</label>' +
         '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' +
         '<input type="search" id="irProductSearch" class="form-control" placeholder="Buscar o escanear…" style="flex:1;min-width:220px;" autocomplete="off">' +
         '<input type="number" id="irProductQty" class="form-control" value="1" min="1" step="1" inputmode="numeric" style="width:90px;" title="Cantidad a agregar (enteros)">' +
         '<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer;" title="Agrega el mismo producto en fila aparte con costo $0 (no diluye el promedio de inventario)">' +
         '<input type="checkbox" id="irProductAsBonus" value="1"> Bonificación ($0)</label>' +
         '<button type="button" class="btn btn-primary" onclick="addInvoiceManualFromSearch()">Agregar</button>' +
         '<button type="button" class="btn btn-secondary" onclick="openQuickProductModal()" title="Crear producto nuevo en inventario y agregarlo a esta factura">+ Nuevo producto</button>' +
         '</div>' +
         '<ul class="combobox-list" id="irProductList" hidden style="position:relative;max-height:200px;overflow:auto;margin-top:6px;"></ul>' +
         '<small style="color:var(--color-text-secondary);">' +
         ((items && items.length)
            ? 'Además de lo leído del archivo, puede completar líneas a mano. Mismo SKU: use «Bonificación ($0)» o el botón +Bonif. en la fila para separar normal y regalo.'
            : 'Borrador vacío: agregue productos existentes o cree uno nuevo. Para regalo del mismo SKU marque «Bonificación ($0)».') +
         '</small>' +
         '</div>';

    var nuevos = items.filter(function(i) { return !i.matched_product_id && i.row_action !== 'skip'; }).length;
    if (nuevos > 0) {
        h += '<p style="font-size:13px;color:#F59E0B;font-weight:600;margin:0 0 10px;">' +
             nuevos + ' línea(s) sin producto en el catálogo: se crearán como productos nuevos si no las asocia.</p>';
    }
    var sinMonto = items.filter(function(i) {
        return i.row_action !== 'skip' && (!(parseFloat(i.quantity) > 0));
    }).length;
    if (sinMonto > 0) {
        h += '<p style="font-size:13px;color:#DC2626;font-weight:600;margin:0 0 10px;">' +
             sinMonto + ' línea(s) con cantidad en 0. Complete Cant. antes de confirmar (marcadas en rojo).</p>';
    }
    var bonifCount = items.filter(function(i) {
        if (i.row_action === 'skip') return false;
        var q = parseFloat(i.quantity) || 0;
        var c = parseFloat(i.unit_cost) || 0;
        var t = parseFloat(i.line_total) || 0;
        return q > 0 && c <= 0 && t <= 0;
    }).length;
    if (bonifCount > 0) {
        h += '<p style="font-size:13px;color:#047857;font-weight:600;margin:0 0 10px;">' +
             bonifCount + ' línea(s) de bonificación/regalo: en la factura quedan a $0; en inventario conservan el costo (no diluyen el promedio).</p>';
    }

    h += '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 10px;font-size:12px;">' +
         '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:rgba(16,185,129,0.35);border:1px solid #10B981;"></span> Factura cargada</span>' +
         '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:rgba(245,158,11,0.4);border:1px solid #F59E0B;"></span> Desde OC (Omitir = pendiente en recepción parcial)</span>' +
         '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:rgba(59,130,246,0.2);border:1px solid #3B82F6;"></span> Bonificación (factura $0 · inventario con costo)</span>' +
         '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:rgba(220,38,38,0.15);border:1px solid #DC2626;"></span> Cant. incompleta</span>' +
         '</div>';

    var thStyle = 'position:sticky;top:0;z-index:5;background:#1e3a8a!important;background-color:#1e3a8a!important;color:#fff!important;font-weight:600;font-size:13px;white-space:nowrap;';
    h += '<div class="table-container invoice-review-table-wrap"><table class="invoice-review-table"><thead><tr>' +
         '<th style="' + thStyle + '">Origen</th>' +
         '<th style="' + thStyle + '">#</th>' +
         '<th style="' + thStyle + '">Acción</th>' +
         '<th style="' + thStyle + '">Descripción</th>' +
         '<th style="' + thStyle + '">SKU / ID</th>' +
         '<th style="' + thStyle + '">EAN</th>' +
         '<th style="' + thStyle + '">Producto en catálogo</th>' +
         '<th style="' + thStyle + '">Cant.</th>' +
         '<th style="' + thStyle + '">Costo u.</th>' +
         '<th style="' + thStyle + '">IVA %</th>' +
         '<th style="' + thStyle + '">Dcto %</th>' +
         '<th style="' + thStyle + '">Lote</th>' +
         '<th style="' + thStyle + '">Vence</th>' +
         '<th style="' + thStyle + '">P. venta</th>' +
         '<th style="' + thStyle + '">Total</th>' +
         '<th style="' + thStyle + '"></th>' +
         '</tr></thead><tbody>';

    var matchLabels = { ean: 'EAN', supplier_sku: 'SKU proveedor', code: 'Código', name: 'Nombre', manual: 'Manual', purchase_order: 'OC' };
    var vatInCost = docKind === 'electronic';

    items.forEach(function(it, idx) {
        var base = 'items[' + it.id + ']';
        var qty = Math.max(0, Math.round(parseFloat(it.quantity) || 0));
        var cost = parseFloat(it.unit_cost) || 0;
        var total = parseFloat(it.line_total) || 0;
        var isBonus = qty > 0 && cost <= 0 && total <= 0;
        var bad = it.row_action !== 'skip' && qty <= 0;
        var fromOc = String(it.match_type || '') === 'purchase_order'
            || /^Desde OC\b/i.test(String(it.raw_line || ''));
        var rowStyle = bad
            ? 'background:rgba(220,38,38,0.10);'
            : (isBonus
                ? 'background:rgba(59,130,246,0.14);'
                : (fromOc
                    ? 'background:rgba(245,158,11,0.22);'
                    : 'background:rgba(16,185,129,0.16);'));
        var lineTax = parseFloat(it.tax_rate) || 0;
        if (docKind === 'remission' || isCustomVat) lineTax = 0;
        if (docKind === 'electronic' && !(lineTax > 0)) lineTax = defaultTax;
        h += '<tr style="' + rowStyle + '" data-vat-in-cost="' + (vatInCost ? '1' : '0') + '" data-from-oc="' + (fromOc ? '1' : '0') + '">';
        h += '<td style="white-space:nowrap;font-size:12px;font-weight:600;color:' +
             (bad ? '#DC2626' : (isBonus ? '#1D4ED8' : (fromOc ? '#B45309' : '#047857'))) + ';">' +
             (bad ? '—' : (isBonus ? 'Bonif.' : (fromOc ? 'OC' : 'Factura'))) + '</td>';
        h += '<td class="inv-line-no" style="text-align:center;font-weight:700;color:var(--color-text-secondary);white-space:nowrap;">' +
             (idx + 1) + '</td>';
        h += '<td><select name="' + base + '[row_action]" class="form-control" style="min-width:118px;">' +
             '<option value="add_stock"' + (it.row_action === 'add_stock' ? ' selected' : '') + '>Ingresar</option>' +
             '<option value="create_product"' + (it.row_action === 'create_product' ? ' selected' : '') + '>Crear producto</option>' +
             '<option value="skip"' + (it.row_action === 'skip' ? ' selected' : '') + '>Omitir</option>' +
             '</select>' +
             (!isBonus && it.matched_product_id
                ? '<button type="button" class="btn btn-sm btn-secondary" style="margin-top:4px;font-size:11px;padding:2px 8px;" ' +
                  'onclick="addInvoiceManualProduct(' + parseInt(it.matched_product_id, 10) + ',{asBonus:true})" ' +
                  'title="Agregar otra fila del mismo SKU como bonificación ($0)">+ Bonif.</button>'
                : '') +
             '</td>';
        h += '<td><input type="text" name="' + base + '[description]" class="form-control" style="min-width:190px;" value="' + esc(it.description || '') + '">' +
             (isBonus ? '<div class="inv-bonus-hint" style="font-size:11px;color:#1D4ED8;font-weight:600;">Bonificación: factura $0 · inventario conserva costo</div>' : '') +
             '</td>';
        h += '<td><input type="text" name="' + base + '[supplier_sku]" class="form-control" style="width:160px;min-width:160px;font-family:ui-monospace,Consolas,monospace;font-size:12px;" value="' + esc(it.supplier_sku || '') + '" placeholder="SKU / ID" title="SKU / ID proveedor"></td>';
        h += '<td><input type="text" name="' + base + '[barcode]" class="form-control" style="width:180px;min-width:180px;font-family:ui-monospace,Consolas,monospace;font-size:12px;" value="' + esc(it.barcode || '') + '" placeholder="EAN" title="Código de barras / EAN"></td>';
        h += '<td><select name="' + base + '[matched_product_id]" class="form-control" style="min-width:210px;">' + productOptionsHtml(it.matched_product_id || '') + '</select>' +
             (it.match_type && it.match_type !== 'none'
                ? '<div style="font-size:11px;color:var(--color-text-secondary);">Coincidencia: ' + esc(matchLabels[it.match_type] || it.match_type) + ' (' + (parseInt(it.confidence,10) || 0) + '%)</div>'
                : '<div style="font-size:11px;color:var(--color-text-secondary);">Sin EAN coincidente: elija producto o cree uno nuevo</div>') +
             '</td>';
        h += '<td><input type="number" step="1" min="0" inputmode="numeric" name="' + base + '[quantity]" class="form-control inv-qty" style="min-width:80px;" value="' + qty + '"' + (bad ? ' required' : '') + ' title="Cantidad en enteros"></td>';
        h += '<td><input type="text" inputmode="decimal" name="' + base + '[unit_cost]" class="form-control money-input inv-cost" data-money="1" style="min-width:95px;" value="' + (window.SeriMoney ? SeriMoney.format(cost) : cost) + '"' +
             (vatInCost ? ' title="Costo con IVA incluido (precio de compra)"' : (isBonus ? ' title="Bonificación: $0 en factura; el inventario usa el costo del producto o de la línea pagada"' : '')) + '></td>';
        if (taxLocked) {
            h += '<td><input type="number" step="0.01" min="0" name="' + base + '[tax_rate]" class="form-control" style="min-width:70px;" value="' +
                 (isCustomVat ? '0' : lineTax) + '" readonly title="' +
                 (isCustomVat ? 'IVA personalizado a nivel factura (no por %)'
                    : (docKind === 'remission' ? 'Remisión: IVA 0%' : 'IVA incluido en el costo unitario')) + '"></td>';
        } else {
            h += '<td><input type="number" step="0.01" min="0" name="' + base + '[tax_rate]" class="form-control" style="min-width:70px;" value="' + lineTax + '" title="IVA del producto (editable)"></td>';
        }
        h += '<td><input type="number" step="0.01" min="0" name="' + base + '[discount_pct]" class="form-control" style="min-width:70px;" value="' + (parseFloat(it.discount_pct) || 0) + '"></td>';
        h += '<td><input type="text" maxlength="60" name="' + base + '[lot_code]" class="form-control" style="min-width:90px;" value="' + esc(it.lot_code || '') + '"></td>';
        h += '<td><input type="date" name="' + base + '[expiry_date]" class="form-control" style="min-width:130px;" value="' + (it.expiry_date || '') + '"></td>';
        h += '<td><input type="text" inputmode="decimal" name="' + base + '[sale_price]" class="form-control money-input" data-money="1" style="min-width:95px;" value="' + (it.sale_price != null && it.sale_price !== '' ? (window.SeriMoney ? SeriMoney.format(it.sale_price) : parseFloat(it.sale_price)) : '') + '"></td>';
        h += '<td class="inv-line-total" style="white-space:nowrap;">' + poMoney(total) + '</td>';
        h += '<td style="white-space:nowrap;text-align:center;">' +
             '<button type="button" class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:12px;" ' +
             'onclick="removeInvoiceReviewLine(' + parseInt(it.id, 10) + ')" ' +
             'title="Eliminar esta línea del borrador">Eliminar</button></td>';
        h += '</tr>';
    });

    h += '</tbody></table></div>';
    h += '<p id="invLiveStatusNotes" style="margin:10px 0 0;font-size:12px;color:var(--color-text-secondary);">Cambios aplicados al instante</p>';
    h += '<div class="inv-review-foot" style="display:flex;justify-content:flex-end;gap:20px;margin-top:12px;font-weight:600;flex-wrap:wrap;">' +
         '<span>Subtotal: ' + poMoney(imp.subtotal) + '</span>' +
         '<span>IVA: ' + poMoney(imp.tax_total) + '</span>' +
         '<span>Total: ' + poMoney(imp.total) + '</span></div>';

    document.getElementById('invoiceReviewBody').innerHTML = h;
    var reviewBody = document.getElementById('invoiceReviewBody');
    if (reviewBody) {
        delete reviewBody.dataset.productSelectsBound;
        delete reviewBody.dataset.totalsBound;
        reviewBody.dataset.docKind = docKind || '';
        reviewBody.dataset.vatInCost = vatInCost ? '1' : '0';
    }
    var reviewWrap = document.getElementById('reviewSupplierCombobox');
    if (reviewWrap) delete reviewWrap.dataset.ready;
    bindSupplierCombobox('reviewSupplierCombobox', 'reviewSupplierId', 'reviewSupplierSearch', 'reviewSupplierList');
    setSupplierCombobox('reviewSupplierId', 'reviewSupplierSearch', imp.supplier_id || '');
    bindInvoiceProductSearch();
    bindLinkedOrderSelect();
    bindInvoiceProductSelectsLazy();
    bindInvoiceLineTotals();
    recalcInvoiceReviewTotals();
    if (window.SeriMoney && typeof SeriMoney.enhanceAll === 'function') {
        SeriMoney.enhanceAll(document.getElementById('invoiceReviewBody') || document);
    }
    var reviewWh = document.querySelector('#invoiceReviewForm input[name="warehouse_date"]');
    if (reviewWh) {
        reviewWh.onchange = function() { poRemember(reviewWh.value); };
    }
    document.getElementById('invoiceReviewModal').style.display = 'flex';
}

function bulkInvoiceLineTax(rate) {
    var body = document.getElementById('invoiceReviewBody');
    if (!body) return;
    rate = Math.max(0, parseFloat(rate) || 0);
    body.querySelectorAll('input[name$="[tax_rate]"]').forEach(function(inp) {
        if (inp.readOnly) return;
        inp.value = String(rate);
    });
    if (typeof recalcInvoiceReviewTotals === 'function') recalcInvoiceReviewTotals();
}

function openOrdersOptionsHtml(selected) {
    var h = '<option value="">Sin cruce (crear factura/OC nueva)</option>';
    (window._invoiceReviewOpenOrders || []).forEach(function(o) {
        var label = o.order_number + ' · ' + (o.supplier_name || 'Sin proveedor') +
            ' · ' + poMoney(o.total) +
            (o.item_count ? (' · ' + o.item_count + ' ítem(s)') : '') +
            (o.order_date ? (' · ' + o.order_date) : '');
        h += '<option value="' + o.id + '"' + (String(selected) === String(o.id) ? ' selected' : '') + '>' + esc(label) + '</option>';
    });
    return h;
}

function uploadOpenOrdersOptionsHtml(selected, orders) {
    var list = orders || [];
    if (!list.length) {
        return '<option value="">Sin OC abiertas para este proveedor</option>';
    }
    var h = '<option value="">Sin cruce (opcional)</option>';
    list.forEach(function(o) {
        var label = o.order_number + ' · ' + poMoney(o.total) +
            (o.item_count ? (' · ' + o.item_count + ' ítem(s)') : '') +
            (o.order_date ? (' · ' + o.order_date) : '');
        h += '<option value="' + o.id + '"' + (String(selected) === String(o.id) ? ' selected' : '') + '>' + esc(label) + '</option>';
    });
    return h;
}

function loadUploadOpenOrders(supplierId, selectedId) {
    var sel = document.getElementById('uploadLinkedOrderId');
    var hint = document.getElementById('uploadLinkedOrderHint');
    if (!sel) return;
    var sid = parseInt(supplierId || '0', 10) || 0;
    if (sid <= 0) {
        sel.innerHTML = '<option value="">Seleccione proveedor primero…</option>';
        sel.disabled = true;
        sel.value = '';
        if (hint) hint.textContent = 'Se listan OC en borrador u ordenadas del proveedor.';
        return;
    }
    sel.disabled = true;
    sel.innerHTML = '<option value="">Cargando OC…</option>';
    if (!window.poOpenOrdersMatchUrl) {
        sel.innerHTML = '<option value="">No disponible</option>';
        return;
    }
    fetch(window.poOpenOrdersMatchUrl + '&supplier_id=' + sid, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        var orders = (d && d.open_orders) ? d.open_orders : [];
        window._uploadOpenOrders = orders;
        sel.innerHTML = uploadOpenOrdersOptionsHtml(selectedId || '', orders);
        sel.disabled = false;
        if (hint) {
            hint.textContent = orders.length
                ? (orders.length + ' OC abierta(s). Al leer/continuar se cruzará con la factura.')
                : 'Este proveedor no tiene OC en borrador u ordenadas.';
        }
    })
    .catch(function() {
        sel.innerHTML = '<option value="">Error al cargar OC</option>';
        sel.disabled = false;
    });
}

function refreshReviewOpenOrders(supplierId) {
    var sid = parseInt(supplierId || '0', 10) || 0;
    if (!window.poOpenOrdersMatchUrl || sid <= 0) return;
    fetch(window.poOpenOrdersMatchUrl + '&supplier_id=' + sid, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        window._invoiceReviewOpenOrders = (d && d.open_orders) ? d.open_orders : [];
        var sel = document.getElementById('reviewLinkedOrderId');
        if (!sel) return;
        var cur = sel.value;
        sel.innerHTML = openOrdersOptionsHtml(cur);
        if (cur) sel.value = cur;
    })
    .catch(function() {});
}

function renderOrderMatchPanel(match) {
    if (!match || !match.order_id) {
        return '<div id="orderMatchPanel" style="margin:0 0 14px;padding:10px 12px;border:1px dashed var(--color-border,#e5e7eb);border-radius:8px;font-size:13px;color:var(--color-text-secondary);">' +
            'Sin cruce con OC. Puede seleccionar una arriba para comparar cantidades y costos antes de ingresar.</div>';
    }
    var ok = !!match.ok;
    var border = ok ? '#10B981' : '#F59E0B';
    var bg = ok ? 'rgba(16,185,129,.06)' : 'rgba(245,158,11,.08)';
    var h = '<div id="orderMatchPanel" style="margin:0 0 14px;padding:12px;border:1px solid ' + border + ';background:' + bg + ';border-radius:8px;">';
    h += '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;margin-bottom:8px;">';
    h += '<strong>Cruce con OC ' + esc(match.order_number || '') + '</strong>';
    h += '<span style="font-size:12px;">Factura ' + poMoney(match.invoice_total) +
         ' · OC ' + poMoney(match.order_total) +
         (Math.abs(match.total_delta || 0) > 0.009 ? (' · Δ ' + poMoney(match.total_delta)) : '') +
         '</span></div>';

    if (ok) {
        h += '<p style="margin:0;font-size:13px;color:#047857;font-weight:600;">Cruce OK: ' +
             (match.matched_count || 0) + ' producto(s) coinciden en cantidad y costo.</p>';
    } else {
        h += '<p style="margin:0 0 8px;font-size:13px;color:#B45309;font-weight:600;">Revise diferencias antes de ingresar (' +
             (match.issue_count || 0) + ' hallazgo(s)).</p>';
        h += '<ul style="margin:0;padding-left:18px;font-size:12px;line-height:1.45;">';
        if (match.qty_diff_count > 0) {
            h += '<li><strong>' + match.qty_diff_count + '</strong> diferencia(s) de cantidad';
            (match.qty_diffs || []).slice(0, 6).forEach(function(r) {
                h += '<div style="color:var(--color-text-secondary);">' + esc(r.name) +
                     ': OC ' + r.oc_qty + ' → Factura ' + r.inv_qty +
                     ' (Δ ' + r.qty_delta + ')</div>';
            });
            h += '</li>';
        }
        if (match.price_diff_count > 0) {
            h += '<li><strong>' + match.price_diff_count + '</strong> diferencia(s) de costo';
            (match.price_diffs || []).slice(0, 6).forEach(function(r) {
                h += '<div style="color:var(--color-text-secondary);">' + esc(r.name) +
                     ': OC ' + poMoney(r.oc_cost) + ' → Factura ' + poMoney(r.inv_cost) + '</div>';
            });
            h += '</li>';
        }
        if (match.only_invoice_count > 0) {
            h += '<li style="background:rgba(16,185,129,0.12);padding:4px 6px;border-radius:4px;margin-bottom:4px;"><strong>' + match.only_invoice_count + '</strong> solo en factura (verde)';
            (match.only_invoice || []).slice(0, 5).forEach(function(r) {
                h += '<div style="color:var(--color-text-secondary);">' + esc(r.name) +
                     ' · ' + r.quantity + ' × ' + poMoney(r.unit_cost) + '</div>';
            });
            h += '</li>';
        }
        if (match.only_order_count > 0) {
            h += '<li style="background:rgba(245,158,11,0.18);padding:4px 6px;border-radius:4px;margin-bottom:4px;"><strong>' + match.only_order_count + '</strong> solo en OC (amarillo)';
            (match.only_order || []).slice(0, 5).forEach(function(r) {
                h += '<div style="color:var(--color-text-secondary);">' + esc(r.name) +
                     ' · ' + r.quantity + ' × ' + poMoney(r.unit_cost) + '</div>';
            });
            h += '</li>';
        }
        if (match.unmatched_invoice_count > 0) {
            h += '<li><strong>' + match.unmatched_invoice_count + '</strong> línea(s) de factura sin producto del catálogo</li>';
        }
        h += '</ul>';
    }
    h += '</div>';
    return h;
}

function renderOcDispositionPanel(linkedOrderId, match) {
    var hasLink = !!(linkedOrderId || (match && match.order_id));
    if (!hasLink) {
        return '<div id="ocDispositionPanel" style="display:none;"></div>';
    }
    var preferPartial = !!(match && (match.only_order_count > 0 || match.qty_diff_count > 0));
    var h = '<div id="ocDispositionPanel" class="form-group" style="margin:0 0 14px;grid-column:1/-1;padding:12px;border:1px solid var(--color-border,#e5e7eb);border-radius:8px;background:rgba(37,99,235,.04);">';
    h += '<label style="font-weight:600;display:block;margin-bottom:8px;">Qué hacer con la OC cruzada</label>';
    h += '<label style="display:flex;gap:8px;align-items:flex-start;margin:0 0 8px;font-weight:500;cursor:pointer;">' +
         '<input type="radio" name="oc_disposition" value="keep_open"' + (preferPartial ? ' checked' : '') + ' style="margin-top:3px;">' +
         '<span><strong>Recepción parcial</strong> — ingresar solo lo de esta factura y dejar la OC abierta con productos pendientes (se puede cruzar de nuevo).</span></label>';
    h += '<label style="display:flex;gap:8px;align-items:flex-start;margin:0;font-weight:500;cursor:pointer;">' +
         '<input type="radio" name="oc_disposition" value="close"' + (!preferPartial ? ' checked' : '') + ' style="margin-top:3px;">' +
         '<span><strong>Cerrar OC completa</strong> — esta factura cierra la OC (reemplaza ítems con lo facturado).</span></label>';
    if (preferPartial) {
        h += '<small style="display:block;margin-top:8px;color:#B45309;">El cruce detectó productos/cantidades pendientes en la OC. Se recomienda recepción parcial.</small>';
    }
    h += '</div>';
    return h;
}

function confirmInvoiceReceive() {
    var form = document.getElementById('invoiceReviewForm');
    var wh = form ? form.querySelector('input[name="warehouse_date"]') : null;
    if (wh && !String(wh.value || '').trim()) {
        if (typeof showAlert === 'function') showAlert('Indique la fecha de recepción de bodega', 'warning');
        if (wh.focus) wh.focus();
        return false;
    }
    if (wh && wh.value) poRemember(wh.value);

    if (window._invoiceReviewCustomVat) {
        var ovEl = document.getElementById('reviewTaxOverrideAmount');
        var ov = ovEl ? invoiceParseMoneyVal(ovEl.value) : 0;
        if (!(ov >= 0.01)) {
            if (typeof showAlert === 'function') showAlert('Indique el IVA personalizado de la factura (monto mayor a 0)', 'warning');
            if (ovEl && ovEl.focus) ovEl.focus();
            return false;
        }
    }

    var match = window._invoiceOrderMatch;
    var disp = document.querySelector('input[name="oc_disposition"]:checked');
    var keepOpen = disp && disp.value === 'keep_open';
    var msg = '¿Ingresar estas líneas al inventario? Se creará/actualizará la OC y su asiento contable.';
    if (match && match.order_id) {
        msg = 'Cruce con OC ' + (match.order_number || '') + '.\n';
        if (keepOpen) {
            msg += 'Recepción parcial: se ingresa esta factura y la OC queda abierta con lo pendiente.\n';
        } else {
            msg += 'Se cerrará la OC completa con los datos de esta factura.\n';
        }
        if (match.ok) {
            msg += 'El cruce coincide. ¿Confirmar ingreso al inventario?';
        } else {
            msg += 'Hay ' + (match.issue_count || 0) + ' diferencia(s) (cantidades, costos o ítems).\n' +
                '¿Desea ingresar de todas formas con los datos de la factura?';
        }
    }
    return confirm(msg);
}

function bindLinkedOrderSelect() {
    var sel = document.getElementById('reviewLinkedOrderId');
    if (!sel || sel.dataset.boundLink === '1') return;
    sel.dataset.boundLink = '1';
    sel.addEventListener('change', function() {
        linkInvoiceToOrder(sel.value || '0');
    });
}

function invoiceParseQty(raw) {
    var s = String(raw == null ? '' : raw).trim().replace(/\s/g, '').replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}

/** Cantidades de factura/inventario: siempre enteros. */
function invoiceParseIntQty(raw) {
    return Math.max(0, Math.round(invoiceParseQty(raw)));
}

function invoiceParseMoneyVal(raw) {
    if (window.SeriMoney && typeof SeriMoney.parse === 'function') {
        return SeriMoney.parse(raw);
    }
    return invoiceParseQty(raw);
}

function bindInvoiceLineTotals() {
    var body = document.getElementById('invoiceReviewBody');
    if (!body || body.dataset.totalsBound === '1') return;
    body.dataset.totalsBound = '1';
    var scheduleRecalc = function() {
        if (window._irRecalcTimer) clearTimeout(window._irRecalcTimer);
        window._irRecalcTimer = setTimeout(function() {
            recalcInvoiceReviewTotals();
        }, 0);
    };
    body.addEventListener('input', function(e) {
        var t = e.target;
        if (!t || !t.name) return;
        if (!/\[(quantity|unit_cost|tax_rate|discount_pct|sale_price)\]$/.test(t.name)
            && t.id !== 'reviewTaxOverrideAmount'
            && !/early_payment_discount_pct|commercial_discount/.test(t.name)) {
            return;
        }
        if (/\[quantity\]$/.test(t.name)) {
            var iq = invoiceParseIntQty(t.value);
            if (String(t.value) !== String(iq) && t.value !== '' && t.value !== '-') {
                if (/\./.test(String(t.value)) || /,/.test(String(t.value))) {
                    t.value = String(iq);
                }
            }
        }
        scheduleRecalc();
    });
    body.addEventListener('change', function(e) {
        var t = e.target;
        if (!t || !t.name) return;
        if (/\[quantity\]$/.test(t.name)) {
            t.value = String(invoiceParseIntQty(t.value));
        }
        if (!/\[(quantity|unit_cost|tax_rate|discount_pct|sale_price|row_action)\]$/.test(t.name)
            && t.id !== 'reviewTaxOverrideAmount'
            && !/early_payment_discount_pct|commercial_discount/.test(t.name)) {
            return;
        }
        scheduleRecalc();
    });
    body.addEventListener('blur', function(e) {
        var t = e.target;
        if (!t || !t.name) return;
        if (/\[(unit_cost|sale_price)\]$/.test(t.name) || t.classList.contains('money-input')) {
            scheduleRecalc();
        }
    }, true);
}

function refreshInvoiceRowVisual(tr, qty, cost, line, action) {
    if (!tr) return;
    var skip = action === 'skip';
    var isBonus = !skip && qty > 0 && cost <= 0 && line <= 0.009;
    var bad = !skip && qty <= 0;
    var fromOc = tr.dataset.fromOc === '1';
    var rowStyle = bad
        ? 'background:rgba(220,38,38,0.10);'
        : (isBonus
            ? 'background:rgba(59,130,246,0.14);'
            : (fromOc
                ? 'background:rgba(245,158,11,0.22);'
                : 'background:rgba(16,185,129,0.16);'));
    tr.setAttribute('style', rowStyle);
    var originTd = tr.querySelector('td:first-child');
    if (originTd) {
        originTd.style.whiteSpace = 'nowrap';
        originTd.style.fontSize = '12px';
        originTd.style.fontWeight = '600';
        originTd.style.color = bad ? '#DC2626' : (isBonus ? '#1D4ED8' : (fromOc ? '#B45309' : '#047857'));
        originTd.textContent = bad ? '—' : (isBonus ? 'Bonif.' : (fromOc ? 'OC' : 'Factura'));
    }
    var descCell = tr.querySelector('td input[name$="[description]"]');
    if (descCell && descCell.parentNode) {
        var hint = descCell.parentNode.querySelector('.inv-bonus-hint');
        if (isBonus) {
            if (!hint) {
                hint = document.createElement('div');
                hint.className = 'inv-bonus-hint';
                hint.style.cssText = 'font-size:11px;color:#1D4ED8;font-weight:600;';
                hint.textContent = 'Bonificación: factura $0 · inventario conserva costo';
                descCell.parentNode.appendChild(hint);
            }
        } else if (hint) {
            hint.remove();
        }
    }
    var qtyEl = tr.querySelector('input[name$="[quantity]"]');
    if (qtyEl) {
        if (bad) qtyEl.setAttribute('required', 'required');
        else qtyEl.removeAttribute('required');
    }
}

function recalcInvoiceReviewTotals() {
    var body = document.getElementById('invoiceReviewBody');
    if (!body) return;
    var vatInCost = body.dataset.vatInCost === '1';
    var customVat = !!window._invoiceReviewCustomVat;
    var rows = body.querySelectorAll('tbody tr');
    var subtotal = 0;
    var taxTotal = 0;
    var grand = 0;
    var productDiscTotal = 0;
    var bonifCount = 0;
    var sinMonto = 0;
    rows.forEach(function(tr) {
        if (tr.dataset.metaRow === '1') return;
        var qtyEl = tr.querySelector('input[name$="[quantity]"]');
        var costEl = tr.querySelector('input[name$="[unit_cost]"]');
        var taxEl = tr.querySelector('input[name$="[tax_rate]"]');
        var discEl = tr.querySelector('input[name$="[discount_pct]"]');
        var actionEl = tr.querySelector('select[name$="[row_action]"]');
        var totalTd = tr.querySelector('.inv-line-total');
        if (!qtyEl || !costEl || !totalTd) return;
        var qty = invoiceParseIntQty(qtyEl.value);
        var cost = invoiceParseMoneyVal(costEl.value);
        var tax = customVat ? 0 : (taxEl ? invoiceParseQty(taxEl.value) : 0);
        var disc = discEl ? Math.max(0, Math.min(100, invoiceParseQty(discEl.value))) : 0;
        var action = actionEl ? actionEl.value : 'add_stock';
        var lineGross = qty * cost;
        var lineDiscAmt = lineGross * (disc / 100);
        var base = lineGross - lineDiscAmt;
        var line = (vatInCost || customVat) ? base : (base * (1 + tax / 100));
        totalTd.textContent = poMoney(line);
        refreshInvoiceRowVisual(tr, qty, cost, line, action);
        if (action === 'skip') return;
        if (!(qty > 0)) sinMonto++;
        if (qty > 0 && cost <= 0 && line <= 0.009) bonifCount++;
        productDiscTotal += lineDiscAmt;
        if (vatInCost && tax > 0.009) {
            var net = line / (1 + tax / 100);
            subtotal += net;
            taxTotal += (line - net);
        } else {
            subtotal += base;
            taxTotal += customVat ? 0 : (base * (tax / 100));
        }
        grand += line;
    });

    if (customVat) {
        var ovEl = document.getElementById('reviewTaxOverrideAmount');
        var ov = ovEl ? Math.max(0, invoiceParseMoneyVal(ovEl.value)) : (parseFloat(window._invoiceReviewTaxOverride) || 0);
        window._invoiceReviewTaxOverride = ov;
        taxTotal = ov;
        grand = subtotal + ov;
    }

    var form = body.closest('form') || document.getElementById('invoiceReviewForm');
    var earlyPctEl = form ? form.querySelector('input[name="early_payment_discount_pct"]') : null;
    var comPctEl = form ? form.querySelector('input[name="commercial_discount_pct"]') : null;
    var comAmtEl = form ? form.querySelector('input[name="commercial_discount_amount"]') : null;
    var earlyPct = earlyPctEl ? Math.max(0, Math.min(100, invoiceParseQty(earlyPctEl.value))) : 0;
    var comPct = comPctEl ? Math.max(0, Math.min(100, invoiceParseQty(comPctEl.value))) : 0;
    var comAmtIn = comAmtEl ? Math.max(0, invoiceParseMoneyVal(comAmtEl.value)) : 0;
    var commercialAmt = comAmtIn > 0.009 ? Math.min(comAmtIn, grand) : (grand * comPct / 100);
    var afterCommercial = Math.max(0, grand - commercialAmt);
    var earlyAmt = afterCommercial * earlyPct / 100;
    var payable = Math.max(0, afterCommercial - earlyAmt);

    // Subtotal visible: antes del dcto. por línea (para que el desc. se vea explícito).
    var displaySubtotal = vatInCost
        ? subtotal
        : (subtotal + productDiscTotal);

    var foot = body.querySelector('.inv-review-foot');
    if (foot) {
        foot.innerHTML = '<span>Subtotal: ' + poMoney(displaySubtotal) + '</span>' +
            (productDiscTotal > 0.009
                ? '<span style="color:#B45309;">Desc. productos: -' + poMoney(productDiscTotal) + '</span>'
                : '') +
            '<span>IVA' + (vatInCost ? ' (en costo)' : (customVat ? ' (personalizado)' : '')) + ': ' + poMoney(taxTotal) + '</span>' +
            '<span>Bruto: ' + poMoney(grand) + '</span>' +
            (commercialAmt > 0.009 ? '<span>Desc. comercial: -' + poMoney(commercialAmt) + '</span>' : '') +
            (earlyAmt > 0.009 ? '<span>Desc. pronto pago: -' + poMoney(earlyAmt) + '</span>' : '') +
            '<span><strong>Total: ' + poMoney(payable) + '</strong></span>';
    }

    var liveNotes = body.querySelector('#invLiveStatusNotes');
    if (liveNotes) {
        var bits = [];
        if (sinMonto > 0) {
            bits.push('<span style="color:#DC2626;font-weight:600;">' + sinMonto + ' línea(s) con cantidad en 0</span>');
        }
        if (bonifCount > 0) {
            bits.push('<span style="color:#047857;font-weight:600;">' + bonifCount + ' bonificación/regalo ($0 en factura)</span>');
        }
        if (productDiscTotal > 0.009) {
            bits.push('<span style="color:#B45309;font-weight:600;">Dcto. en líneas: -' + poMoney(productDiscTotal) + '</span>');
        }
        liveNotes.innerHTML = bits.length ? bits.join(' · ') : '<span style="color:var(--color-text-secondary);">Cambios aplicados al instante</span>';
    }
}

function linkInvoiceToOrder(orderId) {
    var importId = window._invoiceReviewImportId || parseInt((document.getElementById('irImportId') || {}).value || '0', 10);
    if (!importId) return;
    if (!window.poInvoiceLinkOrderUrl) {
        return showAlert('No está disponible la vinculación con OC', 'error');
    }

    var fd = new FormData();
    fd.append('csrf_token', (window.SeriCsrf && SeriCsrf.get) ? SeriCsrf.get() : (window.poCsrfToken || ''));
    fd.append('import_id', String(importId));
    fd.append('linked_order_id', String(orderId || '0'));

    fetch(window.poInvoiceLinkOrderUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success) throw new Error((d && d.message) || 'No se pudo casar la OC');
        if (d.open_orders) window._invoiceReviewOpenOrders = d.open_orders;
        window._invoiceOrderMatch = d.order_match || null;
        renderInvoiceReview(d.import, d.items || []);
        if (typeof showAlert === 'function') showAlert(d.message || 'OC vinculada', 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error al casar OC', 'error');
        // Reabrir preview limpio para no dejar un select inconsistente
        openInvoiceReview(importId);
    });
}

function bindInvoiceProductSearch() {
    var input = document.getElementById('irProductSearch');
    var list = document.getElementById('irProductList');
    if (!input || !list || input.dataset.bound === '1') return;
    input.dataset.bound = '1';
    var selectedId = null;

    function renderList(q) {
        var rows = poFilterProducts(q);
        if (!rows.length) {
            list.hidden = true;
            list.innerHTML = '';
            return;
        }
        list.innerHTML = rows.map(function(p) {
            var label = (p.code ? p.code + ' — ' : '') + p.name + (p.barcode ? ' · EAN ' + p.barcode : '');
            return '<li data-id="' + p.id + '" style="padding:8px 10px;cursor:pointer;">' + esc(label) + '</li>';
        }).join('');
        list.hidden = false;
    }

    input.addEventListener('input', function() {
        selectedId = null;
        var q = input.value.trim();
        var exact = poFindExactProduct(q);
        if (exact && q.length >= 6) {
            selectedId = exact.id;
            input.value = (exact.code ? exact.code + ' — ' : '') + exact.name;
            list.hidden = true;
            addInvoiceManualProduct(exact.id);
            input.value = '';
            selectedId = null;
            return;
        }
        renderList(q);
    });
    input.addEventListener('keydown', function(ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            addInvoiceManualFromSearch();
        }
    });
    list.addEventListener('click', function(ev) {
        var li = ev.target.closest('li[data-id]');
        if (!li) return;
        selectedId = parseInt(li.getAttribute('data-id'), 10);
        var p = (window.poProducts || []).find(function(x) { return x.id === selectedId; });
        input.value = p ? ((p.code ? p.code + ' — ' : '') + p.name) : li.textContent;
        list.hidden = true;
        input.dataset.selectedId = String(selectedId);
    });
}

function addInvoiceManualFromSearch() {
    var input = document.getElementById('irProductSearch');
    if (!input) return;
    var id = parseInt(input.dataset.selectedId || '0', 10);
    if (!id) {
        var exact = poFindExactProduct(input.value);
        if (exact) id = exact.id;
    }
    if (!id) {
        var matches = poFilterProducts(input.value);
        if (matches.length === 1) id = matches[0].id;
    }
    if (!id) {
        return showAlert('Seleccione un producto de la lista o escanee un EAN/SKU exacto', 'warning');
    }
    addInvoiceManualProduct(id);
    input.value = '';
    input.dataset.selectedId = '';
    var list = document.getElementById('irProductList');
    if (list) { list.hidden = true; list.innerHTML = ''; }
}

function addInvoiceManualProduct(productId, opts) {
    var importId = window._invoiceReviewImportId || parseInt(document.getElementById('irImportId').value, 10);
    if (!importId || !productId) return;
    opts = opts || {};
    var asBonus = !!opts.asBonus;
    var qtyEl = document.getElementById('irProductQty');
    var bonusEl = document.getElementById('irProductAsBonus');
    if (!asBonus && bonusEl && bonusEl.checked) asBonus = true;
    var qty = opts.qty != null ? invoiceParseIntQty(opts.qty) : (qtyEl ? invoiceParseIntQty(qtyEl.value) : 1);
    if (!(qty > 0)) qty = 1;
    if (qtyEl && opts.qty == null) qtyEl.value = String(qty);

    var fd = new FormData();
    fd.append('csrf_token', (window.SeriCsrf && SeriCsrf.get) ? SeriCsrf.get() : (window.poCsrfToken || ''));
    fd.append('import_id', String(importId));
    fd.append('product_id', String(productId));
    fd.append('quantity', String(qty));
    if (asBonus) {
        fd.append('as_bonus', '1');
        fd.append('unit_cost', '0');
        fd.append('force_separate', '1');
    } else if (opts.forceSeparate) {
        fd.append('force_separate', '1');
    }

    fetch(window.poInvoiceAddLineUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success) throw new Error((d && d.message) || 'No se pudo agregar');
        window._invoiceReviewOpenOrders = window._invoiceReviewOpenOrders || [];
        // Preservar OC casada seleccionada
        var linked = document.getElementById('reviewLinkedOrderId');
        var linkedVal = linked ? linked.value : '';
        renderInvoiceReview(d.import, d.items || []);
        if (linkedVal) {
            var sel = document.getElementById('reviewLinkedOrderId');
            if (sel) sel.value = linkedVal;
        }
        if (typeof showAlert === 'function') showAlert(d.message || 'Producto agregado', 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error', 'error');
    });
}

function removeInvoiceReviewLine(itemId) {
    var importId = window._invoiceReviewImportId || parseInt((document.getElementById('irImportId') || {}).value || '0', 10);
    itemId = parseInt(itemId, 10) || 0;
    if (!importId || !itemId) return;
    if (!window.confirm('¿Eliminar esta línea del borrador?')) return;

    var fd = new FormData();
    fd.append('csrf_token', (window.SeriCsrf && SeriCsrf.get) ? SeriCsrf.get() : (window.poCsrfToken || ''));
    fd.append('import_id', String(importId));
    fd.append('item_id', String(itemId));

    fetch(window.poInvoiceRemoveLineUrl || (window.comprasBase + '?action=invoice_remove_line'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success) throw new Error((d && d.message) || 'No se pudo eliminar');
        var linked = document.getElementById('reviewLinkedOrderId');
        var linkedVal = linked ? linked.value : '';
        renderInvoiceReview(d.import, d.items || []);
        if (linkedVal) {
            var sel = document.getElementById('reviewLinkedOrderId');
            if (sel) sel.value = linkedVal;
        }
        if (typeof showAlert === 'function') showAlert(d.message || 'Línea eliminada', 'success');
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error', 'error');
    });
}

function upsertPoProduct(p) {
    if (!p || !p.id) return;
    window.poProducts = window.poProducts || [];
    var found = false;
    for (var i = 0; i < window.poProducts.length; i++) {
        if (String(window.poProducts[i].id) === String(p.id)) {
            window.poProducts[i] = Object.assign({}, window.poProducts[i], p);
            found = true;
            break;
        }
    }
    if (!found) {
        window.poProducts.push({
            id: parseInt(p.id, 10),
            code: p.code || '',
            barcode: p.barcode || '',
            name: p.name || '',
            purchase_price: parseFloat(p.purchase_price) || 0,
            tax_rate: parseFloat(p.tax_rate) || 0
        });
        window.poProducts.sort(function(a, b) {
            return String(a.name || '').localeCompare(String(b.name || ''), 'es', { sensitivity: 'base' });
        });
    }
}

function openQuickProductModal() {
    var modal = document.getElementById('quickProductModal');
    var form = document.getElementById('quickProductForm');
    if (!modal || !form) return;
    form.reset();
    var search = document.getElementById('irProductSearch');
    var nameEl = document.getElementById('qpName');
    if (nameEl && search && search.value && !poFindExactProduct(search.value)) {
        nameEl.value = String(search.value).trim();
    }
    var taxEl = document.getElementById('qpTax');
    if (taxEl) taxEl.value = String(window.poDefaultTaxRate != null ? window.poDefaultTaxRate : 0);
    var unitEl = document.getElementById('qpUnit');
    if (unitEl && !unitEl.value) unitEl.value = 'UND';
    var minEl = document.getElementById('qpMinStock');
    if (minEl && !minEl.value) minEl.value = '5';
    if (window.SeriMoney && typeof SeriMoney.enhanceAll === 'function') {
        SeriMoney.enhanceAll(form);
    }
    modal.style.display = 'flex';
    if (nameEl) setTimeout(function() { nameEl.focus(); }, 50);
}

function closeQuickProductModal() {
    var modal = document.getElementById('quickProductModal');
    if (modal) modal.style.display = 'none';
}

function submitQuickProduct(ev) {
    if (ev && ev.preventDefault) ev.preventDefault();
    var form = document.getElementById('quickProductForm');
    if (!form || !window.poQuickProductUrl) return false;
    if (window.SeriMoney && typeof SeriMoney.normalizeForm === 'function') {
        SeriMoney.normalizeForm(form);
    }
    var btn = document.getElementById('qpSubmitBtn');
    var prev = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Creando…'; }
    var fd = new FormData(form);
    fetch(window.poQuickProductUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d || !d.success || !d.product) {
            throw new Error((d && d.message) || 'No se pudo crear el producto');
        }
        upsertPoProduct(d.product);
        closeQuickProductModal();
        if (typeof showAlert === 'function') {
            showAlert(d.message || 'Producto creado', 'success');
        }
        addInvoiceManualProduct(parseInt(d.product.id, 10));
    })
    .catch(function(err) {
        if (typeof showAlert === 'function') showAlert(err.message || 'Error', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = prev || 'Crear y agregar a la factura'; }
        if (window.SeriMoney && typeof SeriMoney.restoreForm === 'function') {
            SeriMoney.restoreForm(form);
        }
    });
    return false;
}

function poToday() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
/** Normaliza fechas de BD (DATE/DATETIME) a YYYY-MM-DD para <input type="date"> */
function poIsoDate(v) {
    if (v == null || v === '') return '';
    var s = String(v).trim();
    var m = s.match(/^(\d{4}-\d{2}-\d{2})/);
    return m ? m[1] : '';
}
function poRememberedDate() {
    try {
        var v = localStorage.getItem(window.poWarehouseKey);
        if (v && /^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
    } catch (e) {}
    return poToday();
}
function poRemember(v) {
    if (v && /^\d{4}-\d{2}-\d{2}$/.test(v)) {
        try { localStorage.setItem(window.poWarehouseKey, v); } catch (e) {}
    }
}
/** Prioridad: guardada en borrador → última usada → fecha factura → hoy */
function poDefaultWarehouseDate(imp) {
    var fromImp = poIsoDate(imp && imp.warehouse_date);
    if (fromImp) return fromImp;
    var remembered = poRememberedDate();
    if (remembered) return remembered;
    var fromInv = poIsoDate(imp && imp.invoice_date);
    if (fromInv) return fromInv;
    return poToday();
}

function productOptionsHtml(selected) {
    // Lazy: solo la opción actual. El catálogo completo se carga al enfocar el select
    // (evita congelar el modal con miles de <option> × N líneas).
    var h = '<option value="">Seleccionar producto</option>';
    var sel = selected ? String(selected) : '';
    if (sel) {
        var p = poFindProductById(sel);
        if (p) {
            var label = (p.code ? p.code + ' — ' : '') + p.name + (p.barcode ? ' · EAN ' + p.barcode : '');
            h += '<option value="' + p.id + '" data-cost="' + p.purchase_price + '" data-code="' + esc(p.code || '') + '" data-barcode="' + esc(p.barcode || '') + '" selected>' +
                esc(label) + '</option>';
        } else {
            h += '<option value="' + esc(sel) + '" selected>#' + esc(sel) + '</option>';
        }
    }
    return h;
}

function poFindProductById(id) {
    var sid = String(id || '');
    if (!sid) return null;
    var found = null;
    (window.poProducts || []).some(function(p) {
        if (String(p.id) === sid) { found = p; return true; }
        return false;
    });
    return found;
}

function poFullProductOptionsHtml(selected) {
    if (!window._poProductOptionsBase) {
        var base = '<option value="">Seleccionar producto</option>';
        (window.poProducts || []).forEach(function(p) {
            var label = (p.code ? p.code + ' — ' : '') + p.name + (p.barcode ? ' · EAN ' + p.barcode : '');
            base += '<option value="' + p.id + '" data-cost="' + p.purchase_price + '" data-code="' + esc(p.code || '') + '" data-barcode="' + esc(p.barcode || '') + '">' +
                esc(label) + '</option>';
        });
        window._poProductOptionsBase = base;
    }
    var h = window._poProductOptionsBase;
    var sel = selected ? String(selected) : '';
    if (sel) {
        h = h.replace('value="' + sel + '"', 'value="' + sel + '" selected');
    }
    return h;
}

function bindInvoiceProductSelectsLazy() {
    var body = document.getElementById('invoiceReviewBody');
    if (!body || body.dataset.productSelectsBound === '1') return;
    body.dataset.productSelectsBound = '1';
    body.addEventListener('focusin', function(e) {
        var t = e.target;
        if (!t || t.tagName !== 'SELECT' || !/\[matched_product_id\]$/.test(t.name || '')) return;
        if (t.dataset.fullOptions === '1') return;
        var selected = t.value;
        var keep = t.value;
        t.innerHTML = poFullProductOptionsHtml(selected);
        t.value = keep;
        t.dataset.fullOptions = '1';
    });
}

function poProductSearchText(p) {
    return [p.code, p.barcode, p.name].filter(Boolean).join(' ').toLowerCase();
}

function poFindExactProduct(code) {
    var codeLc = String(code || '').trim().toLowerCase();
    if (!codeLc) return null;
    var found = null;
    (window.poProducts || []).some(function(p) {
        if (String(p.code || '').toLowerCase() === codeLc || String(p.barcode || '').toLowerCase() === codeLc) {
            found = p;
            return true;
        }
        return false;
    });
    return found;
}

function poFilterProducts(q) {
    q = String(q || '').trim().toLowerCase();
    if (!q) return [];
    var out = [];
    (window.poProducts || []).forEach(function(p) {
        if (poProductSearchText(p).indexOf(q) !== -1) out.push(p);
    });
    return out.slice(0, 40);
}

function preparePurchaseItems() {
    var rows = document.querySelectorAll('#poItems .po-item-row');
    if (!rows.length) {
        if (typeof showAlert === 'function') showAlert('Agregue al menos un producto a la orden', 'error');
        return false;
    }
    var ok = false;
    rows.forEach(function(row) {
        var pid = row.querySelector('input[name="product_id[]"], select[name="product_id[]"]');
        var qty = row.querySelector('input[name="quantity[]"]');
        var val = pid ? (pid.value || '') : '';
        if (parseInt(val, 10) > 0 && qty && parseFloat(qty.value) > 0) ok = true;
    });
    if (!ok) {
        if (typeof showAlert === 'function') showAlert('Seleccione producto y cantidad válidos', 'error');
        return false;
    }
    return true;
}

function addPoItem(prefill) {
    prefill = prefill || {};
    var wrap = document.createElement('div');
    wrap.className = 'po-item-row';
    wrap.style.cssText = 'display:grid;grid-template-columns:2.2fr 0.7fr 0.9fr 0.7fr auto;gap:8px;margin-bottom:8px;align-items:end;';
    var selectedId = prefill.product_id || '';
    var selectedLabel = '';
    if (selectedId) {
        var found = (window.poProducts || []).find(function(p) { return String(p.id) === String(selectedId); });
        if (found) {
            selectedLabel = (found.code ? found.code + ' — ' : '') + found.name;
        }
    }
    wrap.innerHTML =
        '<div class="form-group" style="margin:0;position:relative;"><label>Producto <span class="field-tip" data-tip="Producto del catálogo a pedir. Use buscar o el escáner EAN/SKU." tabindex="0">?</span></label>' +
        '<input type="hidden" name="product_id[]" class="po-product-id" value="' + esc(selectedId) + '" required>' +
        '<input type="search" class="form-control po-product-search" autocomplete="off" placeholder="Buscar producto…" value="' + esc(selectedLabel) + '">' +
        '<ul class="combobox-list po-row-suggest" hidden></ul></div>' +
        '<div class="form-group" style="margin:0;"><label>Cant. <span class="field-tip" data-tip="Unidades solicitadas al proveedor. Aún no afectan el inventario." tabindex="0">?</span></label>' +
        '<input type="number" name="quantity[]" class="form-control" min="1" value="' + (prefill.quantity || 1) + '" required></div>' +
        '<div class="form-group" style="margin:0;"><label>Costo u. <span class="field-tip" data-tip="Costo unitario estimado de compra. Se ajusta al recibir o cargar la factura real." tabindex="0">?</span></label>' +
        '<input type="text" inputmode="decimal" name="unit_cost[]" class="form-control money-input" data-money="1" value="' + (prefill.unit_cost != null ? (window.SeriMoney ? SeriMoney.format(prefill.unit_cost) : prefill.unit_cost) : '0') + '" required></div>' +
        '<div class="form-group" style="margin:0;"><label>IVA % <span class="field-tip" data-tip="Tarifa de IVA descontable estimada. Hereda del producto o de Configuración; se confirma en la recepción/factura." tabindex="0">?</span></label>' +
        '<input type="number" step="0.01" name="tax_rate[]" class="form-control" min="0" value="' + (prefill.tax_rate != null ? prefill.tax_rate : (window.poDefaultTaxRate != null ? window.poDefaultTaxRate : 0)) + '"></div>' +
        '<button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()" title="Quitar línea">✕</button>';
    document.getElementById('poItems').appendChild(wrap);
    bindPoRowSearch(wrap);
    if (window.SeriMoney && typeof SeriMoney.enhanceAll === 'function') {
        SeriMoney.enhanceAll(wrap);
    } else if (window.SeriMoney && typeof SeriMoney.enhance === 'function') {
        var costEl = wrap.querySelector('input[name="unit_cost[]"]');
        if (costEl) SeriMoney.enhance(costEl);
    }
}

function bindPoRowSearch(wrap) {
    var hidden = wrap.querySelector('.po-product-id');
    var input = wrap.querySelector('.po-product-search');
    var list = wrap.querySelector('.po-row-suggest');
    if (!hidden || !input || !list || input.dataset.bound === '1') return;
    input.dataset.bound = '1';

    function closeList() { list.hidden = true; }
    function openList() { list.hidden = false; }
    function render(q) {
        var matches = poFilterProducts(q);
        if (!matches.length) {
            list.innerHTML = '<li class="combobox-option" style="opacity:.7;cursor:default;">Sin coincidencias</li>';
            openList();
            return;
        }
        list.innerHTML = matches.map(function(p) {
            var label = (p.code ? p.code + ' — ' : '') + p.name + (p.barcode ? ' · ' + p.barcode : '');
            return '<li class="combobox-option" data-id="' + p.id + '" data-cost="' + p.purchase_price + '" data-tax="' + (p.tax_rate != null ? p.tax_rate : '') + '" data-label="' + esc(label) + '">' +
                esc(p.name) +
                '<small>' + esc((p.code || '') + (p.barcode ? ' · EAN ' + p.barcode : '')) + '</small></li>';
        }).join('');
        openList();
    }
    function pick(li) {
        if (!li || !li.getAttribute('data-id')) return;
        hidden.value = li.getAttribute('data-id') || '';
        input.value = li.getAttribute('data-label') || '';
        var cost = li.getAttribute('data-cost') || '0';
        var costInput = wrap.querySelector('input[name="unit_cost[]"]');
        if (costInput) {
            costInput.value = window.SeriMoney ? SeriMoney.format(cost) : cost;
        }
        var taxInput = wrap.querySelector('input[name="tax_rate[]"]');
        if (taxInput && li.getAttribute('data-tax') !== null && li.getAttribute('data-tax') !== '') {
            taxInput.value = li.getAttribute('data-tax');
        }
        closeList();
    }

    input.addEventListener('focus', function() { render(input.value); });
    input.addEventListener('input', function() {
        hidden.value = '';
        render(input.value);
    });
    list.addEventListener('mousedown', function(e) {
        var li = e.target.closest('.combobox-option');
        if (!li || !li.getAttribute('data-id')) return;
        e.preventDefault();
        pick(li);
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeList(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            var exact = poFindExactProduct(input.value);
            if (exact) {
                hidden.value = String(exact.id);
                input.value = (exact.code ? exact.code + ' — ' : '') + exact.name;
                var costInput = wrap.querySelector('input[name="unit_cost[]"]');
                if (costInput) costInput.value = window.SeriMoney ? SeriMoney.format(exact.purchase_price || 0) : (exact.purchase_price || 0);
                var taxInput = wrap.querySelector('input[name="tax_rate[]"]');
                if (taxInput && exact.tax_rate != null) taxInput.value = exact.tax_rate;
                closeList();
                return;
            }
            var first = list.querySelector('.combobox-option[data-id]');
            if (first && !list.hidden) pick(first);
        }
    });
    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) closeList();
    });
}

function onPoProductChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var cost = opt ? opt.getAttribute('data-cost') : '0';
    var row = sel.closest('.po-item-row');
    if (row) {
        var costInput = row.querySelector('input[name="unit_cost[]"]');
        if (costInput) {
            var c = cost || 0;
            costInput.value = window.SeriMoney ? SeriMoney.format(c) : c;
        }
    }
}

function setPurchaseModalActions(isEdit, status) {
    var draftBtn = document.getElementById('poSaveDraftBtn');
    var submitBtn = document.getElementById('poSaveOrderBtn');
    var draft = document.getElementById('poSaveAsDraft');
    if (isEdit && status === 'ordered') {
        if (draftBtn) draftBtn.style.display = 'none';
        if (draft) draft.value = '0';
        if (submitBtn) submitBtn.textContent = 'Guardar cambios';
    } else if (isEdit && status === 'draft') {
        if (draftBtn) draftBtn.style.display = '';
        if (draft) draft.value = '1';
        if (submitBtn) submitBtn.textContent = 'Guardar borrador';
    } else {
        if (draftBtn) draftBtn.style.display = '';
        if (draft) draft.value = '0';
        if (submitBtn) submitBtn.textContent = 'Guardar y generar OC';
    }
}

function resetPurchaseModal() {
    var form = document.getElementById('purchaseForm');
    if (form) {
        form.reset();
        form.action = '<?php echo $viewInstance->route('app/compras'); ?>?action=create';
    }
    document.getElementById('poItems').innerHTML = '';
    var from = document.getElementById('poDuplicatedFromId');
    if (from) from.value = '';
    var editId = document.getElementById('poEditId');
    if (editId) editId.value = '';
    var draft = document.getElementById('poSaveAsDraft');
    if (draft) draft.value = '0';
    var title = document.getElementById('purchaseModalTitle');
    if (title) title.textContent = 'Nueva orden de compra';
    var hint = document.getElementById('purchaseModalHint');
    if (hint) hint.style.display = 'none';
    document.getElementById('poOrderDate').value = poToday();
    var exp = document.getElementById('poExpectedDate');
    if (exp) exp.value = '';
    var search = document.getElementById('poProductSearch');
    if (search) search.value = '';
    var suggest = document.getElementById('poProductSuggest');
    if (suggest) { suggest.hidden = true; suggest.innerHTML = ''; }
    setPurchaseModalActions(false, null);
}

function editPurchase(id) {
    fetch(window.poDetailUrl + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return showAlert(d.message || 'No encontrada', 'error');
            var o = d.order || {};
            if (!['draft', 'ordered'].includes(String(o.status || ''))) {
                return showAlert('Solo se editan OC en borrador u ordenadas', 'warning');
            }
            var items = o.items || [];
            resetPurchaseModal();
            var form = document.getElementById('purchaseForm');
            if (form) form.action = '<?php echo $viewInstance->route('app/compras'); ?>?action=update';
            var editId = document.getElementById('poEditId');
            if (editId) editId.value = String(o.id || id);
            var title = document.getElementById('purchaseModalTitle');
            if (title) title.textContent = 'Editar orden ' + (o.order_number || ('#' + id));
            var supplier = document.getElementById('poSupplierId');
            if (supplier && o.supplier_id) supplier.value = String(o.supplier_id);
            document.getElementById('poOrderDate').value = (o.order_date || poToday()).toString().substr(0, 10);
            var exp = document.getElementById('poExpectedDate');
            if (exp) exp.value = (o.expected_date || '').toString().substr(0, 10);
            var notes = document.getElementById('poNotes');
            if (notes) notes.value = o.notes || '';
            setPurchaseModalActions(true, String(o.status || 'ordered'));
            if (!items.length) {
                addPoItem();
            } else {
                items.forEach(function(it) {
                    addPoItem({
                        product_id: it.product_id || '',
                        quantity: it.quantity || 1,
                        unit_cost: it.unit_cost || 0,
                        tax_rate: it.tax_rate != null ? it.tax_rate : (window.poDefaultTaxRate != null ? window.poDefaultTaxRate : 0)
                    });
                });
            }
            document.getElementById('purchaseModal').style.display = 'flex';
        })
        .catch(function() { showAlert('No se pudo cargar la OC', 'error'); });
}

function openPurchaseModal() {
    resetPurchaseModal();
    document.getElementById('purchaseModal').style.display = 'flex';
    setTimeout(function() {
        var search = document.getElementById('poProductSearch');
        if (search) search.focus();
    }, 80);
}

function duplicatePurchase(id) {
    fetch(window.poDetailUrl + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return showAlert(d.message || 'No encontrada', 'error');
            var o = d.order || {};
            var items = o.items || [];
            resetPurchaseModal();
            var title = document.getElementById('purchaseModalTitle');
            if (title) title.textContent = 'Duplicar orden de compra';
            var hint = document.getElementById('purchaseModalHint');
            if (hint) hint.style.display = 'block';
            var from = document.getElementById('poDuplicatedFromId');
            if (from) from.value = String(o.id || id);
            var supplier = document.getElementById('poSupplierId');
            if (supplier && o.supplier_id) supplier.value = String(o.supplier_id);
            document.getElementById('poOrderDate').value = poToday();
            var exp = document.getElementById('poExpectedDate');
            if (exp) exp.value = (o.expected_date || '').toString().substr(0, 10);
            var notes = document.getElementById('poNotes');
            if (notes) {
                var base = (o.notes || '').trim();
                var prefix = 'Copia de ' + (o.order_number || ('#' + id));
                notes.value = base ? (prefix + ' · ' + base) : prefix;
            }
            if (!items.length) {
                addPoItem();
            } else {
                items.forEach(function(it) {
                    addPoItem({
                        product_id: it.product_id || '',
                        quantity: it.quantity || 1,
                        unit_cost: it.unit_cost || 0,
                        tax_rate: it.tax_rate != null ? it.tax_rate : (window.poDefaultTaxRate != null ? window.poDefaultTaxRate : 0)
                    });
                });
            }
            document.getElementById('purchaseModal').style.display = 'flex';
        })
        .catch(function() { showAlert('No se pudo cargar la OC para duplicar', 'error'); });
}

function openReceiveModal(id, number) {
    document.getElementById('receiveOrderId').value = id;
    document.getElementById('receiveOrderLabel').textContent = 'OC ' + number;
    var wh = document.getElementById('receiveWarehouseDate');
    wh.value = poRememberedDate();
    wh.onchange = function() { poRemember(wh.value); };
    var invDate = document.getElementById('receiveInvoiceDate');
    if (invDate) invDate.value = poToday();
    var due = document.getElementById('receiveDueDate');
    if (due) due.value = '';
    var disc = document.getElementById('receiveEarlyDiscount');
    if (disc) disc.value = '';
    var comPct = document.getElementById('receiveCommercialPct');
    if (comPct) comPct.value = '';
    var comAmt = document.getElementById('receiveCommercialAmt');
    if (comAmt) comAmt.value = '';
    document.getElementById('receiveModal').style.display = 'flex';
}

function syncPayCashSourceUi() {
    var method = document.getElementById('payMethod');
    var wrap = document.getElementById('payCashSourceWrap');
    var otherWrap = document.getElementById('payCashOtherWrap');
    var drawerHint = document.getElementById('payCashDrawerHint');
    var note = document.getElementById('payCashSourceNote');
    var otherRadio = document.getElementById('payCashOther');
    if (!method || !wrap) return;
    var isCash = String(method.value || '') === 'cash';
    wrap.style.display = isCash ? '' : 'none';
    var isOther = isCash && otherRadio && otherRadio.checked;
    if (otherWrap) otherWrap.style.display = isOther ? '' : 'none';
    if (drawerHint) drawerHint.style.display = (isCash && !isOther) ? '' : 'none';
    if (note) {
        if (isOther) note.setAttribute('required', 'required');
        else {
            note.removeAttribute('required');
            note.value = '';
        }
    }
}

function openPayModal(id, number, balance, supplier) {
    document.getElementById('payOrderId').value = id;
    document.getElementById('payOrderLabel').textContent = 'OC ' + number + (supplier ? ' — ' + supplier : '');
    document.getElementById('payBalanceLabel').textContent =
        'Saldo pendiente: ' + poMoney(balance || 0);
    var amount = document.getElementById('payAmount');
    amount.value = window.SeriMoney ? SeriMoney.format(balance || 0) : parseFloat(balance || 0).toFixed(2);
    amount.removeAttribute('max');
    document.getElementById('payDate').value = poToday();
    if (window.SeriMoney && typeof SeriMoney.enhance === 'function') SeriMoney.enhance(amount);
    var receipt = document.getElementById('payReceiptFile');
    if (receipt) receipt.value = '';
    var method = document.getElementById('payMethod');
    if (method) method.value = 'cash';
    var drawer = document.getElementById('payCashDrawer');
    if (drawer) drawer.checked = true;
    var note = document.getElementById('payCashSourceNote');
    if (note) note.value = '';
    syncPayCashSourceUi();
    document.getElementById('payModal').style.display = 'flex';
}

function openObservationsModal(payload) {
    if (!payload || !payload.id) return;
    var modal = document.getElementById('observationsModal');
    if (!modal) return;
    document.getElementById('obsOrderId').value = payload.id;
    var inv = String(payload.invoice || '').trim();
    var oc = String(payload.order || '').trim();
    var label = oc ? ('OC ' + oc) : ('Factura #' + payload.id);
    if (inv) label += ' · Factura ' + inv;
    document.getElementById('obsOrderLabel').textContent = label;
    var ta = document.getElementById('obsNotes');
    if (ta) {
        ta.value = String(payload.notes || '');
        if (!payload.canEdit) ta.setAttribute('readonly', 'readonly');
        else ta.removeAttribute('readonly');
    }
    var saveBtn = document.getElementById('obsSaveBtn');
    if (saveBtn) saveBtn.style.display = payload.canEdit ? '' : 'none';
    modal.style.display = 'flex';
    if (ta && payload.canEdit) {
        setTimeout(function () { ta.focus(); }, 50);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var method = document.getElementById('payMethod');
    if (method) method.addEventListener('change', syncPayCashSourceUi);
    Array.prototype.forEach.call(document.querySelectorAll('input[name="cash_source"]'), function (el) {
        el.addEventListener('change', syncPayCashSourceUi);
    });
    syncPayCashSourceUi();
});

function voidReceivedOrder(id, number, opts) {
    opts = opts || {};
    var reason = opts.reason;
    if (!reason) {
        reason = prompt(
            'Anular factura/OC ' + (number || '') + ' por error.\n'
            + 'Se emitirá NC total (sale stock), asiento inverso y la factura dejará de estar activa.\n'
            + 'Si hay abonos, se cancelan y se revierten automáticamente.\n\n'
            + 'Motivo de anulación:',
            'Error de recepción'
        );
        if (reason === null) return;
        reason = String(reason || '').trim();
        if (reason.length < 3) {
            return showAlert('Indique un motivo de al menos 3 caracteres', 'warning');
        }
        if (!confirm('¿Confirma anular la OC ' + (number || '') + '? Esta acción no se puede deshacer fácilmente.')) {
            return;
        }
    }
    var csrf = (window.SeriCsrf && SeriCsrf.get) ? SeriCsrf.get() : (window.poCsrfToken || '');
    var fd = new FormData();
    fd.append('csrf_token', csrf || '');
    fd.append('id', String(id));
    fd.append('reason', reason);
    var base = (typeof comprasBase !== 'undefined' && comprasBase)
        ? comprasBase
        : (window.comprasBase || <?php echo json_encode($comprasBase); ?>);
    fetch(base + '?action=void_received', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf || ''
        }
    })
    .then(function(r) {
        return r.text().then(function(text) {
            var d = null;
            try { d = JSON.parse(text); } catch (e) { d = null; }
            if (!d) {
                throw new Error(r.ok
                    ? 'Respuesta inválida del servidor'
                    : ('Error del servidor (HTTP ' + r.status + ')'));
            }
            if (window.SeriCsrf && d.csrf_token) SeriCsrf.apply(d.csrf_token);
            if (d.code === 'csrf' && !opts.retried && d.csrf_token) {
                return voidReceivedOrder(id, number, { reason: reason, retried: true });
            }
            if (!r.ok || !d.success) {
                throw new Error(d.message || 'No se pudo anular');
            }
            return d;
        });
    })
    .then(function(d) {
        if (!d || typeof d !== 'object' || !d.success) return;
        showAlert(d.message || 'Factura anulada', 'success');
        setTimeout(function() { window.location.href = base + '?action=facturas'; }, 700);
    })
    .catch(function(err) {
        showAlert(err.message || 'No se pudo anular', 'error');
    });
}

function poWhatsapp(orderId) {
    fetch('<?php echo $comprasBase; ?>?action=order_whatsapp&id=' + orderId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) return showAlert(d.message || 'No se pudo preparar el mensaje', 'error');
        if (!d.has_phone) {
            showAlert('El proveedor no tiene teléfono registrado: elija el contacto en WhatsApp', 'warning');
        }
        window.open(d.link, '_blank');
    })
    .catch(function() { showAlert('No se pudo preparar el mensaje', 'error'); });
}

function poEmail(orderId, supplier) {
    var modal = document.getElementById('poEmailModal');
    if (!modal) return showAlert('No tiene permiso para enviar correo', 'warning');
    document.getElementById('poEmailOrderId').value = orderId;
    document.getElementById('poEmailSupplier').textContent = supplier || '';
    modal.style.display = 'flex';
}

function openCreditNoteModal(id) {
    fetch(window.poDetailUrl + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return showAlert(d.message || 'No encontrada', 'error');
            var o = d.order, items = o.items || [];
            if (o.status !== 'received') {
                return showAlert('Solo se emiten notas de crédito sobre OC recibidas', 'warning');
            }
            document.getElementById('cnOrderId').value = o.id;
            document.getElementById('cnOrderLabel').textContent =
                'OC ' + (o.order_number || '') + (o.supplier_name ? ' — ' + o.supplier_name : '');
            document.getElementById('cnDate').value = poToday();
            document.getElementById('cnAffectsStock').checked = true;
            var body = document.getElementById('cnItemsBody');
            body.innerHTML = '';
            var hasAvail = false;
            items.forEach(function(it) {
                var received = parseInt(it.received_qty != null ? it.received_qty : it.quantity, 10) || 0;
                var credited = parseInt(it.credited_qty || 0, 10) || 0;
                var avail = Math.max(0, received - credited);
                if (avail > 0) hasAvail = true;
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + esc(it.product_name) +
                    '<input type="hidden" name="item_id[]" value="' + it.id + '"></td>' +
                    '<td>' + received + '</td>' +
                    '<td>' + credited + '</td>' +
                    '<td>' + avail + '</td>' +
                    '<td><input type="number" name="quantity[]" class="form-control" min="0" max="' + avail +
                    '" value="0" ' + (avail <= 0 ? 'disabled' : '') + ' style="width:90px;"></td>';
                body.appendChild(tr);
            });
            if (!hasAvail) {
                return showAlert('No hay cantidades disponibles para acreditar en esta OC', 'warning');
            }
            document.getElementById('creditNoteModal').style.display = 'flex';
        });
}

function viewPurchase(id) {
    fetch(window.poDetailUrl + '&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return showAlert(d.message || 'No encontrada', 'error');
            var o = d.order, items = o.items || [];
            var notesText = String(o.notes || '');
            var isVoided = o.status === 'cancelled' && (
                !!(o.voided_at)
                || notesText.indexOf('ANULADA POST-RECEPCIÓN') !== -1
                || notesText.indexOf('[[NC_FULL') !== -1
                || parseFloat(o.credit_total || 0) > 0.009
            );
            var statusLabel = isVoided ? 'Anulada' : esc(o.status);
            var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">';
            h += '<div><strong>OC:</strong> ' + esc(o.order_number) + '</div>';
            h += '<div><strong>Estado:</strong> ' + (isVoided
                ? '<span class="badge badge-danger">Anulada</span>'
                : statusLabel) + '</div>';
            h += '<div><strong>Proveedor:</strong> ' + esc(o.supplier_name || '—') + '</div>';
            h += '<div><strong>Factura:</strong> ' + esc(o.invoice_number || '—') + '</div>';
            if (o.invoice_path) {
                h += '<div><strong>Adjunto:</strong> <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice&id=' + o.id + '" target="_blank">Ver foto/PDF</a></div>';
            }
            h += '<div><strong>Fecha OC:</strong> ' + esc((o.order_date || '').substr(0,10)) + '</div>';
            h += '<div><strong>Fecha de factura:</strong> ' + esc((o.invoice_date || '—').toString().substr(0,10)) + '</div>';
            h += '<div><strong>Vencimiento de pago:</strong> ' + esc((o.due_date || '—').toString().substr(0,10)) + '</div>';
            h += '<div><strong>Recepción de bodega:</strong> ' + esc((o.warehouse_date || '—').toString().substr(0,10)) + '</div>';
            h += '<div><strong>Subtotal:</strong> ' + poMoney(o.subtotal) + '</div>';
            h += '<div><strong>IVA:</strong> ' + poMoney(o.tax) + '</div>';
            h += '<div><strong>Total:</strong> ' + poMoney(o.total) + '</div>';
            h += '<div><strong>Notas crédito:</strong> ' + poMoney(o.credit_total) + '</div>';
            h += '<div><strong>Neto:</strong> ' + poMoney(Math.max(0, parseFloat(o.total || 0) - parseFloat(o.credit_total || 0))) + '</div>';
            h += '<div><strong>Asiento:</strong> ' + esc(o.accounting_entry_number || (o.accounting_entry_id ? ('#'+o.accounting_entry_id) : 'pendiente')) + '</div>';
            h += '</div>';
            if (o.review_notes) {
                h += '<p style="margin:0 0 12px;padding:10px 12px;border-radius:8px;background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.2);font-size:13px;">'
                    + '<strong>Observación:</strong> ' + esc(o.review_notes) + '</p>';
            }
            if (isVoided) {
                h += '<p style="margin:0 0 12px;padding:10px 12px;border-radius:8px;background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.25);font-size:13px;">'
                    + '<strong>Factura anulada.</strong> Queda en el historial para trazabilidad; no admite abonos ni nuevas NC.'
                    + (o.voided_at ? (' · ' + esc(String(o.voided_at).substr(0, 16).replace('T', ' '))) : '')
                    + (o.void_reason ? (' · Motivo: ' + esc(o.void_reason)) : '')
                    + '</p>';
            } else {
                h += '<p style="margin:0 0 10px;font-size:12px;color:var(--color-text-secondary);">'
                    + 'NC con stock = devolución (sale inventario). Visible en Contabilidad → Trazabilidad de facturas y en Inventario → Trazabilidad.</p>';
            }
            h += '<table><thead><tr><th>Producto</th><th>EAN</th><th>Cant</th><th>Recibido</th><th>NC</th><th>Costo</th><th>IVA%</th><th>Total</th></tr></thead><tbody>';
            items.forEach(function(it) {
                h += '<tr><td>' + esc(it.product_name) +
                    (it.product_code ? '<div style="font-size:11px;color:var(--color-text-secondary);">' + esc(it.product_code) + '</div>' : '') +
                    '</td>';
                h += '<td style="font-size:12px;white-space:nowrap;">' + esc(it.barcode || '—') + '</td>';
                h += '<td>' + it.quantity + '</td>';
                h += '<td>' + (it.received_qty != null ? it.received_qty : it.quantity) + '</td>';
                h += '<td>' + (it.credited_qty || 0) + '</td>';
                h += '<td>' + poMoney(it.unit_cost) + '</td>';
                h += '<td>' + parseFloat(it.tax_rate||0).toFixed(2) + '</td>';
                h += '<td>' + poMoney(it.line_total) + '</td></tr>';
            });
            h += '</tbody></table>';
            var payments = o.payments || [];
            if (payments.length) {
                h += '<h4 style="margin-top:16px;">Abonos al proveedor</h4>';
                h += '<table><thead><tr><th>Fecha</th><th>Medio</th><th>Estado</th><th>Referencia</th><th>Asiento</th><th>Comprobante</th><th>Valor</th></tr></thead><tbody>';
                payments.forEach(function(p) {
                    var paySt = String(p.status || 'applied');
                    h += '<tr' + (paySt === 'cancelled' ? ' style="opacity:0.7;"' : '') + '>';
                    h += '<td>' + esc((p.payment_date || '').substr(0,10)) + '</td>';
                    h += '<td>' + esc(p.payment_method || '') + '</td>';
                    h += '<td>' + (paySt === 'cancelled'
                        ? '<span class="badge badge-danger">Cancelado</span>'
                        : esc(paySt)) + '</td>';
                    h += '<td>' + esc(p.reference || '—') + '</td>';
                    h += '<td>' + esc(p.accounting_entry_number || '—') + '</td>';
                    h += '<td>';
                    if (p.receipt_path) {
                        h += '<a class="btn btn-sm btn-secondary" href="<?php echo $viewInstance->route('app/compras'); ?>?action=payment_receipt&id=' + p.id + '" target="_blank" rel="noopener">Ver</a>';
                    } else {
                        h += '—';
                    }
                    h += '</td>';
                    h += '<td>' + poMoney(p.amount) + '</td></tr>';
                });
                h += '</tbody></table>';
            }
            var notes = o.credit_notes || [];
            if (notes.length) {
                h += '<h4 style="margin-top:16px;">Notas de crédito / devoluciones</h4>';
                h += '<table><thead><tr><th>Número</th><th>Fecha</th><th>Motivo</th><th>Stock</th><th>Asiento</th><th>Total</th><th></th></tr></thead><tbody>';
                notes.forEach(function(n) {
                    h += '<tr><td>' + esc(n.credit_number || '') + '</td>';
                    h += '<td>' + esc((n.credit_date || '').substr(0,10)) + '</td>';
                    h += '<td>' + esc(n.reason || n.external_number || '—') + '</td>';
                    h += '<td>' + (parseInt(n.affects_stock, 10) === 1 ? 'Sí (devolución)' : 'No (ajuste)') + '</td>';
                    h += '<td>' + esc(n.accounting_entry_number || (n.accounting_entry_id ? ('#' + n.accounting_entry_id) : 'pendiente')) + '</td>';
                    h += '<td>' + poMoney(n.total) + '</td>';
                    h += '<td><a class="btn btn-sm btn-primary" href="' + <?php echo json_encode($viewInstance->route('app/compras')); ?> + '?action=credit_note_pdf&id=' + n.id + '" target="_blank" rel="noopener">PDF</a></td></tr>';
                });
                h += '</tbody></table>';
            }
            var timeline = o.timeline || [];
            if (timeline.length) {
                h += '<h4 style="margin-top:16px;">Historial de movimientos</h4>';
                h += '<ol style="margin:0;padding-left:18px;font-size:13px;line-height:1.45;">';
                timeline.forEach(function(ev) {
                    var at = String(ev.at || '').replace('T', ' ').substr(0, 19);
                    h += '<li style="margin-bottom:8px;">';
                    h += '<strong>' + esc(ev.label || ev.type || 'Evento') + '</strong>';
                    if (at) h += ' <span style="color:var(--color-text-secondary);">· ' + esc(at) + '</span>';
                    if (ev.amount != null && isFinite(parseFloat(ev.amount))) {
                        h += ' <span style="font-weight:600;">· ' + poMoney(ev.amount) + '</span>';
                    }
                    if (ev.detail) {
                        h += '<div style="color:var(--color-text-secondary);font-size:12px;">' + esc(ev.detail) + '</div>';
                    }
                    h += '</li>';
                });
                h += '</ol>';
            }
            if (o.balance != null && parseFloat(o.balance) > 0.009) {
                h += '<p style="margin-top:10px;font-weight:600;color:#DC2626;">Saldo pendiente: '
                    + poMoney(o.balance) + '</p>';
            } else if (o.balance != null && parseFloat(o.balance) < -0.009) {
                h += '<p style="margin-top:10px;font-weight:600;color:#2563EB;">Saldo a favor: '
                    + poMoney(Math.abs(parseFloat(o.balance))) + '</p>';
            } else if (o.status === 'received') {
                h += '<p style="margin-top:10px;font-weight:600;color:#10B981;">Orden sin saldo pendiente</p>';
            }
            if (o.status === 'received' && !isVoided) {
                h += '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">'
                    + '<button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById(\'purchaseDetailModal\').style.display=\'none\';openCreditNoteModal(' + o.id + ')">Emitir nota de crédito</button>'
                    + '<button type="button" class="btn btn-sm btn-danger" onclick="document.getElementById(\'purchaseDetailModal\').style.display=\'none\';voidReceivedOrder(' + o.id + ', \'' + esc(o.order_number || '').replace(/'/g, '') + '\')">Anular por error</button>'
                    + '</div>';
            }
            if (o.notes) h += '<p style="margin-top:10px;font-size:13px;"><strong>Notas:</strong> ' + esc(o.notes) + '</p>';
            document.getElementById('purchaseDetailContent').innerHTML = h;
            document.getElementById('purchaseDetailModal').style.display = 'flex';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    // Tras leer una factura se vuelve con ?import_id=N: abrimos la revisión directo.
    var importId = new URLSearchParams(window.location.search).get('import_id');
    if (importId && document.getElementById('invoiceReviewModal')) {
        openInvoiceReview(importId);
    }

    bindPoTopSearch();
});

function bindPoTopSearch() {
    var search = document.getElementById('poProductSearch');
    var suggest = document.getElementById('poProductSuggest');
    if (!search || !suggest || search.dataset.bound === '1') return;
    search.dataset.bound = '1';

    function closeSuggest() { suggest.hidden = true; }
    function renderSuggest(q) {
        var matches = poFilterProducts(q);
        if (!String(q || '').trim()) {
            closeSuggest();
            return;
        }
        if (!matches.length) {
            suggest.innerHTML = '<li class="combobox-option" style="opacity:.7;cursor:default;">Sin coincidencias — pruebe SKU/EAN exacto</li>';
            suggest.hidden = false;
            return;
        }
        suggest.innerHTML = matches.map(function(p) {
            var label = (p.code ? p.code + ' — ' : '') + p.name;
            return '<li class="combobox-option" data-id="' + p.id + '">' +
                esc(p.name) +
                '<small>' + esc((p.code || '') + (p.barcode ? ' · EAN ' + p.barcode : '') + ' · costo ' + (p.purchase_price || 0)) + '</small></li>';
        }).join('');
        suggest.hidden = false;
    }

    function commitQuery(raw) {
        var code = String(raw || '').trim();
        if (!code) return;
        var product = poFindExactProduct(code);
        if (!product) {
            var matches = poFilterProducts(code);
            if (matches.length === 1) product = matches[0];
        }
        search.value = '';
        closeSuggest();
        if (product) {
            addOrBumpPoProduct(product);
            return;
        }
        if (window.SoftNovaBarcode) {
            SoftNovaBarcode.lookup(code).then(function(d) {
                if (!d || !d.success || !d.product) {
                    return showAlert('Producto no encontrado: ' + code, 'warning');
                }
                addOrBumpPoProduct(d.product);
            });
        } else {
            showAlert('Producto no encontrado: ' + code, 'warning');
        }
    }

    search.addEventListener('input', function() { renderSuggest(search.value); });
    search.addEventListener('focus', function() { if (search.value.trim()) renderSuggest(search.value); });
    suggest.addEventListener('mousedown', function(e) {
        var li = e.target.closest('.combobox-option[data-id]');
        if (!li) return;
        e.preventDefault();
        var id = li.getAttribute('data-id');
        var product = (window.poProducts || []).find(function(p) { return String(p.id) === String(id); });
        search.value = '';
        closeSuggest();
        if (product) addOrBumpPoProduct(product);
    });
    search.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeSuggest(); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var first = suggest.querySelector('.combobox-option[data-id]');
        if (first && !suggest.hidden && !poFindExactProduct(search.value)) {
            var id = first.getAttribute('data-id');
            var product = (window.poProducts || []).find(function(p) { return String(p.id) === String(id); });
            search.value = '';
            closeSuggest();
            if (product) addOrBumpPoProduct(product);
            return;
        }
        commitQuery(search.value);
    });
    document.addEventListener('click', function(e) {
        if (!search.contains(e.target) && !suggest.contains(e.target)) closeSuggest();
    });
}

function addOrBumpPoProduct(product) {
    if (!product || !product.id) return;
    // Asegurar que esté en el catálogo local (lookup remoto).
    var exists = (window.poProducts || []).some(function(p) { return String(p.id) === String(product.id); });
    if (!exists) {
        window.poProducts = window.poProducts || [];
        window.poProducts.push({
            id: product.id,
            code: product.code || '',
            barcode: product.barcode || '',
            name: product.name || ('#' + product.id),
            purchase_price: parseFloat(product.purchase_price) || 0,
            tax_rate: product.tax_rate != null ? parseFloat(product.tax_rate) : (window.poDefaultTaxRate || 0)
        });
    }
    var rows = document.querySelectorAll('#poItems .po-item-row');
    var found = null;
    Array.prototype.some.call(rows, function(row) {
        var sel = row.querySelector('input[name="product_id[]"], select[name="product_id[]"]');
        if (sel && String(sel.value) === String(product.id)) {
            found = row;
            return true;
        }
        return false;
    });
    if (found) {
        var qtyInput = found.querySelector('input[name="quantity[]"]');
        if (qtyInput) qtyInput.value = String((parseInt(qtyInput.value, 10) || 0) + 1);
        showAlert(product.name + ': cantidad +1', 'success');
        return;
    }
    addPoItem({
        product_id: product.id,
        quantity: 1,
        unit_cost: product.purchase_price || 0,
        tax_rate: product.tax_rate != null ? product.tax_rate : (window.poDefaultTaxRate || 0)
    });
    showAlert(product.name + ' agregado a la OC', 'success');
}
</script>
