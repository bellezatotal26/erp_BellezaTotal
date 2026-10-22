<?php
$layout = 'tenant';
$title = 'Proveedores - ' . ($tenantName ?? 'Sistema');
$pageTitle = 'Gestión de Proveedores';
$tenantName = $tenantName ?? 'Mi Empresa'; $userName = $userName ?? 'Usuario';
$suppliers = $suppliers ?? [];
$pagination = $pagination ?? null;
$perPageOptions = $perPageOptions ?? [20, 50, 100, 200];
$qFilter = $qFilter ?? '';
$documentTypeFilter = $documentTypeFilter ?? '';
$allyFilter = $allyFilter ?? '';
$listQuery = $listQuery ?? [];
$supplierOptions = $supplierOptions ?? [];
$relationLabels = $relationLabels ?? [];
$proveedoresBase = $viewInstance->route('app/proveedores');
?>
<?php echo flashMessage(); ?>

<div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <button onclick="openModal()" class="btn btn-primary neumorphic-btn">+ Nuevo Proveedor</button>
    <?php if (\SoftNova\Core\TenantMiddleware::canDo('export', 'proveedores')): ?>
        <a href="<?php echo $proveedoresBase; ?>?action=export<?php echo $listQuery !== [] ? '&' . http_build_query($listQuery) : ''; ?>"
           class="btn btn-secondary" title="Exporta el listado filtrado; sin filtros exporta todos los activos">Exportar CSV</a>
    <?php endif; ?>
    <div class="inv-view-toggle" role="group" aria-label="Vista de proveedores" style="margin-left:auto;display:flex;gap:6px;">
        <button type="button" class="btn btn-secondary inv-view-btn is-active" id="provViewGridBtn" onclick="setProveedoresView('grid')" title="Vista en tarjetas">Tarjetas</button>
        <button type="button" class="btn btn-secondary inv-view-btn" id="provViewListBtn" onclick="setProveedoresView('list')" title="Vista en lista">Lista</button>
    </div>
</div>

<form method="GET" action="<?php echo $proveedoresBase; ?>" class="inv-filters-form" style="margin-bottom:16px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:220px;">
            <label style="font-size:12px;">Buscar</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($qFilter); ?>" class="form-control" placeholder="Nombre, NIT, contacto, teléfono…">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Tipo doc.</label>
            <select name="document_type" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <?php foreach (['NIT' => 'NIT', 'CC' => 'C.C', 'CE' => 'C.E', 'RUT' => 'RUT', 'OTROS' => 'OTROS'] as $code => $lab): ?>
                    <option value="<?php echo $code; ?>" <?php echo $documentTypeFilter === $code ? 'selected' : ''; ?>><?php echo $lab; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Aliado</label>
            <select name="ally" class="form-control" style="width:auto;">
                <option value="">Todos</option>
                <option value="1" <?php echo $allyFilter === '1' ? 'selected' : ''; ?>>Solo aliados</option>
                <option value="0" <?php echo $allyFilter === '0' ? 'selected' : ''; ?>>No aliados</option>
            </select>
        </div>
        <input type="hidden" name="per_page" value="<?php echo (int)($pagination['perPage'] ?? 20); ?>">
        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="<?php echo $proveedoresBase; ?>" class="btn btn-secondary">Limpiar</a>
        </div>
    </div>
</form>

