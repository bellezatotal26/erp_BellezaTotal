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
$fmt = $fmt ?? fn($a) => number_format((float)$a, 2, ',', '.');
$base = $viewInstance->route('app/consignacion');
?>
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
    <div class="stat-card neumorphic"><h4>Por liquidar</h4><div class="stat-value" style="color:#B45309;"><?php echo htmlspecialchars($fmt($stats['payable_amount'] ?? 0)); ?></div>
        <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo (int)($stats['payable_units'] ?? 0); ?> und. vendidas</div>
    </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
    <?php
    $tabs = [
        'productos' => 'Productos',
        'ingresos' => 'Ingresos',
        'liquidaciones' => 'Por liquidar',
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
<div class="card neumorphic">
    <div class="card-header" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <h3 style="margin:0;flex:1;">Catálogo consignado</h3>
        <form method="get" action="<?php echo $base; ?>" style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="productos">
            <select name="supplier_id" class="form-control" style="min-width:200px;">
                <option value="0">Todos los proveedores</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo $supplierFilter === (int)$s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="Buscar…" style="min-width:160px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;">
                <input type="checkbox" name="show_inactive" value="1" <?php echo $showInactive ? 'checked' : ''; ?>>
                Ver inactivos
            </label>
            <button class="btn btn-secondary" type="submit">Filtrar</button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($products)): ?>
            <p style="text-align:center;color:var(--color-text-secondary);padding:28px;">
                No hay productos consignados. Seleccione un proveedor activo al crear el primer producto.
                <?php if (empty($suppliers)): ?>
                    <br><a href="<?php echo $viewInstance->route('app/proveedores'); ?>">Registrar proveedores</a> en el módulo de Proveedores.
                <?php endif; ?>
            </p>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th>Consignante</th>
                        <th>Stock</th>
                        <th>Costo pactado</th>
                        <th>Precio venta</th>
                        <th>Estado</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$p['code']); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars((string)$p['name']); ?></strong>
                            <?php if (!empty($p['barcode'])): ?>
                                <div style="font-size:12px;color:var(--color-text-secondary);"><?php echo htmlspecialchars((string)$p['barcode']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($p['consignor_name'] ?? '—')); ?></td>
                        <td style="font-weight:600;"><?php echo (int)$p['stock']; ?></td>
                        <td><?php echo htmlspecialchars($fmt($p['purchase_price'] ?? 0)); ?></td>
                        <td><?php echo htmlspecialchars($fmt($p['sale_price'] ?? 0)); ?></td>
                        <td><span class="badge <?php echo ($p['status'] ?? '') === 'active' ? 'badge-success' : 'badge-secondary'; ?>"><?php echo htmlspecialchars((string)$p['status']); ?></span></td>
                        <?php if ($canEdit): ?>
                        <td class="table-actions">
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
                            ], JSON_UNESCAPED_UNICODE); ?>)'>Editar</button>
                            <?php if ((int)$p['stock'] > 0): ?>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openReturn(<?php echo (int)$p['id']; ?>, <?php echo (int)$p['stock']; ?>, <?php echo htmlspecialchars(json_encode((string)$p['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">Devolver</button>
                            <?php endif; ?>
                            <?php if ($canEdit && (int)$p['stock'] === 0): ?>
                            <form method="post" action="<?php echo $base; ?>?action=deleteProduct" style="display:inline;"
                                  onsubmit="return confirm('¿Eliminar este producto del catálogo consignado?');">
                                <?php echo \SoftNova\Core\csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
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
                            <option value="<?php echo $cid; ?>"><?php echo htmlspecialchars($cname); ?></option>
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
                <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary">Liquidar seleccionados</button>
                <?php endif; ?>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th></th>
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
                                       style="width:90px;" disabled>
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
                        <td><?php echo htmlspecialchars((string)$c['status']); ?></td>
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
                        <td><?php echo htmlspecialchars((string)$s['payment_method']); ?></td>
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
                        <p style="font-size:12px;color:#B45309;margin:6px 0 0;">
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
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
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
function syncSettleQty(cb) {
    var row = cb.closest('tr');
    var qty = row.querySelector('.settle-qty');
    qty.disabled = !cb.checked;
}
function filterDebts() {
    var cid = document.getElementById('settleConsignor').value;
    document.querySelectorAll('.debt-row').forEach(function(row) {
        var match = !cid || row.getAttribute('data-consignor') === cid;
        row.style.display = match ? '' : 'none';
        if (!match) {
            var cb = row.querySelector('.debt-check');
            if (cb) { cb.checked = false; syncSettleQty(cb); }
        }
    });
}
</script>
