<?php
$layout = 'tenant';
$title = 'Cotizaciones - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gestión de Cotizaciones';
$tenantName = $tenantName ?? 'Mi Empresa'; $userName = $userName ?? 'Usuario';
$quotes = $quotes ?? []; $products = $products ?? []; $customers = $customers ?? [];
$currency = $currency ?? ['symbol'=>'$','decimals'=>0];
$filters = $filters ?? ['q'=>'','from'=>'','to'=>'','status'=>'','sort'=>'date','dir'=>'desc','query'=>[]];
$cotizBase = $viewInstance->route('app/cotizaciones');
$needBarcode = true;
$pageScripts = ['cotizaciones.js'];
function fmtQ(float $a, array $c): string { return $c['symbol'].' '.number_format($a, $c['decimals'], $c['decimal']??',', $c['thousands']??'.'); }
?>
<meta name="currency-symbol" content="<?php echo $currency['symbol']; ?>">
<meta name="currency-decimals" content="<?php echo $currency['decimals']; ?>">
<?php echo flashMessage(); ?>

<div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;">
    <button onclick="openQuoteModal()" class="btn btn-primary neumorphic-btn" title="Nueva cotizacion">Nueva Cotizacion</button>
    <a href="<?php echo $cotizBase; ?>?action=export" class="btn btn-secondary" title="Exportar CSV">Exportar CSV</a>
</div>

<div class="card neumorphic" style="margin-bottom:18px;">
    <div class="card-body" style="padding:14px 18px;">
        <form method="GET" action="<?php echo $cotizBase; ?>" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($filters['sort']); ?>">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($filters['dir']); ?>">
            <div class="form-group" style="margin:0;min-width:220px;flex:1;">
                <label>Buscar</label>
                <input class="form-control" type="search" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="Número, cliente o documento...">
            </div>
            <div class="form-group" style="margin:0;"><label>Desde</label><input class="form-control" type="date" name="from" value="<?php echo htmlspecialchars($filters['from']); ?>"></div>
            <div class="form-group" style="margin:0;"><label>Hasta</label><input class="form-control" type="date" name="to" value="<?php echo htmlspecialchars($filters['to']); ?>"></div>
            <div class="form-group" style="margin:0;min-width:150px;">
                <label>Estado</label>
                <select class="form-control" name="status">
                    <option value="">Todos</option>
                    <option value="pending" <?php echo $filters['status']==='pending'?'selected':''; ?>>Pendiente</option>
                    <option value="accepted" <?php echo $filters['status']==='accepted'?'selected':''; ?>>Aceptada</option>
                    <option value="converted" <?php echo $filters['status']==='converted'?'selected':''; ?>>Convertida</option>
                    <option value="rejected" <?php echo $filters['status']==='rejected'?'selected':''; ?>>Rechazada</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="<?php echo $cotizBase; ?>">Limpiar</a>
        </form>
    </div>
</div>

