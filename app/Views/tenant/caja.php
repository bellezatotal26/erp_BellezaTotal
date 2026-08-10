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
$stockAlert = $stockAlert ?? ['count' => 0, 'negative_count' => 0, 'loan_count' => 0, 'sale_debt_count' => 0, 'summary_lines' => []];
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
<div id="posOfflineBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:#FEF3C7;color:#92400E;border:1px solid #F59E0B;font-size:13px;"></div>
<div id="posNetworkBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:#DBEAFE;color:#1E3A8A;border:1px solid #3B82F6;font-size:13px;"></div>
<div id="posConflictBanner" hidden style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;background:#FEE2E2;color:#991B1B;border:1px solid #EF4444;font-size:13px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span id="posConflictBannerText"></span>
    <button type="button" class="btn btn-sm btn-danger" id="posConflictReviewBtn">Revisar conflictos</button>
</div>

<?php if (!$openSession): ?>
<?php if (($stockAlert['count'] ?? 0) > 0): ?>
<div class="alert alert-warning alert-stock-pending" style="margin-bottom:16px;text-align:left;">
    <strong>Pendiente del día anterior:</strong>
    hay <?php echo (int)$stockAlert['count']; ?> producto(s) con stock negativo o unidades pendientes
    (<?php echo (int)($stockAlert['negative_count'] ?? 0); ?> en negativo ·
    <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> préstamo(s) ·
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
    <h2 style="color:var(--color-primary);margin-bottom:10px;">Caja Cerrada</h2>
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
    préstamos: <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> ·
    ventas sin stock: <?php echo (int)($stockAlert['sale_debt_count'] ?? 0); ?>).
    <?php if (!$isPosRole && \SoftNova\Core\TenantMiddleware::canAccess('prestamos')): ?>
        <a href="<?php echo $viewInstance->route('app/prestamos'); ?>">Ver detalle</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
