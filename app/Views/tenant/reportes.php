<?php
$layout = 'tenant';
$title = 'Reportes - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Analíticas y Reportes';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$currency = $currency ?? ['symbol'=>'$','decimals'=>2,'decimal'=>',','thousands'=>'.'];
$reportsFull = !empty($reportsFull);
$plan = $plan ?? ['plan_name' => 'Plan Basico', 'upgrade_plans' => 'Pro o Premium', 'tier' => 'basic'];
$chartType = in_array(($chartType ?? 'line'), ['line','bar','pie','doughnut'], true) ? $chartType : 'line';
$salesByMonth = $salesByMonth ?? [];
$salesByDay = $salesByDay ?? [];
$topProducts = $topProducts ?? [];
$profitability = $profitability ?? [];
$salesByCategory = $salesByCategory ?? [];
$topCustomers = $topCustomers ?? [];
$paymentMethods = $paymentMethods ?? [];
$productTrend = $productTrend ?? [];
$lowStockProducts = $lowStockProducts ?? [];
$stockMovements = $stockMovements ?? [];
$inventoryByMonth = $inventoryByMonth ?? [];
$inventorySalesByMonth = $inventorySalesByMonth ?? [];
$totalSales = $totalSales ?? 0;
$totalSalesCount = $totalSalesCount ?? 0;
$totalProducts = $totalProducts ?? 0;
$totalCustomers = $totalCustomers ?? 0;
$lowStock = $lowStock ?? 0;
$avgTicket = $avgTicket ?? 0;
$salesTrend = $salesTrend ?? 0;
$ticketTrend = $ticketTrend ?? 0;
$dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $dateTo ?? date('Y-m-d');
$profitSummary = $profitSummary ?? ['revenue'=>0,'cost'=>0,'gross'=>0,'expenses'=>0,'expenses_fixed'=>0,'expenses_financial'=>0,'expenses_operating'=>0,'net'=>0,'margin'=>0];
$expenseBreakdown = $expenseBreakdown ?? ['by_group'=>[], 'by_category'=>[], 'fixed'=>0,'financial'=>0,'operating'=>0,'total'=>0];
$commissionSummary = $commissionSummary ?? ['pending'=>0,'paid'=>0];
$marginByChannel = $marginByChannel ?? [];
$dataphoneRecon = $dataphoneRecon ?? [
    'sales_total' => 0, 'expected_total' => 0, 'recorded_total' => 0, 'gap' => 0,
    'dataphone_sales' => 0, 'card_sales' => 0, 'rate_dataphone' => 2.5, 'rate_card' => 2.8,
    'suggest_amount' => 0, 'suggest_description' => '', 'suggest_category' => 'dataphone_commission',
];
$channelLabels = \SoftNova\Core\product_channels();
$receivablesAging = $receivablesAging ?? ['total'=>0,'d0'=>0,'d30'=>0,'d60'=>0,'d90'=>0,'cnt'=>0];
$topDebtors = $topDebtors ?? [];
$cashSummary = $cashSummary ?? ['income'=>0,'expense'=>0,'net'=>0,'opening'=>0,'expected'=>0,'session'=>null];

if (!function_exists('fmtR')) {
    function fmtR(float $a, array $c): string {
        return $c['symbol'].' '.number_format($a, $c['decimals'], $c['decimal']??',', $c['thousands']??'.');
    }
}
if (!function_exists('trendArrow')) {
    function trendArrow(float $pct): string { return $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '→'); }
}
if (!function_exists('trendColor')) {
    function trendColor(float $pct): string { return $pct > 0 ? '#10B981' : ($pct < 0 ? '#DC2626' : '#64748B'); }
}

$salesMonthsLabels = []; $salesMonthsData = [];
foreach ($salesByMonth as $m) { $salesMonthsLabels[] = date('M Y', strtotime($m['month'].'-01')); $salesMonthsData[] = (float)$m['total']; }

$salesDaysLabels = []; $salesDaysData = [];
foreach ($salesByDay as $d) { $salesDaysLabels[] = date('d/m', strtotime($d['day'])); $salesDaysData[] = (float)$d['total']; }

$topProductsLabels = []; $topProductsData = []; $topProductsColors = [];
$colors = ['#0D7C4A','#10B981','#34D399','#6EE7B7','#A7F3D0','#059669','#047857','#065F46','#7C3AED','#8B5CF6'];
foreach ($topProducts as $i => $p) { $topProductsLabels[] = $p['name']; $topProductsData[] = (int)$p['total_qty']; $topProductsColors[] = $colors[$i % count($colors)]; }

