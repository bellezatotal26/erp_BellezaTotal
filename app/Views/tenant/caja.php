<?php
$layout = 'tenant';
$title = 'Caja - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Control de Caja';
$tenantName = $tenantName ?? 'Mi Empresa'; $userName = $userName ?? 'Usuario';
$openSession = $openSession ?? null; $movements = $movements ?? [];
$todaySales = $todaySales ?? [];
$totals = $totals ?? ['incomes'=>0,'expenses'=>0,'balance'=>0];
$historySessions = $historySessions ?? [];
$currency = $currency ?? ['symbol'=>'$','name'=>'Peso','decimals'=>0];
$isPosRole = $isPosRole ?? \SoftNova\Core\TenantMiddleware::isPosRole();
$isAdminViewer = $isAdminViewer ?? \SoftNova\Core\TenantMiddleware::isManagement();
$openSessionsCount = (int)($openSessionsCount ?? ($openSession ? 1 : 0));
$stockAlert = $stockAlert ?? ['count' => 0, 'negative_count' => 0, 'loan_count' => 0, 'loan_received_count' => 0, 'sale_debt_count' => 0, 'summary_lines' => []];
$needBarcode = true;
$pageScripts = ['ventas.js', 'caja.js'];
if (!function_exists('fmt')) {
    function fmt(float $a, array $c): string { return $c['symbol'].' '.number_format($a,$c['decimals'],$c['decimal']??',',$c['thousands']??'.'); }
}
if (!function_exists('hoursOpen')) {
    function hoursOpen(string $date): float { return round((time()-strtotime($date))/3600, 1); }
}
?>
<?php echo flashMessage(); ?>
<div id="posPwaInstallBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:var(--dmb-ECFDF5, #ECFDF5);color:var(--dmc-065F46, #065F46);border:1px solid #10B981;font-size:13px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span>Instale <strong>Seri Caja</strong> en este computador para usarla como aplicación (incluso sin internet).</span>
    <span style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm btn-primary" id="posPwaInstallBtn">Instalar</button>
        <button type="button" class="btn btn-sm btn-secondary" id="posPwaInstallDismiss">Ahora no</button>
    </span>
</div>
<div id="posOfflineBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:var(--dmb-FEF3C7, #FEF3C7);color:var(--dmc-92400E, #92400E);border:1px solid #F59E0B;font-size:13px;"></div>
<div id="posNetworkBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:var(--dmb-DBEAFE, #DBEAFE);color:var(--dmc-1E3A8A, #1E3A8A);border:1px solid #3B82F6;font-size:13px;"></div>
<div id="posCatalogStaleBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:var(--dmb-FFF7ED, #FFF7ED);color:var(--dmc-9A3412, #9A3412);border:1px solid #FB923C;font-size:13px;"></div>
<div id="posConflictBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:var(--dmb-FEE2E2, #FEE2E2);color:var(--dmc-991B1B, #991B1B);border:1px solid #EF4444;font-size:13px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span id="posConflictBannerText"></span>
    <button type="button" class="btn btn-sm btn-danger" id="posConflictReviewBtn">Revisar conflictos</button>
</div>
<div id="posSyncBar" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
    <div id="posPwaStatus" style="display:none;font-size:12px;color:var(--color-text-secondary);flex:1;min-width:200px;"></div>
    <button type="button" class="btn btn-sm btn-secondary" id="posSyncNowBtn" title="Descargar catálogo y subir ventas en cola">
        Sincronizar ahora
    </button>
</div>

<?php if (!$openSession): ?>
<?php if (($stockAlert['count'] ?? 0) > 0): ?>
<div class="alert alert-warning alert-stock-pending" style="margin-bottom:16px;text-align:left;">
    <strong>Pendiente del día anterior:</strong>
    hay <?php echo (int)$stockAlert['count']; ?> producto(s) con stock negativo o unidades pendientes
    (<?php echo (int)($stockAlert['negative_count'] ?? 0); ?> en negativo ·
    <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> préstamo(s) salida ·
    <?php echo (int)($stockAlert['loan_received_count'] ?? 0); ?> nos prestaron ·
    <?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?> venta(s) sin stock).
    Revise motivos antes de operar.
    <?php if (!$isPosRole && \SoftNova\Core\TenantMiddleware::canAccess('prestamos')): ?>
        <a href="<?php echo $viewInstance->route('app/prestamos'); ?>" style="margin-left:8px;">Ver préstamos / trazabilidad</a>
    <?php endif; ?>
    <ul style="max-height:160px;overflow:auto;">
        <?php foreach (array_slice($stockAlert['summary_lines'] ?? [], 0, 15) as $line): ?>
            <li><?php echo htmlspecialchars((string)$line); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<div class="card neumorphic" style="text-align:center;padding:40px;">
    <div style="font-size:64px;margin-bottom:15px;">🔒</div>
    <h2 style="color:var(--color-accent-text, var(--color-primary));margin-bottom:10px;">Caja Cerrada</h2>
    <p style="color:var(--color-text-secondary);margin-bottom:20px;">No hay ninguna sesión de caja abierta.</p>
    <button onclick="document.getElementById('openCashModal').style.display='flex'" class="btn btn-primary neumorphic-btn" style="font-size:16px;padding:12px 30px;">🔓 Abrir Caja</button>
</div>

<div id="openCashModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header"><h3>Abrir Caja</h3><button onclick="document.getElementById('openCashModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/caja'); ?>?action=open" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <?php if (($stockAlert['count'] ?? 0) > 0): ?>
                <div class="alert alert-warning" style="margin-bottom:12px;font-size:13px;text-align:left;">
                    <strong>Recordatorio de inventario:</strong> hay productos en negativo o préstamos/ventas sin stock pendientes. Quedan registrados para trazabilidad.
                    <ul style="margin:6px 0 0;padding-left:18px;max-height:120px;overflow:auto;">
                        <?php foreach (array_slice($stockAlert['summary_lines'] ?? [], 0, 8) as $line): ?>
                            <li><?php echo htmlspecialchars((string)$line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="form-group"><label>Monto de Apertura (<?php echo $currency['symbol']; ?>) *</label><input type="text" inputmode="decimal" name="opening_amount" class="form-control money-input" data-money="1" placeholder="0" required autofocus></div>
                <div class="form-group"><label>Notas</label><textarea name="notes" class="form-control" rows="2" placeholder="Observaciones..."></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('openCashModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-primary">Abrir Caja</button></div>
        </form>
    </div>
</div>

<?php else: ?>
<?php
$isPosRole = $isPosRole ?? \SoftNova\Core\TenantMiddleware::isPosRole();
$hours = hoursOpen($openSession['opening_date']); $warningClass = $hours > 20 ? 'badge-danger' : ($hours > 16 ? 'badge-warning' : 'badge-success');
$canExpense = $canExpenseFromCaja ?? (
    \SoftNova\Core\TenantMiddleware::isPosRole()
    || \SoftNova\Core\TenantMiddleware::canDo('create', 'gastos')
);
$canLoanFromCaja = $canLoanFromCaja ?? false;
$canCompras = !$isPosRole && \SoftNova\Core\TenantMiddleware::canAccess('compras');
?>
<!-- CAJA ABIERTA -->
<?php if (($stockAlert['count'] ?? 0) > 0): ?>
<div class="alert alert-warning alert-stock-pending" style="margin-bottom:14px;">
    <strong>Inventario pendiente:</strong>
    <?php echo (int)$stockAlert['count']; ?> producto(s) con stock negativo o unidades por reponer/devolver
    (negativos: <?php echo (int)($stockAlert['negative_count'] ?? 0); ?> ·
    préstamos salida: <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> ·
    nos prestaron: <?php echo (int)($stockAlert['loan_received_count'] ?? 0); ?> ·
    ventas sin stock: <?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?>).
    <?php if (!$isPosRole && \SoftNova\Core\TenantMiddleware::canAccess('prestamos')): ?>
        <a href="<?php echo $viewInstance->route('app/prestamos'); ?>">Ver detalle</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
if (!$isPosRole) {
    $pageTopStatsHtml = '
<div class="page-top-stat" title="Fondo con el que se abrió el cajón (no es venta)"><h4>Base cajón</h4><div class="stat-value">' . htmlspecialchars(fmt((float)$openSession['opening_amount'], $currency)) . '</div></div>
<div class="page-top-stat" title="Cobros en efectivo de ventas del día (sin la base)"><h4>Efectivo ventas</h4><div class="stat-value page-top-stat--in" id="cajaStatCashSales">+' . htmlspecialchars(fmt((float)($totals['cash_sales'] ?? 0), $currency)) . '</div></div>
<div class="page-top-stat" title="Cobros en Nequi, transferencia, tarjetas y otros (no efectivo)"><h4>Otros medios</h4><div class="stat-value page-top-stat--in" id="cajaStatOtherIncomes">+' . htmlspecialchars(fmt((float)($totals['other_incomes'] ?? 0), $currency)) . '</div></div>
<div class="page-top-stat"><h4>Egresos</h4><div class="stat-value page-top-stat--out" id="cajaStatExpenses">-' . htmlspecialchars(fmt((float)$totals['expenses'], $currency)) . '</div></div>
<div class="page-top-stat" title="Base + efectivo del día − egresos (lo que debe haber en el cajón)"><h4>En cajón</h4><div class="stat-value ' . ((($totals['expected_cash'] ?? $totals['balance'] ?? 0) >= 0) ? 'page-top-stat--in' : 'page-top-stat--out') . '" id="cajaStatBalance">' . htmlspecialchars(fmt((float)($totals['expected_cash'] ?? $totals['balance'] ?? 0), $currency)) . '</div></div>';
}
?>

<div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <div style="flex:1;min-width:200px;">
        <span style="color:var(--color-text-secondary);font-size:13px;">
            Abierta por: <strong><?php echo htmlspecialchars($openSession['user_name']); ?></strong> · <?php echo date('d/m/Y H:i', strtotime($openSession['opening_date'])); ?>
        </span>
        <span class="badge <?php echo $warningClass; ?>" style="margin-left:8px;">
            ⏱️ <?php echo $hours; ?>h abierta
            <?php if ($hours > 20): ?> ⚠️ ¡Cierre antes de 24h!<?php endif; ?>
        </span>
        <?php if ($hours > 22): ?>
        <div class="alert alert-error" style="margin-top:8px;font-size:13px;">
            ⚠️ <strong>¡Atención!</strong> La caja lleva más de 22 horas abierta. Debe cerrarse antes de 24 horas.
        </div>
        <?php endif; ?>
        <?php if ($isAdminViewer): ?>
        <div class="alert alert-info" style="margin-top:8px;font-size:13px;">
            Vista administrador: ingresos, egresos y ventas de <strong>todos los usuarios</strong> en una sola caja del negocio
            <?php if ($openSessionsCount > 1): ?>(<?php echo (int)$openSessionsCount; ?> sesiones abiertas unificadas)<?php endif; ?>.
            Los movimientos se sincronizan en tiempo real.
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canExpense && !$canCompras): ?>
    <button type="button" class="btn btn-primary neumorphic-btn" onclick="document.getElementById('expenseModal').style.display='flex'">+ Registrar gasto</button>
    <?php elseif ($canExpense || $canCompras): ?>
    <div class="pos-gasto-menu" style="position:relative;display:inline-block;">
        <button type="button" class="btn btn-primary neumorphic-btn" id="posGastoMenuBtn" onclick="var m=document.getElementById('posGastoDropdown');m.style.display=m.style.display==='block'?'none':'block';">+ Gasto<?php echo $canCompras ? ' / Compra' : ''; ?></button>
        <div id="posGastoDropdown" style="display:none;position:absolute;right:0;top:110%;background:var(--bg-card);border:1px solid var(--color-border);border-radius:8px;min-width:200px;z-index:50;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:6px;">
            <?php if ($canExpense): ?>
                <button type="button" class="btn btn-secondary" style="width:100%;margin-bottom:4px;justify-content:flex-start;" onclick="document.getElementById('posGastoDropdown').style.display='none';document.getElementById('expenseModal').style.display='flex'">Registrar gasto</button>
            <?php endif; ?>
            <?php if ($canCompras): ?>
                <a href="<?php echo $viewInstance->route('app/compras'); ?>" class="btn btn-secondary" style="width:100%;justify-content:flex-start;display:flex;">Ir a Compras</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($canLoanFromCaja)): ?>
    <button type="button" class="btn btn-secondary neumorphic-btn" onclick="openCajaLoanModal()" title="Registrar salida por préstamo">+ Préstamo</button>
    <?php endif; ?>
    <?php if (empty($canManageCashSession)): ?>
    <span class="badge badge-info" style="font-size:12px;" title="Solo quien abrió la caja, el administrador o Gerencia pueden cerrarla">
        Cierre: <?php echo htmlspecialchars((string)$openSession['user_name']); ?>
    </span>
    <?php elseif (!$isPosRole || \SoftNova\Core\TenantMiddleware::canDo('edit', 'caja')): ?>
    <button type="button" onclick="openCloseCashModal()" class="btn btn-danger"
            data-balance="<?php echo htmlspecialchars((string)round((float)($totals['expected_cash'] ?? $totals['balance'] ?? 0), 2)); ?>"
            data-opening="<?php echo htmlspecialchars(fmt($openSession['opening_amount'], $currency)); ?>"
            data-cash-sales="<?php echo htmlspecialchars(fmt((float)($totals['cash_sales'] ?? 0), $currency)); ?>"
            data-other-cash="<?php echo htmlspecialchars(fmt((float)($totals['other_cash_incomes'] ?? 0), $currency)); ?>"
            data-expenses="<?php echo htmlspecialchars(fmt($totals['expenses'], $currency)); ?>"
            data-sales-count="<?php echo (int)($totals['sales_count'] ?? 0); ?>"
            data-sales-total="<?php echo htmlspecialchars(fmt($totals['sales_total'] ?? 0, $currency)); ?>"
            data-sales-subtotal="<?php echo htmlspecialchars(fmt($totals['sales_subtotal_before_discount'] ?? 0, $currency)); ?>"
            data-sales-discount="<?php echo htmlspecialchars(fmt($totals['sales_discount_total'] ?? 0, $currency)); ?>"
            data-balance-label="<?php echo htmlspecialchars(fmt((float)($totals['expected_cash'] ?? $totals['balance'] ?? 0), $currency)); ?>"
            id="btnOpenCloseCash">Cerrar Caja</button>
    <?php endif; ?>
</div>

<?php
$posCustomers = $posCustomers ?? [];
$paymentMethods = $paymentMethods ?? \SoftNova\Services\PaymentMethodCatalog::all();
$invoicePrefix = $invoicePrefix ?? 'FAC-';
$csrfPos = \SoftNova\Core\csrf_token();
$searchUrl = $viewInstance->route('app/caja') . '?action=searchProducts';
$catalogUrl = $viewInstance->route('app/caja') . '?action=catalogSnapshot';
$combosUrl = $viewInstance->route('app/caja');
$saleUrl = $viewInstance->route('app/ventas') . '?action=create';
$syncUrl = $viewInstance->route('app/ventas') . '?action=sync_offline';
$ventasBase = $viewInstance->route('app/ventas');
$canSell = \SoftNova\Core\TenantMiddleware::canAccess('ventas') && \SoftNova\Core\TenantMiddleware::canDo('create', 'ventas');
$canDeleteSale = \SoftNova\Core\TenantMiddleware::canDo('delete', 'ventas');
$canEditPaymentMethod = \SoftNova\Core\TenantMiddleware::canDo('create', 'ventas')
    || \SoftNova\Core\TenantMiddleware::canDo('edit', 'ventas');
$canCreateCustomer = \SoftNova\Core\TenantMiddleware::canDo('create', 'clientes');
?>

