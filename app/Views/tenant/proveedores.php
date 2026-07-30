<?php
$layout = 'tenant';
$title = 'Proveedores - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gestión de Proveedores';
$tenantName = $tenantName ?? 'Mi Empresa'; $userName = $userName ?? 'Usuario';
$suppliers = $suppliers ?? [];
?>
<?php echo flashMessage(); ?>

<div style="margin-bottom:20px;">
    <button onclick="openModal()" class="btn btn-primary neumorphic-btn">+ Nuevo Proveedor</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
    <?php if (empty($suppliers)): ?>
        <div class="card neumorphic" style="text-align:center;padding:40px;grid-column:1/-1;">
            <p style="color:var(--color-text-secondary);">No hay proveedores registrados</p>
        </div>
    <?php else: ?>
        <?php foreach($suppliers as $s): ?>
            <div class="card neumorphic" style="border-left:5px solid var(--color-primary);padding:20px;">
                <div style="display:flex;align-items:flex-start;gap:18px;">
                    <div style="width:90px;height:90px;border-radius:14px;overflow:hidden;flex-shrink:0;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:2px solid var(--color-border);box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                        <?php if(!empty($s['image'])): ?>
                            <img src="<?php echo htmlspecialchars($s['image']); ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <span style="display:none;font-size:36px;">🏢</span>
                        <?php else: ?>
                            <span style="font-size:36px;">🏢</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <h4 style="margin:0 0 6px 0;font-size:16px;color:var(--color-primary);"><?php echo htmlspecialchars($s['name']); ?></h4>
                        <?php if(!empty($s['contact_name'])): ?>
                            <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:3px;">👤 <?php echo htmlspecialchars($s['contact_name']); ?></div>
                        <?php endif; ?>
                        <?php if(!empty($s['email'])): ?>
                            <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:3px;">✉️ <?php echo htmlspecialchars($s['email']); ?></div>
                        <?php endif; ?>
                        <?php if(!empty($s['phone'])): ?>
                            <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:3px;">📞 <?php echo htmlspecialchars($s['phone']); ?></div>
                        <?php endif; ?>
                        <?php if(!empty($s['is_ally'])): ?>
                            <div style="margin-top:6px;"><span class="badge badge-success">Aliado · <?php echo number_format((float)($s['discount_percent'] ?? 0), 1); ?>% desc.</span></div>
                        <?php endif; ?>
                        <?php if(!empty($s['document_number'])): ?>
                            <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px;">
                                <span class="badge badge-info"><?php echo htmlspecialchars(($s['document_type']??'NIT').': '.($s['document_number']??'')); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if(!empty($s['address'])): ?>
                            <div style="font-size:11px;color:var(--color-text-secondary);margin-top:4px;line-height:1.3;">📍 <?php echo htmlspecialchars($s['address']); ?></div>
                        <?php endif; ?>
                        <div style="margin-top:12px;display:flex;gap:6px;">
                            <button onclick='openModal(<?php echo htmlspecialchars(json_encode($s)); ?>)' class="btn btn-sm btn-secondary" title="Editar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Editar</button>
                            <form method="POST" action="<?php echo $viewInstance->route('app/proveedores'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                <button type="submit" data-confirm="Eliminar este proveedor?" class="btn btn-sm btn-danger" title="Eliminar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="supplierModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:550px;max-height:85vh;overflow-y:auto;">
        <div class="modal-header"><h3 id="modalTitle">Nuevo Proveedor</h3><button onclick="closeModal()" class="modal-close">&times;</button></div>
        <form method="POST" id="supplierForm" enctype="multipart/form-data" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?><input type="hidden" name="id" id="supId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group" style="grid-column:1/-1;"><label>Nombre de Empresa * <span class="field-tip" data-tip="Razón social o nombre comercial del proveedor. Campo obligatorio.">?</span></label><input type="text" name="name" id="supName" required class="form-control"></div>
                <div class="form-group"><label>Tipo Doc. <span class="field-tip" data-tip="Tipo de documento tributario o de identidad del proveedor.">?</span></label><select name="document_type" id="supDocType" class="form-control"><option value="NIT">NIT</option><option value="CC">C.C</option><option value="CE">C.E</option><option value="OTROS">OTROS</option></select></div>
                <div class="form-group"><label>N° Documento * <span class="field-tip" data-tip="NIT o número de documento. Campo obligatorio.">?</span></label><input type="text" name="document_number" id="supDocNum" class="form-control" required></div>
                <div class="form-group"><label>Contacto <span class="field-tip" data-tip="Persona de contacto habitual en el proveedor.">?</span></label><input type="text" name="contact_name" id="supContact" class="form-control" placeholder="Nombre de la persona"></div>
                <div class="form-group"><label>Email <span class="field-tip" data-tip="Correo para pedidos o facturas del proveedor.">?</span></label><input type="email" name="email" id="supEmail" class="form-control"></div>
                <div class="form-group"><label>Teléfono * <span class="field-tip" data-tip="Teléfono de contacto. Campo obligatorio.">?</span></label><input type="text" name="phone" id="supPhone" class="form-control" required></div>
            </div>
            <div class="form-group"><label>Dirección <span class="field-tip" data-tip="Dirección física o comercial del proveedor.">?</span></label><textarea name="address" id="supAddress" rows="2" class="form-control"></textarea></div>
            <div class="form-group"><label>Notas <span class="field-tip" data-tip="Observaciones internas (condiciones, plazos, referencias).">?</span></label><textarea name="notes" id="supNotes" rows="2" class="form-control"></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="margin:0;">
                    <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_ally" id="supAlly" value="1"> Proveedor aliado</label>
                </div>
                <div class="form-group" style="margin:0;"><label>Descuento aliado %</label><input type="number" step="0.01" min="0" max="100" name="discount_percent" id="supDiscount" class="form-control" value="0"></div>
            </div>
            <div class="form-group"><label>Foto de Perfil <span class="field-tip" data-tip="Imagen opcional del proveedor o logo.">?</span></label><input type="file" name="image" id="supImage" class="form-control" accept="image/*" style="padding:8px;"></div>
            <div class="modal-footer" style="margin-top:15px;"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button><button type="submit" class="btn btn-primary" id="submitBtn">Crear Proveedor</button></div>
        </form>
    </div>
