<?php
$layout = 'tenant';
$title = 'Reportes - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Analiticas y Reportes';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$currency = $currency ?? ['symbol'=>'$','decimals'=>2,'decimal'=>',','thousands'=>'.'];
$reportsFull = !empty($reportsFull);
$plan = $plan ?? ['plan_name' => 'Plan Basico', 'upgrade_plans' => 'Pro o Premium', 'tier' => 'basic'];
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
$topSellers = $topSellers ?? [];

function fmtR(float $a, array $c): string {
    return $c['symbol'].' '.number_format($a, $c['decimals'], $c['decimal']??',', $c['thousands']??'.');
}
function trendArrow(float $pct): string { return $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '→'); }
function trendColor(float $pct): string { return $pct > 0 ? '#10B981' : ($pct < 0 ? '#DC2626' : '#64748B'); }

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

$topCustLabels = []; $topCustData = [];
foreach ($topCustomers as $c) { $topCustLabels[] = $c['customer_name']; $topCustData[] = (int)$c['purchase_count']; }

$payMethodLabels = []; $payMethodData = [];
$payNames = ['cash'=>'Efectivo','card'=>'Tarjeta','transfer'=>'Transferencia','credit'=>'Credito','other'=>'Otro'];
foreach ($paymentMethods as $pm) { $payMethodLabels[] = $payNames[$pm['payment_method']] ?? $pm['payment_method']; $payMethodData[] = (int)$pm['count']; }

$trendLabels = []; $trendCurrent = []; $trendLast = [];
foreach ($productTrend as $t) { $trendLabels[] = $t['name']; $trendCurrent[] = (int)$t['current_month']; $trendLast[] = (int)$t['last_month']; }

$upgradeLabel = htmlspecialchars($plan['upgrade_plans'] ?? 'Pro o Premium');
$planName = htmlspecialchars($plan['plan_name'] ?? 'Plan Basico');
?>

<script src="<?php echo $viewInstance->asset('js/chart.umd.min.js'); ?>"></script>
<?php echo flashMessage(); ?>

<?php if (!$reportsFull): ?>
<div class="plan-banner neumorphic" style="margin-bottom:20px;">
    <div class="plan-banner-content">
        <div>
            <strong>Plan actual: <?php echo $planName; ?> · Reportes basicos</strong>
            <p>Tienes el resumen esencial (KPIs, ventas diarias y stock bajo). Debajo veras el resto de analiticas en vista previa bloqueada: se desbloquean al actualizar a <?php echo $upgradeLabel; ?>.</p>
        </div>
        <a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="btn btn-primary" title="Solicitar actualizacion de plan">Actualizar plan</a>
    </div>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="card neumorphic" style="margin-bottom:20px;">
    <div class="card-body" style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;padding:15px 20px;">
        <form method="GET" action="<?php echo $viewInstance->route('app/reportes'); ?>" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;flex:1;">
            <label style="font-weight:600;font-size:13px;">Periodo:</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="form-control" style="width:160px;" title="Fecha desde">
            <span style="color:var(--color-text-secondary);">hasta</span>
            <input type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>" class="form-control" style="width:160px;" title="Fecha hasta">
            <button type="submit" class="btn btn-primary" title="Aplicar filtro">Aplicar</button>
            <a href="<?php echo $viewInstance->route('app/reportes'); ?>" class="btn btn-secondary" style="font-size:12px;" title="Reset">Reset</a>
        </form>
        <div style="display:flex;gap:8px;">
            <?php if ($reportsFull): ?>
                <a href="<?php echo $viewInstance->route('app/reportes'); ?>?action=export&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>" class="btn btn-secondary" style="font-size:12px;" title="Exportar CSV">CSV</a>
                <button onclick="exportPDF()" class="btn btn-secondary" style="font-size:12px;" title="Imprimir / Guardar PDF">PDF</button>
            <?php else: ?>
                <button type="button" class="btn btn-secondary is-locked-btn" style="font-size:12px;" title="Disponible en planes superiores" onclick="showAlert('Exportacion disponible en <?php echo $upgradeLabel; ?>','warning')">CSV (Pro)</button>
                <button type="button" class="btn btn-secondary is-locked-btn" style="font-size:12px;" title="Disponible en planes superiores" onclick="showAlert('Exportacion disponible en <?php echo $upgradeLabel; ?>','warning')">PDF (Pro)</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- KPIs basicos -->
