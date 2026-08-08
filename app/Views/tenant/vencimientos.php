<?php
$layout = 'tenant';
$title = 'Vencimientos - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Control de vencimientos';
$summary = $summary ?? [];
$lots = $lots ?? [];
$mismatches = $mismatches ?? [];
$daysFilter = (int)($daysFilter ?? 0);
$currency = $currency ?? ['symbol' => '$', 'decimals' => 2, 'decimal' => ',', 'thousands' => '.'];
$canEdit = \SoftNova\Core\TenantMiddleware::canDo('edit', 'inventario');

function vencFmt(float $a, array $c): string
{
    return ($c['symbol'] ?? '$') . ' ' . number_format($a, (int)($c['decimals'] ?? 2), $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}

function vencEstado(?int $daysLeft): array
{
    if ($daysLeft === null) {
        return ['Sin fecha', '#6B7280'];
    }
    if ($daysLeft < 0) {
        return ['Vencido hace ' . abs($daysLeft) . ' d', '#DC2626'];
    }
    if ($daysLeft === 0) {
        return ['Vence hoy', '#DC2626'];
    }
    if ($daysLeft <= 7) {
        return ['Vence en ' . $daysLeft . ' d', '#EA580C'];
    }
    return ['Vence en ' . $daysLeft . ' d', '#F59E0B'];
}
?>
<?php echo flashMessage(); ?>

<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card neumorphic">
        <h4>Lotes vencidos</h4>
        <div class="stat-value" style="color:#DC2626;"><?php echo (int)($summary['expired_lots'] ?? 0); ?></div>
        <p style="font-size:12px;color:var(--color-text-secondary);margin:4px 0 0;">
            <?php echo (int)($summary['expired_units'] ?? 0); ?> unidades
        </p>
    </div>
    <div class="stat-card neumorphic">
        <h4>Pérdida por vencidos</h4>
        <div class="stat-value" style="color:#DC2626;"><?php echo vencFmt((float)($summary['expired_value'] ?? 0), $currency); ?></div>
    </div>
    <div class="stat-card neumorphic">
        <h4>Lotes por vencer</h4>
        <div class="stat-value" style="color:#F59E0B;"><?php echo (int)($summary['expiring_lots'] ?? 0); ?></div>
        <p style="font-size:12px;color:var(--color-text-secondary);margin:4px 0 0;">
            <?php echo (int)($summary['expiring_units'] ?? 0); ?> unidades
        </p>
    </div>
    <div class="stat-card neumorphic">
        <h4>Valor en riesgo</h4>
        <div class="stat-value" style="color:#F59E0B;"><?php echo vencFmt((float)($summary['expiring_value'] ?? 0), $currency); ?></div>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <form method="GET" action="<?php echo $viewInstance->route('app/inventario'); ?>" style="display:flex;gap:10px;align-items:end;">
            <input type="hidden" name="action" value="vencimientos">
            <div class="form-group" style="margin:0;">
                <label>Horizonte de alerta</label>
                <select name="days" class="form-control" onchange="this.form.submit()">
                    <option value="0" <?php echo $daysFilter === 0 ? 'selected' : ''; ?>>Según cada producto</option>
                    <?php foreach ([7 => '7 días', 15 => '15 días', 30 => '30 días', 60 => '60 días', 90 => '90 días'] as $d => $label): ?>
                        <option value="<?php echo $d; ?>" <?php echo $daysFilter === $d ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <a href="<?php echo $viewInstance->route('app/inventario'); ?>" class="btn btn-secondary">Volver a inventario</a>
        <a href="<?php echo $viewInstance->route('app/compras'); ?>?action=reposicion" class="btn btn-primary">Panel de reposición</a>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-header"><h3>Lotes vencidos y próximos a vencer</h3></div>
    <div class="card-body">
        <?php if (empty($lots)): ?>
            <p style="text-align:center;padding:24px;color:var(--color-text-secondary);">
                Ningún lote requiere atención en este horizonte.
            </p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th>Producto</th><th>Lote</th><th>Vence</th><th>Estado</th>
                    <th>Cantidad</th><th>Costo unit.</th><th>Valor en riesgo</th><th>Proveedor</th>
                    <?php if ($canEdit): ?><th>Acciones</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($lots as $lot): ?>
                    <?php
                    $daysLeft = $lot['days_left'] !== null ? (int)$lot['days_left'] : null;
                    [$estadoTexto, $estadoColor] = vencEstado($daysLeft);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string)$lot['product_name']); ?></strong>
                            <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$lot['product_code']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars((string)$lot['lot_code']); ?></td>
                        <td><?php echo htmlspecialchars((string)($lot['expiry_date'] ?? '—')); ?></td>
                        <td><span style="color:<?php echo $estadoColor; ?>;font-weight:600;"><?php echo $estadoTexto; ?></span></td>
                        <td><?php echo (int)$lot['quantity']; ?></td>
                        <td><?php echo vencFmt((float)$lot['unit_cost'], $currency); ?></td>
                        <td><?php echo vencFmt((float)$lot['value_at_risk'], $currency); ?></td>
                        <td><?php echo htmlspecialchars((string)($lot['supplier_name'] ?? '—')); ?></td>
                        <?php if ($canEdit): ?>
                            <td style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick="openLotAdjust(<?php echo (int)$lot['id']; ?>, '<?php echo htmlspecialchars((string)$lot['lot_code'], ENT_QUOTES); ?>', <?php echo (int)$lot['quantity']; ?>)">
                                    Ajustar
                                </button>
                                <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=lot_block" style="display:inline;">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="lot_id" value="<?php echo (int)$lot['id']; ?>">
                                    <input type="hidden" name="notes" value="Bloqueado por vencimiento">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('¿Bloquear este lote para que no se venda?')">Bloquear</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($mismatches)): ?>
    <div class="card neumorphic" style="margin-bottom:18px;">
        <div class="card-header"><h3>Productos con lotes descuadrados</h3></div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin-top:0;">
                El stock del producto no coincide con la suma de sus lotes. Suele pasar cuando se activa
                el control de lotes con saldo previo: registre una entrada con el lote correspondiente para cuadrarlo.
            </p>
            <div class="table-container"><table>
                <thead><tr><th>Producto</th><th>Stock</th><th>Suma de lotes</th><th>Diferencia</th></tr></thead>
                <tbody>
                <?php foreach ($mismatches as $row): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string)$row['name']); ?></strong>
                            <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$row['code']); ?></div>
                        </td>
                        <td><?php echo (int)$row['stock']; ?></td>
                        <td><?php echo (int)$row['lot_stock']; ?></td>
                        <td style="font-weight:600;color:#DC2626;"><?php echo (int)$row['stock'] - (int)$row['lot_stock']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="lotAdjustModal" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header">
            <h3>Ajustar lote</h3>
            <button type="button" class="modal-close" onclick="closeLotAdjust()">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=lot_adjust">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="lot_id" id="lotAdjustId">
            <p style="margin-top:0;font-size:13px;color:var(--color-text-secondary);">
                Lote <strong id="lotAdjustCode"></strong>. El ajuste corrige la trazabilidad del lote;
                para corregir el stock total use el ajuste de inventario.
            </p>
            <div class="form-group">
                <label>Cantidad real del lote</label>
                <input type="number" min="0" name="quantity" id="lotAdjustQty" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Motivo</label>
                <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Merma, rotura, reconteo">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLotAdjust()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar ajuste</button>
            </div>
        </form>
    </div>
</div>

<script>
function openLotAdjust(id, code, qty) {
    document.getElementById('lotAdjustId').value = id;
    document.getElementById('lotAdjustCode').textContent = code;
    document.getElementById('lotAdjustQty').value = qty;
    document.getElementById('lotAdjustModal').style.display = 'flex';
}
function closeLotAdjust() {
    document.getElementById('lotAdjustModal').style.display = 'none';
}
</script>
<?php endif; ?>
