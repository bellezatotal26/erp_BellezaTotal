<?php
$layout = 'tenant';
$title = 'Consignación - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Productos por consignación';
$tab = $tab ?? 'productos';
$stats = $stats ?? [];
$consignors = $consignors ?? [];
$suppliers = $suppliers ?? [];
$products = $products ?? [];
$receiveProducts = $receiveProducts ?? $products;
$openDebts = $openDebts ?? [];
$receipts = $receipts ?? [];
$settlements = $settlements ?? [];
$consignorFilter = (int)($consignorFilter ?? 0);
$supplierFilter = (int)($supplierFilter ?? 0);
$showInactive = !empty($showInactive);
$q = $q ?? '';
$paymentMethods = $paymentMethods ?? ['cash' => 'Efectivo'];
$canCreate = $canCreate ?? false;
$canEdit = $canEdit ?? false;
$canExport = $canExport ?? false;
$fmt = $fmt ?? fn($a) => number_format((float)$a, 2, ',', '.');
$base = $viewInstance->route('app/consignacion');
$productFilters = $productFilters ?? ['consignor_id' => 0, 'supplier_id' => 0, 'q' => '', 'status' => 'active', 'stock_state' => '', 'sort' => 'name'];
$productGroups = $productGroups ?? [];
$groupByConsignor = !empty($groupByConsignor);
$margin = $margin ?? null;
$marginFilters = $marginFilters ?? ['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'consignor_id' => 0, 'q' => '', 'group' => 'product'];
$trace = $trace ?? null;
$traceFilters = $traceFilters ?? ['from' => '', 'to' => '', 'consignor_id' => 0, 'product_id' => 0, 'type' => '', 'q' => ''];
$traceProducts = $traceProducts ?? [];
$CS = \SoftNova\Services\ConsignmentService::class;
$statusLabels = $CS::STATUS_LABELS;
$pctFmt = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.') . '%';
$marginClass = fn($v) => $v === null ? '' : ((float)$v < 0 ? 'cs-neg' : ((float)$v < 15 ? 'cs-warn' : 'cs-pos'));
$qs = fn(array $params) => htmlspecialchars('?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0)));
?>
<style>
.cs-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
.cs-filters label { display:block; font-size:11px; color:var(--color-text-secondary); margin:0 0 2px; }
.cs-filters .form-control { min-width:140px; }
.cs-toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.cs-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin:0 0 14px; }
.cs-kpi { border:1px solid var(--color-border, #e5e7eb); border-radius:10px; padding:10px 12px; }
.cs-kpi span { display:block; font-size:11px; color:var(--color-text-secondary); text-transform:uppercase; letter-spacing:.03em; }
.cs-kpi strong { display:block; font-size:17px; margin-top:2px; }
.cs-kpi small { display:block; font-size:11px; color:var(--color-text-secondary); }
.cs-table th, .cs-table td { white-space:nowrap; }
.cs-table td.cs-wrap { white-space:normal; min-width:180px; }
.cs-num { text-align:right; }
.cs-pos { color:var(--dmc-0D7C4A, #0D7C4A); }
.cs-warn { color:var(--dmc-B45309, #B45309); }
.cs-neg { color:var(--dmc-B91C1C, #B91C1C); }
.cs-sub { font-size:11px; color:var(--color-text-secondary); }
.cs-group { border:1px solid var(--color-border, #e5e7eb); border-radius:12px; margin-bottom:16px; overflow:hidden; }
.cs-group-head { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; padding:12px 14px; background:rgba(13,124,74,.06); }
.cs-group-head h4 { margin:0 0 4px; font-size:15px; }
.cs-chip { display:inline-block; padding:2px 8px; border-radius:999px; background:rgba(0,0,0,.05); font-size:12px; margin:2px 4px 2px 0; }
.cs-chip-warn { background:var(--dmb-FEF3C7, #FEF3C7); color:var(--dmc-92400E, #92400E); }
.cs-chip-ok { background:var(--dmb-DCFCE7, #DCFCE7); color:var(--dmc-166534, #166534); }
.cs-bar { height:6px; background:rgba(0,0,0,.08); border-radius:3px; overflow:hidden; min-width:70px; }
.cs-bar i { display:block; height:100%; background:#0D7C4A; }
.cs-type { font-size:11px; padding:2px 8px; border-radius:999px; font-weight:600; white-space:nowrap; }
.cs-type-consignment_in { background:var(--dmb-DCFCE7, #DCFCE7); color:var(--dmc-166534, #166534); }
.cs-type-sale { background:var(--dmb-DBEAFE, #DBEAFE); color:var(--dmc-1E40AF, #1E40AF); }
.cs-type-consignment_return { background:var(--dmb-FEF3C7, #FEF3C7); color:var(--dmc-92400E, #92400E); }
.cs-type-return, .cs-type-sale_credit_note { background:var(--dmb-F3E8FF, #F3E8FF); color:var(--dmc-6B21A8, #6B21A8); }
.cs-type-adjustment { background:var(--dmb-E5E7EB, #E5E7EB); color:var(--dmc-374151, #374151); }
.cs-type-settlement { background:var(--dmb-D1FAE5, #D1FAE5); color:var(--dmc-065F46, #065F46); }
.cs-link { color:inherit; text-decoration:none; }
.cs-link:hover { color:var(--dmc-0D7C4A, #0D7C4A); text-decoration:underline; }
.cs-note { font-size:12px; color:var(--color-text-secondary); margin:10px 0 0; }
.cs-settle-total { font-weight:600; padding:6px 10px; border-radius:8px; background:var(--dmb-FEF3C7, #FEF3C7); color:var(--dmc-92400E, #92400E); }
</style>
<?php echo flashMessage(); ?>

<p style="margin:0 0 16px;color:var(--color-text-secondary);font-size:13px;max-width:920px;">
    Mercancía de <strong>terceros consignantes</strong>, vinculada al catálogo de <strong>proveedores</strong> para mantener orden.
    Controla stock físico en bodega virtual <em>CONSIGNACIÓN</em> sin capitalizar inventario propio ni generar compra.
    Al vender se crea la obligación al consignante; el pago se hace en liquidaciones.
</p>

<div class="stats-grid" style="margin-bottom:18px;">
    <div class="stat-card neumorphic"><h4>Consignantes</h4><div class="stat-value"><?php echo (int)($stats['consignors'] ?? 0); ?></div></div>
    <div class="stat-card neumorphic"><h4>Productos</h4><div class="stat-value"><?php echo (int)($stats['products'] ?? 0); ?></div></div>
    <div class="stat-card neumorphic"><h4>Unidades en tienda</h4><div class="stat-value"><?php echo (int)($stats['units_on_hand'] ?? 0); ?></div></div>
    <div class="stat-card neumorphic"><h4>Por liquidar</h4><div class="stat-value" style="color:var(--dmc-B45309, #B45309);"><?php echo htmlspecialchars($fmt($stats['payable_amount'] ?? 0)); ?></div>
        <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo (int)($stats['payable_units'] ?? 0); ?> und. vendidas</div>
    </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
    <?php
    $tabs = [
        'productos' => 'Productos',
        'ingresos' => 'Ingresos',
        'liquidaciones' => 'Por liquidar',
        'margen' => 'Margen de venta',
        'trazabilidad' => 'Trazabilidad',
        'consignantes' => 'Consignantes',
        'historial' => 'Historial',
    ];
    foreach ($tabs as $k => $label):
    ?>
        <a class="btn <?php echo $tab === $k ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $base; ?>?tab=<?php echo $k; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
    <span style="flex:1;"></span>
    <?php if ($canCreate && $tab === 'productos'): ?>
        <button type="button" class="btn btn-primary neumorphic-btn" onclick="document.getElementById('modalProduct').style.display='flex'">+ Producto consignado</button>
    <?php endif; ?>
    <?php if ($canCreate && $tab === 'ingresos'): ?>
        <button type="button" class="btn btn-primary neumorphic-btn" onclick="document.getElementById('modalReceive').style.display='flex'">+ Ingreso remesa</button>
    <?php endif; ?>
    <?php if ($canCreate && $tab === 'consignantes'): ?>
        <button type="button" class="btn btn-primary neumorphic-btn" onclick="openConsignorModal()">+ Consignante</button>
    <?php endif; ?>
</div>

<?php if ($tab === 'productos'): ?>
<?php
$pf = $productFilters;
$filterQuery = [
    'consignor_id' => $pf['consignor_id'],
    'q' => $pf['q'],
    'status' => $pf['status'] !== 'active' ? $pf['status'] : '',
    'stock_state' => $pf['stock_state'],
    'sort' => $pf['sort'] !== 'name' ? $pf['sort'] : '',
];
$kpi = ['stock' => 0, 'value_cost' => 0.0, 'value_sale' => 0.0, 'sold' => 0, 'revenue' => 0.0, 'cost' => 0.0, 'pending_units' => 0, 'pending' => 0.0];
foreach ($products as $p) {
    $kpi['stock'] += max(0, (int)$p['stock']);
    $kpi['value_cost'] += (float)($p['stock_value_cost'] ?? 0);
    $kpi['value_sale'] += (float)($p['stock_value_sale'] ?? 0);
    $kpi['sold'] += (int)($p['units_sold'] ?? 0);
    $kpi['revenue'] += (float)($p['revenue'] ?? 0);
    $kpi['cost'] += (float)($p['cost_sold'] ?? 0);
    $kpi['pending_units'] += (int)($p['units_pending'] ?? 0);
    $kpi['pending'] += (float)($p['amount_pending'] ?? 0);
}
$kpiProfit = $kpi['revenue'] - $kpi['cost'];
$kpiMargin = $kpi['revenue'] > 0.009 ? round($kpiProfit / $kpi['revenue'] * 100, 1) : null;
$isFiltered = $pf['consignor_id'] > 0 || $pf['supplier_id'] > 0 || $pf['q'] !== '' || $pf['status'] !== 'active' || $pf['stock_state'] !== '';

$renderProductRow = function (array $p, bool $showConsignor) use ($fmt, $pctFmt, $marginClass, $statusLabels, $canEdit, $base, $qs) {
    $active = ($p['status'] ?? '') === 'active';
    ?>
    <tr>
        <td><?php echo htmlspecialchars((string)$p['code']); ?></td>
        <td class="cs-wrap">
            <strong><?php echo htmlspecialchars((string)$p['name']); ?></strong>
            <?php if (!empty($p['barcode'])): ?>
                <div class="cs-sub"><?php echo htmlspecialchars((string)$p['barcode']); ?></div>
            <?php endif; ?>
        </td>
        <?php if ($showConsignor): ?>
            <td><?php echo htmlspecialchars((string)($p['consignor_name'] ?? '—')); ?></td>
        <?php endif; ?>
        <td class="cs-num" style="font-weight:600;"><?php echo (int)$p['stock']; ?></td>
        <td class="cs-num"><?php echo htmlspecialchars($fmt($p['purchase_price'] ?? 0)); ?></td>
        <td class="cs-num"><?php echo htmlspecialchars($fmt($p['sale_price'] ?? 0)); ?></td>
        <td class="cs-num">
            <span class="<?php echo $marginClass($p['unit_margin_pct']); ?>"><?php echo htmlspecialchars($fmt($p['unit_margin'] ?? 0)); ?></span>
            <div class="cs-sub"><?php echo $pctFmt($p['unit_margin_pct']); ?></div>
        </td>
        <td class="cs-num"><?php echo (int)$p['units_sold']; ?></td>
        <td class="cs-num">
            <?php if ((int)$p['units_sold'] > 0): ?>
                <span class="<?php echo $marginClass($p['margin_pct']); ?>"><?php echo htmlspecialchars($fmt($p['profit'])); ?></span>
                <div class="cs-sub"><?php echo $pctFmt($p['margin_pct']); ?> de <?php echo htmlspecialchars($fmt($p['revenue'])); ?></div>
            <?php else: ?>
                <span class="cs-sub">Sin ventas</span>
            <?php endif; ?>
        </td>
        <td class="cs-num">
            <?php if ((int)$p['units_pending'] > 0): ?>
                <strong class="cs-warn"><?php echo htmlspecialchars($fmt($p['amount_pending'])); ?></strong>
                <div class="cs-sub"><?php echo (int)$p['units_pending']; ?> und.</div>
            <?php else: ?>
                <span class="cs-sub">—</span>
            <?php endif; ?>
        </td>
        <td><span class="badge <?php echo $active ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars($statusLabels[$p['status']] ?? (string)$p['status']); ?></span></td>
        <td class="table-actions">
            <a class="btn btn-sm btn-secondary" title="Ver movimientos de este producto" href="<?php echo $base . $qs(['tab' => 'trazabilidad', 'product_id' => (int)$p['id'], 'from' => 'all']); ?>">Kardex</a>
            <?php if ($canEdit): ?>
            <button type="button" class="btn btn-sm btn-secondary" onclick='editProduct(<?php echo json_encode([
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'barcode' => (string)($p['barcode'] ?? ''),
                'sale_price' => (float)$p['sale_price'],
                'tax_rate' => (float)($p['tax_rate'] ?? 0),
                'unit_cost' => (float)$p['purchase_price'],
                'notes' => (string)($p['notes'] ?? ''),
                'status' => (string)$p['status'],
                'stock' => (int)$p['stock'],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG); ?>)'>Editar</button>
            <?php if ((int)$p['stock'] > 0): ?>
            <button type="button" class="btn btn-sm btn-secondary" onclick="openReturn(<?php echo (int)$p['id']; ?>, <?php echo (int)$p['stock']; ?>, <?php echo htmlspecialchars(json_encode((string)$p['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">Devolver</button>
            <?php endif; ?>
            <?php if ((int)$p['stock'] === 0 && $active && (int)$p['units_pending'] === 0): ?>
            <form method="post" action="<?php echo $base; ?>?action=deleteProduct" style="display:inline;"
                  onsubmit="return confirm('¿Eliminar este producto del catálogo consignado?');">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php
};
$renderTableHead = function (bool $showConsignor) {
    ?>
    <thead>
        <tr>
            <th>SKU</th>
            <th>Producto</th>
            <?php if ($showConsignor): ?><th>Consignante</th><?php endif; ?>
            <th class="cs-num">Stock</th>
            <th class="cs-num">Costo pactado</th>
            <th class="cs-num">Precio venta</th>
            <th class="cs-num" title="Precio de venta menos costo pactado, por unidad">Margen unit.</th>
            <th class="cs-num">Vendidas</th>
            <th class="cs-num" title="Ingreso de ventas menos costo pactado de lo vendido">Utilidad</th>
            <th class="cs-num">Por liquidar</th>
            <th>Estado</th>
            <th></th>
        </tr>
    </thead>
    <?php
};
?>
<div class="card neumorphic">
    <div class="card-header" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <h3 style="margin:0;">Catálogo consignado</h3>
        <div class="cs-toolbar">
            <a class="btn <?php echo $groupByConsignor ? 'btn-secondary' : 'btn-primary'; ?> btn-sm" href="<?php echo $base . $qs(array_merge(['tab' => 'productos'], $filterQuery)); ?>">Lista</a>
            <a class="btn <?php echo $groupByConsignor ? 'btn-primary' : 'btn-secondary'; ?> btn-sm" href="<?php echo $base . $qs(array_merge(['tab' => 'productos', 'group' => 1], $filterQuery)); ?>">Agrupar por consignante</a>
            <?php if ($canExport): ?>
                <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $base . $qs(array_merge(['action' => 'export', 'type' => 'productos', 'format' => 'csv'], $filterQuery)); ?>" title="Descargar CSV con los filtros aplicados">CSV</a>
                <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $base . $qs(array_merge(['action' => 'export', 'type' => 'productos', 'format' => 'pdf', 'group' => $groupByConsignor ? 1 : 0], $filterQuery)); ?>" title="Descargar PDF con los filtros aplicados<?php echo $groupByConsignor ? ' (agrupado por consignante)' : ''; ?>">PDF</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="get" action="<?php echo $base; ?>" class="cs-filters" style="margin-bottom:14px;">
            <input type="hidden" name="tab" value="productos">
            <?php if ($groupByConsignor): ?><input type="hidden" name="group" value="1"><?php endif; ?>
            <div>
                <label>Consignante</label>
                <select name="consignor_id" class="form-control" style="min-width:190px;">
                    <option value="0">Todos</option>
                    <?php foreach ($consignors as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $pf['consignor_id'] === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)$c['name']); ?><?php echo ($c['status'] ?? '') !== 'active' ? ' (inactivo)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Estado</label>
                <select name="status" class="form-control">
                    <option value="active" <?php echo $pf['status'] === 'active' ? 'selected' : ''; ?>>Activos</option>
                    <option value="inactive" <?php echo $pf['status'] === 'inactive' ? 'selected' : ''; ?>>Inactivos</option>
                    <option value="all" <?php echo $pf['status'] === 'all' ? 'selected' : ''; ?>>Todos</option>
                </select>
            </div>
            <div>
                <label>Stock / ventas</label>
                <select name="stock_state" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach ($CS::STOCK_STATE_LABELS as $k => $label): ?>
                        <option value="<?php echo $k; ?>" <?php echo $pf['stock_state'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Ordenar por</label>
                <select name="sort" class="form-control">
                    <?php foreach ($CS::SORT_LABELS as $k => $label): ?>
                        <option value="<?php echo $k; ?>" <?php echo $pf['sort'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:180px;">
                <label>Buscar</label>
                <input type="search" name="q" value="<?php echo htmlspecialchars($pf['q']); ?>" class="form-control" placeholder="SKU, nombre, código de barras…" style="width:100%;">
            </div>
            <button class="btn btn-secondary" type="submit">Filtrar</button>
            <?php if ($isFiltered): ?>
                <a class="btn btn-secondary" href="<?php echo $base . $qs(['tab' => 'productos', 'group' => $groupByConsignor ? 1 : 0]); ?>">Limpiar</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($products)): ?>
        <div class="cs-kpis">
            <div class="cs-kpi"><span>Productos</span><strong><?php echo count($products); ?></strong><small><?php echo (int)$kpi['stock']; ?> und. en tienda</small></div>
            <div class="cs-kpi"><span>Stock a costo pactado</span><strong><?php echo htmlspecialchars($fmt($kpi['value_cost'])); ?></strong><small>No es inventario propio</small></div>
            <div class="cs-kpi"><span>Stock a precio venta</span><strong><?php echo htmlspecialchars($fmt($kpi['value_sale'])); ?></strong><small>Margen potencial <?php echo htmlspecialchars($fmt($kpi['value_sale'] - $kpi['value_cost'])); ?></small></div>
            <div class="cs-kpi"><span>Vendido (histórico)</span><strong><?php echo htmlspecialchars($fmt($kpi['revenue'])); ?></strong><small><?php echo (int)$kpi['sold']; ?> und.</small></div>
            <div class="cs-kpi"><span>Utilidad</span><strong class="<?php echo $marginClass($kpiMargin); ?>"><?php echo htmlspecialchars($fmt($kpiProfit)); ?></strong><small>Margen <?php echo $pctFmt($kpiMargin); ?></small></div>
            <div class="cs-kpi"><span>Por liquidar</span><strong class="cs-warn"><?php echo htmlspecialchars($fmt($kpi['pending'])); ?></strong><small><?php echo (int)$kpi['pending_units']; ?> und. vendidas sin pagar</small></div>
        </div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:28px;">
                <?php if ($isFiltered): ?>
                    No hay productos consignados con esos filtros.
                <?php else: ?>
                    No hay productos consignados. Seleccione un proveedor activo al crear el primer producto.
                    <?php if (empty($suppliers)): ?>
                        <br><a href="<?php echo $viewInstance->route('app/proveedores'); ?>">Registrar proveedores</a> en el módulo de Proveedores.
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        <?php elseif ($groupByConsignor): ?>
            <?php foreach ($productGroups as $g): ?>
            <div class="cs-group">
                <div class="cs-group-head">
                    <div>
                        <h4><?php echo htmlspecialchars($g['consignor_name']); ?></h4>
                        <span class="cs-chip"><?php echo (int)$g['product_count']; ?> producto(s)</span>
                        <span class="cs-chip"><?php echo (int)$g['stock']; ?> und. en tienda · <?php echo htmlspecialchars($fmt($g['stock_value_cost'])); ?></span>
                        <span class="cs-chip"><?php echo (int)$g['units_sold']; ?> vendidas · <?php echo htmlspecialchars($fmt($g['revenue'])); ?></span>
                        <span class="cs-chip <?php echo ($g['margin_pct'] ?? 0) < 0 ? 'cs-chip-warn' : 'cs-chip-ok'; ?>">Utilidad <?php echo htmlspecialchars($fmt($g['profit'])); ?> (<?php echo $pctFmt($g['margin_pct']); ?>)</span>
                        <?php if ($g['settled_count'] > 0): ?>
                            <span class="cs-chip">Pagado <?php echo htmlspecialchars($fmt($g['settled_amount'])); ?> en <?php echo (int)$g['settled_count']; ?> liquidación(es)<?php echo $g['last_settlement'] ? ' · última ' . date('d/m/Y', strtotime((string)$g['last_settlement'])) : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cs-toolbar">
                        <?php if ($g['units_pending'] > 0): ?>
                            <span class="cs-settle-total">Por liquidar <?php echo htmlspecialchars($fmt($g['amount_pending'])); ?> · <?php echo (int)$g['units_pending']; ?> und.</span>
                            <?php if ($canEdit && $g['consignor_id'] > 0): ?>
                                <a class="btn btn-primary btn-sm" href="<?php echo $base . $qs(['tab' => 'liquidaciones', 'consignor_id' => $g['consignor_id']]); ?>">Liquidar</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="cs-chip cs-chip-ok">Al día</span>
                        <?php endif; ?>
                        <a class="btn btn-secondary btn-sm" href="<?php echo $base . $qs(['tab' => 'margen', 'consignor_id' => $g['consignor_id']]); ?>">Margen</a>
                        <a class="btn btn-secondary btn-sm" href="<?php echo $base . $qs(['tab' => 'trazabilidad', 'consignor_id' => $g['consignor_id'], 'from' => 'all']); ?>">Trazabilidad</a>
                    </div>
                </div>
                <div class="table-container">
                    <table class="cs-table">
                        <?php $renderTableHead(false); ?>
                        <tbody>
                        <?php foreach ($g['products'] as $p) { $renderProductRow($p, false); } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="table-container">
            <table class="cs-table">
                <?php $renderTableHead(true); ?>
                <tbody>
                <?php foreach ($products as $p) { $renderProductRow($p, true); } ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="cs-note">Utilidad = ingreso de ventas (con IVA y neto de descuentos, igual que en Ventas) − costo pactado con el consignante. Excluye ventas anuladas y unidades devueltas por nota crédito.</p>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'margen'): ?>
<?php
$mf = $marginFilters;
$mt = $margin['totals'] ?? ['sales_count' => 0, 'units_sold' => 0, 'revenue' => 0, 'cost_sold' => 0, 'profit' => 0, 'margin_pct' => null, 'amount_pending' => 0];
$mRows = $mf['group'] === 'consignor' ? ($margin['by_consignor'] ?? []) : ($margin['rows'] ?? []);
$maxRevenue = 0.0;
foreach ($mRows as $r) {
    $maxRevenue = max($maxRevenue, (float)$r['revenue']);
}
$marginQuery = ['from' => $mf['from'], 'to' => $mf['to'], 'consignor_id' => $mf['consignor_id'], 'q' => $mf['q'], 'group' => $mf['group'] === 'consignor' ? 'consignor' : ''];
?>
<div class="card neumorphic">
    <div class="card-header" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <h3 style="margin:0;">Margen de venta de productos consignados</h3>
        <?php if ($canExport): ?>
        <div class="cs-toolbar">
            <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $base . $qs(array_merge(['action' => 'export', 'type' => 'margen', 'format' => 'csv'], $marginQuery)); ?>">CSV</a>
            <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $base . $qs(array_merge(['action' => 'export', 'type' => 'margen', 'format' => 'pdf'], $marginQuery)); ?>">PDF</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="get" action="<?php echo $base; ?>" class="cs-filters" style="margin-bottom:14px;">
            <input type="hidden" name="tab" value="margen">
            <div><label>Desde</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($mf['from']); ?>"></div>
            <div><label>Hasta</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($mf['to']); ?>"></div>
            <div>
                <label>Consignante</label>
                <select name="consignor_id" class="form-control" style="min-width:190px;">
                    <option value="0">Todos</option>
                    <?php foreach ($consignors as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $mf['consignor_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Ver por</label>
                <select name="group" class="form-control">
                    <option value="product" <?php echo $mf['group'] === 'product' ? 'selected' : ''; ?>>Producto</option>
                    <option value="consignor" <?php echo $mf['group'] === 'consignor' ? 'selected' : ''; ?>>Consignante</option>
                </select>
            </div>
            <div style="flex:1;min-width:160px;"><label>Buscar</label><input type="search" name="q" class="form-control" style="width:100%;" value="<?php echo htmlspecialchars($mf['q']); ?>" placeholder="Producto, SKU o factura…"></div>
            <button class="btn btn-secondary" type="submit">Filtrar</button>
        </form>

        <div class="cs-kpis">
            <div class="cs-kpi"><span>Ventas</span><strong><?php echo (int)$mt['sales_count']; ?></strong><small><?php echo (int)$mt['units_sold']; ?> und. consignadas</small></div>
            <div class="cs-kpi"><span>Ingreso</span><strong><?php echo htmlspecialchars($fmt($mt['revenue'])); ?></strong><small>Con IVA, neto de descuentos</small></div>
            <div class="cs-kpi"><span>Costo pactado</span><strong><?php echo htmlspecialchars($fmt($mt['cost_sold'])); ?></strong><small>A pagar a consignantes</small></div>
            <div class="cs-kpi"><span>Utilidad</span><strong class="<?php echo $marginClass($mt['margin_pct']); ?>"><?php echo htmlspecialchars($fmt($mt['profit'])); ?></strong><small>Margen <?php echo $pctFmt($mt['margin_pct']); ?></small></div>
            <div class="cs-kpi"><span>Aún por liquidar</span><strong class="cs-warn"><?php echo htmlspecialchars($fmt($mt['amount_pending'])); ?></strong><small>De las ventas del periodo</small></div>
        </div>

        <?php if (empty($mRows)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">No hay ventas de productos consignados en el periodo.</p>
        <?php else: ?>
        <div class="table-container">
            <table class="cs-table">
                <thead>
                    <tr>
                        <?php if ($mf['group'] === 'consignor'): ?>
                            <th>Consignante</th><th class="cs-num">Productos</th>
                        <?php else: ?>
                            <th>SKU</th><th>Producto</th><th>Consignante</th><th class="cs-num">Ventas</th>
                        <?php endif; ?>
                        <th class="cs-num">Unidades</th>
                        <th class="cs-num">Ingreso</th>
                        <th class="cs-num">Costo pactado</th>
                        <th class="cs-num">Utilidad</th>
                        <th class="cs-num">Margen</th>
                        <th>Participación</th>
                        <th class="cs-num">Por liquidar</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mRows as $r): ?>
                    <tr>
                        <?php if ($mf['group'] === 'consignor'): ?>
                            <td><strong><?php echo htmlspecialchars((string)$r['consignor_name']); ?></strong></td>
                            <td class="cs-num"><?php echo (int)$r['product_count']; ?></td>
                        <?php else: ?>
                            <td><?php echo htmlspecialchars((string)$r['product_code']); ?></td>
                            <td class="cs-wrap"><strong><?php echo htmlspecialchars((string)$r['product_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars((string)$r['consignor_name']); ?></td>
                            <td class="cs-num"><?php echo (int)$r['sales_count']; ?></td>
                        <?php endif; ?>
                        <td class="cs-num"><?php echo (int)$r['units_sold']; ?></td>
                        <td class="cs-num"><?php echo htmlspecialchars($fmt($r['revenue'])); ?></td>
                        <td class="cs-num"><?php echo htmlspecialchars($fmt($r['cost_sold'])); ?></td>
                        <td class="cs-num"><strong class="<?php echo $marginClass($r['margin_pct']); ?>"><?php echo htmlspecialchars($fmt($r['profit'])); ?></strong></td>
                        <td class="cs-num <?php echo $marginClass($r['margin_pct']); ?>"><?php echo $pctFmt($r['margin_pct']); ?></td>
                        <td><div class="cs-bar" title="<?php echo $maxRevenue > 0 ? round($r['revenue'] / max(0.01, (float)$mt['revenue']) * 100, 1) : 0; ?>% del ingreso"><i style="width:<?php echo $maxRevenue > 0 ? round($r['revenue'] / $maxRevenue * 100) : 0; ?>%;"></i></div></td>
                        <td class="cs-num"><?php echo (float)$r['amount_pending'] > 0.009 ? '<span class="cs-warn">' . htmlspecialchars($fmt($r['amount_pending'])) . '</span>' : '<span class="cs-sub">—</span>'; ?></td>
                        <td class="table-actions">
                            <?php if ($mf['group'] === 'consignor'): ?>
                                <?php if ($canEdit && (float)$r['amount_pending'] > 0.009): ?>
                                    <a class="btn btn-sm btn-primary" href="<?php echo $base . $qs(['tab' => 'liquidaciones', 'consignor_id' => (int)$r['consignor_id']]); ?>">Liquidar</a>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo $base . $qs(['tab' => 'margen', 'from' => $mf['from'], 'to' => $mf['to'], 'consignor_id' => (int)$r['consignor_id']]); ?>">Productos</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-secondary" href="<?php echo $base . $qs(['tab' => 'trazabilidad', 'product_id' => (int)$r['product_id'], 'from' => $mf['from'], 'to' => $mf['to']]); ?>">Kardex</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:700;">
                    <?php if ($mf['group'] === 'consignor'): ?>
                        <td>Total</td><td></td>
                    <?php else: ?>
                        <td colspan="3">Total</td><td class="cs-num"><?php echo (int)$mt['sales_count']; ?></td>
                    <?php endif; ?>
                    <td class="cs-num"><?php echo (int)$mt['units_sold']; ?></td>
                    <td class="cs-num"><?php echo htmlspecialchars($fmt($mt['revenue'])); ?></td>
                    <td class="cs-num"><?php echo htmlspecialchars($fmt($mt['cost_sold'])); ?></td>
                    <td class="cs-num <?php echo $marginClass($mt['margin_pct']); ?>"><?php echo htmlspecialchars($fmt($mt['profit'])); ?></td>
                    <td class="cs-num <?php echo $marginClass($mt['margin_pct']); ?>"><?php echo $pctFmt($mt['margin_pct']); ?></td>
                    <td></td>
                    <td class="cs-num"><?php echo htmlspecialchars($fmt($mt['amount_pending'])); ?></td>
                    <td></td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="cs-note">Mismo criterio de utilidad que en Ventas: ingreso con IVA y neto de descuentos menos el costo pactado con el consignante. Excluye ventas anuladas y unidades devueltas por nota crédito.</p>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'trazabilidad'): ?>
<?php
$tf = $traceFilters;
$traceRows = $trace['rows'] ?? [];
$traceSum = [];
foreach ($traceRows as $r) {
    $k = $r['kind'];
    $traceSum[$k] ??= ['label' => $r['kind_label'], 'count' => 0, 'qty' => 0, 'amount' => 0.0];
    $traceSum[$k]['count']++;
    $traceSum[$k]['qty'] += abs((int)$r['qty']);
    $traceSum[$k]['amount'] += (float)$r['amount'];
}
$traceExportHref = fn(string $format) => $base . '?' . htmlspecialchars(http_build_query([
    'action' => 'export', 'type' => 'trazabilidad', 'format' => $format,
    'from' => $tf['from'], 'to' => $tf['to'], 'consignor_id' => $tf['consignor_id'],
    'product_id' => $tf['product_id'], 'mtype' => $tf['type'], 'q' => $tf['q'],
]));
?>
<div class="card neumorphic">
    <div class="card-header" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <h3 style="margin:0;">Trazabilidad de mercancía consignada</h3>
        <?php if ($canExport): ?>
        <div class="cs-toolbar">
            <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $traceExportHref('csv'); ?>">CSV</a>
            <a class="btn btn-secondary btn-sm" data-export-download href="<?php echo $traceExportHref('pdf'); ?>">PDF</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="get" action="<?php echo $base; ?>" class="cs-filters" style="margin-bottom:14px;">
            <input type="hidden" name="tab" value="trazabilidad">
            <div><label>Desde</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($tf['from']); ?>"></div>
            <div><label>Hasta</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($tf['to']); ?>"></div>
            <div>
                <label>Consignante</label>
                <select name="consignor_id" class="form-control" style="min-width:170px;">
                    <option value="0">Todos</option>
                    <?php foreach ($consignors as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $tf['consignor_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Producto</label>
                <select name="product_id" class="form-control" style="min-width:200px;max-width:280px;">
                    <option value="0">Todos</option>
                    <?php foreach ($traceProducts as $tp): ?>
                        <option value="<?php echo (int)$tp['id']; ?>" <?php echo $tf['product_id'] === (int)$tp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tp['code'] . ' — ' . $tp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tipo</label>
                <select name="mtype" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach ($CS::TRACE_TYPE_LABELS as $k => $label): ?>
                        <option value="<?php echo $k; ?>" <?php echo $tf['type'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:150px;"><label>Buscar</label><input type="search" name="q" class="form-control" style="width:100%;" value="<?php echo htmlspecialchars($tf['q']); ?>" placeholder="Producto, factura, remesa…"></div>
            <button class="btn btn-secondary" type="submit">Filtrar</button>
        </form>

        <?php if ($traceSum): ?>
        <div class="cs-kpis">
            <?php foreach ($CS::TRACE_TYPE_LABELS as $k => $label): if (!isset($traceSum[$k])) continue; $s = $traceSum[$k]; ?>
                <div class="cs-kpi">
                    <span><?php echo htmlspecialchars($label); ?></span>
                    <strong><?php echo (int)$s['qty']; ?> und.</strong>
                    <small><?php echo (int)$s['count']; ?> evento(s) · <?php echo htmlspecialchars($fmt($s['amount'])); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($traceRows)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">Sin movimientos para los filtros aplicados.</p>
        <?php else: ?>
        <?php if (!empty($trace['truncated'])): ?>
            <p class="cs-note" style="margin:0 0 8px;">Se muestran los <?php echo count($traceRows); ?> eventos más recientes. Acote las fechas para ver el resto.</p>
        <?php endif; ?>
        <div class="table-container">
            <table class="cs-table">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Tipo</th><th>Producto</th><th>Consignante</th><th>Documento</th>
                        <th class="cs-num">Cant.</th><th class="cs-num">Costo und.</th><th class="cs-num">Valor</th>
                        <th class="cs-num">Stock</th><th>Usuario</th><th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($traceRows as $r): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($r['event_date'])); ?></td>
                        <td><span class="cs-type cs-type-<?php echo htmlspecialchars($r['kind']); ?>"><?php echo htmlspecialchars($r['kind_label']); ?></span></td>
                        <td class="cs-wrap">
                            <a class="cs-link" title="Ver todo el historial de este producto" href="<?php echo $base . $qs(['tab' => 'trazabilidad', 'product_id' => $r['product_id'], 'from' => 'all']); ?>"><strong><?php echo htmlspecialchars($r['product_name']); ?></strong></a>
                            <div class="cs-sub"><?php echo htmlspecialchars($r['product_code']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($r['consignor_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['document'] !== '' ? $r['document'] : '—'); ?></td>
                        <td class="cs-num" style="font-weight:600;">
                            <?php if ($r['kind'] === 'settlement'): ?>
                                <?php echo (int)$r['qty']; ?>
                            <?php else: ?>
                                <span class="<?php echo $r['qty'] < 0 ? 'cs-neg' : 'cs-pos'; ?>"><?php echo ($r['qty'] > 0 ? '+' : '') . (int)$r['qty']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="cs-num"><?php echo htmlspecialchars($fmt($r['unit_cost'])); ?></td>
                        <td class="cs-num"><?php echo htmlspecialchars($fmt($r['amount'])); ?></td>
                        <td class="cs-num"><?php echo $r['stock_after'] !== null ? (int)$r['stock_after'] : '—'; ?></td>
                        <td><?php echo htmlspecialchars($r['user_name'] !== '' ? $r['user_name'] : '—'); ?></td>
                        <td class="cs-wrap cs-sub"><?php echo htmlspecialchars($r['notes']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="cs-note">Valor = cantidad × costo pactado. En liquidaciones, la cantidad son las unidades pagadas al consignante (no mueven stock).</p>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'ingresos'): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Remesas recibidas</h3></div>
    <div class="card-body">
        <?php if (empty($receipts)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">Aún no hay ingresos de consignación.</p>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead><tr><th>Fecha</th><th>Número</th><th>Consignante</th><th>Unidades</th><th>Notas</th></tr></thead>
                <tbody>
                <?php foreach ($receipts as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$r['receipt_date']); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['receipt_number'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)$r['consignor_name']); ?></td>
                        <td><?php echo (int)$r['total_units']; ?></td>
                        <td><?php echo htmlspecialchars((string)($r['notes'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'liquidaciones'): ?>
<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-header"><h3>Vendidos pendientes de pago al consignante</h3></div>
    <div class="card-body">
        <?php if (empty($openDebts)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">No hay saldos por liquidar.</p>
        <?php else: ?>
        <form method="post" action="<?php echo $base; ?>?action=settle" id="settleForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;align-items:end;">
                <div>
                    <label>Consignante a liquidar</label>
                    <select name="consignor_id" id="settleConsignor" class="form-control" required onchange="filterDebts()">
                        <option value="">Seleccione…</option>
                        <?php
                        $ids = [];
                        foreach ($openDebts as $d) {
                            $ids[(int)$d['consignor_id']] = (string)$d['consignor_name'];
                        }
                        foreach ($ids as $cid => $cname):
                        ?>
                            <option value="<?php echo $cid; ?>" <?php echo $consignorFilter === $cid ? 'selected' : ''; ?>><?php echo htmlspecialchars($cname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Fecha</label>
                    <input type="date" name="settlement_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div>
                    <label>Método de pago</label>
                    <select name="payment_method" class="form-control">
                        <?php foreach ($paymentMethods as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars(is_array($label) ? ($label['code'] ?? $code) : $code); ?>">
                                <?php echo htmlspecialchars(is_array($label) ? ($label['label'] ?? $code) : (string)$label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1;min-width:180px;">
                    <label>Notas</label>
                    <input type="text" name="notes" class="form-control" placeholder="Opcional">
                </div>
                <span class="cs-settle-total" id="settleTotal">Seleccionado: <?php echo htmlspecialchars($fmt(0)); ?></span>
                <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary">Liquidar seleccionados</button>
                <?php endif; ?>
            </div>
            <?php if ($consignorFilter > 0): ?>
                <p class="cs-note" style="margin:0 0 10px;">
                    Mostrando solo el saldo de este consignante.
                    <a href="<?php echo $base; ?>?tab=liquidaciones">Ver todos los consignantes</a>
                </p>
            <?php endif; ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="settleAll" title="Marcar todas las líneas visibles" onchange="toggleAllDebts(this)"></th>
                            <th>Venta</th>
                            <th>Producto</th>
                            <th>Pendiente</th>
                            <th>Costo und.</th>
                            <th>Monto</th>
                            <th>Liquidar qty</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($openDebts as $d): ?>
                        <tr class="debt-row" data-consignor="<?php echo (int)$d['consignor_id']; ?>">
                            <td>
                                <input type="checkbox" class="debt-check" name="debt_ids[]" value="<?php echo (int)$d['id']; ?>"
                                       data-pending="<?php echo (int)$d['qty_pending']; ?>"
                                       data-cost="<?php echo htmlspecialchars((string)(float)$d['unit_cost']); ?>"
                                       onchange="syncSettleQty(this)">
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)($d['invoice_number'] ?? '#' . $d['sale_id'])); ?>
                                <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)($d['sale_date'] ?? '')); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars((string)$d['product_name']); ?></td>
                            <td><?php echo (int)$d['qty_pending']; ?></td>
                            <td><?php echo htmlspecialchars($fmt($d['unit_cost'])); ?></td>
                            <td><?php echo htmlspecialchars($fmt($d['amount_pending'])); ?></td>
                            <td>
                                <input type="number" min="1" max="<?php echo (int)$d['qty_pending']; ?>"
                                       name="settle_qty[<?php echo (int)$d['id']; ?>]"
                                       class="form-control settle-qty" value="<?php echo (int)$d['qty_pending']; ?>"
                                       style="width:90px;" disabled oninput="updateSettleTotal()">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'consignantes'): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Terceros consignantes</h3></div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead><tr><th>Nombre</th><th>Proveedor</th><th>Documento</th><th>Contacto</th><th>Estado</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($consignors)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--color-text-secondary);padding:24px;">Sin consignantes. Se crean automáticamente al registrar productos por proveedor.</td></tr>
                <?php endif; ?>
                <?php foreach ($consignors as $c): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$c['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars((string)($c['supplier_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars(trim(($c['document_type'] ?? '') . ' ' . ($c['document_number'] ?? '')) ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars((string)($c['phone'] ?? $c['email'] ?? '—')); ?></td>
                        <td><span class="badge <?php echo ($c['status'] ?? '') === 'active' ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars($statusLabels[$c['status']] ?? (string)$c['status']); ?></span></td>
                        <?php if ($canEdit): ?>
                        <td>
                            <button type="button" class="btn btn-sm btn-secondary" onclick='openConsignorModal(<?php echo json_encode([
                                'id' => (int)$c['id'],
                                'supplier_id' => (int)($c['supplier_id'] ?? 0),
                                'name' => (string)$c['name'],
                                'document_type' => (string)($c['document_type'] ?? ''),
                                'document_number' => (string)($c['document_number'] ?? ''),
                                'phone' => (string)($c['phone'] ?? ''),
                                'email' => (string)($c['email'] ?? ''),
                                'address' => (string)($c['address'] ?? ''),
                                'notes' => (string)($c['notes'] ?? ''),
                                'status' => (string)$c['status'],
                            ], JSON_UNESCAPED_UNICODE); ?>)'>Editar</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'historial'): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>Liquidaciones pagadas</h3></div>
    <div class="card-body">
        <?php if (empty($settlements)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:24px;">Sin liquidaciones aún.</p>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead><tr><th>Fecha</th><th>Consignante</th><th>Monto</th><th>Método</th><th>Notas</th></tr></thead>
                <tbody>
                <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$s['settlement_date']); ?></td>
                        <td><?php echo htmlspecialchars((string)$s['consignor_name']); ?></td>
                        <td><?php echo htmlspecialchars($fmt($s['amount'])); ?></td>
                        <td><?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)$s['payment_method'])); ?></td>
                        <td><?php echo htmlspecialchars((string)($s['notes'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modales -->
<div id="modalProduct" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header"><h3>Nuevo producto consignado</h3><button type="button" class="modal-close" onclick="this.closest('.modal-overlay').style.display='none'">&times;</button></div>
        <form method="post" action="<?php echo $base; ?>?action=createProduct">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body" style="display:grid;gap:10px;">
                <div>
                    <label>Proveedor *</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">Seleccione proveedor…</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                                <?php if (!empty($s['document_number'])): ?>
                                    (<?php echo htmlspecialchars((string)$s['document_number']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($suppliers)): ?>
                        <p style="font-size:12px;color:var(--dmc-B45309, #B45309);margin:6px 0 0;">
                            No hay proveedores activos.
                            <a href="<?php echo $viewInstance->route('app/proveedores'); ?>">Crear en Proveedores</a>
                        </p>
                    <?php else: ?>
                        <p style="font-size:12px;color:var(--color-text-secondary);margin:6px 0 0;">
                            Se vincula o crea el consignante automáticamente desde el proveedor.
                        </p>
                    <?php endif; ?>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div><label>SKU / código *</label><input name="code" class="form-control" required></div>
                    <div><label>Código barras</label><input name="barcode" class="form-control"></div>
                </div>
                <div><label>Nombre *</label><input name="name" class="form-control" required></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;">
                    <div><label>Costo pactado *</label><input type="number" step="0.01" min="0" name="unit_cost" class="form-control" required></div>
                    <div><label>Precio venta *</label><input type="number" step="0.01" min="0" name="sale_price" class="form-control" required></div>
                    <div>
                        <label>IVA venta (%) <span class="field-tip" data-tip="0 = exento. Si deja vacío al crear, usa la tasa de Configuración." tabindex="0">?</span></label>
                        <input type="number" name="tax_rate" class="form-control" min="0" max="100" step="0.01"
                               placeholder="<?php echo htmlspecialchars((string)($defaultTaxRate ?? 0)); ?>" value="">
                    </div>
                    <div><label>Stock inicial</label><input type="number" min="0" name="initial_qty" class="form-control" value="0"></div>
                </div>
                <div><label>Notas</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalProduct').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div id="modalEditProduct" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header"><h3>Editar producto consignado</h3><button type="button" class="modal-close" onclick="this.closest('.modal-overlay').style.display='none'">&times;</button></div>
        <form method="post" action="<?php echo $base; ?>?action=editProduct">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="editProdId">
            <div class="modal-body" style="display:grid;gap:10px;">
                <div><label>Nombre</label><input name="name" id="editProdName" class="form-control" required></div>
                <div><label>Código barras</label><input name="barcode" id="editProdBarcode" class="form-control"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;">
                    <div><label>Costo pactado</label><input type="number" step="0.01" name="unit_cost" id="editProdCost" class="form-control"></div>
                    <div><label>Precio venta</label><input type="number" step="0.01" name="sale_price" id="editProdSale" class="form-control"></div>
                    <div>
                        <label>IVA venta (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="tax_rate" id="editProdTax" class="form-control"
                               placeholder="<?php echo htmlspecialchars((string)($defaultTaxRate ?? 0)); ?>">
                    </div>
                    <div>
                        <label>Stock</label>
                        <input type="number" min="0" name="stock" id="editProdStock" class="form-control">
                        <p style="font-size:11px;color:var(--color-text-secondary);margin:4px 0 0;">Ajuste directo (ingreso o devolución automática).</p>
                    </div>
                </div>
                <div>
                    <label>Estado</label>
                    <select name="status" id="editProdStatus" class="form-control">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
                <div><label>Notas</label><textarea name="notes" id="editProdNotes" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditProduct').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div id="modalReceive" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:460px;">
        <div class="modal-header"><h3>Ingreso de remesa</h3><button type="button" class="modal-close" onclick="this.closest('.modal-overlay').style.display='none'">&times;</button></div>
        <form method="post" action="<?php echo $base; ?>?action=receive">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body" style="display:grid;gap:10px;">
                <div>
                    <label>Producto consignado *</label>
                    <select name="product_id" class="form-control" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($receiveProducts as $p): if (($p['status'] ?? '') !== 'active') continue; ?>
                            <option value="<?php echo (int)$p['id']; ?>" data-cost="<?php echo htmlspecialchars((string)$p['purchase_price']); ?>">
                                <?php echo htmlspecialchars($p['code'] . ' — ' . $p['name'] . ' (' . ($p['consignor_name'] ?? '') . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                    <div><label>Cantidad *</label><input type="number" min="1" name="quantity" class="form-control" required></div>
                    <div><label>Costo und.</label><input type="number" step="0.01" min="0" name="unit_cost" class="form-control"></div>
                    <div><label>Fecha</label><input type="date" name="receipt_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                </div>
                <div><label>Notas</label><input type="text" name="notes" class="form-control"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalReceive').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar ingreso</button>
            </div>
        </form>
    </div>
</div>

<div id="modalReturn" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header"><h3>Devolver a consignante</h3><button type="button" class="modal-close" onclick="this.closest('.modal-overlay').style.display='none'">&times;</button></div>
        <form method="post" action="<?php echo $base; ?>?action=returnStock">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="product_id" id="returnProductId">
            <div class="modal-body" style="display:grid;gap:10px;">
                <p id="returnProductName" style="margin:0;"></p>
                <div><label>Cantidad</label><input type="number" min="1" name="quantity" id="returnQty" class="form-control" required></div>
                <div><label>Notas</label><input type="text" name="notes" class="form-control"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalReturn').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Devolver</button>
            </div>
        </form>
    </div>
</div>

<div id="modalConsignor" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:480px;">
        <div class="modal-header"><h3 id="consignorModalTitle">Consignante</h3><button type="button" class="modal-close" onclick="this.closest('.modal-overlay').style.display='none'">&times;</button></div>
        <form method="post" action="<?php echo $base; ?>?action=saveConsignor">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="consignorId" value="">
            <input type="hidden" name="supplier_id" id="consignorSupplierId" value="">
            <div class="modal-body" style="display:grid;gap:10px;">
                <div>
                    <label>Vincular proveedor existente</label>
                    <select id="consignorSupplierPick" class="form-control" onchange="fillConsignorFromSupplier(this)">
                        <option value="">— Opcional: elegir del catálogo —</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                data-name="<?php echo htmlspecialchars((string)$s['name'], ENT_QUOTES); ?>"
                                data-document-type="<?php echo htmlspecialchars((string)($s['document_type'] ?? ''), ENT_QUOTES); ?>"
                                data-document-number="<?php echo htmlspecialchars((string)($s['document_number'] ?? ''), ENT_QUOTES); ?>"
                                data-phone="<?php echo htmlspecialchars((string)($s['phone'] ?? ''), ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars((string)($s['email'] ?? ''), ENT_QUOTES); ?>"
                                data-address="<?php echo htmlspecialchars((string)($s['address'] ?? ''), ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Nombre *</label><input name="name" id="consignorName" class="form-control" required></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div><label>Tipo doc.</label><input name="document_type" id="consignorDocType" class="form-control" placeholder="CC / NIT"></div>
                    <div><label>Número</label><input name="document_number" id="consignorDocNum" class="form-control"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div><label>Teléfono</label><input name="phone" id="consignorPhone" class="form-control"></div>
                    <div><label>Email</label><input type="email" name="email" id="consignorEmail" class="form-control"></div>
                </div>
                <div><label>Dirección</label><input name="address" id="consignorAddress" class="form-control"></div>
                <div>
                    <label>Estado</label>
                    <select name="status" id="consignorStatus" class="form-control">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
                <div><label>Notas</label><textarea name="notes" id="consignorNotes" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalConsignor').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
function editProduct(p) {
    document.getElementById('editProdId').value = p.id;
    document.getElementById('editProdName').value = p.name || '';
    document.getElementById('editProdBarcode').value = p.barcode || '';
    document.getElementById('editProdCost').value = p.unit_cost || 0;
    document.getElementById('editProdSale').value = p.sale_price || 0;
    var taxEl = document.getElementById('editProdTax');
    if (taxEl) taxEl.value = (p.tax_rate != null && p.tax_rate !== '') ? p.tax_rate : 0;
    document.getElementById('editProdStock').value = p.stock != null ? p.stock : 0;
    document.getElementById('editProdNotes').value = p.notes || '';
    document.getElementById('editProdStatus').value = p.status || 'active';
    document.getElementById('modalEditProduct').style.display = 'flex';
}
function openReturn(id, stock, name) {
    document.getElementById('returnProductId').value = id;
    document.getElementById('returnProductName').textContent = name + ' (stock: ' + stock + ')';
    document.getElementById('returnQty').max = stock;
    document.getElementById('returnQty').value = Math.min(1, stock);
    document.getElementById('modalReturn').style.display = 'flex';
}
function openConsignorModal(c) {
    c = c || null;
    document.getElementById('consignorModalTitle').textContent = c ? 'Editar consignante' : 'Nuevo consignante';
    document.getElementById('consignorId').value = c ? c.id : '';
    document.getElementById('consignorSupplierId').value = c ? (c.supplier_id || '') : '';
    document.getElementById('consignorSupplierPick').value = c && c.supplier_id ? String(c.supplier_id) : '';
    document.getElementById('consignorName').value = c ? (c.name || '') : '';
    document.getElementById('consignorDocType').value = c ? (c.document_type || '') : '';
    document.getElementById('consignorDocNum').value = c ? (c.document_number || '') : '';
    document.getElementById('consignorPhone').value = c ? (c.phone || '') : '';
    document.getElementById('consignorEmail').value = c ? (c.email || '') : '';
    document.getElementById('consignorAddress').value = c ? (c.address || '') : '';
    document.getElementById('consignorNotes').value = c ? (c.notes || '') : '';
    document.getElementById('consignorStatus').value = c ? (c.status || 'active') : 'active';
    document.getElementById('modalConsignor').style.display = 'flex';
}
function fillConsignorFromSupplier(sel) {
    var opt = sel.options[sel.selectedIndex];
    var sid = sel.value || '';
    document.getElementById('consignorSupplierId').value = sid;
    if (!sid) return;
    document.getElementById('consignorName').value = opt.getAttribute('data-name') || '';
    document.getElementById('consignorDocType').value = opt.getAttribute('data-document-type') || '';
    document.getElementById('consignorDocNum').value = opt.getAttribute('data-document-number') || '';
    document.getElementById('consignorPhone').value = opt.getAttribute('data-phone') || '';
    document.getElementById('consignorEmail').value = opt.getAttribute('data-email') || '';
    document.getElementById('consignorAddress').value = opt.getAttribute('data-address') || '';
}
var CS_CURRENCY = <?php echo json_encode(explode(' ', $fmt(0))[0] ?? '$', JSON_UNESCAPED_UNICODE); ?>;
function syncSettleQty(cb) {
    var row = cb.closest('tr');
    var qty = row.querySelector('.settle-qty');
    qty.disabled = !cb.checked;
    updateSettleTotal();
}
function updateSettleTotal() {
    var el = document.getElementById('settleTotal');
    if (!el) return;
    var total = 0, units = 0;
    document.querySelectorAll('.debt-check:checked').forEach(function (cb) {
        var input = cb.closest('tr').querySelector('.settle-qty');
        var pending = parseInt(cb.getAttribute('data-pending'), 10) || 0;
        var q = Math.min(Math.max(parseInt(input && input.value, 10) || 0, 0), pending);
        units += q;
        total += q * (parseFloat(cb.getAttribute('data-cost')) || 0);
    });
    el.textContent = 'Seleccionado: ' + CS_CURRENCY + ' ' + Math.round(total).toLocaleString('es-CO') + (units ? ' · ' + units + ' und.' : '');
}
function toggleAllDebts(master) {
    var cid = document.getElementById('settleConsignor').value;
    if (master.checked && !cid) {
        master.checked = false;
        alert('Seleccione primero el consignante a liquidar.');
        return;
    }
    document.querySelectorAll('.debt-row').forEach(function (row) {
        if (row.style.display === 'none') return;
        var cb = row.querySelector('.debt-check');
        if (cb) { cb.checked = master.checked; syncSettleQty(cb); }
    });
}
function filterDebts() {
    var cid = document.getElementById('settleConsignor').value;
    var all = document.getElementById('settleAll');
    if (all) all.checked = false;
    document.querySelectorAll('.debt-row').forEach(function(row) {
        var match = !cid || row.getAttribute('data-consignor') === cid;
        row.style.display = match ? '' : 'none';
        if (!match) {
            var cb = row.querySelector('.debt-check');
            if (cb) { cb.checked = false; syncSettleQty(cb); }
        }
    });
    updateSettleTotal();
}
(function () {
    var sel = document.getElementById('settleConsignor');
    if (sel && sel.value) filterDebts();
})();

/* ── Exportar CSV/PDF sin abrir pestañas en blanco ── */
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
        if (link.dataset.busy === '1') return;
        var original = link.textContent;
        link.dataset.busy = '1';
        link.classList.add('disabled');
        link.textContent = 'Generando…';
        fetch(link.href, { credentials: 'same-origin' })
            .then(function (res) {
                var type = (res.headers.get('Content-Type') || '').toLowerCase();
                var isFile = type.indexOf('application/pdf') !== -1 || type.indexOf('text/csv') !== -1;
                if (!res.ok || !isFile) {
                    return res.text().then(function (txt) {
                        throw new Error(type.indexOf('text/plain') !== -1 && txt.trim() !== ''
                            ? txt.trim().slice(0, 300)
                            : 'No se pudo generar el archivo (sesión expirada o sin permiso). Recargue la página e intente de nuevo.');
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
            .catch(function (err) { notify(err && err.message ? err.message : 'Error de red al exportar', 'error'); })
            .finally(function () {
                link.dataset.busy = '';
                link.classList.remove('disabled');
                link.textContent = original;
            });
    });
})();
</script>
