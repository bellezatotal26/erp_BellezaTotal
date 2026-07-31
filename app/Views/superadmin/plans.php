<?php
$layout = 'superadmin';
$title = 'Super Administrador - Planes';
$pageTitle = 'Gestión de Planes';
$userName = $_SESSION['super_admin_name'] ?? 'Super Admin';
$plans = $plans ?? [];
$availableModules = ['dashboard', 'caja', 'ventas', 'inventario', 'clientes', 'proveedores', 'compras', 'cotizaciones', 'gastos', 'contabilidad', 'nomina', 'reportes'];
$moduleNames = [
    'dashboard' => 'Dashboard', 'caja' => 'Caja', 'ventas' => 'Ventas',
    'inventario' => 'Inventario', 'clientes' => 'Clientes', 'proveedores' => 'Proveedores',
    'compras' => 'Compras', 'cotizaciones' => 'Cotizaciones', 'gastos' => 'Gastos', 'contabilidad' => 'Contabilidad',
    'nomina' => 'Nómina', 'reportes' => 'Reportes'
];
$moduleIcons = [
    'dashboard' => '📊', 'caja' => '💰', 'ventas' => '🛒', 'inventario' => '📦',
    'clientes' => '👥', 'proveedores' => '🚚', 'compras' => '🚛', 'cotizaciones' => '📝', 'gastos' => '🧾',
    'contabilidad' => '🧮', 'nomina' => '💵', 'reportes' => '📋'
];
?>

<?php echo flashMessage(); ?>

<div class="card neumorphic">
    <div class="card-header">
        <h3>Planes de Suscripción</h3>
        <button onclick="openCreateModal()" class="btn btn-primary neumorphic-btn">+ Nuevo Plan</button>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Mensual</th>
                        <th>Semestral</th>
                        <th>Anual</th>
                        <th>Usuarios</th>
                        <th>Reportes</th>
                        <th>Módulos</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr><td colspan="9" style="text-align:center;color:var(--color-text-secondary);">No hay planes</td></tr>
                    <?php else: ?>
                        <?php foreach ($plans as $plan):
                            $feat = [];
                            if (!empty($plan['features'])) {
                                $decoded = is_array($plan['features']) ? $plan['features'] : json_decode((string)$plan['features'], true);
                                if (is_array($decoded)) $feat = $decoded;
                            }
                            $reportsLevel = ($feat['reports'] ?? '') === 'full' ? 'Completos' : 'Básicos';
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($plan['name']); ?></strong></td>
                                <td>$<?php echo number_format($plan['monthly_price'], 2); ?></td>
                                <td>$<?php echo number_format($plan['semiannual_price'] ?? $plan['monthly_price'] * 6, 2); ?></td>
                                <td>$<?php echo number_format($plan['annual_price'], 2); ?></td>
                                <td><?php echo $plan['max_users']; ?></td>
                                <td>
                                    <span class="badge <?php echo ($feat['reports'] ?? '') === 'full' ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo $reportsLevel; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $modules = json_decode($plan['modules'], true); ?>
                                    <?php if (is_array($modules)): ?>
                                        <?php foreach (array_slice($modules, 0, 4) as $m): ?>
                                            <span class="badge badge-info"><?php echo $moduleNames[$m] ?? $m; ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($modules) > 4): ?>
                                            <span class="badge badge-info">+<?php echo count($modules) - 4; ?></span>
                                        <?php endif; ?>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $plan['status'] === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $plan['status'] === 'active' ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($plan)); ?>)" class="btn btn-sm btn-secondary" title="Editar plan"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                    <form method="POST" action="<?php echo $viewInstance->route('superadmin/plans'); ?>?action=delete" style="display:inline;" data-ajax="true">
                                        <?php echo \SoftNova\Core\csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" onclick="return confirm('¿Eliminar este plan?')" class="btn btn-sm btn-danger" title="Eliminar plan"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
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

