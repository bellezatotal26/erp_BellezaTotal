<?php
$layout = 'superadmin';
$title = 'Super Administrador - Clientes';
$pageTitle = 'Gestión de Clientes';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$tenants = $tenants ?? [];
$plans = $plans ?? [];
?>

<?php echo flashMessage(); ?>

<div class="card neumorphic">
    <div class="card-header">
        <h3>Lista de Clientes</h3>
        <button onclick="openTenantModal()" class="btn btn-primary neumorphic-btn">+ Nuevo Cliente</button>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>ID</th><th>Empresa</th><th>Email</th><th>Estado</th><th>Plan</th><th>Vencimiento</th><th>Días</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($tenants)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--color-text-secondary);">No hay clientes</td></tr>
                    <?php else: ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <?php $remaining = daysRemaining($tenant['subscription_end_date']); ?>
                            <tr>
                                <td><?php echo $tenant['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($tenant['company_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($tenant['email']); ?></td>
                                <td><span class="badge <?php echo statusBadgeClass($tenant['status']); ?>"><?php echo statusLabel($tenant['status']); ?></span></td>
                                <td><?php echo htmlspecialchars($tenant['plan_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($tenant['subscription_end_date']); ?></td>
                                <td><span class="badge <?php echo $remaining > 3 ? 'badge-success' : ($remaining > 0 ? 'badge-warning' : 'badge-danger'); ?>"><?php echo $remaining > 0 ? $remaining.' días' : 'Vencido'; ?></span></td>
                                <td class="table-actions">
                                    <a href="<?php echo $viewInstance->route('superadmin/tenants/' . $tenant['id'] . '/users'); ?>" class="btn btn-sm btn-warning">👥</a>
                                    <a href="<?php echo $viewInstance->route('app/dashboard'); ?>" class="btn btn-sm btn-info" target="_blank">🔗</a>
                                    <button onclick='openTenantModal(<?php echo htmlspecialchars(json_encode($tenant)); ?>)' class="btn btn-sm btn-secondary">✏️</button>
                                    <form method="POST" action="<?php echo $viewInstance->route('superadmin/tenants'); ?>?action=toggle_status" style="display:inline;" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                        <label class="switch"><input type="checkbox" onchange="handleTenantToggle(this)" <?php echo $tenant['status'] !== 'cancelled' ? 'checked' : ''; ?>><span class="slider"></span></label>
                                    </form>
                                    <form method="POST" action="<?php echo $viewInstance->route('superadmin/tenants'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $tenant['id']; ?>">
                                        <button type="submit" onclick="return confirm('¿Eliminar?')" class="btn btn-sm btn-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Unificado Crear/Editar -->
<div id="tenantModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width: 650px; max-height: 85vh; overflow-y: auto;">
        <div class="modal-header"><h3 id="tenantModalTitle">Nuevo Cliente</h3><button onclick="closeTenantModal()" class="modal-close">&times;</button></div>
        <form method="POST" id="tenantForm" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="tenantId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group"><label>Tipo Doc. * <span class="field-tip" data-tip="Tipo de identificación del cliente (Cédula, extranjería, PPT u otros).">?</span></label><select name="documento_tipo" id="tenantDocTipo" required class="form-control"><option value="">Seleccionar</option><option value="CC">C.C</option><option value="CE">C.E</option><option value="NIT">NIT</option><option value="PPT">PPT</option><option value="OTROS">OTROS</option></select></div>
                <div class="form-group"><label>N° Documento * <span class="field-tip" data-tip="Número de documento sin puntos. Campo obligatorio.">?</span></label><input type="text" name="documento_numero" id="tenantDocNum" required class="form-control"></div>
                <div class="form-group"><label>Empresa * <span class="field-tip" data-tip="Nombre comercial de la empresa cliente (tenant).">?</span></label><input type="text" name="name" id="tenantName" required class="form-control"></div>
                <div class="form-group"><label>Email * <span class="field-tip" data-tip="Correo principal del cliente. También se usará para el usuario administrador inicial.">?</span></label><input type="email" name="email" id="tenantEmail" required class="form-control"></div>
                <div class="form-group"><label>Razón Social <span class="field-tip" data-tip="Nombre legal o razón social registrada.">?</span></label><input type="text" name="razon_social" id="tenantRazon" class="form-control"></div>
                <div class="form-group"><label>Teléfono * <span class="field-tip" data-tip="Teléfono de contacto obligatorio del cliente.">?</span></label><input type="text" name="phone" id="tenantPhone" required class="form-control"></div>
                <div class="form-group"><label>Plan * <span class="field-tip" data-tip="Plan de suscripción que determina módulos y límites.">?</span></label><select name="plan_id" id="tenantPlan" required class="form-control"><?php foreach($plans as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Ciclo Facturación <span class="field-tip" data-tip="Periodicidad de cobro: mensual, semestral o anual.">?</span></label><select name="billing_cycle" id="tenantCycle" class="form-control"><option value="monthly">Mensual</option><option value="semiannual">Semestral</option><option value="annual">Anual</option></select></div>
                <div class="form-group"><label>Estado <span class="field-tip" data-tip="Activo puede ingresar; Suspendido/Cancelado bloquea el acceso.">?</span></label><select name="status" id="tenantStatus" class="form-control"><option value="active">Activo</option><option value="suspended">Suspendido</option><option value="cancelled">Cancelado</option></select></div>
            </div>
            <div class="form-group"><label>Dirección <span class="field-tip" data-tip="Dirección comercial o fiscal del cliente.">?</span></label><textarea name="address" id="tenantAddress" rows="2" class="form-control"></textarea></div>
            <div id="tenantAdminUserBox" style="background:var(--bg-input);border:1px solid var(--color-border);border-radius:8px;padding:12px;margin-bottom:12px;">
                <h4 style="margin:0 0 8px;font-size:14px;">Usuario administrador inicial</h4>
                <p style="margin:0 0 10px;font-size:12px;color:var(--color-text-secondary);">Se crea automáticamente. Luego puedes agregar más usuarios en 👥 Usuarios.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;"><label>Nombre admin <span class="field-tip" data-tip="Nombre del primer usuario administrador del cliente.">?</span></label><input type="text" name="admin_name" id="tenantAdminName" class="form-control" placeholder="Admin"></div>
                    <div class="form-group" style="margin:0;"><label>Contraseña inicial <span class="field-tip" data-tip="Si la deja vacía se genera una temporal y se muestra al crear.">?</span></label><input type="text" name="admin_password" id="tenantAdminPass" class="form-control" placeholder="Auto-generar" minlength="8"></div>
                </div>
            </div>
            <div class="modal-footer" style="margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeTenantModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="tenantSubmitBtn">Crear Cliente</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTenantModal(data) {
    var adminBox = document.getElementById('tenantAdminUserBox');
    if (data) {
        document.getElementById('tenantModalTitle').textContent = 'Editar Cliente';
        document.getElementById('tenantForm').action = '<?php echo $viewInstance->route('superadmin/tenants'); ?>?action=edit';
        document.getElementById('tenantSubmitBtn').textContent = 'Guardar';
        document.getElementById('tenantId').value = data.id;
        document.getElementById('tenantName').value = data.company_name || '';
        document.getElementById('tenantEmail').value = data.email || '';
        document.getElementById('tenantRazon').value = data.razon_social || '';
        document.getElementById('tenantPhone').value = data.phone || '';
        document.getElementById('tenantDocTipo').value = data.documento_tipo || '';
        document.getElementById('tenantDocNum').value = data.documento_numero || '';
        document.getElementById('tenantPlan').value = data.subscription_plan_id || '';
        document.getElementById('tenantStatus').value = data.status || 'active';
        document.getElementById('tenantAddress').value = data.address || '';
        if (adminBox) adminBox.style.display = 'none';
    } else {
        document.getElementById('tenantModalTitle').textContent = 'Nuevo Cliente';
        document.getElementById('tenantForm').action = '<?php echo $viewInstance->route('superadmin/tenants'); ?>?action=create';
        document.getElementById('tenantSubmitBtn').textContent = 'Crear Cliente';
        ['tenantId','tenantName','tenantEmail','tenantRazon','tenantPhone','tenantDocNum','tenantAddress','tenantAdminName','tenantAdminPass'].forEach(id => {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('tenantDocTipo').value = '';
        document.getElementById('tenantStatus').value = 'active';
        if (adminBox) adminBox.style.display = 'block';
    }
    document.getElementById('tenantModal').style.display = 'flex';
}
function closeTenantModal() { document.getElementById('tenantModal').style.display = 'none'; }

function handleTenantToggle(cb) {
    cb.closest('form').querySelector('input[name="id"]').value = cb.closest('form').querySelector('input[name="id"]').value;
    cb.closest('form').submit();
}
</script>