$profitLabels = []; $profitData = []; $profitColors = [];
foreach ($profitability as $i => $p) { $profitLabels[] = $p['name']; $profitData[] = (float)$p['profit']; $profitColors[] = $p['profit'] >= 0 ? '#10B981' : '#DC2626'; }

$catLabels = []; $catData = [];
foreach ($salesByCategory as $cat) { $catLabels[] = $cat['category']; $catData[] = (float)$cat['total']; }

$channelChartLabels = []; $channelChartData = [];
foreach ($marginByChannel as $ch) {
    $channelChartLabels[] = $channelLabels[$ch['channel']] ?? $ch['channel'];
    $channelChartData[] = (float)$ch['profit'];
}

$payMethodLabels = []; $payMethodData = [];
$payNames = ['cash'=>'Efectivo','card'=>'Tarjeta','transfer'=>'Transferencia','credit'=>'Credito','other'=>'Otro','dataphone'=>'Datafono'];
foreach ($paymentMethods as $pm) { $payMethodLabels[] = $payNames[$pm['payment_method']] ?? $pm['payment_method']; $payMethodData[] = (int)$pm['count']; }

$trendLabels = []; $trendCurrent = []; $trendLast = [];
foreach ($productTrend as $t) { $trendLabels[] = $t['name']; $trendCurrent[] = (int)$t['current_month']; $trendLast[] = (int)$t['last_month']; }

$invMonthLabels = []; $invInData = []; $invOutData = []; $invSoldData = [];
$salesMonthMap = [];
foreach ($inventorySalesByMonth as $row) { $salesMonthMap[$row['month']] = $row; }
foreach ($inventoryByMonth as $row) {
    $invMonthLabels[] = date('M Y', strtotime($row['month'].'-01'));
    $invInData[] = (int)$row['qty_in'];
    $invOutData[] = (int)$row['qty_out'];
    $invSoldData[] = (int)($salesMonthMap[$row['month']]['units_sold'] ?? 0);
}

$upgradeLabel = htmlspecialchars($plan['upgrade_plans'] ?? 'Pro o Premium');
$planName = htmlspecialchars($plan['plan_name'] ?? 'Plan Basico');
$reportBase = $viewInstance->route('app/reportes');
$qs = http_build_query(['from' => $dateFrom, 'to' => $dateTo]);

if (!function_exists('rptChartTypeSelect')) {
    function rptChartTypeSelect(string $chartId, array $types = ['line','bar','pie','doughnut']): string
    {
        $opts = [
            'line' => 'Línea',
            'bar' => 'Barras',
            'pie' => 'Torta',
            'doughnut' => 'Dona',
        ];
        $html = '<select class="form-control rpt-chart-type" data-chart-id="'
            . htmlspecialchars($chartId) . '" title="Tipo de gráfico" style="width:auto;min-width:110px;font-size:12px;padding:4px 8px;">';
        foreach ($types as $t) {
            if (!isset($opts[$t])) {
                continue;
            }
            $html .= '<option value="' . $t . '">' . $opts[$t] . '</option>';
        }
        return $html . '</select>';
    }
}
?>

<script src="<?php echo $viewInstance->asset('js/chart.umd.min.js'); ?>"></script>
<?php echo flashMessage(); ?>

<?php if (!$reportsFull): ?>
<div class="plan-banner neumorphic" style="margin-bottom:20px;">
    <div class="plan-banner-content">
        <div>
            <strong>Plan actual: <?php echo $planName; ?> · Reportes básicos</strong>
            <p>Resumen esencial activo. Las analíticas avanzadas se desbloquean en <?php echo $upgradeLabel; ?>.</p>
        </div>
        <a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="btn btn-primary">Actualizar plan</a>
    </div>
</div>
<?php endif; ?>

