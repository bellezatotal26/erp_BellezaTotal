<?php
$layout = 'tenant';
$title = 'Ventas - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Punto de Venta';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$sales = $sales ?? [];
$todayTotal = $todayTotal ?? 0;
$todayCount = $todayCount ?? 0;
$products = $products ?? [];
$customers = $customers ?? [];
$currency = $currency ?? ['symbol' => '$', 'decimals' => 0];
$canCreateSale = \SoftNova\Core\TenantMiddleware::canDo('create', 'ventas');
$canDeleteSale = \SoftNova\Core\TenantMiddleware::canDo('delete', 'ventas');
$canExportSale = \SoftNova\Core\TenantMiddleware::canDo('export', 'ventas');
$filters = $filters ?? ['q'=>'','from'=>'','to'=>'','status'=>'','sort'=>'date','dir'=>'desc','query'=>[]];
$ventasBase = $viewInstance->route('app/ventas');
$needBarcode = true;
$pageScripts = ['ventas.js'];

function fmtV(float $a, array $c): string
{
    return $c['symbol'] . ' ' . number_format($a, $c['decimals'], $c['decimal'] ?? ',', $c['thousands'] ?? '.');
}
?>
<meta name="currency-symbol" content="<?php echo $currency['symbol']; ?>">
<meta name="currency-decimals" content="<?php echo $currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<div class="stats-grid">
    <div class="stat-card neumorphic"><h4>Ventas Hoy</h4><div class="stat-value"><?php echo (int)$todayCount; ?></div></div>
    <div class="stat-card neumorphic"><h4>Total Hoy</h4><div class="stat-value" style="color:#10B981;"><?php echo fmtV($todayTotal, $currency); ?></div></div>
</div>

