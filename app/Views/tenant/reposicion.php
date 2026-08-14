<?php
$layout = 'tenant';
$title = 'Reposición - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Panel de reposición';
$needs = $needs ?? [];
$summary = $summary ?? [];
$filters = $filters ?? ['state' => '', 'supplier_id' => 0, 'category_id' => 0, 'q' => '', 'days' => 30];
$suppliers = $suppliers ?? [];
$categories = $categories ?? [];
$draftOrders = $draftOrders ?? [];
$draftCart = $draftCart ?? [];
$draftCount = (int)($draftCount ?? count($draftCart));
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0, 'decimal' => ',', 'thousands' => '.'];
$canCreate = \SoftNova\Core\TenantMiddleware::canDo('create', 'compras');
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'compras');
$comprasBase = $viewInstance->route('app/compras');

function repFmt(float $a, array $c): string
{
    return ($c['symbol'] ?? '$') . ' ' . number_format($a, (int)($c['decimals'] ?? 0), $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<meta name="currency-symbol" content="<?php echo htmlspecialchars($currency['symbol']); ?>">
<meta name="currency-decimals" content="<?php echo (int)$currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
    <a href="<?php echo $comprasBase; ?>" class="btn btn-secondary" title="Volver al módulo de órdenes de compra">← Volver a compras</a>
    <a href="<?php echo $comprasBase; ?>?action=facturas" class="btn btn-secondary" title="Ir a facturas recibidas, abonos y CxP">Facturas</a>
    <span style="font-size:13px;color:var(--color-text-secondary);">
        Acumule productos en la bandeja y genere la OC cuando esté listo.
    </span>
</div>

<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card neumorphic">
        <h4>Agotados</h4>
        <div class="stat-value" style="color:#DC2626;"><?php echo (int)($summary['out_of_stock'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Por agotarse</h4>
        <div class="stat-value" style="color:#F59E0B;"><?php echo (int)($summary['low_stock'] ?? 0); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Sin proveedor asignado</h4>
        <div class="stat-value" style="color:<?php echo ($summary['without_supplier'] ?? 0) > 0 ? '#DC2626' : '#10B981'; ?>;">
            <?php echo (int)($summary['without_supplier'] ?? 0); ?>
        </div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Costo estimado de reposición</h4>
        <div class="stat-value"><?php echo repFmt((float)($summary['estimated_cost'] ?? 0), $currency); ?></div>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $comprasBase; ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="action" value="reposicion">
            <div class="form-group" style="margin:0;flex:1;min-width:180px;">
                <label style="font-size:12px;">Buscar</label>
                <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars((string)$filters['q']); ?>" placeholder="Nombre o código">
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Estado</label>
                <select name="state" class="form-control" style="width:auto;">
                    <option value="">Agotados y por agotarse</option>
                    <option value="out" <?php echo $filters['state'] === 'out' ? 'selected' : ''; ?>>Solo agotados</option>
                    <option value="low" <?php echo $filters['state'] === 'low' ? 'selected' : ''; ?>>Solo por agotarse</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Proveedor</label>
                <select name="supplier_id" class="form-control" style="width:auto;">
                    <option value="0">Todos</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?php echo (int)$sup['id']; ?>" <?php echo (int)$filters['supplier_id'] === (int)$sup['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sup['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Categoría</label>
                <select name="category_id" class="form-control" style="width:auto;">
                    <option value="0">Todas</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo (int)$filters['category_id'] === (int)$cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px;">Demanda de los últimos</label>
                <select name="days" class="form-control" style="width:auto;">
                    <?php foreach ([15 => '15 días', 30 => '30 días', 60 => '60 días', 90 => '90 días'] as $d => $label): ?>
                        <option value="<?php echo $d; ?>" <?php echo (int)$filters['days'] === $d ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:6px;">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="<?php echo $comprasBase; ?>?action=reposicion" class="btn btn-secondary">Limpiar</a>
            </div>
        </form>
    </div>
</div>

<?php if (($summary['without_supplier'] ?? 0) > 0 && $canEdit): ?>
    <div class="card neumorphic" style="margin-bottom:18px;border-left:4px solid #F59E0B;">
        <div class="card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
            <p style="margin:0;flex:1;min-width:240px;font-size:13px;">
                Hay <strong><?php echo (int)$summary['without_supplier']; ?></strong> producto(s) sin proveedor asignado.
                Puede generar la OC igual (grupo «Sin proveedor») o asignar proveedores desde el histórico.
            </p>
            <form method="POST" action="<?php echo $comprasBase; ?>?action=link_suppliers" style="margin:0;">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <button type="submit" class="btn btn-secondary"
                        onclick="return confirm('¿Derivar los proveedores a partir del histórico de entradas de inventario?')">
                    Asignar desde histórico de compras
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<form method="POST" action="<?php echo $comprasBase; ?>?action=draft_add" id="repForm">
    <?php echo \SoftNova\Core\csrf_field(); ?>
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h3>Productos a reponer (<?php echo count($needs); ?>)</h3>
            <?php if ($canCreate && !empty($needs)): ?>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="repToggleAll(true)">Seleccionar todo</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="repToggleAll(false)">Ninguno</button>
                    <button type="submit" class="btn btn-primary"
                            onclick="return repConfirmAdd()">
                        Añadir a bandeja
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($needs)): ?>
                <p style="text-align:center;padding:24px;color:var(--color-text-secondary);">
                    Ningún producto está agotado ni por agotarse con los filtros actuales.
                </p>
            <?php else: ?>
                <p style="font-size:13px;color:var(--color-text-secondary);margin-top:0;">
                    Acumule productos en la <strong>bandeja</strong> y genere la(s) OC cuando decida.
                    La cantidad sugerida lleva el stock al objetivo y cubre el lead time del proveedor.
                </p>
                <div class="table-container"><table>
                    <thead><tr>
                        <?php if ($canCreate): ?><th style="width:34px;"></th><?php endif; ?>
                        <th>Producto</th><th>EAN</th><th>Stock</th><th>Mínimo</th><th>Vendidos</th><th>Cobertura</th>
                        <th>Proveedor</th><th>Costo unit.</th><th>A pedir</th><th>Estimado</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($needs as $item): ?>
                        <?php
                        $agotado = (int)$item['stock'] <= 0;
                        $sinProveedor = empty($item['supplier_id']);
                        ?>
                        <tr>
                            <?php if ($canCreate): ?>
                                <td>
                                    <input type="checkbox" name="product_ids[]" class="rep-check"
                                           value="<?php echo (int)$item['id']; ?>"
                                           checked
                                           <?php echo $sinProveedor ? 'title="Se generará en OC sin proveedor"' : ''; ?>>
                                </td>
                            <?php endif; ?>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$item['name']); ?></strong>
                                <div style="font-size:12px;color:var(--color-text-secondary);">
                                    <?php echo htmlspecialchars((string)($item['code'] ?? '')); ?>
                                    <?php if (!empty($item['category_name'])): ?>
                                        · <?php echo htmlspecialchars((string)$item['category_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="font-size:12px;white-space:nowrap;">
                                <?php echo htmlspecialchars((string)($item['barcode'] ?? '') !== '' ? (string)$item['barcode'] : '—'); ?>
                            </td>
                            <td style="font-weight:600;color:<?php echo $agotado ? '#DC2626' : '#F59E0B'; ?>;">
                                <?php echo (int)$item['stock']; ?>
                            </td>
                            <td><?php echo (int)$item['min_stock']; ?></td>
                            <td><?php echo (int)$item['sold_qty']; ?></td>
                            <td>
                                <?php if ($item['days_of_cover'] === null): ?>
                                    <span style="color:var(--color-text-secondary);">Sin rotación</span>
                                <?php else: ?>
                                    <?php echo (int)$item['days_of_cover']; ?> días
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sinProveedor): ?>
                                    <span style="color:#B45309;font-weight:600;">Sin proveedor (OK)</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string)$item['supplier_name']); ?>
                                    <?php if (!empty($item['supplier_sku'])): ?>
                                        <div style="font-size:12px;color:var(--color-text-secondary);">
                                            SKU: <?php echo htmlspecialchars((string)$item['supplier_sku']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ((int)($item['lead_time_days'] ?? 0) > 0): ?>
                                        <div style="font-size:12px;color:var(--color-text-secondary);">
                                            Entrega: <?php echo (int)$item['lead_time_days']; ?> días
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo repFmt((float)$item['unit_cost'], $currency); ?></td>
                            <td>
                                <?php if ($canCreate): ?>
                                    <input type="number" min="0" style="width:88px;"
                                           class="form-control rep-qty"
                                           name="quantities[<?php echo (int)$item['id']; ?>]"
                                           data-cost="<?php echo (float)$item['unit_cost']; ?>"
                                           value="<?php echo (int)$item['suggested_qty']; ?>">
                                <?php else: ?>
                                    <?php echo (int)$item['suggested_qty']; ?>
                                <?php endif; ?>
                            </td>
                            <td class="rep-line-total"><?php echo repFmt((float)$item['estimated_total'], $currency); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px;font-weight:600;">
                    Total seleccionado: <span id="repTotal" style="margin-left:8px;">—</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card neumorphic" style="margin-top:18px;border-left:4px solid #2563EB;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h3>Bandeja de reposición (<?php echo $draftCount; ?>)</h3>
        <?php if ($canCreate && $draftCount > 0): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="POST" action="<?php echo $comprasBase; ?>?action=draft_clear" style="margin:0;"
                      onsubmit="return confirm('¿Vaciar toda la bandeja?')">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <button type="submit" class="btn btn-sm btn-secondary">Vaciar</button>
                </form>
                <form method="POST" action="<?php echo $comprasBase; ?>?action=draft_generate" style="margin:0;"
                      onsubmit="return confirm('¿Generar órdenes de compra con los <?php echo $draftCount; ?> producto(s) de la bandeja?')">
                    <?php echo \SoftNova\Core\csrf_field(); ?>
                    <button type="submit" class="btn btn-primary">Generar órdenes ahora</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($draftCart)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:16px;margin:0;">
                La bandeja está vacía. Seleccione productos arriba y pulse <strong>Añadir a bandeja</strong>.
                Puede acumular en varias pasadas antes de generar la OC.
            </p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th>Producto</th><th>EAN</th><th>Proveedor</th><th>Cantidad</th><th>Costo</th><th>Estimado</th><th></th>
                </tr></thead>
                <tbody>
                <?php
                $draftTotal = 0.0;
                foreach ($draftCart as $row):
                    $cost = (float)($row['unit_cost'] ?? $row['purchase_price'] ?? 0);
                    $qty = (int)$row['quantity'];
                    $line = round($cost * $qty, 2);
                    $draftTotal += $line;
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string)$row['name']); ?></strong>
                            <div style="font-size:12px;color:var(--color-text-secondary);">
                                <?php echo htmlspecialchars((string)($row['code'] ?? '')); ?>
                            </div>
                        </td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars((string)($row['barcode'] ?? '') !== '' ? (string)$row['barcode'] : '—'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['supplier_name'] ?? 'Sin proveedor')); ?></td>
                        <td>
                            <?php if ($canCreate): ?>
                                <form method="POST" action="<?php echo $comprasBase; ?>?action=draft_update" style="display:flex;gap:6px;align-items:center;margin:0;">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo (int)$row['product_id']; ?>">
                                    <input type="number" name="quantity" min="1" value="<?php echo $qty; ?>" class="form-control" style="width:80px;">
                                    <button type="submit" class="btn btn-sm btn-secondary">OK</button>
                                </form>
                            <?php else: ?>
                                <?php echo $qty; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo repFmt($cost, $currency); ?></td>
                        <td><?php echo repFmt($line, $currency); ?></td>
                        <td>
                            <?php if ($canCreate): ?>
                                <form method="POST" action="<?php echo $comprasBase; ?>?action=draft_remove" style="margin:0;">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo (int)$row['product_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Quitar">✕</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div style="text-align:right;font-weight:600;margin-top:10px;">
                Total bandeja: <?php echo repFmt($draftTotal, $currency); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($draftOrders)): ?>
    <div class="card neumorphic" style="margin-top:18px;">
        <div class="card-header"><h3>Órdenes pendientes de recibir</h3></div>
        <div class="card-body">
            <div class="table-container"><table>
                <thead><tr><th>OC</th><th>Proveedor</th><th>Fecha</th><th>Total</th><th>Envío</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($draftOrders as $order): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$order['order_number']); ?></td>
                        <td><?php echo htmlspecialchars((string)($order['supplier_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars(substr((string)$order['order_date'], 0, 10)); ?></td>
                        <td><?php echo repFmt((float)$order['total'], $currency); ?></td>
                        <td>
                            <?php if (!empty($order['sent_at'])): ?>
                                <span style="color:#10B981;font-weight:600;">
                                    <?php echo htmlspecialchars(strtoupper((string)$order['sent_via'])); ?>
                                </span>
                                <div style="font-size:12px;color:var(--color-text-secondary);">
                                    <?php echo htmlspecialchars(substr((string)$order['sent_at'], 0, 16)); ?>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--color-text-secondary);">Sin enviar</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="<?php echo $comprasBase; ?>?action=order_pdf&id=<?php echo (int)$order['id']; ?>"
                               class="btn btn-sm btn-secondary" target="_blank">PDF</a>
                            <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-sm btn-success"
                                        onclick="repWhatsapp(<?php echo (int)$order['id']; ?>)">WhatsApp</button>
                                <button type="button" class="btn btn-sm btn-info"
                                        onclick="repEmail(<?php echo (int)$order['id']; ?>, '<?php echo htmlspecialchars((string)($order['supplier_name'] ?? ''), ENT_QUOTES); ?>')">Correo</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="repEmailModal" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:440px;">
        <div class="modal-header">
            <h3>Enviar orden por correo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('repEmailModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $comprasBase; ?>?action=order_email">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="repEmailOrderId">
            <p style="margin-top:0;font-weight:600;" id="repEmailSupplier"></p>
            <div class="form-group">
                <label>Correo destino</label>
                <input type="email" name="email" class="form-control" placeholder="Se usa el del proveedor si lo deja vacío">
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);">
                Se adjunta el PDF de la orden. Si el hosting no tiene correo saliente, descargue el PDF y envíelo manualmente.
            </p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('repEmailModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
var repSym = document.querySelector('meta[name="currency-symbol"]')?.content || '$';
var repDec = parseInt(document.querySelector('meta[name="currency-decimals"]')?.content || '0');

function repMoney(v) {
    return repSym + ' ' + (parseFloat(v) || 0).toFixed(repDec);
}

function repToggleAll(on) {
    document.querySelectorAll('.rep-check:not([disabled])').forEach(function(c) { c.checked = on; });
    repRecalc();
}

function repRecalc() {
    var total = 0;
    document.querySelectorAll('#repForm tbody tr').forEach(function(row) {
        var check = row.querySelector('.rep-check');
        var qty = row.querySelector('.rep-qty');
        var cell = row.querySelector('.rep-line-total');
        if (!qty) return;
        var linea = (parseFloat(qty.value) || 0) * (parseFloat(qty.dataset.cost) || 0);
        if (cell) cell.textContent = repMoney(linea);
        if (check && check.checked && !check.disabled) total += linea;
    });
    var out = document.getElementById('repTotal');
    if (out) out.textContent = repMoney(total);
}

function repConfirmAdd() {
    var n = document.querySelectorAll('.rep-check:checked').length;
    if (n === 0) {
        showAlert('Seleccione al menos un producto', 'error');
        return false;
    }
    return confirm('Se añadirán ' + n + ' producto(s) a la bandeja (puede acumular y generar la OC después). ¿Continuar?');
}

function repWhatsapp(orderId) {
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

function repEmail(orderId, supplier) {
    document.getElementById('repEmailOrderId').value = orderId;
    document.getElementById('repEmailSupplier').textContent = supplier || '';
    document.getElementById('repEmailModal').style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.rep-qty').forEach(function(el) {
        el.addEventListener('input', repRecalc);
    });
    document.querySelectorAll('.rep-check').forEach(function(el) {
        el.addEventListener('change', repRecalc);
    });
    repRecalc();
});
</script>