<div class="card neumorphic" style="margin-bottom:20px;">
    <div class="card-body" style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;padding:15px 20px;">
        <form method="GET" action="<?php echo $reportBase; ?>" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;flex:1;">
            <label style="font-weight:600;font-size:13px;">Periodo</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="form-control" style="width:150px;">
            <span style="color:var(--color-text-secondary);">hasta</span>
            <input type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>" class="form-control" style="width:150px;">
            <button type="submit" class="btn btn-primary">Aplicar</button>
            <a href="<?php echo $reportBase; ?>" class="btn btn-secondary" style="font-size:12px;">Reset</a>
        </form>
        <div style="display:flex;gap:8px;">
            <?php if ($reportsFull): ?>
                <a href="<?php echo $reportBase; ?>?action=export&amp;<?php echo htmlspecialchars($qs); ?>" class="btn btn-secondary" style="font-size:12px;">CSV</a>
                <a href="<?php echo $reportBase; ?>?action=pdf&amp;<?php echo htmlspecialchars($qs); ?>" class="btn btn-secondary" style="font-size:12px;" target="_blank">PDF</a>
            <?php else: ?>
                <button type="button" class="btn btn-secondary is-locked-btn" style="font-size:12px;" onclick="showAlert('Exportación disponible en <?php echo $upgradeLabel; ?>','warning')">CSV (Pro)</button>
                <button type="button" class="btn btn-secondary is-locked-btn" style="font-size:12px;" onclick="showAlert('Exportación disponible en <?php echo $upgradeLabel; ?>','warning')">PDF (Pro)</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card neumorphic">
        <h4>Ventas del periodo</h4>
        <div class="stat-value" style="color:#10B981;"><?php echo fmtR((float)$totalSales, $currency); ?></div>
        <small><?php echo (int)$totalSalesCount; ?> ventas</small>
        <?php if ($reportsFull): ?>
        <div style="margin-top:6px;font-size:12px;color:<?php echo trendColor((float)$salesTrend); ?>;">
            <?php echo trendArrow((float)$salesTrend); ?> <?php echo abs((float)$salesTrend); ?>% vs periodo anterior
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-card neumorphic">
        <h4>Ticket promedio</h4>
        <div class="stat-value" style="color:var(--color-primary);"><?php echo fmtR((float)$avgTicket, $currency); ?></div>
        <small>por venta</small>
        <?php if ($reportsFull): ?>
        <div style="margin-top:6px;font-size:12px;color:<?php echo trendColor((float)$ticketTrend); ?>;">
            <?php echo trendArrow((float)$ticketTrend); ?> <?php echo abs((float)$ticketTrend); ?>% vs periodo anterior
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-card neumorphic">
        <h4>Catálogo</h4>
        <div class="stat-value"><?php echo (int)$totalProducts; ?></div>
        <small>productos activos</small>
    </div>
    <div class="stat-card neumorphic">
        <h4>Clientes</h4>
        <div class="stat-value"><?php echo (int)$totalCustomers; ?></div>
        <small>activos</small>
    </div>
</div>

