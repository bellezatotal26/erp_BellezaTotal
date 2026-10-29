<?php
$layout = 'tenant';
$title = 'Trazabilidad - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Trazabilidad de inventario';
$movementGroups = $movementGroups ?? [];
$movementsLineTotal = (int)($movementsLineTotal ?? 0);
$movementsTruncated = !empty($movementsTruncated);
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

$traceRefMap = [
    'csv_import' => 'Importación CSV',
    'csv_export' => 'Exportación CSV',
    'purchase_order' => 'Orden de compra',
    'purchase_credit_note' => 'Nota crédito compra',
    'sale_credit_note' => 'Nota crédito venta',
    'opening' => 'Inventario inicial',
    'sale' => 'Venta',
    'return' => 'Devolución',
    'adjustment' => 'Ajuste',
    'purchase' => 'Compra',
    'inventory_exit' => 'Salida / pérdida',
    'loan' => 'Préstamo (salida)',
    'loan_return' => 'Devolución préstamo (entrada)',
    'loan_received' => 'Préstamo recibido (entrada)',
    'loan_received_return' => 'Devolución préstamo recibido (salida)',
    'loan_received_settle' => 'Cierre préstamo recibido (sin stock)',
    'status_change' => 'Cambio de estado',
    'woocommerce' => 'WooCommerce',
    'mercadolibre' => 'Mercado Libre',
    'purchase_cancel' => 'Cancel. compra',
];
$typeLabels = ['in' => 'Entrada', 'out' => 'Salida', 'adjustment' => 'Ajuste'];
?>
<?php echo flashMessage(); ?>

<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card neumorphic"><h4>Movimientos (filtro)</h4><div class="stat-value"><?php echo (int)($pagination['total'] ?? count($movementGroups)); ?></div></div>
    <div class="stat-card neumorphic"><h4>Líneas de producto</h4><div class="stat-value"><?php echo $movementsLineTotal; ?></div></div>
    <div class="stat-card neumorphic"><h4>Pendientes de asiento</h4><div class="stat-value" style="color:#F59E0B;"><?php echo $pendingAccounting; ?></div></div>
    <div class="stat-card neumorphic"><h4>Alertas de costo</h4><div class="stat-value"><?php echo count($costAlerts); ?></div></div>
</div>