<meta name="currency-symbol" content="<?php echo htmlspecialchars($currency['symbol'] ?? '$'); ?>">
<meta name="currency-decimals" content="<?php echo (int)($currency['decimals'] ?? 0); ?>">

<?php if ($canSell): ?>
<!-- POS rápido (venta en caja) -->
<section class="pos-panel" id="posPanel"
         data-search-url="<?php echo htmlspecialchars($searchUrl); ?>"
         data-catalog-url="<?php echo htmlspecialchars($catalogUrl); ?>"
         data-combos-url="<?php echo htmlspecialchars($combosUrl); ?>"
         data-sale-url="<?php echo htmlspecialchars($saleUrl); ?>"
         data-sync-url="<?php echo htmlspecialchars($syncUrl); ?>"
         data-csrf="<?php echo htmlspecialchars($csrfPos); ?>"
         data-symbol="<?php echo htmlspecialchars($currency['symbol'] ?? '$'); ?>"
         data-decimals="<?php echo (int)($currency['decimals'] ?? 0); ?>"
         data-thousands="<?php echo htmlspecialchars($currency['thousands'] ?? '.'); ?>"
         data-decimal="<?php echo htmlspecialchars($currency['decimal'] ?? ','); ?>"
         data-prefix="<?php echo htmlspecialchars((string)$invoicePrefix); ?>"
         data-user="<?php echo htmlspecialchars($userName); ?>"
         data-auto-print-ticket="<?php echo !empty($autoPrintTicket) ? '1' : '0'; ?>"
         data-ticket-print-base="<?php echo htmlspecialchars($ventasBase); ?>">
    <div class="pos-sale card neumorphic">
        <div class="pos-sale-head">
            <div class="pos-sale-meta">
                <label class="pos-label">Cliente</label>
                <div class="pos-customer-row" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-bottom:8px;">
                    <div class="customer-combobox" id="posCustomerCombobox" style="flex:1;min-width:180px;position:relative;">
                        <input type="hidden" id="posCustomer" value="">
                        <input type="text" id="posCustomerSearch" class="form-control" placeholder="Buscar cliente..." autocomplete="off">
                        <ul class="combobox-list" id="posCustomerList" hidden style="max-height:220px;overflow:auto;">
                            <li class="combobox-option" data-id="" data-label="Cliente Final" data-search="cliente final consumidor">Cliente Final</li>
                            <?php foreach ($posCustomers as $c):
                                $cname = trim((string)($c['name'] ?? ''));
                                if ($cname === '') {
                                    $cname = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                                }
                                if ($cname === '') {
                                    $cname = 'Cliente #' . $c['id'];
                                }
                                $search = strtolower($cname . ' ' . ($c['document_number'] ?? '') . ' ' . ($c['phone'] ?? ''));
                            ?>
                                <li class="combobox-option" data-id="<?php echo (int)$c['id']; ?>" data-label="<?php echo htmlspecialchars($cname); ?>" data-search="<?php echo htmlspecialchars($search); ?>">
                                    <?php echo htmlspecialchars($cname); ?>
                                    <?php if (!empty($c['document_number'])): ?>
                                        <small><?php echo htmlspecialchars($c['document_number']); ?></small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($canCreateCustomer): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="posNewCustomerBtn" onclick="document.getElementById('quickCustomerModal').style.display='flex'" title="Nuevo cliente">+ Nuevo</button>
                    <?php endif; ?>
                </div>
                <div class="pos-discount-row">
                    <div class="pos-discount-left">
                        <label class="pos-label" for="posDiscountPreset" style="margin:0;">Descuento %</label>
                        <select id="posDiscountPreset" class="form-control" style="width:110px;" title="Descuento solo sobre el precio del producto (no afecta el IVA)" data-custom-other="discount_percent">
                            <option value="0" selected>0%</option>
                            <option value="5">5%</option>
                            <option value="6">6%</option>
                            <option value="7">7%</option>
                            <option value="8">8%</option>
                            <option value="9">9%</option>
                            <option value="10">10%</option>
                            <option value="15">15%</option>
                            <?php
                            try {
                                $discOpts = (new \SoftNova\Services\CustomOptionService(\SoftNova\Core\TenantMiddleware::getDb()))
                                    ->custom(\SoftNova\Services\CustomOptionService::GROUP_DISCOUNT_PERCENT);
                                foreach ($discOpts as $dOpt) {
                                    echo '<option value="' . htmlspecialchars((string)$dOpt['value']) . '">'
                                        . htmlspecialchars((string)$dOpt['label']) . '</option>';
                                }
                            } catch (\Throwable $e) {
                            }
                            ?>
                            <option value="other">Otro</option>
                        </select>
                        <span id="posDiscountOtherWrap" style="display:none;">
                            <input type="number" id="posDiscountPercent" class="form-control" min="0" max="100" step="0.01" value="0" style="width:90px;" placeholder="Otro %" title="Porcentaje manual">
                        </span>
                        <label class="pos-label" for="posDeliveryTotal" style="margin:0 0 0 6px;">Domicilio</label>
                        <input type="text" id="posDeliveryTotal" class="form-control money-input" data-money="1" value="0" style="width:110px;" placeholder="Total" inputmode="decimal" title="Costo total del domicilio / envío">
                    </div>
                    <div class="pos-meta-side">
                        <div class="pos-attendant">
                            <span class="pos-label">Atendido</span>
                            <strong id="posAttendant"><?php echo htmlspecialchars(mb_strtoupper($userName)); ?></strong>
                        </div>
                        <div class="pos-invoice-side">
                            <span class="pos-label">Factura</span>
                            <strong id="posInvoicePreview"><?php echo htmlspecialchars((string)$invoicePrefix . date('Ymd') . '-····'); ?></strong>
                        </div>
                    </div>
                </div>
                <div id="posDeliverySplit" class="pos-delivery-split" style="display:none;width:100%;margin:0 0 8px;gap:8px;align-items:center;flex-wrap:wrap;">
                    <span class="pos-label" style="margin:0;">División domicilio (sugerido 50/50)</span>
                        <label class="pos-label" for="posDeliveryClient" style="margin:0;">Cliente</label>
                        <input type="text" id="posDeliveryClient" class="form-control money-input" data-money="1" value="0" style="width:100px;" placeholder="0" inputmode="decimal" title="Parte del cliente que suma al total de la venta">
                        <label class="pos-label" for="posDeliveryCompany" style="margin:0;">Empresa</label>
                        <input type="text" id="posDeliveryCompany" class="form-control money-input" data-money="1" value="0" style="width:100px;" placeholder="0" inputmode="decimal" title="Parte empresa → gasto Transporte (no suma al total de la venta)">
                        <span id="posDeliverySplitHint" class="pos-label" style="margin:0;opacity:.75;font-weight:400;">Cliente → total venta · Empresa → gasto Transporte</span>
                </div>
                <div class="pos-ticket-meta">
                    <span>Items: <strong id="posItemCount">0</strong></span>
                    <span>Registro: <strong id="posClock"><?php echo date('g:i A'); ?></strong></span>
                </div>
                <div id="posSubtotalLine" class="pos-sub-line" style="display:none;">Subtotal: <span id="posSubtotal"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posDiscountLine" class="pos-sub-line" style="display:none;color:var(--dmc-B45309, #B45309);">Descuento: <span id="posDiscountAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posDeliveryLine" class="pos-sub-line" style="display:none;color:var(--dmc-1D4ED8, #1D4ED8);">Domicilio: <span id="posDeliveryAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posDeliveryCompanyLine" class="pos-sub-line" style="display:none;color:var(--dmc-7C3AED, #7C3AED);">Empresa (transporte): <span id="posDeliveryCompanyAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posTaxLine" class="pos-sub-line" style="display:none;color:var(--dmc-0F766E, #0F766E);">Impuesto: <span id="posTaxAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posCreditSurchargeLine" class="pos-sub-line" style="display:none;color:var(--dmc-B45309, #B45309);">Recargo crédito: <span id="posCreditSurchargeAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div class="pos-total-block">
                    <div class="pos-total-line">TOTAL: <span id="posTotal"><?php echo fmt(0, $currency); ?></span></div>
                    <div class="pos-cash-change" id="posCashChangeBlock">
                        <div class="pos-cash-field">
                            <label class="pos-label" for="posCashReceived">Billete / recibido</label>
                            <input type="text" id="posCashReceived" class="form-control money-input" data-money="1" placeholder="0" inputmode="decimal"
                                   oninput="if(window.SoftNovaPos&amp;&amp;SoftNovaPos.updateCashChange)SoftNovaPos.updateCashChange()"
                                   onkeyup="if(window.SoftNovaPos&amp;&amp;SoftNovaPos.updateCashChange)SoftNovaPos.updateCashChange()"
                                   onchange="if(window.SoftNovaPos&amp;&amp;SoftNovaPos.updateCashChange)SoftNovaPos.updateCashChange()">
                        </div>
                        <div class="pos-cash-field">
                            <label class="pos-label">Cambio / vueltas</label>
                            <div class="pos-change-value" id="posCashChange"><?php echo fmt(0, $currency); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="pos-pay-btn" id="posPayBtn" disabled>Listo</button>
        </div>
        <div class="pos-items-wrap">
            <table class="pos-items-table">
                <thead>
                    <tr><th style="width:70px;">Cant</th><th>Producto</th><th style="width:110px;">Acción</th></tr>
                </thead>
                <tbody id="posItemsBody">
                    <tr class="pos-empty-row"><td colspan="3">Escanee o busque productos a la derecha</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pos-pay-bar" id="posPayBar">
            <div style="display:flex;flex-wrap:wrap;gap:8px;width:100%;align-items:end;">
                <div class="form-group" style="margin:0;min-width:120px;">
                    <label style="font-size:12px;">Tipo</label>
                    <select id="posPaymentType" class="form-control">
                        <option value="full">Contado</option>
                        <option value="credit">Crédito</option>
                    </select>
                </div>
                <div class="form-group" id="posTermsGroup" style="margin:0;min-width:120px;display:none;">
                    <label style="font-size:12px;">Plazo</label>
                    <select id="posPaymentTerms" class="form-control" data-custom-other="credit_term">
                        <?php
                        try {
                            echo (new \SoftNova\Services\CustomOptionService(\SoftNova\Core\TenantMiddleware::getDb()))
                                ->optionsHtml(\SoftNova\Services\CustomOptionService::GROUP_CREDIT_TERM, 'net_30', true);
                        } catch (\Throwable $e) {
                            echo '<option value="net_15">15 días</option>'
                                . '<option value="net_30" selected>30 días</option>'
                                . '<option value="net_60">60 días</option>'
                                . '<option value="net_90">90 días</option>'
                                . '<option value="net_120">120 días</option>'
                                . '<option value="overdue">Vencido</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group" id="posInitialGroup" style="margin:0;min-width:110px;display:none;">
                    <label style="font-size:12px;">Abono inicial</label>
                    <input type="text" id="posInitialPayment" class="form-control money-input" data-money="1" inputmode="decimal" value="0">
                </div>
                <label class="pos-mixed-toggle" for="posWholesaleMode" style="display:inline-flex;align-items:center;gap:6px;margin:0 4px 2px;font-size:13px;cursor:pointer;user-select:none;padding:6px 10px;border:1px solid var(--color-border);border-radius:8px;background:var(--bg-input,#f8fafc);" title="Usa el precio al por mayor del producto">
                    <input type="checkbox" id="posWholesaleMode" style="width:16px;height:16px;">
                    Al por mayor
                </label>
                <div class="form-group" style="margin:0;min-width:140px;flex:1;" id="posSinglePayWrap">
                    <label style="font-size:12px;">Método de pago</label>
                    <select id="posPayMethod" class="form-control" data-custom-other="payment_method">
                        <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?>
                    </select>
                </div>
                <label class="pos-mixed-toggle" for="posMixedPay" style="display:inline-flex;align-items:center;gap:6px;margin:0 4px 2px;font-size:13px;cursor:pointer;user-select:none;padding:6px 10px;border:1px solid var(--color-border);border-radius:8px;background:var(--bg-input,#f8fafc);">
                    <input type="checkbox" id="posMixedPay" style="width:16px;height:16px;">
                    Pago mixto
                </label>
                <button type="button" class="btn btn-success" id="posConfirmPay" disabled>Cobrar ahora</button>
                <button type="button" class="btn btn-secondary" id="posCancelPay">Cancelar</button>
            </div>
            <div class="form-group" style="margin:8px 0 0;width:100%;">
                <label style="font-size:12px;" for="posSaleNotes">Observación / nota <span class="field-tip" data-tip="Queda guardada en la venta y aparece en Ventas y en el reporte de cierre de caja.">?</span></label>
                <input type="text" id="posSaleNotes" class="form-control" maxlength="255" placeholder="Ej: mesa 3, entrega mañana, referencia…" autocomplete="off">
            </div>
            <div id="posMixedPayWrap" class="pos-mixed-wrap" style="display:none;">
                <div id="posMixedRows" class="pos-mixed-rows"></div>
                <div class="pos-mixed-actions">
                    <button type="button" class="btn btn-sm btn-secondary" id="posMixedAdd">+ Agregar método</button>
                    <span id="posMixedSummary" class="pos-mixed-summary" title="Al editar un monto, el otro se completa con el restante"></span>
                </div>
                <select id="posPayMethodOptionsTpl" hidden aria-hidden="true" tabindex="-1">
                    <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', false, \SoftNova\Core\TenantMiddleware::getDb()); ?>
                </select>
            </div>
        </div>
    </div>

    <div class="pos-search card neumorphic">
        <div class="pos-search-box">
            <span class="pos-search-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" id="posProductSearch" class="pos-search-input" placeholder="Código de barras o nombre…" autocomplete="off" autofocus data-barcode-input="true">
            <button type="button" class="pos-search-clear" id="posSearchClear" title="Limpiar">&times;</button>
        </div>
        <?php
        $recentProducts = $recentProducts ?? [];
        $openItemQuick = null;
        $recentWithoutOpen = [];
        foreach ($recentProducts as $rp) {
            $isOpenRp = !empty($rp['is_open_item'])
                || strtoupper((string)($rp['code'] ?? '')) === \SoftNova\Services\OpenItemProduct::CODE;
            if ($isOpenRp && $openItemQuick === null) {
                $openItemQuick = $rp;
                continue;
            }
            if (!$isOpenRp) {
                $recentWithoutOpen[] = $rp;
            }
        }
        if ($openItemQuick === null) {
            try {
                $ensured = \SoftNova\Services\OpenItemProduct::ensure(\SoftNova\Core\TenantMiddleware::getDb());
                $openItemQuick = [
                    'id' => (int)$ensured['id'],
                    'name' => (string)($ensured['name'] ?? 'Producto comodín'),
                    'code' => \SoftNova\Services\OpenItemProduct::CODE,
                    'product_type' => 'service',
                    'is_open_item' => 1,
                ];
            } catch (\Throwable $e) {
                $openItemQuick = null;
            }
        }
        ?>
        <?php
        $consignmentProducts = $consignmentProducts ?? [];
        // Barra rápida: siempre visible si hay POS (comodín / consignación / combos).
        ?>
        <div class="pos-open-item-bar">
            <?php if ($openItemQuick): ?>
            <button type="button"
                    class="btn btn-success pos-open-item-btn"
                    id="posOpenItemBtn"
                    data-id="<?php echo (int)$openItemQuick['id']; ?>"
                    data-name="<?php echo \SoftNova\Core\e((string)($openItemQuick['name'] ?? 'Producto comodín')); ?>"
                    data-code="<?php echo \SoftNova\Core\e(\SoftNova\Services\OpenItemProduct::CODE); ?>"
                    data-type="service"
                    data-open-item="1"
                    title="Venta libre: pide nombre y precio, no descuenta stock">
                Nuevo P. Comodin
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary pos-combo-btn" id="posCombosBtn" title="Vender o crear combos (varios productos a un precio)">
                Combos
            </button>
            <label class="pos-consign-select-wrap" title="Mercancía de terceros (no inventario propio)">
                <span class="pos-consign-select-label">Consignación</span>
                <select id="posConsignmentSelect" class="form-control pos-consign-select" <?php echo empty($consignmentProducts) ? 'disabled' : ''; ?>>
                    <option value="">
                        <?php echo empty($consignmentProducts)
                            ? 'Sin productos en consignación'
                            : 'Agregar consignado… (' . count($consignmentProducts) . ')'; ?>
                    </option>
                    <?php foreach ($consignmentProducts as $cp):
                        $cpLabel = trim(
                            (($cp['code'] ?? '') !== '' ? ($cp['code'] . ' — ') : '')
                            . ($cp['name'] ?? '')
                        );
                        $cpConsignor = trim((string)($cp['consignor_name'] ?? ''));
                        $cpStock = (int)($cp['stock'] ?? 0);
                        $cpPrice = (float)($cp['sale_price'] ?? 0);
                        $optText = $cpLabel
                            . ' · stock ' . $cpStock
                            . ' · ' . fmt($cpPrice, $currency)
                            . ($cpConsignor !== '' ? (' · ' . $cpConsignor) : '');
                    ?>
                    <option value="<?php echo (int)$cp['id']; ?>"
                            data-id="<?php echo (int)$cp['id']; ?>"
                            data-name="<?php echo \SoftNova\Core\e((string)($cp['name'] ?? '')); ?>"
                            data-code="<?php echo \SoftNova\Core\e((string)($cp['code'] ?? '')); ?>"
                            data-price="<?php echo $cpPrice; ?>"
                            data-wholesale="<?php echo (float)($cp['wholesale_price'] ?? 0); ?>"
                            data-cost="<?php echo (float)($cp['purchase_price'] ?? 0); ?>"
                            data-stock="<?php echo $cpStock; ?>"
                            data-type="<?php echo htmlspecialchars((string)($cp['product_type'] ?? 'product')); ?>"
                            data-tax-rate="<?php echo htmlspecialchars((string)($cp['tax_rate'] ?? 0)); ?>"
                            data-ownership="consignment"
                            data-consignor-id="<?php echo (int)($cp['consignor_id'] ?? 0); ?>"
                            data-is-consignment="1">
                        <?php echo \SoftNova\Core\e($optText); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span style="font-size:12px;color:var(--color-text-secondary);">
                <?php if ($openItemQuick): ?>Ítem libre · <?php endif; ?>combos · consignados
            </span>
        </div>
        <?php
        $offerProducts = $offerProducts ?? [];
        if (!empty($offerProducts)):
        ?>
        <div class="pos-offer-suggestions" id="posOfferSuggestions">
            <p class="pos-search-hint" style="margin:8px 0 6px;">Ofertas recientes</p>
            <?php foreach ($offerProducts as $op):
                $offerPrice = (float)($op['offer_price'] ?? $op['sale_price'] ?? 0);
                $origPrice = (float)($op['offer_original_price'] ?? 0);
                $pct = (int)($op['offer_percent'] ?? 0);
                $reason = (string)($op['offer_reason_label'] ?? '');
            ?>
            <div class="pos-result-row pos-recent-product pos-offer-product"
                 data-id="<?php echo (int)$op['id']; ?>"
                 data-name="<?php echo \SoftNova\Core\e((string)$op['name']); ?>"
                 data-code="<?php echo \SoftNova\Core\e((string)($op['code'] ?? '')); ?>"
                 data-price="<?php echo $offerPrice; ?>"
                 data-cost="<?php echo (float)($op['purchase_price'] ?? 0); ?>"
                 data-stock="<?php echo (int)($op['stock'] ?? 0); ?>"
                 data-type="<?php echo htmlspecialchars((string)($op['product_type'] ?? 'product')); ?>"
                 data-ownership="<?php echo htmlspecialchars((string)($op['ownership'] ?? 'owned')); ?>"
                 data-consignor-id="<?php echo (int)($op['consignor_id'] ?? 0); ?>"
                 data-tax-rate="<?php echo (float)($op['tax_rate'] ?? 0); ?>"
                 data-open-item="0">
                <div class="pos-result-info">
                    <div class="pos-result-title">
                        <span class="pos-offer-badge">Oferta<?php echo $pct > 0 ? (' -' . $pct . '%') : ''; ?></span>
                        <?php echo \SoftNova\Core\e((($op['code'] ?? '') !== '' ? ($op['code'] . ' — ') : '') . $op['name']); ?>
                    </div>
                    <div class="pos-result-meta">
                        Stock: <?php echo (int)($op['stock'] ?? 0); ?> ·
                        <?php if ($origPrice > $offerPrice && $origPrice > 0): ?>
                            <span class="pos-offer-old"><?php echo fmt($origPrice, $currency); ?></span>
                        <?php endif; ?>
                        <strong class="pos-offer-now"><?php echo fmt($offerPrice, $currency); ?></strong>
                        <?php if ($reason !== ''): ?>
                            <span class="pos-offer-reason">· <?php echo \SoftNova\Core\e($reason); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="pos-result-add" title="Agregar">+</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
        $suggestedCombos = $suggestedCombos ?? [];
        if (!empty($suggestedCombos)):
        ?>
        <div class="pos-combo-suggestions" id="posComboSuggestions">
            <p class="pos-search-hint" style="margin:8px 0 6px;">Combos sugeridos</p>
            <?php foreach ($suggestedCombos as $sc): ?>
            <div class="pos-result-row pos-combo-chip"
                 data-combo-id="<?php echo (int)$sc['id']; ?>"
                 role="button"
                 tabindex="0"
                 title="Agregar combo a la venta">
                <div class="pos-result-info">
                    <div class="pos-result-title">
                        <span class="pos-combo-badge">Combo</span>
                        <?php echo \SoftNova\Core\e((string)$sc['name']); ?>
                    </div>
                    <div class="pos-result-meta">
                        <?php echo (int)($sc['item_count'] ?? 0); ?> productos ·
                        <strong><?php echo fmt((float)($sc['sale_price'] ?? 0), $currency); ?></strong>
                        <?php if (!empty($sc['reason_label'])): ?>
                            · <?php echo \SoftNova\Core\e((string)$sc['reason_label']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="pos-result-add pos-combo-apply-btn" data-combo-id="<?php echo (int)$sc['id']; ?>" title="Agregar combo">+</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="pos-search-results" id="posSearchResults">
            <?php if (!empty($recentWithoutOpen)): ?>
                <p class="pos-search-hint" style="margin-bottom:8px;">Últimos vendidos</p>
                <?php foreach ($recentWithoutOpen as $rp): ?>
                    <div class="pos-result-row pos-recent-product"
                         data-id="<?php echo (int)$rp['id']; ?>"
                         data-name="<?php echo \SoftNova\Core\e((string)$rp['name']); ?>"
                         data-code="<?php echo \SoftNova\Core\e((string)($rp['code'] ?? '')); ?>"
                         data-price="<?php echo (float)$rp['sale_price']; ?>"
                         data-cost="<?php echo (float)($rp['purchase_price'] ?? 0); ?>"
                         data-stock="<?php echo (int)$rp['stock']; ?>"
                         data-type="<?php echo htmlspecialchars((string)($rp['product_type'] ?? 'product')); ?>"
                         data-ownership="<?php echo htmlspecialchars((string)($rp['ownership'] ?? 'owned')); ?>"
                         data-consignor-id="<?php echo (int)($rp['consignor_id'] ?? 0); ?>"
                         data-tax-rate="<?php echo (float)($rp['tax_rate'] ?? 0); ?>"
                         data-open-item="0">
                        <div class="pos-result-info">
                            <div class="pos-result-title"><?php echo \SoftNova\Core\e((($rp['code'] ?? '') !== '' ? ($rp['code'] . ' — ') : '') . $rp['name']); ?></div>
                            <div class="pos-result-meta">
                                Stock: <?php echo (int)$rp['stock']; ?> · <?php echo fmt((float)$rp['sale_price'], $currency); ?>
                            </div>
                        </div>
                        <button type="button" class="pos-result-add" title="Agregar">+</button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pos-search-hint">Escriba o escanee un código para encontrar productos</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<div class="alert alert-warning" style="margin-bottom:20px;">No tiene permiso para registrar ventas desde caja.</div>
<?php endif; ?>

<!-- Ventas de la sesión -->
<div class="card neumorphic" style="margin-bottom:20px;">
    <div class="card-header"><h3>🛒 Últimas ventas<?php
        $salesPagination = $salesPagination ?? null;
        echo $salesPagination ? ' (' . (int)$salesPagination['total'] . ')' : ' (' . count($todaySales) . ')';
    ?></h3></div>
    <div class="card-body">
        <?php if (empty($todaySales)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay ventas en esta sesión</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th>Factura</th><th>Cliente</th><th>Usuario</th><th>Fecha</th><th>Total</th><th>Pagado</th><th>Pago</th><th>NC</th><th>Estado</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($todaySales as $sale): ?>
                    <?php
                    $cajaNcTot = round((float)($sale['credit_note_total'] ?? 0), 2);
                    $cajaSaleTot = round((float)($sale['total'] ?? 0), 2);
                    $cajaNcFull = $cajaNcTot > 0.009 && $cajaNcTot >= ($cajaSaleTot - 0.02);
                    $cajaNcTip = trim((string)($sale['credit_note_numbers'] ?? ''));
                    if ($cajaNcTip === '' && $cajaNcTot > 0.009) {
                        $cajaNcTip = 'Nota(s) de crédito';
                    }
                    $cajaSaleObs = \SoftNova\Services\SalesDocumentService::displayNotes((string)($sale['notes'] ?? ''));
                    ?>
                    <tr<?php echo $cajaNcFull ? ' style="opacity:0.85;"' : ''; ?>>
                        <td>
                            <strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong>
                            <?php if ($cajaNcFull): ?>
                                <div><span class="badge badge-warning" title="<?php echo htmlspecialchars($cajaNcTip); ?>">Devuelta</span></div>
                            <?php elseif ($cajaNcTot > 0.009): ?>
                                <div><span class="badge badge-info" title="<?php echo htmlspecialchars($cajaNcTip); ?>">Con NC</span></div>
                            <?php endif; ?>
                            <?php if ($cajaSaleObs !== ''): ?>
                                <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;max-width:200px;" title="<?php echo htmlspecialchars($cajaSaleObs); ?>">
                                    <?php echo htmlspecialchars(mb_strlen($cajaSaleObs) > 70 ? mb_substr($cajaSaleObs, 0, 67) . '…' : $cajaSaleObs); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Cliente Final'); ?></td>
                        <td><?php echo htmlspecialchars((string)($sale['user_name'] ?? '—')); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></td>
                        <td style="font-weight:600;">
                            <?php echo fmt($cajaSaleTot, $currency); ?>
                            <?php if ($cajaNcTot > 0.009): ?>
                                <div style="font-size:11px;color:var(--color-text-secondary);">Neto <?php echo fmt(max(0, $cajaSaleTot - $cajaNcTot), $currency); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo ($sale['payment_status'] ?? '') === 'paid' ? fmt($cajaSaleTot, $currency) : fmt((float)($sale['paid_amount'] ?? 0), $currency); ?></td>
                        <td>
                            <span class="badge <?php
                                $pm = (string)($sale['payment_method'] ?? '');
                                echo $pm === 'cash' ? 'badge-success' : ($pm === 'mixed' || in_array($pm, ['card','dataphone','payment_link','transfer','debit_card','credit_card','nequi'], true) ? 'badge-info' : 'badge-warning');
                            ?>">
                                <?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'))); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($cajaNcTot > 0.009): ?>
                                <span class="badge badge-warning" title="<?php echo htmlspecialchars($cajaNcTip); ?>">
                                    NC <?php echo fmt($cajaNcTot, $currency); ?>
                                </span>
                                <?php if ($cajaNcTip !== ''): ?>
                                    <div style="font-size:11px;color:var(--color-text-secondary);"><?php echo htmlspecialchars($cajaNcTip); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--color-text-secondary);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($sale['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge badge-danger">Cancelado</span>
                            <?php elseif ($cajaNcFull): ?>
                                <span class="badge badge-warning">Devuelta</span>
                            <?php elseif (($sale['payment_status'] ?? '') === 'paid'): ?>
                                <span class="badge badge-success">Pagado</span>
                            <?php elseif (($sale['payment_status'] ?? '') === 'partial'): ?>
                                <span class="badge badge-warning">Parcial</span>
                            <?php else: ?>
                                <span class="badge badge-info">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <button type="button" onclick="viewDetail(<?php echo (int)$sale['id']; ?>)" class="btn btn-sm btn-info" title="Ver detalle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            <a href="<?php echo $ventasBase; ?>?action=pdf&id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="PDF carta"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg></a>
                            <a href="<?php echo $ventasBase; ?>?action=pdf&format=print&id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="Imprimir tirilla 58mm">58mm</a>
                            <button type="button" class="btn btn-sm btn-success" onclick="shareSale(<?php echo (int)$sale['id']; ?>)" title="WhatsApp / Correo">↗</button>
                            <?php if ($canEditPaymentMethod && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-secondary" title="Editar medio de pago"
                                    onclick="openEditPaymentMethodModal(<?php echo (int)$sale['id']; ?>, '<?php echo htmlspecialchars((string)($sale['invoice_number'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string)($sale['payment_method'] ?? 'cash'), ENT_QUOTES); ?>')">
                                    Pago
                                </button>
                            <?php endif; ?>
                            <?php if (in_array($sale['payment_status'] ?? '', ['pending', 'partial'], true) && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" onclick="openPaymentModal(<?php echo (int)$sale['id']; ?>,<?php echo (float)$sale['total']; ?>,<?php echo (float)($sale['paid_amount'] ?? 0); ?>,<?php echo (float)($sale['credit_note_total'] ?? 0); ?>)" class="btn btn-sm btn-success" title="Registrar abono"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></button>
                            <?php endif; ?>
                            <?php if ($canDeleteSale && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-danger" title="Eliminar venta"
                                    onclick="openCancelSaleModal(<?php echo (int)$sale['id']; ?>, '<?php echo htmlspecialchars($sale['invoice_number'] ?? '', ENT_QUOTES); ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $pagination = $salesPagination;
            $paginationBaseUrl = $viewInstance->route('app/caja');
            $paginationQuery = [];
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>

<?php
$payBreakDashboard = $totals['payment_breakdown'] ?? [];
$payBreakTotalSum = 0.0;
foreach ($payBreakDashboard as $pbRow) {
    $payBreakTotalSum += (float)($pbRow['total'] ?? 0);
}
?>
<?php if (!empty($payBreakDashboard)): ?>
<div class="card neumorphic" style="margin-bottom:20px;padding:14px 16px;">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
        <h3 style="margin:0;font-size:15px;color:var(--color-accent-text, var(--color-primary));">Totales cobrados por medio de pago</h3>
        <span style="font-size:12px;color:var(--color-text-secondary);">
            Suma de cobros de la sesión · Total <?php echo fmt($payBreakTotalSum, $currency); ?>
        </span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;">
        <?php foreach ($payBreakDashboard as $code => $pb): ?>
            <?php
            $isCash = ($code === 'cash');
            $cardBg = $isCash ? 'rgba(16,185,129,.08)' : 'var(--bg-body,#f8f8f8)';
            $cardBorder = $isCash ? '#10B981' : 'var(--color-border)';
            ?>
            <div style="background:<?php echo $cardBg; ?>;border:1px solid <?php echo $cardBorder; ?>;border-radius:8px;padding:10px 12px;">
                <div style="font-size:12px;color:var(--color-text-secondary);margin-bottom:4px;">
                    <?php echo htmlspecialchars((string)($pb['label'] ?? $code)); ?>
                </div>
                <div style="font-size:18px;font-weight:700;line-height:1.2;">
                    <?php echo fmt((float)($pb['total'] ?? 0), $currency); ?>
                </div>
                <div style="font-size:11px;color:var(--color-text-secondary);margin-top:4px;">
                    <?php echo (int)($pb['count'] ?? 0); ?> cobro(s)
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (isset($totals['expected_cash'])): ?>
    <p style="margin:12px 0 0;font-size:12px;color:var(--color-text-secondary);">
        En cajón = base <?php echo fmt((float)$openSession['opening_amount'], $currency); ?>
        + efectivo ventas <?php echo fmt((float)($totals['cash_sales'] ?? 0), $currency); ?>
        <?php if ((float)($totals['other_cash_incomes'] ?? 0) > 0.009): ?>
        + otros ingresos <?php echo fmt((float)$totals['other_cash_incomes'], $currency); ?>
        <?php endif; ?>
        − egresos <?php echo fmt((float)$totals['expenses'], $currency); ?>
        <?php if ((float)($totals['purchases_cash'] ?? 0) > 0.009): ?>
        (incluye compras OC/proveedores <?php echo fmt((float)$totals['purchases_cash'], $currency); ?>)
        <?php endif; ?>
        → <strong style="color:#10B981;"><?php echo fmt((float)$totals['expected_cash'], $currency); ?></strong>
        — los demás medios no van al cajón físico.
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$todayExpensesByUser = $todayExpensesByUser ?? [];
$todayExpensesTotal = (float)($todayExpensesTotal ?? 0);
$todayExpensesCount = (int)($todayExpensesCount ?? 0);
?>
<div class="card neumorphic" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h3>Gastos del cajón (efectivo)<?php echo $todayExpensesCount > 0 ? ' (' . $todayExpensesCount . ')' : ''; ?></h3>
        <?php if ($todayExpensesCount > 0): ?>
            <span style="font-size:13px;color:var(--dmc-DC2626, #DC2626);font-weight:600;">Total: -<?php echo fmt($todayExpensesTotal, $currency); ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($todayExpensesByUser)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay egresos en efectivo registrados en esta sesión de caja</p>
        <?php elseif ($isAdminViewer): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Usuario</th>
                            <th>Descripción</th>
                            <th>Categoría</th>
                            <th>Pago</th>
                            <th>Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $flatExpenses = [];
                    foreach ($todayExpensesByUser as $group) {
                        foreach ($group['items'] as $exp) {
                            $flatExpenses[] = $exp;
                        }
                    }
                    usort($flatExpenses, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
                    foreach ($flatExpenses as $exp):
                    ?>
                        <tr>
                            <td><?php
                                $ts = $exp['created_at'] ?? $exp['expense_date'] ?? '';
                                echo $ts !== '' ? date('H:i', strtotime((string)$ts)) : '—';
                            ?></td>
                            <td><?php echo htmlspecialchars((string)($exp['user_name'] ?? '—')); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$exp['description']); ?></strong>
                                <?php if (!empty($exp['supplier_name'])): ?>
                                    <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$exp['supplier_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars((string)($exp['category_label'] ?? 'General')); ?></td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)($exp['payment_method'] ?? 'cash'))); ?>
                                </span>
                            </td>
                            <td style="color:var(--dmc-DC2626, #DC2626);font-weight:600;">-<?php echo fmt((float)$exp['amount'], $currency); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?php foreach ($todayExpensesByUser as $group): ?>
                <div style="margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--color-border);">
                        <strong><?php echo htmlspecialchars((string)$group['user_name']); ?></strong>
                        <span style="color:var(--dmc-DC2626, #DC2626);font-weight:600;font-size:13px;">-<?php echo fmt((float)$group['total'], $currency); ?> · <?php echo count($group['items']); ?> gasto(s)</span>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Hora</th>
                                    <th>Descripción</th>
                                    <th>Categoría</th>
                                    <th>Pago</th>
                                    <th>Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['items'] as $exp): ?>
                                <tr>
                                    <td><?php
                                        $ts = $exp['created_at'] ?? $exp['expense_date'] ?? '';
                                        echo $ts !== '' ? date('H:i', strtotime((string)$ts)) : '—';
                                    ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string)$exp['description']); ?></strong>
                                        <?php if (!empty($exp['supplier_name'])): ?>
                                            <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$exp['supplier_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($exp['receipt_number'])): ?>
                                            <div style="font-size:12px;color:var(--color-text-secondary);">Comp: <?php echo htmlspecialchars((string)$exp['receipt_number']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($exp['category_label'] ?? 'General')); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)($exp['payment_method'] ?? 'cash'))); ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--dmc-DC2626, #DC2626);font-weight:600;">-<?php echo fmt((float)$exp['amount'], $currency); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($openSession): ?>
<?php
$cajaSessionUserId = (int)($openSession['user_id'] ?? 0);
$cajaSessionUserName = (string)($openSession['user_name'] ?? 'Caja');
?>
<div class="card neumorphic" id="cajaMovementsPanel">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h3>📋 Movimientos del Día (<span id="cajaMovementsCount"><?php echo count($movements); ?></span>)</h3>
        <span id="cajaLiveSyncBadge" style="font-size:12px;color:var(--color-text-secondary);">Sincronizando…</span>
    </div>
    <div class="card-body">
        <?php if (!empty($hiddenVoidedCount)): ?>
            <p id="cajaVoidedNotice" style="margin:0 0 10px;padding:8px 10px;background:var(--dmb-FFF7ED, #FFF7ED);border:1px solid #FED7AA;border-radius:8px;font-size:12px;color:var(--dmc-9A3412, #9A3412);">
                Se omitieron <?php echo (int)$hiddenVoidedCount; ?> movimiento(s) de compras/gastos ya anulados (neto $0 en el cajón).
            </p>
        <?php else: ?>
            <p id="cajaVoidedNotice" style="display:none;margin:0 0 10px;padding:8px 10px;background:var(--dmb-FFF7ED, #FFF7ED);border:1px solid #FED7AA;border-radius:8px;font-size:12px;color:var(--dmc-9A3412, #9A3412);"></p>
        <?php endif; ?>
        <?php if (empty($movements)): ?>
            <p id="cajaMovementsEmpty" style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay movimientos en esta sesión de caja</p>
            <div class="table-container" id="cajaMovementsTableWrap" style="display:none;"><table>
                <thead><tr><th>Hora</th><th>Usuario</th><th>Tipo</th><th>Descripción</th><th>Origen</th><th>Monto</th></tr></thead>
                <tbody id="cajaMovementsBody"></tbody>
            </table></div>
        <?php else: ?>
            <p id="cajaMovementsEmpty" style="display:none;text-align:center;color:var(--color-text-secondary);padding:20px;">No hay movimientos en esta sesión de caja</p>
            <div class="table-container" id="cajaMovementsTableWrap"><table>
                <thead><tr><th>Hora</th><th>Usuario</th><th>Tipo</th><th>Descripción</th><th>Origen</th><th>Monto</th></tr></thead>
                <tbody id="cajaMovementsBody"><?php foreach($movements as $mov): ?>
                    <tr data-movement-id="<?php echo (int)$mov['id']; ?>">
                        <td><?php echo date('H:i',strtotime($mov['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars((string)($mov['user_name'] ?? '—')); ?></td>
                        <td><span class="badge <?php echo $mov['type']==='income'?'badge-success':'badge-danger';?>"><?php echo $mov['type']==='income'?'Ingreso':'Egreso';?></span></td>
                        <td><?php echo htmlspecialchars($mov['description']);?></td>
                        <td><?php if (!empty($mov['origin_url'])): ?><a href="<?php echo htmlspecialchars($mov['origin_url']); ?>"><?php echo htmlspecialchars((string)$mov['origin_label']); ?></a><?php elseif (!empty($mov['origin_label'])): ?><?php echo htmlspecialchars((string)$mov['origin_label']); ?><?php else: ?>—<?php endif; ?></td>
                        <td style="color:<?php echo $mov['type']==='income'?'#10B981':'#DC2626';?>;font-weight:600;"><?php echo $mov['type']==='income'?'+':'-';?><?php echo fmt($mov['amount'],$currency);?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
<script>
window.CajaLiveSyncConfig = <?php echo json_encode([
    'url' => $viewInstance->route('app/caja') . '?action=liveSync',
    'intervalMs' => 12000,
    'showTopStats' => !$isPosRole,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php endif; ?>

<!-- Modal de cierre -->
<div id="closeCashModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;max-height:92vh;overflow-y:auto;">
        <div class="modal-header"><h3>Cerrar Caja</h3><button type="button" onclick="document.getElementById('closeCashModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/caja'); ?>?action=close" data-ajax="true" id="closeCashForm">
            <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="session_id" value="<?php echo (int)$openSession['id']; ?>">
            <div class="modal-body">
                <?php if (($stockAlert['count'] ?? 0) > 0): ?>
                <div class="alert alert-warning" style="margin-bottom:12px;font-size:13px;">
                    <strong>Productos en stock negativo / pendientes</strong>
                    (<?php echo (int)($stockAlert['negative_count'] ?? 0); ?> negativos ·
                    <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> préstamos salida ·
                    <?php echo (int)($stockAlert['loan_received_count'] ?? 0); ?> nos prestaron ·
                    <?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?> ventas sin stock).
                    Se guardará en las notas del cierre para trazabilidad.
                    <ul style="margin:6px 0 0;padding-left:18px;max-height:130px;overflow:auto;">
                        <?php foreach (array_slice($stockAlert['summary_lines'] ?? [], 0, 12) as $line): ?>
                            <li><?php echo htmlspecialchars((string)$line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!$isPosRole && \SoftNova\Core\TenantMiddleware::canAccess('prestamos')): ?>
                        <a href="<?php echo $viewInstance->route('app/prestamos'); ?>">Gestionar préstamos</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div id="closeCashSummary" style="background:var(--bg-body,#f5f5f5);border:1px solid var(--color-border);border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span>Base cajón (apertura)<br><span style="font-size:11px;color:var(--color-text-secondary);">Fondo inicial, no es venta</span></span>
                        <strong id="closeSumOpening">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span>Efectivo de ventas del día</span>
                        <strong id="closeSumCashSales" style="color:#10B981;">—</strong>
                    </div>
                    <div id="closeSumOtherCashRow" style="display:none;justify-content:space-between;margin-bottom:6px;">
                        <span>Otros ingresos efectivo</span>
                        <strong id="closeSumOtherCash" style="color:#10B981;">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span>Egresos del cajón</span>
                        <strong id="closeSumExpenses" style="color:var(--dmc-DC2626, #DC2626);">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span>Sub-total sin descuentos<br><span style="font-size:11px;color:var(--color-text-secondary);">Todos los medios, antes de descuento</span></span>
                        <strong id="closeSumSubtotal">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span>Descuento total aplicado<br><span style="font-size:11px;color:var(--color-text-secondary);">Todos los medios</span></span>
                        <strong id="closeSumDiscount" style="color:var(--dmc-B45309, #B45309);">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                        <span>Ventas facturadas (todos los medios)</span>
                        <strong id="closeSumSales">—</strong>
                    </div>
                    <div id="closeSumPayments" style="margin:10px 0;padding:12px;border:1px solid var(--color-border);border-radius:8px;background:var(--dmb-FFFFFF, #fff);font-size:13px;">
                        <?php
                        $payBreak = $totals['payment_breakdown'] ?? [];
                        $payBreakSum = 0.0;
                        foreach ($payBreak as $pbTmp) {
                            $payBreakSum += (float)($pbTmp['total'] ?? 0);
                        }
                        if (!empty($payBreak)):
                        ?>
                            <div style="font-weight:700;margin-bottom:8px;font-size:14px;">Totales cobrados por medio de pago</div>
                            <p style="margin:0 0 10px;font-size:12px;color:var(--color-text-secondary);">
                                Aquí ve cuánto entró en cada medio (efectivo, Nequi, Daviplata, tarjeta, etc.).
                            </p>
                            <?php foreach ($payBreak as $code => $pb): ?>
                                <?php $isCashMethod = ($code === 'cash'); ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;padding:8px 10px;border-radius:6px;background:<?php echo $isCashMethod ? 'rgba(16,185,129,.1)' : 'var(--bg-body,#f5f5f5)'; ?>;">
                                    <span>
                                        <strong><?php echo htmlspecialchars((string)($pb['label'] ?? '')); ?></strong>
                                        <span style="color:var(--color-text-secondary);font-size:11px;"> · <?php echo (int)($pb['count'] ?? 0); ?> cobro(s)</span>
                                    </span>
                                    <strong style="font-size:15px;<?php echo $isCashMethod ? 'color:var(--dmc-059669, #059669);' : ''; ?>">
                                        <?php echo fmt((float)($pb['total'] ?? 0), $currency); ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                            <div style="display:flex;justify-content:space-between;border-top:1px dashed var(--color-border);padding-top:8px;margin-top:4px;font-size:12px;">
                                <span>Suma de todos los medios</span>
                                <strong><?php echo fmt($payBreakSum, $currency); ?></strong>
                            </div>
                        <?php else: ?>
                            <div style="color:var(--color-text-secondary);">Sin cobros por medio de pago en esta sesión.</div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--color-border);padding-top:8px;margin-top:4px;">
                        <span><strong>Efectivo esperado en cajón</strong><br><span style="font-size:11px;color:var(--color-text-secondary);">Solo efectivo físico a contar</span></span>
                        <strong id="closeSumBalance" style="color:#10B981;font-size:16px;">—</strong>
                    </div>
                </div>
                <p style="margin-bottom:12px;font-size:12px;color:var(--color-text-secondary);">Al cerrar podrá descargar o compartir el PDF cuando lo desee (botón PDF en el historial de cierres).</p>
                <div class="form-group"><label>Monto Final Contado (<?php echo $currency['symbol'];?>) *</label><input type="text" inputmode="decimal" name="closing_amount" id="closingAmountInput" class="form-control money-input" data-money="1" placeholder="0" required></div>
                <div class="form-group"><label>Notas de cierre</label><textarea name="notes" class="form-control" rows="2" placeholder="Observaciones..."></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('closeCashModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-danger">Cerrar Caja</button></div>
        </form>
    </div>
</div>
<div id="posConflictModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:640px;max-height:90vh;overflow:auto;">
        <div class="modal-header">
            <h3>Conflictos de sincronización offline</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('posConflictModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin-top:0;">
                Estas ventas no se pudieron aplicar en el servidor (casi siempre por stock).
                Ajuste inventario o descarte la venta local. La contabilidad solo se genera cuando el sync es exitoso.
            </p>
            <div id="posConflictList"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="posConflictRetryAll">Reintentar todas</button>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('posConflictModal').style.display='none'">Cerrar</button>
        </div>
    </div>
</div>
<script>
function openCloseCashModal() {
    if (window.SeriPosOffline && !SeriPosOffline.isOnline()) {
        if (typeof showAlert === 'function') {
            showAlert('No puede cerrar caja sin conexión. Espere a recuperar internet y sincronice las ventas pendientes.', 'error');
        }
        return;
    }
    if (window.SeriPosOffline && SeriPosOffline.loadQueue().length > 0) {
        if (typeof showAlert === 'function') {
            showAlert('Hay ventas en cola offline. Espere a que se sincronicen antes de cerrar caja.', 'warning');
        }
        return;
    }
    if (window.SeriPosOffline && SeriPosOffline.loadConflicts().length > 0) {
        if (typeof showAlert === 'function') {
            showAlert('Hay ventas offline con conflicto de stock. Revíselas antes de cerrar caja.', 'warning');
        }
        return;
    }
    var btn = document.getElementById('btnOpenCloseCash');
    if (!btn) return;
    var opening = btn.getAttribute('data-opening') || '—';
    var cashSales = btn.getAttribute('data-cash-sales') || '—';
    var otherCash = btn.getAttribute('data-other-cash') || '';
    var expenses = btn.getAttribute('data-expenses') || '—';
    var salesCount = btn.getAttribute('data-sales-count') || '0';
    var salesTotal = btn.getAttribute('data-sales-total') || '—';
    var salesSubtotal = btn.getAttribute('data-sales-subtotal') || '—';
    var salesDiscount = btn.getAttribute('data-sales-discount') || '—';
    var balanceLabel = btn.getAttribute('data-balance-label') || '—';
    var balance = btn.getAttribute('data-balance') || '0';
    document.getElementById('closeSumOpening').textContent = opening;
    document.getElementById('closeSumCashSales').textContent = '+' + cashSales;
    var otherRow = document.getElementById('closeSumOtherCashRow');
    var otherEl = document.getElementById('closeSumOtherCash');
    if (otherRow && otherEl) {
        var otherRaw = String(otherCash).replace(/[^\d.,\-]/g, '').replace(/\./g, '').replace(',', '.');
        var otherNum = parseFloat(otherRaw) || 0;
        if (otherNum > 0.009) {
            otherRow.style.display = 'flex';
            otherEl.textContent = '+' + otherCash;
        } else {
            otherRow.style.display = 'none';
        }
    }
    document.getElementById('closeSumExpenses').textContent = '-' + expenses;
    var elSub = document.getElementById('closeSumSubtotal');
    if (elSub) elSub.textContent = salesSubtotal;
    var elDisc = document.getElementById('closeSumDiscount');
    if (elDisc) elDisc.textContent = '-' + salesDiscount;
    document.getElementById('closeSumSales').textContent = salesCount + ' / ' + salesTotal;
    document.getElementById('closeSumBalance').textContent = balanceLabel;
    var inp = document.getElementById('closingAmountInput');
    if (inp) {
        inp.value = balance;
        inp.focus();
        inp.select();
    }
    document.getElementById('closeCashModal').style.display = 'flex';
}
</script>

<!-- Modal Gasto (mismo flujo que módulo Gastos) -->
<?php
$operationalCats = $operationalCats ?? [];
$administrativeCats = $administrativeCats ?? [];
$managementCats = $managementCats ?? [];
$customSubgroupSections = $customSubgroupSections ?? [];
$expenseSuppliers = $expenseSuppliers ?? [];
if ($canExpense):
?>
<div id="expenseModal" class="modal-overlay" style="display:none;z-index:1100;">
    <div class="modal-content neumorphic" style="max-width:600px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h3>Nuevo Gasto</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('expenseModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/caja'); ?>?action=expense" data-ajax="true" enctype="multipart/form-data">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Descripcion *</label>
                    <input type="text" name="description" class="form-control" required placeholder="Ej. Comisión datáfono">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Monto *</label>
                        <input type="text" inputmode="decimal" name="amount" class="form-control money-input" data-money="1" required placeholder="0">
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Categoria *</label>
                        <?php $viewInstance->partial('expense_category_select', [
                            'operationalCats' => $operationalCats,
                            'financialCats' => $financialCats ?? [],
                            'administrativeCats' => $administrativeCats,
                            'managementCats' => $managementCats,
                            'customSubgroupSections' => $customSubgroupSections,
                            'required' => true,
                            'allowCustomExpenseCategory' => \SoftNova\Core\TenantMiddleware::getRole() === 'admin',
                        ]); ?>
                        <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                            Operativos descuentan caja; contables (administrativos, financieros, gestión) no.
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Medio de pago</label>
                        <select name="payment_method" class="form-control" data-custom-other="payment_method">
                            <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Proveedor</label>
                    <select name="supplier_id" class="form-control">
                        <option value="">Sin proveedor</option>
                        <?php foreach ($expenseSuppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>N° comprobante</label>
                        <input type="text" name="receipt_number" class="form-control" placeholder="Referencia">
                    </div>
                    <div class="form-group">
                        <label>Foto / PDF del pago</label>
                        <input type="file" name="receipt_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notas</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group js-cash-explanation-block" style="display:none;padding:10px 12px;border:1px solid #F59E0B;border-radius:8px;background:rgba(245,158,11,.08);">
                    <label style="font-weight:600;">Explicación del pago en efectivo *</label>
                    <input type="text" name="cash_explanation" class="form-control" maxlength="255"
                           placeholder="Ej. Retiro cajero Bancolombia Calle 80 / efectivo de ahorros">
                    <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                        Solo gastos contables con efectivo: no descuenta el cajón. Operativos en efectivo sí descuentan caja.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('expenseModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php if ($canExpense): ?>
<script>
(function () {
    var form = document.querySelector('#expenseModal form');
    if (!form || form.dataset.expenseCashBound === '1') return;
    form.dataset.expenseCashBound = '1';
    var payEl = form.querySelector('[name="payment_method"]');
    var catEl = form.querySelector('[name="category_id"]');
    var explBlock = form.querySelector('.js-cash-explanation-block');
    var explEl = form.querySelector('[name="cash_explanation"]');
    function isCashMethod() {
        if (!payEl) return false;
        var v = String(payEl.value || '').toLowerCase();
        return v === 'cash' || v === 'efectivo' || v === '';
    }
    function isAccountingCategory() {
        if (!catEl || catEl.selectedIndex < 0) return false;
        var opt = catEl.options[catEl.selectedIndex];
        if (opt.getAttribute('data-accounting') === '1') return true;
        var k = String(opt.getAttribute('data-kind') || '');
        return k === 'administrative' || k === 'financial' || k === 'management' || k.indexOf('sg_') === 0;
    }
    function syncCashExplanation() {
        var show = isCashMethod() && isAccountingCategory();
        if (explBlock) explBlock.style.display = show ? '' : 'none';
        if (explEl) {
            explEl.required = show;
            if (!show) explEl.value = '';
        }
    }
    if (payEl) payEl.addEventListener('change', syncCashExplanation);
    if (catEl) catEl.addEventListener('change', syncCashExplanation);
    syncCashExplanation();
})();
</script>
<?php endif; ?>

<?php
$canLoanFromCaja = !empty($canLoanFromCaja);
$loanProducts = $loanProducts ?? [];
$loanReasonsHtml = $loanReasonsHtml ?? \SoftNova\Services\ProductLoan::optionsHtml(null, \SoftNova\Core\TenantMiddleware::getDb());
if ($canLoanFromCaja):
?>
<div id="cajaLoanModal" class="modal-overlay" style="display:none;z-index:1100;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Salida por préstamo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('cajaLoanModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/caja'); ?>?action=loan" data-ajax="true" id="cajaLoanForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Producto *</label>
                    <input type="hidden" name="product_id" id="cajaLoanProductId" required>
                    <input type="text" id="cajaLoanProductSearch" class="form-control" autocomplete="off"
                           placeholder="Buscar nombre, SKU o escanear…"
                           data-barcode-input="true">
                    <div id="cajaLoanProductResults" class="product-picker-results" style="display:none;"></div>
                    <div id="cajaLoanProductSelected" class="product-picker-selected" style="display:none;"></div>
                    <small id="cajaLoanStockHint" style="color:var(--color-text-secondary);"></small>
                </div>
                <div class="form-group">
                    <label>Cantidad *</label>
                    <input type="number" name="quantity" id="cajaLoanQty" class="form-control" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Motivo *</label>
                    <select name="reason" id="cajaLoanReason" class="form-control" required>
                        <?php echo $loanReasonsHtml; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>A quién se presta *</label>
                    <input type="text" name="borrower" id="cajaLoanBorrower" class="form-control" maxlength="120" placeholder="Nombre de la persona o empresa" required>
                </div>
                <div class="form-group">
                    <label>Nota / detalle <span id="cajaLoanNoteReq" style="display:none;">*</span></label>
                    <input type="text" name="note" id="cajaLoanNote" class="form-control" maxlength="180" placeholder="Detalle adicional">
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                    Si no hay stock suficiente, el inventario quedará en negativo y el préstamo seguirá pendiente hasta la devolución.
                    <?php if (!$isPosRole && \SoftNova\Core\TenantMiddleware::canAccess('prestamos')): ?>
                        <a href="<?php echo $viewInstance->route('app/prestamos'); ?>">Ver préstamos</a>
                    <?php endif; ?>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('cajaLoanModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar salida</button>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    var searchUrl = <?php echo json_encode($viewInstance->route('app/caja') . '?action=searchProducts'); ?>;
    var seed = <?php echo json_encode(array_values(array_map(static function ($p) {
        return [
            'id' => (int)$p['id'],
            'code' => (string)($p['code'] ?? ''),
            'name' => (string)($p['name'] ?? ''),
            'stock' => (int)($p['stock'] ?? 0),
            'barcode' => (string)($p['barcode'] ?? ''),
        ];
    }, $loanProducts)), JSON_UNESCAPED_UNICODE); ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function label(p) { return ((p.code ? p.code + ' · ' : '') + (p.name || '')).trim(); }

    window.setCajaLoanProduct = function (p) {
        var hid = document.getElementById('cajaLoanProductId');
        var sel = document.getElementById('cajaLoanProductSelected');
        var search = document.getElementById('cajaLoanProductSearch');
        var results = document.getElementById('cajaLoanProductResults');
        if (!hid || !p) return;
        hid.value = String(p.id);
        hid.dataset.stock = String(p.stock != null ? p.stock : 0);
        if (sel) {
            sel.style.display = '';
            sel.innerHTML = '<strong>' + esc(label(p)) + '</strong> · stock ' + esc(p.stock)
                + ' <button type="button" class="btn btn-sm btn-secondary" style="margin-left:8px;" onclick="clearCajaLoanProduct()">Cambiar</button>';
        }
        if (search) { search.value = ''; search.style.display = 'none'; }
        if (results) { results.style.display = 'none'; results.innerHTML = ''; }
        updateCajaLoanStockHint();
    };
    window.clearCajaLoanProduct = function () {
        var hid = document.getElementById('cajaLoanProductId');
        var sel = document.getElementById('cajaLoanProductSelected');
        var search = document.getElementById('cajaLoanProductSearch');
        if (hid) { hid.value = ''; delete hid.dataset.stock; }
        if (sel) { sel.style.display = 'none'; sel.innerHTML = ''; }
        if (search) { search.style.display = ''; search.focus(); }
        updateCajaLoanStockHint();
    };
    window.openCajaLoanModal = function () {
        var modal = document.getElementById('cajaLoanModal');
        if (!modal) return;
        clearCajaLoanProduct();
        modal.style.display = 'flex';
        var s = document.getElementById('cajaLoanProductSearch');
        if (s) setTimeout(function () { s.focus(); }, 40);
    };
    window.updateCajaLoanStockHint = function () {
        var hid = document.getElementById('cajaLoanProductId');
        var hint = document.getElementById('cajaLoanStockHint');
        if (!hid || !hint) return;
        if (!hid.value) { hint.textContent = ''; return; }
        var stock = parseInt(hid.dataset.stock || '0', 10);
        var qty = parseInt((document.getElementById('cajaLoanQty') || {}).value || '1', 10) || 1;
        if (qty > Math.max(0, stock)) {
            hint.textContent = 'Stock actual: ' + stock + '. Esta salida dejará el inventario en ' + (stock - qty) + '.';
            hint.style.color = 'var(--dmc-B45309, #B45309)';
        } else {
            hint.textContent = 'Stock actual: ' + stock;
            hint.style.color = '';
        }
    };

    function render(list) {
        var box = document.getElementById('cajaLoanProductResults');
        if (!box) return;
        if (!list || !list.length) {
            box.style.display = '';
            box.innerHTML = '<div class="product-picker-row" style="cursor:default;color:var(--color-text-secondary);">Sin resultados</div>';
            return;
        }
        box.style.display = '';
        box.innerHTML = '';
        list.forEach(function (p) {
            var row = document.createElement('div');
            row.className = 'product-picker-row';
            row.innerHTML = '<span>' + esc(label(p)) + '</span><span style="color:var(--color-text-secondary);">stock ' + esc(p.stock) + '</span>';
            row.addEventListener('click', function () { setCajaLoanProduct(p); });
            box.appendChild(row);
        });
    }
    function localSearch(q) {
        q = String(q || '').toLowerCase();
        return (seed || []).filter(function (p) {
            return String(p.name||'').toLowerCase().indexOf(q) >= 0
                || String(p.code||'').toLowerCase().indexOf(q) >= 0
                || String(p.barcode||'').toLowerCase() === q;
        }).slice(0, 25);
    }
    var timer = null;
    var input = document.getElementById('cajaLoanProductSearch');
    function run(autoExact) {
        var q = (input && input.value || '').trim();
        if (!q) {
            var box = document.getElementById('cajaLoanProductResults');
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            return;
        }
        fetch(searchUrl + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var list = (d && d.products) ? d.products : localSearch(q);
                if (autoExact && list.length) {
                    var ql = q.toLowerCase();
                    var exact = list.find(function (p) {
                        return String(p.code||'').toLowerCase() === ql || String(p.barcode||'').toLowerCase() === ql;
                    }) || (list.length === 1 ? list[0] : null);
                    if (exact) { setCajaLoanProduct(exact); return; }
                }
                render(list);
            })
            .catch(function () { render(localSearch(q)); });
    }
    if (input) {
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { run(false); }, 220); });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(true); } });
    }
    var qty = document.getElementById('cajaLoanQty');
    var cajaLoanReason = document.getElementById('cajaLoanReason');
    if (qty) qty.addEventListener('input', updateCajaLoanStockHint);
    if (cajaLoanReason && window.SoftNovaCustomSelect) SoftNovaCustomSelect.bind(cajaLoanReason);
    var form = document.getElementById('cajaLoanForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var hid = document.getElementById('cajaLoanProductId');
            if (!hid || !hid.value) {
                e.preventDefault(); e.stopImmediatePropagation();
                if (typeof showAlert === 'function') showAlert('Seleccione o escanee un producto', 'error');
            }
        }, true);
    }
    if (window.SoftNovaBarcode && SoftNovaBarcode.onScan) {
        SoftNovaBarcode.onScan(function (code, product) {
            var modal = document.getElementById('cajaLoanModal');
            if (!modal || modal.style.display === 'none' || !product) return false;
            setCajaLoanProduct(product);
            return true;
        });
    }
    var prefill = <?php echo (int)($loanProductIdPrefill ?? 0); ?>;
    if (prefill > 0) {
        var hit = (seed || []).find(function (p) { return String(p.id) === String(prefill); });
        openCajaLoanModal();
        if (hit) setCajaLoanProduct(hit);
    }
})();
</script>
<style>
.product-picker-results {
    margin-top: 6px; border: 1px solid var(--color-border); border-radius: 8px;
    max-height: 220px; overflow: auto; background: var(--bg-card, #fff); z-index: 2; position: relative;
}
.product-picker-row {
    display: flex; justify-content: space-between; gap: 10px;
    padding: 8px 10px; cursor: pointer; border-bottom: 1px solid var(--color-border); font-size: 13px;
}
.product-picker-row:last-child { border-bottom: 0; }
.product-picker-row:hover { background: var(--bg-input, #f8fafc); }
.product-picker-selected {
    margin-top: 8px; padding: 8px 10px; border-radius: 8px;
    background: var(--bg-input, #f1f5f9); font-size: 13px;
}
</style>
<?php endif; ?>

<!-- Modal Quick Customer (POS) -->
<div id="quickCustomerModal" class="modal-overlay" style="display:none;z-index:1100;">
    <div class="modal-content neumorphic" style="max-width:450px;">
        <div class="modal-header">
            <h3>Nuevo Cliente</h3>
            <button onclick="closeQuickCustomer()" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="<?php echo $viewInstance->route('app/clientes'); ?>?action=create" id="quickCustomerForm" onsubmit="return submitQuickCustomer(this)">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group"><label>Tipo Doc.</label><select name="document_type" class="form-control"><option value="CC">C.C</option><option value="CE">C.E</option><option value="NIT">NIT</option><option value="PPT">PPT</option><option value="OTROS">OTROS</option></select></div>
                    <div class="form-group"><label>N° Documento *</label><input type="text" name="document_number" class="form-control" placeholder="Número" required></div>
                    <div class="form-group"><label>Nombre *</label><input type="text" name="first_name" class="form-control" required placeholder="Nombres"></div>
                    <div class="form-group"><label>Apellido</label><input type="text" name="last_name" class="form-control" placeholder="Apellidos"></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="correo@ejemplo.com"></div>
                    <div class="form-group"><label>Teléfono *</label><input type="text" name="phone" class="form-control" placeholder="Teléfono" required></div>
                </div>
                <div class="form-group"><label>Dirección</label><textarea name="address" class="form-control" rows="2" placeholder="Dirección"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQuickCustomer()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear y Seleccionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal venta sin stock -->
<div id="oversellModal" class="modal-overlay" style="display:none;z-index:1200;">
    <div class="modal-content neumorphic" style="max-width:440px;">
        <div class="modal-header">
            <h3>Vender sin stock</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('oversellModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p id="oversellInfo" style="margin:0 0 12px;color:var(--color-text-secondary);font-size:13px;"></p>
            <div class="form-group">
                <label>Motivo *</label>
                <select id="oversellReason" class="form-control">
                    <?php echo \SoftNova\Services\StockOversell::optionsHtml(); ?>
                </select>
            </div>
            <div class="form-group" id="oversellNoteGroup" style="display:none;">
                <label>Detalle (obligatorio si elige Otro)</label>
                <input type="text" id="oversellNote" class="form-control" maxlength="180" placeholder="Describa el motivo…">
            </div>
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                El inventario quedará en negativo o cero. Al reponer mercancía se cubrirá esta deuda y se mostrará el motivo.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('oversellModal').style.display='none'">Cancelar</button>
            <button type="button" class="btn btn-primary" id="oversellConfirmBtn">Continuar venta</button>
        </div>
    </div>
</div>

<!-- Modal Combos -->
<div id="posComboModal" class="modal-overlay" style="display:none;z-index:1260;">
    <div class="modal-content neumorphic" style="max-width:720px;max-height:92vh;overflow:auto;">
        <div class="modal-header">
            <h3>Combos</h3>
            <button type="button" class="modal-close" id="posComboModalClose">&times;</button>
        </div>
        <div class="modal-body" style="padding-top:8px;">
            <div class="pos-combo-tabs" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                <button type="button" class="btn btn-primary btn-sm" id="posComboTabList" data-tab="list">Usar sugerencia</button>
                <button type="button" class="btn btn-secondary btn-sm" id="posComboTabCreate" data-tab="create">Crear / guardar</button>
            </div>

            <div id="posComboListPane">
                <p style="margin:0 0 10px;font-size:13px;color:var(--color-text-secondary);">
                    Elija un combo guardado. Se agregan los productos al carrito con el precio del combo prorrateado (el stock baja por cada ítem).
                </p>
                <div id="posComboList" class="pos-combo-list"></div>
                <p id="posComboListEmpty" class="pos-search-hint" style="display:none;">Aún no hay combos. Cree uno en la pestaña «Crear / guardar».</p>
            </div>

            <div id="posComboCreatePane" style="display:none;">
                <p style="margin:0 0 10px;font-size:13px;color:var(--color-text-secondary);">
                    Arme un paquete (mín. 2 productos), fije el precio del combo y guárdelo como sugerencia para fechas especiales, tinte+agua, kits, etc.
                </p>
                <div class="form-group">
                    <label>Nombre del combo *</label>
                    <input type="text" id="posComboName" class="form-control" maxlength="160" placeholder="Ej: Tinte + agua oxigenada">
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:140px;">
                        <label>Precio del combo *</label>
                        <input type="text" id="posComboPrice" class="form-control money-input" data-money="1" inputmode="decimal" placeholder="0">
                    </div>
                    <div class="form-group" style="flex:1;min-width:160px;">
                        <label>Motivo</label>
                        <select id="posComboReason" class="form-control">
                            <?php foreach (\SoftNova\Services\ProductComboService::REASONS as $rk => $rl): ?>
                                <option value="<?php echo htmlspecialchars($rk); ?>"><?php echo htmlspecialchars($rl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="posComboReasonNoteWrap" style="display:none;">
                    <label>Detalle del motivo</label>
                    <input type="text" id="posComboReasonNote" class="form-control" maxlength="180" placeholder="Ej: Promo abril · Día de la belleza">
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:140px;">
                        <label>Vigente desde (opcional)</label>
                        <input type="date" id="posComboFrom" class="form-control">
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px;">
                        <label>Vigente hasta (opcional)</label>
                        <input type="date" id="posComboUntil" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Buscar producto para el combo</label>
                    <input type="text" id="posComboProductSearch" class="form-control" placeholder="Código o nombre…" autocomplete="off">
                    <div id="posComboProductResults" class="pos-combo-product-results"></div>
                </div>
                <div class="form-group" style="margin-bottom:6px;">
                    <label style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                        <span>Componentes</span>
                        <button type="button" class="btn btn-secondary btn-sm" id="posComboFromCartBtn" title="Usa los productos actuales del carrito">Desde carrito</button>
                    </label>
                    <div id="posComboItems" class="pos-combo-items"></div>
                    <div id="posComboRetailHint" class="pos-search-hint" style="margin-top:6px;"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn btn-secondary" id="posComboCancelBtn">Cerrar</button>
            <button type="button" class="btn btn-primary" id="posComboSaveBtn" style="display:none;">Guardar sugerencia</button>
            <button type="button" class="btn btn-success" id="posComboSaveAndApplyBtn" style="display:none;">Guardar y vender</button>
        </div>
    </div>
</div>

<!-- Modal producto comodín -->
<div id="openItemModal" class="modal-overlay" style="display:none;z-index:1250;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header">
            <h3>Producto comodín</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('openItemModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 12px;font-size:13px;color:var(--color-text-secondary);">
                Para ventas puntuales que no están en inventario. No descuenta stock.
            </p>
            <div class="form-group">
                <label>Nombre del producto *</label>
                <input type="text" id="openItemName" class="form-control" maxlength="255" placeholder="Ej: Servicio de instalación">
            </div>
            <div class="form-group">
                <label>Precio de venta *</label>
                <input type="text" id="openItemPrice" class="form-control money-input" data-money="1" inputmode="decimal" placeholder="0">
            </div>
            <div class="form-group" id="openItemTaxWrap" style="margin-bottom:0;">
                <label style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer;font-weight:500;">
                    <input type="checkbox" id="openItemHasTax" style="width:auto;margin:0;" checked>
                    <span>Este producto tiene IVA (<span id="openItemTaxRateLabel">19</span>%)</span>
                </label>
                <small style="display:block;margin-top:6px;color:var(--color-text-secondary);">
                    Si lo desmarca, se vende como exento (sin IVA).
                </small>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('openItemModal').style.display='none'">Cancelar</button>
            <button type="button" class="btn btn-primary" id="openItemConfirmBtn">Agregar a la venta</button>
        </div>
    </div>
</div>

<!-- Modal Abono -->
<div id="paymentModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:400px;">
        <div class="modal-header"><h3>Registrar Abono</h3><button onclick="document.getElementById('paymentModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=payment" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="sale_id" id="paySaleId">
            <p id="payInfo" style="margin-bottom:10px;"></p>
            <div class="form-group"><label>Monto *</label><input type="text" name="amount" id="payAmount" class="form-control money-input" data-money="1" inputmode="decimal" required placeholder="0"></div>
            <div class="form-group"><label>Método</label><select name="payment_method" class="form-control" data-custom-other="payment_method"><?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?></select></div>
            <div class="form-group"><label>Notas</label><input type="text" name="notes" class="form-control" placeholder="Abono"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('paymentModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-success">Registrar Abono</button></div>
        </form>
    </div>
</div>

<!-- Modal editar medio de pago (simple o mixto) -->
<div id="editPaymentMethodModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Editar medio de pago</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('editPaymentMethodModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=update_payment_method" data-ajax="true" id="editPayMethodForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="sale_id" id="editPaySaleId" value="">
            <input type="hidden" name="is_mixed" id="editPayIsMixed" value="0">
            <div class="modal-body">
                <p style="margin:0 0 12px;color:var(--color-text-secondary);">
                    Venta <strong id="editPayInvoice"></strong>
                    · Monto a repartir: <strong id="editPayTarget">—</strong>
                </p>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;cursor:pointer;">
                    <input type="checkbox" id="editPayMixedToggle"> Pago mixto (varios medios)
                </label>
                <div id="editPaySingleWrap" class="form-group">
                    <label>Nuevo medio de pago *</label>
                    <select name="payment_method" id="editPayMethodSelect" class="form-control" data-custom-other="payment_method">
                        <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?>
                    </select>
                </div>
                <div id="editPayMixedWrap" style="display:none;">
                    <div id="editPayMixedRows"></div>
                    <button type="button" class="btn btn-sm btn-secondary" id="editPayMixedAdd" style="margin-top:6px;">+ Agregar método</button>
                    <p id="editPayMixedSummary" style="font-size:12px;margin:8px 0 0;color:var(--color-text-secondary);"></p>
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:10px 0 0;">
                    La suma de los montos debe igualar lo cobrado. La caja se ajusta si cambia la parte en efectivo.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editPaymentMethodModal').style.display='none'">Volver</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<template id="editPayMethodOptionsTpl"><?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', true, \SoftNova\Core\TenantMiddleware::getDb()); ?></template>

<!-- Modal cancelar venta (mismo flujo que módulo Ventas) -->
<?php $cancelReasons = $cancelReasons ?? []; ?>
<div id="cancelSaleModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:440px;">
        <div class="modal-header">
            <h3>Eliminar venta</h3>
            <button type="button" class="modal-close" onclick="closeCancelSaleModal()">&times;</button>
        </div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=cancel" data-ajax="true" id="cancelSaleForm" onsubmit="return prepareCancelSale()">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="cancelSaleId" value="">
            <input type="hidden" name="cancel_reason_other" id="cancelReasonOther" value="">
            <div class="modal-body">
                <p style="margin:0 0 12px;color:var(--color-text-secondary);">
                    Se cancelará <strong id="cancelSaleInvoice"></strong>, se devolverá el stock y se ajustará la caja.
                </p>
                <div class="form-group">
                    <label>Motivo de eliminación *</label>
                    <select name="cancel_reason" id="cancelReasonSelect" class="form-control" required data-custom-other="sale_cancel_reason">
                        <option value="">Seleccione un motivo...</option>
                        <?php foreach ($cancelReasons as $opt): ?>
                            <option value="<?php echo htmlspecialchars((string)$opt['value']); ?>"><?php echo htmlspecialchars((string)$opt['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelSaleModal()">Volver</button>
                <button type="submit" class="btn btn-danger">Confirmar eliminación</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detalle -->
<div id="saleDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:650px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header"><h3>Detalle de Venta</h3><button onclick="document.getElementById('saleDetailModal').style.display='none'" class="modal-close">&times;</button></div>
        <div class="modal-body" id="saleDetailContent"></div>
    </div>
</div>

<div id="shareModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:420px;">
        <div class="modal-header"><h3>Enviar recibo</h3><button type="button" class="modal-close" onclick="document.getElementById('shareModal').style.display='none'">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <label>WhatsApp (celular)</label>
                <input type="tel" id="sharePhone" class="form-control" placeholder="Ej. 3001234567" inputmode="tel" autocomplete="tel">
                <small style="color:var(--color-text-secondary);">Digite el número del cliente. Se abre el chat con el mensaje del recibo.</small>
            </div>
            <a id="shareWhatsappBtn" href="#" target="_blank" class="btn btn-success" style="width:100%;margin-bottom:10px;">Abrir WhatsApp</a>
            <form method="POST" action="<?php echo $ventasBase; ?>?action=email" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="id" id="shareSaleId">
                <div class="form-group"><label>Correo</label><input type="email" name="email" id="shareEmail" class="form-control" required></div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 10px;">Se adjunta el ticket 58mm en PDF.</p>
                <button type="submit" class="btn btn-primary" style="width:100%;">Enviar por correo</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Historial / reportes de cierres -->
<?php if (!$isPosRole): ?>
<?php
$historyDate = $historyDate ?? '';
$historySessions = $historySessions ?? [];
$cajaBase = $viewInstance->route('app/caja');
?>
<div class="card neumorphic" style="margin-top:20px;" id="cierresReportes">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h3>Reportes de cierre</h3>
        <span style="font-size:12px;color:var(--color-text-secondary);">
            <?php echo $historyDate !== '' ? 'Día filtrado' : 'Últimos 2 días'; ?>
        </span>
    </div>
    <div class="card-body">
        <form method="GET" action="<?php echo $cajaBase; ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
            <input type="hidden" name="focus" value="cierresReportes">
            <div class="form-group" style="margin:0;">
                <label>Fecha de cierre</label>
                <input type="date" name="history_date" class="form-control" value="<?php echo htmlspecialchars($historyDate); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <?php if ($historyDate !== ''): ?>
                <a href="<?php echo $cajaBase; ?>?focus=cierresReportes" class="btn btn-secondary">Ver últimos 2 días</a>
            <?php endif; ?>
        </form>
        <?php if (empty($historySessions)): ?>
            <p style="color:var(--color-text-secondary);text-align:center;padding:12px;">No hay cierres en el período seleccionado.</p>
        <?php else: ?>
        <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 12px;line-height:1.45;">
            <strong>Contado cajón</strong> = solo efectivo físico (base + ventas en efectivo − egresos).
            <strong>Ventas del día</strong> = todos los medios (transferencia, Nequi, tarjetas, efectivo…).
            Use <strong>Detalle</strong> para ver el desglose completo antes del PDF.
        </p>
        <div class="table-container"><table>
            <thead>
                <tr>
                    <th>Apertura</th>
                    <th>Cierre</th>
                    <th>Usuario</th>
                    <th>Base</th>
                    <th>Ventas<br><span style="font-weight:400;font-size:11px;">(todos los medios)</span></th>
                    <th>Contado cajón<br><span style="font-weight:400;font-size:11px;">(solo efectivo)</span></th>
                    <th>Neto cajón</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody><?php foreach ($historySessions as $h): ?>
                <?php
                $openingAmt = (float)($h['opening_amount'] ?? 0);
                $countedAmt = (float)($h['closing_amount'] ?? 0);
                $expectedAmt = (float)($h['report_expected_cash'] ?? $h['expected_amount'] ?? $countedAmt);
                $cashNet = $expectedAmt - $openingAmt;
                $salesTotalHist = $h['report_sales_total'] ?? null;
                $salesCountHist = (int)($h['report_sales_count'] ?? 0);
                $notesRaw = (string)($h['notes'] ?? '');
                $isAutoClose = str_contains($notesRaw, '[[AUTO_CLOSE]]')
                    || stripos($notesRaw, 'Cierre automatico a medianoche') !== false
                    || stripos($notesRaw, 'Cierre automático a medianoche') !== false;
                $payMini = $h['report_payments'] ?? [];
                ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($h['opening_date'])); ?></td>
                    <td>
                        <?php echo !empty($h['closing_date']) ? date('d/m/Y H:i', strtotime($h['closing_date'])) : '—'; ?>
                        <?php if ($isAutoClose): ?>
                            <div style="font-size:11px;color:var(--dmc-B45309, #B45309);margin-top:2px;">Cierre automático 23:59</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($h['user_name'] ?? ''); ?>
                        <?php if ($isAutoClose): ?>
                            <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;">quien abrió (no cerró)</div>
                        <?php endif; ?>
                    </td>
                    <td title="Fondo de apertura (no es venta)"><?php echo fmt($openingAmt, $currency); ?></td>
                    <td title="Total facturado en todos los medios de pago">
                        <?php if ($salesTotalHist !== null): ?>
                            <strong><?php echo fmt((float)$salesTotalHist, $currency); ?></strong>
                            <div style="font-size:11px;color:var(--color-text-secondary);"><?php echo $salesCountHist; ?> venta(s)</div>
                            <?php if (!empty($payMini)): ?>
                                <div style="font-size:10px;color:var(--color-text-secondary);margin-top:4px;line-height:1.35;max-width:180px;">
                                    <?php foreach ($payMini as $pm): ?>
                                        <div><?php echo htmlspecialchars((string)$pm['label']); ?>: <?php echo fmt((float)$pm['total'], $currency); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td title="Efectivo físico contado al cierre (no incluye transferencia/tarjeta)">
                        <?php echo fmt($countedAmt, $currency); ?>
                        <div style="font-size:10px;color:var(--dmc-059669, #059669);margin-top:2px;">solo efectivo</div>
                    </td>
                    <td style="color:<?php echo $cashNet >= 0 ? '#10B981' : '#DC2626'; ?>;" title="Esperado cajón − base (sin otros medios)">
                        <?php echo ($cashNet >= 0 ? '+' : '') . fmt($cashNet, $currency); ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="cajaShowClosingDetail(<?php echo (int)$h['id']; ?>)" title="Ver detalle del cierre">Detalle</button>
                        <a href="<?php echo $cajaBase; ?>?action=pdf&id=<?php echo (int)$h['id']; ?>" class="btn btn-sm btn-info" target="_blank" rel="noopener" title="Descargar PDF">PDF</a>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="cajaShareWhatsapp(<?php echo (int)$h['id']; ?>)" title="Enviar por WhatsApp">WhatsApp</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="cajaShareEmail(<?php echo (int)$h['id']; ?>)" title="Enviar por correo">Correo</button>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<div id="cajaClosingDetailModal" class="modal-overlay" style="display:none;z-index:1150;">
    <div class="modal-content neumorphic" style="max-width:720px;max-height:92vh;overflow:auto;">
        <div class="modal-header">
            <h3 id="cajaClosingDetailTitle">Detalle de cierre</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('cajaClosingDetailModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" id="cajaClosingDetailBody">
            <p style="color:var(--color-text-secondary);">Cargando…</p>
        </div>
        <div class="modal-footer" style="flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('cajaClosingDetailModal').style.display='none'">Cerrar</button>
            <a href="#" class="btn btn-info" id="cajaClosingDetailPdf" target="_blank" rel="noopener">PDF</a>
            <button type="button" class="btn btn-secondary" id="cajaClosingDetailWa">WhatsApp</button>
            <button type="button" class="btn btn-secondary" id="cajaClosingDetailMail">Correo</button>
        </div>
    </div>
</div>

<div id="cajaShareEmailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3>Enviar cierre por correo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('cajaShareEmailModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $cajaBase; ?>?action=report_email" data-ajax="true" id="cajaShareEmailForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="cajaShareEmailId" value="">
            <?php
            $cajaReportEmail = trim((string)($cajaReportEmail ?? $companyEmail ?? ''));
            $hasCajaDefaultEmail = $cajaReportEmail !== '';
            ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Correo destino</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="email" name="email" id="cajaShareEmailTo" class="form-control" required
                               placeholder="correo@empresa.com"
                               value="<?php echo htmlspecialchars($cajaReportEmail); ?>"
                               data-default-email="<?php echo htmlspecialchars($cajaReportEmail); ?>"
                               <?php echo $hasCajaDefaultEmail ? 'readonly' : ''; ?>>
                        <?php if ($hasCajaDefaultEmail): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="cajaShareEmailUnlock" title="Cambiar correo predeterminado">Cambiar</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group" id="cajaShareEmailAdminWrap" style="display:none;">
                    <label>Clave de administrador</label>
                    <input type="password" name="admin_password" id="cajaShareEmailAdminPass" class="form-control" autocomplete="current-password" placeholder="Requerida para cambiar el correo">
                    <small style="color:var(--color-text-secondary);">Solo se pide al cambiar el correo predeterminado.</small>
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                    Se adjuntará el PDF del cierre.
                    <?php if ($hasCajaDefaultEmail): ?>
                        El correo actual es el <strong>predeterminado</strong>; para cambiarlo use la clave de administrador.
                    <?php else: ?>
                        El correo que ingrese quedará como <strong>predeterminado</strong> para próximos envíos.
                    <?php endif; ?>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('cajaShareEmailModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</div>

<div id="cajaShareCloseModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3>Caja cerrada</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('cajaShareCloseModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-top:0;">Caja cerrada. Descargue o comparta el reporte cuando lo necesite:</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a id="cajaShareClosePdf" href="#" class="btn btn-info" target="_blank" rel="noopener">PDF</a>
                <button type="button" class="btn btn-secondary" id="cajaShareCloseWa">WhatsApp</button>
                <button type="button" class="btn btn-secondary" id="cajaShareCloseMail">Correo</button>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="document.getElementById('cajaShareCloseModal').style.display='none'">Listo</button>
        </div>
    </div>
</div>

<script>
    window.invRouteVentasDetail = '<?php echo $viewInstance->route('app/ventas'); ?>?action=detail';
    window.invRouteVentasShare = '<?php echo $viewInstance->route('app/ventas'); ?>?action=share';
    window.cajaBase = <?php echo json_encode($cajaBase); ?>;
    window.cajaCreditSurchargeRate = <?php echo json_encode((float)($creditSurchargeRate ?? 0)); ?>;
    window.cajaCreditSurchargeDays = <?php echo json_encode((int)($creditSurchargeDays ?? 60)); ?>;
    window.cajaCreditSurchargeTiers = <?php echo json_encode(array_values($creditSurchargeTiers ?? []), JSON_UNESCAPED_UNICODE); ?>;
    window.cajaQtyDiscountTiers = <?php echo json_encode(array_values($quantityDiscountTiers ?? []), JSON_UNESCAPED_UNICODE); ?>;
    window.cajaTaxRate = <?php echo json_encode((float)($taxRate ?? 0)); ?>;
    window.cajaTaxEnabled = <?php echo json_encode(!empty($taxEnabled ?? true)); ?>;
    window.cajaProductTaxEnabled = <?php echo json_encode(!empty($productTaxEnabled ?? false)); ?>;

    function cajaEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function cajaToggleMarginDetail() {
        var box = document.getElementById('cajaMarginDetail');
        var label = document.getElementById('cajaMarginEyeLabel');
        if (!box) return;
        var show = box.style.display === 'none' || box.style.display === '';
        // Starts as display:none; first click should show
        if (box.getAttribute('data-open') === '1') {
            box.style.display = 'none';
            box.setAttribute('data-open', '0');
            if (label) label.textContent = 'Ver costo y utilidad';
        } else {
            box.style.display = 'block';
            box.setAttribute('data-open', '1');
            if (label) label.textContent = 'Ocultar costo y utilidad';
        }
    }

    function cajaShowClosingDetail(sessionId) {
        var modal = document.getElementById('cajaClosingDetailModal');
        var body = document.getElementById('cajaClosingDetailBody');
        var title = document.getElementById('cajaClosingDetailTitle');
        var pdf = document.getElementById('cajaClosingDetailPdf');
        var wa = document.getElementById('cajaClosingDetailWa');
        var mail = document.getElementById('cajaClosingDetailMail');
        if (title) title.textContent = 'Detalle de cierre #' + sessionId;
        if (body) body.innerHTML = '<p style="color:var(--color-text-secondary);">Cargando…</p>';
        if (pdf) pdf.href = window.cajaBase + '?action=pdf&id=' + encodeURIComponent(sessionId);
        if (wa) wa.onclick = function() { cajaShareWhatsapp(sessionId); };
        if (mail) mail.onclick = function() { cajaShareEmail(sessionId); };
        if (modal) modal.style.display = 'flex';

        fetch(window.cajaBase + '?action=report_detail&id=' + encodeURIComponent(sessionId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.success) {
                if (body) body.innerHTML = '<p style="color:var(--dmc-DC2626, #DC2626);">' + cajaEsc((d && d.message) || 'No se pudo cargar el detalle') + '</p>';
                return;
            }
            var dr = d.drawer || {};
            var sa = d.sales || {};
            var pays = d.payments || [];
            var canMargin = !!d.can_view_margin;
            var enCajaHint = (dr.expenses || 0) > 0.009
                ? 'Base de caja + efectivo de ventas − '
                    + (((dr.purchases || 0) > 0.009 || (dr.nc_cash_refunds || 0) > 0.009)
                        ? 'compras, NC y gastos'
                        : 'gastos')
                : 'Base de caja + efectivo de ventas';

            var kpi = function(label, value, bg, extra) {
                return '<div style="background:' + bg + ';color:#fff;padding:10px 12px;margin:0 0 6px;border-radius:6px;display:flex;justify-content:space-between;align-items:center;gap:8px;">'
                    + '<span style="font-weight:700;font-size:13px;">' + label + (extra || '') + '</span>'
                    + '<strong style="font-size:16px;">' + value + '</strong></div>';
            };
            var row = function(label, sign, value, bg, color) {
                return '<div style="display:flex;justify-content:space-between;gap:8px;padding:8px 10px;border-bottom:1px solid var(--color-border,#e5e7eb);'
                    + (bg ? 'background:' + bg + ';' : '') + '">'
                    + '<span style="font-size:13px;' + (bg === '#2563EB' ? 'color:#fff;font-weight:700;' : '') + '">' + label + '</span>'
                    + '<span style="display:flex;gap:10px;align-items:center;">'
                    + '<span style="width:14px;text-align:center;font-weight:700;color:'
                    + (sign === '+' ? 'var(--dmc-059669, #059669)' : (sign === '-' ? 'var(--dmc-DC2626, #DC2626)' : (bg === '#2563EB' ? '#fff' : 'var(--dmc-64748B, #64748B)'))) + ';">' + cajaEsc(sign) + '</span>'
                    + '<strong style="min-width:100px;text-align:right;' + (color || (bg === '#2563EB' ? 'color:#fff;' : '')) + '">' + value + '</strong></span></div>';
            };

            var accountHtml = row('Base de apertura', 'i', cajaEsc(dr.opening_fmt), 'var(--dmb-FFFBEB, #FFFBEB)');
            if ((sa.cancelled || 0) > 0.009 || (sa.cancelled_count || 0) > 0) {
                accountHtml += row('Anulado (' + (sa.cancelled_count || 0) + ')', 'i', cajaEsc(sa.cancelled_fmt));
            }
            pays.forEach(function(p) {
                if (p.is_cash) return;
                accountHtml += row(cajaEsc(p.label) + ' (' + (p.count || 0) + ')', 'i', cajaEsc(p.total_fmt));
            });
            accountHtml += row('Ventas efectivo', '+', cajaEsc(dr.cash_sales_fmt), 'rgba(16,185,129,.12)', 'color:var(--dmc-059669, #059669);');
            if ((sa.discount || 0) > 0.009) {
                accountHtml += row('Descuentos', 'i', cajaEsc(sa.discount_fmt), '', 'color:var(--dmc-B45309, #B45309);');
            }
            var refunds = dr.refunds || 0;
            var purchases = dr.purchases || 0;
            var ncCash = dr.nc_cash_refunds || 0;
            if (refunds > 0.009) {
                accountHtml += row('Reintegro compras anuladas', '+', cajaEsc(dr.refunds_fmt), '', 'color:var(--dmc-059669, #059669);');
            }
            if ((dr.other_cash || 0) - refunds > 0.009) {
                accountHtml += row('Otros ingresos efectivo', '+', cajaEsc(refunds > 0.009 ? dr.other_cash_net_fmt : dr.other_cash_fmt), '', 'color:var(--dmc-059669, #059669);');
            }
            if (purchases > 0.009) {
                accountHtml += row('Compras a proveedores (OC / abonos efectivo)', '-', cajaEsc(dr.purchases_fmt), '', 'color:var(--dmc-DC2626, #DC2626);');
            }
            if (ncCash > 0.009 || (sa.nc_count || 0) > 0) {
                var ncLabel = 'Reembolsos NC venta'
                    + ((sa.nc_count || 0) > 0 ? ' (' + sa.nc_count + ')' : '');
                if (sa.nc_numbers) {
                    ncLabel += '<br><span style="font-weight:500;font-size:10px;opacity:.85;">' + cajaEsc(sa.nc_numbers) + '</span>';
                }
                if ((sa.nc_total || 0) > 0.009 && Math.abs((sa.nc_total || 0) - ncCash) > 0.02) {
                    ncLabel += '<br><span style="font-weight:500;font-size:10px;opacity:.85;">Total NC documental '
                        + cajaEsc(sa.nc_total_fmt) + '</span>';
                }
                accountHtml += row(ncLabel, '-', cajaEsc(dr.nc_cash_refunds_fmt || '0'), '#FFF7ED', 'color:#C2410C;');
            }
            accountHtml += row(
                'Gastos efectivo (cajón)',
                '-',
                cajaEsc((purchases > 0.009 || ncCash > 0.009) ? dr.other_expenses_fmt : dr.expenses_fmt),
                '',
                'color:var(--dmc-DC2626, #DC2626);'
            );
            accountHtml += row(
                '= En caja (esperado)<br><span style="font-weight:500;font-size:10px;opacity:.9;">' + cajaEsc(enCajaHint) + '</span>',
                '=',
                cajaEsc(dr.expected_fmt),
                '#2563EB',
                'color:#fff;'
            );
            accountHtml += row('Dinero contado (cuadre)', 'i', cajaEsc(dr.counted_fmt), 'var(--dmb-FEF3C7, #FEF3C7)');
            var diffOk = Math.abs(dr.difference || 0) < 0.05;
            accountHtml += row(
                diffOk ? 'Diferencia (cuadrado)' : ((dr.difference || 0) < 0 ? 'Diferencia (faltante)' : 'Diferencia (sobrante)'),
                diffOk ? '=' : ((dr.difference || 0) < 0 ? '-' : '+'),
                cajaEsc(dr.difference_fmt),
                diffOk ? 'rgba(16,185,129,.1)' : 'rgba(220,38,38,.08)',
                diffOk ? 'color:var(--dmc-059669, #059669);' : 'color:var(--dmc-DC2626, #DC2626);'
            );

            var marginToggle = '';
            var marginBlock = '';
            if (canMargin) {
                marginToggle = '<button type="button" id="cajaMarginEyeBtn" onclick="cajaToggleMarginDetail()" '
                    + 'title="Mostrar / ocultar costo y utilidad" '
                    + 'style="display:inline-flex;align-items:center;gap:6px;margin:0 0 10px;padding:6px 10px;'
                    + 'border:1px solid var(--color-border,#e5e7eb);border-radius:6px;background:var(--bg-body,#f8fafc);'
                    + 'cursor:pointer;font-size:12px;color:var(--color-text-secondary);">'
                    + '<svg id="cajaMarginEyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                    + '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>'
                    + '<circle cx="12" cy="12" r="3"></circle></svg>'
                    + '<span id="cajaMarginEyeLabel">Ver costo y utilidad</span></button>';
                marginBlock = '<div id="cajaMarginDetail" style="display:none;margin:0 0 12px;">'
                    + kpi('COSTO MERCANCÍA', cajaEsc(sa.cost_fmt || '—'), '#DC2626')
                    + kpi('UTILIDAD BRUTA', cajaEsc(sa.gross_profit_fmt || '—'), '#EA580C',
                        ' <span style="background:#16A34A;padding:2px 8px;border-radius:3px;font-size:12px;margin-left:6px;">'
                        + Number(sa.gross_margin_pct || 0).toFixed(2).replace('.', ',') + '%</span>')
                    + '<p style="margin:4px 0 0;font-size:12px;color:var(--color-text-secondary);">'
                    + 'Utilidad = facturado − costo (sin restar egresos de caja).</p></div>';
            }

            var byUser = d.by_user || [];
            var movs = d.movements || [];
            var userHtml = '';
            if (byUser.length) {
                userHtml = '<div style="border:1px solid var(--color-border,#e5e7eb);border-radius:8px;overflow:hidden;margin-bottom:14px;">'
                    + '<div style="padding:8px 10px;background:var(--bg-body,#f8fafc);font-weight:700;font-size:13px;">Por usuario (caja única)</div>';
                byUser.forEach(function(u) {
                    userHtml += '<div style="display:flex;justify-content:space-between;gap:8px;padding:7px 10px;border-bottom:1px solid var(--color-border,#e5e7eb);font-size:12px;">'
                        + '<span><strong>' + cajaEsc(u.user_name) + '</strong> · ' + (u.count || 0) + ' mov.</span>'
                        + '<span style="text-align:right;">+ ' + cajaEsc(u.incomes_fmt) + ' · − ' + cajaEsc(u.expenses_fmt)
                        + '<br><strong>Neto ' + cajaEsc(u.net_fmt) + '</strong></span></div>';
                });
                userHtml += '</div>';
            }
            var movHtml = '';
            if (movs.length) {
                movHtml = '<div style="border:1px solid var(--color-border,#e5e7eb);border-radius:8px;overflow:hidden;margin-bottom:14px;">'
                    + '<div style="padding:8px 10px;background:var(--bg-body,#f8fafc);font-weight:700;font-size:13px;">Movimientos — quién hizo qué</div>'
                    + '<div style="max-height:320px;overflow:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">'
                    + '<thead><tr><th style="text-align:left;padding:6px 8px;">Hora</th><th style="text-align:left;padding:6px 8px;">Usuario</th><th style="text-align:left;padding:6px 8px;">Tipo</th><th style="text-align:left;padding:6px 8px;">Descripción</th><th style="text-align:right;padding:6px 8px;">Monto</th></tr></thead><tbody>';
                movs.forEach(function(m) {
                    movHtml += '<tr style="border-top:1px solid var(--color-border,#e5e7eb);">'
                        + '<td style="padding:5px 8px;">' + cajaEsc(m.time) + '</td>'
                        + '<td style="padding:5px 8px;"><strong>' + cajaEsc(m.user_name) + '</strong></td>'
                        + '<td style="padding:5px 8px;">' + cajaEsc(m.type_label) + '</td>'
                        + '<td style="padding:5px 8px;">' + cajaEsc(m.description) + '</td>'
                        + '<td style="padding:5px 8px;text-align:right;"><strong>' + cajaEsc(m.amount_fmt) + '</strong></td></tr>';
                });
                movHtml += '</tbody></table></div></div>';
            }

            if (body) {
                body.innerHTML = '<p style="margin:0 0 12px;font-size:13px;color:var(--color-text-secondary);">'
                    + 'Abierta por ' + cajaEsc(d.user_name) + ' · ' + cajaEsc(d.opening_date) + ' → ' + cajaEsc(d.closing_date) + '</p>'
                    + kpi('FACTURADO + BASE', cajaEsc(sa.ventas_totales_con_base_fmt || sa.total_fmt), '#2563EB',
                        ' <span style="font-weight:500;font-size:11px;opacity:.9;">ventas del día + apertura</span>')
                    + marginToggle
                    + marginBlock
                    + '<p style="margin:4px 0 12px;font-size:12px;color:var(--color-text-secondary);line-height:1.4;">'
                    + 'Facturado ' + cajaEsc(sa.total_fmt)
                    + (sa.collected_fmt ? (' · Cobrado (Σ medios) ' + cajaEsc(sa.collected_fmt)) : '')
                    + ' + base ' + cajaEsc(dr.opening_fmt)
                    + '. El facturado no incluye facturas viejas cobradas hoy; esos abonos sí entran en Σ medios.</p>'
                    + '<div style="border:1px solid var(--color-border,#e5e7eb);border-radius:8px;overflow:hidden;margin-bottom:14px;">'
                    + '<div style="padding:8px 10px;background:var(--bg-body,#f8fafc);font-weight:700;font-size:13px;">Cuentas del día</div>'
                    + accountHtml + '</div>'
                    + userHtml
                    + movHtml;
            }
        })
        .catch(function() {
            if (body) body.innerHTML = '<p style="color:var(--dmc-DC2626, #DC2626);">Error al cargar el detalle.</p>';
        });
    }

    function cajaShareWhatsapp(sessionId) {
        fetch(window.cajaBase + '?action=report_whatsapp&id=' + sessionId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                return showAlert((d && d.message) || 'No se pudo preparar WhatsApp', 'error');
            }
            var pdfUrl = d.pdf_url || (window.cajaBase + '?action=pdf&id=' + sessionId);
            if (typeof sharePdfToWhatsApp === 'function') {
                return sharePdfToWhatsApp({
                    pdfUrl: pdfUrl,
                    waLink: d.link || '',
                    title: 'Cierre de caja #' + sessionId,
                    filename: 'Cierre_Caja_' + sessionId + '.pdf',
                    text: 'Cierre de caja #' + sessionId
                });
            }
            if (d.link) window.open(d.link, '_blank', 'noopener');
        })
        .catch(function() { showAlert('Error al preparar WhatsApp', 'error'); });
    }

    function cajaShareEmail(sessionId) {
        var modal = document.getElementById('cajaShareEmailModal');
        var idInput = document.getElementById('cajaShareEmailId');
        var emailInput = document.getElementById('cajaShareEmailTo');
        var adminWrap = document.getElementById('cajaShareEmailAdminWrap');
        var adminPass = document.getElementById('cajaShareEmailAdminPass');
        var unlockBtn = document.getElementById('cajaShareEmailUnlock');
        if (idInput) idInput.value = String(sessionId);
        if (emailInput) {
            var def = (emailInput.getAttribute('data-default-email') || '').trim();
            emailInput.value = def || emailInput.value || '';
            if (def) {
                emailInput.readOnly = true;
                if (adminWrap) adminWrap.style.display = 'none';
                if (adminPass) { adminPass.value = ''; adminPass.required = false; }
            } else {
                emailInput.readOnly = false;
            }
        }
        if (modal) modal.style.display = 'flex';
        if (emailInput && !emailInput.readOnly) setTimeout(function() { emailInput.focus(); }, 50);
        if (unlockBtn && !unlockBtn._bound) {
            unlockBtn._bound = true;
            unlockBtn.addEventListener('click', function () {
                if (!emailInput) return;
                emailInput.readOnly = false;
                emailInput.focus();
                if (adminWrap) adminWrap.style.display = '';
                if (adminPass) {
                    adminPass.required = true;
                    adminPass.focus();
                }
            });
        }
        if (emailInput && !emailInput._boundChange) {
            emailInput._boundChange = true;
            emailInput.addEventListener('input', function () {
                var def = (emailInput.getAttribute('data-default-email') || '').trim().toLowerCase();
                var cur = (emailInput.value || '').trim().toLowerCase();
                var changing = def !== '' && cur !== '' && cur !== def;
                if (adminWrap) adminWrap.style.display = changing || !emailInput.readOnly && def !== '' ? '' : 'none';
                if (adminPass) adminPass.required = changing;
            });
        }
    }

    (function() {
        var params = new URLSearchParams(window.location.search);
        var shareId = params.get('share_close');
        if (shareId) {
            var modal = document.getElementById('cajaShareCloseModal');
            var pdf = document.getElementById('cajaShareClosePdf');
            if (pdf) pdf.href = window.cajaBase + '?action=pdf&id=' + encodeURIComponent(shareId);
            document.getElementById('cajaShareCloseWa').onclick = function() { cajaShareWhatsapp(shareId); };
            document.getElementById('cajaShareCloseMail').onclick = function() { cajaShareEmail(shareId); };
            if (modal) modal.style.display = 'flex';
            if (window.history && history.replaceState) {
                params.delete('share_close');
                var qs = params.toString();
                history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
            }
        }
        if (location.search.indexOf('focus=cierresReportes') >= 0 || location.hash === '#cierresReportes') {
            var el = document.getElementById('cierresReportes');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    })();
</script>
<?php else: ?>
<?php
$cajaReportEmailPos = trim((string)($cajaReportEmail ?? $companyEmail ?? ''));
$hasCajaDefaultEmailPos = $cajaReportEmailPos !== '';
$cajaBasePos = $viewInstance->route('app/caja');
?>
<div id="cajaShareEmailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3>Enviar cierre por correo</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('cajaShareEmailModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="<?php echo $cajaBasePos; ?>?action=report_email" data-ajax="true" id="cajaShareEmailForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="cajaShareEmailId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label>Correo destino</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="email" name="email" id="cajaShareEmailTo" class="form-control" required
                               placeholder="correo@empresa.com"
                               value="<?php echo htmlspecialchars($cajaReportEmailPos); ?>"
                               data-default-email="<?php echo htmlspecialchars($cajaReportEmailPos); ?>"
                               <?php echo $hasCajaDefaultEmailPos ? 'readonly' : ''; ?>>
                        <?php if ($hasCajaDefaultEmailPos): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="cajaShareEmailUnlock" title="Cambiar correo predeterminado">Cambiar</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group" id="cajaShareEmailAdminWrap" style="display:none;">
                    <label>Clave de administrador</label>
                    <input type="password" name="admin_password" id="cajaShareEmailAdminPass" class="form-control" autocomplete="current-password" placeholder="Requerida para cambiar el correo">
                </div>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0;">
                    <?php if ($hasCajaDefaultEmailPos): ?>
                        Correo <strong>predeterminado</strong>. Para cambiarlo use la clave de administrador.
                    <?php else: ?>
                        El correo quedará como <strong>predeterminado</strong> para próximos envíos.
                    <?php endif; ?>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('cajaShareEmailModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</div>
<script>
    window.invRouteVentasDetail = '<?php echo $viewInstance->route('app/ventas'); ?>?action=detail';
    window.invRouteVentasShare = '<?php echo $viewInstance->route('app/ventas'); ?>?action=share';
    window.cajaBase = <?php echo json_encode($viewInstance->route('app/caja')); ?>;

    function cajaShareWhatsapp(sessionId) {
        fetch(window.cajaBase + '?action=report_whatsapp&id=' + sessionId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                return showAlert((d && d.message) || 'No se pudo preparar WhatsApp', 'error');
            }
            var pdfUrl = d.pdf_url || (window.cajaBase + '?action=pdf&id=' + sessionId);
            if (typeof sharePdfToWhatsApp === 'function') {
                return sharePdfToWhatsApp({
                    pdfUrl: pdfUrl,
                    waLink: d.link || '',
                    title: 'Cierre de caja #' + sessionId,
                    filename: 'Cierre_Caja_' + sessionId + '.pdf',
                    text: 'Cierre de caja #' + sessionId
                });
            }
            if (d.link) window.open(d.link, '_blank', 'noopener');
        })
        .catch(function() { showAlert('Error al preparar WhatsApp', 'error'); });
    }

    function cajaShareEmail(sessionId) {
        var modal = document.getElementById('cajaShareEmailModal');
        var idInput = document.getElementById('cajaShareEmailId');
        var emailInput = document.getElementById('cajaShareEmailTo');
        var adminWrap = document.getElementById('cajaShareEmailAdminWrap');
        var adminPass = document.getElementById('cajaShareEmailAdminPass');
        var unlockBtn = document.getElementById('cajaShareEmailUnlock');
        if (idInput) idInput.value = String(sessionId);
        if (emailInput) {
            var def = (emailInput.getAttribute('data-default-email') || '').trim();
            emailInput.value = def || emailInput.value || '';
            if (def) {
                emailInput.readOnly = true;
                if (adminWrap) adminWrap.style.display = 'none';
                if (adminPass) { adminPass.value = ''; adminPass.required = false; }
            } else {
                emailInput.readOnly = false;
            }
        }
        if (modal) modal.style.display = 'flex';
        if (emailInput && !emailInput.readOnly) setTimeout(function() { emailInput.focus(); }, 50);
        if (unlockBtn && !unlockBtn._bound) {
            unlockBtn._bound = true;
            unlockBtn.addEventListener('click', function () {
                if (!emailInput) return;
                emailInput.readOnly = false;
                if (adminWrap) adminWrap.style.display = '';
                if (adminPass) adminPass.required = true;
                emailInput.focus();
            });
        }
        if (emailInput && !emailInput._boundChange) {
            emailInput._boundChange = true;
            emailInput.addEventListener('input', function () {
                var def = (emailInput.getAttribute('data-default-email') || '').trim().toLowerCase();
                var cur = (emailInput.value || '').trim().toLowerCase();
                var changing = def !== '' && cur !== '' && cur !== def;
                if (adminWrap) adminWrap.style.display = changing || (!emailInput.readOnly && def !== '') ? '' : 'none';
                if (adminPass) adminPass.required = changing;
            });
        }
    }

    (function() {
        var params = new URLSearchParams(window.location.search);
        var shareId = params.get('share_close');
        if (!shareId) return;
        var wrap = document.createElement('div');
        wrap.className = 'modal-overlay';
        wrap.style.display = 'flex';
        wrap.innerHTML = '<div class="modal-content" style="max-width:420px;">' +
            '<div class="modal-header"><h3>Caja cerrada</h3>' +
            '<button type="button" class="modal-close" id="cajaPosShareX">&times;</button></div>' +
            '<div class="modal-body"><p style="margin-top:0;">Enviar reporte de cierre:</p>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
            '<a class="btn btn-info" target="_blank" rel="noopener" href="' + window.cajaBase + '?action=pdf&id=' + encodeURIComponent(shareId) + '">PDF</a>' +
            '<button type="button" class="btn btn-secondary" id="cajaPosShareWa">WhatsApp</button>' +
            '<button type="button" class="btn btn-secondary" id="cajaPosShareMail">Correo</button>' +
            '</div></div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-primary" id="cajaPosShareOk">Listo</button></div></div>';
        document.body.appendChild(wrap);
        function close() { wrap.remove(); }
        document.getElementById('cajaPosShareX').onclick = close;
        document.getElementById('cajaPosShareOk').onclick = close;
        document.getElementById('cajaPosShareWa').onclick = function() { cajaShareWhatsapp(shareId); };
        document.getElementById('cajaPosShareMail').onclick = function() { cajaShareEmail(shareId); };
        if (window.history && history.replaceState) {
            params.delete('share_close');
            var qs = params.toString();
            history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
        }
    })();

</script>

<?php endif; ?>