<div class="reports-section-title">Resultado financiero</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:22px;">
    <div class="card neumorphic">
        <div class="card-header"><h3>Utilidad del periodo</h3></div>
        <div class="card-body">
            <div class="rpt-row"><span>Ingresos</span><strong style="color:#10B981;"><?php echo fmtR((float)$profitSummary['revenue'], $currency); ?></strong></div>
            <div class="rpt-row"><span>− Costo de ventas</span><span><?php echo fmtR((float)$profitSummary['cost'], $currency); ?></span></div>
            <div class="rpt-row rpt-row-sep"><span>Utilidad bruta</span><strong><?php echo fmtR((float)$profitSummary['gross'], $currency); ?></strong></div>
            <div class="rpt-row muted"><span>− Gastos fijos</span><span><?php echo fmtR((float)($profitSummary['expenses_fixed'] ?? 0), $currency); ?></span></div>
            <div class="rpt-row muted"><span>− Gastos financieros</span><span><?php echo fmtR((float)($profitSummary['expenses_financial'] ?? 0), $currency); ?></span></div>
            <div class="rpt-row muted"><span>− Otros gastos</span><span><?php echo fmtR((float)($profitSummary['expenses_operating'] ?? 0), $currency); ?></span></div>
            <div class="rpt-row rpt-row-total">
                <strong>Utilidad neta</strong>
                <strong style="font-size:18px;color:<?php echo (float)$profitSummary['net'] >= 0 ? '#10B981' : '#DC2626'; ?>;">
                    <?php echo fmtR((float)$profitSummary['net'], $currency); ?>
                </strong>
            </div>
            <div style="text-align:right;font-size:12px;color:var(--color-text-secondary);">Margen: <?php echo (float)$profitSummary['margin']; ?>%</div>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <h3 style="margin:0;">Margen por canal de ventas</h3>
            <?php echo rptChartTypeSelect('chartChannel', ['bar','line','pie','doughnut']); ?>
        </div>
        <div class="card-body">
            <?php if (empty($marginByChannel)): ?>
                <p class="rpt-empty">Sin ventas en el periodo</p>
            <?php else: ?>
                <div style="height:180px;margin-bottom:10px;"><canvas id="chartChannel"></canvas></div>
                <?php foreach ($marginByChannel as $ch): ?>
                    <div class="rpt-row">
                        <span><?php echo htmlspecialchars($channelLabels[$ch['channel']] ?? $ch['channel']); ?></span>
                        <span>
                            <?php echo fmtR((float)$ch['profit'], $currency); ?>
                            <small style="color:var(--color-text-secondary);">(<?php echo (float)$ch['margin']; ?>% · <?php echo fmtR((float)$ch['revenue'], $currency); ?>)</small>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>Conciliación datáfono</h3></div>
        <div class="card-body">
            <div class="rpt-row muted"><span>Ventas datáfono</span><strong><?php echo fmtR((float)$dataphoneRecon['dataphone_sales'], $currency); ?></strong></div>
            <div class="rpt-row muted"><span>Ventas tarjeta / link</span><strong><?php echo fmtR((float)$dataphoneRecon['card_sales'], $currency); ?></strong></div>
            <div class="rpt-row muted"><span>Comisión esperada</span><strong><?php echo fmtR((float)$dataphoneRecon['expected_total'], $currency); ?></strong></div>
            <div class="rpt-row muted"><span>Gastos registrados</span><span><?php echo fmtR((float)$dataphoneRecon['recorded_total'], $currency); ?></span></div>
            <div class="rpt-row rpt-row-total">
                <strong>Brecha</strong>
                <strong style="color:<?php echo (float)$dataphoneRecon['gap'] > 0.01 ? '#DC2626' : '#10B981'; ?>;">
                    <?php echo fmtR((float)$dataphoneRecon['gap'], $currency); ?>
                </strong>
            </div>
            <?php if ((float)$dataphoneRecon['suggest_amount'] > 0.01): ?>
                <a class="btn btn-sm btn-primary" style="margin-top:10px;"
                   href="<?php echo $viewInstance->route('app/gastos'); ?>?suggest=1&amp;amount=<?php echo urlencode((string)$dataphoneRecon['suggest_amount']); ?>&amp;category=<?php echo urlencode((string)$dataphoneRecon['suggest_category']); ?>&amp;description=<?php echo urlencode((string)$dataphoneRecon['suggest_description']); ?>">
                    Registrar comisión sugerida
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="reports-section-title">Productos</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-bottom:22px;">
    <div class="card neumorphic">
        <div class="card-header"><h3>Más vendidos</h3></div>
        <div class="card-body">
            <?php if (empty($topProducts)): ?>
                <p class="rpt-empty">Sin ventas en el periodo</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>#</th><th>Producto</th><th>Unid.</th><th>Ventas</th></tr></thead>
                    <tbody>
                    <?php foreach ($topProducts as $i => $p): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><span class="badge badge-success"><?php echo (int)$p['total_qty']; ?></span></td>
                            <td><?php echo fmtR((float)$p['total_sales'], $currency); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <h3 style="margin:0;">Más rentables</h3>
            <?php echo rptChartTypeSelect('chartProfit', ['bar','line','pie','doughnut']); ?>
        </div>
        <div class="card-body">
            <?php if (empty($profitability)): ?>
                <p class="rpt-empty">Sin datos de margen</p>
            <?php else: ?>
                <div style="height:200px;margin-bottom:10px;"><canvas id="chartProfit"></canvas></div>
                <div class="table-container"><table>
                    <thead><tr><th>#</th><th>Producto</th><th>Utilidad</th><th>Margen</th></tr></thead>
                    <tbody>
                    <?php foreach ($profitability as $i => $p):
                        $rev = (float)$p['revenue'];
                        $prof = (float)$p['profit'];
                        $m = $rev > 0 ? round(($prof / $rev) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td style="color:<?php echo $prof >= 0 ? '#10B981' : '#DC2626'; ?>;font-weight:600;"><?php echo fmtR($prof, $currency); ?></td>
                            <td><?php echo $m; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="reports-section-title">Inventario por mes</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:20px;margin-bottom:22px;">
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <h3 style="margin:0;">Movimientos mensuales (12 meses)</h3>
            <?php echo rptChartTypeSelect('chartInventoryMonth', ['bar','line']); ?>
        </div>
        <div class="card-body">
            <div style="height:240px;margin-bottom:12px;"><canvas id="chartInventoryMonth"></canvas></div>
            <?php if (empty($inventoryByMonth)): ?>
                <p class="rpt-empty">Sin movimientos registrados</p>
            <?php else: ?>
                <div class="table-container" style="max-height:260px;overflow:auto;"><table>
                    <thead><tr><th>Mes</th><th>Entradas</th><th>Salidas</th><th>Ajustes</th><th>Vendidas</th></tr></thead>
                    <tbody>
                    <?php foreach ($inventoryByMonth as $row):
                        $sold = (int)($salesMonthMap[$row['month']]['units_sold'] ?? 0);
                    ?>
                        <tr>
                            <td><?php echo date('M Y', strtotime($row['month'].'-01')); ?></td>
                            <td><?php echo (int)$row['qty_in']; ?></td>
                            <td><?php echo (int)$row['qty_out']; ?></td>
                            <td><?php echo (int)$row['qty_adj']; ?></td>
                            <td><?php echo $sold; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>Stock bajo</h3></div>
        <div class="card-body">
            <?php if (empty($lowStockProducts)): ?>
                <p class="rpt-empty">Inventario en niveles saludables</p>
            <?php else: ?>
                <p style="font-size:13px;margin-top:0;"><?php echo (int)$lowStock; ?> producto(s) bajo el mínimo.</p>
                <div class="table-container"><table>
                    <thead><tr><th>Producto</th><th>Stock</th><th>Mín.</th><th>Faltante</th></tr></thead>
                    <tbody>
                    <?php foreach ($lowStockProducts as $lp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($lp['name']); ?></td>
                            <td><span class="badge badge-danger"><?php echo (int)$lp['stock']; ?></span></td>
                            <td><?php echo (int)$lp['min_stock']; ?></td>
                            <td style="color:#DC2626;font-weight:600;"><?php echo (int)$lp['min_stock'] - (int)$lp['stock']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="reports-section-title">Cobranza y caja</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:22px;">
    <div class="card neumorphic">
        <div class="card-header"><h3>Cuentas por cobrar</h3></div>
        <div class="card-body">
            <div style="text-align:center;margin-bottom:12px;">
                <div style="font-size:24px;font-weight:700;color:var(--color-primary);"><?php echo fmtR((float)$receivablesAging['total'], $currency); ?></div>
                <small style="color:var(--color-text-secondary);"><?php echo (int)$receivablesAging['cnt']; ?> facturas pendientes</small>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px;">
                <div class="rpt-row"><span>0-30 días</span><strong><?php echo fmtR((float)$receivablesAging['d0'], $currency); ?></strong></div>
                <div class="rpt-row"><span>31-60</span><strong><?php echo fmtR((float)$receivablesAging['d30'], $currency); ?></strong></div>
                <div class="rpt-row"><span>61-90</span><strong style="color:#F59E0B;"><?php echo fmtR((float)$receivablesAging['d60'], $currency); ?></strong></div>
                <div class="rpt-row"><span>+90 días</span><strong style="color:#DC2626;"><?php echo fmtR((float)$receivablesAging['d90'], $currency); ?></strong></div>
            </div>
            <?php if (!empty($topDebtors)): ?>
                <div style="margin-top:12px;border-top:1px dashed var(--color-border);padding-top:8px;">
                    <?php foreach (array_slice($topDebtors, 0, 4) as $d): ?>
                        <div class="rpt-row muted">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:55%;"><?php echo htmlspecialchars($d['customer_name']); ?></span>
                            <span><?php echo fmtR((float)$d['balance'], $currency); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>Caja de hoy</h3></div>
        <div class="card-body">
            <?php if ($cashSummary['session']): ?>
                <div class="rpt-row muted"><span>Base inicial</span><span><?php echo fmtR((float)$cashSummary['opening'], $currency); ?></span></div>
                <div class="rpt-row muted"><span>+ Ingresos</span><strong style="color:#10B981;"><?php echo fmtR((float)$cashSummary['income'], $currency); ?></strong></div>
                <div class="rpt-row muted"><span>− Egresos</span><strong style="color:#DC2626;"><?php echo fmtR((float)$cashSummary['expense'], $currency); ?></strong></div>
                <div class="rpt-row rpt-row-total"><strong>Esperado en caja</strong><strong style="color:var(--color-primary);"><?php echo fmtR((float)$cashSummary['expected'], $currency); ?></strong></div>
                <small style="color:var(--color-text-secondary);">Sesión de <?php echo htmlspecialchars($cashSummary['session']['user_name'] ?? 'Usuario'); ?></small>
            <?php else: ?>
                <p class="rpt-empty">No hay caja abierta. <a href="<?php echo $viewInstance->route('app/caja'); ?>">Abrir caja</a></p>
                <div class="rpt-row"><span>Movimientos hoy (neto)</span><strong><?php echo fmtR((float)$cashSummary['net'], $currency); ?></strong></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>Gastos y comisiones</h3></div>
        <div class="card-body">
            <?php foreach (($expenseBreakdown['by_group'] ?? []) as $g): ?>
                <div class="rpt-row"><span><?php echo htmlspecialchars($g['label']); ?></span><strong><?php echo fmtR((float)$g['total'], $currency); ?></strong></div>
            <?php endforeach; ?>
            <div class="rpt-row rpt-row-sep"><span>Comisiones pendientes</span><strong style="color:#F59E0B;"><?php echo fmtR((float)$commissionSummary['pending'], $currency); ?></strong></div>
            <div class="rpt-row"><span>Comisiones registradas</span><strong><?php echo fmtR((float)$commissionSummary['paid'], $currency); ?></strong></div>
            <a href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=commissions" style="font-size:12px;">Gestionar comisiones →</a>
        </div>
    </div>
</div>

<div class="reports-section-title">Gráficos del periodo</div>
<div class="reports-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(420px, 1fr));gap:20px;">
    <div class="card neumorphic">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <h3 style="margin:0;">Ventas diarias</h3>
            <?php echo rptChartTypeSelect('chartSalesDay', ['line','bar','pie','doughnut']); ?>
        </div>
        <div class="card-body"><canvas id="chartSalesDay" height="260"></canvas></div>
    </div>

    <?php if ($reportsFull): ?>
        <div class="card neumorphic">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Ventas por mes (12 meses)</h3>
                <?php echo rptChartTypeSelect('chartSalesMonth', ['line','bar','pie','doughnut']); ?>
            </div>
            <div class="card-body"><canvas id="chartSalesMonth" height="260"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Ventas por categoría</h3>
                <?php echo rptChartTypeSelect('chartCategories', ['doughnut','pie','bar','line']); ?>
            </div>
            <div class="card-body"><canvas id="chartCategories" height="260"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Métodos de pago</h3>
                <?php echo rptChartTypeSelect('chartPayMethods', ['doughnut','pie','bar','line']); ?>
            </div>
            <div class="card-body"><canvas id="chartPayMethods" height="260"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Tendencia mes actual vs anterior</h3>
                <?php echo rptChartTypeSelect('chartTrend', ['bar','line']); ?>
            </div>
            <div class="card-body"><canvas id="chartTrend" height="260"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Últimos movimientos de inventario</h3></div>
            <div class="card-body">
                <?php if (empty($stockMovements)): ?>
                    <p class="rpt-empty">Sin movimientos</p>
                <?php else: ?>
                    <div class="table-container" style="max-height:320px;overflow:auto;"><table>
                        <thead><tr><th>Producto</th><th>Tipo</th><th>Cant</th><th>Origen</th><th>Fecha</th></tr></thead>
                        <tbody>
                        <?php foreach ($stockMovements as $sm):
                            $typeLabel = $sm['type'] === 'in' ? 'Entrada' : ($sm['type'] === 'out' ? 'Salida' : 'Ajuste');
                            $typeClass = $sm['type'] === 'in' ? 'badge-success' : ($sm['type'] === 'out' ? 'badge-danger' : 'badge-warning');
                            $dt = $sm['movement_date'] ?? $sm['created_at'];
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sm['product_name']); ?></td>
                                <td><span class="badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                                <td><?php echo (int)$sm['quantity']; ?></td>
                                <td style="font-size:12px;"><?php echo htmlspecialchars($sm['reference_type'] ?? ''); ?></td>
                                <td style="font-size:12px;"><?php echo $dt ? date('d/m/Y H:i', strtotime($dt)) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ([
            'Ventas por mes (12 meses)',
            'Ventas por categoría',
            'Métodos de pago',
            'Tendencia mes vs anterior',
            'Trazabilidad de inventario',
        ] as $lockedTitle): ?>
            <div class="card neumorphic feature-locked">
                <div class="card-header"><h3><?php echo htmlspecialchars($lockedTitle); ?></h3></div>
                <div class="card-body feature-locked-body">
                    <div class="feature-locked-overlay">
                        <strong>Incluido en planes superiores</strong>
                        <p>Actualiza a <?php echo $upgradeLabel; ?> para desbloquear.</p>
                        <a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="btn btn-primary btn-sm">Desbloquear</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.reports-section-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin: 4px 0 12px;
}
.rpt-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 6px 0;
    font-size: 13px;
    border-bottom: 1px dashed var(--color-border);
}
.rpt-row.muted { font-size: 12px; color: var(--color-text-secondary); }
.rpt-row-sep { border-top: 1px dashed var(--color-border); margin-top: 4px; }
.rpt-row-total {
    border-top: 2px solid var(--color-border);
    border-bottom: none;
    margin-top: 6px;
    padding-top: 10px;
}
.rpt-empty { text-align: center; color: var(--color-text-secondary); padding: 18px; margin: 0; font-size: 13px; }
</style>