<?php $hasProvFilters = $qFilter !== '' || $documentTypeFilter !== '' || $allyFilter !== ''; ?>
<div id="provCatalog" class="prov-catalog prov-catalog--grid">
    <div class="prov-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
        <?php if (empty($suppliers)): ?>
            <div class="card neumorphic" style="text-align:center;padding:40px;grid-column:1/-1;">
                <p style="color:var(--color-text-secondary);">
                    <?php echo $hasProvFilters ? 'No hay proveedores con esos filtros' : 'No hay proveedores registrados'; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($suppliers as $s): ?>
                <div class="card neumorphic" style="border-left:5px solid var(--color-primary);padding:20px;">
                    <div style="display:flex;align-items:flex-start;gap:18px;">
                        <div style="width:90px;height:90px;border-radius:14px;overflow:hidden;flex-shrink:0;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:2px solid var(--color-border);box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                            <?php if (!empty($s['image'])): ?>
                                <img src="<?php echo htmlspecialchars(\SoftNova\Core\media_url($s['image'])); ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <span style="display:none;font-size:36px;">🏢</span>
                            <?php else: ?>
                                <span style="font-size:36px;">🏢</span>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <h4 style="margin:0 0 6px 0;font-size:16px;color:var(--color-accent-text, var(--color-primary));"><?php echo htmlspecialchars($s['name']); ?></h4>
                            <?php if (!empty($s['contact_name'])): ?>
                                <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:3px;">👤 <?php echo htmlspecialchars($s['contact_name']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($s['email'])): ?>
                                <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:3px;">✉️ <?php echo htmlspecialchars($s['email']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($s['phone'])): ?>
                                <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:3px;">📞 <?php echo htmlspecialchars($s['phone']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($s['is_ally'])): ?>
                                <div style="margin-top:6px;"><span class="badge badge-success">Aliado · <?php echo number_format((float)($s['discount_percent'] ?? 0), 1); ?>% desc.</span></div>
                            <?php endif; ?>
                            <?php if (!empty($s['document_number'])): ?>
                                <div style="font-size:12px;color:var(--color-text-secondary);margin-top:4px;">
                                    <span class="badge badge-info"><?php echo htmlspecialchars(($s['document_type'] ?? 'NIT') . ': ' . ($s['document_number'] ?? '')); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($s['address'])): ?>
                                <div style="font-size:11px;color:var(--color-text-secondary);margin-top:4px;line-height:1.3;">📍 <?php echo htmlspecialchars($s['address']); ?></div>
                            <?php endif; ?>
                            <div style="margin-top:12px;display:flex;gap:6px;">
                                <button onclick='openModal(<?php echo htmlspecialchars(json_encode($s)); ?>)' class="btn btn-sm btn-secondary" title="Editar"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Editar</button>
                                <button type="button" onclick='openDeleteSupplierModal(<?php echo htmlspecialchars(json_encode(['id' => (int)$s['id'], 'name' => (string)$s['name'], 'relations' => $s['relations'] ?? []], JSON_UNESCAPED_UNICODE)); ?>)' class="btn btn-sm btn-danger" title="Eliminar (si tiene movimientos, se trasladan a otro proveedor)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card neumorphic prov-products-list" style="display:none;padding:0;overflow:hidden;">
        <div class="table-container" style="overflow-x:auto;">
            <table class="prov-list-table" style="min-width:900px;">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>Documento</th>
                        <th>Contacto</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th>Aliado</th>
                        <th>Dirección</th>
                        <th style="text-align:right;width:140px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:32px;color:var(--color-text-secondary);">
                            <?php echo $hasProvFilters ? 'No hay proveedores con esos filtros' : 'No hay proveedores registrados'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                                    <div style="width:36px;height:36px;border-radius:8px;overflow:hidden;flex-shrink:0;background:var(--bg-input);display:flex;align-items:center;justify-content:center;border:1px solid var(--color-border);font-size:16px;">
                                        <?php if (!empty($s['image'])): ?>
                                            <img src="<?php echo htmlspecialchars(\SoftNova\Core\media_url($s['image'])); ?>" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.parentNode.textContent='🏢';">
                                        <?php else: ?>
                                            🏢
                                        <?php endif; ?>
                                    </div>
                                    <strong style="color:var(--color-accent-text, var(--color-primary));"><?php echo htmlspecialchars((string)$s['name']); ?></strong>
                                </div>
                            </td>
                            <td style="white-space:nowrap;font-size:13px;">
                                <?php if (!empty($s['document_number'])): ?>
                                    <?php echo htmlspecialchars((string)($s['document_type'] ?? 'NIT') . ': ' . (string)$s['document_number']); ?>
                                <?php else: ?>
                                    <span style="color:var(--color-text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;"><?php echo htmlspecialchars((string)($s['contact_name'] ?? '')) ?: '<span style="color:var(--color-text-secondary);">—</span>'; ?></td>
                            <td style="font-size:13px;"><?php echo htmlspecialchars((string)($s['email'] ?? '')) ?: '<span style="color:var(--color-text-secondary);">—</span>'; ?></td>
                            <td style="font-size:13px;white-space:nowrap;"><?php echo htmlspecialchars((string)($s['phone'] ?? '')) ?: '<span style="color:var(--color-text-secondary);">—</span>'; ?></td>
                            <td>
                                <?php if (!empty($s['is_ally'])): ?>
                                    <span class="badge badge-success"><?php echo number_format((float)($s['discount_percent'] ?? 0), 1); ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--color-text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;max-width:220px;color:var(--color-text-secondary);">
                                <?php echo htmlspecialchars((string)($s['address'] ?? '')) ?: '—'; ?>
                            </td>
                            <td style="text-align:right;white-space:nowrap;">
                                <button type="button" onclick='openModal(<?php echo htmlspecialchars(json_encode($s)); ?>)' class="btn btn-sm btn-secondary" title="Editar">Editar</button>
                                <button type="button" onclick='openDeleteSupplierModal(<?php echo htmlspecialchars(json_encode(['id' => (int)$s['id'], 'name' => (string)$s['name'], 'relations' => $s['relations'] ?? []], JSON_UNESCAPED_UNICODE)); ?>)' class="btn btn-sm btn-danger" title="Eliminar (si tiene movimientos, se trasladan a otro proveedor)">Eliminar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div style="margin-top:16px;">
    <?php
    $paginationBaseUrl = $proveedoresBase;
    $paginationQuery = $listQuery;
    $paginationPerPageOptions = $perPageOptions;
    $viewInstance->partial('pagination', compact('pagination', 'paginationBaseUrl', 'paginationQuery', 'paginationPerPageOptions'));
    ?>
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

