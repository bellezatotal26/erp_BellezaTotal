<?php
$layout = 'tenant';
$title = 'Trazabilidad - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Trazabilidad de inventario';
$movements = $movements ?? [];
$filters = $filters ?? ['q'=>'','type'=>'','reference_type'=>'','from'=>'','to'=>''];
$pagination = $pagination ?? null;
$currency = $currency ?? ['symbol'=>'$','decimals'=>2,'decimal'=>',','thousands'=>'.'];
$kardex = $kardex ?? [];
$costAlerts = $costAlerts ?? [];
$purchaseProjection = $purchaseProjection ?? [];
$pendingAccounting = (int)($pendingAccounting ?? 0);

function fmtT(float $a, array $c): string
{
    return ($c['symbol'] ?? '$') . ' ' . number_format($a, (int)($c['decimals'] ?? 2), $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<?php echo flashMessage(); ?>

<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card neumorphic"><h4>Movimientos (filtro)</h4><div class="stat-value"><?php echo (int)($pagination['total'] ?? count($movements)); ?></div></div>
    <div class="stat-card neumorphic"><h4>Pendientes de asiento</h4><div class="stat-value" style="color:#F59E0B;"><?php echo $pendingAccounting; ?></div></div>
    <div class="stat-card neumorphic"><h4>Alertas de costo</h4><div class="stat-value"><?php echo count($costAlerts); ?></div></div>
    <div class="stat-card neumorphic"><h4>A reponer (proyección)</h4><div class="stat-value"><?php echo count($purchaseProjection); ?></div></div>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $viewInstance->route('app/inventario'); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="action" value="traceability">
            <div class="form-group" style="margin:0;"><label>Buscar</label><input class="form-control" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="Producto, nota, referencia"></div>
            <div class="form-group" style="margin:0;"><label>Tipo</label>
                <select name="type" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach (['in'=>'Entrada','out'=>'Salida','adjustment'=>'Ajuste'] as $k=>$v): ?>
                        <option value="<?php echo $k; ?>" <?php echo ($filters['type']??'')===$k?'selected':''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Origen</label>
                <select name="reference_type" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach ([
                        'purchase'=>'Compra',
                        'purchase_order'=>'Orden de compra',
                        'sale'=>'Venta',
                        'adjustment'=>'Ajuste',
                        'return'=>'Devolución',
                        'woocommerce'=>'WooCommerce',
                        'mercadolibre'=>'Mercado Libre',
                        'purchase_cancel'=>'Cancel. compra',
                    ] as $k=>$v): ?>
                        <option value="<?php echo $k; ?>" <?php echo ($filters['reference_type']??'')===$k?'selected':''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;"><label>Desde</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($filters['from']??''); ?>"></div>
            <div class="form-group" style="margin:0;"><label>Hasta</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($filters['to']??''); ?>"></div>
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/inventario'); ?>">Volver a inventario</a>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/compras'); ?>">Compras</a>
        </form>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card neumorphic">
        <div class="card-header"><h3>Alertas de variación de costo</h3></div>
        <div class="card-body">
            <?php if (empty($costAlerts)): ?>
                <p style="color:var(--color-text-secondary);font-size:13px;margin:0;">Sin alertas relevantes.</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Producto</th><th>Antes</th><th>Ahora</th><th>Var.%</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($costAlerts, 0, 8) as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($a['name'] ?? '—'); ?></td>
                            <td><?php echo fmtT((float)($a['avg_previous'] ?? 0), $currency); ?></td>
                            <td><?php echo fmtT((float)($a['avg_current'] ?? 0), $currency); ?></td>
                            <td><?php echo htmlspecialchars((string)($a['change_pct'] ?? '—')); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card neumorphic">
        <div class="card-header"><h3>Proyección de reposición</h3></div>
        <div class="card-body">
            <?php if (empty($purchaseProjection)): ?>
                <p style="color:var(--color-text-secondary);font-size:13px;margin:0;">Sin sugerencias de compra ahora.</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Producto</th><th>Stock</th><th>Sugerido</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($purchaseProjection, 0, 8) as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['name'] ?? $p['product_name'] ?? '—'); ?></td>
                            <td><?php echo (int)($p['stock'] ?? 0); ?></td>
                            <td><?php echo (int)($p['suggested_qty'] ?? $p['to_buy'] ?? $p['qty'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:16px;">
    <div class="card-header"><h3>Kardex valorizado (reciente)</h3></div>
    <div class="card-body">
        <?php if (empty($kardex)): ?>
            <p style="color:var(--color-text-secondary);font-size:13px;margin:0;">Sin movimientos valorizados.</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cant.</th><th>Costo u.</th><th>Total</th><th>Stock</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($kardex, 0, 15) as $k): ?>
                    <tr>
                        <td><?php echo !empty($k['movement_date']) ? date('d/m/Y', strtotime($k['movement_date'])) : (!empty($k['created_at']) ? date('d/m/Y', strtotime($k['created_at'])) : '—'); ?></td>
                        <td><?php echo htmlspecialchars($k['product_name'] ?? $k['name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($k['type'] ?? '—'); ?></td>
                        <td><?php echo (int)($k['quantity'] ?? 0); ?></td>
                        <td><?php echo fmtT((float)($k['unit_cost'] ?? 0), $currency); ?></td>
                        <td><?php echo fmtT((float)($k['total_cost'] ?? 0), $currency); ?></td>
                        <td><?php echo isset($k['stock_after']) ? (int)$k['stock_after'] : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Movimientos de stock</h3></div>
    <div class="card-body">
        <?php if (empty($movements)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Sin movimientos con esos filtros</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead>
                <tr>
                    <th>Fecha ingreso</th><th>Producto</th><th>Canal</th><th>Tipo</th><th>Cant.</th>
                    <th>Costo u.</th><th>Total</th><th>Stock</th>
                    <th>Origen</th><th>Notas</th><th>Usuario</th><th>Editar fecha</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($movements as $m):
                    $dt = $m['movement_date'] ?? $m['created_at'];
                    $dateVal = $dt ? date('Y-m-d', strtotime($dt)) : date('Y-m-d');
                ?>
                    <tr>
                        <td><?php echo $dt ? date('d/m/Y H:i', strtotime($dt)) : '—'; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($m['product_name'] ?? '—'); ?></strong>
                            <?php if (!empty($m['product_code'])): ?>
                                <br><small><?php echo htmlspecialchars($m['product_code']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(\SoftNova\Core\product_channel_label((string)($m['source_channel'] ?? 'manual'))); ?></td>
                        <td>
                            <?php
                            $badge = $m['type'] === 'in' ? 'badge-success' : ($m['type'] === 'out' ? 'badge-danger' : 'badge-warning');
                            ?>
                            <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($m['type']); ?></span>
                        </td>
                        <td><?php echo (int)$m['quantity']; ?></td>
                        <td><?php echo fmtT((float)($m['unit_cost'] ?? 0), $currency); ?></td>
                        <td><?php echo fmtT((float)($m['total_cost'] ?? 0), $currency); ?></td>
                        <td><?php echo isset($m['stock_after']) && $m['stock_after'] !== null ? (int)$m['stock_after'] : '—'; ?></td>
                        <td>
                            <?php echo htmlspecialchars((string)($m['reference_type'] ?? '—')); ?>
                            <?php if (!empty($m['reference_id'])): ?>
                                #<?php echo (int)$m['reference_id']; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($m['notes'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($m['user_name'] ?? '—')); ?></td>
                        <td>
                            <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=edit_movement_date" data-ajax="true" style="display:flex;gap:4px;align-items:center;">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="movement_id" value="<?php echo (int)$m['id']; ?>">
                                <input type="date" name="movement_date" value="<?php echo htmlspecialchars($dateVal); ?>" class="form-control" style="width:140px;">
                                <button type="submit" class="btn btn-sm btn-secondary" title="Guardar fecha">OK</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $paginationBaseUrl = $viewInstance->route('app/inventario');
            $paginationQuery = array_filter([
                'action' => 'traceability',
                'q' => $filters['q'] ?: null,
                'type' => $filters['type'] ?: null,
                'reference_type' => $filters['reference_type'] ?: null,
                'from' => $filters['from'] ?: null,
                'to' => $filters['to'] ?: null,
            ]);
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>