<script>
(function() {
    var isDark = document.body.classList.contains('dark-mode');
    var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    var textColor = isDark ? '#94A3B8' : '#64748B';
    var reportsFull = <?php echo $reportsFull ? 'true' : 'false'; ?>;
    var symbol = <?php echo json_encode($currency['symbol'] ?? '$'); ?>;
    var palette = ['#0D7C4A','#10B981','#34D399','#7C3AED','#F59E0B','#3B82F6','#EF4444','#EC4899','#059669','#047857'];
    var STORAGE_KEY = 'seri_rpt_chart_types_v1';
    var instances = {};
    var builders = {};
    var defaults = {
        chartSalesDay: 'line',
        chartChannel: 'bar',
        chartProfit: 'bar',
        chartInventoryMonth: 'bar',
        chartSalesMonth: 'line',
        chartCategories: 'doughnut',
        chartPayMethods: 'doughnut',
        chartTrend: 'bar'
    };

    function moneyTick(v) { return symbol + Number(v).toLocaleString(); }
    function isCircular(t) { return t === 'pie' || t === 'doughnut'; }

    function loadPrefs() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            return raw ? (JSON.parse(raw) || {}) : {};
        } catch (e) { return {}; }
    }
    function savePrefs(prefs) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs)); } catch (e) {}
    }
    function getType(id) {
        var prefs = loadPrefs();
        var sel = document.querySelector('.rpt-chart-type[data-chart-id="' + id + '"]');
        var allowed = sel
            ? Array.prototype.map.call(sel.options, function(o) { return o.value; })
            : ['line','bar','pie','doughnut'];
        var t = prefs[id] || defaults[id] || 'line';
        if (allowed.indexOf(t) === -1) t = defaults[id] || allowed[0] || 'line';
        return t;
    }
    function setType(id, type) {
        var prefs = loadPrefs();
        prefs[id] = type;
        savePrefs(prefs);
    }
    function syncSelect(id) {
        var sel = document.querySelector('.rpt-chart-type[data-chart-id="' + id + '"]');
        if (sel) sel.value = getType(id);
    }

    function baseOptions(circular, money, showLegend) {
        if (circular) {
            return {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: textColor, padding: 12 } },
                    tooltip: money ? { callbacks: { label: function(ctx) { return ctx.label + ': ' + moneyTick(ctx.raw); } } } : {}
                }
            };
        }
        return {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: !!showLegend, labels: { color: textColor } } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: money ? moneyTick : undefined } },
                x: { grid: { display: false }, ticks: { color: textColor, maxTicksLimit: 12 } }
            }
        };
    }

    function makeDataset(label, data, type, opts) {
        opts = opts || {};
        var circular = isCircular(type);
        return {
            label: label,
            data: data,
            borderColor: opts.border || '#10B981',
            backgroundColor: circular
                ? (opts.colors || palette)
                : (opts.fill || 'rgba(16,185,129,0.15)'),
            borderWidth: circular ? 2 : 1,
            fill: !circular && type === 'line',
            tension: 0.3,
            pointRadius: type === 'line' ? 2 : 0,
            borderRadius: type === 'bar' ? 6 : 0
        };
    }

    function destroyChart(id) {
        if (instances[id]) {
            try { instances[id].destroy(); } catch (e) {}
            instances[id] = null;
        }
    }

    function mount(id) {
        var build = builders[id];
        if (!build) return;
        destroyChart(id);
        syncSelect(id);
        instances[id] = build(getType(id));
    }

    builders.chartSalesDay = function(type) {
        var el = document.getElementById('chartSalesDay');
        if (!el) return null;
        return new Chart(el, {
            type: type,
            data: {
                labels: <?php echo json_encode($salesDaysLabels); ?>,
                datasets: [makeDataset('Ventas', <?php echo json_encode($salesDaysData); ?>, type, {
                    colors: palette, fill: 'rgba(13,124,74,0.65)', border: '#0D7C4A'
                })]
            },
            options: baseOptions(isCircular(type), true)
        });
    };

    builders.chartChannel = function(type) {
        var el = document.getElementById('chartChannel');
        if (!el) return null;
        return new Chart(el, {
            type: type,
            data: {
                labels: <?php echo json_encode($channelChartLabels); ?>,
                datasets: [makeDataset('Utilidad', <?php echo json_encode($channelChartData); ?>, type, {
                    colors: palette, fill: 'rgba(13,124,74,0.7)', border: '#0D7C4A'
                })]
            },
            options: baseOptions(isCircular(type), true)
        });
    };

    builders.chartProfit = function(type) {
        var el = document.getElementById('chartProfit');
        if (!el) return null;
        var circular = isCircular(type);
        return new Chart(el, {
            type: type,
            data: {
                labels: <?php echo json_encode($profitLabels); ?>,
                datasets: [{
                    label: 'Utilidad',
                    data: <?php echo json_encode($profitData); ?>,
                    backgroundColor: circular ? palette : <?php echo json_encode($profitColors); ?>,
                    borderColor: '#0D7C4A',
                    borderWidth: circular ? 2 : 0,
                    borderRadius: 6,
                    fill: type === 'line',
                    tension: 0.3
                }]
            },
            options: circular ? baseOptions(true, true) : {
                indexAxis: type === 'bar' ? 'y' : 'x',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return moneyTick(ctx.raw); } } } },
                scales: {
                    x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: moneyTick } },
                    y: { grid: { display: false }, ticks: { color: textColor } }
                }
            }
        });
    };

    builders.chartInventoryMonth = function(type) {
        var el = document.getElementById('chartInventoryMonth');
        if (!el) return null;
        var t = type === 'line' ? 'line' : 'bar';
        return new Chart(el, {
            type: t,
            data: {
                labels: <?php echo json_encode($invMonthLabels); ?>,
                datasets: [
                    { label: 'Entradas', data: <?php echo json_encode($invInData); ?>, backgroundColor: 'rgba(16,185,129,0.7)', borderColor: '#10B981', borderRadius: 4, tension: 0.3, fill: t === 'line' },
                    { label: 'Salidas', data: <?php echo json_encode($invOutData); ?>, backgroundColor: 'rgba(239,68,68,0.55)', borderColor: '#EF4444', borderRadius: 4, tension: 0.3, fill: t === 'line' },
                    { label: 'Vendidas', data: <?php echo json_encode($invSoldData); ?>, backgroundColor: 'rgba(59,130,246,0.55)', borderColor: '#3B82F6', borderRadius: 4, tension: 0.3, fill: t === 'line' }
                ]
            },
            options: baseOptions(false, false, true)
        });
    };

    if (reportsFull) {
        builders.chartSalesMonth = function(type) {
            var el = document.getElementById('chartSalesMonth');
            if (!el) return null;
            return new Chart(el, {
                type: type,
                data: {
                    labels: <?php echo json_encode($salesMonthsLabels); ?>,
                    datasets: [makeDataset('Ventas', <?php echo json_encode($salesMonthsData); ?>, type, {
                        colors: palette, fill: 'rgba(13,124,74,0.65)', border: '#0D7C4A'
                    })]
                },
                options: baseOptions(isCircular(type), true)
            });
        };

        builders.chartCategories = function(type) {
            var el = document.getElementById('chartCategories');
            if (!el) return null;
            var circular = isCircular(type);
            return new Chart(el, {
                type: type,
                data: {
                    labels: <?php echo json_encode($catLabels); ?>,
                    datasets: [makeDataset('Categorías', <?php echo json_encode($catData); ?>, type, {
                        colors: palette, fill: 'rgba(13,124,74,0.65)', border: circular ? (isDark ? '#1E293B' : '#fff') : '#0D7C4A'
                    })]
                },
                options: baseOptions(circular, true, !circular)
            });
        };

        builders.chartPayMethods = function(type) {
            var el = document.getElementById('chartPayMethods');
            if (!el) return null;
            var circular = isCircular(type);
            return new Chart(el, {
                type: type,
                data: {
                    labels: <?php echo json_encode($payMethodLabels); ?>,
                    datasets: [makeDataset('Pagos', <?php echo json_encode($payMethodData); ?>, type, {
                        colors: palette, fill: 'rgba(59,130,246,0.55)', border: circular ? (isDark ? '#1E293B' : '#fff') : '#3B82F6'
                    })]
                },
                options: baseOptions(circular, false, !circular)
            });
        };

        builders.chartTrend = function(type) {
            var el = document.getElementById('chartTrend');
            if (!el) return null;
            var t = type === 'line' ? 'line' : 'bar';
            return new Chart(el, {
                type: t,
                data: {
                    labels: <?php echo json_encode($trendLabels); ?>,
                    datasets: [
                        { label: 'Mes actual', data: <?php echo json_encode($trendCurrent); ?>, backgroundColor: 'rgba(13,124,74,0.7)', borderColor: '#0D7C4A', borderRadius: 4, tension: 0.3, fill: t === 'line' },
                        { label: 'Mes anterior', data: <?php echo json_encode($trendLast); ?>, backgroundColor: 'rgba(100,116,139,0.35)', borderColor: '#64748B', borderRadius: 4, tension: 0.3, fill: t === 'line' }
                    ]
                },
                options: baseOptions(false, false, true)
            });
        };
    }

    Object.keys(builders).forEach(mount);

    document.querySelectorAll('.rpt-chart-type').forEach(function(sel) {
        syncSelect(sel.getAttribute('data-chart-id'));
        sel.addEventListener('change', function() {
            var id = sel.getAttribute('data-chart-id');
            setType(id, sel.value);
            mount(id);
        });
    });
})();
</script>