<div class="card neumorphic" style="margin-bottom:18px;" id="filtro-trazabilidad">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $viewInstance->route('app/inventario'); ?>#movimientos-stock" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="action" value="traceability">
            <div class="form-group" style="margin:0;"><label>Buscar</label><input class="form-control" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="SKU, EAN o nombre… varios con coma" style="min-width:220px;"></div>
            <div class="form-group" style="margin:0;"><label>Tipo movimiento</label>
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
                        'purchase_credit_note'=>'Nota crédito compra',
                        'sale_credit_note'=>'Nota crédito venta',
                        'sale'=>'Venta',
                        'adjustment'=>'Ajuste / agregar stock',
                        'opening'=>'Inventario inicial',
                        'return'=>'Devolución venta',
                        'inventory_exit'=>'Salida / pérdida',
                        'loan'=>'Préstamo (salida)',
                        'loan_return'=>'Devolución préstamo (entrada)',
                        'loan_received'=>'Préstamo recibido (entrada)',
                        'loan_received_return'=>'Devolución préstamo recibido (salida)',
                        'loan_received_settle'=>'Cierre préstamo recibido (sin stock)',
                        'status_change'=>'Cambio de estado',
                        'csv_import'=>'Importación CSV',
                        'csv_export'=>'Exportación CSV',
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
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/inventario'); ?>?action=traceability#movimientos-stock">Limpiar</a>
            <?php
            $traceFilterQ = array_filter([
                'q' => $filters['q'] ?? null,
                'type' => $filters['type'] ?? null,
                'reference_type' => $filters['reference_type'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'product_id' => $filters['product_id'] ?? null,
            ], static fn($v) => $v !== null && $v !== '');
            $tracePdfQ = http_build_query(array_merge(['action' => 'traceability_pdf'], $traceFilterQ));
            $traceXlsQ = http_build_query(array_merge(['action' => 'traceability_export'], $traceFilterQ));
            ?>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/inventario') . '?' . $traceXlsQ; ?>" title="Exportar movimientos del filtro actual a Excel">Exportar Excel</a>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/inventario') . '?' . $tracePdfQ; ?>" target="_blank" title="Descargar PDF detallado">Reporte PDF</a>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/inventario'); ?>">Volver a inventario</a>
            <a class="btn btn-secondary" href="<?php echo $viewInstance->route('app/compras'); ?>">Compras</a>
        </form>
        <?php
        $hasFilters = ($filters['q'] ?? '') !== ''
            || ($filters['type'] ?? '') !== ''
            || ($filters['reference_type'] ?? '') !== ''
            || ($filters['from'] ?? '') !== ''
            || ($filters['to'] ?? '') !== '';
        ?>
        <?php if ($hasFilters): ?>
            <p style="margin:10px 0 0;font-size:12px;color:var(--color-text-secondary);">
                Filtros activos sobre <strong>Movimientos de stock</strong>.
                Tip: varias referencias con <strong>comas</strong> (SKU1, SKU2, EAN…).
                Salidas por garantía/merma: Origen = <strong>Salida / pérdida</strong>.
                Use <strong>Limpiar</strong> para ver todos los movimientos.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card neumorphic" style="margin-bottom:16px;" id="movimientos-stock">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">Movimientos de stock</h3>
        <small style="color:var(--color-text-secondary);">Agrupados por operación · clic para ver productos</small>
    </div>
    <div class="card-body">
        <?php if (empty($movementGroups)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">
                Sin movimientos con esos filtros.
                <?php if (!empty($hasFilters)): ?>
                    <br><a href="<?php echo $viewInstance->route('app/inventario'); ?>?action=traceability#movimientos-stock">Limpiar filtros</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <?php if ($movementsTruncated): ?>
                <p class="trace-mov-hint">Hay más de 2500 líneas con estos filtros; se agrupan las más recientes. Acote fechas u origen para ver el resto.</p>
            <?php endif; ?>
            <div class="trace-mov-groups">
                <?php foreach ($movementGroups as $g):
                    $refType = (string)($g['reference_type'] ?? '');
                    $refId = (int)($g['reference_id'] ?? 0);
                    $refCode = trim((string)($g['reference_code'] ?? ''));
                    $refLabel = $traceRefMap[$refType] ?? ($refType !== '' ? $refType : 'Movimiento');
                    if ($refCode !== '') {
                        // Mostrar número humano (OC-202609-0004), no solo el id interno (#15).
                        $refLabel .= ' ' . $refCode;
                    } elseif ($refId > 0) {
                        $refLabel .= ' #' . $refId;
                    } elseif ($refType === '') {
                        $refLabel = 'Movimiento individual';
                    }
                    $poStatus = (string)($g['purchase_order_status'] ?? '');
                    $poStatusHint = '';
                    if (in_array($refType, ['purchase_order', 'purchase'], true) && $poStatus === 'received') {
                        $poStatusHint = 'Recibida → ver en Facturas';
                    } elseif (in_array($refType, ['purchase_order', 'purchase'], true) && $poStatus !== '') {
                        $poStatusHint = 'Estado: ' . $poStatus;
                    }
                    $badge = ($g['type'] ?? '') === 'in' ? 'badge-success' : (($g['type'] ?? '') === 'out' ? 'badge-danger' : 'badge-warning');
                    $lines = $g['lines'] ?? [];
                    $openByDefault = count($lines) <= 3;
                    $summaryMotive = \SoftNova\Services\StockService::describeMovementMotiveLines($lines[0] ?? []);
                    $summaryKind = $summaryMotive[0] ?? '';
                ?>
                    <details class="trace-mov-group<?php echo !empty($g['new_products_count']) ? ' has-new-products' : ''; ?>" <?php echo $openByDefault ? 'open' : ''; ?>>
                        <summary class="trace-mov-summary">
                            <span class="trace-mov-summary-main">
                                <span class="trace-mov-date"><?php echo htmlspecialchars((string)($g['display_at'] ?? '—')); ?></span>
                                <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($typeLabels[$g['type'] ?? ''] ?? (string)($g['type'] ?? '—')); ?></span>
                                <strong class="trace-mov-origin"><?php echo htmlspecialchars($refLabel); ?></strong>
                                <?php if ($poStatusHint !== ''): ?>
                                    <span class="trace-mov-kind" title="Las OC recibidas salen de «Órdenes abiertas» y pasan a Facturas"><?php echo htmlspecialchars($poStatusHint); ?></span>
                                <?php elseif ($summaryKind !== '' && $summaryKind !== '—'): ?>
                                    <span class="trace-mov-kind"><?php echo htmlspecialchars($summaryKind); ?></span>
                                <?php endif; ?>
                                <?php if ($poStatusHint !== '' && $summaryKind !== '' && $summaryKind !== '—'): ?>
                                    <span class="trace-mov-kind"><?php echo htmlspecialchars($summaryKind); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="trace-mov-summary-meta">
                                <span><?php echo (int)($g['lines_count'] ?? 0); ?> prod.</span>
                                <?php if (!empty($g['new_products_count'])): ?>
                                    <span class="trace-new-pill"><?php echo (int)$g['new_products_count']; ?> nuevo<?php echo (int)$g['new_products_count'] === 1 ? '' : 's'; ?></span>
                                <?php endif; ?>
                                <span><?php echo (int)($g['qty_total'] ?? 0); ?> und.</span>
                                <span><?php echo fmtT((float)($g['cost_total'] ?? 0), $currency); ?></span>
                                <span class="trace-mov-user"><?php echo htmlspecialchars((string)($g['user_name'] ?? '—')); ?></span>
                            </span>
                        </summary>
                        <div class="table-container trace-movements-wrap">
                            <table class="trace-movements-table trace-mov-lines">
                                <thead>
                                <tr>
                                    <th>Producto</th><th>Ubicación</th><th>Canal</th><th>Cant.</th>
                                    <th>Costo u.</th><th>Total</th><th>Stock</th>
                                    <th class="trace-col-motivo">Detalle</th><th>Editar fecha</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($lines as $m):
                                    $isNew = \SoftNova\Services\StockService::isNewProductMovement($m);
                                    $dateVal = !empty($m['movement_date']) && $m['movement_date'] !== '0000-00-00'
                                        ? date('Y-m-d', strtotime((string)$m['movement_date']))
                                        : (!empty($m['created_at']) ? date('Y-m-d', strtotime((string)$m['created_at'])) : date('Y-m-d'));
                                    $motivoLines = \SoftNova\Services\StockService::describeMovementMotiveLines($m);
                                ?>
                                    <tr class="<?php echo $isNew ? 'trace-line-new' : ''; ?>">
                                        <td class="trace-col-product">
                                            <strong><?php echo htmlspecialchars($m['product_name'] ?? '—'); ?></strong>
                                            <?php if ($isNew): ?>
                                                <span class="trace-new-badge" title="Producto creado en este movimiento">Nuevo</span>
                                            <?php endif; ?>
                                            <?php if (!empty($m['product_code'])): ?>
                                                <br><small><?php echo htmlspecialchars($m['product_code']); ?></small>
                                            <?php endif; ?>
                                            <?php
                                            $ean = trim((string)($m['product_barcode'] ?? $m['barcode'] ?? ''));
                                            if ($ean !== ''):
                                            ?>
                                                <br><small style="color:var(--color-text-secondary);">EAN: <?php echo htmlspecialchars($ean); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($m['category_name'])): ?>
                                                <br><small style="color:var(--color-text-secondary);"><?php echo htmlspecialchars($m['category_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($m['product_location'] ?? '—'); ?></td>
                                        <td><?php echo htmlspecialchars(\SoftNova\Core\product_channel_label((string)($m['source_channel'] ?? 'manual'))); ?></td>
                                        <td><?php echo (int)$m['quantity']; ?></td>
                                        <td><?php echo fmtT((float)($m['unit_cost'] ?? 0), $currency); ?></td>
                                        <td><?php echo fmtT((float)($m['total_cost'] ?? 0), $currency); ?></td>
                                        <td><?php echo isset($m['stock_after']) && $m['stock_after'] !== null ? (int)$m['stock_after'] : '—'; ?></td>
                                        <td class="trace-col-motivo">
                                            <div class="trace-motivo">
                                                <?php foreach ($motivoLines as $i => $line): ?>
                                                    <div class="trace-motivo-line<?php echo $i === 0 ? ' is-kind' : ''; ?>">
                                                        <?php echo htmlspecialchars($line); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="trace-col-edit">
                                            <form method="POST" action="<?php echo $viewInstance->route('app/inventario'); ?>?action=edit_movement_date" data-ajax="true" style="display:flex;gap:4px;align-items:center;" title="Corrige la fecha operativa del movimiento (no borra el registro)">
                                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                                <input type="hidden" name="movement_id" value="<?php echo (int)$m['id']; ?>">
                                                <input type="date" name="movement_date" value="<?php echo htmlspecialchars($dateVal); ?>" class="form-control" style="width:140px;" aria-label="Fecha del movimiento">
                                                <button type="submit" class="btn btn-sm btn-secondary" title="Guardar la fecha del movimiento">Guardar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
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

<div class="card neumorphic">
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