<div class="card neumorphic">
    <div class="card-header"><h3>Cotizaciones</h3></div>
    <div class="card-body">
        <?php if (empty($quotes)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:20px;">No hay cotizaciones con esos filtros</p>
        <?php else: ?>
            <div class="table-container"><table>
                <thead><tr>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Número','column'=>'number','filters'=>$filters,'baseUrl'=>$cotizBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Cliente','column'=>'customer','filters'=>$filters,'baseUrl'=>$cotizBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Fecha','column'=>'date','filters'=>$filters,'baseUrl'=>$cotizBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Total','column'=>'total','filters'=>$filters,'baseUrl'=>$cotizBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Válido hasta','column'=>'valid','filters'=>$filters,'baseUrl'=>$cotizBase]); ?></th>
                    <th><?php $viewInstance->partial('sortable_th', ['label'=>'Estado','column'=>'status','filters'=>$filters,'baseUrl'=>$cotizBase]); ?></th>
                    <th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($quotes as $q): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($q['quote_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($q['customer_name'] ?? 'General'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($q['quote_date'])); ?></td>
                        <td style="font-weight:600;"><?php echo fmtQ($q['total'], $currency); ?></td>
                        <td><?php echo !empty($q['valid_until']) ? date('d/m/Y', strtotime($q['valid_until'])) : '-'; ?></td>
                        <td>
                            <span class="badge <?php echo $q['status']==='pending'?'badge-warning':($q['status']==='accepted'?'badge-success':($q['status']==='converted'?'badge-info':'badge-danger')); ?>">
                                <?php echo $q['status']==='pending'?'Pendiente':($q['status']==='accepted'?'Aceptada':($q['status']==='converted'?'Convertida':'Rechazada')); ?>
                            </span>
                        </td>
                        <td class="table-actions">
                            <button onclick="viewQuoteDetail(<?php echo $q['id']; ?>)" class="btn btn-sm btn-info" title="Ver detalle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            <a href="<?php echo $cotizBase; ?>?action=pdf&id=<?php echo $q['id']; ?>" class="btn btn-sm btn-secondary" target="_blank" title="Descargar PDF"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg></a>
                            <?php if (in_array($q['status'], ['pending', 'accepted'])): ?>
                                <button onclick="openConvertModal(<?php echo $q['id']; ?>,<?php echo $q['total']; ?>)" class="btn btn-sm btn-success" title="Convertir a venta"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></button>
                            <?php endif; ?>
                            <form method="POST" action="<?php echo $cotizBase; ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" value="<?php echo $q['id']; ?>">
                                <button type="submit" data-confirm="Eliminar esta cotizacion?" class="btn btn-sm btn-danger" title="Eliminar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php
            $pagination = $pagination ?? null;
            $paginationBaseUrl = $cotizBase;
            $paginationQuery = $filters['query'] ?? [];
            $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Nueva Cotización -->
<div id="quoteModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:750px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header"><h3>Nueva Cotización</h3><button onclick="closeQuoteModal()" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $cotizBase; ?>?action=create" data-ajax="true" data-prepare="quoteItems" onsubmit="return prepareQuoteItems()">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Cliente <span class="field-tip" data-tip="Escriba para buscar por nombre o documento y elija un cliente.">?</span></label>
                    <div class="customer-combobox" id="customerCombobox">
                        <input type="hidden" name="customer_id" id="customerId" value="">
                        <input type="text" id="customerSearch" class="form-control" placeholder="Buscar o seleccionar cliente..." autocomplete="off">
                        <ul class="combobox-list" id="customerList" hidden>
                            <li class="combobox-option" data-id="" data-label="Consumidor Final" data-search="consumidor final">Consumidor Final</li>
                            <?php foreach($customers as $c):
                                $label = trim(($c['first_name']??$c['name']).' '.($c['last_name']??''));
                                $search = strtolower($label.' '.($c['document_number']??''));
                            ?>
                                <li class="combobox-option" data-id="<?php echo (int)$c['id']; ?>" data-label="<?php echo htmlspecialchars($label); ?>" data-search="<?php echo htmlspecialchars($search); ?>">
                                    <?php echo htmlspecialchars($label); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button type="button" onclick="(function(){var m=document.getElementById('quickCustomerModal');if(m)m.style.display='flex';})()" class="link-btn">+ Nuevo Cliente</button>
                </div>
                <div class="form-group"><label>Válido hasta <span class="field-tip" data-tip="Fecha de vencimiento de la cotización.">?</span></label><input type="date" name="valid_until" class="form-control" value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>"></div>
            </div>
            <div class="form-group"><label>Notas <span class="field-tip" data-tip="Observaciones visibles en la cotización.">?</span></label><input type="text" name="notes" class="form-control" placeholder="Observaciones..."></div>
                <div class="form-group"><label>Productos <span class="field-tip" data-tip="Agregue los ítems a cotizar con cantidad y precio.">?</span></label>
                <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                    <input type="text" id="quoteBarcodeScan" class="form-control" placeholder="Escanear…" autocomplete="off" data-barcode-input="true" style="flex:1;min-width:120px;" title="Pistola de códigos de barras">
                    <select id="productSelect" class="form-control" style="flex:2;min-width:160px;"><option value="">Seleccionar...</option><?php foreach($products as $p): ?><option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['sale_price']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-code="<?php echo htmlspecialchars($p['code'] ?? ''); ?>"><?php echo htmlspecialchars((($p['code'] ?? '') !== '' ? ($p['code'] . ' — ') : '') . $p['name']); ?> (<?php echo fmtQ($p['sale_price'],$currency); ?>)</option><?php endforeach; ?></select>
                    <input type="number" id="productQty" value="1" min="1" class="form-control" style="width:80px;">
                    <button type="button" class="btn btn-primary" onclick="addQuoteProduct()">+</button>
                </div>
                <table id="itemsTable" style="width:100%;margin-top:10px;display:none;"><thead><tr><th>Producto</th><th>Cant</th><th>Precio</th><th>Subtotal</th><th></th></tr></thead><tbody></tbody><tfoot><tr><td colspan="3" style="text-align:right;font-weight:600;">Total:</td><td id="quoteTotal" style="font-weight:700;color:#10B981;">0</td><td></td></tr></tfoot></table>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeQuoteModal()">Cancelar</button><button type="submit" class="btn btn-primary" id="submitQuoteBtn">Crear Cotización</button></div>
        </form>
    </div>
</div>

<!-- Modal Quick Customer (encima del modal de cotización) -->
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

<!-- Modal Convertir a Venta -->
<div id="convertModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:450px;">
        <div class="modal-header"><h3>Convertir a Venta</h3><button onclick="document.getElementById('convertModal').style.display='none'" class="modal-close">&times;</button></div>
        <form method="POST" action="<?php echo $viewInstance->route('app/cotizaciones'); ?>?action=convert" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" id="convertQuoteId">
            <p id="convertInfo" style="margin-bottom:10px;"></p>
            <div class="form-group"><label>Tipo de Pago <span class="field-tip" data-tip="Totalidad cobra todo ahora. A Crédito deja saldo pendiente.">?</span></label><select name="payment_type" class="form-control" onchange="toggleConvertCredit()"><option value="full">Totalidad</option><option value="credit">A Crédito</option></select></div>
            <div class="form-group"><label>Método <span class="field-tip" data-tip="Medio de pago de la venta generada.">?</span></label><select name="payment_method" class="form-control"><?php echo \SoftNova\Core\payment_method_options('cash'); ?></select></div>
            <div class="form-group" id="convertInitialGroup" style="display:none;"><label>Abono Inicial <span class="field-tip" data-tip="Monto pagado al convertir; el resto queda por cobrar.">?</span></label><input type="number" name="initial_payment" class="form-control" step="0.01" min="0" placeholder="0"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('convertModal').style.display='none'">Cancelar</button><button type="submit" class="btn btn-success">Convertir a Venta</button></div>
        </form>
    </div>
</div>

<!-- Modal Detalle -->
<div id="quoteDetailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:650px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header"><h3>Detalle de Cotización</h3><button onclick="document.getElementById('quoteDetailModal').style.display='none'" class="modal-close">&times;</button></div>
        <div class="modal-body" id="quoteDetailContent"></div>
    </div>
</div>

<script>
window.quoteRouteCreate = '<?php echo $viewInstance->route('app/cotizaciones'); ?>?action=create';
window.quoteRouteDetail = '<?php echo $viewInstance->route('app/cotizaciones'); ?>?action=detail';
</script>