<!-- Modal eliminar / fusionar proveedor duplicado -->
<div id="deleteSupplierModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width:520px;">
        <div class="modal-header">
            <h3>Eliminar proveedor</h3>
            <button type="button" class="modal-close" onclick="closeDeleteSupplierModal()">&times;</button>
        </div>
        <form method="POST" action="<?php echo $proveedoresBase; ?>?action=delete" data-ajax="true" id="deleteSupplierForm">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="delSupId">
            <p id="delSupName" style="font-weight:600;margin:0 0 8px;"></p>
            <p id="delSupRelations" style="font-size:13px;color:var(--color-text-secondary);margin:0 0 12px;"></p>
            <div class="form-group">
                <label for="delSupTarget">Proveedor destino (el que queda) <span class="field-tip" data-tip="Las OC, facturas, abonos, notas crédito, productos vinculados y demás movimientos del proveedor eliminado pasan a este proveedor. Úselo cuando se creó un duplicado: elija aquí el proveedor correcto.">?</span></label>
                <select name="target_supplier_id" id="delSupTarget" class="form-control"></select>
                <small style="color:var(--color-text-secondary);display:block;margin-top:4px;">
                    Si el proveedor tiene movimientos, el destino es obligatorio: nada queda huérfano.
                    El proveedor eliminado queda inactivo (baja lógica) y todo su historial pasa al destino.
                </small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteSupplierModal()">Cancelar</button>
                <button type="submit" class="btn btn-danger" id="delSupSubmit">Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script>
window.supplierTargets = <?php echo json_encode(array_map(static function ($o) {
    return [
        'id' => (int)$o['id'],
        'name' => (string)$o['name'],
        'doc' => trim((string)($o['document_type'] ?? '') . ': ' . (string)($o['document_number'] ?? ''), ': '),
    ];
}, $supplierOptions), JSON_UNESCAPED_UNICODE); ?>;
window.supplierRelationLabels = <?php echo json_encode($relationLabels, JSON_UNESCAPED_UNICODE); ?>;

