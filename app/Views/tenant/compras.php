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
$statusFilter = $statusFilter ?? '';
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0, 'decimal' => ',', 'thousands' => '.'];
$pagination = $pagination ?? null;
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'compras');
$needBarcode = true;
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'compras');
$canDelete = \SoftNova\Core\TenantMiddleware::canDo('delete', 'compras');

function fmtC(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}

$statusLabels = [
    'draft' => 'Borrador',
    'ordered' => 'Ordenada',
    'received' => 'Recibida',
    'cancelled' => 'Cancelada',
];
?>
<meta name="currency-symbol" content="<?php echo htmlspecialchars($currency['symbol']); ?>">
<meta name="currency-decimals" content="<?php echo (int)$currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>OC abiertas</h4><div class="stat-value"><?php echo fmtC((float)$openTotal, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Recibido este mes</h4><div class="stat-value" style="color:#10B981;"><?php echo fmtC((float)$monthReceived, $currency); ?></div></div>
    <div class="stat-card neumorphic"><h4>Registros</h4><div class="stat-value"><?php echo (int)($pagination['total'] ?? count($orders)); ?></div></div>
</div>

<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="openPurchaseModal()">Nueva orden de compra</button>
    <?php endif; ?>
    <form method="GET" action="<?php echo $viewInstance->route('app/compras'); ?>" style="display:flex;gap:8px;align-items:center;margin:0;">
        <select name="status" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <?php foreach ($statusLabels as $k => $lab): ?>
                <option value="<?php echo $k; ?>" <?php echo $statusFilter === $k ? 'selected' : ''; ?>><?php echo $lab; ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<p style="font-size:13px;color:var(--color-text-secondary);margin:0 0 14px;">
    Flujo: <strong>OC → Recepción/Factura</strong> (ingresa a bodega) → asiento automático
    <em>Inventarios + IVA descontable / Proveedores o Caja</em>.
    El costo del producto se actualiza con <strong>promedio ponderado</strong>.
</p>

<div class="card neumorphic">
    <div class="card-header"><h3>Órdenes de compra</h3></div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Aún no hay órdenes de compra</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead>
                    <tr>
                        <th>OC</th><th>Fecha</th><th>Proveedor</th><th>Factura</th><th>Foto</th>
                        <th>Total</th><th>Estado</th><th>Asiento</th><th>Acciones</th>
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
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($o['order_number']); ?></strong></td>
                        <td><?php echo date('d/m/Y', strtotime($o['order_date'])); ?></td>
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
                        <td><span class="badge <?php echo $badge; ?>"><?php echo $statusLabels[$st] ?? $st; ?></span></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($o['accounting_entry_id'] ? ('#' . $o['accounting_entry_id']) : '—'); ?></td>
                        <td class="table-actions" style="white-space:nowrap;">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="viewPurchase(<?php echo (int)$o['id']; ?>)" title="Ver">Ver</button>
                            <?php if ($canEdit && in_array($st, ['draft', 'ordered'], true)): ?>
                                <button type="button" class="btn btn-sm btn-success" onclick="openReceiveModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars($o['order_number'], ENT_QUOTES); ?>')" title="Recibir">Recibir</button>
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
            $paginationQuery = array_filter(['status' => $statusFilter !== '' ? $statusFilter : null]);
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

<script>
window.poProducts = <?php echo json_encode(array_map(static function ($p) {
    return [
        'id' => (int)$p['id'],
        'code' => $p['code'],
        'name' => $p['name'],
        'purchase_price' => (float)$p['purchase_price'],
    ];
}, $products), JSON_UNESCAPED_UNICODE); ?>;
window.poDetailUrl = '<?php echo $viewInstance->route('app/compras'); ?>?action=detail';
window.poWarehouseKey = 'seri_last_warehouse_date';

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
            h += '<div><strong>Asiento:</strong> ' + esc(o.accounting_entry_number || (o.accounting_entry_id ? ('#'+o.accounting_entry_id) : 'pendiente')) + '</div>';
            h += '</div>';
            h += '<table><thead><tr><th>Producto</th><th>Cant</th><th>Costo</th><th>IVA%</th><th>Total</th></tr></thead><tbody>';
            items.forEach(function(it) {
                h += '<tr><td>' + esc(it.product_name) + '</td><td>' + it.quantity + '</td>';
                h += '<td>' + sym + parseFloat(it.unit_cost||0).toFixed(dec) + '</td>';
                h += '<td>' + parseFloat(it.tax_rate||0).toFixed(2) + '</td>';
                h += '<td>' + sym + parseFloat(it.line_total||0).toFixed(dec) + '</td></tr>';
            });
            h += '</tbody></table>';
            if (o.notes) h += '<p style="margin-top:10px;font-size:13px;"><strong>Notas:</strong> ' + esc(o.notes) + '</p>';
            document.getElementById('purchaseDetailContent').innerHTML = h;
            document.getElementById('purchaseDetailModal').style.display = 'flex';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    var scan = document.getElementById('poBarcodeScan');
    if (!scan) return;
    scan.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var code = (scan.value || '').trim();
        if (!code) return;
        var product = null;
        (window.poProducts || []).some(function(p) {
            if (String(p.code || '').toLowerCase() === code.toLowerCase()) {
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