</div>

<script>
function openModal(data){
    if(data){
        document.getElementById('modalTitle').textContent='Editar Proveedor';
        document.getElementById('supplierForm').action='<?php echo $viewInstance->route('app/proveedores'); ?>?action=edit';
        document.getElementById('submitBtn').textContent='Guardar';
        document.getElementById('supId').value=data.id; document.getElementById('supName').value=data.name||'';
        document.getElementById('supContact').value=data.contact_name||''; document.getElementById('supDocType').value=data.document_type||'NIT';
        document.getElementById('supDocNum').value=data.document_number||''; document.getElementById('supEmail').value=data.email||'';
        document.getElementById('supPhone').value=data.phone||''; document.getElementById('supAddress').value=data.address||'';
        document.getElementById('supNotes').value=data.notes||'';
        document.getElementById('supAlly').checked = !!(data.is_ally && Number(data.is_ally) === 1);
        document.getElementById('supDiscount').value = data.discount_percent || 0;
    }else{
        document.getElementById('modalTitle').textContent='Nuevo Proveedor';
        document.getElementById('supplierForm').action='<?php echo $viewInstance->route('app/proveedores'); ?>?action=create';
        document.getElementById('submitBtn').textContent='Crear Proveedor';
        ['supId','supName','supContact','supDocNum','supEmail','supPhone','supAddress','supNotes'].forEach(id=>document.getElementById(id).value='');
        document.getElementById('supDocType').value='NIT'; document.getElementById('supImage').value='';
        document.getElementById('supAlly').checked=false; document.getElementById('supDiscount').value=0;
    }
    document.getElementById('supplierModal').style.display='flex';
}
function closeModal(){document.getElementById('supplierModal').style.display='none';}
</script>