<div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;">
    <?php if ($canCreateSale): ?>
        <button onclick="openSaleModal()" class="btn btn-primary neumorphic-btn" title="Nueva venta">Nueva Venta</button>
    <?php endif; ?>
    <?php if ($canExportSale): ?>
        <a href="<?php echo $ventasBase; ?>?action=export" class="btn btn-secondary" title="Exportar CSV">Exportar CSV</a>
    <?php endif; ?>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $ventasBase; ?>" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($filters['sort']); ?>">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($filters['dir']); ?>">
            <div class="form-group" style="margin:0;min-width:220px;flex:1;">
                <label>Buscar</label>
                <input class="form-control" type="search" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="Factura, cliente o documento..." title="Buscar ventas">
            </div>
            <div class="form-group" style="margin:0;"><label>Desde</label><input class="form-control" type="date" name="from" value="<?php echo htmlspecialchars($filters['from']); ?>"></div>
            <div class="form-group" style="margin:0;"><label>Hasta</label><input class="form-control" type="date" name="to" value="<?php echo htmlspecialchars($filters['to']); ?>"></div>
            <div class="form-group" style="margin:0;min-width:150px;">
                <label>Estado pago</label>
                <select class="form-control" name="status">
                    <option value="">Todos</option>
                    <option value="paid" <?php echo $filters['status']==='paid'?'selected':''; ?>>Pagado</option>
                    <option value="partial" <?php echo $filters['status']==='partial'?'selected':''; ?>>Parcial</option>
                    <option value="pending" <?php echo $filters['status']==='pending'?'selected':''; ?>>Pendiente</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="<?php echo $ventasBase; ?>">Limpiar</a>
        </form>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>📋 Últimas Ventas</h3></div>
    <div class="card-body">
        <?php if (empty($sales)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay ventas con esos filtros</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Factura','column'=>'invoice','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Cliente','column'=>'customer','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Fecha','column'=>'date','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Total','column'=>'total','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Pagado','column'=>'paid','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Pago','column'=>'method','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Estado','column'=>'status','filters'=>$filters,'baseUrl'=>$ventasBase]); ?></th>
                    <th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'General'); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></td>
                        <td style="font-weight:600;"><?php echo fmtV($sale['total'], $currency); ?></td>
                        <td><?php echo $sale['payment_status'] === 'paid' ? fmtV($sale['total'], $currency) : fmtV($sale['paid_amount'] ?? 0, $currency); ?></td>
                        <td>
                            <span class="badge <?php echo $sale['payment_method'] === 'cash' ? 'badge-success' : (in_array($sale['payment_method'], ['card','dataphone','payment_link','transfer'], true) ? 'badge-info' : 'badge-warning'); ?>">
                                <?php echo htmlspecialchars(\SoftNova\Services\PaymentMethodCatalog::label((string)$sale['payment_method'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (($sale['status'] ?? '') === 'cancelled'): ?>
                                <span class="badge badge-danger">Cancelado</span>
                            <?php elseif ($sale['payment_status'] === 'paid'): ?>
                                <span class="badge badge-success">Pagado</span>
                            <?php elseif ($sale['payment_status'] === 'partial'): ?>
                                <span class="badge badge-warning">Parcial</span>
                            <?php else: ?>
                                <span class="badge badge-info">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <button onclick="viewDetail(<?php echo $sale['id']; ?>)" class="btn btn-sm btn-info" title="Ver detalle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            <a href="<?php echo $ventasBase; ?>?action=pdf&id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="PDF carta"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg></a>
                            <a href="<?php echo $ventasBase; ?>?action=pdf&format=ticket&id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="Ticket 58mm">58mm</a>
                            <button type="button" class="btn btn-sm btn-success" onclick="shareSale(<?php echo (int)$sale['id']; ?>)" title="WhatsApp / Correo">↗</button>
                            <?php if (in_array($sale['payment_status'], ['pending', 'partial'])): ?>
                                <button onclick="openPaymentModal(<?php echo $sale['id']; ?>,<?php echo $sale['total']; ?>,<?php echo ($sale['paid_amount'] ?? 0); ?>)" class="btn btn-sm btn-success" title="Registrar abono"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></button>
                            <?php endif; ?>
                            <?php if ($canDeleteSale && $sale['status'] !== 'cancelled'): ?>
                                <form method="POST" action="<?php echo $ventasBase; ?>?action=cancel" style="display:inline;" data-ajax="true">
                                    <?php echo \SoftNova\Core\csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$sale['id']; ?>">
                                    <button type="submit" data-confirm="Eliminar esta venta? Se cancelara, se devolvera el stock y se ajustara la caja." class="btn btn-sm btn-danger" title="Eliminar venta"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $pagination = $pagination ?? null;
            $paginationBaseUrl = $ventasBase;
            $paginationQuery = $filters['query'] ?? [];
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Nueva Venta -->
<div id="saleModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:750px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header"><h3>Nueva Venta</h3><button onclick="closeSaleModal()" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $ventasBase; ?>?action=create" data-ajax="true" data-prepare="saleItems" onsubmit="return prepareSaleItems()">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div class="form-group">
                <label>Cliente <span class="field-tip" data-tip="Escriba para buscar por nombre o documento y elija un cliente. Deje vacío o seleccione Consumidor Final si no aplica.">?</span></label>
                <div class="customer-combobox" id="customerCombobox">
                    <input type="hidden" name="customer_id" id="customerId" value="">
                    <input type="text" id="customerSearch" class="form-control" placeholder="Buscar o seleccionar cliente..." autocomplete="off" aria-autocomplete="list" aria-expanded="false">
                    <ul class="combobox-list" id="customerList" hidden>
                        <li class="combobox-option" data-id="" data-label="Consumidor Final" data-search="consumidor final">Consumidor Final</li>
                        <?php foreach ($customers as $c):
                            $label = trim(($c['first_name'] ?? $c['name']) . ' ' . ($c['last_name'] ?? ''));
                            $search = strtolower($label . ' ' . ($c['document_number'] ?? ''));
                        ?>
                            <li class="combobox-option" data-id="<?php echo (int)$c['id']; ?>" data-label="<?php echo htmlspecialchars($label); ?>" data-search="<?php echo htmlspecialchars($search); ?>">
                                <?php echo htmlspecialchars($label); ?>
                                <?php if (!empty($c['document_number'])): ?>
                                    <small><?php echo htmlspecialchars(($c['document_type'] ?? '') . ' ' . $c['document_number']); ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button type="button" onclick="quickAddCustomer()" class="link-btn">+ Nuevo Cliente</button>
            </div>
            <div class="form-group">
                <label>Productos <span class="field-tip" data-tip="Seleccione el producto, indique la cantidad y pulse + para agregarlo a la venta.">?</span></label>
                <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                    <input type="text" id="saleBarcodeScan" class="form-control" placeholder="Escanear…" autocomplete="off" data-barcode-input="true" style="flex:1;min-width:120px;" title="Pistola de códigos de barras">
                    <select id="productSelect" class="form-control" style="flex:2;min-width:180px;">
                        <option value="">Seleccionar producto...</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>"
                                    data-price="<?php echo $p['sale_price']; ?>"
                                    data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                    data-stock="<?php echo $p['stock']; ?>"
                                    data-code="<?php echo htmlspecialchars($p['code'] ?? ''); ?>">
                                <?php echo htmlspecialchars(($p['code'] ?? '') !== '' ? ($p['code'] . ' — ') : ''); ?><?php echo htmlspecialchars($p['name']); ?> (<?php echo fmtV($p['sale_price'], $currency); ?>) — Stock: <?php echo (int)$p['stock']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="productQty" value="1" min="1" class="form-control" style="width:80px;" placeholder="Cant" title="Cantidad a vender">
                    <button type="button" class="btn btn-primary" id="addProductBtn" onclick="addProduct(event)">+</button>
                </div>
                <p id="productStockHint" style="font-size:12px;color:var(--color-text-secondary);margin:0 0 8px;min-height:18px;"></p>
                <table id="itemsTable" style="width:100%;margin-top:10px;display:none;">
                    <thead><tr><th>Producto</th><th>Cant</th><th>Precio</th><th>Restante</th><th>Subtotal</th><th></th></tr></thead>
                    <tbody></tbody>
                    <tfoot><tr><td colspan="4" style="text-align:right;font-weight:600;">Total:</td><td id="saleTotal" style="font-weight:700;color:#10B981;">0</td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="form-group">
                <label>Notas <span class="field-tip" data-tip="Observaciones internas de la venta (referencia, mesa, entrega, etc.). No aparecen como ítem facturado.">?</span></label>
                <input type="text" name="notes" class="form-control" placeholder="Observaciones...">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Condición de pago</label>
                    <select name="payment_terms" id="paymentTerms" class="form-control" onchange="onPaymentTermsChange()">
                        <?php foreach (\SoftNova\Services\SalesDocumentService::paymentTerms() as $code => $lab): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($lab); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de Pago <span class="field-tip" data-tip="Contado: cobra el monto completo ahora. Crédito: deja saldo pendiente y genera una cuenta por cobrar.">?</span></label>
                    <select name="payment_type" id="paymentType" class="form-control" onchange="toggleCredit()">
                        <option value="full">Contado</option>
                        <option value="credit">Crédito</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="document_type" value="invoice">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Fecha facturación</label>
                    <input type="date" name="sale_date" id="saleDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="onPaymentTermsChange()">
                </div>
                <div class="form-group">
                    <label>Fecha vencimiento</label>
                    <input type="date" name="due_date" id="dueDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Fecha recepción</label>
                    <input type="date" name="received_date" class="form-control" placeholder="Opcional">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Método de Pago <span class="field-tip" data-tip="Medio con el que se recibe el dinero (efectivo, tarjeta, transferencia u otro).">?</span></label>
                    <select name="payment_method" class="form-control">
                        <?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash'); ?>
                    </select>
                </div>
                <div class="form-group" id="initialPaymentGroup" style="display:none;">
                    <label>Abono Inicial <span class="field-tip" data-tip="Monto que el cliente paga hoy. El resto queda como cuenta por cobrar.">?</span></label>
                    <input type="number" name="initial_payment" class="form-control" step="0.01" min="0" placeholder="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSaleModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="submitSaleBtn">Completar Venta</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Quick Customer (encima del modal de venta) -->
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
                    <div class="form-group"><label>Tipo Doc. <span class="field-tip" data-tip="Tipo de identificación del cliente (CC, CE, NIT, etc.).">?</span></label><select name="document_type" class="form-control"><option value="CC">C.C</option><option value="CE">C.E</option><option value="NIT">NIT</option><option value="PPT">PPT</option><option value="OTROS">OTROS</option></select></div>
                    <div class="form-group"><label>N° Documento * <span class="field-tip" data-tip="Número de identificación sin puntos ni espacios.">?</span></label><input type="text" name="document_number" class="form-control" placeholder="Número" required></div>
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

<!-- Modal Abono -->
<div id="paymentModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:400px;">
        <div class="modal-header"><h3>Registrar Abono</h3><button onclick="document.getElementById('paymentModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/ventas'); ?>?action=payment" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="sale_id" id="paySaleId">
            <p id="payInfo" style="margin-bottom:10px;"></p>
            <div class="form-group"><label>Monto * <span class="field-tip" data-tip="Cantidad que el cliente abona ahora. No puede superar el saldo pendiente.">?</span></label><input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="0.01" required></div>
            <div class="form-group"><label>Método <span class="field-tip" data-tip="Medio de pago de este abono.">?</span></label><select name="payment_method" class="form-control"><?php echo \SoftNova\Services\PaymentMethodCatalog::optionsHtml('cash', false); ?></select></div>
            <div class="form-group"><label>Notas <span class="field-tip" data-tip="Referencia del abono (número de transferencia, recibo, etc.).">?</span></label><input type="text" name="notes" class="form-control" placeholder="Abono"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('paymentModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-success">Registrar Abono</button></div>
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
            <form method="POST" action="<?php echo $viewInstance->route('app/ventas'); ?>?action=email" data-ajax="true">
                <?php echo \SoftNova\Core\csrf_field(); ?>
                <input type="hidden" name="id" id="shareSaleId">
                <div class="form-group"><label>Correo</label><input type="email" name="email" id="shareEmail" class="form-control" required></div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Enviar por correo</button>
            </form>
        </div>
    </div>
</div>

<script>
    window.invRouteVentasDetail = '<?php echo $viewInstance->route('app/ventas'); ?>?action=detail';
    window.invRouteVentasShare = '<?php echo $viewInstance->route('app/ventas'); ?>?action=share';
</script>