var delSupRelationsTotal = 0;

function openDeleteSupplierModal(data) {
    if (!data || !data.id) return;
    document.getElementById('delSupId').value = data.id;
    document.getElementById('delSupName').textContent = '«' + (data.name || '') + '»';
    var rel = data.relations || {};
    var labels = window.supplierRelationLabels || {};
    var parts = [];
    delSupRelationsTotal = 0;
    Object.keys(labels).forEach(function (k) {
        var c = parseInt(rel[k] || 0, 10) || 0;
        if (c > 0) { parts.push(c + ' ' + labels[k]); delSupRelationsTotal += c; }
    });
    var relEl = document.getElementById('delSupRelations');
    var submit = document.getElementById('delSupSubmit');
    var target = document.getElementById('delSupTarget');
    target.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = '';
    ph.textContent = delSupRelationsTotal > 0 ? '— Elija el proveedor que queda —' : '— Sin traslado (no tiene movimientos) —';
    target.appendChild(ph);
    (window.supplierTargets || []).forEach(function (o) {
        if (parseInt(o.id, 10) === parseInt(data.id, 10)) return;
        var opt = document.createElement('option');
        opt.value = o.id;
        opt.textContent = o.name + (o.doc ? ' · ' + o.doc : '');
        target.appendChild(opt);
    });
    if (delSupRelationsTotal > 0) {
        relEl.innerHTML = 'Este proveedor <strong>sí tiene movimientos</strong>: ' + parts.join(', ')
            + '.<br>Se trasladarán al proveedor destino que elija y luego el duplicado se eliminará.';
        target.setAttribute('required', 'required');
        submit.textContent = 'Trasladar y eliminar';
    } else {
        relEl.textContent = 'Este proveedor no tiene OC, facturas, abonos ni otros movimientos: se puede eliminar directamente. Si igual quiere fusionarlo con otro, elija el destino.';
        target.removeAttribute('required');
        submit.textContent = 'Eliminar proveedor';
    }
    document.getElementById('deleteSupplierModal').style.display = 'flex';
}

function closeDeleteSupplierModal() {
    document.getElementById('deleteSupplierModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('deleteSupplierForm');
    if (!form) return;
    form.addEventListener('submit', function (ev) {
        var target = document.getElementById('delSupTarget');
        if (delSupRelationsTotal > 0 && !(target && target.value)) {
            ev.preventDefault();
            var msg = 'Este proveedor tiene movimientos: elija el proveedor destino para trasladarlos antes de eliminarlo.';
            if (typeof showAlert === 'function') showAlert(msg, 'warning');
            else alert(msg);
        }
    });
});
</script>

<style>
.prov-catalog--list .prov-products-grid { display: none !important; }
.prov-catalog--list .prov-products-list { display: block !important; }
.prov-catalog--grid .prov-products-list { display: none !important; }
.prov-list-table th, .prov-list-table td { vertical-align: middle; }
</style>
<script>
function setProveedoresView(mode, skipSave) {
    var catalog = document.getElementById('provCatalog');
    if (!catalog) return;
    var isList = mode === 'list';
    catalog.classList.toggle('prov-catalog--list', isList);
    catalog.classList.toggle('prov-catalog--grid', !isList);
    var gridBtn = document.getElementById('provViewGridBtn');
    var listBtn = document.getElementById('provViewListBtn');
    if (gridBtn) gridBtn.classList.toggle('is-active', !isList);
    if (listBtn) listBtn.classList.toggle('is-active', isList);
    if (!skipSave) {
        try { localStorage.setItem('seri_prov_view_mode', isList ? 'list' : 'grid'); } catch (e) {}
    }
}

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

(function () {
    var saved = 'grid';
    try { saved = localStorage.getItem('seri_prov_view_mode') || 'grid'; } catch (e) {}
    setProveedoresView(saved === 'list' ? 'list' : 'grid', true);
})();
</script>
