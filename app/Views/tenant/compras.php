<?php
$layout = 'tenant';
$title = 'Compras - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Compras / Órdenes de compra';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$orders = $orders ?? [];
$products = $products ?? [];
$suppliers = $suppliers ?? [];
$openTotal = $openTotal ?? 0;
$monthReceived = $monthReceived ?? 0;
$payableTotal = $payableTotal ?? 0;
$payableOrders = $payableOrders ?? [];
$pendingInvoices = $pendingInvoices ?? [];
$aiInvoiceReady = !empty($aiInvoiceReady);
$statusFilter = $statusFilter ?? '';
$dueFilter = $dueFilter ?? '';
$creditNotesPanel = !empty($creditNotesPanel);
$creditNotes = $creditNotes ?? [];
$creditNotesQ = $creditNotesQ ?? '';
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0, 'decimal' => ',', 'thousands' => '.'];
$pagination = $pagination ?? null;
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'compras');
$needBarcode = !$creditNotesPanel;
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'compras');
$canDelete = \SoftNova\Core\TenantMiddleware::canDo('delete', 'compras');

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
}
?>
<meta name="currency-symbol" content="<?php echo htmlspecialchars($currency['symbol']); ?>">
<meta name="currency-decimals" content="<?php echo (int)$currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<?php if ($creditNotesPanel): ?>
<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <a href="<?php echo $viewInstance->route('app/compras'); ?>" class="btn btn-secondary">← Volver a compras</a>
    <?php if ($canEdit): ?>
        <span style="font-size:13px;color:var(--color-text-secondary);">
            Para emitir una NC abra el detalle de una OC recibida o use el botón <strong>NC</strong>.
        </span>
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
                Aún no hay notas de crédito. Emítalas desde una orden de compra recibida (botón NC).
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
            <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
                <?php
                $paginationBaseUrl = $viewInstance->route('app/compras');
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

<div id="purchaseDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h3>Detalle de compra</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('purchaseDetailModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" id="purchaseDetailContent"></div>
    </div>