if (!$isPosRole) {
    $pageTopStatsHtml = '
<div class="page-top-stat"><h4>Monto Apertura</h4><div class="stat-value">' . htmlspecialchars(fmt((float)$openSession['opening_amount'], $currency)) . '</div></div>
<div class="page-top-stat"><h4>Ingresos</h4><div class="stat-value page-top-stat--in">+' . htmlspecialchars(fmt((float)$totals['incomes'], $currency)) . '</div></div>
<div class="page-top-stat"><h4>Egresos</h4><div class="stat-value page-top-stat--out">-' . htmlspecialchars(fmt((float)$totals['expenses'], $currency)) . '</div></div>
<div class="page-top-stat"><h4>Balance Actual</h4><div class="stat-value ' . ($totals['balance'] >= 0 ? 'page-top-stat--in' : 'page-top-stat--out') . '">' . htmlspecialchars(fmt((float)$totals['balance'], $currency)) . '</div></div>';
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
    <?php if (!$isPosRole || \SoftNova\Core\TenantMiddleware::canDo('edit', 'caja')): ?>
    <button type="button" onclick="openCloseCashModal()" class="btn btn-danger"
            data-balance="<?php echo htmlspecialchars((string)round((float)($totals['expected_cash'] ?? $totals['balance'] ?? 0), 2)); ?>"
            data-opening="<?php echo htmlspecialchars(fmt($openSession['opening_amount'], $currency)); ?>"
            data-incomes="<?php echo htmlspecialchars(fmt($totals['incomes'], $currency)); ?>"
            data-expenses="<?php echo htmlspecialchars(fmt($totals['expenses'], $currency)); ?>"
            data-sales-count="<?php echo (int)($totals['sales_count'] ?? 0); ?>"
            data-sales-total="<?php echo htmlspecialchars(fmt($totals['sales_total'] ?? 0, $currency)); ?>"
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
         data-sale-url="<?php echo htmlspecialchars($saleUrl); ?>"
         data-sync-url="<?php echo htmlspecialchars($syncUrl); ?>"
         data-csrf="<?php echo htmlspecialchars($csrfPos); ?>"
         data-symbol="<?php echo htmlspecialchars($currency['symbol'] ?? '$'); ?>"
         data-decimals="<?php echo (int)($currency['decimals'] ?? 0); ?>"
         data-thousands="<?php echo htmlspecialchars($currency['thousands'] ?? '.'); ?>"
         data-decimal="<?php echo htmlspecialchars($currency['decimal'] ?? ','); ?>"
         data-prefix="<?php echo htmlspecialchars((string)$invoicePrefix); ?>"
         data-user="<?php echo htmlspecialchars($userName); ?>">
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
                        <select id="posDiscountPreset" class="form-control" style="width:110px;" title="Descuento sobre el subtotal" data-custom-other="discount_percent">
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
                        <input type="number" id="posDeliveryTotal" class="form-control" min="0" step="0.01" value="0" style="width:110px;" placeholder="Total" inputmode="decimal" title="Costo total del domicilio / envío">
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
                        <input type="number" id="posDeliveryClient" class="form-control" min="0" step="0.01" value="0" style="width:100px;" placeholder="0" inputmode="decimal" title="Parte del cliente que suma al total de la venta">
                        <label class="pos-label" for="posDeliveryCompany" style="margin:0;">Empresa</label>
                        <input type="number" id="posDeliveryCompany" class="form-control" min="0" step="0.01" value="0" style="width:100px;" placeholder="0" inputmode="decimal" title="Parte empresa → gasto Transporte (no suma al total de la venta)">
                        <span id="posDeliverySplitHint" class="pos-label" style="margin:0;opacity:.75;font-weight:400;">Cliente → total venta · Empresa → gasto Transporte</span>
                </div>
                <div class="pos-ticket-meta">
                    <span>Items: <strong id="posItemCount">0</strong></span>
                    <span>Registro: <strong id="posClock"><?php echo date('g:i A'); ?></strong></span>
                </div>
                <div id="posSubtotalLine" class="pos-sub-line" style="display:none;">Subtotal: <span id="posSubtotal"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posDiscountLine" class="pos-sub-line" style="display:none;color:#B45309;">Descuento: <span id="posDiscountAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posDeliveryLine" class="pos-sub-line" style="display:none;color:#1D4ED8;">Domicilio: <span id="posDeliveryAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posDeliveryCompanyLine" class="pos-sub-line" style="display:none;color:#7C3AED;">Empresa (transporte): <span id="posDeliveryCompanyAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posTaxLine" class="pos-sub-line" style="display:none;color:#0F766E;">Impuesto: <span id="posTaxAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div id="posCreditSurchargeLine" class="pos-sub-line" style="display:none;color:#B45309;">Recargo crédito &gt;<?php echo (int)($creditSurchargeDays ?? 60); ?> días: <span id="posCreditSurchargeAmt"><?php echo fmt(0, $currency); ?></span></div>
                <div class="pos-total-block">
                    <div class="pos-total-line">TOTAL: <span id="posTotal"><?php echo fmt(0, $currency); ?></span></div>
                    <div class="pos-cash-change" id="posCashChangeBlock">
                        <div class="pos-cash-field">
                            <label class="pos-label" for="posCashReceived">Billete / recibido</label>
                            <input type="number" id="posCashReceived" class="form-control" min="0" step="0.01" placeholder="0" inputmode="decimal"
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
        <div class="pos-pay-bar" id="posPayBar" hidden>
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
                    <select id="posPaymentTerms" class="form-control">
                        <option value="net_15">15 días</option>
                        <option value="net_30" selected>30 días</option>
                        <option value="net_60">60 días</option>
                        <option value="net_90">90 días</option>
                        <option value="net_120">120 días</option>
                        <option value="overdue">Vencido</option>
                    </select>
                </div>
                <div class="form-group" id="posInitialGroup" style="margin:0;min-width:110px;display:none;">
                    <label style="font-size:12px;">Abono inicial</label>
                    <input type="number" id="posInitialPayment" class="form-control" step="0.01" min="0" value="0">
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
                <button type="button" class="btn btn-success" id="posConfirmPay">Cobrar ahora</button>
                <button type="button" class="btn btn-secondary" id="posCancelPay">Cancelar</button>
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
        <?php if ($openItemQuick): ?>
        <div class="pos-open-item-bar">
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
            <span style="font-size:12px;color:var(--color-text-secondary);">Ítem libre · nombre y precio al vender · sin stock</span>
        </div>
        <?php endif; ?>
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
                 data-stock="<?php echo (int)($op['stock'] ?? 0); ?>"
                 data-type="<?php echo htmlspecialchars((string)($op['product_type'] ?? 'product')); ?>"
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
        <div class="pos-search-results" id="posSearchResults">
            <?php if (!empty($recentWithoutOpen)): ?>
                <p class="pos-search-hint" style="margin-bottom:8px;">Últimos vendidos</p>
                <?php foreach ($recentWithoutOpen as $rp): ?>
                    <div class="pos-result-row pos-recent-product"
                         data-id="<?php echo (int)$rp['id']; ?>"
                         data-name="<?php echo \SoftNova\Core\e((string)$rp['name']); ?>"
                         data-code="<?php echo \SoftNova\Core\e((string)($rp['code'] ?? '')); ?>"
                         data-price="<?php echo (float)$rp['sale_price']; ?>"
                         data-stock="<?php echo (int)$rp['stock']; ?>"
                         data-type="<?php echo htmlspecialchars((string)($rp['product_type'] ?? 'product')); ?>"
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
                    <th>Factura</th><th>Cliente</th><th>Fecha</th><th>Total</th><th>Pagado</th><th>Pago</th><th>Estado</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($todaySales as $sale): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Cliente Final'); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></td>
                        <td style="font-weight:600;"><?php echo fmt((float)$sale['total'], $currency); ?></td>
                        <td><?php echo ($sale['payment_status'] ?? '') === 'paid' ? fmt((float)$sale['total'], $currency) : fmt((float)($sale['paid_amount'] ?? 0), $currency); ?></td>
                        <td>
                            <span class="badge <?php
                                $pm = (string)($sale['payment_method'] ?? '');
                                echo $pm === 'cash' ? 'badge-success' : ($pm === 'mixed' || in_array($pm, ['card','dataphone','payment_link','transfer','debit_card','credit_card','nequi'], true) ? 'badge-info' : 'badge-warning');
                            ?>">
                                <?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)($sale['payment_method'] ?? 'cash'))); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (($sale['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge badge-danger">Cancelado</span>
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
                            <a href="<?php echo $ventasBase; ?>?action=pdf&format=ticket&id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="Ticket 58mm">58mm</a>
                            <button type="button" class="btn btn-sm btn-success" onclick="shareSale(<?php echo (int)$sale['id']; ?>)" title="WhatsApp / Correo">↗</button>
                            <?php if ($canEditPaymentMethod && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-secondary" title="Editar medio de pago"
                                    onclick="openEditPaymentMethodModal(<?php echo (int)$sale['id']; ?>, '<?php echo htmlspecialchars((string)($sale['invoice_number'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string)($sale['payment_method'] ?? 'cash'), ENT_QUOTES); ?>')">
                                    Pago
                                </button>
                            <?php endif; ?>
                            <?php if (in_array($sale['payment_status'] ?? '', ['pending', 'partial'], true) && ($sale['status'] ?? '') !== 'cancelled'): ?>
                                <button type="button" onclick="openPaymentModal(<?php echo (int)$sale['id']; ?>,<?php echo (float)$sale['total']; ?>,<?php echo (float)($sale['paid_amount'] ?? 0); ?>)" class="btn btn-sm btn-success" title="Registrar abono"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></button>
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
$todayExpensesByUser = $todayExpensesByUser ?? [];
$todayExpensesTotal = (float)($todayExpensesTotal ?? 0);
$todayExpensesCount = (int)($todayExpensesCount ?? 0);
?>
<div class="card neumorphic" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h3>Gastos del día<?php echo $todayExpensesCount > 0 ? ' (' . $todayExpensesCount . ')' : ''; ?></h3>
        <?php if ($todayExpensesCount > 0): ?>
            <span style="font-size:13px;color:#DC2626;font-weight:600;">Total: -<?php echo fmt($todayExpensesTotal, $currency); ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($todayExpensesByUser)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay gastos registrados hoy</p>
        <?php else: ?>
            <?php foreach ($todayExpensesByUser as $group): ?>
                <div style="margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--color-border);">
                        <strong><?php echo htmlspecialchars((string)$group['user_name']); ?></strong>
                        <span style="color:#DC2626;font-weight:600;font-size:13px;">-<?php echo fmt((float)$group['total'], $currency); ?> · <?php echo count($group['items']); ?> gasto(s)</span>
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
                                    <td style="color:#DC2626;font-weight:600;">-<?php echo fmt((float)$exp['amount'], $currency); ?></td>
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

<?php if (!$isPosRole): ?>
<div class="card neumorphic">
    <div class="card-header"><h3>📋 Movimientos del Día (<?php echo count($movements); ?>)</h3></div>
    <div class="card-body">
        <?php if (empty($movements)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay movimientos</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr><th>Hora</th><th>Tipo</th><th>Descripción</th><th>Monto</th></tr></thead>
                <tbody><?php foreach($movements as $mov): ?>
                    <tr><td><?php echo date('H:i',strtotime($mov['created_at'])); ?></td><td><span class="badge <?php echo $mov['type']==='income'?'badge-success':'badge-danger';?>"><?php echo $mov['type']==='income'?'Ingreso':'Egreso';?></span></td><td><?php echo htmlspecialchars($mov['description']);?></td><td style="color:<?php echo $mov['type']==='income'?'#10B981':'#DC2626';?>;font-weight:600;"><?php echo $mov['type']==='income'?'+':'-';?><?php echo fmt($mov['amount'],$currency);?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modales de movimiento y cierre -->
<?php if (!$isPosRole): ?>
<div id="movementModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:450px;">
        <div class="modal-header"><h3>Registrar Movimiento</h3><button onclick="document.getElementById('movementModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/caja'); ?>?action=movement" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="session_id" value="<?php echo $openSession['id']; ?>">
            <div class="modal-body">
                <div class="form-group"><label>Tipo *</label><select name="type" class="form-control" required><option value="income">Ingreso (+)</option><option value="expense">Egreso (-)</option></select></div>
                <div class="form-group"><label>Descripción *</label><input type="text" name="description" class="form-control" placeholder="Ej: Pago proveedor..." required></div>
                <div class="form-group"><label>Monto (<?php echo $currency['symbol']; ?>) *</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0" required></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('movementModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-primary">Registrar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

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
                    <?php echo (int)($stockAlert['loan_count'] ?? 0); ?> préstamos ·
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
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span>Apertura</span><strong id="closeSumOpening">—</strong></div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span>Ingresos</span><strong id="closeSumIncomes" style="color:#10B981;">—</strong></div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span>Egresos</span><strong id="closeSumExpenses" style="color:#DC2626;">—</strong></div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span>Ventas del dia</span><strong id="closeSumSales">—</strong></div>
                    <div id="closeSumPayments" style="margin:8px 0;padding:8px;border:1px dashed var(--color-border);border-radius:6px;font-size:12px;">
                        <?php
                        $payBreak = $totals['payment_breakdown'] ?? [];
                        if (!empty($payBreak)):
                            uasort($payBreak, static function ($a, $b) {
                                return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
                            });
                        ?>
                            <div style="font-weight:600;margin-bottom:6px;">Desglose por tipo de pago</div>
                            <?php foreach ($payBreak as $pb): ?>
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                    <span><?php echo htmlspecialchars((string)($pb['label'] ?? '')); ?> (<?php echo (int)($pb['count'] ?? 0); ?>)</span>
                                    <strong><?php echo fmt((float)($pb['total'] ?? 0), $currency); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="color:var(--color-text-secondary);">Sin ventas por método de pago en esta sesión.</div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--color-border);padding-top:8px;margin-top:4px;"><span>Efectivo esperado en cajón</span><strong id="closeSumBalance" style="color:#10B981;">—</strong></div>
                </div>
                <p style="margin-bottom:12px;font-size:12px;color:var(--color-text-secondary);">Al cerrar se descargara el reporte PDF. Si no abre, use el historial de reportes.</p>
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
    var incomes = btn.getAttribute('data-incomes') || '—';
    var expenses = btn.getAttribute('data-expenses') || '—';
    var salesCount = btn.getAttribute('data-sales-count') || '0';
    var salesTotal = btn.getAttribute('data-sales-total') || '—';
    var balanceLabel = btn.getAttribute('data-balance-label') || '—';
    var balance = btn.getAttribute('data-balance') || '0';
    document.getElementById('closeSumOpening').textContent = opening;
    document.getElementById('closeSumIncomes').textContent = '+' + incomes;
    document.getElementById('closeSumExpenses').textContent = '-' + expenses;
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
$financialCats = $financialCats ?? [];
$operationalCats = $operationalCats ?? [];
$administrativeCats = $administrativeCats ?? [];
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
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Categoria *</label>
                        <select name="category_id" class="form-control" required data-custom-other="expense_category">
                            <option value="">Seleccionar…</option>
                            <optgroup label="Gastos financieros">
                                <?php foreach ($financialCats as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Gastos operativos">
                                <?php foreach ($operationalCats as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ($c['code'] ?? '') === 'general' ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Gastos administrativos">
                                <?php foreach ($administrativeCats as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <option value="__other__">Otro (agregar nuevo tipo)…</option>
                        </select>
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
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="affect_cash" value="1" checked>
                        Ya pagado (si es efectivo, también registra egreso en caja)
                    </label>
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

<?php
$canLoanFromCaja = !empty($canLoanFromCaja);
$loanProducts = $loanProducts ?? [];
$loanReasonsHtml = $loanReasonsHtml ?? \SoftNova\Services\ProductLoan::optionsHtml();
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
            hint.style.color = '#B45309';
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
    var reason = document.getElementById('cajaLoanReason');
    if (qty) qty.addEventListener('input', updateCajaLoanStockHint);
    if (reason) reason.addEventListener('change', function(){
        var req = document.getElementById('cajaLoanNoteReq');
        var note = document.getElementById('cajaLoanNote');
        var isOther = reason.value === 'otro';
        if (req) req.style.display = isOther ? 'inline' : 'none';
        if (note) note.required = isOther;
    });
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
                <input type="number" id="openItemPrice" class="form-control" min="0.01" step="any" placeholder="0">
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
            <div class="form-group"><label>Monto *</label><input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="0.01" required></div>
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
$historyFrom = $historyFrom ?? date('Y-m-d', strtotime('-30 days'));
$historyTo = $historyTo ?? date('Y-m-d');
$historySessions = $historySessions ?? [];
$cajaBase = $viewInstance->route('app/caja');
?>
<div class="card neumorphic" style="margin-top:20px;" id="cierresReportes">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h3>Reportes de cierre</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="<?php echo $cajaBase; ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
            <input type="hidden" name="focus" value="cierresReportes">
            <div class="form-group" style="margin:0;">
                <label>Desde</label>
                <input type="date" name="history_from" class="form-control" value="<?php echo htmlspecialchars($historyFrom); ?>">
            </div>
            <div class="form-group" style="margin:0;">
                <label>Hasta</label>
                <input type="date" name="history_to" class="form-control" value="<?php echo htmlspecialchars($historyTo); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </form>
        <?php if (empty($historySessions)): ?>
            <p style="color:var(--color-text-secondary);text-align:center;padding:12px;">No hay cierres en el rango seleccionado.</p>
        <?php else: ?>
        <div class="table-container"><table>
            <thead><tr><th>Apertura</th><th>Cierre</th><th>Usuario</th><th>Monto Ini.</th><th>Monto Fin</th><th>Dif.</th><th>Acciones</th></tr></thead>
            <tbody><?php foreach ($historySessions as $h): ?>
                <?php $diff = (float)($h['closing_amount'] ?? 0) - (float)($h['opening_amount'] ?? 0); ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($h['opening_date'])); ?></td>
                    <td><?php echo !empty($h['closing_date']) ? date('d/m/Y H:i', strtotime($h['closing_date'])) : '—'; ?></td>
                    <td><?php echo htmlspecialchars($h['user_name'] ?? ''); ?></td>
                    <td><?php echo fmt($h['opening_amount'], $currency); ?></td>
                    <td><?php echo fmt($h['closing_amount'] ?? 0, $currency); ?></td>
                    <td style="color:<?php echo $diff >= 0 ? '#10B981' : '#DC2626'; ?>;"><?php echo ($diff >= 0 ? '+' : '') . fmt($diff, $currency); ?></td>
                    <td style="white-space:nowrap;">
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
            <p style="margin-top:0;">El PDF se descargó. También puede enviar el reporte:</p>
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
    window.cajaTaxRate = <?php echo json_encode((float)($taxRate ?? 0)); ?>;

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