<!-- Modal Crear/Editar Plan -->
<div id="planModal" class="modal-overlay" style="display:none;">
    <div class="modal-content neumorphic" style="max-width: 580px; max-height: 85vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 id="planModalTitle">📦 Nuevo Plan</h3>
            <button onclick="closePlanModal()" class="modal-close">&times;</button>
        </div>
        <form method="POST" id="planForm" data-ajax="true">
            <?php echo \SoftNova\Core\csrf_field(); ?>
            <input type="hidden" name="id" id="planId">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre del Plan *</label>
                    <input type="text" name="name" id="planName" required class="form-control" placeholder="Ej: Plan Básico, Plan Premium...">
                </div>
                
                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="description" id="planDescription" rows="2" class="form-control" placeholder="Describe las características de este plan..."></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>💵 Precio Mensual *</label>
                        <input type="number" step="0.01" name="monthly_price" id="planMonthly" required class="form-control" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>📆 Precio Semestral</label>
                        <input type="number" step="0.01" name="semiannual_price" id="planSemiannual" class="form-control" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>📅 Precio Anual *</label>
                        <input type="number" step="0.01" name="annual_price" id="planAnnual" required class="form-control" placeholder="0.00">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>👥 Máx. Usuarios</label>
                        <input type="number" name="max_users" id="planMaxUsers" value="5" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>📦 Máx. Productos</label>
                        <input type="number" name="max_products" id="planMaxProducts" value="500" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="status" id="planStatus" class="form-control">
                            <option value="active">✅ Activo</option>
                            <option value="inactive">❌ Inactivo</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Nivel del plan</label>
                        <select name="plan_tier" id="planTier" class="form-control">
                            <option value="basic">Básico</option>
                            <option value="pro">Pro</option>
                            <option value="premium">Premium</option>
                            <option value="custom">Personalizado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nivel de Reportes</label>
                        <select name="reports_level" id="planReportsLevel" class="form-control" onchange="syncExportDefault()">
                            <option value="basic">Básicos (resumen + bloqueados)</option>
                            <option value="full">Completos (todo desbloqueado)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="export_reports" id="planExportReports" value="1" style="accent-color: var(--color-primary);">
                        Permitir exportar reportes (CSV / PDF)
                    </label>
                    <small style="color:var(--color-text-secondary);">Recomendado activarlo junto con reportes completos.</small>
                </div>
                
                <div class="form-group">
                    <label>🧩 Módulos incluidos</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; background: var(--bg-input); border: 1px solid var(--color-border); border-radius: 8px; padding: 12px;">
                        <?php foreach ($availableModules as $module): ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer; font-size: 13px;">
                                <input type="checkbox" name="modules[]" value="<?php echo $module; ?>" class="plan-module-cb" style="accent-color: var(--color-primary);">
                                <?php echo $moduleIcons[$module]; ?> <?php echo $moduleNames[$module]; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePlanModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary neumorphic-btn" id="planSubmitBtn">💾 Crear Plan</button>
            </div>
        </form>
    </div>
</div>

<script>
function syncExportDefault() {
    var level = document.getElementById('planReportsLevel').value;
    var exportCb = document.getElementById('planExportReports');
    if (level === 'full') exportCb.checked = true;
}

function openCreateModal() {
    document.getElementById('planModalTitle').textContent = '📦 Nuevo Plan';
    document.getElementById('planForm').action = '<?php echo $viewInstance->route('superadmin/plans'); ?>?action=create';
    document.getElementById('planSubmitBtn').textContent = '💾 Crear Plan';
    document.getElementById('planId').value = '';
    document.getElementById('planName').value = '';
    document.getElementById('planDescription').value = '';
    document.getElementById('planMonthly').value = '';
    document.getElementById('planSemiannual').value = '';
    document.getElementById('planAnnual').value = '';
    document.getElementById('planMaxUsers').value = '5';
    document.getElementById('planMaxProducts').value = '500';
    document.getElementById('planStatus').value = 'active';
    document.getElementById('planTier').value = 'basic';
    document.getElementById('planReportsLevel').value = 'basic';
    document.getElementById('planExportReports').checked = false;
    document.querySelectorAll('.plan-module-cb').forEach(cb => cb.checked = false);
    document.getElementById('planModal').style.display = 'flex';
}

function openEditModal(plan) {
    document.getElementById('planModalTitle').textContent = '✏️ Editar Plan';
    document.getElementById('planForm').action = '<?php echo $viewInstance->route('superadmin/plans'); ?>?action=edit';
    document.getElementById('planSubmitBtn').textContent = '💾 Guardar Cambios';
    document.getElementById('planId').value = plan.id;
    document.getElementById('planName').value = plan.name;
    document.getElementById('planDescription').value = plan.description || '';
    document.getElementById('planMonthly').value = plan.monthly_price;
    document.getElementById('planSemiannual').value = plan.semiannual_price || '';
    document.getElementById('planAnnual').value = plan.annual_price;
    document.getElementById('planMaxUsers').value = plan.max_users || 5;
    document.getElementById('planMaxProducts').value = plan.max_products || 500;
    document.getElementById('planStatus').value = plan.status || 'active';
    
    var feat = {};
    try {
        feat = typeof plan.features === 'object' && plan.features
            ? plan.features
            : JSON.parse(plan.features || '{}');
    } catch (e) { feat = {}; }
    document.getElementById('planTier').value = feat.tier || 'basic';
    document.getElementById('planReportsLevel').value = feat.reports === 'full' ? 'full' : 'basic';
    document.getElementById('planExportReports').checked = !!feat.export;
    
    const activeModules = JSON.parse(plan.modules || '[]');
    document.querySelectorAll('.plan-module-cb').forEach(cb => {
        cb.checked = activeModules.includes(cb.value);
    });
    
    document.getElementById('planModal').style.display = 'flex';
}

function closePlanModal() {
    document.getElementById('planModal').style.display = 'none';
}
</script>
