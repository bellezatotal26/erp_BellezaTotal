<?php
$layout = 'tenant';
$title = 'Clientes - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gestión de Clientes';
$tenantName = $tenantName ?? 'Mi Empresa';
$userName = $userName ?? 'Usuario';
$customers = $customers ?? [];

$sourceOptions = ['Facebook', 'Instagram', 'WhatsApp', 'Google', 'Recomendación', 'Tienda Física', 'Llamada', 'Email', 'Feria/Evento', 'Otro'];
?>
<?php echo flashMessage(); ?>

<div class="card neumorphic">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <h3>Lista de Clientes (<?php echo (int)($pagination['total'] ?? count($customers)); ?>)</h3>
        <div style="display:flex;gap:8px;">
            <a href="<?php echo $viewInstance->route('app/clientes'); ?>?action=export" class="btn btn-secondary" title="Exportar CSV">CSV</a>
            <button onclick="openModal()" class="btn btn-primary neumorphic-btn" title="Nuevo cliente">+ Nuevo Cliente</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-container"><table>
            <thead><tr><th>ID</th><th>Nombre</th><th>Apellido</th><th>Empresa</th><th>Documento</th><th>Email</th><th>Teléfono</th><th>Origen</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if (empty($customers)): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--color-text-secondary);">No hay clientes</td></tr>
            <?php else: ?>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?php echo $c['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($c['first_name'] ?? $c['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($c['last_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['company_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars(($c['document_type']??'CC').' '.($c['document_number']??'')); ?></td>
                        <td><?php echo htmlspecialchars($c['email'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['phone'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['source'] ?? '-'); ?></td>
                        <td class="table-actions">
                            <button onclick="viewDetail(<?php echo $c['id']; ?>)" class="btn btn-sm btn-info" title="Ver detalle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            <button onclick='openModal(<?php echo htmlspecialchars(json_encode($c)); ?>)' class="btn btn-sm btn-secondary" title="Editar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                            <form method="POST" action="<?php echo $viewInstance->route('app/clientes'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                <button type="submit" data-confirm="Eliminar este cliente?" class="btn btn-sm btn-danger" title="Eliminar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table></div>
        <?php
        $pagination = $pagination ?? null;
        $paginationBaseUrl = $viewInstance->route('app/clientes');
        $paginationQuery = [];
        $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery'));
        ?>
    </div>
</div>

<!-- Modal Crear/Editar -->
<div id="customerModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:600px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header"><h3 id="modalTitle">Nuevo Cliente</h3><button onclick="closeModal()" class="modal-close">&times;</button></div>
        <form method="POST" id="customerForm" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" id="custId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group"><label>Tipo Doc. <span class="field-tip" data-tip="Tipo de identificación del cliente (Cédula, NIT, extranjería, etc.).">?</span></label><select name="document_type" id="custDocType" class="form-control"><option value="CC">C.C</option><option value="CE">C.E</option><option value="NIT">NIT</option><option value="PPT">PPT</option><option value="OTROS">OTROS</option></select></div>
                <div class="form-group"><label>N° Documento * <span class="field-tip" data-tip="Número de documento sin puntos. Campo obligatorio.">?</span></label><input type="text" name="document_number" id="custDocNum" class="form-control" placeholder="Número" required></div>
                <div class="form-group"><label>Nombre * <span class="field-tip" data-tip="Nombre(s) del cliente. Campo obligatorio.">?</span></label><input type="text" name="first_name" id="custFirstName" class="form-control" placeholder="Nombres" required></div>
                <div class="form-group"><label>Apellido <span class="field-tip" data-tip="Apellido(s) del cliente.">?</span></label><input type="text" name="last_name" id="custLastName" class="form-control" placeholder="Apellidos"></div>
                <div class="form-group"><label>Empresa (opcional) <span class="field-tip" data-tip="Razón social o nombre comercial si compra a nombre de empresa.">?</span></label><input type="text" name="company_name" id="custCompany" class="form-control" placeholder="Nombre de empresa"></div>
                <div class="form-group"><label>¿De dónde viene? <span class="field-tip" data-tip="Canal de origen del cliente (referido, redes, pasante, etc.) para seguimiento comercial.">?</span></label><select name="source" id="custSource" class="form-control"><option value="">Seleccionar</option><?php foreach($sourceOptions as $s): ?><option value="<?php echo $s; ?>"><?php echo $s; ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Email <span class="field-tip" data-tip="Correo para facturas, cotizaciones o contacto.">?</span></label><input type="email" name="email" id="custEmail" class="form-control"></div>
                <div class="form-group"><label>Teléfono * <span class="field-tip" data-tip="Número de contacto principal. Campo obligatorio.">?</span></label><input type="text" name="phone" id="custPhone" class="form-control" required></div>
            </div>
            <div class="form-group"><label>Dirección <span class="field-tip" data-tip="Dirección de residencia o entrega.">?</span></label><textarea name="address" id="custAddress" rows="2" class="form-control"></textarea></div>
            <div class="modal-footer" style="margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Crear Cliente</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detalle -->
<div id="detailModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:700px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header"><h3 id="detailTitle">Detalle del Cliente</h3><button onclick="document.getElementById('detailModal').style.display='none'" class="modal-close">&times;</button></div>
        <div class="modal-body" id="detailContent"></div>
    </div>
</div>

<script>
function openModal(data) {
    if (data) {
        document.getElementById('modalTitle').textContent = 'Editar Cliente';
        document.getElementById('customerForm').action = '<?php echo $viewInstance->route('app/clientes'); ?>?action=edit';
        document.getElementById('submitBtn').textContent = 'Guardar';
        document.getElementById('custId').value = data.id;
        document.getElementById('custFirstName').value = data.first_name||data.name||'';
        document.getElementById('custLastName').value = data.last_name||'';
        document.getElementById('custCompany').value = data.company_name||'';
        document.getElementById('custSource').value = data.source||'';
        document.getElementById('custDocType').value = data.document_type||'CC';
        document.getElementById('custDocNum').value = data.document_number||'';
        document.getElementById('custEmail').value = data.email||'';
        document.getElementById('custPhone').value = data.phone||'';
        document.getElementById('custAddress').value = data.address||'';
    } else {
        document.getElementById('modalTitle').textContent = 'Nuevo Cliente';
        document.getElementById('customerForm').action = '<?php echo $viewInstance->route('app/clientes'); ?>?action=create';
        document.getElementById('submitBtn').textContent = 'Crear Cliente';
        ['custId','custFirstName','custLastName','custCompany','custDocNum','custEmail','custPhone','custAddress'].forEach(id=>document.getElementById(id).value='');
        document.getElementById('custSource').value = '';
        document.getElementById('custDocType').value = 'CC';
    }
    document.getElementById('customerModal').style.display = 'flex';
}
function closeModal() { document.getElementById('customerModal').style.display = 'none'; }

function viewDetail(id) {
    fetch('<?php echo $viewInstance->route('app/clientes'); ?>?action=detail&id=' + id, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.error) return alert(d.error);
        let c = d.customer, p = d.purchases||[];
        let html = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
            <div><strong>Nombre:</strong> ${esc(c.first_name||c.name)}</div>
            <div><strong>Apellido:</strong> ${esc(c.last_name||'-')}</div>
            <div><strong>Empresa:</strong> ${esc(c.company_name||'-')}</div>
            <div><strong>Origen:</strong> ${esc(c.source||'-')}</div>
            <div><strong>Documento:</strong> ${esc((c.document_type||'')+' '+(c.document_number||''))}</div>
            <div><strong>Email:</strong> ${esc(c.email||'-')}</div>
            <div><strong>Teléfono:</strong> ${esc(c.phone||'-')}</div>
            <div><strong>Dirección:</strong> ${esc(c.address||'-')}</div>
        </div>`;
        html += '<h4 style="margin-bottom:10px;">📋 Historial de Compras ('+p.length+')</h4>';
        if(p.length===0) html += '<p style="color:var(--color-text-secondary);text-align:center;">Sin compras registradas</p>';
        else {
            html += '<div class="table-container"><table><thead><tr><th>Factura</th><th>Fecha</th><th>Total</th><th>Método</th><th>Vendedor</th></tr></thead><tbody>';
            p.forEach(s=>{html+=`<tr><td>${esc(s.invoice_number)}</td><td>${s.sale_date?.substr(0,16)}</td><td>$${parseFloat(s.total).toFixed(0)}</td><td>${esc(s.payment_method)}</td><td>${esc(s.user_name||'-')}</td></tr>`});
            html+='</tbody></table></div>';
        }
        document.getElementById('detailContent').innerHTML = html;
        document.getElementById('detailModal').style.display = 'flex';
    });
}
function esc(s) { return (s||'').replace(/</g,'<').replace(/>/g,'>'); }
</script>