<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card neumorphic">
        <h4>Ventas Totales</h4>
        <div class="stat-value" style="color:#10B981;"><?php echo fmtR((float)$totalSales, $currency); ?></div>
        <small><?php echo (int)$totalSalesCount; ?> ventas</small>
        <?php if ($reportsFull): ?>
        <div style="margin-top:6px;font-size:12px;color:<?php echo trendColor((float)$salesTrend); ?>;">
            <?php echo trendArrow((float)$salesTrend); ?> <?php echo abs((float)$salesTrend); ?>% vs periodo anterior
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-card neumorphic">
        <h4>Productos</h4>
        <div class="stat-value"><?php echo (int)$totalProducts; ?></div>
        <small><?php echo (int)$lowStock; ?> con stock bajo</small>
    </div>
    <div class="stat-card neumorphic">
        <h4>Clientes</h4>
        <div class="stat-value"><?php echo (int)$totalCustomers; ?></div>
        <small>activos</small>
    </div>
    <div class="stat-card neumorphic">
        <h4>Ticket Promedio</h4>
        <div class="stat-value" style="color:var(--color-primary);"><?php echo $totalSalesCount > 0 ? fmtR((float)$avgTicket, $currency) : fmtR(0, $currency); ?></div>
        <small>por venta</small>
        <?php if ($reportsFull): ?>
        <div style="margin-top:6px;font-size:12px;color:<?php echo trendColor((float)$ticketTrend); ?>;">
            <?php echo trendArrow((float)$ticketTrend); ?> <?php echo abs((float)$ticketTrend); ?>% vs periodo anterior
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Reportes clave (disponibles en todos los planes) ===== -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:20px;">

    <!-- Utilidad del periodo -->
    <div class="card neumorphic">
        <div class="card-header"><h3>💰 Utilidad del periodo</h3></div>
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;padding:6px 0;">
                <span style="color:var(--color-text-secondary);">Ingresos</span>
                <strong style="color:#10B981;"><?php echo fmtR((float)$profitSummary['revenue'], $currency); ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:6px 0;">
                <span style="color:var(--color-text-secondary);">− Costo de ventas</span>
                <span><?php echo fmtR((float)$profitSummary['cost'], $currency); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px dashed var(--color-border);">
                <span>Utilidad bruta</span>
                <strong><?php echo fmtR((float)$profitSummary['gross'], $currency); ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">− Gastos fijos</span>
                <span><?php echo fmtR((float)($profitSummary['expenses_fixed'] ?? 0), $currency); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">− Gastos financieros</span>
                <span><?php echo fmtR((float)($profitSummary['expenses_financial'] ?? 0), $currency); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">− Otros gastos</span>
                <span><?php echo fmtR((float)($profitSummary['expenses_operating'] ?? 0), $currency); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px 0 0;border-top:2px solid var(--color-border);margin-top:6px;">
                <strong>Utilidad neta</strong>
                <strong style="font-size:18px;color:<?php echo (float)$profitSummary['net'] >= 0 ? '#10B981' : '#DC2626'; ?>;">
                    <?php echo fmtR((float)$profitSummary['net'], $currency); ?>
                </strong>
            </div>
            <div style="text-align:right;font-size:12px;color:var(--color-text-secondary);margin-top:4px;">
                Margen: <?php echo (float)$profitSummary['margin']; ?>%
            </div>
        </div>
    </div>

    <!-- Conciliación datáfono / tarjetas -->
    <div class="card neumorphic">
        <div class="card-header"><h3>Conciliación datáfono</h3></div>
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">Ventas datáfono</span>
                <strong><?php echo fmtR((float)$dataphoneRecon['dataphone_sales'], $currency); ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">Ventas tarjeta / link</span>
                <strong><?php echo fmtR((float)$dataphoneRecon['card_sales'], $currency); ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">Comisión esperada (<?php echo (float)$dataphoneRecon['rate_dataphone']; ?>% / <?php echo (float)$dataphoneRecon['rate_card']; ?>%)</span>
                <strong><?php echo fmtR((float)$dataphoneRecon['expected_total'], $currency); ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;">
                <span style="color:var(--color-text-secondary);">Gastos registrados</span>
                <span><?php echo fmtR((float)$dataphoneRecon['recorded_total'], $currency); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0 0;border-top:2px solid var(--color-border);margin-top:6px;">
                <strong>Brecha</strong>
                <strong style="color:<?php echo (float)$dataphoneRecon['gap'] > 0.01 ? '#DC2626' : '#10B981'; ?>;">
                    <?php echo fmtR((float)$dataphoneRecon['gap'], $currency); ?>
                </strong>
            </div>
            <?php if ((float)$dataphoneRecon['suggest_amount'] > 0.01): ?>
                <a class="btn btn-sm btn-primary" style="margin-top:12px;display:inline-block;"
                   href="<?php echo $viewInstance->route('app/gastos'); ?>?suggest=1&amp;amount=<?php echo urlencode((string)$dataphoneRecon['suggest_amount']); ?>&amp;category=<?php echo urlencode((string)$dataphoneRecon['suggest_category']); ?>&amp;description=<?php echo urlencode((string)$dataphoneRecon['suggest_description']); ?>">
                    Registrar comisión sugerida
                </a>
            <?php else: ?>
                <p style="font-size:12px;color:var(--color-text-secondary);margin-top:10px;">Comisiones alineadas con las tasas configuradas.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Margen por canal -->
    <div class="card neumorphic">
        <div class="card-header"><h3>Margen por canal</h3></div>
        <div class="card-body">
            <?php if (empty($marginByChannel)): ?>
                <p style="text-align:center;color:var(--color-text-secondary);font-size:13px;">Sin ventas en el periodo</p>
            <?php else: ?>
                <?php foreach ($marginByChannel as $ch): ?>
                    <div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px dashed var(--color-border);font-size:13px;">
                        <span><?php echo htmlspecialchars($channelLabels[$ch['channel']] ?? $ch['channel']); ?></span>
                        <span>
                            <?php echo fmtR((float)$ch['profit'], $currency); ?>
                            <small style="color:var(--color-text-secondary);">(<?php echo (float)$ch['margin']; ?>%)</small>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Gastos por tipo -->
    <div class="card neumorphic">
        <div class="card-header"><h3>Gastos por tipo</h3></div>
        <div class="card-body">
            <?php foreach (($expenseBreakdown['by_group'] ?? []) as $g): ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;border-bottom:1px dashed var(--color-border);">
                    <span><?php echo htmlspecialchars($g['label']); ?></span>
                    <strong><?php echo fmtR((float)$g['total'], $currency); ?></strong>
                </div>
            <?php endforeach; ?>
            <?php if (!empty($expenseBreakdown['by_category'])): ?>
                <div style="margin-top:10px;font-size:12px;color:var(--color-text-secondary);">Detalle</div>
                <?php foreach (array_slice($expenseBreakdown['by_category'], 0, 8) as $row): ?>
                    <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:12px;">
                        <span><?php echo htmlspecialchars($row['label']); ?></span>
                        <span><?php echo fmtR((float)$row['total'], $currency); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div style="margin-top:12px;padding-top:8px;border-top:1px solid var(--color-border);font-size:13px;">
                <div style="display:flex;justify-content:space-between;"><span>Comisiones pendientes</span><strong style="color:#F59E0B;"><?php echo fmtR((float)$commissionSummary['pending'], $currency); ?></strong></div>
                <div style="display:flex;justify-content:space-between;"><span>Comisiones registradas</span><strong><?php echo fmtR((float)$commissionSummary['paid'], $currency); ?></strong></div>
                <a href="<?php echo $viewInstance->route('app/contabilidad'); ?>?tab=commissions" style="font-size:12px;">Gestionar comisiones →</a>
            </div>
        </div>
    </div>

    <!-- Cuentas por cobrar -->
    <div class="card neumorphic">
        <div class="card-header"><h3>📥 Cuentas por cobrar</h3></div>
        <div class="card-body">
            <div style="text-align:center;margin-bottom:12px;">
                <div style="font-size:24px;font-weight:700;color:var(--color-primary);"><?php echo fmtR((float)$receivablesAging['total'], $currency); ?></div>
                <small style="color:var(--color-text-secondary);"><?php echo (int)$receivablesAging['cnt']; ?> facturas pendientes</small>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px;">
                <div style="display:flex;justify-content:space-between;"><span>0-30 días</span><strong><?php echo fmtR((float)$receivablesAging['d0'], $currency); ?></strong></div>
                <div style="display:flex;justify-content:space-between;"><span>31-60</span><strong><?php echo fmtR((float)$receivablesAging['d30'], $currency); ?></strong></div>
                <div style="display:flex;justify-content:space-between;"><span>61-90</span><strong style="color:#F59E0B;"><?php echo fmtR((float)$receivablesAging['d60'], $currency); ?></strong></div>
                <div style="display:flex;justify-content:space-between;"><span>+90 días</span><strong style="color:#DC2626;"><?php echo fmtR((float)$receivablesAging['d90'], $currency); ?></strong></div>
            </div>
            <?php if (!empty($topDebtors)): ?>
                <div style="margin-top:12px;border-top:1px dashed var(--color-border);padding-top:8px;">
                    <?php foreach (array_slice($topDebtors, 0, 4) as $d): ?>
                        <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%;"><?php echo htmlspecialchars($d['customer_name']); ?></span>
                            <span><?php echo fmtR((float)$d['balance'], $currency); ?> <small style="color:var(--color-text-secondary);">(<?php echo (int)$d['max_days']; ?>d)</small></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Caja del día -->
    <div class="card neumorphic">
        <div class="card-header"><h3>🧾 Caja de hoy</h3></div>
        <div class="card-body">
            <?php if ($cashSummary['session']): ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;">
                    <span style="color:var(--color-text-secondary);">Base inicial</span>
                    <span><?php echo fmtR((float)$cashSummary['opening'], $currency); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;">
                    <span style="color:var(--color-text-secondary);">+ Ingresos</span>
                    <strong style="color:#10B981;"><?php echo fmtR((float)$cashSummary['income'], $currency); ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;">
                    <span style="color:var(--color-text-secondary);">− Egresos</span>
                    <strong style="color:#DC2626;"><?php echo fmtR((float)$cashSummary['expense'], $currency); ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:10px 0 0;border-top:2px solid var(--color-border);margin-top:6px;">
                    <strong>Esperado en caja</strong>
                    <strong style="font-size:18px;color:var(--color-primary);"><?php echo fmtR((float)$cashSummary['expected'], $currency); ?></strong>
                </div>
                <small style="color:var(--color-text-secondary);">Sesión de <?php echo htmlspecialchars($cashSummary['session']['user_name'] ?? 'Usuario'); ?></small>
            <?php else: ?>
                <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay caja abierta.<br>
                    <a href="<?php echo $viewInstance->route('app/caja'); ?>" style="color:var(--color-primary);">Abrir caja</a>
                </p>
                <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <span>Movimientos hoy (neto)</span>
                    <strong><?php echo fmtR((float)$cashSummary['net'], $currency); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top productos + stock crítico -->
    <div class="card neumorphic">
        <div class="card-header"><h3>🏆 Más vendidos</h3></div>
        <div class="card-body">
            <?php if (empty($topSellers)): ?>
                <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Sin ventas en el periodo</p>
            <?php else: ?>
                <?php foreach ($topSellers as $i => $ts): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--color-border);">
                        <span><strong><?php echo $i + 1; ?>.</strong> <?php echo htmlspecialchars($ts['name']); ?></span>
                        <span><span class="badge badge-success"><?php echo (int)$ts['qty']; ?></span> <?php echo fmtR((float)$ts['total'], $currency); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ((int)$lowStock > 0): ?>
                <div style="margin-top:12px;padding:10px;background:rgba(220,38,38,0.06);border-radius:8px;font-size:13px;">
                    ⚠️ <strong><?php echo (int)$lowStock; ?></strong> producto(s) con stock crítico.
                    <a href="<?php echo $viewInstance->route('app/inventario'); ?>?stock_state=low" style="color:var(--color-primary);">Ver</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="reports-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(480px, 1fr));gap:20px;">

    <!-- Disponible en basico -->
    <div class="card neumorphic">
        <div class="card-header"><h3>Ventas Diarias</h3></div>
        <div class="card-body"><canvas id="chartSalesDay" height="280"></canvas></div>
    </div>

    <div class="card neumorphic">
        <div class="card-header"><h3>Productos con Stock Bajo</h3></div>
        <div class="card-body">
            <?php if (empty($lowStockProducts)): ?>
                <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Todos los productos tienen stock suficiente</p>
            <?php else: ?>
                <div class="table-container"><table>
                    <thead><tr><th>Producto</th><th>Stock</th><th>Minimo</th><th>Faltante</th><th>Precio</th></tr></thead>
                    <tbody>
                    <?php foreach ($lowStockProducts as $lp): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($lp['name']); ?></strong></td>
                            <td><span class="badge badge-danger"><?php echo (int)$lp['stock']; ?></span></td>
                            <td><?php echo (int)$lp['min_stock']; ?></td>
                            <td style="color:var(--color-error);font-weight:600;"><?php echo (int)$lp['min_stock'] - (int)$lp['stock']; ?></td>
                            <td><?php echo fmtR((float)$lp['sale_price'], $currency); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $lockedBlocks = [
        ['id' => 'chartSalesMonth', 'title' => 'Ventas por Mes (12 meses)', 'desc' => 'Historico anual y estacionalidad'],
        ['id' => 'chartProfit', 'title' => 'Productos Mas Rentables', 'desc' => 'Margen real por producto'],
        ['id' => 'chartTopProducts', 'title' => 'Productos Mas Vendidos', 'desc' => 'Ranking de unidades'],
        ['id' => 'chartCategories', 'title' => 'Ventas por Categoria', 'desc' => 'Distribucion por rubro'],
        ['id' => 'chartTopCustomers', 'title' => 'Clientes Frecuentes', 'desc' => 'Fidelizacion y recurrencia'],
        ['id' => 'chartPayMethods', 'title' => 'Metodos de Pago', 'desc' => 'Preferencias de cobro'],
        ['id' => 'chartTrend', 'title' => 'Tendencia Mes vs Anterior', 'desc' => 'Comparativa de crecimiento'],
        ['id' => 'tracePanel', 'title' => 'Trazabilidad de Inventario', 'desc' => 'Ultimos movimientos de stock'],
    ];
    ?>

    <?php if ($reportsFull): ?>
        <div class="card neumorphic">
            <div class="card-header"><h3>Ventas por Mes (12 meses)</h3></div>
            <div class="card-body"><canvas id="chartSalesMonth" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Productos Mas Rentables</h3></div>
            <div class="card-body"><canvas id="chartProfit" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Productos Mas Vendidos</h3></div>
            <div class="card-body"><canvas id="chartTopProducts" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Ventas por Categoria</h3></div>
            <div class="card-body"><canvas id="chartCategories" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Clientes Frecuentes</h3></div>
            <div class="card-body"><canvas id="chartTopCustomers" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Metodos de Pago</h3></div>
            <div class="card-body"><canvas id="chartPayMethods" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Tendencia: Este Mes vs Mes Anterior</h3></div>
            <div class="card-body"><canvas id="chartTrend" height="280"></canvas></div>
        </div>
        <div class="card neumorphic">
            <div class="card-header"><h3>Trazabilidad - Ultimos Movimientos</h3></div>
            <div class="card-body">
                <?php if (empty($stockMovements)): ?>
                    <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">Sin movimientos registrados</p>
                <?php else: ?>
                    <div class="table-container" style="max-height:350px;overflow-y:auto;"><table>
                        <thead><tr><th>Producto</th><th>Tipo</th><th>Cant</th><th>Ref</th><th>Fecha</th></tr></thead>
                        <tbody>
                        <?php foreach ($stockMovements as $sm):
                            $typeLabel = $sm['type'] === 'in' ? 'Entrada' : ($sm['type'] === 'out' ? 'Salida' : 'Ajuste');
                            $typeClass = $sm['type'] === 'in' ? 'badge-success' : ($sm['type'] === 'out' ? 'badge-danger' : 'badge-warning');
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sm['product_name']); ?></td>
                                <td><span class="badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                                <td><?php echo (int)$sm['quantity']; ?></td>
                                <td style="font-size:12px;"><?php echo htmlspecialchars($sm['reference_type'] ?? ''); ?></td>
                                <td style="font-size:12px;"><?php echo date('d/m/Y H:i', strtotime($sm['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($lockedBlocks as $block): ?>
            <div class="card neumorphic feature-locked">
                <div class="card-header"><h3><?php echo htmlspecialchars($block['title']); ?></h3></div>
                <div class="card-body feature-locked-body">
                    <div class="feature-locked-ghost" aria-hidden="true">
                        <span style="height:42%"></span><span style="height:68%"></span><span style="height:35%"></span>
                        <span style="height:80%"></span><span style="height:55%"></span><span style="height:72%"></span>
                        <span style="height:40%"></span>
                    </div>
                    <div class="feature-locked-overlay">
                        <div class="feature-locked-icon" aria-hidden="true">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zm-7-2a2 2 0 0 1 4 0v2h-4V6zm3 11a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
                        </div>
                        <strong>Incluido en planes superiores</strong>
                        <p><?php echo htmlspecialchars($block['desc']); ?>. Actualiza a <?php echo $upgradeLabel; ?> para verlo en vivo.</p>
                        <a href="<?php echo $viewInstance->route('app/soporte'); ?>" class="btn btn-primary btn-sm" title="Solicitar upgrade">Desbloquear</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
@media print {
    .sidebar, .header, .app-footer, .breadcrumbs, .btn, form, .plan-banner, .feature-locked { display: none !important; }
    .main-content { margin-left: 0 !important; }
    .reports-grid { grid-template-columns: 1fr 1fr !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; }
    body { background: #fff !important; }
}
</style>

<script>
function exportPDF() { window.print(); }

(function() {
    var isDark = document.body.classList.contains('dark-mode');
    var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    var textColor = isDark ? '#94A3B8' : '#64748B';
    var reportsFull = <?php echo $reportsFull ? 'true' : 'false'; ?>;
    var symbol = <?php echo json_encode($currency['symbol'] ?? '$'); ?>;

    function moneyTick(v) { return symbol + Number(v).toLocaleString(); }

    var dayEl = document.getElementById('chartSalesDay');
    if (dayEl) {
        new Chart(dayEl, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($salesDaysLabels); ?>,
                datasets: [{
                    label: 'Ventas',
                    data: <?php echo json_encode($salesDaysData); ?>,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: moneyTick } },
                    x: { grid: { display: false }, ticks: { color: textColor, maxTicksLimit: 12 } }
                }
            }
        });
    }

    if (!reportsFull) return;

    new Chart(document.getElementById('chartSalesMonth'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($salesMonthsLabels); ?>,
            datasets: [{ label: 'Ventas', data: <?php echo json_encode($salesMonthsData); ?>, backgroundColor: 'rgba(13,124,74,0.7)', borderColor: '#0D7C4A', borderWidth: 1, borderRadius: 6 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: moneyTick } },
                x: { grid: { display: false }, ticks: { color: textColor } }
            }
        }
    });

    new Chart(document.getElementById('chartProfit'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($profitLabels); ?>,
            datasets: [{ label: 'Ganancia Neta', data: <?php echo json_encode($profitData); ?>, backgroundColor: <?php echo json_encode($profitColors); ?>, borderRadius: 6 }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return moneyTick(ctx.raw); } } } },
            scales: {
                x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, callback: moneyTick } },
                y: { grid: { display: false }, ticks: { color: textColor } }
            }
        }
    });

    new Chart(document.getElementById('chartTopProducts'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($topProductsLabels); ?>,
            datasets: [{ label: 'Unidades', data: <?php echo json_encode($topProductsData); ?>, backgroundColor: <?php echo json_encode($topProductsColors); ?>, borderRadius: 6 }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
                y: { grid: { display: false }, ticks: { color: textColor } }
            }
        }
    });

    new Chart(document.getElementById('chartCategories'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($catLabels); ?>,
            datasets: [{ data: <?php echo json_encode($catData); ?>, backgroundColor: ['#0D7C4A','#10B981','#34D399','#7C3AED','#F59E0B','#3B82F6','#EF4444','#EC4899'], borderWidth: 2, borderColor: isDark ? '#1E293B' : '#fff' }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { color: textColor, padding: 15 } },
                tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + moneyTick(ctx.raw); } } }
            }
        }
    });

    new Chart(document.getElementById('chartTopCustomers'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($topCustLabels); ?>,
            datasets: [{ label: 'Compras', data: <?php echo json_encode($topCustData); ?>, backgroundColor: 'rgba(13,124,74,0.65)', borderRadius: 6 }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, stepSize: 1 } },
                y: { grid: { display: false }, ticks: { color: textColor } }
            }
        }
    });

    new Chart(document.getElementById('chartPayMethods'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($payMethodLabels); ?>,
            datasets: [{ data: <?php echo json_encode($payMethodData); ?>, backgroundColor: ['#0D7C4A','#059669','#F59E0B','#3B82F6','#EF4444'], borderWidth: 2, borderColor: isDark ? '#1E293B' : '#fff' }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { color: textColor, padding: 15 } } }
        }
    });

    new Chart(document.getElementById('chartTrend'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($trendLabels); ?>,
            datasets: [
                { label: 'Mes Actual', data: <?php echo json_encode($trendCurrent); ?>, backgroundColor: 'rgba(13,124,74,0.7)', borderRadius: 4 },
                { label: 'Mes Anterior', data: <?php echo json_encode($trendLast); ?>, backgroundColor: 'rgba(100,116,139,0.35)', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: textColor } } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
                x: { grid: { display: false }, ticks: { color: textColor } }
            }
        }
    });
})();
</script>