</div>
<script>
var comprasBase = <?php echo json_encode($viewInstance->route('app/compras')); ?>;
function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
}
function viewPurchase(id) {
    var sym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
    var dec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0', 10);
    fetch(comprasBase + '?action=detail&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.order) return showAlert(data.message || 'No se pudo cargar', 'error');
            var o = data.order;
            var h = '<p><strong>OC:</strong> ' + esc(o.order_number) + ' · <strong>Proveedor:</strong> ' + esc(o.supplier_name || '—') + '</p>';
            var notes = o.credit_notes || [];
            if (notes.length) {
                h += '<h4 style="margin-top:12px;">Notas de crédito</h4>';
                h += '<table><thead><tr><th>Número</th><th>Fecha</th><th>Motivo</th><th>Stock</th><th>Asiento</th><th>Total</th><th></th></tr></thead><tbody>';
                notes.forEach(function(n) {
                    h += '<tr><td>' + esc(n.credit_number || '') + '</td>';
                    h += '<td>' + esc((n.credit_date || '').substr(0,10)) + '</td>';
                    h += '<td>' + esc(n.reason || n.external_number || '—') + '</td>';
                    h += '<td>' + (parseInt(n.affects_stock, 10) === 1 ? 'Sí' : 'No') + '</td>';
                    h += '<td>' + esc(n.accounting_entry_number || '—') + '</td>';
                    h += '<td>' + sym + parseFloat(n.total||0).toFixed(dec) + '</td>';
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

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>OC abiertas</h4><div class="stat-value"><?php echo fmtC((float)$openTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Recibido este mes</h4><div class="stat-value" style="color:#10B981;"><?php echo fmtC((float)$monthReceived, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Por pagar a proveedores</h4><div class="stat-value" style="color:<?php echo $payableTotal > 0 ? '#DC2626' : '#10B981'; ?>;"><?php echo fmtC((float)$payableTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Registros</h4><div class="stat-value"><?php echo (int)($pagination['total'] ?? count($orders)); ?></div></div>
</div>

<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="openPurchaseModal()">Nueva orden de compra</button>
        <button type="button" class="btn btn-secondary" onclick="openInvoiceUpload()">Cargar factura de proveedor</button>
    <?php endif; ?>
    <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=notas_credito" class="btn btn-secondary">Notas de crédito</a>
    <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=reposicion" class="btn btn-secondary">Panel de reposición</a>
    <form method="GET" action="<?php echo $viewInstance->route('app/compras'); ?>" style="display:flex;gap:8px;align-items:center;margin:0;flex-wrap:wrap;">
        <select name="status" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <?php foreach ($statusLabels as $k => $lab): ?>
                <option value="<?php echo $k; ?>" <?php echo $statusFilter === $k ? 'selected' : ''; ?>><?php echo $lab; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="due" class="form-control" style="width:auto;" onchange="this.form.submit()" title="Filtro por vencimiento de pago">
            <option value="">Vencimiento: todos</option>
            <option value="overdue" <?php echo $dueFilter === 'overdue' ? 'selected' : ''; ?>>Vencidas</option>
            <option value="due_today" <?php echo $dueFilter === 'due_today' ? 'selected' : ''; ?>>Vencen hoy</option>
            <option value="due_soon" <?php echo $dueFilter === 'due_soon' ? 'selected' : ''; ?>>Por vencer (7 días)</option>
            <option value="no_due" <?php echo $dueFilter === 'no_due' ? 'selected' : ''; ?>>Sin fecha de vencimiento</option>
        </select>
    </form>
</div>

<p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
    Flujo: <strong>OC → Recepción/Factura</strong> (ingresa a bodega) → asiento automático
    <em>Inventarios + IVA descontable / Proveedores o Caja</em>.
    El costo del producto se actualiza con <strong>promedio ponderado</strong>.
</p>

<?php if (!empty($pendingInvoices)): ?>
<div class="card neumorphic" style="margin-bottom:18px;border-left:4px solid #F59E0B;">
    <div class="card-header"><h3>Facturas leídas pendientes de confirmar</h3></div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--color-text-secondary);margin-top:0;">
            Estas facturas ya fueron interpretadas pero <strong>aún no afectan el inventario</strong>.
            Revise las líneas y confirme para ingresarlas.
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
                        <button type="button" class="btn btn-sm btn-primary" onclick="openInvoiceReview(<?php echo (int)$inv['id']; ?>)">Revisar</button>
                        <?php if ($canCreate): ?>
                            <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_discard" style="display:inline;">
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
<?php endif; ?>

<div class="card neumorphic">
    <div class="card-header"><h3>Órdenes de compra</h3></div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Aún no hay órdenes de compra</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead>
                    <tr>
                        <th>OC</th><th>Fecha</th><th>Vence</th><th>Proveedor</th><th>Factura</th><th>Foto</th>
                        <th>Total</th><th>Saldo</th><th>Estado</th><th>Asiento</th><th>Acciones</th>
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
                    $orderBalance = round(
                        (float)$o['total']
                        - (float)($o['paid_total'] ?? 0)
                        - (float)($o['credit_total'] ?? 0),
                        2
                    );
                    $isPayable = $st === 'received'
                        && ($o['payment_mode'] ?? 'payable') === 'payable'
                        && $orderBalance > 0.009;
                    $dueDate = $o['due_date'] ?? null;
                    $daysOver = isset($o['days_overdue']) ? (int)$o['days_overdue'] : null;
                    $dueOverdue = $dueDate && $daysOver !== null && $daysOver > 0 && $isPayable;
                    $dueSoon = $dueDate && $daysOver !== null && $daysOver <= 0 && $daysOver >= -7 && $isPayable;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($o['order_number']); ?></strong></td>
                        <td><?php echo date('d/m/Y', strtotime($o['order_date'])); ?></td>
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
                        <td><?php echo htmlspecialchars($o['invoice_number'] ?? '—'); ?></td>
                        <td>
                            <?php if (!empty($o['invoice_path'])): ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice&id=<?php echo (int)$o['id']; ?>" target="_blank" title="Ver foto/PDF factura">Ver</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600;"><?php echo fmtC((float)$o['total'], $currency); ?></td>
                        <td style="font-weight:600;color:<?php echo $isPayable ? '#DC2626' : '#10B981'; ?>;">
                            <?php echo $st === 'received' ? fmtC(max(0, $orderBalance), $currency) : '—'; ?>
                        </td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo $statusLabels[$st] ?? $st; ?></span></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($o['accounting_entry_id'] ? ('#' . $o['accounting_entry_id']) : '—'); ?></td>
                        <td class="table-actions" style="white-space:nowrap;">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="viewPurchase(<?php echo (int)$o['id']; ?>)" title="Ver">Ver</button>
                            <?php if ($canEdit && in_array($st, ['draft', 'ordered'], true)): ?>
                                <button type="button" class="btn btn-sm btn-success" onclick="openReceiveModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>')" title="Recibir">Recibir</button>
                            <?php endif; ?>
                            <?php if ($canEdit && $st === 'received'): ?>
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick="openCreditNoteModal(<?php echo (int)$o['id']; ?>)"
                                        title="Nota de crédito">NC</button>
                            <?php endif; ?>
                            <?php if ($canEdit && $isPayable): ?>
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="openPayModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>', <?php echo $orderBalance; ?>, '<?php echo htmlspecialchars($o['supplier_name'] ?? '', ENT_QUOTES); ?>')"
                                        title="Registrar abono al proveedor">Abonar</button>
                            <?php endif; ?>
                            <?php if ($canDelete && in_array($st, ['draft', 'ordered'], true)): ?>
                                <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=cancel" style="display:inline;" data-ajax="true">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Cancelar esta OC?')" title="Cancelar">✕</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $paginationBaseUrl = $viewInstance->route('app/compras');
            $paginationQuery = array_filter([
                'status' => $statusFilter !== '' ? $statusFilter : null,
                'due' => $dueFilter !== '' ? $dueFilter : null,
            ]);
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Nueva OC -->
<div id="purchaseModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:820px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Nueva orden de compra</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('purchaseModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=create" data-ajax="true" id="purchaseForm" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;">
                        <label>Proveedor</label>
                        <select name="supplier_id" class="form-control">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Fecha OC *</label>
                        <input type="date" name="order_date" id="poOrderDate" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Fecha bodega (si recibe ya)</label>
                        <input type="date" name="warehouse_date" id="poWarehouseDate" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notas</label>
                    <input type="text" name="notes" class="form-control" placeholder="Observaciones de la compra">
                </div>
                <div class="form-group">
                    <label>Foto / PDF factura proveedor</label>
                    <input type="file" name="invoice_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                    <small style="color:var(--color-text-secondary);">Opcional. También puedes adjuntarla al recibir.</small>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;">
                        <label>Fecha factura</label>
                        <input type="date" name="invoice_date" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Fecha vencimiento</label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Desc. pronto pago %</label>
                        <input type="number" step="0.01" min="0" max="100" name="early_payment_discount_pct" class="form-control" value="0" placeholder="Ej. 2">
                    </div>
                </div>

                <h4 style="margin:12px 0 8px;">Ítems</h4>
                <div class="form-group" style="margin-bottom:8px;">
                    <input type="text" id="poBarcodeScan" class="form-control" placeholder="Escanear…" autocomplete="off" data-barcode-input="true" title="Pistola de códigos de barras">
                </div>
                <div id="poItems"></div>
                <button type="button" class="btn btn-secondary" onclick="addPoItem()" style="margin-top:8px;">+ Agregar producto</button>

                <div style="margin-top:16px;padding:12px;border:1px solid var(--color-border);border-radius:8px;background:var(--bg-input);">
                    <label style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">
                        <input type="checkbox" name="receive_now" value="1" id="poReceiveNow" onchange="toggleReceiveNow()">
                        Recibir y contabilizar ahora (factura proveedor)
                    </label>
                    <div id="poReceiveNowFields" style="display:none;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group" style="margin:0;">
                            <label>N° factura proveedor</label>
                            <input type="text" name="invoice_number" class="form-control" placeholder="FV-001">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Pago / contrapartida</label>
                            <select name="payment_mode" class="form-control">
                                <option value="payable">Por pagar (Proveedores + IVA descontable)</option>
                                <option value="cash">Efectivo</option>
                                <option value="transfer">Transferencia/tarjeta</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('purchaseModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar OC</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Recibir -->
<div id="receiveModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header">
            <h3>Recibir mercancía</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('receiveModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=receive" data-ajax="true" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="receiveOrderId">
            <p id="receiveOrderLabel" style="font-weight:600;margin-bottom:10px;"></p>
            <div class="form-group">
                <label>Fecha ingreso a bodega *</label>
                <input type="date" name="warehouse_date" id="receiveWarehouseDate" class="form-control" required>
            </div>
            <div class="form-group">
                <label>N° factura proveedor</label>
                <input type="text" name="invoice_number" class="form-control" placeholder="FV-001">
            </div>
            <div class="form-group">
                <label>Foto / PDF factura</label>
                <input type="file" name="invoice_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
            </div>
            <div class="form-group">
                <label>Pago / contrapartida *</label>
                <select name="payment_mode" class="form-control" required>
                    <option value="payable">Por pagar (220505) + IVA descontable (240802)</option>
                    <option value="cash">Efectivo (110505)</option>
                    <option value="transfer">Transferencia (111005)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Notas</label>
                <input type="text" name="notes" class="form-control">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('receiveModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-success">Recibir y contabilizar</button>
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
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=pay" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="payOrderId">
            <p id="payOrderLabel" style="font-weight:600;margin-bottom:4px;"></p>
            <p id="payBalanceLabel" style="font-size:13px;color:var(--color-text-secondary);margin-bottom:12px;"></p>
            <div class="form-group">
                <label>Valor del abono *</label>
                <input type="number" step="0.01" min="0.01" name="amount" id="payAmount" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Fecha del pago *</label>
                <input type="date" name="payment_date" id="payDate" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Medio de pago *</label>
                <select name="payment_method" class="form-control" required>
                    <option value="cash">Efectivo (110505)</option>
                    <option value="transfer">Transferencia (111005)</option>
                    <option value="card">Tarjeta (111005)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Referencia / comprobante</label>
                <input type="text" name="reference" class="form-control" placeholder="N° transacción o recibo">
            </div>
            <div class="form-group">
                <label>Notas</label>
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

<?php if (!empty($payableOrders)): ?>
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
    <div class="modal-content neumorphic" style="max-width:620px;max-height:88vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Cargar factura de proveedor</h3>
            <button type="button" class="modal-close" onclick="closeInvoiceUpload()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="invoiceUploadForm"
              action="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_import">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <p style="margin-top:0;font-size:13px;color:var(--color-text-secondary);">
                La factura se interpreta y se muestra en un modal de confirmación.
                <strong>Nada entra al inventario hasta que usted confirme.</strong>
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Proveedor *</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo (int)$sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Número de factura</label>
                    <input type="text" name="invoice_number" class="form-control" maxlength="80" placeholder="Se detecta si viene en el archivo">
                </div>
                <div class="form-group">
                    <label>Fecha de factura</label>
                    <input type="date" name="invoice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Vencimiento de pago</label>
                    <input type="date" name="due_date" class="form-control">
                </div>
                <div class="form-group">
                    <label>Forma de pago</label>
                    <select name="payment_mode" class="form-control">
                        <option value="payable">Crédito (cuenta por pagar)</option>
                        <option value="cash">Efectivo</option>
                        <option value="transfer">Transferencia</option>
                        <option value="card">Tarjeta</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Archivo de la factura</label>
                <input type="file" name="invoice_file" class="form-control" style="padding:8px;"
                       accept=".csv,.txt,.pdf,.xml,image/*">
                <small style="color:var(--color-text-secondary);">
                    CSV o Excel exportado a CSV es lo más confiable. También se leen PDF con texto y XML.
                    Columnas sugeridas: código, descripción, cantidad, costo, IVA, descuento, lote, vencimiento.
                </small>
            </div>
            <div class="form-group">
                <label>O pegue el texto de la factura</label>
                <textarea name="pasted_text" rows="5" class="form-control"
                          placeholder="Pegue aquí las líneas de la factura si no tiene archivo"></textarea>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;<?php echo $aiInvoiceReady ? '' : 'opacity:.55;'; ?>">
                    <input type="checkbox" name="use_ai" value="1" style="width:auto;" <?php echo $aiInvoiceReady ? '' : 'disabled'; ?>>
                    Interpretar con IA
                    <?php if (!$aiInvoiceReady): ?>
                        <small style="color:var(--color-text-secondary);">(configure OPENROUTER_API_KEY para habilitarlo)</small>
                    <?php endif; ?>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeInvoiceUpload()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Leer factura</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal confirmación previa al inventario -->
<div id="invoiceReviewModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:1100px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Confirmar ingreso al inventario</h3>
            <button type="button" class="modal-close" onclick="closeInvoiceReview()">&times;</button>
        </div>
        <form method="POST" id="invoiceReviewForm"
              action="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_confirm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="import_id" id="irImportId">
            <div id="invoiceReviewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeInvoiceReview()">Cerrar sin aplicar</button>
                <button type="submit" class="btn btn-success"
                        onclick="return confirm('¿Ingresar estas líneas al inventario? Se creará la orden de compra y su asiento contable.')">
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
            <h3>Nota de crédito (compra)</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('creditNoteModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/compras'); ?>?action=credit_note" data-ajax="true" id="creditNoteForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="cnOrderId" value="">
            <p id="cnOrderLabel" style="margin-top:0;font-size:13px;color:var(--color-text-secondary);"></p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Fecha</label>
                    <input type="date" name="credit_date" id="cnDate" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>N° documento proveedor</label>
                    <input type="text" name="external_number" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div class="form-group">
                <label>Motivo</label>
                <input type="text" name="reason" class="form-control" placeholder="Devolución, mercancía dañada, descuento, etc.">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="affects_stock" id="cnAffectsStock" value="1" checked style="width:auto;">
                    Devolver mercancía al proveedor (salida de inventario)
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
    ];
}, $products), JSON_UNESCAPED_UNICODE); ?>;
window.poDetailUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=detail';
window.poWarehouseKey = 'seri_last_warehouse_date';
window.poInvoicePreviewUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=invoice_preview';
window.poSuppliers = <?php echo json_encode(array_map(static fn($s) => [
    'id' => (int)$s['id'],
    'name' => $s['name'],
], $suppliers), JSON_UNESCAPED_UNICODE); ?>;
window.poCurrency = { symbol: '<?php echo htmlspecialchars($currency['symbol']); ?>', decimals: <?php echo (int)$currency['decimals']; ?> };

function openInvoiceUpload() {
    document.getElementById('invoiceUploadForm').reset();
    document.getElementById('invoiceUploadModal').style.display = 'flex';
}
function closeInvoiceUpload() {
    document.getElementById('invoiceUploadModal').style.display = 'none';
}
function closeInvoiceReview() {
    document.getElementById('invoiceReviewModal').style.display = 'none';
}

function poMoney(v) {
    return window.poCurrency.symbol + ' ' + (parseFloat(v) || 0).toFixed(window.poCurrency.decimals);
}

function supplierOptionsHtml(selected) {
    var h = '<option value="">Seleccione…</option>';
    (window.poSuppliers || []).forEach(function(s) {
        h += '<option value="' + s.id + '"' + (String(selected) === String(s.id) ? ' selected' : '') + '>' + esc(s.name) + '</option>';
    });
    return h;
}

function openInvoiceReview(importId) {
    fetch(window.poInvoicePreviewUrl + '&id=' + importId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) return showAlert(d.message || 'No se pudo cargar la factura', 'error');
        renderInvoiceReview(d.import, d.items || []);
    })
    .catch(function() { showAlert('No se pudo cargar la factura', 'error'); });
}

function renderInvoiceReview(imp, items) {
    document.getElementById('irImportId').value = imp.id;

    var h = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px;">';
    h += '<div class="form-group" style="margin:0;"><label>Proveedor *</label>' +
         '<select name="supplier_id" class="form-control" required>' + supplierOptionsHtml(imp.supplier_id) + '</select></div>';
    h += '<div class="form-group" style="margin:0;"><label>N.º factura</label>' +
         '<input type="text" name="invoice_number" class="form-control" maxlength="80" value="' + esc(imp.invoice_number || '') + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Fecha factura</label>' +
         '<input type="date" name="invoice_date" class="form-control" value="' + (imp.invoice_date || '') + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Vencimiento pago</label>' +
         '<input type="date" name="due_date" class="form-control" value="' + (imp.due_date || '') + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Fecha a bodega</label>' +
         '<input type="date" name="warehouse_date" class="form-control" value="' + (imp.invoice_date || poToday()) + '"></div>';
    h += '<div class="form-group" style="margin:0;"><label>Forma de pago</label><select name="payment_mode" class="form-control">';
    [['payable','Crédito (CxP)'],['cash','Efectivo'],['transfer','Transferencia'],['card','Tarjeta']].forEach(function(o) {
        h += '<option value="' + o[0] + '"' + (imp.payment_mode === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
    });
    h += '</select></div></div>';

    if (imp.parse_notes) {
        h += '<p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">Lectura: ' + esc(imp.parse_notes) + '</p>';
    }

    var nuevos = items.filter(function(i) { return !i.matched_product_id && i.row_action !== 'skip'; }).length;
    if (nuevos > 0) {
        h += '<p style="font-size:13px;color:#F59E0B;font-weight:600;margin:0 0 10px;">' +
             nuevos + ' línea(s) sin producto en el catálogo: se crearán como productos nuevos si no las asocia.</p>';
    }
    var sinMonto = items.filter(function(i) {
        return i.row_action !== 'skip' && (!(parseFloat(i.quantity) > 0) || (!(parseFloat(i.unit_cost) > 0) && !(parseFloat(i.line_total) > 0)));
    }).length;
    if (sinMonto > 0) {
        h += '<p style="font-size:13px;color:#DC2626;font-weight:600;margin:0 0 10px;">' +
             sinMonto + ' línea(s) con cantidad o costo en 0. Complete Cant. y Costo u. antes de confirmar (marcadas en rojo).</p>';
    }

    h += '<div class="table-container" style="max-height:44vh;overflow:auto;"><table><thead><tr>' +
         '<th>Acción</th><th>Descripción</th><th>SKU prov.</th><th>Producto en catálogo</th>' +
         '<th>Cant.</th><th>Costo u.</th><th>IVA %</th><th>Dcto %</th><th>Lote</th><th>Vence</th><th>P. venta</th><th>Total</th>' +
         '</tr></thead><tbody>';

    items.forEach(function(it) {
        var base = 'items[' + it.id + ']';
        var qty = parseFloat(it.quantity) || 0;
        var cost = parseFloat(it.unit_cost) || 0;
        var total = parseFloat(it.line_total) || 0;
        var bad = it.row_action !== 'skip' && (qty <= 0 || (cost <= 0 && total <= 0));
        h += '<tr' + (bad ? ' style="background:rgba(220,38,38,0.08);"' : '') + '>';
        h += '<td><select name="' + base + '[row_action]" class="form-control" style="min-width:118px;">' +
             '<option value="add_stock"' + (it.row_action === 'add_stock' ? ' selected' : '') + '>Ingresar</option>' +
             '<option value="create_product"' + (it.row_action === 'create_product' ? ' selected' : '') + '>Crear producto</option>' +
             '<option value="skip"' + (it.row_action === 'skip' ? ' selected' : '') + '>Omitir</option>' +
             '</select></td>';
        h += '<td><input type="text" name="' + base + '[description]" class="form-control" style="min-width:190px;" value="' + esc(it.description || '') + '"></td>';
        h += '<td><input type="text" name="' + base + '[supplier_sku]" class="form-control" style="min-width:100px;" value="' + esc(it.supplier_sku || '') + '"></td>';
        h += '<td><select name="' + base + '[matched_product_id]" class="form-control" style="min-width:210px;">' + productOptionsHtml(it.matched_product_id || '') + '</select>' +
             (it.match_type && it.match_type !== 'none'
                ? '<div style="font-size:11px;color:var(--color-text-secondary);">Coincidencia: ' + esc(it.match_type) + ' (' + (parseInt(it.confidence,10) || 0) + '%)</div>'
                : '') +
             '</td>';
        h += '<td><input type="number" step="0.001" min="0" name="' + base + '[quantity]" class="form-control" style="min-width:80px;" value="' + qty + '"' + (bad && qty <= 0 ? ' required' : '') + '></td>';
        h += '<td><input type="number" step="0.01" min="0" name="' + base + '[unit_cost]" class="form-control" style="min-width:95px;" value="' + cost + '"' + (bad && cost <= 0 && total <= 0 ? ' required' : '') + '></td>';
        h += '<td><input type="number" step="0.01" min="0" name="' + base + '[tax_rate]" class="form-control" style="min-width:70px;" value="' + (parseFloat(it.tax_rate) || 0) + '"></td>';
        h += '<td><input type="number" step="0.01" min="0" name="' + base + '[discount_pct]" class="form-control" style="min-width:70px;" value="' + (parseFloat(it.discount_pct) || 0) + '"></td>';
        h += '<td><input type="text" maxlength="60" name="' + base + '[lot_code]" class="form-control" style="min-width:90px;" value="' + esc(it.lot_code || '') + '"></td>';
        h += '<td><input type="date" name="' + base + '[expiry_date]" class="form-control" style="min-width:130px;" value="' + (it.expiry_date || '') + '"></td>';
        h += '<td><input type="number" step="0.01" min="0" name="' + base + '[sale_price]" class="form-control" style="min-width:95px;" value="' + (it.sale_price != null ? parseFloat(it.sale_price) : '') + '"></td>';
        h += '<td style="white-space:nowrap;">' + poMoney(total) + '</td>';
        h += '</tr>';
    });

    h += '</tbody></table></div>';
    h += '<div style="display:flex;justify-content:flex-end;gap:20px;margin-top:12px;font-weight:600;">' +
         '<span>Subtotal: ' + poMoney(imp.subtotal) + '</span>' +
         '<span>IVA: ' + poMoney(imp.tax_total) + '</span>' +
         '<span>Total: ' + poMoney(imp.total) + '</span></div>';

    document.getElementById('invoiceReviewBody').innerHTML = h;
    document.getElementById('invoiceReviewModal').style.display = 'flex';
}

function poToday() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
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

function productOptionsHtml(selected) {
    var h = '<option value="">Seleccionar producto</option>';
    (window.poProducts || []).forEach(function(p) {
        h += '<option value="' + p.id + '" data-cost="' + p.purchase_price + '" data-code="' + esc(p.code || '') + '"' +
            (String(selected) === String(p.id) ? ' selected' : '') + '>' +
            esc((p.code ? p.code + ' — ' : '') + p.name) + '</option>';
    });
    return h;
}

function addPoItem(prefill) {
    prefill = prefill || {};
    var wrap = document.createElement('div');
    wrap.className = 'po-item-row';
    wrap.style.cssText = 'display:grid;grid-template-columns:2fr 0.7fr 0.9fr 0.7fr auto;gap:8px;margin-bottom:8px;align-items:end;';
    wrap.innerHTML =
        '<div class="form-group" style="margin:0;"><label>Producto</label>' +
        '<select name="product_id[]" class="form-control" onchange="onPoProductChange(this)" required>' + productOptionsHtml(prefill.product_id || '') + '</select></div>' +
        '<div class="form-group" style="margin:0;"><label>Cant.</label>' +
        '<input type="number" name="quantity[]" class="form-control" min="1" value="' + (prefill.quantity || 1) + '" required></div>' +
        '<div class="form-group" style="margin:0;"><label>Costo u.</label>' +
        '<input type="number" step="0.01" name="unit_cost[]" class="form-control" min="0" value="' + (prefill.unit_cost || 0) + '" required></div>' +
        '<div class="form-group" style="margin:0;"><label>IVA %</label>' +
        '<input type="number" step="0.01" name="tax_rate[]" class="form-control" min="0" value="' + (prefill.tax_rate != null ? prefill.tax_rate : 19) + '"></div>' +
        '<button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">✕</button>';
    document.getElementById('poItems').appendChild(wrap);
}

function onPoProductChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var cost = opt ? opt.getAttribute('data-cost') : '0';
    var row = sel.closest('.po-item-row');
    if (row) {
        var costInput = row.querySelector('input[name="unit_cost[]"]');
        if (costInput) costInput.value = cost || 0;
    }
}

function toggleReceiveNow() {
    var on = document.getElementById('poReceiveNow').checked;
    document.getElementById('poReceiveNowFields').style.display = on ? 'grid' : 'none';
}

function openPurchaseModal() {
    document.getElementById('poItems').innerHTML = '';
    addPoItem();
    document.getElementById('poOrderDate').value = poToday();
    var wh = document.getElementById('poWarehouseDate');
    wh.value = poRememberedDate();
    wh.onchange = function() { poRemember(wh.value); };
    document.getElementById('poReceiveNow').checked = false;
    toggleReceiveNow();
    document.getElementById('purchaseModal').style.display = 'flex';
}

function openReceiveModal(id, number) {
    document.getElementById('receiveOrderId').value = id;
    document.getElementById('receiveOrderLabel').textContent = 'OC ' + number;
    var wh = document.getElementById('receiveWarehouseDate');
    wh.value = poRememberedDate();
    wh.onchange = function() { poRemember(wh.value); };
    document.getElementById('receiveModal').style.display = 'flex';
}

function openPayModal(id, number, balance, supplier) {
    document.getElementById('payOrderId').value = id;
    document.getElementById('payOrderLabel').textContent = 'OC ' + number + (supplier ? ' — ' + supplier : '');
    var sym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
    var dec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');
    document.getElementById('payBalanceLabel').textContent =
        'Saldo pendiente: ' + sym + ' ' + parseFloat(balance || 0).toFixed(dec);
    var amount = document.getElementById('payAmount');
    amount.value = parseFloat(balance || 0).toFixed(2);
    amount.max = parseFloat(balance || 0).toFixed(2);
    document.getElementById('payDate').value = poToday();
    document.getElementById('payModal').style.display = 'flex';
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
            var sym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
            var dec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');
            var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">';
            h += '<div><strong>OC:</strong> ' + esc(o.order_number) + '</div>';
            h += '<div><strong>Estado:</strong> ' + esc(o.status) + '</div>';
            h += '<div><strong>Proveedor:</strong> ' + esc(o.supplier_name || '—') + '</div>';
            h += '<div><strong>Factura:</strong> ' + esc(o.invoice_number || '—') + '</div>';
            if (o.invoice_path) {
                h += '<div><strong>Adjunto:</strong> <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=invoice&id=' + o.id + '" target="_blank">Ver foto/PDF</a></div>';
            }
            h += '<div><strong>Fecha OC:</strong> ' + esc((o.order_date || '').substr(0,10)) + '</div>';
            h += '<div><strong>Fecha bodega:</strong> ' + esc((o.warehouse_date || '—').toString().substr(0,10)) + '</div>';
            h += '<div><strong>Subtotal:</strong> ' + sym + parseFloat(o.subtotal||0).toFixed(dec) + '</div>';
            h += '<div><strong>IVA:</strong> ' + sym + parseFloat(o.tax||0).toFixed(dec) + '</div>';
            h += '<div><strong>Total:</strong> ' + sym + parseFloat(o.total||0).toFixed(dec) + '</div>';
            h += '<div><strong>Notas crédito:</strong> ' + sym + parseFloat(o.credit_total||0).toFixed(dec) + '</div>';
            h += '<div><strong>Asiento:</strong> ' + esc(o.accounting_entry_number || (o.accounting_entry_id ? ('#'+o.accounting_entry_id) : 'pendiente')) + '</div>';
            h += '</div>';
            h += '<table><thead><tr><th>Producto</th><th>Cant</th><th>Recibido</th><th>NC</th><th>Costo</th><th>IVA%</th><th>Total</th></tr></thead><tbody>';
            items.forEach(function(it) {
                h += '<tr><td>' + esc(it.product_name) + '</td><td>' + it.quantity + '</td>';
                h += '<td>' + (it.received_qty != null ? it.received_qty : it.quantity) + '</td>';
                h += '<td>' + (it.credited_qty || 0) + '</td>';
                h += '<td>' + sym + parseFloat(it.unit_cost||0).toFixed(dec) + '</td>';
                h += '<td>' + parseFloat(it.tax_rate||0).toFixed(2) + '</td>';
                h += '<td>' + sym + parseFloat(it.line_total||0).toFixed(dec) + '</td></tr>';
            });
            h += '</tbody></table>';
            var payments = o.payments || [];
            if (payments.length) {
                h += '<h4 style="margin-top:16px;">Abonos al proveedor</h4>';
                h += '<table><thead><tr><th>Fecha</th><th>Medio</th><th>Referencia</th><th>Asiento</th><th>Valor</th></tr></thead><tbody>';
                payments.forEach(function(p) {
                    h += '<tr><td>' + esc((p.payment_date || '').substr(0,10)) + '</td>';
                    h += '<td>' + esc(p.payment_method || '') + '</td>';
                    h += '<td>' + esc(p.reference || '—') + '</td>';
                    h += '<td>' + esc(p.accounting_entry_number || '—') + '</td>';
                    h += '<td>' + sym + parseFloat(p.amount||0).toFixed(dec) + '</td></tr>';
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
                    h += '<td>' + sym + parseFloat(n.total||0).toFixed(dec) + '</td>';
                    h += '<td><a class="btn btn-sm btn-primary" href="' + <?php echo json_encode($viewInstance->route('app/compras')); ?> + '?action=credit_note_pdf&id=' + n.id + '" target="_blank" rel="noopener">PDF</a></td></tr>';
                });
                h += '</tbody></table>';
            }
            if (o.balance != null && parseFloat(o.balance) > 0.009) {
                h += '<p style="margin-top:10px;font-weight:600;color:#DC2626;">Saldo pendiente: '
                    + sym + parseFloat(o.balance).toFixed(dec) + '</p>';
            } else if (o.balance != null && parseFloat(o.balance) < -0.009) {
                h += '<p style="margin-top:10px;font-weight:600;color:#2563EB;">Saldo a favor: '
                    + sym + Math.abs(parseFloat(o.balance)).toFixed(dec) + '</p>';
            } else if (o.status === 'received') {
                h += '<p style="margin-top:10px;font-weight:600;color:#10B981;">Orden sin saldo pendiente</p>';
            }
            if (o.status === 'received') {
                h += '<div style="margin-top:12px;"><button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById(\'purchaseDetailModal\').style.display=\'none\';openCreditNoteModal(' + o.id + ')">Emitir nota de crédito</button></div>';
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

    var scan = document.getElementById('poBarcodeScan');
    if (!scan) return;
    scan.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var code = (scan.value || '').trim();
        if (!code) return;
        var product = null;
        (window.poProducts || []).some(function(p) {
            var codeLc = code.toLowerCase();
            if (String(p.code || '').toLowerCase() === codeLc || String(p.barcode || '').toLowerCase() === codeLc) {
                product = p;
                return true;
            }
            return false;
        });
        scan.value = '';
        if (!product) {
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
            return;
        }
        addOrBumpPoProduct(product);
    });
});

function addOrBumpPoProduct(product) {
    var rows = document.querySelectorAll('#poItems .po-item-row');
    var found = null;
    Array.prototype.some.call(rows, function(row) {
        var sel = row.querySelector('select[name="product_id[]"]');
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
        unit_cost: product.purchase_price || 0
    });
    showAlert(product.name + ' agregado a la OC', 'success');
}
</script>
